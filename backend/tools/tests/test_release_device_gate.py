#!/usr/bin/env python3
"""Offline dynamic contracts for the formal Release device evidence/waiver gate."""

from __future__ import annotations

import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import types
import unittest


try:
    import paramiko  # noqa: F401
except ModuleNotFoundError:
    paramiko_stub = types.ModuleType("paramiko")
    paramiko_stub.SSHClient = type("SSHClient", (), {})
    paramiko_stub.SFTPClient = type("SFTPClient", (), {})
    paramiko_stub.RejectPolicy = type("RejectPolicy", (), {})
    sys.modules["paramiko"] = paramiko_stub


ROOT = Path(__file__).resolve().parents[3]
VALIDATOR = ROOT / "android" / "tools" / "verify-release-device-gate.ps1"
GENERATOR = ROOT / "android" / "tools" / "new-release-risk-waiver.ps1"
RELEASE = ROOT / "android" / "tools" / "release.ps1"
PACKAGE = ROOT / "scripts" / "package-project.ps1"
PROJECTION = ROOT / "download-site" / "scripts" / "public-release-projection.mjs"
DEPLOY_STATIC = ROOT / "download-site" / "scripts" / "deploy-static.py"
PUBLISH_ANDROID = ROOT / "backend" / "tools" / "publish-android-ssh.py"
VERIFY_PRODUCTION = ROOT / "backend" / "tools" / "verify-production-release-ssh.py"
PYTHON_GATE = ROOT / "backend" / "tools" / "release_device_gate.py"

PYTHON_GATE_SPEC = importlib.util.spec_from_file_location(
    "release_device_gate_contract", PYTHON_GATE
)
assert PYTHON_GATE_SPEC and PYTHON_GATE_SPEC.loader
PYTHON_GATE_MODULE = importlib.util.module_from_spec(PYTHON_GATE_SPEC)
PYTHON_GATE_SPEC.loader.exec_module(PYTHON_GATE_MODULE)

