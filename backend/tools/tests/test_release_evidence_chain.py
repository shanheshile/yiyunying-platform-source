#!/usr/bin/env python3
"""Static and pure-parser regression checks for the release evidence chain."""

from __future__ import annotations

import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import types
import unittest
import zipfile


ROOT = Path(__file__).resolve().parents[3]
VERIFIER_PATH = ROOT / "backend" / "tools" / "verify-production-release-ssh.py"
RELEASE_PATH = ROOT / "android" / "tools" / "release.ps1"
PACKAGE_PATH = ROOT / "scripts" / "package-project.ps1"
VERSION_PATH = ROOT / "android" / "tools" / "version.ps1"
VERIFY_PATH = ROOT / "android" / "tools" / "verify.ps1"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8-sig")


def fake_connection_identity() -> dict[str, str]:
    return {
        "apiBaseUrl": "https://api.example.invalid/",
        "appKeySha256": "1" * 64,
        "platformKeySha256": "2" * 64,
        "authorizedPlatformKeySha256": "3" * 64,
    }


def load_verifier_module():
    previous = sys.modules.get("paramiko")
    module_name = "release_verifier_under_test"
    previous_module = sys.modules.get(module_name)
    sys.modules["paramiko"] = types.ModuleType("paramiko")
    try:
        spec = importlib.util.spec_from_file_location(module_name, VERIFIER_PATH)
        if spec is None or spec.loader is None:
            raise AssertionError("cannot load production release verifier")
        module = importlib.util.module_from_spec(spec)
        sys.modules[module_name] = module
        spec.loader.exec_module(module)
        return module
    finally:
        if previous is None:
            sys.modules.pop("paramiko", None)
        else:
            sys.modules["paramiko"] = previous
        if previous_module is None:
            sys.modules.pop(module_name, None)
        else:
            sys.modules[module_name] = previous_module


def run(
    command: list[str],
    cwd: Path,
    *,
    check: bool = True,
    env: dict[str, str] | None = None,
) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(
        command,
        cwd=cwd,
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        encoding="utf-8",
        errors="replace",
        env=env,
    )
    if check and result.returncode != 0:
        raise AssertionError(
            f"command failed ({result.returncode}): {command!r}\n{result.stdout}"
        )
    return result


