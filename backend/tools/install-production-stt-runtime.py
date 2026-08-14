#!/usr/bin/env python3
"""Install the frozen offline STT runtime over pinned SSH.

The default action validates the complete local source bundle and performs a
read-only remote preflight.  ``--execute`` additionally derives a production
payload locally, uploads it to a unique root-only stage, and invokes the
content-addressed remote installer.  The production host never contacts the
Internet and no byte from the legacy www-writable STT tree is reused.
"""

from __future__ import annotations

import argparse
from dataclasses import dataclass
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import shlex
import stat
import sys
import tarfile
import tempfile
import time
from typing import Any, BinaryIO


SCRIPT = Path(__file__).resolve()
BACKEND = SCRIPT.parents[1]
OFFLINE = BACKEND / "tools" / "stt" / "offline"
PINNED_ARTIFACTS = OFFLINE / "artifacts.json"
PINNED_LOCK = OFFLINE / "requirements-linux-x86_64-cp311.lock"
PINNED_MODEL_MANIFEST = OFFLINE / "model-manifest.json"
PINNED_BUILDER_TOOLS = OFFLINE / "builder-tools.json"
PINNED_LICENSE_EVIDENCE = OFFLINE / "license-evidence.json"
REMOTE_INSTALLER = OFFLINE / "remote-install.py"
TRANSCRIBE_WRAPPER = BACKEND / "tools" / "stt" / "transcribe.py"
EXPECTED_REMOTE_ROOT = "/www/wwwroot/appht.jjmxg.xyz/yiyunying-backend"
EXPECTED_USER = "root"
RUNTIME_USER = "www"
RUNTIME_GROUP = "www"
EXECUTE_CONFIRMATION = "install-offline-stt-cpython-3.11.15-faster-whisper-1.2.1"
MAINTENANCE_CONFIRMATION = "stt-current-switch-and-rollback-reviewed"
REMOTE_TIMEOUT = 30 * 60
SFTP_TIMEOUT = 15 * 60
MAX_REMOTE_OUTPUT = 128 * 1024
MINIMUM_REMOTE_FREE_BYTES = 2 << 30
MINIMUM_TMP_FREE_BYTES = 512 << 20
PROBE_SIZE = 32_044
PROBE_SHA256 = "d13e4f6fd2e70b6d93dbc1029412c4a00716e5539a9840d2dd746b414170df94"
THIRD_PARTY_NOTICES_SIZE = 90_601
THIRD_PARTY_NOTICES_SHA256 = "55cd6e0bca728d3d053389310bb8eacdefc95e803fb55d927965ba0ec19a170e"
HASH_RE = re.compile(r"^[0-9a-f]{64}$")
REMOTE_STAGE_RE = re.compile(r"^/tmp/\.yiyunying-stt-runtime-([0-9a-f]{32})$")
REMOTE_ARCHIVE_RE = re.compile(r"^/tmp/\.yiyunying-stt-runtime-([0-9a-f]{32})/payload\.tar$")
REMOTE_HELPER_RE = re.compile(r"^/tmp/\.yiyunying-stt-runtime-([0-9a-f]{32})/remote-install\.py$")
SOURCE_BUNDLE_REQUIRED = {
    "metadata/artifacts.json",
    "metadata/requirements-linux-x86_64-cp311.lock",
    "metadata/model-manifest.json",
    "metadata/builder-tools.json",
    "metadata/license-evidence.json",
    "metadata/dependency-closure.json",
    "probe/stt-runtime-probe.wav",
    "tree-manifest.json",
}


@dataclass(frozen=True)
class SourceInspection:
    bundle_path: Path
    bundle_size: int
    bundle_sha256: str
    source_manifest_sha256: str
    bundle_id: str
    release_id: str
    extracted_root: Path
    temporary: tempfile.TemporaryDirectory[str]


@dataclass(frozen=True)
class Payload:
    path: Path
    size: int
    sha256: str
    helper_sha256: str
    fingerprint: tuple[int, int, int, int]
    temporary: tempfile.TemporaryDirectory[str]


class RecoveryRequired(RuntimeError):
    pass


def canonical_json(value: Any) -> bytes:
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n").encode("utf-8")


def sha256_stream(handle: BinaryIO) -> tuple[int, str]:
    digest = hashlib.sha256()
    size = 0
    while True:
        chunk = handle.read(1024 * 1024)
        if not chunk:
            break
        size += len(chunk)
        digest.update(chunk)
    return size, digest.hexdigest()


def sha256_file(path: Path) -> tuple[int, str]:
    with path.open("rb") as handle:
        return sha256_stream(handle)


def validate_regular_file(path: Path, label: str) -> os.stat_result:
    metadata = os.lstat(path)
    reparse = getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0)
    if (
        path.is_symlink()
        or not stat.S_ISREG(metadata.st_mode)
        or metadata.st_nlink != 1
        or (reparse and getattr(metadata, "st_file_attributes", 0) & reparse)
    ):
        raise RuntimeError(f"{label} must be one unique regular non-link file")
    return metadata


def trusted_input_file(path: Path, label: str) -> Path:
    unresolved = path.expanduser().absolute()
    validate_regular_file(unresolved, label)
    resolved = unresolved.resolve(strict=True)
    if resolved != unresolved:
        raise RuntimeError(f"{label} path traverses a link or reparse point")
    return resolved


def safe_name(name: str) -> bool:
    pure = PurePosixPath(name)
    return bool(
        name
        and len(name.encode("utf-8")) <= 4096
        and not pure.is_absolute()
        and ".." not in pure.parts
        and "\\" not in name
        and "\x00" not in name
    )


