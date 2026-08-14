#!/usr/bin/env python3
"""Back up and deploy the backend over SSH without storing credentials."""

from __future__ import annotations

import argparse
from collections.abc import Callable
import hashlib
import io
import json
import os
import posixpath
import re
import secrets
import shlex
import socket
import stat
import sys
import tarfile
import time
from typing import Any
from urllib.parse import urlsplit

# These migrations form one release gate.  In particular, 63 moves catalog
# files and must never be omitted or run outside the maintenance sequence.
REQUIRED_RELEASE_MIGRATIONS = (
    "database/migrations/upgrade_20260811_content_moderation_closure.sql",
    "database/migrations/upgrade_20260811_short_video_controls.sql",
    "database/migrations/upgrade_20260811_resource_store_review_closure.sql",
    "database/migrations/upgrade_20260811_management_shell_restructure.sql",
    "database/migrations/upgrade_20260814_secure_mail_settings.sql",
)
REQUIRED_RELEASE_FILES = (
    "backend/public/index.php",
    "backend/config/release-identity.json",
    "backend/tools/audit-default-credentials.php",
    "backend/tools/backfill-catalog-source-uploads.php",
    "backend/tools/catalog-legacy-upload-binding.php",
    "backend/tools/catalog-private-retention.php",
    "backend/tools/catalog-public-conflict-repair-contract.php",
    "backend/tools/catalog-conflict-server-local-preparation-contract.php",
    "backend/tools/catalog-public-quarantine-contract.php",
    "backend/tools/quarantine-catalog-public-files.php",
    "backend/tools/prepare-catalog-public-conflicts-server-local.php",
    "backend/tools/repair-catalog-public-conflicts.php",
)

PHP82_BIN = "/www/server/php/82/bin/php"
MYSQL_BIN_FALLBACK = "/www/server/mysql/bin/mysql"
MYSQLDUMP_BIN_FALLBACK = "/www/server/mysql/bin/mysqldump"
PHP_FPM82_INIT_SCRIPT = "/etc/init.d/php-fpm-82"
PHP_FPM82_SYSTEMD_SERVICE = "php8.2-fpm.service"
MEDIA_FFMPEG_BIN = "/opt/yiyunying/media-runtime/current/ffmpeg"
MEDIA_FFPROBE_BIN = "/opt/yiyunying/media-runtime/current/ffprobe"
MEDIA_RUNTIME_ROOT = "/opt/yiyunying/media-runtime"
MEDIA_RUNTIME_VERSION = "8.1.2-3bfa407c614a"
MEDIA_FFMPEG_SIZE = 140059552
MEDIA_FFPROBE_SIZE = 139834144
MEDIA_FFMPEG_SHA256 = "7b3fb9508c20166ab3ba236a9585c3e22e903880723c1a6448e69ae6e4cd88d2"
MEDIA_FFPROBE_SHA256 = "fe39eb91eb04dd18dff3870a87b59e41be997476c2d373c46ff7e12bb284743c"
REDACTED_PHP_CLI_OPTIONS = (
    "-d display_errors=0 -d display_startup_errors=0 "
    "-d log_errors=0 -d html_errors=0"
)
SSH_KEEPALIVE_SECONDS = 15
REMOTE_COMMAND_TIMEOUT_SECONDS = 2 * 60 * 60
SFTP_CHANNEL_TIMEOUT_SECONDS = 5 * 60
CATALOG_CONFLICT_ACTION_JPEG = "jpeg_to_png_register"
CATALOG_CONFLICT_ACTION_HEIC = "heic_to_png_sync"
CATALOG_CONFLICT_SERVER_LOCAL_PATHS = {
    CATALOG_CONFLICT_ACTION_JPEG:
        "6dba5a3f5092e15bad671d0d59c117f101e52ea58cd284079709568af52e3d29",
    CATALOG_CONFLICT_ACTION_HEIC:
        "6e2f1db260b172345f8890c5360f187d1c0a8e331c1e108167134d9fa1fbf83f",
}


def quote(value: str) -> str:
    return shlex.quote(value)


def exception_type_label(exception: Exception) -> str:
    label = re.sub(r"[^A-Za-z0-9_]", "", type(exception).__name__)[:64]
    return label or "Exception"


def sha256_file(path: str) -> str:
    digest = hashlib.sha256()
    try:
        with open(path, "rb") as handle:
            while True:
                chunk = handle.read(1024 * 1024)
                if not chunk:
                    break
                digest.update(chunk)
    except OSError as exc:
        raise RuntimeError(
            f"archive-local-sha256 failed ({exception_type_label(exc)})"
        ) from exc
    return digest.hexdigest()


def _read_private_local_file(path: str, label: str, maximum_size: int) -> bytes:
    """Read a unique local regular file without following a link."""
    try:
        metadata = os.lstat(path)
        if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
            raise RuntimeError(f"{label} must be a unique regular file")
        if metadata.st_nlink != 1 or metadata.st_size < 1 or metadata.st_size > maximum_size:
            raise RuntimeError(f"{label} is outside the private input boundary")
        if os.name != "nt" and (
            stat.S_IMODE(metadata.st_mode) != 0o600 or metadata.st_uid != os.geteuid()
        ):
            raise RuntimeError(f"{label} must be owned by the caller with mode 0600")
        with open(path, "rb") as handle:
            data = handle.read(maximum_size + 1)
        if len(data) != metadata.st_size:
            raise RuntimeError(f"{label} changed during readback")
        after = os.lstat(path)
        if (
            after.st_dev != metadata.st_dev
            or after.st_ino != metadata.st_ino
            or after.st_size != metadata.st_size
            or after.st_mtime_ns != metadata.st_mtime_ns
        ):
            raise RuntimeError(f"{label} changed during readback")
        return data
    except OSError as exc:
        raise RuntimeError(f"{label} cannot be read safely") from exc


