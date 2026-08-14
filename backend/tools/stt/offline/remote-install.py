#!/usr/bin/env python3
"""Production-side installer for one verified offline STT payload.

This file is transported inside the content-addressed payload and is executed
only by a separately validated, root-owned system Python.  It never opens a
network connection and never reuses bytes from the legacy www-writable STT
runtime.
"""

from __future__ import annotations

import argparse
import grp
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import pwd
import re
import secrets
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
from typing import Any, BinaryIO, Iterable


EXPECTED_ROOT = Path("/www/wwwroot/appht.jjmxg.xyz/yiyunying-backend")
RUNTIME_USER = "www"
RUNTIME_GROUP = "www"
RELEASE_RE = re.compile(r"^py31115-fw121-ebe41f70d5b6-[0-9a-f]{12}$")
TOKEN_RE = re.compile(r"^[0-9a-f]{32}$")
HASH_RE = re.compile(r"^[0-9a-f]{64}$")
EXPECTED_VERSIONS = {
    "anyio": "4.14.2",
    "av": "12.3.0",
    "certifi": "2026.6.17",
    "click": "8.4.2",
    "coloredlogs": "15.0.1",
    "ctranslate2": "4.6.2",
    "faster-whisper": "1.2.1",
    "filelock": "3.29.7",
    "flatbuffers": "25.12.19",
    "fsspec": "2026.6.0",
    "h11": "0.16.0",
    "hf-xet": "1.5.1",
    "httpcore": "1.0.9",
    "httpx": "0.28.1",
    "huggingface-hub": "1.23.0",
    "humanfriendly": "10.0",
    "idna": "3.18",
    "mpmath": "1.3.0",
    "numpy": "1.26.4",
    "onnxruntime": "1.16.3",
    "packaging": "26.2",
    "pip": "26.1.2",
    "protobuf": "7.35.1",
    "PyYAML": "6.0.3",
    "setuptools": "84.0.0",
    "sympy": "1.14.0",
    "tokenizers": "0.23.1",
    "tqdm": "4.68.4",
    "typing-extensions": "4.16.0",
    "wheel": "0.47.0",
}
EXPECTED_MODEL = {
    "config.json": (2309, "56a6d8110d311f19c8f0471e562832c7527f146b567275bfca59fcf7c184da9a"),
    "model.bin": (145217532, "d01c3014881c9c6f3133c182f3d2887eb6ca1c789a7538c5c007196857a0a6a9"),
    "tokenizer.json": (2203239, "fb7b63191e9bb045082c79fd742a3106a12c99513ab30df4a0d47fa6cb6fd0ab"),
    "vocabulary.txt": (459861, "34ce3fe1c5041027b3f8d42912270993f986dbc4bb34cf27f951e34a1e453913"),
}
PYTHON_RUNTIME_FILENAME = "cpython-3.11.15+20260718-x86_64-unknown-linux-gnu-install_only_stripped.tar.gz"
PYTHON_RUNTIME_SIZE = 30_930_344
PYTHON_RUNTIME_SHA256 = "23ccae6f1ff73e8aa8378436f869da003b8eb7d6c95f2bc706f494115ba1447d"
MODEL_REVISION = "ebe41f70d5b6dfa9166e2c581c45c9c0cfc57b66"
PROBE_SHA256 = "d13e4f6fd2e70b6d93dbc1029412c4a00716e5539a9840d2dd746b414170df94"
PROBE_SIZE = 32_044
MAX_MEMBER_SIZE = 512 * 1024 * 1024
MINIMUM_FREE_BYTES = 2 << 30


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


def fsync_directory(path: Path) -> None:
    descriptor = os.open(path, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def fsync_release_tree(root: Path) -> None:
    for path in root.rglob("*"):
        if path.is_symlink() or not path.is_file():
            continue
        flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)
        descriptor = os.open(path, flags)
        try:
            metadata = os.fstat(descriptor)
            if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
                raise RuntimeError("release changed while durability was being established")
            os.fsync(descriptor)
        finally:
            os.close(descriptor)
    directories = [root, *(path for path in root.rglob("*") if path.is_dir() and not path.is_symlink())]
    directories.sort(key=lambda item: len(item.parts), reverse=True)
    for directory in directories:
        fsync_directory(directory)


def atomic_write(path: Path, payload: bytes, mode: int = 0o600) -> None:
    temporary = path.parent / f".{path.name}.{secrets.token_hex(8)}.partial"
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, mode)
    try:
        with os.fdopen(descriptor, "wb", closefd=False) as handle:
            handle.write(payload)
            handle.flush()
            os.fsync(handle.fileno())
        os.close(descriptor)
        descriptor = -1
        os.replace(temporary, path)
        fsync_directory(path.parent)
    finally:
        if descriptor >= 0:
            os.close(descriptor)
        if temporary.exists():
            temporary.unlink()


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


def normalized_link(member_name: str, link_name: str) -> PurePosixPath | None:
    if not link_name or "\\" in link_name or "\x00" in link_name:
        return None
    link = PurePosixPath(link_name)
    if link.is_absolute():
        return None
    parts: list[str] = []
    for part in PurePosixPath(member_name).parent.joinpath(link).parts:
        if part in ("", "."):
            continue
        if part == "..":
            if not parts:
                return None
            parts.pop()
        else:
            parts.append(part)
    return PurePosixPath(*parts) if parts else None


