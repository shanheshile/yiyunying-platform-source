#!/usr/bin/env python3
"""Verify deployed Android lifecycle responses using production database context."""

from __future__ import annotations

import argparse
import json
import os
import shlex
import sys
import time
import urllib.parse

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
    return output


def mysql_query(
    client: paramiko.SSHClient,
    args: argparse.Namespace,
    db_password: str,
    query: str,
    label: str,
) -> str:
    command = (
        "MYSQL_BIN=$(command -v mysql || true); "
        "if [ -z \"$MYSQL_BIN\" ] && [ -x /www/server/mysql/bin/mysql ]; "
        "then MYSQL_BIN=/www/server/mysql/bin/mysql; fi; "
        "test -n \"$MYSQL_BIN\"; "
        f"MYSQL_PWD={quote(db_password)} \"$MYSQL_BIN\" --default-character-set=utf8mb4 "
        "--batch --raw --skip-column-names "
        f"-h {quote(args.db_host)} -P {args.db_port} -u {quote(args.db_user)} "
        f"{quote(args.db_name)} -e {quote(query)}"
    )
    return run(client, command, label)


def parse_expected(value: str) -> tuple[str, dict[str, object]]:
    parts = value.split("|", 3)
    if len(parts) != 4:
        raise argparse.ArgumentTypeError("expected must be edition|package|sha256|size")
    edition, package_name, sha256, size = (part.strip() for part in parts)
    if edition not in {"platform_owner", "authorized_platform", "admin", "user"}:
        raise argparse.ArgumentTypeError(f"unsupported edition: {edition}")
    if len(sha256) != 64 or any(char not in "0123456789abcdefABCDEF" for char in sha256):
        raise argparse.ArgumentTypeError(f"invalid sha256 for {edition}")
    if not size.isdigit() or int(size) <= 0:
        raise argparse.ArgumentTypeError(f"invalid size for {edition}")
    return edition, {"package": package_name, "sha256": sha256.lower(), "size": int(size)}


def connect_ssh(args: argparse.Namespace, password: str) -> paramiko.SSHClient:
    last_error: Exception | None = None
    for attempt in range(1, 6):
        client = paramiko.SSHClient()
        client.load_system_host_keys()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        try:
            client.connect(
                args.host,
                port=args.port,
                username=args.user,
                password=password,
                timeout=30,
                banner_timeout=60,
                auth_timeout=30,
                look_for_keys=False,
                allow_agent=False,
                disabled_algorithms={
                    "kex": ["curve25519-sha256", "curve25519-sha256@libssh.org"]
                },
            )
            transport = client.get_transport()
            if transport is not None:
                transport.set_keepalive(20)
            return client
        except Exception as exc:
            client.close()
            last_error = exc
            if attempt >= 5:
                break
            delay = min(attempt * 2, 8)
            print(
                f"[ssh-retry] attempt={attempt}, "
                f"error={type(exc).__name__}, retry-in={delay}s"
            )
            time.sleep(delay)
    if last_error is None:
        raise RuntimeError("SSH connection failed without an exception")
    raise last_error


