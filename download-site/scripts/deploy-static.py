#!/usr/bin/env python3
"""Atomically deploy the static download center and immutable release artifacts."""

from __future__ import annotations

import argparse
from dataclasses import dataclass
import hashlib
import json
import os
import posixpath
import re
import secrets
import shlex
import stat
import struct
import subprocess
import sys
from pathlib import Path
from urllib.parse import urlsplit, urlunsplit

import paramiko


SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
COMMIT_RE = re.compile(r"^[0-9a-f]{40}$")
VERSION_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")
FILENAME_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$")
EXPECTED_RELEASE_IDS = {"owner", "authorized", "admin", "user"}
PUBLIC_RELEASE_IDS = EXPECTED_RELEASE_IDS
EXPECTED_PROJECT_IDS = {"source", "history", "delivery", "manifest"}
RELEASE_CHANNELS = {"Debug", "Stable"}
DEBUG_HTTP_CONFIRMATION = "DEBUG_HTTP_NON_PRODUCTION_CONFIRMED"
IDENTITY_FIELDS = {
    "apiBaseUrl",
    "appKeySha256",
    "platformKeySha256",
    "authorizedPlatformKeySha256",
}
IMMUTABLE_METADATA_FIELDS = (
    "schemaVersion",
    "channel",
    "versionName",
    "versionCode",
    "buildSourceCommit",
    "releaseTag",
    "releaseIdentitySha256",
    "connectionIdentity",
    "releaseDate",
    "generatedAt",
    "downloadRootBase",
    "releaseNotes",
    "releases",
    "projectAssets",
)


STABLE_SITE_FILES = {
    "index.html",
    "site.js",
    "docs.js",
    "site.webmanifest",
    "logo.svg",
    "og-card.png",
    "api-docs/index.html",
    "privacy/index.html",
    "terms/index.html",
}
STABLE_ASSET_SUFFIXES = {".css", ".png", ".svg", ".webp", ".woff", ".woff2"}
STABLE_FORBIDDEN_SUFFIXES = {".apk", ".zip", ".bundle"}

@dataclass(frozen=True)
class Artifact:
    name: str
    path: Path
    sha256: str
    size: int


@dataclass(frozen=True)
class SiteFile:
    relative: str
    path: Path
    sha256: str
    size: int


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", type=int, default=22)
    parser.add_argument("--username", default="root")
    parser.add_argument("--known-hosts", required=True)
    parser.add_argument("--allow-insecure-http-debug", action="store_true")
    parser.add_argument("--insecure-http-confirmation", default="")
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
    return digest.hexdigest()


def prefix_sha256(path: Path, size: int) -> str:
    digest = hashlib.sha256()
    remaining = size
    with path.open("rb") as stream:
        while remaining:
            chunk = stream.read(min(1024 * 1024, remaining))
            if not chunk:
                raise RuntimeError(f"Unexpected EOF while hashing prefix: {path}")
            digest.update(chunk)
            remaining -= len(chunk)
    return digest.hexdigest()


def read_json(path: Path, label: str) -> dict:
    try:
        with path.open("r", encoding="utf-8-sig") as stream:
            value = json.load(stream)
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"Cannot read {label}: {path}: {exc}") from exc
    if not isinstance(value, dict):
        raise RuntimeError(f"{label} must contain a JSON object")
    return value


def require_sha256(value: object, label: str) -> str:
    digest = str(value).lower()
    if SHA256_RE.fullmatch(digest) is None:
        raise RuntimeError(f"{label} must be a SHA-256 digest")
    return digest


def require_commit(value: object, label: str) -> str:
    commit = str(value).lower()
    if COMMIT_RE.fullmatch(commit) is None:
        raise RuntimeError(f"{label} must be a 40-character Git commit")
    return commit


def safe_filename(value: object, label: str) -> str:
    name = str(value)
    if (
        not name
        or name != posixpath.basename(name)
        or name in {".", ".."}
        or FILENAME_RE.fullmatch(name) is None
    ):
        raise RuntimeError(f"{label} is not a canonical URL-safe file name")
    return name


def normalize_api_base_url(value: object) -> str:
    raw = str(value)
    if not raw or raw != raw.strip():
        raise RuntimeError("connectionIdentity.apiBaseUrl must be an exact non-empty value")
    parsed = urlsplit(raw)
    try:
        parsed.port
    except ValueError as exc:
        raise RuntimeError("connectionIdentity.apiBaseUrl has an invalid port") from exc
    if (
        parsed.scheme.lower() not in {"http", "https"}
        or not parsed.hostname
        or parsed.username is not None
        or parsed.password is not None
        or parsed.query
        or parsed.fragment
    ):
        raise RuntimeError("connectionIdentity.apiBaseUrl must be an absolute HTTP(S) URL")
    path = parsed.path or "/"
    if not path.endswith("/"):
        raise RuntimeError("connectionIdentity.apiBaseUrl must end with a slash")
    canonical = urlunsplit((parsed.scheme.lower(), parsed.netloc.lower(), path, "", ""))
    if canonical != raw:
        raise RuntimeError("connectionIdentity.apiBaseUrl must use canonical form")
    return canonical


