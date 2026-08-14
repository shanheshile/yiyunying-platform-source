#!/usr/bin/env python3
"""Install the pinned production FFmpeg runtime over SSH.

The default mode is a read-only preflight.  The execute path accepts exactly
one locally supplied, content-addressed OCI layer and never downloads anything
from the production host.
"""

from __future__ import annotations

import argparse
from dataclasses import dataclass
import gzip
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import posixpath
import re
import secrets
import shlex
import socket
import stat
import sys
import tarfile
import time
from typing import Any, Callable


INDEX_DIGEST = "sha256:33f770f812cbfc3de96c547157fc9faf8bd95a36481753439ffa761045167585"
AMD64_MANIFEST_DIGEST = "sha256:3bfa407c614a29a4535f1e3220fd9f6bc9cd7c25483036962e3c8ff711b56e01"
CONFIG_DIGEST = "sha256:34e6fa0a15eb08744e6a2926eead1d91a6bd7e3764278638f6b62d3fe0b386e2"
LAYER_SHA256 = "9ec618fc9dc33fd2997bb09df3244055b00d519361a6d7083462638b414a939e"
DIFF_ID_SHA256 = "c501421dac74c35e228240b1da269451b943dec115e0ad2aaafefce6f44c9325"
LAYER_SIZE = 123_477_739
UNCOMPRESSED_TAR_SIZE = 309_507_072
MEMBER_COUNT = 544
MEMBER_PAYLOAD_SIZE = 309_102_517
VERSION = "8.1.2"
VERSION_DIRECTORY = "8.1.2-3bfa407c614a"
RUNTIME_ROOT = "/opt/yiyunying/media-runtime"
RUNTIME_USER = "www"
RUNTIME_GROUP = "www"
MINIMUM_FREE_BYTES = 1 << 30
REMOTE_COMMAND_TIMEOUT = 15 * 60
SFTP_TIMEOUT = 5 * 60
MAX_REMOTE_OUTPUT = 64 * 1024
EXECUTE_CONFIRMATION = "install-production-media-runtime-8.1.2"
MAINTENANCE_CONFIRMATION = "runtime-install-and-rollback-reviewed"
REMOTE_STAGE_RE = re.compile(
    r"^/tmp/\.yiyunying-media-runtime-8\.1\.2-([0-9a-f]{32})\.tar\.gz$"
)
REMOTE_PYTHON_CANDIDATES = (
    "/usr/bin/python3",
    "/usr/local/bin/python3",
)
OCI_PLATFORM_OS = "linux"
OCI_PLATFORM_ARCH = "amd64"


@dataclass(frozen=True)
class MemberContract:
    size: int
    sha256: str
    mode: int


@dataclass(frozen=True)
class LayerContract:
    compressed_size: int
    compressed_sha256: str
    diff_id_sha256: str
    uncompressed_size: int
    member_count: int
    payload_size: int
    members: dict[str, MemberContract]


PINNED_MEMBERS = {
    "ffmpeg": MemberContract(
        140_059_552,
        "7b3fb9508c20166ab3ba236a9585c3e22e903880723c1a6448e69ae6e4cd88d2",
        0o755,
    ),
    "ffprobe": MemberContract(
        139_834_144,
        "fe39eb91eb04dd18dff3870a87b59e41be997476c2d373c46ff7e12bb284743c",
        0o755,
    ),
    "versions.json": MemberContract(
        1_608,
        "494357b48cdfb7710c804b66f3794d0b7e1b04cf05f6c3c2d4ab131f25684bf1",
        0o644,
    ),
}
PINNED_CONTRACT = LayerContract(
    LAYER_SIZE,
    LAYER_SHA256,
    DIFF_ID_SHA256,
    UNCOMPRESSED_TAR_SIZE,
    MEMBER_COUNT,
    MEMBER_PAYLOAD_SIZE,
    PINNED_MEMBERS,
)


def sha256_stream(handle: Any) -> tuple[int, str]:
    digest = hashlib.sha256()
    size = 0
    while True:
        chunk = handle.read(1024 * 1024)
        if not chunk:
            break
        size += len(chunk)
        digest.update(chunk)
    return size, digest.hexdigest()


def safe_virtual_link(member_name: str, link_name: str, all_names: set[str]) -> bool:
    """Validate a link inside an OCI virtual root; links are never extracted."""
    del all_names
    if not link_name or "\\" in link_name or "\x00" in link_name:
        return False
    target = PurePosixPath(link_name)
    if target.is_absolute():
        normalized = posixpath.normpath(link_name).lstrip("/")
    else:
        normalized = posixpath.normpath(
            posixpath.join(posixpath.dirname(member_name), link_name)
        )
    if normalized in ("", ".") or normalized == ".." or normalized.startswith("../"):
        return False
    return normalized not in ("", ".")


def validate_tar_members(
    archive: tarfile.TarFile, contract: LayerContract
) -> dict[str, tarfile.TarInfo]:
    members = archive.getmembers()
    if len(members) != contract.member_count:
        raise RuntimeError("OCI layer member count does not match the pinned contract")
    names = [item.name for item in members]
    if len(set(names)) != len(names):
        raise RuntimeError("OCI layer contains duplicate member paths")
    all_names = set(names)
    payload_size = 0
    selected: dict[str, tarfile.TarInfo] = {}
    reserved = set(contract.members)
    for item in members:
        name = item.name
        pure = PurePosixPath(name)
        if (
            not name
            or len(name) > 4096
            or pure.is_absolute()
            or ".." in pure.parts
            or "\\" in name
            or "\x00" in name
            or posixpath.normpath(name) != name
        ):
            raise RuntimeError("OCI layer contains an unsafe member path")
        if PurePosixPath(name).name in reserved and name not in reserved:
            raise RuntimeError("OCI layer contains an extra reserved basename")
        if item.isreg():
            if item.mode & 0o7000:
                raise RuntimeError("OCI layer contains a privileged file mode")
            payload_size += item.size
        elif item.isdir():
            pass
        elif item.issym():
            if not safe_virtual_link(name, item.linkname, all_names):
                raise RuntimeError("OCI layer contains an escaping or broken link")
        elif item.islnk():
            raise RuntimeError("OCI layer contains a hard-link entry")
        else:
            raise RuntimeError("OCI layer contains a device, fifo, or unsupported entry")
        if name in reserved:
            if not item.isreg():
                raise RuntimeError("A pinned runtime member is not a regular file")
            selected[name] = item
    if payload_size != contract.payload_size:
        raise RuntimeError("OCI layer payload size does not match the pinned contract")
    if set(selected) != reserved:
        raise RuntimeError("OCI layer is missing a pinned runtime member")
    return selected


