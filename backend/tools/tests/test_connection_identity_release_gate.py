#!/usr/bin/env python3
"""Offline regression tests for the Android production connection-identity gate."""

from __future__ import annotations

from contextlib import redirect_stdout
import hashlib
import importlib.util
import io
from pathlib import Path
import sys
import types
import unittest


TOOLS = Path(__file__).resolve().parents[1]
SCRIPT_PATHS = {
    "publisher": TOOLS / "publish-android-ssh.py",
    "verifier": TOOLS / "verify-production-release-ssh.py",
}
SECRETS = {
    "YY_API_BASE_URL": "https://release.example.test/api/",
    "YY_APP_KEY": "never-log-app-key-7c8f",
    "YY_PLATFORM_KEY": "never-log-root-key-18a2",
    "YY_AUTHORIZED_PLATFORM_KEY": "never-log-authorized-key-2b9d",
}


def load_script(name: str):
    module_name = f"connection_identity_{name}_under_test"
    paramiko_stub = types.ModuleType("paramiko")

    class SSHClient:
        pass

    class RejectPolicy:
        pass

    paramiko_stub.SSHClient = SSHClient
    paramiko_stub.RejectPolicy = RejectPolicy
    previous_paramiko = sys.modules.get("paramiko")
    previous_module = sys.modules.get(module_name)
    sys.modules["paramiko"] = paramiko_stub
    try:
        spec = importlib.util.spec_from_file_location(module_name, SCRIPT_PATHS[name])
        if spec is None or spec.loader is None:
            raise AssertionError(f"cannot import {name} release script")
        module = importlib.util.module_from_spec(spec)
        sys.modules[module_name] = module
        spec.loader.exec_module(module)
        return module
    finally:
        if previous_paramiko is None:
            sys.modules.pop("paramiko", None)
        else:
            sys.modules["paramiko"] = previous_paramiko
        if previous_module is None:
            sys.modules.pop(module_name, None)
        else:
            sys.modules[module_name] = previous_module


def manifest_connection_identity() -> dict[str, str]:
    return {
        "apiBaseUrl": SECRETS["YY_API_BASE_URL"],
        "appKeySha256": hashlib.sha256(
            SECRETS["YY_APP_KEY"].encode("utf-8")
        ).hexdigest(),
        "platformKeySha256": hashlib.sha256(
            SECRETS["YY_PLATFORM_KEY"].encode("utf-8")
        ).hexdigest(),
        "authorizedPlatformKeySha256": hashlib.sha256(
            SECRETS["YY_AUTHORIZED_PLATFORM_KEY"].encode("utf-8")
        ).hexdigest(),
    }


class ConnectionIdentityPureGateTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.modules = {name: load_script(name) for name in SCRIPT_PATHS}

    def test_finalized_manifest_shape_and_all_environment_values_are_required(self) -> None:
        expected = manifest_connection_identity()
        for module in self.modules.values():
            with self.assertRaisesRegex(RuntimeError, "connectionIdentity"):
                module.validate_manifest_connection_identity({})
            parsed = module.validate_manifest_connection_identity(
                {"connectionIdentity": expected}
            )
            self.assertEqual(parsed, expected)
            for missing in SECRETS:
                environment = dict(SECRETS)
                environment.pop(missing)
                with self.assertRaisesRegex(RuntimeError, missing):
                    module.load_connection_identity_from_environment(
                        parsed, environment
                    )

    def test_key_hash_mismatch_fails_without_secret_disclosure(self) -> None:
        bad = manifest_connection_identity()
        bad["appKeySha256"] = "0" * 64
        for module in self.modules.values():
            with self.assertRaises(RuntimeError) as raised:
                module.load_connection_identity_from_environment(bad, dict(SECRETS))
            message = str(raised.exception)
            self.assertIn("hash mismatch", message)
            for secret in SECRETS.values():
                self.assertNotIn(secret, message)

            evidence = manifest_connection_identity()
            for variable in SECRETS:
                changed = dict(SECRETS)
                changed[variable] = (
                    "https://wrong.example.test/api/"
                    if variable == "YY_API_BASE_URL"
                    else "wrong-runtime-identity-value"
                )
                with self.assertRaises(RuntimeError) as changed_error:
                    module.load_connection_identity_from_environment(
                        evidence, changed
                    )
                for secret in changed.values():
                    self.assertNotIn(secret, str(changed_error.exception))

    def test_lifecycle_and_download_origins_must_match_build_api_origin(self) -> None:
        for module in self.modules.values():
            module.validate_connection_origins(
                SECRETS["YY_API_BASE_URL"],
                {
                    "lifecycle-url": "https://release.example.test:443/api/public/lifecycle",
                    "download-url": "https://release.example.test/downloads/app.apk",
                },
            )
            for bad_url in (
                "http://release.example.test/api/public/lifecycle",
                "https://other.example.test/api/public/lifecycle",
                "https://release.example.test:444/api/public/lifecycle",
            ):
                with self.assertRaisesRegex(RuntimeError, "scheme, host and port"):
                    module.validate_connection_origins(
                        SECRETS["YY_API_BASE_URL"], {"public-url": bad_url}
                    )

    def test_remote_database_values_are_exact_and_never_rendered(self) -> None:
        evidence = manifest_connection_identity()
        for module in self.modules.values():
            identity = module.load_connection_identity_from_environment(
                evidence, dict(SECRETS)
            )
            module.validate_remote_connection_identity(
                identity,
                SECRETS["YY_PLATFORM_KEY"],
                SECRETS["YY_AUTHORIZED_PLATFORM_KEY"],
                [SECRETS["YY_APP_KEY"]],
            )
            self.assertNotIn(SECRETS["YY_APP_KEY"], repr(identity))
            with self.assertRaises(RuntimeError) as raised:
                module.validate_remote_connection_identity(
                    identity,
                    "wrong-root-key",
                    SECRETS["YY_AUTHORIZED_PLATFORM_KEY"],
                    ["wrong-app-key"],
                )
            message = str(raised.exception)
            self.assertIn("platformKey", message)
            self.assertIn("appKey", message)
            for secret in SECRETS.values():
                self.assertNotIn(secret, message)

    def test_platform_context_selects_the_exact_unique_root_and_child(self) -> None:
        evidence = manifest_connection_identity()
        for module in self.modules.values():
            identity = module.load_connection_identity_from_environment(
                evidence, dict(SECRETS)
            )
            rows = [
                (1, "different-root", 1, 0),
                (2, SECRETS["YY_PLATFORM_KEY"], 1, 0),
                (3, "different-authorized", 2, 2),
                (4, SECRETS["YY_AUTHORIZED_PLATFORM_KEY"], 2, 2),
            ]
            root, authorized = module.select_platform_connection_context(rows, identity)
            self.assertEqual(root[0], 2)
            self.assertEqual(authorized[0], 4)
            with self.assertRaisesRegex(RuntimeError, "exactly one"):
                module.select_platform_connection_context(rows + [rows[3]], identity)

    def test_api_base_url_requires_the_same_canonical_form_in_both_scripts(self) -> None:
        for module in self.modules.values():
            self.assertEqual(
                module.normalize_api_base_url("https://release.example.test/api/"),
                "https://release.example.test/api/",
            )
            self.assertEqual(
                module.normalize_api_base_url("https://Example.TEST/api/"),
                "https://example.test/api/",
            )
            for value in (
                "https://Example.TEST/api/",
                "https://release.example.test/api/?x=/",
                "https://release.example.test/api/#fragment",
            ):
                with self.assertRaises(RuntimeError):
                    module.validate_manifest_connection_identity(
                        {
                            "connectionIdentity": {
                                **manifest_connection_identity(),
                                "apiBaseUrl": value,
                            }
                        }
                    )

    def test_validation_errors_do_not_echo_lifecycle_credentials_or_payloads(self) -> None:
        publisher = self.modules["publisher"]
        verifier = self.modules["verifier"]
        with self.assertRaises(RuntimeError) as payload_error:
            publisher.validate_lifecycle_payload(
                None,
                "https://release.example.test/downloads/app.apk",
                {"code": 0, "debug": SECRETS["YY_APP_KEY"]},
            )
        self.assertNotIn(SECRETS["YY_APP_KEY"], str(payload_error.exception))
        with self.assertRaises(RuntimeError) as url_error:
            verifier.validate_https_url(
                "https://user:"
                + SECRETS["YY_PLATFORM_KEY"]
                + "@release.example.test/api/public/lifecycle",
                "lifecycle-url",
            )
        self.assertNotIn(SECRETS["YY_PLATFORM_KEY"], str(url_error.exception))


