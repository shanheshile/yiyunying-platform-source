#!/usr/bin/env python3
"""Publish Android editions and update policies over SSH without storing secrets."""

from __future__ import annotations

import argparse
import hashlib
import os
import posixpath
import re
import shlex
import sys
import time
from urllib.parse import urlsplit, urlunsplit
from dataclasses import dataclass

import paramiko


EDITIONS = {"platform_owner", "authorized_platform", "admin", "user"}
PACKAGE_RE = re.compile(r"^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$")
FILENAME_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]*\.apk$")


@dataclass(frozen=True)
class Release:
    edition: str
    package_name: str
    local_path: str
    remote_filename: str
    size_bytes: int
    sha256: str


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


def connect_ssh(args: argparse.Namespace, password: str) -> paramiko.SSHClient:
    last_error: Exception | None = None
    for attempt in range(1, 11):
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
                # Production runs OpenSSH 7.4. Its Curve25519 negotiation may
                # close newer Paramiko sessions before authentication.
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
            if attempt >= 10:
                break
            delay = min(attempt * 3, 24)
            print(
                f"[ssh-retry] attempt={attempt}, "
                f"error={type(exc).__name__}, retry-in={delay}s"
            )
            time.sleep(delay)
    if last_error is None:
        raise RuntimeError("SSH connection failed without an exception")
    raise last_error


def upload_release(
    args: argparse.Namespace,
    ssh_password: str,
    release: Release,
    remote_stage: str,
) -> None:
    """Upload in reconnectable chunks so a reset never restarts a large APK."""
    # Raw SSH streaming avoids the request/response overhead of SFTP writes on
    # older OpenSSH servers while retaining resumable boundaries.
    session_limit = 64 * 1024 * 1024
    block_size = 1024 * 1024
    offset = 0
    consecutive_failures = 0
    while offset < release.size_bytes:
        client: paramiko.SSHClient | None = None
        try:
            client = connect_ssh(args, ssh_password)
            sftp = client.open_sftp()
            try:
                try:
                    remote_size = int(sftp.stat(remote_stage).st_size)
                except OSError:
                    remote_size = 0
                if remote_size > release.size_bytes:
                    sftp.remove(remote_stage)
                    remote_size = 0
                offset = remote_size
                if offset >= release.size_bytes:
                    break

                if offset == 0:
                    with sftp.open(remote_stage, "wb"):
                        pass

                transport = client.get_transport()
                if transport is None or not transport.is_active():
                    raise RuntimeError("SSH transport is not active")
                channel = transport.open_session(
                    window_size=64 * 1024 * 1024,
                    max_packet_size=1024 * 1024,
                )
                channel.exec_command(f"cat >> {quote(remote_stage)}")
                with open(release.local_path, "rb") as source:
                    source.seek(offset)
                    remaining = min(session_limit, release.size_bytes - offset)
                    while remaining > 0:
                        block = source.read(min(block_size, remaining))
                        if not block:
                            raise RuntimeError(
                                f"unexpected EOF while uploading {release.edition}"
                            )
                        channel.sendall(block)
                        remaining -= len(block)
                channel.shutdown_write()
                status = channel.recv_exit_status()
                error = channel.makefile_stderr("rb").read().decode(
                    "utf-8", errors="replace"
                )
                channel.close()
                if status != 0:
                    raise RuntimeError(
                        f"remote stream failed for {release.edition}: {error}"
                    )
                uploaded = int(sftp.stat(remote_stage).st_size)
                if uploaded <= offset or uploaded > release.size_bytes:
                    raise RuntimeError(
                        f"invalid remote size for {release.edition}: {uploaded}"
                    )
                offset = uploaded
                percent = int(offset * 100 / release.size_bytes)
                print(f"[upload] {release.edition}: {percent}% ({offset}/{release.size_bytes})")
                consecutive_failures = 0
            finally:
                try:
                    sftp.close()
                except Exception:
                    pass
        except Exception as exc:
            consecutive_failures += 1
            if consecutive_failures > 12:
                raise
            delay = min(consecutive_failures * 2, 12)
            print(
                f"[upload-retry] {release.edition}: "
                f"error={type(exc).__name__}, retry-in={delay}s"
            )
            time.sleep(delay)
        finally:
            if client is not None:
                client.close()