def inspect_source_bundle(path: Path, expected_sha256: str) -> SourceInspection:
    if not HASH_RE.fullmatch(expected_sha256):
        raise RuntimeError("--bundle-sha256 must be exactly 64 lowercase hex characters")
    expanded = trusted_input_file(path, "offline STT source bundle")
    metadata = os.lstat(expanded)
    size, digest = sha256_file(expanded)
    if not secrets.compare_digest(digest, expected_sha256):
        raise RuntimeError("offline STT source bundle SHA-256 does not match operator input")
    before = (metadata.st_dev, metadata.st_ino, metadata.st_size, metadata.st_mtime_ns)
    # Keep all large, transient expansion on the same D: volume as the
    # operator-supplied bundle.  The repository migration deliberately avoids
    # rebuilding hundreds of MiB under the Windows system drive.
    temporary = tempfile.TemporaryDirectory(
        prefix="yiyunying-stt-source-",
        dir=str(expanded.parent),
    )
    root = Path(temporary.name) / "source"
    root.mkdir(mode=0o700)
    try:
        with tarfile.open(expanded, "r:") as archive:
            members = archive.getmembers()
            names = [member.name for member in members]
            if len(names) != len(set(names)):
                raise RuntimeError("source bundle contains duplicate member paths")
            for member in members:
                if not safe_name(member.name) or not (member.isfile() or member.isdir()):
                    raise RuntimeError("source bundle contains a link, special, or unsafe member")
                destination = root / Path(*PurePosixPath(member.name).parts)
                if member.isdir():
                    destination.mkdir(parents=True, exist_ok=True, mode=0o700)
                    continue
                destination.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                source = archive.extractfile(member)
                if source is None:
                    raise RuntimeError("source bundle member cannot be read")
                descriptor = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
                with os.fdopen(descriptor, "wb") as output:
                    while True:
                        chunk = source.read(1024 * 1024)
                        if not chunk:
                            break
                        output.write(chunk)
        manifest_path = root / "tree-manifest.json"
        validate_regular_file(manifest_path, "source tree manifest")
        manifest_bytes = manifest_path.read_bytes()
        manifest = json.loads(manifest_bytes.decode("utf-8"))
        if manifest.get("schema_version") != 1:
            raise RuntimeError("source tree manifest schema is invalid")
        if manifest.get("bundle_id") != "stt-cpython-3.11.15-faster-whisper-1.2.1-20260718":
            raise RuntimeError("source tree manifest bundle id is not reviewed")
        records = manifest.get("files")
        if not isinstance(records, list) or manifest.get("file_count") != len(records):
            raise RuntimeError("source tree manifest count is invalid")
        expected_paths = {"tree-manifest.json"}
        payload_size = 0
        for record in records:
            if not isinstance(record, dict):
                raise RuntimeError("source tree manifest record is invalid")
            relative = record.get("path")
            expected_size = record.get("size")
            expected_hash = record.get("sha256")
            if (
                not isinstance(relative, str)
                or not safe_name(relative)
                or relative in expected_paths
                or not isinstance(expected_size, int)
                or expected_size < 0
                or not isinstance(expected_hash, str)
                or not HASH_RE.fullmatch(expected_hash)
            ):
                raise RuntimeError("source tree manifest identity is invalid")
            expected_paths.add(relative)
            candidate = root / Path(*PurePosixPath(relative).parts)
            item_metadata = validate_regular_file(candidate, relative)
            item_size, item_hash = sha256_file(candidate)
            if item_metadata.st_size != expected_size or item_size != expected_size or item_hash != expected_hash:
                raise RuntimeError(f"source tree file identity mismatch: {relative}")
            payload_size += expected_size
        actual_paths = {
            candidate.relative_to(root).as_posix()
            for candidate in root.rglob("*")
            if candidate.is_file() and not candidate.is_symlink()
        }
        if actual_paths != expected_paths or manifest.get("payload_size") != payload_size:
            raise RuntimeError("source bundle contains an unmanifested/missing file or size")
        if not SOURCE_BUNDLE_REQUIRED.issubset(actual_paths):
            raise RuntimeError("source bundle omits a required contract/probe file")
        for relative, pinned in (
            ("metadata/artifacts.json", PINNED_ARTIFACTS),
            ("metadata/requirements-linux-x86_64-cp311.lock", PINNED_LOCK),
            ("metadata/model-manifest.json", PINNED_MODEL_MANIFEST),
            ("metadata/builder-tools.json", PINNED_BUILDER_TOOLS),
            ("metadata/license-evidence.json", PINNED_LICENSE_EVIDENCE),
        ):
            if (root / relative).read_bytes() != pinned.read_bytes():
                raise RuntimeError(f"source bundle contract differs from repository pin: {relative}")
        artifact_manifest = json.loads((root / "metadata" / "artifacts.json").read_text(encoding="utf-8"))
        artifact_paths = validate_frozen_artifacts(root, artifact_manifest)
        evidence_paths = validate_license_evidence_artifacts(root)
        validate_closed_source_topology(
            root,
            actual_paths,
            artifact_paths,
            evidence_paths,
            artifact_manifest,
        )
        source_manifest_sha = hashlib.sha256(manifest_bytes).hexdigest()
        validate_regular_file(REMOTE_INSTALLER, "repository remote STT installer")
        validate_regular_file(TRANSCRIBE_WRAPPER, "repository STT wrapper")
        _helper_size, helper_hash = sha256_file(REMOTE_INSTALLER)
        _wrapper_size, wrapper_hash = sha256_file(TRANSCRIBE_WRAPPER)
        release_identity = canonical_json({
            "remote_installer_sha256": helper_hash,
            "source_manifest_sha256": source_manifest_sha,
            "transcribe_wrapper_sha256": wrapper_hash,
        })
        release_id = "py31115-fw121-ebe41f70d5b6-" + hashlib.sha256(release_identity).hexdigest()[:12]
        after = os.lstat(expanded)
        if before != (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns):
            raise RuntimeError("source bundle changed during validation")
        return SourceInspection(
            bundle_path=expanded,
            bundle_size=size,
            bundle_sha256=digest,
            source_manifest_sha256=source_manifest_sha,
            bundle_id=str(manifest.get("bundle_id", "")),
            release_id=release_id,
            extracted_root=root,
            temporary=temporary,
        )
    except BaseException:
        temporary.cleanup()
        raise


