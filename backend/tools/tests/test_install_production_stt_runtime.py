from __future__ import annotations

import hashlib
import importlib.util
import io
import os
from pathlib import Path
import sys
import tarfile
import tempfile
import types
import unittest
from unittest import mock


BACKEND = Path(__file__).resolve().parents[2]
INSTALLER = BACKEND / "tools" / "install-production-stt-runtime.py"
REMOTE = BACKEND / "tools" / "stt" / "offline" / "remote-install.py"
WRAPPER = BACKEND / "tools" / "install-production-stt-runtime.ps1"


def load_module(path: Path, name: str, fake_unix: bool = False):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"unable to load {path.name}")
    module = importlib.util.module_from_spec(spec)
    additions = {}
    if fake_unix:
        additions = {"pwd": types.ModuleType("pwd"), "grp": types.ModuleType("grp")}
    with mock.patch.dict(sys.modules, additions, clear=False):
        sys.modules[spec.name] = module
        spec.loader.exec_module(module)
    return module


class InstallProductionSttRuntimeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.local = load_module(INSTALLER, "install_production_stt_runtime_test")
        cls.remote = load_module(REMOTE, "remote_install_stt_runtime_test", fake_unix=True)
        cls.local_source = INSTALLER.read_text(encoding="utf-8")
        cls.remote_source = REMOTE.read_text(encoding="utf-8")
        cls.wrapper_source = WRAPPER.read_text(encoding="utf-8")

    def test_default_is_dry_run_and_execute_has_three_exact_confirmations(self) -> None:
        args = self.local.parser().parse_args([
            "--host", "example.invalid",
            "--known-hosts", "known_hosts",
            "--bundle", "source.tar",
            "--bundle-sha256", "0" * 64,
        ])
        self.assertFalse(args.execute)
        self.assertEqual("", args.confirm)
        self.assertEqual("", args.maintenance_confirmed)
        self.assertEqual("", args.confirm_manifest_sha)
        self.assertIn("install-offline-stt-cpython-3.11.15", self.local.EXECUTE_CONFIRMATION)
        self.assertEqual("stt-current-switch-and-rollback-reviewed", self.local.MAINTENANCE_CONFIRMATION)

    def test_preflight_pins_root_linux_x86_64_glibc_217_and_system_python(self) -> None:
        command = self.local.preflight_command()
        for marker in (
            "test \"$(id -u)\" -eq 0",
            "test \"$(uname -s)\" = Linux",
            "test \"$(uname -m)\" = x86_64",
            "GNU_LIBC_VERSION",
            "(v[2]+0)>=17",
            "/usr/local/bin/python3.12 /usr/bin/python3.12",
            "sys.version_info >= (3,9)",
            "SYSTEM_PYTHON_VERSION",
            "/www/wwwroot/appht.jjmxg.xyz",
            "unshare runuser env ldd readelf",
            "readlink tar",
            "[ \"$free\" -ge 2147483648 ]",
            "[ \"$tmp_free\" -ge 536870912 ]",
        ):
            self.assertIn(marker, command)
        self.assertEqual(2 << 30, self.local.MINIMUM_REMOTE_FREE_BYTES)
        self.assertEqual(2 << 30, self.remote.MINIMUM_FREE_BYTES)

    def test_preflight_rejects_missing_or_old_system_python_version(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "older than 3.9"):
            self.local.parse_system_python(
                "SYSTEM_PYTHON=/usr/bin/python3\nSYSTEM_PYTHON_VERSION=3.8.19\n"
            )
        self.assertEqual(
            "/usr/bin/python3.11",
            self.local.parse_system_python(
                "SYSTEM_PYTHON=/usr/bin/python3.11\nSYSTEM_PYTHON_VERSION=3.11.15\n"
            ),
        )

    def test_remote_install_is_offline_hash_locked_atomic_and_recoverable(self) -> None:
        for marker in (
            '"--no-index"',
            '"--require-hashes"',
            '"--no-deps"',
            'unshare, "--net"',
            "validate_unique_regular",
            "metadata.st_nlink != 1",
            "release contains an escaping or broken symlink",
            "release contains a regular hardlink",
            "audit_elf(release)",
            "local_files_only=True",
            "run_www_probe",
            "os.replace(temporary, current)",
            "RECOVERY_REQUIRED=stt-runtime-current-indeterminate",
            "RECOVERY_REQUIRED=stt-stage-cleanup-failed",
            "validate_trusted_parent_chain",
            "assert_trusted_parent_chain_unchanged",
            "production STT parent permissions were not hardened first",
            "shutil.disk_usage(root / \"storage\" / \"stt\")",
        ):
            self.assertIn(marker, self.remote_source)

    def test_first_install_without_previous_current_keeps_exact_recovery_state(self) -> None:
        rollback = self.remote_source.split("        if switched:\n", 1)[1]
        rollback = rollback.split("        raise\n", 1)[0]
        indeterminate = "RECOVERY_REQUIRED=stt-runtime-current-indeterminate"
        no_previous = "RECOVERY_REQUIRED=stt-no-prior-trusted-current"
        self.assertIn(indeterminate, rollback)
        self.assertIn(no_previous, rollback)
        self.assertLess(
            rollback.index(indeterminate),
            rollback.index(no_previous),
            "the known no-previous state must be raised after the rollback catch",
        )
        no_previous_branch = rollback.split("            if previous is None:\n", 1)[1]
        self.assertTrue(
            no_previous_branch.lstrip().startswith("# The successful readback above proves current is absent."),
        )

    def test_release_modes_match_hardener_bin_only_contract(self) -> None:
        root = Path("/release")
        self.assertEqual(0o750, self.remote.release_file_mode(root, root / "python/bin/python3"))
        self.assertEqual(0o750, self.remote.release_file_mode(root, root / "python/bin/pip"))
        self.assertEqual(0o640, self.remote.release_file_mode(root, root / "python/lib/libpython3.11.so.1.0"))
        self.assertEqual(0o640, self.remote.release_file_mode(root, root / "python/lib/python3.11/site.py"))
        hardener = (BACKEND / "tools" / "harden-production-permissions.py").read_text(encoding="utf-8")
        self.assertIn("-type f -path '*/bin/*'", hardener)
        self.assertIn("-type f ! -path '*/bin/*'", hardener)

    def test_origin_rpath_cannot_escape_release(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            release = Path(temporary) / "release"
            elf = release / "python/lib/native.so"
            elf.parent.mkdir(parents=True)
            elf.write_bytes(b"\x7fELF")
            self.assertTrue(self.remote.origin_rpath_within_release(release, elf, "$ORIGIN"))
            self.assertTrue(self.remote.origin_rpath_within_release(release, elf, "${ORIGIN}/../lib"))
            self.assertFalse(self.remote.origin_rpath_within_release(release, elf, "$ORIGIN/../../../outside"))
            self.assertFalse(self.remote.origin_rpath_within_release(release, elf, "$ORIGIN/$LIB"))
            self.assertFalse(self.remote.origin_rpath_within_release(release, elf, "/tmp"))

    def test_release_manifest_commits_symlink_topology(self) -> None:
        if os.name == "nt":
            self.skipTest("symlink fixture requires Unix")
        with tempfile.TemporaryDirectory() as temporary:
            release = Path(temporary)
            target = release / "python/bin/python3.11"
            target.parent.mkdir(parents=True)
            target.write_bytes(b"python")
            (target.parent / "python3").symlink_to("python3.11")
            manifest = self.remote.release_tree_manifest(release)
            links = [entry for entry in manifest["entries"] if entry["type"] == "symlink"]
            self.assertEqual([{"path": "python/bin/python3", "type": "symlink", "target": "python3.11"}], links)

    def test_production_wrapper_must_match_content_addressed_payload_before_www_exec(self) -> None:
        self.assertIn('(\"installer/transcribe.py\", TRANSCRIBE_WRAPPER)', self.local_source)
        for marker in (
            "validate_production_wrapper",
            "production STT wrapper differs from the content-addressed payload",
            "metadata.st_uid != 0",
            "stat.S_IMODE(metadata.st_mode) != 0o640",
            "wrapper_identity",
        ):
            self.assertIn(marker, self.remote_source)

    def test_python_projection_rejects_escape_and_allows_reviewed_root(self) -> None:
        self.assertEqual("bin/python3", self.remote.python_projection_name("python/bin/python3"))
        for unsafe in ("bin/python3", "../python/bin/python3", "/python/bin/python3", "python"):
            with self.subTest(unsafe=unsafe), self.assertRaises(RuntimeError):
                self.remote.python_projection_name(unsafe)
        self.assertIsNone(self.remote.normalized_link("python/bin/x", "../../../outside"))
        self.assertEqual(
            "python/lib/libpython.so",
            self.remote.normalized_link("python/bin/python3", "../lib/libpython.so").as_posix(),
        )

    def test_payload_tar_rejects_links_before_extraction(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            archive_path = root / "payload.tar"
            with tarfile.open(archive_path, "w") as archive:
                info = tarfile.TarInfo("payload-manifest.json")
                payload = b"{}"
                info.size = len(payload)
                archive.addfile(info, io.BytesIO(payload))
                link = tarfile.TarInfo("model/base/model.bin")
                link.type = tarfile.SYMTYPE
                link.linkname = "../../outside"
                archive.addfile(link)
            destination = root / "extract"
            destination.mkdir()
            with self.assertRaisesRegex(RuntimeError, "link or special"):
                self.remote.extract_payload(archive_path, destination)
            self.assertFalse((root / "outside").exists())

    def test_installer_command_separates_extract_and_stat_and_pins_hashes(self) -> None:
        source = types.SimpleNamespace(source_manifest_sha256="1" * 64, release_id="py31115-fw121-ebe41f70d5b6-" + "2" * 12)
        payload = types.SimpleNamespace(size=123, sha256="3" * 64, helper_sha256="4" * 64)
        token = "5" * 32
        command = self.local.installer_command(
            "/usr/bin/python3",
            f"/tmp/.yiyunying-stt-runtime-{token}/payload.tar",
            f"/tmp/.yiyunying-stt-runtime-{token}/remote-install.py",
            payload,
            source,
            token,
        )
        self.assertIn('> "$helper_partial"\ntest "$(stat', command)
        self.assertNotIn('> "$helper_partial"+test', command)
        self.assertIn("3" * 64, command)
        self.assertIn("4" * 64, command)
        self.assertIn("/usr/bin/python3 -I -B", command)
        self.assertIn('helper_partial="$helper.partial"', command)
        self.assertIn("trap 'rm -f -- \"$helper_partial\"' EXIT", command)

    def test_remote_stage_is_one_root_only_atomic_directory(self) -> None:
        token = "a" * 32
        archive = f"/tmp/.yiyunying-stt-runtime-{token}/payload.tar"
        helper = f"/tmp/.yiyunying-stt-runtime-{token}/remote-install.py"
        self.assertEqual(f"/tmp/.yiyunying-stt-runtime-{token}", self.local.validate_remote_paths(archive, helper))
        create = self.local.create_stage_command(archive)
        self.assertIn("mkdir -m 0700", create)
        self.assertIn("set -C; : >", create)
        self.assertNotIn("test ! -e /tmp/.yiyunying-stt-runtime-" + token + "/payload.tar; : >", create)
        with self.assertRaisesRegex(RuntimeError, "one reviewed root-only stage"):
            self.local.validate_remote_paths(archive, "/tmp/.yiyunying-stt-runtime-" + "b" * 32 + "/remote-install.py")

    def test_current_target_requires_exact_formal_release_id(self) -> None:
        self.assertIn("RELEASE_RE.fullmatch(match.group(1))", self.remote_source)
        command = self.local.preflight_command()
        self.assertIn("py31115-fw121-ebe41f70d5b6-", command)
        self.assertIn("''|.|..|*/*", command)

    def test_source_bundle_is_closed_and_notices_are_exactly_locked(self) -> None:
        self.assertEqual(90_601, self.local.THIRD_PARTY_NOTICES_SIZE)
        self.assertEqual(
            "55cd6e0bca728d3d053389310bb8eacdefc95e803fb55d927965ba0ec19a170e",
            self.local.THIRD_PARTY_NOTICES_SHA256,
        )
        self.assertIn("validate_closed_source_topology", self.local_source)
        self.assertIn("source bundle topology is not the reviewed closed file set", self.local_source)
        self.assertIn("len(extracted) != 264", self.local_source)
        self.assertIn('"remote_installer_sha256": helper_hash', self.local_source)
        self.assertIn('"transcribe_wrapper_sha256": wrapper_hash', self.local_source)
        self.assertLess(
            self.local_source.index("payload = build_payload(source)"),
            self.local_source.index("client = connect(args, password)"),
            "dry-run must hash the exact derived production payload before connecting",
        )
        self.assertIn("STT_PRODUCTION_PAYLOAD_PIN=", self.local_source)

    def test_final_channel_drain_rechecks_output_bound(self) -> None:
        source = self.local_source.split("def collect_channel", 1)[1].split("def run_remote", 1)[0]
        self.assertGreaterEqual(source.count("len(stdout) + len(stderr) > MAX_REMOTE_OUTPUT"), 2)

    def test_dpapi_launcher_never_accepts_password_argument(self) -> None:
        for marker in (
            "Windows-DPAPI-CurrentUser",
            "yiyunying-production-ssh",
            "ProtectedData]::Unprotect",
            "ciphertextSha256",
            "payloadSha256",
            "$env:YY_SSH_PASSWORD = $password",
            "Remove-Item Env:YY_SSH_PASSWORD",
            "Paramiko 5.0.0",
            "Remove-Item Env:PYTHONPATH",
            "ReparsePoint",
        ):
            self.assertIn(marker, self.wrapper_source)
        self.assertNotRegex(self.wrapper_source, r"param\([\s\S]*\[string\]\$Password")
        self.assertNotIn("Write-Output $password", self.wrapper_source)
        self.assertNotIn("Write-Host $password", self.wrapper_source)

    def test_source_bundle_temp_and_payload_temp_stay_on_bundle_volume(self) -> None:
        self.assertIn('dir=str(expanded.parent)', self.local_source)
        self.assertIn('dir=str(source.bundle_path.parent)', self.local_source)
        self.assertIn('"metadata/builder-tools.json"', self.local_source)
        self.assertIn('(\"metadata/builder-tools.json\", PINNED_BUILDER_TOOLS)', self.local_source)
        self.assertIn('(\"metadata/license-evidence.json\", PINNED_LICENSE_EVIDENCE)', self.local_source)
        self.assertIn(
            'for directory in ("wheelhouse", "model", "metadata", "probe", "licenses")',
            self.local_source,
        )
        self.assertNotIn(
            'for directory in ("wheelhouse", "model", "metadata", "probe", "licenses", "evidence")',
            self.local_source,
        )

    def test_sanitizer_removes_secret_from_nested_failure(self) -> None:
        secret = "fixture-super-secret"
        sanitized = self.local.sanitize(RuntimeError("failure: " + secret), (secret,))
        self.assertNotIn(secret, sanitized)
        self.assertIn("[REDACTED]", sanitized)


if __name__ == "__main__":
    unittest.main()
