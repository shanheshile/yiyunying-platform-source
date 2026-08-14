#!/usr/bin/env python3
"""Strict, offline validation of finalized Android device-gate evidence."""

from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import re
from typing import Mapping


SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
COMMIT_RE = re.compile(r"^[0-9a-f]{40}(?:[0-9a-f]{24})?$")
UTC_CREATED_AT_RE = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$")
TARGET_RE = re.compile(r"^sha256:[0-9a-f]{12}$")

DEVICE_PENDING_NOTICE = "真机验证待用户完成（不得声明真机通过）"
DEVICE_PASSED_NOTICE = "真机升级验证已由完整证据通过"
WAIVER_TOKEN_SHA256 = (
    "df6e749945125dc45fddb3cfc433436b349beca063c0eb64a72aa0627e05afe5"
)
ROLE_ORDER = ("user", "admin", "authorized", "owner")
ROLE_IDENTITIES = {
    "user": ("xyz.jjmxg.yiyunying.user", "user"),
    "admin": ("xyz.jjmxg.yiyunying.admin", "admin"),
    "authorized": (
        "xyz.jjmxg.yiyunying.authorized",
        "authorized-platform",
    ),
    "owner": ("xyz.jjmxg.yiyunying.platformowner", "platform-owner"),
}
UNEXECUTED_CHECKS = (
    "stable-code62-to-code66-in-place-upgrade-four-roles",
    "legacy-debug-code60-to-code66-compat-upgrade-four-roles",
    "four-role-login",
    "four-role-data-continuity",
    "four-role-core-function-smoke",
    "multi-vendor-device-matrix",
)
ACKNOWLEDGEMENTS = (
    "真机验证尚未执行，不得声明真机通过。",
    "用户接受本次在真机验证完成前发布，并承担后续四角色真机验收。",
    "发现真机问题后必须修复并重新发布，不得用本豁免冒充验收证据。",
)
SUMMARY_FIELDS = {
    "plan",
    "status",
    "evidenceFileName",
    "evidenceSha256",
    "publicNotice",
}
WAIVER_FIELDS = {
    "schemaVersion",
    "evidenceType",
    "versionName",
    "versionCode",
    "channel",
    "createdAt",
    "decision",
    "deviceValidationStatus",
    "roles",
    "unexecutedChecks",
    "acknowledgements",
    "buildSourceCommit",
    "releaseEvidenceCommit",
    "releaseTag",
    "pendingManifestSha256",
    "confirmationTokenSha256",
}
DEVICE_EVIDENCE_FIELDS = {
    "schemaVersion",
    "evidenceType",
    "versionName",
    "versionCode",
    "channel",
    "createdAt",
    "status",
    "roles",
    "buildSourceCommit",
    "releaseEvidenceCommit",
    "releaseTag",
    "pendingManifestSha256",
}
DEVICE_ROLE_FIELDS = {
    "status",
    "gate",
    "target",
    "role",
    "packageName",
    "fromVersionCode",
    "fromVersionName",
    "toVersionCode",
    "versionName",
    "signerSha256",
    "signatureSchemeV2Verified",
    "uidPreserved",
    "dataDirPreserved",
    "launchVerifiedBeforeAndAfter",
}


def _reject_duplicate_pairs(pairs: list[tuple[str, object]]) -> dict[str, object]:
    result: dict[str, object] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError(f"duplicate JSON field: {key}")
        result[key] = value
    return result


def _reject_constant(value: str) -> object:
    raise ValueError(f"invalid JSON constant: {value}")


def _read_strict_json(path: Path, label: str, *, max_bytes: int = 1024 * 1024) -> dict:
    if not os.path.lexists(path) or path.is_symlink() or not path.is_file():
        raise RuntimeError(f"{label} must be a regular non-symlink file: {path}")
    size = path.stat().st_size
    if size < 1 or size > max_bytes:
        raise RuntimeError(f"{label} must be non-empty and no larger than {max_bytes} bytes")
    try:
        text = path.read_bytes().decode("utf-8-sig")
        value = json.loads(
            text,
            object_pairs_hook=_reject_duplicate_pairs,
            parse_constant=_reject_constant,
        )
    except (OSError, UnicodeError, json.JSONDecodeError, ValueError) as exc:
        raise RuntimeError(f"{label} is not strict UTF-8 JSON: {exc}") from exc
    if not isinstance(value, dict):
        raise RuntimeError(f"{label} must be a JSON object")
    return value


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _require_exact_fields(value: object, expected: set[str], label: str) -> dict:
    if not isinstance(value, dict) or set(value) != expected:
        raise RuntimeError(f"{label} fields must exactly match the schema; unknown fields are rejected")
    return value