def validate_unique_regular(path: Path, label: str) -> os.stat_result:
    metadata = os.lstat(path)
    if path.is_symlink() or not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
        raise RuntimeError(f"{label} must be one unique regular non-link file")
    return metadata


def load_payload_manifest(root: Path) -> dict[str, Any]:
    manifest_path = root / "payload-manifest.json"
    validate_unique_regular(manifest_path, "payload manifest")
    data = json.loads(manifest_path.read_text(encoding="utf-8"))
    if data.get("schema_version") != 1 or data.get("target") != "linux-x86_64-cp311-glibc2.17":
        raise RuntimeError("payload manifest target is not reviewed")
    files = data.get("files")
    if not isinstance(files, list) or not files:
        raise RuntimeError("payload manifest has no files")
    expected_paths: set[str] = {"payload-manifest.json"}
    for record in files:
        if not isinstance(record, dict):
            raise RuntimeError("payload manifest record is invalid")
        relative = record.get("path")
        size = record.get("size")
        digest = record.get("sha256")
        if (
            not isinstance(relative, str)
            or not safe_name(relative)
            or relative in expected_paths
            or not isinstance(size, int)
            or size < 0
            or not isinstance(digest, str)
            or not HASH_RE.fullmatch(digest)
        ):
            raise RuntimeError("payload manifest contains an unsafe identity")
        expected_paths.add(relative)
        path = root / Path(*PurePosixPath(relative).parts)
        metadata = validate_unique_regular(path, relative)
        if metadata.st_size != size:
            raise RuntimeError(f"payload size mismatch: {relative}")
        actual_size, actual_hash = sha256_file(path)
        if actual_size != size or actual_hash != digest:
            raise RuntimeError(f"payload SHA-256 mismatch: {relative}")
    actual_paths = {
        path.relative_to(root).as_posix()
        for path in root.rglob("*")
        if path.is_file() and not path.is_symlink()
    }
    if actual_paths != expected_paths:
        raise RuntimeError("payload contains an unmanifested or missing regular file")
    return data


def extract_payload(archive_path: Path, destination: Path) -> dict[str, Any]:
    with tarfile.open(archive_path, "r:") as archive:
        members = archive.getmembers()
        names = [member.name for member in members]
        if len(names) != len(set(names)):
            raise RuntimeError("payload tar contains duplicate paths")
        for member in members:
            if not safe_name(member.name):
                raise RuntimeError("payload tar contains an unsafe path")
            if not (member.isfile() or member.isdir()):
                raise RuntimeError("payload tar contains a link or special entry")
            if member.size > MAX_MEMBER_SIZE:
                raise RuntimeError("payload tar member exceeds the safety limit")
            path = destination / Path(*PurePosixPath(member.name).parts)
            if member.isdir():
                path.mkdir(parents=True, exist_ok=True, mode=0o700)
                continue
            path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            source = archive.extractfile(member)
            if source is None:
                raise RuntimeError("payload tar member cannot be read")
            descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(descriptor, "wb") as output:
                shutil.copyfileobj(source, output, length=1024 * 1024)
                output.flush()
                os.fsync(output.fileno())
    return load_payload_manifest(destination)


def python_projection_name(name: str) -> str:
    pure = PurePosixPath(name)
    if not safe_name(name) or not pure.parts or pure.parts[0] != "python" or len(pure.parts) == 1:
        raise RuntimeError("Python runtime member is outside the python/ projection")
    return PurePosixPath(*pure.parts[1:]).as_posix()


def extract_python_runtime(archive_path: Path, destination: Path) -> None:
    size, digest = sha256_file(archive_path)
    if size != PYTHON_RUNTIME_SIZE or digest != PYTHON_RUNTIME_SHA256:
        raise RuntimeError("Python runtime archive identity changed")
    with tarfile.open(archive_path, "r:gz") as archive:
        members = archive.getmembers()
        projected: dict[str, tarfile.TarInfo] = {}
        symlinks: list[tuple[str, str]] = []
        for member in members:
            # python-build-standalone archives include an explicit top-level
            # python/ directory.  It is the projection root, not a release
            # member, and must not collide with the destination created by the
            # installer.
            if member.isdir() and member.name.rstrip("/") == "python":
                continue
            relative = python_projection_name(member.name)
            if relative in projected:
                raise RuntimeError("Python runtime contains duplicate projected paths")
            projected[relative] = member
            if member.issym():
                target = normalized_link(relative, member.linkname)
                if target is None:
                    raise RuntimeError("Python runtime contains an escaping symlink")
                symlinks.append((relative, member.linkname))
            elif not (member.isfile() or member.isdir() or member.islnk()):
                raise RuntimeError("Python runtime contains a special entry")
            if member.size > MAX_MEMBER_SIZE:
                raise RuntimeError("Python runtime member exceeds the safety limit")

        for relative, member in sorted(projected.items()):
            path = destination / Path(*PurePosixPath(relative).parts)
            if member.isdir():
                path.mkdir(parents=True, exist_ok=True, mode=0o700)
                continue
            if member.issym():
                continue
            path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            source = archive.extractfile(member)
            if source is None:
                raise RuntimeError("Python runtime regular/hardlink data cannot be read")
            descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(descriptor, "wb") as output:
                shutil.copyfileobj(source, output, length=1024 * 1024)
                output.flush()
                os.fsync(output.fileno())
            mode = 0o700 if member.mode & 0o111 else 0o600
            os.chmod(path, mode)
        for relative, link_name in symlinks:
            path = destination / Path(*PurePosixPath(relative).parts)
            path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            os.symlink(link_name, path)
        for relative, _link_name in symlinks:
            path = destination / Path(*PurePosixPath(relative).parts)
            resolved = path.resolve(strict=True)
            if not resolved.is_relative_to(destination.resolve(strict=True)):
                raise RuntimeError("Python runtime symlink resolved outside the release")