class PublisherLogRedactionTest(unittest.TestCase):
    def test_sensitive_database_query_result_is_returned_but_not_logged(self) -> None:
        module = load_script("publisher")

        class Channel:
            @staticmethod
            def recv_exit_status() -> int:
                return 0

        class Stream:
            def __init__(self, value: bytes):
                self.value = value
                self.channel = Channel()

            def read(self) -> bytes:
                return self.value

        class Client:
            @staticmethod
            def exec_command(_command: str, get_pty: bool = False):
                del get_pty
                return None, Stream((SECRETS["YY_APP_KEY"] + "\n").encode()), Stream(b"")

        captured = io.StringIO()
        with redirect_stdout(captured):
            output = module.run(
                Client(),
                "read-sensitive-value",
                "application-context",
                sensitive_output=True,
            )
        self.assertEqual(output.strip(), SECRETS["YY_APP_KEY"])
        self.assertNotIn(SECRETS["YY_APP_KEY"], captured.getvalue())

    def test_failed_sensitive_query_redacts_partial_stdout_and_stderr(self) -> None:
        class Channel:
            @staticmethod
            def recv_exit_status() -> int:
                return 1

        class Stream:
            def __init__(self, value: bytes):
                self.value = value
                self.channel = Channel()

            def read(self) -> bytes:
                return self.value

        class Client:
            @staticmethod
            def exec_command(_command: str, get_pty: bool = False):
                del get_pty
                value = SECRETS["YY_PLATFORM_KEY"].encode()
                return None, Stream(value), Stream(value)

        for name in SCRIPT_PATHS:
            module = load_script(name)
            with self.assertRaises(RuntimeError) as raised:
                module.run(
                    Client(),
                    "read-sensitive-value",
                    "platform-context",
                    sensitive_output=True,
                )
            self.assertIn("redacted", str(raised.exception))
            self.assertNotIn(SECRETS["YY_PLATFORM_KEY"], str(raised.exception))


class StaticContractFreezeTest(unittest.TestCase):
    def test_publish_and_verify_keep_the_same_fail_closed_contract(self) -> None:
        for name, path in SCRIPT_PATHS.items():
            source = path.read_text(encoding="utf-8")
            compile(source, str(path), "exec")
            for marker in (
                'manifest.get("connectionIdentity")',
                '"YY_API_BASE_URL"',
                '"YY_APP_KEY"',
                '"YY_PLATFORM_KEY"',
                '"YY_AUTHORIZED_PLATFORM_KEY"',
                "hmac.compare_digest",
                "validate_connection_origins",
                "validate_remote_connection_identity",
                "select_platform_connection_context",
            ):
                self.assertIn(marker, source, f"missing {marker!r} in {name}")
            self.assertIn("a.status = 1 AND p.status = 1", source)
            self.assertNotIn("ORDER BY id LIMIT 1", source)
        publisher = SCRIPT_PATHS["publisher"].read_text(encoding="utf-8")
        verifier = SCRIPT_PATHS["verifier"].read_text(encoding="utf-8")
        self.assertIn("sensitive_output=True", publisher)
        self.assertIn('"connection_identity": connection_identity.public_evidence()', publisher)
        self.assertIn('publication.get("connection_identity")', verifier)


if __name__ == "__main__":
    unittest.main()
