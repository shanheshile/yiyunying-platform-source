#!/usr/bin/env python3
"""Validate and atomically deploy the two private Android download tracks.

Dry-run is offline and is the default. Execution requires two exact confirmation
phrases, pinned SSH host keys, an explicitly identified existing PHP-FPM target,
and an Nginx host config which already includes the reviewed fragment path.
"""

from __future__ import annotations

import argparse
import base64
from dataclasses import dataclass
import hashlib
import hmac
import json
import os
from pathlib import Path
import posixpath
import re
import secrets
import shlex
import ssl
import stat
import subprocess
import sys
import time
import zipfile
from urllib.error import HTTPError
from urllib.parse import urlsplit, urlunsplit
from urllib.request import Request, urlopen

try:
    import paramiko
except ModuleNotFoundError:  # Offline dry-run intentionally needs no SSH package.
    paramiko = None


EXECUTE_CONFIRMATION = "INTERNAL_APK_PRIVATE_DEPLOY_EXECUTE_CONFIRMED"
NGINX_CONFIRMATION = "INTERNAL_APK_NGINX_AUTH_REQUEST_CONFIRMED"
SECRET_ENV = "YIYUNYING_INTERNAL_DOWNLOAD_SIGNING_SECRET"
EXPECTED_PRIVATE_ROOT = "/srv/yiyunying-internal-apks"
EXPECTED_SECRET_INCLUDE = "/etc/nginx/private/yiyunying-internal-apks-secret.conf"
PRIVATE_RUNTIME_GROUP = "www"
PRIVATE_DIRECTORY_MODE = 0o750
PRIVATE_FILE_MODE = 0o640
RELEASE_IDENTITY_PATH = "backend/config/release-identity.json"
ANDROID_VERSION_PROPERTIES_PATH = "android/version.properties"
FROZEN_DEBUG_MANIFEST_PATH = "releases/2.7.15/release-manifest.json"
LEGACY_UPGRADE_IDENTITY_PATH = "android/legacy-debug-upgrade-identity.json"
COMPATIBILITY_MANIFEST_ROOT = "releases/internal/legacy-debug-compat"
COMPATIBILITY_CHANNEL = "DebugCompatibility"
COMPATIBILITY_STATUS = "internal"
COMPATIBILITY_BUILD_TYPE = "legacyCompat"
PRODUCTION_API_BASE_URL = "https://appht.jjmxg.xyz/"
TRACKS = {
    "debug": {
        "manifest": FROZEN_DEBUG_MANIFEST_PATH,
        "version": "2.7.15",
        "code": 60,
        "channel": "Debug",
        "status": "finalized",
        "debug": True,
    },
    "candidate": {
        "manifest": "releases/1.0.0/release-manifest.json",
        "version": "1.0.0",
        # Stable candidates are rebuilt under the same public version name
        # until finalization. The validated release manifest is authoritative
        # for versionCode, while this floor prevents an old candidate from
        # being deployed after code 64 has entered the release line.
        "minimum_code": 64,
        "channel": "Stable",
        "status": "pending",
        "debug": False,
    },
}
ROLES = {
    "user": ("user", "user", "xyz.jjmxg.yiyunying.user"),
    "admin": ("admin", "admin", "xyz.jjmxg.yiyunying.admin"),
    "authorized": (
        "authorized-platform",
        "authorized-platform",
        "xyz.jjmxg.yiyunying.authorized",
    ),
    "owner": ("platform-owner", "platform-owner", "xyz.jjmxg.yiyunying.platformowner"),
}
PACKAGE_LINE = re.compile(
    r"^package:\s+name='(?P<package>[^']+)'\s+versionCode='(?P<code>\d+)'\s+"
    r"versionName='(?P<name>[^']+)'"
)
SIGNER_LINE = re.compile(
    r"^Signer #1 certificate SHA-256 digest:\s*(?P<digest>[0-9A-Fa-f]{64})\s*$",
    re.MULTILINE,
)
SHA256 = re.compile(r"^[0-9A-Fa-f]{64}$")
SAFE_VERSION = re.compile(r"^\d+\.\d+\.\d+$")
SAFE_COMPILED_XML = re.compile(r"^res/[A-Za-z0-9._/-]+\.xml$")
SAFE_PACKAGE_NAME = re.compile(r"^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$")
SAFE_REMOTE_FILE = re.compile(r"^/[A-Za-z0-9._/-]+$")
SAFE_FPM = re.compile(
    r"^(?:unix:/[A-Za-z0-9._/-]+\.sock|[A-Za-z_][A-Za-z0-9_]*|(?:127\.0\.0\.1|\[::1\]):[1-9][0-9]{0,4})$"
)


@dataclass(frozen=True)
class ApkArtifact:
    track: str
    role: str
    version: str
    version_code: int
    file_name: str
    package_name: str
    version_name: str
    signer_sha256: str
    path: Path
    size: int
    sha256: str

    @property
    def remote_relative(self) -> str:
        return f"{self.track}/{self.version}/{self.file_name}"

    @property
    def public_path(self) -> str:
        return f"/__internal-apks/{self.remote_relative}"


@dataclass(frozen=True)
class LocalFile:
    path: Path
    size: int
    sha256: str


@dataclass(frozen=True)
class ReleaseIdentity:
    version_name: str
    version_code: int
    stable_signer_sha256: str


@dataclass(frozen=True)
class LegacyUpgradeIdentity:
    maximum_version_code: int
    signer_sha256: str
    packages: dict[str, str]
    connection_identity: dict[str, str]


def quote(value: str) -> str:
    return shlex.quote(value)


def sha256_file(path: Path) -> tuple[int, str]:
    digest = hashlib.sha256()
    size = 0
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            size += len(block)
            digest.update(block)
    return size, digest.hexdigest()


def local_file(path: Path, label: str) -> LocalFile:
    path = path.resolve()
    if not path.is_file() or path.is_symlink():
        raise RuntimeError(f"{label} must be a regular non-symlink file")
    size, digest = sha256_file(path)
    return LocalFile(path, size, digest)


def read_json(path: Path, label: str) -> dict:
    item = local_file(path, label)
    try:
        value = json.loads(item.path.read_text(encoding="utf-8"))
    except (UnicodeError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"Cannot parse {label}") from exc
    if not isinstance(value, dict):
        raise RuntimeError(f"{label} must contain an object")
    return value


def run_local(command: list[str], label: str, cwd: Path | None = None) -> str:
    result = subprocess.run(
        command,
        cwd=str(cwd) if cwd else None,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        encoding="utf-8",
        errors="replace",
        timeout=180,
        check=False,
    )
    if result.returncode != 0:
        detail = result.stdout.strip()[:800]
        raise RuntimeError(f"{label} failed ({result.returncode}): {detail}")
    return result.stdout


def sdk_root(repository_root: Path) -> Path | None:
    properties = repository_root / "android" / "local.properties"
    if properties.is_file():
        for line in properties.read_text(encoding="utf-8").splitlines():
            if line.startswith("sdk.dir="):
                value = line.split("=", 1)[1].replace(r"\:", ":").replace("\\", "/")
                candidate = Path(value)
                if candidate.is_dir():
                    return candidate
    for variable in ("ANDROID_SDK_ROOT", "ANDROID_HOME"):
        value = os.environ.get(variable, "")
        if value and Path(value).is_dir():
            return Path(value)
    return None


def resolve_android_tool(
    repository_root: Path, name: str, override: Path | None
) -> Path:
    if override:
        candidate = override.resolve()
        if not candidate.is_file():
            raise RuntimeError(f"Configured {name} does not exist")
        return candidate
    root = sdk_root(repository_root)
    if root:
        build_tools = root / "build-tools"
        if build_tools.is_dir():
            directories = sorted(
                (item for item in build_tools.iterdir() if item.is_dir()),
                key=lambda item: tuple(int(number) for number in re.findall(r"\d+", item.name)),
                reverse=True,
            )
            extensions = (".exe", ".bat", ".cmd", "") if os.name == "nt" else ("",)
            for directory in directories:
                for extension in extensions:
                    candidate = directory / f"{name}{extension}"
                    if candidate.is_file():
                        return candidate.resolve()
    raise RuntimeError(f"Cannot find Android tool {name}; pass --{name}")


def exact_text(value: object, label: str) -> str:
    if not isinstance(value, str) or not value or value != value.strip():
        raise RuntimeError(f"Invalid manifest field: {label}")
    return value