def inspect_layer(path: Path, contract: LayerContract = PINNED_CONTRACT) -> dict[str, Any]:
    expanded = path.expanduser()
    metadata = os.lstat(expanded)
    if expanded.is_symlink() or not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
        raise RuntimeError("The local OCI layer must be one unique regular non-link file")
    reparse = getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0)
    if reparse and getattr(metadata, "st_file_attributes", 0) & reparse:
        raise RuntimeError("The local OCI layer must not be a Windows reparse point")
    if metadata.st_size != contract.compressed_size:
        raise RuntimeError("The local OCI layer size does not match the pinned contract")
    with expanded.open("rb") as handle:
        compressed_size, compressed_hash = sha256_stream(handle)
    if compressed_size != contract.compressed_size or not secrets.compare_digest(
        compressed_hash, contract.compressed_sha256
    ):
        raise RuntimeError("The local OCI layer compressed hash is invalid")
    with gzip.open(expanded, "rb") as handle:
        uncompressed_size, diff_id = sha256_stream(handle)
    if uncompressed_size != contract.uncompressed_size or not secrets.compare_digest(
        diff_id, contract.diff_id_sha256
    ):
        raise RuntimeError("The local OCI layer diff_id is invalid")
    with tarfile.open(expanded, "r:gz") as archive:
        selected = validate_tar_members(archive, contract)
        for name, expected in contract.members.items():
            member = selected[name]
            if member.size != expected.size or stat.S_IMODE(member.mode) != expected.mode:
                raise RuntimeError("A runtime member size or mode is invalid")
            source = archive.extractfile(member)
            if source is None:
                raise RuntimeError("A runtime member cannot be read")
            size, actual_hash = sha256_stream(source)
            if size != expected.size or not secrets.compare_digest(actual_hash, expected.sha256):
                raise RuntimeError("A runtime member hash is invalid")
        versions_source = archive.extractfile(selected["versions.json"])
        if versions_source is None:
            raise RuntimeError("versions.json cannot be read")
        try:
            versions = json.loads(versions_source.read().decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError) as exc:
            raise RuntimeError("versions.json is not valid UTF-8 JSON") from exc
        if not isinstance(versions, dict) or versions.get("ffmpeg") != VERSION:
            raise RuntimeError("versions.json does not declare pinned FFmpeg 8.1.2")
    after = os.lstat(expanded)
    fingerprint = (metadata.st_dev, metadata.st_ino, metadata.st_size, metadata.st_mtime_ns)
    if fingerprint != (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns):
        raise RuntimeError("The local OCI layer changed during validation")
    return {
        "path": str(expanded.resolve(strict=True)),
        "size": compressed_size,
        "sha256": compressed_hash,
        "fingerprint": fingerprint,
        "oci_index": INDEX_DIGEST,
        "oci_manifest": AMD64_MANIFEST_DIGEST,
        "oci_config": CONFIG_DIGEST,
        "oci_platform": f"{OCI_PLATFORM_OS}/{OCI_PLATFORM_ARCH}",
    }


def oci_pinset(local: dict[str, Any]) -> dict[str, Any]:
    """Describe the frozen OCI chain without overstating local verification.

    The local input is only the compressed layer.  Its digest, diff ID, and
    member hashes are reverified here; index/manifest/config descriptors are
    immutable provenance pins established by the separate registry audit.
    """
    if local.get("sha256") != LAYER_SHA256:
        raise RuntimeError("local layer is outside the frozen OCI pinset")
    return {
        "image": "mwader/static-ffmpeg:8.1.2",
        "platform": f"{OCI_PLATFORM_OS}/{OCI_PLATFORM_ARCH}",
        "index": INDEX_DIGEST,
        "manifest": AMD64_MANIFEST_DIGEST,
        "config": CONFIG_DIGEST,
        "layer": "sha256:" + LAYER_SHA256,
        "diff_id": "sha256:" + DIFF_ID_SHA256,
        "members": {
            name: "sha256:" + member.sha256
            for name, member in sorted(PINNED_MEMBERS.items())
        },
        "local_verification": ["layer", "diff_id", "members"],
        "registry_chain": "offline-frozen-provenance",
    }


def sanitize_for_log(value: object, sensitive: tuple[str, ...] = ()) -> str:
    text = str(value)
    for secret in sensitive:
        if secret:
            text = text.replace(secret, "[REDACTED]")
    text = re.sub(r"(?i)Bearer\s+[A-Za-z0-9._~+/-]+", "Bearer [REDACTED]", text)
    text = re.sub(
        r"(?i)(password|passwd|token|secret)\s*[=:]\s*[^\s;]+",
        lambda match: f"{match.group(1)}=[REDACTED]",
        text,
    )
    return text[:MAX_REMOTE_OUTPUT]


def append_bounded(
    buffer: bytearray,
    chunk: bytes,
    channel: Any,
    label: str,
    other_buffered: int = 0,
) -> None:
    if other_buffered + len(buffer) + len(chunk) > MAX_REMOTE_OUTPUT:
        channel.close()
        raise RuntimeError(f"remote combined output exceeded the safe log limit at {label}")
    buffer.extend(chunk)


def transition_with_rollback(
    read_target: Callable[[], str | None],
    write_target: Callable[[str | None], None],
    verify: Callable[[], None],
    new_target: str,
) -> str | None:
    """Small, testable state machine also mirrored by the remote installer."""
    previous = read_target()
    try:
        # write_target may have completed its atomic replace before a following
        # directory fsync reports failure.  Keep it inside the rollback region.
        write_target(new_target)
        if read_target() != new_target:
            raise RuntimeError("atomic switch readback mismatch")
        verify()
    except Exception:
        write_target(previous)
        if read_target() != previous:
            raise RuntimeError("atomic switch rollback readback mismatch")
        raise
    return previous