def validate_connection_identity(value: object, label: str) -> dict[str, str]:
    if not isinstance(value, dict) or set(value) != IDENTITY_FIELDS:
        raise RuntimeError(f"{label} must contain only the four public identity fields")
    evidence = {"apiBaseUrl": normalize_api_base_url(value["apiBaseUrl"])}
    for field in sorted(IDENTITY_FIELDS - {"apiBaseUrl"}):
        evidence[field] = require_sha256(value[field], f"{label}.{field}")
    if evidence != value:
        raise RuntimeError(f"{label} must use canonical lowercase SHA-256 values")
    return evidence


def public_origin(api_base_url: str) -> str:
    parsed = urlsplit(api_base_url)
    return urlunsplit((parsed.scheme, parsed.netloc, "", "", "")).rstrip("/")


def release_channel(manifest: dict) -> str:
    raw = manifest.get("channel")
    if raw in RELEASE_CHANNELS:
        return str(raw)
    if raw not in (None, ""):
        raise RuntimeError("Release manifest channel must be Debug or Stable")

    releases = manifest.get("releases")
    legacy_debug = (
        str(manifest.get("releaseTag", "")).endswith("-debug")
        and isinstance(releases, list)
        and len(releases) == 4
        and all(
            isinstance(entry, dict)
            and str(entry.get("packageName", "")).endswith(".debug")
            and str(entry.get("versionName", "")).endswith("-debug")
            and str(entry.get("fileName", "")).endswith("-debug.apk")
            for entry in releases
        )
    )
    if legacy_debug:
        return "Debug"
    raise RuntimeError("Stable release manifest must explicitly declare channel=Stable")


def expected_release_tag(version: str, channel: str) -> str:
    if channel not in RELEASE_CHANNELS:
        raise RuntimeError("Unsupported release channel")
    return f"v{version}" if channel == "Stable" else f"v{version}-debug"


def validate_public_transport(
    manifest: dict,
    allow_insecure_http_debug: bool,
    insecure_http_confirmation: str,
) -> None:
    channel = release_channel(manifest)
    if channel != "Stable":
        raise RuntimeError("Public deployment only accepts Stable releases")
    api_base_url = str(manifest["connectionIdentity"]["apiBaseUrl"])
    scheme = urlsplit(api_base_url).scheme
    releases = manifest.get("releases")
    debug_only = isinstance(releases, list) and len(releases) == 4 and all(
        isinstance(entry, dict)
        and str(entry.get("packageName", "")).endswith(".debug")
        and str(entry.get("versionName", "")).endswith("-debug")
        and str(entry.get("fileName", "")).endswith("-debug.apk")
        for entry in releases
    )

    if scheme != "https":
        raise RuntimeError("Stable publication requires an HTTPS API base URL")
    if debug_only:
        raise RuntimeError("Stable publication may not contain Debug package identities")


def validate_remote_root(value: str) -> str:
    if not value or value != value.strip() or not value.startswith("/"):
        raise RuntimeError("--remote-public-root must be an absolute POSIX path")
    normalized = posixpath.normpath(value)
    if normalized != value.rstrip("/") or normalized == "/" or normalized.count("/") < 3:
        raise RuntimeError("--remote-public-root is too broad or not normalized")
    if any(character in normalized for character in ("\x00", "\r", "\n")):
        raise RuntimeError("--remote-public-root contains an unsafe character")
    return normalized


def png_dimensions(path: Path) -> tuple[int, int]:
    with path.open("rb") as stream:
        header = stream.read(24)
    if (
        len(header) != 24
        or header[:8] != b"\x89PNG\r\n\x1a\n"
        or header[12:16] != b"IHDR"
    ):
        raise RuntimeError(f"Stable social card is not a canonical PNG: {path}")
    return struct.unpack(">II", header[16:24])


def stable_site_file_allowed(relative: str) -> bool:
    if relative in STABLE_SITE_FILES:
        return True
    path = Path(relative)
    return (
        relative.startswith("assets/")
        and path.suffix.lower() in STABLE_ASSET_SUFFIXES
        and path.name not in {"", ".", ".."}
    )