def _require_int(value: object, expected: int, label: str) -> None:
    if isinstance(value, bool) or not isinstance(value, int) or value != expected:
        raise RuntimeError(f"{label} must equal {expected}")


def _require_string_list(value: object, expected: tuple[str, ...], label: str) -> None:
    if not isinstance(value, list) or tuple(value) != expected or not all(
        isinstance(item, str) for item in value
    ):
        raise RuntimeError(f"{label} must exactly match the fixed ordered values")


def _require_digest(value: object, label: str) -> str:
    if not isinstance(value, str) or SHA256_RE.fullmatch(value) is None:
        raise RuntimeError(f"{label} must be a lowercase SHA-256 digest")
    return value


def _require_commit(value: object, label: str) -> str:
    if not isinstance(value, str) or COMMIT_RE.fullmatch(value) is None:
        raise RuntimeError(f"{label} must be a lowercase Git commit id")
    return value


def _release_bindings(manifest: Mapping[str, object], pending: Mapping[str, object]) -> dict:
    version_name = manifest.get("versionName")
    version_code = manifest.get("versionCode")
    if not isinstance(version_name, str) or not version_name:
        raise RuntimeError("release manifest versionName is invalid")
    if isinstance(version_code, bool) or not isinstance(version_code, int) or version_code < 1:
        raise RuntimeError("release manifest versionCode is invalid")
    channel = manifest.get("channel")
    if channel != "Stable":
        raise RuntimeError("the finalized device gate is only valid for Stable releases")
    build_commit = _require_commit(manifest.get("buildSourceCommit"), "buildSourceCommit")
    evidence_commit = _require_commit(
        manifest.get("releaseEvidenceCommit"), "releaseEvidenceCommit"
    )
    if build_commit == evidence_commit:
        raise RuntimeError("buildSourceCommit and releaseEvidenceCommit must be distinct")
    release_tag = manifest.get("releaseTag")
    if release_tag != f"v{version_name}":
        raise RuntimeError("releaseTag does not match the Stable version")
    pending_hash = _require_digest(
        pending.get("pendingManifestSha256"), "pendingManifestSha256"
    )
    for field, expected in (
        ("schemaVersion", 4),
        ("versionName", version_name),
        ("versionCode", version_code),
        ("channel", "Stable"),
        ("buildSourceCommit", build_commit),
        ("releaseTag", release_tag),
        ("deviceValidationPlan", manifest.get("deviceValidationPlan")),
        ("releaseNotes", manifest.get("releaseNotes")),
    ):
        if pending.get(field) != expected:
            raise RuntimeError(f"pending release metadata does not match finalized field: {field}")
    if pending.get("finalizationStatus") != "pending" or pending.get(
        "releaseEvidenceCommit"
    ) not in (None, ""):
        raise RuntimeError("release metadata must remain pending B-commit evidence")
    return {
        "versionName": version_name,
        "versionCode": version_code,
        "channel": "Stable",
        "buildSourceCommit": build_commit,
        "releaseEvidenceCommit": evidence_commit,
        "releaseTag": release_tag,
        "pendingManifestSha256": pending_hash,
    }


def _validate_common_evidence(value: dict, bindings: Mapping[str, object], label: str) -> None:
    for field in (
        "versionName",
        "versionCode",
        "channel",
        "buildSourceCommit",
        "releaseEvidenceCommit",
        "releaseTag",
        "pendingManifestSha256",
    ):
        if value.get(field) != bindings[field]:
            raise RuntimeError(f"{label} is not bound to the finalized release: {field}")


def _validate_waiver(value: dict, bindings: Mapping[str, object]) -> None:
    _require_exact_fields(value, WAIVER_FIELDS, "release-risk-waiver.json")
    _validate_common_evidence(value, bindings, "release-risk-waiver.json")
    if bindings["versionName"] != "1.0.0" or bindings["versionCode"] != 66:
        raise RuntimeError("risk waiver is restricted to Stable 1.0.0/code66")
    _require_int(value.get("schemaVersion"), 1, "waiver schemaVersion")
    expected_scalars = {
        "evidenceType": "release-risk-waiver",
        "createdAt": "2026-08-15",
        "decision": "release-before-device-validation",
        "deviceValidationStatus": "pending-user-validation",
        "confirmationTokenSha256": WAIVER_TOKEN_SHA256,
    }
    for field, expected in expected_scalars.items():
        if value.get(field) != expected:
            raise RuntimeError(f"release-risk-waiver.json {field} is invalid")
    _require_string_list(value.get("roles"), ROLE_ORDER, "waiver roles")
    _require_string_list(
        value.get("unexecutedChecks"), UNEXECUTED_CHECKS, "waiver unexecutedChecks"
    )
    _require_string_list(
        value.get("acknowledgements"), ACKNOWLEDGEMENTS, "waiver acknowledgements"
    )