def exact_positive_int(value: object, label: str) -> int:
    if not isinstance(value, int) or isinstance(value, bool) or value <= 0:
        raise RuntimeError(f"Invalid manifest field: {label}")
    return value


def load_android_version(
    repository_root: Path, legacy_maximum_version_code: int = 60
) -> tuple[str, int]:
    path = repository_root / ANDROID_VERSION_PROPERTIES_PATH
    item = local_file(path, "Android version properties")
    values: dict[str, str] = {}
    for line in item.path.read_text(encoding="utf-8").splitlines():
        match = re.fullmatch(r"\s*([A-Z_]+)\s*=\s*(.*?)\s*", line)
        if match:
            values[match.group(1)] = match.group(2)
    version = values.get("VERSION_NAME", "")
    raw_code = values.get("VERSION_CODE", "")
    if not SAFE_VERSION.fullmatch(version) or not raw_code.isdigit():
        raise RuntimeError("Invalid Android global version properties")
    code = int(raw_code)
    if code <= legacy_maximum_version_code:
        raise RuntimeError(
            "Debug compatibility versionCode must be greater than the tracked legacy maximum"
        )
    return version, code


def load_legacy_upgrade_identity(repository_root: Path) -> LegacyUpgradeIdentity:
    anchor = read_json(
        repository_root / LEGACY_UPGRADE_IDENTITY_PATH,
        "tracked legacy upgrade identity anchor",
    )
    if anchor.get("schemaVersion") != 1:
        raise RuntimeError("Tracked legacy upgrade identity schema is invalid")
    maximum = anchor.get("legacyUpgradeMaximumVersionCode")
    signer = anchor.get("legacyPackageSignerSha256")
    packages = anchor.get("packages")
    if maximum != 60 or not isinstance(signer, str) or not re.fullmatch(r"[0-9A-F]{64}", signer):
        raise RuntimeError("Tracked legacy upgrade identity anchor is malformed")
    if not isinstance(packages, dict) or set(packages) != set(ROLES):
        raise RuntimeError("Tracked legacy package identities are incomplete")
    normalized: dict[str, str] = {}
    for role in ROLES:
        package = exact_text(packages.get(role), f"legacyAnchor.packages.{role}")
        if not SAFE_PACKAGE_NAME.fullmatch(package) or not package.endswith(".debug"):
            raise RuntimeError(f"Tracked legacy package identity is invalid: {role}")
        normalized[role] = package
    connection_identity = validate_connection_hashes(
        anchor.get("connectionIdentity"), "tracked legacy connectionIdentity"
    )
    return LegacyUpgradeIdentity(maximum, signer, normalized, connection_identity)


def frozen_debug_signer(
    repository_root: Path, anchor: LegacyUpgradeIdentity
) -> str:
    manifest = read_json(
        repository_root / FROZEN_DEBUG_MANIFEST_PATH, "frozen Debug manifest"
    )
    if manifest.get("versionCode") != anchor.maximum_version_code:
        raise RuntimeError("Frozen Debug signer anchor has an unexpected versionCode")
    releases = manifest.get("releases")
    if not isinstance(releases, list) or len(releases) != len(ROLES):
        raise RuntimeError("Frozen Debug signer anchor must contain four APKs")
    roles: set[str] = set()
    signers: set[str] = set()
    for raw in releases:
        if not isinstance(raw, dict):
            raise RuntimeError("Frozen Debug signer anchor is malformed")
        role = exact_text(raw.get("id"), "frozenDebug.id")
        roles.add(role)
        signer = exact_text(raw.get("signerSha256"), "frozenDebug.signer").upper()
        if not SHA256.fullmatch(signer):
            raise RuntimeError("Frozen Debug signer anchor is malformed")
        signers.add(signer)
        if role not in anchor.packages or raw.get("packageName") != anchor.packages[role]:
            raise RuntimeError("Frozen Debug package identity differs from tracked anchor")
    if (
        roles != set(ROLES)
        or signers != {anchor.signer_sha256}
    ):
        raise RuntimeError("Frozen Debug signer anchor is inconsistent")
    frozen_connection_identity = manifest.get("connectionIdentity")
    if frozen_connection_identity is not None:
        if not isinstance(frozen_connection_identity, dict):
            raise RuntimeError("Frozen Debug connection identity is malformed")
        frozen_hashes = validate_connection_hashes(
            {
                field: frozen_connection_identity.get(field)
                for field in CONNECTION_HASH_FIELDS
            },
            "frozen Debug connectionIdentity",
        )
        if frozen_hashes != anchor.connection_identity:
            raise RuntimeError(
                "Frozen Debug connection identity differs from tracked legacy anchor"
            )
    return anchor.signer_sha256


CONNECTION_HASH_FIELDS = (
    "appKeySha256",
    "platformKeySha256",
    "authorizedPlatformKeySha256",
)


def validate_connection_hashes(value: object, label: str) -> dict[str, str]:
    if not isinstance(value, dict) or set(value) != set(CONNECTION_HASH_FIELDS):
        raise RuntimeError(f"{label} must contain exactly three connection identity hashes")
    result: dict[str, str] = {}
    for field in CONNECTION_HASH_FIELDS:
        digest = value.get(field)
        if not isinstance(digest, str) or re.fullmatch(r"[0-9a-f]{64}", digest) is None:
            raise RuntimeError(f"{label}.{field} must be a lowercase SHA-256 digest")
        result[field] = digest
    return result


def stable_pending_connection_hashes(
    repository_root: Path,
    version: str,
    version_code: int,
    anchor: LegacyUpgradeIdentity,
) -> dict[str, str]:
    stable = read_json(
        repository_root / str(TRACKS["candidate"]["manifest"]),
        "tracked Stable pending manifest",
    )
    if (
        stable.get("schemaVersion") != 4
        or stable.get("channel") != "Stable"
        or stable.get("finalizationStatus") != "pending"
        or stable.get("versionName") != version
        or stable.get("versionCode") != version_code
    ):
        raise RuntimeError("Tracked Stable pending manifest does not match compatibility version")
    identity = stable.get("connectionIdentity")
    if (
        not isinstance(identity, dict)
        or set(identity) != {"apiBaseUrl", *CONNECTION_HASH_FIELDS}
        or identity.get("apiBaseUrl") != PRODUCTION_API_BASE_URL
    ):
        raise RuntimeError("Tracked Stable pending connection identity is invalid")
    stable_hashes = validate_connection_hashes(
        {field: identity.get(field) for field in CONNECTION_HASH_FIELDS},
        "Stable connectionIdentity",
    )
    if stable_hashes != anchor.connection_identity:
        raise RuntimeError(
            "Stable pending connection identity differs from tracked historical anchor"
        )
    return stable_hashes


def effective_tracks(
    repository_root: Path,
    debug_compatibility_manifest: Path | None,
    anchor: LegacyUpgradeIdentity,
) -> dict[str, dict]:
    policies = {track: dict(policy) for track, policy in TRACKS.items()}
    if debug_compatibility_manifest is None:
        return policies
    version, code = load_android_version(repository_root, anchor.maximum_version_code)
    manifest_path = debug_compatibility_manifest
    if not manifest_path.is_absolute():
        manifest_path = repository_root / manifest_path
    manifest_path = manifest_path.resolve()
    expected = (
        repository_root
        / COMPATIBILITY_MANIFEST_ROOT
        / version
        / "release-manifest.json"
    ).resolve()
    if manifest_path != expected:
        raise RuntimeError(
            "Debug compatibility manifest must use the reviewed internal release path"
        )
    local_file(manifest_path, "Debug compatibility manifest")
    policies["debug"] = {
        "manifest": manifest_path,
        "version": version,
        "code": code,
        "channel": COMPATIBILITY_CHANNEL,
        "status": COMPATIBILITY_STATUS,
        "debug": True,
        "compatibility": True,
    }
    return policies