def validate_site_tree(
    site_dir: Path,
    version: str,
    channel: str = "Stable",
    manifest: dict | None = None,
) -> list[SiteFile]:
    if channel != "Stable":
        raise RuntimeError("Public deployment only accepts Stable releases")
    index_path = site_dir / "index.html"
    if not index_path.is_file() or index_path.is_symlink():
        raise RuntimeError("Download site must contain a regular index.html")
    if version not in index_path.read_text(encoding="utf-8", errors="replace"):
        raise RuntimeError("Download site index.html does not contain the release version")

    discovered: list[SiteFile] = []
    forbidden_markers = (
        "authorized-platform",
        "platform-owner",
        "yiyunying-source-",
        "yiyunying-git-history-",
        "yiyunying-project-delivery-",
        "project-assets-manifest",
        "release-manifest",
        "sha256sums",
    )
    for path in sorted(site_dir.rglob("*")):
        if path.is_symlink():
            raise RuntimeError(f"Download site may not contain symlinks: {path}")
        if path.is_dir():
            continue
        if not path.is_file():
            raise RuntimeError(f"Download site contains a non-regular entry: {path}")
        relative = path.relative_to(site_dir).as_posix()
        lower = relative.lower()
        if (
            path.suffix.lower() in STABLE_FORBIDDEN_SUFFIXES
            or any(marker in lower for marker in forbidden_markers)
        ):
            raise RuntimeError(f"Stable site contains a forbidden public file: {relative}")
        if stable_site_file_allowed(relative):
            discovered.append(
                SiteFile(relative, path, sha256(path), path.stat().st_size)
            )
    if not discovered:
        raise RuntimeError("Download site is empty")
    selected = {item.relative: item for item in discovered}
    missing = sorted(STABLE_SITE_FILES - set(selected))
    if missing:
        raise RuntimeError(f"Stable site is missing required public files: {missing}")
    if not any(
        relative.startswith("assets/") and Path(relative).suffix.lower() == ".css"
        for relative in selected
    ):
        raise RuntimeError("Stable site must contain its content-hashed stylesheet")
    og_width, og_height = png_dimensions(site_dir / "og-card.png")
    if (
        og_width < 1200
        or og_height < 630
        or abs((og_width * 630) - (og_height * 1200)) >= og_width
    ):
        raise RuntimeError(
            "Stable og-card.png must preserve the 1200x630 social-card aspect ratio"
        )

    manifest_document = read_json(
        site_dir / "site.webmanifest", "stable site web manifest"
    )
    icon_paths = {
        str(icon.get("src", ""))
        for icon in manifest_document.get("icons", [])
        if isinstance(icon, dict)
    }
    if "/download-center/logo.svg" not in icon_paths:
        raise RuntimeError(
            "Stable site.webmanifest must reference /download-center/logo.svg"
        )

    public_text_files = [
        item
        for item in discovered
        if item.path.suffix.lower() in {".html", ".js", ".css", ".json", ".webmanifest"}
        or item.path.name == "site.webmanifest"
    ]
    public_text = "\n".join(
        item.path.read_text(encoding="utf-8", errors="replace")
        for item in public_text_files
    )
    if "\ufffd" in public_text:
        raise RuntimeError("Stable site contains a UTF-8 replacement character")
    for root_reference in ('href="/logo.svg"', 'src="/logo.svg"', 'href="/site.webmanifest"'):
        if root_reference in public_text:
            raise RuntimeError(
                "Stable site assets must use the /download-center/ public prefix"
            )

    referenced: set[str] = set()
    for match in re.findall(r'(?:src|href)=["\']([^"\']+)["\']', public_text):
        parsed = urlsplit(match)
        path = parsed.path
        if path == "/download-center/" or path == "/download-center":
            referenced.add("index.html")
        elif path.startswith("/download-center/"):
            relative = path.removeprefix("/download-center/")
            if relative.endswith("/"):
                relative += "index.html"
            referenced.add(relative)
    for relative in sorted(referenced):
        if (
            relative.startswith("downloads/")
            or relative.startswith("#")
            or relative == ""
        ):
            continue
        if relative not in selected:
            raise RuntimeError(f"Stable site references a non-public asset: {relative}")

    if manifest is not None:
        private_values: list[str] = []
        for entry in manifest.get("releases", []):
            if isinstance(entry, dict) and str(entry.get("id")) not in PUBLIC_RELEASE_IDS:
                private_values.extend(
                    str(entry.get(field, ""))
                    for field in ("fileName", "packageName", "sha256")
                )
        for descriptor in manifest.get("projectAssets", []):
            if isinstance(descriptor, dict):
                private_values.append(str(descriptor.get("fileName", "")))
        leaked = sorted(
            value for value in private_values if value and value in public_text
        )
        if leaked:
            raise RuntimeError("Stable site leaks non-public release metadata")

    return [selected[relative] for relative in sorted(selected)]


def exact_artifact(path: Path, expected_hash: object, expected_size: object, label: str) -> Artifact:
    if not path.is_file() or path.is_symlink():
        raise RuntimeError(f"Missing regular {label}: {path}")
    digest = require_sha256(expected_hash, f"{label} sha256")
    try:
        size = int(expected_size)
    except (TypeError, ValueError) as exc:
        raise RuntimeError(f"{label} sizeBytes is invalid") from exc
    if size < 1 or path.stat().st_size != size:
        raise RuntimeError(f"Local size mismatch: {path.name}")
    if sha256(path) != digest:
        raise RuntimeError(f"Local SHA-256 mismatch: {path.name}")
    return Artifact(path.name, path, digest, size)