def validate_frozen_artifacts(root: Path, manifest: dict[str, Any]) -> set[str]:
    records: list[tuple[str, Path, int, str]] = []
    python = manifest.get("python", {})
    for key in ("runtime", "license_companion"):
        item = python.get(key, {})
        relative = "python/" + str(item.get("filename", ""))
        records.append((relative, root / Path(*PurePosixPath(relative).parts), int(item.get("size", -1)), str(item.get("sha256", ""))))
    wheels = manifest.get("wheels", [])
    if not isinstance(wheels, list) or len(wheels) != 30:
        raise RuntimeError("source bundle does not contain the frozen 30-wheel manifest")
    for item in wheels:
        relative = "wheelhouse/" + str(item.get("filename", ""))
        records.append((relative, root / Path(*PurePosixPath(relative).parts), int(item.get("size", -1)), str(item.get("sha256", ""))))
    model = manifest.get("model", {})
    if model.get("revision") != "ebe41f70d5b6dfa9166e2c581c45c9c0cfc57b66":
        raise RuntimeError("source bundle model revision changed")
    for item in model.get("files", []):
        relative = "model/base/" + str(item.get("filename", ""))
        records.append((relative, root / Path(*PurePosixPath(relative).parts), int(item.get("size", -1)), str(item.get("sha256", ""))))
    if len(records) != 36:
        raise RuntimeError("source bundle frozen artifact count changed")
    expected_paths: set[str] = set()
    for relative, path, expected_size, expected_hash in records:
        if not safe_name(relative) or relative in expected_paths or expected_size <= 0 or not HASH_RE.fullmatch(expected_hash):
            raise RuntimeError("source bundle artifact contract is invalid")
        expected_paths.add(relative)
        metadata = validate_regular_file(path, path.name)
        size, digest = sha256_file(path)
        if metadata.st_size != expected_size or size != expected_size or digest != expected_hash:
            raise RuntimeError(f"source bundle artifact identity mismatch: {path.name}")
    return expected_paths


def validate_license_evidence_artifacts(root: Path) -> set[str]:
    manifest = json.loads((root / "metadata" / "license-evidence.json").read_text(encoding="utf-8"))
    records = manifest.get("artifacts")
    if not isinstance(records, list) or len(records) != 3:
        raise RuntimeError("source bundle license evidence manifest count changed")
    expected_paths: set[str] = set()
    for record in records:
        if not isinstance(record, dict):
            raise RuntimeError("source bundle license evidence record is invalid")
        relative = "evidence/licenses/" + str(record.get("filename", ""))
        expected_size = record.get("size")
        expected_hash = record.get("sha256")
        if (
            not safe_name(relative)
            or relative in expected_paths
            or not isinstance(expected_size, int)
            or expected_size <= 0
            or not isinstance(expected_hash, str)
            or not HASH_RE.fullmatch(expected_hash)
        ):
            raise RuntimeError("source bundle license evidence identity is invalid")
        expected_paths.add(relative)
        path = root / Path(*PurePosixPath(relative).parts)
        metadata = validate_regular_file(path, path.name)
        size, digest = sha256_file(path)
        if metadata.st_size != expected_size or size != expected_size or digest != expected_hash:
            raise RuntimeError(f"source bundle license evidence mismatch: {path.name}")
    return expected_paths