def validate_compatibility_contract(
    manifest: dict,
    anchor: LegacyUpgradeIdentity,
    stable_connection_hashes: dict[str, str],
) -> None:
    expected = {
        "schemaVersion": 2,
        "channel": COMPATIBILITY_CHANNEL,
        "finalizationStatus": COMPATIBILITY_STATUS,
        "distribution": "internal-only",
        "buildType": COMPATIBILITY_BUILD_TYPE,
        "debuggable": False,
        "testOnly": False,
        "apiBaseUrl": PRODUCTION_API_BASE_URL,
        "cleartextTrafficPermitted": False,
        "trustAnchors": ["system"],
        "followRedirects": False,
        "apkSignatureSchemeV2": True,
        "signerCount": 1,
        "dexTransportVerified": True,
        "legacyUpgradeMaximumVersionCode": anchor.maximum_version_code,
    }
    if any(manifest.get(name) != value for name, value in expected.items()):
        raise RuntimeError(
            "Debug compatibility manifest violates the HTTPS/non-debuggable contract"
        )
    if manifest.get("legacyPackageSignerSha256") != anchor.signer_sha256:
        raise RuntimeError("Debug compatibility signer anchor mismatch")
    compatibility_hashes = validate_connection_hashes(
        manifest.get("connectionIdentity"), "Debug compatibility connectionIdentity"
    )
    if compatibility_hashes != stable_connection_hashes:
        raise RuntimeError(
            "Debug compatibility connection identity does not match Stable pending metadata"
        )
    if compatibility_hashes != anchor.connection_identity:
        raise RuntimeError(
            "Debug compatibility connection identity differs from tracked historical anchor"
        )


def track_version_code(manifest: dict, policy: dict, track: str) -> int:
    version_code = exact_positive_int(
        manifest.get("versionCode"), f"{track}.versionCode"
    )
    pinned_code = policy.get("code")
    if pinned_code is not None and version_code != pinned_code:
        raise RuntimeError(f"Release versionCode mismatch for {track}")
    minimum_code = policy.get("minimum_code")
    if minimum_code is not None and version_code < minimum_code:
        raise RuntimeError(
            f"Release versionCode for {track} is below the minimum {minimum_code}"
        )
    return version_code


def load_release_identity(repository_root: Path) -> ReleaseIdentity:
    identity = read_json(
        repository_root / RELEASE_IDENTITY_PATH, "release identity"
    )
    version_name = exact_text(identity.get("version_name"), "identity.version_name")
    version_code = exact_positive_int(
        identity.get("version_code"), "identity.version_code"
    )
    signer = exact_text(
        identity.get("stable_signer_sha256"), "identity.stable_signer_sha256"
    ).upper()
    if not SHA256.fullmatch(signer):
        raise RuntimeError("Invalid release identity Stable signer")
    return ReleaseIdentity(version_name, version_code, signer)


def resolve_compiled_network_security_resource(
    manifest_text: str, resources_text: str
) -> str:
    references = re.findall(
        r"android:networkSecurityConfig[^=]*=@0x(?P<id>[0-9A-Fa-f]{8})",
        manifest_text,
    )
    if len(references) != 1:
        raise RuntimeError(
            "Compiled manifest must contain one networkSecurityConfig resource reference"
        )
    resource_id = "0x" + references[0].lower()
    lines = resources_text.splitlines()
    indices = [
        index
        for index, line in enumerate(lines)
        if re.fullmatch(
            rf"\s*resource\s+{re.escape(resource_id)}\s+xml/\S+\s*",
            line,
            flags=re.IGNORECASE,
        )
    ]
    if len(indices) != 1:
        raise RuntimeError("Compiled network security resource id is absent or duplicated")
    for line in lines[indices[0] + 1 :]:
        if re.match(r"\s*resource\s+0x[0-9A-Fa-f]+\s+", line):
            break
        match = re.fullmatch(
            r"\s*\(\)\s+\(file\)\s+(?P<path>res/[A-Za-z0-9._/-]+\.xml)\s+type=XML\s*",
            line,
        )
        if match:
            path = match.group("path")
            if not SAFE_COMPILED_XML.fullmatch(path) or ".." in path or "//" in path:
                raise RuntimeError("Compiled network security resource path is unsafe")
            return path
    raise RuntimeError("Compiled network security resource has no default XML file")


def validate_compiled_network_security_output(network_text: str) -> None:
    if (
        re.search(r"(?m)A:\s+cleartextTrafficPermitted=false(?:\s|$)", network_text)
        is None
        or re.search(r"(?m)A:\s+cleartextTrafficPermitted=true(?:\s|$)", network_text)
        is not None
    ):
        raise RuntimeError("Compiled compatibility network config must set cleartext=false")
    source_lines = [
        line
        for line in network_text.splitlines()
        if re.search(r"^\s*A:\s+src(?:=|\()", line)
    ]
    if not source_lines or any(
        re.search(r'^\s*A:\s+src="system"(?:\s|$)', line) is None
        for line in source_lines
    ):
        raise RuntimeError(
            "Compiled compatibility certificate sources must all be system"
        )


def validate_compatibility_dex_transport(apk_path: Path) -> None:
    required = b"https://appht.jjmxg.xyz/\x00"
    forbidden = tuple(
        endpoint + b"\x00"
        for endpoint in (
            b"http://appht.jjmxg.xyz/",
            b"http://appht.jjmxg.xyz",
            b"http://127.0.0.1:8788/",
            b"http://127.0.0.1:8788",
            b"http://10.0.2.2:8788/",
            b"http://10.0.2.2:8788",
        )
    )
    patterns = (required, *forbidden)
    overlap = max(len(pattern) for pattern in patterns) - 1
    required_found = False
    try:
        with zipfile.ZipFile(apk_path) as archive:
            dex_names = [
                info.filename
                for info in archive.infolist()
                if re.fullmatch(r"classes(?:\d+)?\.dex", info.filename)
            ]
            if not dex_names:
                raise RuntimeError("Compatibility APK contains no DEX")
            for name in dex_names:
                tail = b""
                with archive.open(name) as stream:
                    while True:
                        chunk = stream.read(1024 * 1024)
                        if not chunk:
                            break
                        data = tail + chunk
                        required_found = required_found or required in data
                        for endpoint in forbidden:
                            if endpoint in data:
                                raise RuntimeError(
                                    "Compatibility DEX contains a forbidden exact endpoint"
                                )
                        tail = data[-overlap:]
    except (OSError, zipfile.BadZipFile) as exc:
        raise RuntimeError("Cannot inspect compatibility APK DEX") from exc
    if not required_found:
        raise RuntimeError(
            "Compatibility DEX is missing the exact production HTTPS endpoint"
        )


def validate_apk_with_tools(
    artifact: ApkArtifact,
    aapt2: Path,
    apksigner: Path,
    *,
    compatibility_network_resource: str | None = None,
) -> None:
    # Work from the APK directory so older Android tools do not receive a
    # Unicode-heavy absolute APK path on Windows.
    badging = run_local(
        [str(aapt2), "dump", "badging", artifact.path.name],
        f"aapt2 identity for {artifact.track}/{artifact.role}",
        artifact.path.parent,
    )
    first = next((line for line in badging.splitlines() if line.startswith("package:")), "")
    match = PACKAGE_LINE.match(first)
    if not match or (
        match.group("package") != artifact.package_name
        or int(match.group("code")) != artifact.version_code
        or match.group("name") != artifact.version_name
    ):
        raise RuntimeError(f"APK identity mismatch: {artifact.track}/{artifact.role}")
    if compatibility_network_resource is not None and (
        re.search(r"(?m)^application-debuggable\s*$", badging)
        or re.search(r"(?im)^application-test-only\s*$", badging)
        or re.search(r"(?i)testOnly\s*=\s*'?true'?", badging)
    ):
        raise RuntimeError(
            f"Compatibility APK is debuggable or testOnly: {artifact.role}"
        )
    signer_output = run_local(
        [
            str(apksigner),
            "verify",
            "--verbose",
            "--print-certs",
            artifact.path.name,
        ],
        f"APK signature for {artifact.track}/{artifact.role}",
        artifact.path.parent,
    )
    signers = SIGNER_LINE.findall(signer_output)
    if len(signers) != 1 or signers[0].upper() != artifact.signer_sha256:
        raise RuntimeError(f"APK signer mismatch: {artifact.track}/{artifact.role}")
    if compatibility_network_resource is None:
        return
    if (
        re.search(
            r"(?m)^Verified using v2 scheme \(APK Signature Scheme v2\): true\s*$",
            signer_output,
        )
        is None
        or re.search(r"(?m)^Number of signers: 1\s*$", signer_output) is None
    ):
        raise RuntimeError(
            f"Compatibility APK must use v2 with one signer: {artifact.role}"
        )
    manifest_output = run_local(
        [
            str(aapt2),
            "dump",
            "xmltree",
            artifact.path.name,
            "--file",
            "AndroidManifest.xml",
        ],
        f"compiled manifest for compatibility/{artifact.role}",
        artifact.path.parent,
    )
    resources_output = run_local(
        [str(aapt2), "dump", "resources", artifact.path.name],
        f"compiled resources for compatibility/{artifact.role}",
        artifact.path.parent,
    )
    actual_network_resource = resolve_compiled_network_security_resource(
        manifest_output, resources_output
    )
    if actual_network_resource != compatibility_network_resource:
        raise RuntimeError(
            f"Compatibility compiled network resource differs from manifest: {artifact.role}"
        )
    network_output = run_local(
        [
            str(aapt2),
            "dump",
            "xmltree",
            artifact.path.name,
            "--file",
            actual_network_resource,
        ],
        f"compiled network config for compatibility/{artifact.role}",
        artifact.path.parent,
    )
    validate_compiled_network_security_output(network_output)
    validate_compatibility_dex_transport(artifact.path)