def load_release_files(
    release_dir: Path,
    version: str,
    repository_root: Path,
) -> tuple[list[Artifact], dict]:
    manifest_path = release_dir / "release-manifest.json"
    manifest = read_json(manifest_path, "release manifest")
    if manifest.get("schemaVersion") != 4:
        raise RuntimeError("Release manifest schemaVersion must be 4")
    channel = release_channel(manifest)
    if channel != "Stable":
        raise RuntimeError("Public deployment only accepts Stable releases")
    if manifest.get("finalizationStatus") != "finalized":
        raise RuntimeError("Release manifest must be finalized")
    if str(manifest.get("versionName")) != version or int(manifest.get("versionCode", 0)) < 1:
        raise RuntimeError("Release manifest version does not match --version")
    if manifest.get("downloadRootBase") != "/downloads":
        raise RuntimeError("Release manifest downloadRootBase must be /downloads")

    build_commit = require_commit(manifest.get("buildSourceCommit"), "buildSourceCommit")
    evidence_commit = require_commit(manifest.get("releaseEvidenceCommit"), "releaseEvidenceCommit")
    if build_commit == evidence_commit:
        raise RuntimeError("Build commit A and evidence commit B must be distinct")
    expected_tag = expected_release_tag(version, channel)
    if manifest.get("releaseTag") != expected_tag:
        raise RuntimeError(f"Release tag must be {expected_tag}")
    identity = validate_connection_identity(
        manifest.get("connectionIdentity"), "release manifest connectionIdentity"
    )

    identity_path = repository_root / "backend" / "config" / "release-identity.json"
    identity_hash = sha256(identity_path) if identity_path.is_file() else ""
    if require_sha256(manifest.get("releaseIdentitySha256"), "releaseIdentitySha256") != identity_hash:
        raise RuntimeError("Release manifest is not bound to the repository release identity bytes")
    identity_document = read_json(identity_path, "repository release identity")
    if (
        str(identity_document.get("version_name", "")) != version
        or int(identity_document.get("version_code", 0)) != int(manifest["versionCode"])
    ):
        raise RuntimeError("Repository release identity version does not match the manifest")
    stable_signer = ""
    if channel == "Stable":
        stable_signer = require_sha256(
            identity_document.get("stable_signer_sha256"),
            "release identity stable_signer_sha256",
        )
        if urlsplit(identity["apiBaseUrl"]).scheme != "https":
            raise RuntimeError("Stable publication requires an HTTPS API base URL")

    metadata = read_json(
        repository_root / "download-site" / "release-metadata.json",
        "download release metadata",
    )
    if metadata.get("schemaVersion") != 4:
        raise RuntimeError("Download release metadata schemaVersion must be 4")
    if (
        metadata.get("finalizationStatus") != "pending"
        or metadata.get("releaseEvidenceCommit") not in (None, "")
    ):
        raise RuntimeError("Download release metadata must be the pending B-commit evidence")
    require_sha256(metadata.get("pendingManifestSha256"), "pendingManifestSha256")
    for field in IMMUTABLE_METADATA_FIELDS:
        if metadata.get(field) != manifest.get(field):
            raise RuntimeError(
                f"Release metadata does not exactly match finalized manifest field: {field}"
            )
    if (
        validate_connection_identity(
            metadata.get("connectionIdentity"), "release metadata connectionIdentity"
        )
        != identity
    ):
        raise RuntimeError("Release metadata connection identity mismatch")

    entries = manifest.get("releases")
    if not isinstance(entries, list) or len(entries) != 4 or entries != metadata.get("releases"):
        raise RuntimeError("Manifest and metadata must contain the same four APK entries")
    apk_artifacts: list[Artifact] = []
    apk_by_id: dict[str, Artifact] = {}
    release_ids: set[str] = set()
    release_names: set[str] = set()
    release_signers: set[str] = set()
    for entry in entries:
        if not isinstance(entry, dict):
            raise RuntimeError("APK manifest entry must be an object")
        release_id = str(entry.get("id"))
        if release_id in release_ids or release_id not in EXPECTED_RELEASE_IDS:
            raise RuntimeError(f"Invalid or duplicate APK release id: {release_id}")
        release_ids.add(release_id)
        name = safe_filename(entry.get("fileName"), "APK fileName")
        if not name.endswith(".apk") or name in release_names:
            raise RuntimeError(f"Invalid or duplicate APK fileName: {name}")
        release_names.add(name)
        if str(entry.get("versionCode")) != str(manifest["versionCode"]):
            raise RuntimeError(f"APK versionCode mismatch: {name}")
        signer = require_sha256(entry.get("signerSha256"), f"{release_id} signerSha256")
        release_signers.add(signer)

        package_name = str(entry.get("packageName", ""))
        embedded_version = str(entry.get("versionName", ""))
        debug_identity = (
            package_name.endswith(".debug")
            or embedded_version.endswith("-debug")
            or name.endswith("-debug.apk")
        )
        if channel == "Stable" and debug_identity:
            raise RuntimeError(f"Stable APK contains a Debug identity: {name}")
        if channel == "Debug" and not (
            package_name.endswith(".debug")
            and embedded_version.endswith("-debug")
            and name.endswith("-debug.apk")
        ):
            raise RuntimeError(f"Debug APK identity is incomplete: {name}")

        artifact = exact_artifact(
            release_dir / name,
            entry.get("sha256"),
            entry.get("sizeBytes"),
            "APK",
        )
        apk_artifacts.append(artifact)
        apk_by_id[release_id] = artifact
    if release_ids != EXPECTED_RELEASE_IDS:
        raise RuntimeError("Release manifest does not contain the complete four-APK set")
    if len(release_signers) != 1:
        raise RuntimeError("All four APK entries must use one signer")
    if channel == "Stable" and next(iter(release_signers)) != stable_signer:
        raise RuntimeError(
            "Stable APK signer does not match release identity stable_signer_sha256"
        )

    descriptor_list = manifest.get("projectAssets")
    if not isinstance(descriptor_list, list) or len(descriptor_list) != 4:
        raise RuntimeError("Release manifest must contain four project asset descriptors")
    descriptors: dict[str, str] = {}
    for descriptor in descriptor_list:
        if not isinstance(descriptor, dict):
            raise RuntimeError("Project asset descriptor must be an object")
        asset_id = str(descriptor.get("id"))
        if asset_id in descriptors or asset_id not in EXPECTED_PROJECT_IDS:
            raise RuntimeError(f"Invalid or duplicate project asset id: {asset_id}")
        descriptors[asset_id] = safe_filename(
            descriptor.get("fileName"), "project fileName"
        )
    expected_names = {
        "source": f"yiyunying-source-v{version}.zip",
        "history": f"yiyunying-git-history-v{version}.bundle",
        "delivery": f"yiyunying-project-delivery-v{version}.zip",
        "manifest": "project-assets-manifest.json",
    }
    if descriptors != expected_names:
        raise RuntimeError("Project asset descriptors do not use the canonical finalized names")

    project_manifest_path = release_dir / descriptors["manifest"]
    project_manifest = read_json(project_manifest_path, "project assets manifest")
    expected_project_fields = {
        "schemaVersion": 3,
        "versionName": manifest["versionName"],
        "versionCode": manifest["versionCode"],
        "buildSourceCommit": build_commit,
        "releaseEvidenceCommit": evidence_commit,
        "releaseTag": expected_tag,
        "releaseIdentitySha256": identity_hash,
        "connectionIdentity": identity,
        "releaseManifestSha256": sha256(manifest_path),
        "bundleRefs": ["refs/heads/main", f"refs/tags/{expected_tag}"],
        "security": {
            "containsCredentials": False,
            "containsSigningKeys": False,
            "containsProductionData": False,
        },
    }
    if manifest.get("channel") not in (None, ""):
        expected_project_fields["channel"] = channel
    for field, expected in expected_project_fields.items():
        actual = project_manifest.get(field)
        if field.endswith("Sha256") and isinstance(actual, str):
            actual = actual.lower()
        if actual != expected:
            raise RuntimeError(f"Project assets manifest mismatch: {field}")

    asset_entries = project_manifest.get("assets")
    if not isinstance(asset_entries, list) or len(asset_entries) != 3:
        raise RuntimeError(
            "Project assets manifest must contain exactly source, history and delivery"
        )
    project_artifacts: list[Artifact] = []
    project_names: set[str] = set()
    expected_checksum_names = {
        expected_names[key] for key in ("source", "history", "delivery")
    }
    for item in asset_entries:
        if not isinstance(item, dict):
            raise RuntimeError("Project asset checksum entry must be an object")
        name = safe_filename(item.get("fileName"), "project checksum fileName")
        if name in project_names or name not in expected_checksum_names:
            raise RuntimeError(f"Unexpected or duplicate project asset: {name}")
        project_names.add(name)
        project_artifacts.append(
            exact_artifact(
                release_dir / name,
                item.get("sha256"),
                item.get("sizeBytes"),
                "project asset",
            )
        )
    if project_names != expected_checksum_names:
        raise RuntimeError("Project asset checksum set is incomplete")
    project_artifacts.append(
        Artifact(
            project_manifest_path.name,
            project_manifest_path,
            sha256(project_manifest_path),
            project_manifest_path.stat().st_size,
        )
    )

    sums_path = release_dir / "SHA256SUMS.txt"
    sums: dict[str, str] = {}
    try:
        sum_lines = sums_path.read_text(encoding="utf-8-sig").splitlines()
    except OSError as exc:
        raise RuntimeError(f"Cannot read SHA256SUMS.txt: {exc}") from exc
    for line in sum_lines:
        parts = line.strip().split(None, 1)
        if len(parts) != 2:
            raise RuntimeError("SHA256SUMS.txt contains an invalid line")
        digest = require_sha256(parts[0], "SHA256SUMS digest")
        name = safe_filename(parts[1].lstrip("*"), "SHA256SUMS fileName")
        if name in sums:
            raise RuntimeError(f"SHA256SUMS.txt contains a duplicate: {name}")
        sums[name] = digest
    expected_sums = {item.name: item.sha256 for item in apk_artifacts}
    if sums != expected_sums:
        raise RuntimeError("SHA256SUMS.txt must exactly describe the four APKs")

    if len(project_artifacts) != 4 or len({item.name for item in project_artifacts}) != 4:
        raise RuntimeError("Expected four finalized project artifacts")
    public_artifacts = [
        apk_by_id[release_id]
        for release_id in ("user", "admin", "authorized", "owner")
    ]
    if len(public_artifacts) != 4 or any(
        marker in artifact.name.lower()
        for artifact in public_artifacts
        for marker in ("debug", "source", "bundle", "delivery", "manifest")
    ):
        raise RuntimeError("Stable public artifact whitelist is invalid")
    return public_artifacts, manifest


