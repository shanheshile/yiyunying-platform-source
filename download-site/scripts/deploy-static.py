#!/usr/bin/env python3
"""Atomically deploy the static download center and matching Android artifacts."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import posixpath
import shlex
import stat
import sys
import time
from pathlib import Path

import paramiko


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", type=int, default=22)
    parser.add_argument("--username", default="root")
    parser.add_argument("--site-dir", type=Path, required=True)
    parser.add_argument("--release-dir", type=Path, required=True)
    parser.add_argument("--version", required=True)
    parser.add_argument(
        "--remote-public-root",
        default="/www/wwwroot/appht.jjmxg.xyz/yiyunying-backend/public",
    )
    return parser.parse_args()


def quote(value: str) -> str:
    return shlex.quote(value)


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest().upper()


def run(ssh: paramiko.SSHClient, command: str, *, check: bool = True) -> str:
    stdin, stdout, stderr = ssh.exec_command(command, timeout=180)
    del stdin
    output = stdout.read().decode("utf-8", errors="replace")
    error = stderr.read().decode("utf-8", errors="replace")
    status = stdout.channel.recv_exit_status()
    if check and status != 0:
        raise RuntimeError(f"Remote command failed ({status}): {error.strip() or output.strip()}")
    return output.strip()


def ensure_remote_dir(sftp: paramiko.SFTPClient, path: str) -> None:
    current = "/"
    for part in path.strip("/").split("/"):
        current = posixpath.join(current, part)
        try:
            attrs = sftp.stat(current)
            if not stat.S_ISDIR(attrs.st_mode):
                raise RuntimeError(f"Remote path is not a directory: {current}")
        except FileNotFoundError:
            sftp.mkdir(current, mode=0o755)


def upload_tree(sftp: paramiko.SFTPClient, local_root: Path, remote_root: str) -> None:
    ensure_remote_dir(sftp, remote_root)
    for local_path in sorted(local_root.rglob("*")):
        relative = local_path.relative_to(local_root).as_posix()
        remote_path = posixpath.join(remote_root, relative)
        if local_path.is_dir():
            ensure_remote_dir(sftp, remote_path)
            continue
        ensure_remote_dir(sftp, posixpath.dirname(remote_path))
        sftp.put(str(local_path), remote_path)
        sftp.chmod(remote_path, 0o644)


def load_release_files(release_dir: Path, version: str) -> list[dict[str, object]]:
    manifest_path = release_dir / "release-manifest.json"
    with manifest_path.open("r", encoding="utf-8-sig") as stream:
        manifest = json.load(stream)
    if str(manifest.get("versionName")) != version:
        raise RuntimeError("Release manifest version does not match --version")

    artifacts: list[dict[str, object]] = []
    for item in manifest.get("releases", []):
        file_name = str(item["fileName"])
        local_path = release_dir / file_name
        if not local_path.is_file():
            raise FileNotFoundError(local_path)
        expected_hash = str(item["sha256"]).upper()
        actual_hash = sha256(local_path)
        if actual_hash != expected_hash:
            raise RuntimeError(f"Local SHA-256 mismatch: {file_name}")
        expected_size = int(item["sizeBytes"])
        if local_path.stat().st_size != expected_size:
            raise RuntimeError(f"Local size mismatch: {file_name}")
        artifacts.append(
            {
                "name": file_name,
                "path": local_path,
                "sha256": actual_hash,
                "size": expected_size,
            }
        )
    if len(artifacts) != 4:
        raise RuntimeError(f"Expected four Android artifacts, found {len(artifacts)}")
    return artifacts


def main() -> int:
    args = parse_args()
    password = os.environ.get("YY_SSH_PASSWORD")
    if not password:
        raise RuntimeError("YY_SSH_PASSWORD is required")

    site_dir = args.site_dir.resolve()
    release_dir = args.release_dir.resolve()
    if not (site_dir / "index.html").is_file():
        raise FileNotFoundError(site_dir / "index.html")
    artifacts = load_release_files(release_dir, args.version)

    stamp = time.strftime("%Y%m%d-%H%M%S")
    public_root = args.remote_public_root.rstrip("/")
    remote_site = posixpath.join(public_root, "download-center")
    remote_release = posixpath.join(public_root, "downloads", args.version)
    staging_root = posixpath.join(public_root, f".download-deploy-{stamp}")
    staging_site = posixpath.join(staging_root, "download-center")
    staging_release = posixpath.join(staging_root, "release")
    backup_root = posixpath.join(
        "/www/backup/yiyunying/download-center", stamp
    )

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(
        args.host,
        port=args.port,
        username=args.username,
        password=password,
        look_for_keys=False,
        allow_agent=False,
        timeout=20,
        banner_timeout=20,
        auth_timeout=20,
    )
    try:
        run(ssh, f"test -d {quote(public_root)}")
        run(ssh, f"rm -rf {quote(staging_root)} && mkdir -p {quote(staging_release)}")
        with ssh.open_sftp() as sftp:
            upload_tree(sftp, site_dir, staging_site)
            for artifact in artifacts:
                remote_file = posixpath.join(staging_release, str(artifact["name"]))
                deployed_file = posixpath.join(remote_release, str(artifact["name"]))
                deployed_hash = run(
                    ssh,
                    f"if [ -f {quote(deployed_file)} ]; then "
                    f"sha256sum {quote(deployed_file)} | awk '{{print $1}}'; fi",
                ).upper()
                if deployed_hash == artifact["sha256"]:
                    run(ssh, f"cp {quote(deployed_file)} {quote(remote_file)}")
                else:
                    sftp.put(str(artifact["path"]), remote_file)
                    sftp.chmod(remote_file, 0o644)

        for artifact in artifacts:
            remote_file = posixpath.join(staging_release, str(artifact["name"]))
            remote_hash = run(ssh, f"sha256sum {quote(remote_file)} | awk '{{print $1}}'").upper()
            if remote_hash != artifact["sha256"]:
                raise RuntimeError(f"Uploaded SHA-256 mismatch: {artifact['name']}")
            remote_size = int(run(ssh, f"stat -c %s {quote(remote_file)}"))
            if remote_size != artifact["size"]:
                raise RuntimeError(f"Uploaded size mismatch: {artifact['name']}")

        run(
            ssh,
            " && ".join(
                [
                    f"mkdir -p {quote(backup_root)}",
                    (
                        f"if [ -d {quote(remote_site)} ]; then "
                        f"tar -C {quote(public_root)} -czf {quote(posixpath.join(backup_root, 'download-center.tar.gz'))} download-center; fi"
                    ),
                    f"mkdir -p {quote(remote_release)}",
                    f"cp -f {quote(staging_release)}/* {quote(remote_release)}/",
                    f"find {quote(remote_release)} -type f -exec chmod 0644 {{}} +",
                    f"rm -rf {quote(remote_site + '.previous')}",
                    (
                        f"if [ -d {quote(remote_site)} ]; then "
                        f"mv {quote(remote_site)} {quote(remote_site + '.previous')}; fi"
                    ),
                    f"mv {quote(staging_site)} {quote(remote_site)}",
                    f"find {quote(remote_site)} -type d -exec chmod 0755 {{}} +",
                    f"find {quote(remote_site)} -type f -exec chmod 0644 {{}} +",
                ]
            ),
        )

        try:
            local_status = run(
                ssh,
                "curl -sS -o /tmp/yiyunying-download-center-check.html "
                "-w '%{http_code}' -H 'Host: appht.jjmxg.xyz' "
                "http://127.0.0.1/download-center/",
            )
            if local_status != "200":
                raise RuntimeError(f"Server-side HTTP check returned {local_status}")
            marker = run(
                ssh,
                f"grep -Rh -m1 -o {quote(args.version)} {quote(remote_site)} | head -n1",
            )
            if marker != args.version:
                raise RuntimeError("Deployed site does not contain the release version")
        except Exception:
            run(
                ssh,
                f"rm -rf {quote(remote_site)}; "
                f"if [ -d {quote(remote_site + '.previous')} ]; then "
                f"mv {quote(remote_site + '.previous')} {quote(remote_site)}; fi",
                check=False,
            )
            raise

        run(ssh, f"rm -rf {quote(remote_site + '.previous')} {quote(staging_root)}")
        print(f"Deployed download center {args.version}; verified four APK artifacts.")
        print(f"Backup: {backup_root}")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"Deployment failed: {exc}", file=sys.stderr)
        raise SystemExit(1)
