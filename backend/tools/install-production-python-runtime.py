#!/usr/bin/env python3
"""Install the pinned production Python runtime over SSH.

The default mode is a production read-only preflight.  Execution accepts one
locally supplied, content-addressed python-build-standalone archive, validates
the complete source contract, derives a deterministic ``python/install``
payload locally, and uploads only that payload.  The production host never
contacts a package repository and never executes an application, panel, STT,
PATH-discovered, or otherwise caller-selected Python interpreter.
"""

from __future__ import annotations

import argparse
from contextlib import contextmanager
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
import stat
import struct
import sys
import tarfile
import tempfile
import time
from typing import Any, BinaryIO, Iterator


ARTIFACT_NAME = (
    "cpython-3.12.13+20260718-x86_64-unknown-linux-musl-"
    "noopt+static-full.tar.zst"
)
ARTIFACT_SIZE = 35_579_339
ARTIFACT_SHA256 = "4f5ba66719827d2c97e6562987e8f1c79b2f2e2d661548b6fc2e02d04828a798"
UNCOMPRESSED_TAR_SIZE = 257_556_480
UNCOMPRESSED_TAR_SHA256 = "a38850ff4a7bd20ad0d1b30326712c2e947b5b02842505ee20c09edf60dfcc9f"
FULL_MEMBER_COUNT = 6_480
FULL_REGULAR_COUNT = 5_432
FULL_SYMLINK_COUNT = 1_048
FULL_PAYLOAD_SIZE = 252_742_364
PROJECTION_PREFIX = "python/install/"
PROJECTED_MEMBER_COUNT = 6_171
PROJECTED_REGULAR_COUNT = 5_123
PROJECTED_SYMLINK_COUNT = 1_048
PROJECTED_PAYLOAD_SIZE = 159_084_213
DERIVED_DIRECTORY_COUNT = 337
CONTENT_MANIFEST_SHA256 = "56ae61726d6f9e3620be87724d5b5fd8ec835b08761986b5fd46fa1d78c21c9c"
DERIVED_PAYLOAD_SIZE = 52_390_506
DERIVED_PAYLOAD_SHA256 = "8c36fc15be9e1acbe2869342551470d200a6241aba23ef2bf8b1f7d976e05a89"
PYTHON_BINARY_RELATIVE = "bin/python3.12"
PYTHON_ENTRY_RELATIVE = "bin/python3"
PYTHON_BINARY_SIZE = 47_591_248
PYTHON_BINARY_SHA256 = "8a92a92d7612969cf0865f1e08cf46f691b6ae44d5b72b7ed56052a224d7fa84"
VERSION = "3.12.13"
VERSION_DIRECTORY = "3.12.13-20260718"
RUNTIME_ROOT = "/opt/yiyunying/python-runtime"
TARGET_DIRECTORY = f"{RUNTIME_ROOT}/{VERSION_DIRECTORY}"
STABLE_PATH = "/usr/local/bin/python3"
LOCK_DIRECTORY = f"{RUNTIME_ROOT}/.install-lock"
PREVIOUS_TARGET_RECEIPT = f"{RUNTIME_ROOT}/.previous-target-{VERSION_DIRECTORY}"
MINIMUM_FREE_BYTES = 1 << 30
MAX_REMOTE_OUTPUT = 64 * 1024
REMOTE_COMMAND_TIMEOUT = 20 * 60
SFTP_TIMEOUT = 10 * 60
MAX_PATH_BYTES = 4096
MAX_MEMBER_SIZE = 256 * 1024 * 1024
EXECUTE_CONFIRMATION = "install-production-python-runtime-3.12.13"
MAINTENANCE_CONFIRMATION = "python-runtime-install-and-rollback-reviewed"
REMOTE_VALIDATE_CONFIRMATION = "validate-production-python-runtime-3.12.13"
REMOTE_STAGE_RE = re.compile(
    r"^/tmp/\.yiyunying-python-runtime-3\.12\.13-([0-9a-f]{32})\.tar\.gz$"
)
REMOTE_VALIDATE_WORK_RE = re.compile(
    r"^/tmp/\.yiyunying-python-runtime-validate-3\.12\.13-([0-9a-f]{32})$"
)
REMOTE_FAILURE_PHASES = frozenset(
    {
        "archive",
        "parents",
        "lock",
        "extract",
        "normalize",
        "tree-audit",
        "python-smoke",
        "target-move",
        "stable-switch",
        "post-smoke",
        "cleanup",
    }
)
REMOTE_VALIDATE_FAILURE_PHASES = frozenset(
    {"archive", "extract", "normalize", "tree-audit", "python-smoke", "cleanup"}
)
REMOTE_FAILURE_DIAGNOSTIC_RE = re.compile(
    r"PYTHON_RUNTIME_FAILURE_PHASE=([a-z-]+);EXIT_CODE=([1-9][0-9]{0,2})"
)
FULL_SOURCE_REGULAR_MODES = {0o660, 0o664, 0o775}
PROJECTED_SOURCE_REGULAR_MODES = {0o664, 0o775}
REQUIRED_STDLIB_IMPORTS = (
    "bz2",
    "ctypes",
    "hashlib",
    "json",
    "lzma",
    "multiprocessing",
    "pathlib",
    "sqlite3",
    "ssl",
    "tempfile",
    "urllib.parse",
    "zlib",
)


@dataclass(frozen=True)
class ProjectedMember:
    source_name: str
    relative_name: str
    kind: str
    size: int
    source_mode: int
    link_name: str
    content_sha256: str


@dataclass(frozen=True)
class ArtifactInspection:
    path: str
    size: int
    sha256: str
    fingerprint: tuple[int, int, int, int]
    members: tuple[ProjectedMember, ...]
    derived_directories: tuple[str, ...]
    python_binary_sha256: str


class RemotePhaseFailure(RuntimeError):
    """A strictly authenticated remote failure phase and process status."""

    def __init__(self, phase: str, status: int) -> None:
        super().__init__(f"remote Python runtime phase {phase} failed with exit {status}")
        self.phase = phase
        self.status = status


class RecoveryRequired(RuntimeError):
    """A non-secret recovery record that can be safely augmented after cleanup."""

    def __init__(
        self,
        reason: str,
        identifiers: dict[str, Any],
        cleanup_uncertainties: tuple[str, ...] = (),
    ) -> None:
        self.reason = reason
        self.identifiers = dict(identifiers)
        self.cleanup_uncertainties = tuple(cleanup_uncertainties)
        message = (
            "RECOVERY_REQUIRED: "
            + reason
            + "; recovery_identifiers="
            + json.dumps(self.identifiers, sort_keys=True, separators=(",", ":"))
        )
        if self.cleanup_uncertainties:
            message += "; cleanup_uncertainties=" + json.dumps(
                self.cleanup_uncertainties, separators=(",", ":")
            )
        super().__init__(message)


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


@contextmanager
def open_zstd_reader(path: Path) -> Iterator[BinaryIO]:
    """Open the reviewed artifact without invoking a PATH-selected program."""
    try:
        from compression import zstd as stdlib_zstd  # type: ignore[attr-defined]
    except ImportError:
        stdlib_zstd = None
    if stdlib_zstd is not None:
        with stdlib_zstd.open(path, "rb") as handle:
            yield handle
        return

    try:
        import zstandard  # type: ignore[import-not-found]
    except ImportError as exc:
        raise RuntimeError(
            "local Zstandard support is required: use Python 3.14 compression.zstd "
            "or the reviewed zstandard 0.25.0 offline module"
        ) from exc
    raw = path.open("rb")
    reader = zstandard.ZstdDecompressor().stream_reader(raw)
    try:
        yield reader
    finally:
        reader.close()
        raw.close()


def validate_local_regular_file(path: Path, label: str) -> tuple[Path, os.stat_result]:
    expanded = path.expanduser()
    metadata = os.lstat(expanded)
    reparse = getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0)
    if (
        expanded.is_symlink()
        or not stat.S_ISREG(metadata.st_mode)
        or metadata.st_nlink != 1
        or (reparse and getattr(metadata, "st_file_attributes", 0) & reparse)
    ):
        raise RuntimeError(f"{label} must be one unique regular non-link file")
    return expanded.resolve(strict=True), metadata


def canonical_member_name(item: tarfile.TarInfo) -> str:
    name = item.name.rstrip("/") if item.isdir() else item.name
    if (
        not name
        or len(name.encode("utf-8", "strict")) > MAX_PATH_BYTES
        or PurePosixPath(name).is_absolute()
        or ".." in PurePosixPath(name).parts
        or "\\" in name
        or "\x00" in name
        or any(ord(char) < 32 or ord(char) == 127 for char in name)
        or posixpath.normpath(name) != name
    ):
        raise RuntimeError("source archive contains an unsafe member path")
    if PurePosixPath(name).parts[0] != "python":
        raise RuntimeError("source archive contains a member outside the python top level")
    return name


def normalized_link_target(member_name: str, link_name: str) -> str:
    if (
        not link_name
        or len(link_name.encode("utf-8", "strict")) > MAX_PATH_BYTES
        or PurePosixPath(link_name).is_absolute()
        or "\\" in link_name
        or "\x00" in link_name
        or any(ord(char) < 32 or ord(char) == 127 for char in link_name)
    ):
        raise RuntimeError("source archive contains an unsafe symbolic link")
    target = posixpath.normpath(posixpath.join(posixpath.dirname(member_name), link_name))
    if target in ("", ".", "..") or target.startswith("../"):
        raise RuntimeError("source archive contains an escaping symbolic link")
    if PurePosixPath(target).parts[0] != "python":
        raise RuntimeError("source archive symbolic link leaves the python top level")
    return target