def validate_known_hosts(path: Path) -> Path:
    resolved = path.expanduser().resolve(strict=True)
    metadata = os.lstat(path.expanduser())
    if path.expanduser().is_symlink() or not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
        raise RuntimeError("known_hosts must be one regular non-link file")
    reparse = getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0)
    if reparse and getattr(metadata, "st_file_attributes", 0) & reparse:
        raise RuntimeError("known_hosts must not be a Windows reparse point")
    return resolved


def connect(args: argparse.Namespace, password: str):
    try:
        import paramiko
    except ImportError as exc:
        raise RuntimeError("paramiko is required; install backend/tools/requirements-release.txt") from exc
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


def collect_channel(channel: Any, timeout: float, password: str) -> tuple[int, str, str]:
    deadline = time.monotonic() + timeout
    stdout = bytearray()
    stderr = bytearray()
    while not channel.exit_status_ready():
        while channel.recv_ready():
            append_bounded(stdout, channel.recv(8192), channel, "stdout", len(stderr))
        while channel.recv_stderr_ready():
            append_bounded(stderr, channel.recv_stderr(8192), channel, "stderr", len(stdout))
        if time.monotonic() >= deadline:
            channel.close()
            raise TimeoutError("remote command exceeded its reviewed timeout")
        time.sleep(0.02)
    while channel.recv_ready():
        append_bounded(stdout, channel.recv(8192), channel, "stdout", len(stderr))
    while channel.recv_stderr_ready():
        append_bounded(stderr, channel.recv_stderr(8192), channel, "stderr", len(stdout))
    status = channel.recv_exit_status()
    return (
        status,
        sanitize_for_log(stdout.decode("utf-8", "replace"), (password,)),
        sanitize_for_log(stderr.decode("utf-8", "replace"), (password,)),
    )


def run_remote(
    client: Any,
    command: str,
    label: str,
    password: str,
    allowed: set[int] = {0},
    timeout: int = REMOTE_COMMAND_TIMEOUT,
    emit_output: bool = True,
    require_empty_stderr: bool = False,
) -> tuple[int, str]:
    _stdin, stdout, _stderr = client.exec_command(command, get_pty=False, timeout=timeout)
    status, output, error = collect_channel(stdout.channel, timeout, password)
    if status not in allowed:
        detail = error.strip() or output.strip() or "no diagnostic output"
        raise RuntimeError(f"{label} failed ({status}): {detail}")
    if require_empty_stderr and error.strip():
        raise RuntimeError(f"{label} returned unexpected stderr: {error.strip()}")
    if emit_output and output.strip():
        print(output.strip())
    return status, output


def remote_python_probe_command() -> str:
    candidates = " ".join(shlex.quote(item) for item in REMOTE_PYTHON_CANDIDATES)
    return f"""set -eu
export LC_ALL=C LANG=C
test "$(id -u)" -eq 0
id -u {shlex.quote(RUNTIME_USER)} >/dev/null
getent group {shlex.quote(RUNTIME_GROUP)} >/dev/null
test "$(uname -s)" = Linux
test "$(uname -m)" = x86_64
command -v sha256sum >/dev/null
validate_python_chain() {{
  requested="$1"
  case "$requested" in /usr/bin/python3|/usr/local/bin/python3) ;; *) return 1;; esac
  root_state=$(stat -c '%u|%a|%F' -- /) || return 1
  root_uid=${{root_state%%|*}}; root_rest=${{root_state#*|}}; root_mode=${{root_rest%%|*}}; root_kind=${{root_rest#*|}}
  test "$root_uid" = 0 && test "$root_kind" = directory || return 1
  test $((0$root_mode & 022)) -eq 0 || return 1
  resolved=''; remaining=${{requested#/}}; hops=0
  while [ -n "$remaining" ]; do
    component=${{remaining%%/*}}
    if [ "$remaining" = "$component" ]; then remaining=''; else remaining=${{remaining#*/}}; fi
    case "$component" in ''|.) continue;; ..)
      case "$resolved" in '') return 1;; */*) resolved=${{resolved%/*}};; *) resolved='';; esac
      continue;;
      *[!A-Za-z0-9_.+-]*) return 1;;
    esac
    if [ -n "$resolved" ]; then candidate_path=/$resolved/$component; else candidate_path=/$component; fi
    state=$(stat -c '%u|%a|%F' -- "$candidate_path") || return 1
    uid=${{state%%|*}}; rest=${{state#*|}}; mode=${{rest%%|*}}; kind=${{rest#*|}}
    test "$uid" = 0 || return 1
    if [ "$kind" = 'symbolic link' ]; then
      hops=$((hops+1)); test "$hops" -le 16 || return 1
      target=$(readlink -- "$candidate_path") || return 1
      case "$target" in ''|*[!A-Za-z0-9_./+-]*) return 1;; esac
      if [ "${{target#/}}" != "$target" ]; then resolved=''; target=${{target#/}}; fi
      if [ -n "$remaining" ]; then remaining=$target/$remaining; else remaining=$target; fi
      continue
    fi
    if [ -n "$remaining" ]; then
      test "$kind" = directory || return 1
      test $((0$mode & 022)) -eq 0 || return 1
      if [ -n "$resolved" ]; then resolved=$resolved/$component; else resolved=$component; fi
      continue
    fi
    test "$kind" = 'regular file' || return 1
    test $((0$mode & 022)) -eq 0 || return 1
    test -x "$candidate_path" || return 1
    printf '%s\n' "$requested"
    return 0
  done
  return 1
}}
PY=''
for candidate in {candidates}; do
  reviewed=$(validate_python_chain "$candidate" 2>/dev/null || true)
  if [ -n "$reviewed" ] && env -i PATH=/usr/bin:/bin LC_ALL=C LANG=C "$reviewed" -I -S -B -c 'import sys; raise SystemExit(0 if sys.version_info >= (3,8) else 1)'; then PY="$reviewed"; break; fi
done
test -n "$PY"
validate_root_directory() {{
  boundary_state=$(stat -c '%u|%g|%a|%F' -- "$1") || return 1
  boundary_uid=${{boundary_state%%|*}}; boundary_rest=${{boundary_state#*|}}
  boundary_gid=${{boundary_rest%%|*}}; boundary_rest=${{boundary_rest#*|}}
  boundary_mode=${{boundary_rest%%|*}}; boundary_kind=${{boundary_rest#*|}}
  test "$boundary_uid" = 0 && test "$boundary_gid" = 0 && test "$boundary_kind" = directory || return 1
  test $((0$boundary_mode & 022)) -eq 0 || return 1
}}
validate_root_directory /
validate_root_directory /opt
for boundary in /opt/yiyunying {shlex.quote(RUNTIME_ROOT)}; do
  if [ -e "$boundary" ] || [ -L "$boundary" ]; then validate_root_directory "$boundary"; fi
done
ancestor={shlex.quote(RUNTIME_ROOT)}
while [ ! -e "$ancestor" ]; do ancestor=${{ancestor%/*}}; test -n "$ancestor"; done
test -d "$ancestor" && test ! -L "$ancestor"
if [ -e {shlex.quote(RUNTIME_ROOT)} ] || [ -L {shlex.quote(RUNTIME_ROOT)} ]; then
  test -d {shlex.quote(RUNTIME_ROOT)} && test ! -L {shlex.quote(RUNTIME_ROOT)}
  test "$(stat -c '%a|%U|%G' {shlex.quote(RUNTIME_ROOT)})" = '755|root|root'
  if [ -e {shlex.quote(RUNTIME_ROOT + '/current')} ] || [ -L {shlex.quote(RUNTIME_ROOT + '/current')} ]; then
    test -L {shlex.quote(RUNTIME_ROOT + '/current')}
    test "$(stat -c '%U|%G|%F' -- {shlex.quote(RUNTIME_ROOT + '/current')})" = 'root|root|symbolic link'
    target=$(readlink -- {shlex.quote(RUNTIME_ROOT + '/current')})
    case "$target" in ''|.|..|*/*|*[!A-Za-z0-9._-]*) exit 42;; esac
    validate_root_directory {shlex.quote(RUNTIME_ROOT)}/"$target"
  fi
fi
free=$(df -PB1 "$ancestor" | awk 'NR==2 {{print $4}}')
test "$free" -ge {MINIMUM_FREE_BYTES}
printf 'MEDIA_RUNTIME_PREFLIGHT=pass\nPYTHON=%s\nFREE_BYTES=%s\n' "$PY" "$free"
"""


