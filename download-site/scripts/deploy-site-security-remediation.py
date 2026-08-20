#!/usr/bin/env python3
"""Atomically publish a fail-closed customer site without publishing APKs."""

from __future__ import annotations

import argparse
from dataclasses import dataclass
import hashlib
import json
import os
from pathlib import Path
import posixpath
import re
import secrets
import shlex
import stat
import sys
from urllib.parse import urlsplit, urlunsplit

try:
    import paramiko
except ModuleNotFoundError:  # Dry-run validation is intentionally dependency-free.
    paramiko = None


EXECUTE_CONFIRMATION = "SITE_ONLY_SECURITY_REMEDIATION_EXECUTE_CONFIRMED"
NGINX_CONFIRMATION = "NGINX_DEBUG_BLOCK_REMEDIATION_CONFIRMED"
EXPECTED_RELEASE_IDS = {"user", "admin", "authorized", "owner"}
REQUIRED_SITE_FILES = {
    "index.html",
    "site.js",
    "docs.js",
    "site.webmanifest",
    "logo.svg",
    "og-card.png",
    "api-docs/index.html",
    "privacy/index.html",
    "terms/index.html",
}
OPTIONAL_PUBLIC_SITE_FILES = {
    "favicon.svg",
    "file.svg",
    "globe.svg",
    "window.svg",
}
ALLOWED_ICON_FILES = {
    "icons/badge-check.svg",
    "icons/check.svg",
    "icons/chevron-down.svg",
    "icons/clipboard.svg",
    "icons/download.svg",
    "icons/external-link.svg",
    "icons/file-check-2.svg",
    "icons/lock-keyhole.svg",
    "icons/monitor-smartphone.svg",
    "icons/package-check.svg",
    "icons/shield-check.svg",
    "icons/smartphone.svg",
}
LOCAL_ONLY_SITE_FILES = {".assetsignore", "_headers", "wrangler.json"}
ALLOWED_ASSET_SUFFIXES = {".css", ".png", ".svg", ".webp", ".woff", ".woff2"}
TEXT_SUFFIXES = {".html", ".js", ".css", ".json", ".webmanifest", ".svg"}
FORBIDDEN_FILE_SUFFIXES = {".apk", ".zip", ".bundle", ".aab", ".jks", ".keystore"}
FORBIDDEN_GENERIC_TEXT = (
    "/internal-downloads",
    "internal-downloads",
    "内部下载中心",
    "YIYUNYING_INTERNAL_DOWNLOAD",
    "yiyunying-source-",
    "yiyunying-git-history-",
    "yiyunying-project-delivery-",
    "project-assets-manifest",
    "release-manifest.json",
    "SHA256SUMS.txt",
)
FAIL_CLOSED_MARKER_SETS = (
    (
        "接入资料已开放，客户端仍在发布验收",
        "下载区在完成正式发布验收前保持关闭",
    ),
    # Keep validating already-generated pre-redesign safe trees during offline
    # rollback tests. Current exports must use the first, customer-facing set.
    (
        "正式版尚未开放",
        "当前页面不会公开候选版本、安装包名称、校验值或下载地址",
    ),
)
OLD_DEBUG_PATHS = (
    "/downloads/2.7.14/yiyunying-user-v2.7.14-debug.apk",
    "/downloads/2.7.14/yiyunying-admin-v2.7.14-debug.apk",
    "/downloads/2.7.14/yiyunying-authorized-platform-v2.7.14-debug.apk",
    "/downloads/2.7.14/yiyunying-platform-owner-v2.7.14-debug.apk",
)
MAX_SITE_FILE_BYTES = 32 * 1024 * 1024
MAX_SITE_TOTAL_BYTES = 128 * 1024 * 1024


@dataclass(frozen=True)
class SiteFile:
    relative: str
    path: Path
    sha256: str
    size: int


@dataclass(frozen=True)
class LocalArtifact:
    path: Path
    sha256: str
    size: int


