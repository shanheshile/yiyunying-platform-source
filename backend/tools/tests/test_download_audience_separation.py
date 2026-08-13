#!/usr/bin/env python3
"""Offline contracts separating the public Stable site from internal assets and Debug."""

from __future__ import annotations

import importlib.util
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
DEPLOY_SCRIPT = ROOT / "download-site" / "scripts" / "deploy-static.py"
EXPORT_SCRIPT = ROOT / "download-site" / "scripts" / "export-static.mjs"
PROJECTION_SCRIPT = ROOT / "download-site" / "scripts" / "public-release-projection.mjs"
NGINX_CONFIG = ROOT / "download-site" / "deploy" / "nginx-download-center.conf"
SPEC = importlib.util.spec_from_file_location(
    "download_audience_deploy_static", DEPLOY_SCRIPT
)
assert SPEC and SPEC.loader
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class DownloadAudienceSeparationTest(unittest.TestCase):
    def test_export_rebuilds_only_the_customer_site_routes(self) -> None:
        source = EXPORT_SCRIPT.read_text(encoding="utf-8")

        self.assertIn('const DEFAULT_OUTPUT_DIR = new URL("../static-dist/", import.meta.url)', source)
        self.assertLess(
            source.index("await rm(OUTPUT_DIR, { recursive: true, force: true })"),
            source.index("await cp(CLIENT_DIR, OUTPUT_DIR"),
        )
        self.assertIn(
            'for (const pathname of ["/api-docs/", "/privacy/", "/terms/"])',
            source,
        )
        projection = PROJECTION_SCRIPT.read_text(encoding="utf-8")
        self.assertIn('const ROLE_ORDER = ["user", "admin", "authorized", "owner"]', projection)
        self.assertIn("loadPublicReleaseProjection", source)
        self.assertIn("PUBLIC_RELEASE_PROJECTION_KEY", source)
        self.assertIn("finally {", source)
        self.assertIn("delete globalThis[PUBLIC_RELEASE_PROJECTION_KEY]", source)
        for internal_route in (
            '"/internal/"',
            '"/dashboard/"',
            '"/console/"',
            '"/release-admin/"',
        ):
            self.assertNotIn(internal_route, source)
        self.assertNotIn("releaseMetadata.projectAssets", source)
        self.assertIn("const pendingBrowserScript", source)
        self.assertIn(
            "const browserScript = isFormalRelease\n  ? formalBrowserScript\n  : pendingBrowserScript;",
            source,
        )

    def test_stable_site_allowlist_excludes_internal_and_release_assets(self) -> None:
        self.assertEqual(
            MODULE.PUBLIC_RELEASE_IDS,
            {"user", "admin", "authorized", "owner"},
        )
        self.assertEqual(
            MODULE.STABLE_SITE_FILES,
            {
                "index.html",
                "site.js",
                "docs.js",
                "site.webmanifest",
                "logo.svg",
                "og-card.png",
                "api-docs/index.html",
                "privacy/index.html",
                "terms/index.html",
            },
        )
        for relative in (
            "internal/index.html",
            "dashboard/index.html",
            "release-manifest.json",
            "project-assets-manifest.json",
            "yiyunying-source-v1.0.0.zip",
            "yiyunying-git-history-v1.0.0.bundle",
            "yiyunying-project-delivery-v1.0.0.zip",
            "yiyunying-user-v1.0.0.apk",
        ):
            with self.subTest(relative=relative):
                self.assertFalse(MODULE.stable_site_file_allowed(relative))

    def test_pending_manifest_cannot_enter_public_deployment(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            repository = Path(temporary)
            release_dir = repository / "releases" / "1.0.0"
            release_dir.mkdir(parents=True)
            (release_dir / "release-manifest.json").write_text(
                '{"schemaVersion":4,"channel":"Stable",'
                '"versionName":"1.0.0","versionCode":63,'
                '"finalizationStatus":"pending","downloadRootBase":"/downloads"}\n',
                encoding="utf-8",
            )

            with self.assertRaisesRegex(RuntimeError, "must be finalized"):
                MODULE.load_release_files(release_dir, "1.0.0", repository)

    def test_stable_and_debug_transport_cannot_be_mixed(self) -> None:
        debug_releases = [
            {
                "packageName": f"example.role{position}.debug",
                "versionName": "1.0.0-debug",
                "fileName": f"role{position}-debug.apk",
            }
            for position in range(4)
        ]
        stable_with_debug = {
            "channel": "Stable",
            "connectionIdentity": {"apiBaseUrl": "https://example.test/"},
            "releases": debug_releases,
        }
        with self.assertRaisesRegex(RuntimeError, "may not contain Debug"):
            MODULE.validate_public_transport(stable_with_debug, False, "")

        http_debug = {
            "channel": "Debug",
            "connectionIdentity": {"apiBaseUrl": "http://example.test/"},
            "releases": debug_releases,
        }
        with self.assertRaisesRegex(RuntimeError, "only accepts Stable"):
            MODULE.validate_public_transport(http_debug, False, "")
        with self.assertRaisesRegex(RuntimeError, "only accepts Stable"):
            MODULE.validate_public_transport(
                http_debug,
                True,
                MODULE.DEBUG_HTTP_CONFIRMATION,
            )

    def test_stable_artifact_branch_is_a_four_apk_whitelist(self) -> None:
        source = DEPLOY_SCRIPT.read_text(encoding="utf-8")
        stable_branch = source[source.index("public_artifacts = [", source.index("project_artifacts.append")) :]

        self.assertIn(
            'for release_id in ("user", "admin", "authorized", "owner")',
            stable_branch,
        )
        self.assertIn("return public_artifacts, manifest", stable_branch)
        self.assertNotIn("artifacts = apk_artifacts + project_artifacts", stable_branch)
        for marker in ("source", "bundle", "delivery", "manifest"):
            self.assertIn(f'"{marker}"', stable_branch)

    def test_nginx_allows_only_the_four_exact_stable_apk_names(self) -> None:
        source = NGINX_CONFIG.read_text(encoding="utf-8")
        self.assertIn(
            "yiyunying-(?:user|admin|authorized-platform|platform-owner)-v",
            source,
        )
        self.assertIn("(?<release_version>", source)
        self.assertIn(r"\k<release_version>", source)
        self.assertIn("(?:-[0-9a-f]{24})?", source)
        self.assertIn("Lifecycle policies", source)
        self.assertIn("location = /downloads", source)
        self.assertIn("location /downloads/", source)
        self.assertNotIn("[^/]+\\.apk", source)
        self.assertNotIn("~* ^/downloads", source)
        self.assertNotIn("-debug", source.lower())


if __name__ == "__main__":
    unittest.main()
