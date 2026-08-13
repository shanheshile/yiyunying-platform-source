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
from unittest import mock


ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "download-site" / "scripts" / "deploy-internal-apks.py"
SPEC = importlib.util.spec_from_file_location("internal_apk_private_deploy", SCRIPT)
assert SPEC and SPEC.loader
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class PrivateDownloadContractTests(unittest.TestCase):
    def test_reviewed_auth_request_template_is_exact_and_secret_free(self) -> None:
        template, verifier = MODULE.validate_deployment_sources(ROOT)
        rendered = MODULE.render_nginx(
            template,
            "/srv/yiyunying-internal-apks",
            "/etc/nginx/private/yiyunying-internal-apks-secret.conf",
            "unix:/tmp/php-cgi-82.sock",
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
        candidate_manifest = json.loads(
            (ROOT / MODULE.TRACKS["candidate"]["manifest"]).read_text(encoding="utf-8")
        )
        candidate_code = candidate_manifest["versionCode"]
        self.assertEqual(8, len(artifacts))
        self.assertEqual(
            {("debug", "2.7.15", 60), ("candidate", "1.0.0", candidate_code)},
            {(item.track, item.version, item.version_code) for item in artifacts},
        )
        self.assertGreaterEqual(
            candidate_code, MODULE.TRACKS["candidate"]["minimum_code"]
        )
        self.assertTrue(
            all(item.package_name.endswith(".debug") for item in artifacts if item.track == "debug")
        )
        self.assertTrue(
            all(not item.package_name.endswith(".debug") for item in artifacts if item.track == "candidate")
        )
        self.assertEqual(4, len({item.role for item in artifacts if item.track == "debug"}))
        self.assertEqual(4, len({item.role for item in artifacts if item.track == "candidate"}))

    def test_candidate_version_code_follows_manifest_and_rejects_regression(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            for track, policy in MODULE.TRACKS.items():
                manifest_path = root / policy["manifest"]
                manifest_path.parent.mkdir(parents=True, exist_ok=True)
                version_code = 60 if track == "debug" else 65
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
                            "signerSha256": "AB" * 32,
                            "sizeBytes": len(payload),
                            "sha256": hashlib.sha256(payload).hexdigest().upper(),
                        }
                    )
                manifest = {
                    "channel": policy["channel"],
                    "versionName": policy["version"],
                    "versionCode": version_code,
                    "finalizationStatus": policy["status"],
                    "releases": releases,
                }
                manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

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

            candidate_path = root / MODULE.TRACKS["candidate"]["manifest"]
            candidate = json.loads(candidate_path.read_text(encoding="utf-8"))
            candidate["versionCode"] = 63
            for release in candidate["releases"]:
                release["versionCode"] = 63
            candidate_path.write_text(json.dumps(candidate), encoding="utf-8")
            with mock.patch.object(MODULE, "validate_apk_with_tools"):
                with self.assertRaisesRegex(RuntimeError, "below the minimum 64"):
                    MODULE.validate_artifacts(root, Path("aapt2"), Path("apksigner"))


class DeploymentSafetyTests(unittest.TestCase):
    def test_nested_staging_parents_precede_version_directories(self) -> None:
        source = SCRIPT.read_text(encoding="utf-8")
        debug_parent = 'posixpath.join(stage, "debug"),'
        debug_version = 'posixpath.join(stage, "debug/2.7.15"),'
        candidate_parent = 'posixpath.join(stage, "candidate"),'
        candidate_version = 'posixpath.join(stage, "candidate/1.0.0"),'
        self.assertLess(source.index(debug_parent), source.index(debug_version))
        self.assertLess(source.index(candidate_parent), source.index(candidate_version))

    def test_public_probe_refreshes_short_link_before_every_request(self) -> None:
        secret = "ab" * 32
        clock = [1_800_000_000]
        artifacts = [
            MODULE.ApkArtifact(
                track=track,
                role="user",
                version="1.0.0",
                version_code=64,
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


def php_status(verifier: Path, secret: str, request_uri: str) -> int:
    code = (
        "$_SERVER['YY_INTERNAL_VERIFIER']='1';"
        "$_SERVER['YY_INTERNAL_ORIGINAL_METHOD']='GET';"
        "$_SERVER['YY_INTERNAL_ORIGINAL_REQUEST_URI']=$argv[1];"
        "$_SERVER['YY_INTERNAL_DOWNLOAD_SECRET']=$argv[2];"
        "register_shutdown_function(function(){echo http_response_code();});"
        "require $argv[3];"
    )
    result = subprocess.run(
        ["php", "-r", code, request_uri, secret, str(verifier)],
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
