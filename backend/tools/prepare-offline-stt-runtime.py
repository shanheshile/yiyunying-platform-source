#!/usr/bin/env python3
"""Acquire and build the frozen offline STT source bundle.

The default mode is read-only.  The explicit ``--download`` and
``--download-license-evidence`` modes store every response as a unique ``.partial`` file before validating its
exact size and SHA-256, flushing it, and atomically publishing the final file.
``--build`` creates a deterministic, content-addressed source tar after every
artifact, wheel, model, probe, and license record has been revalidated.
"""

from __future__ import annotations

import argparse
import base64
from dataclasses import dataclass
from email.parser import BytesParser
from email.policy import default as email_policy
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import shutil
import stat
import struct
import subprocess
import sys
import tarfile
import tempfile
import time
from typing import Any, BinaryIO, Iterable
from urllib.parse import urlparse
from urllib.request import Request, build_opener
import zipfile


SCRIPT = Path(__file__).resolve()
BACKEND = SCRIPT.parents[1]
REPOSITORY = BACKEND.parent
DEFAULT_MANIFEST = BACKEND / "tools" / "stt" / "offline" / "artifacts.json"
DEFAULT_LOCK = BACKEND / "tools" / "stt" / "offline" / "requirements-linux-x86_64-cp311.lock"
DEFAULT_MODEL_MANIFEST = BACKEND / "tools" / "stt" / "offline" / "model-manifest.json"
DEFAULT_BUILDER_TOOLS = BACKEND / "tools" / "stt" / "offline" / "builder-tools.json"
DEFAULT_LICENSE_EVIDENCE = BACKEND / "tools" / "stt" / "offline" / "license-evidence.json"
THIRD_PARTY_NOTICES_SIZE = 90_601
THIRD_PARTY_NOTICES_SHA256 = "55cd6e0bca728d3d053389310bb8eacdefc95e803fb55d927965ba0ec19a170e"
DEFAULT_OUTPUT = REPOSITORY.parent / ".tools_deps" / "stt"
BUNDLE_FILENAME = "stt-offline-source-bundle-20260718.tar"
BUNDLE_HASH_FILENAME = BUNDLE_FILENAME + ".sha256"
TREE_MANIFEST_FILENAME = "tree-manifest.json"
MAX_LICENSE_BYTES = 32 * 1024 * 1024
MAX_TOTAL_LICENSE_BYTES = 256 * 1024 * 1024
DOWNLOAD_TIMEOUT = 60
DOWNLOAD_RETRIES = 3
CURL_CHUNK_BYTES = 8 * 1024 * 1024
HASH_RE = re.compile(r"^[0-9a-f]{64}$")
SAFE_HOSTS = {
    "files.pythonhosted.org",
    "github.com",
    "huggingface.co",
}
SAFE_HOST_SUFFIXES = (
    ".githubusercontent.com",
    ".hf.co",
    ".xethub.hf.co",
)
LICENSE_BASENAME_RE = re.compile(
    r"^(?:licen[cs]e|copying|notice|copyright|third[_-]?party)(?:[._-].*)?$",
    re.IGNORECASE,
)


@dataclass(frozen=True)
class Artifact:
    category: str
    filename: str
    size: int
    sha256: str
    url: str
    name: str = ""
    version: str = ""
    license: str = ""


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