def run_checked(command: list[str], *, environment: dict[str, str] | None = None, timeout: int = 900) -> str:
    result = subprocess.run(
        command,
        env=environment,
        text=True,
        encoding="utf-8",
        errors="replace",
        capture_output=True,
        timeout=timeout,
        check=False,
    )
    if result.returncode != 0:
        detail = (result.stderr or result.stdout or "no diagnostic output").strip()
        raise RuntimeError(f"command failed ({result.returncode}): {detail[:2000]}")
    return result.stdout


def offline_environment(home: Path, python_bin: Path) -> dict[str, str]:
    return {
        "HOME": str(home),
        "LANG": "C.UTF-8",
        "LC_ALL": "C.UTF-8",
        "PATH": f"{python_bin.parent}:/usr/sbin:/usr/bin:/sbin:/bin",
        "PIP_CONFIG_FILE": "/dev/null",
        "PIP_DISABLE_PIP_VERSION_CHECK": "1",
        "PIP_NO_INDEX": "1",
        "PYTHONDONTWRITEBYTECODE": "1",
        "PYTHONNOUSERSITE": "1",
        "PYTHONSAFEPATH": "1",
        "HF_HUB_OFFLINE": "1",
        "HF_HUB_DISABLE_TELEMETRY": "1",
        "TRANSFORMERS_OFFLINE": "1",
        "TOKENIZERS_PARALLELISM": "false",
        "NO_PROXY": "*",
        "no_proxy": "*",
    }


def unshared(command: list[str], environment: dict[str, str]) -> list[str]:
    unshare = shutil.which("unshare", path="/usr/sbin:/usr/bin:/sbin:/bin")
    env_tool = shutil.which("env", path="/usr/sbin:/usr/bin:/sbin:/bin")
    if not unshare or not env_tool:
        raise RuntimeError("unshare/env are required to prove production installation is offline")
    env_args = [f"{name}={value}" for name, value in sorted(environment.items())]
    return [unshare, "--net", "--", env_tool, "-i", *env_args, *command]


def install_wheels(release: Path, input_root: Path, root_home: Path) -> None:
    python_bin = release / "python" / "bin" / "python3"
    if not python_bin.is_file() or not os.access(python_bin, os.X_OK):
        raise RuntimeError("pinned Python entrypoint is missing or not executable")
    environment = offline_environment(root_home, python_bin)
    run_checked(unshared([str(python_bin), "-I", "-m", "ensurepip", "--upgrade", "--default-pip"], environment))
    lock = input_root / "metadata" / "requirements-linux-x86_64-cp311.lock"
    wheelhouse = input_root / "wheelhouse"
    run_checked(
        unshared(
            [
                str(python_bin),
                "-I",
                "-m",
                "pip",
                "install",
                "--no-index",
                "--find-links",
                str(wheelhouse),
                "--only-binary=:all:",
                "--no-deps",
                "--require-hashes",
                "--disable-pip-version-check",
                "-r",
                str(lock),
            ],
            environment,
        )
    )
    run_checked(unshared([str(python_bin), "-I", "-m", "pip", "check"], environment))


def copy_release_data(release: Path, input_root: Path) -> None:
    destinations = {
        input_root / "model" / "base": release / "model" / "base",
        input_root / "probe": release / "probe",
        input_root / "metadata": release / "manifests",
        input_root / "licenses": release / "notices",
    }
    for source, destination in destinations.items():
        if not source.is_dir() or source.is_symlink():
            raise RuntimeError(f"payload release source is missing: {source.name}")
        shutil.copytree(source, destination, symlinks=False)
    for name, (expected_size, expected_hash) in EXPECTED_MODEL.items():
        path = release / "model" / "base" / name
        size, digest = sha256_file(path)
        if size != expected_size or digest != expected_hash:
            raise RuntimeError(f"release model identity mismatch: {name}")
    probe = release / "probe" / "stt-runtime-probe.wav"
    size, digest = sha256_file(probe)
    if size != PROBE_SIZE or digest != PROBE_SHA256:
        raise RuntimeError("release probe identity mismatch")


def release_file_mode(root: Path, path: Path) -> int:
    relative = path.relative_to(root)
    return 0o750 if "bin" in relative.parts[:-1] else 0o640