def inspect_static_elf(payload: bytes) -> dict[str, int]:
    if len(payload) < 64 or payload[:4] != b"\x7fELF":
        raise RuntimeError("pinned Python executable is not an ELF file")
    if payload[4] != 2 or payload[5] != 1 or payload[6] != 1:
        raise RuntimeError("pinned Python executable is not little-endian ELF64")
    e_type, e_machine = struct.unpack_from("<HH", payload, 16)
    if e_type != 2 or e_machine != 62:
        raise RuntimeError("pinned Python executable is not x86-64 ET_EXEC")
    e_phoff = struct.unpack_from("<Q", payload, 32)[0]
    e_phentsize, e_phnum = struct.unpack_from("<HH", payload, 54)
    if e_phnum < 1 or e_phentsize < 56 or e_phoff + e_phentsize * e_phnum > len(payload):
        raise RuntimeError("pinned Python executable has an invalid program header table")
    dynamic_segments = 0
    needed_entries = 0
    interpreter_segments = 0
    for index in range(e_phnum):
        offset = e_phoff + index * e_phentsize
        p_type = struct.unpack_from("<I", payload, offset)[0]
        p_offset = struct.unpack_from("<Q", payload, offset + 8)[0]
        p_filesz = struct.unpack_from("<Q", payload, offset + 32)[0]
        if p_offset + p_filesz > len(payload):
            raise RuntimeError("pinned Python executable contains an invalid segment")
        if p_type == 3:  # PT_INTERP
            interpreter_segments += 1
        if p_type == 2:  # PT_DYNAMIC
            dynamic_segments += 1
            if p_filesz % 16 != 0:
                raise RuntimeError("pinned Python executable has an invalid dynamic table")
            for dynamic_offset in range(p_offset, p_offset + p_filesz, 16):
                tag = struct.unpack_from("<q", payload, dynamic_offset)[0]
                if tag == 0:
                    break
                if tag == 1:  # DT_NEEDED
                    needed_entries += 1
    if interpreter_segments or dynamic_segments or needed_entries:
        raise RuntimeError("pinned Python executable is not fully static")
    return {
        "type": e_type,
        "machine": e_machine,
        "program_headers": e_phnum,
        "interpreter_segments": interpreter_segments,
        "dynamic_segments": dynamic_segments,
        "needed_entries": needed_entries,
    }


def manifest_digest(members: list[ProjectedMember]) -> str:
    lines: list[bytes] = []
    for member in members:
        relative = member.relative_name.encode("utf-8")
        if member.kind == "file":
            lines.append(
                b"F\0"
                + relative
                + b"\0"
                + str(member.size).encode("ascii")
                + b"\0"
                + member.content_sha256.encode("ascii")
                + b"\n"
            )
        elif member.kind == "symlink":
            lines.append(
                b"L\0" + relative + b"\0" + member.link_name.encode("utf-8") + b"\n"
            )
        else:
            raise RuntimeError("unexpected projected member kind")
    digest = hashlib.sha256()
    for line in sorted(lines):
        digest.update(line)
    return digest.hexdigest()


def validate_member_graph(
    names: set[str], symlinks: dict[str, str], members: list[ProjectedMember]
) -> None:
    for name in names:
        parts = PurePosixPath(name).parts
        for index in range(1, len(parts)):
            if "/".join(parts[:index]) in symlinks:
                raise RuntimeError("source archive stores content beneath a symbolic link")

    for name, link_name in symlinks.items():
        target = normalized_link_target(name, link_name)
        visited = {name}
        for _hop in range(32):
            if target not in names:
                raise RuntimeError("source archive contains a broken symbolic link")
            if target not in symlinks:
                break
            if target in visited:
                raise RuntimeError("source archive contains a symbolic-link cycle")
            visited.add(target)
            target = normalized_link_target(target, symlinks[target])
        else:
            raise RuntimeError("source archive symbolic-link chain is too deep")

    projected_names = {member.relative_name for member in members}
    projected_links = {
        member.relative_name: member.link_name
        for member in members
        if member.kind == "symlink"
    }
    for name, link_name in projected_links.items():
        target = posixpath.normpath(posixpath.join(posixpath.dirname(name), link_name))
        if target in ("", ".", "..") or target.startswith("../"):
            raise RuntimeError("python/install projection contains an escaping link")
        visited = {name}
        for _hop in range(32):
            if target not in projected_names:
                raise RuntimeError("python/install projection contains a broken link")
            if target not in projected_links:
                break
            if target in visited:
                raise RuntimeError("python/install projection contains a link cycle")
            visited.add(target)
            target = posixpath.normpath(
                posixpath.join(posixpath.dirname(target), projected_links[target])
            )
        else:
            raise RuntimeError("python/install projection link chain is too deep")


def inspect_artifact(path: Path) -> ArtifactInspection:
    resolved, metadata = validate_local_regular_file(path, "Python source artifact")
    if resolved.name != ARTIFACT_NAME or metadata.st_size != ARTIFACT_SIZE:
        raise RuntimeError("Python source artifact name or size is outside the pinned contract")
    with resolved.open("rb") as source:
        compressed_size, compressed_hash = sha256_stream(source)
    if compressed_size != ARTIFACT_SIZE or not secrets.compare_digest(
        compressed_hash, ARTIFACT_SHA256
    ):
        raise RuntimeError("Python source artifact compressed hash is invalid")

    with open_zstd_reader(resolved) as decompressed:
        tar_size, tar_hash = sha256_stream(decompressed)
    if tar_size != UNCOMPRESSED_TAR_SIZE or not secrets.compare_digest(
        tar_hash, UNCOMPRESSED_TAR_SHA256
    ):
        raise RuntimeError("Python source artifact decompressed tar contract is invalid")

    all_names: set[str] = set()
    all_symlinks: dict[str, str] = {}
    projected: list[ProjectedMember] = []
    full_regular = 0
    full_symlinks = 0
    full_payload = 0
    full_count = 0
    python_binary = b""
    with open_zstd_reader(resolved) as decompressed:
        with tarfile.open(fileobj=decompressed, mode="r|") as archive:
            for item in archive:
                full_count += 1
                name = canonical_member_name(item)
                if name in all_names:
                    raise RuntimeError("Python source archive contains duplicate member paths")
                all_names.add(name)
                if item.pax_headers and any(
                    "xattr" in key.lower()
                    or "acl" in key.lower()
                    or "capability" in key.lower()
                    for key in item.pax_headers
                ):
                    raise RuntimeError("Python source archive contains extended security metadata")
                if item.isreg():
                    full_regular += 1
                    full_payload += item.size
                    source_mode = stat.S_IMODE(item.mode)
                    if (
                        source_mode not in FULL_SOURCE_REGULAR_MODES
                        or item.size < 0
                        or item.size > MAX_MEMBER_SIZE
                        or item.sparse is not None
                    ):
                        raise RuntimeError("Python source archive regular-file contract is invalid")
                elif item.issym():
                    full_symlinks += 1
                    if stat.S_IMODE(item.mode) != 0o777:
                        raise RuntimeError("Python source archive symbolic-link mode is invalid")
                    normalized_link_target(name, item.linkname)
                    all_symlinks[name] = item.linkname
                else:
                    raise RuntimeError("Python source archive contains a non-file/non-link member")

                if not name.startswith(PROJECTION_PREFIX):
                    continue
                relative = name[len(PROJECTION_PREFIX) :]
                if not relative or posixpath.normpath(relative) != relative:
                    raise RuntimeError("Python install projection contains an invalid path")
                if item.isreg():
                    if stat.S_IMODE(item.mode) not in PROJECTED_SOURCE_REGULAR_MODES:
                        raise RuntimeError("Python install projection source mode is invalid")
                    member_source = archive.extractfile(item)
                    if member_source is None:
                        raise RuntimeError("Python source archive member cannot be read")
                    member_digest = hashlib.sha256()
                    member_size = 0
                    captured = bytearray() if relative == PYTHON_BINARY_RELATIVE else None
                    while True:
                        chunk = member_source.read(1024 * 1024)
                        if not chunk:
                            break
                        member_size += len(chunk)
                        member_digest.update(chunk)
                        if captured is not None:
                            if len(captured) + len(chunk) > MAX_MEMBER_SIZE:
                                raise RuntimeError("pinned Python executable exceeds the safe limit")
                            captured.extend(chunk)
                    member_source.close()
                    member_hash = member_digest.hexdigest()
                    if member_size != item.size:
                        raise RuntimeError("Python source archive member size changed during read")
                    projected.append(
                        ProjectedMember(
                            name,
                            relative,
                            "file",
                            item.size,
                            stat.S_IMODE(item.mode),
                            "",
                            member_hash,
                        )
                    )
                    if relative == PYTHON_BINARY_RELATIVE:
                        python_binary = bytes(captured or b"")
                else:
                    projected.append(
                        ProjectedMember(
                            name,
                            relative,
                            "symlink",
                            0,
                            stat.S_IMODE(item.mode),
                            item.linkname,
                            "",
                        )
                    )

    if (
        full_count != FULL_MEMBER_COUNT
        or full_regular != FULL_REGULAR_COUNT
        or full_symlinks != FULL_SYMLINK_COUNT
        or full_payload != FULL_PAYLOAD_SIZE
    ):
        raise RuntimeError("Python source archive member totals do not match the pinned contract")
    projected_regular = sum(member.kind == "file" for member in projected)
    projected_symlinks = sum(member.kind == "symlink" for member in projected)
    projected_payload = sum(member.size for member in projected if member.kind == "file")
    if (
        len(projected) != PROJECTED_MEMBER_COUNT
        or projected_regular != PROJECTED_REGULAR_COUNT
        or projected_symlinks != PROJECTED_SYMLINK_COUNT
        or projected_payload != PROJECTED_PAYLOAD_SIZE
    ):
        raise RuntimeError("Python install projection totals do not match the pinned contract")
    validate_member_graph(all_names, all_symlinks, projected)
    if manifest_digest(projected) != CONTENT_MANIFEST_SHA256:
        raise RuntimeError("Python install projection content manifest is invalid")

    projected_names = {member.relative_name for member in projected}
    if (
        PYTHON_ENTRY_RELATIVE not in projected_names
        or PYTHON_BINARY_RELATIVE not in projected_names
        or "lib/python3.12/os.py" not in projected_names
    ):
        raise RuntimeError("Python install projection is missing a required runtime member")
    if len(python_binary) != PYTHON_BINARY_SIZE or not secrets.compare_digest(
        hashlib.sha256(python_binary).hexdigest(), PYTHON_BINARY_SHA256
    ):
        raise RuntimeError("pinned Python executable fingerprint is invalid")
    inspect_static_elf(python_binary)

    directories: set[str] = set()
    for member in projected:
        parts = PurePosixPath(member.relative_name).parts
        for index in range(1, len(parts)):
            directories.add("/".join(parts[:index]))
    if len(directories) != DERIVED_DIRECTORY_COUNT:
        raise RuntimeError("Python install projection directory derivation changed")

    after = os.lstat(resolved)
    fingerprint = (metadata.st_dev, metadata.st_ino, metadata.st_size, metadata.st_mtime_ns)
    if fingerprint != (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns):
        raise RuntimeError("Python source artifact changed during validation")
    return ArtifactInspection(
        str(resolved),
        compressed_size,
        compressed_hash,
        fingerprint,
        tuple(projected),
        tuple(sorted(directories, key=lambda value: (value.count("/"), value))),
        PYTHON_BINARY_SHA256,
    )


