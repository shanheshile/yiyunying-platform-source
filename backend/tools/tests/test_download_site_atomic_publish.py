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
            "channel": "Debug",
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
            "channel": "Debug",
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

    def make_stable(self) -> None:
        stable_signer = "9" * 64
        identity_path = (
            self.repository / "backend" / "config" / "release-identity.json"
        )
        write_json(
            identity_path,
            {
                "version_name": self.version,
                "version_code": self.version_code,
                "stable_signer_sha256": stable_signer,
            },
        )
        self.identity_sha = digest(identity_path)
        self.tag = f"v{self.version}"
        self.manifest["channel"] = "Stable"
        self.manifest["releaseTag"] = self.tag
        self.manifest["releaseIdentitySha256"] = self.identity_sha

        renamed: list[str] = []
        for entry in self.manifest["releases"]:
            old_path = self.release_dir / entry["fileName"]
            entry["fileName"] = entry["fileName"].replace("-debug.apk", ".apk")
            entry["packageName"] = entry["packageName"].removesuffix(".debug")
            entry["versionName"] = entry["versionName"].removesuffix("-debug")
            entry["signerSha256"] = stable_signer
            new_path = self.release_dir / entry["fileName"]
            old_path.rename(new_path)
            entry["sizeBytes"] = new_path.stat().st_size
            entry["sha256"] = digest(new_path)
            renamed.append(entry["fileName"])
        self.apk_names = renamed
        self.rewrite_manifest()

        self.project_manifest["channel"] = "Stable"
        self.project_manifest["releaseTag"] = self.tag
        self.project_manifest["releaseIdentitySha256"] = self.identity_sha
        self.project_manifest["releaseManifestSha256"] = digest(self.manifest_path)
        self.project_manifest["bundleRefs"] = [
            "refs/heads/main",
            f"refs/tags/{self.tag}",
        ]
        self.rewrite_project_manifest()

        sums = "".join(
            f"{entry['sha256'].upper()}  {entry['fileName']}\n"
            for entry in self.manifest["releases"]
        )
        (self.release_dir / "SHA256SUMS.txt").write_text(
            sums, encoding="utf-8", newline="\n"
        )

        self.metadata = copy.deepcopy(self.manifest)
        self.metadata["finalizationStatus"] = "pending"
        self.metadata["releaseEvidenceCommit"] = None
        self.metadata.pop("finalizedAt")
        self.metadata["pendingManifestSha256"] = "5" * 64
        self.rewrite_metadata()

    def write_stable_site(self) -> None:
        files = {
            "index.html": (
                f'<!doctype html><link rel="stylesheet" '
                f'href="/download-center/assets/site.css">'
                f'<link rel="manifest" '
                f'href="/download-center/site.webmanifest">'
                f'<img src="/download-center/logo.svg">{self.version}'
            ),
            "site.js": "document.documentElement.dataset.ready='true';",
            "docs.js": "document.documentElement.dataset.docs='true';",
            "logo.svg": "<svg xmlns=\"http://www.w3.org/2000/svg\"></svg>",
            "api-docs/index.html": '<a href="/download-center/">home</a>',
            "privacy/index.html": '<a href="/download-center/">home</a>',
            "terms/index.html": '<a href="/download-center/">home</a>',
            "assets/site.css": "body{color:#123}",
        }
        for relative, text in files.items():
            path = self.site_dir / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(text, encoding="utf-8", newline="\n")
        write_json(
            self.site_dir / "site.webmanifest",
            {
                "name": "fixture",
                "icons": [
                    {
                        "src": "/download-center/logo.svg",
                        "sizes": "any",
                        "type": "image/svg+xml",
                    }
                ],
            },
        )
        (self.site_dir / "og-card.png").write_bytes(
            b"\x89PNG\r\n\x1a\n"
            + b"\x00\x00\x00\x0dIHDR"
            + (1200).to_bytes(4, "big")
            + (630).to_bytes(4, "big")
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

    def test_complete_finalized_debug_release_is_rejected_from_public_deploy(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "only accepts Stable"):
            self.load()

    def test_debug_transport_is_rejected_even_with_legacy_confirmation(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "only accepts Stable"):
            MODULE.validate_public_transport(self.fixture.manifest, False, "")
        insecure = copy.deepcopy(self.fixture.manifest)
        insecure["connectionIdentity"]["apiBaseUrl"] = "http://downloads.example.test/"
        with self.assertRaisesRegex(RuntimeError, "only accepts Stable"):
            MODULE.validate_public_transport(insecure, False, "")
        with self.assertRaisesRegex(RuntimeError, "only accepts Stable"):
            MODULE.validate_public_transport(insecure, True, "wrong-confirmation")
        with self.assertRaisesRegex(RuntimeError, "only accepts Stable"):
            MODULE.validate_public_transport(
                insecure, True, MODULE.DEBUG_HTTP_CONFIRMATION
            )
    def test_stable_publishes_all_four_clients_with_https_and_bound_signer(self) -> None:
        self.fixture.make_stable()
        artifacts, manifest = self.load()
        self.assertEqual(manifest["channel"], "Stable")
        self.assertEqual(manifest["releaseTag"], f"v{self.fixture.version}")
        self.assertEqual(
            {item.name for item in artifacts},
            {
                f"yiyunying-user-v{self.fixture.version}.apk",
                f"yiyunying-admin-v{self.fixture.version}.apk",
                f"yiyunying-authorized-v{self.fixture.version}.apk",
                f"yiyunying-owner-v{self.fixture.version}.apk",
            },
        )
        self.assertFalse(
            any(
                marker in item.name
                for item in artifacts
                for marker in (
                    "source",
                    "git-history",
                    "delivery",
                    "manifest",
                )
            )
        )

        insecure = copy.deepcopy(self.fixture.manifest)
        insecure["connectionIdentity"]["apiBaseUrl"] = (
            "http://downloads.example.test/"
        )
        with self.assertRaisesRegex(RuntimeError, "Stable.*HTTPS"):
            MODULE.validate_public_transport(insecure, False, "")

        self.fixture.manifest["releases"][0]["signerSha256"] = "8" * 64
        self.fixture.metadata["releases"][0]["signerSha256"] = "8" * 64
        self.fixture.rewrite_manifest()
        self.fixture.rewrite_metadata()
        with self.assertRaisesRegex(RuntimeError, "one signer|stable_signer"):
            self.load()

    def test_stable_site_uses_an_explicit_minimum_public_whitelist(self) -> None:
        self.fixture.make_stable()
        self.fixture.write_stable_site()
        files = MODULE.validate_site_tree(
            self.fixture.site_dir,
            self.fixture.version,
            channel="Stable",
            manifest=self.fixture.manifest,
        )
        relative = {item.relative for item in files}
        self.assertTrue(MODULE.STABLE_SITE_FILES.issubset(relative))
        self.assertIn("assets/site.css", relative)
        self.assertFalse(any(path.endswith(".apk") for path in relative))

        forbidden = (
            self.fixture.site_dir
            / f"yiyunying-authorized-platform-v{self.fixture.version}.apk"
        )
        forbidden.write_bytes(b"private")
        with self.assertRaisesRegex(RuntimeError, "forbidden public file"):
            MODULE.validate_site_tree(
                self.fixture.site_dir,
                self.fixture.version,
                channel="Stable",
                manifest=self.fixture.manifest,
            )

    def test_stable_site_rejects_private_metadata_even_without_private_files(self) -> None:
        self.fixture.make_stable()
        self.fixture.write_stable_site()
        (self.fixture.site_dir / "site.js").write_text(
            self.fixture.manifest["projectAssets"][0]["fileName"],
            encoding="utf-8",
        )
        with self.assertRaisesRegex(RuntimeError, "non-public release metadata"):
            MODULE.validate_site_tree(
                self.fixture.site_dir,
                self.fixture.version,
                channel="Stable",
                manifest=self.fixture.manifest,
            )

    def test_final_manifest_pending_metadata_and_public_site_close_one_loop(self) -> None:
        self.fixture.make_stable()
        self.fixture.write_stable_site()
        artifacts, manifest = self.load()
        site_files = MODULE.validate_site_tree(
            self.fixture.site_dir,
            self.fixture.version,
            channel="Stable",
            manifest=manifest,
        )
        self.assertEqual(len(artifacts), 4)
        self.assertEqual(
            {artifact.name for artifact in artifacts},
            set(self.fixture.apk_names),
        )
        self.assertIn("index.html", {item.relative for item in site_files})
        self.assertIn("site.js", {item.relative for item in site_files})
        self.assertIn("docs.js", {item.relative for item in site_files})
        self.assertEqual(self.fixture.metadata["finalizationStatus"], "pending")
        self.assertEqual(manifest["finalizationStatus"], "finalized")

    def test_pending_or_same_commit_manifest_is_rejected(self) -> None:
        self.fixture.make_stable()
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
        self.fixture.make_stable()
        self.fixture.metadata["releases"][0]["sha256"] = "6" * 64
        self.fixture.rewrite_metadata()
        with self.assertRaisesRegex(RuntimeError, "exactly match"):
            self.load()

    def test_connection_identity_and_identity_bytes_are_bound(self) -> None:
        self.fixture.make_stable()
        self.fixture.metadata["connectionIdentity"]["appKeySha256"] = "7" * 64
        self.fixture.rewrite_metadata()
        with self.assertRaisesRegex(RuntimeError, "connectionIdentity"):
            self.load()

        self.fixture = ReleaseFixture(Path(self.temporary.name) / "second")
        self.fixture.make_stable()
        identity_path = (
            self.fixture.repository / "backend" / "config" / "release-identity.json"
        )
        identity_path.write_bytes(identity_path.read_bytes() + b" ")
        with self.assertRaisesRegex(RuntimeError, "identity bytes"):
            self.load()

    def test_project_manifest_binds_final_manifest_and_all_three_assets(self) -> None:
        self.fixture.make_stable()
        self.fixture.project_manifest["releaseManifestSha256"] = "8" * 64
        self.fixture.rewrite_project_manifest()
        with self.assertRaisesRegex(RuntimeError, "releaseManifestSha256"):
            self.load()

        self.fixture = ReleaseFixture(Path(self.temporary.name) / "second")
        self.fixture.make_stable()
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