REMOTE_INSTALLER_SOURCE = r'''
from __future__ import annotations
import gzip, grp, hashlib, json, os, pathlib, posixpath, pwd, re, secrets, shutil, stat, subprocess, sys, tarfile, tempfile

LAYER_SHA = "9ec618fc9dc33fd2997bb09df3244055b00d519361a6d7083462638b414a939e"
DIFF_SHA = "c501421dac74c35e228240b1da269451b943dec115e0ad2aaafefce6f44c9325"
LAYER_SIZE = 123477739
TAR_SIZE = 309507072
MEMBER_COUNT = 544
PAYLOAD_SIZE = 309102517
ROOT = pathlib.Path("/opt/yiyunying/media-runtime")
VERSION_DIR = "8.1.2-3bfa407c614a"
MEMBERS = {
 "ffmpeg": (140059552, "7b3fb9508c20166ab3ba236a9585c3e22e903880723c1a6448e69ae6e4cd88d2", 0o555),
 "ffprobe": (139834144, "fe39eb91eb04dd18dff3870a87b59e41be997476c2d373c46ff7e12bb284743c", 0o555),
 "versions.json": (1608, "494357b48cdfb7710c804b66f3794d0b7e1b04cf05f6c3c2d4ab131f25684bf1", 0o444),
}
TIMEOUT = 15

def digest_stream(handle):
    h=hashlib.sha256(); size=0
    while True:
        chunk=handle.read(1024*1024)
        if not chunk: break
        size += len(chunk); h.update(chunk)
    return size,h.hexdigest()

def safe_link(name,target,names):
    del names
    if not target or "\\" in target or "\0" in target: return False
    if target.startswith("/"): normalized=posixpath.normpath(target).lstrip("/")
    else: normalized=posixpath.normpath(posixpath.join(posixpath.dirname(name),target))
    return normalized not in ("",".","..") and not normalized.startswith("../")

def scan(archive):
    items=archive.getmembers(); names=[x.name for x in items]
    if len(items)!=MEMBER_COUNT or len(set(names))!=len(names): raise RuntimeError("tar member contract")
    name_set=set(names); payload=0; chosen={}
    for item in items:
        pure=pathlib.PurePosixPath(item.name)
        if not item.name or len(item.name)>4096 or pure.is_absolute() or ".." in pure.parts or "\\" in item.name or "\0" in item.name or posixpath.normpath(item.name)!=item.name: raise RuntimeError("tar path")
        if pure.name in MEMBERS and item.name not in MEMBERS: raise RuntimeError("reserved basename")
        if item.isreg():
            if item.mode & 0o7000: raise RuntimeError("privileged tar mode")
            payload += item.size
        elif item.isdir(): pass
        elif item.issym():
            if not safe_link(item.name,item.linkname,name_set): raise RuntimeError("tar link")
        elif item.islnk(): raise RuntimeError("tar hardlink")
        else: raise RuntimeError("tar special")
        if item.name in MEMBERS:
            if not item.isreg(): raise RuntimeError("runtime member type")
            chosen[item.name]=item
    if payload!=PAYLOAD_SIZE or set(chosen)!=set(MEMBERS): raise RuntimeError("tar payload")
    return chosen

def file_hash(path):
    with path.open("rb") as handle: return digest_stream(handle)

def no_metadata(path):
    meta=os.lstat(path)
    if stat.S_ISLNK(meta.st_mode) or meta.st_uid!=0 or meta.st_gid!=0: raise RuntimeError("runtime ownership or link")
    if meta.st_nlink!=1 and stat.S_ISREG(meta.st_mode): raise RuntimeError("runtime hardlink")
    if hasattr(os,"listxattr") and os.listxattr(path,follow_symlinks=False): raise RuntimeError("runtime xattr or capability")

def fsync_dir(path):
    descriptor=os.open(path,os.O_RDONLY|os.O_DIRECTORY)
    try: os.fsync(descriptor)
    finally: os.close(descriptor)

def audit_version(path):
    no_metadata(path)
    if not stat.S_ISDIR(os.lstat(path).st_mode) or stat.S_IMODE(os.lstat(path).st_mode)!=0o555: raise RuntimeError("runtime directory mode")
    if set(x.name for x in path.iterdir())!=set(MEMBERS): raise RuntimeError("runtime directory contents")
    for name,(size,digest,mode) in MEMBERS.items():
        member=path/name; no_metadata(member); meta=os.lstat(member)
        if not stat.S_ISREG(meta.st_mode) or stat.S_IMODE(meta.st_mode)!=mode or meta.st_size!=size: raise RuntimeError("runtime file state")
        actual_size,actual_hash=file_hash(member)
        if actual_size!=size or not secrets.compare_digest(actual_hash,digest): raise RuntimeError("runtime file hash")
        if name in ("ffmpeg","ffprobe"):
            with member.open("rb") as handle: header=handle.read(20)
            if len(header)!=20 or header[:7]!=b"\x7fELF\x02\x01\x01" or int.from_bytes(header[16:18],"little")!=3 or int.from_bytes(header[18:20],"little")!=62: raise RuntimeError("runtime ELF machine binding")

def ensure_root():
    for boundary in (pathlib.Path("/"),pathlib.Path("/opt")):
        meta=os.lstat(boundary)
        if stat.S_ISLNK(meta.st_mode) or not stat.S_ISDIR(meta.st_mode) or meta.st_uid!=0 or meta.st_gid!=0 or stat.S_IMODE(meta.st_mode)&0o022: raise RuntimeError("runtime ancestor boundary")
    current=pathlib.Path("/opt")
    for name in ("yiyunying","media-runtime"):
        current=current/name
        if current.exists() or current.is_symlink():
            meta=os.lstat(current)
            if stat.S_ISLNK(meta.st_mode) or not stat.S_ISDIR(meta.st_mode) or meta.st_uid!=0 or meta.st_gid!=0 or stat.S_IMODE(meta.st_mode)!=0o755: raise RuntimeError("runtime parent boundary")
        else:
            os.mkdir(current,0o755); os.chown(current,0,0); os.chmod(current,0o755)
            fsync_dir(current.parent)
        if hasattr(os,"listxattr") and os.listxattr(current,follow_symlinks=False): raise RuntimeError("runtime parent xattr or capability")
    return current

def install(archive_path):
    destination=ROOT/VERSION_DIR
    if destination.exists() or destination.is_symlink(): audit_version(destination); return destination
    stage=pathlib.Path(tempfile.mkdtemp(prefix=".install-8.1.2-",dir=ROOT)); os.chmod(stage,0o700)
    try:
        with tarfile.open(archive_path,"r:gz") as archive:
            selected=scan(archive)
            for name,(size,digest,final_mode) in MEMBERS.items():
                source=archive.extractfile(selected[name])
                if source is None: raise RuntimeError("runtime member read")
                target=stage/name; h=hashlib.sha256(); count=0
                with target.open("xb") as out:
                    while True:
                        chunk=source.read(1024*1024)
                        if not chunk: break
                        out.write(chunk); h.update(chunk); count += len(chunk)
                    out.flush(); os.fsync(out.fileno())
                if count!=size or not secrets.compare_digest(h.hexdigest(),digest): raise RuntimeError("runtime extraction hash")
                os.chown(target,0,0); os.chmod(target,final_mode)
        os.chown(stage,0,0); os.chmod(stage,0o555); audit_version(stage); fsync_dir(stage); os.replace(stage,destination); fsync_dir(ROOT); stage=None
        audit_version(destination); return destination
    finally:
        if stage is not None and stage.exists():
            os.chmod(stage,0o700); shutil.rmtree(stage)

def run_www(argv,allow_failure=False):
    user=pwd.getpwnam("www"); group=grp.getgrnam("www")
    def demote(): os.setgroups([]); os.setgid(group.gr_gid); os.setuid(user.pw_uid); os.umask(0o077)
    env={"PATH":"/usr/bin:/bin","HOME":"/tmp","LC_ALL":"C","LANG":"C"}
    try: result=subprocess.run(argv,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=TIMEOUT,check=False,env=env,preexec_fn=demote)
    except subprocess.TimeoutExpired as exc: raise RuntimeError("media runtime timeout") from exc
    if len(result.stdout)>262144 or len(result.stderr)>262144: raise RuntimeError("media runtime output cap")
    if not allow_failure and result.returncode!=0: raise RuntimeError("media runtime command failed")
    return result

def smoke(binary_root):
    ffmpeg=str(binary_root/"ffmpeg"); ffprobe=str(binary_root/"ffprobe")
    version=run_www([ffmpeg,"-version"]).stdout.decode("utf-8","replace")
    if not version.startswith("ffmpeg version 8.1.2"): raise RuntimeError("ffmpeg version")
    build=run_www([ffmpeg,"-buildconf"]).stdout.decode("utf-8","replace")
    for flag in ("--toolchain=hardened","--disable-shared","--enable-static","--enable-gpl","--enable-version3"):
        if flag not in build: raise RuntimeError("ffmpeg buildconf")
    license_text=run_www([ffmpeg,"-L"]).stdout.decode("utf-8","replace")
    if "GNU General Public License" not in license_text or "version 3" not in license_text: raise RuntimeError("ffmpeg license")
    encoders=run_www([ffmpeg,"-hide_banner","-encoders"]).stdout.decode("utf-8","replace")
    if not re.search(r"(?m)^\s*V.....\s+libx264\s",encoders) or not re.search(r"(?m)^\s*A.....\s+aac\s",encoders): raise RuntimeError("required encoders")
    blocked=run_www([ffprobe,"-v","error","-protocol_whitelist","file","-i","http://127.0.0.1:9/must-not-connect"],allow_failure=True)
    blocked_text=blocked.stderr.decode("utf-8","replace").lower()
    if blocked.returncode==0 or "not on whitelist" not in blocked_text: raise RuntimeError("protocol whitelist")
    probe_dir=pathlib.Path(tempfile.mkdtemp(prefix="yiyunying-media-smoke-",dir="/tmp")); os.chown(probe_dir,pwd.getpwnam("www").pw_uid,grp.getgrnam("www").gr_gid); os.chmod(probe_dir,0o700)
    output=probe_dir/"closed-loop.mp4"
    try:
        run_www([ffmpeg,"-nostdin","-hide_banner","-v","error","-f","lavfi","-i","testsrc2=size=16x16:rate=1","-f","lavfi","-i","anullsrc=r=8000:cl=mono","-t","1","-c:v","libx264","-pix_fmt","yuv420p","-c:a","aac","-b:a","32k","-y",str(output)])
        result=run_www([ffprobe,"-v","error","-protocol_whitelist","file","-show_entries","stream=codec_name,codec_type,width,height:format=duration","-of","json",str(output)])
        data=json.loads(result.stdout.decode("utf-8")); streams=data.get("streams",[])
        video=[x for x in streams if x.get("codec_type")=="video" and x.get("codec_name")=="h264" and x.get("width")==16 and x.get("height")==16]
        audio=[x for x in streams if x.get("codec_type")=="audio" and x.get("codec_name")=="aac"]
        duration=float(data.get("format",{}).get("duration",0))
        if len(video)!=1 or len(audio)!=1 or not 0.5<=duration<=2.0: raise RuntimeError("media closed loop")
    finally: shutil.rmtree(probe_dir,ignore_errors=True)

def read_current():
    current=ROOT/"current"
    if not current.exists() and not current.is_symlink(): return None
    current_meta=os.lstat(current)
    if not stat.S_ISLNK(current_meta.st_mode) or current_meta.st_uid!=0 or current_meta.st_gid!=0: raise RuntimeError("current is not a root-owned symlink")
    if hasattr(os,"listxattr") and os.listxattr(current,follow_symlinks=False): raise RuntimeError("current symlink xattr")
    target=os.readlink(current)
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,127}",target): raise RuntimeError("current target boundary")
    target_path=ROOT/target; target_meta=os.lstat(target_path)
    if stat.S_ISLNK(target_meta.st_mode) or not stat.S_ISDIR(target_meta.st_mode) or target_meta.st_uid!=0 or target_meta.st_gid!=0 or stat.S_IMODE(target_meta.st_mode)&0o022: raise RuntimeError("current target directory boundary")
    if hasattr(os,"listxattr") and os.listxattr(target_path,follow_symlinks=False): raise RuntimeError("current target xattr")
    resolved=target_path.resolve(strict=True)
    if resolved.parent!=ROOT.resolve(strict=True) or not resolved.is_dir(): raise RuntimeError("current target escape")
    return target

def write_current(target):
    current=ROOT/"current"
    if target is None:
        if current.is_symlink(): current.unlink(); fsync_dir(ROOT)
        elif current.exists(): raise RuntimeError("current rollback boundary")
        return
    token=secrets.token_hex(8); temporary=ROOT/(".current-"+token)
    try:
        os.symlink(target,temporary); os.replace(temporary,current); fsync_dir(ROOT)
    finally:
        if temporary.is_symlink(): temporary.unlink()

def save_previous(target):
    receipt=ROOT/(".previous-target-"+VERSION_DIR)
    if receipt.exists() or receipt.is_symlink():
        meta=os.lstat(receipt)
        if stat.S_ISLNK(meta.st_mode) or not stat.S_ISREG(meta.st_mode) or meta.st_uid!=0 or meta.st_gid!=0 or stat.S_IMODE(meta.st_mode)!=0o400 or meta.st_nlink!=1: raise RuntimeError("previous-target receipt boundary")
        raise RuntimeError("RECOVERY_REQUIRED: create-once previous-target receipt already exists")
    descriptor=None
    try:
        descriptor=os.open(receipt,os.O_WRONLY|os.O_CREAT|os.O_EXCL|getattr(os,"O_NOFOLLOW",0),0o400)
        payload=((target or "none")+"\n").encode("ascii")
        if os.write(descriptor,payload)!=len(payload): raise RuntimeError("previous-target receipt short write")
        os.fsync(descriptor); os.close(descriptor); descriptor=None
        os.chown(receipt,0,0); os.chmod(receipt,0o400)
        if hasattr(os,"listxattr") and os.listxattr(receipt,follow_symlinks=False): raise RuntimeError("previous-target receipt xattr")
        descriptor=os.open(receipt,os.O_RDONLY|getattr(os,"O_NOFOLLOW",0))
        os.fsync(descriptor); os.close(descriptor); descriptor=None
        fsync_dir(ROOT)
    except Exception as exc:
        if descriptor is not None:
            try: os.close(descriptor)
            except OSError: pass
        raise RuntimeError("RECOVERY_REQUIRED: previous-target receipt is uncertain") from exc

def activate(destination):
    previous=read_current(); save_previous(previous)
    try:
        write_current(destination.name)
        if read_current()!=destination.name: raise RuntimeError("current readback")
        audit_version((ROOT/"current").resolve(strict=True)); run_www([str(ROOT/"current"/"ffmpeg"),"-version"])
    except Exception as primary:
        try:
            write_current(previous)
            if read_current()!=previous: raise RuntimeError("current rollback readback")
        except Exception as rollback:
            raise RuntimeError("RECOVERY_REQUIRED: current target rollback is uncertain") from rollback
        raise
    return previous

def activate_if_needed(destination):
    current=read_current()
    if current==destination.name:
        audit_version(destination); smoke(destination)
        if read_current()!=destination.name: raise RuntimeError("current repeated-install readback")
        return current,True
    smoke(destination)
    return activate(destination),False

def main():
    archive=pathlib.Path(sys.argv[1]); meta=os.lstat(archive)
    machine=os.uname()
    if machine.sysname!="Linux" or machine.machine!="x86_64": raise RuntimeError("runtime platform binding")
    if os.geteuid()!=0 or stat.S_ISLNK(meta.st_mode) or not stat.S_ISREG(meta.st_mode) or meta.st_uid!=0 or meta.st_gid!=0 or meta.st_nlink!=1 or stat.S_IMODE(meta.st_mode)!=0o600 or meta.st_size!=LAYER_SIZE: raise RuntimeError("remote stage boundary")
    with archive.open("rb") as handle: size,digest=digest_stream(handle)
    if size!=LAYER_SIZE or not secrets.compare_digest(digest,LAYER_SHA): raise RuntimeError("compressed hash")
    with gzip.open(archive,"rb") as handle: size,digest=digest_stream(handle)
    if size!=TAR_SIZE or not secrets.compare_digest(digest,DIFF_SHA): raise RuntimeError("diff id")
    with tarfile.open(archive,"r:gz") as tar: scan(tar)
    ensure_root()
    free=shutil.disk_usage(ROOT).free
    if free < 1073741824: raise RuntimeError("disk budget")
    destination=install(archive); previous,repeated=activate_if_needed(destination)
    print(json.dumps({"MEDIA_RUNTIME_INSTALL":"pass","version":"8.1.2","previous":previous or "none","current":VERSION_DIR,"already_current":repeated,"platform":"linux/amd64","free_bytes":free},separators=(",",":")))

if __name__ == "__main__": main()
'''


