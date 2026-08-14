from __future__ import annotations

import argparse
import base64
import hashlib
import hmac
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time
import unittest
import urllib.parse
import zipfile
from unittest import mock


ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "download-site" / "scripts" / "deploy-internal-apks.py"
SPEC = importlib.util.spec_from_file_location("internal_apk_private_deploy", SCRIPT)
assert SPEC and SPEC.loader
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)

TEST_CONNECTION_HASHES = {
    "appKeySha256": "1" * 64,
    "platformKeySha256": "2" * 64,
    "authorizedPlatformKeySha256": "3" * 64,
}


class PrivateDownloadContractTests(unittest.TestCase):
    def test_reviewed_auth_request_template_is_exact_and_secret_free(self) -> None:
        template, verifier = MODULE.validate_deployment_sources(ROOT)
        rendered = MODULE.render_nginx(
            template,
            "/srv/yiyunying-internal-apks",
            "/etc/nginx/private/yiyunying-internal-apks-secret.conf",
            "unix:/tmp/php-cgi-82.sock",
            "2.7.15",
        ).decode("utf-8")

        self.assertNotIn("secure_link", rendered)
        self.assertNotIn("__YY_", rendered)
        self.assertNotIn(MODULE.SECRET_ENV, rendered)
        self.assertEqual(2, rendered.count("auth_request /__internal-apks-auth;"))
        self.assertEqual(2, rendered.count("error_page 401 =404"))
        self.assertEqual(2, rendered.count("error_page 403 =410"))
        self.assertEqual(2, rendered.count("if ($request_method !~ ^(GET|HEAD)$) { return 405; }"))
        self.assertNotIn("limit_except", rendered)
        self.assertIn("fastcgi_pass unix:/tmp/php-cgi-82.sock;", rendered)
        self.assertIn("location = /__internal-apks-auth", rendered)
        self.assertIn("internal;", rendered)
        self.assertIn(
            "fastcgi_param YY_INTERNAL_DEBUG_VERSION 2.7.15;", rendered
        )
        self.assertIn("access_log off;", rendered)
        self.assertIn("max_ranges 1;", rendered)
        self.assertNotIn("location ^~ /__internal-apks/", rendered)
        self.assertRegex(
            rendered,
            r"alias /srv/yiyunying-internal-apks/current/debug/2\.7\.15/\$apk;",
        )
        self.assertRegex(
            rendered,
            r"alias /srv/yiyunying-internal-apks/current/candidate/1\.0\.0/\$apk;",
        )

        php = verifier.path.read_text(encoding="utf-8")
        self.assertIn("hash_hmac('sha256'", php)
        self.assertIn("hex2bin($secretHex)", php)
        self.assertIn("hash_equals", php)
        self.assertNotIn("md5", php.lower())
        self.assertNotIn("$_GET", php)

    def test_reviewed_nginx_binds_one_dynamic_debug_version_and_fails_closed(self) -> None:
        template, _ = MODULE.validate_deployment_sources(ROOT)
        rendered = MODULE.render_nginx(
            template,
            "/srv/yiyunying-internal-apks",
            "/etc/nginx/private/yiyunying-internal-apks-secret.conf",
            "unix:/tmp/php-cgi-82.sock",
            "1.0.0",
        ).decode("utf-8")
        self.assertIn(
            "location ~ ^/__internal-apks/debug/1\\.0\\.0/", rendered
        )
        self.assertIn(
            "alias /srv/yiyunying-internal-apks/current/debug/1.0.0/$apk;",
            rendered,
        )
        self.assertIn(
            "fastcgi_param YY_INTERNAL_DEBUG_VERSION 1.0.0;", rendered
        )
        self.assertNotIn("debug/2\\.7\\.15", rendered)
        self.assertNotIn("__YY_", rendered)
        for unsafe in ("1.0", "1.0.0/../x", "1.0.0;evil", "v1.0.0"):
            with self.subTest(unsafe=unsafe):
                with self.assertRaisesRegex(RuntimeError, "major.minor.patch"):
                    MODULE.render_nginx(
                        template, "/srv/yiyunying-internal-apks", "/etc/nginx/private/yiyunying-internal-apks-secret.conf", "unix:/tmp/php-cgi-82.sock", unsafe
                    )

    def test_signature_is_raw_hmac_sha256_base64url_without_padding(self) -> None:
        secret = "01" * 32
        path = "/__internal-apks/debug/2.7.15/yiyunying-user-v2.7.15-debug.apk"
        expires = 1_800_000_000
        query = MODULE.signed_query(secret, expires, path)
        parsed = dict(item.split("=", 1) for item in query.split("&"))
        expected_raw = hmac.new(
            bytes.fromhex(secret), f"{expires}\n{path}".encode("ascii"), hashlib.sha256
        ).digest()
        expected = base64.urlsafe_b64encode(expected_raw).decode("ascii").rstrip("=")
        self.assertEqual(str(expires), parsed["expires"])
        self.assertEqual(expected, parsed["sig"])
        self.assertRegex(parsed["sig"], r"^[A-Za-z0-9_-]{43}$")
        self.assertNotIn("=", parsed["sig"])

    def test_php_verifier_accepts_valid_and_distinguishes_bad_from_expired(self) -> None:
        if not shutil_which("php"):
            self.skipTest("PHP CLI is unavailable")
        verifier = ROOT / "download-site" / "deploy" / "internal-apk-verifier.php"
        secret = "ab" * 32
        path = "/__internal-apks/candidate/1.0.0/yiyunying-admin-v1.0.0.apk"
        expires = int(time.time()) + 300
        good_query = MODULE.signed_query(secret, expires, path)

        self.assertEqual(204, php_status(verifier, secret, f"{path}?{good_query}"))
        self.assertEqual(
            401,
            php_status(verifier, secret, f"{path}?expires={expires}&sig={'A' * 43}"),
        )
        expired = int(time.time()) - 1
        expired_query = MODULE.signed_query(secret, expired, path)
        self.assertEqual(403, php_status(verifier, secret, f"{path}?{expired_query}"))
        self.assertEqual(
            401,
            php_status(
                verifier,
                secret,
                f"{path}?{good_query}&sig={'B' * 43}",
            ),
        )
        self.assertEqual(
            401,
            php_status(
                verifier,
                secret,
                f"/__internal-apks/candidate/1.0.0/not-allowed.apk?{good_query}",
            ),
        )

    def test_all_eight_real_apks_match_manifest_aapt2_and_apksigner(self) -> None:
        aapt2 = MODULE.resolve_android_tool(ROOT, "aapt2", None)
        apksigner = MODULE.resolve_android_tool(ROOT, "apksigner", None)
        artifacts = MODULE.validate_artifacts(ROOT, aapt2, apksigner)
        identity = MODULE.load_release_identity(ROOT)
        self.assertEqual(8, len(artifacts))
        self.assertEqual(
            {
                ("debug", "2.7.15", 60),
                ("candidate", identity.version_name, identity.version_code),
            },
            {(item.track, item.version, item.version_code) for item in artifacts},
        )
        self.assertGreaterEqual(
            identity.version_code, MODULE.TRACKS["candidate"]["minimum_code"]
        )
        self.assertTrue(
            all(item.package_name.endswith(".debug") for item in artifacts if item.track == "debug")
        )
        self.assertTrue(
            all(not item.package_name.endswith(".debug") for item in artifacts if item.track == "candidate")
        )
        self.assertEqual(4, len({item.role for item in artifacts if item.track == "debug"}))
        self.assertEqual(4, len({item.role for item in artifacts if item.track == "candidate"}))
        self.assertEqual(
            {identity.stable_signer_sha256},
            {
                item.signer_sha256
                for item in artifacts
                if item.track == "candidate"
            },
        )

    def write_release_fixture(
        self,
        root: Path,
        *,
        identity_code: int = 65,
        manifest_code: int = 65,
        candidate_signer: str | None = None,
    ) -> str:
        stable_signer = "CD" * 32
        legacy_signer = "AB" * 32
        legacy_anchor_path = root / MODULE.LEGACY_UPGRADE_IDENTITY_PATH
        legacy_anchor_path.parent.mkdir(parents=True, exist_ok=True)
        legacy_anchor_path.write_text(
            json.dumps(
                {
                    "schemaVersion": 1,
                    "legacyUpgradeMaximumVersionCode": 60,
                    "legacyPackageSignerSha256": legacy_signer,
                    "connectionIdentity": dict(TEST_CONNECTION_HASHES),
                    "packages": {
                        role: base_package + ".debug"
                        for role, (_, _, base_package) in MODULE.ROLES.items()
                    },
                }
            ),
            encoding="utf-8",
        )
        identity_path = root / MODULE.RELEASE_IDENTITY_PATH
        identity_path.parent.mkdir(parents=True, exist_ok=True)
        identity_path.write_text(
            json.dumps(
                {
                    "version_name": "1.0.0",
                    "version_code": identity_code,
                    "stable_signer_sha256": stable_signer,
                }
            ),
            encoding="utf-8",
        )
        for track, policy in MODULE.TRACKS.items():
            manifest_path = root / policy["manifest"]
            manifest_path.parent.mkdir(parents=True, exist_ok=True)
            version_code = 60 if track == "debug" else manifest_code
            releases = []
            for role, (file_stem, version_suffix, base_package) in MODULE.ROLES.items():
                debug_suffix = "-debug" if policy["debug"] else ""
                package_suffix = ".debug" if policy["debug"] else ""
                file_name = (
                    f"yiyunying-{file_stem}-v{policy['version']}{debug_suffix}.apk"
                )
                payload = f"{track}-{role}".encode("ascii")
                (manifest_path.parent / file_name).write_bytes(payload)
                releases.append(
                    {
                        "id": role,
                        "fileName": file_name,
                        "packageName": base_package + package_suffix,
                        "versionName": (
                            f"{policy['version']}-{version_suffix}{debug_suffix}"
                        ),
                        "versionCode": version_code,
                        "signerSha256": (
                            legacy_signer
                            if track == "debug"
                            else candidate_signer or stable_signer
                        ),
                        "sizeBytes": len(payload),
                        "sha256": hashlib.sha256(payload).hexdigest().upper(),
                    }
                )
            manifest = {
                "schemaVersion": 4,
                "versionName": policy["version"],
                "versionCode": version_code,
                "finalizationStatus": policy["status"],
                "releases": releases,
            }
            # Preserve compatibility with the frozen legacy Debug manifest,
            # whose schema predates the explicit channel field.
            if track != "debug":
                manifest["channel"] = policy["channel"]
                manifest["connectionIdentity"] = {
                    "apiBaseUrl": MODULE.PRODUCTION_API_BASE_URL,
                    **TEST_CONNECTION_HASHES,
                }
            else:
                manifest["connectionIdentity"] = {
                    "apiBaseUrl": "http://appht.jjmxg.xyz/",
                    **TEST_CONNECTION_HASHES,
                }
            manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
        return stable_signer

    def write_compatibility_fixture(
        self,
        root: Path,
        *,
        version_code: int = 65,
        signer: str = "AB" * 32,
        api_base_url: str = MODULE.PRODUCTION_API_BASE_URL,
        network_resource: str = "res/8G.xml",
        connection_hashes: dict[str, str] | None = None,
    ) -> Path:
        version = "1.0.0"
        version_file = root / MODULE.ANDROID_VERSION_PROPERTIES_PATH
        version_file.parent.mkdir(parents=True, exist_ok=True)
        version_file.write_text(
            f"VERSION_CODE={version_code}\nVERSION_NAME={version}\n", encoding="utf-8"
        )
        manifest_path = (
            root
            / MODULE.COMPATIBILITY_MANIFEST_ROOT
            / version
            / "release-manifest.json"
        )
        manifest_path.parent.mkdir(parents=True, exist_ok=True)
        releases = []
        for role, (file_stem, version_suffix, base_package) in MODULE.ROLES.items():
            payload = f"compatibility-{role}".encode("ascii")
            file_name = f"yiyunying-{file_stem}-v{version}-debug.apk"
            (manifest_path.parent / file_name).write_bytes(payload)
            releases.append(
                {
                    "id": role,
                    "fileName": file_name,
                    "packageName": base_package + ".debug",
                    "versionName": f"{version}-{version_suffix}-debug",
                    "versionCode": version_code,
                    "signerSha256": signer,
                    "networkSecurityResource": network_resource,
                    "sizeBytes": len(payload),
                    "sha256": hashlib.sha256(payload).hexdigest().upper(),
                }
            )
        manifest = {
            "schemaVersion": 2,
            "channel": MODULE.COMPATIBILITY_CHANNEL,
            "finalizationStatus": MODULE.COMPATIBILITY_STATUS,
            "distribution": "internal-only",
            "versionName": version,
            "versionCode": version_code,
            "buildType": MODULE.COMPATIBILITY_BUILD_TYPE,
            "debuggable": False,
            "testOnly": False,
            "apiBaseUrl": api_base_url,
            "cleartextTrafficPermitted": False,
            "trustAnchors": ["system"],
            "followRedirects": False,
            "apkSignatureSchemeV2": True,
            "signerCount": 1,
            "dexTransportVerified": True,
            "legacyUpgradeMaximumVersionCode": 60,
            "legacyPackageSignerSha256": signer,
            "connectionIdentity": connection_hashes or dict(TEST_CONNECTION_HASHES),
            "releases": releases,
        }
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
        return manifest_path

    def test_matching_identity_65_manifest_65_and_legacy_debug_are_accepted(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            stable_signer = self.write_release_fixture(root)
            with mock.patch.object(MODULE, "validate_apk_with_tools"):
                artifacts = MODULE.validate_artifacts(root, Path("aapt2"), Path("apksigner"))
        self.assertEqual(
            {65},
            {
                artifact.version_code
                for artifact in artifacts
                if artifact.track == "candidate"
            },
        )
        self.assertEqual(
            {stable_signer},
            {
                artifact.signer_sha256
                for artifact in artifacts
                if artifact.track == "candidate"
            },
        )

    def test_current_compatibility_manifest_replaces_only_private_debug_track(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_release_fixture(root)
            compatibility = self.write_compatibility_fixture(root)
            with mock.patch.object(MODULE, "validate_apk_with_tools"):
                artifacts = MODULE.validate_artifacts(
                    root, Path("aapt2"), Path("apksigner"), compatibility
                )
        self.assertEqual(8, len(artifacts))
        self.assertEqual(
            {("debug", "1.0.0", 65), ("candidate", "1.0.0", 65)},
            {(item.track, item.version, item.version_code) for item in artifacts},
        )
        self.assertEqual(
            {"AB" * 32},
            {item.signer_sha256 for item in artifacts if item.track == "debug"},
        )
        self.assertTrue(
            all(item.package_name.endswith(".debug") for item in artifacts if item.track == "debug")
        )

    def test_compatibility_manifest_rejects_http_old_code_and_wrong_signer(self) -> None:
        cases = (
            (dict(api_base_url="http://appht.jjmxg.xyz/"), "HTTPS/non-debuggable"),
            (dict(version_code=60), "greater than the tracked legacy maximum"),
            (dict(signer="EF" * 32), "signer.*mismatch"),
            (
                dict(network_resource="res/../unsafe.xml"),
                "Unsafe compiled network security resource",
            ),
            (
                dict(
                    connection_hashes={
                        **TEST_CONNECTION_HASHES,
                        "appKeySha256": "4" * 64,
                    }
                ),
                "does not match Stable pending metadata",
            ),
        )
        for options, expected in cases:
            with self.subTest(options=options), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                self.write_release_fixture(root)
                compatibility = self.write_compatibility_fixture(root, **options)
                with mock.patch.object(MODULE, "validate_apk_with_tools"):
                    with self.assertRaisesRegex(RuntimeError, expected):
                        MODULE.validate_artifacts(
                            root, Path("aapt2"), Path("apksigner"), compatibility
                        )

    def test_tracked_legacy_anchor_tamper_is_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_release_fixture(root)
            anchor_path = root / MODULE.LEGACY_UPGRADE_IDENTITY_PATH
            anchor = json.loads(anchor_path.read_text(encoding="utf-8"))
            anchor["packages"]["user"] = "xyz.example.wrong.debug"
            anchor_path.write_text(json.dumps(anchor), encoding="utf-8")
            with mock.patch.object(MODULE, "validate_apk_with_tools"):
                with self.assertRaisesRegex(RuntimeError, "Frozen Debug package identity"):
                    MODULE.validate_artifacts(
                        root, Path("aapt2"), Path("apksigner")
                    )

    def test_frozen_connection_identity_tamper_is_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_release_fixture(root)
            frozen_path = root / MODULE.FROZEN_DEBUG_MANIFEST_PATH
            frozen = json.loads(frozen_path.read_text(encoding="utf-8"))
            frozen["connectionIdentity"]["appKeySha256"] = "4" * 64
            frozen_path.write_text(json.dumps(frozen), encoding="utf-8")
            with mock.patch.object(MODULE, "validate_apk_with_tools"):
                with self.assertRaisesRegex(
                    RuntimeError, "Frozen Debug connection identity"
                ):
                    MODULE.validate_artifacts(
                        root, Path("aapt2"), Path("apksigner")
                    )

    def test_synchronized_stable_and_compat_replacement_is_rejected(self) -> None:
        replacement = {
            "appKeySha256": "4" * 64,
            "platformKeySha256": "5" * 64,
            "authorizedPlatformKeySha256": "6" * 64,
        }
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_release_fixture(root)
            stable_path = root / str(MODULE.TRACKS["candidate"]["manifest"])
            stable = json.loads(stable_path.read_text(encoding="utf-8"))
            stable["connectionIdentity"].update(replacement)
            stable_path.write_text(json.dumps(stable), encoding="utf-8")
            compatibility = self.write_compatibility_fixture(
                root, connection_hashes=replacement
            )
            with mock.patch.object(MODULE, "validate_apk_with_tools"):
                with self.assertRaisesRegex(
                    RuntimeError, "tracked historical anchor"
                ):
                    MODULE.validate_artifacts(
                        root, Path("aapt2"), Path("apksigner"), compatibility
                    )

    def test_identity_65_manifest_64_is_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_release_fixture(root, identity_code=65, manifest_code=64)
            with mock.patch.object(MODULE, "validate_apk_with_tools"):
                with self.assertRaisesRegex(RuntimeError, "does not match release identity"):
                    MODULE.validate_artifacts(root, Path("aapt2"), Path("apksigner"))

    def test_candidate_signer_must_match_release_identity(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_release_fixture(root, candidate_signer="EF" * 32)
            with mock.patch.object(MODULE, "validate_apk_with_tools"):
                with self.assertRaisesRegex(RuntimeError, "Stable signer"):
                    MODULE.validate_artifacts(root, Path("aapt2"), Path("apksigner"))


class CompatibilityApkIndependentGateTests(unittest.TestCase):
    def test_compiled_resource_and_system_only_certificate_sources(self) -> None:
        manifest = (
            "A: android:networkSecurityConfig(0x01010527)=@0x7F140006"
        )
        resources = (
            "resource 0x7f140006 xml/network_security_config\n"
            "  () (file) res/8G.xml type=XML\n"
        )
        self.assertEqual(
            "res/8G.xml",
            MODULE.resolve_compiled_network_security_resource(manifest, resources),
        )
        system_only = (
            "A: cleartextTrafficPermitted=false\n"
            'A: src="system" (Raw: "system")\n'
            'A: src="system" (Raw: "system")\n'
        )
        MODULE.validate_compiled_network_security_output(system_only)
        for source in ('A: src="user"', "A: src=@raw/custom_ca"):
            with self.subTest(source=source):
                with self.assertRaisesRegex(RuntimeError, "must all be system"):
                    MODULE.validate_compiled_network_security_output(
                        system_only + source
                    )

    def test_dex_gate_rejects_only_exact_old_endpoints(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)

            def write_apk(name: str, payload: bytes) -> Path:
                path = root / name
                with zipfile.ZipFile(path, "w") as archive:
                    archive.writestr("classes.dex", payload)
                return path

            good = write_apk(
                "good.apk",
                b"https://appht.jjmxg.xyz/\x00"
                b"localhost\x00http://localhost:9999/\x00"
                b"http://appht.jjmxg.xyz/documentation\x00",
            )
            MODULE.validate_compatibility_dex_transport(good)
            for index, endpoint in enumerate(
                (
                    b"http://appht.jjmxg.xyz/",
                    b"http://appht.jjmxg.xyz",
                    b"http://127.0.0.1:8788/",
                    b"http://127.0.0.1:8788",
                    b"http://10.0.2.2:8788/",
                    b"http://10.0.2.2:8788",
                )
            ):
                bad = write_apk(
                    f"bad-{index}.apk",
                    b"https://appht.jjmxg.xyz/\x00" + endpoint + b"\x00",
                )
                with self.assertRaisesRegex(RuntimeError, "forbidden exact endpoint"):
                    MODULE.validate_compatibility_dex_transport(bad)

    def test_deployer_rechecks_badging_signer_network_and_dex(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            apk = root / "fixture.apk"
            with zipfile.ZipFile(apk, "w") as archive:
                archive.writestr(
                    "classes.dex", b"https://appht.jjmxg.xyz/\x00localhost\x00"
                )
            artifact = MODULE.ApkArtifact(
                track="debug",
                role="user",
                version="1.0.0",
                version_code=65,
                file_name=apk.name,
                package_name="xyz.jjmxg.yiyunying.user.debug",
                version_name="1.0.0-user-debug",
                signer_sha256="AB" * 32,
                path=apk,
                size=apk.stat().st_size,
                sha256=hashlib.sha256(apk.read_bytes()).hexdigest(),
            )
            commands: list[list[str]] = []

            def fake_run(command, _label, _cwd=None):
                commands.append(command)
                if command[1:3] == ["dump", "badging"]:
                    return (
                        "package: name='xyz.jjmxg.yiyunying.user.debug' "
                        "versionCode='65' versionName='1.0.0-user-debug'\n"
                    )
                if command[1] == "verify":
                    return (
                        "Verified using v2 scheme (APK Signature Scheme v2): true\n"
                        "Number of signers: 1\n"
                        f"Signer #1 certificate SHA-256 digest: {'AB' * 32}\n"
                    )
                if command[1:3] == ["dump", "resources"]:
                    return (
                        "resource 0x7f140006 xml/network_security_config\n"
                        "  () (file) res/8G.xml type=XML\n"
                    )
                if command[-1] == "AndroidManifest.xml":
                    return "A: android:networkSecurityConfig=@0x7f140006\n"
                if command[-1] == "res/8G.xml":
                    return (
                        "A: cleartextTrafficPermitted=false\n"
                        'A: src="system" (Raw: "system")\n'
                    )
                raise AssertionError(command)

            with mock.patch.object(MODULE, "run_local", side_effect=fake_run):
                MODULE.validate_apk_with_tools(
                    artifact,
                    Path("aapt2"),
                    Path("apksigner"),
                    compatibility_network_resource="res/8G.xml",
                )
            flattened = "\n".join(" ".join(command) for command in commands)
            for marker in (
                "dump badging",
                "verify --verbose --print-certs",
                "AndroidManifest.xml",
                "dump resources",
                "res/8G.xml",
            ):
                self.assertIn(marker, flattened)

            def debuggable_run(command, label, cwd=None):
                output = fake_run(command, label, cwd)
                if command[1:3] == ["dump", "badging"]:
                    return output + "application-debuggable\n"
                return output

            with mock.patch.object(MODULE, "run_local", side_effect=debuggable_run):
                with self.assertRaisesRegex(RuntimeError, "debuggable or testOnly"):
                    MODULE.validate_apk_with_tools(
                        artifact,
                        Path("aapt2"),
                        Path("apksigner"),
                        compatibility_network_resource="res/8G.xml",
                    )

            def weak_signer_run(command, label, cwd=None):
                output = fake_run(command, label, cwd)
                if command[1] == "verify":
                    return output.replace("Scheme v2): true", "Scheme v2): false")
                return output

            with mock.patch.object(MODULE, "run_local", side_effect=weak_signer_run):
                with self.assertRaisesRegex(RuntimeError, "must use v2 with one signer"):
                    MODULE.validate_apk_with_tools(
                        artifact,
                        Path("aapt2"),
                        Path("apksigner"),
                        compatibility_network_resource="res/8G.xml",
                    )


class LegacyDebugCompatibilitySourceContractTests(unittest.TestCase):
    def test_variant_is_release_hardened_but_uses_legacy_debug_identity(self) -> None:
        gradle = (ROOT / "android/app/build.gradle").read_text(encoding="utf-8")
        markers = (
            "legacyCompat {",
            "initWith release",
            "debuggable false",
            "applicationIdSuffix '.debug'",
            "versionNameSuffix '-debug'",
            "signingConfig signingConfigs.debug",
            "YIYUNYING_LEGACY_COMPAT_STRICT",
            "buildConfigField 'boolean', 'ALLOW_HTTP_ENDPOINTS', 'false'",
            "asBuildConfigString('https://appht.jjmxg.xyz/')",
        )
        for marker in markers:
            self.assertIn(marker, gradle)
        self.assertNotIn("matchingFallbacks = ['debug']", gradle)
        network = (ROOT / "android/app/src/main/res/xml/network_security_config.xml").read_text(encoding="utf-8")
        self.assertIn('cleartextTrafficPermitted="false"', network)
        api_client = (ROOT / "android/app/src/main/java/xyz/jjmxg/yiyunying/data/api/ApiClient.java").read_text(encoding="utf-8")
        self.assertIn(".followRedirects(false)", api_client)
        self.assertIn(".followSslRedirects(false)", api_client)

    def test_builder_uses_global_code_above_60_and_internal_manifest_only(self) -> None:
        version = dict(
            line.split("=", 1)
            for line in (ROOT / "android/version.properties").read_text(encoding="utf-8").splitlines()
            if "=" in line
        )
        self.assertGreater(int(version["VERSION_CODE"]), 60)
        script = (ROOT / "android/tools/build-legacy-debug-compat.ps1").read_text(encoding="utf-8")
        self.assertIn("assemble$($_.Flavor.Substring(0,1).ToUpperInvariant())$($_.Flavor.Substring(1))LegacyCompat", script)
        self.assertIn("releases\\internal\\legacy-debug-compat", script)
        self.assertIn("Read-LegacyUpgradeIdentityAnchor", script)
        self.assertIn("Assert-FrozenDebugManifestMatchesAnchor", script)
        self.assertIn("Read-LegacyCompatConnectionIdentity", script)
        self.assertIn("connectionIdentity = $connectionIdentity", script)
        self.assertNotIn("release-metadata.json", script)
        anchor = json.loads(
            (ROOT / MODULE.LEGACY_UPGRADE_IDENTITY_PATH).read_text(encoding="utf-8")
        )
        self.assertEqual(1, anchor["schemaVersion"])
        self.assertEqual(60, anchor["legacyUpgradeMaximumVersionCode"])
        self.assertEqual(
            "10162EBB7147EA0823C281D9F86FEFF2A353984A41497F17E196E50614E9B76E",
            anchor["legacyPackageSignerSha256"],
        )
        self.assertEqual(
            {
                "appKeySha256": "05872e1f0465c7ab48df13e37dfefa3a95c882c268f3836264ed83f6c0b9f264",
                "platformKeySha256": "e8260e22cd152015735ab5a05e392fed162b3e71d639f4392fb8550ae886ef54",
                "authorizedPlatformKeySha256": "9d300ae4617dc8f0ebc22444733ab3b8681636ac74ce2676059c7754eab7ff82",
            },
            anchor["connectionIdentity"],
        )
        self.assertEqual(
            {
                role: base_package + ".debug"
                for role, (_, _, base_package) in MODULE.ROLES.items()
            },
            anchor["packages"],
        )
        gate = (ROOT / "android/tools/legacy-debug-compat-security.ps1").read_text(
            encoding="utf-8-sig"
        )
        for marker in ("application-debuggable", "testOnly", "Verified using v2 scheme", "Number of signers: 1", "cleartextTrafficPermitted=false", "certificate sources must all be system", "Resolve-LegacyCompatNetworkSecurityResource", "AndroidManifest.xml", "dump resources", "https://appht.jjmxg.xyz/", "http://appht.jjmxg.xyz/", "http://127.0.0.1:8788/", "http://10.0.2.2:8788/"):
            self.assertIn(marker, gate)
        self.assertIn("Assert-LegacyCompatApk", script)


class DeploymentSafetyTests(unittest.TestCase):
    def test_nested_staging_parents_precede_version_directories(self) -> None:
        source = SCRIPT.read_text(encoding="utf-8")
        parent = "directories.append(posixpath.join(stage, track))"
        version = 'directories.append(posixpath.join(stage, f"{track}/{version}"))'
        self.assertLess(source.index(parent), source.index(version))
        self.assertIn('{"debug", "candidate"}', source)

    def test_public_probe_refreshes_short_link_before_every_request(self) -> None:
        secret = "ab" * 32
        clock = [1_800_000_000]
        artifacts = [
            MODULE.ApkArtifact(
                track=track,
                role="user",
                version="1.0.0",
                version_code=65,
                file_name=f"{track}.apk",
                package_name=f"example.{track}",
                version_name=f"1.0.0-{track}",
                signer_sha256="cd" * 32,
                path=ROOT / f"{track}.apk",
                size=1234,
                sha256="ef" * 32,
            )
            for track in ("debug", "candidate")
        ]

        def fake_http(request, expected):
            query = urllib.parse.parse_qs(urllib.parse.urlsplit(request.full_url).query)
            expires = int(query["expires"][0])
            if expected == {410}:
                self.assertLess(expires, clock[0])
            else:
                self.assertGreater(expires, clock[0])
            status = next(iter(expected))
            headers = {}
            body = b""
            if status == 200:
                headers = {
                    "Content-Length": "1234",
                    "Content-Type": "application/vnd.android.package-archive",
                    "Cache-Control": "private, no-store",
                    "X-Content-Type-Options": "nosniff",
                    "ETag": '"fixture-etag"',
                }
            elif status == 206:
                headers = {"Content-Range": "bytes 0-63/1234"}
                body = b"x" * 64
            clock[0] += 301
            return status, headers, body

        with mock.patch.object(MODULE.time, "time", side_effect=lambda: clock[0]), mock.patch.object(
            MODULE, "http_status", side_effect=fake_http
        ):
            MODULE.verify_public_downloads("https://example.test", artifacts, secret)

    def args(self, **overrides) -> argparse.Namespace:
        values = dict(
            aapt2=None,
            apksigner=None,
            debug_compatibility_manifest=None,
            public_origin="https://appht.jjmxg.xyz",
            remote_private_root=MODULE.EXPECTED_PRIVATE_ROOT,
            host=None,
            port=22,
            username="root",
            known_hosts=None,
            fpm_upstream="",
            remote_php_binary="",
            remote_fpm_evidence_config="",
            remote_nginx_host_config="",
            remote_nginx_host_include="",
            remote_nginx_include="",
            remote_secret_include="",
            execute=False,
            confirmation="",
            nginx_confirmation="",
        )
        values.update(overrides)
        return argparse.Namespace(**values)

    def test_default_is_offline_dry_run_and_confirmations_are_execute_only(self) -> None:
        with mock.patch.dict(os.environ, {}, clear=True):
            _, _, root, origin = MODULE.validate_args(self.args(), ROOT)
        self.assertEqual(MODULE.EXPECTED_PRIVATE_ROOT, root)
        self.assertEqual("https://appht.jjmxg.xyz", origin)
        with self.assertRaisesRegex(RuntimeError, "accepted only with --execute"):
            MODULE.validate_args(
                self.args(confirmation=MODULE.EXECUTE_CONFIRMATION), ROOT
            )

    def test_execute_needs_both_confirmations_explicit_fpm_paths_and_secrets(self) -> None:
        execute = self.args(execute=True)
        with self.assertRaisesRegex(RuntimeError, "--debug-compatibility-manifest"):
            MODULE.validate_args(execute, ROOT)

        execute.debug_compatibility_manifest = ROOT / "releases" / "2.7.15" / "release-manifest.json"
        with self.assertRaisesRegex(RuntimeError, "exact current"):
            MODULE.validate_args(execute, ROOT)
        execute.debug_compatibility_manifest = (
            ROOT
            / MODULE.COMPATIBILITY_MANIFEST_ROOT
            / "1.0.0"
            / "release-manifest.json"
        )
        with self.assertRaisesRegex(RuntimeError, "--confirmation"):
            MODULE.validate_args(execute, ROOT)
        execute.confirmation = MODULE.EXECUTE_CONFIRMATION
        with self.assertRaisesRegex(RuntimeError, "--nginx-confirmation"):
            MODULE.validate_args(execute, ROOT)

        execute.nginx_confirmation = MODULE.NGINX_CONFIRMATION
        with self.assertRaisesRegex(RuntimeError, "--remote-php-binary"):
            MODULE.validate_args(execute, ROOT)

        execute.host = "example.test"
        execute.known_hosts = "known_hosts"
        execute.fpm_upstream = "unix:/tmp/php-cgi-82.sock"
        execute.remote_php_binary = "/www/server/php/82/bin/php"
        execute.remote_fpm_evidence_config = "/www/server/panel/vhost/nginx/phpfpm_status.conf"
        execute.remote_nginx_host_config = "/www/server/panel/vhost/nginx/appht.jjmxg.xyz.conf"
        execute.remote_nginx_host_include = "/etc/nginx/private/yiyunying-internal-apks.conf"
        execute.remote_nginx_include = "/etc/nginx/private/yiyunying-internal-apks.conf"
        execute.remote_secret_include = "/etc/nginx/private/yiyunying-internal-apks-secret.conf"
        with mock.patch.dict(
            os.environ,
            {"YY_SSH_PASSWORD": "present", MODULE.SECRET_ENV: "AB" * 32},
            clear=True,
        ):
            with self.assertRaisesRegex(RuntimeError, "64 lowercase hex"):
                MODULE.validate_args(execute, ROOT)

    def test_remote_paths_and_fpm_are_narrowly_validated(self) -> None:
        self.assertEqual(
            "unix:/tmp/php-cgi-82.sock",
            MODULE.validate_fpm_upstream("unix:/tmp/php-cgi-82.sock"),
        )
        for unsafe in ("unix:/tmp/a.sock;evil", "0.0.0.0:9000", "example.com:9000", ""):
            with self.subTest(unsafe=unsafe):
                with self.assertRaises(RuntimeError):
                    MODULE.validate_fpm_upstream(unsafe)
        self.assertEqual(
            "/www/server/php/82/bin/php",
            MODULE.validate_php_binary("/www/server/php/82/bin/php"),
        )
        for unsafe in ("php", "/bin/php", "/www/server/php/82/bin/php;id", ""):
            with self.subTest(unsafe_php=unsafe):
                with self.assertRaises(RuntimeError):
                    MODULE.validate_php_binary(unsafe)
        for unsafe in ("/srv", "/var/www/internal", "/www/private", "/srv/other"):
            with self.subTest(unsafe=unsafe):
                with self.assertRaises(RuntimeError):
                    MODULE.validate_private_root(unsafe)
        self.assertEqual(
            "/www/server/panel/vhost/nginx/extension/appht.jjmxg.xyz/*.conf",
            MODULE.validate_host_include_pattern(
                "/www/server/panel/vhost/nginx/extension/appht.jjmxg.xyz/*.conf",
                "/www/server/panel/vhost/nginx/extension/appht.jjmxg.xyz/internal-apks.conf",
            ),
        )
        with self.assertRaises(RuntimeError):
            MODULE.validate_host_include_pattern(
                "/etc/nginx/conf.d/*.conf", "/etc/nginx/private/internal-apks.conf"
            )

    def test_activation_rollback_and_cleanup_contracts_are_atomic_and_private(self) -> None:
        activation = MODULE.activate_file_command(
            "/etc/nginx/private/live.conf",
            "/etc/nginx/private/live.conf.candidate-token",
            "/etc/nginx/private/live.conf.backup-token",
            True,
        )
        rollback = MODULE.restore_file_command(
            "/etc/nginx/private/live.conf",
            "/etc/nginx/private/live.conf.candidate-token",
            "/etc/nginx/private/live.conf.backup-token",
            True,
        )
        self.assertIn("mv", activation)
        self.assertIn("backup-token", activation)
        self.assertIn("if [ -f", rollback)
        self.assertNotIn("/downloads", activation + rollback)

        source = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("nginx -t && nginx -s reload", source)
        self.assertIn("PHP_MAJOR_VERSION", source)
        self.assertIn("remote_php_binary", source)
        self.assertIn("ROLLBACK INCOMPLETE", source)
        self.assertIn("RejectPolicy", source)
        self.assertIn("known_hosts", source)
        self.assertIn("0o600", source)
        self.assertNotRegex(source, r"public[/\\\\]downloads")

    def test_ssh_connection_pins_only_the_supplied_known_hosts(self) -> None:
        class FakePolicy:
            pass

        class FakeClient:
            def __init__(self):
                self.loaded = None
                self.policy = None
                self.connect_kwargs = None

            def load_host_keys(self, value):
                self.loaded = value

            def set_missing_host_key_policy(self, value):
                self.policy = value

            def connect(self, host, **kwargs):
                self.connect_kwargs = (host, kwargs)

        fake_client = FakeClient()
        fake_paramiko = mock.Mock()
        fake_paramiko.SSHClient.return_value = fake_client
        fake_paramiko.RejectPolicy = FakePolicy
        known_hosts = ROOT / ".gitignore"
        args = self.args(host="example.test", known_hosts=str(known_hosts))
        with mock.patch.object(MODULE, "paramiko", fake_paramiko), mock.patch.dict(
            os.environ, {"YY_SSH_PASSWORD": "redacted"}, clear=True
        ):
            result = MODULE.connect_ssh(args)
        self.assertIs(result, fake_client)
        self.assertEqual(str(known_hosts.resolve()), fake_client.loaded)
        self.assertIsInstance(fake_client.policy, FakePolicy)
        _, kwargs = fake_client.connect_kwargs
        self.assertFalse(kwargs["look_for_keys"])
        self.assertFalse(kwargs["allow_agent"])


def shutil_which(name: str) -> str | None:
    import shutil

    return shutil.which(name)


def php_status(verifier: Path, secret: str, request_uri: str, debug_version: str = "2.7.15") -> int:
    code = (
        "$_SERVER['YY_INTERNAL_VERIFIER']='1';"
        "$_SERVER['YY_INTERNAL_ORIGINAL_METHOD']='GET';"
        "$_SERVER['YY_INTERNAL_ORIGINAL_REQUEST_URI']=$argv[1];"
        "$_SERVER['YY_INTERNAL_DOWNLOAD_SECRET']=$argv[2];"
        "$_SERVER['YY_INTERNAL_DEBUG_VERSION']=$argv[4];"
        "register_shutdown_function(function(){echo http_response_code();});"
        "require $argv[3];"
    )
    result = subprocess.run(
        ["php", "-r", code, request_uri, secret, str(verifier), debug_version],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        encoding="utf-8",
        errors="replace",
        timeout=20,
        check=False,
    )
    if result.returncode != 0 or not re.fullmatch(r"\d{3}", result.stdout.strip()):
        raise AssertionError(f"PHP verifier harness failed: {result.stderr[:200]}")
    return int(result.stdout.strip())


if __name__ == "__main__":
    unittest.main()