def normalize_permissions(root: Path, group_id: int) -> None:
    paths = [root, *root.rglob("*")]
    paths.sort(key=lambda item: len(item.parts))
    for path in paths:
        metadata = os.lstat(path)
        if path.is_symlink():
            os.lchown(path, 0, group_id)
            continue
        if stat.S_ISDIR(metadata.st_mode):
            os.chown(path, 0, group_id)
            os.chmod(path, 0o750)
        elif stat.S_ISREG(metadata.st_mode):
            os.chown(path, 0, group_id)
            os.chmod(path, release_file_mode(root, path))
        else:
            raise RuntimeError("release contains a special entry")


def audit_release_tree(root: Path, group_id: int) -> None:
    resolved_root = root.resolve(strict=True)
    for path in [root, *root.rglob("*")]:
        metadata = os.lstat(path)
        if path.is_symlink():
            if metadata.st_uid != 0 or metadata.st_gid != group_id:
                raise RuntimeError("release symlink ownership matrix is invalid")
            resolved = path.resolve(strict=True)
            if not resolved.is_relative_to(resolved_root):
                raise RuntimeError("release contains an escaping or broken symlink")
            continue
        if metadata.st_uid != 0 or metadata.st_gid != group_id:
            raise RuntimeError("release ownership matrix is invalid")
        if stat.S_ISDIR(metadata.st_mode):
            if stat.S_IMODE(metadata.st_mode) != 0o750:
                raise RuntimeError("release directory mode matrix is invalid")
        elif stat.S_ISREG(metadata.st_mode):
            if metadata.st_nlink != 1:
                raise RuntimeError("release contains a regular hardlink")
            expected = release_file_mode(root, path)
            if stat.S_IMODE(metadata.st_mode) != expected:
                raise RuntimeError("release file mode matrix is invalid")
        else:
            raise RuntimeError("release contains a special entry")


def audit_elf(release: Path) -> None:
    ldd = shutil.which("ldd", path="/usr/sbin:/usr/bin:/sbin:/bin")
    readelf = shutil.which("readelf", path="/usr/sbin:/usr/bin:/sbin:/bin")
    if not ldd or not readelf:
        raise RuntimeError("ldd/readelf are required for the native dependency gate")
    elf_files: list[Path] = []
    for path in release.rglob("*"):
        if not path.is_file() or path.is_symlink():
            continue
        with path.open("rb") as handle:
            if handle.read(4) == b"\x7fELF":
                elf_files.append(path)
    if not elf_files:
        raise RuntimeError("release contains no ELF runtime")
    for path in elf_files:
        output = run_checked([ldd, str(path)], timeout=120)
        if "not found" in output.lower():
            raise RuntimeError(f"ELF dependency is missing: {path.name}")
        dynamic = run_checked([readelf, "-d", str(path)], timeout=120)
        for match in re.finditer(r"\((?:RPATH|RUNPATH)\).*?\[(.*?)\]", dynamic):
            for entry in match.group(1).split(":"):
                if entry and not origin_rpath_within_release(release, path, entry):
                    raise RuntimeError(f"ELF contains an external RPATH/RUNPATH: {path.name}")


def origin_rpath_within_release(release: Path, elf: Path, entry: str) -> bool:
    prefix = next(
        (candidate for candidate in ("${ORIGIN}", "$ORIGIN") if entry == candidate or entry.startswith(candidate + "/")),
        None,
    )
    if prefix is None:
        return False
    suffix = entry[len(prefix):].lstrip("/")
    if "$" in suffix or "\\" in suffix or "\x00" in suffix:
        return False
    candidate = (elf.parent / suffix).resolve(strict=False)
    return candidate.is_relative_to(release.resolve(strict=True))


def www_command(user: str, environment: dict[str, str], command: list[str]) -> list[str]:
    unshare = shutil.which("unshare", path="/usr/sbin:/usr/bin:/sbin:/bin")
    runuser = shutil.which("runuser", path="/usr/sbin:/usr/bin:/sbin:/bin")
    env_tool = shutil.which("env", path="/usr/sbin:/usr/bin:/sbin:/bin")
    if not unshare or not runuser or not env_tool:
        raise RuntimeError("unshare/runuser/env are required for the www probe")
    env_args = [f"{name}={value}" for name, value in sorted(environment.items())]
    return [unshare, "--net", "--", runuser, "--user", user, "--", env_tool, "-i", *env_args, *command]


def validate_production_wrapper(
    backend_root: Path,
    expected_size: int,
    expected_hash: str,
    group_id: int,
) -> Path:
    for directory in (backend_root, backend_root / "tools", backend_root / "tools" / "stt"):
        metadata = os.lstat(directory)
        if (
            directory.is_symlink()
            or not stat.S_ISDIR(metadata.st_mode)
            or metadata.st_uid != 0
            or metadata.st_gid != group_id
            or stat.S_IMODE(metadata.st_mode) != 0o750
        ):
            raise RuntimeError("production STT wrapper parent is not root-owned read-only")
    wrapper = backend_root / "tools" / "stt" / "transcribe.py"
    metadata = validate_unique_regular(wrapper, "production STT wrapper")
    if (
        metadata.st_uid != 0
        or metadata.st_gid != group_id
        or stat.S_IMODE(metadata.st_mode) != 0o640
        or metadata.st_size != expected_size
    ):
        raise RuntimeError("production STT wrapper ownership/mode/size is invalid")
    size, digest = sha256_file(wrapper)
    if size != expected_size or digest != expected_hash:
        raise RuntimeError("production STT wrapper differs from the content-addressed payload")
    return wrapper