TOKEN = "I_ACCEPT_1.0.0_CODE66_RELEASE_WITH_DEVICE_VALIDATION_PENDING"
TOKEN_SHA256 = "df6e749945125dc45fddb3cfc433436b349beca063c0eb64a72aa0627e05afe5"
BUILD_COMMIT = "a" * 40
EVIDENCE_COMMIT = "b" * 40
PENDING_SHA256 = "c" * 64
SIGNER_SHA256 = "d" * 64
PUBLIC_NOTICE = "真机验证待用户完成（不得声明真机通过）"
ROLES = ("user", "admin", "authorized", "owner")
UNEXECUTED = (
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
ROLE_IDENTITY = {
    "user": ("xyz.jjmxg.yiyunying.user", "user"),
    "admin": ("xyz.jjmxg.yiyunying.admin", "admin"),
    "authorized": ("xyz.jjmxg.yiyunying.authorized", "authorized-platform"),
    "owner": ("xyz.jjmxg.yiyunying.platformowner", "platform-owner"),
}


def waiver_document(*, code: int = 66) -> dict[str, object]:
    return {
        "schemaVersion": 1,
        "evidenceType": "release-risk-waiver",
        "versionName": "1.0.0",
        "versionCode": code,
        "channel": "Stable",
        "createdAt": "2026-08-15",
        "decision": "release-before-device-validation",
        "deviceValidationStatus": "pending-user-validation",
        "roles": list(ROLES),
        "unexecutedChecks": list(UNEXECUTED),
        "acknowledgements": list(ACKNOWLEDGEMENTS),
        "buildSourceCommit": BUILD_COMMIT,
        "releaseEvidenceCommit": EVIDENCE_COMMIT,
        "releaseTag": "v1.0.0",
        "pendingManifestSha256": PENDING_SHA256,
        "confirmationTokenSha256": TOKEN_SHA256,
    }


def device_document() -> dict[str, object]:
    evidence_roles: list[dict[str, object]] = []
    for index, role in enumerate(ROLES):
        package_name, suffix = ROLE_IDENTITY[role]
        evidence_roles.append(
            {
                "status": "PASS",
                "gate": "android-device-upgrade",
                "target": f"sha256:{index + 1:012x}",
                "role": role,
                "packageName": package_name,
                "fromVersionCode": 62,
                "fromVersionName": f"2.8.0-{suffix}",
                "toVersionCode": 66,
                "versionName": f"1.0.0-{suffix}",
                "signerSha256": SIGNER_SHA256,
                "signatureSchemeV2Verified": True,
                "uidPreserved": True,
                "dataDirPreserved": True,
                "launchVerifiedBeforeAndAfter": True,
            }
        )
    return {
        "schemaVersion": 1,
        "evidenceType": "android-device-upgrade",
        "versionName": "1.0.0",
        "versionCode": 66,
        "channel": "Stable",
        "createdAt": "2026-08-15T12:00:00Z",
        "status": "PASS",
        "roles": evidence_roles,
        "buildSourceCommit": BUILD_COMMIT,
        "releaseEvidenceCommit": EVIDENCE_COMMIT,
        "releaseTag": "v1.0.0",
        "pendingManifestSha256": PENDING_SHA256,
    }


def file_sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def load_script(path: Path, name: str):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


class FinalDeviceGatePythonRuntimeTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory(prefix="final-device-gate-")
        self.addCleanup(self.temporary.cleanup)
        self.repository = Path(self.temporary.name) / "repo"
        self.release_directory = self.repository / "releases" / "1.0.0"
        self.release_directory.mkdir(parents=True)
        (self.repository / "download-site").mkdir()
        self.manifest_path = self.release_directory / "release-manifest.json"
        self.metadata_path = self.repository / "download-site" / "release-metadata.json"
        self.project_path = self.release_directory / "project-assets-manifest.json"

    @staticmethod
    def _releases() -> list[dict[str, object]]:
        releases = []
        for role in ("owner", "authorized", "admin", "user"):
            package_name, suffix = ROLE_IDENTITY[role]
            releases.append(
                {
                    "id": role,
                    "fileName": f"yiyunying-{role}-v1.0.0.apk",
                    "packageName": package_name,
                    "versionName": f"1.0.0-{suffix}",
                    "versionCode": 66,
                    "sizeBytes": 1,
                    "sha256": "e" * 64,
                    "signerSha256": SIGNER_SHA256,
                }
            )
        return releases

    def _write_json(self, path: Path, value: object) -> None:
        path.write_text(
            json.dumps(value, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )

    def _materialize(self, plan: str) -> tuple[dict[str, object], dict[str, object]]:
        if plan == "risk-waiver":
            evidence_name = "release-risk-waiver.json"
            evidence = waiver_document()
            status = "pending-user-validation"
            public_notice = PUBLIC_NOTICE
            notes = ["release fixture", PUBLIC_NOTICE]
        else:
            evidence_name = "device-upgrade-evidence.json"
            evidence = device_document()
            status = "passed"
            public_notice = "真机升级验证已由完整证据通过"
            notes = ["release fixture"]
        evidence_path = self.release_directory / evidence_name
        self._write_json(evidence_path, evidence)
        summary = {
            "plan": plan,
            "status": status,
            "evidenceFileName": evidence_name,
            "evidenceSha256": file_sha256(evidence_path),
            "publicNotice": public_notice,
        }
        manifest: dict[str, object] = {
            "schemaVersion": 4,
            "channel": "Stable",
            "versionName": "1.0.0",
            "versionCode": 66,
            "buildSourceCommit": BUILD_COMMIT,
            "releaseEvidenceCommit": EVIDENCE_COMMIT,
            "releaseTag": "v1.0.0",
            "finalizationStatus": "finalized",
            "finalizedAt": "2026-08-15T12:00:00Z",
            "deviceValidationPlan": plan,
            "deviceValidation": summary,
            "releaseNotes": notes,
            "releases": self._releases(),
        }
        self._write_json(self.manifest_path, manifest)
        metadata = copy.deepcopy(manifest)
        metadata["finalizationStatus"] = "pending"
        metadata["releaseEvidenceCommit"] = None
        metadata["pendingManifestSha256"] = PENDING_SHA256
        metadata.pop("finalizedAt")
        metadata.pop("deviceValidation")
        self._write_json(self.metadata_path, metadata)
        project = {
            "schemaVersion": 3,
            "channel": "Stable",
            "versionName": "1.0.0",
            "versionCode": 66,
            "buildSourceCommit": BUILD_COMMIT,
            "releaseEvidenceCommit": EVIDENCE_COMMIT,
            "releaseTag": "v1.0.0",
            "releaseManifestSha256": file_sha256(self.manifest_path),
            "deviceValidation": copy.deepcopy(summary),
        }
        self._write_json(self.project_path, project)
        return manifest, project

    def test_python_consumer_accepts_both_exact_gate_paths(self) -> None:
        for plan, expected_status in (
            ("risk-waiver", "pending-user-validation"),
            ("device-evidence", "passed"),
        ):
            with self.subTest(plan=plan):
                if self.release_directory.exists():
                    shutil.rmtree(self.release_directory)
                self.release_directory.mkdir()
                manifest, _ = self._materialize(plan)
                result = PYTHON_GATE_MODULE.validate_final_release_device_gate(
                    manifest, self.manifest_path, self.repository
                )
                self.assertIsNotNone(result)
                self.assertEqual(expected_status, result["status"])

    def test_python_consumer_rejects_future_missing_plan_and_forged_passed(self) -> None:
        future = {
            "channel": "Stable",
            "versionName": "1.0.1",
            "versionCode": 67,
        }
        self._write_json(self.manifest_path, future)
        with self.assertRaisesRegex(RuntimeError, "requires a device validation plan"):
            PYTHON_GATE_MODULE.validate_final_release_device_gate(
                future, self.manifest_path, self.repository
            )

        manifest, project = self._materialize("risk-waiver")
        summary = manifest["deviceValidation"]
        assert isinstance(summary, dict)
        summary["status"] = "passed"
        summary["publicNotice"] = "真机验证已通过"
        self._write_json(self.manifest_path, manifest)
        project["deviceValidation"] = copy.deepcopy(summary)
        project["releaseManifestSha256"] = file_sha256(self.manifest_path)
        self._write_json(self.project_path, project)
        with self.assertRaisesRegex(RuntimeError, "invalid for risk-waiver"):
            PYTHON_GATE_MODULE.validate_final_release_device_gate(
                manifest, self.manifest_path, self.repository
            )

    def test_python_consumer_rejects_hash_unknown_duplicate_and_project_drift(self) -> None:
        manifest, project = self._materialize("risk-waiver")
        waiver_path = self.release_directory / "release-risk-waiver.json"
        waiver = waiver_document()
        waiver["unknown"] = True
        self._write_json(waiver_path, waiver)
        summary = manifest["deviceValidation"]
        assert isinstance(summary, dict)
        summary["evidenceSha256"] = file_sha256(waiver_path)
        self._write_json(self.manifest_path, manifest)
        project["deviceValidation"] = copy.deepcopy(summary)
        project["releaseManifestSha256"] = file_sha256(self.manifest_path)
        self._write_json(self.project_path, project)
        with self.assertRaisesRegex(RuntimeError, "unknown fields"):
            PYTHON_GATE_MODULE.validate_final_release_device_gate(
                manifest, self.manifest_path, self.repository
            )

        manifest, project = self._materialize("risk-waiver")
        waiver_path.write_text(
            waiver_path.read_text(encoding="utf-8").replace(
                '"schemaVersion": 1,', '"schemaVersion": 1,\n  "schemaVersion": 1,'
            ),
            encoding="utf-8",
        )
        summary = manifest["deviceValidation"]
        assert isinstance(summary, dict)
        summary["evidenceSha256"] = file_sha256(waiver_path)
        self._write_json(self.manifest_path, manifest)
        project["deviceValidation"] = copy.deepcopy(summary)
        project["releaseManifestSha256"] = file_sha256(self.manifest_path)
        self._write_json(self.project_path, project)
        with self.assertRaisesRegex(RuntimeError, "duplicate JSON field"):
            PYTHON_GATE_MODULE.validate_final_release_device_gate(
                manifest, self.manifest_path, self.repository
            )

        manifest, project = self._materialize("risk-waiver")
        project["deviceValidation"]["status"] = "passed"
        self._write_json(self.project_path, project)
        with self.assertRaisesRegex(RuntimeError, "project assets manifest deviceValidation"):
            PYTHON_GATE_MODULE.validate_final_release_device_gate(
                manifest, self.manifest_path, self.repository
            )

    def test_publish_and_production_verifier_reject_future_missing_plan(self) -> None:
        identity_path = Path(self.temporary.name) / "release-identity.json"
        identity = {
            "version_name": "1.0.1",
            "version_code": 67,
            "stable_signer_sha256": SIGNER_SHA256,
        }
        self._write_json(identity_path, identity)
        future_manifest = {
            "schemaVersion": 4,
            "channel": "Stable",
            "versionName": "1.0.1",
            "versionCode": 67,
            "buildSourceCommit": BUILD_COMMIT,
            "releaseEvidenceCommit": EVIDENCE_COMMIT,
            "releaseTag": "v1.0.1",
            "finalizationStatus": "finalized",
            "releaseIdentitySha256": file_sha256(identity_path),
            "connectionIdentity": {
                "apiBaseUrl": "https://example.test/",
                "appKeySha256": "1" * 64,
                "platformKeySha256": "2" * 64,
                "authorizedPlatformKeySha256": "3" * 64,
            },
        }
        future_manifest_path = Path(self.temporary.name) / "future-manifest.json"
        self._write_json(future_manifest_path, future_manifest)

        verifier = load_script(VERIFY_PRODUCTION, "device_gate_verify_consumer")
        with self.assertRaisesRegex(RuntimeError, "requires a device validation plan"):
            verifier.load_release_evidence(identity_path, future_manifest_path)

        publisher = load_script(PUBLISH_ANDROID, "device_gate_publish_consumer")
        releases = [
            publisher.Release(
                edition=edition,
                package_name=f"example.{edition}",
                local_path=str(Path(self.temporary.name) / f"{edition}.apk"),
                remote_filename=f"{edition}.apk",
                size_bytes=1,
                sha256="f" * 64,
            )
            for edition in ("platform_owner", "authorized_platform", "admin", "user")
        ]
        with self.assertRaisesRegex(RuntimeError, "requires a device validation plan"):
            publisher.validate_release_plan(
                releases,
                str(future_manifest_path),
                str(identity_path),
                "1.0.1",
                67,
                "unused-aapt",
                "unused-apksigner",
            )


class ReleaseDeviceGateRuntimeTest(unittest.TestCase):
    def setUp(self) -> None:
        self.powershell = shutil.which("powershell.exe") or shutil.which("powershell")
        self.assertIsNotNone(self.powershell, "Windows PowerShell is required")
        self.temporary = tempfile.TemporaryDirectory(prefix="release-device-gate-")
        self.release_directory = Path(self.temporary.name) / "1.0.0"
        self.release_directory.mkdir()

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def _write(self, name: str, value: object) -> None:
        (self.release_directory / name).write_text(
            json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
        )

    def _run(
        self,
        *,
        token: str | None = None,
        expected_code: int = 66,
        pending_sha256: str = PENDING_SHA256,
    ) -> subprocess.CompletedProcess[str]:
        command = [
            str(self.powershell),
            "-NoProfile",
            "-ExecutionPolicy",
            "Bypass",
            "-File",
            str(VALIDATOR),
            "-ReleaseDirectory",
            str(self.release_directory),
            "-ExpectedVersionName",
            "1.0.0",
            "-ExpectedVersionCode",
            str(expected_code),
            "-ExpectedChannel",
            "Stable",
            "-ExpectedBuildSourceCommit",
            BUILD_COMMIT,
            "-ExpectedReleaseEvidenceCommit",
            EVIDENCE_COMMIT,
            "-ExpectedReleaseTag",
            "v1.0.0",
            "-ExpectedPendingManifestSha256",
            pending_sha256,
            "-ExpectedStableSignerSha256",
            SIGNER_SHA256,
        ]
        if token is not None:
            command.extend(("-RiskWaiverConfirmationToken", token))
        return subprocess.run(
            command,
            cwd=ROOT,
            text=True,
            encoding="utf-8",
            errors="replace",
            capture_output=True,
            timeout=30,
            check=False,
        )

    @staticmethod
    def _combined(completed: subprocess.CompletedProcess[str]) -> str:
        return completed.stdout + completed.stderr

    def test_valid_waiver_is_pending_and_never_passed(self) -> None:
        self._write("release-risk-waiver.json", waiver_document())
        completed = self._run(token=TOKEN)
        self.assertEqual(0, completed.returncode, self._combined(completed))
        summary = json.loads(completed.stdout.strip().splitlines()[-1])
        self.assertEqual("risk-waiver", summary["mode"])
        self.assertEqual("pending-user-validation", summary["status"])
        self.assertEqual(PUBLIC_NOTICE, summary["publicNotice"])
        self.assertNotEqual("passed", summary["status"])

    def test_waiver_requires_exact_finalize_confirmation(self) -> None:
        self._write("release-risk-waiver.json", waiver_document())
        for token in (None, "i_accept_1.0.0_code66_release_with_device_validation_pending"):
            completed = self._run(token=token)
            self.assertNotEqual(0, completed.returncode)
            self.assertIn("RISK_WAIVER_EXACT_CONFIRMATION_REQUIRED", self._combined(completed))

    def test_waiver_is_frozen_to_code66_and_created_date(self) -> None:
        document = waiver_document(code=65)
        self._write("release-risk-waiver.json", document)
        completed = self._run(token=TOKEN, expected_code=65)
        self.assertNotEqual(0, completed.returncode)
        self.assertIn("1.0.0/code66", self._combined(completed))

        document["versionCode"] = 66
        document["createdAt"] = "2026-08-16"
        self._write("release-risk-waiver.json", document)
        completed = self._run(token=TOKEN)
        self.assertNotEqual(0, completed.returncode)
        self.assertIn("固定 schema", self._combined(completed))

    def test_unknown_or_binding_drift_is_rejected(self) -> None:
        document = waiver_document()
        document["unexpected"] = True
        self._write("release-risk-waiver.json", document)
        completed = self._run(token=TOKEN)
        self.assertNotEqual(0, completed.returncode)
        self.assertIn("unknown", self._combined(completed))

        document.pop("unexpected")
        document["releaseEvidenceCommit"] = "e" * 40
        self._write("release-risk-waiver.json", document)
        completed = self._run(token=TOKEN)
        self.assertNotEqual(0, completed.returncode)
        self.assertIn("未精确绑定", self._combined(completed))

    def test_exactly_one_gate_file_is_required(self) -> None:
        completed = self._run(token=TOKEN)
        self.assertNotEqual(0, completed.returncode)
        self.assertIn("RELEASE_DEVICE_GATE_EXACTLY_ONE_REQUIRED", self._combined(completed))

        self._write("release-risk-waiver.json", waiver_document())
        self._write("device-upgrade-evidence.json", device_document())
        completed = self._run(token=TOKEN)
        self.assertNotEqual(0, completed.returncode)
        self.assertIn("RELEASE_DEVICE_GATE_EXACTLY_ONE_REQUIRED", self._combined(completed))

    def test_complete_four_role_device_evidence_passes_without_waiver(self) -> None:
        self._write("device-upgrade-evidence.json", device_document())
        completed = self._run()
        self.assertEqual(0, completed.returncode, self._combined(completed))
        summary = json.loads(completed.stdout.strip().splitlines()[-1])
        self.assertEqual("device-evidence", summary["mode"])
        self.assertEqual("passed", summary["status"])

    def test_incomplete_or_false_device_evidence_is_rejected(self) -> None:
        document = device_document()
        roles = document["roles"]
        assert isinstance(roles, list)
        roles.pop()
        self._write("device-upgrade-evidence.json", document)
        completed = self._run()
        self.assertNotEqual(0, completed.returncode)
        self.assertIn("四角色", self._combined(completed))

        document = device_document()
        roles = document["roles"]
        assert isinstance(roles, list) and isinstance(roles[0], dict)
        roles[0]["dataDirPreserved"] = False
        self._write("device-upgrade-evidence.json", document)
        completed = self._run()
        self.assertNotEqual(0, completed.returncode)
        self.assertIn("user", self._combined(completed))

    def test_device_evidence_rejects_waiver_token(self) -> None:
        self._write("device-upgrade-evidence.json", device_document())
        completed = self._run(token=TOKEN)
        self.assertNotEqual(0, completed.returncode)
        self.assertIn("不得传入风险豁免令牌", self._combined(completed))


class ReleaseDeviceGateStaticContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.validator = VALIDATOR.read_text(encoding="utf-8")
        cls.generator = GENERATOR.read_text(encoding="utf-8")
        cls.release = RELEASE.read_text(encoding="utf-8")
        cls.package = PACKAGE.read_text(encoding="utf-8")

    def test_all_powershell_scripts_parse(self) -> None:
        powershell = shutil.which("powershell.exe") or shutil.which("powershell")
        self.assertIsNotNone(powershell)
        for script in (VALIDATOR, GENERATOR, RELEASE, PACKAGE):
            command = (
                "$errors=$null;"
                f"[Management.Automation.Language.Parser]::ParseFile('{str(script).replace(chr(39), chr(39) * 2)}',"
                "[ref]$null,[ref]$errors)|Out-Null;"
                "if($errors.Count){$errors|%{$_.ToString()};exit 1}"
            )
            completed = subprocess.run(
                [str(powershell), "-NoProfile", "-Command", command],
                text=True,
                encoding="utf-8",
                errors="replace",
                capture_output=True,
                timeout=30,
                check=False,
            )
            self.assertEqual(0, completed.returncode, completed.stdout + completed.stderr)

    def test_generator_is_fail_closed_and_does_not_ship_a_waiver(self) -> None:
        for marker in (
            "versionName -cne '1.0.0'",
            "versionCode -ne 66",
            "createdAt = '2026-08-15'",
            "tagType -cne 'tag'",
            "evidenceCommit -cne $mainCommit",
            "Write-Utf8JsonAtomic",
            "verify-release-device-gate.ps1",
            "拒绝覆盖",
        ):
            self.assertIn(marker, self.generator)
        tracked = subprocess.run(
            ["git", "-C", str(ROOT), "ls-files", "--", "releases/1.0.0/release-risk-waiver.json"],
            text=True,
            encoding="utf-8",
            errors="replace",
            capture_output=True,
            timeout=30,
            check=True,
        )
        self.assertEqual("", tracked.stdout.strip())

    def test_finalize_calls_gate_before_any_transaction_write(self) -> None:
        gate = self.package.index("$gateOutput = @(& powershell.exe @gateArguments")
        transaction = self.package.index("$token = [Guid]::NewGuid().ToString('N')")
        self.assertLess(gate, transaction)
        for marker in (
            "RELEASE_DEVICE_GATE_EXACTLY_ONE_REQUIRED",
            "pending-user-validation",
            "device-upgrade-evidence.json",
            "release-risk-waiver.json",
            "NotePropertyName deviceValidation -NotePropertyValue $deviceValidation",
            "Assert-DeviceGateArtifact -Directory $stagingDirectory",
        ):
            self.assertIn(marker, self.validator + self.package)

    def test_release_requires_plan_and_exact_confirmation_twice(self) -> None:
        for marker in (
            "[ValidateSet('DeviceEvidence', 'UserRiskWaiver')]",
            "RISK_WAIVER_EXACT_CONFIRMATION_REQUIRED",
            "-RiskWaiverConfirmationToken",
            "deviceValidationPlan",
            PUBLIC_NOTICE,
        ):
            self.assertIn(marker, self.release)
        self.assertGreaterEqual(self.release.count("$RiskWaiverConfirmationToken"), 5)

    def test_website_metadata_keeps_the_plan_immutable(self) -> None:
        self.assertIn('"deviceValidationPlan"', PROJECTION.read_text(encoding="utf-8"))
        self.assertIn('"deviceValidationPlan"', DEPLOY_STATIC.read_text(encoding="utf-8"))
        self.assertIn(PUBLIC_NOTICE, self.package)
        self.assertIn("$riskWaiverPublicNotice -cnotin", self.package)

    def test_all_final_release_consumers_share_the_strict_python_gate(self) -> None:
        for consumer in (DEPLOY_STATIC, PUBLISH_ANDROID, VERIFY_PRODUCTION):
            source = consumer.read_text(encoding="utf-8")
            self.assertIn("from release_device_gate import", source)
            self.assertIn("validate_final_release_device_gate(", source)
        python_gate = PYTHON_GATE.read_text(encoding="utf-8")
        for marker in (
            "version_code < 66",
            "risk waiver is restricted to Stable 1.0.0/code66",
            "project assets manifest is not bound to finalized manifest bytes",
            "duplicate JSON field",
            PUBLIC_NOTICE,
        ):
            self.assertIn(marker, python_gate)


if __name__ == "__main__":
    unittest.main()
