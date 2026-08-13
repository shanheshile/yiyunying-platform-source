#!/usr/bin/env python3
"""Fail-closed verification for an evidence-bound production Android release."""

from __future__ import annotations

import argparse
from collections.abc import Mapping
from dataclasses import dataclass
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import shlex
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

import paramiko


EDITION_BY_RELEASE_ID = {
    "owner": "platform_owner",
    "authorized": "authorized_platform",
    "admin": "admin",
    "user": "user",
}
REQUIRED_EDITIONS = frozenset(EDITION_BY_RELEASE_ID.values())
SCHEMA_MIGRATIONS = (
    "2026.08.11-content-moderation-closure",
    "2026.08.11-short-video-controls",
    "2026.08.11-resource-store-review-closure",
    "2026.08.11-management-shell-restructure",
)
CATALOG_SCHEMA_MIGRATION = "2026.08.11-resource-store-review-closure"
SHA256_PATTERN = re.compile(r"^[0-9a-fA-F]{64}$")
COMMIT_PATTERN = re.compile(r"^[0-9a-fA-F]{40}$")
PACKAGE_LINE_PATTERN = re.compile(
    r"^package:\s+name='(?P<package>[^']+)'\s+versionCode='(?P<code>\d+)'\s+"
    r"versionName='(?P<name>[^']+)'"
)
SIGNER_PATTERN = re.compile(
    r"^Signer #(?P<number>\d+) certificate SHA-256 digest:\s*(?P<digest>[0-9A-Fa-f]{64})\s*$"
)
INSECURE_HTTP_CONFIRMATION = "DEBUG_HTTP_NON_PRODUCTION_CONFIRMED"


@dataclass(frozen=True, repr=False)
class ConnectionIdentity:
    api_base_url: str
    app_key: str
    platform_key: str
    authorized_platform_key: str
    app_key_sha256: str
    platform_key_sha256: str
    authorized_platform_key_sha256: str

    def public_evidence(self) -> dict[str, str]:
        return {
            "apiBaseUrl": self.api_base_url,
            "appKeySha256": self.app_key_sha256,
            "platformKeySha256": self.platform_key_sha256,
            "authorizedPlatformKeySha256": self.authorized_platform_key_sha256,
        }


def quote(value: str) -> str:
    return shlex.quote(value)


def run(
    client: paramiko.SSHClient,
    command: str,
    label: str,
    *,
    sensitive_output: bool = False,
) -> str:
    stdin, stdout, stderr = client.exec_command(command, get_pty=False)
    del stdin
    output = stdout.read().decode("utf-8", errors="replace")
    error = stderr.read().decode("utf-8", errors="replace")
    status = stdout.channel.recv_exit_status()
    if status != 0:
        detail = "sensitive output redacted" if sensitive_output else (error or output)
        raise RuntimeError(f"{label} failed ({status}): {detail}")
    return output


def mysql_query(
    client: paramiko.SSHClient,
    args: argparse.Namespace,
    db_password: str,
    query: str,
    label: str,
    *,
    sensitive_output: bool = False,
) -> str:
    command = (
        "MYSQL_BIN=$(command -v mysql || true); "
        "if [ -z \"$MYSQL_BIN\" ] && [ -x /www/server/mysql/bin/mysql ]; "
        "then MYSQL_BIN=/www/server/mysql/bin/mysql; fi; "
        "test -n \"$MYSQL_BIN\"; "
        f"MYSQL_PWD={quote(db_password)} \"$MYSQL_BIN\" --default-character-set=utf8mb4 "
        "--batch --raw --skip-column-names "
        f"-h {quote(args.db_host)} -P {args.db_port} -u {quote(args.db_user)} "
        f"{quote(args.db_name)} -e {quote(query)}"
    )
    return run(client, command, label, sensitive_output=sensitive_output)