def run_local(command: list[str], label: str) -> str:
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
        raise RuntimeError(
            f"{label} failed ({result.returncode}): {result.stdout.strip()}"
        )
    return result.stdout.strip()


def validate_git_release_evidence(repository_root: Path, manifest: dict) -> None:
    build_commit = str(manifest["buildSourceCommit"]).lower()
    evidence_commit = str(manifest["releaseEvidenceCommit"]).lower()
    tag = str(manifest["releaseTag"])
    if run_local(
        [
            "git",
            "-C",
            str(repository_root),
            "status",
            "--porcelain",
            "--untracked-files=all",
        ],
        "Git worktree",
    ):
        raise RuntimeError("Static publication requires a completely clean Git worktree")
    run_local(
        [
            "git",
            "-C",
            str(repository_root),
            "ls-files",
            "--error-unmatch",
            "--",
            "download-site/release-metadata.json",
        ],
        "Tracked B-commit metadata",
    )
    if (
        run_local(
            ["git", "-C", str(repository_root), "symbolic-ref", "--short", "HEAD"],
            "Git branch",
        )
        != "main"
    ):
        raise RuntimeError("Static publication is restricted to main")
    head = run_local(
        ["git", "-C", str(repository_root), "rev-parse", "HEAD^{commit}"],
        "Git evidence commit",
    ).lower()
    main = run_local(
        [
            "git",
            "-C",
            str(repository_root),
            "rev-parse",
            "refs/heads/main^{commit}",
        ],
        "Git main commit",
    ).lower()
    if head != evidence_commit or main != evidence_commit:
        raise RuntimeError("HEAD and main must equal evidence commit B")
    run_local(
        [
            "git",
            "-C",
            str(repository_root),
            "merge-base",
            "--is-ancestor",
            build_commit,
            evidence_commit,
        ],
        "A/B ancestry",
    )
    if (
        run_local(
            ["git", "-C", str(repository_root), "cat-file", "-t", f"refs/tags/{tag}"],
            "Annotated tag",
        )
        != "tag"
    ):
        raise RuntimeError("Release tag must be annotated")
    tagged = run_local(
        [
            "git",
            "-C",
            str(repository_root),
            "rev-parse",
            f"refs/tags/{tag}^{{commit}}",
        ],
        "Tag commit",
    ).lower()
    if tagged != evidence_commit:
        raise RuntimeError("Release tag must point exactly to evidence commit B")


