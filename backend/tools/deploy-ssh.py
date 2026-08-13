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

import paramiko


# These migrations form one release gate.  In particular, 63 moves catalog
# files and must never be omitted or run outside the maintenance sequence.
REQUIRED_RELEASE_MIGRATIONS = (
    "database/migrations/upgrade_20260811_content_moderation_closure.sql",
    "database/migrations/upgrade_20260811_short_video_controls.sql",
    "database/migrations/upgrade_20260811_resource_store_review_closure.sql",
    "database/migrations/upgrade_20260811_management_shell_restructure.sql",
    "database/migrations/upgrade_20260814_secure_mail_settings.sql",
)


def quote(value: str) -> str:
    return shlex.quote(value)


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
            "if [ -z \"$DUMP_BIN\" ] && [ -x /www/server/mysql/bin/mysqldump ]; "
            "then DUMP_BIN=/www/server/mysql/bin/mysqldump; fi; "
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
            "PHP_BIN=/www/server/php/82/bin/php; "
            "if [ ! -x \"$PHP_BIN\" ]; then PHP_BIN=$(command -v php || true); fi; "
            "test -n \"$PHP_BIN\"; "
            f"\"$PHP_BIN\" -r {quote(config_assertion)} {quote(stage_backend)} "
            f"{quote(args.db_name)} {quote(args.db_user)}"
        )
        run(client, php_preflight, "application-config-preflight")

        credential_audit = (
            "PHP_BIN=/www/server/php/82/bin/php; "
            "if [ ! -x \"$PHP_BIN\" ]; then PHP_BIN=$(command -v php || true); fi; "
            "test -n \"$PHP_BIN\"; "
            f"cd {quote(stage_backend)}; \"$PHP_BIN\" tools/audit-default-credentials.php"
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
            "if [ -z \"$MYSQL_BIN\" ] && [ -x /www/server/mysql/bin/mysql ]; "
            "then MYSQL_BIN=/www/server/mysql/bin/mysql; fi; "
            "test -n \"$MYSQL_BIN\"; "
            f"gzip -t {dump_path_q}; "
            f"gzip -dc {dump_path_q} > {quote(backup_dir + '/database.restore.sql')}; "
            f"test -s {quote(backup_dir + '/database.restore.sql')}; "
            f"MYSQL_PWD={db_password_q} \"$MYSQL_BIN\" -h {db_host_q} -P {args.db_port} "
            f"-u {db_user_q} {database_q} < {quote(backup_dir + '/database.restore.sql')}; "
            f"rm -f {quote(backup_dir + '/database.restore.sql')}"
        )

        restart = (
            "if [ -x /etc/init.d/php-fpm-82 ]; then "
            "/etc/init.d/php-fpm-82 restart >/dev/null 2>&1 "
            "|| /etc/init.d/php-fpm-82 start >/dev/null 2>&1; "
            "elif command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files | grep -q '^php8.2-fpm'; "
            "then systemctl restart php8.2-fpm >/dev/null 2>&1 "
            "|| systemctl start php8.2-fpm >/dev/null 2>&1; "
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
            "PHP_BIN=/www/server/php/82/bin/php; "
            "if [ ! -x \"$PHP_BIN\" ]; then PHP_BIN=$(command -v php || true); fi; "
            "test -n \"$PHP_BIN\"; "
            f"\"$PHP_BIN\" -r {quote(health_assertion)} {quote(health_path)}; "
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

            catalog_php = (
                "PHP_BIN=/www/server/php/82/bin/php; "
                "if [ ! -x \"$PHP_BIN\" ]; then PHP_BIN=$(command -v php || true); fi; "
                "test -n \"$PHP_BIN\"; "
            )

            for index, migration in enumerate(migrations, start=1):
                migration_path = stage_backend + "/" + migration
                migrate = (
                    "MYSQL_BIN=$(command -v mysql || true); "
                    "if [ -z \"$MYSQL_BIN\" ] && [ -x /www/server/mysql/bin/mysql ]; "
                    "then MYSQL_BIN=/www/server/mysql/bin/mysql; fi; "
                    "test -n \"$MYSQL_BIN\"; "
                    f"test -f {quote(migration_path)}; "
                    f"MYSQL_PWD={db_password_q} \"$MYSQL_BIN\" -h {db_host_q} -P {args.db_port} "
                    f"-u {db_user_q} {database_q} < {quote(migration_path)}"
                )
                database_changed = True
                run(client, migrate, f"database-migration-{index}")

            # Migration 63 closes this gate for every live app.  Refuse to
            # switch code until the live database independently reads it back.
            catalog_gate_closed = (
                f"cd {quote(args.remote_root)}; " + catalog_php
                + "\"$PHP_BIN\" -r "
                + quote(
                    'require "bootstrap.php"; '
                    '$rows = Yiyunying\\Core\\Database::one('
                    '"SELECT COUNT(*) AS total FROM apps a LEFT JOIN app_settings s ON s.app_id = a.id '
                    "AND s.setting_key = 'catalog_private_migration_ready' "
                    "WHERE a.deleted_at IS NULL AND (s.id IS NULL OR s.value_type <> 'bool' "
                    "OR s.setting_value NOT IN ('0', 'false'))"
                    "); if ((int) ($rows['total'] ?? -1) !== 0) exit(29); echo \"catalog-gate=false\";"
                )
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
            catalog_gate_readback = (
                f"cd {quote(args.remote_root)}; " + catalog_php
                + "\"$PHP_BIN\" -r "
                + quote(
                    'require "bootstrap.php"; '
                    '$rows = Yiyunying\\Core\\Database::one('
                    '"SELECT COUNT(*) AS total FROM apps a LEFT JOIN app_settings s ON s.app_id = a.id '
                    "AND s.setting_key = 'catalog_private_migration_ready' "
                    "WHERE a.deleted_at IS NULL AND (s.id IS NULL OR s.setting_value NOT IN ('1', 'true') OR s.value_type <> 'bool')"
                    "); if ((int) ($rows['total'] ?? -1) !== 0) exit(30); echo \"catalog-gate=true\";"
                )
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