def validate_artifacts(
    repository_root: Path,
    aapt2: Path,
    apksigner: Path,
    debug_compatibility_manifest: Path | None = None,
) -> list[ApkArtifact]:
    artifacts: list[ApkArtifact] = []
    release_identity = load_release_identity(repository_root)
    legacy_anchor = load_legacy_upgrade_identity(repository_root)
    policies = effective_tracks(
        repository_root, debug_compatibility_manifest, legacy_anchor
    )
    expected_debug_signer = frozen_debug_signer(repository_root, legacy_anchor)
    stable_connection_identity: dict[str, str] | None = None
    if policies["debug"].get("compatibility"):
        stable_connection_identity = stable_pending_connection_hashes(
            repository_root,
            str(policies["debug"]["version"]),
            int(policies["debug"]["code"]),
            legacy_anchor,
        )
    if release_identity.version_name != TRACKS["candidate"]["version"]:
        raise RuntimeError(
            "Release identity version is incompatible with the reviewed candidate route"
        )
    for track, policy in policies.items():
        manifest_path = Path(policy["manifest"])
        if not manifest_path.is_absolute():
            manifest_path = repository_root / manifest_path
        manifest = read_json(manifest_path, f"{track} release manifest")
        version_code = track_version_code(manifest, policy, track)
        expected_version = str(policy["version"])
        channel = manifest.get("channel")
        # The frozen 2.7.15 Debug schema predates the explicit channel field;
        # no other missing channel is accepted.
        if track == "debug" and policy.get("compatibility"):
            assert stable_connection_identity is not None
            validate_compatibility_contract(
                manifest, legacy_anchor, stable_connection_identity
            )
        elif track == "debug":
            if channel not in (None, "Debug"):
                raise RuntimeError("2.7.15 must remain the frozen Debug track")
        elif channel != policy["channel"]:
            raise RuntimeError("1.0.0 must remain Stable")
        if track == "candidate" and (
            manifest.get("versionName") != release_identity.version_name
            or version_code != release_identity.version_code
        ):
            raise RuntimeError(
                "Candidate manifest version/code does not match release identity"
            )
        if (
            manifest.get("versionName") != expected_version
            or manifest.get("finalizationStatus") != policy["status"]
        ):
            raise RuntimeError(f"Release state mismatch for {track}")
        releases = manifest.get("releases")
        if not isinstance(releases, list) or len(releases) != 4:
            raise RuntimeError(f"{track} manifest must contain exactly four APKs")
        by_role = {}
        for raw in releases:
            if not isinstance(raw, dict):
                raise RuntimeError(f"Malformed release in {track}")
            role = exact_text(raw.get("id"), f"{track}.id")
            if role not in ROLES or role in by_role:
                raise RuntimeError(f"Unknown or duplicate role in {track}")
            by_role[role] = raw
        for role, (file_stem, version_suffix, base_package) in ROLES.items():
            raw = by_role[role]
            debug_suffix = "-debug" if policy["debug"] else ""
            file_name = f"yiyunying-{file_stem}-v{expected_version}{debug_suffix}.apk"
            package_name = (
                legacy_anchor.packages[role]
                if policy["debug"]
                else base_package
            )
            version_name = f"{expected_version}-{version_suffix}{debug_suffix}"
            signer = exact_text(raw.get("signerSha256"), f"{track}.{role}.signer").upper()
            declared_sha = exact_text(raw.get("sha256"), f"{track}.{role}.sha256").upper()
            declared_size = exact_positive_int(raw.get("sizeBytes"), f"{track}.{role}.size")
            if track == "debug" and policy.get("compatibility"):
                network_resource = exact_text(
                    raw.get("networkSecurityResource"), f"{track}.{role}.networkSecurityResource"
                )
                if (
                    not SAFE_COMPILED_XML.fullmatch(network_resource)
                    or ".." in network_resource
                    or "//" in network_resource
                ):
                    raise RuntimeError(f"Unsafe compiled network security resource: {role}")
            if not SHA256.fullmatch(signer) or not SHA256.fullmatch(declared_sha):
                raise RuntimeError(f"Invalid digest in {track}/{role}")
            if (
                track == "debug"
                and expected_debug_signer is not None
                and signer != expected_debug_signer
            ):
                raise RuntimeError(f"Debug compatibility signer mismatch: {role}")
            if (
                track == "candidate"
                and signer != release_identity.stable_signer_sha256
            ):
                raise RuntimeError(
                    f"Candidate Stable signer does not match release identity: {role}"
                )
            if (
                raw.get("fileName") != file_name
                or raw.get("packageName") != package_name
                or raw.get("versionName") != version_name
                or raw.get("versionCode") != version_code
            ):
                raise RuntimeError(f"Manifest identity mismatch: {track}/{role}")
            apk_path = manifest_path.parent / file_name
            if not apk_path.is_file() or apk_path.is_symlink():
                raise RuntimeError(f"Missing regular APK: {track}/{role}")
            size, digest = sha256_file(apk_path)
            if size != declared_size or digest.upper() != declared_sha:
                raise RuntimeError(f"APK size/SHA mismatch: {track}/{role}")
            artifact = ApkArtifact(
                track,
                role,
                expected_version,
                version_code,
                file_name,
                package_name,
                version_name,
                signer,
                apk_path.resolve(),
                size,
                digest,
            )
            validate_apk_with_tools(
                artifact,
                aapt2,
                apksigner,
                compatibility_network_resource=(
                    network_resource
                    if track == "debug" and policy.get("compatibility")
                    else None
                ),
            )
            artifacts.append(artifact)
    return artifacts


def validate_remote_path(value: str, label: str) -> str:
    if (
        not value
        or not SAFE_REMOTE_FILE.fullmatch(value)
        or posixpath.normpath(value) != value
        or "//" in value
        or "/../" in value
    ):
        raise RuntimeError(f"{label} must be a normalized absolute POSIX path")
    return value


def validate_private_root(value: str) -> str:
    value = validate_remote_path(value, "--remote-private-root")
    if value != EXPECTED_PRIVATE_ROOT:
        raise RuntimeError(f"Private root is pinned to {EXPECTED_PRIVATE_ROOT}")
    return value


def validate_fpm_upstream(value: str) -> str:
    if not SAFE_FPM.fullmatch(value):
        raise RuntimeError("--fpm-upstream must be an explicit local socket/upstream")
    if value.startswith("127.0.0.1:") or value.startswith("[::1]:"):
        port = int(value.rsplit(":", 1)[1])
        if port > 65535:
            raise RuntimeError("FPM port is invalid")
    return value


def validate_php_binary(value: str) -> str:
    value = validate_remote_path(value, "--remote-php-binary")
    if posixpath.basename(value) != "php" or len([part for part in value.split("/") if part]) < 4:
        raise RuntimeError("--remote-php-binary must be a specific absolute PHP executable")
    return value


def validate_host_include_pattern(value: str, target: str) -> str:
    if value == target:
        return value
    if not value.endswith("/*.conf") or value.count("*") != 1:
        raise RuntimeError(
            "--remote-nginx-host-include must be the exact target or one explicit /*.conf include"
        )
    directory = value[:-7]
    validate_remote_path(directory, "--remote-nginx-host-include directory")
    if posixpath.dirname(target) != directory or not target.endswith(".conf"):
        raise RuntimeError("Nginx fragment is not covered by the explicit host include")
    return value