def normalized_mode(member: ProjectedMember) -> int:
    if member.kind == "symlink":
        return 0o777
    return 0o755 if member.source_mode & 0o111 else 0o644


def canonical_tar_info(name: str, kind: str, mode: int) -> tarfile.TarInfo:
    info = tarfile.TarInfo(name)
    info.uid = 0
    info.gid = 0
    info.uname = "root"
    info.gname = "root"
    info.mtime = 0
    info.mode = mode
    if kind == "directory":
        info.type = tarfile.DIRTYPE
    elif kind == "symlink":
        info.type = tarfile.SYMTYPE
    elif kind == "file":
        info.type = tarfile.REGTYPE
    else:
        raise RuntimeError("unsupported canonical payload member kind")
    return info


def inspect_derived_payload(
    path: Path, expected_members: tuple[ProjectedMember, ...] | None = None
) -> dict[str, Any]:
    resolved, metadata = validate_local_regular_file(path, "derived Python payload")
    members = 0
    files = 0
    links = 0
    directories = 0
    payload_size = 0
    names: set[str] = set()
    symlinks: dict[str, str] = {}
    derived_records: list[ProjectedMember] = []
    with tarfile.open(resolved, "r:gz") as archive:
        for item in archive:
            members += 1
            name = item.name.rstrip("/") if item.isdir() else item.name
            if (
                not name
                or PurePosixPath(name).is_absolute()
                or ".." in PurePosixPath(name).parts
                or "\\" in name
                or "\x00" in name
                or any(ord(char) < 32 or ord(char) == 127 for char in name)
                or posixpath.normpath(name) != name
                or name in names
            ):
                raise RuntimeError("derived Python payload contains an unsafe path")
            names.add(name)
            if item.uid != 0 or item.gid != 0 or item.uname != "root" or item.gname != "root":
                raise RuntimeError("derived Python payload ownership metadata is invalid")
            if item.mtime != 0 or (
                item.pax_headers
                and any(
                    "xattr" in key.lower()
                    or "acl" in key.lower()
                    or "capability" in key.lower()
                    for key in item.pax_headers
                )
            ):
                raise RuntimeError("derived Python payload metadata is invalid")
            if item.isdir():
                directories += 1
                if stat.S_IMODE(item.mode) != 0o755:
                    raise RuntimeError("derived Python payload directory mode is invalid")
            elif item.isreg():
                files += 1
                payload_size += item.size
                mode = stat.S_IMODE(item.mode)
                if mode not in (0o644, 0o755) or item.size > MAX_MEMBER_SIZE:
                    raise RuntimeError("derived Python payload file mode is invalid")
                source = archive.extractfile(item)
                if source is None:
                    raise RuntimeError("derived Python payload file cannot be read")
                actual_size, actual_hash = sha256_stream(source)
                source.close()
                if actual_size != item.size:
                    raise RuntimeError("derived Python payload file size changed during read")
                derived_records.append(
                    ProjectedMember(
                        PROJECTION_PREFIX + name,
                        name,
                        "file",
                        item.size,
                        0o775 if mode == 0o755 else 0o664,
                        "",
                        actual_hash,
                    )
                )
            elif item.issym():
                links += 1
                normalized_link_target("python/" + name, item.linkname)
                symlinks[name] = item.linkname
                if stat.S_IMODE(item.mode) != 0o777:
                    raise RuntimeError("derived Python payload symbolic-link mode is invalid")
                derived_records.append(
                    ProjectedMember(
                        PROJECTION_PREFIX + name,
                        name,
                        "symlink",
                        0,
                        0o777,
                        item.linkname,
                        "",
                    )
                )
            else:
                raise RuntimeError("derived Python payload contains an unsupported member")
    expected_member_count = DERIVED_DIRECTORY_COUNT + PROJECTED_MEMBER_COUNT
    if (
        members != expected_member_count
        or directories != DERIVED_DIRECTORY_COUNT
        or files != PROJECTED_REGULAR_COUNT
        or links != PROJECTED_SYMLINK_COUNT
        or payload_size != PROJECTED_PAYLOAD_SIZE
    ):
        raise RuntimeError("derived Python payload totals are invalid")
    for name, link_name in symlinks.items():
        target = posixpath.normpath(posixpath.join(posixpath.dirname(name), link_name))
        if target not in names or target.startswith("../"):
            raise RuntimeError("derived Python payload contains a broken or escaping link")
    for name in names:
        parts = PurePosixPath(name).parts
        for index in range(1, len(parts)):
            if "/".join(parts[:index]) in symlinks:
                raise RuntimeError("derived Python payload stores content beneath a link")
    if manifest_digest(derived_records) != CONTENT_MANIFEST_SHA256:
        raise RuntimeError("derived Python payload content manifest is invalid")
    if expected_members is not None:
        expected = {member.relative_name: member for member in expected_members}
        actual = {member.relative_name: member for member in derived_records}
        if set(actual) != set(expected):
            raise RuntimeError("derived Python payload member set changed")
        for name, contract in expected.items():
            value = actual[name]
            if (
                value.kind != contract.kind
                or value.size != contract.size
                or value.link_name != contract.link_name
                or value.content_sha256 != contract.content_sha256
                or normalized_mode(value) != normalized_mode(contract)
            ):
                raise RuntimeError("derived Python payload member contract changed")
    with resolved.open("rb") as handle:
        size, digest = sha256_stream(handle)
    if size != DERIVED_PAYLOAD_SIZE or not secrets.compare_digest(
        digest, DERIVED_PAYLOAD_SHA256
    ):
        raise RuntimeError("derived Python payload compressed fingerprint is invalid")
    after = os.lstat(resolved)
    fingerprint = (metadata.st_dev, metadata.st_ino, metadata.st_size, metadata.st_mtime_ns)
    if fingerprint != (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns):
        raise RuntimeError("derived Python payload changed during validation")
    return {
        "path": str(resolved),
        "size": size,
        "sha256": digest,
        "fingerprint": fingerprint,
    }


def build_derived_payload(artifact: ArtifactInspection) -> dict[str, Any]:
    descriptor, temporary_name = tempfile.mkstemp(
        prefix="yiyunying-python-runtime-", suffix=".tar.gz"
    )
    os.close(descriptor)
    output = Path(temporary_name)
    contracts = {member.source_name: member for member in artifact.members}
    written: set[str] = set()
    try:
        with output.open("wb") as raw_output:
            with gzip.GzipFile(
                filename="", mode="wb", fileobj=raw_output, compresslevel=9, mtime=0
            ) as compressed_output:
                with tarfile.open(
                    fileobj=compressed_output, mode="w|", format=tarfile.GNU_FORMAT
                ) as destination:
                    for directory in artifact.derived_directories:
                        destination.addfile(canonical_tar_info(directory, "directory", 0o755))
                    with open_zstd_reader(Path(artifact.path)) as decompressed:
                        with tarfile.open(fileobj=decompressed, mode="r|") as source_archive:
                            for item in source_archive:
                                contract = contracts.get(item.name)
                                if contract is None:
                                    continue
                                if contract.source_name in written:
                                    raise RuntimeError("duplicate source member while deriving payload")
                                written.add(contract.source_name)
                                info = canonical_tar_info(
                                    contract.relative_name,
                                    contract.kind,
                                    normalized_mode(contract),
                                )
                                if contract.kind == "file":
                                    info.size = contract.size
                                    source = source_archive.extractfile(item)
                                    if source is None:
                                        raise RuntimeError("projected source member cannot be read")
                                    destination.addfile(info, source)
                                    source.close()
                                else:
                                    info.linkname = contract.link_name
                                    destination.addfile(info)
        if written != set(contracts):
            raise RuntimeError("not every reviewed projection member entered the payload")
        payload = inspect_derived_payload(output, artifact.members)
        source_after = os.lstat(artifact.path)
        if artifact.fingerprint != (
            source_after.st_dev,
            source_after.st_ino,
            source_after.st_size,
            source_after.st_mtime_ns,
        ):
            raise RuntimeError("Python source artifact changed while deriving the payload")
        return payload
    except BaseException:
        try:
            current = os.lstat(output)
            if stat.S_ISREG(current.st_mode) and current.st_nlink == 1 and not output.is_symlink():
                output.unlink()
        except OSError:
            pass
        raise


def remove_derived_payload(payload: dict[str, Any]) -> None:
    path = Path(str(payload["path"]))
    current = os.lstat(path)
    expected = tuple(payload["fingerprint"])
    actual = (current.st_dev, current.st_ino, current.st_size, current.st_mtime_ns)
    if (
        actual != expected
        or not stat.S_ISREG(current.st_mode)
        or current.st_nlink != 1
        or path.is_symlink()
    ):
        raise RuntimeError("RECOVERY_REQUIRED: derived payload identity changed before cleanup")
    path.unlink()


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
    return (
        channel.recv_exit_status(),
        sanitize_for_log(stdout.decode("utf-8", "replace"), (password,)),
        sanitize_for_log(stderr.decode("utf-8", "replace"), (password,)),
    )


def run_remote(
    client: Any,
    command: str,
    label: str,
    password: str,
    allowed: set[int] | None = None,
    timeout: int = REMOTE_COMMAND_TIMEOUT,
    emit_output: bool = True,
    require_empty_stdout: bool = False,
    require_empty_stderr: bool = False,
) -> tuple[int, str]:
    accepted = {0} if allowed is None else allowed
    status, output, error = collect_remote_result(client, command, password, timeout)
    if status not in accepted:
        detail = error.strip() or output.strip() or "no diagnostic output"
        raise RuntimeError(f"{label} failed ({status}): {detail}")
    if require_empty_stdout and output:
        raise RuntimeError(f"{label} returned unexpected stdout")
    if require_empty_stderr and error:
        raise RuntimeError(f"{label} returned unexpected stderr")
    if emit_output and output.strip():
        print(output.strip())
    return status, output


def collect_remote_result(
    client: Any, command: str, password: str, timeout: int = REMOTE_COMMAND_TIMEOUT
) -> tuple[int, str, str]:
    """Run one command without interpreting or emitting its bounded result."""
    _stdin, stdout, _stderr = client.exec_command(command, get_pty=False, timeout=timeout)
    return collect_channel(stdout.channel, timeout, password)