def run_www_probe(
    release: Path,
    backend_root: Path,
    home: Path,
    runtime_user: str,
    runtime_group_id: int,
    wrapper_identity: tuple[int, str],
) -> dict[str, Any]:
    python_bin = release / "python" / "bin" / "python3"
    model = release / "model" / "base"
    probe = release / "probe" / "stt-runtime-probe.wav"
    environment = offline_environment(home, python_bin)
    code = r'''import importlib.metadata as md,json,math,platform,sys
expected=json.loads(sys.argv[1])
assert platform.python_version()=="3.11.15"
for name,version in expected.items(): assert md.version(name)==version,(name,md.version(name),version)
import av,ctranslate2,faster_whisper,numpy,onnxruntime
from faster_whisper import WhisperModel
model=WhisperModel(sys.argv[2],device="cpu",compute_type="int8",local_files_only=True,cpu_threads=1,num_workers=1)
segments,info=model.transcribe(sys.argv[3],language="en",beam_size=1,vad_filter=False,condition_on_previous_text=False)
rows=[{"start":float(s.start),"end":float(s.end),"text":str(s.text)} for s in segments]
assert all(math.isfinite(x[k]) for x in rows for k in ("start","end"))
print("STT_LIBRARY_PROBE="+json.dumps({"duration":float(info.duration),"segments":len(rows)},sort_keys=True,separators=(",",":")))'''
    output = run_checked(
        www_command(
            runtime_user,
            environment,
            [str(python_bin), "-I", "-B", "-c", code, json.dumps(EXPECTED_VERSIONS), str(model), str(probe)],
        ),
        timeout=900,
    )
    if "STT_LIBRARY_PROBE=" not in output:
        raise RuntimeError("www library probe returned no authenticated receipt")
    transcript = home / "wrapper-output.txt"
    wrapper = validate_production_wrapper(
        backend_root,
        wrapper_identity[0],
        wrapper_identity[1],
        runtime_group_id,
    )
    run_checked(
        www_command(
            runtime_user,
            environment,
            [
                str(python_bin),
                "-I",
                "-B",
                str(wrapper),
                "--input",
                str(probe),
                "--output",
                str(transcript),
                "--language",
                "en",
                "--model",
                str(model),
                "--runtime-probe",
            ],
        ),
        timeout=900,
    )
    payload = json.loads(transcript.read_text(encoding="utf-8"))
    if payload.get("runtime_probe") is not True:
        raise RuntimeError("actual transcribe wrapper probe receipt is invalid")
    for path in [release, *release.rglob("*")]:
        if os.access(path, os.W_OK, effective_ids=True):
            # The installer is still root here; the explicit negative write
            # test below is authoritative for the runtime identity.
            break
    denied = home / "write-denied-result"
    write_test = (
        "from pathlib import Path; import sys; p=Path(sys.argv[1]); "
        "\ntry: p.write_bytes(b'x')\nexcept PermissionError: raise SystemExit(0)\nraise SystemExit(9)"
    )
    run_checked(
        www_command(
            runtime_user,
            environment,
            [str(python_bin), "-I", "-B", "-c", write_test, str(release / denied.name)],
        ),
        timeout=60,
    )
    return {"library": "passed", "wrapper": "passed", "network": "unshared", "release_write": "denied"}


def release_tree_manifest(release: Path) -> dict[str, Any]:
    excluded = {"manifests/release-tree.json", "manifests/install-receipt.json"}
    entries: list[dict[str, Any]] = []
    regular_files = 0
    payload_size = 0
    for path in sorted(release.rglob("*"), key=lambda item: item.relative_to(release).as_posix()):
        relative = path.relative_to(release).as_posix()
        if relative in excluded:
            continue
        metadata = os.lstat(path)
        if path.is_symlink():
            entries.append({"path": relative, "type": "symlink", "target": os.readlink(path)})
        elif stat.S_ISDIR(metadata.st_mode):
            entries.append({"path": relative, "type": "directory"})
        elif stat.S_ISREG(metadata.st_mode):
            size, digest = sha256_file(path)
            entries.append({"path": relative, "type": "file", "size": size, "sha256": digest})
            regular_files += 1
            payload_size += size
        else:
            raise RuntimeError("release tree manifest encountered a special entry")
    return {
        "schema_version": 1,
        "entries": entries,
        "entry_count": len(entries),
        "regular_file_count": regular_files,
        "payload_size": payload_size,
    }