def digest(path: str) -> tuple[int, str]:
    total = 0
    hasher = hashlib.sha256()
    with open(path, "rb") as stream:
        while True:
            block = stream.read(1024 * 1024)
            if not block:
                break
            total += len(block)
            hasher.update(block)
    return total, hasher.hexdigest()


def parse_release(value: str) -> Release:
    parts = value.split("|", 3)
    if len(parts) != 4:
        raise argparse.ArgumentTypeError(
            "release must be edition|package_name|local_apk|remote_filename"
        )
    edition, package_name, local_path, remote_filename = (part.strip() for part in parts)
    if edition not in EDITIONS:
        raise argparse.ArgumentTypeError(f"unsupported edition: {edition}")
    if PACKAGE_RE.fullmatch(package_name) is None:
        raise argparse.ArgumentTypeError(f"invalid package name: {package_name}")
    if FILENAME_RE.fullmatch(remote_filename) is None:
        raise argparse.ArgumentTypeError(f"invalid remote APK filename: {remote_filename}")
    if not os.path.isfile(local_path):
        raise argparse.ArgumentTypeError(f"APK not found: {local_path}")
    size_bytes, sha256 = digest(local_path)
    return Release(edition, package_name, os.path.abspath(local_path), remote_filename, size_bytes, sha256)


def sql_string(value: str) -> str:
    escaped = (
        value.replace("\\", "\\\\")
        .replace("'", "''")
        .replace("\x00", "")
        .replace("\r\n", "\n")
        .replace("\r", "\n")
    )
    return "'" + escaped + "'"


def normalize_download_base_url(value: str) -> str:
    """Accept either the site origin or the public downloads root."""
    raw = value.strip().rstrip("/")
    parsed = urlsplit(raw)
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        raise RuntimeError("base-url must be an absolute HTTP(S) URL")
    path = parsed.path.rstrip("/")
    if not path.endswith("/downloads"):
        path += "/downloads"
    return urlunsplit((parsed.scheme, parsed.netloc, path, "", ""))