def _release_entries_by_role(manifest: Mapping[str, object]) -> dict[str, dict]:
    entries = manifest.get("releases")
    if not isinstance(entries, list) or len(entries) != 4:
        raise RuntimeError("finalized Stable manifest must contain exactly four releases")
    result: dict[str, dict] = {}
    for entry in entries:
        if not isinstance(entry, dict) or entry.get("id") not in ROLE_ORDER:
            raise RuntimeError("finalized Stable manifest has an invalid role entry")
        role = str(entry["id"])
        if role in result:
            raise RuntimeError(f"finalized Stable manifest duplicates role: {role}")
        result[role] = entry
    if tuple(sorted(result)) != tuple(sorted(ROLE_ORDER)):
        raise RuntimeError("finalized Stable manifest does not contain all four roles")
    return result


def _validate_device_evidence(
    value: dict, bindings: Mapping[str, object], manifest: Mapping[str, object]
) -> None:
    _require_exact_fields(value, DEVICE_EVIDENCE_FIELDS, "device-upgrade-evidence.json")
    _validate_common_evidence(value, bindings, "device-upgrade-evidence.json")
    _require_int(value.get("schemaVersion"), 1, "device evidence schemaVersion")
    if (
        value.get("evidenceType") != "android-device-upgrade"
        or value.get("status") != "PASS"
        or not isinstance(value.get("createdAt"), str)
        or UTC_CREATED_AT_RE.fullmatch(str(value["createdAt"])) is None
    ):
        raise RuntimeError("device-upgrade-evidence.json type, status or UTC createdAt is invalid")
    roles = value.get("roles")
    if not isinstance(roles, list) or len(roles) != len(ROLE_ORDER):
        raise RuntimeError("device-upgrade-evidence.json requires four ordered roles")
    entries = _release_entries_by_role(manifest)
    signers = {
        _require_digest(entry.get("signerSha256"), f"{role} signerSha256")
        for role, entry in entries.items()
    }
    if len(signers) != 1:
        raise RuntimeError("four Stable releases must use one signer")
    signer = next(iter(signers))
    version_name = str(bindings["versionName"])
    version_code = int(bindings["versionCode"])
    for index, expected_role in enumerate(ROLE_ORDER):
        role = _require_exact_fields(
            roles[index], DEVICE_ROLE_FIELDS, f"device evidence roles[{index}]"
        )
        package_name, suffix = ROLE_IDENTITIES[expected_role]
        entry = entries[expected_role]
        expected_values = {
            "status": "PASS",
            "gate": "android-device-upgrade",
            "role": expected_role,
            "packageName": package_name,
            "fromVersionCode": 62,
            "fromVersionName": f"2.8.0-{suffix}",
            "toVersionCode": version_code,
            "versionName": f"{version_name}-{suffix}",
            "signerSha256": signer,
            "signatureSchemeV2Verified": True,
            "uidPreserved": True,
            "dataDirPreserved": True,
            "launchVerifiedBeforeAndAfter": True,
        }
        for field, expected in expected_values.items():
            actual = role.get(field)
            if isinstance(expected, int) and not isinstance(expected, bool):
                if isinstance(actual, bool) or not isinstance(actual, int):
                    raise RuntimeError(f"device evidence {expected_role}.{field} has invalid type")
            if actual != expected:
                raise RuntimeError(f"device evidence {expected_role}.{field} is invalid")
        if (
            entry.get("packageName") != package_name
            or entry.get("versionName") != f"{version_name}-{suffix}"
            or entry.get("versionCode") != version_code
        ):
            raise RuntimeError(f"final manifest role identity is invalid: {expected_role}")
        target = role.get("target")
        if not isinstance(target, str) or TARGET_RE.fullmatch(target) is None:
            raise RuntimeError(f"device evidence {expected_role}.target is invalid")