def quote(value: str) -> str:
    return shlex.quote(value)


def file_sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def read_json(path: Path, label: str) -> dict:
    if not path.is_file() or path.is_symlink():
        raise RuntimeError(f"Missing regular {label}: {path}")
    try:
        value = json.loads(path.read_text(encoding="utf-8-sig"))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"Cannot read {label}: {exc}") from exc
    if not isinstance(value, dict):
        raise RuntimeError(f"{label} must be a JSON object")
    return value


def normalize_public_origin(value: str) -> str:
    if not value or value != value.strip():
        raise RuntimeError("--public-origin must be an exact HTTPS origin")
    parsed = urlsplit(value)
    try:
        parsed.port
    except ValueError as exc:
        raise RuntimeError("--public-origin has an invalid port") from exc
    if (
        parsed.scheme.lower() != "https"
        or not parsed.hostname
        or parsed.username is not None
        or parsed.password is not None
        or parsed.path not in ("", "/")
        or parsed.query
        or parsed.fragment
    ):
        raise RuntimeError("--public-origin must contain only an HTTPS scheme and host")
    canonical = urlunsplit(("https", parsed.netloc.lower(), "", "", "")).rstrip("/")
    if canonical != value.rstrip("/"):
        raise RuntimeError("--public-origin must use canonical lowercase host form")
    return canonical


def validate_remote_root(value: str) -> str:
    if not value or value != value.strip() or not value.startswith("/"):
        raise RuntimeError("--remote-public-root must be an absolute POSIX path")
    normalized = posixpath.normpath(value)
    if normalized != value.rstrip("/") or normalized == "/" or normalized.count("/") < 3:
        raise RuntimeError("--remote-public-root is too broad or not normalized")
    if any(character in normalized for character in ("\x00", "\r", "\n")):
        raise RuntimeError("--remote-public-root contains an unsafe character")
    return normalized


def validate_remote_nginx_path(value: str) -> str:
    if not value or value != value.strip() or not value.startswith("/"):
        raise RuntimeError("--remote-nginx-config must be an absolute POSIX path")
    normalized = posixpath.normpath(value)
    if (
        normalized != value
        or normalized == "/"
        or normalized.count("/") < 3
        or not normalized.endswith(".conf")
        or any(character in normalized for character in ("\x00", "\r", "\n"))
    ):
        raise RuntimeError("--remote-nginx-config is unsafe or not normalized")
    return normalized


def candidate_values(metadata: dict) -> set[str]:
    if metadata.get("schemaVersion") != 4:
        raise RuntimeError("release-metadata schemaVersion must be 4")
    if metadata.get("channel") != "Stable" or metadata.get("finalizationStatus") != "pending":
        raise RuntimeError("site-only remediation requires pending Stable metadata")
    releases = metadata.get("releases")
    if not isinstance(releases, list) or len(releases) != 4:
        raise RuntimeError("release-metadata must contain exactly four candidate entries")
    ids = {str(entry.get("id")) for entry in releases if isinstance(entry, dict)}
    if ids != EXPECTED_RELEASE_IDS:
        raise RuntimeError("release-metadata candidate roles are incomplete")

    values: set[str] = set()
    version_name = metadata.get("versionName")
    if not isinstance(version_name, str) or re.fullmatch(r"\d+\.\d+\.\d+", version_name) is None:
        raise RuntimeError("release-metadata versionName is invalid")
    values.add(version_name)
    for entry in releases:
        if not isinstance(entry, dict):
            raise RuntimeError("release-metadata candidate entry must be an object")
        for field in (
            "fileName",
            "packageName",
            "versionName",
            "sha256",
            "signerSha256",
        ):
            value = entry.get(field)
            if isinstance(value, str) and value:
                values.add(value)
    for descriptor in metadata.get("projectAssets", []):
        if isinstance(descriptor, dict):
            value = descriptor.get("fileName")
            if isinstance(value, str) and value:
                values.add(value)
    for field in ("releaseIdentitySha256", "pendingManifestSha256"):
        value = metadata.get(field)
        if isinstance(value, str) and value:
            values.add(value)
    return values


