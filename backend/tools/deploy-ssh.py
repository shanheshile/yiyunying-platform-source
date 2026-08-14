#!/usr/bin/env python3
"""Back up and deploy the backend over SSH without storing credentials."""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import os
import posixpath
import re
import secrets
import shlex
import sys
import tarfile
import time
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

PHP82_BIN = "/www/server/php/82/bin/php"
MYSQL_BIN_FALLBACK = "/www/server/mysql/bin/mysql"
MYSQLDUMP_BIN_FALLBACK = "/www/server/mysql/bin/mysqldump"
PHP_FPM82_INIT_SCRIPT = "/etc/init.d/php-fpm-82"
PHP_FPM82_SYSTEMD_SERVICE = "php8.2-fpm.service"


def quote(value: str) -> str:
    return shlex.quote(value)


def strict_php82_bootstrap(php_bin: str = PHP82_BIN) -> str:
    """Select only the reviewed PHP 8.2 binary used by this deployment."""
    return (
        f"PHP_BIN={quote(php_bin)}; "
        'test -x "$PHP_BIN"; '
        'test ! -L "$PHP_BIN"; '
    )


def runtime_dependency_preflight_command(
    php_bin: str = PHP82_BIN,
    mysql_fallback: str = MYSQL_BIN_FALLBACK,
    mysqldump_fallback: str = MYSQLDUMP_BIN_FALLBACK,
    fpm_init_script: str = PHP_FPM82_INIT_SCRIPT,
    fpm_systemd_service: str = PHP_FPM82_SYSTEMD_SERVICE,
) -> str:
    """Build the fail-closed production runtime dependency preflight."""
    php_probe = (
        'if (PHP_VERSION_ID < 80200) exit(40); '
        'foreach (["PDO", "pdo_mysql", "mbstring", "json", "hash"] as $extension) { '
        'if (!extension_loaded($extension)) exit(41); } '
        'foreach (["getimagesize", "disk_free_space", "hash_file", "json_encode"] as $function) { '
        'if (!function_exists($function)) exit(42); } '
        'echo "runtime-dependencies-ready";'
    )
    return (
        "set -e; "
        + strict_php82_bootstrap(php_bin)
        + 'for TOOL in tar sha256sum gzip rsync curl; do '
        + 'command -v "$TOOL" >/dev/null 2>&1; done; '
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
    stdin, stdout, stderr = client.exec_command(command, get_pty=False)
    del stdin
    output = stdout.read().decode("utf-8", errors="replace")
    error = stderr.read().decode("utf-8", errors="replace")
    status = stdout.channel.recv_exit_status()
    if status not in allowed_statuses:
        raise RuntimeError(f"{label} failed ({status}): {error or output}")
    if output.strip():
        print(f"[{label}] {output.strip()}")
    return output


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
        "backend/public/index.php",
        "backend/config/release-identity.json",
        "backend/tools/audit-default-credentials.php",
        "backend/tools/backfill-catalog-source-uploads.php",
        "backend/tools/catalog-legacy-upload-binding.php",
        "backend/tools/catalog-private-retention.php",
        "backend/tools/catalog-public-quarantine-contract.php",
        "backend/tools/quarantine-catalog-public-files.php",
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
    migrations = assert_required_release_migrations(args.migration)
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
        fingerprint = client.get_transport().get_remote_server_key().get_fingerprint().hex()
        print(f"[ssh] connected; host-key={fingerprint}")
        run(
            client,
            f"test -d {quote(args.remote_root)} && test -f {quote(args.remote_root + '/public/index.php')}",
            "preflight",
        )

        sftp = client.open_sftp()
        try:
            sftp.put(args.archive, remote_archive, confirm=True)
        finally:
            sftp.close()
        print("[upload] archive uploaded")

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
            runtime_dependency_preflight_command(),
            "runtime-dependency-preflight",
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
            sftp = client.open_sftp()
            try:
                sftp.putfo(io.BytesIO(env_content), remote_env_tmp, confirm=True)
                sftp.chmod(remote_env_tmp, 0o640)
            finally:
                sftp.close()
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
        run(client, f"mkdir -p {quote(backup_dir)}", "backup-directory")
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
            run(client, f"rm -f {quote(health_path)}", "health-cleanup")
            if recovery_errors:
                raise RuntimeError(
                    f"{deployment_error}; rollback incomplete: {'; '.join(recovery_errors)}"
                ) from deployment_error
            raise
        run(client, f"rm -rf {quote(stage_dir)} && rm -f {quote(remote_archive)}", "temporary-cleanup")
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