def run(ssh: paramiko.SSHClient, command: str, *, check: bool = True) -> str:
    stdin, stdout, stderr = ssh.exec_command(command, timeout=1800, get_pty=False)
    del stdin
    output = stdout.read().decode("utf-8", errors="replace")
    error = stderr.read().decode("utf-8", errors="replace")
    status = stdout.channel.recv_exit_status()
    if check and status != 0:
        raise RuntimeError(
            f"Remote command failed ({status}): {error.strip() or output.strip()}"
        )
    return output.strip()


def connect_ssh(args: argparse.Namespace, password: str) -> paramiko.SSHClient:
    known_hosts = Path(args.known_hosts).resolve()
    if not known_hosts.is_file() or known_hosts.stat().st_size < 1:
        raise RuntimeError(
            "--known-hosts must reference a non-empty pinned host-key file"
        )
    ssh = paramiko.SSHClient()
    ssh.load_host_keys(str(known_hosts))
    ssh.set_missing_host_key_policy(paramiko.RejectPolicy())
    ssh.connect(
        args.host,
        port=args.port,
        username=args.username,
        password=password,
        look_for_keys=False,
        allow_agent=False,
        timeout=30,
        banner_timeout=60,
        auth_timeout=30,
        disabled_algorithms={
            "kex": ["curve25519-sha256", "curve25519-sha256@libssh.org"]
        },
    )
    return ssh


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


def upload_tree(
    sftp: paramiko.SFTPClient, files: list[SiteFile], remote_root: str
) -> None:
    ensure_remote_dir(sftp, remote_root)
    for item in files:
        remote_path = posixpath.join(remote_root, item.relative)
        ensure_remote_dir(sftp, posixpath.dirname(remote_path))
        sftp.put(str(item.path), remote_path)
        sftp.chmod(remote_path, 0o644)


def remote_identity(
    ssh: paramiko.SSHClient, remote_path: str
) -> tuple[int, str]:
    output = run(
        ssh,
        f"test -f {quote(remote_path)} && test ! -L {quote(remote_path)} && "
        f"printf '%s ' \"$(stat -c %s {quote(remote_path)})\" && "
        f"sha256sum {quote(remote_path)} | awk '{{print $1}}'",
    ).split()
    if (
        len(output) != 2
        or not output[0].isdigit()
        or SHA256_RE.fullmatch(output[1].lower()) is None
    ):
        raise RuntimeError(f"Invalid remote identity response: {remote_path}")
    return int(output[0]), output[1].lower()


def public_verification_command(
    local_path: Path,
    expected_size: int,
    expected_sha256: str,
    url: str,
    scratch: str,
) -> str:
    range_size = min(64 * 1024, expected_size)
    if range_size < 1:
        raise RuntimeError(f"Cannot verify an empty public file: {local_path}")
    range_end = range_size - 1
    range_hash = prefix_sha256(local_path, range_size)
    headers = scratch + ".headers"
    body = scratch + ".body"
    range_headers = scratch + ".range.headers"
    range_body = scratch + ".range.body"
    not_modified_headers = scratch + ".not-modified.headers"
    return " ; ".join(
        [
            "set -eu",
            f"status=$(curl -sS --proto '=http,https' --max-time 1800 -D {quote(headers)} -o {quote(body)} -w '%{{http_code}}' {quote(url)})",
            'test "$status" = 200',
            f"test \"$(stat -c %s {quote(body)})\" -eq {expected_size}",
            f"test \"$(sha256sum {quote(body)} | awk '{{print $1}}')\" = {quote(expected_sha256)}",
            f"etag=$(awk 'BEGIN{{IGNORECASE=1}} /^ETag:/{{sub(/\\r$/,\"\"); sub(/^[^:]*:[[:space:]]*/,\"\"); print; exit}}' {quote(headers)})",
            'test -n "$etag"',
            'case "$etag" in W/*) exit 41;; esac',
            f"range_status=$(curl -sS --proto '=http,https' --max-time 120 -D {quote(range_headers)} -o {quote(range_body)} -w '%{{http_code}}' -H \"Range: bytes=0-{range_end}\" -H \"If-Range: $etag\" {quote(url)})",
            'test "$range_status" = 206',
            f"test \"$(stat -c %s {quote(range_body)})\" -eq {range_size}",
            f"test \"$(sha256sum {quote(range_body)} | awk '{{print $1}}')\" = {quote(range_hash)}",
            f"grep -Eiq {quote('^Content-Range:[[:space:]]*bytes 0-' + str(range_end) + '/' + str(expected_size) + chr(92) + 'r?$')} {quote(range_headers)}",
            f"range_etag=$(awk 'BEGIN{{IGNORECASE=1}} /^ETag:/{{sub(/\\r$/,\"\"); sub(/^[^:]*:[[:space:]]*/,\"\"); print; exit}}' {quote(range_headers)})",
            'test "$range_etag" = "$etag"',
            f"not_modified=$(curl -sS --proto '=http,https' --max-time 120 -D {quote(not_modified_headers)} -o /dev/null -w '%{{http_code}}' -H \"If-None-Match: $etag\" {quote(url)})",
            'test "$not_modified" = 304',
            f"not_modified_etag=$(awk 'BEGIN{{IGNORECASE=1}} /^ETag:/{{sub(/\\r$/,\"\"); sub(/^[^:]*:[[:space:]]*/,\"\"); print; exit}}' {quote(not_modified_headers)})",
            'test "$not_modified_etag" = "$etag"',
            "rm -f -- " + " ".join(quote(path) for path in (headers, body, range_headers, range_body, not_modified_headers)),
        ]
    )