def site_file_allowed(relative: str) -> bool:
    if relative in REQUIRED_SITE_FILES | OPTIONAL_PUBLIC_SITE_FILES | ALLOWED_ICON_FILES:
        return True
    path = Path(relative)
    return (
        relative.startswith("assets/")
        and path.name not in {"", ".", ".."}
        and path.suffix.lower() in ALLOWED_ASSET_SUFFIXES
        and re.fullmatch(r"[A-Za-z0-9_.-]+-[A-Za-z0-9_-]{6,}\.[A-Za-z0-9]+", path.name)
        is not None
    )


def local_only_file_allowed(relative: str) -> bool:
    if relative in LOCAL_ONLY_SITE_FILES:
        return True
    path = Path(relative)
    return (
        relative.startswith("assets/")
        and path.suffix.lower() == ".js"
        and re.fullmatch(r"[A-Za-z0-9_.-]+-[A-Za-z0-9_-]{6,}\.js", path.name)
        is not None
    )


def validate_site_tree(site_dir: Path, metadata_path: Path) -> list[SiteFile]:
    if not site_dir.is_dir() or site_dir.is_symlink():
        raise RuntimeError("static-dist must be a regular directory")
    forbidden_values = candidate_values(read_json(metadata_path, "release metadata"))
    files: list[SiteFile] = []
    text_parts: list[str] = []
    text_by_relative: dict[str, str] = {}
    local_only_relatives: list[str] = []
    total_size = 0

    for path in sorted(site_dir.rglob("*")):
        if path.is_symlink():
            raise RuntimeError(f"static-dist may not contain symlinks: {path}")
        if path.is_dir():
            continue
        if not path.is_file():
            raise RuntimeError(f"static-dist contains a non-regular entry: {path}")
        relative = path.relative_to(site_dir).as_posix()
        if local_only_file_allowed(relative):
            if path.stat().st_size > 256 * 1024:
                if relative in LOCAL_ONLY_SITE_FILES:
                    raise RuntimeError(f"static-dist local-only control file is too large: {relative}")
                if path.stat().st_size > MAX_SITE_FILE_BYTES:
                    raise RuntimeError(f"static-dist local-only bundle is too large: {relative}")
            local_only_relatives.append(relative)
            continue
        if not site_file_allowed(relative):
            raise RuntimeError(f"static-dist contains a non-whitelisted file: {relative}")
        if path.suffix.lower() in FORBIDDEN_FILE_SUFFIXES:
            raise RuntimeError(f"static-dist contains a forbidden artifact: {relative}")
        size = path.stat().st_size
        if size < 1 or size > MAX_SITE_FILE_BYTES:
            raise RuntimeError(f"static-dist file size is unsafe: {relative}")
        total_size += size
        if total_size > MAX_SITE_TOTAL_BYTES:
            raise RuntimeError("static-dist total size exceeds the remediation limit")
        files.append(SiteFile(relative, path, file_sha256(path), size))
        if path.suffix.lower() in TEXT_SUFFIXES or path.name == "site.webmanifest":
            try:
                text = path.read_text(encoding="utf-8")
            except (OSError, UnicodeError) as exc:
                raise RuntimeError(f"static-dist text is not valid UTF-8: {relative}") from exc
            if "\ufffd" in text:
                raise RuntimeError(f"static-dist text contains a replacement character: {relative}")
            text_by_relative[relative] = text
            text_parts.append(text)

    discovered = {item.relative for item in files}
    missing = sorted(REQUIRED_SITE_FILES - discovered)
    if missing:
        raise RuntimeError(f"static-dist required whitelist is incomplete: {missing}")
    if not any(item.relative.startswith("assets/") and item.path.suffix.lower() == ".css" for item in files):
        raise RuntimeError("static-dist must contain a content stylesheet")

    public_text = "\n".join(text_parts)
    lower_text = public_text.lower()
    for relative in local_only_relatives:
        if relative in LOCAL_ONLY_SITE_FILES:
            continue
        if relative.lower() in lower_text or Path(relative).name.lower() in lower_text:
            raise RuntimeError("static-dist public files reference a local-only JavaScript bundle")
    for marker in FORBIDDEN_GENERIC_TEXT:
        if marker.lower() in lower_text:
            raise RuntimeError(f"static-dist leaks a forbidden internal/private marker: {marker}")
    for value in sorted(forbidden_values, key=len, reverse=True):
        if value and value.lower() in lower_text:
            raise RuntimeError("static-dist leaks pending candidate metadata")
    if re.search(r"(?:href|src)=[\"'][^\"']+\.apk(?:[?#][^\"']*)?[\"']", public_text, re.I):
        raise RuntimeError("static-dist contains an APK URL")
    if re.search(r"\sdownload(?:\s|=|>)", public_text, re.I):
        raise RuntimeError("static-dist contains a download attribute")

    index_text = text_by_relative.get("index.html", "")
    if not any(
        all(marker in index_text for marker in markers)
        for markers in FAIL_CLOSED_MARKER_SETS
    ):
        raise RuntimeError("fail-closed customer marker set is missing")
    site_script = text_by_relative.get("site.js", "")
    if "publicRelease" in site_script and not re.search(
        r"\bconst\s+publicRelease\s*=\s*null\s*;", site_script
    ):
        raise RuntimeError("site.js is not bound to a null publicRelease")
    return files