class ReleaseEvidenceChainTest(unittest.TestCase):
    def test_stable_verify_reuses_existing_debug_tests_and_release_outputs(self) -> None:
        source = read(VERIFY_PATH)
        for marker in (
            "function Invoke-GradlePhase",
            "--no-daemon --no-parallel --max-workers=1 --rerun-tasks @Tasks",
            "foreach ($unitTestTask in $unitTestTasks)",
            "@('clean', $unitTestTask)",
            'Invoke-GradlePhase -Label "unit test: $unitTestTask" -Tasks $phaseTasks',
            "foreach ($edition in $editions)",
            'Invoke-GradlePhase -Label "$edition $buildType lint and assemble"',
            "if ($LASTEXITCODE -ne 0)",
        ):
            self.assertIn(marker, source)
        for edition in ("PlatformOwner", "AuthorizedPlatform", "Admin", "User"):
            self.assertIn(f"'test{edition}DebugUnitTest'", source)
            self.assertNotIn(f'"test{edition}${{buildType}}UnitTest"', source)
        self.assertIn('"lint${edition}${buildType}"', source)
        self.assertIn('"assemble${edition}${buildType}"', source)
        self.assertNotIn("--rerun-tasks @tasks", source)

    def test_version_writers_use_lf_for_release_identity_and_version_evidence(self) -> None:
        source = read(VERSION_PATH)
        for marker in (
            '$content = "VERSION_CODE=$Code`nVERSION_NAME=$Name`n"',
            '-replace "`r`n?", "`n"',
            '$json + "`n"',
            '"$audit`n"',
        ):
            self.assertIn(marker, source)
        self.assertNotIn("[Environment]::NewLine", source)

    def test_version_set_preserves_committed_stable_signer_identity(self) -> None:
        powershell = shutil.which("powershell.exe") or shutil.which("powershell")
        self.assertIsNotNone(powershell, "Windows PowerShell is required")
        signer = "6cf7b18af125a1d44e28feaee7a5c6d39ca0bbae89529ca43a8c200b21db9772"

        with tempfile.TemporaryDirectory() as temporary:
            repository = Path(temporary) / "repository"
            tools = repository / "android" / "tools"
            tools.mkdir(parents=True)
            (repository / "download-site").mkdir()
            (repository / "backend" / "config").mkdir(parents=True)
            shutil.copy2(VERSION_PATH, tools / "version.ps1")
            (repository / "android" / "version.properties").write_text(
                "VERSION_CODE=60\nVERSION_NAME=2.7.15\n",
                encoding="utf-8",
            )
            (repository / "download-site" / "package.json").write_text(
                json.dumps({"version": "2.7.15"}) + "\n",
                encoding="utf-8",
            )
            identity_path = repository / "backend" / "config" / "release-identity.json"
            identity_path.write_text(
                json.dumps(
                    {
                        "version_name": "2.7.15",
                        "version_code": 60,
                        "stable_signer_sha256": signer.upper(),
                    }
                )
                + "\n",
                encoding="utf-8",
            )

            result = run(
                [
                    powershell,
                    "-NoProfile",
                    "-ExecutionPolicy",
                    "Bypass",
                    "-File",
                    str(tools / "version.ps1"),
                    "-Action",
                    "set",
                    "-VersionName",
                    "2.8.0",
                    "-VersionCode",
                    "61",
                    "-Json",
                ],
                repository,
            )
            self.assertIn('"versionName":"2.8.0"', result.stdout)
            identity = json.loads(identity_path.read_text(encoding="utf-8"))
            self.assertEqual(identity["version_name"], "2.8.0")
            self.assertEqual(identity["version_code"], 61)
            self.assertEqual(identity["stable_signer_sha256"], signer)
            self.assertNotIn(b"\r\n", identity_path.read_bytes())
            self.assertNotIn(
                b"\r\n", (repository / "download-site" / "package.json").read_bytes()
            )

    def test_ssh_and_public_apk_verification_fail_closed(self) -> None:
        source = read(VERIFIER_PATH)
        compile(source, str(VERIFIER_PATH), "exec")
        for marker in (
            'parser.add_argument("--known-hosts", required=True)',
            "client.load_host_keys(str(known_hosts))",
            "paramiko.RejectPolicy()",
            'parser.add_argument("--release-identity", required=True)',
            'parser.add_argument("--release-manifest", required=True)',
            'parser.add_argument("--aapt", required=True',
            'parser.add_argument("--apksigner", required=True',
            "public APK size or SHA-256 does not match",
            '"If-Range": etag',
            '"If-None-Match": etag',
            "content_range",
            "single-signer + Range/ETag",
            "catalog_private_migration_ready",
            "catalog-private-activation-",
            "runtime_gate_readback",
            "schema_migrations",
            "active-release-policies",
            "2026.08.11-management-shell-restructure",
            'parser.add_argument("--allow-insecure-http-debug", action="store_true")',
            'parser.add_argument("--insecure-http-confirmation", default="")',
            "DEBUG_HTTP_NON_PRODUCTION_CONFIRMED",
            "authorize_insecure_http_debug",
        ):
            self.assertIn(marker, source)
        self.assertNotIn("AutoAddPolicy", source)
        self.assertNotIn('parser.add_argument("--expected"', source)

    def test_verifier_http_debug_requires_exact_confirmation_and_debug_identities(self) -> None:
        module = load_verifier_module()
        expected = {
            edition: {
                "package": f"xyz.jjmxg.yiyunying.{edition}.debug",
                "version_name": f"2.7.15-{edition}-debug",
                "file_name": f"{edition}-debug.apk",
            }
            for edition in module.REQUIRED_EDITIONS
        }
        self.assertFalse(module.authorize_insecure_http_debug(expected, False, ""))
        with self.assertRaisesRegex(RuntimeError, "exact non-production"):
            module.authorize_insecure_http_debug(expected, True, "wrong")
        self.assertTrue(
            module.authorize_insecure_http_debug(
                expected, True, module.INSECURE_HTTP_CONFIRMATION
            )
        )
        expected["user"]["package"] = "xyz.jjmxg.yiyunying.user"
        with self.assertRaisesRegex(RuntimeError, "restricted to four Debug"):
            module.authorize_insecure_http_debug(
                expected, True, module.INSECURE_HTTP_CONFIRMATION
            )

    def test_manifest_parser_requires_exact_four_and_identity_hash(self) -> None:
        module = load_verifier_module()
        identity_bytes = b'{"version_name":"2.7.14","version_code":59}\n'
        identity_hash = hashlib.sha256(identity_bytes).hexdigest()
        ids = {
            "owner": ("platformowner", "platform-owner-debug"),
            "authorized": ("authorized", "authorized-platform-debug"),
            "admin": ("admin", "admin-debug"),
            "user": ("user", "user-debug"),
        }
        releases = []
        for release_id, (package_suffix, version_suffix) in ids.items():
            releases.append(
                {
                    "id": release_id,
                    "fileName": f"{release_id}.apk",
                    "packageName": f"xyz.jjmxg.yiyunying.{package_suffix}.debug",
                    "versionName": f"2.7.14-{version_suffix}",
                    "versionCode": 59,
                    "sizeBytes": 1234567,
                    "sha256": "a" * 64,
                    "signerSha256": "b" * 64,
                }
            )
        manifest = {
            "schemaVersion": 4,
            "versionName": "2.7.14",
            "versionCode": 59,
            "buildSourceCommit": "c" * 40,
            "releaseEvidenceCommit": "d" * 40,
            "releaseTag": "v2.7.14-debug",
            "finalizationStatus": "finalized",
            "releaseIdentitySha256": identity_hash,
            "connectionIdentity": fake_connection_identity(),
            "releases": releases,
        }
        with tempfile.TemporaryDirectory() as temporary:
            identity_path = Path(temporary) / "identity.json"
            manifest_path = Path(temporary) / "manifest.json"
            identity_path.write_bytes(identity_bytes)
            manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
            (
                identity,
                expected,
                connection_identity,
                actual_hash,
                commit,
                evidence_commit,
                release_tag,
                manifest_hash,
            ) = module.load_release_evidence(identity_path, manifest_path)
            self.assertEqual(identity["version_code"], 59)
            self.assertEqual(set(expected), module.REQUIRED_EDITIONS)
            self.assertEqual(connection_identity, fake_connection_identity())
            self.assertEqual(actual_hash, identity_hash)
            self.assertEqual(commit, "c" * 40)
            self.assertEqual(evidence_commit, "d" * 40)
            self.assertEqual(release_tag, "v2.7.14-debug")
            self.assertEqual(len(manifest_hash), 64)

            manifest["releases"] = releases[:-1]
            manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
            with self.assertRaisesRegex(RuntimeError, "exactly four"):
                module.load_release_evidence(identity_path, manifest_path)

            manifest["releases"] = releases
            manifest["releases"][0]["signerSha256"] = "d" * 64
            manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
            with self.assertRaisesRegex(RuntimeError, "unified signer"):
                module.load_release_evidence(identity_path, manifest_path)

    def test_release_script_separates_build_from_finalize(self) -> None:
        source = read(RELEASE_PATH)
        for marker in (
            "[ValidateSet('Build', 'Finalize')]",
            "[string] $Phase = 'Build'",
            "[string] $Bump = 'none'",
            "--untracked-files=all",
            "buildSourceCommit = $buildSourceCommit",
            "releaseEvidenceCommit = $null",
            "[ValidateSet('Debug', 'Stable')]",
            "[string] $Channel = 'Debug'",
            'releaseTag = $expectedTag',
            "if ($Channel -eq 'Stable' -and $SkipVerification)",
            "finalizationStatus = 'pending'",
            "releaseIdentitySha256 = $releaseIdentitySha256",
            "Read-ReleaseConnectionIdentity",
            "Assert-ReleaseConnectionKey",
            "Assert-GeneratedConnectionIdentity -ConnectionIdentity $connectionIdentity",
            "connectionIdentity = $connectionIdentity.evidence",
            "appKeySha256 = Get-Utf8StringSha256 -Value $appKey",
            "platformKeySha256 = Get-Utf8StringSha256 -Value $platformKey",
            "authorizedPlatformKeySha256 = Get-Utf8StringSha256 -Value $authorizedPlatformKey",
            "Read-GitBlobBytes",
            "Get-ByteArraySha256 -Bytes $committedIdentityBytes",
            "发布身份文件工作树原始字节与 HEAD Git blob 不一致",
            "$downloadMetadata['pendingManifestSha256'] = $pendingManifestSha256",
            "'ls-files', '--error-unmatch'",
            "if ($Phase -eq 'Finalize')",
            "-ReleaseRoot $releaseRoot -ExpectedTag $expectedTag",
            "releaseEvidenceCommit -ne $evidenceCommit",
            "finalizationStatus -ne 'finalized'",
            "项目资产清单未同时绑定 Build 源码提交与 Finalize 证据提交",
            "项目资产体积或 SHA-256 与证据清单不一致",
        ):
            self.assertIn(marker, source)
        self.assertIn("$Bump -ne 'none'", source)
        finalize_start = source.index("if ($Phase -eq 'Finalize')")
        package_call = source.index("-ReleaseRoot $releaseRoot -ExpectedTag $expectedTag")
        build_start = source.index("$releaseEvidence = Read-CommittedReleaseEvidence")
        self.assertLess(finalize_start, package_call)
        self.assertLess(package_call, build_start)

        build_gate_start = source.index("function Read-CommittedReleaseEvidence")
        build_gate_end = source.index("Assert-ApkIdentityParser", build_gate_start)
        build_gate = source[build_gate_start:build_gate_end]
        self.assertNotIn("refs/tags", build_gate)
        self.assertNotIn("rev-list", build_gate)
        self.assertIsNone(re.search(r"Write-(?:Host|Output).*\$(?:appKey|platformKey|authorizedPlatformKey)", source))
        manifest_start = source.index("$manifest = [ordered]@{")
        manifest_end = source.index("Write-Utf8JsonAtomic -Path $pendingManifestPath", manifest_start)
        manifest_block = source[manifest_start:manifest_end]
        for plaintext_field in ("appKey =", "platformKey =", "authorizedPlatformKey ="):
            self.assertNotIn(plaintext_field, manifest_block)

    def test_build_rejects_crlf_identity_even_when_git_reports_clean(self) -> None:
        powershell = shutil.which("powershell.exe") or shutil.which("powershell")
        git = shutil.which("git.exe") or shutil.which("git")
        self.assertIsNotNone(powershell, "Windows PowerShell is required")
        self.assertIsNotNone(git, "Git is required")

        with tempfile.TemporaryDirectory() as temporary:
            repository = Path(temporary) / "repository"
            (repository / "android" / "tools").mkdir(parents=True)
            (repository / "backend" / "config").mkdir(parents=True)
            (repository / "download-site").mkdir(parents=True)
            shutil.copy2(RELEASE_PATH, repository / "android" / "tools" / "release.ps1")
            (repository / "android" / "tools" / "version.ps1").write_text(
                "param([string] $Action, [switch] $Json)\n"
                "[pscustomobject]@{ versionName = '9.9.9'; versionCode = 999; changed = $false } "
                "| ConvertTo-Json -Compress\n",
                encoding="utf-8-sig",
            )
            identity_path = repository / "backend" / "config" / "release-identity.json"
            identity_path.write_bytes(b'{"version_name":"9.9.9","version_code":999}\n')

            run([git, "init", "-b", "main"], repository)
            run([git, "config", "user.name", "Release Test"], repository)
            run([git, "config", "user.email", "release-test@example.invalid"], repository)
            run([git, "config", "core.autocrlf", "true"], repository)
            run([git, "add", "."], repository)
            run([git, "commit", "-m", "build source"], repository)

            command = [
                powershell,
                "-NoProfile",
                "-ExecutionPolicy",
                "Bypass",
                "-File",
                str(repository / "android" / "tools" / "release.ps1"),
                "-Phase",
                "Build",
                "-JavaHome",
                str(repository),
            ]
            connection_names = {
                "YIYUNYING_API_BASE_URL",
                "YIYUNYING_APP_KEY",
                "YIYUNYING_PLATFORM_KEY",
                "YIYUNYING_AUTHORIZED_PLATFORM_KEY",
            }
            missing_environment = {
                name: value for name, value in os.environ.items() if name not in connection_names
            }
            missing = run(command, repository, check=False, env=missing_environment)
            self.assertNotEqual(missing.returncode, 0)
            self.assertIn("YIYUNYING_API_BASE_URL", missing.stdout)

            secret_values = {
                "YIYUNYING_API_BASE_URL": "https://api.example.invalid/",
                "YIYUNYING_APP_KEY": "sensitive-app-key-9f34",
                "YIYUNYING_PLATFORM_KEY": "sensitive-platform-key-7a21",
                "YIYUNYING_AUTHORIZED_PLATFORM_KEY": "sensitive-authorized-key-5c18",
            }
            valid_environment = dict(missing_environment)
            valid_environment.update(secret_values)
            placeholder_environment = dict(valid_environment)
            placeholder_environment["YIYUNYING_APP_KEY"] = "yiyunying-local"
            placeholder = run(command, repository, check=False, env=placeholder_environment)
            self.assertNotEqual(placeholder.returncode, 0)
            self.assertIn("占位值", placeholder.stdout)

            emulator_environment = dict(valid_environment)
            emulator_environment["YIYUNYING_API_BASE_URL"] = "http://10.0.2.2:8788/"
            emulator = run(command, repository, check=False, env=emulator_environment)
            self.assertNotEqual(emulator.returncode, 0)
            self.assertIn("Android 模拟器宿主地址", emulator.stdout)

            relative_environment = dict(valid_environment)
            relative_environment["YIYUNYING_API_BASE_URL"] = "/api/"
            relative = run(command, repository, check=False, env=relative_environment)
            self.assertNotEqual(relative.returncode, 0)
            self.assertIn("绝对 HTTP/HTTPS 地址", relative.stdout)

            noncanonical_outputs = []
            for bad_url in (
                "https://Example.INVALID/api/",
                "https://api.example.invalid/api/?x=/",
                "https://api.example.invalid/api/#fragment",
            ):
                noncanonical_environment = dict(valid_environment)
                noncanonical_environment["YIYUNYING_API_BASE_URL"] = bad_url
                noncanonical = run(
                    command, repository, check=False, env=noncanonical_environment
                )
                self.assertNotEqual(noncanonical.returncode, 0)
                self.assertIn("API_BASE_URL_CANONICAL_REQUIRED", noncanonical.stdout)
                noncanonical_outputs.append(noncanonical.stdout)
            identity_path.write_bytes(b'{"version_name":"9.9.9","version_code":999}\r\n')
            run([git, "add", "backend/config/release-identity.json"], repository)
            self.assertEqual(
                run([git, "status", "--porcelain", "--untracked-files=all"], repository).stdout,
                "",
                "fixture must reproduce a byte-different worktree that Git reports clean",
            )
            rejected = run(command, repository, check=False, env=valid_environment)
            self.assertNotEqual(rejected.returncode, 0)
            self.assertIn("HEAD Git blob", rejected.stdout)
            combined_output = (
                missing.stdout
                + placeholder.stdout
                + emulator.stdout
                + relative.stdout
                + "".join(noncanonical_outputs)
                + rejected.stdout
            )
            for secret in secret_values.values():
                self.assertNotIn(secret, combined_output)
            self.assertFalse((repository / "releases" / "9.9.9").exists())

    def test_build_rejects_generated_buildconfig_connection_mismatch_without_leaking_keys(self) -> None:
        powershell = shutil.which("powershell.exe") or shutil.which("powershell")
        git = shutil.which("git.exe") or shutil.which("git")
        self.assertIsNotNone(powershell, "Windows PowerShell is required")
        self.assertIsNotNone(git, "Git is required")

        version = "7.7.7"
        code = 777
        api_base_url = "https://api.example.invalid/"
        app_key = "sensitive-app-key-generated-31"
        platform_key = "sensitive-platform-key-generated-42"
        authorized_key = "sensitive-authorized-key-generated-53"

        def java_literal(value: str) -> str:
            return value.replace("\\", "\\\\").replace('"', '\\"')

        with tempfile.TemporaryDirectory() as temporary:
            repository = Path(temporary) / "repository"
            tools_directory = repository / "android" / "tools"
            tools_directory.mkdir(parents=True)
            (repository / "backend" / "config").mkdir(parents=True)
            (repository / "download-site").mkdir(parents=True)
            shutil.copy2(RELEASE_PATH, tools_directory / "release.ps1")
            (tools_directory / "version.ps1").write_text(
                "param([string] $Action, [switch] $Json)\n"
                f"[pscustomobject]@{{ versionName = '{version}'; versionCode = {code}; changed = $false }} "
                "| ConvertTo-Json -Compress\n",
                encoding="utf-8-sig",
            )
            (repository / "backend" / "config" / "release-identity.json").write_bytes(
                f'{{"version_name":"{version}","version_code":{code}}}\n'.encode()
            )
            (repository / "android" / "gradlew.bat").write_bytes(b"@echo off\r\nexit /b 0\r\n")

            for flavor in ("platformOwner", "authorizedPlatform", "admin", "user"):
                expected_platform = authorized_key if flavor == "authorizedPlatform" else platform_key
                generated_app_key = "mismatched-generated-key" if flavor == "admin" else app_key
                build_config = (
                    "package xyz.jjmxg.yiyunying;\n"
                    "public final class BuildConfig {\n"
                    f'  public static final String DEFAULT_API_BASE_URL = "{java_literal(api_base_url)}";\n'
                    f'  public static final String DEFAULT_APP_KEY = "{java_literal(generated_app_key)}";\n'
                    f'  public static final String DEFAULT_PLATFORM_KEY = "{java_literal(expected_platform)}";\n'
                    "}\n"
                )
                build_config_path = (
                    repository
                    / "android"
                    / "app"
                    / "build"
                    / "generated"
                    / "source"
                    / "buildConfig"
                    / flavor
                    / "debug"
                    / "xyz"
                    / "jjmxg"
                    / "yiyunying"
                    / "BuildConfig.java"
                )
                build_config_path.parent.mkdir(parents=True, exist_ok=True)
                build_config_path.write_text(build_config, encoding="utf-8")

            run([git, "init", "-b", "main"], repository)
            run([git, "config", "user.name", "Release Test"], repository)
            run([git, "config", "user.email", "release-test@example.invalid"], repository)
            run([git, "config", "core.autocrlf", "false"], repository)
            run([git, "add", "."], repository)
            run([git, "commit", "-m", "generated BuildConfig fixture"], repository)

            environment = dict(os.environ)
            environment.update(
                {
                    "YIYUNYING_API_BASE_URL": api_base_url,
                    "YIYUNYING_APP_KEY": app_key,
                    "YIYUNYING_PLATFORM_KEY": platform_key,
                    "YIYUNYING_AUTHORIZED_PLATFORM_KEY": authorized_key,
                }
            )
            rejected = run(
                [
                    powershell,
                    "-NoProfile",
                    "-ExecutionPolicy",
                    "Bypass",
                    "-File",
                    str(tools_directory / "release.ps1"),
                    "-Phase",
                    "Build",
                    "-SkipVerification",
                    "-JavaHome",
                    str(repository),
                ],
                repository,
                check=False,
                env=environment,
            )
            self.assertNotEqual(rejected.returncode, 0)
            self.assertIn("admin / DEFAULT_APP_KEY", rejected.stdout)
            for secret in (app_key, platform_key, authorized_key):
                self.assertNotIn(secret, rejected.stdout)
            self.assertFalse((repository / "releases" / version).exists())

    def test_project_finalize_binds_distinct_ancestor_and_annotated_tag(self) -> None:
        source = read(PACKAGE_PATH)
        for marker in (
            "--untracked-files=all",
            '$tag -ne $expectedTagForChannel',
            "$mainCommit -ne $evidenceCommit",
            "$evidenceCommit -eq $buildCommit",
            "'merge-base', '--is-ancestor', $buildCommit, $evidenceCommit",
            "'cat-file', '-t', \"refs/tags/$tag\"",
            "$tagType -ne 'tag'",
            "$tagCommit -ne $evidenceCommit",
            '"refs/tags/$tag"',
            "'core.autocrlf=false' '-C' $projectRoot 'archive' '--format=zip' \"--output=$sourcePath\" $buildCommit",
            "'core.autocrlf=false' '-C' $projectRoot 'archive' '--format=zip' \"--output=$evidenceZip\" $evidenceCommit",
            "'bundle' 'create' $historyPath 'refs/heads/main'",
            "releaseManifestSha256",
            "releaseIdentitySha256",
            "pendingManifestSha256",
            "Assert-ConnectionIdentityEvidence",
            "Test-ConnectionIdentityEvidenceEqual",
            "connectionIdentity = $releaseManifest.connectionIdentity",
            "Get-ZipEntrySha256",
            "Build 源码 A 快照中的发布身份原始字节 SHA-256 与发布清单不一致",
            "buildSourceCommit",
            "releaseEvidenceCommit",
            "releaseTag",
            "bundleRefs = @('refs/heads/main'",
            ".$version.$token.finalizing",
            ".$version.$token.build-backup",
            "Move-Item -LiteralPath $releaseDirectory -Destination $backupDirectory",
            "Move-Item -LiteralPath $stagingDirectory -Destination $releaseDirectory",
            "Copy-Item -LiteralPath $sourcePath -Destination (Join-Path $temporary $sourceName)",
        ):
            self.assertIn(marker, source)
        self.assertNotIn("--untracked-files=no", source)
        self.assertNotIn("'archive' '--format=zip' \"--output=$sourcePath\" 'HEAD'", source)
        self.assertNotIn("'bundle' 'create' $historyPath '--all'", source)
        self.assertNotIn("AllowDirty", source)
        self.assertNotIn("Remove-Item -LiteralPath $sourcePath", source)
        self.assertNotIn("Expand-Archive -LiteralPath $sourcePath", source)
        self.assertNotIn("Move-Item -LiteralPath $stagingDirectory -Destination $releaseDirectory -Force", source)

    def test_finalize_rejects_identity_hash_that_does_not_match_exact_a_archive(self) -> None:
        powershell = shutil.which("powershell.exe") or shutil.which("powershell")
        git = shutil.which("git.exe") or shutil.which("git")
        self.assertIsNotNone(powershell, "Windows PowerShell is required")
        self.assertIsNotNone(git, "Git is required")

        version = "8.8.8"
        code = 888
        tag = f"v{version}-debug"
        identity_lf = b'{"version_name":"8.8.8","version_code":888}\n'
        identity_crlf = b'{"version_name":"8.8.8","version_code":888}\r\n'
        with tempfile.TemporaryDirectory() as temporary:
            repository = Path(temporary) / "repository"
            (repository / "scripts").mkdir(parents=True)
            (repository / "backend" / "config").mkdir(parents=True)
            shutil.copy2(PACKAGE_PATH, repository / "scripts" / "package-project.ps1")
            (repository / ".gitignore").write_text("releases/\n", encoding="utf-8")
            identity_path = repository / "backend" / "config" / "release-identity.json"
            identity_path.write_bytes(identity_lf)
            (repository / "HANDOFF.md").write_text("Build source\n", encoding="utf-8")

            run([git, "init", "-b", "main"], repository)
            run([git, "config", "user.name", "Release Test"], repository)
            run([git, "config", "user.email", "release-test@example.invalid"], repository)
            run([git, "config", "core.autocrlf", "true"], repository)
            run([git, "add", "."], repository)
            run([git, "commit", "-m", "build source"], repository)
            build_commit = run([git, "rev-parse", "HEAD"], repository).stdout.strip()
            committed_identity_bytes = subprocess.run(
                [git, "cat-file", "blob", f"{build_commit}:backend/config/release-identity.json"],
                cwd=repository,
                check=True,
                stdout=subprocess.PIPE,
            ).stdout
            identity_path.write_bytes(committed_identity_bytes)
            run([git, "add", "backend/config/release-identity.json"], repository)

            release_directory = repository / "releases" / version
            release_directory.mkdir(parents=True)
            releases: list[dict[str, object]] = []
            for release_id in ("user", "admin", "authorized", "owner"):
                file_name = f"{release_id}.apk"
                payload = f"fake-{release_id}".encode()
                (release_directory / file_name).write_bytes(payload)
                releases.append(
                    {
                        "id": release_id,
                        "fileName": file_name,
                        "sizeBytes": len(payload),
                        "sha256": hashlib.sha256(payload).hexdigest(),
                    }
                )
            project_assets = [
                {"id": "source", "fileName": f"yiyunying-source-v{version}.zip"},
                {"id": "history", "fileName": f"yiyunying-git-history-v{version}.bundle"},
                {"id": "delivery", "fileName": f"yiyunying-project-delivery-v{version}.zip"},
                {"id": "manifest", "fileName": "project-assets-manifest.json"},
            ]
            manifest = {
                "schemaVersion": 4,
                "versionName": version,
                "versionCode": code,
                "buildSourceCommit": build_commit,
                "releaseEvidenceCommit": None,
                "releaseTag": tag,
                "finalizationStatus": "pending",
                "releaseIdentitySha256": hashlib.sha256(identity_crlf).hexdigest(),
                "connectionIdentity": fake_connection_identity(),
                "releases": releases,
                "projectAssets": project_assets,
            }
            manifest_path = release_directory / "release-manifest.json"
            manifest_path.write_text(
                json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
            )
            metadata = dict(manifest)
            metadata["pendingManifestSha256"] = hashlib.sha256(
                manifest_path.read_bytes()
            ).hexdigest()
            metadata_path = repository / "download-site" / "release-metadata.json"
            metadata_path.parent.mkdir(parents=True)
            metadata_path.write_text(
                json.dumps(metadata, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
            )
            (release_directory / "SHA256SUMS.txt").write_text(
                "".join(f"{entry['sha256']}  {entry['fileName']}\n" for entry in releases),
                encoding="utf-8",
            )

            (repository / "HANDOFF.md").write_text("Evidence commit\n", encoding="utf-8")
            run([git, "add", "HANDOFF.md", "download-site/release-metadata.json"], repository)
            run([git, "commit", "-m", "release evidence"], repository)
            run([git, "tag", "-a", tag, "-m", "debug release evidence"], repository)
            identity_path.write_bytes(identity_crlf)
            run([git, "add", "backend/config/release-identity.json"], repository)
            self.assertEqual(
                run([git, "status", "--porcelain", "--untracked-files=all"], repository).stdout,
                "",
                "fixture must keep the CRLF identity invisible to Git status",
            )

            rejected = run(
                [
                    powershell,
                    "-NoProfile",
                    "-ExecutionPolicy",
                    "Bypass",
                    "-File",
                    str(repository / "scripts" / "package-project.ps1"),
                    "-ReleaseRoot",
                    str(repository / "releases"),
                    "-ExpectedTag",
                    tag,
                ],
                repository,
                check=False,
            )
            self.assertNotEqual(rejected.returncode, 0)
            self.assertIn("Build 源码 A", rejected.stdout)
            self.assertFalse((release_directory / f"yiyunying-source-v{version}.zip").exists())

    def test_finalize_transaction_closes_a_b_evidence_chain_once(self) -> None:
        powershell = shutil.which("powershell.exe") or shutil.which("powershell")
        git = shutil.which("git.exe") or shutil.which("git")
        self.assertIsNotNone(powershell, "Windows PowerShell is required")
        self.assertIsNotNone(git, "Git is required")

        version = "9.9.9"
        code = 999
        tag = f"v{version}-debug"
        with tempfile.TemporaryDirectory() as temporary:
            repository = Path(temporary) / "repository"
            (repository / "scripts").mkdir(parents=True)
            (repository / "backend" / "config").mkdir(parents=True)
            shutil.copy2(PACKAGE_PATH, repository / "scripts" / "package-project.ps1")
            (repository / ".gitignore").write_text("releases/\n", encoding="utf-8")
            identity_path = repository / "backend" / "config" / "release-identity.json"
            identity_path.write_text(
                json.dumps({"version_name": version, "version_code": code}, ensure_ascii=False)
                + "\n",
                encoding="utf-8",
            )
            (repository / "HANDOFF.md").write_text("Build 源码\n", encoding="utf-8")

            run([git, "init", "-b", "main"], repository)
            run([git, "config", "user.name", "Release Test"], repository)
            run([git, "config", "user.email", "release-test@example.invalid"], repository)
            run([git, "add", "."], repository)
            run([git, "commit", "-m", "build source"], repository)
            build_commit = run([git, "rev-parse", "HEAD"], repository).stdout.strip()

            committed_identity_bytes = subprocess.run(
                [git, "cat-file", "blob", f"{build_commit}:backend/config/release-identity.json"],
                cwd=repository,
                check=True,
                stdout=subprocess.PIPE,
            ).stdout
            identity_path.write_bytes(committed_identity_bytes)
            run([git, "add", "backend/config/release-identity.json"], repository)

            release_directory = repository / "releases" / version
            release_directory.mkdir(parents=True)
            release_entries: list[dict[str, object]] = []
            for index, release_id in enumerate(("user", "admin", "authorized", "owner"), start=1):
                file_name = f"{release_id}.apk"
                payload = (f"fake-apk-{release_id}-" * (index + 1)).encode()
                apk_path = release_directory / file_name
                apk_path.write_bytes(payload)
                release_entries.append(
                    {
                        "id": release_id,
                        "fileName": file_name,
                        "sizeBytes": len(payload),
                        "sha256": hashlib.sha256(payload).hexdigest(),
                    }
                )
            project_assets = [
                {"id": "source", "fileName": f"yiyunying-source-v{version}.zip"},
                {"id": "history", "fileName": f"yiyunying-git-history-v{version}.bundle"},
                {"id": "delivery", "fileName": f"yiyunying-project-delivery-v{version}.zip"},
                {"id": "manifest", "fileName": "project-assets-manifest.json"},
            ]
            manifest = {
                "schemaVersion": 4,
                "versionName": version,
                "versionCode": code,
                "buildSourceCommit": build_commit,
                "releaseEvidenceCommit": None,
                "releaseTag": tag,
                "finalizationStatus": "pending",
                "releaseIdentitySha256": hashlib.sha256(committed_identity_bytes).hexdigest(),
                "connectionIdentity": fake_connection_identity(),
                "releases": release_entries,
                "projectAssets": project_assets,
            }
            (release_directory / "release-manifest.json").write_text(
                json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
            )
            pending_manifest_bytes = (release_directory / "release-manifest.json").read_bytes()
            download_metadata = dict(manifest)
            download_metadata["pendingManifestSha256"] = hashlib.sha256(
                pending_manifest_bytes
            ).hexdigest()
            download_metadata_path = repository / "download-site" / "release-metadata.json"
            download_metadata_path.parent.mkdir(parents=True)
            download_metadata_path.write_text(
                json.dumps(download_metadata, ensure_ascii=False, indent=2) + "\n",
                encoding="utf-8",
            )
            download_metadata_bytes = download_metadata_path.read_bytes()
            (release_directory / "SHA256SUMS.txt").write_text(
                "".join(f"{entry['sha256']}  {entry['fileName']}\n" for entry in release_entries),
                encoding="utf-8",
            )

            (repository / "HANDOFF.md").write_text("部署证据\n", encoding="utf-8")
            run([git, "add", "HANDOFF.md", "download-site/release-metadata.json"], repository)
            run([git, "commit", "-m", "release evidence"], repository)
            evidence_commit = run([git, "rev-parse", "HEAD"], repository).stdout.strip()
            run([git, "tag", "-a", tag, "-m", "debug release evidence"], repository)

            command = [
                powershell,
                "-NoProfile",
                "-ExecutionPolicy",
                "Bypass",
                "-File",
                str(repository / "scripts" / "package-project.ps1"),
                "-ReleaseRoot",
                str(repository / "releases"),
                "-ExpectedTag",
                tag,
            ]
            tampered_metadata = json.loads(download_metadata_bytes.decode("utf-8"))
            tampered_metadata["connectionIdentity"]["appKeySha256"] = "4" * 64
            download_metadata_path.write_text(
                json.dumps(tampered_metadata, ensure_ascii=False, indent=2) + "\n",
                encoding="utf-8",
            )
            connection_rejected = run(command, repository, check=False)
            self.assertNotEqual(connection_rejected.returncode, 0)
            self.assertIn("pending", connection_rejected.stdout)
            download_metadata_path.write_bytes(download_metadata_bytes)

            tampered_manifest = dict(manifest)
            tampered_manifest["releaseNotes"] = ["ignored release directory was modified"]
            (release_directory / "release-manifest.json").write_text(
                json.dumps(tampered_manifest, ensure_ascii=False, indent=2) + "\n",
                encoding="utf-8",
            )
            rejected = run(command, repository, check=False)
            self.assertNotEqual(rejected.returncode, 0)
            self.assertIn("pending", rejected.stdout)
            (release_directory / "release-manifest.json").write_bytes(pending_manifest_bytes)

            first = run(command, repository)
            self.assertIn("完整项目产物已一次性收口", first.stdout)

            final_manifest = json.loads(
                (release_directory / "release-manifest.json").read_text(encoding="utf-8-sig")
            )
            assets_manifest = json.loads(
                (release_directory / "project-assets-manifest.json").read_text(
                    encoding="utf-8-sig"
                )
            )
            for value in (final_manifest, assets_manifest):
                self.assertEqual(value["buildSourceCommit"], build_commit)
                self.assertEqual(value["releaseEvidenceCommit"], evidence_commit)
                self.assertEqual(value["releaseTag"], tag)
                self.assertEqual(value["connectionIdentity"], fake_connection_identity())
                self.assertEqual(
                    set(value["connectionIdentity"]),
                    {
                        "apiBaseUrl",
                        "appKeySha256",
                        "platformKeySha256",
                        "authorizedPlatformKeySha256",
                    },
                )
            self.assertEqual(final_manifest["finalizationStatus"], "finalized")
            self.assertEqual(
                assets_manifest["releaseManifestSha256"],
                hashlib.sha256((release_directory / "release-manifest.json").read_bytes()).hexdigest(),
            )

            source_zip = release_directory / f"yiyunying-source-v{version}.zip"
            delivery_zip = release_directory / f"yiyunying-project-delivery-v{version}.zip"
            bundle = release_directory / f"yiyunying-git-history-v{version}.bundle"
            with zipfile.ZipFile(source_zip) as archive:
                self.assertEqual(
                    archive.read("HANDOFF.md").decode("utf-8").replace("\r\n", "\n"),
                    "Build 源码\n",
                )
            with zipfile.ZipFile(delivery_zip) as archive:
                nested_source = archive.read(f"yiyunying-source-v{version}.zip")
                self.assertEqual(nested_source, source_zip.read_bytes())
                with zipfile.ZipFile(io.BytesIO(nested_source)) as source_archive:
                    self.assertIn(".gitignore", source_archive.namelist())
                self.assertEqual(
                    archive.read("handoff/HANDOFF.md")
                    .decode("utf-8")
                    .replace("\r\n", "\n"),
                    "部署证据\n",
                )
            heads = run([git, "bundle", "list-heads", str(bundle)], repository).stdout
            self.assertIn("refs/heads/main", heads)
            self.assertIn(f"refs/tags/{tag}", heads)

            immutable_hashes = {
                item["fileName"]: hashlib.sha256(
                    (release_directory / item["fileName"]).read_bytes()
                ).hexdigest()
                for item in assets_manifest["assets"]
            }
            second = run(command, repository, check=False)
            self.assertNotEqual(second.returncode, 0)
            for name, expected_hash in immutable_hashes.items():
                self.assertEqual(
                    hashlib.sha256((release_directory / name).read_bytes()).hexdigest(),
                    expected_hash,
                )


if __name__ == "__main__":
    unittest.main()