def parse_remote_failure_diagnostic(
    error: str,
    status: int,
    allowed_phases: frozenset[str] = REMOTE_FAILURE_PHASES,
) -> str:
    """Accept only the one-line, allowlisted remote failure protocol."""
    if status < 1 or status > 255:
        raise RuntimeError("remote failure status is outside the strict protocol")
    lines = error.splitlines()
    if len(lines) != 1 or not lines[0]:
        raise RuntimeError("remote failure diagnostic must contain exactly one line")
    match = REMOTE_FAILURE_DIAGNOSTIC_RE.fullmatch(lines[0])
    if match is None:
        raise RuntimeError("remote failure diagnostic does not match the strict protocol")
    phase = match.group(1)
    reported_status = int(match.group(2))
    if phase not in allowed_phases:
        raise RuntimeError("remote failure diagnostic contains an unknown phase")
    if reported_status != status:
        raise RuntimeError("remote failure diagnostic exit code does not match SSH status")
    return phase


def run_remote_phased(
    client: Any,
    command: str,
    label: str,
    password: str,
    timeout: int = REMOTE_COMMAND_TIMEOUT,
    allowed_phases: frozenset[str] = REMOTE_FAILURE_PHASES,
) -> str:
    """Run an install/validation command with a non-secret failure protocol."""
    status, output, error = collect_remote_result(client, command, password, timeout)
    if status == 0:
        if error:
            raise RuntimeError(f"{label} returned unexpected diagnostic output")
        return output
    if output:
        raise RuntimeError(f"{label} returned unexpected failure output")
    phase = parse_remote_failure_diagnostic(error, status, allowed_phases)
    raise RemotePhaseFailure(phase, status)


def validate_known_hosts(path: Path) -> Path:
    resolved, _metadata = validate_local_regular_file(path, "known_hosts")
    if resolved.stat().st_size < 1:
        raise RuntimeError("known_hosts must not be empty")
    return resolved


def connect(args: argparse.Namespace, password: str):
    try:
        import paramiko
    except ImportError as exc:
        raise RuntimeError(
            "paramiko is required; use backend/tools/requirements-release.txt"
        ) from exc
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
        disabled_algorithms={
            "kex": ["curve25519-sha256", "curve25519-sha256@libssh.org"]
        },
    )
    transport = client.get_transport()
    if transport is None or not transport.is_active():
        client.close()
        raise RuntimeError("SSH transport is inactive")
    transport.set_keepalive(15)
    return client


def python_smoke_code() -> str:
    imports = ",".join(REQUIRED_STDLIB_IMPORTS)
    source = f"""import {imports},hashlib,os,struct,sys
if sys.version_info[:3] != (3, 12, 13): raise SystemExit(17)
path = os.path.realpath(sys.executable)
with open(path, 'rb') as handle: payload = handle.read()
if len(payload) != {PYTHON_BINARY_SIZE}: raise SystemExit(18)
if hashlib.sha256(payload).hexdigest() != {PYTHON_BINARY_SHA256!r}: raise SystemExit(19)
if len(payload) < 64 or payload[:7] != b'\\x7fELF\\x02\\x01\\x01': raise SystemExit(20)
e_type, e_machine = struct.unpack_from('<HH', payload, 16)
if e_type != 2 or e_machine != 62: raise SystemExit(21)
e_phoff = struct.unpack_from('<Q', payload, 32)[0]
e_phentsize, e_phnum = struct.unpack_from('<HH', payload, 54)
if e_phnum < 1 or e_phentsize < 56 or e_phoff + e_phentsize * e_phnum > len(payload): raise SystemExit(22)
for index in range(e_phnum):
    p_type = struct.unpack_from('<I', payload, e_phoff + index * e_phentsize)[0]
    if p_type in (2, 3): raise SystemExit(23)
"""
    return "exec(" + repr(source) + ")"


def remote_tree_and_smoke_functions() -> str:
    """One Bash implementation shared by install and remote validation."""
    return r'''audit_tree() {
  local root="$1" found
  if ! test -d "$root" || test -L "$root"; then return 1; fi
  if ! found=$(stat -c '%a|%U|%G' -- "$root"); then return 1; fi
  if [ "$found" != '755|root|root' ]; then return 1; fi
  if ! found=$(find "$root" -xdev \( ! -user root -o ! -group root \) -print -quit); then return 1; fi
  if test -n "$found"; then return 1; fi
  if ! found=$(find "$root" -xdev ! -type l -perm /022 -print -quit); then return 1; fi
  if test -n "$found"; then return 1; fi
  if ! found=$(find "$root" -xdev ! -type l -perm /7000 -print -quit); then return 1; fi
  if test -n "$found"; then return 1; fi
  if ! found=$(find "$root" -xdev -type f -links +1 -print -quit); then return 1; fi
  if test -n "$found"; then return 1; fi
  if ! found=$(find "$root" -xdev ! -type f ! -type d ! -type l -print -quit); then return 1; fi
  if test -n "$found"; then return 1; fi
  if ! find "$root" -xdev -type l -exec /bin/bash --noprofile --norc -c '
    root=$1; shift
    for link do
      if ! resolved=$(readlink -f -- "$link"); then exit 1; fi
      case "$resolved" in "$root"/*) ;; *) exit 1;; esac
      test -e "$resolved" || exit 1
    done
  ' audit-python-links "$root" {} +; then return 1; fi
}

python_smoke() {
  local executable="$1" expected_root="$2" resolved
  if ! test -x "$executable"; then return 1; fi
  if ! resolved=$(readlink -f -- "$executable"); then return 1; fi
  case "$resolved" in "$expected_root"/*) ;; *) return 1;; esac
  env -i PATH=/usr/bin:/bin LC_ALL=C LANG=C \
    "$executable" -I -S -B -c "$SMOKE_CODE" </dev/null >/dev/null 2>&1
}'''


def bash_command(script: str, arguments: tuple[str, ...] = ()) -> str:
    command = (
        "env -i PATH=/usr/bin:/bin LC_ALL=C LANG=C "
        "/bin/bash --noprofile --norc -c "
        + shlex.quote(script)
        + " --"
    )
    for argument in arguments:
        command += " " + shlex.quote(argument)
    return command


def preflight_script() -> str:
    template = r'''set -euo pipefail
export LC_ALL=C LANG=C
RUNTIME_ROOT=@RUNTIME_ROOT@
TARGET=@TARGET@
STABLE=@STABLE@
LOCK=@LOCK@
RECEIPT=@RECEIPT@
MIN_FREE=@MIN_FREE@
SMOKE_CODE=@SMOKE_CODE@

validate_root_directory() {
  local path="$1" state uid gid mode kind
  if ! test -d "$path" || test -L "$path"; then return 1; fi
  if ! state=$(stat -c '%u|%g|%a|%F' -- "$path"); then return 1; fi
  uid=${state%%|*}; state=${state#*|}; gid=${state%%|*}; state=${state#*|}
  mode=${state%%|*}; kind=${state#*|}
  if [ "$uid" != 0 ] || [ "$gid" != 0 ] || [ "$kind" != directory ]; then return 1; fi
  if ! test $((0$mode & 022)) -eq 0; then return 1; fi
}

audit_tree() {
  local root="$1" found
  if ! test -d "$root" || test -L "$root"; then return 1; fi
  if ! found=$(stat -c '%a|%U|%G' -- "$root"); then return 1; fi
  if [ "$found" != '755|root|root' ]; then return 1; fi
  if ! found=$(find "$root" -xdev \( ! -user root -o ! -group root \) -print -quit); then return 1; fi
  if test -n "$found"; then return 1; fi
  if ! found=$(find "$root" -xdev ! -type l -perm /022 -print -quit); then return 1; fi
  if test -n "$found"; then return 1; fi
  if ! found=$(find "$root" -xdev ! -type l -perm /7000 -print -quit); then return 1; fi
  if test -n "$found"; then return 1; fi
  if ! found=$(find "$root" -xdev -type f -links +1 -print -quit); then return 1; fi
  if test -n "$found"; then return 1; fi
  if ! found=$(find "$root" -xdev ! -type f ! -type d ! -type l -print -quit); then return 1; fi
  if test -n "$found"; then return 1; fi
  if ! find "$root" -xdev -type l -exec /bin/bash --noprofile --norc -c '
    root=$1; shift
    for link do
      if ! resolved=$(readlink -f -- "$link"); then exit 1; fi
      case "$resolved" in "$root"/*) ;; *) exit 1;; esac
      test -e "$resolved" || exit 1
    done
  ' audit-python-links "$root" {} +; then return 1; fi
}

python_smoke() {
  local executable="$1" expected_root="$2" resolved
  if ! test -x "$executable"; then return 1; fi
  if ! resolved=$(readlink -f -- "$executable"); then return 1; fi
  case "$resolved" in "$expected_root"/*) ;; *) return 1;; esac
  env -i PATH=/usr/bin:/bin LC_ALL=C LANG=C \
    "$executable" -I -S -B -c "$SMOKE_CODE" </dev/null >/dev/null 2>&1
}

validate_stable() {
  local target version suffix root state
  if [ ! -e "$STABLE" ] && [ ! -L "$STABLE" ]; then return 0; fi
  if ! test -L "$STABLE"; then return 1; fi
  if ! state=$(stat -c '%U|%G|%F' -- "$STABLE"); then return 1; fi
  if [ "$state" != 'root|root|symbolic link' ]; then return 1; fi
  if ! target=$(readlink -- "$STABLE"); then return 1; fi
  case "$target" in "$RUNTIME_ROOT"/*/bin/python3) ;; *) return 1;; esac
  version=${target#"$RUNTIME_ROOT"/}; version=${version%%/*}
  suffix=${target#"$RUNTIME_ROOT"/$version/}
  case "$version" in ''|*[!A-Za-z0-9._+-]*) return 1;; esac
  if [ "$suffix" != 'bin/python3' ]; then return 1; fi
  root="$RUNTIME_ROOT/$version"
  audit_tree "$root" || return 1
  python_smoke "$STABLE" "$root"
}

validate_previous_receipt() {
  local value version suffix root state line_count
  if ! test -f "$RECEIPT" || test -L "$RECEIPT"; then return 1; fi
  if ! state=$(stat -c '%a|%U|%G' -- "$RECEIPT"); then return 1; fi
  if [ "$state" != '400|root|root' ]; then return 1; fi
  if ! line_count=$(wc -l < "$RECEIPT"); then return 1; fi
  if [ "$line_count" -ne 1 ]; then return 1; fi
  if ! value=$(cat -- "$RECEIPT"); then return 1; fi
  if [ "$value" = missing ]; then return 0; fi
  case "$value" in "$RUNTIME_ROOT"/*/bin/python3) ;; *) return 1;; esac
  version=${value#"$RUNTIME_ROOT"/}; version=${version%%/*}
  suffix=${value#"$RUNTIME_ROOT"/$version/}
  case "$version" in ''|*[!A-Za-z0-9._+-]*) return 1;; esac
  if [ "$suffix" != 'bin/python3' ]; then return 1; fi
  root="$RUNTIME_ROOT/$version"
  audit_tree "$root" || return 1
  python_smoke "$value" "$root"
}

if ! identity=$(id -u); then exit 1; fi
if [ "$identity" -ne 0 ]; then exit 1; fi
if ! kernel=$(uname -s); then exit 1; fi
if [ "$kernel" != Linux ]; then exit 1; fi
if ! machine=$(uname -m); then exit 1; fi
if [ "$machine" != x86_64 ]; then exit 1; fi
test -x /bin/bash
for command in stat df awk sha256sum tar install mkdir mv ln readlink find chown chmod rm rmdir grep wc cat; do
  command -v "$command" >/dev/null
done
tar --version | grep -q 'GNU tar'
tar --help | grep -q -- '--strip-components'
mv --help | grep -q -- '-T'
validate_root_directory /
validate_root_directory /opt
validate_root_directory /usr
validate_root_directory /usr/local
validate_root_directory /usr/local/bin
for boundary in /opt/yiyunying "$RUNTIME_ROOT"; do
  if [ -e "$boundary" ] || [ -L "$boundary" ]; then validate_root_directory "$boundary"; fi
done
test ! -e "$LOCK" && test ! -L "$LOCK"
RECEIPT_STATE=absent
if [ -e "$RECEIPT" ] || [ -L "$RECEIPT" ]; then
  validate_previous_receipt
  RECEIPT_STATE=ready
fi

TARGET_STATE=absent
if [ -e "$TARGET" ] || [ -L "$TARGET" ]; then
  audit_tree "$TARGET"
  python_smoke "$TARGET/bin/python3" "$TARGET"
  TARGET_STATE=ready
fi
STABLE_STATE=absent
if [ -e "$STABLE" ] || [ -L "$STABLE" ]; then
  validate_stable
  STABLE_STATE=ready
fi
ancestor="$RUNTIME_ROOT"
while [ ! -e "$ancestor" ]; do ancestor=${ancestor%/*}; test -n "$ancestor"; done
test -d "$ancestor" && test ! -L "$ancestor"
if ! free=$(df -PB1 "$ancestor" | awk 'NR==2 {print $4}'); then exit 1; fi
test -n "$free" && test "$free" -ge "$MIN_FREE"
printf 'PYTHON_RUNTIME_PREFLIGHT=pass\nTARGET_STATE=%s\nSTABLE_STATE=%s\nRECEIPT_STATE=%s\nFREE_BYTES=%s\n' \
  "$TARGET_STATE" "$STABLE_STATE" "$RECEIPT_STATE" "$free"
'''
    return (
        template.replace("@RUNTIME_ROOT@", shlex.quote(RUNTIME_ROOT))
        .replace("@TARGET@", shlex.quote(TARGET_DIRECTORY))
        .replace("@STABLE@", shlex.quote(STABLE_PATH))
        .replace("@LOCK@", shlex.quote(LOCK_DIRECTORY))
        .replace("@RECEIPT@", shlex.quote(PREVIOUS_TARGET_RECEIPT))
        .replace("@MIN_FREE@", str(MINIMUM_FREE_BYTES))
        .replace("@SMOKE_CODE@", shlex.quote(python_smoke_code()))
    )


