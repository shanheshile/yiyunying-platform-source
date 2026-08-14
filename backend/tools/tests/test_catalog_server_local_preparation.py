#!/usr/bin/env python3
"""Dynamic contract for zero-exfiltration catalog conflict preparation."""

from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import socket
import sys
import unittest
from unittest import mock
from types import SimpleNamespace


TOOLS = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("deploy_ssh_server_local", TOOLS / "deploy-ssh.py")
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Unable to load deployment module")
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


def valid_receipt(batch: str = "catalog-repair-20260814-201530-0123456789abcdef") -> str:
    items = []
    for index, (action, path_hash) in enumerate(
        sorted(MODULE.CATALOG_CONFLICT_SERVER_LOCAL_PATHS.items())
    ):
        items.append(
            {
                "action": action,
                "path_sha256": path_hash,
                "replacement_sha256": str(index + 1) * 64,
                "replacement_size_bytes": 100 + index,
            }
        )
    return json.dumps(
        {
            "schema": 1,
            "status": "prepared",
            "batch": batch,
            "source_plan_sha256": "a" * 64,
            "items": items,
        },
        separators=(",", ":"),
    ) + "\n"


class ServerLocalPreparationContractTests(unittest.TestCase):
    def test_omission_remains_disabled_and_local_inputs_require_explicit_mode(self) -> None:
        self.assertEqual(("", None), MODULE.resolve_catalog_conflict_mode("", "", "", ""))
        with self.assertRaisesRegex(RuntimeError, "require --catalog-conflict-repair-mode local"):
            MODULE.resolve_catalog_conflict_mode("", "plan", "jpeg", "heic")
        with self.assertRaisesRegex(RuntimeError, "cannot accept local"):
            MODULE.resolve_catalog_conflict_mode("server-local", "plan", "", "")

    def test_local_and_server_local_modes_are_mutually_exclusive(self) -> None:
        self.assertEqual(
            ("server-local", None),
            MODULE.resolve_catalog_conflict_mode("server-local", "", "", ""),
        )
        fixture = {"batch": "fixture", "prepared": {}}
        with mock.patch.object(MODULE, "load_catalog_conflict_inputs", return_value=fixture) as loader:
            self.assertEqual(
                ("local", fixture),
                MODULE.resolve_catalog_conflict_mode("local", "plan", "jpeg", "heic"),
            )
            loader.assert_called_once_with("plan", "jpeg", "heic")
        for arguments in (("local", "", "jpeg", "heic"), ("unknown", "", "", "")):
            with self.assertRaises(RuntimeError):
                MODULE.resolve_catalog_conflict_mode(*arguments)

    def test_preparation_command_is_fixed_and_has_all_three_confirmations(self) -> None:
        stage = "/tmp/yiyunying-catalog-conflict-20260814-201530-0123456789abcdef"
        batch = "catalog-repair-20260814-201530-0123456789abcdef"
        command = MODULE.catalog_conflict_server_local_preparation_command(
            "/srv/backend",
            stage,
            batch,
            "/private/current/database.sql.gz",
            "/private/current/public-uploads.tar.gz",
        )
        self.assertIn("prepare-catalog-public-conflicts-server-local.php", command)
        self.assertIn("--maintenance-confirmed --backup-confirmed --gate-confirmed", command)
        self.assertIn("timeout --signal=TERM --kill-after=10s 1200s", command)
        for disabled in (
            "display_errors=0", "display_startup_errors=0", "log_errors=0", "html_errors=0"
        ):
            self.assertIn(disabled, command)
        self.assertIn(stage, command)
        self.assertNotIn("sftp", command.lower())
        self.assertNotIn("http://", command.lower())
        self.assertNotIn("https://", command.lower())
        with self.assertRaises(ValueError):
            MODULE.catalog_conflict_server_local_preparation_command(
                "/srv/backend", "/tmp/not-pinned", batch, "/db", "/uploads"
            )
        cleanup = MODULE.catalog_conflict_stage_cleanup_command(stage)
        for boundary in (
            "test -d", "test ! -L", "stat -c %u", "stat -c %g", "stat -c %h",
            "stat -c %a", "rm -f --", "find \"$STAGE\"", "exit 71", "rmdir --",
        ):
            self.assertIn(boundary, cleanup)
        for name in (
            "source-plan.json", "runtime-plan.json", "jpeg-prepared.png", "heic-prepared.png"
        ):
            self.assertIn(name, cleanup)
        self.assertNotIn("rm -rf", cleanup)
        with self.assertRaises(ValueError):
            MODULE.catalog_conflict_stage_cleanup_command("/tmp/not-pinned")

    def test_media_runtime_is_fully_audited_before_its_first_execution(self) -> None:
        command = MODULE.runtime_dependency_preflight_command(
            require_catalog_conflict_repair=True
        )
        for boundary in (
            "/",
            "/opt",
            "/opt/yiyunying",
            MODULE.MEDIA_RUNTIME_ROOT,
            MODULE.MEDIA_RUNTIME_VERSION,
            MODULE.MEDIA_FFMPEG_SHA256,
            MODULE.MEDIA_FFPROBE_SHA256,
            str(MODULE.MEDIA_FFMPEG_SIZE),
            str(MODULE.MEDIA_FFPROBE_SIZE),
            "stat -c %U:%G",
            "stat -c %h",
            "stat -c %a",
            "readlink -f",
        ):
            self.assertIn(boundary, command)
        first_execution = min(
            command.index('"$FFMPEG_BIN" -version'),
            command.index('"$FFPROBE_BIN" -version'),
        )
        for integrity_gate in (
            MODULE.MEDIA_FFMPEG_SHA256,
            MODULE.MEDIA_FFPROBE_SHA256,
            'test "$(readlink -- "$CURRENT")"',
            'test "$(readlink -f -- "$CURRENT")"',
        ):
            self.assertLess(command.index(integrity_gate), first_execution)

    def test_sensitive_remote_output_is_never_printed_or_exposed_in_errors(self) -> None:
        class FakeChannel:
            def __init__(self, stdout: bytes, stderr: bytes, status: int) -> None:
                self.stdout = bytearray(stdout)
                self.stderr = bytearray(stderr)
                self.status = status
                self.closed = False

            def settimeout(self, _value: int) -> None:
                return None

            def recv_ready(self) -> bool:
                return bool(self.stdout)

            def recv_stderr_ready(self) -> bool:
                return bool(self.stderr)

            def recv(self, length: int) -> bytes:
                value = bytes(self.stdout[:length])
                del self.stdout[:length]
                return value

            def recv_stderr(self, length: int) -> bytes:
                value = bytes(self.stderr[:length])
                del self.stderr[:length]
                return value

            def exit_status_ready(self) -> bool:
                return not self.stdout and not self.stderr

            def recv_exit_status(self) -> int:
                return self.status

            def close(self) -> None:
                self.closed = True

        class FakeClient:
            def __init__(self, channel: FakeChannel) -> None:
                self.channel = channel

            def exec_command(self, *_args: object, **_kwargs: object) -> tuple[None, object, None]:
                return None, SimpleNamespace(channel=self.channel), None

        class TimeoutChannel(FakeChannel):
            def recv_ready(self) -> bool:
                raise socket.timeout("remote output timeout")

        secret = b"/private/raw-media-path SECRET_PASSWORD"
        failures = [
            FakeChannel(secret, b"", 1),
            FakeChannel(b"", secret, 0),
            FakeChannel(secret * 400, b"", 0),
        ]
        for channel in failures:
            with self.subTest(status=channel.status, length=len(channel.stdout) + len(channel.stderr)):
                with mock.patch("builtins.print") as printer:
                    with self.assertRaises(RuntimeError) as caught:
                        MODULE.run_redacted_capture(FakeClient(channel), "fixed", "sensitive-step")
                    printer.assert_not_called()
                self.assertNotIn("raw-media-path", str(caught.exception))
                self.assertNotIn("SECRET_PASSWORD", str(caught.exception))

        timed_out = TimeoutChannel(secret, b"", 0)
        with mock.patch("builtins.print") as printer:
            with self.assertRaisesRegex(RuntimeError, "remote output redacted") as caught:
                MODULE.run_redacted_capture(FakeClient(timed_out), "fixed", "sensitive-step")
            printer.assert_not_called()
        self.assertTrue(timed_out.closed)
        self.assertNotIn("raw-media-path", str(caught.exception))

        safe = valid_receipt()
        channel = FakeChannel(safe.encode("utf-8"), b"", 0)
        with mock.patch("builtins.print") as printer:
            captured = MODULE.run_redacted_capture(FakeClient(channel), "fixed", "sensitive-step")
            printer.assert_not_called()
        self.assertEqual(safe, captured)

    def test_receipt_parser_is_duplicate_free_redacted_and_hash_bound(self) -> None:
        batch = "catalog-repair-20260814-201530-0123456789abcdef"
        receipt = MODULE.parse_catalog_conflict_server_local_receipt(valid_receipt(batch), batch)
        self.assertEqual("prepared", receipt["status"])
        self.assertNotIn("path", receipt)
        invalid = [
            valid_receipt(batch) + valid_receipt(batch),
            valid_receipt("catalog-repair-other"),
            valid_receipt(batch).replace('"schema":1', '"schema":1,"schema":1'),
            valid_receipt(batch).replace("a" * 64, "z" * 64),
            valid_receipt(batch).replace(
                next(iter(MODULE.CATALOG_CONFLICT_SERVER_LOCAL_PATHS.values())), "0" * 64
            ),
        ]
        for value in invalid:
            with self.subTest(value=value[:80]):
                with self.assertRaises(RuntimeError):
                    MODULE.parse_catalog_conflict_server_local_receipt(value, batch)

    def test_deployment_order_is_backup_gate_prepare_plan_repair(self) -> None:
        source = (TOOLS / "deploy-ssh.py").read_text(encoding="utf-8")
        hooks = [
            source.index('"catalog-maintenance"'),
            source.index('"public-uploads-backup"'),
            source.index('"database-backup"'),
            source.index('"catalog-gate-closed-readback"'),
            source.index('"catalog-conflict-server-local-preparation"'),
            source.index('"catalog-conflict-runtime-plan-create"'),
            source.index('"catalog-conflict-repair-apply"'),
            source.index('"catalog-public-quarantine-dry-run"'),
        ]
        self.assertEqual(sorted(hooks), hooks)
        self.assertIn("if conflict_mode == \"server-local\"", source)
        self.assertIn("if conflict_mode == \"local\"", source)
        self.assertIn("if conflict_enabled:", source)
        self.assertIn("conflict_output = run_redacted_capture(", source)
        self.assertIn("conflict_readback_output = run_redacted_capture(", source)
        self.assertIn("[catalog-conflict-repair-apply] validated", source)
        self.assertIn("[catalog-conflict-repair-readback] validated", source)
        self.assertIn('"catalog-conflict-stage-cleanup-after-rollback"', source)
        cleanup = source.index('"catalog-conflict-stage-cleanup"')
        self.assertLess(cleanup, source.index('"php-start-or-restart"'))
        self.assertLess(cleanup, source.index('"health-check"'))
        self.assertLess(cleanup, source.index('"catalog-maintenance-release"'))
        self.assertNotIn("sftp.get(", source)

    def test_stage_cleanup_failure_stays_inside_the_rollback_transaction(self) -> None:
        labels: list[str] = []

        class FakeTransport:
            def is_active(self) -> bool:
                return True

            def set_keepalive(self, _interval: int) -> None:
                return None

            def get_remote_server_key(self) -> object:
                return SimpleNamespace(get_fingerprint=lambda: b"\x01\x02")

        class FakeClient:
            closed = False

            def load_host_keys(self, _path: str) -> None:
                return None

            def set_missing_host_key_policy(self, _policy: object) -> None:
                return None

            def connect(self, *_args: object, **_kwargs: object) -> None:
                return None

            def get_transport(self) -> FakeTransport:
                return FakeTransport()

            def close(self) -> None:
                self.closed = True

        fake_client = FakeClient()
        fake_paramiko = SimpleNamespace(
            SSHClient=lambda: fake_client,
            RejectPolicy=lambda: object(),
        )

        def fake_run(_client: object, _command: str, label: str) -> str:
            labels.append(label)
            if label == "catalog-conflict-stage-cleanup":
                raise RuntimeError("private stage cleanup failed")
            responses = {
                "environment-check": "yes",
                "catalog-binding-apply": "CATALOG_BINDING_REPORT=/private/binding.json\n",
                "catalog-public-quarantine-apply": "report=/private/quarantine.json\n",
                "catalog-apply": "report=/private/catalog.json\n",
            }
            return responses.get(label, "")

        def fake_run_with_status(
            _client: object, _command: str, label: str, _allowed: set[int]
        ) -> tuple[str, int]:
            labels.append(label)
            return "", 0

        def fake_redacted(_client: object, _command: str, label: str) -> str:
            labels.append(label)
            if label == "catalog-conflict-server-local-preparation":
                return valid_receipt()
            return (
                "pending=0\nalready_repaired=2\nconflicts=0\nrepaired=2\nzero_work=0\n"
                "report=repair-fixture-12345678.json\n"
            )

        arguments = [
            "deploy-ssh.py",
            "--host", "example.invalid",
            "--user", "deploy",
            "--known-hosts", "known-hosts",
            "--archive", "release.tar.gz",
            "--remote-root", "/srv/yiyunying/backend",
            "--release-version", "1.0.0",
            "--release-identity", "identity.json",
            "--build-source-commit", "a" * 40,
            "--maintenance-command", "maintenance-enter",
            "--maintenance-release-command", "maintenance-exit",
            "--catalog-conflict-repair-mode", "server-local",
            "--health-url", "https://example.invalid/api/health",
            "--db-name", "app",
            "--db-user", "app",
        ]
        for migration in MODULE.REQUIRED_RELEASE_MIGRATIONS:
            arguments.extend(("--migration", migration))

        with (
            mock.patch.dict(
                os.environ,
                {"YY_SSH_PASSWORD": "not-printed", "YY_DB_PASSWORD": "not-printed"},
            ),
            mock.patch.dict("sys.modules", {"paramiko": fake_paramiko}),
            mock.patch("sys.argv", arguments),
            mock.patch.object(os.path, "isfile", return_value=True),
            mock.patch.object(MODULE, "sha256_file", return_value="c" * 64),
            mock.patch.object(
                MODULE,
                "validate_release_archive",
                return_value=("b" * 64, "a" * 40),
            ),
            mock.patch.object(MODULE.time, "strftime", return_value="20260814-201530"),
            mock.patch.object(MODULE.secrets, "token_hex", return_value="0123456789abcdef"),
            mock.patch.object(MODULE, "run", side_effect=fake_run),
            mock.patch.object(MODULE, "run_with_status", side_effect=fake_run_with_status),
            mock.patch.object(MODULE, "run_redacted_capture", side_effect=fake_redacted),
            mock.patch.object(MODULE, "run_sftp_operation", return_value=None),
        ):
            with self.assertRaisesRegex(RuntimeError, "private stage cleanup failed"):
                MODULE.main()

        cleanup_index = labels.index("catalog-conflict-stage-cleanup")
        self.assertNotIn("php-start-or-restart", labels[: cleanup_index + 1])
        self.assertNotIn("health-check", labels[: cleanup_index + 1])
        self.assertNotIn("catalog-maintenance-release", labels[: cleanup_index + 1])
        for rollback_label in (
            "code-rollback",
            "uploads-rollback",
            "database-rollback",
            "php-start-or-restart-after-rollback",
            "health-check-after-rollback",
            "catalog-maintenance-release-after-rollback",
            "catalog-conflict-stage-cleanup-after-rollback",
        ):
            self.assertIn(rollback_label, labels[cleanup_index + 1 :])
        self.assertTrue(fake_client.closed)

    def test_release_archive_requires_server_local_tool_and_contract(self) -> None:
        self.assertIn(
            "backend/tools/prepare-catalog-public-conflicts-server-local.php",
            MODULE.REQUIRED_RELEASE_FILES,
        )
        self.assertIn(
            "backend/tools/catalog-conflict-server-local-preparation-contract.php",
            MODULE.REQUIRED_RELEASE_FILES,
        )


if __name__ == "__main__":
    unittest.main(verbosity=2)
