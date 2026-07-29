#!/usr/bin/env python3
"""Inspect a production deployment over SSH without storing credentials."""

from __future__ import annotations

import argparse
import os
import shlex
import sys

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
    print(f"[{label}]\n{output.strip()}")
    return output


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", type=int, default=22)
    parser.add_argument("--user", required=True)
    parser.add_argument("--remote-root", required=True)
    parser.add_argument("--db-host", default="127.0.0.1")
    parser.add_argument("--db-port", type=int, default=3306)
    parser.add_argument("--db-name", required=True)
    parser.add_argument("--db-user", required=True)
    parser.add_argument("--tail-logs", action="store_true")
    parser.add_argument("--recent-errors", action="store_true")
    parser.add_argument("--list-migrations", action="store_true")
    args = parser.parse_args()

    ssh_password = os.environ.get("YY_SSH_PASSWORD", "")
    db_password = os.environ.get("YY_DB_PASSWORD", "")
    if not ssh_password or not db_password:
        raise RuntimeError("YY_SSH_PASSWORD and YY_DB_PASSWORD are required")

    client = paramiko.SSHClient()
    client.load_system_host_keys()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(
        args.host,
        port=args.port,
        username=args.user,
        password=ssh_password,
        timeout=30,
        banner_timeout=60,
        auth_timeout=30,
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
            " && ".join(
                [
                    f"test -f {quote(args.remote_root + '/public/index.php')}",
                    f"test -f {quote(args.remote_root + '/app/Services/RedPacketAmountService.php')}",
                    f"sha256sum {quote(args.remote_root + '/app/Services/RedPacketAmountService.php')}",
                    f"sha256sum {quote(args.remote_root + '/app/Controllers/User/CommerceController.php')}",
                ]
            ),
            "deployed-code",
        )

        query = """
SELECT 'schema_migration' AS item, COUNT(*) AS value
FROM schema_migrations
WHERE version = '2026.07.22-random-red-packet-money';
SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS item, COLUMN_TYPE AS value
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND ((TABLE_NAME = 'red_packets' AND COLUMN_NAME IN ('total_amount','packet_label'))
    OR (TABLE_NAME = 'red_packet_claims' AND COLUMN_NAME = 'amount')
    OR (TABLE_NAME IN ('transfers','gifts') AND COLUMN_NAME = 'amount'))
ORDER BY TABLE_NAME, ORDINAL_POSITION;
SELECT id, admin_id, app_key, name, status FROM apps ORDER BY id;
""".strip()
        mysql_command = (
            "MYSQL_BIN=$(command -v mysql || true); "
            "if [ -z \"$MYSQL_BIN\" ] && [ -x /www/server/mysql/bin/mysql ]; "
            "then MYSQL_BIN=/www/server/mysql/bin/mysql; fi; "
            "test -n \"$MYSQL_BIN\"; "
            f"MYSQL_PWD={quote(db_password)} \"$MYSQL_BIN\" --batch --raw --skip-column-names "
            f"-h {quote(args.db_host)} -P {args.db_port} -u {quote(args.db_user)} "
            f"{quote(args.db_name)} -e {quote(query)}"
        )
        run(client, mysql_command, "database-schema-and-apps")
        if args.list_migrations:
            migration_query = """
SELECT version, applied_at
FROM schema_migrations
ORDER BY applied_at, version;
""".strip()
            migration_command = (
                "MYSQL_BIN=$(command -v mysql || true); "
                "if [ -z \"$MYSQL_BIN\" ] && [ -x /www/server/mysql/bin/mysql ]; "
                "then MYSQL_BIN=/www/server/mysql/bin/mysql; fi; "
                "test -n \"$MYSQL_BIN\"; "
                f"MYSQL_PWD={quote(db_password)} \"$MYSQL_BIN\" --batch --raw "
                f"-h {quote(args.db_host)} -P {args.db_port} -u {quote(args.db_user)} "
                f"{quote(args.db_name)} -e {quote(migration_query)}"
            )
            run(client, migration_command, "schema-migrations")
        if args.recent_errors:
            error_query = """
SELECT id, created_at, path, error_class, error_message, error_file, error_line, trace_id
FROM system_error_logs
ORDER BY id DESC
LIMIT 40;
""".strip()
            error_command = (
                "MYSQL_BIN=$(command -v mysql || true); "
                "if [ -z \"$MYSQL_BIN\" ] && [ -x /www/server/mysql/bin/mysql ]; "
                "then MYSQL_BIN=/www/server/mysql/bin/mysql; fi; "
                "test -n \"$MYSQL_BIN\"; "
                f"MYSQL_PWD={quote(db_password)} \"$MYSQL_BIN\" --batch --raw "
                f"-h {quote(args.db_host)} -P {args.db_port} -u {quote(args.db_user)} "
                f"{quote(args.db_name)} -e {quote(error_query)}"
            )
            run(client, error_command, "recent-system-errors")
        if args.tail_logs:
            run(
                client,
                "\n".join(
                    [
                        "printf '%s\\n' '[application logs]'",
                        f"find {quote(args.remote_root + '/storage')} -maxdepth 3 -type f \\( -name '*.log' -o -name '*.err.log' -o -name '*.out.log' \\) -printf '%T@ %p\\n' 2>/dev/null | sort -nr | head -n 20 | cut -d' ' -f2-",
                        f"find {quote(args.remote_root + '/storage')} -maxdepth 3 -type f \\( -name '*.log' -o -name '*.err.log' -o -name '*.out.log' \\) -printf '%T@ %p\\n' 2>/dev/null | sort -nr | head -n 8 | cut -d' ' -f2- | while IFS= read -r file; do printf '\\n== %s ==\\n' \"$file\"; tail -n 120 \"$file\"; done",
                        "printf '%s\\n' '[nginx error log]'",
                        "tail -n 160 /www/wwwlogs/appht.jjmxg.xyz.error.log 2>/dev/null || true",
                    ]
                ),
                "recent-logs",
            )
    finally:
        client.close()
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"inspection failed: {exc}", file=sys.stderr)
        raise SystemExit(1)