def preflight_command() -> str:
    return bash_command(preflight_script())


def remote_install_script() -> str:
    template = r'''set -Eeuo pipefail
export LC_ALL=C LANG=C
exec 3>&1 4>&2
exec >/dev/null 2>&1
ARCHIVE="$1"
PAYLOAD_SIZE="$2"
PAYLOAD_SHA="$3"
TOKEN="$4"
RUNTIME_ROOT=@RUNTIME_ROOT@
TARGET=@TARGET@
STABLE=@STABLE@
LOCK=@LOCK@
RECEIPT=@RECEIPT@
VERSION=@VERSION@
VERSION_DIR=@VERSION_DIR@
ARTIFACT_SHA=@ARTIFACT_SHA@
SMOKE_CODE=@SMOKE_CODE@
WORK="$RUNTIME_ROOT/.stage-$VERSION_DIR-$TOKEN"
LINK_STAGE="/usr/local/bin/.python3.yiyunying-$TOKEN"
ROLLBACK_LINK="/usr/local/bin/.python3.rollback-$TOKEN"
LOCK_HELD=0
WORK_HELD=0
LINK_STAGE_HELD=0
ROLLBACK_LINK_HELD=0
SWITCHED=0
PREVIOUS_KIND=missing
PREVIOUS_TARGET=''
REPEAT=false
PHASE=archive

fail() { return "$1"; }

validate_root_directory() {
  local path="$1" state uid gid mode kind
  if ! test -d "$path" || test -L "$path"; then return 1; fi
  if ! state=$(stat -c '%u|%g|%a|%F' -- "$path"); then return 1; fi
  uid=${state%%|*}; state=${state#*|}; gid=${state%%|*}; state=${state#*|}
  mode=${state%%|*}; kind=${state#*|}
  if [ "$uid" != 0 ] || [ "$gid" != 0 ] || [ "$kind" != directory ]; then return 1; fi
  if ! test $((0$mode & 022)) -eq 0; then return 1; fi
}

@VALIDATION_FUNCTIONS@

validate_stable_target() {
  local target="$1" version suffix root
  case "$target" in "$RUNTIME_ROOT"/*/bin/python3) ;; *) return 1;; esac
  version=${target#"$RUNTIME_ROOT"/}; version=${version%%/*}
  suffix=${target#"$RUNTIME_ROOT"/$version/}
  case "$version" in ''|*[!A-Za-z0-9._+-]*) return 1;; esac
  if [ "$suffix" != 'bin/python3' ]; then return 1; fi
  root="$RUNTIME_ROOT/$version"
  audit_tree "$root" || return 1
  python_smoke "$target" "$root"
}

validate_previous_value() {
  local value="$1"
  if [ "$value" = missing ]; then return 0; fi
  validate_stable_target "$value"
}

read_previous_receipt() {
  local value state line_count
  if ! test -f "$RECEIPT" || test -L "$RECEIPT"; then return 1; fi
  if ! state=$(stat -c '%a|%U|%G' -- "$RECEIPT"); then return 1; fi
  if [ "$state" != '400|root|root' ]; then return 1; fi
  if ! line_count=$(wc -l < "$RECEIPT"); then return 1; fi
  if [ "$line_count" -ne 1 ]; then return 1; fi
  if ! value=$(cat -- "$RECEIPT"); then return 1; fi
  validate_previous_value "$value" || return 1
  printf '%s' "$value"
}

ensure_previous_receipt() {
  local expected="$1" current
  validate_previous_value "$expected" || return 1
  if [ -e "$RECEIPT" ] || [ -L "$RECEIPT" ]; then
    if ! current=$(read_previous_receipt); then return 1; fi
    if [ "$current" != "$expected" ]; then return 1; fi
    return 0
  fi
  (umask 077; set -o noclobber; printf '%s\n' "$expected" > "$RECEIPT") || return 1
  chown root:root -- "$RECEIPT" || return 1
  chmod 0400 -- "$RECEIPT" || return 1
  if ! current=$(read_previous_receipt); then return 1; fi
  if [ "$current" != "$expected" ]; then return 1; fi
}

cleanup_owned_link() {
  local path="$1" expected="$2" current state
  if [ ! -e "$path" ] && [ ! -L "$path" ]; then return 0; fi
  if ! test -L "$path"; then return 1; fi
  if ! state=$(stat -c '%U|%G|%F' -- "$path"); then return 1; fi
  if [ "$state" != 'root|root|symbolic link' ]; then return 1; fi
  if ! current=$(readlink -- "$path"); then return 1; fi
  if [ "$current" != "$expected" ]; then return 1; fi
  rm -f -- "$path"
}

cleanup_work() {
  local state
  if [ "$WORK_HELD" -eq 0 ]; then return 0; fi
  if [ ! -e "$WORK" ] && [ ! -L "$WORK" ]; then return 0; fi
  if ! test -d "$WORK" || test -L "$WORK"; then return 1; fi
  if ! state=$(stat -c '%a|%U|%G' -- "$WORK"); then return 1; fi
  case "$state" in '700|root|root'|'755|root|root') ;; *) return 1;; esac
  case "$WORK" in "$RUNTIME_ROOT"/.stage-"$VERSION_DIR"-"$TOKEN") ;; *) return 1;; esac
  rm -rf -- "$WORK" || return 1
  if [ -e "$WORK" ] || [ -L "$WORK" ]; then return 1; fi
  WORK_HELD=0
}

release_lock() {
  local state
  if [ "$LOCK_HELD" -eq 0 ]; then return 0; fi
  if ! test -d "$LOCK" || test -L "$LOCK"; then return 1; fi
  if ! state=$(stat -c '%a|%U|%G' -- "$LOCK"); then return 1; fi
  if [ "$state" != '700|root|root' ]; then return 1; fi
  rmdir -- "$LOCK" || return 1
  LOCK_HELD=0
}

rollback_stable() {
  local current state
  if [ "$SWITCHED" -eq 0 ]; then return 0; fi
  if [ -e "$ROLLBACK_LINK" ] || [ -L "$ROLLBACK_LINK" ]; then return 1; fi
  if ! test -L "$STABLE"; then return 1; fi
  if ! state=$(stat -c '%U|%G|%F' -- "$STABLE"); then return 1; fi
  if [ "$state" != 'root|root|symbolic link' ]; then return 1; fi
  if ! current=$(readlink -- "$STABLE"); then return 1; fi
  if [ "$current" != "$TARGET/bin/python3" ]; then return 1; fi
  if [ "$PREVIOUS_KIND" = link ]; then
    validate_stable_target "$PREVIOUS_TARGET" || return 1
    (umask 077; ln -s -- "$PREVIOUS_TARGET" "$ROLLBACK_LINK") || return 1
    ROLLBACK_LINK_HELD=1
    chown -h root:root -- "$ROLLBACK_LINK" || return 1
    mv -Tf -- "$ROLLBACK_LINK" "$STABLE" || return 1
    ROLLBACK_LINK_HELD=0
    if ! test -L "$STABLE"; then return 1; fi
    if ! current=$(readlink -- "$STABLE"); then return 1; fi
    if [ "$current" != "$PREVIOUS_TARGET" ]; then return 1; fi
    python_smoke "$STABLE" "${PREVIOUS_TARGET%/bin/python3}" || return 1
  else
    rm -f -- "$STABLE" || return 1
    if [ -e "$STABLE" ] || [ -L "$STABLE" ]; then return 1; fi
  fi
  SWITCHED=0
}

on_error() {
  local status=$? failure_phase="$PHASE"
  trap - ERR INT TERM HUP
  if [ "$status" -eq 0 ]; then status=130; fi
  if ! rollback_stable; then
    failure_phase=cleanup
    status=90
  fi
  if [ "$LINK_STAGE_HELD" -eq 1 ]; then
    if ! cleanup_owned_link "$LINK_STAGE" "$TARGET/bin/python3"; then
      failure_phase=cleanup
      status=91
    else
      LINK_STAGE_HELD=0
    fi
  fi
  if [ "$ROLLBACK_LINK_HELD" -eq 1 ]; then
    if ! cleanup_owned_link "$ROLLBACK_LINK" "$PREVIOUS_TARGET"; then
      failure_phase=cleanup
      status=92
    else
      ROLLBACK_LINK_HELD=0
    fi
  fi
  if ! cleanup_work; then
    failure_phase=cleanup
    status=93
  fi
  if ! release_lock; then
    failure_phase=cleanup
    status=94
  fi
  case "$failure_phase" in
    archive|parents|lock|extract|normalize|tree-audit|python-smoke|target-move|stable-switch|post-smoke|cleanup) ;;
    *) failure_phase=cleanup; status=95;;
  esac
  printf 'PYTHON_RUNTIME_FAILURE_PHASE=%s;EXIT_CODE=%s\n' "$failure_phase" "$status" >&4
  exit "$status"
}
trap on_error ERR INT TERM HUP

case "$TOKEN" in ''|*[!0-9a-f]* ) fail 20;; esac
test "${#TOKEN}" -eq 32
case "$PAYLOAD_SHA" in *[!0-9a-f]*|'') fail 21;; esac
test "${#PAYLOAD_SHA}" -eq 64
case "$PAYLOAD_SIZE" in ''|*[!0-9]*) fail 22;; esac
test "$PAYLOAD_SIZE" -gt 0 && test "$PAYLOAD_SIZE" -lt 536870912
test -f "$ARCHIVE" && test ! -L "$ARCHIVE"
if ! archive_state=$(stat -c '%a|%U|%G|%s' -- "$ARCHIVE"); then fail 23; fi
if [ "$archive_state" != "600|root|root|$PAYLOAD_SIZE" ]; then fail 23; fi
if ! archive_hash=$(sha256sum -- "$ARCHIVE" | awk 'NR==1 {print $1}'); then fail 24; fi
if [ "$archive_hash" != "$PAYLOAD_SHA" ]; then fail 24; fi
if ! identity=$(id -u); then fail 25; fi
if [ "$identity" -ne 0 ]; then fail 25; fi
if ! kernel=$(uname -s); then fail 26; fi
if [ "$kernel" != Linux ]; then fail 26; fi
if ! machine=$(uname -m); then fail 27; fi
if [ "$machine" != x86_64 ]; then fail 27; fi
PHASE=parents
validate_root_directory /
validate_root_directory /opt
validate_root_directory /usr
validate_root_directory /usr/local
validate_root_directory /usr/local/bin

if [ ! -e /opt/yiyunying ] && [ ! -L /opt/yiyunying ]; then
  install -d -m 0755 -o root -g root -- /opt/yiyunying
else
  validate_root_directory /opt/yiyunying
fi
if [ ! -e "$RUNTIME_ROOT" ] && [ ! -L "$RUNTIME_ROOT" ]; then
  install -d -m 0755 -o root -g root -- "$RUNTIME_ROOT"
else
  validate_root_directory "$RUNTIME_ROOT"
  if ! runtime_state=$(stat -c '%a|%U|%G' -- "$RUNTIME_ROOT"); then fail 28; fi
  if [ "$runtime_state" != '755|root|root' ]; then fail 28; fi
fi
PHASE=lock
test ! -e "$LOCK" && test ! -L "$LOCK"
(umask 077; mkdir -- "$LOCK")
LOCK_HELD=1
chown root:root -- "$LOCK"
chmod 0700 -- "$LOCK"

if [ -e "$STABLE" ] || [ -L "$STABLE" ]; then
  test -L "$STABLE"
  if ! stable_state=$(stat -c '%U|%G|%F' -- "$STABLE"); then fail 29; fi
  if [ "$stable_state" != 'root|root|symbolic link' ]; then fail 29; fi
  if ! PREVIOUS_TARGET=$(readlink -- "$STABLE"); then fail 29; fi
  validate_stable_target "$PREVIOUS_TARGET"
  PREVIOUS_KIND=link
fi

if [ -e "$TARGET" ] || [ -L "$TARGET" ]; then
  PHASE=tree-audit
  audit_tree "$TARGET"
  PHASE=python-smoke
  python_smoke "$TARGET/bin/python3" "$TARGET"
  REPEAT=true
else
  PHASE=extract
  test ! -e "$WORK" && test ! -L "$WORK"
  (umask 077; mkdir -- "$WORK")
  WORK_HELD=1
  chown root:root -- "$WORK"; chmod 0700 -- "$WORK"
  tar -xzf "$ARCHIVE" -C "$WORK" --no-same-owner --same-permissions
  PHASE=normalize
  chown -R root:root -- "$WORK"
  find "$WORK" -xdev -type l -exec chown -h root:root -- {} +
  find "$WORK" -xdev -type d -exec chmod 0755 -- {} +
  find "$WORK" -xdev -type f -perm /111 -exec chmod 0755 -- {} +
  find "$WORK" -xdev -type f ! -perm /111 -exec chmod 0644 -- {} +
  PHASE=tree-audit
  audit_tree "$WORK"
  PHASE=python-smoke
  python_smoke "$WORK/bin/python3" "$WORK"
  PHASE=target-move
  test ! -e "$TARGET" && test ! -L "$TARGET"
  mv -T -- "$WORK" "$TARGET"
  WORK_HELD=0
  PHASE=tree-audit
  audit_tree "$TARGET"
  PHASE=python-smoke
  python_smoke "$TARGET/bin/python3" "$TARGET"
fi

PHASE=stable-switch
if [ "$PREVIOUS_KIND" = link ]; then
  if ! test -L "$STABLE"; then fail 30; fi
  if ! stable_target=$(readlink -- "$STABLE"); then fail 30; fi
  if [ "$stable_target" != "$PREVIOUS_TARGET" ]; then fail 30; fi
else
  test ! -e "$STABLE" && test ! -L "$STABLE"
fi

if [ "$PREVIOUS_KIND" != link ] || [ "$PREVIOUS_TARGET" != "$TARGET/bin/python3" ]; then
  PREVIOUS_VALUE=missing
  if [ "$PREVIOUS_KIND" = link ]; then PREVIOUS_VALUE="$PREVIOUS_TARGET"; fi
  ensure_previous_receipt "$PREVIOUS_VALUE"
  test ! -e "$LINK_STAGE" && test ! -L "$LINK_STAGE"
  (umask 077; ln -s -- "$TARGET/bin/python3" "$LINK_STAGE")
  LINK_STAGE_HELD=1
  chown -h root:root -- "$LINK_STAGE"
  if ! link_state=$(stat -c '%U|%G|%F' -- "$LINK_STAGE"); then fail 31; fi
  if [ "$link_state" != 'root|root|symbolic link' ]; then fail 31; fi
  SWITCHED=1
  mv -Tf -- "$LINK_STAGE" "$STABLE"
  LINK_STAGE_HELD=0
fi
if ! test -L "$STABLE"; then fail 32; fi
if ! stable_target=$(readlink -- "$STABLE"); then fail 32; fi
if [ "$stable_target" != "$TARGET/bin/python3" ]; then fail 32; fi
if ! stable_state=$(stat -c '%U|%G|%F' -- "$STABLE"); then fail 32; fi
if [ "$stable_state" != 'root|root|symbolic link' ]; then fail 32; fi
PHASE=post-smoke
audit_tree "$TARGET"
python_smoke "$STABLE" "$TARGET"

SWITCH_VALUE=false
if [ "$SWITCHED" -eq 1 ]; then SWITCH_VALUE=true; fi
PREVIOUS_VALUE=missing
if [ "$PREVIOUS_KIND" = link ]; then PREVIOUS_VALUE="$PREVIOUS_TARGET"; fi
RECEIPT_VALUE=absent
if [ -e "$RECEIPT" ] || [ -L "$RECEIPT" ]; then
  read_previous_receipt >/dev/null
  RECEIPT_VALUE="$RECEIPT"
fi
PHASE=cleanup
if [ "$LINK_STAGE_HELD" -eq 1 ]; then
  cleanup_owned_link "$LINK_STAGE" "$TARGET/bin/python3"
  LINK_STAGE_HELD=0
fi
if [ "$ROLLBACK_LINK_HELD" -eq 1 ]; then
  cleanup_owned_link "$ROLLBACK_LINK" "$PREVIOUS_TARGET"
  ROLLBACK_LINK_HELD=0
fi
cleanup_work
release_lock
trap - ERR INT TERM HUP
printf '{"PYTHON_RUNTIME_INSTALL":"pass","artifact_sha256":"%s","payload_sha256":"%s","platform":"linux/amd64","previous":"%s","repeat":%s,"rollback_receipt":"%s","stable":"%s","switched":%s,"target":"%s","version":"%s"}\n' \
  "$ARTIFACT_SHA" "$PAYLOAD_SHA" "$PREVIOUS_VALUE" "$REPEAT" "$RECEIPT_VALUE" "$STABLE" "$SWITCH_VALUE" "$TARGET" "$VERSION" >&3
'''
    return (
        template.replace("@RUNTIME_ROOT@", shlex.quote(RUNTIME_ROOT))
        .replace("@TARGET@", shlex.quote(TARGET_DIRECTORY))
        .replace("@STABLE@", shlex.quote(STABLE_PATH))
        .replace("@LOCK@", shlex.quote(LOCK_DIRECTORY))
        .replace("@RECEIPT@", shlex.quote(PREVIOUS_TARGET_RECEIPT))
        .replace("@VERSION@", shlex.quote(VERSION))
        .replace("@VERSION_DIR@", shlex.quote(VERSION_DIRECTORY))
        .replace("@ARTIFACT_SHA@", shlex.quote(ARTIFACT_SHA256))
        .replace("@SMOKE_CODE@", shlex.quote(python_smoke_code()))
        .replace("@VALIDATION_FUNCTIONS@", remote_tree_and_smoke_functions())
    )