def validate_closed_source_topology(
    root: Path,
    actual_paths: set[str],
    artifact_paths: set[str],
    evidence_paths: set[str],
    artifact_manifest: dict[str, Any],
) -> None:
    metadata_paths = {
        "metadata/artifacts.json",
        "metadata/requirements-linux-x86_64-cp311.lock",
        "metadata/model-manifest.json",
        "metadata/builder-tools.json",
        "metadata/license-evidence.json",
        "metadata/dependency-closure.json",
    }
    probe_contract = artifact_manifest.get("probe", {})
    probe_relative = "probe/" + str(probe_contract.get("filename", ""))
    if (
        probe_relative != "probe/stt-runtime-probe.wav"
        or probe_contract.get("size") != PROBE_SIZE
        or probe_contract.get("sha256") != PROBE_SHA256
    ):
        raise RuntimeError("source bundle probe contract changed")
    probe_path = root / "probe" / "stt-runtime-probe.wav"
    probe_size, probe_hash = sha256_file(probe_path)
    if probe_size != PROBE_SIZE or probe_hash != PROBE_SHA256:
        raise RuntimeError("source bundle probe identity changed")

    notices_relative = "licenses/THIRD_PARTY_NOTICES.json"
    notices_path = root / "licenses" / "THIRD_PARTY_NOTICES.json"
    notices_size, notices_hash = sha256_file(notices_path)
    if notices_size != THIRD_PARTY_NOTICES_SIZE or notices_hash != THIRD_PARTY_NOTICES_SHA256:
        raise RuntimeError("source bundle third-party notices differ from the reviewed exact lock")
    notices = json.loads(notices_path.read_text(encoding="utf-8"))
    extracted = notices.get("extracted")
    if notices.get("schema_version") != 1 or not isinstance(extracted, list) or len(extracted) != 264:
        raise RuntimeError("source bundle extracted license index changed")
    license_paths: set[str] = {notices_relative}
    for record in extracted:
        if not isinstance(record, dict) or set(record) != {"component", "source_member", "path", "size", "sha256"}:
            raise RuntimeError("source bundle extracted license record is invalid")
        relative = record.get("path")
        expected_size = record.get("size")
        expected_hash = record.get("sha256")
        if (
            not isinstance(relative, str)
            or not relative.startswith("licenses/")
            or not safe_name(relative)
            or relative in license_paths
            or not isinstance(expected_size, int)
            or expected_size < 0
            or expected_size > 4 * 1024 * 1024
            or not isinstance(expected_hash, str)
            or not HASH_RE.fullmatch(expected_hash)
        ):
            raise RuntimeError("source bundle extracted license identity is invalid")
        license_paths.add(relative)
        path = root / Path(*PurePosixPath(relative).parts)
        metadata = validate_regular_file(path, path.name)
        size, digest = sha256_file(path)
        if metadata.st_size != expected_size or size != expected_size or digest != expected_hash:
            raise RuntimeError(f"source bundle extracted license mismatch: {relative}")

    expected_paths = {
        "tree-manifest.json",
        *artifact_paths,
        *evidence_paths,
        *metadata_paths,
        probe_relative,
        *license_paths,
    }
    if actual_paths != expected_paths:
        raise RuntimeError("source bundle topology is not the reviewed closed file set")


def iter_payload_sources(source: SourceInspection) -> list[tuple[str, Path]]:
    root = source.extracted_root
    artifacts = json.loads((root / "metadata" / "artifacts.json").read_text(encoding="utf-8"))
    runtime_name = artifacts["python"]["runtime"]["filename"]
    selected: list[tuple[str, Path]] = [(f"python/{runtime_name}", root / "python" / runtime_name)]
    for directory in ("wheelhouse", "model", "metadata", "probe", "licenses"):
        base = root / directory
        if not base.is_dir() or base.is_symlink():
            raise RuntimeError(f"source bundle production directory is missing: {directory}")
        for path in sorted(base.rglob("*"), key=lambda item: item.relative_to(root).as_posix()):
            if path.is_dir():
                continue
            validate_regular_file(path, path.name)
            selected.append((path.relative_to(root).as_posix(), path))
    selected.append(("installer/remote-install.py", REMOTE_INSTALLER))
    selected.append(("installer/transcribe.py", TRANSCRIBE_WRAPPER))
    names = [name for name, _path in selected]
    if len(names) != len(set(names)):
        raise RuntimeError("derived payload contains duplicate paths")
    return selected


def add_tar_directory(archive: tarfile.TarFile, name: str) -> None:
    info = tarfile.TarInfo(name.rstrip("/") + "/")
    info.type = tarfile.DIRTYPE
    info.mode = 0o700
    info.uid = 0
    info.gid = 0
    info.uname = "root"
    info.gname = "root"
    info.mtime = 0
    archive.addfile(info)


def build_payload(source: SourceInspection) -> Payload:
    temporary = tempfile.TemporaryDirectory(
        prefix="yiyunying-stt-payload-",
        dir=str(source.bundle_path.parent),
    )
    path = Path(temporary.name) / f"stt-production-{source.release_id}.tar"
    selected = iter_payload_sources(source)
    records = []
    for relative, candidate in selected:
        size, digest = sha256_file(candidate)
        records.append({"path": relative, "size": size, "sha256": digest})
    payload_manifest = {
        "schema_version": 1,
        "target": "linux-x86_64-cp311-glibc2.17",
        "release_id": source.release_id,
        "source_manifest_sha256": source.source_manifest_sha256,
        "files": records,
    }
    manifest_bytes = canonical_json(payload_manifest)
    directories: set[str] = set()
    with path.open("xb") as output:
        with tarfile.open(fileobj=output, mode="w", format=tarfile.PAX_FORMAT) as archive:
            all_files = [("payload-manifest.json", None), *selected]
            for relative, candidate in all_files:
                pure = PurePosixPath(relative)
                parents: list[str] = []
                parent = pure.parent
                while str(parent) != ".":
                    parents.append(str(parent))
                    parent = parent.parent
                for directory in reversed(parents):
                    if directory not in directories:
                        add_tar_directory(archive, directory)
                        directories.add(directory)
                info = tarfile.TarInfo(relative)
                info.mode = 0o600
                info.uid = 0
                info.gid = 0
                info.uname = "root"
                info.gname = "root"
                info.mtime = 0
                if candidate is None:
                    import io

                    info.size = len(manifest_bytes)
                    archive.addfile(info, io.BytesIO(manifest_bytes))
                else:
                    info.size = candidate.stat().st_size
                    with candidate.open("rb") as source_file:
                        archive.addfile(info, source_file)
        output.flush()
        os.fsync(output.fileno())
    metadata = validate_regular_file(path, "derived STT production payload")
    size, digest = sha256_file(path)
    helper_size, helper_hash = sha256_file(REMOTE_INSTALLER)
    if helper_size <= 0:
        raise RuntimeError("remote STT installer helper is empty")
    return Payload(
        path=path,
        size=size,
        sha256=digest,
        helper_sha256=helper_hash,
        fingerprint=(metadata.st_dev, metadata.st_ino, metadata.st_size, metadata.st_mtime_ns),
        temporary=temporary,
    )