def validate_nginx_config(path: Path, repository_root: Path) -> LocalArtifact:
    expected = (repository_root / "download-site" / "deploy" / "nginx-download-center.conf").resolve()
    if path.resolve() != expected:
        raise RuntimeError("Nginx remediation accepts only the reviewed repository config")
    if not path.is_file() or path.is_symlink():
        raise RuntimeError("Reviewed Nginx config must be a regular non-symlink file")
    text = path.read_text(encoding="utf-8")
    required = (
        "location ^~ /download-center/",
        "location = /downloads",
        "location /downloads/",
        "application/vnd.android.package-archive",
        "yiyunying-(?:user|admin|authorized-platform|platform-owner)",
    )
    if any(marker not in text for marker in required):
        raise RuntimeError("Reviewed Nginx config is missing a required security rule")
    if len(re.findall(r"return\s+404\s*;", text)) < 2:
        raise RuntimeError("Reviewed Nginx config must fail closed for /downloads")
    if "-debug.apk" in text.lower() or re.search(r"\b(alias|proxy_pass)\b", text, re.I):
        raise RuntimeError("Reviewed Nginx config contains an unsafe download rule")
    return LocalArtifact(path, file_sha256(path), path.stat().st_size)


def connect_ssh(args: argparse.Namespace):
    if paramiko is None:
        raise RuntimeError(
            "Paramiko is required only for --execute; install the pinned deployment dependencies"
        )
    known_hosts = Path(args.known_hosts).resolve()
    if not known_hosts.is_file() or known_hosts.is_symlink():
        raise RuntimeError("--known-hosts must be a regular pinned host-key file")
    client = paramiko.SSHClient()
    client.load_host_keys(str(known_hosts))
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect(
        args.host,
        port=args.port,
        username=args.username,
        password=os.environ["YY_SSH_PASSWORD"],
        look_for_keys=False,
        allow_agent=False,
        timeout=20,
        banner_timeout=20,
        auth_timeout=20,
    )
    return client