def validate_final_release_device_gate(
    manifest: Mapping[str, object],
    manifest_path: Path,
    repository_root: Path,
) -> dict[str, object] | None:
    """Validate the final manifest, pending metadata, project binding and A/B evidence.

    Legacy code <=65 and Debug manifests retain their existing compatibility behavior.
    Every Stable code >=66 release fails closed unless it has one complete device gate.
    """

    manifest_path = Path(manifest_path).resolve()
    repository_root = Path(repository_root).resolve()
    channel = manifest.get("channel")
    version_code = manifest.get("versionCode")
    if isinstance(version_code, bool) or not isinstance(version_code, int):
        raise RuntimeError("release manifest versionCode is invalid")
    plan = manifest.get("deviceValidationPlan")
    has_summary = "deviceValidation" in manifest
    if channel != "Stable":
        if plan not in (None, "", "not-required-debug") or has_summary:
            raise RuntimeError("Debug releases may not claim finalized device validation")
        return None
    if version_code <= 65 and plan in (None, "") and not has_summary:
        return None
    if version_code < 66:
        raise RuntimeError("the finalized device gate starts at Stable code66")
    if plan not in ("device-evidence", "risk-waiver"):
        raise RuntimeError("Stable code >=66 requires a device validation plan")

    disk_manifest = _read_strict_json(manifest_path, "release manifest", max_bytes=4 * 1024 * 1024)
    if disk_manifest != dict(manifest):
        raise RuntimeError("in-memory release manifest does not match finalized manifest bytes")
    metadata_path = repository_root / "download-site" / "release-metadata.json"
    metadata = _read_strict_json(metadata_path, "pending release metadata", max_bytes=4 * 1024 * 1024)
    bindings = _release_bindings(manifest, metadata)

    summary = _require_exact_fields(
        manifest.get("deviceValidation"), SUMMARY_FIELDS, "deviceValidation"
    )
    if summary.get("plan") != plan:
        raise RuntimeError("deviceValidation.plan does not match deviceValidationPlan")
    expected_summary = {
        "device-evidence": {
            "status": "passed",
            "evidenceFileName": "device-upgrade-evidence.json",
            "publicNotice": DEVICE_PASSED_NOTICE,
        },
        "risk-waiver": {
            "status": "pending-user-validation",
            "evidenceFileName": "release-risk-waiver.json",
            "publicNotice": DEVICE_PENDING_NOTICE,
        },
    }[str(plan)]
    for field, expected in expected_summary.items():
        if summary.get(field) != expected:
            raise RuntimeError(f"deviceValidation.{field} is invalid for {plan}")
    evidence_digest = _require_digest(
        summary.get("evidenceSha256"), "deviceValidation.evidenceSha256"
    )

    release_dir = manifest_path.parent
    gate_paths = {
        "device-upgrade-evidence.json": release_dir / "device-upgrade-evidence.json",
        "release-risk-waiver.json": release_dir / "release-risk-waiver.json",
    }
    present = [name for name, path in gate_paths.items() if os.path.lexists(path)]
    if present != [str(summary["evidenceFileName"])]:
        raise RuntimeError(
            "finalized release must contain exactly its declared device evidence or risk waiver"
        )
    evidence_path = gate_paths[str(summary["evidenceFileName"])]
    evidence = _read_strict_json(evidence_path, str(summary["evidenceFileName"]))
    if _sha256(evidence_path) != evidence_digest:
        raise RuntimeError("deviceValidation.evidenceSha256 does not match evidence bytes")

    notes = manifest.get("releaseNotes")
    if not isinstance(notes, list) or not all(isinstance(note, str) for note in notes):
        raise RuntimeError("releaseNotes must be an array of strings")
    if plan == "risk-waiver":
        if DEVICE_PENDING_NOTICE not in notes:
            raise RuntimeError("risk waiver release notes must show pending user device validation")
        if any(
            DEVICE_PASSED_NOTICE in note or "真机验证已通过" in note
            for note in notes
        ):
            raise RuntimeError("risk waiver release notes may not claim device validation passed")
        _validate_waiver(evidence, bindings)
    else:
        if DEVICE_PENDING_NOTICE in notes:
            raise RuntimeError("device evidence release notes may not retain the waiver notice")
        _validate_device_evidence(evidence, bindings, manifest)

    project_path = release_dir / "project-assets-manifest.json"
    project = _read_strict_json(project_path, "project assets manifest", max_bytes=4 * 1024 * 1024)
    if project.get("deviceValidation") != summary:
        raise RuntimeError("project assets manifest deviceValidation does not match release manifest")
    if project.get("releaseManifestSha256") != _sha256(manifest_path):
        raise RuntimeError("project assets manifest is not bound to finalized manifest bytes")
    for field in (
        "versionName",
        "versionCode",
        "channel",
        "buildSourceCommit",
        "releaseEvidenceCommit",
        "releaseTag",
    ):
        if project.get(field) != manifest.get(field):
            raise RuntimeError(f"project assets manifest release binding mismatch: {field}")
    return dict(summary)