def validate_known_hosts(path: Path) -> Path:
    expanded = trusted_input_file(path, "known_hosts")
    metadata = os.lstat(expanded)
    if metadata.st_size < 1:
        raise RuntimeError("known_hosts must not be empty")
    return expanded


def connect(args: argparse.Namespace, password: str):
    try:
        import paramiko
    except ImportError as exc:
        raise RuntimeError("paramiko==5.0.0 is required from the reviewed release tooling environment") from exc
    expected_host = os.environ.get("YY_SSH_EXPECTED_HOST", "")
    if not expected_host or args.host != expected_host:
        raise RuntimeError("SSH host must match the host sealed inside the DPAPI launcher context")
    known_hosts = validate_known_hosts(Path(args.known_hosts))
    client = paramiko.SSHClient()
    client.load_host_keys(str(known_hosts))
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect(
        args.host,
        port=args.port,
        username=args.user,
        password=password,
        timeout=20,
        banner_timeout=20,
        auth_timeout=20,
        look_for_keys=False,
        allow_agent=False,
        disabled_algorithms={"kex": ["curve25519-sha256", "curve25519-sha256@libssh.org"]},
    )
    transport = client.get_transport()
    if transport is None or not transport.is_active():
        client.close()
        raise RuntimeError("SSH transport is inactive")
    transport.set_keepalive(15)
    return client


def sanitize(value: Any, secrets_to_remove: tuple[str, ...]) -> str:
    result = str(value).replace("\x00", "?")
    for secret in secrets_to_remove:
        if secret:
            result = result.replace(secret, "[REDACTED]")
    return result[:MAX_REMOTE_OUTPUT]


def collect_channel(channel: Any, timeout: float, password: str) -> tuple[int, str, str]:
    deadline = time.monotonic() + timeout
    stdout = bytearray()
    stderr = bytearray()
    while not channel.exit_status_ready():
        while channel.recv_ready():
            stdout.extend(channel.recv(8192))
        while channel.recv_stderr_ready():
            stderr.extend(channel.recv_stderr(8192))
        if len(stdout) + len(stderr) > MAX_REMOTE_OUTPUT:
            channel.close()
            raise RuntimeError("remote output exceeded the reviewed bound")
        if time.monotonic() >= deadline:
            channel.close()
            raise TimeoutError("remote command exceeded the reviewed timeout")
        time.sleep(0.02)
    while channel.recv_ready():
        stdout.extend(channel.recv(8192))
    while channel.recv_stderr_ready():
        stderr.extend(channel.recv_stderr(8192))
    if len(stdout) + len(stderr) > MAX_REMOTE_OUTPUT:
        channel.close()
        raise RuntimeError("remote output exceeded the reviewed bound")
    status = channel.recv_exit_status()
    return (
        status,
        sanitize(stdout.decode("utf-8", "replace"), (password,)),
        sanitize(stderr.decode("utf-8", "replace"), (password,)),
    )


def run_remote(
    client: Any,
    command: str,
    label: str,
    password: str,
    *,
    timeout: int = REMOTE_TIMEOUT,
    require_empty_stderr: bool = False,
) -> str:
    _stdin, stdout, _stderr = client.exec_command(command, get_pty=False, timeout=timeout)
    status, output, error = collect_channel(stdout.channel, timeout, password)
    if status != 0:
        raise RuntimeError(f"{label} failed ({status}): {(error or output or 'no diagnostic').strip()}")
    if require_empty_stderr and error.strip():
        raise RuntimeError(f"{label} returned unexpected stderr: {error.strip()}")
    return output