def validate_deployment_sources(repository_root: Path) -> tuple[LocalFile, LocalFile]:
    template = local_file(
        repository_root / "download-site/deploy/nginx-internal-apks-auth-request.conf",
        "reviewed Nginx auth_request template",
    )
    verifier = local_file(
        repository_root / "download-site/deploy/internal-apk-verifier.php",
        "private PHP verifier",
    )
    nginx = template.path.read_text(encoding="utf-8")
    php = verifier.path.read_text(encoding="utf-8")
    required_nginx = (
        "YIYUNYING_INTERNAL_APKS_AUTH_REQUEST_V1",
        "auth_request /__internal-apks-auth;",
        "error_page 401 =404",
        "error_page 403 =410",
        "fastcgi_pass __YY_FPM_UPSTREAM__;",
        "include __YY_SECRET_INCLUDE__;",
        "alias __YY_PRIVATE_ROOT__/current/debug/__YY_DEBUG_VERSION__/$apk;",
        "fastcgi_param YY_INTERNAL_DEBUG_VERSION __YY_DEBUG_VERSION__;",
        "__YY_DEBUG_VERSION_REGEX__",
        "alias __YY_PRIVATE_ROOT__/current/candidate/1.0.0/$apk;",
        "access_log off;",
        "max_ranges 1;",
        "location /__internal-apks/",
    )
    if any(marker not in nginx for marker in required_nginx):
        raise RuntimeError("Reviewed Nginx template is incomplete")
    if "secure_link" in nginx or "location ^~ /__internal-apks/" in nginx:
        raise RuntimeError("Reviewed Nginx template contains an unavailable/bypassing rule")
    if nginx.count("auth_request /__internal-apks-auth;") != 2:
        raise RuntimeError("Exactly two private APK locations must be authenticated")
    required_php = (
        "hash_hmac('sha256'",
        "hex2bin($secretHex)",
        "$parameters['expires'] . \"\\n\" . $path",
        "hash_equals($expected, $parameters['sig'])",
        "finish_verification(403)",
        "finish_verification(204)",
        "YY_INTERNAL_DEBUG_VERSION",
        "preg_quote($debugVersion, '~')",
    )
    if any(marker not in php for marker in required_php):
        raise RuntimeError("Private PHP verifier is incomplete")
    if "md5" in php.lower() or "$_GET" in php:
        raise RuntimeError("Private PHP verifier uses an unsafe signature/query path")
    return template, verifier


def validate_version_segment(value: str) -> str:
    if not SAFE_VERSION.fullmatch(value):
        raise RuntimeError("Private Debug version must use major.minor.patch format")
    return value


def render_nginx(
    template: LocalFile,
    private_root: str,
    secret_include: str,
    fpm_upstream: str,
    debug_version: str,
) -> bytes:
    text = template.path.read_text(encoding="utf-8")
    debug_version = validate_version_segment(debug_version)
    replacements = {
        "__YY_PRIVATE_ROOT__": private_root,
        "__YY_SECRET_INCLUDE__": secret_include,
        "__YY_FPM_UPSTREAM__": fpm_upstream,
        "__YY_DEBUG_VERSION__": debug_version,
        "__YY_DEBUG_VERSION_REGEX__": re.escape(debug_version),
    }
    for marker, value in replacements.items():
        if text.count(marker) == 0:
            raise RuntimeError(f"Missing Nginx template marker: {marker}")
        text = text.replace(marker, value)
    if "__YY_" in text or SECRET_ENV in text or "secure_link" in text:
        raise RuntimeError("Rendered Nginx config is unsafe")
    return text.encode("utf-8")


def normalize_origin(value: str) -> str:
    parsed = urlsplit(value.strip())
    if (
        parsed.scheme.lower() != "https"
        or not parsed.hostname
        or parsed.username is not None
        or parsed.password is not None
        or parsed.path not in ("", "/")
        or parsed.query
        or parsed.fragment
    ):
        raise RuntimeError("--public-origin must be a bare HTTPS origin")
    return urlunsplit(("https", parsed.netloc.lower(), "", "", ""))


def connect_ssh(args: argparse.Namespace):
    if paramiko is None:
        raise RuntimeError("Paramiko is required only for --execute")
    known_hosts = Path(args.known_hosts).resolve()
    if not known_hosts.is_file() or known_hosts.is_symlink():
        raise RuntimeError("--known-hosts must be a regular pinned host-key file")
    client = paramiko.SSHClient()
    client.load_host_keys(str(known_hosts))
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    client.connect(
        args.host,
        port=args.port,
        username=args.username,
        password=os.environ["YY_SSH_PASSWORD"],
        look_for_keys=False,
        allow_agent=False,
        timeout=20,
        banner_timeout=20,
        auth_timeout=20,
    )
    return client


def run(client, command: str, label: str) -> str:
    _, stdout, stderr = client.exec_command(command, get_pty=False)
    output = stdout.read().decode("utf-8", errors="replace").strip()
    error = stderr.read().decode("utf-8", errors="replace").strip()
    status_code = stdout.channel.recv_exit_status()
    if status_code != 0:
        detail = (error or output)[:800]
        raise RuntimeError(f"{label} failed ({status_code}): {detail}")
    return output


def ensure_active_ssh(client, args: argparse.Namespace):
    transport = client.get_transport() if client is not None else None
    if transport is not None and transport.is_active():
        return client
    if client is not None:
        client.close()
    return connect_ssh(args)


def remote_identity(client, path: str) -> tuple[int, str]:
    output = run(
        client,
        f"test -f {quote(path)} && test ! -L {quote(path)} && "
        f"printf '%s ' \"$(stat -c %s {quote(path)})\" && "
        f"sha256sum {quote(path)} | awk '{{print $1}}'",
        "remote file identity",
    )
    parts = output.split()
    if len(parts) != 2 or not parts[0].isdigit() or not re.fullmatch(r"[0-9a-f]{64}", parts[1]):
        raise RuntimeError("Remote file identity output is malformed")
    return int(parts[0]), parts[1]


def resolve_remote_group_gid(client, group: str) -> int:
    if not re.fullmatch(r"[a-z_][a-z0-9_-]{0,31}", group):
        raise RuntimeError("Remote runtime group name is invalid")
    output = run(
        client,
        " ; ".join(
            (
                "set -eu",
                f"entry=$(getent group {quote(group)})",
                'test -n "$entry"',
                "gid=$(printf '%s\\n' \"$entry\" | awk -F: 'NR == 1 { print $3 }')",
                'test -n "$gid"',
                "printf '%s' \"$gid\"",
            )
        ),
        "resolve private runtime group",
    )
    if not re.fullmatch(r"[1-9][0-9]*", output):
        raise RuntimeError("Remote runtime group identity is malformed")
    return int(output)


def private_tree_contract_command(
    root: str,
    directories: list[str],
    files: list[str],
    runtime_gid: int,
) -> str:
    root = validate_remote_path(root, "private tree root")
    if not isinstance(runtime_gid, int) or runtime_gid <= 0:
        raise RuntimeError("Private runtime group id is invalid")
    normalized_directories = [
        validate_remote_path(path, "private tree directory") for path in directories
    ]
    normalized_files = [validate_remote_path(path, "private tree file") for path in files]
    prefix = root.rstrip("/") + "/"
    if (
        root not in normalized_directories
        or len(set(normalized_directories)) != len(normalized_directories)
        or len(set(normalized_files)) != len(normalized_files)
        or set(normalized_directories).intersection(normalized_files)
        or any(path != root and not path.startswith(prefix) for path in normalized_directories)
        or any(not path.startswith(prefix) for path in normalized_files)
    ):
        raise RuntimeError("Private tree contract paths are incomplete or ambiguous")

    directory_state = f"0:{runtime_gid}:{PRIVATE_DIRECTORY_MODE:o}"
    file_state = f"0:{runtime_gid}:{PRIVATE_FILE_MODE:o}"
    named_directory_state = f"root:{PRIVATE_RUNTIME_GROUP}:{PRIVATE_DIRECTORY_MODE:o}"
    named_file_state = f"root:{PRIVATE_RUNTIME_GROUP}:{PRIVATE_FILE_MODE:o}"
    commands = [
        "set -eu",
        (
            f"if ! private_symlinks=$(find {quote(root)} -xdev -type l -print -quit); "
            "then exit 1; fi"
        ),
        'test -z "$private_symlinks"',
        (
            f"if ! private_special_entries=$(find {quote(root)} -xdev "
            "! -type d ! -type f ! -type l -print -quit); then exit 1; fi"
        ),
        'test -z "$private_special_entries"',
        (
            f"if ! private_hardlinks=$(find {quote(root)} -xdev -type f "
            "! -links 1 -print -quit); then exit 1; fi"
        ),
        'test -z "$private_hardlinks"',
        (
            f"if ! private_directories=$(find {quote(root)} -xdev -type d -printf x); "
            "then exit 1; fi"
        ),
        f'test "${{#private_directories}}" -eq {len(normalized_directories)}',
        (
            f"if ! private_files=$(find {quote(root)} -xdev -type f -printf x); "
            "then exit 1; fi"
        ),
        f'test "${{#private_files}}" -eq {len(normalized_files)}',
    ]
    for path in normalized_directories:
        commands.extend(
            (
                f"test -d {quote(path)}",
                f"test ! -L {quote(path)}",
                f"test \"$(stat -c %u:%g:%a {quote(path)})\" = {quote(directory_state)}",
                f"test \"$(stat -c %U:%G:%a {quote(path)})\" = {quote(named_directory_state)}",
            )
        )
    for path in normalized_files:
        commands.extend(
            (
                f"test -f {quote(path)}",
                f"test ! -L {quote(path)}",
                f"test \"$(stat -c %h {quote(path)})\" = 1",
                f"test \"$(stat -c %u:%g:%a {quote(path)})\" = {quote(file_state)}",
                f"test \"$(stat -c %U:%G:%a {quote(path)})\" = {quote(named_file_state)}",
            )
        )
    return " ; ".join(commands)