def run(client, command: str, label: str = "remote operation") -> str:
    _, stdout, stderr = client.exec_command(command, get_pty=False)
    exit_code = stdout.channel.recv_exit_status()
    output = stdout.read().decode("utf-8", errors="replace").strip()
    error = stderr.read().decode("utf-8", errors="replace").strip()
    if exit_code != 0:
        detail = error or output
        if len(detail) > 800:
            detail = detail[:800] + "...<truncated>"
        raise RuntimeError(f"{label} failed ({exit_code}): {detail}")
    return output


def remote_identity(client, path: str) -> tuple[int, str]:
    output = run(
        client,
        f"test -f {quote(path)} && test ! -L {quote(path)} && "
        f"printf '%s ' \"$(stat -c %s {quote(path)})\" && sha256sum {quote(path)} | awk '{{print $1}}'",
        "remote file identity",
    )
    parts = output.split()
    if len(parts) != 2 or not parts[0].isdigit() or re.fullmatch(r"[0-9a-f]{64}", parts[1]) is None:
        raise RuntimeError("Remote file identity is malformed")
    return int(parts[0]), parts[1]


def ensure_active_ssh(client, args: argparse.Namespace):
    transport = client.get_transport() if client is not None else None
    if transport is not None and transport.is_active():
        return client
    if client is not None:
        client.close()
    return connect_ssh(args)


def upload_site(sftp, files: list[SiteFile], remote_site: str) -> None:
    directories = {posixpath.dirname(item.relative) for item in files if posixpath.dirname(item.relative)}
    for relative in sorted(directories, key=lambda value: (value.count("/"), value)):
        current = remote_site
        for part in relative.split("/"):
            current = posixpath.join(current, part)
            try:
                sftp.mkdir(current, mode=0o755)
            except OSError:
                attributes = sftp.stat(current)
                if not stat.S_ISDIR(attributes.st_mode):
                    raise RuntimeError(f"Remote site path is not a directory: {current}")
    for item in files:
        remote_path = posixpath.join(remote_site, item.relative)
        sftp.put(str(item.path), remote_path, confirm=True)
        sftp.chmod(remote_path, 0o644)


def site_activation_command(
    remote_site: str,
    staging_site: str,
    rollback_site: str,
    had_previous_site: bool,
) -> str:
    commands = ["set -eu"]
    if had_previous_site:
        commands.append(f"mv {quote(remote_site)} {quote(rollback_site)}")
    commands.extend(
        [
            f"test ! -e {quote(remote_site)}",
            f"mv {quote(staging_site)} {quote(remote_site)}",
        ]
    )
    return " ; ".join(commands)


def site_rollback_command(
    remote_site: str,
    staging_site: str,
    rollback_site: str,
    had_previous_site: bool,
) -> str:
    commands = ["set -eu"]
    if had_previous_site:
        commands.append(
            f"if [ -d {quote(rollback_site)} ]; then "
            f"if [ -d {quote(remote_site)} ]; then test ! -e {quote(staging_site)}; "
            f"mv {quote(remote_site)} {quote(staging_site)}; fi; "
            f"test ! -e {quote(remote_site)}; mv {quote(rollback_site)} {quote(remote_site)}; fi"
        )
    else:
        commands.append(
            f"if [ -d {quote(remote_site)} ] && [ ! -e {quote(staging_site)} ]; then "
            f"mv {quote(remote_site)} {quote(staging_site)}; fi"
        )
    return " ; ".join(commands)


def nginx_activation_command(remote: str, candidate: str, backup: str) -> str:
    return " ; ".join(
        (
            "set -eu",
            f"test -f {quote(remote)} && test ! -L {quote(remote)}",
            f"test -f {quote(candidate)} && test ! -L {quote(candidate)}",
            f"test ! -e {quote(backup)}",
            f"mv {quote(remote)} {quote(backup)}",
            f"mv {quote(candidate)} {quote(remote)}",
            "nginx -t",
            "nginx -s reload",
        )
    )