def preflight_command() -> str:
    root = shlex.quote(EXPECTED_REMOTE_ROOT)
    user = shlex.quote(RUNTIME_USER)
    group = shlex.quote(RUNTIME_GROUP)
    return f'''set -eu
export LC_ALL=C LANG=C
test "$(id -u)" -eq 0
test "$(uname -s)" = Linux
test "$(uname -m)" = x86_64
root={root}
test -d "$root" && test ! -L "$root"
test -d "$root/storage/stt" && test ! -L "$root/storage/stt"
id -u {user} >/dev/null
getent group {group} >/dev/null
libc=$(getconf GNU_LIBC_VERSION)
  for tool in sha256sum stat df uname getconf unshare runuser env ldd readelf awk cut readlink tar; do command -v "$tool" >/dev/null; done
glibc_ok=$(printf '%s\n' "$libc" | awk '$1=="glibc" {{ split($2,v,"."); if ((v[1]+0)>2 || ((v[1]+0)==2 && (v[2]+0)>=17)) print "yes" }}')
[ "$glibc_ok" = yes ] || exit 31
for trusted_dir in /www /www/wwwroot /www/wwwroot/appht.jjmxg.xyz "$root" "$root/storage" "$root/storage/stt" "$root/tools" "$root/tools/stt"; do
  [ -d "$trusted_dir" ] && [ ! -L "$trusted_dir" ] || exit 34
  state=$(stat -c '%u|%a|%F' -- "$trusted_dir")
  owner=$(printf '%s' "$state" | cut -d'|' -f1)
  mode=$(printf '%s' "$state" | cut -d'|' -f2)
  kind=$(printf '%s' "$state" | cut -d'|' -f3)
  [ "$owner" = 0 ] && [ "$kind" = directory ] && [ $((0$mode & 022)) -eq 0 ] || exit 35
done
python=''
python_version=''
for candidate in /usr/local/bin/python3.12 /usr/bin/python3.12 /usr/local/bin/python3.11 /usr/bin/python3.11 /usr/local/bin/python3 /usr/bin/python3; do
  [ -f "$candidate" ] && [ -x "$candidate" ] && [ ! -L "$candidate" ] || continue
  state=$(stat -c '%u|%a|%F' -- "$candidate")
  case "$state" in 0\\|???\\|regular\\ file) ;; *) continue;; esac
  mode=$(printf '%s' "$state" | cut -d'|' -f2)
  [ $((0$mode & 022)) -eq 0 ] || continue
  if version=$("$candidate" -I -S -B -c 'import pathlib,subprocess,sys; assert sys.version_info >= (3,9); assert hasattr(pathlib.Path,"is_relative_to"); assert "capture_output" in __import__("inspect").signature(subprocess.run).parameters; print(".".join(map(str,sys.version_info[:3])))' 2>/dev/null); then
    case "$version" in [0-9]*.[0-9]*.[0-9]*) python="$candidate"; python_version="$version"; break;; esac
  fi
done
[ -n "$python" ]
    free=$(df -PB1 "$root/storage/stt" | awk 'NR==2 {{print $4}}')
tmp_free=$(df -PB1 /tmp | awk 'NR==2 {{print $4}}')
[ "$free" -ge {MINIMUM_REMOTE_FREE_BYTES} ]
[ "$tmp_free" -ge {MINIMUM_TMP_FREE_BYTES} ]
current='NONE'
if [ -e "$root/storage/stt/current" ] || [ -L "$root/storage/stt/current" ]; then
  [ -L "$root/storage/stt/current" ] || exit 32
  current=$(readlink "$root/storage/stt/current")
  case "$current" in releases/*) current_name=${{current#releases/}};; *) exit 33;; esac
  case "$current_name" in ''|.|..|*/*|*[!A-Za-z0-9._-]*) exit 33;; esac
  case "$current_name" in py31115-fw121-ebe41f70d5b6-[0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]) ;; *) exit 33;; esac
fi
printf 'SYSTEM_PYTHON=%s\nSYSTEM_PYTHON_VERSION=%s\nGLIBC=%s\nCURRENT=%s\nFREE_BYTES=%s\nTMP_FREE_BYTES=%s\n' "$python" "$python_version" "$libc" "$current" "$free" "$tmp_free"
'''


def parse_system_python(output: str) -> str:
    values = {}
    for line in output.splitlines():
        if "=" in line:
            key, value = line.split("=", 1)
            values[key] = value
    python = values.get("SYSTEM_PYTHON", "")
    allowed = {
        "/usr/local/bin/python3.12",
        "/usr/bin/python3.12",
        "/usr/local/bin/python3.11",
        "/usr/bin/python3.11",
        "/usr/local/bin/python3",
        "/usr/bin/python3",
    }
    if python not in allowed:
        raise RuntimeError("remote preflight returned no trusted system Python")
    version = values.get("SYSTEM_PYTHON_VERSION", "")
    match = re.fullmatch(r"([0-9]+)\.([0-9]+)\.([0-9]+)", version)
    if match is None or (int(match.group(1)), int(match.group(2))) < (3, 9):
        raise RuntimeError("remote preflight system Python is older than 3.9")
    return python


def create_stage_command(remote_path: str) -> str:
    match = REMOTE_ARCHIVE_RE.fullmatch(remote_path)
    if match is None:
        raise RuntimeError("remote STT stage path is outside the reviewed pattern")
    stage = f"/tmp/.yiyunying-stt-runtime-{match.group(1)}"
    if REMOTE_STAGE_RE.fullmatch(stage) is None:
        raise RuntimeError("remote STT stage directory is outside the reviewed pattern")
    quoted = shlex.quote(remote_path)
    quoted_stage = shlex.quote(stage)
    return (
        f"set -eu; umask 077; test ! -e {quoted_stage} && test ! -L {quoted_stage}; "
        f"mkdir -m 0700 -- {quoted_stage}; "
        f"test \"$(stat -c '%u|%a|%F' -- {quoted_stage})\" = '0|700|directory'; "
        f"set -C; : > {quoted}; set +C; "
        f"test \"$(stat -c '%u|%a|%F|%s' -- {quoted})\" = '0|600|regular empty file|0'"
    )


def validate_remote_paths(remote_archive: str, remote_helper: str) -> str:
    archive_match = REMOTE_ARCHIVE_RE.fullmatch(remote_archive)
    helper_match = REMOTE_HELPER_RE.fullmatch(remote_helper)
    if archive_match is None or helper_match is None or archive_match.group(1) != helper_match.group(1):
        raise RuntimeError("remote STT paths are outside one reviewed root-only stage")
    stage = f"/tmp/.yiyunying-stt-runtime-{archive_match.group(1)}"
    if REMOTE_STAGE_RE.fullmatch(stage) is None:
        raise RuntimeError("remote STT stage directory is outside the reviewed pattern")
    return stage