def remote_validate_script() -> str:
    """Validate the exact payload under /tmp without touching install state."""
    template = r'''set -Eeuo pipefail
export LC_ALL=C LANG=C
exec 3>&1 4>&2
exec >/dev/null 2>&1
ARCHIVE="$1"
PAYLOAD_SIZE="$2"
PAYLOAD_SHA="$3"
TOKEN="$4"
VERSION=@VERSION@
SMOKE_CODE=@SMOKE_CODE@
WORK="/tmp/.yiyunying-python-runtime-validate-$VERSION-$TOKEN"
WORK_HELD=0
PHASE=archive

fail() { return "$1"; }

@VALIDATION_FUNCTIONS@

cleanup_validate_work() {
  local state
  if [ "$WORK_HELD" -eq 0 ]; then return 0; fi
  if [ ! -e "$WORK" ] && [ ! -L "$WORK" ]; then return 0; fi
  case "$WORK" in /tmp/.yiyunying-python-runtime-validate-"$VERSION"-"$TOKEN") ;; *) return 1;; esac
  if ! test -d "$WORK" || test -L "$WORK"; then return 1; fi
  if ! state=$(stat -c '%a|%U|%G' -- "$WORK"); then return 1; fi
  case "$state" in '700|root|root'|'755|root|root') ;; *) return 1;; esac
  rm -rf -- "$WORK" || return 1
  if [ -e "$WORK" ] || [ -L "$WORK" ]; then return 1; fi
  WORK_HELD=0
}

on_error() {
  local status=$? failure_phase="$PHASE"
  trap - ERR INT TERM HUP
  if [ "$status" -eq 0 ]; then status=130; fi
  if ! cleanup_validate_work; then
    failure_phase=cleanup
    status=93
  fi
  case "$failure_phase" in
    archive|extract|normalize|tree-audit|python-smoke|cleanup) ;;
    *) failure_phase=cleanup; status=95;;
  esac
  printf 'PYTHON_RUNTIME_FAILURE_PHASE=%s;EXIT_CODE=%s\n' "$failure_phase" "$status" >&4
  exit "$status"
}
trap on_error ERR INT TERM HUP

case "$TOKEN" in ''|*[!0-9a-f]* ) fail 20;; esac
test "${#TOKEN}" -eq 32
case "$PAYLOAD_SHA" in *[!0-9a-f]*|'') fail 21;; esac
test "${#PAYLOAD_SHA}" -eq 64
case "$PAYLOAD_SIZE" in ''|*[!0-9]*) fail 22;; esac
test "$PAYLOAD_SIZE" -gt 0 && test "$PAYLOAD_SIZE" -lt 536870912
test -f "$ARCHIVE" && test ! -L "$ARCHIVE"
if ! archive_state=$(stat -c '%a|%U|%G|%s' -- "$ARCHIVE"); then fail 23; fi
if [ "$archive_state" != "600|root|root|$PAYLOAD_SIZE" ]; then fail 23; fi
if ! archive_hash=$(sha256sum -- "$ARCHIVE" | awk 'NR==1 {print $1}'); then fail 24; fi
if [ "$archive_hash" != "$PAYLOAD_SHA" ]; then fail 24; fi
if ! identity=$(id -u); then fail 25; fi
if [ "$identity" -ne 0 ]; then fail 25; fi
if ! kernel=$(uname -s); then fail 26; fi
if [ "$kernel" != Linux ]; then fail 26; fi
if ! machine=$(uname -m); then fail 27; fi
if [ "$machine" != x86_64 ]; then fail 27; fi
test -d /tmp && test ! -L /tmp

PHASE=extract
test ! -e "$WORK" && test ! -L "$WORK"
(umask 077; mkdir -- "$WORK")
WORK_HELD=1
chown root:root -- "$WORK"
chmod 0700 -- "$WORK"
tar -xzf "$ARCHIVE" -C "$WORK" --no-same-owner --same-permissions

PHASE=normalize
chown -R root:root -- "$WORK"
find "$WORK" -xdev -type l -exec chown -h root:root -- {} +
find "$WORK" -xdev -type d -exec chmod 0755 -- {} +
find "$WORK" -xdev -type f -perm /111 -exec chmod 0755 -- {} +
find "$WORK" -xdev -type f ! -perm /111 -exec chmod 0644 -- {} +

PHASE=tree-audit
audit_tree "$WORK"
PHASE=python-smoke
python_smoke "$WORK/bin/python3" "$WORK"
PHASE=cleanup
cleanup_validate_work
trap - ERR INT TERM HUP
printf '{"PYTHON_RUNTIME_REMOTE_VALIDATE":"pass","payload_sha256":"%s","platform":"linux/amd64","version":"%s"}\n' \
  "$PAYLOAD_SHA" "$VERSION" >&3
'''
    return (
        template.replace("@VERSION@", shlex.quote(VERSION))
        .replace("@SMOKE_CODE@", shlex.quote(python_smoke_code()))
        .replace("@VALIDATION_FUNCTIONS@", remote_tree_and_smoke_functions())
    )