def mysql_command(args: argparse.Namespace, db_password: str, suffix: str) -> str:
    return (
        "MYSQL_BIN=$(command -v mysql || true); "
        "if [ -z \"$MYSQL_BIN\" ] && [ -x /www/server/mysql/bin/mysql ]; "
        "then MYSQL_BIN=/www/server/mysql/bin/mysql; fi; "
        "test -n \"$MYSQL_BIN\"; "
        f"MYSQL_PWD={quote(db_password)} \"$MYSQL_BIN\" --default-character-set=utf8mb4 "
        f"-h {quote(args.db_host)} -P {args.db_port} -u {quote(args.db_user)} "
        f"{quote(args.db_name)} {suffix}"
    )


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", type=int, default=22)
    parser.add_argument("--user", required=True)
    parser.add_argument("--remote-root", required=True)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--version-name", required=True)
    parser.add_argument("--version-code", required=True, type=int)
    parser.add_argument("--release", action="append", required=True, type=parse_release)
    parser.add_argument("--release-notes", default="")
    parser.add_argument("--db-host", default="127.0.0.1")
    parser.add_argument("--db-port", type=int, default=3306)
    parser.add_argument("--db-name", required=True)
    parser.add_argument("--db-user", required=True)
    args = parser.parse_args()

    ssh_password = os.environ.get("YY_SSH_PASSWORD", "")
    db_password = os.environ.get("YY_DB_PASSWORD", "")
    if not ssh_password or not db_password:
        raise RuntimeError("YY_SSH_PASSWORD and YY_DB_PASSWORD are required")
    if args.version_code < 1:
        raise RuntimeError("version-code must be positive")
    if len(args.release) != len({release.edition for release in args.release}):
        raise RuntimeError("each edition may only be published once")

    base_url = normalize_download_base_url(args.base_url)
    version_slug = re.sub(r"[^A-Za-z0-9._-]", "-", args.version_name).strip("-")
    if not version_slug:
        raise RuntimeError("version-name does not produce a safe release directory")

    stamp = time.strftime("%Y%m%d-%H%M%S")
    release_dir = posixpath.join(args.remote_root.rstrip("/"), "public", "downloads", version_slug)
    stage_dir = f"/tmp/yiyunying-android-{version_slug}"
    backup_dir = f"/www/backup/yiyunying/{stamp}-android"
    remote_sql = f"/tmp/yiyunying-android-policy-{stamp}.sql"
    release_notes = args.release_notes.strip() or (
        "随机拼手气红包精确到0.01余额，支持唯一运气王与价格标签；"
        "转账和礼物仅接收方可以主动退回，款项退还原发送人。"
    )

    client = connect_ssh(args, ssh_password)
    try:
        fingerprint = client.get_transport().get_remote_server_key().get_fingerprint().hex()
        print(f"[ssh] connected; host-key={fingerprint}")
        run(
            client,
            f"test -d {quote(args.remote_root)} && test -f {quote(args.remote_root + '/public/index.php')}",
            "preflight",
        )

        root_output = run(
            client,
            mysql_command(
                args,
                db_password,
                "--batch --raw --skip-column-names -e "
                + quote(
                    "SELECT id, level FROM platform_accounts "
                    "WHERE level = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1"
                ),
            ),
            "root-platform",
        ).strip()
        root_parts = root_output.split("\t")
        if len(root_parts) != 2 or not all(part.isdigit() for part in root_parts):
            raise RuntimeError("active level-1 platform account was not found")
        issuer_id, issuer_level = (int(root_parts[0]), int(root_parts[1]))

        run(client, f"mkdir -p {quote(backup_dir)} {quote(stage_dir)}", "prepare-directories")
        table_backup = (
            "DUMP_BIN=$(command -v mysqldump || true); "
            "if [ -z \"$DUMP_BIN\" ] && [ -x /www/server/mysql/bin/mysqldump ]; "
            "then DUMP_BIN=/www/server/mysql/bin/mysqldump; fi; "
            "test -n \"$DUMP_BIN\"; "
            f"MYSQL_PWD={quote(db_password)} \"$DUMP_BIN\" --default-character-set=utf8mb4 "
            f"-h {quote(args.db_host)} -P {args.db_port} -u {quote(args.db_user)} "
            f"--single-transaction --quick --skip-triggers {quote(args.db_name)} "
            f"software_update_policies | gzip -c > {quote(backup_dir + '/software_update_policies.sql.gz')}; "
            f"test -s {quote(backup_dir + '/software_update_policies.sql.gz')}"
        )
        run(client, table_backup, "policy-backup")
        run(
            client,
            f"if [ -d {quote(release_dir)} ]; then tar -czf {quote(backup_dir + '/android-release.tar.gz')} "
            f"-C {quote(posixpath.dirname(release_dir))} {quote(posixpath.basename(release_dir))}; fi",
            "apk-backup",
        )

        reused_editions: set[str] = set()
        for release in args.release:
            remote_stage = posixpath.join(stage_dir, release.remote_filename)
            remote_final = posixpath.join(release_dir, release.remote_filename)
            reused = run(
                client,
                f"if [ -f {quote(remote_final)} ] "
                f"&& [ $(stat -c %s {quote(remote_final)}) -eq {release.size_bytes} ] "
                f"&& [ $(sha256sum {quote(remote_final)} | awk '{{print $1}}') = {quote(release.sha256)} ]; "
                f"then cp -f {quote(remote_final)} {quote(remote_stage)} && echo reused; fi",
                f"reuse-{release.edition}",
            ).strip()
            if reused == "reused":
                reused_editions.add(release.edition)

        client.close()
        for release in args.release:
            if release.edition in reused_editions:
                print(f"[upload] {release.edition}: reused verified production artifact")
                continue
            remote_stage = posixpath.join(stage_dir, release.remote_filename)
            print(
                f"[upload] {release.edition}: {release.size_bytes} bytes, sha256={release.sha256}"
            )
            upload_release(args, ssh_password, release, remote_stage)
        client = connect_ssh(args, ssh_password)

        for release in args.release:
            remote_stage = posixpath.join(stage_dir, release.remote_filename)
            remote_hash = run(
                client,
                f"test $(stat -c %s {quote(remote_stage)}) -eq {release.size_bytes} && "
                f"sha256sum {quote(remote_stage)} | awk '{{print $1}}'",
                f"verify-{release.edition}",
            ).strip().lower()
            if remote_hash != release.sha256:
                raise RuntimeError(
                    f"remote hash mismatch for {release.edition}: {remote_hash} != {release.sha256}"
                )

        run(client, f"mkdir -p {quote(release_dir)}", "release-directory")
        for release in args.release:
            remote_stage = posixpath.join(stage_dir, release.remote_filename)
            remote_final = posixpath.join(release_dir, release.remote_filename)
            run(
                client,
                f"install -m 0644 {quote(remote_stage)} {quote(remote_final + '.new')} && "
                f"mv -f {quote(remote_final + '.new')} {quote(remote_final)}",
                f"publish-{release.edition}",
            )

        sql_lines = ["SET NAMES utf8mb4;", "START TRANSACTION;"]
        for release in args.release:
            download_url = f"{base_url}/{version_slug}/{release.remote_filename}"
            sql_lines.append(
                "UPDATE software_update_policies SET status = 0, updated_at = NOW() "
                f"WHERE issuer_type = 'platform' AND issuer_id = {issuer_id} "
                f"AND edition_code = {sql_string(release.edition)} AND target_type = 'global' "
                f"AND version_code <= {args.version_code};"
            )
            sql_lines.append(
                "INSERT INTO software_update_policies "
                "(issuer_type, issuer_id, issuer_level, edition_code, target_type, target_id, target_level, "
                "version_name, version_code, min_supported_version_code, download_url, package_name, sha256, "
                "size_bytes, release_notes, force_update, priority, status, starts_at, ends_at, created_at, updated_at) "
                "VALUES ("
                f"'platform', {issuer_id}, {issuer_level}, {sql_string(release.edition)}, 'global', NULL, NULL, "
                f"{sql_string(args.version_name)}, {args.version_code}, 0, {sql_string(download_url)}, "
                f"{sql_string(release.package_name)}, {sql_string(release.sha256)}, {release.size_bytes}, "
                f"{sql_string(release_notes)}, 0, {args.version_code}, 1, NULL, NULL, NOW(), NOW());"
            )
        sql_lines.append("COMMIT;")
        sql_payload = ("\n".join(sql_lines) + "\n").encode("utf-8")

        sftp = client.open_sftp()
        try:
            with sftp.open(remote_sql, "wb") as stream:
                stream.write(sql_payload)
        finally:
            sftp.close()
        run(
            client,
            mysql_command(args, db_password, f"< {quote(remote_sql)}"),
            "publish-policies",
        )

        query = (
            "SELECT edition_code, version_name, version_code, force_update, package_name, "
            "size_bytes, sha256, download_url FROM software_update_policies "
            f"WHERE issuer_type = 'platform' AND issuer_id = {issuer_id} "
            f"AND version_code = {args.version_code} AND status = 1 ORDER BY edition_code"
        )
        run(
            client,
            mysql_command(
                args,
                db_password,
                "--batch --raw --skip-column-names -e " + quote(query),
            ),
            "active-policies",
        )
        for release in args.release:
            download_url = f"{base_url}/{version_slug}/{release.remote_filename}"
            run(
                client,
                f"curl -fsSI --max-time 30 {quote(download_url)} | head -n 8",
                f"http-{release.edition}",
            )

        run(
            client,
            f"rm -rf {quote(stage_dir)} && rm -f {quote(remote_sql)}",
            "temporary-cleanup",
        )
        print(f"[complete] release={release_dir}")
        print(f"[complete] backup={backup_dir}")
    finally:
        client.close()
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(
            f"Android publication failed: {type(exc).__name__}: {exc!r}",
            file=sys.stderr,
        )
        raise SystemExit(1)