def upload_payload(client: Any, payload: Payload, remote_path: str) -> None:
    current = os.lstat(payload.path)
    if payload.fingerprint != (current.st_dev, current.st_ino, current.st_size, current.st_mtime_ns):
        raise RuntimeError("derived STT payload changed before upload")
    sftp = client.open_sftp()
    try:
        sftp.get_channel().settimeout(SFTP_TIMEOUT)
        before = sftp.lstat(remote_path)
        if before.st_size != 0 or stat.S_IMODE(before.st_mode) != 0o600:
            raise RuntimeError("remote STT stage is not the create-once root-only file")
        with payload.path.open("rb") as source, sftp.file(remote_path, "r+") as destination:
            while True:
                chunk = source.read(1024 * 1024)
                if not chunk:
                    break
                destination.write(chunk)
            destination.flush()
        sftp.chmod(remote_path, 0o600)
        after = sftp.lstat(remote_path)
        if after.st_size != payload.size or stat.S_IMODE(after.st_mode) != 0o600:
            raise RuntimeError("remote STT payload stage size/mode readback failed")
    finally:
        sftp.close()
    current = os.lstat(payload.path)
    if payload.fingerprint != (current.st_dev, current.st_ino, current.st_size, current.st_mtime_ns):
        raise RuntimeError("derived STT payload changed during upload")


def installer_command(
    system_python: str,
    remote_archive: str,
    remote_helper: str,
    payload: Payload,
    source: SourceInspection,
    token: str,
) -> str:
    remote_stage = validate_remote_paths(remote_archive, remote_helper)
    args = [
        system_python,
        "-I",
        "-B",
        remote_helper,
        "--backend-root",
        EXPECTED_REMOTE_ROOT,
        "--archive",
        remote_archive,
        "--archive-size",
        str(payload.size),
        "--archive-sha256",
        payload.sha256,
        "--source-manifest-sha256",
        source.source_manifest_sha256,
        "--release-id",
        source.release_id,
        "--token",
        token,
        "--runtime-user",
        RUNTIME_USER,
        "--runtime-group",
        RUNTIME_GROUP,
    ]
    invoke = " ".join(shlex.quote(item) for item in args)
    return f'''set -eu
export LC_ALL=C LANG=C
archive={shlex.quote(remote_archive)}
helper={shlex.quote(remote_helper)}
helper_partial="$helper.partial"
stage={shlex.quote(remote_stage)}
test "$(id -u)" -eq 0
test "$(stat -c '%u|%a|%F' -- "$stage")" = '0|700|directory'
test "$(stat -c '%u|%a|%F|%s' -- "$archive")" = {shlex.quote(f'0|600|regular file|{payload.size}')}
test "$(sha256sum -- "$archive" | awk '{{print $1}}')" = {shlex.quote(payload.sha256)}
test ! -e "$helper"
test ! -e "$helper_partial"
umask 077
trap 'rm -f -- "$helper_partial"' EXIT
tar -xOf "$archive" installer/remote-install.py > "$helper_partial"
test "$(stat -c '%u|%a|%F' -- "$helper_partial")" = '0|600|regular file'
test "$(sha256sum -- "$helper_partial" | awk '{{print $1}}')" = {shlex.quote(payload.helper_sha256)}
mv -- "$helper_partial" "$helper"
trap - EXIT
{invoke}
'''


def cleanup_command(remote_archive: str, remote_helper: str, payload: Payload, helper_sha256: str) -> str:
    remote_stage = validate_remote_paths(remote_archive, remote_helper)
    return f'''set -eu
archive={shlex.quote(remote_archive)}
helper={shlex.quote(remote_helper)}
helper_partial="$helper.partial"
stage={shlex.quote(remote_stage)}
test "$(stat -c '%u|%a|%F' -- "$stage")" = '0|700|directory'
if [ -e "$archive" ]; then
  test "$(stat -c '%u|%a|%F|%s' -- "$archive")" = {shlex.quote(f'0|600|regular file|{payload.size}')}
  test "$(sha256sum -- "$archive" | awk '{{print $1}}')" = {shlex.quote(payload.sha256)}
  rm -f -- "$archive"
fi
if [ -e "$helper" ]; then
  test "$(stat -c '%u|%a|%F' -- "$helper")" = '0|600|regular file'
  test "$(sha256sum -- "$helper" | awk '{{print $1}}')" = {shlex.quote(helper_sha256)}
  rm -f -- "$helper"
fi
if [ -e "$helper_partial" ]; then
  test ! -L "$helper_partial"
  test "$(stat -c '%u|%a|%F' -- "$helper_partial")" = '0|600|regular file'
  rm -f -- "$helper_partial"
fi
rmdir -- "$stage"
'''