def parse_install_receipt(output: str) -> dict[str, Any]:
    """Accept exactly one complete, duplicate-key-free remote success receipt."""

    lines = output.splitlines()
    if len(lines) != 1 or not lines[0] or lines[0] != lines[0].strip():
        raise RuntimeError("remote install did not return one unique JSON receipt")

    def unique_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            if key in result:
                raise ValueError("duplicate JSON key")
            result[key] = value
        return result

    try:
        receipt = json.loads(lines[0], object_pairs_hook=unique_object)
    except (json.JSONDecodeError, ValueError) as exc:
        raise RuntimeError("remote install receipt is not strict JSON") from exc
    expected_keys = {
        "MEDIA_RUNTIME_INSTALL",
        "version",
        "previous",
        "current",
        "already_current",
        "platform",
        "free_bytes",
    }
    if not isinstance(receipt, dict) or set(receipt) != expected_keys:
        raise RuntimeError("remote install receipt fields do not match the contract")
    if (
        receipt["MEDIA_RUNTIME_INSTALL"] != "pass"
        or receipt["version"] != VERSION
        or receipt["current"] != VERSION_DIRECTORY
        or receipt["platform"] != f"{OCI_PLATFORM_OS}/{OCI_PLATFORM_ARCH}"
        or not isinstance(receipt["already_current"], bool)
        or isinstance(receipt["free_bytes"], bool)
        or not isinstance(receipt["free_bytes"], int)
        or receipt["free_bytes"] < MINIMUM_FREE_BYTES
        or not isinstance(receipt["previous"], str)
        or re.fullmatch(r"(?:none|[A-Za-z0-9][A-Za-z0-9._-]{0,127})", receipt["previous"])
        is None
        or (
            receipt["already_current"]
            and receipt["previous"] != VERSION_DIRECTORY
        )
    ):
        raise RuntimeError("remote install receipt values do not prove the pinned result")
    return receipt