def load_json_bytes(path: Path, label: str) -> tuple[bytes, dict[str, object]]:
    try:
        payload = path.read_bytes()
    except OSError as exc:
        raise RuntimeError(f"cannot read {label}: {path}: {exc}") from exc
    try:
        value = json.loads(payload.decode("utf-8-sig"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"invalid {label} JSON: {path}: {exc}") from exc
    if not isinstance(value, dict):
        raise RuntimeError(f"{label} must be a JSON object: {path}")
    return payload, value


def require_text(value: object, field: str) -> str:
    if not isinstance(value, str) or not value.strip():
        raise RuntimeError(f"release manifest field {field} must be non-empty text")
    return value.strip()


def require_positive_int(value: object, field: str) -> int:
    if isinstance(value, bool) or not isinstance(value, int) or value <= 0:
        raise RuntimeError(f"release manifest field {field} must be a positive integer")
    return value


def normalize_api_base_url(value: str) -> str:
    raw = value.strip()
    if not raw or raw != value:
        raise RuntimeError("apiBaseUrl must be non-empty and have no surrounding whitespace")
    parsed = urllib.parse.urlsplit(raw)
    try:
        parsed_port = parsed.port
    except ValueError as exc:
        raise RuntimeError("apiBaseUrl contains an invalid port") from exc
    if (
        parsed.scheme.lower() not in {"http", "https"}
        or not parsed.hostname
        or parsed.username is not None
        or parsed.password is not None
        or parsed.query
        or parsed.fragment
    ):
        raise RuntimeError(
            "apiBaseUrl must be an absolute HTTP(S) URL without credentials, query or fragment"
        )
    del parsed_port
    path = parsed.path or "/"
    if not path.endswith("/"):
        path += "/"
    return urllib.parse.urlunsplit(
        (parsed.scheme.lower(), parsed.netloc.lower(), path, "", "")
    )


def validate_manifest_connection_identity(manifest: dict[str, object]) -> dict[str, str]:
    value = manifest.get("connectionIdentity")
    if not isinstance(value, dict):
        raise RuntimeError("finalized release manifest must contain connectionIdentity")
    api_base_url = value.get("apiBaseUrl")
    if not isinstance(api_base_url, str):
        raise RuntimeError("connectionIdentity.apiBaseUrl must be text")
    normalized_api_base_url = normalize_api_base_url(api_base_url)
    if normalized_api_base_url != api_base_url:
        raise RuntimeError("connectionIdentity.apiBaseUrl must use canonical trailing-slash form")
    evidence = {"apiBaseUrl": normalized_api_base_url}
    for field in (
        "appKeySha256",
        "platformKeySha256",
        "authorizedPlatformKeySha256",
    ):
        digest_value = value.get(field)
        if not isinstance(digest_value, str) or SHA256_PATTERN.fullmatch(digest_value) is None:
            raise RuntimeError(f"connectionIdentity.{field} must be a SHA-256 digest")
        evidence[field] = digest_value.lower()
    return evidence


def load_connection_identity_from_environment(
    manifest_evidence: dict[str, str],
    environment: Mapping[str, str] | None = None,
) -> ConnectionIdentity:
    source = os.environ if environment is None else environment
    values: dict[str, str] = {}
    for variable in (
        "YY_API_BASE_URL",
        "YY_APP_KEY",
        "YY_PLATFORM_KEY",
        "YY_AUTHORIZED_PLATFORM_KEY",
    ):
        value = source.get(variable, "")
        if (
            not isinstance(value, str)
            or not value
            or value != value.strip()
            or any(character in value for character in ("\x00", "\r", "\n"))
        ):
            raise RuntimeError(f"{variable} is required and must be a single exact value")
        values[variable] = value

    api_base_url = normalize_api_base_url(values["YY_API_BASE_URL"])
    if not hmac.compare_digest(api_base_url, manifest_evidence["apiBaseUrl"]):
        raise RuntimeError("YY_API_BASE_URL does not match release manifest connectionIdentity")
    hashed_values = {
        "appKeySha256": hashlib.sha256(values["YY_APP_KEY"].encode("utf-8")).hexdigest(),
        "platformKeySha256": hashlib.sha256(
            values["YY_PLATFORM_KEY"].encode("utf-8")
        ).hexdigest(),
        "authorizedPlatformKeySha256": hashlib.sha256(
            values["YY_AUTHORIZED_PLATFORM_KEY"].encode("utf-8")
        ).hexdigest(),
    }
    for field, actual_digest in hashed_values.items():
        if not hmac.compare_digest(actual_digest, manifest_evidence[field]):
            raise RuntimeError(f"environment connection identity hash mismatch for {field}")
    return ConnectionIdentity(
        api_base_url=api_base_url,
        app_key=values["YY_APP_KEY"],
        platform_key=values["YY_PLATFORM_KEY"],
        authorized_platform_key=values["YY_AUTHORIZED_PLATFORM_KEY"],
        app_key_sha256=hashed_values["appKeySha256"],
        platform_key_sha256=hashed_values["platformKeySha256"],
        authorized_platform_key_sha256=hashed_values[
            "authorizedPlatformKeySha256"
        ],
    )


def url_origin(value: str, label: str) -> tuple[str, str, int]:
    parsed = urllib.parse.urlsplit(value)
    try:
        port = parsed.port
    except ValueError as exc:
        raise RuntimeError(f"{label} contains an invalid port") from exc
    scheme = parsed.scheme.lower()
    if scheme not in {"http", "https"} or not parsed.hostname:
        raise RuntimeError(f"{label} must be an absolute HTTP(S) URL")
    return scheme, parsed.hostname.lower(), port or (443 if scheme == "https" else 80)


def validate_connection_origins(
    api_base_url: str,
    urls: dict[str, str],
) -> None:
    expected_origin = url_origin(api_base_url, "connectionIdentity.apiBaseUrl")
    for label, value in urls.items():
        if url_origin(value, label) != expected_origin:
            raise RuntimeError(
                f"{label} scheme, host and port must match connectionIdentity.apiBaseUrl"
            )


def validate_remote_connection_identity(
    identity: ConnectionIdentity,
    root_platform_key: str,
    authorized_platform_key: str,
    app_keys: list[str],
) -> None:
    mismatches = []
    if not hmac.compare_digest(root_platform_key, identity.platform_key):
        mismatches.append("platformKey")
    if not hmac.compare_digest(
        authorized_platform_key, identity.authorized_platform_key
    ):
        mismatches.append("authorizedPlatformKey")
    app_key_matches = sum(
        1 for value in app_keys if hmac.compare_digest(value, identity.app_key)
    )
    if app_key_matches != 1:
        mismatches.append("appKey")
    if mismatches:
        raise RuntimeError(
            "remote database connection identity mismatch: " + ", ".join(mismatches)
        )


def select_platform_connection_context(
    platforms: list[tuple[int, str, int, int]],
    identity: ConnectionIdentity,
) -> tuple[tuple[int, str, int, int], tuple[int, str, int, int]]:
    roots = [
        row
        for row in platforms
        if row[2] == 1 and hmac.compare_digest(row[1], identity.platform_key)
    ]
    if len(roots) != 1:
        raise RuntimeError("release root platform identity must match exactly one active row")
    root = roots[0]
    authorized = [
        row
        for row in platforms
        if row[2] == 2
        and row[3] == root[0]
        and hmac.compare_digest(row[1], identity.authorized_platform_key)
    ]
    if len(authorized) != 1:
        raise RuntimeError(
            "authorized platform identity must match exactly one active child row"
        )
    return root, authorized[0]


def load_release_evidence(
    identity_path: Path, manifest_path: Path
) -> tuple[
    dict[str, object],
    dict[str, dict[str, object]],
    dict[str, str],
    str,
    str,
    str,
    str,
    str,
]:
    identity_bytes, identity = load_json_bytes(identity_path, "release identity")
    manifest_bytes, manifest = load_json_bytes(manifest_path, "release manifest")

    version_name = identity.get("version_name")
    version_code = identity.get("version_code")
    if not isinstance(version_name, str) or re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", version_name) is None:
        raise RuntimeError("release identity version_name is invalid")
    if isinstance(version_code, bool) or not isinstance(version_code, int) or version_code <= 0:
        raise RuntimeError("release identity version_code is invalid")

    identity_sha256 = hashlib.sha256(identity_bytes).hexdigest()
    manifest_identity_sha256 = str(manifest.get("releaseIdentitySha256", "")).lower()
    if not SHA256_PATTERN.fullmatch(manifest_identity_sha256):
        raise RuntimeError("release manifest releaseIdentitySha256 is missing or invalid")
    if manifest_identity_sha256 != identity_sha256:
        raise RuntimeError("release manifest is not bound to the supplied release identity bytes")
    if manifest.get("versionName") != version_name or manifest.get("versionCode") != version_code:
        raise RuntimeError("release identity and release manifest versions do not match")

    if manifest.get("schemaVersion") != 4 or manifest.get("finalizationStatus") != "finalized":
        raise RuntimeError("release manifest must be finalized schemaVersion 4 evidence")
    connection_identity = validate_manifest_connection_identity(manifest)
    build_commit = str(manifest.get("buildSourceCommit", "")).lower()
    evidence_commit = str(manifest.get("releaseEvidenceCommit", "")).lower()
    release_tag = str(manifest.get("releaseTag", ""))
    if (
        COMMIT_PATTERN.fullmatch(build_commit) is None
        or COMMIT_PATTERN.fullmatch(evidence_commit) is None
        or build_commit == evidence_commit
    ):
        raise RuntimeError("release manifest build/evidence commits are missing, invalid or equal")
    if release_tag != f"v{version_name}-debug":
        raise RuntimeError("release manifest tag does not match v<version>-debug")

    releases = manifest.get("releases")
    if not isinstance(releases, list) or len(releases) != 4:
        raise RuntimeError("release manifest must contain exactly four Android releases")
    expected: dict[str, dict[str, object]] = {}
    seen_release_ids: set[str] = set()
    release_signers: set[str] = set()
    for index, entry in enumerate(releases):
        if not isinstance(entry, dict):
            raise RuntimeError(f"release manifest releases[{index}] must be an object")
        release_id = require_text(entry.get("id"), f"releases[{index}].id")
        if release_id not in EDITION_BY_RELEASE_ID or release_id in seen_release_ids:
            raise RuntimeError(f"unsupported or duplicate release id: {release_id}")
        seen_release_ids.add(release_id)
        edition = EDITION_BY_RELEASE_ID[release_id]
        sha256 = require_text(entry.get("sha256"), f"releases[{index}].sha256").lower()
        signer = require_text(entry.get("signerSha256"), f"releases[{index}].signerSha256").lower()
        if SHA256_PATTERN.fullmatch(sha256) is None or SHA256_PATTERN.fullmatch(signer) is None:
            raise RuntimeError(f"invalid APK or signer SHA-256 for {edition}")
        release_signers.add(signer)
        entry_version_code = require_positive_int(
            entry.get("versionCode"), f"releases[{index}].versionCode"
        )
        if entry_version_code != version_code:
            raise RuntimeError(f"version code mismatch in release {edition}")
        file_name = require_text(entry.get("fileName"), f"releases[{index}].fileName")
        if file_name != os.path.basename(file_name) or not file_name.lower().endswith(".apk"):
            raise RuntimeError(f"release APK filename is unsafe for {edition}")
        expected[edition] = {
            "file_name": file_name,
            "package": require_text(entry.get("packageName"), f"releases[{index}].packageName"),
            "version_name": require_text(
                entry.get("versionName"), f"releases[{index}].versionName"
            ),
            "version_code": entry_version_code,
            "sha256": sha256,
            "size": require_positive_int(entry.get("sizeBytes"), f"releases[{index}].sizeBytes"),
            "signer_sha256": signer,
        }
    if set(expected) != REQUIRED_EDITIONS:
        raise RuntimeError("all four Android editions must be present exactly once")
    if len(release_signers) != 1:
        raise RuntimeError("all four Android editions must use one unified signer")
    return (
        identity,
        expected,
        connection_identity,
        identity_sha256,
        build_commit,
        evidence_commit,
        release_tag,
        hashlib.sha256(manifest_bytes).hexdigest(),
    )


def validate_https_url(
    value: str, label: str, allow_insecure_http_debug: bool = False
) -> urllib.parse.ParseResult:
    parsed = urllib.parse.urlparse(value)
    if (
        parsed.scheme.lower()
        not in ({"http", "https"} if allow_insecure_http_debug else {"https"})
        or not parsed.hostname
        or parsed.username is not None
        or parsed.password is not None
        or parsed.fragment
    ):
        requirement = "HTTP(S) Debug" if allow_insecure_http_debug else "HTTPS"
        raise RuntimeError(
            f"{label} must be a {requirement} URL without credentials or fragment"
        )
    return parsed


def authorize_insecure_http_debug(
    expected: dict[str, dict[str, object]],
    requested: bool,
    confirmation: str,
) -> bool:
    if not requested:
        if confirmation:
            raise RuntimeError("insecure HTTP confirmation requires the explicit Debug flag")
        return False
    if confirmation != INSECURE_HTTP_CONFIRMATION:
        raise RuntimeError("the exact non-production Debug HTTP confirmation is required")
    invalid = [
        edition
        for edition, release in expected.items()
        if not str(release["package"]).endswith(".debug")
        or not str(release["version_name"]).endswith("-debug")
        or not str(release["file_name"]).endswith("-debug.apk")
    ]
    if invalid:
        raise RuntimeError(
            "insecure HTTP verification is restricted to four Debug APK identities; invalid="
            + ",".join(sorted(invalid))
        )
    return True


def connect_ssh(args: argparse.Namespace, password: str) -> paramiko.SSHClient:
    known_hosts = Path(args.known_hosts).expanduser().resolve()
    if not known_hosts.is_file():
        raise RuntimeError(f"pinned known_hosts file does not exist: {known_hosts}")
    last_error: Exception | None = None
    for attempt in range(1, 6):
        client = paramiko.SSHClient()
        # Load only the explicitly supplied host-key set. Unknown or changed keys fail closed.
        client.load_host_keys(str(known_hosts))
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
            )
            transport = client.get_transport()
            if transport is not None:
                transport.set_keepalive(20)
            return client
        except Exception as exc:
            client.close()
            last_error = exc
            if attempt >= 5:
                break
            delay = min(attempt * 2, 8)
            print(
                f"[ssh-retry] attempt={attempt}, error={type(exc).__name__}, "
                f"retry-in={delay}s"
            )
            time.sleep(delay)
    if last_error is None:
        raise RuntimeError("SSH connection failed without an exception")
    raise last_error


