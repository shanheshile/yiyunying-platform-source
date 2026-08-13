#!/usr/bin/env python3
"""End-to-end tests for the loopback-only internal APK download server."""

from __future__ import annotations

import copy
import hashlib
import http.client
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import threading
import unittest


ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "download-site" / "scripts" / "serve-internal-downloads.py"
SPEC = importlib.util.spec_from_file_location("internal_download_server", SCRIPT)
assert SPEC and SPEC.loader
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)

CANDIDATE_VERSION_CODE = 64


class Fixture:
    def __init__(self, root: Path) -> None:
        self.root = root
        self.manifest_path = root / "release-manifest.json"
        self.payloads: dict[str, bytes] = {}
        releases = []
        for position, role in enumerate(MODULE.ROLE_ORDER, start=1):
            payload = (f"{role}-verified-apk-".encode("ascii")) * (position + 2)
            file_name = f"yiyunying-{MODULE.ROLE_FILE_STEMS[role]}-v1.0.0.apk"
            (root / file_name).write_bytes(payload)
            self.payloads[role] = payload
            releases.append(
                {
                    "id": role,
                    "fileName": file_name,
                    "versionName": f"1.0.0-{MODULE.ROLE_VERSION_SUFFIXES[role]}",
                    "packageName": MODULE.STABLE_PACKAGE_NAMES[role],
                    "versionCode": CANDIDATE_VERSION_CODE,
                    "sizeBytes": len(payload),
                    "sha256": hashlib.sha256(payload).hexdigest().upper(),
                }
            )
        self.manifest = {
            "schemaVersion": 4,
            "channel": "Stable",
            "versionName": "1.0.0",
            "versionCode": CANDIDATE_VERSION_CODE,
            "finalizationStatus": "pending",
            "releases": releases,
            "projectAssets": [
                {"id": "source", "fileName": "private-source.zip"},
                {"id": "history", "fileName": "private-history.bundle"},
                {"id": "delivery", "fileName": "private-delivery.zip"},
                {"id": "manifest", "fileName": "project-assets-manifest.json"},
            ],
            "platformKey": "MUST_NOT_BE_READ_OR_RENDERED",
            "appKey": "MUST_NOT_BE_READ_OR_RENDERED",
        }
        for asset in self.manifest["projectAssets"]:
            (root / asset["fileName"]).write_bytes(b"private")
        self.write_manifest()

    def write_manifest(self, manifest: dict | None = None) -> None:
        self.manifest_path.write_text(
            json.dumps(manifest or self.manifest, ensure_ascii=False),
            encoding="utf-8",
        )


class RunningServer:
    def __init__(self, catalog: object) -> None:
        self.server = MODULE.create_server(catalog)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)

    def __enter__(self):
        self.thread.start()
        return self

    def __exit__(self, exc_type, exc, traceback) -> None:
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=3)

    def request(self, method: str, path: str, headers: dict[str, str] | None = None):
        connection = http.client.HTTPConnection(
            "127.0.0.1", self.server.server_address[1], timeout=5
        )
        connection.request(method, path, headers=headers or {})
        response = connection.getresponse()
        body = response.read()
        result = response.status, dict(response.getheaders()), body
        connection.close()
        return result


class InternalDownloadServerTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.fixture = Fixture(self.root)

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def test_page_and_four_downloads_close_the_local_loop(self) -> None:
        catalog = MODULE.load_catalog(self.fixture.manifest_path)
        with RunningServer(catalog) as running:
            prefix = running.server.session_prefix
            status, headers, body = running.request("GET", prefix)
            page = body.decode("utf-8")
            self.assertEqual(status, 200)
            self.assertIn("Release candidate（待完成发布）", page)
            self.assertIn(
                f"版本 1.0.0 · versionCode {CANDIDATE_VERSION_CODE}", page
            )
            self.assertIn("覆盖升级", page)
            self.assertEqual(headers["Cache-Control"], "no-store, max-age=0")
            self.assertIn("noindex", headers["X-Robots-Tag"])
            self.assertEqual(headers["X-Content-Type-Options"], "nosniff")
            self.assertIn("default-src 'none'", headers["Content-Security-Policy"])
            for private_name in (
                "private-source.zip",
                "private-history.bundle",
                "private-delivery.zip",
                "project-assets-manifest.json",
                "MUST_NOT_BE_READ_OR_RENDERED",
            ):
                self.assertNotIn(private_name, page)

            for role in MODULE.ROLE_ORDER:
                status, apk_headers, apk = running.request("GET", prefix + "apk/" + role)
                self.assertEqual(status, 200)
                self.assertEqual(apk, self.fixture.payloads[role])
                self.assertEqual(apk_headers["Accept-Ranges"], "bytes")
                self.assertEqual(
                    apk_headers["Content-Type"],
                    "application/vnd.android.package-archive",
                )
                self.assertTrue(apk_headers["Content-Disposition"].startswith("attachment;"))
                self.assertRegex(apk_headers["ETag"], r'^"sha256-[0-9a-f]{64}"$')

    def test_head_range_etag_and_unsatisfied_range(self) -> None:
        catalog = MODULE.load_catalog(self.fixture.manifest_path)
        payload = self.fixture.payloads["user"]
        with RunningServer(catalog) as running:
            path = running.server.session_prefix + "apk/user"
            status, headers, body = running.request("HEAD", path)
            self.assertEqual((status, body), (200, b""))
            self.assertEqual(int(headers["Content-Length"]), len(payload))

            status, range_headers, body = running.request(
                "GET", path, {"Range": "bytes=2-8"}
            )
            self.assertEqual(status, 206)
            self.assertEqual(body, payload[2:9])
            self.assertEqual(range_headers["Content-Range"], f"bytes 2-8/{len(payload)}")

            status, _, body = running.request(
                "GET", path, {"If-None-Match": headers["ETag"]}
            )
            self.assertEqual((status, body), (304, b""))

            status, invalid_headers, body = running.request(
                "GET", path, {"Range": f"bytes={len(payload)}-"}
            )
            self.assertEqual((status, body), (416, b""))
            self.assertEqual(invalid_headers["Content-Range"], f"bytes */{len(payload)}")

    def test_random_session_and_invalid_paths_fail_closed(self) -> None:
        catalog = MODULE.load_catalog(self.fixture.manifest_path)
        first = MODULE.create_server(catalog)
        second = MODULE.create_server(catalog)
        try:
            self.assertNotEqual(first.session_token, second.session_token)
        finally:
            first.server_close()
            second.server_close()

        with RunningServer(catalog) as running:
            prefix = running.server.session_prefix
            for path in (
                "/",
                "/release-manifest.json",
                "/private-source.zip",
                prefix + "apk/source",
                prefix + "apk/user/../../release-manifest.json",
                prefix + "apk/user.apk",
                prefix + "../",
            ):
                with self.subTest(path=path):
                    status, _, body = running.request("GET", path)
                    self.assertEqual((status, body), (404, b""))
            status, headers, body = running.request("POST", prefix)
            self.assertEqual((status, body), (405, b""))
            self.assertEqual(headers["Allow"], "GET, HEAD")

    def test_manifest_path_type_and_hash_mismatch_fail_closed(self) -> None:
        traversal = copy.deepcopy(self.fixture.manifest)
        traversal["releases"][0]["fileName"] = "../outside.apk"
        self.fixture.write_manifest(traversal)
        with self.assertRaisesRegex(RuntimeError, "safe APK basename"):
            MODULE.load_catalog(self.fixture.manifest_path)

        non_apk = copy.deepcopy(self.fixture.manifest)
        non_apk["releases"][0]["fileName"] = "private-source.zip"
        self.fixture.write_manifest(non_apk)
        with self.assertRaisesRegex(RuntimeError, "safe APK basename"):
            MODULE.load_catalog(self.fixture.manifest_path)

        mismatch = copy.deepcopy(self.fixture.manifest)
        mismatch["releases"][0]["sha256"] = "0" * 64
        self.fixture.write_manifest(mismatch)
        with self.assertRaisesRegex(RuntimeError, "does not match"):
            MODULE.load_catalog(self.fixture.manifest_path)

        wrong_role = copy.deepcopy(self.fixture.manifest)
        wrong_role["releases"][0]["fileName"] = "yiyunying-user-v9.9.9.apk"
        (self.root / "yiyunying-user-v9.9.9.apk").write_bytes(
            self.fixture.payloads["user"]
        )
        self.fixture.write_manifest(wrong_role)
        with self.assertRaisesRegex(RuntimeError, "role/version identity"):
            MODULE.load_catalog(self.fixture.manifest_path)

        wrong_package = copy.deepcopy(self.fixture.manifest)
        wrong_package["releases"][0]["packageName"] = "example.wrong"
        self.fixture.write_manifest(wrong_package)
        with self.assertRaisesRegex(RuntimeError, "packageName"):
            MODULE.load_catalog(self.fixture.manifest_path)

        missing_entry_code = copy.deepcopy(self.fixture.manifest)
        missing_entry_code["releases"][0].pop("versionCode")
        self.fixture.write_manifest(missing_entry_code)
        with self.assertRaisesRegex(RuntimeError, "versionCode"):
            MODULE.load_catalog(self.fixture.manifest_path)

    def test_non_loopback_bind_and_runtime_tampering_fail_closed(self) -> None:
        self.fixture.write_manifest()
        catalog = MODULE.load_catalog(self.fixture.manifest_path)
        for host in ("0.0.0.0", "localhost", "192.168.1.20", "::"):
            with self.subTest(host=host):
                with self.assertRaisesRegex(RuntimeError, "only to 127.0.0.1 or ::1"):
                    MODULE.create_server(catalog, host=host)

        with RunningServer(catalog) as running:
            user_path = self.root / self.fixture.manifest["releases"][0]["fileName"]
            user_path.write_bytes(b"changed-after-validation")
            status, _, body = running.request(
                "GET", running.server.session_prefix + "apk/user"
            )
            self.assertEqual((status, body), (409, b""))

    def test_debug_candidate_and_finalized_labels_are_distinct(self) -> None:
        self.assertEqual(
            MODULE.publication_label("Debug", "pending"), "Debug 非生产测试版"
        )
        self.assertEqual(
            MODULE.publication_label("Stable", "pending"),
            "Release candidate（待完成发布）",
        )
        self.assertEqual(
            MODULE.publication_label("Stable", "finalized"),
            "Stable 正式版（已 Finalize）",
        )

    def test_exact_legacy_debug_manifest_is_inferred_without_becoming_stable(self) -> None:
        debug = copy.deepcopy(self.fixture.manifest)
        debug.pop("channel")
        debug.pop("finalizationStatus")
        for entry in debug["releases"]:
            role = entry["id"]
            old_path = self.root / entry["fileName"]
            entry["fileName"] = (
                f"yiyunying-{MODULE.ROLE_FILE_STEMS[role]}-v1.0.0-debug.apk"
            )
            entry["versionName"] = (
                f"1.0.0-{MODULE.ROLE_VERSION_SUFFIXES[role]}-debug"
            )
            entry["packageName"] = MODULE.STABLE_PACKAGE_NAMES[role] + ".debug"
            (self.root / entry["fileName"]).write_bytes(old_path.read_bytes())
        self.fixture.write_manifest(debug)
        catalog = MODULE.load_catalog(self.fixture.manifest_path)
        self.assertEqual((catalog.channel, catalog.finalization_status), ("Debug", "finalized"))
        self.assertEqual(catalog.status_label, "Debug 非生产测试版")


if __name__ == "__main__":
    unittest.main()