def load_catalog_conflict_inputs(plan_path: str, jpeg_png: str, heic_png: str) -> dict[str, Any]:
    """Validate the local source plan and bind both prepared PNG fingerprints."""
    plan_bytes = _read_private_local_file(plan_path, "Catalog conflict source plan", 131072)
    try:
        plan = json.loads(plan_bytes.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise RuntimeError("Catalog conflict source plan must be UTF-8 JSON") from exc
    if not isinstance(plan, dict) or set(plan) != {"schema", "plan_kind", "batch", "items"}:
        raise RuntimeError("Catalog conflict source plan has an invalid top-level contract")
    batch = plan.get("batch")
    if (
        plan.get("schema") != 2
        or plan.get("plan_kind") != "source"
        or not isinstance(batch, str)
        or re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{7,79}", batch) is None
    ):
        raise RuntimeError("Catalog conflict source plan schema or batch is invalid")
    items = plan.get("items")
    if not isinstance(items, list) or len(items) != 2:
        raise RuntimeError("Catalog conflict source plan must contain exactly two items")

    local_paths = {
        CATALOG_CONFLICT_ACTION_JPEG: jpeg_png,
        CATALOG_CONFLICT_ACTION_HEIC: heic_png,
    }
    resolved_inputs = [os.path.realpath(path) for path in local_paths.values()]
    if len(set(os.path.normcase(path) for path in resolved_inputs)) != 2:
        raise RuntimeError("Prepared PNG inputs must be two distinct files")
    prepared: dict[str, dict[str, Any]] = {}
    seen_actions: set[str] = set()
    seen_paths: set[str] = set()
    exact_item_keys = {"path_sha256", "preimage", "replacement", "expected", "action", "registration"}
    for item in items:
        if not isinstance(item, dict) or set(item) != exact_item_keys:
            raise RuntimeError("Catalog conflict source item contract is invalid")
        action = item.get("action")
        path_hash = item.get("path_sha256")
        if (
            action not in local_paths
            or action in seen_actions
            or not isinstance(path_hash, str)
            or re.fullmatch(r"[0-9a-f]{64}", path_hash) is None
            or path_hash in seen_paths
        ):
            raise RuntimeError("Catalog conflict source actions or path hashes are invalid")
        seen_actions.add(action)
        seen_paths.add(path_hash)
        expected = item.get("expected")
        if not isinstance(expected, dict):
            raise RuntimeError("Catalog conflict expected state is invalid")
        if action == CATALOG_CONFLICT_ACTION_JPEG:
            exact_counts = (8, 0, 0, 0)
        else:
            exact_counts = (3, 1, 1, 1)
        actual_counts = tuple(
            expected.get(key)
            for key in ("path_references", "upload_id_references", "upload_rows", "media_attachment_rows")
        )
        if actual_counts != exact_counts:
            raise RuntimeError("Catalog conflict expected reference counts are not allowlisted")
        replacement = item.get("replacement")
        if not isinstance(replacement, dict) or set(replacement) != {
            "sha256", "size_bytes", "width", "height", "metadata_policy"
        }:
            raise RuntimeError("Catalog conflict replacement contract is invalid")
        replacement_hash = replacement.get("sha256")
        replacement_size = replacement.get("size_bytes")
        if (
            not isinstance(replacement_hash, str)
            or re.fullmatch(r"[0-9a-f]{64}", replacement_hash) is None
            or not isinstance(replacement_size, int)
            or isinstance(replacement_size, bool)
            or replacement_size < 1
            or replacement_size > 512 * 1024 * 1024
            or replacement.get("metadata_policy") != "no_ancillary_chunks_v1"
            or not all(isinstance(replacement.get(key), int) and 1 <= replacement[key] <= 8192 for key in ("width", "height"))
        ):
            raise RuntimeError("Catalog conflict replacement fingerprint is invalid")
        png_bytes = _read_private_local_file(local_paths[action], "Prepared PNG", 512 * 1024 * 1024)
        actual_hash = hashlib.sha256(png_bytes).hexdigest()
        if (
            len(png_bytes) != replacement_size
            or not secrets.compare_digest(actual_hash, replacement_hash)
            or not png_bytes.startswith(b"\x89PNG\r\n\x1a\n")
        ):
            raise RuntimeError("Prepared PNG does not match the source plan fingerprint")
        prepared[action] = {
            "path": local_paths[action],
            "sha256": actual_hash,
            "size_bytes": len(png_bytes),
        }
    if seen_actions != set(local_paths):
        raise RuntimeError("Both catalog conflict repair actions are required")
    return {
        "plan_path": plan_path,
        "plan_sha256": hashlib.sha256(plan_bytes).hexdigest(),
        "plan_size_bytes": len(plan_bytes),
        "batch": batch,
        "prepared": prepared,
    }


def resolve_catalog_conflict_mode(
    mode: str,
    plan_path: str,
    jpeg_png: str,
    heic_png: str,
) -> tuple[str, dict[str, Any] | None]:
    """Require an explicit, mutually exclusive conflict-repair input mode."""
    selected = mode.strip()
    local_arguments = [plan_path.strip(), jpeg_png.strip(), heic_png.strip()]
    if selected == "":
        if any(local_arguments):
            raise RuntimeError(
                "Catalog conflict local inputs require --catalog-conflict-repair-mode local"
            )
        return "", None
    if selected == "local":
        if not all(local_arguments):
            raise RuntimeError(
                "Local catalog conflict repair requires a source plan and both prepared PNG files"
            )
        return selected, load_catalog_conflict_inputs(*local_arguments)
    if selected == "server-local":
        if any(local_arguments):
            raise RuntimeError(
                "Server-local catalog conflict repair cannot accept local media or a local plan"
            )
        return selected, None
    raise RuntimeError("Catalog conflict repair mode is invalid")


def run_sftp_operation(
    client: Any,
    label: str,
    operation: Callable[[Any], object],
) -> None:
    sftp = None
    phase = "open"
    failure: tuple[RuntimeError, Exception] | None = None
    try:
        sftp = client.open_sftp()
        phase = "timeout-configure"
        sftp.get_channel().settimeout(SFTP_CHANNEL_TIMEOUT_SECONDS)
        phase = "transfer"
        operation(sftp)
    except (TimeoutError, socket.timeout) as exc:
        failure = (
            RuntimeError(
                f"{label} timed out during {phase}; "
                f"sftp-timeout={SFTP_CHANNEL_TIMEOUT_SECONDS}s"
            ),
            exc,
        )
    except Exception as exc:
        failure = (
            RuntimeError(
                f"{label} failed during {phase} ({exception_type_label(exc)})"
            ),
            exc,
        )
    finally:
        if sftp is not None:
            try:
                sftp.close()
            except Exception as exc:
                if failure is None:
                    failure = (
                        RuntimeError(
                            f"{label} failed during close ({exception_type_label(exc)})"
                        ),
                        exc,
                    )
    if failure is not None:
        raise failure[0] from failure[1]


def archive_sha256_check_command(remote_archive: str, expected_sha256: str) -> str:
    if re.fullmatch(r"[0-9a-f]{64}", expected_sha256) is None:
        raise ValueError("Expected archive SHA-256 must be lowercase hexadecimal")
    return (
        "set -e; "
        f"test -s {quote(remote_archive)}; "
        f"ACTUAL_ARCHIVE_SHA256=$(sha256sum {quote(remote_archive)}); "
        f'test "${{ACTUAL_ARCHIVE_SHA256%% *}}" = {quote(expected_sha256)}'
    )


def strict_php82_bootstrap(php_bin: str = PHP82_BIN) -> str:
    """Select only the reviewed PHP 8.2 binary used by this deployment."""
    return (
        f"PHP_BIN={quote(php_bin)}; "
        'test -x "$PHP_BIN"; '
        'test ! -L "$PHP_BIN"; '
    )


def media_runtime_integrity_preflight_command() -> str:
    """Audit the immutable runtime before executing either media binary."""
    version_root = MEDIA_RUNTIME_ROOT + "/" + MEDIA_RUNTIME_VERSION
    directories = ("/", "/opt", "/opt/yiyunying", MEDIA_RUNTIME_ROOT)
    directory_checks = " ".join(
        f"test -d {quote(path)}; test ! -L {quote(path)}; "
        f"test \"$(stat -c %U:%G -- {quote(path)})\" = root:root; "
        f"stat -c %a -- {quote(path)} | grep -Eq '^[0-7]?[0-7][0145][0145]$';"
        for path in directories
    )
    binaries = (
        ("ffmpeg", MEDIA_FFMPEG_SIZE, MEDIA_FFMPEG_SHA256, MEDIA_FFMPEG_BIN),
        ("ffprobe", MEDIA_FFPROBE_SIZE, MEDIA_FFPROBE_SHA256, MEDIA_FFPROBE_BIN),
    )
    binary_checks = " ".join(
        f"PINNED={quote(version_root + '/' + name)}; STABLE={quote(stable)}; "
        'test -f "$PINNED"; test ! -L "$PINNED"; '
        'test "$(stat -c %U:%G -- "$PINNED")" = root:root; '
        'test "$(stat -c %h -- "$PINNED")" -eq 1; '
        'test "$(stat -c %a -- "$PINNED")" = 555; '
        f'test "$(stat -c %s -- "$PINNED")" -eq {size}; '
        f'ACTUAL_RUNTIME_SHA256=$(sha256sum -- "$PINNED"); '
        f'test "${{ACTUAL_RUNTIME_SHA256%% *}}" = {quote(digest)}; '
        'test "$(readlink -f -- "$STABLE")" = "$PINNED";'
        for name, size, digest, stable in binaries
    )
    current = MEDIA_RUNTIME_ROOT + "/current"
    return (
        directory_checks
        + f" VERSION_ROOT={quote(version_root)}; test -d \"$VERSION_ROOT\"; "
        + 'test ! -L "$VERSION_ROOT"; test "$(stat -c %U:%G -- "$VERSION_ROOT")" = root:root; '
        + 'test "$(stat -c %a -- "$VERSION_ROOT")" = 555; '
        + f"CURRENT={quote(current)}; test -L \"$CURRENT\"; "
        + 'test "$(stat -c %U:%G -- "$CURRENT")" = root:root; '
        + 'test "$(stat -c %h -- "$CURRENT")" -eq 1; '
        + f'test "$(readlink -- "$CURRENT")" = {quote(MEDIA_RUNTIME_VERSION)}; '
        + 'test "$(readlink -f -- "$CURRENT")" = "$VERSION_ROOT"; '
        + binary_checks
    )


def runtime_dependency_preflight_command(
    php_bin: str = PHP82_BIN,
    mysql_fallback: str = MYSQL_BIN_FALLBACK,
    mysqldump_fallback: str = MYSQLDUMP_BIN_FALLBACK,
    fpm_init_script: str = PHP_FPM82_INIT_SCRIPT,
    fpm_systemd_service: str = PHP_FPM82_SYSTEMD_SERVICE,
    require_catalog_conflict_repair: bool = False,
) -> str:
    """Build the fail-closed production runtime dependency preflight."""
    extensions = ["PDO", "pdo_mysql", "mbstring", "json", "hash", "gd", "zlib"]
    functions = [
        "getimagesize", "imagecreatefromstring", "imagejpeg", "imagepng", "imagewebp",
        "imagetypes", "proc_open", "proc_get_status", "proc_terminate", "proc_close",
        "inflate_init", "inflate_add", "inflate_get_status", "inflate_get_read_len",
        "tempnam", "sys_get_temp_dir", "disk_free_space", "hash_file", "json_encode",
    ]
    if require_catalog_conflict_repair:
        functions.extend(["imagecreatefrompng", "imagesx", "imagesy"])
    php_probe = (
        'if (PHP_VERSION_ID < 80200) exit(40); '
        f'foreach ({json.dumps(extensions)} as $extension) {{ '
        'if (!extension_loaded($extension)) exit(41); } '
        f'foreach ({json.dumps(functions)} as $function) {{ '
        'if (!function_exists($function)) exit(42); } '
        '$imageTypes = imagetypes(); '
        'foreach ([IMG_JPG, IMG_PNG, IMG_WEBP] as $imageType) { '
        'if (($imageTypes & $imageType) !== $imageType) exit(44); } '
        'echo "runtime-dependencies-ready";'
    )
    return (
        "set -e; "
        + strict_php82_bootstrap(php_bin)
        + 'for TOOL in tar sha256sum gzip rsync curl stat readlink mktemp grep find timeout; do '
        + 'command -v "$TOOL" >/dev/null 2>&1; done; '
        + f'FFMPEG_BIN={quote(MEDIA_FFMPEG_BIN)}; FFPROBE_BIN={quote(MEDIA_FFPROBE_BIN)}; '
        + media_runtime_integrity_preflight_command()
        + 'test -f "$FFMPEG_BIN"; test -x "$FFMPEG_BIN"; '
        + 'test -f "$FFPROBE_BIN"; test -x "$FFPROBE_BIN"; '
        + 'timeout 5s "$FFMPEG_BIN" -version >/dev/null 2>&1; '
        + 'timeout 5s "$FFPROBE_BIN" -version >/dev/null 2>&1; '
        + "timeout 10s \"$FFMPEG_BIN\" -hide_banner -encoders 2>/dev/null | grep -Eq '[[:space:]]libx264[[:space:]]'; "
        + "timeout 10s \"$FFMPEG_BIN\" -hide_banner -encoders 2>/dev/null | grep -Eq '[[:space:]]aac[[:space:]]'; "
        + 'MEDIA_PROBE_DIR=$(mktemp -d /tmp/yiyunying-media-preflight.XXXXXX); '
        + 'trap \'rm -rf "$MEDIA_PROBE_DIR"\' EXIT; '
        + 'timeout 30s "$FFMPEG_BIN" -nostdin -hide_banner -loglevel error -f lavfi -i '
        + quote('color=c=black:s=16x16:r=10:d=0.4')
        + ' -f lavfi -i ' + quote('anullsrc=r=8000:cl=mono')
        + ' -shortest -c:v libx264 -pix_fmt yuv420p -c:a aac -movflags +faststart '
        + '"$MEDIA_PROBE_DIR/input.mp4"; '
        + 'VIDEO_PACKETS=$(timeout 15s "$FFPROBE_BIN" -v error -count_packets -select_streams v:0 '
        + '-show_entries stream=nb_read_packets -of csv=p=0 "$MEDIA_PROBE_DIR/input.mp4"); '
        + 'AUDIO_PACKETS=$(timeout 15s "$FFPROBE_BIN" -v error -count_packets -select_streams a:0 '
        + '-show_entries stream=nb_read_packets -of csv=p=0 "$MEDIA_PROBE_DIR/input.mp4"); '
        + 'printf "%s" "$VIDEO_PACKETS" | grep -Eq \'^[1-9][0-9]*$\'; '
        + 'printf "%s" "$AUDIO_PACKETS" | grep -Eq \'^[1-9][0-9]*$\'; '
        + 'MEDIA_DURATION=$(timeout 15s "$FFPROBE_BIN" -v error -show_entries format=duration '
        + '-of csv=p=0 "$MEDIA_PROBE_DIR/input.mp4"); '
        + 'printf "%s" "$MEDIA_DURATION" | grep -Eq \'^([1-9][0-9]*|0[.][0-9]*[1-9][0-9]*)$\'; '
        + 'timeout 60s "$FFMPEG_BIN" -nostdin -hide_banner -loglevel error -i "$MEDIA_PROBE_DIR/input.mp4" '
        + '-c:v libx264 -c:a aac "$MEDIA_PROBE_DIR/output.mp4"; '
        + 'test -s "$MEDIA_PROBE_DIR/output.mp4"; '
        + 'timeout 15s "$FFPROBE_BIN" -v error -select_streams v:0 -show_entries stream=codec_type '
        + '-of csv=p=0 "$MEDIA_PROBE_DIR/output.mp4" | grep -qx video; '
        + 'timeout 15s "$FFPROBE_BIN" -v error -select_streams a:0 -show_entries stream=codec_type '
        + '-of csv=p=0 "$MEDIA_PROBE_DIR/output.mp4" | grep -qx audio; '
        + 'rm -rf "$MEDIA_PROBE_DIR"; trap - EXIT; '
        + 'MYSQL_BIN=$(command -v mysql || true); '
        + f'if [ -z "$MYSQL_BIN" ] && [ -x {quote(mysql_fallback)} ]; '
        + f'then MYSQL_BIN={quote(mysql_fallback)}; fi; '
        + 'test -n "$MYSQL_BIN"; test -x "$MYSQL_BIN"; '
        + 'DUMP_BIN=$(command -v mysqldump || true); '
        + f'if [ -z "$DUMP_BIN" ] && [ -x {quote(mysqldump_fallback)} ]; '
        + f'then DUMP_BIN={quote(mysqldump_fallback)}; fi; '
        + 'test -n "$DUMP_BIN"; test -x "$DUMP_BIN"; '
        + f'if [ -x {quote(fpm_init_script)} ]; then :; '
        + 'elif command -v systemctl >/dev/null 2>&1 '
        + f'&& systemctl show -p LoadState --value {quote(fpm_systemd_service)} '
        + '| grep -qx loaded; then :; else exit 43; fi; '
        + f'"$PHP_BIN" -r {quote(php_probe)}'
    )


def catalog_conflict_stage_readback_command(files: list[tuple[str, int, str]]) -> str:
    checks = ["set -e"]
    for remote_path, expected_size, expected_sha256 in files:
        if expected_size < 1 or re.fullmatch(r"[0-9a-f]{64}", expected_sha256) is None:
            raise ValueError("Catalog conflict stage fingerprint is invalid")
        checks.extend(
            [
                f"test -f {quote(remote_path)}",
                f"test ! -L {quote(remote_path)}",
                f"test $(stat -c %h {quote(remote_path)}) -eq 1",
                f"test $(stat -c %a {quote(remote_path)}) = 600",
                f"test $(stat -c %s {quote(remote_path)}) -eq {expected_size}",
                f"ACTUAL=$(sha256sum {quote(remote_path)})",
                f'test "${{ACTUAL%% *}}" = {quote(expected_sha256)}',
            ]
        )
    return "; ".join(checks)


def catalog_conflict_stage_cleanup_command(stage: str) -> str:
    """Remove only this deployment's verified root-owned private stage."""
    if re.fullmatch(
        r"/tmp/yiyunying-catalog-conflict-[0-9]{8}-[0-9]{6}-[0-9a-f]{16}",
        stage,
    ) is None:
        raise ValueError("Catalog conflict cleanup stage is invalid")
    staged = quote(stage)
    known_names = (
        "source-plan.json",
        "runtime-plan.json",
        "jpeg-prepared.png",
        "heic-prepared.png",
    )
    file_cleanup = " ".join(
        (
            f"FILE=$STAGE/{quote(name)}; "
            'if test -e "$FILE" || test -L "$FILE"; then '
            'test -f "$FILE"; test ! -L "$FILE"; '
            'test $(stat -c %u "$FILE") -eq 0; test $(stat -c %g "$FILE") -eq 0; '
            'test $(stat -c %h "$FILE") -eq 1; test $(stat -c %a "$FILE") = 600; '
            'rm -f -- "$FILE"; fi;'
        )
        for name in known_names
    )
    return (
        "set -e; "
        + f"if test -e {staged} || test -L {staged}; then "
        + f"STAGE={staged}; test -d \"$STAGE\"; test ! -L \"$STAGE\"; "
        + 'test $(stat -c %u "$STAGE") -eq 0; test $(stat -c %g "$STAGE") -eq 0; '
        + 'test $(stat -c %a "$STAGE") = 700; '
        + file_cleanup
        + 'if find "$STAGE" -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then exit 71; fi; '
        + 'rmdir -- "$STAGE"; fi'
    )


def catalog_conflict_input_preflight_command(
    staged_backend: str,
    source_plan: str,
    jpeg_png: str,
    heic_png: str,
) -> str:
    php_source = (
        'require $argv[1] . "/tools/catalog-public-upload-type.php"; '
        'require $argv[1] . "/tools/catalog-public-quarantine-contract.php"; '
        'require $argv[1] . "/tools/catalog-public-conflict-repair-contract.php"; '
        '$bytes = file_get_contents($argv[2]); if (!is_string($bytes)) exit(51); '
        '$source = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR); '
        '$source = catalogConflictRepairValidateSourcePlan($source); '
        '$paths = ["jpeg_to_png_register" => $argv[3], "heic_to_png_sync" => $argv[4]]; '
        'foreach ($source["items"] as $item) { '
        '$replacement = ["path" => $paths[$item["action"]]] + $item["replacement"]; '
        'catalogConflictRepairAssertPrivateReplacement($replacement, $argv[1] . "/public"); } '
        'echo "catalog-conflict-inputs-ready";'
    )
    return (
        strict_php82_bootstrap()
        + f'"$PHP_BIN" -r {quote(php_source)} {quote(staged_backend)} '
        + f'{quote(source_plan)} {quote(jpeg_png)} {quote(heic_png)}'
    )


def catalog_conflict_server_local_preparation_command(
    live_backend: str,
    stage: str,
    batch: str,
    database_backup: str,
    uploads_backup: str,
) -> str:
    """Prepare both fixed media replacements on-host after all release gates."""
    if re.fullmatch(
        r"/tmp/yiyunying-catalog-conflict-[0-9]{8}-[0-9]{6}-[0-9a-f]{16}",
        stage,
    ) is None:
        raise ValueError("Server-local catalog preparation stage is invalid")
    if re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{7,79}", batch) is None:
        raise ValueError("Server-local catalog preparation batch is invalid")
    return (
        "set -e; "
        + strict_php82_bootstrap()
        + f"cd {quote(live_backend)}; "
        + 'timeout --signal=TERM --kill-after=10s 1200s '
        + f'"$PHP_BIN" {REDACTED_PHP_CLI_OPTIONS} '
        + 'tools/prepare-catalog-public-conflicts-server-local.php '
        + f"--output-directory {quote(stage)} --batch {quote(batch)} "
        + f"--database-backup {quote(database_backup)} "
        + f"--public-uploads-backup {quote(uploads_backup)} "
        + "--maintenance-confirmed --backup-confirmed --gate-confirmed"
    )


def parse_catalog_conflict_server_local_receipt(output: str, expected_batch: str) -> dict[str, Any]:
    """Accept exactly one redacted, duplicate-key-free preparation receipt."""
    lines = [line.strip() for line in output.splitlines() if line.strip()]
    if len(lines) != 1 or len(lines[0]) > 8192:
        raise RuntimeError("Server-local catalog preparation returned an invalid receipt count")

    def unique_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            if key in result:
                raise ValueError("duplicate receipt key")
            result[key] = value
        return result

    try:
        receipt = json.loads(lines[0], object_pairs_hook=unique_object)
    except (json.JSONDecodeError, ValueError) as exc:
        raise RuntimeError("Server-local catalog preparation receipt is not strict JSON") from exc
    if not isinstance(receipt, dict) or set(receipt) != {
        "schema", "status", "batch", "source_plan_sha256", "items"
    }:
        raise RuntimeError("Server-local catalog preparation receipt fields are invalid")
    if (
        receipt["schema"] != 1
        or isinstance(receipt["schema"], bool)
        or receipt["status"] != "prepared"
        or receipt["batch"] != expected_batch
        or not isinstance(receipt["source_plan_sha256"], str)
        or re.fullmatch(r"[0-9a-f]{64}", receipt["source_plan_sha256"]) is None
        or not isinstance(receipt["items"], list)
        or len(receipt["items"]) != 2
    ):
        raise RuntimeError("Server-local catalog preparation receipt values are invalid")
    seen: set[str] = set()
    for item in receipt["items"]:
        if not isinstance(item, dict) or set(item) != {
            "action", "path_sha256", "replacement_sha256", "replacement_size_bytes"
        }:
            raise RuntimeError("Server-local catalog preparation receipt item is invalid")
        action = item["action"]
        if (
            action not in CATALOG_CONFLICT_SERVER_LOCAL_PATHS
            or action in seen
            or item["path_sha256"] != CATALOG_CONFLICT_SERVER_LOCAL_PATHS[action]
            or not isinstance(item["replacement_sha256"], str)
            or re.fullmatch(r"[0-9a-f]{64}", item["replacement_sha256"]) is None
            or isinstance(item["replacement_size_bytes"], bool)
            or not isinstance(item["replacement_size_bytes"], int)
            or not 1 <= item["replacement_size_bytes"] <= 512 * 1024 * 1024
        ):
            raise RuntimeError("Server-local catalog preparation receipt binding is invalid")
        seen.add(action)
    if seen != set(CATALOG_CONFLICT_SERVER_LOCAL_PATHS):
        raise RuntimeError("Server-local catalog preparation receipt is incomplete")
    return receipt


def catalog_conflict_runtime_plan_command(
    live_backend: str,
    source_plan: str,
    runtime_plan: str,
    jpeg_png: str,
    heic_png: str,
    database_backup: str,
    uploads_backup: str,
) -> str:
    """Create the server-owned runtime plan from this deployment's backups."""
    php_source = (
        'require $argv[1] . "/tools/catalog-public-upload-type.php"; '
        'require $argv[1] . "/tools/catalog-public-quarantine-contract.php"; '
        'require $argv[1] . "/tools/catalog-public-conflict-repair-contract.php"; '
        '$bytes = file_get_contents($argv[2]); if (!is_string($bytes)) exit(61); '
        '$source = catalogConflictRepairValidateSourcePlan(json_decode($bytes, true, 32, JSON_THROW_ON_ERROR)); '
        '$artifact = static function (string $path, string $format): array { '
        '$real = realpath($path); $stat = lstat($path); '
        'if ($real === false || $real !== $path || !is_file($path) || is_link($path) || !is_array($stat) '
        '|| (int)($stat["nlink"] ?? 0) !== 1 || (((int)($stat["mode"] ?? 0)) & 0777) !== 0600 '
        '|| !function_exists("posix_geteuid") || (int)($stat["uid"] ?? -1) !== posix_geteuid()) exit(62); '
        '$size = filesize($real); $hash = hash_file("sha256", $real); $mtime = filemtime($real); '
        'if (!is_int($size) || $size < 1 || !is_string($hash) || !is_int($mtime)) exit(63); '
        'return ["path"=>$real,"size_bytes"=>$size,"sha256"=>strtolower($hash),"format"=>$format,"mtime_epoch"=>$mtime]; }; '
        '$paths = ["jpeg_to_png_register" => $argv[4], "heic_to_png_sync" => $argv[5]]; '
        '$items = []; foreach ($source["items"] as $item) { '
        '$item["replacement"] = ["path" => $paths[$item["action"]]] + $item["replacement"]; $items[] = $item; } '
        '$runtime = ["schema"=>2,"plan_kind"=>"runtime","batch"=>$source["batch"],'
        '"backup"=>["confirmed"=>true,"confirmed_at_utc"=>gmdate("Y-m-d\\TH:i:s\\Z"),'
        '"database"=>$artifact($argv[6],"database_gzip"),'
        '"public_uploads"=>$artifact($argv[7],"public_uploads_tar_gzip")],"items"=>$items]; '
        '$runtime = catalogConflictRepairValidateRuntimePlan($runtime); '
        '$json = json_encode($runtime, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) . "\\n"; '
        '$temporary = $argv[3] . ".partial-" . bin2hex(random_bytes(6)); '
        'if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !chmod($temporary, 0600) '
        '|| !rename($temporary, $argv[3])) { @unlink($temporary); exit(64); } '
        '$readback = file_get_contents($argv[3]); if (!is_string($readback) || !hash_equals(hash("sha256",$json),hash("sha256",$readback))) exit(65); '
        'echo "catalog-conflict-runtime-plan-ready";'
    )
    return (
        strict_php82_bootstrap()
        + f'"$PHP_BIN" -r {quote(php_source)} {quote(live_backend)} {quote(source_plan)} '
        + f'{quote(runtime_plan)} {quote(jpeg_png)} {quote(heic_png)} '
        + f'{quote(database_backup)} {quote(uploads_backup)}'
    )


def parse_catalog_conflict_report_basename(output: str) -> str:
    reports = [line[len("report=") :].strip() for line in output.splitlines() if line.startswith("report=")]
    if len(reports) != 1 or re.fullmatch(r"repair-[A-Za-z0-9._-]{8,180}\.json", reports[0]) is None:
        raise RuntimeError("Catalog conflict repair did not return one safe report basename")
    if posixpath.basename(reports[0]) != reports[0]:
        raise RuntimeError("Catalog conflict repair report escaped the private report directory")
    return reports[0]


def catalog_conflict_report_assertion_command(report_path: str, mode: str) -> str:
    if mode not in {"apply", "dry-run"}:
        raise ValueError("Catalog conflict report mode is invalid")
    if mode == "apply":
        outcome = (
            '(((int)($report["repaired"] ?? -1) === 2 && ($report["zero_work"] ?? null) === false) '
            '|| ((int)($report["repaired"] ?? -1) === 0 && ($report["zero_work"] ?? null) === true))'
        )
    else:
        outcome = '((int)($report["repaired"] ?? -1) === 0 && ($report["zero_work"] ?? null) === true)'
    php_source = (
        '$report = json_decode((string)file_get_contents($argv[1]), true); '
        f'if (!is_array($report) || ($report["mode"] ?? null) !== {json.dumps(mode)} '
        '|| ($report["passed"] ?? null) !== true || (int)($report["pending"] ?? -1) !== 0 '
        '|| (int)($report["already_repaired"] ?? -1) !== 2 || (int)($report["conflicts"] ?? -1) !== 0 '
        f'|| !{outcome}) exit(66); echo "catalog-conflict-report=passed";'
    )
    return strict_php82_bootstrap() + f'"$PHP_BIN" -r {quote(php_source)} {quote(report_path)}'


def catalog_gate_readback_command(
    remote_root: str,
    php_bootstrap: str,
    accepted_values: tuple[str, str],
    failure_status: int,
    success_message: str,
) -> str:
    """Build a shell-safe, prepared catalog-gate readback command."""
    if len(accepted_values) != 2:
        raise ValueError("accepted_values must contain exactly two values")
    if not 1 <= failure_status <= 255:
        raise ValueError("failure_status must be a non-zero shell exit status")

    php_source = (
        'require "bootstrap.php"; '
        '$rows = Yiyunying\\Core\\Database::one('
        '"SELECT COUNT(*) AS total FROM apps a LEFT JOIN app_settings s ON s.app_id = a.id '
        'AND s.setting_key = ? WHERE a.deleted_at IS NULL AND (s.id IS NULL '
        'OR s.value_type <> ? OR s.setting_value NOT IN (?, ?))", '
        '[$argv[1], $argv[2], $argv[3], $argv[4]]); '
        'if ((int) ($rows["total"] ?? -1) !== 0) exit((int) $argv[5]); '
        'echo $argv[6];'
    )
    php_arguments = (
        "catalog_private_migration_ready",
        "bool",
        *accepted_values,
        str(failure_status),
        success_message,
    )
    return (
        f"cd {quote(remote_root)}; "
        + php_bootstrap
        + '"$PHP_BIN" -r '
        + quote(php_source)
        + " "
        + " ".join(quote(argument) for argument in php_arguments)
    )


def dotenv_value(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def run(client: paramiko.SSHClient, command: str, label: str) -> str:
    return run_with_status(client, command, label, {0})


def run_with_status(
    client: paramiko.SSHClient, command: str, label: str, allowed_statuses: set[int]
) -> str:
    channel = None
    try:
        stdin, stdout, stderr = client.exec_command(
            command,
            get_pty=False,
            timeout=REMOTE_COMMAND_TIMEOUT_SECONDS,
        )
        del stdin
        channel = stdout.channel
        channel.settimeout(REMOTE_COMMAND_TIMEOUT_SECONDS)
        output = stdout.read().decode("utf-8", errors="replace")
        error = stderr.read().decode("utf-8", errors="replace")
        status = channel.recv_exit_status()
    except (TimeoutError, socket.timeout) as exc:
        try:
            if channel is not None:
                channel.close()
        finally:
            raise RuntimeError(
                f"{label} timed out after {REMOTE_COMMAND_TIMEOUT_SECONDS} seconds"
            ) from exc
    if status not in allowed_statuses:
        raise RuntimeError(f"{label} failed ({status}): {error or output}")
    if output.strip():
        print(f"[{label}] {output.strip()}")
    return output


def run_redacted_capture(
    client: paramiko.SSHClient,
    command: str,
    label: str,
    maximum_output: int = 8192,
) -> str:
    """Capture one sensitive remote receipt without echoing remote bytes."""
    if maximum_output < 1024 or maximum_output > 65536:
        raise ValueError("Redacted remote output boundary is invalid")
    channel = None
    output = bytearray()
    error = bytearray()
    try:
        stdin, stdout, _stderr = client.exec_command(
            command,
            get_pty=False,
            timeout=REMOTE_COMMAND_TIMEOUT_SECONDS,
        )
        del stdin, _stderr
        channel = stdout.channel
        channel.settimeout(REMOTE_COMMAND_TIMEOUT_SECONDS)
        deadline = time.monotonic() + REMOTE_COMMAND_TIMEOUT_SECONDS
        while True:
            progressed = False
            while channel.recv_ready():
                chunk = channel.recv(min(32768, maximum_output + 1 - len(output) - len(error)))
                if not chunk:
                    break
                output.extend(chunk)
                progressed = True
                if len(output) + len(error) > maximum_output:
                    raise RuntimeError(f"{label} exceeded the redacted output boundary")
            while channel.recv_stderr_ready():
                chunk = channel.recv_stderr(
                    min(32768, maximum_output + 1 - len(output) - len(error))
                )
                if not chunk:
                    break
                error.extend(chunk)
                progressed = True
                if len(output) + len(error) > maximum_output:
                    raise RuntimeError(f"{label} exceeded the redacted output boundary")
            if channel.exit_status_ready() and not channel.recv_ready() and not channel.recv_stderr_ready():
                break
            if time.monotonic() >= deadline:
                raise RuntimeError(
                    f"{label} timed out after {REMOTE_COMMAND_TIMEOUT_SECONDS} seconds"
                )
            if not progressed:
                time.sleep(0.01)
        status = channel.recv_exit_status()
    except (TimeoutError, socket.timeout) as exc:
        if channel is not None:
            channel.close()
        raise RuntimeError(
            f"{label} timed out after {REMOTE_COMMAND_TIMEOUT_SECONDS} seconds; remote output redacted"
        ) from exc
    except RuntimeError:
        if channel is not None:
            channel.close()
        raise
    except Exception as exc:
        if channel is not None:
            channel.close()
        raise RuntimeError(
            f"{label} transport failed ({exception_type_label(exc)}); remote output redacted"
        ) from exc
    if status != 0:
        raise RuntimeError(f"{label} failed ({status}); remote output redacted")
    if error:
        raise RuntimeError(f"{label} returned unexpected stderr; remote output redacted")
    try:
        return output.decode("utf-8", errors="strict")
    except UnicodeDecodeError as exc:
        raise RuntimeError(f"{label} returned non-UTF-8 output; remote output redacted") from exc


def normalize_migration_path(path: str) -> str:
    normalized = posixpath.normpath(path.strip().lstrip("/"))
    if not normalized or normalized == "." or normalized.startswith("../"):
        raise RuntimeError(f"Invalid migration path: {path!r}")
    return normalized


def normalize_remote_root(value: str) -> str:
    if "\x00" in value or "\n" in value or "\r" in value:
        raise RuntimeError("remote-root contains invalid characters")
    normalized = posixpath.normpath(value.strip())
    components = [item for item in normalized.split("/") if item]
    if not normalized.startswith("/") or normalized == "/" or len(components) < 2:
        raise RuntimeError("remote-root must be a specific absolute application directory")
    return normalized


def assert_required_release_migrations(migrations: list[str]) -> list[str]:
    normalized = [normalize_migration_path(item) for item in migrations]
    missing = [item for item in REQUIRED_RELEASE_MIGRATIONS if item not in normalized]
    duplicate = [item for item in REQUIRED_RELEASE_MIGRATIONS if normalized.count(item) != 1]
    positions = [normalized.index(item) for item in REQUIRED_RELEASE_MIGRATIONS if item in normalized]
    if missing or duplicate or positions != sorted(positions):
        raise RuntimeError(
            "This release must include migrations 61-65 exactly once and in order; "
            f"missing={missing}, duplicate={duplicate}"
        )
    return normalized


def validate_release_archive(
    archive_path: str,
    identity_path: str,
    release_version: str,
    build_source_commit: str,
) -> tuple[str, str]:
    commit = build_source_commit.strip().lower()
    if re.fullmatch(r"[0-9a-f]{40}", commit) is None:
        raise RuntimeError("build-source-commit must be exactly 40 hexadecimal characters")
    if not os.path.isfile(identity_path):
        raise RuntimeError(f"Release identity not found: {identity_path}")

    with open(identity_path, "rb") as identity_file:
        identity_bytes = identity_file.read()
    try:
        identity = json.loads(identity_bytes)
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise RuntimeError("Release identity is not valid UTF-8 JSON") from exc
    if not isinstance(identity, dict) or identity.get("version_name") != release_version:
        raise RuntimeError("release-version does not match release identity version_name")
    identity_sha256 = hashlib.sha256(identity_bytes).hexdigest()

    required_files = {
        *REQUIRED_RELEASE_FILES,
        *(f"backend/{path}" for path in REQUIRED_RELEASE_MIGRATIONS),
    }
    try:
        with tarfile.open(archive_path, mode="r:gz") as archive:
            members = archive.getmembers()
            archive_commit = str(archive.pax_headers.get("comment", "")).lower()
            if archive_commit != commit:
                raise RuntimeError(
                    "Archive is not a git archive bound to build-source-commit by its PAX comment"
                )
            names: set[str] = set()
            for member in members:
                name = member.name.rstrip("/")
                if (
                    not name
                    or name.startswith("/")
                    or posixpath.normpath(name) != name
                    or name == ".."
                    or name.startswith("../")
                    or not (member.isfile() or member.isdir())
                ):
                    raise RuntimeError(f"Archive contains an unsafe member: {member.name!r}")
                names.add(name)
            missing = sorted(required_files - names)
            if missing:
                raise RuntimeError(f"Archive is missing required release files: {missing}")
            embedded = archive.extractfile("backend/config/release-identity.json")
            if embedded is None or hashlib.sha256(embedded.read()).hexdigest() != identity_sha256:
                raise RuntimeError("Archive release identity does not match --release-identity")
    except (tarfile.TarError, OSError) as exc:
        raise RuntimeError("Archive must be a readable gzip-compressed git tar archive") from exc
    return identity_sha256, commit


def main() -> int:
    import paramiko

    parser = argparse.ArgumentParser()
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", type=int, default=22)
    parser.add_argument("--user", required=True)
    parser.add_argument(
        "--known-hosts",
        required=True,
        help="Pinned OpenSSH known_hosts file for this deployment target",
    )
    parser.add_argument("--archive", required=True)
    parser.add_argument("--remote-root", required=True)
    parser.add_argument(
        "--migration",
        action="append",
        default=[],
        help="Migration path inside the archive; repeat in dependency order",
    )
    parser.add_argument(
        "--release-version",
        required=True,
        help="Release version bound to the catalog migration evidence",
    )
    parser.add_argument("--release-identity", required=True)
    parser.add_argument("--build-source-commit", required=True)
    parser.add_argument(
        "--maintenance-command",
        required=True,
        help="Reviewed remote command that enters maintenance and stops traffic/writes (it may stop PHP-FPM)",
    )
    parser.add_argument(
        "--maintenance-release-command",
        required=True,
        help="Reviewed remote command that exits maintenance after a verified recovery or deploy",
    )
    parser.add_argument(
        "--catalog-conflict-repair-mode",
        choices=("local", "server-local"),
        default="",
        help=(
            "Explicit optional conflict-repair input mode: local uses three private fixture inputs; "
            "server-local prepares the two pinned production preimages without transferring media"
        ),
    )
    parser.add_argument(
        "--catalog-conflict-repair-plan",
        default="",
        help="Local-mode private schema-2 source JSON plan (requires both prepared PNG arguments)",
    )
    parser.add_argument(
        "--catalog-conflict-repair-jpeg-png",
        default="",
        help="Local private prepared PNG for the JPEG-content conflict",
    )
    parser.add_argument(
        "--catalog-conflict-repair-heic-png",
        default="",
        help="Local private prepared PNG for the HEIC-content conflict",
    )
    parser.add_argument("--health-url", required=True)
    parser.add_argument("--app-url", default="")
    parser.add_argument("--ensure-env", action="store_true")
    parser.add_argument("--runtime-user", default="www")
    parser.add_argument("--runtime-group", default="www")
    parser.add_argument("--db-host", default="127.0.0.1")
    parser.add_argument("--db-port", type=int, default=3306)
    parser.add_argument("--db-name", required=True)
    parser.add_argument("--db-user", required=True)
    args = parser.parse_args()

    ssh_password = os.environ.get("YY_SSH_PASSWORD", "")
    db_password = os.environ.get("YY_DB_PASSWORD", "")
    if not ssh_password or not db_password:
        raise RuntimeError("YY_SSH_PASSWORD and YY_DB_PASSWORD are required")
    if not os.path.isfile(args.archive):
        raise RuntimeError(f"Archive not found: {args.archive}")
    if not os.path.isfile(args.known_hosts):
        raise RuntimeError(f"Pinned known_hosts file not found: {args.known_hosts}")
    if not args.maintenance_command.strip() or not args.maintenance_release_command.strip():
        raise RuntimeError("Maintenance entry and release commands must not be empty")
    args.remote_root = normalize_remote_root(args.remote_root)
    conflict_mode, conflict_inputs = resolve_catalog_conflict_mode(
        args.catalog_conflict_repair_mode,
        args.catalog_conflict_repair_plan,
        args.catalog_conflict_repair_jpeg_png,
        args.catalog_conflict_repair_heic_png,
    )
    conflict_enabled = conflict_mode != ""
    migrations = assert_required_release_migrations(args.migration)
    archive_sha256 = sha256_file(args.archive)
    identity_sha256, build_source_commit = validate_release_archive(
        args.archive,
        args.release_identity,
        args.release_version,
        args.build_source_commit,
    )

    stamp = time.strftime("%Y%m%d-%H%M%S")
    deploy_token = secrets.token_hex(8)
    deployment_slug = f"{stamp}-{deploy_token}"
    remote_archive = f"/tmp/yiyunying-backend-{deployment_slug}.tar.gz"
    stage_dir = f"/tmp/yiyunying-stage-{deployment_slug}"
    stage_backend = stage_dir + "/backend"
    backup_dir = f"/www/backup/yiyunying/{deployment_slug}"
    conflict_stage = f"/tmp/yiyunying-catalog-conflict-{deployment_slug}"
    conflict_source_plan = conflict_stage + "/source-plan.json"
    conflict_runtime_plan = conflict_stage + "/runtime-plan.json"
    conflict_jpeg_png = conflict_stage + "/jpeg-prepared.png"
    conflict_heic_png = conflict_stage + "/heic-prepared.png"
    conflict_batch = f"catalog-repair-{deployment_slug}"
    remote_parent = posixpath.dirname(args.remote_root.rstrip("/"))
    remote_name = posixpath.basename(args.remote_root.rstrip("/"))

    client = paramiko.SSHClient()
    client.load_host_keys(args.known_hosts)
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect(
        args.host,
        port=args.port,
        username=args.user,
        password=ssh_password,
        timeout=20,
        banner_timeout=20,
        auth_timeout=20,
        look_for_keys=False,
        allow_agent=False,
        disabled_algorithms={
            "kex": ["curve25519-sha256", "curve25519-sha256@libssh.org"]
        },
    )
    try:
        transport = client.get_transport()
        if transport is None or not transport.is_active():
            raise RuntimeError("SSH transport is not active after connect")
        transport.set_keepalive(SSH_KEEPALIVE_SECONDS)
        fingerprint = transport.get_remote_server_key().get_fingerprint().hex()
        print(f"[ssh] connected; host-key={fingerprint}")
        run(
            client,
            f"test -d {quote(args.remote_root)} && test -f {quote(args.remote_root + '/public/index.php')}",
            "preflight",
        )

        run_sftp_operation(
            client,
            "archive-upload",
            lambda sftp: sftp.put(args.archive, remote_archive, confirm=False),
        )
        run(
            client,
            archive_sha256_check_command(remote_archive, archive_sha256),
            "archive-sha256-check",
        )
        print("[upload] archive uploaded and SHA-256 verified")

        run(client, f"tar -tzf {quote(remote_archive)} >/dev/null", "archive-check")
        run(
            client,
            f"mkdir -p {quote(stage_dir)} && tar -xzf {quote(remote_archive)} -C {quote(stage_dir)} "
            f"&& test -f {quote(stage_backend + '/public/index.php')} "
            f"&& test -f {quote(stage_backend + '/tools/audit-default-credentials.php')} "
            f"&& test -f {quote(stage_backend + '/config/release-identity.json')} "
            f"&& ACTUAL_IDENTITY_SHA256=$(sha256sum {quote(stage_backend + '/config/release-identity.json')}) "
            f"&& test \"${{ACTUAL_IDENTITY_SHA256%% *}}\" = {quote(identity_sha256)}",
            "stage-files",
        )
        run(
            client,
            runtime_dependency_preflight_command(
                require_catalog_conflict_repair=conflict_enabled
            ),
            "runtime-dependency-preflight",
        )
        if conflict_mode == "local":
            run(
                client,
                f"mkdir {quote(conflict_stage)} && chmod 0700 {quote(conflict_stage)}",
                "catalog-conflict-stage-create",
            )

            def upload_catalog_conflict_inputs(sftp: Any) -> None:
                transfers = (
                    (str(conflict_inputs["plan_path"]), conflict_source_plan),
                    (
                        str(conflict_inputs["prepared"][CATALOG_CONFLICT_ACTION_JPEG]["path"]),
                        conflict_jpeg_png,
                    ),
                    (
                        str(conflict_inputs["prepared"][CATALOG_CONFLICT_ACTION_HEIC]["path"]),
                        conflict_heic_png,
                    ),
                )
                for local_path, remote_path in transfers:
                    sftp.put(local_path, remote_path, confirm=False)
                    sftp.chmod(remote_path, 0o600)

            run_sftp_operation(
                client,
                "catalog-conflict-input-upload",
                upload_catalog_conflict_inputs,
            )
            conflict_fingerprints = [
                (
                    conflict_source_plan,
                    int(conflict_inputs["plan_size_bytes"]),
                    str(conflict_inputs["plan_sha256"]),
                ),
                (
                    conflict_jpeg_png,
                    int(conflict_inputs["prepared"][CATALOG_CONFLICT_ACTION_JPEG]["size_bytes"]),
                    str(conflict_inputs["prepared"][CATALOG_CONFLICT_ACTION_JPEG]["sha256"]),
                ),
                (
                    conflict_heic_png,
                    int(conflict_inputs["prepared"][CATALOG_CONFLICT_ACTION_HEIC]["size_bytes"]),
                    str(conflict_inputs["prepared"][CATALOG_CONFLICT_ACTION_HEIC]["sha256"]),
                ),
            ]
            run(
                client,
                catalog_conflict_stage_readback_command(conflict_fingerprints),
                "catalog-conflict-input-readback",
            )
            run(
                client,
                catalog_conflict_input_preflight_command(
                    stage_backend,
                    conflict_source_plan,
                    conflict_jpeg_png,
                    conflict_heic_png,
                ),
                "catalog-conflict-input-preflight",
            )
        backup_excludes = [
            f"{remote_name}/.env",
            f"{remote_name}/storage/cache",
            f"{remote_name}/storage/logs",
            f"{remote_name}/public/uploads",
            f"{remote_name}/public/downloads",
            f"{remote_name}/public/download-center",
            f"{remote_name}/public/.codex-deploy-*",
            f"{remote_name}/public/.download-center-*",
            f"{remote_name}/releases",
        ]
        code_backup = (
            "tar -czf "
            + quote(backup_dir + "/code.tar.gz")
            + " "
            + " ".join(f"--exclude={quote(path)}" for path in backup_excludes)
            + f" -C {quote(remote_parent)} {quote(remote_name)}"
            + f" && test -s {quote(backup_dir + '/code.tar.gz')}"
            + f" && tar -tzf {quote(backup_dir + '/code.tar.gz')} >/dev/null"
            + f" && chmod 0600 {quote(backup_dir + '/code.tar.gz')}"
        )

        uploads_backup_q = quote(backup_dir + "/public-uploads.tar.gz")
        uploads_backup = (
            "set -e; "
            f"test -d {quote(args.remote_root.rstrip('/') + '/public/uploads')}; "
            f"tar -czf {uploads_backup_q} -C {quote(args.remote_root)} public/uploads; "
            f"test -s {uploads_backup_q}; tar -tzf {uploads_backup_q} >/dev/null; "
            f"chmod 0600 {uploads_backup_q}"
        )
        db_password_q = quote(db_password)
        database_q = quote(args.db_name)
        db_user_q = quote(args.db_user)
        db_host_q = quote(args.db_host)
        dump_sql_q = quote(backup_dir + "/database.sql")
        dump_path_q = quote(backup_dir + "/database.sql.gz")
        db_backup = (
            "set -e; DUMP_BIN=$(command -v mysqldump || true); "
            f"if [ -z \"$DUMP_BIN\" ] && [ -x {quote(MYSQLDUMP_BIN_FALLBACK)} ]; "
            f"then DUMP_BIN={quote(MYSQLDUMP_BIN_FALLBACK)}; fi; "
            "test -n \"$DUMP_BIN\"; "
            f"MYSQL_PWD={db_password_q} \"$DUMP_BIN\" -h {db_host_q} -P {args.db_port} "
            f"-u {db_user_q} --single-transaction --quick --routines --triggers {database_q} > {dump_sql_q}; "
            f"test -s {dump_sql_q}; gzip -c {dump_sql_q} > {dump_path_q}; "
            f"test -s {dump_path_q}; gzip -t {dump_path_q}; "
            f"chmod 0600 {dump_sql_q} {dump_path_q}; rm -f {dump_sql_q}; test -s {dump_path_q}"
        )
        remote_env = args.remote_root.rstrip("/") + "/.env"
        env_exists = run(
            client,
            f"if test -s {quote(remote_env)}; then printf yes; else printf no; fi",
            "environment-check",
        ).strip() == "yes"
        if not env_exists:
            if not args.ensure_env:
                raise RuntimeError(
                    "Production .env is missing; rerun with --ensure-env after reviewing the target"
                )
            app_url = args.app_url.strip()
            if not app_url:
                health = urlsplit(args.health_url)
                app_url = f"{health.scheme}://{health.netloc}"
            env_content = "\n".join(
                [
                    "APP_ENV=production",
                    "APP_DEBUG=false",
                    f"APP_URL={dotenv_value(app_url.rstrip('/'))}",
                    "APP_BASE_PATH=",
                    "APP_TIMEZONE=Asia/Shanghai",
                    "CORS_ORIGINS=*",
                    f"DB_HOST={dotenv_value(args.db_host)}",
                    f"DB_PORT={args.db_port}",
                    f"DB_NAME={dotenv_value(args.db_name)}",
                    f"DB_USER={dotenv_value(args.db_user)}",
                    f"DB_PASSWORD={dotenv_value(db_password)}",
                    f"QR_SIGNING_KEY={dotenv_value(secrets.token_urlsafe(48))}",
                    "",
                ]
            ).encode("utf-8")
            remote_env_tmp = remote_env + f".tmp-{stamp}"

            def upload_environment(sftp: Any) -> None:
                sftp.putfo(io.BytesIO(env_content), remote_env_tmp, confirm=True)
                sftp.chmod(remote_env_tmp, 0o640)

            run_sftp_operation(
                client,
                "environment-upload",
                upload_environment,
            )
            install_env = (
                f"id -u {quote(args.runtime_user)} >/dev/null 2>&1; "
                f"getent group {quote(args.runtime_group)} >/dev/null 2>&1; "
                f"chown {quote(args.runtime_user + ':' + args.runtime_group)} {quote(remote_env_tmp)}; "
                f"chmod 0640 {quote(remote_env_tmp)}; "
                f"mv {quote(remote_env_tmp)} {quote(remote_env)}"
            )
            run(client, install_env, "environment-create")

        runtime_read = (
            f"test -s {quote(remote_env)}; "
            f"su -s /bin/sh -c {quote('test -r ' + quote(remote_env))} {quote(args.runtime_user)}"
        )
        run(client, runtime_read, "environment-permissions")

        staged_env = stage_backend + "/.env"
        run(
            client,
            f"cp -p {quote(remote_env)} {quote(staged_env)}",
            "stage-environment",
        )
        config_assertion = (
            'require $argv[1] . "/bootstrap.php"; '
            'if ((string) config("app.env") !== "production") { exit(10); } '
            'if ((string) config("database.name") !== $argv[2]) { exit(11); } '
            'if ((string) config("database.user") !== $argv[3]) { exit(12); } '
            'if ((string) config("database.password") === "") { exit(13); } '
            'echo "configuration-ready";'
        )
        php_preflight = (
            strict_php82_bootstrap()
            + f"\"$PHP_BIN\" -r {quote(config_assertion)} {quote(stage_backend)} "
            f"{quote(args.db_name)} {quote(args.db_user)}"
        )
        run(client, php_preflight, "application-config-preflight")

        credential_audit = (
            strict_php82_bootstrap()
            + f"cd {quote(stage_backend)}; \"$PHP_BIN\" tools/audit-default-credentials.php"
        )
        # Exit 0 is the only accepted result.  Exit 1 (known defaults found) or
        # 2 (audit could not read the live DB) stops before backup/maintenance,
        # outside the rollback scope because nothing has changed yet.
        run(client, credential_audit, "default-credential-read-only-audit")
        run(
            client,
            f"mkdir -p {quote(backup_dir)} && chmod 0700 {quote(backup_dir)}",
            "backup-directory",
        )
        run(client, code_backup, "code-backup")

        restore_stage = backup_dir + "/code-restore"
        restore_code = (
            "set -e; command -v rsync >/dev/null 2>&1; "
            f"rm -rf -- {quote(restore_stage)}; mkdir -p {quote(restore_stage)}; "
            f"tar -xzf {quote(backup_dir + '/code.tar.gz')} -C {quote(restore_stage)}; "
            f"test -f {quote(restore_stage + '/' + remote_name + '/public/index.php')}; "
            f"rsync -a --delete --exclude='.env' --exclude='.git/' --exclude='storage/' "
            f"--exclude='public/uploads/' --exclude='public/downloads/' "
            f"--exclude='public/download-center/' --exclude='public/.codex-deploy-*/' "
            f"--exclude='public/.download-center-*/' --exclude='releases/' "
            f"{quote(restore_stage + '/' + remote_name + '/')} "
            f"{quote(args.remote_root.rstrip('/') + '/')}; "
            f"rm -rf -- {quote(restore_stage)}"
        )
        restore_uploads = (
            f"rm -rf {quote(args.remote_root.rstrip('/') + '/public/uploads')}; "
            f"tar -xzf {uploads_backup_q} -C {quote(args.remote_root)}"
        )
        restore_database = (
            "MYSQL_BIN=$(command -v mysql || true); "
            f"if [ -z \"$MYSQL_BIN\" ] && [ -x {quote(MYSQL_BIN_FALLBACK)} ]; "
            f"then MYSQL_BIN={quote(MYSQL_BIN_FALLBACK)}; fi; "
            "test -n \"$MYSQL_BIN\"; "
            f"gzip -t {dump_path_q}; "
            f"gzip -dc {dump_path_q} > {quote(backup_dir + '/database.restore.sql')}; "
            f"test -s {quote(backup_dir + '/database.restore.sql')}; "
            f"MYSQL_PWD={db_password_q} \"$MYSQL_BIN\" -h {db_host_q} -P {args.db_port} "
            f"-u {db_user_q} {database_q} < {quote(backup_dir + '/database.restore.sql')}; "
            f"rm -f {quote(backup_dir + '/database.restore.sql')}"
        )

        restart = (
            f"if [ -x {quote(PHP_FPM82_INIT_SCRIPT)} ]; then "
            f"{quote(PHP_FPM82_INIT_SCRIPT)} restart >/dev/null 2>&1 "
            f"|| {quote(PHP_FPM82_INIT_SCRIPT)} start >/dev/null 2>&1; "
            "elif command -v systemctl >/dev/null 2>&1 "
            f"&& systemctl show -p LoadState --value {quote(PHP_FPM82_SYSTEMD_SERVICE)} "
            "| grep -qx loaded; "
            f"then systemctl restart {quote(PHP_FPM82_SYSTEMD_SERVICE)} >/dev/null 2>&1 "
            f"|| systemctl start {quote(PHP_FPM82_SYSTEMD_SERVICE)} >/dev/null 2>&1; "
            "else exit 1; fi"
        )
        health_path = f"/tmp/yiyunying-health-{stamp}.json"
        health_assertion = (
            '$payload = json_decode((string) file_get_contents($argv[1]), true); '
            'if (!is_array($payload) || ($payload["code"] ?? null) !== 1 '
            '|| ($payload["data"]["status"] ?? null) !== "ok" '
            '|| ($payload["data"]["database"] ?? null) !== "connected") { exit(20); } '
            'echo "health-ready";'
        )
        health_check = (
            "set -e; "
            f"HTTP_CODE=$(curl -sS --max-time 20 -o {quote(health_path)} "
            f"-w '%{{http_code}}' {quote(args.health_url)}); "
            'test "$HTTP_CODE" = "200"; '
            + strict_php82_bootstrap()
            + f"\"$PHP_BIN\" -r {quote(health_assertion)} {quote(health_path)}; "
            f"rm -f {quote(health_path)}"
        )

        maintenance_attempted = False
        code_changed = False
        uploads_changed = False
        database_changed = False
        try:
            maintenance_attempted = True
            run(client, args.maintenance_command, "catalog-maintenance")

            # Database and mutable uploads must be captured only after writes
            # are stopped.  Otherwise a rollback could erase writes accepted
            # between a live backup and the maintenance transition.
            run(client, uploads_backup, "public-uploads-backup")
            run(client, db_backup, "database-backup")

            catalog_php = strict_php82_bootstrap()

            for index, migration in enumerate(migrations, start=1):
                migration_path = stage_backend + "/" + migration
                migrate = (
                    "MYSQL_BIN=$(command -v mysql || true); "
                    f"if [ -z \"$MYSQL_BIN\" ] && [ -x {quote(MYSQL_BIN_FALLBACK)} ]; "
                    f"then MYSQL_BIN={quote(MYSQL_BIN_FALLBACK)}; fi; "
                    "test -n \"$MYSQL_BIN\"; "
                    f"test -f {quote(migration_path)}; "
                    f"MYSQL_PWD={db_password_q} \"$MYSQL_BIN\" -h {db_host_q} -P {args.db_port} "
                    f"-u {db_user_q} {database_q} < {quote(migration_path)}"
                )
                database_changed = True
                run(client, migrate, f"database-migration-{index}")

            # Migration 63 closes this gate for every live app.  Refuse to
            # switch code until the live database independently reads it back.
            catalog_gate_closed = catalog_gate_readback_command(
                args.remote_root,
                catalog_php,
                ("0", "false"),
                29,
                "catalog-gate=false",
            )
            run(client, catalog_gate_closed, "catalog-gate-closed-readback")

            deploy = (
                "command -v rsync >/dev/null 2>&1; "
                f"rsync -a --delete --exclude='.env' --exclude='.git/' --exclude='storage/' "
                f"--exclude='public/uploads/' --exclude='public/downloads/' "
                f"--exclude='public/download-center/' --exclude='public/.codex-deploy-*/' "
                f"--exclude='public/.download-center-*/' --exclude='releases/' "
                f"{quote(stage_backend + '/')} {quote(args.remote_root.rstrip('/') + '/')}"
            )
            code_changed = True
            run(client, deploy, "deploy-files")

            application_origin_value = args.app_url.strip()
            if not application_origin_value:
                health_origin = urlsplit(args.health_url)
                application_origin_value = f"{health_origin.scheme}://{health_origin.netloc}"
            parsed_application_origin = urlsplit(application_origin_value)
            if parsed_application_origin.scheme not in {"http", "https"} or not parsed_application_origin.hostname:
                raise RuntimeError("A canonical application origin is required for catalog reconciliation")
            catalog_allowed_origin = f"{parsed_application_origin.scheme}://{parsed_application_origin.netloc}"

            # Optional two-item conflict repair runs only after traffic/writes
            # are stopped, both mutable backups exist, the catalog gate reads
            # closed, and the reviewed tool has been deployed. Server-local
            # preparation happens here so no production media crosses SSH.
            # Preparation is immediately bound to the same backups and repair.
            if conflict_enabled:
                if conflict_mode == "server-local":
                    preparation_output = run_redacted_capture(
                        client,
                        catalog_conflict_server_local_preparation_command(
                            args.remote_root,
                            conflict_stage,
                            conflict_batch,
                            backup_dir + "/database.sql.gz",
                            backup_dir + "/public-uploads.tar.gz",
                        ),
                        "catalog-conflict-server-local-preparation",
                    )
                    parse_catalog_conflict_server_local_receipt(
                        preparation_output, conflict_batch
                    )
                    print("[catalog-conflict-server-local-preparation] validated")
                run(
                    client,
                    catalog_conflict_runtime_plan_command(
                        args.remote_root,
                        conflict_source_plan,
                        conflict_runtime_plan,
                        conflict_jpeg_png,
                        conflict_heic_png,
                        backup_dir + "/database.sql.gz",
                        backup_dir + "/public-uploads.tar.gz",
                    ),
                    "catalog-conflict-runtime-plan-create",
                )
                conflict_php = '"$PHP_BIN"'
                if conflict_mode == "server-local":
                    conflict_php += " " + REDACTED_PHP_CLI_OPTIONS
                conflict_repair = (
                    f"cd {quote(args.remote_root)}; " + catalog_php
                    + f"{conflict_php} tools/repair-catalog-public-conflicts.php --plan {quote(conflict_runtime_plan)} "
                    + "--apply --maintenance-confirmed --backup-confirmed"
                )
                database_changed = True
                uploads_changed = True
                if conflict_mode == "server-local":
                    conflict_output = run_redacted_capture(
                        client, conflict_repair, "catalog-conflict-repair-apply"
                    )
                else:
                    conflict_output = run(client, conflict_repair, "catalog-conflict-repair-apply")
                conflict_report_name = parse_catalog_conflict_report_basename(conflict_output)
                if conflict_mode == "server-local":
                    print("[catalog-conflict-repair-apply] validated")
                conflict_report_path = (
                    args.remote_root.rstrip("/")
                    + "/storage/private/catalog-conflict-repair-reports/"
                    + conflict_report_name
                )
                run(
                    client,
                    catalog_conflict_report_assertion_command(conflict_report_path, "apply"),
                    "catalog-conflict-repair-report-check",
                )
                conflict_readback = (
                    f"cd {quote(args.remote_root)}; " + catalog_php
                    + f"{conflict_php} tools/repair-catalog-public-conflicts.php --plan {quote(conflict_runtime_plan)} "
                    + "--maintenance-confirmed --backup-confirmed"
                )
                if conflict_mode == "server-local":
                    conflict_readback_output = run_redacted_capture(
                        client, conflict_readback, "catalog-conflict-repair-readback"
                    )
                else:
                    conflict_readback_output = run(
                        client,
                        conflict_readback,
                        "catalog-conflict-repair-readback",
                    )
                conflict_readback_name = parse_catalog_conflict_report_basename(conflict_readback_output)
                if conflict_mode == "server-local":
                    print("[catalog-conflict-repair-readback] validated")
                conflict_readback_path = (
                    args.remote_root.rstrip("/")
                    + "/storage/private/catalog-conflict-repair-reports/"
                    + conflict_readback_name
                )
                run(
                    client,
                    catalog_conflict_report_assertion_command(conflict_readback_path, "dry-run"),
                    "catalog-conflict-repair-readback-report-check",
                )

            catalog_binding_dry_run = (
                f"cd {quote(args.remote_root)}; " + catalog_php
                + f"\"$PHP_BIN\" tools/backfill-catalog-source-uploads.php --release-version {quote(args.release_version)} "
                + f"--allowed-origin {quote(catalog_allowed_origin)}"
            )
            run(client, catalog_binding_dry_run, "catalog-binding-dry-run")
            catalog_quarantine_dry_run = (
                f"cd {quote(args.remote_root)}; " + catalog_php
                + f"\"$PHP_BIN\" tools/quarantine-catalog-public-files.php --release-version {quote(args.release_version)}"
            )
            run_with_status(client, catalog_quarantine_dry_run, "catalog-public-quarantine-dry-run", {0, 2})

            catalog_binding_apply = catalog_binding_dry_run + " --apply --maintenance-confirmed"
            database_changed = True
            binding_output = run(client, catalog_binding_apply, "catalog-binding-apply")
            binding_reports = [
                line[len("CATALOG_BINDING_REPORT=") :].strip()
                for line in binding_output.splitlines()
                if line.startswith("CATALOG_BINDING_REPORT=")
            ]
            if len(binding_reports) != 1 or not binding_reports[0]:
                raise RuntimeError("Catalog binding apply did not return exactly one report path")
            binding_report_assertion = (
                f"cd {quote(args.remote_root)}; " + catalog_php
                + "\"$PHP_BIN\" -r "
                + quote(
                    '$report = json_decode((string) file_get_contents($argv[1]), true); '
                    "if (!is_array($report) || ($report['mode'] ?? null) !== 'apply' "
                    "|| ($report['status'] ?? null) !== 'applied_verified' "
                    "|| (int) ($report['summary']['unresolved'] ?? -1) !== 0) exit(33); "
                    "echo 'catalog-binding-report=passed';"
                )
                + f" {quote(binding_reports[0])}"
            )
            run(client, binding_report_assertion, "catalog-binding-report-check")

            catalog_quarantine_apply = catalog_quarantine_dry_run + " --apply --maintenance-confirmed"
            uploads_changed = True
            quarantine_output = run(client, catalog_quarantine_apply, "catalog-public-quarantine-apply")
            quarantine_reports = [
                line[len("report=") :].strip()
                for line in quarantine_output.splitlines()
                if line.startswith("report=")
            ]
            if len(quarantine_reports) != 1 or not quarantine_reports[0]:
                raise RuntimeError("Catalog public quarantine did not return exactly one report path")
            quarantine_report_assertion = (
                f"cd {quote(args.remote_root)}; " + catalog_php
                + "\"$PHP_BIN\" -r "
                + quote(
                    '$report = json_decode((string) file_get_contents($argv[1]), true); '
                    "if (!is_array($report) || ($report['mode'] ?? null) !== 'apply' "
                    "|| ($report['passed'] ?? null) !== true "
                    "|| (int) ($report['conflicts'] ?? -1) !== 0 "
                    "|| (int) ($report['quarantined'] ?? -1) !== (int) ($report['would_quarantine'] ?? -2)) exit(34); "
                    "echo 'catalog-public-quarantine-report=passed';"
                )
                + f" {quote(quarantine_reports[0])}"
            )
            run(client, quarantine_report_assertion, "catalog-public-quarantine-report-check")

            catalog_dry_run = (
                f"cd {quote(args.remote_root)}; " + catalog_php
                + f"\"$PHP_BIN\" tools/migrate-catalog-private-files.php --release-version {quote(args.release_version)}"
            )
            # A dry run returns 2 when it discovers work; that result is expected
            # immediately before the required apply, but any other status aborts.
            run_with_status(client, catalog_dry_run, "catalog-dry-run", {0, 2})
            catalog_apply = (
                f"cd {quote(args.remote_root)}; " + catalog_php
                + f"\"$PHP_BIN\" tools/migrate-catalog-private-files.php --release-version {quote(args.release_version)} "
                "--apply --maintenance-confirmed"
            )
            database_changed = True
            uploads_changed = True
            catalog_output = run(client, catalog_apply, "catalog-apply")
            report_lines = [line for line in catalog_output.splitlines() if line.startswith("report=")]
            if len(report_lines) != 1 or not report_lines[0][len("report=") :].strip():
                raise RuntimeError("Catalog apply did not return exactly one report path")
            report_path = report_lines[0][len("report=") :].strip()
            catalog_apply_report_assertion = (
                f"cd {quote(args.remote_root)}; " + catalog_php
                + "\"$PHP_BIN\" -r "
                + quote(
                    '$report = json_decode((string) file_get_contents($argv[1]), true); '
                    "if (!is_array($report) || ($report['mode'] ?? null) !== 'apply' "
                    "|| ($report['passed'] ?? null) !== true || ($report['runtime_gate_activated'] ?? null) !== false) exit(31); "
                    "foreach (['failed', 'unresolved', 'residual_public_uploads', 'residual_cleanup_journal', "
                    "'residual_legacy_urls', 'residual_public_files', 'residual_invalid_catalog_hashes', "
                    "'residual_catalog_metadata_mismatches', 'unsafe_public_entries'] as $key) { "
                    "if ((int) ($report[$key] ?? -1) !== 0) exit(32); } echo 'catalog-apply-report=passed';"
                )
                + f" {quote(report_path)}"
            )
            run(client, catalog_apply_report_assertion, "catalog-apply-report-check")
            catalog_verify = (
                f"cd {quote(args.remote_root)}; " + catalog_php
                + f"\"$PHP_BIN\" tools/verify-catalog-migration-report.php --report {quote(report_path)} "
                f"--release-version {quote(args.release_version)} --activate --maintenance-confirmed"
            )
            run(client, catalog_verify, "catalog-verify-activate")
            catalog_gate_readback = catalog_gate_readback_command(
                args.remote_root,
                catalog_php,
                ("1", "true"),
                30,
                "catalog-gate=true",
            )
            run(client, catalog_gate_readback, "catalog-gate-readback")

            # Treat private-stage removal as part of the deployment
            # transaction. If the stage has an unexpected entry or unsafe
            # metadata, fail while maintenance and all rollback guarantees
            # are still active rather than reporting an indeterminate release
            # after traffic has resumed.
            if conflict_enabled:
                run(
                    client,
                    catalog_conflict_stage_cleanup_command(conflict_stage),
                    "catalog-conflict-stage-cleanup",
                )

            run(client, restart, "php-start-or-restart")
            run(client, health_check, "health-check")
            run(client, args.maintenance_release_command, "catalog-maintenance-release")
        except Exception as deployment_error:
            recovery_errors: list[str] = []
            for command, label in (
                (restore_code, "code-rollback"),
                (restore_uploads, "uploads-rollback"),
                (restore_database, "database-rollback"),
            ):
                if label == "code-rollback" and not code_changed:
                    continue
                if label == "uploads-rollback" and not uploads_changed:
                    continue
                if label == "database-rollback" and not database_changed:
                    continue
                try:
                    run(client, command, label)
                except Exception as recovery_error:
                    recovery_errors.append(f"{label}: {recovery_error}")
            if maintenance_attempted and not recovery_errors:
                try:
                    run(client, restart, "php-start-or-restart-after-rollback")
                    pre_release_health_error: Exception | None = None
                    try:
                        run(client, health_check, "health-check-after-rollback")
                    except Exception as health_error:
                        # Some maintenance front doors intentionally return 503
                        # until the release command runs.  Still attempt the
                        # release, then require health immediately afterwards.
                        pre_release_health_error = health_error
                    run(client, args.maintenance_release_command, "catalog-maintenance-release-after-rollback")
                    if pre_release_health_error is not None:
                        run(client, health_check, "health-check-after-maintenance-release")
                except Exception as recovery_error:
                    recovery_errors.append(f"maintenance recovery: {recovery_error}")
            if conflict_enabled and not recovery_errors:
                try:
                    run(
                        client,
                        catalog_conflict_stage_cleanup_command(conflict_stage),
                        "catalog-conflict-stage-cleanup-after-rollback",
                    )
                except Exception as recovery_error:
                    recovery_errors.append(f"catalog conflict stage cleanup: {recovery_error}")
            run(client, f"rm -f {quote(health_path)}", "health-cleanup")
            if recovery_errors:
                raise RuntimeError(
                    f"{deployment_error}; rollback incomplete: {'; '.join(recovery_errors)}"
                ) from deployment_error
            raise
        run(
            client,
            f"rm -rf {quote(stage_dir)} && rm -f {quote(remote_archive)}",
            "temporary-cleanup",
        )
        print(f"[complete] backup={backup_dir}")
    finally:
        client.close()
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"deployment failed: {exc}", file=sys.stderr)
        raise SystemExit(1)