def verify_remote_release_state(
    client: paramiko.SSHClient,
    args: argparse.Namespace,
    db_password: str,
    identity: dict[str, object],
    identity_sha256: str,
    expected: dict[str, dict[str, object]],
    manifest_connection_identity: dict[str, str],
    build_commit: str,
    evidence_commit: str,
    release_tag: str,
    manifest_sha256: str,
    allow_insecure_http_debug: bool,
) -> dict[str, str]:
    backend_root = args.remote_backend_root.rstrip("/")
    identity_path = f"{backend_root}/config/release-identity.json"
    raw = run(
        client,
        "set -eu; "
        f"test -f {quote(identity_path)}; "
        f"sha256sum {quote(identity_path)} | awk '{{print $1}}'; "
        f"cat {quote(identity_path)}",
        "remote-release-identity",
    )
    remote_hash, separator, remote_json = raw.partition("\n")
    if not separator or remote_hash.strip().lower() != identity_sha256:
        raise RuntimeError("deployed backend release identity bytes do not match the local identity")
    try:
        remote_identity = json.loads(remote_json)
    except json.JSONDecodeError as exc:
        raise RuntimeError("deployed backend release identity JSON is invalid") from exc
    if remote_identity != identity:
        raise RuntimeError("deployed backend release identity content does not match")

    migration_values = ", ".join("'" + value.replace("'", "''") + "'" for value in SCHEMA_MIGRATIONS)
    migration_rows = mysql_query(
        client,
        args,
        db_password,
        "SELECT version, COUNT(*) FROM schema_migrations "
        f"WHERE version IN ({migration_values}) GROUP BY version ORDER BY version",
        "schema-migration-gate",
    )
    migration_counts: dict[str, int] = {}
    for row in migration_rows.splitlines():
        parts = row.split("\t")
        if len(parts) != 2 or not parts[1].isdigit():
            raise RuntimeError(f"schema migration gate returned malformed row: {row!r}")
        migration_counts[parts[0]] = int(parts[1])
    invalid_migrations = [
        version for version in SCHEMA_MIGRATIONS if migration_counts.get(version) != 1
    ]
    if invalid_migrations or set(migration_counts) != set(SCHEMA_MIGRATIONS):
        raise RuntimeError(
            "required schema migrations are not registered exactly once: "
            + ", ".join(invalid_migrations or sorted(set(migration_counts) - set(SCHEMA_MIGRATIONS)))
        )

    gate_rows = mysql_query(
        client,
        args,
        db_password,
        "SELECT (SELECT COUNT(*) FROM apps WHERE deleted_at IS NULL), "
        "(SELECT COUNT(*) FROM apps a LEFT JOIN app_settings s ON s.app_id = a.id "
        "AND s.setting_key = 'catalog_private_migration_ready' "
        "WHERE a.deleted_at IS NULL AND (s.id IS NULL "
        "OR s.setting_value NOT IN ('1', 'true') OR s.value_type <> 'bool'))",
        "catalog-runtime-gate",
    ).strip().split("\t")
    if len(gate_rows) != 2 or not all(value.isdigit() for value in gate_rows):
        raise RuntimeError(f"catalog runtime gate returned malformed counts: {gate_rows!r}")
    if int(gate_rows[0]) < 1 or int(gate_rows[1]) != 0:
        raise RuntimeError(
            f"catalog runtime gate is not activated for every app: apps={gate_rows[0]}, "
            f"not_ready={gate_rows[1]}"
        )

    version_name = str(identity["version_name"])
    receipt_dir = f"{backend_root}/storage/private/catalog-migration-reports"
    receipt_glob = f"catalog-private-activation-{version_name}-*Z.json"
    receipt_path = run(
        client,
        "set -eu; "
        f"test -d {quote(receipt_dir)}; "
        f"find {quote(receipt_dir)} -maxdepth 1 -type f -name {quote(receipt_glob)} "
        "-printf '%T@\t%p\n' | sort -nr | head -n 1 | cut -f2-",
        "catalog-activation-receipt-path",
    ).strip()
    if not receipt_path.startswith(receipt_dir + "/"):
        raise RuntimeError("no activated catalog migration receipt exists for this release")
    receipt_raw = run(
        client,
        f"set -eu; test -f {quote(receipt_path)}; cat {quote(receipt_path)}",
        "catalog-activation-receipt",
    )
    try:
        receipt = json.loads(receipt_raw)
    except json.JSONDecodeError as exc:
        raise RuntimeError("catalog migration activation receipt JSON is invalid") from exc
    receipt_checks = {
        "status": receipt.get("status") == "activated",
        "release_version": receipt.get("release_version") == identity["version_name"],
        "release_code": receipt.get("release_code") == identity["version_code"],
        "release_identity_sha256": str(receipt.get("release_identity_sha256", "")).lower()
        == identity_sha256,
        "schema_migration": receipt.get("schema_migration") == CATALOG_SCHEMA_MIGRATION,
        "runtime_gate_readback": receipt.get("runtime_gate_readback") is True,
        "activated_at_utc": isinstance(receipt.get("activated_at_utc"), str)
        and bool(receipt.get("activated_at_utc")),
    }
    failed = [name for name, passed in receipt_checks.items() if not passed]
    if failed:
        raise RuntimeError(f"catalog migration activation receipt failed: {', '.join(failed)}")
    report_file = receipt.get("report_file")
    report_sha256 = str(receipt.get("report_sha256", "")).lower()
    if (
        not isinstance(report_file, str)
        or not report_file
        or report_file != os.path.basename(report_file)
        or SHA256_PATTERN.fullmatch(report_sha256) is None
    ):
        raise RuntimeError("catalog migration receipt report evidence is invalid")
    report_path = f"{receipt_dir}/{report_file}"
    actual_report_hash = run(
        client,
        f"set -eu; test -f {quote(report_path)}; sha256sum {quote(report_path)} | awk '{{print $1}}'",
        "catalog-migration-report-hash",
    ).strip().lower()
    if actual_report_hash != report_sha256:
        raise RuntimeError("catalog migration report hash does not match its activated receipt")

    publication_dir = f"{backend_root}/storage/private/release-publication-receipts"
    publication_glob = f"android-publication-{version_name}-*.json"
    publication_path = run(
        client,
        "set -eu; "
        f"test -d {quote(publication_dir)}; "
        f"find {quote(publication_dir)} -maxdepth 1 -type f -name {quote(publication_glob)} "
        "-printf '%T@\t%p\n' | sort -nr | head -n 1 | cut -f2-",
        "android-publication-receipt-path",
    ).strip()
    if not publication_path.startswith(publication_dir + "/"):
        raise RuntimeError("no Android publication receipt exists for this release")
    publication_raw = run(
        client,
        f"set -eu; test -f {quote(publication_path)}; cat {quote(publication_path)}",
        "android-publication-receipt",
    )
    try:
        publication = json.loads(publication_raw)
    except json.JSONDecodeError as exc:
        raise RuntimeError("Android publication receipt JSON is invalid") from exc
    top_checks = {
        "status": publication.get("status") == "activated",
        "version_name": publication.get("version_name") == identity["version_name"],
        "version_code": publication.get("version_code") == identity["version_code"],
        "build_source_commit": publication.get("build_source_commit") == build_commit,
        "release_evidence_commit": publication.get("release_evidence_commit") == evidence_commit,
        "release_tag": publication.get("release_tag") == release_tag,
        "release_identity_sha256": publication.get("release_identity_sha256") == identity_sha256,
        "release_manifest_sha256": publication.get("release_manifest_sha256") == manifest_sha256,
        "connection_identity": publication.get("connection_identity")
        == manifest_connection_identity,
        "activated_at_utc": isinstance(publication.get("activated_at_utc"), str)
        and bool(publication.get("activated_at_utc")),
    }
    failed = [name for name, passed in top_checks.items() if not passed]
    if failed:
        raise RuntimeError(f"Android publication receipt failed: {', '.join(failed)}")
    publication_releases = publication.get("releases")
    if not isinstance(publication_releases, list) or len(publication_releases) != 4:
        raise RuntimeError("Android publication receipt must contain exactly four editions")
    receipt_urls: dict[str, str] = {}
    for entry in publication_releases:
        if not isinstance(entry, dict):
            raise RuntimeError("Android publication receipt contains a malformed edition")
        edition = str(entry.get("edition", ""))
        if edition not in expected or edition in receipt_urls:
            raise RuntimeError("Android publication receipt contains an unsupported or duplicate edition")
        release = expected[edition]
        download_url = str(entry.get("download_url", ""))
        validate_https_url(
            download_url,
            f"{edition} receipt download_url",
            allow_insecure_http_debug,
        )
        validate_connection_origins(
            manifest_connection_identity["apiBaseUrl"],
            {f"{edition} receipt download_url": download_url},
        )
        checks = {
            "version_name": entry.get("version_name") == release["version_name"],
            "version_code": entry.get("version_code") == release["version_code"],
            "package_name": entry.get("package_name") == release["package"],
            "sha256": str(entry.get("sha256", "")).lower() == release["sha256"],
            "size_bytes": entry.get("size_bytes") == release["size"],
            "signer_sha256": str(entry.get("signer_sha256", "")).lower()
            == release["signer_sha256"],
            "download_file": urllib.parse.unquote(
                validate_https_url(
                    download_url,
                    f"{edition} receipt download_url",
                    allow_insecure_http_debug,
                ).path
            ).endswith("/" + str(release["file_name"])),
        }
        entry_failed = [name for name, passed in checks.items() if not passed]
        if entry_failed:
            raise RuntimeError(
                f"{edition} Android publication receipt failed: {', '.join(entry_failed)}"
            )
        receipt_urls[edition] = download_url
    if set(receipt_urls) != REQUIRED_EDITIONS:
        raise RuntimeError("Android publication receipt is not the complete four-edition set")
    print(
        "[ok] server identity, four schema migrations, catalog gate/receipt and Android publication receipt"
    )
    return receipt_urls