def probe_public_apk(
    client: paramiko.SSHClient,
    url: str,
    expected_size: int,
    edition: str,
) -> None:
    """Read public headers and the first four bytes without downloading the APK."""
    script = f"""set -eu
headers=$(mktemp)
body=$(mktemp)
trap 'rm -f "$headers" "$body"' EXIT
curl -fsSIL --max-time 30 -D "$headers" -o /dev/null {quote(url)}
mime=$(awk 'BEGIN {{IGNORECASE=1}} /^Content-Type:/ {{gsub(/\\r/, ""); value=$2}} END {{print value}}' "$headers")
length=$(awk 'BEGIN {{IGNORECASE=1}} /^Content-Length:/ {{gsub(/\\r/, ""); value=$2}} END {{print value}}' "$headers")
curl -fsS --max-time 30 --max-filesize 1024 --range 0-3 -o "$body" {quote(url)}
magic=$(od -An -tx1 -N4 "$body" | tr -d ' \\n')
printf '%s\\t%s\\t%s' "$mime" "$length" "$magic"
"""
    raw = run(client, script, f"public-apk-{edition}").strip()
    parts = raw.split("\t")
    if len(parts) != 3:
        raise RuntimeError(f"{edition} public APK probe returned malformed output: {raw!r}")
    mime, length, magic = parts
    if mime.lower() != "application/vnd.android.package-archive":
        raise RuntimeError(f"{edition} public APK has invalid MIME type: {mime!r}")
    if not length.isdigit() or int(length) != expected_size:
        raise RuntimeError(
            f"{edition} public APK length mismatch: expected {expected_size}, got {length!r}"
        )
    if magic.lower() != "504b0304":
        raise RuntimeError(f"{edition} public APK is not a ZIP/APK payload: {magic!r}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", type=int, default=22)
    parser.add_argument("--user", required=True)
    parser.add_argument("--lifecycle-url", required=True)
    parser.add_argument("--version-code", type=int, required=True)
    parser.add_argument("--current-version-code", type=int, default=1)
    parser.add_argument("--expected", action="append", required=True, type=parse_expected)
    parser.add_argument("--db-host", default="127.0.0.1")
    parser.add_argument("--db-port", type=int, default=3306)
    parser.add_argument("--db-name", required=True)
    parser.add_argument("--db-user", required=True)
    args = parser.parse_args()

    ssh_password = os.environ.get("YY_SSH_PASSWORD", "")
    db_password = os.environ.get("YY_DB_PASSWORD", "")
    if not ssh_password or not db_password:
        raise RuntimeError("YY_SSH_PASSWORD and YY_DB_PASSWORD are required")
    expected = dict(args.expected)
    if set(expected) != {"platform_owner", "authorized_platform", "admin", "user"}:
        raise RuntimeError("all four editions must be provided exactly once")

    client = connect_ssh(args, ssh_password)
    try:
        fingerprint = client.get_transport().get_remote_server_key().get_fingerprint().hex()
        print(f"[ssh] connected; host-key={fingerprint}")
        platform_rows = mysql_query(
            client,
            args,
            db_password,
            "SELECT id, platform_key, level, status FROM platform_accounts "
            "WHERE deleted_at IS NULL ORDER BY level, status DESC, id",
            "platform-context",
        )
        platforms: dict[int, str] = {}
        for line in platform_rows.splitlines():
            parts = line.split("\t")
            if len(parts) >= 4 and parts[2].isdigit():
                platforms.setdefault(int(parts[2]), parts[1])
        if 1 not in platforms or 2 not in platforms:
            raise RuntimeError("both level-1 and level-2 platform contexts are required")

        app_rows = mysql_query(
            client,
            args,
            db_password,
            "SELECT app_key, status FROM apps WHERE deleted_at IS NULL ORDER BY status DESC, id",
            "app-context",
        )
        app_key = ""
        for line in app_rows.splitlines():
            parts = line.split("\t")
            if parts and parts[0].strip():
                app_key = parts[0].strip()
                break
        if not app_key:
            raise RuntimeError("an application context is required")

        contexts = {
            "platform_owner": {"platform_key": platforms[1]},
            "authorized_platform": {"platform_key": platforms[2]},
            "admin": {"platform_key": platforms[1]},
            "user": {"app_key": app_key},
        }
        for edition, context in contexts.items():
            query = {
                "edition_code": edition,
                "version_code": str(args.current_version_code),
                **context,
            }
            url = args.lifecycle_url + "?" + urllib.parse.urlencode(query)
            raw = run(client, f"curl -fsS --max-time 30 {quote(url)}", f"lifecycle-{edition}")
            response = json.loads(raw)
            if int(response.get("code", 0)) != 1:
                raise RuntimeError(f"{edition} lifecycle returned an error: {response}")
            data = response.get("data") or {}
            update = data.get("update") or {}
            release = expected[edition]
            checks = {
                "edition_code": data.get("edition_code") == edition,
                "available": update.get("available") is True,
                "version_code": int(update.get("version_code", 0)) == args.version_code,
                "package_name": update.get("package_name") == release["package"],
                "sha256": str(update.get("sha256", "")).lower() == release["sha256"],
                "size_bytes": int(update.get("size_bytes", 0)) == release["size"],
                "download_url": str(update.get("download_url", "")).endswith(".apk"),
                "force_update": update.get("force_update") is False,
            }
            failed = [name for name, passed in checks.items() if not passed]
            if failed:
                raise RuntimeError(f"{edition} lifecycle validation failed: {', '.join(failed)}")
            probe_public_apk(
                client,
                str(update["download_url"]),
                int(release["size"]),
                edition,
            )
            print(
                f"[ok] {edition}: version={update['version_code']}, package={update['package_name']}, "
                f"size={update['size_bytes']}, force_update=false, public-apk=valid"
            )
        print("[complete] lifecycle verification passed: 4/4")
    finally:
        client.close()
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(
            f"release verification failed: {type(exc).__name__}: {exc!r}",
            file=sys.stderr,
        )
        raise SystemExit(1)