def fsync_directory(path: Path) -> None:
    if os.name == "nt":
        # Windows directory handles cannot be opened through os.open.  File
        # data is flushed below and MoveFileEx uses WRITE_THROUGH.
        return
    descriptor = os.open(path, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def publish_new_file(partial: Path, destination: Path) -> None:
    if destination.exists() or destination.is_symlink():
        raise RuntimeError(f"refusing to replace existing artifact: {destination.name}")
    if os.name == "nt":
        import ctypes

        move_write_through = 0x00000008
        if not ctypes.windll.kernel32.MoveFileExW(
            str(partial), str(destination), move_write_through
        ):
            raise ctypes.WinError()
    else:
        os.replace(partial, destination)
        fsync_directory(destination.parent)


def atomic_write(path: Path, payload: bytes) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    partial = path.parent / f".{path.name}.{secrets.token_hex(16)}.partial"
    descriptor = os.open(partial, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(descriptor, "wb", closefd=False) as handle:
            handle.write(payload)
            handle.flush()
            os.fsync(handle.fileno())
        os.close(descriptor)
        descriptor = -1
        if path.exists() or path.is_symlink():
            old_size, old_hash = sha256_file(path)
            new_hash = hashlib.sha256(payload).hexdigest()
            if old_size == len(payload) and old_hash == new_hash:
                partial.unlink()
                return
            raise RuntimeError(f"refusing to overwrite changed generated file: {path}")
        publish_new_file(partial, path)
    finally:
        if descriptor >= 0:
            os.close(descriptor)
        if partial.exists():
            partial.unlink()


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
    """Return an absolute input path without allowing a link at its leaf."""
    unresolved = path.expanduser().absolute()
    validate_regular_file(unresolved, label)
    resolved = unresolved.resolve(strict=True)
    if resolved != unresolved:
        raise RuntimeError(f"{label} path traverses a link or reparse point")
    return resolved


def validate_safe_url(url: str) -> None:
    parsed = urlparse(url)
    host = (parsed.hostname or "").lower()
    if (
        parsed.scheme != "https"
        or not host
        or parsed.username is not None
        or parsed.password is not None
        or parsed.fragment
        or not (host in SAFE_HOSTS or any(host.endswith(suffix) for suffix in SAFE_HOST_SUFFIXES))
    ):
        raise RuntimeError(f"artifact URL is outside the reviewed HTTPS hosts: {url}")


def canonical_name(value: str) -> str:
    return re.sub(r"[-_.]+", "-", value).lower()


def load_manifest(path: Path) -> tuple[dict[str, Any], list[Artifact]]:
    validate_regular_file(path, "artifact manifest")
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise RuntimeError("artifact manifest is not valid UTF-8 JSON") from exc
    if data.get("schema_version") != 1:
        raise RuntimeError("unsupported artifact manifest schema")
    target = data.get("target")
    expected_target = {
        "os": "linux",
        "architecture": "x86_64",
        "python": "3.11.15",
        "python_tag": "cp311",
        "minimum_glibc": "2.17",
    }
    if target != expected_target:
        raise RuntimeError("artifact manifest target changed from the reviewed contract")
    if data.get("bundle_id") != "stt-cpython-3.11.15-faster-whisper-1.2.1-20260718":
        raise RuntimeError("artifact manifest bundle id is not reviewed")

    records: list[Artifact] = []
    python = data.get("python", {})
    for key in ("runtime", "license_companion"):
        item = python.get(key, {})
        records.append(artifact_from_json(f"python/{key}", item))
    wheels = data.get("wheels")
    if not isinstance(wheels, list) or len(wheels) != 30:
        raise RuntimeError("artifact manifest must contain exactly 30 wheels")
    names: set[str] = set()
    for item in wheels:
        record = artifact_from_json("wheel", item)
        normalized = canonical_name(record.name)
        if not normalized or normalized in names:
            raise RuntimeError("artifact manifest contains duplicate wheel project names")
        names.add(normalized)
        validate_wheel_filename(record.filename)
        records.append(record)
    if "setuptools" not in names or any(
        canonical_name(item.name) == "setuptools" and item.version != "84.0.0"
        for item in records
    ):
        raise RuntimeError("the reviewed setuptools 84.0.0 security replacement is missing")
    model = data.get("model", {})
    if model.get("repository") != "Systran/faster-whisper-base" or model.get("revision") != "ebe41f70d5b6dfa9166e2c581c45c9c0cfc57b66":
        raise RuntimeError("model repository or revision changed")
    model_files = model.get("files")
    if not isinstance(model_files, list) or [item.get("filename") for item in model_files] != [
        "config.json", "model.bin", "tokenizer.json", "vocabulary.txt"
    ]:
        raise RuntimeError("model manifest must contain the four reviewed regular payloads")
    for item in model_files:
        copy = dict(item)
        copy["name"] = "Systran/faster-whisper-base"
        copy["version"] = model["revision"]
        copy["license"] = model.get("license", "")
        records.append(artifact_from_json("model", copy))

    filenames: set[tuple[str, str]] = set()
    for record in records:
        key = (record.category, record.filename.casefold())
        if key in filenames:
            raise RuntimeError("artifact manifest contains duplicate destination names")
        filenames.add(key)
        validate_safe_url(record.url)
    validate_lock_file(records)
    validate_model_manifest(model)
    return data, records


def load_license_evidence() -> list[Artifact]:
    validate_regular_file(DEFAULT_LICENSE_EVIDENCE, "license evidence manifest")
    data = json.loads(DEFAULT_LICENSE_EVIDENCE.read_text(encoding="utf-8"))
    if (
        data.get("schema_version") != 1
        or data.get("purpose")
        != "license text evidence only; never used as executable or Python installation input"
    ):
        raise RuntimeError("license evidence manifest purpose changed")
    items = data.get("artifacts")
    if not isinstance(items, list) or len(items) != 3:
        raise RuntimeError("license evidence manifest must contain exactly three records")
    records = [artifact_from_json("license-evidence", item) for item in items]
    expected = {
        "ctranslate2": (
            "4.6.2",
            "ctranslate2-v4.6.2-LICENSE",
            1115,
            "54aa79d9fe3c09e67a16dcd95b9e88676405a6ec174efda31036983cf7672ecb",
            "https://raw.githubusercontent.com/OpenNMT/CTranslate2/v4.6.2/LICENSE",
            "MIT",
        ),
        "flatbuffers": (
            "25.12.19",
            "flatbuffers-v25.12.19-LICENSE",
            11358,
            "cfc7749b96f63bd31c3c42b5c471bf756814053e847c10f3eb003417bc523d30",
            "https://raw.githubusercontent.com/google/flatbuffers/v25.12.19/LICENSE",
            "Apache-2.0",
        ),
        "tokenizers": (
            "0.23.1",
            "tokenizers-v0.23.1-LICENSE",
            11357,
            "c71d239df91726fc519c6eb72d318ec65820627232b2f796219e87dcf35d0ab4",
            "https://raw.githubusercontent.com/huggingface/tokenizers/v0.23.1/LICENSE",
            "Apache-2.0",
        ),
    }
    actual = {
        canonical_name(record.name): (
            record.version,
            record.filename,
            record.size,
            record.sha256,
            record.url,
            record.license,
        )
        for record in records
    }
    if actual != expected:
        raise RuntimeError("license evidence identities changed")
    for record in records:
        validate_safe_url(record.url)
    return records


def validate_lock_file(records: list[Artifact]) -> None:
    wheel_records = [record for record in records if record.category == "wheel"]
    expected = {
        canonical_name(record.name): (record.version, record.sha256)
        for record in wheel_records
    }
    actual: dict[str, tuple[str, str]] = {}
    pattern = re.compile(
        r"^([A-Za-z0-9_.-]+)==([^\s]+) --hash=sha256:([0-9a-f]{64})$"
    )
    validate_regular_file(DEFAULT_LOCK, "offline STT lock")
    for raw_line in DEFAULT_LOCK.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue
        match = pattern.fullmatch(line)
        if match is None:
            raise RuntimeError("offline STT lock contains a non-exact requirement")
        name = canonical_name(match.group(1))
        if name in actual:
            raise RuntimeError("offline STT lock contains a duplicate project")
        actual[name] = (match.group(2), match.group(3))
    if actual != expected:
        raise RuntimeError("offline STT lock differs from the frozen 30-wheel manifest")


def validate_model_manifest(model: dict[str, Any], path: Path = DEFAULT_MODEL_MANIFEST) -> None:
    validate_regular_file(path, "model manifest")
    data = json.loads(path.read_text(encoding="utf-8"))
    expected = {
        "schema_version": 1,
        "repository": model.get("repository"),
        "revision": model.get("revision"),
        "license": model.get("license"),
        "materialization": "four regular files only; no cache, refs, lock, symlink, hardlink, or special entry",
        "files": [
            {
                "filename": item.get("filename"),
                "size": item.get("size"),
                "sha256": item.get("sha256"),
            }
            for item in model.get("files", [])
        ],
    }
    if data != expected:
        raise RuntimeError("model manifest differs from the frozen artifact contract")


def artifact_from_json(category: str, item: Any) -> Artifact:
    if not isinstance(item, dict):
        raise RuntimeError(f"invalid {category} artifact record")
    filename = item.get("filename")
    size = item.get("size")
    digest = item.get("sha256")
    url = item.get("url")
    if (
        not isinstance(filename, str)
        or not filename
        or PurePosixPath(filename).name != filename
        or "\\" in filename
        or "\x00" in filename
        or not isinstance(size, int)
        or size <= 0
        or not isinstance(digest, str)
        or not HASH_RE.fullmatch(digest)
        or not isinstance(url, str)
    ):
        raise RuntimeError(f"invalid {category} artifact identity")
    return Artifact(
        category=category,
        filename=filename,
        size=size,
        sha256=digest,
        url=url,
        name=str(item.get("name", "")),
        version=str(item.get("version", "")),
        license=str(item.get("license", "")),
    )


def validate_wheel_filename(filename: str) -> None:
    lower = filename.lower()
    if not lower.endswith(".whl") or any(marker in lower for marker in ("win32", "win_amd64", "macosx", "musllinux")):
        raise RuntimeError(f"non-reviewed wheel platform: {filename}")
    if not (
        lower.endswith("-py3-none-any.whl")
        or lower.endswith("-py2.py3-none-any.whl")
        or ("manylinux" in lower and ("cp311-cp311" in lower or "-abi3-" in lower))
    ):
        raise RuntimeError(f"wheel is not CPython 3.11/manylinux_2_17 compatible: {filename}")


def artifact_destination(source_root: Path, record: Artifact) -> Path:
    if record.category == "wheel":
        return source_root / "wheelhouse" / record.filename
    if record.category == "model":
        return source_root / "model" / "base" / record.filename
    if record.category == "license-evidence":
        return source_root / "evidence" / "licenses" / record.filename
    return source_root / "python" / record.filename


def verify_artifact(path: Path, record: Artifact) -> None:
    metadata = validate_regular_file(path, record.filename)
    if metadata.st_size != record.size:
        raise RuntimeError(f"size mismatch for {record.filename}")
    size, digest = sha256_file(path)
    if size != record.size or not secrets.compare_digest(digest, record.sha256):
        raise RuntimeError(f"SHA-256 mismatch for {record.filename}")


def validate_artifact_source_layout(source_root: Path, records: list[Artifact]) -> None:
    expected_files = {
        artifact_destination(source_root, record).relative_to(source_root).as_posix()
        for record in records
    }
    expected_directories = {"."}
    for relative in expected_files:
        parent = PurePosixPath(relative).parent
        while str(parent) != ".":
            expected_directories.add(parent.as_posix())
            parent = parent.parent
    actual_files: set[str] = set()
    actual_directories = {"."}
    for path in source_root.rglob("*"):
        relative = path.relative_to(source_root).as_posix()
        metadata = os.lstat(path)
        reparse = getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0)
        if path.is_symlink() or (reparse and getattr(metadata, "st_file_attributes", 0) & reparse):
            raise RuntimeError(f"artifact source contains a link/reparse point: {relative}")
        if stat.S_ISDIR(metadata.st_mode):
            actual_directories.add(relative)
        elif stat.S_ISREG(metadata.st_mode) and metadata.st_nlink == 1:
            actual_files.add(relative)
        else:
            raise RuntimeError(f"artifact source contains a special or hardlinked file: {relative}")
    if actual_files != expected_files or actual_directories != expected_directories:
        extra = sorted(actual_files - expected_files)
        missing = sorted(expected_files - actual_files)
        raise RuntimeError(
            "artifact source layout is not closed "
            f"(extra={extra[:3]}, missing={missing[:3]})"
        )


def curl_download(record: Artifact, output: BinaryIO, executable: str) -> None:
    for start in range(0, record.size, CURL_CHUNK_BYTES):
        end = min(record.size - 1, start + CURL_CHUNK_BYTES - 1)
        expected = end - start + 1
        result = subprocess.run(
            [
                executable,
                "--fail",
                "--location",
                "--silent",
                "--show-error",
                "--ipv4",
                "--http1.1",
                "--connect-timeout", "20",
                "--max-time", "180",
                "--speed-time", "60",
                "--speed-limit", "1",
                "--proto", "=https",
                "--proto-redir", "=https",
                "--header", "Accept-Encoding: identity",
                "--user-agent", "yiyunying-offline-stt-builder/1",
                "--range", f"{start}-{end}",
                "--write-out", "%{stderr}YY_TRANSFER:%{http_code}|%{size_download}|%{url_effective}\\n",
                record.url,
            ],
            stdout=output,
            stderr=subprocess.PIPE,
            timeout=200,
            check=False,
        )
        diagnostic = result.stderr.decode("utf-8", "replace")
        transfers = [
            line.removeprefix("YY_TRANSFER:")
            for line in diagnostic.splitlines()
            if line.startswith("YY_TRANSFER:")
        ]
        if result.returncode != 0:
            detail = "\n".join(
                line for line in diagnostic.splitlines()
                if not line.startswith("YY_TRANSFER:")
            )
            raise RuntimeError(
                f"curl failed ({result.returncode}) for {record.filename}: {detail[:500]}"
            )
        if len(transfers) != 1:
            raise RuntimeError(f"curl returned no unique transfer receipt for {record.filename}")
        fields = transfers[0].split("|", 2)
        if len(fields) != 3:
            raise RuntimeError(f"curl transfer receipt is malformed for {record.filename}")
        status, downloaded_text, effective_url = fields
        try:
            downloaded = int(float(downloaded_text))
        except ValueError as exc:
            raise RuntimeError(f"curl byte count is invalid for {record.filename}") from exc
        full_response_allowed = start == 0 and expected == record.size and status == "200"
        if (status != "206" and not full_response_allowed) or downloaded != expected:
            raise RuntimeError(
                f"curl range identity mismatch for {record.filename}: status={status}, bytes={downloaded}"
            )
        validate_safe_url(effective_url)
        output.flush()
        if os.fstat(output.fileno()).st_size != end + 1:
            raise RuntimeError(f"curl range did not append exactly for {record.filename}")


def download_artifact(record: Artifact, destination: Path) -> None:
    destination.parent.mkdir(parents=True, exist_ok=True)
    if destination.exists() or destination.is_symlink():
        verify_artifact(destination, record)
        print(f"verified existing {record.filename}")
        return
    error: BaseException | None = None
    for attempt in range(1, DOWNLOAD_RETRIES + 1):
        partial = destination.parent / f".{record.filename}.{secrets.token_hex(16)}.partial"
        descriptor = -1
        try:
            descriptor = os.open(partial, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            print(
                f"downloading {record.filename} attempt {attempt}/{DOWNLOAD_RETRIES}",
                flush=True,
            )
            request = Request(
                record.url,
                headers={
                    "User-Agent": "yiyunying-offline-stt-builder/1",
                    "Accept-Encoding": "identity",
                    "Connection": "close",
                },
            )
            size = 0
            with os.fdopen(descriptor, "wb", closefd=False) as output:
                curl = shutil.which("curl.exe" if os.name == "nt" else "curl")
                if curl:
                    curl_download(record, output, curl)
                else:
                    with build_opener().open(request, timeout=DOWNLOAD_TIMEOUT) as response:
                        validate_safe_url(response.geturl())
                        content_length = response.headers.get("Content-Length")
                        if content_length is not None and int(content_length) != record.size:
                            raise RuntimeError(
                                f"Content-Length mismatch for {record.filename}: {content_length}"
                            )
                        while True:
                            chunk = response.read(1024 * 1024)
                            if not chunk:
                                break
                            size += len(chunk)
                            if size > record.size:
                                raise RuntimeError(f"download exceeded pinned size for {record.filename}")
                            output.write(chunk)
                output.flush()
                os.fsync(output.fileno())
            os.close(descriptor)
            descriptor = -1
            size, digest = sha256_file(partial)
            if size != record.size or not secrets.compare_digest(digest, record.sha256):
                raise RuntimeError(f"downloaded identity mismatch for {record.filename}")
            publish_new_file(partial, destination)
            verify_artifact(destination, record)
            print(f"downloaded and verified {record.filename}")
            return
        except Exception as exc:
            error = exc
            print(
                f"download attempt failed for {record.filename}: {type(exc).__name__}: {exc}",
                file=sys.stderr,
                flush=True,
            )
            if descriptor >= 0:
                os.close(descriptor)
            if partial.exists():
                partial.unlink()
            if attempt < DOWNLOAD_RETRIES:
                time.sleep(min(attempt * 2, 5))
    raise RuntimeError(f"failed to download {record.filename} after {DOWNLOAD_RETRIES} attempts") from error


def safe_archive_name(name: str) -> bool:
    pure = PurePosixPath(name)
    return bool(
        name
        and len(name.encode("utf-8")) <= 4096
        and not pure.is_absolute()
        and ".." not in pure.parts
        and "\\" not in name
        and "\x00" not in name
    )


def safe_link_target(member_name: str, target_name: str) -> bool:
    if not target_name or "\\" in target_name or "\x00" in target_name:
        return False
    target = PurePosixPath(target_name)
    if target.is_absolute():
        return False
    combined = PurePosixPath(member_name).parent.joinpath(target)
    depth = 0
    for part in combined.parts:
        if part in ("", "."):
            continue
        if part == "..":
            depth -= 1
        else:
            depth += 1
        if depth < 0:
            return False
    return True


def is_top_level_wheel_metadata(name: str) -> bool:
    parts = PurePosixPath(name).parts
    return bool(
        len(parts) == 2
        and parts[0].lower().endswith(".dist-info")
        and parts[1] == "METADATA"
    )


def wheel_license_payload(record: Artifact, wheel: Path) -> dict[str, bytes]:
    result: dict[str, bytes] = {}
    with zipfile.ZipFile(wheel, "r") as archive:
        infos = archive.infolist()
        names = [item.filename for item in infos]
        if len(names) != len(set(names)):
            raise RuntimeError(f"wheel contains duplicate members: {record.filename}")
        metadata_candidates: list[zipfile.ZipInfo] = []
        for item in infos:
            if not safe_archive_name(item.filename):
                raise RuntimeError(f"wheel contains unsafe member: {record.filename}")
            unix_type = (item.external_attr >> 16) & 0o170000
            if unix_type not in (0, stat.S_IFREG, stat.S_IFDIR):
                raise RuntimeError(f"wheel contains link or special entry: {record.filename}")
            if item.file_size > 512 * 1024 * 1024:
                raise RuntimeError(f"wheel member exceeds safety limit: {record.filename}")
            if is_top_level_wheel_metadata(item.filename):
                metadata_candidates.append(item)
            basename = PurePosixPath(item.filename).name
            in_license_directory = ".dist-info/licenses/" in item.filename.lower()
            if not item.is_dir() and (in_license_directory or LICENSE_BASENAME_RE.match(basename)):
                payload = archive.read(item)
                if len(payload) > MAX_LICENSE_BYTES:
                    raise RuntimeError(f"wheel license exceeds safety limit: {record.filename}")
                result[item.filename] = payload
        if len(metadata_candidates) != 1:
            raise RuntimeError(f"wheel must contain exactly one METADATA: {record.filename}")
        metadata = BytesParser(policy=email_policy).parsebytes(archive.read(metadata_candidates[0]))
        if canonical_name(str(metadata.get("Name", ""))) != canonical_name(record.name) or str(metadata.get("Version", "")) != record.version:
            raise RuntimeError(f"wheel METADATA identity mismatch: {record.filename}")
        result[metadata_candidates[0].filename] = archive.read(metadata_candidates[0])
    return result


def wheel_metadata(record: Artifact, wheel: Path):
    with zipfile.ZipFile(wheel, "r") as archive:
        candidates = [item for item in archive.infolist() if is_top_level_wheel_metadata(item.filename)]
        if len(candidates) != 1:
            raise RuntimeError(f"wheel must contain exactly one METADATA: {record.filename}")
        metadata = BytesParser(policy=email_policy).parsebytes(archive.read(candidates[0]))
    if canonical_name(str(metadata.get("Name", ""))) != canonical_name(record.name):
        raise RuntimeError(f"wheel METADATA project changed: {record.filename}")
    if str(metadata.get("Version", "")) != record.version:
        raise RuntimeError(f"wheel METADATA version changed: {record.filename}")
    return metadata


def builder_zstandard_record() -> Artifact:
    validate_regular_file(DEFAULT_BUILDER_TOOLS, "builder tools manifest")
    data = json.loads(DEFAULT_BUILDER_TOOLS.read_text(encoding="utf-8"))
    if (
        data.get("schema_version") != 1
        or data.get("purpose")
        != "trusted-workstation license extraction only; builder wheel never included in or executed on production"
    ):
        raise RuntimeError("builder tools manifest purpose changed")
    record = artifact_from_json("builder/zstandard", data.get("zstandard"))
    if (
        record.name != "zstandard"
        or record.version != "0.25.0"
        or record.license != "BSD-3-Clause"
        or record.filename != "zstandard-0.25.0-cp312-cp312-win_amd64.whl"
        or record.size != 506_276
        or record.sha256 != "ffef5a74088f1e09947aecf91011136665152e0b4b359c42be3373897fb39b01"
    ):
        raise RuntimeError("builder zstandard identity changed")
    validate_safe_url(record.url)
    return record


def extract_builder_wheel(wheel: Path, destination: Path, record: Artifact) -> None:
    verify_artifact(wheel, record)
    with zipfile.ZipFile(wheel, "r") as archive:
        infos = archive.infolist()
        names = [item.filename for item in infos]
        if len(names) != len(set(names)):
            raise RuntimeError("builder wheel contains duplicate paths")
        for item in infos:
            if not safe_archive_name(item.filename):
                raise RuntimeError("builder wheel contains an unsafe path")
            unix_type = (item.external_attr >> 16) & 0o170000
            if unix_type not in (0, stat.S_IFREG, stat.S_IFDIR):
                raise RuntimeError("builder wheel contains a link or special entry")
            if item.file_size > 64 * 1024 * 1024:
                raise RuntimeError("builder wheel member exceeds the safety limit")
            path = destination / Path(*PurePosixPath(item.filename).parts)
            if item.is_dir():
                path.mkdir(parents=True, exist_ok=True)
                continue
            path.parent.mkdir(parents=True, exist_ok=True)
            descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(descriptor, "wb") as output:
                with archive.open(item, "r") as source:
                    shutil.copyfileobj(source, output, length=1024 * 1024)
                output.flush()
                os.fsync(output.fileno())


ZSTANDARD_COMPANION_HELPER = r'''
import base64,json,os,re,sys,tarfile
from pathlib import PurePosixPath
sys.dont_write_bytecode=True
sys.path.insert(0,sys.argv[1])
import zstandard
if zstandard.__version__!="0.25.0": raise RuntimeError("zstandard version mismatch")
LICENSE=re.compile(r"^(?:licen[cs]e|copying|notice|copyright|third[_-]?party)(?:[._-].*)?$",re.I)
def safe(name):
 p=PurePosixPath(name)
 return bool(name and len(name.encode("utf-8"))<=4096 and not p.is_absolute() and ".." not in p.parts and "\\" not in name and "\x00" not in name)
def safe_link(member,target):
 if not target or "\\" in target or "\x00" in target or PurePosixPath(target).is_absolute(): return False
 parts=[]
 for part in PurePosixPath(member).parent.joinpath(PurePosixPath(target)).parts:
  if part in ("","."): continue
  if part=="..":
   if not parts: return False
   parts.pop()
  else: parts.append(part)
 return bool(parts)
result={};seen=set();total=0
with open(sys.argv[2],"rb") as raw:
 with zstandard.ZstdDecompressor().stream_reader(raw) as reader:
  with tarfile.open(fileobj=reader,mode="r|") as archive:
   for member in archive:
    if not safe(member.name) or member.name in seen: raise RuntimeError("unsafe or duplicate companion path")
    seen.add(member.name)
    if member.issym() or member.islnk():
     if not safe_link(member.name,member.linkname): raise RuntimeError("escaping companion link")
    elif not (member.isfile() or member.isdir()): raise RuntimeError("special companion member")
    basename=PurePosixPath(member.name).name
    if member.isfile() and (basename=="PYTHON.json" or LICENSE.match(basename)):
     if member.size>33554432: raise RuntimeError("companion evidence member too large")
     source=archive.extractfile(member)
     if source is None: raise RuntimeError("companion evidence unreadable")
     payload=source.read();total+=len(payload)
     if total>268435456: raise RuntimeError("companion evidence aggregate too large")
     result[member.name]=base64.b64encode(payload).decode("ascii")
payload=(json.dumps(result,sort_keys=True,separators=(",",":"))+"\n").encode("utf-8")
with open(sys.argv[3],"xb") as output:
 output.write(payload);output.flush();os.fsync(output.fileno())
'''


def companion_payload_with_builder(companion: Path, builder_wheel: Path) -> dict[str, bytes]:
    record = builder_zstandard_record()
    expanded = trusted_input_file(builder_wheel, "builder-only zstandard wheel")
    temporary = tempfile.TemporaryDirectory(
        prefix="yiyunying-zstandard-builder-",
        dir=str(companion.parent),
    )
    root = Path(temporary.name)
    site = root / "site-packages"
    site.mkdir(mode=0o700)
    output = root / "companion-evidence.json"
    try:
        extract_builder_wheel(expanded, site, record)
        environment = {
            "PATH": os.environ.get("PATH", ""),
            "SystemRoot": os.environ.get("SystemRoot", r"C:\Windows"),
            "PYTHONDONTWRITEBYTECODE": "1",
            "PYTHONNOUSERSITE": "1",
        }
        result = subprocess.run(
            [
                sys.executable,
                "-I",
                "-B",
                "-c",
                ZSTANDARD_COMPANION_HELPER,
                str(site),
                str(companion),
                str(output),
            ],
            env=environment,
            capture_output=True,
            timeout=240,
            check=False,
        )
        if result.returncode != 0 or result.stdout or result.stderr:
            raise RuntimeError(
                "frozen zstandard builder failed to read the Python companion: "
                + (result.stderr or result.stdout).decode("utf-8", "replace")[:1000]
            )
        metadata = validate_regular_file(output, "builder companion evidence")
        if metadata.st_size > 384 * 1024 * 1024:
            raise RuntimeError("builder companion evidence output exceeds the safety limit")
        encoded = json.loads(output.read_text(encoding="utf-8"))
        if not isinstance(encoded, dict):
            raise RuntimeError("builder companion evidence output is not an object")
        payloads: dict[str, bytes] = {}
        for name, value in encoded.items():
            if not isinstance(name, str) or not safe_archive_name(name) or not isinstance(value, str):
                raise RuntimeError("builder companion evidence output identity is invalid")
            payloads[name] = base64.b64decode(value, validate=True)
        return payloads
    finally:
        temporary.cleanup()


def generate_dependency_closure(source_root: Path, records: list[Artifact]) -> dict[str, Any]:
    wheel_records = [record for record in records if record.category == "wheel"]
    packaging_record = next(
        (record for record in wheel_records if canonical_name(record.name) == "packaging"),
        None,
    )
    if packaging_record is None or packaging_record.version != "26.2":
        raise RuntimeError("frozen packaging 26.2 wheel is required for dependency validation")
    packaging_wheel = artifact_destination(source_root, packaging_record)
    # A previously imported ambient package must not satisfy the validator.
    # Purge that namespace, then prove every loaded packaging module originates
    # inside the hash-pinned wheel zip.
    for module_name in tuple(sys.modules):
        if module_name == "packaging" or module_name.startswith("packaging."):
            del sys.modules[module_name]
    sys.path.insert(0, str(packaging_wheel))
    try:
        import packaging as packaging_package  # type: ignore[import-not-found]
        from packaging import __version__ as packaging_version  # type: ignore[import-not-found]
        from packaging.markers import default_environment  # type: ignore[import-not-found]
        from packaging.requirements import Requirement  # type: ignore[import-not-found]
        from packaging.specifiers import SpecifierSet  # type: ignore[import-not-found]
        from packaging.version import Version  # type: ignore[import-not-found]
    finally:
        try:
            sys.path.remove(str(packaging_wheel))
        except ValueError:
            pass
    if packaging_version != "26.2":
        raise RuntimeError("dependency validator did not load the frozen packaging 26.2 wheel")
    expected_origin = str(packaging_wheel).replace("\\", "/") + "/"
    loaded_origins = {
        name: str(getattr(module, "__file__", "")).replace("\\", "/")
        for name, module in sys.modules.items()
        if name == "packaging" or name.startswith("packaging.")
    }
    if (
        packaging_package.__name__ != "packaging"
        or not loaded_origins
        or any(not origin.startswith(expected_origin) for origin in loaded_origins.values())
    ):
        raise RuntimeError("dependency validator imported packaging outside the frozen wheel")

    environment = default_environment()
    environment.update({
        "implementation_name": "cpython",
        "implementation_version": "3.11.15",
        "os_name": "posix",
        "platform_machine": "x86_64",
        "platform_python_implementation": "CPython",
        "platform_release": "",
        "platform_system": "Linux",
        "platform_version": "",
        "python_full_version": "3.11.15",
        "python_version": "3.11",
        "sys_platform": "linux",
        "extra": "",
    })
    pinned = {canonical_name(record.name): record.version for record in wheel_records}
    components: list[dict[str, Any]] = []
    active_edges = 0
    for record in sorted(wheel_records, key=lambda item: canonical_name(item.name)):
        metadata = wheel_metadata(record, artifact_destination(source_root, record))
        requires_python = str(metadata.get("Requires-Python", ""))
        if requires_python and Version("3.11.15") not in SpecifierSet(requires_python):
            raise RuntimeError(f"wheel excludes Python 3.11.15: {record.filename}")
        dependencies: list[dict[str, Any]] = []
        for raw in metadata.get_all("Requires-Dist", []):
            requirement = Requirement(str(raw))
            if requirement.url is not None:
                raise RuntimeError(f"wheel contains a direct URL dependency: {record.filename}")
            active = requirement.marker is None or requirement.marker.evaluate(environment=environment)
            dependency_name = canonical_name(requirement.name)
            pinned_version = pinned.get(dependency_name)
            if active:
                active_edges += 1
                if pinned_version is None:
                    raise RuntimeError(
                        f"active dependency is absent from the frozen wheelhouse: {record.name} -> {requirement.name}"
                    )
                if requirement.specifier and Version(pinned_version) not in requirement.specifier:
                    raise RuntimeError(
                        f"pinned dependency violates METADATA: {record.name} -> {raw}"
                    )
            dependencies.append({
                "raw": str(raw),
                "name": dependency_name,
                "specifier": str(requirement.specifier),
                "marker": str(requirement.marker or ""),
                "active": active,
                "pinned_version": pinned_version if active else None,
            })
        components.append({
            "name": canonical_name(record.name),
            "version": record.version,
            "requires_python": requires_python,
            "dependencies": dependencies,
        })
    return {
        "schema_version": 1,
        "target": "linux-x86_64-cpython-3.11.15-glibc2.17",
        "marker_environment": environment,
        "component_count": len(components),
        "active_edge_count": active_edges,
        "components": components,
    }


def runtime_license_payload(runtime: Path) -> dict[str, bytes]:
    result: dict[str, bytes] = {}
    with tarfile.open(runtime, "r:gz") as archive:
        seen: set[str] = set()
        for member in archive:
            if not safe_archive_name(member.name) or member.name in seen:
                raise RuntimeError("Python runtime archive contains an unsafe or duplicate path")
            seen.add(member.name)
            if member.issym() or member.islnk():
                if not safe_link_target(member.name, member.linkname):
                    raise RuntimeError("Python runtime archive contains an escaping link")
            elif not (member.isfile() or member.isdir()):
                raise RuntimeError("Python runtime archive contains a special entry")
            basename = PurePosixPath(member.name).name
            if member.isfile() and LICENSE_BASENAME_RE.match(basename):
                if member.size > MAX_LICENSE_BYTES:
                    raise RuntimeError("Python runtime license exceeds safety limit")
                source = archive.extractfile(member)
                if source is None:
                    raise RuntimeError("Python runtime license cannot be read")
                result[member.name] = source.read()
    if not result:
        raise RuntimeError("Python runtime archive contains no license evidence")
    return result


def companion_license_payload(
    companion: Path,
    builder_wheel: Path | None,
) -> dict[str, bytes]:
    """Read only license metadata from the full zstd archive.

    Python 3.14's standard library may decompress this format directly.
    Earlier interpreters must receive the separately frozen builder-only
    ``zstandard`` wheel.  Ambient site packages and system tar programs are
    deliberately outside the trust boundary.
    """
    try:
        from compression import zstd as stdlib_zstd  # type: ignore[attr-defined]
    except ImportError:
        stdlib_zstd = None
    result: dict[str, bytes] = {}
    if stdlib_zstd is not None:
        reader: Any = stdlib_zstd.open(companion, "rb")
        try:
            with tarfile.open(fileobj=reader, mode="r|") as archive:
                seen: set[str] = set()
                for member in archive:
                    if not safe_archive_name(member.name) or member.name in seen:
                        raise RuntimeError("Python companion contains an unsafe or duplicate path")
                    seen.add(member.name)
                    if member.issym() or member.islnk():
                        if not safe_link_target(member.name, member.linkname):
                            raise RuntimeError("Python companion contains an escaping link")
                    elif not (member.isfile() or member.isdir()):
                        raise RuntimeError("Python companion contains a special entry")
                    basename = PurePosixPath(member.name).name
                    wanted = basename == "PYTHON.json" or LICENSE_BASENAME_RE.match(basename)
                    if member.isfile() and wanted:
                        if member.size > MAX_LICENSE_BYTES:
                            raise RuntimeError("Python companion license exceeds safety limit")
                        source = archive.extractfile(member)
                        if source is None:
                            raise RuntimeError("Python companion license cannot be read")
                        result[member.name] = source.read()
        finally:
            reader.close()
    elif builder_wheel is not None:
        result = companion_payload_with_builder(companion, builder_wheel)
    else:
        raise RuntimeError(
            "reading the full Python license companion requires Python 3.14 compression.zstd "
            "or --zstandard-wheel pointing to the reviewed builder-only wheel"
        )
    if "python/PYTHON.json" not in result and not any(name.endswith("/PYTHON.json") for name in result):
        raise RuntimeError("Python companion does not expose PYTHON.json")
    if not any(LICENSE_BASENAME_RE.match(PurePosixPath(name).name) for name in result):
        raise RuntimeError("Python companion exposes no license text")
    if sum(len(value) for value in result.values()) > MAX_TOTAL_LICENSE_BYTES:
        raise RuntimeError("Python companion license payload exceeds aggregate limit")
    return result


def safe_license_relative(source: str) -> Path:
    pure = PurePosixPath(source)
    cleaned = [re.sub(r"[^A-Za-z0-9._-]+", "_", part) for part in pure.parts]
    if not cleaned or any(part in ("", ".", "..") for part in cleaned):
        raise RuntimeError("invalid license output path")
    return Path(*cleaned)


def write_license_set(root: Path, component: str, payloads: dict[str, bytes]) -> list[dict[str, Any]]:
    records: list[dict[str, Any]] = []
    for source_name, payload in sorted(payloads.items()):
        relative = Path("licenses") / component / safe_license_relative(source_name)
        atomic_write(root / relative, payload)
        records.append({
            "component": component,
            "source_member": source_name,
            "path": relative.as_posix(),
            "size": len(payload),
            "sha256": hashlib.sha256(payload).hexdigest(),
        })
    return records


def probe_bytes() -> bytes:
    samples = b"".join(
        struct.pack("<h", 8192 if ((index // 20) % 2 == 0) else -8192)
        for index in range(16000)
    )
    return (
        b"RIFF"
        + struct.pack("<I", 36 + len(samples))
        + b"WAVEfmt "
        + struct.pack("<IHHIIHH", 16, 1, 1, 16000, 32000, 2, 16)
        + b"data"
        + struct.pack("<I", len(samples))
        + samples
    )


def copy_contract_files(source_root: Path) -> None:
    for source in (
        DEFAULT_MANIFEST,
        DEFAULT_LOCK,
        DEFAULT_MODEL_MANIFEST,
        DEFAULT_BUILDER_TOOLS,
        DEFAULT_LICENSE_EVIDENCE,
    ):
        validate_regular_file(source, source.name)
        payload = source.read_bytes()
        atomic_write(source_root / "metadata" / source.name, payload)


def generate_evidence(
    source_root: Path,
    manifest: dict[str, Any],
    records: list[Artifact],
    builder_wheel: Path | None,
) -> None:
    license_records: list[dict[str, Any]] = []
    wheel_records = [record for record in records if record.category == "wheel"]
    missing_embedded_license: set[str] = set()
    for record in wheel_records:
        wheel = artifact_destination(source_root, record)
        payloads = wheel_license_payload(record, wheel)
        if not any(not is_top_level_wheel_metadata(name) for name in payloads):
            missing_embedded_license.add(canonical_name(record.name))
        license_records.extend(
            write_license_set(
                source_root,
                "wheels/" + canonical_name(record.name) + "-" + record.version,
                payloads,
            )
        )
    evidence_records = [record for record in records if record.category == "license-evidence"]
    evidence_names = {canonical_name(record.name) for record in evidence_records}
    if evidence_names != missing_embedded_license:
        raise RuntimeError(
            "external license evidence set differs from wheels lacking embedded license text"
        )
    for record in evidence_records:
        payload = artifact_destination(source_root, record).read_bytes()
        license_records.extend(
            write_license_set(
                source_root,
                "wheels/" + canonical_name(record.name) + "-" + record.version + "/upstream",
                {record.filename: payload},
            )
        )
    runtime_record = next(record for record in records if record.category == "python/runtime")
    companion_record = next(record for record in records if record.category == "python/license_companion")
    builder_record = builder_zstandard_record()
    if builder_wheel is not None:
        builder_wheel = trusted_input_file(builder_wheel, "builder-only zstandard wheel")
        verify_artifact(builder_wheel, builder_record)
        license_records.extend(
            write_license_set(
                source_root,
                "builder-tools/zstandard-0.25.0",
                wheel_license_payload(builder_record, builder_wheel),
            )
        )
    license_records.extend(
        write_license_set(
            source_root,
            "python-runtime",
            runtime_license_payload(artifact_destination(source_root, runtime_record)),
        )
    )
    license_records.extend(
        write_license_set(
            source_root,
            "python-license-companion",
            companion_license_payload(
                artifact_destination(source_root, companion_record),
                builder_wheel,
            ),
        )
    )
    declared = {
        "schema_version": 1,
        "note": "Declared licenses are evidence, not legal advice. Extracted license files remain authoritative.",
        "components": [
            {
                "name": record.name or record.filename,
                "version": record.version,
                "filename": record.filename,
                "license": record.license,
                "sha256": record.sha256,
            }
            for record in records
            if record.category != "license-evidence"
        ],
        "license_evidence": [
            {
                "name": record.name,
                "version": record.version,
                "filename": record.filename,
                "license": record.license,
                "size": record.size,
                "sha256": record.sha256,
                "url": record.url,
                "role": "non-executable upstream license text",
            }
            for record in evidence_records
        ],
        "model": {
            "repository": manifest["model"]["repository"],
            "revision": manifest["model"]["revision"],
            "license": manifest["model"]["license"],
        },
        "builder_tools": {
            "purpose": "trusted workstation only; the builder wheel is excluded from the production payload",
            "components": [{
                "name": builder_record.name,
                "version": builder_record.version,
                "filename": builder_record.filename,
                "license": builder_record.license,
                "sha256": builder_record.sha256,
                "license_extracted": builder_wheel is not None,
            }],
        },
        "extracted": license_records,
    }
    notices = canonical_json(declared)
    if (
        len(notices) != THIRD_PARTY_NOTICES_SIZE
        or hashlib.sha256(notices).hexdigest() != THIRD_PARTY_NOTICES_SHA256
    ):
        raise RuntimeError("generated third-party notices differ from the reviewed exact lock")
    atomic_write(source_root / "licenses" / "THIRD_PARTY_NOTICES.json", notices)
    dependency_closure = generate_dependency_closure(source_root, records)
    atomic_write(
        source_root / "metadata" / "dependency-closure.json",
        canonical_json(dependency_closure),
    )

    probe = probe_bytes()
    expected = manifest["probe"]
    if len(probe) != expected["size"] or hashlib.sha256(probe).hexdigest() != expected["sha256"]:
        raise RuntimeError("deterministic STT probe does not match the reviewed contract")
    atomic_write(source_root / "probe" / expected["filename"], probe)
    copy_contract_files(source_root)


def iter_regular_tree(root: Path, excluded: set[str] | None = None) -> Iterable[tuple[Path, Path]]:
    excluded = excluded or set()
    for path in sorted(root.rglob("*"), key=lambda item: item.relative_to(root).as_posix()):
        relative = path.relative_to(root)
        if relative.as_posix() in excluded:
            continue
        metadata = os.lstat(path)
        reparse = getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0)
        if path.is_symlink() or (reparse and getattr(metadata, "st_file_attributes", 0) & reparse):
            raise RuntimeError(f"source bundle contains a link/reparse point: {relative}")
        if path.is_dir():
            continue
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
            raise RuntimeError(f"source bundle contains a non-unique regular file: {relative}")
        yield relative, path


def build_tree_manifest(source_root: Path, bundle_id: str) -> dict[str, Any]:
    records: list[dict[str, Any]] = []
    excluded = {TREE_MANIFEST_FILENAME, BUNDLE_FILENAME, BUNDLE_HASH_FILENAME}
    for relative, path in iter_regular_tree(source_root, excluded):
        size, digest = sha256_file(path)
        records.append({"path": relative.as_posix(), "size": size, "sha256": digest})
    return {
        "schema_version": 1,
        "bundle_id": bundle_id,
        "file_count": len(records),
        "payload_size": sum(item["size"] for item in records),
        "files": records,
    }


def add_tar_directory(archive: tarfile.TarFile, name: str) -> None:
    info = tarfile.TarInfo(name.rstrip("/") + "/")
    info.type = tarfile.DIRTYPE
    info.mode = 0o755
    info.uid = 0
    info.gid = 0
    info.uname = "root"
    info.gname = "root"
    info.mtime = 0
    archive.addfile(info)


def build_bundle(source_root: Path, bundle_id: str) -> tuple[Path, str]:
    tree_manifest = build_tree_manifest(source_root, bundle_id)
    atomic_write(source_root / TREE_MANIFEST_FILENAME, canonical_json(tree_manifest))
    bundle = source_root.parent / BUNDLE_FILENAME
    if bundle.exists() or bundle.is_symlink():
        raise RuntimeError(f"refusing to overwrite existing bundle: {bundle}")
    partial = bundle.parent / f".{bundle.name}.{secrets.token_hex(16)}.partial"
    directories: set[str] = set()
    with partial.open("xb") as raw:
        with tarfile.open(fileobj=raw, mode="w", format=tarfile.PAX_FORMAT) as archive:
            files = list(iter_regular_tree(source_root, {BUNDLE_FILENAME, BUNDLE_HASH_FILENAME}))
            for relative, _path in files:
                parent = relative.parent
                parents = []
                while parent != Path("."):
                    parents.append(parent.as_posix())
                    parent = parent.parent
                for directory in reversed(parents):
                    if directory not in directories:
                        add_tar_directory(archive, directory)
                        directories.add(directory)
                info = tarfile.TarInfo(relative.as_posix())
                metadata = _path.stat()
                info.size = metadata.st_size
                info.mode = 0o644
                info.uid = 0
                info.gid = 0
                info.uname = "root"
                info.gname = "root"
                info.mtime = 0
                with _path.open("rb") as source:
                    archive.addfile(info, source)
        raw.flush()
        os.fsync(raw.fileno())
    publish_new_file(partial, bundle)
    bundle_size, bundle_hash = sha256_file(bundle)
    atomic_write(
        source_root.parent / BUNDLE_HASH_FILENAME,
        f"{bundle_hash}  {BUNDLE_FILENAME}\n".encode("ascii"),
    )
    receipt = {
        "schema_version": 1,
        "bundle": BUNDLE_FILENAME,
        "size": bundle_size,
        "sha256": bundle_hash,
        "tree_manifest_sha256": hashlib.sha256(canonical_json(tree_manifest)).hexdigest(),
        "file_count": tree_manifest["file_count"],
        "payload_size": tree_manifest["payload_size"],
    }
    atomic_write(source_root.parent / "bundle-receipt.json", canonical_json(receipt))
    return bundle, bundle_hash


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument("--manifest", default=str(DEFAULT_MANIFEST))
    result.add_argument("--output", default=str(DEFAULT_OUTPUT))
    downloads = result.add_mutually_exclusive_group()
    downloads.add_argument("--download", action="store_true", help="perform all reviewed HTTPS acquisitions")
    downloads.add_argument(
        "--download-license-evidence",
        action="store_true",
        help="acquire only the three hash-pinned upstream license texts",
    )
    result.add_argument("--build", action="store_true", help="generate evidence and deterministic source bundle")
    result.add_argument(
        "--zstandard-wheel",
        help="path to the exact builder-only zstandard 0.25.0 wheel used to read the Python .tar.zst companion",
    )
    return result


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    if args.zstandard_wheel and not args.build:
        raise RuntimeError("--zstandard-wheel is valid only together with --build")
    if args.download_license_evidence and args.build:
        raise RuntimeError("--download-license-evidence cannot be combined with --build")
    if args.build and not args.zstandard_wheel:
        raise RuntimeError("--build requires the exact --zstandard-wheel so notices remain reproducible")
    manifest_path = trusted_input_file(Path(args.manifest), "artifact manifest")
    manifest, records = load_manifest(manifest_path)
    license_evidence = load_license_evidence()
    # Evidence is deliberately acquired before model payloads in the full mode.
    # It is non-executable and has separate trust anchors, so a blocked model CDN
    # cannot prevent notices material from being cached and reviewed.
    all_records = [
        *(record for record in records if record.category != "model"),
        *license_evidence,
        *(record for record in records if record.category == "model"),
    ]
    output = Path(args.output).expanduser().resolve(strict=False)
    source_root = output / "source"
    print(
        "STT_SOURCE_PLAN="
        + json.dumps(
            {
                "bundle_id": manifest["bundle_id"],
                "artifact_count": len(records),
                "artifact_bytes": sum(record.size for record in records),
                "license_evidence_count": len(license_evidence),
                "license_evidence_bytes": sum(record.size for record in license_evidence),
                "output": str(output),
                "network": bool(args.download or args.download_license_evidence),
                "license_evidence_only": bool(args.download_license_evidence),
                "build": bool(args.build),
            },
            sort_keys=True,
            separators=(",", ":"),
        )
    )
    if not args.download and not args.download_license_evidence and not args.build:
        missing = [
            record.filename
            for record in all_records
            if not artifact_destination(source_root, record).is_file()
        ]
        if missing:
            print(f"[dry-run] {len(missing)} frozen artifacts are absent; no directories, files, or network connections were created")
            return 0
    if args.download or args.download_license_evidence or args.build:
        source_root.mkdir(parents=True, exist_ok=True)
    selected_records = license_evidence if args.download_license_evidence else all_records
    for record in selected_records:
        destination = artifact_destination(source_root, record)
        if args.download or args.download_license_evidence:
            download_artifact(record, destination)
        else:
            if not destination.is_file():
                raise RuntimeError(f"required frozen artifact is missing: {record.filename}")
            verify_artifact(destination, record)
    if args.download_license_evidence:
        print("all three frozen upstream license evidence files verified; no runtime artifact was downloaded")
        return 0
    if args.build:
        validate_artifact_source_layout(source_root, all_records)
        generate_evidence(
            source_root,
            manifest,
            all_records,
            Path(args.zstandard_wheel) if args.zstandard_wheel else None,
        )
        bundle, bundle_hash = build_bundle(source_root, manifest["bundle_id"])
        print(
            "STT_SOURCE_BUNDLE="
            + json.dumps(
                {"path": str(bundle), "sha256": bundle_hash},
                sort_keys=True,
                separators=(",", ":"),
            )
        )
    else:
        print("all frozen STT artifacts verified; no bundle was generated")
    return 0


def cli(argv: list[str] | None = None) -> int:
    try:
        return main(argv)
    except Exception as exc:
        print(f"offline STT source preparation failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(cli())
