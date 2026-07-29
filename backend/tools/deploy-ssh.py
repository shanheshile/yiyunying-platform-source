#!/usr/bin/env python3
"""Back up and deploy the backend over SSH without storing credentials."""

from __future__ import annotations

import argparse
import os
import posixpath
import shlex
import sys
import time

import paramiko


def quote(value: str) -> str:
    return shlex.quote(value)


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
            + f" -C {quote(remote_parent)} {quote(remote_name)}",
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
            f"test -s {dump_path_q}"
        )
        run(client, db_backup, "database-backup")

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
            "if [ -x /etc/init.d/php-fpm-80 ]; then /etc/init.d/php-fpm-80 reload >/dev/null 2>&1 "
            "|| /etc/init.d/php-fpm-80 restart >/dev/null 2>&1; "
            "elif command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files | grep -q '^php.*fpm'; "
            "then systemctl reload php8.0-fpm >/dev/null 2>&1 || true; fi"
        )
        run(client, restart, "php-reload")
        try:
            run(
                client,
                f"curl -fsS --max-time 20 {quote(args.health_url)}",
                "health-check",
            )
        except Exception:
            restore = (
                f"find {quote(args.remote_root)} -mindepth 1 -maxdepth 1 "
                "! -name storage ! -name public ! -name .env ! -name releases -exec rm -rf {} +; "
                f"tar -xzf {quote(backup_dir + '/code.tar.gz')} -C {quote(remote_parent)}"
            )
            run(client, restore, "code-rollback")
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
