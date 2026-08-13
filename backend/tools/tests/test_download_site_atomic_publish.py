#!/usr/bin/env python3
"""Offline regression tests for atomic static download-center publication."""

from __future__ import annotations

import copy
import hashlib
import importlib.util
import json
from pathlib import Path
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
SCRIPT = ROOT / "download-site" / "scripts" / "deploy-static.py"
SPEC = importlib.util.spec_from_file_location("download_site_atomic_publish", SCRIPT)
assert SPEC and SPEC.loader
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def write_json(path: Path, value: dict) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


class ReleaseFixture:
    version = "9.8.7"
    version_code = 987
    build_commit = "a" * 40
    evidence_commit = "b" * 40
    tag = "v9.8.7-debug"

    def __init__(self, root: Path) -> None:
        self.repository = root / "repo"
        self.release_dir = self.repository / "releases" / self.version
        self.site_dir = self.repository / "download-site" / "static-dist"
        identity_dir = self.repository / "backend" / "config"
        identity_dir.mkdir(parents=True)
        self.release_dir.mkdir(parents=True)
        self.site_dir.mkdir(parents=True)
        identity_path = identity_dir / "release-identity.json"
        identity_path.write_bytes(b'{"version_name":"9.8.7","version_code":987}\n')
        self.identity_sha = digest(identity_path)
        self.connection_identity = {
            "apiBaseUrl": "https://downloads.example.test/",
            "appKeySha256": "1" * 64,
            "platformKeySha256": "2" * 64,
            "authorizedPlatformKeySha256": "3" * 64,
        }

        release_ids = ("owner", "authorized", "admin", "user")
        releases: list[dict] = []
        self.apk_names: list[str] = []
        for position, release_id in enumerate(release_ids, start=1):
            name = f"yiyunying-{release_id}-v{self.version}-debug.apk"
            path = self.release_dir / name
            path.write_bytes((release_id.encode("ascii") + b"-") * (position + 2))
            self.apk_names.append(name)
            releases.append(
                {
                    "id": release_id,
                    "fileName": name,
                    "packageName": f"example.{release_id}.debug",
                    "versionName": f"{self.version}-{release_id}-debug",
                    "versionCode": self.version_code,
                    "sizeBytes": path.stat().st_size,
                    "sha256": digest(path),
                    "signerSha256": "4" * 64,
                }
            )

        self.project_names = {
            "source": f"yiyunying-source-v{self.version}.zip",
            "history": f"yiyunying-git-history-v{self.version}.bundle",
            "delivery": f"yiyunying-project-delivery-v{self.version}.zip",
            "manifest": "project-assets-manifest.json",
        }
        project_assets = [
            {"id": asset_id, "fileName": name}
            for asset_id, name in self.project_names.items()
        ]
        self.manifest = {
            "schemaVersion": 4,
            "versionName": self.version,
            "versionCode": self.version_code,
            "buildSourceCommit": self.build_commit,
            "releaseEvidenceCommit": self.evidence_commit,
            "releaseTag": self.tag,
            "finalizationStatus": "finalized",
            "finalizedAt": "2026-08-13T00:00:00Z",
            "releaseIdentitySha256": self.identity_sha,
            "connectionIdentity": self.connection_identity,
            "releaseDate": "2026-08-13",
            "generatedAt": "2026-08-13T00:00:00Z",
            "downloadRootBase": "/downloads",
            "releaseNotes": ["offline fixture"],
            "releases": releases,
            "projectAssets": project_assets,
        }
        self.manifest_path = self.release_dir / "release-manifest.json"
        write_json(self.manifest_path, self.manifest)

        asset_checks: list[dict] = []
        for position, asset_id in enumerate(("source", "history", "delivery"), start=1):
            name = self.project_names[asset_id]
            path = self.release_dir / name
            path.write_bytes((asset_id.encode("ascii") + b"-") * (position + 3))
            asset_checks.append(
                {
                    "fileName": name,
                    "sizeBytes": path.stat().st_size,
                    "sha256": digest(path),
                }
            )
        self.project_manifest = {
            "schemaVersion": 3,
            "versionName": self.version,
            "versionCode": self.version_code,
            "buildSourceCommit": self.build_commit,
            "releaseEvidenceCommit": self.evidence_commit,
            "releaseTag": self.tag,
            "releaseIdentitySha256": self.identity_sha,
            "connectionIdentity": self.connection_identity,
            "releaseManifestSha256": digest(self.manifest_path),
            "bundleRefs": ["refs/heads/main", f"refs/tags/{self.tag}"],
            "security": {
                "containsCredentials": False,
                "containsSigningKeys": False,
                "containsProductionData": False,
            },
            "assets": asset_checks,
        }
        self.project_manifest_path = self.release_dir / "project-assets-manifest.json"
        write_json(self.project_manifest_path, self.project_manifest)

        sums = "".join(
            f"{entry['sha256'].upper()}  {entry['fileName']}\n" for entry in releases
        )
        (self.release_dir / "SHA256SUMS.txt").write_text(
            sums, encoding="utf-8", newline="\n"
        )

        self.metadata = copy.deepcopy(self.manifest)
        self.metadata["finalizationStatus"] = "pending"
        self.metadata["releaseEvidenceCommit"] = None
        self.metadata.pop("finalizedAt")
        self.metadata["pendingManifestSha256"] = "5" * 64
        self.metadata_path = self.site_dir.parent / "release-metadata.json"
        write_json(self.metadata_path, self.metadata)
        (self.site_dir / "index.html").write_text(
            f"<!doctype html><title>{self.version}</title>\n",
            encoding="utf-8",
            newline="\n",
        )

    def rewrite_manifest(self) -> None:
        write_json(self.manifest_path, self.manifest)

    def rewrite_metadata(self) -> None:
        write_json(self.metadata_path, self.metadata)

    def rewrite_project_manifest(self) -> None:
        write_json(self.project_manifest_path, self.project_manifest)

class DownloadSiteAtomicPublishTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.fixture = ReleaseFixture(Path(self.temporary.name))

    def load(self):
        return MODULE.load_release_files(
            self.fixture.release_dir,
            self.fixture.version,
            self.fixture.repository,
        )

    def test_complete_finalized_release_closes_eight_artifact_loop(self) -> None:
        artifacts, manifest = self.load()
        self.assertEqual(len(artifacts), 8)
        self.assertEqual(len({item.name for item in artifacts}), 8)
        self.assertEqual(manifest["releaseEvidenceCommit"], "b" * 40)
        self.assertEqual(
            {item.name for item in artifacts[:4]}, set(self.fixture.apk_names)
        )
        self.assertEqual(
            {item.name for item in artifacts[4:]},
            set(self.fixture.project_names.values()),
        )

    def test_http_requires_explicit_debug_only_dual_confirmation(self) -> None:
        MODULE.validate_public_transport(self.fixture.manifest, False, "")
        insecure = copy.deepcopy(self.fixture.manifest)
        insecure["connectionIdentity"]["apiBaseUrl"] = "http://downloads.example.test/"
        with self.assertRaisesRegex(RuntimeError, "dual confirmation"):
            MODULE.validate_public_transport(insecure, False, "")
        with self.assertRaisesRegex(RuntimeError, "dual confirmation"):
            MODULE.validate_public_transport(insecure, True, "wrong-confirmation")
        MODULE.validate_public_transport(
            insecure, True, MODULE.DEBUG_HTTP_CONFIRMATION
        )
        insecure["releases"][0]["packageName"] = "example.owner"
        with self.assertRaisesRegex(RuntimeError, "non-production Debug"):
            MODULE.validate_public_transport(
                insecure, True, MODULE.DEBUG_HTTP_CONFIRMATION
            )
    def test_pending_or_same_commit_manifest_is_rejected(self) -> None:
        self.fixture.manifest["finalizationStatus"] = "pending"
        self.fixture.rewrite_manifest()
        with self.assertRaisesRegex(RuntimeError, "finalized"):
            self.load()

        self.fixture.manifest["finalizationStatus"] = "finalized"
        self.fixture.manifest["releaseEvidenceCommit"] = self.fixture.build_commit
        self.fixture.rewrite_manifest()
        with self.assertRaisesRegex(RuntimeError, "must be distinct"):
            self.load()

    def test_metadata_must_exactly_match_four_apk_manifest_entries(self) -> None:
        self.fixture.metadata["releases"][0]["sha256"] = "6" * 64
        self.fixture.rewrite_metadata()
        with self.assertRaisesRegex(RuntimeError, "exactly match"):
            self.load()

    def test_connection_identity_and_identity_bytes_are_bound(self) -> None:
        self.fixture.metadata["connectionIdentity"]["appKeySha256"] = "7" * 64
        self.fixture.rewrite_metadata()
        with self.assertRaisesRegex(RuntimeError, "connectionIdentity"):
            self.load()

        self.fixture = ReleaseFixture(Path(self.temporary.name) / "second")
        identity_path = (
            self.fixture.repository / "backend" / "config" / "release-identity.json"
        )
        identity_path.write_bytes(identity_path.read_bytes() + b" ")
        with self.assertRaisesRegex(RuntimeError, "identity bytes"):
            self.load()

    def test_project_manifest_binds_final_manifest_and_all_three_assets(self) -> None:
        self.fixture.project_manifest["releaseManifestSha256"] = "8" * 64
        self.fixture.rewrite_project_manifest()
        with self.assertRaisesRegex(RuntimeError, "releaseManifestSha256"):
            self.load()

        self.fixture = ReleaseFixture(Path(self.temporary.name) / "second")
        self.fixture.project_manifest["assets"].pop()
        self.fixture.rewrite_project_manifest()
        with self.assertRaisesRegex(RuntimeError, "exactly source"):
            self.load()

    def test_public_acceptance_requires_full_hash_range_and_strong_etag(self) -> None:
        payload = Path(self.temporary.name) / "payload.bin"
        payload.write_bytes(bytes(range(256)) * 300)
        command = MODULE.public_verification_command(
            payload,
            payload.stat().st_size,
            digest(payload),
            "https://downloads.example.test/downloads/9.8.7/payload.bin",
            "/safe/stage/check",
        )
        for required in (
            "--proto '=http,https'",
            "sha256sum",
            "Range: bytes=0-65535",
            "If-Range: $etag",
            "Content-Range:",
            "range_etag",
            "If-None-Match: $etag",
            "not_modified_etag",
            'test "$range_status" = 206',
            'test "$not_modified" = 304',
            "rm -f --",
            'case "$etag" in W/*)',
        ):
            self.assertIn(required, command)

    def test_rollback_moves_both_site_and_stable_release_back_to_candidates(self) -> None:
        command = MODULE.rollback_command(
            "/public/download-center",
            "/public/downloads/9.8.7",
            "/public/.candidate/site",
            "/public/.candidate/release",
            "/public/.previous-token",
            True,
        )
        self.assertIn(
            "mv /public/download-center /public/.candidate/site", command
        )
        self.assertIn(
            "mv /public/.previous-token /public/download-center", command
        )
        self.assertIn(
            "mv /public/downloads/9.8.7 /public/.candidate/release", command
        )

    def test_remote_root_guard_rejects_broad_or_non_normalized_targets(self) -> None:
        for value in ("/", "/www", "relative/path", "/www/site/../public"):
            with self.subTest(value=value):
                with self.assertRaises(RuntimeError):
                    MODULE.validate_remote_root(value)
        self.assertEqual(
            MODULE.validate_remote_root("/www/wwwroot/example/public"),
            "/www/wwwroot/example/public",
        )

    def test_static_contract_uses_pinned_host_atomic_rename_and_immutable_version(self) -> None:
        source = SCRIPT.read_text(encoding="utf-8")
        for required in (
            'parser.add_argument("--known-hosts", required=True)',
            "ssh.load_host_keys(str(known_hosts))",
            "paramiko.RejectPolicy()",
            "secrets.token_hex(16)",
            'f"test ! -e {quote(remote_release)}',
            'f"mv {quote(staging_release)} {quote(remote_release)}"',
            'f"mv {quote(staging_site)} {quote(remote_site)}"',
            'stat -c %d {quote(staging_root)}',
            "validate_git_release_evidence(repository_root, manifest)",
            'manifest.get("downloadRootBase") != "/downloads"',
            'expected_site_dir = (repository_root / "download-site" / "static-dist").resolve()',
            'expected_release_dir = (repository_root / "releases" / args.version).resolve()',
            "FILENAME_RE.fullmatch(name)",
            'repository_root / "download-site" / "release-metadata.json"',
            '"Tracked B-commit metadata"',
            "transport.is_active()",
            "elif lock_acquired:",
            "CLEANUP INCOMPLETE; deployment lock retained",
            '"rm -rf -- " + " ".join(quote(path) for path in cleanup)',
        ):
            self.assertIn(required, source)
        for forbidden in (
            "paramiko.AutoAddPolicy()",
            "cp -f",
            'time.strftime("%Y%m%d-%H%M%S")',
        ):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
