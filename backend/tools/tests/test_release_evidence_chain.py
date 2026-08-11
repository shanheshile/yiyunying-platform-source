#!/usr/bin/env python3
"""Static and pure-parser regression checks for the release evidence chain."""

from __future__ import annotations

import hashlib
import importlib.util
import io
import json
from pathlib import Path
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


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8-sig")


def load_verifier_module():
    previous = sys.modules.get("paramiko")
    sys.modules["paramiko"] = types.ModuleType("paramiko")
    try:
        spec = importlib.util.spec_from_file_location("release_verifier_under_test", VERIFIER_PATH)
        if spec is None or spec.loader is None:
            raise AssertionError("cannot load production release verifier")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module
    finally:
        if previous is None:
            sys.modules.pop("paramiko", None)
        else:
            sys.modules["paramiko"] = previous


def run(command: list[str], cwd: Path, *, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(
        command,
        cwd=cwd,
        check=False,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        encoding="utf-8",
        errors="replace",
    )
    if check and result.returncode != 0:
        raise AssertionError(
            f"command failed ({result.returncode}): {command!r}\n{result.stdout}"
        )
    return result


class ReleaseEvidenceChainTest(unittest.TestCase):
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
                actual_hash,
                commit,
                evidence_commit,
                release_tag,
                manifest_hash,
            ) = module.load_release_evidence(identity_path, manifest_path)
            self.assertEqual(identity["version_code"], 59)
            self.assertEqual(set(expected), module.REQUIRED_EDITIONS)
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
            'releaseTag = "v$($version.versionName)-debug"',
            "finalizationStatus = 'pending'",
            "releaseIdentitySha256 = $releaseIdentitySha256",
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

    def test_project_finalize_binds_distinct_ancestor_and_annotated_tag(self) -> None:
        source = read(PACKAGE_PATH)
        for marker in (
            "--untracked-files=all",
            '$tag -ne "v$version-debug"',
            "$mainCommit -ne $evidenceCommit",
            "$evidenceCommit -eq $buildCommit",
            "'merge-base', '--is-ancestor', $buildCommit, $evidenceCommit",
            "'cat-file', '-t', \"refs/tags/$tag\"",
            "$tagType -ne 'tag'",
            "$tagCommit -ne $evidenceCommit",
            '"refs/tags/$tag"',
            "'archive' '--format=zip' \"--output=$sourcePath\" $buildCommit",
            "'archive' '--format=zip' \"--output=$evidenceZip\" $evidenceCommit",
            "'bundle' 'create' $historyPath 'refs/heads/main'",
            "releaseManifestSha256",
            "releaseIdentitySha256",
            "pendingManifestSha256",
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
                "releaseIdentitySha256": hashlib.sha256(identity_path.read_bytes()).hexdigest(),
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