def nginx_rollback_command(remote: str, candidate: str, backup: str) -> str:
    return " ; ".join(
        (
            "set -eu",
            f"if [ -f {quote(backup)} ]; then "
            f"rm -f -- {quote(candidate)}; "
            f"if [ -f {quote(remote)} ]; then mv {quote(remote)} {quote(candidate)}; fi; "
            f"mv {quote(backup)} {quote(remote)}; nginx -t; nginx -s reload; "
            f"rm -f -- {quote(candidate)}; else rm -f -- {quote(candidate)}; fi",
        )
    )


def public_index_verification_command(
    local_index: SiteFile,
    public_origin: str,
    scratch: str,
) -> str:
    url = f"{public_origin}/download-center/index.html"
    headers = scratch + ".headers"
    body = scratch + ".body"
    return " ; ".join(
        (
            "set -eu",
            f"status=$(curl -sS --proto '=https' --tlsv1.2 --max-redirs 0 --max-time 60 "
            f"-D {quote(headers)} -o {quote(body)} -w '%{{http_code}}' {quote(url)})",
            'test "$status" = 200',
            f"test \"$(stat -c %s {quote(body)})\" -eq {local_index.size}",
            f"test \"$(sha256sum {quote(body)} | awk '{{print $1}}')\" = {quote(local_index.sha256)}",
            f"grep -Eiq '^Content-Type:[[:space:]]*text/html' {quote(headers)}",
            f"rm -f -- {quote(headers)} {quote(body)}",
        )
    )


def old_debug_probe_command(public_origin: str) -> str:
    commands = ["set -eu"]
    for path in OLD_DEBUG_PATHS:
        url = public_origin + path
        commands.append(
            f"status=$(curl -sS --proto '=https' --tlsv1.2 --max-redirs 0 --max-time 30 "
            f"-o /dev/null -w '%{{http_code}}' {quote(url)}); test \"$status\" = 404"
        )
    return " ; ".join(commands)


def validate_execution_args(args: argparse.Namespace, repository_root: Path) -> tuple[Path, Path, str, str, LocalArtifact | None]:
    site_dir = Path(args.site_dir).resolve()
    expected_site = (repository_root / "download-site" / "static-dist").resolve()
    if site_dir != expected_site:
        raise RuntimeError("Site remediation accepts only download-site/static-dist")
    metadata_path = (repository_root / "download-site" / "release-metadata.json").resolve()
    public_origin = normalize_public_origin(args.public_origin)
    remote_root = validate_remote_root(args.remote_public_root)
    nginx_artifact = None
    has_nginx_path = bool(args.nginx_config or args.remote_nginx_config)
    if has_nginx_path and not (args.nginx_config and args.remote_nginx_config):
        raise RuntimeError("Nginx remediation requires both local and remote config paths")
    if args.nginx_config:
        nginx_artifact = validate_nginx_config(Path(args.nginx_config), repository_root)
        validate_remote_nginx_path(args.remote_nginx_config)
        if args.execute and args.nginx_confirmation != NGINX_CONFIRMATION:
            raise RuntimeError(f"Nginx remediation requires --nginx-confirmation {NGINX_CONFIRMATION}")
        if not args.execute and args.nginx_confirmation:
            raise RuntimeError("--nginx-confirmation is accepted only together with --execute")
    elif args.nginx_confirmation:
        raise RuntimeError("--nginx-confirmation is invalid without Nginx remediation")
    if args.execute:
        if args.confirmation != EXECUTE_CONFIRMATION:
            raise RuntimeError(f"Execution requires --confirmation {EXECUTE_CONFIRMATION}")
        if not args.host or not args.known_hosts:
            raise RuntimeError("Execution requires --host and --known-hosts")
        if not isinstance(args.port, int) or args.port < 1 or args.port > 65535:
            raise RuntimeError("--port must be between 1 and 65535")
        if not os.environ.get("YY_SSH_PASSWORD", ""):
            raise RuntimeError("YY_SSH_PASSWORD is required for execution")
    elif args.confirmation:
        raise RuntimeError("--confirmation is accepted only together with --execute")
    return site_dir, metadata_path, public_origin, remote_root, nginx_artifact