def verify_remote_private_tree(
    client,
    root: str,
    directories: list[str],
    files: list[str],
    runtime_gid: int,
    label: str,
) -> None:
    run(
        client,
        private_tree_contract_command(root, directories, files, runtime_gid),
        label,
    )


def upload_bytes(sftp, payload: bytes, remote_path: str, mode: int) -> tuple[int, str]:
    with sftp.open(remote_path, "wb") as stream:
        stream.write(payload)
    sftp.chmod(remote_path, mode)
    return len(payload), hashlib.sha256(payload).hexdigest()


def signed_query(secret_hex: str, expires: int, path: str) -> str:
    key = bytes.fromhex(secret_hex)
    signature = hmac.new(key, f"{expires}\n{path}".encode("ascii"), hashlib.sha256).digest()
    encoded = base64.urlsafe_b64encode(signature).decode("ascii").rstrip("=")
    return f"expires={expires}&sig={encoded}"


def http_status(request: Request, expected: set[int]) -> tuple[int, object, bytes]:
    try:
        response = urlopen(request, timeout=45, context=ssl.create_default_context())
        status_code = response.status
        headers = response.headers
        body = response.read()
        response.close()
    except HTTPError as exc:
        status_code = exc.code
        headers = exc.headers
        body = exc.read()
        exc.close()
    except Exception as exc:
        raise RuntimeError(f"HTTPS verification transport failed: {type(exc).__name__}") from exc
    if status_code not in expected:
        parsed = urlsplit(request.full_url)
        raise RuntimeError(
            "HTTPS verification returned unexpected status "
            f"{status_code} for {request.get_method()} {parsed.path}; expected {sorted(expected)}"
        )
    return status_code, headers, body


def verify_public_downloads(
    origin: str, artifacts: list[ApkArtifact], secret_hex: str
) -> None:
    def fresh_url(path: str) -> str:
        expires = int(time.time()) + 300
        return f"{origin}{path}?{signed_query(secret_hex, expires, path)}"

    etag_examples: dict[str, tuple[ApkArtifact, str]] = {}
    for artifact in artifacts:
        url = fresh_url(artifact.public_path)
        _, headers, body = http_status(Request(url, method="HEAD"), {200})
        if body:
            raise RuntimeError("HEAD verification unexpectedly returned a body")
        if int(headers.get("Content-Length", "-1")) != artifact.size:
            raise RuntimeError("HEAD verification size mismatch")
        if "android.package-archive" not in headers.get("Content-Type", "").lower():
            raise RuntimeError("HEAD verification MIME mismatch")
        if "no-store" not in headers.get("Cache-Control", "").lower():
            raise RuntimeError("HEAD verification lacks no-store")
        if headers.get("X-Content-Type-Options", "").lower() != "nosniff":
            raise RuntimeError("HEAD verification lacks nosniff")
        etag = headers.get("ETag", "")
        if not etag:
            raise RuntimeError("HEAD verification lacks ETag")
        etag_examples.setdefault(artifact.track, (artifact, etag))

    for artifact, etag in etag_examples.values():
        _, headers, body = http_status(
            Request(fresh_url(artifact.public_path), headers={"Range": "bytes=0-63"}, method="GET"), {206}
        )
        if len(body) != 64 or headers.get("Content-Range", "").split("/")[0] != "bytes 0-63":
            raise RuntimeError("Range verification failed")
        http_status(Request(fresh_url(artifact.public_path), headers={"If-None-Match": etag}, method="GET"), {304})
        http_status(
            Request(fresh_url(artifact.public_path), headers={"Range": f"bytes={artifact.size}-"}, method="GET"),
            {416},
        )
        http_status(Request(fresh_url(artifact.public_path), data=b"", method="POST"), {405})

        expires = int(time.time()) + 300
        bad_url = f"{origin}{artifact.public_path}?expires={expires}&sig={'A' * 43}"
        http_status(Request(bad_url, method="HEAD"), {404})
        expired = int(time.time()) - 1
        expired_url = (
            f"{origin}{artifact.public_path}?"
            f"{signed_query(secret_hex, expired, artifact.public_path)}"
        )
        http_status(Request(expired_url, method="HEAD"), {410})

    invalid_path = "/__internal-apks/candidate/1.0.0/not-an-allowed-role.apk"
    invalid_url = fresh_url(invalid_path)
    http_status(Request(invalid_url, method="HEAD"), {404})


def snapshot_state_command(paths: list[str]) -> str:
    pieces = ["set -eu"]
    for index, path in enumerate(paths):
        pieces.append(
            f"if [ -e {quote(path)} ]; then test ! -L {quote(path)}; printf '{index}:1\\n'; "
            f"else printf '{index}:0\\n'; fi"
        )
    return " ; ".join(pieces)


def activate_file_command(target: str, candidate: str, backup: str, existed: bool) -> str:
    commands = ["set -eu", f"test -f {quote(candidate)}", f"test ! -L {quote(candidate)}"]
    if existed:
        commands.extend((f"test ! -e {quote(backup)}", f"mv {quote(target)} {quote(backup)}"))
    else:
        commands.append(f"test ! -e {quote(target)}")
    commands.append(f"mv {quote(candidate)} {quote(target)}")
    return " ; ".join(commands)


def restore_file_command(target: str, candidate: str, backup: str, existed: bool) -> str:
    if existed:
        return " ; ".join(
            (
                "set -eu",
                f"if [ -f {quote(backup)} ]; then "
                f"if [ -e {quote(target)} ]; then test ! -e {quote(candidate)}; mv {quote(target)} {quote(candidate)}; fi; "
                f"mv {quote(backup)} {quote(target)}; fi",
            )
        )
    return f"set -eu ; if [ -e {quote(target)} ]; then mv {quote(target)} {quote(candidate)}; fi"