def installer_command(remote_stage: str, payload: dict[str, Any]) -> str:
    match = REMOTE_STAGE_RE.fullmatch(remote_stage)
    size = int(payload["size"])
    digest = str(payload["sha256"])
    if match is None or size < 1 or size >= 512 * 1024 * 1024:
        raise RuntimeError("derived payload stage contract is invalid")
    if (
        re.fullmatch(r"[0-9a-f]{64}", digest) is None
        or size != DERIVED_PAYLOAD_SIZE
        or digest != DERIVED_PAYLOAD_SHA256
    ):
        raise RuntimeError("derived payload hash contract is invalid")
    return bash_command(
        remote_install_script(),
        (remote_stage, str(size), digest, match.group(1)),
    )


def remote_validate_command(remote_stage: str, payload: dict[str, Any]) -> str:
    match = REMOTE_STAGE_RE.fullmatch(remote_stage)
    size = int(payload["size"])
    digest = str(payload["sha256"])
    if match is None or size < 1 or size >= 512 * 1024 * 1024:
        raise RuntimeError("derived payload validation stage contract is invalid")
    if (
        re.fullmatch(r"[0-9a-f]{64}", digest) is None
        or size != DERIVED_PAYLOAD_SIZE
        or digest != DERIVED_PAYLOAD_SHA256
    ):
        raise RuntimeError("derived payload validation hash contract is invalid")
    token = match.group(1)
    work = f"/tmp/.yiyunying-python-runtime-validate-{VERSION}-{token}"
    if REMOTE_VALIDATE_WORK_RE.fullmatch(work) is None:
        raise RuntimeError("remote validation work contract is invalid")
    return bash_command(
        remote_validate_script(),
        (remote_stage, str(size), digest, token),
    )


def duplicate_rejecting_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError(f"duplicate JSON key: {key}")
        result[key] = value
    return result


def parse_install_receipt(output: str, payload_sha256: str) -> dict[str, Any]:
    lines = output.splitlines()
    if len(lines) != 1 or not lines[0] or lines[0].strip() != lines[0]:
        raise RuntimeError("remote installer did not return exactly one receipt")
    try:
        receipt = json.loads(lines[0], object_pairs_hook=duplicate_rejecting_object)
    except (json.JSONDecodeError, ValueError) as exc:
        raise RuntimeError("remote installer receipt is invalid JSON") from exc
    expected_keys = {
        "PYTHON_RUNTIME_INSTALL",
        "artifact_sha256",
        "payload_sha256",
        "platform",
        "previous",
        "repeat",
        "rollback_receipt",
        "stable",
        "switched",
        "target",
        "version",
    }
    if not isinstance(receipt, dict) or set(receipt) != expected_keys:
        raise RuntimeError("remote installer receipt has an unexpected schema")
    previous = receipt["previous"]
    previous_valid = previous == "missing" or (
        isinstance(previous, str)
        and re.fullmatch(
            re.escape(RUNTIME_ROOT) + r"/[A-Za-z0-9._+-]+/bin/python3", previous
        )
        is not None
    )
    rollback_receipt = receipt["rollback_receipt"]
    rollback_receipt_valid = rollback_receipt in ("absent", PREVIOUS_TARGET_RECEIPT)
    if (
        receipt["PYTHON_RUNTIME_INSTALL"] != "pass"
        or receipt["artifact_sha256"] != ARTIFACT_SHA256
        or receipt["payload_sha256"] != payload_sha256
        or receipt["platform"] != "linux/amd64"
        or receipt["stable"] != STABLE_PATH
        or receipt["target"] != TARGET_DIRECTORY
        or receipt["version"] != VERSION
        or type(receipt["repeat"]) is not bool
        or type(receipt["switched"]) is not bool
        or not previous_valid
        or not rollback_receipt_valid
        or (receipt["switched"] and rollback_receipt != PREVIOUS_TARGET_RECEIPT)
    ):
        raise RuntimeError("remote installer receipt values do not prove the pinned result")
    return receipt


def parse_remote_validate_receipt(output: str, payload_sha256: str) -> dict[str, Any]:
    lines = output.splitlines()
    if len(lines) != 1 or not lines[0] or lines[0].strip() != lines[0]:
        raise RuntimeError("remote validation did not return exactly one receipt")
    try:
        receipt = json.loads(lines[0], object_pairs_hook=duplicate_rejecting_object)
    except (json.JSONDecodeError, ValueError) as exc:
        raise RuntimeError("remote validation receipt is invalid JSON") from exc
    expected = {
        "PYTHON_RUNTIME_REMOTE_VALIDATE": "pass",
        "payload_sha256": payload_sha256,
        "platform": "linux/amd64",
        "version": VERSION,
    }
    if not isinstance(receipt, dict) or receipt != expected:
        raise RuntimeError("remote validation receipt does not prove the pinned payload")
    return receipt


def stage_marker(remote_stage: str) -> bytes:
    match = REMOTE_STAGE_RE.fullmatch(remote_stage)
    if match is None:
        raise RuntimeError("unreviewed remote Python payload stage path")
    return f"YY_PYTHON_STAGE_V1:{match.group(1)}\n".encode("ascii")


def create_stage_command(remote_stage: str) -> str:
    marker = stage_marker(remote_stage).decode("ascii")
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
        + "; if ! stage_state=$(stat -c '%a|%U|%G|%s' "
        + quoted
        + "); then exit 3; fi; test \"$stage_state\" = '600|root|root|"
        + str(len(marker.encode("ascii")))
        + "'"
    )