def deploy(
    args: argparse.Namespace,
    site_files: list[SiteFile],
    public_origin: str,
    remote_root: str,
    nginx_artifact: LocalArtifact | None,
) -> None:
    remote_site = posixpath.join(remote_root, "download-center")
    token = secrets.token_hex(16)
    staging_root = posixpath.join(remote_root, f".site-security-remediation-{token}")
    staging_site = posixpath.join(staging_root, "download-center")
    rollback_site = posixpath.join(remote_root, f".download-center.security-previous-{token}")
    lock_dir = posixpath.join(remote_root, ".site-security-remediation.lock")
    remote_nginx = validate_remote_nginx_path(args.remote_nginx_config) if nginx_artifact else ""
    nginx_candidate = f"{remote_nginx}.candidate-{token}" if nginx_artifact else ""
    nginx_backup = f"{remote_nginx}.backup-{token}" if nginx_artifact else ""

    ssh = None
    lock_acquired = False
    candidate_started = False
    site_started = False
    nginx_started = False
    had_previous_site = False
    completed = False
    rollback_ok = False
    try:
        ssh = connect_ssh(args)
        run(
            ssh,
            f"test -d {quote(remote_root)} && test ! -L {quote(remote_root)} && "
            "command -v curl >/dev/null && command -v sha256sum >/dev/null && "
            "command -v stat >/dev/null && "
            f"mkdir {quote(lock_dir)}",
            "acquire site remediation lock",
        )
        lock_acquired = True
        state = run(
            ssh,
            f"test ! -e {quote(staging_root)} && test ! -e {quote(rollback_site)} && "
            f"mkdir {quote(staging_root)} && mkdir {quote(staging_site)} && "
            f"test \"$(stat -c %d {quote(remote_root)})\" = \"$(stat -c %d {quote(staging_root)})\" && "
            f"if [ -e {quote(remote_site)} ]; then test -d {quote(remote_site)} && "
            f"test ! -L {quote(remote_site)} && "
            f"test \"$(stat -c %d {quote(remote_site)})\" = \"$(stat -c %d {quote(staging_root)})\" && "
            "printf present; else printf absent; fi",
            "prepare unique same-volume site staging",
        )
        had_previous_site = state == "present"
        if state not in {"present", "absent"}:
            raise RuntimeError("Cannot determine the current customer-site state")
        candidate_started = True

        with ssh.open_sftp() as sftp:
            upload_site(sftp, site_files, staging_site)
            if nginx_artifact:
                sftp.put(str(nginx_artifact.path), nginx_candidate, confirm=True)
                sftp.chmod(nginx_candidate, 0o644)

        for item in site_files:
            if remote_identity(ssh, posixpath.join(staging_site, item.relative)) != (item.size, item.sha256):
                raise RuntimeError(f"Remote staged site identity mismatch: {item.relative}")
        if nginx_artifact and remote_identity(ssh, nginx_candidate) != (nginx_artifact.size, nginx_artifact.sha256):
            raise RuntimeError("Remote staged Nginx identity mismatch")

        if nginx_artifact:
            nginx_started = True
            run(
                ssh,
                nginx_activation_command(remote_nginx, nginx_candidate, nginx_backup),
                "activate reviewed Nginx security policy",
            )
            if remote_identity(ssh, remote_nginx) != (nginx_artifact.size, nginx_artifact.sha256):
                raise RuntimeError("Activated Nginx identity mismatch")
            run(ssh, old_debug_probe_command(public_origin), "verify old Debug URLs are blocked")

        site_started = True
        run(
            ssh,
            site_activation_command(remote_site, staging_site, rollback_site, had_previous_site),
            "atomically activate fail-closed customer site",
        )
        for item in site_files:
            if remote_identity(ssh, posixpath.join(remote_site, item.relative)) != (item.size, item.sha256):
                raise RuntimeError(f"Activated customer-site identity mismatch: {item.relative}")
        local_index = next(item for item in site_files if item.relative == "index.html")
        run(
            ssh,
            public_index_verification_command(
                local_index,
                public_origin,
                posixpath.join(staging_root, "public-index-readback"),
            ),
            "verify complete public customer index readback",
        )
        completed = True
        rollback_ok = True
        print(
            "Site-only security remediation completed; no APK or /downloads path was published."
        )
    except Exception:
        if ssh is not None and candidate_started:
            rollback_ok = False
            try:
                ssh = ensure_active_ssh(ssh, args)
                if site_started:
                    run(
                        ssh,
                        site_rollback_command(remote_site, staging_site, rollback_site, had_previous_site),
                        "rollback customer site",
                    )
                if nginx_started:
                    run(
                        ssh,
                        nginx_rollback_command(remote_nginx, nginx_candidate, nginx_backup),
                        "restore previous Nginx policy",
                    )
                rollback_ok = True
            except Exception as rollback_error:
                print(
                    "ROLLBACK INCOMPLETE; remediation lock retained. "
                    f"staging={staging_root}, previous={rollback_site}, error={rollback_error}",
                    file=sys.stderr,
                )
        elif lock_acquired:
            rollback_ok = True
        raise
    finally:
        if ssh is not None:
            if lock_acquired and (completed or rollback_ok):
                try:
                    ssh = ensure_active_ssh(ssh, args)
                    cleanup = [staging_root]
                    if completed:
                        cleanup.append(rollback_site)
                    cleanup_command = "rm -rf -- " + " ".join(quote(path) for path in cleanup)
                    if nginx_artifact:
                        cleanup_command += (
                            f" ; rm -f -- {quote(nginx_candidate)}"
                            + (f" {quote(nginx_backup)}" if completed else "")
                        )
                    cleanup_command += f" ; rmdir {quote(lock_dir)}"
                    run(ssh, cleanup_command, "cleanup remediation transaction")
                except Exception as cleanup_error:
                    print(
                        "CLEANUP INCOMPLETE; remediation lock retained. "
                        f"staging={staging_root}, error={cleanup_error}",
                        file=sys.stderr,
                    )
            ssh.close()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site-dir", type=Path, default=Path("download-site/static-dist"))
    parser.add_argument("--public-origin", default="https://appht.jjmxg.xyz")
    parser.add_argument(
        "--remote-public-root",
        default="/www/wwwroot/appht.jjmxg.xyz/yiyunying-backend/public",
    )
    parser.add_argument("--host")
    parser.add_argument("--port", type=int, default=22)
    parser.add_argument("--username", default="root")
    parser.add_argument("--known-hosts")
    parser.add_argument("--execute", action="store_true")
    parser.add_argument("--confirmation", default="")
    parser.add_argument("--nginx-config", type=Path)
    parser.add_argument("--remote-nginx-config", default="")
    parser.add_argument("--nginx-confirmation", default="")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    repository_root = Path(__file__).resolve().parents[2]
    site_dir, metadata_path, public_origin, remote_root, nginx_artifact = validate_execution_args(
        args, repository_root
    )
    site_files = validate_site_tree(site_dir, metadata_path)
    if not args.execute:
        print(
            f"DRY RUN PASS: {len(site_files)} fail-closed site files verified; "
            "no SSH connection, deployment, APK publication or /downloads mutation occurred."
        )
        if nginx_artifact:
            print(
                "DRY RUN PASS: reviewed Nginx config verified; no upload, nginx -t or reload occurred."
            )
        return 0
    deploy(args, site_files, public_origin, remote_root, nginx_artifact)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"Site security remediation failed: {type(exc).__name__}: {exc}", file=sys.stderr)
        raise SystemExit(1)