def parse_receipt(output: str, source: SourceInspection, payload: Payload) -> dict[str, Any]:
    prefix = "STT_RUNTIME_RECEIPT="
    matches = [line[len(prefix):] for line in output.splitlines() if line.startswith(prefix)]
    if len(matches) != 1:
        raise RecoveryRequired("RECOVERY_REQUIRED=stt-runtime-receipt-missing-or-ambiguous")
    try:
        receipt = json.loads(matches[0])
    except json.JSONDecodeError as exc:
        raise RecoveryRequired("RECOVERY_REQUIRED=stt-runtime-receipt-invalid-json") from exc
    if (
        receipt.get("status") != "committed"
        or receipt.get("release_id") != source.release_id
        or receipt.get("source_manifest_sha256") != source.source_manifest_sha256
        or receipt.get("payload_sha256") != payload.sha256
        or receipt.get("python") != "3.11.15"
        or receipt.get("faster_whisper") != "1.2.1"
    ):
        raise RecoveryRequired("RECOVERY_REQUIRED=stt-runtime-receipt-identity-mismatch")
    return receipt


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument("--host", required=True)
    result.add_argument("--port", type=int, default=22)
    result.add_argument("--user", default=EXPECTED_USER)
    result.add_argument("--known-hosts", required=True)
    result.add_argument("--bundle", required=True)
    result.add_argument("--bundle-sha256", required=True)
    result.add_argument("--execute", action="store_true")
    result.add_argument("--confirm", default="")
    result.add_argument("--maintenance-confirmed", default="")
    result.add_argument("--confirm-manifest-sha", default="")
    return result


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    if args.user != EXPECTED_USER or args.port < 1 or args.port > 65535:
        raise RuntimeError("production STT installer is pinned to root and a valid SSH port")
    if args.execute:
        if args.confirm != EXECUTE_CONFIRMATION or args.maintenance_confirmed != MAINTENANCE_CONFIRMATION:
            raise RuntimeError("execute requires both exact reviewed confirmation tokens")
        if not HASH_RE.fullmatch(args.confirm_manifest_sha):
            raise RuntimeError("execute requires the exact source tree manifest SHA-256")
    elif args.confirm or args.maintenance_confirmed or args.confirm_manifest_sha:
        raise RuntimeError("confirmation tokens are only valid with --execute")
    password = os.environ.get("YY_SSH_PASSWORD", "")
    if not password:
        raise RuntimeError("YY_SSH_PASSWORD must be supplied only by the DPAPI launcher environment")
    source = inspect_source_bundle(Path(args.bundle), args.bundle_sha256)
    if args.execute and args.confirm_manifest_sha != source.source_manifest_sha256:
        source.temporary.cleanup()
        raise RuntimeError("operator manifest confirmation does not match the verified source bundle")
    print(
        "STT_SOURCE_BUNDLE_PIN="
        + json.dumps(
            {
                "bundle_sha256": source.bundle_sha256,
                "source_manifest_sha256": source.source_manifest_sha256,
                "release_id": source.release_id,
            },
            sort_keys=True,
            separators=(",", ":"),
        )
    )
    client = None
    payload: Payload | None = None
    remote_archive: str | None = None
    remote_helper: str | None = None
    stage_created = False
    primary: BaseException | None = None
    receipt: dict[str, Any] | None = None
    try:
        # Derive and hash the exact production payload in dry-run as well.  The
        # temporary tar stays on the reviewed bundle volume and is removed in
        # finally; only execute mode may upload it.
        payload = build_payload(source)
        print(
            "STT_PRODUCTION_PAYLOAD_PIN="
            + json.dumps(
                {"size": payload.size, "sha256": payload.sha256},
                sort_keys=True,
                separators=(",", ":"),
            )
        )
        client = connect(args, password)
        preflight = run_remote(client, preflight_command(), "STT runtime read-only preflight", password)
        system_python = parse_system_python(preflight)
        if not args.execute:
            print(preflight.strip())
            print("[dry-run] local bundle/payload and remote prerequisites passed; no remote upload, extraction, install, permission change, current switch, or cleanup occurred; the local derived payload is removed on exit")
            return 0
        token = secrets.token_hex(16)
        remote_archive = f"/tmp/.yiyunying-stt-runtime-{token}/payload.tar"
        remote_helper = f"/tmp/.yiyunying-stt-runtime-{token}/remote-install.py"
        # Mark cleanup eligible before the atomic mkdir command: if a later
        # statement in that command fails, the root-only directory may exist.
        stage_created = True
        run_remote(client, create_stage_command(remote_archive), "STT payload stage creation", password, timeout=60)
        upload_payload(client, payload, remote_archive)
        try:
            output = run_remote(
                client,
                installer_command(system_python, remote_archive, remote_helper, payload, source, token),
                "offline STT runtime install",
                password,
                require_empty_stderr=True,
            )
            receipt = parse_receipt(output, source, payload)
        except BaseException as exc:
            raise RecoveryRequired("RECOVERY_REQUIRED=stt-runtime-remote-result-indeterminate; " + sanitize(exc, (password,))) from exc
        print("STT_RUNTIME_RECEIPT=" + json.dumps(receipt, sort_keys=True, separators=(",", ":")))
        return 0
    except BaseException as exc:
        primary = exc
        raise
    finally:
        cleanup_failure: BaseException | None = None
        close_failure: BaseException | None = None
        if client is not None and payload is not None and remote_archive is not None and remote_helper is not None and stage_created:
            try:
                run_remote(
                    client,
                    cleanup_command(remote_archive, remote_helper, payload, payload.helper_sha256),
                    "STT payload stage cleanup",
                    password,
                    timeout=120,
                    require_empty_stderr=True,
                )
            except BaseException as exc:
                cleanup_failure = exc
        if client is not None:
            try:
                client.close()
            except BaseException as exc:
                close_failure = exc
        if payload is not None:
            payload.temporary.cleanup()
        source.temporary.cleanup()
        if primary is None and cleanup_failure is not None:
            raise RecoveryRequired("RECOVERY_REQUIRED=stt-runtime-remote-stage-cleanup-unproven") from cleanup_failure
        if primary is None and close_failure is not None:
            raise RecoveryRequired("RECOVERY_REQUIRED=stt-runtime-ssh-close-unproven") from close_failure


def cli(argv: list[str] | None = None) -> int:
    actual = list(sys.argv[1:] if argv is None else argv)
    try:
        return main(actual)
    except BaseException as exc:
        password = os.environ.get("YY_SSH_PASSWORD", "")
        detail = sanitize(exc, (password,))
        if "--execute" in actual and "RECOVERY_REQUIRED=" not in detail:
            detail = "RECOVERY_REQUIRED=stt-runtime-execute-unproven; " + detail
        print("production STT runtime installation failed: " + detail, file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(cli())