def cleanup_stage_command(
    remote_stage: str,
    *,
    ownership_confirmed: bool,
) -> str:
    stage_marker(remote_stage)
    if not ownership_confirmed:
        raise RuntimeError(
            "automatic stage cleanup requires confirmed creation ownership"
        )
    quoted = shlex.quote(remote_stage)
    recovery = (
        "printf "
        + shlex.quote("RECOVERY_REQUIRED=confirmed-python-stage-cleanup\n")
        + " >&2; exit 3"
    )
    command = (
        "set -euo pipefail; if [ ! -e "
        + quoted
        + " ] && [ ! -L "
        + quoted
        + " ]; then exit 0; fi; if ! test -f "
        + quoted
        + " || test -L "
        + quoted
        + "; then "
        + recovery
        + "; fi; if ! stage_state=$(stat -c '%a|%U|%G' "
        + quoted
        + "); then "
        + recovery
        + "; fi; if [ \"$stage_state\" != '600|root|root' ]; then "
        + recovery
        + "; fi; "
    )
    return (
        command
        + "rm -f -- "
        + quoted
        + "; if [ -e "
        + quoted
        + " ] || [ -L "
        + quoted
        + " ]; then "
        + recovery
        + "; fi"
    )


def upload_payload(client: Any, payload: dict[str, Any], remote_path: str) -> None:
    marker = stage_marker(remote_path)
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
            raise RuntimeError("remote Python stage was not prepared as root:root 0600")
        with sftp.file(remote_path, "r") as prepared:
            if prepared.read(len(marker) + 1) != marker:
                raise RuntimeError("remote Python stage marker readback failed")
        with open(str(payload["path"]), "rb") as source:
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
            remote_stat.st_size != int(payload["size"])
            or stat.S_IMODE(remote_stat.st_mode) != 0o600
            or remote_stat.st_uid != 0
            or remote_stat.st_gid != 0
        ):
            raise RuntimeError("remote Python payload stage size or mode readback failed")
    finally:
        sftp.close()
    current = os.lstat(str(payload["path"]))
    if tuple(payload["fingerprint"]) != (
        current.st_dev,
        current.st_ino,
        current.st_size,
        current.st_mtime_ns,
    ):
        raise RuntimeError("derived Python payload changed during upload")


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument("--host", required=True)
    result.add_argument("--port", type=int, default=22)
    result.add_argument("--user", default="root")
    result.add_argument("--known-hosts", required=True)
    result.add_argument("--artifact", required=True)
    mode = result.add_mutually_exclusive_group()
    mode.add_argument("--execute", action="store_true")
    mode.add_argument("--remote-validate", action="store_true")
    result.add_argument("--confirm", default="")
    result.add_argument("--maintenance-confirmed", default="")
    return result


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    if args.user != "root":
        raise RuntimeError("production Python installer is pinned to SSH user root")
    if args.port < 1 or args.port > 65535:
        raise RuntimeError("SSH port is outside the valid range")
    if args.execute and (
        args.confirm != EXECUTE_CONFIRMATION
        or args.maintenance_confirmed != MAINTENANCE_CONFIRMATION
    ):
        raise RuntimeError("execute requires both reviewed confirmation tokens")
    if args.remote_validate and (
        args.confirm != REMOTE_VALIDATE_CONFIRMATION or args.maintenance_confirmed
    ):
        raise RuntimeError("remote validation requires its exact validation confirmation")
    if not args.execute and not args.remote_validate and (
        args.confirm or args.maintenance_confirmed
    ):
        raise RuntimeError("confirmation tokens require an explicit stateful mode")
    password = os.environ.get("YY_SSH_PASSWORD", "")
    if not password:
        raise RuntimeError("YY_SSH_PASSWORD is required and is never accepted on the command line")

    artifact = inspect_artifact(Path(args.artifact))
    print(
        "PYTHON_ARTIFACT_PIN="
        + json.dumps(
            {
                "artifact": ARTIFACT_NAME,
                "artifact_sha256": artifact.sha256,
                "content_manifest_sha256": CONTENT_MANIFEST_SHA256,
                "elf": "ELF64/ET_EXEC/x86_64/static",
                "python_binary_sha256": artifact.python_binary_sha256,
                "version": VERSION,
            },
            sort_keys=True,
            separators=(",", ":"),
        )
    )
    client = connect(args, password)
    remote_stage: str | None = None
    token: str | None = None
    stage_created = False
    payload: dict[str, Any] | None = None
    success_line: str | None = None
    primary_failure: BaseException | None = None
    try:
        run_remote(client, preflight_command(), "Python runtime preflight", password)
        if not args.execute and not args.remote_validate:
            print(
                "[dry-run] pinned source and remote prerequisites passed; no derived payload "
                "upload, extraction, installation, or stable-link switch occurred"
            )
            return 0

        payload = build_derived_payload(artifact)
        token = secrets.token_hex(16)
        remote_stage = f"/tmp/.yiyunying-python-runtime-{VERSION}-{token}.tar.gz"
        run_remote(
            client,
            create_stage_command(remote_stage),
            "Python payload stage creation",
            password,
            timeout=60,
        )
        stage_created = True
        upload_payload(client, payload, remote_stage)
        if args.remote_validate:
            validation_work = (
                f"/tmp/.yiyunying-python-runtime-validate-{VERSION}-{token}"
            )
            try:
                validation_output = run_remote_phased(
                    client,
                    remote_validate_command(remote_stage, payload),
                    "Python runtime remote validation",
                    password,
                    allowed_phases=REMOTE_VALIDATE_FAILURE_PHASES,
                )
                validation_receipt = parse_remote_validate_receipt(
                    validation_output, str(payload["sha256"])
                )
            except BaseException as exc:
                failure_phase = (
                    exc.phase if isinstance(exc, RemotePhaseFailure) else "unavailable"
                )
                recovery_identifiers = {
                    "failure_phase": failure_phase,
                    "remote_stage": remote_stage,
                    "token": token,
                    "work": validation_work,
                }
                raise RecoveryRequired(
                    "remote Python payload validation result uncertain",
                    recovery_identifiers,
                ) from exc
            success_line = (
                "PYTHON_RUNTIME_REMOTE_VALIDATE_RECEIPT="
                + json.dumps(
                    validation_receipt, sort_keys=True, separators=(",", ":")
                )
            )
        else:
            try:
                install_output = run_remote_phased(
                    client,
                    installer_command(remote_stage, payload),
                    "Python runtime install",
                    password,
                )
                receipt = parse_install_receipt(
                    install_output, str(payload["sha256"])
                )
            except BaseException as exc:
                failure_phase = (
                    exc.phase if isinstance(exc, RemotePhaseFailure) else "unavailable"
                )
                recovery_identifiers = {
                    "failure_phase": failure_phase,
                    "link_stage": f"/usr/local/bin/.python3.yiyunying-{token}",
                    "lock": LOCK_DIRECTORY,
                    "receipt": PREVIOUS_TARGET_RECEIPT,
                    "remote_stage": remote_stage,
                    "rollback_link": f"/usr/local/bin/.python3.rollback-{token}",
                    "stable": STABLE_PATH,
                    "target": TARGET_DIRECTORY,
                    "token": token,
                    "work": f"{RUNTIME_ROOT}/.stage-{VERSION_DIRECTORY}-{token}",
                }
                raise RecoveryRequired(
                    "remote Python runtime install result uncertain",
                    recovery_identifiers,
                ) from exc
            success_line = (
                "PYTHON_RUNTIME_RECEIPT="
                + json.dumps(receipt, sort_keys=True, separators=(",", ":"))
            )
    except BaseException as exc:
        primary_failure = exc
    finally:
        cleanup_failure: BaseException | None = None
        close_failure: BaseException | None = None
        local_cleanup_failure: BaseException | None = None
        if remote_stage is not None and stage_created:
            try:
                run_remote(
                    client,
                    cleanup_stage_command(remote_stage, ownership_confirmed=True),
                    "Python payload stage cleanup",
                    password,
                    timeout=60,
                    emit_output=False,
                    require_empty_stdout=True,
                    require_empty_stderr=True,
                )
            except BaseException as exc:
                cleanup_failure = exc
        try:
            client.close()
        except BaseException as exc:
            close_failure = exc
        if payload is not None:
            try:
                remove_derived_payload(payload)
            except BaseException as exc:
                local_cleanup_failure = exc
        uncertainties: list[str] = []
        if remote_stage is not None and not stage_created:
            uncertainties.append("stage_creation_ownership_unconfirmed")
        if cleanup_failure is not None:
            uncertainties.append("remote_stage_cleanup_unconfirmed")
        if close_failure is not None:
            uncertainties.append("ssh_close_unconfirmed")
        if local_cleanup_failure is not None:
            uncertainties.append("local_payload_cleanup_unconfirmed")
        if uncertainties:
            if isinstance(primary_failure, RecoveryRequired):
                reason = primary_failure.reason
                identifiers = primary_failure.identifiers
            else:
                identifiers = {}
                if remote_stage is not None:
                    identifiers = {"remote_stage": remote_stage, "token": token}
                if "stage_creation_ownership_unconfirmed" in uncertainties:
                    reason = "Python payload stage creation ownership was not confirmed"
                elif primary_failure is not None:
                    reason = "primary operation failed and cleanup result is uncertain"
                else:
                    reason = "post-operation cleanup result is uncertain"
            cause = (
                primary_failure
                or cleanup_failure
                or close_failure
                or local_cleanup_failure
            )
            raise RecoveryRequired(
                reason, identifiers, tuple(uncertainties)
            ) from cause
        if primary_failure is not None:
            raise primary_failure
        if success_line is not None:
            print(success_line)

    return 0


def cli(argv: list[str] | None = None) -> int:
    actual_argv = list(sys.argv[1:] if argv is None else argv)
    try:
        return main(actual_argv)
    except SystemExit as exc:
        if exc.code in (None, 0) and any(
            argument in ("-h", "--help") for argument in actual_argv
        ):
            return 0
        failure: BaseException = exc
    except BaseException as exc:
        failure = exc

    password = os.environ.get("YY_SSH_PASSWORD", "")
    detail = sanitize_for_log(failure, (password,))
    if (
        "--execute" in actual_argv or "--remote-validate" in actual_argv
    ) and "RECOVERY_REQUIRED" not in detail:
        detail = "RECOVERY_REQUIRED: production execute ended without proof: " + detail
    print(
        "production Python runtime installation failed: " + detail,
        file=sys.stderr,
    )
    return 1


if __name__ == "__main__":
    raise SystemExit(cli())
