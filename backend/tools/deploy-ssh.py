#!/usr/bin/env python3
"""Back up and deploy the backend over SSH without storing credentials."""

from __future__ import annotations

import argparse
import io
import os
import posixpath
import secrets
import shlex
import sys
import time
from urllib.parse import urlsplit

import paramiko


def quote(value: str) -> str:
    return shlex.quote(value)


def dotenv_value(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def run(client: paramiko.SSHClient, command: str, label: str) -> str:
    stdin, stdout, stderr = client.exec_command(command, get_pty=False)
    del stdin
    output = stdout.read().decode("utf-8", errors="replace")
    error = stderr.read().decode("utf-8", errors="replace")
    status = stdout.channel.recv_exit_status()
    if status != 0:
        raise RuntimeError(f"{label} failed ({status}): {error or output}")
    if output.strip():
        print(f"[{label}] {output.strip()}")
    return output


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", type=int, default=22)
    parser.add_argument("--user", required=True)
    parser.add_argument("--archive", required=True)
    parser.add_argument("--remote-root", required=True)
    parser.add_argument(
        "--migration",
        action="append",
        default=[],
        help="Migration path inside the archive; repeat in dependency order",
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

    stamp = time.strftime("%Y%m%d-%H%M%S")
    remote_archive = f"/tmp/yiyunying-backend-{stamp}.tar.gz"
    stage_dir = f"/tmp/yiyunying-stage-{stamp}"
    backup_dir = f"/www/backup/yiyunying/{stamp}"
    remote_parent = posixpath.dirname(args.remote_root.rstrip("/"))
    remote_name = posixpath.basename(args.remote_root.rstrip("/"))

    client = paramiko.SSHClient()
    client.load_system_host_keys()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
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
            f"&& test -f {quote(stage_dir + '/public/index.php')}",
            "stage-files",
        )
        run(client, f"mkdir -p {quote(backup_dir)}", "backup-directory")
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
        run(
            client,
            "tar -czf "
            + quote(backup_dir + "/code.tar.gz")
            + " "
            + " ".join(f"--exclude={quote(path)}" for path in backup_excludes)
            + f" -C {quote(remote_parent)} {quote(remote_name)}"
            + f" && chmod 0600 {quote(backup_dir + '/code.tar.gz')}",
            "code-backup",
        )

        db_password_q = quote(db_password)
        database_q = quote(args.db_name)
        db_user_q = quote(args.db_user)
        db_host_q = quote(args.db_host)
        dump_path_q = quote(backup_dir + "/database.sql.gz")
        db_backup = (
            "DUMP_BIN=$(command -v mysqldump || true); "
            "if [ -z \"$DUMP_BIN\" ] && [ -x /www/server/mysql/bin/mysqldump ]; "
            "then DUMP_BIN=/www/server/mysql/bin/mysqldump; fi; "
            "test -n \"$DUMP_BIN\"; "
            f"MYSQL_PWD={db_password_q} \"$DUMP_BIN\" -h {db_host_q} -P {args.db_port} "
            f"-u {db_user_q} --single-transaction --quick --routines --triggers {database_q} | gzip -c > {dump_path_q}; "
            f"test -s {dump_path_q}; chmod 0600 {dump_path_q}"
        )
        run(client, db_backup, "database-backup")

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

        staged_env = stage_dir.rstrip("/") + "/.env"
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
            f"\"$PHP_BIN\" -r {quote(config_assertion)} {quote(stage_dir)} "
            f"{quote(args.db_name)} {quote(args.db_user)}; "
            f"rm -f {quote(staged_env)}"
        )
        run(client, php_preflight, "application-config-preflight")

        for index, migration in enumerate(args.migration, start=1):
            migration_path = stage_dir.rstrip("/") + "/" + migration.lstrip("/")
            migrate = (
                "MYSQL_BIN=$(command -v mysql || true); "
                "if [ -z \"$MYSQL_BIN\" ] && [ -x /www/server/mysql/bin/mysql ]; "
                "then MYSQL_BIN=/www/server/mysql/bin/mysql; fi; "
                "test -n \"$MYSQL_BIN\"; "
                f"test -f {quote(migration_path)}; "
                f"MYSQL_PWD={db_password_q} \"$MYSQL_BIN\" -h {db_host_q} -P {args.db_port} "
                f"-u {db_user_q} {database_q} < {quote(migration_path)}"
            )
            run(client, migrate, f"database-migration-{index}")

        deploy = (
            "command -v rsync >/dev/null 2>&1; "
            f"rsync -a --delete --exclude='.env' --exclude='.git/' --exclude='storage/' "
            f"--exclude='public/uploads/' --exclude='public/downloads/' "
            f"--exclude='public/download-center/' --exclude='public/.codex-deploy-*/' "
            f"--exclude='public/.download-center-*/' --exclude='releases/' "
            f"{quote(stage_dir.rstrip('/') + '/')} {quote(args.remote_root.rstrip('/') + '/')}"
        )
        run(client, deploy, "deploy-files")

        restart = (
            "if [ -x /etc/init.d/php-fpm-82 ]; then "
            "/etc/init.d/php-fpm-82 reload >/dev/null 2>&1 "
            "|| /etc/init.d/php-fpm-82 restart >/dev/null 2>&1; "
            "elif command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files | grep -q '^php8.2-fpm'; "
            "then systemctl reload php8.2-fpm >/dev/null 2>&1; "
            "else exit 1; fi"
        )
        run(client, restart, "php-reload")
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
        try:
            run(client, health_check, "health-check")
        except Exception:
            restore = (
                f"find {quote(args.remote_root)} -mindepth 1 -maxdepth 1 "
                "! -name storage ! -name public ! -name .env ! -name releases -exec rm -rf {} +; "
                f"tar -xzf {quote(backup_dir + '/code.tar.gz')} -C {quote(remote_parent)}"
            )
            run(client, restore, "code-rollback")
            run(client, restart, "php-reload-after-rollback")
            run(client, f"rm -f {quote(health_path)}", "health-cleanup")
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