def remote_python_path(output: str) -> str:
    match = re.search(r"(?m)^PYTHON=(/[^\r\n]+)$", output)
    if match is None or match.group(1) not in REMOTE_PYTHON_CANDIDATES:
        raise RuntimeError("remote preflight did not return a reviewed Python path")
    return match.group(1)


def upload_layer(client: Any, local: dict[str, Any], remote_path: str) -> None:
    marker = stage_marker(remote_path)
    if REMOTE_STAGE_RE.fullmatch(remote_path) is None:
        raise RuntimeError("remote stage path is outside the reviewed namespace")
    sftp = client.open_sftp()
    try:
        sftp.get_channel().settimeout(SFTP_TIMEOUT)
        before = sftp.lstat(remote_path)
        if (
            before.st_size != len(marker)
            or stat.S_IMODE(before.st_mode) != 0o600
            or before.st_uid != 0
            or before.st_gid != 0
        ):
            raise RuntimeError("remote stage was not exclusively prepared as root:root 0600")
        with sftp.file(remote_path, "r") as prepared:
            if prepared.read(len(marker) + 1) != marker:
                raise RuntimeError("remote stage ownership marker readback failed")
        with open(local["path"], "rb") as source:
            destination = sftp.file(remote_path, "r+")
            try:
                destination.set_pipelined(True)
                destination.seek(0)
                destination.truncate(0)
                while True:
                    chunk = source.read(1024 * 1024)
                    if not chunk:
                        break
                    destination.write(chunk)
                destination.flush()
            finally:
                destination.close()
        sftp.chmod(remote_path, 0o600)
        remote_stat = sftp.lstat(remote_path)
        if (
            remote_stat.st_size != local["size"]
            or stat.S_IMODE(remote_stat.st_mode) != 0o600
            or remote_stat.st_uid != 0
            or remote_stat.st_gid != 0
        ):
            raise RuntimeError("remote stage size or mode readback failed")
    finally:
        sftp.close()
    current = os.lstat(local["path"])
    if local["fingerprint"] != (
        current.st_dev,
        current.st_ino,
        current.st_size,
        current.st_mtime_ns,
    ):
        raise RuntimeError("the local layer changed during upload")


