#!/usr/bin/env python3
"""Offline tests for the site-only security remediation transaction."""

from __future__ import annotations

import hashlib
import importlib.util
import json
from pathlib import Path
from types import SimpleNamespace
import sys
import tempfile
import types
import unittest


try:
    import paramiko  # noqa: F401
except ModuleNotFoundError:
    paramiko_stub = types.ModuleType("paramiko")
    paramiko_stub.SSHClient = type("SSHClient", (), {})
    paramiko_stub.RejectPolicy = type("RejectPolicy", (), {})
    sys.modules["paramiko"] = paramiko_stub


ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "download-site" / "scripts" / "deploy-site-security-remediation.py"
SPEC = importlib.util.spec_from_file_location("site_security_remediation_under_test", SCRIPT)
assert SPEC and SPEC.loader
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


def write_text(path: Path, value: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(value, encoding="utf-8", newline="\n")


def write_json(path: Path, value: dict) -> None:
    write_text(path, json.dumps(value, ensure_ascii=False, indent=2) + "\n")


class SiteFixture:
    def __init__(self, root: Path) -> None:
        self.repository = root / "repo"
        self.site = self.repository / "download-site" / "static-dist"
        self.metadata = self.repository / "download-site" / "release-metadata.json"
        self.nginx = (
            self.repository
            / "download-site"
            / "deploy"
            / "nginx-download-center.conf"
        )
        self.site.mkdir(parents=True)
        releases = []
        definitions = {
            "user": ("user", "xyz.example.user"),
            "admin": ("admin", "xyz.example.admin"),
            "authorized": ("authorized-platform", "xyz.example.authorized"),
            "owner": ("platform-owner", "xyz.example.owner"),
        }
        for position, (role, (stem, package_name)) in enumerate(definitions.items(), 1):
            releases.append(
                {
                    "id": role,
                    "fileName": f"yiyunying-{stem}-v9.8.7.apk",
                    "packageName": package_name,
                    "versionName": f"9.8.7-{stem}",
                    "versionCode": 987,
                    "sizeBytes": 2_000_000 + position,
                    "sha256": f"{position:x}" * 64,
                    "signerSha256": "a" * 64,
                }
            )
        write_json(
            self.metadata,
            {
                "schemaVersion": 4,
                "channel": "Stable",
                "versionName": "9.8.7",
                "versionCode": 987,
                "finalizationStatus": "pending",
                "releaseIdentitySha256": "b" * 64,
                "pendingManifestSha256": "c" * 64,
                "releases": releases,
                "projectAssets": [
                    {"id": "source", "fileName": "yiyunying-source-v9.8.7.zip"},
                    {"id": "history", "fileName": "yiyunying-git-history-v9.8.7.bundle"},
                    {"id": "delivery", "fileName": "yiyunying-project-delivery-v9.8.7.zip"},
                    {"id": "manifest", "fileName": "project-assets-manifest.json"},
                ],
            },
        )
        files = {
            "index.html": (
                "<!doctype html><title>安全维护</title>"
                "<h1>正式版尚未开放</h1>"
                "<p>当前页面不会公开候选版本、安装包名称、校验值或下载地址</p>"
                '<link rel="stylesheet" href="/download-center/assets/site-Abc123.css">'
            ),
            "site.js": "const publicRelease = null; document.documentElement.dataset.safe='true';",
            "docs.js": "document.documentElement.dataset.docs='true';",
            "site.webmanifest": '{"name":"security remediation"}\n',
            "logo.svg": '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
            "api-docs/index.html": "<!doctype html><title>API docs</title>",
            "privacy/index.html": "<!doctype html><title>Privacy</title>",
            "terms/index.html": "<!doctype html><title>Terms</title>",
            "assets/site-Abc123.css": "body{color:#123}",
        }
        for relative, value in files.items():
            write_text(self.site / relative, value)
        (self.site / "og-card.png").write_bytes(b"safe-png-fixture")

        write_text(
            self.nginx,
            """
location ^~ /download-center/ { try_files $uri $uri/ =404; }
location ~ ^/downloads/[0-9]+\\.[0-9]+\\.[0-9]+/yiyunying-(?:user|admin|authorized-platform|platform-owner)-v[0-9]+\\.[0-9]+\\.[0-9]+\\.apk$ {
  types { application/vnd.android.package-archive apk; }
  try_files $uri =404;
}
location = /downloads { return 404; }
location /downloads/ { return 404; }
""".strip()
            + "\n",
        )


class SiteValidationTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.fixture = SiteFixture(Path(self.temporary.name))

    def test_fail_closed_whitelist_accepts_only_customer_site_files(self) -> None:
        files = MODULE.validate_site_tree(self.fixture.site, self.fixture.metadata)
        relatives = {item.relative for item in files}
        self.assertTrue(MODULE.REQUIRED_SITE_FILES.issubset(relatives))
        self.assertIn("assets/site-Abc123.css", relatives)
        self.assertFalse(any(path.endswith(".apk") for path in relatives))
        for item in files:
            self.assertEqual(item.sha256, hashlib.sha256(item.path.read_bytes()).hexdigest())

    def test_build_control_files_are_allowed_locally_but_never_uploaded(self) -> None:
        for relative in MODULE.LOCAL_ONLY_SITE_FILES:
            write_text(self.fixture.site / relative, "local build control only\n")
        write_text(
            self.fixture.site / "assets" / "index-Abcdef.js",
            "window.__PRIVATE_ROUTE__='/internal-downloads';\n",
        )
        files = MODULE.validate_site_tree(self.fixture.site, self.fixture.metadata)
        relatives = {item.relative for item in files}
        self.assertTrue(relatives.isdisjoint(MODULE.LOCAL_ONLY_SITE_FILES))
        self.assertNotIn("assets/index-Abcdef.js", relatives)

        with (self.fixture.site / "index.html").open("a", encoding="utf-8") as stream:
            stream.write('<script src="/download-center/assets/index-Abcdef.js"></script>')
        with self.assertRaisesRegex(RuntimeError, "local-only JavaScript bundle"):
            MODULE.validate_site_tree(self.fixture.site, self.fixture.metadata)

    def test_candidate_version_file_package_hash_and_private_assets_are_rejected(self) -> None:
        metadata = json.loads(self.fixture.metadata.read_text(encoding="utf-8"))
        candidate_values = (
            metadata["versionName"],
            metadata["releases"][0]["fileName"],
            metadata["releases"][1]["packageName"],
            metadata["releases"][2]["sha256"],
            metadata["projectAssets"][0]["fileName"],
        )
        for position, value in enumerate(candidate_values):
            with self.subTest(value=value):
                write_text(self.fixture.site / "api-docs" / "index.html", value)
                with self.assertRaisesRegex(RuntimeError, "candidate metadata|internal/private marker"):
                    MODULE.validate_site_tree(self.fixture.site, self.fixture.metadata)
                write_text(
                    self.fixture.site / "api-docs" / "index.html",
                    f"<!doctype html><title>API docs {position}</title>",
                )

    def test_apk_unknown_file_internal_route_and_download_attribute_are_rejected(self) -> None:
        attacks = (
            ("payload.apk", b"apk", "non-whitelisted|forbidden"),
            ("internal-downloads/index.html", b"private", "non-whitelisted|internal"),
            ("secrets.txt", b"secret", "non-whitelisted"),
        )
        for relative, payload, message in attacks:
            with self.subTest(relative=relative):
                path = self.fixture.site / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(payload)
                with self.assertRaisesRegex(RuntimeError, message):
                    MODULE.validate_site_tree(self.fixture.site, self.fixture.metadata)
                path.unlink()
                if path.parent != self.fixture.site and not any(path.parent.iterdir()):
                    path.parent.rmdir()
        write_text(
            self.fixture.site / "privacy" / "index.html",
            '<a download="candidate">download</a>',
        )
        with self.assertRaisesRegex(RuntimeError, "download attribute"):
            MODULE.validate_site_tree(self.fixture.site, self.fixture.metadata)

    def test_missing_fail_closed_marker_or_null_release_binding_is_rejected(self) -> None:
        write_text(self.fixture.site / "index.html", "<!doctype html><h1>available</h1>")
        with self.assertRaisesRegex(RuntimeError, "fail-closed customer marker"):
            MODULE.validate_site_tree(self.fixture.site, self.fixture.metadata)
        self.fixture = SiteFixture(Path(self.temporary.name) / "second")
        write_text(self.fixture.site / "site.js", "const publicRelease = {}; ")
        with self.assertRaisesRegex(RuntimeError, "null publicRelease"):
            MODULE.validate_site_tree(self.fixture.site, self.fixture.metadata)


class PolicyAndCommandTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.fixture = SiteFixture(Path(self.temporary.name))

    def arguments(self, **overrides):
        values = {
            "site_dir": self.fixture.site,
            "public_origin": "https://downloads.example.test",
            "remote_public_root": "/www/wwwroot/example/public",
            "host": None,
            "port": 22,
            "username": "root",
            "known_hosts": None,
            "execute": False,
            "confirmation": "",
            "nginx_config": None,
            "remote_nginx_config": "",
            "nginx_confirmation": "",
        }
        values.update(overrides)
        return SimpleNamespace(**values)

    def test_default_is_offline_dry_run_and_execute_needs_exact_confirmation(self) -> None:
        args = self.arguments()
        site, metadata, _, _, nginx = MODULE.validate_execution_args(args, self.fixture.repository)
        self.assertEqual(site, self.fixture.site.resolve())
        self.assertEqual(metadata, self.fixture.metadata.resolve())
        self.assertIsNone(nginx)
        with self.assertRaisesRegex(RuntimeError, MODULE.EXECUTE_CONFIRMATION):
            MODULE.validate_execution_args(
                self.arguments(execute=True, host="example.test", known_hosts="missing"),
                self.fixture.repository,
            )

    def test_nginx_uses_only_reviewed_config_and_requires_separate_confirmation(self) -> None:
        with self.assertRaisesRegex(RuntimeError, MODULE.NGINX_CONFIRMATION):
            MODULE.validate_execution_args(
                self.arguments(
                    execute=True,
                    confirmation=MODULE.EXECUTE_CONFIRMATION,
                    host="host.example.test",
                    known_hosts=str(self.fixture.nginx),
                    nginx_config=self.fixture.nginx,
                    remote_nginx_config="/etc/nginx/conf.d/download-center.conf",
                ),
                self.fixture.repository,
            )
        args = self.arguments(
            nginx_config=self.fixture.nginx,
            remote_nginx_config="/etc/nginx/conf.d/download-center.conf",
        )
        *_, artifact = MODULE.validate_execution_args(args, self.fixture.repository)
        self.assertIsNotNone(artifact)
        write_text(self.fixture.nginx, self.fixture.nginx.read_text(encoding="utf-8") + "# -debug.apk\n")
        with self.assertRaisesRegex(RuntimeError, "unsafe download rule"):
            MODULE.validate_nginx_config(self.fixture.nginx, self.fixture.repository)

    def test_site_activation_and_rollback_only_rename_customer_site(self) -> None:
        activate = MODULE.site_activation_command(
            "/public/download-center",
            "/public/.stage/site",
            "/public/.previous",
            True,
        )
        rollback = MODULE.site_rollback_command(
            "/public/download-center",
            "/public/.stage/site",
            "/public/.previous",
            True,
        )
        self.assertIn("mv /public/download-center /public/.previous", activate)
        self.assertIn("mv /public/.stage/site /public/download-center", activate)
        self.assertIn("mv /public/.previous /public/download-center", rollback)
        self.assertNotIn("/downloads/", activate + rollback)
        self.assertNotIn("release", activate + rollback.lower())

    def test_full_public_readback_and_nginx_404_probe_are_fail_closed(self) -> None:
        index = MODULE.SiteFile(
            "index.html",
            self.fixture.site / "index.html",
            "d" * 64,
            123,
        )
        readback = MODULE.public_index_verification_command(
            index,
            "https://downloads.example.test",
            "/public/.stage/readback",
        )
        for marker in (
            "--proto '=https'",
            "--max-redirs 0",
            'test "$status" = 200',
            "stat -c %s",
            "sha256sum",
            "Content-Type:",
        ):
            self.assertIn(marker, readback)
        probe = MODULE.old_debug_probe_command("https://downloads.example.test")
        self.assertEqual(probe.count('test "$status" = 404'), 4)
        for path in MODULE.OLD_DEBUG_PATHS:
            self.assertIn(path, probe)

    def test_nginx_transaction_tests_reloads_and_restores_before_reload(self) -> None:
        activate = MODULE.nginx_activation_command(
            "/etc/nginx/site.conf",
            "/etc/nginx/site.conf.candidate",
            "/etc/nginx/site.conf.backup",
        )
        rollback = MODULE.nginx_rollback_command(
            "/etc/nginx/site.conf",
            "/etc/nginx/site.conf.candidate",
            "/etc/nginx/site.conf.backup",
        )
        self.assertLess(activate.index("mv /etc/nginx/site.conf.candidate"), activate.index("nginx -t"))
        self.assertLess(activate.index("nginx -t"), activate.index("nginx -s reload"))
        self.assertLess(rollback.index("mv /etc/nginx/site.conf.backup"), rollback.index("nginx -t"))
        self.assertLess(rollback.index("nginx -t"), rollback.index("nginx -s reload"))

    def test_inactive_connection_is_closed_and_reconnected_for_rollback(self) -> None:
        class Transport:
            @staticmethod
            def is_active() -> bool:
                return False

        class Client:
            closed = False

            @staticmethod
            def get_transport():
                return Transport()

            def close(self):
                self.closed = True

        original = MODULE.connect_ssh
        replacement = object()
        MODULE.connect_ssh = lambda _args: replacement
        try:
            client = Client()
            self.assertIs(MODULE.ensure_active_ssh(client, self.arguments()), replacement)
            self.assertTrue(client.closed)
        finally:
            MODULE.connect_ssh = original

    def test_connect_ssh_pins_known_hosts_and_rejects_unknown_keys(self) -> None:
        known_hosts = Path(self.temporary.name) / "known_hosts"
        write_text(known_hosts, "host ssh-ed25519 fixture\n")
        calls = []

        class FakeClient:
            def load_host_keys(self, value):
                calls.append(("load", value))

            def set_missing_host_key_policy(self, value):
                calls.append(("policy", type(value).__name__))

            def connect(self, *args, **kwargs):
                calls.append(("connect", args, kwargs))

        original_client = MODULE.paramiko.SSHClient
        original_policy = MODULE.paramiko.RejectPolicy
        old_password = MODULE.os.environ.get("YY_SSH_PASSWORD")
        MODULE.paramiko.SSHClient = FakeClient
        MODULE.paramiko.RejectPolicy = type("RejectPolicy", (), {})
        MODULE.os.environ["YY_SSH_PASSWORD"] = "test-only-password"
        try:
            MODULE.connect_ssh(
                self.arguments(
                    host="host.example.test",
                    known_hosts=str(known_hosts),
                )
            )
        finally:
            MODULE.paramiko.SSHClient = original_client
            MODULE.paramiko.RejectPolicy = original_policy
            if old_password is None:
                MODULE.os.environ.pop("YY_SSH_PASSWORD", None)
            else:
                MODULE.os.environ["YY_SSH_PASSWORD"] = old_password
        self.assertEqual(calls[0], ("load", str(known_hosts.resolve())))
        self.assertEqual(calls[1], ("policy", "RejectPolicy"))
        self.assertFalse(calls[2][2]["look_for_keys"])
        self.assertFalse(calls[2][2]["allow_agent"])

    def test_static_contract_has_no_bypass_or_release_artifact_upload(self) -> None:
        source = SCRIPT.read_text(encoding="utf-8")
        for marker in (
            "paramiko.RejectPolicy()",
            "client.load_host_keys(str(known_hosts))",
            "secrets.token_hex(16)",
            "ensure_active_ssh(ssh, args)",
            "site_rollback_command",
            "nginx_rollback_command",
            "ROLLBACK INCOMPLETE; remediation lock retained",
            "no APK or /downloads path was published",
            "if not args.execute:",
            "EXECUTE_CONFIRMATION",
        ):
            self.assertIn(marker, source)
        for forbidden in (
            "paramiko.AutoAddPolicy()",
            "deploy-static.py",
            "SkipFinalized",
            "skip-finalized",
            "upload_release",
            "staging_release",
        ):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