class HttpsOnlyRedirectHandler(urllib.request.HTTPRedirectHandler):
    def __init__(
        self,
        allow_insecure_http_debug: bool = False,
        api_base_url: str = "",
    ):
        super().__init__()
        self.allow_insecure_http_debug = allow_insecure_http_debug
        self.api_base_url = api_base_url

    def redirect_request(self, request, file_pointer, code, message, headers, new_url):
        validate_https_url(
            new_url, "redirect target", self.allow_insecure_http_debug
        )
        if self.api_base_url:
            validate_connection_origins(
                self.api_base_url, {"redirect target": new_url}
            )
        return super().redirect_request(
            request, file_pointer, code, message, headers, new_url
        )


def build_https_opener(
    allow_insecure_http_debug: bool = False,
    api_base_url: str = "",
) -> urllib.request.OpenerDirector:
    return urllib.request.build_opener(
        HttpsOnlyRedirectHandler(allow_insecure_http_debug, api_base_url)
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


def inspect_local_apk(apk_path: Path, aapt_path: str, apksigner_path: str) -> tuple[str, int, str, str]:
    badging = run_local_tool(
        [aapt_path, "dump", "badging", str(apk_path)], "local aapt identity"
    )
    package_match = next(
        (
            PACKAGE_LINE_PATTERN.match(line.strip())
            for line in badging.splitlines()
            if line.startswith("package:")
        ),
        None,
    )
    if package_match is None:
        raise RuntimeError("local aapt did not return a valid package identity")
    signature_output = run_local_tool(
        [apksigner_path, "verify", "--verbose", "--print-certs", str(apk_path)],
        "local APK signature verification",
    )
    signers = [SIGNER_PATTERN.match(line.strip()) for line in signature_output.splitlines()]
    signers = [match for match in signers if match is not None]
    if len(signers) != 1 or signers[0].group("number") != "1":
        raise RuntimeError("public APK must contain exactly one signer")
    return (
        package_match.group("package"),
        int(package_match.group("code")),
        package_match.group("name"),
        signers[0].group("digest").lower(),
    )


def fetch_lifecycle_json(
    opener: urllib.request.OpenerDirector,
    url: str,
    label: str,
    allow_insecure_http_debug: bool = False,
) -> dict[str, object]:
    validate_https_url(url, label, allow_insecure_http_debug)
    request = urllib.request.Request(
        url,
        headers={"Accept": "application/json", "Accept-Encoding": "identity"},
    )
    try:
        with opener.open(request, timeout=60) as response:
            validate_https_url(
                response.geturl(), f"{label} final URL", allow_insecure_http_debug
            )
            if response.getcode() != 200:
                raise RuntimeError(f"{label} returned HTTP {response.getcode()}")
            payload = response.read(1024 * 1024 + 1)
    except urllib.error.HTTPError as exc:
        raise RuntimeError(f"{label} request failed with HTTP {exc.code}") from None
    except (urllib.error.URLError, TimeoutError, OSError) as exc:
        raise RuntimeError(
            f"{label} request failed: {type(exc).__name__}"
        ) from None
    if len(payload) > 1024 * 1024:
        raise RuntimeError(f"{label} response is too large")
    try:
        value = json.loads(payload.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"{label} returned invalid JSON") from exc
    if not isinstance(value, dict):
        raise RuntimeError(f"{label} JSON must be an object")
    return value


def probe_public_apk(
    opener: urllib.request.OpenerDirector,
    aapt_path: str,
    apksigner_path: str,
    url: str,
    expected: dict[str, object],
    edition: str,
    allow_insecure_http_debug: bool = False,
) -> None:
    """Download locally and validate the public APK plus its HTTP cache/range contract."""
    validate_https_url(
        url, f"{edition} download_url", allow_insecure_http_debug
    )
    expected_size = int(expected["size"])
    with tempfile.TemporaryDirectory(prefix=f"yiyunying-{edition}-") as temporary:
        apk_path = Path(temporary) / "release.apk"
        request = urllib.request.Request(
            url,
            headers={"Accept-Encoding": "identity"},
        )
        hasher = hashlib.sha256()
        total = 0
        with opener.open(request, timeout=600) as response, apk_path.open("wb") as output:
            validate_https_url(
                response.geturl(),
                f"{edition} final download URL",
                allow_insecure_http_debug,
            )
            status = response.getcode()
            mime = response.headers.get("Content-Type", "")
            etag = response.headers.get("ETag", "").strip()
            content_encoding = response.headers.get("Content-Encoding", "").strip().lower()
            while True:
                block = response.read(1024 * 1024)
                if not block:
                    break
                total += len(block)
                if total > expected_size:
                    raise RuntimeError(f"{edition} public APK exceeds the manifest size")
                hasher.update(block)
                output.write(block)
        if status != 200:
            raise RuntimeError(f"{edition} public APK returned HTTP {status}")
        if content_encoding not in {"", "identity"}:
            raise RuntimeError(f"{edition} public APK used unexpected content encoding")
        if mime.split(";", 1)[0].strip().lower() != "application/vnd.android.package-archive":
            raise RuntimeError(f"{edition} public APK MIME type is invalid: {mime!r}")
        if (
            not etag
            or etag.lower().startswith("w/")
            or not etag.startswith('"')
            or not etag.endswith('"')
        ):
            raise RuntimeError(f"{edition} public APK is missing a strong quoted ETag")
        if total != expected_size or hasher.hexdigest().lower() != expected["sha256"]:
            raise RuntimeError(f"{edition} public APK size or SHA-256 does not match")
        with apk_path.open("rb") as stream:
            first_four = stream.read(4)
            stream.seek(1)
            expected_range = stream.read(3)
        if first_four != b"PK\x03\x04":
            raise RuntimeError(f"{edition} public APK does not have ZIP magic")

        range_request = urllib.request.Request(
            url,
            headers={
                "Accept-Encoding": "identity",
                "Range": "bytes=1-3",
                "If-Range": etag,
            },
        )
        with opener.open(range_request, timeout=60) as response:
            validate_https_url(
                response.geturl(),
                f"{edition} range final URL",
                allow_insecure_http_debug,
            )
            range_status = response.getcode()
            content_range = response.headers.get("Content-Range", "").strip().lower()
            range_etag = response.headers.get("ETag", "").strip()
            range_body = response.read(4)
        if (
            range_status != 206
            or content_range != f"bytes 1-3/{expected_size}"
            or range_etag != etag
            or range_body != expected_range
        ):
            raise RuntimeError(f"{edition} public APK Range/If-Range contract failed")

        conditional_request = urllib.request.Request(
            url,
            headers={"Accept-Encoding": "identity", "If-None-Match": etag},
        )
        conditional_status = 0
        try:
            with opener.open(conditional_request, timeout=60) as response:
                conditional_status = response.getcode()
        except urllib.error.HTTPError as exc:
            if exc.code != 304:
                raise
            conditional_status = exc.code
        if conditional_status != 304:
            raise RuntimeError(f"{edition} public APK ETag revalidation did not return 304")

        package_name, version_code, version_name, signer_sha256 = inspect_local_apk(
            apk_path, aapt_path, apksigner_path
        )
        checks = {
            "package_name": package_name == expected["package"],
            "version_code": version_code == expected["version_code"],
            "version_name": version_name == expected["version_name"],
            "signer_sha256": signer_sha256 == expected["signer_sha256"],
        }
        failed = [name for name, passed in checks.items() if not passed]
        if failed:
            raise RuntimeError(f"{edition} public APK identity failed: {', '.join(failed)}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", type=int, default=22)
    parser.add_argument("--user", required=True)
    parser.add_argument("--known-hosts", required=True)
    parser.add_argument("--lifecycle-url", required=True)
    parser.add_argument("--allow-insecure-http-debug", action="store_true")
    parser.add_argument("--insecure-http-confirmation", default="")
    parser.add_argument("--release-identity", required=True)
    parser.add_argument("--release-manifest", required=True)
    parser.add_argument("--remote-backend-root", required=True)
    parser.add_argument("--aapt", required=True, help="Local Android aapt executable")
    parser.add_argument("--apksigner", required=True, help="Local Android apksigner executable")
    parser.add_argument("--version-code", type=int)
    parser.add_argument("--current-version-code", type=int, default=1)
    parser.add_argument("--db-host", default="127.0.0.1")
    parser.add_argument("--db-port", type=int, default=3306)
    parser.add_argument("--db-name", required=True)
    parser.add_argument("--db-user", required=True)
    args = parser.parse_args()

    (
        identity,
        expected,
        manifest_connection_identity,
        identity_sha256,
        build_commit,
        evidence_commit,
        release_tag,
        manifest_sha256,
    ) = load_release_evidence(
        Path(args.release_identity), Path(args.release_manifest)
    )
    connection_identity = load_connection_identity_from_environment(
        manifest_connection_identity
    )
    allow_insecure_http_debug = authorize_insecure_http_debug(
        expected,
        args.allow_insecure_http_debug,
        args.insecure_http_confirmation,
    )
    validate_https_url(
        args.lifecycle_url, "lifecycle-url", allow_insecure_http_debug
    )
    validate_connection_origins(
        connection_identity.api_base_url,
        {"lifecycle-url": args.lifecycle_url},
    )
    if allow_insecure_http_debug:
        print(
            "[non-production-debug] HTTP 明文验收已被显式确认；这不是正式 Release，"
            "仅用于四个 Debug 安装包闭环。",
            file=sys.stderr,
        )
    if args.version_code is not None and args.version_code != identity["version_code"]:
        raise RuntimeError("--version-code does not match the release identity")
    if args.current_version_code >= int(identity["version_code"]):
        raise RuntimeError("current-version-code must be lower than the released version code")
    if not args.remote_backend_root.startswith("/"):
        raise RuntimeError("remote-backend-root must be an absolute POSIX path")
    aapt_path = str(Path(args.aapt).expanduser().resolve())
    apksigner_path = str(Path(args.apksigner).expanduser().resolve())
    if not Path(aapt_path).is_file() or not Path(apksigner_path).is_file():
        raise RuntimeError("local aapt and apksigner paths must both reference files")

    ssh_password = os.environ.get("YY_SSH_PASSWORD", "")
    db_password = os.environ.get("YY_DB_PASSWORD", "")
    if not ssh_password or not db_password:
        raise RuntimeError("YY_SSH_PASSWORD and YY_DB_PASSWORD are required")

    opener = build_https_opener(
        allow_insecure_http_debug, connection_identity.api_base_url
    )
    client = connect_ssh(args, ssh_password)
    try:
        transport = client.get_transport()
        if transport is None:
            raise RuntimeError("SSH transport is unavailable after connection")
        host_key = transport.get_remote_server_key()
        fingerprint = hashlib.sha256(host_key.asbytes()).hexdigest()
        print(f"[ssh] pinned host key accepted; sha256={fingerprint}")
        print(
            f"[evidence] build-source-commit={build_commit}; "
            f"release-evidence-commit={evidence_commit}; tag={release_tag}; "
            f"identity-sha256={identity_sha256}; manifest-sha256={manifest_sha256}"
        )
        receipt_urls = verify_remote_release_state(
            client,
            args,
            db_password,
            identity,
            identity_sha256,
            expected,
            manifest_connection_identity,
            build_commit,
            evidence_commit,
            release_tag,
            manifest_sha256,
            allow_insecure_http_debug,
        )

        platform_rows = mysql_query(
            client,
            args,
            db_password,
            "SELECT id, platform_key, level, COALESCE(parent_id, 0) FROM platform_accounts "
            "WHERE deleted_at IS NULL AND status = 1 ORDER BY level, id",
            "platform-context",
            sensitive_output=True,
        )
        platforms: list[tuple[int, str, int, int]] = []
        for line in platform_rows.splitlines():
            parts = line.split("\t")
            if (
                len(parts) != 4
                or not parts[0].isdigit()
                or not parts[1].strip()
                or not parts[2].isdigit()
                or not parts[3].isdigit()
            ):
                raise RuntimeError("platform context returned a malformed row")
            platforms.append((int(parts[0]), parts[1].strip(), int(parts[2]), int(parts[3])))
        root, authorized = select_platform_connection_context(
            platforms, connection_identity
        )
        root_id, root_platform_key, _, _ = root

        edition_values = ", ".join(
            "'" + edition.replace("'", "''") + "'"
            for edition in sorted(REQUIRED_EDITIONS | {"all"})
        )
        policy_rows = mysql_query(
            client,
            args,
            db_password,
            "SELECT edition_code, version_name, version_code, download_url, package_name, "
            "LOWER(sha256), size_bytes FROM software_update_policies "
            "WHERE issuer_type = 'platform' "
            f"AND issuer_id = {root_id} AND target_type = 'global' AND status = 1 "
            f"AND edition_code IN ({edition_values}) ORDER BY edition_code, id",
            "active-release-policies",
        )
        active_policies: dict[str, dict[str, object]] = {}
        active_row_count = 0
        for line in policy_rows.splitlines():
            if not line.strip():
                continue
            active_row_count += 1
            parts = line.split("\t")
            if (
                len(parts) != 7
                or parts[0] not in REQUIRED_EDITIONS
                or parts[0] in active_policies
                or not parts[2].isdigit()
                or not parts[6].isdigit()
            ):
                raise RuntimeError(f"active release policy set is malformed or duplicated: {line!r}")
            active_policies[parts[0]] = {
                "version_name": parts[1],
                "version_code": int(parts[2]),
                "download_url": parts[3],
                "package": parts[4],
                "sha256": parts[5].lower(),
                "size": int(parts[6]),
            }
        if active_row_count != 4 or set(active_policies) != REQUIRED_EDITIONS:
            raise RuntimeError(
                "active release policy set must contain exactly one row for each of the four editions"
            )
        for edition, release in expected.items():
            policy = active_policies[edition]
            checks = {
                "version_name": policy["version_name"] == release["version_name"],
                "version_code": policy["version_code"] == release["version_code"],
                "package": policy["package"] == release["package"],
                "sha256": policy["sha256"] == release["sha256"],
                "size": policy["size"] == release["size"],
                "download_url": policy["download_url"] == receipt_urls[edition],
            }
            failed = [name for name, passed in checks.items() if not passed]
            if failed:
                raise RuntimeError(
                    f"{edition} active policy does not match release evidence: {', '.join(failed)}"
                )

        app_rows = mysql_query(
            client,
            args,
            db_password,
            "SELECT ap.app_key FROM apps ap "
            "INNER JOIN admins a ON a.id = ap.admin_id "
            "INNER JOIN platform_accounts p ON p.id = a.platform_id "
            "WHERE ap.deleted_at IS NULL AND ap.status = 1 AND a.deleted_at IS NULL "
            "AND a.status = 1 AND p.status = 1 AND p.deleted_at IS NULL "
            f"AND (CASE WHEN p.level = 1 THEN p.id ELSE p.parent_id END) = {root_id} "
            "ORDER BY ap.id",
            "app-context",
            sensitive_output=True,
        )
        app_keys = [line.strip() for line in app_rows.splitlines() if line.strip()]
        if not app_keys or any("\t" in value for value in app_keys):
            raise RuntimeError("an active application context under the release root is required")

        validate_remote_connection_identity(
            connection_identity,
            root_platform_key,
            authorized[1],
            app_keys,
        )

        contexts = {
            "platform_owner": {"platform_key": connection_identity.platform_key},
            "authorized_platform": {
                "platform_key": connection_identity.authorized_platform_key
            },
            "admin": {"platform_key": connection_identity.platform_key},
            "user": {"app_key": connection_identity.app_key},
        }
        if set(contexts) != REQUIRED_EDITIONS:
            raise RuntimeError("internal error: lifecycle contexts are not exactly four editions")
        for edition in ("platform_owner", "authorized_platform", "admin", "user"):
            context = contexts[edition]
            release = expected[edition]
            query = {
                "edition_code": edition,
                "version_code": str(args.current_version_code),
                **context,
            }
            url = args.lifecycle_url + "?" + urllib.parse.urlencode(query)
            response = fetch_lifecycle_json(
                opener,
                url,
                f"lifecycle-{edition}",
                allow_insecure_http_debug,
            )
            if int(response.get("code", 0)) != 1:
                raise RuntimeError(f"{edition} lifecycle returned an error payload")
            data = response.get("data") or {}
            update = data.get("update") or {}
            download_url = str(update.get("download_url", ""))
            parsed_download = validate_https_url(
                download_url,
                f"{edition} download_url",
                allow_insecure_http_debug,
            )
            validate_connection_origins(
                connection_identity.api_base_url,
                {f"{edition} download_url": download_url},
            )
            checks = {
                "edition_code": data.get("edition_code") == edition,
                "available": update.get("available") is True,
                "version_code": update.get("version_code") == release["version_code"],
                "version_name": update.get("version_name") == release["version_name"],
                "package_name": update.get("package_name") == release["package"],
                "sha256": str(update.get("sha256", "")).lower() == release["sha256"],
                "size_bytes": update.get("size_bytes") == release["size"],
                "download_url": download_url == active_policies[edition]["download_url"],
                "download_file": urllib.parse.unquote(parsed_download.path).endswith(
                    "/" + str(release["file_name"])
                ),
                "force_update": update.get("force_update") is False,
            }
            failed = [name for name, passed in checks.items() if not passed]
            if failed:
                raise RuntimeError(f"{edition} lifecycle validation failed: {', '.join(failed)}")
            probe_public_apk(
                opener,
                aapt_path,
                apksigner_path,
                download_url,
                release,
                edition,
                allow_insecure_http_debug,
            )
            print(
                f"[ok] {edition}: lifecycle + full APK hash/size/package/version/"
                "single-signer + Range/ETag"
            )
        if allow_insecure_http_debug:
            print("[complete] non-production Debug HTTP evidence verification passed: 4/4")
        else:
            print("[complete] evidence-bound production release verification passed: 4/4")
    finally:
        client.close()
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(
            f"release verification failed: {type(exc).__name__}: {exc}",
            file=sys.stderr,
        )
        raise SystemExit(1)