def deploy(
    args: argparse.Namespace,
    artifacts: list[ApkArtifact],
    template: LocalFile,
    verifier: LocalFile,
    private_root: str,
    origin: str,
    secret_hex: str,
) -> None:
    fpm = validate_fpm_upstream(args.fpm_upstream)
    php_binary = validate_php_binary(args.remote_php_binary)
    nginx_include = validate_remote_path(args.remote_nginx_include, "--remote-nginx-include")
    secret_include = validate_remote_path(args.remote_secret_include, "--remote-secret-include")
    if secret_include != EXPECTED_SECRET_INCLUDE:
        raise RuntimeError(f"Signing-secret include is pinned to {EXPECTED_SECRET_INCLUDE}")
    host_config = validate_remote_path(args.remote_nginx_host_config, "--remote-nginx-host-config")
    host_include = validate_host_include_pattern(args.remote_nginx_host_include, nginx_include)
    fpm_evidence = validate_remote_path(
        args.remote_fpm_evidence_config, "--remote-fpm-evidence-config"
    )
    if nginx_include == secret_include or host_config in {nginx_include, secret_include}:
        raise RuntimeError("Remote Nginx paths must be distinct")
    debug_versions = {
        artifact.version for artifact in artifacts if artifact.track == "debug"
    }
    if len(debug_versions) != 1:
        raise RuntimeError("Private deployment must contain one exact Debug version")
    rendered_nginx = render_nginx(
        template, private_root, secret_include, fpm, next(iter(debug_versions))
    )
    secret_payload = f"set $yy_internal_download_secret '{secret_hex}';\n".encode("ascii")

    token = secrets.token_hex(16)
    current = posixpath.join(private_root, "current")
    stage = posixpath.join(private_root, f".candidate-{token}")
    previous = posixpath.join(private_root, f".previous-{token}")
    lock = posixpath.join(private_root, ".deploy.lock")
    nginx_candidate = f"{nginx_include}.candidate-{token}"
    nginx_backup = f"{nginx_include}.backup-{token}"
    secret_candidate = f"{secret_include}.candidate-{token}"
    secret_backup = f"{secret_include}.backup-{token}"

    ssh = None
    lock_acquired = False
    staged = False
    data_started = False
    secret_started = False
    nginx_started = False
    completed = False
    rollback_ok = False
    states = {"current": False, "nginx": False, "secret": False}
    try:
        ssh = connect_ssh(args)
        runtime_gid = resolve_remote_group_gid(ssh, PRIVATE_RUNTIME_GROUP)
        fpm_value = f"{fpm};"
        preflight = " ; ".join(
            (
                "set -eu",
                "command -v sha256sum >/dev/null",
                "command -v stat >/dev/null",
                "command -v find >/dev/null",
                "command -v getent >/dev/null",
                "command -v install >/dev/null",
                "command -v nginx >/dev/null",
                "nginx -V 2>&1 | grep -q -- '--with-http_auth_request_module'",
                f"test -f {quote(php_binary)} && test -x {quote(php_binary)} && test ! -L {quote(php_binary)}",
                f"test \"$({quote(php_binary)} -r {quote('echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')})\" = '8.2'",
                f"test -f {quote(host_config)} && test ! -L {quote(host_config)}",
                f"awk -v expected={quote(host_include + ';')} '$1 == \"include\" && $2 == expected {{ found=1 }} END {{ exit !found }}' {quote(host_config)}",
                f"test -f {quote(fpm_evidence)} && test ! -L {quote(fpm_evidence)}",
                f"awk -v expected={quote(fpm_value)} '$1 == \"fastcgi_pass\" && $2 == expected {{ found=1 }} END {{ exit !found }}' {quote(fpm_evidence)}",
                f"test -d {quote(posixpath.dirname(nginx_include))} && test ! -L {quote(posixpath.dirname(nginx_include))}",
                f"install -d -m 0700 {quote(posixpath.dirname(secret_include))}",
                f"test -d {quote(posixpath.dirname(secret_include))} && test ! -L {quote(posixpath.dirname(secret_include))}",
                (
                    f"if [ ! -e {quote(private_root)} ]; then "
                    f"install -d -o 0 -g {runtime_gid} -m {PRIVATE_DIRECTORY_MODE:o} {quote(private_root)}; fi"
                ),
                f"test -d {quote(private_root)} && test ! -L {quote(private_root)}",
                (
                    f"case \"$(stat -c %u:%g:%a {quote(private_root)})\" in "
                    f"0:0:755|0:{runtime_gid}:{PRIVATE_DIRECTORY_MODE:o}) ;; *) exit 1 ;; esac"
                ),
                f"mkdir -m 0700 {quote(lock)}",
                f"chown 0:{runtime_gid} {quote(private_root)}",
                f"chmod {PRIVATE_DIRECTORY_MODE:o} {quote(private_root)}",
                (
                    f"test \"$(stat -c %u:%g:%a {quote(private_root)})\" = "
                    f"{quote(f'0:{runtime_gid}:{PRIVATE_DIRECTORY_MODE:o}')}"
                ),
                (
                    f"test \"$(stat -c %U:%G:%a {quote(private_root)})\" = "
                    f"{quote(f'root:{PRIVATE_RUNTIME_GROUP}:{PRIVATE_DIRECTORY_MODE:o}')}"
                ),
            )
        )
        run(ssh, preflight, "private download preflight and lock")
        lock_acquired = True

        output = run(
            ssh,
            snapshot_state_command([current, nginx_include, secret_include]),
            "snapshot private deployment state",
        )
        parsed = dict(line.split(":", 1) for line in output.splitlines())
        if set(parsed) != {"0", "1", "2"} or any(value not in {"0", "1"} for value in parsed.values()):
            raise RuntimeError("Remote state snapshot is malformed")
        states = {
            "current": parsed["0"] == "1",
            "nginx": parsed["1"] == "1",
            "secret": parsed["2"] == "1",
        }

        track_versions = sorted(
            {(artifact.track, artifact.version) for artifact in artifacts}
        )
        if {track for track, _ in track_versions} != {"debug", "candidate"}:
            raise RuntimeError("Private deployment must contain Debug and candidate tracks")
        directories = [stage]
        seen_tracks: set[str] = set()
        for track, version in track_versions:
            if track not in seen_tracks:
                directories.append(posixpath.join(stage, track))
                seen_tracks.add(track)
            directories.append(posixpath.join(stage, f"{track}/{version}"))
        directories.append(posixpath.join(stage, "_auth"))
        private_files = [
            posixpath.join(stage, artifact.remote_relative) for artifact in artifacts
        ]
        private_files.append(posixpath.join(stage, "_auth/verify.php"))
        run(
            ssh,
            " ; ".join(
                (
                    "set -eu",
                    f"test ! -e {quote(stage)} && test ! -e {quote(previous)}",
                    *(
                        f"install -d -o 0 -g {runtime_gid} -m {PRIVATE_DIRECTORY_MODE:o} {quote(path)}"
                        for path in directories
                    ),
                    f"test \"$(stat -c %d {quote(private_root)})\" = \"$(stat -c %d {quote(stage)})\"",
                    f"if [ -e {quote(current)} ]; then test -d {quote(current)} && test ! -L {quote(current)} && test \"$(stat -c %d {quote(current)})\" = \"$(stat -c %d {quote(stage)})\"; fi",
                )
            ),
            "prepare same-volume private staging",
        )
        staged = True

        expected_remote: list[tuple[str, int, str]] = []
        with ssh.open_sftp() as sftp:
            for artifact in artifacts:
                remote = posixpath.join(stage, artifact.remote_relative)
                sftp.put(str(artifact.path), remote, confirm=True)
                sftp.chown(remote, 0, runtime_gid)
                sftp.chmod(remote, PRIVATE_FILE_MODE)
                expected_remote.append((remote, artifact.size, artifact.sha256))
            verifier_remote = posixpath.join(stage, "_auth/verify.php")
            sftp.put(str(verifier.path), verifier_remote, confirm=True)
            sftp.chown(verifier_remote, 0, runtime_gid)
            sftp.chmod(verifier_remote, PRIVATE_FILE_MODE)
            expected_remote.append((verifier_remote, verifier.size, verifier.sha256))
            nginx_size, nginx_sha = upload_bytes(sftp, rendered_nginx, nginx_candidate, 0o644)
            secret_size, secret_sha = upload_bytes(sftp, secret_payload, secret_candidate, 0o600)

        for remote, size, digest in expected_remote:
            if remote_identity(ssh, remote) != (size, digest):
                raise RuntimeError("Remote staged private artifact identity mismatch")
        if remote_identity(ssh, nginx_candidate) != (nginx_size, nginx_sha):
            raise RuntimeError("Remote staged Nginx identity mismatch")
        if remote_identity(ssh, secret_candidate) != (secret_size, secret_sha):
            raise RuntimeError("Remote staged secret identity mismatch")
        verify_remote_private_tree(
            ssh,
            stage,
            directories,
            private_files,
            runtime_gid,
            "verify staged private tree permissions and topology",
        )
        run(
            ssh,
            f"{quote(php_binary)} -l {quote(posixpath.join(stage, '_auth/verify.php'))} >/dev/null",
            "validate private PHP verifier",
        )

        activation = ["set -eu"]
        if states["current"]:
            activation.extend((f"test ! -e {quote(previous)}", f"mv {quote(current)} {quote(previous)}"))
        else:
            activation.append(f"test ! -e {quote(current)}")
        activation.append(f"mv {quote(stage)} {quote(current)}")
        data_started = True
        run(ssh, " ; ".join(activation), "activate private APK directory")
        secret_started = True
        run(
            ssh,
            activate_file_command(
                secret_include, secret_candidate, secret_backup, states["secret"]
            ),
            "activate root-only signing secret",
        )
        nginx_started = True
        run(
            ssh,
            activate_file_command(
                nginx_include, nginx_candidate, nginx_backup, states["nginx"]
            ),
            "activate reviewed private Nginx fragment",
        )
        run(ssh, "nginx -t && nginx -s reload", "validate and reload Nginx")

        for artifact in artifacts:
            active_path = posixpath.join(current, artifact.remote_relative)
            if remote_identity(ssh, active_path) != (artifact.size, artifact.sha256):
                raise RuntimeError("Activated private APK identity mismatch")
        active_directories = [
            current + path[len(stage) :] for path in directories
        ]
        active_files = [current + path[len(stage) :] for path in private_files]
        verify_remote_private_tree(
            ssh,
            current,
            active_directories,
            active_files,
            runtime_gid,
            "verify activated private tree permissions and topology",
        )
        verify_public_downloads(origin, artifacts, secret_hex)
        completed = True
        rollback_ok = True
        print(
            "Private APK deployment completed: two authenticated tracks and eight APKs verified; public /downloads was untouched."
        )
    except Exception:
        if ssh is not None and staged:
            rollback_ok = False
            try:
                ssh = ensure_active_ssh(ssh, args)
                if nginx_started:
                    run(
                        ssh,
                        restore_file_command(
                            nginx_include, nginx_candidate, nginx_backup, states["nginx"]
                        ),
                        "restore previous Nginx fragment",
                    )
                if secret_started:
                    run(
                        ssh,
                        restore_file_command(
                            secret_include, secret_candidate, secret_backup, states["secret"]
                        ),
                        "restore previous signing secret",
                    )
                if data_started:
                    data_rollback = ["set -eu"]
                    if states["current"]:
                        data_rollback.append(
                            f"if [ -d {quote(previous)} ]; then "
                            f"if [ -d {quote(current)} ]; then test ! -e {quote(stage)}; mv {quote(current)} {quote(stage)}; fi; "
                            f"mv {quote(previous)} {quote(current)}; fi"
                        )
                    else:
                        data_rollback.append(
                            f"if [ -d {quote(current)} ]; then test ! -e {quote(stage)}; mv {quote(current)} {quote(stage)}; fi"
                        )
                    run(ssh, " ; ".join(data_rollback), "restore previous private APK directory")
                if nginx_started or secret_started:
                    run(ssh, "nginx -t && nginx -s reload", "validate and reload restored Nginx")
                rollback_ok = True
            except Exception as rollback_error:
                print(
                    "ROLLBACK INCOMPLETE; private deployment lock retained. "
                    f"transaction={token}, error={type(rollback_error).__name__}",
                    file=sys.stderr,
                )
        elif lock_acquired:
            rollback_ok = True
        raise
    finally:
        if ssh is not None:
            if lock_acquired and (completed or rollback_ok):
                try:
                    ssh = ensure_active_ssh(ssh, args)
                    cleanup_paths = [stage, nginx_candidate, secret_candidate]
                    if completed:
                        cleanup_paths.extend((previous, nginx_backup, secret_backup))
                    # Every cleanup target contains the per-run token and is below
                    # one of the three exact reviewed roots.
                    if any(token not in path for path in cleanup_paths):
                        raise RuntimeError("Refusing unsafe transaction cleanup")
                    run(
                        ssh,
                        "set -eu ; rm -rf -- "
                        + " ".join(quote(path) for path in cleanup_paths)
                        + f" ; rmdir {quote(lock)}",
                        "cleanup private deployment transaction",
                    )
                except Exception as cleanup_error:
                    print(
                        "CLEANUP INCOMPLETE; private deployment lock retained. "
                        f"transaction={token}, error={type(cleanup_error).__name__}",
                        file=sys.stderr,
                    )
            ssh.close()