def read_current(stt_root: Path) -> str | None:
    current = stt_root / "current"
    if not current.exists() and not current.is_symlink():
        return None
    if not current.is_symlink():
        raise RuntimeError("storage/stt/current exists but is not a symlink")
    current_metadata = os.lstat(current)
    stt_metadata = os.lstat(stt_root)
    if current_metadata.st_uid != 0 or current_metadata.st_gid != stt_metadata.st_gid:
        raise RuntimeError("storage/stt/current link ownership is invalid")
    target = os.readlink(current)
    match = re.fullmatch(r"releases/([^/]+)", target)
    if PurePosixPath(target).is_absolute() or match is None or RELEASE_RE.fullmatch(match.group(1)) is None:
        raise RuntimeError("storage/stt/current target is outside releases")
    release_entry = stt_root / target
    if release_entry.is_symlink() or not release_entry.is_dir():
        raise RuntimeError("storage/stt/current does not select a real release directory")
    resolved = current.resolve(strict=True)
    if not resolved.is_relative_to((stt_root / "releases").resolve(strict=True)):
        raise RuntimeError("storage/stt/current resolves outside releases")
    return target


def atomic_current(stt_root: Path, target: str | None, token: str) -> None:
    current = stt_root / "current"
    if target is None:
        if current.is_symlink():
            current.unlink()
            fsync_directory(stt_root)
        return
    temporary = stt_root / f".current-{token}"
    if temporary.exists() or temporary.is_symlink():
        raise RecoveryRequired("RECOVERY_REQUIRED=stt-temporary-current-collision")
    os.symlink(target, temporary)
    os.lchown(temporary, 0, os.lstat(stt_root).st_gid)
    os.replace(temporary, current)
    fsync_directory(stt_root)


def validate_trusted_parent_chain(root: Path) -> list[tuple[Path, int, int]]:
    expected = [
        Path("/www"),
        Path("/www/wwwroot"),
        Path("/www/wwwroot/appht.jjmxg.xyz"),
        root,
        root / "storage",
        root / "storage" / "stt",
        root / "tools",
        root / "tools" / "stt",
    ]
    fingerprints: list[tuple[Path, int, int]] = []
    for path in expected:
        metadata = os.lstat(path)
        if (
            path.is_symlink()
            or not stat.S_ISDIR(metadata.st_mode)
            or metadata.st_uid != 0
            or stat.S_IMODE(metadata.st_mode) & 0o022
        ):
            raise RuntimeError("production parent chain is not root-owned and non-writable")
        fingerprints.append((path, metadata.st_dev, metadata.st_ino))
    return fingerprints


def assert_trusted_parent_chain_unchanged(fingerprints: list[tuple[Path, int, int]]) -> None:
    for path, device, inode in fingerprints:
        metadata = os.lstat(path)
        if (
            path.is_symlink()
            or not stat.S_ISDIR(metadata.st_mode)
            or metadata.st_uid != 0
            or stat.S_IMODE(metadata.st_mode) & 0o022
            or (metadata.st_dev, metadata.st_ino) != (device, inode)
        ):
            raise RuntimeError("production parent chain changed during installation")


def validate_root_contract(root: Path) -> list[tuple[Path, int, int]]:
    if root != EXPECTED_ROOT or not root.is_dir() or root.is_symlink():
        raise RuntimeError("backend root is outside the pinned production scope")
    if os.geteuid() != 0:
        raise RuntimeError("offline STT installer must run as root")
    if os.uname().sysname != "Linux" or os.uname().machine != "x86_64":
        raise RuntimeError("offline STT installer requires Linux x86_64")
    libc = os.confstr("CS_GNU_LIBC_VERSION") or ""
    match = re.fullmatch(r"glibc ([0-9]+)\.([0-9]+)", libc)
    if match is None or (int(match.group(1)), int(match.group(2))) < (2, 17):
        raise RuntimeError("production glibc is older than 2.17")
    free = shutil.disk_usage(root / "storage" / "stt").free
    if free < MINIMUM_FREE_BYTES:
        raise RuntimeError("production filesystem has insufficient free space")
    return validate_trusted_parent_chain(root)


def acquire_lock(stt_root: Path, token: str) -> Path:
    lock = stt_root / ".install-lock"
    lock.mkdir(mode=0o700)
    os.chown(lock, 0, 0)
    atomic_write(lock / "token", (token + "\n").encode("ascii"), 0o600)
    return lock


def release_is_trusted(release: Path, group_id: int) -> bool:
    try:
        if release.is_symlink() or not release.is_dir() or RELEASE_RE.fullmatch(release.name) is None:
            return False
        receipt = json.loads((release / "manifests" / "install-receipt.json").read_text(encoding="utf-8"))
        if (
            receipt.get("status") != "committed"
            or receipt.get("release_id") != release.name
            or receipt.get("python") != "3.11.15"
            or receipt.get("faster_whisper") != "1.2.1"
            or receipt.get("model_revision") != MODEL_REVISION
            or not HASH_RE.fullmatch(str(receipt.get("source_manifest_sha256", "")))
            or not HASH_RE.fullmatch(str(receipt.get("payload_sha256", "")))
            or not HASH_RE.fullmatch(str(receipt.get("release_tree_sha256", "")))
        ):
            return False
        audit_release_tree(release, group_id)
        expected_tree = release_tree_manifest(release)
        expected_bytes = canonical_json(expected_tree)
        stored_bytes = (release / "manifests" / "release-tree.json").read_bytes()
        if stored_bytes != expected_bytes:
            return False
        if hashlib.sha256(stored_bytes).hexdigest() != receipt["release_tree_sha256"]:
            return False
        return True
    except Exception:
        return False


