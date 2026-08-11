#!/usr/bin/env python3
"""Publish Android editions and update policies over SSH without storing secrets."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import posixpath
import re
import secrets
import shlex
import shutil
import subprocess
import sys
import time
from pathlib import Path
from urllib.parse import urlencode, urlsplit, urlunsplit
from dataclasses import dataclass, replace

import paramiko


EDITIONS = {"platform_owner", "authorized_platform", "admin", "user"}
MANIFEST_IDS = {
    "owner": "platform_owner",
    "authorized": "authorized_platform",
    "admin": "admin",
    "user": "user",
}
EDITION_MANIFEST_IDS = {edition: manifest_id for manifest_id, edition in MANIFEST_IDS.items()}
PACKAGE_RE = re.compile(r"^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$")
FILENAME_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]*\.apk$")
SHA256_RE = re.compile(r"^[0-9A-Fa-f]{64}$")
COMMIT_RE = re.compile(r"^[0-9A-Fa-f]{40}$")
PACKAGE_LINE_RE = re.compile(
    r"^package:\s+name='(?P<package>[^']+)'\s+versionCode='(?P<code>\d+)'\s+"
    r"versionName='(?P<name>[^']+)'"
)
SIGNER_RE = re.compile(
    r"^Signer #(?P<number>\d+) certificate SHA-256 digest:\s*(?P<digest>[0-9A-Fa-f]{64})\s*$"
)
INSECURE_HTTP_CONFIRMATION = "DEBUG_HTTP_NON_PRODUCTION_CONFIRMED"


@dataclass(frozen=True)
class Release:
    edition: str
    package_name: str
    local_path: str
    remote_filename: str
    size_bytes: int
    sha256: str
    version_name: str = ""
    version_code: int = 0
    signer_sha256: str = ""
    range_size: int = 0
    range_sha256: str = ""


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
        # Publication must be pinned to the caller-provided known_hosts file.
        # Do not silently trust a system-wide entry or enroll a new server key.
        client.load_host_keys(args.known_hosts)
        client.set_missing_host_key_policy(paramiko.RejectPolicy())
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
    # older OpenSSH servers while retaining resumable boundaries. Each retry
    # writes at an explicit aligned offset instead of appending: a disconnected
    # remote process can then only overwrite the same range, never duplicate it.
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
                aligned_size = remote_size - (remote_size % block_size)
                if aligned_size != remote_size:
                    run(
                        client,
                        f"truncate -s {aligned_size} {quote(remote_stage)}",
                        f"truncate-partial-{release.edition}",
                    )
                offset = aligned_size
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
                seek_blocks = offset // block_size
                channel.exec_command(
                    "dd "
                    f"of={quote(remote_stage)} bs={block_size} seek={seek_blocks} "
                    "conv=notrunc status=none"
                )
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


def digest_prefix(path: str, size: int) -> str:
    hasher = hashlib.sha256()
    with open(path, "rb") as stream:
        remaining = size
        while remaining > 0:
            block = stream.read(min(1024 * 1024, remaining))
            if not block:
                raise RuntimeError(f"unexpected EOF while hashing range: {path}")
            hasher.update(block)
            remaining -= len(block)
    return hasher.hexdigest()


def read_json_object(path: str, label: str) -> dict:
    try:
        with open(path, "r", encoding="utf-8") as stream:
            value = json.load(stream)
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"cannot read {label}: {path}: {exc}") from exc
    if not isinstance(value, dict):
        raise RuntimeError(f"{label} must contain a JSON object: {path}")
    return value


def resolve_android_tool(name: str, override: str | None) -> str:
    if override:
        candidate = os.path.abspath(override)
        if not os.path.isfile(candidate):
            raise RuntimeError(f"Android tool not found: {candidate}")
        return candidate

    executable_names = [name]
    if os.name == "nt":
        executable_names = [f"{name}.exe", f"{name}.bat", f"{name}.cmd", name]
    for executable in executable_names:
        candidate = shutil.which(executable)
        if candidate:
            return os.path.abspath(candidate)

    sdk_root = os.environ.get("ANDROID_SDK_ROOT") or os.environ.get("ANDROID_HOME")
    if sdk_root:
        build_tools = Path(sdk_root) / "build-tools"
        if build_tools.is_dir():
            def version_key(directory: Path) -> tuple[int, ...]:
                numbers = re.findall(r"\d+", directory.name)
                return tuple(int(number) for number in numbers) or (0,)

            for directory in sorted(build_tools.iterdir(), key=version_key, reverse=True):
                for executable in executable_names:
                    candidate = directory / executable
                    if candidate.is_file():
                        return str(candidate.resolve())
    raise RuntimeError(
        f"Android tool {name} was not found; pass --{name} or configure ANDROID_SDK_ROOT"
    )


def run_local_tool(command: list[str], label: str) -> str:
    result = subprocess.run(
        command,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        encoding="utf-8",
        errors="replace",
        timeout=180,
        check=False,
    )
    if result.returncode != 0:
        raise RuntimeError(f"{label} failed ({result.returncode}): {result.stdout.strip()}")
    return result.stdout


def validate_git_release_evidence(repository_root: Path, manifest: dict) -> None:
    build_commit = str(manifest["buildSourceCommit"]).lower()
    evidence_commit = str(manifest["releaseEvidenceCommit"]).lower()
    release_tag = str(manifest["releaseTag"])
    status = run_local_tool(
        ["git", "-C", str(repository_root), "status", "--porcelain", "--untracked-files=all"],
        "Git release worktree",
    )
    if status.strip():
        raise RuntimeError("publication requires a completely clean Git worktree")
    branch = run_local_tool(
        ["git", "-C", str(repository_root), "symbolic-ref", "--short", "HEAD"],
        "Git release branch",
    ).strip()
    if branch != "main":
        raise RuntimeError("publication is restricted to the main branch")
    head = run_local_tool(
        ["git", "-C", str(repository_root), "rev-parse", "HEAD^{commit}"],
        "Git evidence commit",
    ).strip().lower()
    main_commit = run_local_tool(
        ["git", "-C", str(repository_root), "rev-parse", "refs/heads/main^{commit}"],
        "Git main commit",
    ).strip().lower()
    if head != evidence_commit or main_commit != evidence_commit:
        raise RuntimeError("HEAD and main must equal the release evidence commit")
    run_local_tool(
        ["git", "-C", str(repository_root), "merge-base", "--is-ancestor", build_commit, evidence_commit],
        "Git build/evidence ancestry",
    )
    tag_type = run_local_tool(
        ["git", "-C", str(repository_root), "cat-file", "-t", f"refs/tags/{release_tag}"],
        "Git release tag type",
    ).strip()
    if tag_type != "tag":
        raise RuntimeError("release tag must be an annotated tag object")
    tag_commit = run_local_tool(
        ["git", "-C", str(repository_root), "rev-parse", f"refs/tags/{release_tag}^{{commit}}"],
        "Git release tag commit",
    ).strip().lower()
    if tag_commit != evidence_commit:
        raise RuntimeError("release tag does not point to the release evidence commit")


def inspect_apk(apk_path: str, aapt_path: str, apksigner_path: str) -> tuple[str, str, int, str]:
    badging = run_local_tool([aapt_path, "dump", "badging", apk_path], "aapt identity")
    package_match = next(
        (PACKAGE_LINE_RE.match(line.strip()) for line in badging.splitlines() if line.startswith("package:")),
        None,
    )
    if package_match is None:
        raise RuntimeError(f"aapt did not return a valid package identity: {apk_path}")

    signer_output = run_local_tool(
        [apksigner_path, "verify", "--verbose", "--print-certs", apk_path],
        "APK signature verification",
    )
    signers = [SIGNER_RE.match(line.strip()) for line in signer_output.splitlines()]
    signers = [match for match in signers if match is not None]
    if len(signers) != 1 or signers[0].group("number") != "1":
        raise RuntimeError(f"APK must contain exactly one signer: {apk_path}")
    return (
        package_match.group("package"),
        package_match.group("name"),
        int(package_match.group("code")),
        signers[0].group("digest").upper(),
    )


def previous_release_signer(manifest_path: str, current_version_code: int) -> str:
    release_root = Path(manifest_path).resolve().parent.parent
    if not release_root.is_dir():
        return ""
    candidates: list[tuple[int, str]] = []
    for candidate in release_root.glob("*/release-manifest.json"):
        if candidate.resolve() == Path(manifest_path).resolve():
            continue
        try:
            data = read_json_object(str(candidate), "previous release manifest")
            code = int(data.get("versionCode", 0))
            if code < 1 or code >= current_version_code:
                continue
            entries = data.get("releases")
            if not isinstance(entries, list):
                continue
            digests = {
                str(entry.get("signerSha256", "")).upper()
                for entry in entries
                if isinstance(entry, dict) and SHA256_RE.fullmatch(str(entry.get("signerSha256", "")))
            }
            if not digests:
                continue
            if len(digests) != 1:
                raise RuntimeError(f"previous release has inconsistent signers: {candidate}")
            candidates.append((code, next(iter(digests))))
        except (RuntimeError, TypeError, ValueError) as exc:
            raise RuntimeError(f"invalid previous release identity: {candidate}: {exc}") from exc
    return max(candidates, default=(0, ""), key=lambda value: value[0])[1]


def validate_release_plan(
    releases: list[Release],
    manifest_path: str,
    identity_path: str,
    expected_version_name: str,
    expected_version_code: int,
    aapt_path: str,
    apksigner_path: str,
) -> tuple[list[Release], dict]:
    editions = [release.edition for release in releases]
    if len(editions) != len(set(editions)):
        raise RuntimeError("each edition may only be published once")
    if set(editions) != EDITIONS or len(editions) != len(EDITIONS):
        missing = sorted(EDITIONS - set(editions))
        extra = sorted(set(editions) - EDITIONS)
        raise RuntimeError(f"release set must contain exactly four editions; missing={missing}, extra={extra}")
    for label, values in {
        "remote filename": [release.remote_filename for release in releases],
        "package name": [release.package_name for release in releases],
        "local APK": [os.path.normcase(os.path.realpath(release.local_path)) for release in releases],
    }.items():
        if len(values) != len(set(values)):
            raise RuntimeError(f"duplicate {label} in release set")

    manifest = read_json_object(manifest_path, "release manifest")
    identity = read_json_object(identity_path, "release identity")
    manifest_name = str(manifest.get("versionName", ""))
    manifest_code = int(manifest.get("versionCode", 0))
    identity_name = str(identity.get("version_name", identity.get("versionName", "")))
    identity_code = int(identity.get("version_code", identity.get("versionCode", 0)))
    if (manifest_name, manifest_code) != (expected_version_name, expected_version_code):
        raise RuntimeError("release manifest version does not match command line version")
    if (identity_name, identity_code) != (expected_version_name, expected_version_code):
        raise RuntimeError("release identity version does not match command line version")
    _, identity_sha256 = digest(identity_path)
    build_commit = str(manifest.get("buildSourceCommit", "")).lower()
    evidence_commit = str(manifest.get("releaseEvidenceCommit", "")).lower()
    expected_tag = f"v{expected_version_name}-debug"
    if manifest.get("schemaVersion") != 4:
        raise RuntimeError("release manifest schemaVersion must be 4")
    if manifest.get("finalizationStatus") != "finalized":
        raise RuntimeError("release manifest must be finalized before publication")
    if COMMIT_RE.fullmatch(build_commit) is None or COMMIT_RE.fullmatch(evidence_commit) is None:
        raise RuntimeError("release manifest build/evidence commits must be 40 hexadecimal characters")
    if build_commit == evidence_commit:
        raise RuntimeError("release manifest build and evidence commits must be distinct")
    if manifest.get("releaseTag") != expected_tag:
        raise RuntimeError(f"release manifest tag must be {expected_tag}")
    manifest_identity_sha256 = str(manifest.get("releaseIdentitySha256", "")).lower()
    if manifest_identity_sha256 != identity_sha256:
        raise RuntimeError("release manifest is not bound to the supplied release identity bytes")

    entries = manifest.get("releases")
    if not isinstance(entries, list) or len(entries) != len(EDITIONS):
        raise RuntimeError("release manifest must contain exactly four APK entries")
    manifest_by_edition: dict[str, dict] = {}
    for entry in entries:
        if not isinstance(entry, dict) or entry.get("id") not in MANIFEST_IDS:
            raise RuntimeError("release manifest contains an unsupported edition id")
        edition = MANIFEST_IDS[str(entry["id"])]
        if edition in manifest_by_edition:
            raise RuntimeError(f"duplicate release manifest edition: {edition}")
        manifest_by_edition[edition] = entry
    if set(manifest_by_edition) != EDITIONS:
        raise RuntimeError("release manifest does not contain the complete four-edition set")

    validated: list[Release] = []
    manifest_signers: set[str] = set()
    actual_signers: set[str] = set()
    for release in releases:
        entry = manifest_by_edition[release.edition]
        current_size, current_sha = digest(release.local_path)
        if current_size != release.size_bytes or current_sha != release.sha256:
            raise RuntimeError(f"local APK changed after argument parsing: {release.edition}")
        manifest_sha = str(entry.get("sha256", "")).upper()
        manifest_signer = str(entry.get("signerSha256", "")).upper()
        if SHA256_RE.fullmatch(manifest_sha) is None or SHA256_RE.fullmatch(manifest_signer) is None:
            raise RuntimeError(f"manifest hash or signer is invalid for {release.edition}")
        expected = (
            str(entry.get("packageName", "")),
            str(entry.get("versionName", "")),
            int(entry.get("versionCode", 0)),
            str(entry.get("fileName", "")),
            int(entry.get("sizeBytes", -1)),
            manifest_sha,
        )
        actual_file = (
            release.package_name,
            str(entry.get("versionName", "")),
            expected_version_code,
            release.remote_filename,
            current_size,
            current_sha.upper(),
        )
        if actual_file != expected:
            raise RuntimeError(f"release argument or local digest does not match manifest: {release.edition}")

        package_name, version_name, version_code, signer = inspect_apk(
            release.local_path, aapt_path, apksigner_path
        )
        if (package_name, version_name, version_code) != expected[:3]:
            raise RuntimeError(f"APK embedded identity does not match manifest: {release.edition}")
        if signer != manifest_signer:
            raise RuntimeError(f"APK signer does not match manifest: {release.edition}")
        manifest_signers.add(manifest_signer)
        actual_signers.add(signer)
        range_size = min(64 * 1024, release.size_bytes)
        if range_size < 1:
            raise RuntimeError(f"APK is empty: {release.local_path}")
        validated.append(
            replace(
                release,
                version_name=version_name,
                version_code=version_code,
                signer_sha256=signer,
                range_size=range_size,
                range_sha256=digest_prefix(release.local_path, range_size),
            )
        )

    if len(manifest_signers) != 1 or len(actual_signers) != 1:
        raise RuntimeError("all four APKs must use one unified signer")
    signer = next(iter(actual_signers))
    configured_signer = str(
        identity.get("signer_sha256", identity.get("signerSha256", ""))
    ).upper()
    if configured_signer and SHA256_RE.fullmatch(configured_signer) is None:
        raise RuntimeError("release identity signer hash is invalid")
    previous_signer = previous_release_signer(manifest_path, expected_version_code)
    trusted_signers = {value for value in (configured_signer, previous_signer) if value}
    if len(trusted_signers) > 1:
        raise RuntimeError("configured signer conflicts with previous release signer")
    if trusted_signers and signer != next(iter(trusted_signers)):
        raise RuntimeError("APK signer does not match the trusted previous release signer")
    return validated, manifest


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
    if (
        parsed.scheme not in {"http", "https"}
        or not parsed.netloc
        or not parsed.hostname
        or parsed.username is not None
        or parsed.password is not None
        or parsed.query
        or parsed.fragment
    ):
        raise RuntimeError("base-url must be an absolute HTTP(S) URL")
    path = parsed.path.rstrip("/")
    if not path.endswith("/downloads"):
        path += "/downloads"
    return urlunsplit((parsed.scheme, parsed.netloc, path, "", ""))


def normalize_lifecycle_url(value: str) -> str:
    raw = value.strip()
    parsed = urlsplit(raw)
    path = parsed.path.rstrip("/")
    if (
        parsed.scheme not in {"http", "https"}
        or not parsed.netloc
        or not parsed.hostname
        or parsed.username is not None
        or parsed.password is not None
        or parsed.query
        or parsed.fragment
        or not path
        or path == "/"
    ):
        raise RuntimeError(
            "lifecycle-url must be an absolute HTTP(S) endpoint without credentials, query or fragment"
        )
    return urlunsplit((parsed.scheme, parsed.netloc, path, "", ""))


def normalize_remote_root(value: str) -> str:
    if "\x00" in value or "\n" in value or "\r" in value:
        raise RuntimeError("remote-root contains invalid characters")
    normalized = posixpath.normpath(value.strip())
    if not normalized.startswith("/") or normalized == "/":
        raise RuntimeError("remote-root must be a specific absolute directory")
    return normalized


def enforce_transport_policy(
    base_url: str,
    lifecycle_url: str,
    releases: list[Release],
    allow_insecure_http_debug: bool,
    confirmation: str,
) -> bool:
    insecure = [
        url for url in (base_url, lifecycle_url) if urlsplit(url).scheme.lower() != "https"
    ]
    if not insecure:
        return False
    if (
        not allow_insecure_http_debug
        or confirmation != INSECURE_HTTP_CONFIRMATION
    ):
        raise RuntimeError(
            "HTTP publication is refused by default; the explicit non-production Debug flag and confirmation are required"
        )
    invalid = [
        release.edition
        for release in releases
        if not release.package_name.endswith(".debug")
        or not release.version_name.endswith("-debug")
        or not release.remote_filename.endswith("-debug.apk")
    ]
    if invalid:
        raise RuntimeError(
            "insecure HTTP is restricted to the four Debug APK identities; invalid="
            + ",".join(sorted(invalid))
        )
    return True


def candidate_condition(releases: list[Release], download_urls: dict[str, str]) -> str:
    clauses = []
    for release in releases:
        clauses.append(
            "(edition_code = " + sql_string(release.edition)
            + " AND download_url = " + sql_string(download_urls[release.edition])
            + " AND package_name = " + sql_string(release.package_name)
            + " AND version_code = " + str(release.version_code)
            + " AND size_bytes = " + str(release.size_bytes)
            + " AND sha256 = " + sql_string(release.sha256)
            + ")"
        )
    return "(" + " OR ".join(clauses) + ")"


def build_candidate_policy_sql(
    issuer_id: int,
    issuer_level: int,
    releases: list[Release],
    download_urls: dict[str, str],
    release_notes: str,
) -> bytes:
    lines = ["SET NAMES utf8mb4;", "START TRANSACTION;"]
    for release in releases:
        lines.append(
            "INSERT INTO software_update_policies "
            "(issuer_type, issuer_id, issuer_level, edition_code, target_type, target_id, target_level, "
            "version_name, version_code, min_supported_version_code, download_url, package_name, sha256, "
            "size_bytes, release_notes, force_update, priority, status, starts_at, ends_at, created_at, updated_at) "
            "VALUES ("
            f"'platform', {issuer_id}, {issuer_level}, {sql_string(release.edition)}, 'global', NULL, NULL, "
            f"{sql_string(release.version_name)}, {release.version_code}, 0, "
            f"{sql_string(download_urls[release.edition])}, {sql_string(release.package_name)}, "
            f"{sql_string(release.sha256)}, {release.size_bytes}, {sql_string(release_notes)}, "
            f"0, {release.version_code}, 0, NULL, NULL, NOW(), NOW());"
        )
    lines.append("COMMIT;")
    return ("\n".join(lines) + "\n").encode("utf-8")


def build_activation_sql(
    issuer_id: int,
    releases: list[Release],
    download_urls: dict[str, str],
) -> str:
    selector = candidate_condition(releases, download_urls)
    edition_values = ", ".join(sql_string(edition) for edition in sorted(EDITIONS))
    scope = (
        "issuer_type = 'platform' "
        f"AND issuer_id = {issuer_id} AND target_type = 'global' "
        f"AND edition_code IN ({edition_values})"
    )
    return "\n".join(
        [
            "SET NAMES utf8mb4;",
            "START TRANSACTION;",
            "SELECT COUNT(*) INTO @candidate_count FROM software_update_policies "
            f"WHERE {scope} AND status = 0 AND {selector} FOR UPDATE;",
            "UPDATE software_update_policies SET status = 1, updated_at = NOW() "
            f"WHERE {scope} AND status = 0 AND {selector} AND @candidate_count = 4;",
            "SET @activated_count = ROW_COUNT();",
            "UPDATE software_update_policies SET status = 0, updated_at = NOW() "
            f"WHERE {scope} AND status = 1 AND NOT {selector} AND @activated_count = 4;",
            "SELECT CONCAT('ACTIVATED:', @candidate_count, ':', @activated_count, ':', COUNT(*)) "
            "FROM software_update_policies "
            f"WHERE {scope} AND status = 1 AND {selector};",
            "COMMIT;",
            "",
        ]
    )


def build_disable_candidate_sql(
    issuer_id: int,
    releases: list[Release],
    download_urls: dict[str, str],
) -> str:
    selector = candidate_condition(releases, download_urls)
    return (
        "UPDATE software_update_policies SET status = 0, updated_at = NOW() "
        f"WHERE issuer_type = 'platform' AND issuer_id = {issuer_id} "
        f"AND target_type = 'global' AND {selector};"
    )


def build_failure_recovery_sql(
    issuer_id: int,
    releases: list[Release],
    download_urls: dict[str, str],
    old_active_ids: list[int],
) -> str:
    lines = ["START TRANSACTION;", build_disable_candidate_sql(issuer_id, releases, download_urls)]
    if old_active_ids:
        ids = ", ".join(str(value) for value in sorted(set(old_active_ids)))
        lines.append(
            "UPDATE software_update_policies SET status = 1, updated_at = NOW() "
            f"WHERE issuer_type = 'platform' AND issuer_id = {issuer_id} "
            f"AND target_type = 'global' AND id IN ({ids});"
        )
    lines.extend(["COMMIT;", ""])
    return "\n".join(lines)


def policy_backup_command(args: argparse.Namespace, db_password: str, backup_dir: str) -> str:
    plain = posixpath.join(backup_dir, "software_update_policies.sql")
    compressed = plain + ".gz"
    return (
        "set -eu; DUMP_BIN=$(command -v mysqldump || true); "
        "if [ -z \"$DUMP_BIN\" ] && [ -x /www/server/mysql/bin/mysqldump ]; "
        "then DUMP_BIN=/www/server/mysql/bin/mysqldump; fi; "
        "test -n \"$DUMP_BIN\"; "
        f"MYSQL_PWD={quote(db_password)} \"$DUMP_BIN\" --default-character-set=utf8mb4 "
        f"-h {quote(args.db_host)} -P {args.db_port} -u {quote(args.db_user)} "
        f"--single-transaction --quick --skip-triggers {quote(args.db_name)} "
        f"software_update_policies > {quote(plain)}; "
        f"test -s {quote(plain)}; gzip -f {quote(plain)}; test -s {quote(compressed)}"
    )


def public_verification_command(
    release: Release,
    download_url: str,
    remote_stage: str,
) -> str:
    probe = posixpath.join(remote_stage, f"public-{release.edition}")
    full = probe + ".apk"
    full_headers = probe + ".full.headers"
    full_sha = probe + ".full.sha256"
    partial = probe + ".range"
    partial_headers = probe + ".range.headers"
    partial_sha = probe + ".range.sha256"
    range_end = release.range_size - 1
    expected_content_range = f"bytes 0-{range_end}/{release.size_bytes}"
    cleanup = " ".join(
        quote(path)
        for path in (full, full_headers, full_sha, partial, partial_headers, partial_sha)
    )
    return (
        "set -eu; "
        f"rm -f {cleanup}; "
        f"curl -fsSL --max-time 900 -D {quote(full_headers)} -o {quote(full)} {quote(download_url)}; "
        f"test $(stat -c %s {quote(full)}) -eq {release.size_bytes}; "
        f"sha256sum {quote(full)} > {quote(full_sha)}; read FULL_SHA _ < {quote(full_sha)}; "
        f"test \"$FULL_SHA\" = {quote(release.sha256)}; "
        f"ETAG=$(tr -d '\\r' < {quote(full_headers)} | "
        "sed -n 's/^[Ee][Tt][Aa][Gg]:[[:space:]]*//p' | tail -n 1); test -n \"$ETAG\"; "
        f"curl -fsSL --max-time 120 -D {quote(partial_headers)} -o {quote(partial)} "
        f"-H {quote(f'Range: bytes=0-{range_end}')} {quote(download_url)}; "
        f"STATUS=$(tr -d '\\r' < {quote(partial_headers)} | "
        "awk '/^HTTP\\// {code=$2} END {print code}'); test \"$STATUS\" = 206; "
        f"RANGE_ETAG=$(tr -d '\\r' < {quote(partial_headers)} | "
        "sed -n 's/^[Ee][Tt][Aa][Gg]:[[:space:]]*//p' | tail -n 1); "
        "test -n \"$RANGE_ETAG\"; test \"$RANGE_ETAG\" = \"$ETAG\"; "
        f"CONTENT_RANGE=$(tr -d '\\r' < {quote(partial_headers)} | "
        "sed -n 's/^[Cc][Oo][Nn][Tt][Ee][Nn][Tt]-[Rr][Aa][Nn][Gg][Ee]:[[:space:]]*//p' | tail -n 1); "
        f"test \"$CONTENT_RANGE\" = {quote(expected_content_range)}; "
        f"test $(stat -c %s {quote(partial)}) -eq {release.range_size}; "
        f"sha256sum {quote(partial)} > {quote(partial_sha)}; read RANGE_SHA _ < {quote(partial_sha)}; "
        f"test \"$RANGE_SHA\" = {quote(release.range_sha256)}; rm -f {cleanup}"
    )


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


def validate_lifecycle_payload(
    release: Release,
    download_url: str,
    payload: object,
) -> None:
    if not isinstance(payload, dict) or payload.get("code") != 1:
        raise RuntimeError(f"lifecycle returned an error payload: {payload!r}")
    data = payload.get("data")
    if not isinstance(data, dict):
        raise RuntimeError("lifecycle response data must be an object")
    update = data.get("update")
    if not isinstance(update, dict):
        raise RuntimeError("lifecycle response update must be an object")
    checks = {
        "edition_code": data.get("edition_code") == release.edition,
        "available": update.get("available") is True,
        "version_name": update.get("version_name") == release.version_name,
        "version_code": update.get("version_code") == release.version_code,
        "download_url": update.get("download_url") == download_url,
        "package_name": update.get("package_name") == release.package_name,
        "sha256": str(update.get("sha256", "")).lower() == release.sha256.lower(),
        "size_bytes": update.get("size_bytes") == release.size_bytes,
        "force_update": update.get("force_update") is False,
    }
    failed = [name for name, passed in checks.items() if not passed]
    if failed:
        raise RuntimeError(f"lifecycle release mismatch: {', '.join(failed)}")


def verify_lifecycle_release(
    client: paramiko.SSHClient,
    lifecycle_url: str,
    release: Release,
    download_url: str,
    context: dict[str, str],
    probe_token: str,
) -> None:
    query = {
        "edition_code": release.edition,
        "version_code": str(max(0, release.version_code - 1)),
        "release_probe": probe_token,
        **context,
    }
    url = lifecycle_url + "?" + urlencode(query)
    raw = run(
        client,
        "curl -fsS --max-time 30 "
        + f"--proto {quote('=http,https')} {quote(url)}",
        f"lifecycle-after-activation-{release.edition}",
    )
    try:
        payload = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise RuntimeError(
            f"lifecycle returned invalid JSON for {release.edition}"
        ) from exc
    validate_lifecycle_payload(release, download_url, payload)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", type=int, default=22)
    parser.add_argument("--user", required=True)
    parser.add_argument("--known-hosts", required=True)
    parser.add_argument("--remote-root", required=True)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--lifecycle-url", required=True)
    parser.add_argument("--allow-insecure-http-debug", action="store_true")
    parser.add_argument("--insecure-http-confirmation", default="")
    parser.add_argument("--version-name", required=True)
    parser.add_argument("--version-code", required=True, type=int)
    parser.add_argument("--release", action="append", required=True, type=parse_release)
    parser.add_argument("--release-manifest")
    parser.add_argument(
        "--release-identity",
        default=str(Path(__file__).resolve().parents[1] / "config" / "release-identity.json"),
    )
    parser.add_argument("--aapt")
    parser.add_argument("--apksigner")
    parser.add_argument("--release-notes", default="")
    parser.add_argument("--db-host", default="127.0.0.1")
    parser.add_argument("--db-port", type=int, default=3306)
    parser.add_argument("--db-name", required=True)
    parser.add_argument("--db-user", required=True)
    args = parser.parse_args()

    if args.version_code < 1:
        raise RuntimeError("version-code must be positive")
    args.known_hosts = os.path.abspath(args.known_hosts)
    if not os.path.isfile(args.known_hosts) or os.path.getsize(args.known_hosts) < 1:
        raise RuntimeError("--known-hosts must reference a non-empty pinned host-key file")

    base_url = normalize_download_base_url(args.base_url)
    lifecycle_url = normalize_lifecycle_url(args.lifecycle_url)
    args.remote_root = normalize_remote_root(args.remote_root)
    version_slug = re.sub(r"[^A-Za-z0-9._-]", "-", args.version_name).strip("-")
    if not version_slug:
        raise RuntimeError("version-name does not produce a safe release directory")

    repository_root = Path(__file__).resolve().parents[2]
    manifest_path = args.release_manifest or str(
        repository_root / "releases" / args.version_name / "release-manifest.json"
    )
    aapt_path = resolve_android_tool("aapt", args.aapt)
    apksigner_path = resolve_android_tool("apksigner", args.apksigner)
    releases, manifest = validate_release_plan(
        args.release,
        os.path.abspath(manifest_path),
        os.path.abspath(args.release_identity),
        args.version_name,
        args.version_code,
        aapt_path,
        apksigner_path,
    )
    validate_git_release_evidence(repository_root, manifest)
    _, manifest_sha256 = digest(os.path.abspath(manifest_path))
    args.release = releases
    insecure_http_debug = enforce_transport_policy(
        base_url,
        lifecycle_url,
        releases,
        args.allow_insecure_http_debug,
        args.insecure_http_confirmation,
    )
    if insecure_http_debug:
        print(
            "[non-production-debug] HTTP 明文发布已被显式确认；这不是正式 Release，"
            "仅用于 Debug 安装包闭环。",
            file=sys.stderr,
        )
    manifest_notes = manifest.get("releaseNotes", [])
    if not isinstance(manifest_notes, list):
        raise RuntimeError("release manifest releaseNotes must be a list")
    release_notes = args.release_notes.strip() or "；".join(
        str(note).strip() for note in manifest_notes if str(note).strip()
    )
    if not release_notes:
        raise RuntimeError("release notes are required")

    ssh_password = os.environ.get("YY_SSH_PASSWORD", "")
    db_password = os.environ.get("YY_DB_PASSWORD", "")
    if not ssh_password or not db_password:
        raise RuntimeError("YY_SSH_PASSWORD and YY_DB_PASSWORD are required")

    stamp = time.strftime("%Y%m%d-%H%M%S")
    release_token = secrets.token_hex(12)
    candidate_slug = f"{version_slug}-{release_token}"
    release_dir = posixpath.join(
        args.remote_root, "public", "downloads", candidate_slug
    )
    stage_dir = f"/tmp/yiyunying-android-{candidate_slug}"
    backup_dir = f"/www/backup/yiyunying/{stamp}-{release_token}-android"
    remote_sql = posixpath.join(stage_dir, "candidate-policies.sql")
    publication_receipt_dir = posixpath.join(
        args.remote_root, "storage", "private", "release-publication-receipts"
    )
    publication_receipt_path = posixpath.join(
        publication_receipt_dir,
        f"android-publication-{version_slug}-{release_token}.json",
    )
    staged_receipt_path = posixpath.join(stage_dir, "publication-receipt.json")
    download_urls = {
        release.edition: f"{base_url}/{candidate_slug}/{release.remote_filename}"
        for release in releases
    }

    client: paramiko.SSHClient | None = None
    issuer_id: int | None = None
    old_active_ids: list[int] = []
    candidate_started = False
    activated = False
    try:
        client = connect_ssh(args, ssh_password)
        fingerprint = client.get_transport().get_remote_server_key().get_fingerprint().hex()
        print(f"[ssh] connected; host-key={fingerprint}")
        run(
            client,
            f"test -d {quote(args.remote_root)} && "
            f"test -f {quote(args.remote_root + '/public/index.php')} && "
            "command -v curl >/dev/null && command -v sha256sum >/dev/null && "
            "command -v stat >/dev/null && command -v gzip >/dev/null",
            "preflight",
        )

        root_output = run(
            client,
            mysql_command(
                args,
                db_password,
                "--batch --raw --skip-column-names -e "
                + quote(
                    "SELECT id, level, platform_key FROM platform_accounts "
                    "WHERE level = 1 AND status = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1"
                ),
            ),
            "root-platform",
        ).strip()
        root_parts = root_output.split("\t")
        if (
            len(root_parts) != 3
            or not all(part.isdigit() for part in root_parts[:2])
            or not root_parts[2].strip()
        ):
            raise RuntimeError("active level-1 platform account was not found")
        issuer_id, issuer_level = (int(root_parts[0]), int(root_parts[1]))
        root_platform_key = root_parts[2].strip()

        authorized_platform_key = run(
            client,
            mysql_command(
                args,
                db_password,
                "--batch --raw --skip-column-names -e "
                + quote(
                    "SELECT platform_key FROM platform_accounts "
                    f"WHERE level = 2 AND parent_id = {issuer_id} AND status = 1 "
                    "AND deleted_at IS NULL ORDER BY id LIMIT 1"
                ),
            ),
            "authorized-platform-context",
        ).strip()
        if not authorized_platform_key or "\n" in authorized_platform_key:
            raise RuntimeError("an active level-2 platform context is required for lifecycle acceptance")

        app_key = run(
            client,
            mysql_command(
                args,
                db_password,
                "--batch --raw --skip-column-names -e "
                + quote(
                    "SELECT ap.app_key FROM apps ap "
                    "INNER JOIN admins a ON a.id = ap.admin_id "
                    "INNER JOIN platform_accounts p ON p.id = a.platform_id "
                    "WHERE ap.status = 1 AND ap.deleted_at IS NULL AND a.deleted_at IS NULL "
                    "AND p.deleted_at IS NULL "
                    f"AND (CASE WHEN p.level = 1 THEN p.id ELSE p.parent_id END) = {issuer_id} "
                    "ORDER BY ap.id LIMIT 1"
                ),
            ),
            "application-context",
        ).strip()
        if not app_key or "\n" in app_key:
            raise RuntimeError("an active application context is required for lifecycle acceptance")

        lifecycle_contexts = {
            "platform_owner": {"platform_key": root_platform_key},
            "authorized_platform": {"platform_key": authorized_platform_key},
            "admin": {"platform_key": root_platform_key},
            "user": {"app_key": app_key},
        }

        edition_values = ", ".join(sql_string(edition) for edition in sorted(EDITIONS))
        old_policy_output = run(
            client,
            mysql_command(
                args,
                db_password,
                "--batch --raw --skip-column-names -e "
                + quote(
                    "SELECT id FROM software_update_policies "
                    "WHERE issuer_type = 'platform' "
                    f"AND issuer_id = {issuer_id} AND target_type = 'global' AND status = 1 "
                    f"AND edition_code IN ({edition_values}) ORDER BY id"
                ),
            ),
            "snapshot-old-active-policies",
        )
        old_policy_lines = [line.strip() for line in old_policy_output.splitlines() if line.strip()]
        if any(not value.isdigit() for value in old_policy_lines):
            raise RuntimeError("old active policy snapshot returned an invalid id")
        old_active_ids = [int(value) for value in old_policy_lines]

        run(
            client,
            f"test ! -e {quote(stage_dir)} && test ! -e {quote(release_dir)} && "
            f"mkdir -p {quote(backup_dir)} {quote(stage_dir)}",
            "prepare-unique-staging",
        )
        candidate_started = True
        run(client, policy_backup_command(args, db_password, backup_dir), "policy-backup")

        client.close()
        client = None
        for release in releases:
            remote_stage = posixpath.join(stage_dir, release.remote_filename)
            print(
                f"[upload] {release.edition}: {release.size_bytes} bytes, sha256={release.sha256}"
            )
            upload_release(args, ssh_password, release, remote_stage)
        client = connect_ssh(args, ssh_password)

        for release in releases:
            remote_stage = posixpath.join(stage_dir, release.remote_filename)
            remote_identity = run(
                client,
                f"test $(stat -c %s {quote(remote_stage)}) -eq {release.size_bytes} && "
                f"sha256sum {quote(remote_stage)}",
                f"verify-{release.edition}",
            ).strip().split()
            remote_hash = remote_identity[0].lower() if remote_identity else ""
            if remote_hash != release.sha256:
                raise RuntimeError(
                    f"remote hash mismatch for {release.edition}: {remote_hash} != {release.sha256}"
                )

        run(
            client,
            f"test ! -e {quote(release_dir)} && mkdir -p {quote(release_dir)}",
            "public-candidate-directory",
        )
        for release in releases:
            remote_stage = posixpath.join(stage_dir, release.remote_filename)
            remote_final = posixpath.join(release_dir, release.remote_filename)
            run(
                client,
                f"install -m 0644 {quote(remote_stage)} {quote(remote_final)}",
                f"stage-public-{release.edition}",
            )

        sql_payload = build_candidate_policy_sql(
            issuer_id,
            issuer_level,
            releases,
            download_urls,
            release_notes,
        )

        sftp = client.open_sftp()
        try:
            with sftp.open(remote_sql, "wb") as stream:
                stream.write(sql_payload)
        finally:
            sftp.close()
        run(
            client,
            mysql_command(args, db_password, f"< {quote(remote_sql)}"),
            "insert-disabled-candidate-policies",
        )

        for release in releases:
            run(
                client,
                public_verification_command(
                    release, download_urls[release.edition], stage_dir
                ),
                f"public-sha-range-etag-{release.edition}",
            )

        activation_output = run(
            client,
            mysql_command(
                args,
                db_password,
                "--batch --raw --skip-column-names -e "
                + quote(build_activation_sql(issuer_id, releases, download_urls)),
            ),
            "activate-four-policies-atomically",
        )
        marker = next(
            (
                line.strip()
                for line in activation_output.splitlines()
                if line.strip().startswith("ACTIVATED:")
            ),
            "",
        )
        if marker != "ACTIVATED:4:4:4":
            raise RuntimeError(f"candidate activation guard rejected the release: {marker or 'missing marker'}")

        for release in releases:
            verify_lifecycle_release(
                client,
                lifecycle_url,
                release,
                download_urls[release.edition],
                lifecycle_contexts[release.edition],
                candidate_slug,
            )

        receipt = {
            "status": "activated",
            "version_name": args.version_name,
            "version_code": args.version_code,
            "build_source_commit": str(manifest["buildSourceCommit"]).lower(),
            "release_evidence_commit": str(manifest["releaseEvidenceCommit"]).lower(),
            "release_tag": str(manifest["releaseTag"]),
            "release_identity_sha256": str(manifest["releaseIdentitySha256"]).lower(),
            "release_manifest_sha256": manifest_sha256.lower(),
            "activated_at_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "releases": [
                {
                    "edition": release.edition,
                    "version_name": release.version_name,
                    "version_code": release.version_code,
                    "download_url": download_urls[release.edition],
                    "package_name": release.package_name,
                    "sha256": release.sha256.lower(),
                    "size_bytes": release.size_bytes,
                    "signer_sha256": release.signer_sha256.lower(),
                }
                for release in sorted(releases, key=lambda item: item.edition)
            ],
        }
        receipt_payload = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
        sftp = client.open_sftp()
        try:
            with sftp.open(staged_receipt_path, "wb") as stream:
                stream.write(receipt_payload)
        finally:
            sftp.close()
        run(
            client,
            f"mkdir -p {quote(publication_receipt_dir)} && "
            f"test ! -e {quote(publication_receipt_path)} && "
            f"install -m 0600 {quote(staged_receipt_path)} {quote(publication_receipt_path)}",
            "write-activated-publication-receipt",
        )
        activated = True
        print(f"[complete] release={release_dir}")
        print(f"[complete] backup={backup_dir}")
    finally:
        if candidate_started:
            try:
                transport = client.get_transport() if client is not None else None
                if transport is None or not transport.is_active():
                    if client is not None:
                        client.close()
                    client = connect_ssh(args, ssh_password)
                if not activated and issuer_id is not None:
                    run(
                        client,
                        mysql_command(
                            args,
                            db_password,
                            "--batch --raw --skip-column-names -e "
                            + quote(
                                build_failure_recovery_sql(
                                    issuer_id,
                                    releases,
                                    download_urls,
                                    old_active_ids,
                                )
                            ),
                        ),
                        "restore-old-and-disable-failed-candidates",
                    )
                cleanup_paths = [stage_dir]
                if not activated:
                    cleanup_paths.append(release_dir)
                    cleanup_paths.append(publication_receipt_path)
                run(
                    client,
                    "rm -rf -- " + " ".join(quote(path) for path in cleanup_paths),
                    "candidate-cleanup" if not activated else "temporary-cleanup",
                )
            except Exception as cleanup_error:
                print(
                    f"[cleanup-warning] {type(cleanup_error).__name__}: {cleanup_error}",
                    file=sys.stderr,
                )
        if client is not None:
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
