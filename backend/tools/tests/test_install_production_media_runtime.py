from __future__ import annotations

import gzip
import hashlib
import importlib.util
import io
import json
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path
from types import ModuleType, SimpleNamespace
import sys
import tarfile
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "backend" / "tools" / "install-production-media-runtime.py"
SPEC = importlib.util.spec_from_file_location("production_media_runtime_installer", SCRIPT)
assert SPEC and SPEC.loader
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


def remote_namespace() -> dict[str, object]:
    namespace: dict[str, object] = {"__name__": "remote_state_machine_test"}
    with mock.patch.dict(sys.modules, {"grp": ModuleType("grp"), "pwd": ModuleType("pwd")}):
        exec(compile(MODULE.REMOTE_INSTALLER_SOURCE, "<remote-installer>", "exec"), namespace)
    return namespace


class FakeClient:
    def __init__(self) -> None:
        self.closed = False

    def close(self) -> None:
        self.closed = True


def build_layer(extra: list[tuple[str, bytes | str, str]] | None = None):
    payloads = {
        "ffmpeg": b"fixture-ffmpeg",
        "ffprobe": b"fixture-ffprobe",
        "versions.json": json.dumps({"ffmpeg": "8.1.2"}).encode("utf-8"),
    }
    raw = io.BytesIO()
    with tarfile.open(fileobj=raw, mode="w") as archive:
        for name, content in payloads.items():
            item = tarfile.TarInfo(name)
            item.mode = 0o755 if name != "versions.json" else 0o644
            item.size = len(content)
            archive.addfile(item, io.BytesIO(content))
        for name, content, kind in extra or []:
            item = tarfile.TarInfo(name)
            if kind == "file":
                data = content if isinstance(content, bytes) else content.encode("utf-8")
                item.mode = 0o644
                item.size = len(data)
                archive.addfile(item, io.BytesIO(data))
            elif kind == "dir":
                item.type = tarfile.DIRTYPE
                item.mode = 0o755
                archive.addfile(item)
            elif kind == "symlink":
                item.type = tarfile.SYMTYPE
                item.mode = 0o777
                item.linkname = str(content)
                archive.addfile(item)
            else:
                raise AssertionError(kind)
    uncompressed = raw.getvalue()
    compressed = gzip.compress(uncompressed, mtime=0)
    members = {
        name: MODULE.MemberContract(
            len(content),
            hashlib.sha256(content).hexdigest(),
            0o755 if name != "versions.json" else 0o644,
        )
        for name, content in payloads.items()
    }
    payload_size = sum(len(value) for value in payloads.values()) + sum(
        len(content if isinstance(content, bytes) else content.encode("utf-8"))
        for _name, content, kind in extra or []
        if kind == "file"
    )
    contract = MODULE.LayerContract(
        len(compressed),
        hashlib.sha256(compressed).hexdigest(),
        hashlib.sha256(uncompressed).hexdigest(),
        len(uncompressed),
        3 + len(extra or []),
        payload_size,
        members,
    )
    return compressed, contract


def valid_install_receipt(**overrides: object) -> str:
    receipt: dict[str, object] = {
        "MEDIA_RUNTIME_INSTALL": "pass",
        "version": MODULE.VERSION,
        "previous": "old-version",
        "current": MODULE.VERSION_DIRECTORY,
        "already_current": False,
        "platform": "linux/amd64",
        "free_bytes": MODULE.MINIMUM_FREE_BYTES,
    }
    receipt.update(overrides)
    return json.dumps(receipt, sort_keys=True, separators=(",", ":")) + "\n"