def install(args: argparse.Namespace) -> dict[str, Any]:
    root = Path(args.backend_root)
    parent_fingerprints = validate_root_contract(root)
    if not TOKEN_RE.fullmatch(args.token) or not RELEASE_RE.fullmatch(args.release_id):
        raise RuntimeError("release token or id is outside the reviewed format")
    if not HASH_RE.fullmatch(args.archive_sha256) or not HASH_RE.fullmatch(args.source_manifest_sha256):
        raise RuntimeError("installer digest confirmation is invalid")
    archive = Path(args.archive)
    metadata = validate_unique_regular(archive, "remote STT payload")
    if metadata.st_uid != 0 or stat.S_IMODE(metadata.st_mode) != 0o600 or metadata.st_size != args.archive_size:
        raise RuntimeError("remote STT payload owner/mode/size is invalid")
    size, digest = sha256_file(archive)
    if size != args.archive_size or digest != args.archive_sha256:
        raise RuntimeError("remote STT payload SHA-256 is invalid")

    runtime = pwd.getpwnam(args.runtime_user)
    group = grp.getgrnam(args.runtime_group)
    if runtime.pw_uid == 0 or group.gr_gid == 0:
        raise RuntimeError("runtime identity must be non-root")
    stt_root = root / "storage" / "stt"
    exact_parent_modes = {
        root: 0o750,
        root / "storage": 0o710,
        stt_root: 0o750,
        root / "tools": 0o750,
        root / "tools" / "stt": 0o750,
    }
    for path, mode in exact_parent_modes.items():
        metadata = os.lstat(path)
        if (
            path.is_symlink()
            or not stat.S_ISDIR(metadata.st_mode)
            or metadata.st_uid != 0
            or metadata.st_gid != group.gr_gid
            or stat.S_IMODE(metadata.st_mode) != mode
        ):
            raise RuntimeError("production STT parent permissions were not hardened first")
    assert_trusted_parent_chain_unchanged(parent_fingerprints)
    releases = stt_root / "releases"
    try:
        releases_metadata = os.lstat(releases)
    except FileNotFoundError:
        releases.mkdir(mode=0o750)
        os.chown(releases, 0, group.gr_gid)
        fsync_directory(stt_root)
        releases_metadata = os.lstat(releases)
    if (
        releases.is_symlink()
        or not stat.S_ISDIR(releases_metadata.st_mode)
        or releases_metadata.st_uid != 0
        or releases_metadata.st_gid != group.gr_gid
        or stat.S_IMODE(releases_metadata.st_mode) != 0o750
    ):
        raise RuntimeError("storage/stt/releases must be a hardened real directory")
    if releases_metadata.st_dev != os.lstat(stt_root).st_dev:
        raise RuntimeError("STT releases and current are not on one filesystem")
    assert_trusted_parent_chain_unchanged(parent_fingerprints)
    lock = acquire_lock(stt_root, args.token)
    stage = releases / f".{args.release_id}-{args.token}.partial"
    destination = releases / args.release_id
    switched = False
    previous: str | None = None
    try:
        if stage.exists() or stage.is_symlink() or destination.exists() or destination.is_symlink():
            raise RuntimeError("unique STT release stage or destination already exists")
        stage.mkdir(mode=0o750)
        os.chown(stage, 0, group.gr_gid)
        input_root = stage / "input"
        input_root.mkdir(mode=0o700)
        payload_manifest = extract_payload(archive, input_root)
        if payload_manifest.get("source_manifest_sha256") != args.source_manifest_sha256:
            raise RuntimeError("source tree manifest confirmation changed in transit")
        if payload_manifest.get("release_id") != args.release_id:
            raise RuntimeError("payload release id differs from the confirmed release")
        wrapper_path = input_root / "installer" / "transcribe.py"
        wrapper_metadata = validate_unique_regular(wrapper_path, "payload STT wrapper")
        wrapper_size, wrapper_hash = sha256_file(wrapper_path)
        if wrapper_metadata.st_size != wrapper_size:
            raise RuntimeError("payload STT wrapper size changed after extraction")
        wrapper_identity = (wrapper_size, wrapper_hash)

        release = stage / "release"
        release.mkdir(mode=0o750)
        python_root = release / "python"
        python_root.mkdir(mode=0o700)
        extract_python_runtime(input_root / "python" / PYTHON_RUNTIME_FILENAME, python_root)
        root_home = stage / "root-home"
        root_home.mkdir(mode=0o700)
        install_wheels(release, input_root, root_home)
        copy_release_data(release, input_root)

        www_home = stage / "www-home"
        www_home.mkdir(mode=0o700)
        os.chown(www_home, runtime.pw_uid, runtime.pw_gid)
        normalize_permissions(release, group.gr_gid)
        audit_release_tree(release, group.gr_gid)
        audit_elf(release)
        pre_probe = run_www_probe(
            release,
            root,
            www_home,
            args.runtime_user,
            group.gr_gid,
            wrapper_identity,
        )

        tree = release_tree_manifest(release)
        tree_bytes = canonical_json(tree)
        atomic_write(release / "manifests" / "release-tree.json", tree_bytes, 0o640)
        receipt = {
            "schema_version": 1,
            "status": "prepared",
            "release_id": args.release_id,
            "python": "3.11.15",
            "faster_whisper": "1.2.1",
            "model_revision": MODEL_REVISION,
            "source_manifest_sha256": args.source_manifest_sha256,
            "payload_sha256": args.archive_sha256,
            "release_tree_sha256": hashlib.sha256(tree_bytes).hexdigest(),
            "pre_activation_probe": pre_probe,
        }
        atomic_write(release / "manifests" / "install-receipt.json", canonical_json(receipt), 0o640)
        normalize_permissions(release, group.gr_gid)
        audit_release_tree(release, group.gr_gid)
        fsync_release_tree(release)
        os.replace(release, destination)
        fsync_directory(releases)

        previous = read_current(stt_root)
        if previous is not None:
            previous_release = (stt_root / previous).resolve(strict=True)
            if not release_is_trusted(previous_release, group.gr_gid):
                raise RuntimeError("previous current release has no trusted committed receipt")
        atomic_current(stt_root, f"releases/{args.release_id}", args.token)
        switched = True
        if read_current(stt_root) != f"releases/{args.release_id}":
            raise RecoveryRequired("RECOVERY_REQUIRED=stt-current-readback-mismatch")
        post_home = stage / "post-www-home"
        post_home.mkdir(mode=0o700)
        os.chown(post_home, runtime.pw_uid, runtime.pw_gid)
        post_probe = run_www_probe(
            (stt_root / "current").resolve(strict=True),
            root,
            post_home,
            args.runtime_user,
            group.gr_gid,
            wrapper_identity,
        )

        receipt["status"] = "committed"
        receipt["previous_current"] = previous
        receipt["post_activation_probe"] = post_probe
        atomic_write(destination / "manifests" / "install-receipt.json", canonical_json(receipt), 0o640)
        os.chown(destination / "manifests" / "install-receipt.json", 0, group.gr_gid)
        os.chmod(destination / "manifests" / "install-receipt.json", 0o640)
        fsync_directory(destination / "manifests")
        audit_release_tree(destination, group.gr_gid)
        if not release_is_trusted(destination, group.gr_gid):
            raise RecoveryRequired("RECOVERY_REQUIRED=stt-committed-release-readback-failed")
        # Once the committed release and current link are independently
        # verified, the root-only input/home stage is no longer required.
        # Mark the current transaction settled before cleanup so a cleanup
        # failure never rolls back a healthy release; it instead emits an
        # explicit recovery gate with the unique stage left for inspection.
        switched = False
        try:
            shutil.rmtree(stage)
            fsync_directory(releases)
        except BaseException as cleanup_error:
            raise RecoveryRequired(
                "RECOVERY_REQUIRED=stt-stage-cleanup-failed; "
                f"detail={type(cleanup_error).__name__}"
            ) from cleanup_error
        return receipt
    except BaseException as exc:
        if switched:
            try:
                atomic_current(stt_root, previous, args.token)
                if read_current(stt_root) != previous:
                    raise RuntimeError("rollback current readback mismatch")
                if previous is not None:
                    rollback_home = stage / "rollback-www-home"
                    rollback_home.mkdir(mode=0o700)
                    os.chown(rollback_home, runtime.pw_uid, runtime.pw_gid)
                    run_www_probe(
                        (stt_root / previous).resolve(strict=True),
                        root,
                        rollback_home,
                        args.runtime_user,
                        group.gr_gid,
                        wrapper_identity,
                    )
            except BaseException as rollback:
                raise RecoveryRequired(
                    "RECOVERY_REQUIRED=stt-runtime-current-indeterminate; "
                    f"primary={type(exc).__name__}; rollback={type(rollback).__name__}"
                ) from rollback
            if previous is None:
                # The successful readback above proves current is absent.  A
                # first install has nothing trusted to reactivate, which is a
                # known recovery state rather than an indeterminate switch.
                raise RecoveryRequired("RECOVERY_REQUIRED=stt-no-prior-trusted-current")
        raise
    finally:
        try:
            token_path = lock / "token"
            if token_path.read_text(encoding="ascii").strip() != args.token:
                raise RecoveryRequired("RECOVERY_REQUIRED=stt-install-lock-ownership-indeterminate")
            token_path.unlink()
            lock.rmdir()
            fsync_directory(stt_root)
        except FileNotFoundError:
            pass


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument("--backend-root", required=True)
    result.add_argument("--archive", required=True)
    result.add_argument("--archive-size", type=int, required=True)
    result.add_argument("--archive-sha256", required=True)
    result.add_argument("--source-manifest-sha256", required=True)
    result.add_argument("--release-id", required=True)
    result.add_argument("--token", required=True)
    result.add_argument("--runtime-user", default=RUNTIME_USER)
    result.add_argument("--runtime-group", default=RUNTIME_GROUP)
    return result


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    receipt = install(args)
    print("STT_RUNTIME_RECEIPT=" + json.dumps(receipt, sort_keys=True, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except BaseException as exc:
        detail = str(exc)
        if "RECOVERY_REQUIRED=" not in detail:
            detail = "RECOVERY_REQUIRED=stt-runtime-execute-unproven; " + detail
        print(detail, file=sys.stderr)
        raise SystemExit(1)