def rollback_command(
    remote_site: str,
    remote_release: str,
    staging_site: str,
    staging_release: str,
    rollback_site: str,
    had_previous_site: bool,
) -> str:
    commands = ["set -eu"]
    if had_previous_site:
        commands.append(
            f"if [ -d {quote(rollback_site)} ]; then "
            f"if [ -d {quote(remote_site)} ]; then test ! -e {quote(staging_site)}; "
            f"mv {quote(remote_site)} {quote(staging_site)}; fi; "
            f"test ! -e {quote(remote_site)}; "
            f"mv {quote(rollback_site)} {quote(remote_site)}; fi"
        )
    else:
        commands.append(
            f"if [ -d {quote(remote_site)} ] && [ ! -e {quote(staging_site)} ]; then "
            f"mv {quote(remote_site)} {quote(staging_site)}; fi"
        )
    commands.append(
        f"if [ -d {quote(remote_release)} ]; then "
        f"test ! -e {quote(staging_release)}; "
        f"mv {quote(remote_release)} {quote(staging_release)}; fi"
    )
    return " ; ".join(commands)
def main() -> int:
    args = parse_args()
    password = os.environ.get("YY_SSH_PASSWORD", "")
    if not password:
        raise RuntimeError("YY_SSH_PASSWORD is required")
    if VERSION_RE.fullmatch(args.version) is None:
        raise RuntimeError("--version contains unsafe characters")

    repository_root = Path(__file__).resolve().parents[2]
    site_dir = args.site_dir.resolve()
    release_dir = args.release_dir.resolve()
    expected_site_dir = (repository_root / "download-site" / "static-dist").resolve()
    expected_release_dir = (repository_root / "releases" / args.version).resolve()
    if site_dir != expected_site_dir or release_dir != expected_release_dir:
        raise RuntimeError(
            "Static publication only accepts canonical download-site/static-dist "
            "and releases/<version> inputs"
        )
    artifacts, manifest = load_release_files(
        release_dir, args.version, repository_root
    )
    channel = release_channel(manifest)
    site_files = validate_site_tree(
        site_dir, args.version, channel=channel, manifest=manifest
    )
    validate_git_release_evidence(repository_root, manifest)
    validate_public_transport(
        manifest,
        args.allow_insecure_http_debug,
        args.insecure_http_confirmation,
    )

    public_root = validate_remote_root(args.remote_public_root)
    public_base = public_origin(
        str(manifest["connectionIdentity"]["apiBaseUrl"])
    )
    remote_site = posixpath.join(public_root, "download-center")
    downloads_root = posixpath.join(public_root, "downloads")
    remote_release = posixpath.join(downloads_root, args.version)
    token = secrets.token_hex(16)
    staging_root = posixpath.join(
        public_root, f".download-deploy-{args.version}-{token}"
    )
    staging_site = posixpath.join(staging_root, "download-center")
    staging_release = posixpath.join(staging_root, "release")
    rollback_site = posixpath.join(
        public_root, f".download-center.previous-{token}"
    )
    lock_dir = posixpath.join(public_root, ".download-deploy.lock")

    ssh: paramiko.SSHClient | None = None
    lock_acquired = False
    candidate_started = False
    completed = False
    rollback_ok = False
    had_previous_site = False
    try:
        ssh = connect_ssh(args, password)
        run(
            ssh,
            f"test -d {quote(public_root)} && test ! -L {quote(public_root)} && "
            "command -v curl >/dev/null && command -v sha256sum >/dev/null && "
            "command -v stat >/dev/null && "
            f"mkdir {quote(lock_dir)}",
        )
        lock_acquired = True
        state = run(
            ssh,
            f"mkdir -p {quote(downloads_root)} && "
            f"test ! -L {quote(downloads_root)} && "
            f"test ! -e {quote(remote_release)} && "
            f"test ! -e {quote(staging_root)} && "
            f"test ! -e {quote(rollback_site)} && "
            f"mkdir {quote(staging_root)} && mkdir {quote(staging_release)} && "
            f"test \"$(stat -c %d {quote(public_root)})\" = "
            f"\"$(stat -c %d {quote(staging_root)})\" && "
            f"test \"$(stat -c %d {quote(downloads_root)})\" = "
            f"\"$(stat -c %d {quote(staging_root)})\" && "
            f"if [ -e {quote(remote_site)} ]; then "
            f"test -d {quote(remote_site)} && test ! -L {quote(remote_site)} && "
            "printf present; else printf absent; fi",
        )
        had_previous_site = state == "present"
        if state not in {"present", "absent"}:
            raise RuntimeError("Could not determine current download-center state")
        candidate_started = True

        with ssh.open_sftp() as sftp:
            upload_tree(sftp, site_files, staging_site)
            for artifact in artifacts:
                remote_file = posixpath.join(staging_release, artifact.name)
                sftp.put(str(artifact.path), remote_file)
                sftp.chmod(remote_file, 0o644)

        for item in site_files:
            actual = remote_identity(
                ssh, posixpath.join(staging_site, item.relative)
            )
            if actual != (item.size, item.sha256):
                raise RuntimeError(
                    f"Uploaded site identity mismatch: {item.relative}"
                )
        for artifact in artifacts:
            actual = remote_identity(
                ssh, posixpath.join(staging_release, artifact.name)
            )
            if actual != (artifact.size, artifact.sha256):
                raise RuntimeError(
                    f"Uploaded release identity mismatch: {artifact.name}"
                )
        run(
            ssh,
            f"find {quote(staging_site)} {quote(staging_release)} "
            "-type d -exec chmod 0755 {} + && "
            f"find {quote(staging_site)} {quote(staging_release)} "
            "-type f -exec chmod 0644 {} +",
        )

        activation = ["set -eu"]
        if had_previous_site:
            activation.append(f"mv {quote(remote_site)} {quote(rollback_site)}")
        activation.extend(
            [
                f"test ! -e {quote(remote_release)}",
                f"mv {quote(staging_release)} {quote(remote_release)}",
                f"mv {quote(staging_site)} {quote(remote_site)}",
            ]
        )
        run(ssh, " ; ".join(activation))

        for artifact in artifacts:
            actual = remote_identity(
                ssh, posixpath.join(remote_release, artifact.name)
            )
            if actual != (artifact.size, artifact.sha256):
                raise RuntimeError(
                    f"Activated release identity mismatch: {artifact.name}"
                )
        for item in site_files:
            actual = remote_identity(
                ssh, posixpath.join(remote_site, item.relative)
            )
            if actual != (item.size, item.sha256):
                raise RuntimeError(
                    f"Activated site identity mismatch: {item.relative}"
                )

        index = next(item for item in site_files if item.relative == "index.html")
        public_checks: list[tuple[Path, int, str, str, str]] = [
            (
                index.path,
                index.size,
                index.sha256,
                f"{public_base}/download-center/index.html",
                "site-index",
            )
        ]
        public_checks.extend(
            (
                artifact.path,
                artifact.size,
                artifact.sha256,
                f"{public_base}/downloads/{args.version}/{artifact.name}",
                artifact.name,
            )
            for artifact in artifacts
        )
        for position, (path, size, digest, url, label) in enumerate(public_checks):
            scratch = posixpath.join(staging_root, f"public-check-{position}")
            run(
                ssh,
                public_verification_command(path, size, digest, url, scratch),
            )
            print(
                f"[public-verified] {label}: size={size}, sha256={digest}"
            )

        completed = True
        rollback_ok = True
        print(
            f"Deployed immutable download center {args.version} ({channel}); "
            f"verified site plus {len(artifacts)} public release artifacts."
        )
        print(f"{channel} public release: {remote_release}")
        print(
            "WARNING: static deployment does not activate software_update_policies. "
            "Run backend/tools/publish-android-ssh.py and "
            "backend/tools/verify-production-release-ssh.py before announcing the release."
        )
        return 0
    except Exception:
        if ssh is not None and candidate_started:
            rollback_ok = False
            try:
                transport = ssh.get_transport()
                if transport is None or not transport.is_active():
                    ssh.close()
                    ssh = connect_ssh(args, password)
                run(
                    ssh,
                    rollback_command(
                        remote_site,
                        remote_release,
                        staging_site,
                        staging_release,
                        rollback_site,
                        had_previous_site,
                    ),
                )
                rollback_ok = True
            except Exception as rollback_error:
                print(
                    "ROLLBACK INCOMPLETE; deployment lock retained. "
                    f"staging={staging_root}, previous={rollback_site}, "
                    f"error={rollback_error}",
                    file=sys.stderr,
                )
        elif lock_acquired:
            rollback_ok = True
        raise
    finally:
        if ssh is not None:
            if lock_acquired and (completed or rollback_ok):
                cleanup = [staging_root]
                if completed:
                    cleanup.append(rollback_site)
                try:
                    run(
                        ssh,
                        "rm -rf -- " + " ".join(quote(path) for path in cleanup),
                    )
                    run(ssh, f"rmdir {quote(lock_dir)}")
                except Exception as cleanup_error:
                    print(
                        "CLEANUP INCOMPLETE; deployment lock retained: "
                        f"{cleanup_error}",
                        file=sys.stderr,
                    )
            ssh.close()


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(
            f"Static deployment failed: {type(exc).__name__}: {exc}",
            file=sys.stderr,
        )
        raise SystemExit(1)