class MediaRuntimeInstallerTests(unittest.TestCase):
    def write_layer(self, directory: Path, data: bytes) -> Path:
        path = directory / "layer.tar.gz"
        path.write_bytes(data)
        return path

    def test_good_layer_and_virtual_root_link_pass_without_extracting_link(self) -> None:
        data, contract = build_layer(
            [
                ("etc", b"", "dir"),
                ("etc/ffmpeg-link", "/ffmpeg", "symlink"),
            ]
        )
        with tempfile.TemporaryDirectory() as temporary:
            result = MODULE.inspect_layer(
                self.write_layer(Path(temporary), data), contract
            )
        self.assertEqual(contract.compressed_sha256, result["sha256"])

    def test_tar_link_escape_is_rejected(self) -> None:
        data, contract = build_layer(
            [("nested/link", "../../../outside", "symlink")]
        )
        with tempfile.TemporaryDirectory() as temporary:
            with self.assertRaisesRegex(RuntimeError, "escaping or broken link"):
                MODULE.inspect_layer(self.write_layer(Path(temporary), data), contract)

    def test_extra_reserved_basename_is_rejected(self) -> None:
        data, contract = build_layer(
            [("nested/ffmpeg", b"shadow-binary", "file")]
        )
        with tempfile.TemporaryDirectory() as temporary:
            with self.assertRaisesRegex(RuntimeError, "extra reserved basename"):
                MODULE.inspect_layer(self.write_layer(Path(temporary), data), contract)

    def test_compressed_hash_mismatch_fails_before_tar_use(self) -> None:
        data, contract = build_layer()
        bad = MODULE.LayerContract(
            contract.compressed_size,
            "0" * 64,
            contract.diff_id_sha256,
            contract.uncompressed_size,
            contract.member_count,
            contract.payload_size,
            contract.members,
        )
        with tempfile.TemporaryDirectory() as temporary:
            with self.assertRaisesRegex(RuntimeError, "compressed hash"):
                MODULE.inspect_layer(self.write_layer(Path(temporary), data), bad)

    def test_member_hash_mismatch_is_rejected(self) -> None:
        data, contract = build_layer()
        members = dict(contract.members)
        original = members["ffprobe"]
        members["ffprobe"] = MODULE.MemberContract(
            original.size, "f" * 64, original.mode
        )
        bad = MODULE.LayerContract(
            contract.compressed_size,
            contract.compressed_sha256,
            contract.diff_id_sha256,
            contract.uncompressed_size,
            contract.member_count,
            contract.payload_size,
            members,
        )
        with tempfile.TemporaryDirectory() as temporary:
            with self.assertRaisesRegex(RuntimeError, "member hash"):
                MODULE.inspect_layer(self.write_layer(Path(temporary), data), bad)

    def test_remote_channel_timeout_closes_channel(self) -> None:
        class NeverFinishes:
            closed = False

            def exit_status_ready(self):
                return False

            def recv_ready(self):
                return False

            def recv_stderr_ready(self):
                return False

            def close(self):
                self.closed = True

        channel = NeverFinishes()
        with mock.patch.object(MODULE.time, "monotonic", side_effect=[0.0, 1.0, 20.0]), mock.patch.object(
            MODULE.time, "sleep", return_value=None
        ):
            with self.assertRaisesRegex(TimeoutError, "reviewed timeout"):
                MODULE.collect_channel(channel, 10, "password")
        self.assertTrue(channel.closed)

    def test_final_channel_drain_is_also_bounded(self) -> None:
        class FinalBurst:
            def __init__(self) -> None:
                self.chunks = [b"A" * 40_000, b"B" * 40_000]
                self.closed = False

            def exit_status_ready(self):
                return True

            def recv_ready(self):
                return bool(self.chunks)

            def recv(self, _size):
                return self.chunks.pop(0)

            def recv_stderr_ready(self):
                return False

            def close(self):
                self.closed = True

        channel = FinalBurst()
        with self.assertRaisesRegex(RuntimeError, "combined output exceeded"):
            MODULE.collect_channel(channel, 10, "password")
        self.assertTrue(channel.closed)

    def test_final_stdout_and_stderr_share_one_64kib_budget(self) -> None:
        class CombinedFinalBurst:
            def __init__(self) -> None:
                self.stdout_chunks = [b"A" * 40_000]
                self.stderr_chunks = [b"B" * 30_000]
                self.closed = False

            def exit_status_ready(self):
                return True

            def recv_ready(self):
                return bool(self.stdout_chunks)

            def recv(self, _size):
                return self.stdout_chunks.pop(0)

            def recv_stderr_ready(self):
                return bool(self.stderr_chunks)

            def recv_stderr(self, _size):
                return self.stderr_chunks.pop(0)

            def close(self):
                self.closed = True

        channel = CombinedFinalBurst()
        with self.assertRaisesRegex(RuntimeError, "combined output exceeded"):
            MODULE.collect_channel(channel, 10, "password")
        self.assertTrue(channel.closed)

    def test_install_style_remote_call_rejects_stderr_even_with_status_zero(self) -> None:
        class CompleteChannel:
            def __init__(self) -> None:
                self.stdout_chunks = [valid_install_receipt().encode("utf-8")]
                self.stderr_chunks = [b"unexpected warning\n"]

            def exit_status_ready(self):
                return True

            def recv_ready(self):
                return bool(self.stdout_chunks)

            def recv(self, _size):
                return self.stdout_chunks.pop(0)

            def recv_stderr_ready(self):
                return bool(self.stderr_chunks)

            def recv_stderr(self, _size):
                return self.stderr_chunks.pop(0)

            def recv_exit_status(self):
                return 0

            def close(self):
                pass

        channel = CompleteChannel()
        client = SimpleNamespace(
            exec_command=lambda *_args, **_kwargs: (
                None,
                SimpleNamespace(channel=channel),
                None,
            )
        )
        with self.assertRaisesRegex(RuntimeError, "unexpected stderr"):
            MODULE.run_remote(
                client,
                "command",
                "media runtime install",
                "password",
                emit_output=False,
                require_empty_stderr=True,
            )

    def test_atomic_switch_verification_failure_rolls_back(self) -> None:
        state = {"target": "old-version"}

        def read():
            return state["target"]

        def write(value):
            state["target"] = value

        def fail():
            raise RuntimeError("post-switch readback failed")

        with self.assertRaisesRegex(RuntimeError, "post-switch"):
            MODULE.transition_with_rollback(read, write, fail, "new-version")
        self.assertEqual("old-version", state["target"])

    def test_atomic_switch_write_failure_after_mutation_also_rolls_back(self) -> None:
        state = {"target": "old-version", "first": True}

        def read():
            return state["target"]

        def write(value):
            state["target"] = value
            if state["first"]:
                state["first"] = False
                raise OSError("fsync failed after replace")

        with self.assertRaisesRegex(OSError, "fsync failed"):
            MODULE.transition_with_rollback(read, write, lambda: None, "new-version")
        self.assertEqual("old-version", state["target"])

    def test_remote_replace_then_fsync_failure_rolls_back_old_target(self) -> None:
        namespace = remote_namespace()
        state = {"target": "old-version", "receipt": 0}

        def read_current():
            return state["target"]

        def save_previous(value):
            self.assertEqual("old-version", value)
            state["receipt"] += 1

        def write_current(value):
            state["target"] = value
            if value == "new-version":
                raise OSError("fsync failed after os.replace")

        namespace.update(
            {
                "read_current": read_current,
                "save_previous": save_previous,
                "write_current": write_current,
                "audit_version": lambda _path: None,
                "run_www": lambda _args: None,
            }
        )
        with self.assertRaisesRegex(OSError, "fsync failed"):
            namespace["activate"](SimpleNamespace(name="new-version"))
        self.assertEqual("old-version", state["target"])
        self.assertEqual(1, state["receipt"])

    def test_remote_uncertain_rollback_is_explicit_recovery_required(self) -> None:
        namespace = remote_namespace()
        state = {"target": "old-version"}

        def write_current(value):
            state["target"] = value
            raise OSError("directory fsync failed")

        namespace.update(
            {
                "read_current": lambda: state["target"],
                "save_previous": lambda _value: None,
                "write_current": write_current,
                "audit_version": lambda _path: None,
                "run_www": lambda _args: None,
            }
        )
        with self.assertRaisesRegex(RuntimeError, "RECOVERY_REQUIRED"):
            namespace["activate"](SimpleNamespace(name="new-version"))
        self.assertEqual("old-version", state["target"])

    def test_remote_repeated_install_only_audits_and_smokes(self) -> None:
        namespace = remote_namespace()
        calls = {"audit": 0, "smoke": 0}
        namespace.update(
            {
                "read_current": lambda: "8.1.2-3bfa407c614a",
                "audit_version": lambda _path: calls.__setitem__("audit", calls["audit"] + 1),
                "smoke": lambda _path: calls.__setitem__("smoke", calls["smoke"] + 1),
                "save_previous": lambda _value: self.fail("receipt must be create-once"),
                "write_current": lambda _value: self.fail("current must not be replaced"),
            }
        )
        previous, repeated = namespace["activate_if_needed"](
            SimpleNamespace(name="8.1.2-3bfa407c614a")
        )
        self.assertTrue(repeated)
        self.assertEqual("8.1.2-3bfa407c614a", previous)
        self.assertEqual({"audit": 1, "smoke": 1}, calls)

    def test_log_sanitization_removes_credentials_and_tokens(self) -> None:
        password = "S3cret-Value"
        raw = f"password={password} Bearer abc.def token=qwerty plain={password}"
        cleaned = MODULE.sanitize_for_log(raw, (password,))
        self.assertNotIn(password, cleaned)
        self.assertNotIn("abc.def", cleaned)
        self.assertNotIn("qwerty", cleaned)
        self.assertGreaterEqual(cleaned.count("[REDACTED]"), 3)

    def test_remote_install_receipt_requires_one_strict_pinned_success(self) -> None:
        receipt = MODULE.parse_install_receipt(valid_install_receipt())
        self.assertEqual(MODULE.VERSION_DIRECTORY, receipt["current"])
        self.assertEqual("linux/amd64", receipt["platform"])
        for invalid in (
            valid_install_receipt() + valid_install_receipt(),
            valid_install_receipt(platform="linux/arm64"),
            valid_install_receipt(free_bytes=MODULE.MINIMUM_FREE_BYTES - 1),
            '{"MEDIA_RUNTIME_INSTALL":"pass","MEDIA_RUNTIME_INSTALL":"pass"}\n',
            "not-json\n",
        ):
            with self.subTest(invalid=invalid[:60]):
                with self.assertRaises(RuntimeError):
                    MODULE.parse_install_receipt(invalid)

        repeated = MODULE.parse_install_receipt(
            valid_install_receipt(
                previous=MODULE.VERSION_DIRECTORY,
                already_current=True,
            )
        )
        self.assertTrue(repeated["already_current"])

    def test_execute_requires_two_exact_confirmations_before_credentials(self) -> None:
        with mock.patch.dict("os.environ", {}, clear=True):
            with self.assertRaisesRegex(RuntimeError, "both reviewed confirmation"):
                MODULE.main(
                    [
                        "--host",
                        "example.invalid",
                        "--known-hosts",
                        "known_hosts",
                        "--layer",
                        "layer.tar.gz",
                        "--execute",
                    ]
                )

    def test_remote_contract_is_pinned_and_uses_no_downloader(self) -> None:
        source = MODULE.REMOTE_INSTALLER_SOURCE
        compile(source, "<remote-media-runtime-installer>", "exec")
        for value in (
            MODULE.LAYER_SHA256,
            MODULE.DIFF_ID_SHA256,
            MODULE.VERSION_DIRECTORY,
            MODULE.PINNED_MEMBERS["ffmpeg"].sha256,
            MODULE.PINNED_MEMBERS["ffprobe"].sha256,
        ):
            self.assertIn(value, source)
        self.assertNotRegex(source, r"\b(?:curl|wget|urllib|requests)\b")
        self.assertIn("os.replace(temporary,current)", source)
        self.assertIn("write_current(previous)", source)
        self.assertIn(".previous-target-", source)
        self.assertIn("fsync_dir(ROOT)", source)
        self.assertIn("-protocol_whitelist", source)
        self.assertIn('run_www([ffmpeg,"-L"])', source)
        self.assertIn("GNU General Public License", source)
        self.assertIn("libx264", source)
        self.assertIn("aac", source)
        self.assertIn('machine.sysname!="Linux"', source)
        self.assertIn('machine.machine!="x86_64"', source)
        self.assertIn("os.O_EXCL", source)
        self.assertIn("O_NOFOLLOW", source)
        self.assertNotIn("os.replace(temporary,receipt)", source)

        probe = MODULE.remote_python_probe_command()
        self.assertNotIn("storage/stt", probe)
        self.assertIn("validate_python_chain", probe)
        self.assertIn("stat -c '%u|%a|%F'", probe)
        self.assertIn("readlink --", probe)
        self.assertIn("validate_root_directory /opt", probe)
        self.assertIn("root|root|symbolic link", probe)
        self.assertIn("env -i PATH=/usr/bin:/bin", probe)
        self.assertIn("-I -S -B", probe)
        self.assertLess(probe.index("validate_python_chain"), probe.index("-I -S -B"))
        command = MODULE.installer_command(
            "/usr/bin/python3",
            "/tmp/.yiyunying-media-runtime-8.1.2-" + "a" * 32 + ".tar.gz",
        )
        self.assertIn("env -i PATH=/usr/bin:/bin", command)
        self.assertIn(" -I -S -B -c ", command)

        pinset = MODULE.oci_pinset({"sha256": MODULE.LAYER_SHA256})
        self.assertEqual("linux/amd64", pinset["platform"])
        self.assertEqual(MODULE.INDEX_DIGEST, pinset["index"])
        self.assertEqual(MODULE.AMD64_MANIFEST_DIGEST, pinset["manifest"])
        self.assertEqual(MODULE.CONFIG_DIGEST, pinset["config"])
        self.assertEqual("offline-frozen-provenance", pinset["registry_chain"])
        self.assertEqual(["layer", "diff_id", "members"], pinset["local_verification"])
        self.assertEqual(
            "sha256:" + MODULE.PINNED_MEMBERS["ffmpeg"].sha256,
            pinset["members"]["ffmpeg"],
        )

    def test_dangerous_python_response_is_rejected_before_any_stage_write(self) -> None:
        client = FakeClient()
        local = {
            "path": "layer",
            "size": MODULE.LAYER_SIZE,
            "sha256": MODULE.LAYER_SHA256,
            "fingerprint": (1, 2, MODULE.LAYER_SIZE, 3),
        }
        dangerous = "/www/wwwroot/appht.jjmxg.xyz/yiyunying-backend/storage/stt/venv/bin/python3"
        with mock.patch.dict("os.environ", {"YY_SSH_PASSWORD": "pw"}, clear=True), mock.patch.object(
            MODULE, "inspect_layer", return_value=local
        ), mock.patch.object(MODULE, "connect", return_value=client), mock.patch.object(
            MODULE, "run_remote", return_value=(0, f"MEDIA_RUNTIME_PREFLIGHT=pass\nPYTHON={dangerous}\n")
        ), mock.patch.object(MODULE, "upload_layer") as upload:
            with self.assertRaisesRegex(RuntimeError, "reviewed Python path"):
                MODULE.main(
                    ["--host", "host", "--known-hosts", "hosts", "--layer", "layer"]
                )
        upload.assert_not_called()
        self.assertTrue(client.closed)

    def test_dry_run_has_zero_upload_or_stage_write(self) -> None:
        client = FakeClient()
        local = {
            "path": "layer",
            "size": MODULE.LAYER_SIZE,
            "sha256": MODULE.LAYER_SHA256,
            "fingerprint": (1, 2, MODULE.LAYER_SIZE, 3),
        }
        seen: list[str] = []

        def remote(_client, command, _label, _password, **_kwargs):
            seen.append(command)
            return 0, "MEDIA_RUNTIME_PREFLIGHT=pass\nPYTHON=/usr/bin/python3\nFREE_BYTES=2000000000\n"

        stdout = io.StringIO()
        with mock.patch.dict("os.environ", {"YY_SSH_PASSWORD": "pw"}, clear=True), mock.patch.object(
            MODULE, "inspect_layer", return_value=local
        ), mock.patch.object(MODULE, "connect", return_value=client), mock.patch.object(
            MODULE, "run_remote", side_effect=remote
        ), mock.patch.object(MODULE, "upload_layer") as upload, redirect_stdout(stdout):
            result = MODULE.main(
                ["--host", "host", "--known-hosts", "hosts", "--layer", "layer"]
            )
        self.assertEqual(0, result)
        self.assertEqual(1, len(seen))
        self.assertNotIn("yiyunying-media-runtime-8.1.2-", seen[0])
        upload.assert_not_called()
        self.assertIn("no upload, install, symlink switch", stdout.getvalue())
        for frozen in (
            MODULE.INDEX_DIGEST,
            MODULE.AMD64_MANIFEST_DIGEST,
            MODULE.CONFIG_DIGEST,
            "sha256:" + MODULE.DIFF_ID_SHA256,
            "sha256:" + MODULE.PINNED_MEMBERS["ffmpeg"].sha256,
            '"platform":"linux/amd64"',
            '"registry_chain":"offline-frozen-provenance"',
        ):
            self.assertIn(frozen, stdout.getvalue())
        self.assertTrue(client.closed)

    def test_stage_creation_response_loss_and_cleanup_failure_require_recovery(self) -> None:
        client = FakeClient()
        labels: list[str] = []

        def remote(_client, _command, label, _password, **_kwargs):
            labels.append(label)
            if label == "media runtime preflight":
                return 0, "PYTHON=/usr/bin/python3\n"
            if label == "media runtime stage creation":
                raise TimeoutError("response lost")
            if label == "media runtime stage cleanup":
                raise TimeoutError("cleanup response lost")
            self.fail(label)

        stderr = io.StringIO()
        with mock.patch.dict("os.environ", {"YY_SSH_PASSWORD": "pw"}, clear=True), mock.patch.object(
            MODULE, "inspect_layer", return_value={"sha256": MODULE.LAYER_SHA256}
        ), mock.patch.object(MODULE, "connect", return_value=client), mock.patch.object(
            MODULE, "run_remote", side_effect=remote
        ), redirect_stderr(stderr):
            with self.assertRaisesRegex(TimeoutError, "response lost"):
                MODULE.main(
                    [
                        "--host", "host", "--known-hosts", "hosts", "--layer", "layer",
                        "--execute", "--confirm", MODULE.EXECUTE_CONFIRMATION,
                        "--maintenance-confirmed", MODULE.MAINTENANCE_CONFIRMATION,
                    ]
                )
        self.assertEqual(
            ["media runtime preflight", "media runtime stage creation", "media runtime stage cleanup"],
            labels,
        )
        self.assertIn("RECOVERY_REQUIRED=media-runtime-stage-cleanup", stderr.getvalue())
        self.assertTrue(client.closed)

    def test_install_response_loss_with_successful_cleanup_is_still_uncertain(self) -> None:
        client = FakeClient()
        labels: list[str] = []

        def remote(_client, _command, label, _password, **_kwargs):
            labels.append(label)
            if label == "media runtime preflight":
                return 0, "PYTHON=/usr/bin/python3\n"
            if label == "media runtime stage creation":
                return 0, ""
            if label == "media runtime install":
                raise TimeoutError("install exit status and receipt lost")
            if label == "media runtime stage cleanup":
                return 0, ""
            self.fail(label)

        with mock.patch.dict("os.environ", {"YY_SSH_PASSWORD": "pw"}, clear=True), mock.patch.object(
            MODULE, "inspect_layer", return_value={"sha256": MODULE.LAYER_SHA256}
        ), mock.patch.object(MODULE, "connect", return_value=client), mock.patch.object(
            MODULE, "run_remote", side_effect=remote
        ), mock.patch.object(MODULE, "upload_layer"):
            with self.assertRaisesRegex(
                RuntimeError, "RECOVERY_REQUIRED: remote install result uncertain"
            ) as raised:
                MODULE.main(
                    [
                        "--host", "host", "--known-hosts", "hosts", "--layer", "layer",
                        "--execute", "--confirm", MODULE.EXECUTE_CONFIRMATION,
                        "--maintenance-confirmed", MODULE.MAINTENANCE_CONFIRMATION,
                    ]
                )
        self.assertIsInstance(raised.exception.__cause__, TimeoutError)
        self.assertEqual(
            [
                "media runtime preflight",
                "media runtime stage creation",
                "media runtime install",
                "media runtime stage cleanup",
            ],
            labels,
        )
        self.assertTrue(client.closed)

    def test_install_keyboard_interrupt_with_successful_cleanup_is_recovery_required(self) -> None:
        client = FakeClient()

        def remote(_client, _command, label, _password, **_kwargs):
            if label == "media runtime preflight":
                return 0, "PYTHON=/usr/bin/python3\n"
            if label == "media runtime stage creation":
                return 0, ""
            if label == "media runtime install":
                raise KeyboardInterrupt("operator interrupted after remote start")
            if label == "media runtime stage cleanup":
                return 0, ""
            self.fail(label)

        with mock.patch.dict("os.environ", {"YY_SSH_PASSWORD": "pw"}, clear=True), mock.patch.object(
            MODULE, "inspect_layer", return_value={"sha256": MODULE.LAYER_SHA256}
        ), mock.patch.object(MODULE, "connect", return_value=client), mock.patch.object(
            MODULE, "run_remote", side_effect=remote
        ), mock.patch.object(MODULE, "upload_layer"):
            with self.assertRaisesRegex(
                RuntimeError, "RECOVERY_REQUIRED: remote install result uncertain"
            ) as raised:
                MODULE.main(
                    [
                        "--host", "host", "--known-hosts", "hosts", "--layer", "layer",
                        "--execute", "--confirm", MODULE.EXECUTE_CONFIRMATION,
                        "--maintenance-confirmed", MODULE.MAINTENANCE_CONFIRMATION,
                    ]
                )
        self.assertIsInstance(raised.exception.__cause__, KeyboardInterrupt)
        self.assertTrue(client.closed)

    def test_cleanup_interrupt_cannot_replace_install_uncertainty_marker(self) -> None:
        client = FakeClient()

        def remote(_client, _command, label, _password, **_kwargs):
            if label == "media runtime preflight":
                return 0, "PYTHON=/usr/bin/python3\n"
            if label == "media runtime stage creation":
                return 0, ""
            if label == "media runtime install":
                raise KeyboardInterrupt("install interrupt")
            if label == "media runtime stage cleanup":
                raise KeyboardInterrupt("cleanup interrupt")
            self.fail(label)

        stderr = io.StringIO()
        with mock.patch.dict("os.environ", {"YY_SSH_PASSWORD": "pw"}, clear=True), mock.patch.object(
            MODULE, "inspect_layer", return_value={"sha256": MODULE.LAYER_SHA256}
        ), mock.patch.object(MODULE, "connect", return_value=client), mock.patch.object(
            MODULE, "run_remote", side_effect=remote
        ), mock.patch.object(MODULE, "upload_layer"), redirect_stderr(stderr):
            with self.assertRaisesRegex(
                RuntimeError, "RECOVERY_REQUIRED: remote install result uncertain"
            ) as raised:
                MODULE.main(
                    [
                        "--host", "host", "--known-hosts", "hosts", "--layer", "layer",
                        "--execute", "--confirm", MODULE.EXECUTE_CONFIRMATION,
                        "--maintenance-confirmed", MODULE.MAINTENANCE_CONFIRMATION,
                    ]
                )
        self.assertIsInstance(raised.exception.__cause__, KeyboardInterrupt)
        self.assertEqual("install interrupt", str(raised.exception.__cause__))
        self.assertIn("RECOVERY_REQUIRED=media-runtime-stage-cleanup", stderr.getvalue())
        self.assertTrue(client.closed)

    def test_ambiguous_install_receipt_with_successful_cleanup_requires_recovery(self) -> None:
        client = FakeClient()

        def remote(_client, _command, label, _password, **_kwargs):
            if label == "media runtime preflight":
                return 0, "PYTHON=/usr/bin/python3\n"
            if label == "media runtime stage creation":
                return 0, ""
            if label == "media runtime install":
                return 0, valid_install_receipt() + valid_install_receipt()
            if label == "media runtime stage cleanup":
                return 0, ""
            self.fail(label)

        with mock.patch.dict("os.environ", {"YY_SSH_PASSWORD": "pw"}, clear=True), mock.patch.object(
            MODULE, "inspect_layer", return_value={"sha256": MODULE.LAYER_SHA256}
        ), mock.patch.object(MODULE, "connect", return_value=client), mock.patch.object(
            MODULE, "run_remote", side_effect=remote
        ), mock.patch.object(MODULE, "upload_layer"):
            with self.assertRaisesRegex(
                RuntimeError, "RECOVERY_REQUIRED: remote install result uncertain"
            ):
                MODULE.main(
                    [
                        "--host", "host", "--known-hosts", "hosts", "--layer", "layer",
                        "--execute", "--confirm", MODULE.EXECUTE_CONFIRMATION,
                        "--maintenance-confirmed", MODULE.MAINTENANCE_CONFIRMATION,
                    ]
                )
        self.assertTrue(client.closed)

    def test_execute_accepts_one_receipt_then_cleans_stage(self) -> None:
        client = FakeClient()
        labels: list[str] = []

        def remote(_client, _command, label, _password, **kwargs):
            labels.append(label)
            if label == "media runtime preflight":
                return 0, "PYTHON=/usr/bin/python3\n"
            if label == "media runtime stage creation":
                return 0, ""
            if label == "media runtime install":
                self.assertFalse(kwargs.get("emit_output", True))
                self.assertTrue(kwargs.get("require_empty_stderr", False))
                return 0, valid_install_receipt()
            if label == "media runtime stage cleanup":
                return 0, ""
            self.fail(label)

        stdout = io.StringIO()
        with mock.patch.dict("os.environ", {"YY_SSH_PASSWORD": "pw"}, clear=True), mock.patch.object(
            MODULE, "inspect_layer", return_value={"sha256": MODULE.LAYER_SHA256}
        ), mock.patch.object(MODULE, "connect", return_value=client), mock.patch.object(
            MODULE, "run_remote", side_effect=remote
        ), mock.patch.object(MODULE, "upload_layer"), redirect_stdout(stdout):
            result = MODULE.main(
                [
                    "--host", "host", "--known-hosts", "hosts", "--layer", "layer",
                    "--execute", "--confirm", MODULE.EXECUTE_CONFIRMATION,
                    "--maintenance-confirmed", MODULE.MAINTENANCE_CONFIRMATION,
                ]
            )
        self.assertEqual(0, result)
        self.assertIn("MEDIA_RUNTIME_RECEIPT=", stdout.getvalue())
        self.assertEqual("media runtime stage cleanup", labels[-1])
        self.assertTrue(client.closed)

    def test_sftp_failure_and_cleanup_failure_require_recovery(self) -> None:
        client = FakeClient()

        def remote(_client, _command, label, _password, **_kwargs):
            if label == "media runtime preflight":
                return 0, "PYTHON=/usr/bin/python3\n"
            if label == "media runtime stage creation":
                return 0, ""
            if label == "media runtime stage cleanup":
                raise RuntimeError("cleanup failed")
            self.fail(label)

        stderr = io.StringIO()
        with mock.patch.dict("os.environ", {"YY_SSH_PASSWORD": "pw"}, clear=True), mock.patch.object(
            MODULE, "inspect_layer", return_value={"sha256": MODULE.LAYER_SHA256}
        ), mock.patch.object(MODULE, "connect", return_value=client), mock.patch.object(
            MODULE, "run_remote", side_effect=remote
        ), mock.patch.object(MODULE, "upload_layer", side_effect=OSError("sftp failed")), redirect_stderr(stderr):
            with self.assertRaisesRegex(OSError, "sftp failed"):
                MODULE.main(
                    [
                        "--host", "host", "--known-hosts", "hosts", "--layer", "layer",
                        "--execute", "--confirm", MODULE.EXECUTE_CONFIRMATION,
                        "--maintenance-confirmed", MODULE.MAINTENANCE_CONFIRMATION,
                    ]
                )
        self.assertIn("RECOVERY_REQUIRED=media-runtime-stage-cleanup", stderr.getvalue())
        self.assertTrue(client.closed)

    def test_success_with_cleanup_failure_returns_recovery_required_failure(self) -> None:
        client = FakeClient()

        def remote(_client, _command, label, _password, **_kwargs):
            if label == "media runtime preflight":
                return 0, "PYTHON=/usr/bin/python3\n"
            if label == "media runtime stage creation":
                return 0, ""
            if label == "media runtime install":
                return 0, valid_install_receipt()
            if label == "media runtime stage cleanup":
                raise RuntimeError("cleanup failed")
            self.fail(label)

        with mock.patch.dict("os.environ", {"YY_SSH_PASSWORD": "pw"}, clear=True), mock.patch.object(
            MODULE, "inspect_layer", return_value={"sha256": MODULE.LAYER_SHA256}
        ), mock.patch.object(MODULE, "connect", return_value=client), mock.patch.object(
            MODULE, "run_remote", side_effect=remote
        ), mock.patch.object(MODULE, "upload_layer"), redirect_stderr(io.StringIO()):
            with self.assertRaisesRegex(RuntimeError, "RECOVERY_REQUIRED"):
                MODULE.main(
                    [
                        "--host", "host", "--known-hosts", "hosts", "--layer", "layer",
                        "--execute", "--confirm", MODULE.EXECUTE_CONFIRMATION,
                        "--maintenance-confirmed", MODULE.MAINTENANCE_CONFIRMATION,
                    ]
                )
        self.assertTrue(client.closed)

    def test_cleanup_path_is_narrowly_scoped(self) -> None:
        good = "/tmp/.yiyunying-media-runtime-8.1.2-" + "a" * 32 + ".tar.gz"
        create = MODULE.create_stage_command(good)
        command = MODULE.cleanup_command(good)
        self.assertIn("set -euC", create)
        self.assertIn("umask 077", create)
        self.assertIn("YY_MEDIA_STAGE_V1", create)
        self.assertIn("600|root|root|", create)
        self.assertIn(MODULE.LAYER_SHA256, command)
        self.assertIn(good, command)
        for bad in ("/tmp/x", good + "/../x", "/opt/yiyunying"):
            with self.subTest(bad=bad):
                with self.assertRaisesRegex(RuntimeError, "marker path"):
                    MODULE.create_stage_command(bad)
                with self.assertRaisesRegex(RuntimeError, "cleanup path"):
                    MODULE.cleanup_command(bad)


if __name__ == "__main__":
    unittest.main()