def validate_args(
    args: argparse.Namespace, repository_root: Path
) -> tuple[Path, Path, str, str]:
    aapt2 = resolve_android_tool(repository_root, "aapt2", args.aapt2)
    apksigner = resolve_android_tool(repository_root, "apksigner", args.apksigner)
    private_root = validate_private_root(args.remote_private_root)
    origin = normalize_origin(args.public_origin)
    if args.execute:
        if args.debug_compatibility_manifest is None:
            raise RuntimeError(
                "Execution requires --debug-compatibility-manifest for the current global version"
            )
        legacy_anchor = load_legacy_upgrade_identity(repository_root)
        version, _ = load_android_version(
            repository_root, legacy_anchor.maximum_version_code
        )
        expected_compatibility_manifest = (
            repository_root
            / COMPATIBILITY_MANIFEST_ROOT
            / version
            / "release-manifest.json"
        ).resolve()
        provided_compatibility_manifest = args.debug_compatibility_manifest
        if not provided_compatibility_manifest.is_absolute():
            provided_compatibility_manifest = (
                repository_root / provided_compatibility_manifest
            )
        if provided_compatibility_manifest.resolve() != expected_compatibility_manifest:
            raise RuntimeError(
                "Execution requires the exact current Debug compatibility manifest path"
            )
        if args.confirmation != EXECUTE_CONFIRMATION:
            raise RuntimeError(f"Execution requires --confirmation {EXECUTE_CONFIRMATION}")
        if args.nginx_confirmation != NGINX_CONFIRMATION:
            raise RuntimeError(
                f"Execution requires --nginx-confirmation {NGINX_CONFIRMATION}"
            )
        required = {
            "--host": args.host,
            "--known-hosts": args.known_hosts,
            "--fpm-upstream": args.fpm_upstream,
            "--remote-php-binary": args.remote_php_binary,
            "--remote-fpm-evidence-config": args.remote_fpm_evidence_config,
            "--remote-nginx-host-config": args.remote_nginx_host_config,
            "--remote-nginx-host-include": args.remote_nginx_host_include,
            "--remote-nginx-include": args.remote_nginx_include,
            "--remote-secret-include": args.remote_secret_include,
        }
        missing = [name for name, value in required.items() if not value]
        if missing:
            raise RuntimeError("Execution requires explicit parameters: " + ", ".join(missing))
        if not isinstance(args.port, int) or not 1 <= args.port <= 65535:
            raise RuntimeError("--port must be between 1 and 65535")
        if not os.environ.get("YY_SSH_PASSWORD", ""):
            raise RuntimeError("YY_SSH_PASSWORD is required for execution")
        secret = os.environ.get(SECRET_ENV, "")
        if not re.fullmatch(r"[0-9a-f]{64}", secret):
            raise RuntimeError(f"{SECRET_ENV} must contain exactly 64 lowercase hex characters")
    else:
        if args.confirmation or args.nginx_confirmation:
            raise RuntimeError("Confirmation phrases are accepted only with --execute")
        secret = ""
    return aapt2, apksigner, private_root, origin


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--aapt2", type=Path)
    parser.add_argument("--apksigner", type=Path)
    parser.add_argument("--debug-compatibility-manifest", type=Path)
    parser.add_argument("--public-origin", default="https://appht.jjmxg.xyz")
    parser.add_argument("--remote-private-root", default=EXPECTED_PRIVATE_ROOT)
    parser.add_argument("--host")
    parser.add_argument("--port", type=int, default=22)
    parser.add_argument("--username", default="root")
    parser.add_argument("--known-hosts")
    parser.add_argument("--fpm-upstream", default="")
    parser.add_argument("--remote-php-binary", default="")
    parser.add_argument("--remote-fpm-evidence-config", default="")
    parser.add_argument("--remote-nginx-host-config", default="")
    parser.add_argument("--remote-nginx-host-include", default="")
    parser.add_argument("--remote-nginx-include", default="")
    parser.add_argument("--remote-secret-include", default="")
    parser.add_argument("--execute", action="store_true")
    parser.add_argument("--confirmation", default="")
    parser.add_argument("--nginx-confirmation", default="")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    repository_root = Path(__file__).resolve().parents[2]
    aapt2, apksigner, private_root, origin = validate_args(args, repository_root)
    template, verifier = validate_deployment_sources(repository_root)
    artifacts = validate_artifacts(
        repository_root,
        aapt2,
        apksigner,
        args.debug_compatibility_manifest,
    )
    if not args.execute:
        track_versions = {
            artifact.track: (artifact.version, artifact.version_code)
            for artifact in artifacts
        }
        debug_version, debug_code = track_versions["debug"]
        candidate_version, candidate_code = track_versions["candidate"]
        print(
            f"DRY RUN PASS: Debug {debug_version}/{debug_code} and Stable pending "
            f"{candidate_version}/{candidate_code}, eight APK identities, SHA-256 and "
            "signers verified; no SSH, secret read, upload, reload or public probe occurred."
        )
        return 0
    deploy(
        args,
        artifacts,
        template,
        verifier,
        private_root,
        origin,
        os.environ[SECRET_ENV],
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(
            f"Private APK deployment failed: {type(exc).__name__}: {exc}",
            file=sys.stderr,
        )
        raise SystemExit(1)