def installer_command(python_path: str, remote_stage: str) -> str:
    if python_path not in REMOTE_PYTHON_CANDIDATES:
        raise RuntimeError("unreviewed remote Python path")
    if REMOTE_STAGE_RE.fullmatch(remote_stage) is None:
        raise RuntimeError("unreviewed remote stage path")
    import base64

    encoded = base64.b64encode(REMOTE_INSTALLER_SOURCE.encode("utf-8")).decode("ascii")
    bootstrap = "import base64;exec(compile(base64.b64decode(" + repr(encoded) + "),'<media-runtime-installer>','exec'))"
    return (
        "env -i PATH=/usr/bin:/bin LC_ALL=C LANG=C "
        + shlex.quote(python_path)
        + " -I -S -B -c "
        + shlex.quote(bootstrap)
        + " "
        + shlex.quote(remote_stage)
    )


def stage_marker(remote_stage: str) -> bytes:
    match = REMOTE_STAGE_RE.fullmatch(remote_stage)
    if match is None:
        raise RuntimeError("unreviewed remote stage marker path")
    return f"YY_MEDIA_STAGE_V1:{match.group(1)}\n".encode("ascii")


def create_stage_command(remote_stage: str) -> str:
    marker = stage_marker(remote_stage).decode("ascii")
    if REMOTE_STAGE_RE.fullmatch(remote_stage) is None:
        raise RuntimeError("unreviewed remote stage creation path")
    quoted = shlex.quote(remote_stage)
    return (
        "set -euC; test ! -e "
        + quoted
        + "; test ! -L "
        + quoted
        + "; (umask 077; printf %s "
        + shlex.quote(marker)
        + " > "
        + quoted
        + "); chown root:root -- "
        + quoted
        + "; chmod 0600 -- "
        + quoted
        + "; test \"$(stat -c '%a|%U|%G|%s' "
        + quoted
        + ")\" = '600|root|root|"
        + str(len(marker.encode("ascii")))
        + "'"
    )


def cleanup_command(remote_stage: str, ownership_confirmed: bool = False) -> str:
    if REMOTE_STAGE_RE.fullmatch(remote_stage) is None:
        raise RuntimeError("unreviewed remote stage cleanup path")
    quoted = shlex.quote(remote_stage)
    marker = stage_marker(remote_stage).decode("ascii").rstrip("\n")
    command = (
        "set -eu; if [ ! -e "
        + quoted
        + " ] && [ ! -L "
        + quoted
        + " ]; then exit 0; fi; test -f "
        + quoted
        + "; test ! -L "
        + quoted
        + "; test \"$(stat -c '%a|%U|%G' "
        + quoted
        + ")\" = '600|root|root'; "
    )
    if ownership_confirmed:
        command += "rm -f -- " + quoted
    else:
        command += (
            "size=$(stat -c '%s' "
            + quoted
            + "); owned=0; if [ \"$size\" -eq "
            + str(len(stage_marker(remote_stage)))
            + " ] && [ \"$(cat -- "
            + quoted
            + ")\" = "
            + shlex.quote(marker)
            + " ]; then owned=1; elif [ \"$size\" -eq "
            + str(LAYER_SIZE)
            + " ] && [ \"$(sha256sum -- "
            + quoted
            + " | awk '{print $1}')\" = "
            + shlex.quote(LAYER_SHA256)
            + " ]; then owned=1; fi; if [ \"$owned\" -ne 1 ]; then printf 'RECOVERY_REQUIRED=unowned-or-partial-media-stage\\n' >&2; exit 3; fi; rm -f -- "
            + quoted
        )
    return command


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument("--host", required=True)
    result.add_argument("--port", type=int, default=22)
    result.add_argument("--user", default="root")
    result.add_argument("--known-hosts", required=True)
    result.add_argument("--layer", required=True)
    result.add_argument("--execute", action="store_true")
    result.add_argument("--confirm", default="")
    result.add_argument("--maintenance-confirmed", default="")
    return result


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    if args.user != "root":
        raise RuntimeError("the production media runtime installer is pinned to SSH user root")
    if args.execute and (
        args.confirm != EXECUTE_CONFIRMATION
        or args.maintenance_confirmed != MAINTENANCE_CONFIRMATION
    ):
        raise RuntimeError("execute requires both reviewed confirmation tokens")
    if not args.execute and (args.confirm or args.maintenance_confirmed):
        raise RuntimeError("confirmation tokens are only valid with --execute")
    password = os.environ.get("YY_SSH_PASSWORD", "")
    if not password:
        raise RuntimeError("YY_SSH_PASSWORD is required and is never accepted on the command line")
    local = inspect_layer(Path(args.layer))
    client = connect(args, password)
    remote_stage: str | None = None
    stage_intended = False
    stage_created = False
    try:
        _status, output = run_remote(
            client,
            remote_python_probe_command(),
            "media runtime preflight",
            password,
        )
        python_path = remote_python_path(output)
        print(
            "OCI_PROVENANCE_PIN="
            + json.dumps(oci_pinset(local), sort_keys=True, separators=(",", ":"))
        )
        if not args.execute:
            print(
                "[dry-run] pinned layer and remote prerequisites passed; "
                "no upload, install, symlink switch, or application configuration change occurred"
            )
            return 0
        remote_stage = f"/tmp/.yiyunying-media-runtime-8.1.2-{secrets.token_hex(16)}.tar.gz"
        stage_intended = True
        run_remote(
            client,
            create_stage_command(remote_stage),
            "media runtime stage creation",
            password,
            timeout=60,
        )
        stage_created = True
        upload_layer(client, local, remote_stage)
        install_command = installer_command(python_path, remote_stage)
        try:
            # From this point onward the remote process may have completed its
            # atomic switch even when SSH loses the exit status or stdout.
            _status, install_output = run_remote(
                client,
                install_command,
                "media runtime install",
                password,
                emit_output=False,
                require_empty_stderr=True,
            )
            receipt = parse_install_receipt(install_output)
        except BaseException as exc:
            raise RuntimeError(
                "RECOVERY_REQUIRED: remote install result uncertain: "
                + sanitize_for_log(exc, (password,))
            ) from exc
        print(
            "MEDIA_RUNTIME_RECEIPT="
            + json.dumps(receipt, sort_keys=True, separators=(",", ":"))
        )
        return 0
    finally:
        primary_failure = sys.exc_info()[1]
        cleanup_failure: BaseException | None = None
        close_failure: BaseException | None = None
        if remote_stage is not None and stage_intended:
            try:
                run_remote(
                    client,
                    cleanup_command(remote_stage, ownership_confirmed=stage_created),
                    "media runtime stage cleanup",
                    password,
                    allowed={0},
                    timeout=60,
                )
            except BaseException as exc:
                try:
                    print(
                        "RECOVERY_REQUIRED=media-runtime-stage-cleanup:" + remote_stage + "; "
                        + sanitize_for_log(exc, (password,)),
                        file=sys.stderr,
                    )
                except BaseException:
                    pass
                cleanup_failure = exc
        try:
            client.close()
        except BaseException as exc:
            close_failure = exc
            try:
                print(
                    "RECOVERY_REQUIRED=media-runtime-ssh-close; "
                    + sanitize_for_log(exc, (password,)),
                    file=sys.stderr,
                )
            except BaseException:
                pass
        if cleanup_failure is not None and primary_failure is None:
            raise RuntimeError("RECOVERY_REQUIRED: media runtime stage cleanup failed") from cleanup_failure
        if close_failure is not None and primary_failure is None:
            raise RuntimeError("RECOVERY_REQUIRED: media runtime SSH close failed") from close_failure


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        password = os.environ.get("YY_SSH_PASSWORD", "")
        print(
            "production media runtime installation failed: "
            + sanitize_for_log(exc, (password,)),
            file=sys.stderr,
        )
        raise SystemExit(1)
