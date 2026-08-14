from __future__ import annotations

import importlib.util
import os
from pathlib import Path
import re
import shlex
import shutil
import subprocess
import tempfile
import unittest
from unittest import mock


BACKEND = Path(__file__).resolve().parents[2]
SCRIPT = BACKEND / "tools" / "harden-production-permissions.py"
NGINX = BACKEND / "deploy" / "nginx-uploads-static-only.conf.example"
DOC = BACKEND / "docs" / "PRODUCTION_PERMISSION_HARDENING.md"
CHECK = BACKEND / "tools" / "check.ps1"


def load_module():
    spec = importlib.util.spec_from_file_location("permission_hardening", SCRIPT)
    if spec is None or spec.loader is None:
        raise RuntimeError("unable to load permission hardening module")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def find_bash() -> Path | None:
    discovered = shutil.which("bash")
    if discovered:
        return Path(discovered)
    candidates = (
        Path.home()
        / ".cache/codex-runtimes/codex-primary-runtime/dependencies/native/git/usr/bin/sh.exe",
        Path("C:/Program Files/Git/usr/bin/bash.exe"),
        Path("C:/Program Files/Git/bin/bash.exe"),
    )
    return next((candidate for candidate in candidates if candidate.is_file()), None)


def shell_quote(path: Path | str) -> str:
    value = Path(path).resolve().as_posix() if isinstance(path, Path) else path
    return shlex.quote(value)


class PermissionHardeningContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.module = load_module()
        cls.bash = find_bash()

    def run_bash(self, source: str) -> subprocess.CompletedProcess[str]:
        if self.bash is None:
            self.skipTest("no local Bash; run generated scripts through production read-only bash -n")
        environment = dict(os.environ)
        environment["MSYS2_ARG_CONV_EXCL"] = "*"
        portable_prefix = '''PATH=/usr/bin:/bin
export PATH
sha256sum() { b2sum "$@"; }
chmod() { return 0; }
'''
        return subprocess.run(
            [str(self.bash)],
            input=portable_prefix + source,
            text=True,
            encoding="utf-8",
            capture_output=True,
            env=environment,
            check=False,
        )

    def test_remote_scope_is_pinned(self) -> None:
        expected = "/www/wwwroot/appht.jjmxg.xyz/yiyunying-backend"
        self.assertEqual(expected, self.module.validate_remote_root(expected + "/"))
        for unsafe in ("/", "/www/wwwroot", "/tmp/backend", expected + "/public"):
            with self.subTest(unsafe=unsafe), self.assertRaises(ValueError):
                self.module.validate_remote_root(unsafe)
        with self.assertRaises(ValueError):
            self.module.validate_runtime_identity("root", "www")

    def test_default_audit_is_read_only_and_covers_every_boundary(self) -> None:
        command = self.module.audit_command(
            self.module.EXPECTED_REMOTE_ROOT,
            self.module.EXPECTED_RUNTIME_USER,
            self.module.EXPECTED_RUNTIME_GROUP,
        )
        for marker in (
            "TREE_SHAPE_BEFORE",
            "TREE_SHAPE_AFTER",
            "TREE_SHAPE_STABLE",
            "FULL_TREE_TYPES",
            "foreign_devices",
            "UNKNOWN_APPLICATION_FILE",
            "UNKNOWN_PUBLIC_COUNT",
            "public-root",
            "storage/private/uploads",
            "storage/deploy-backups",
            "STT_MATRIX",
            "STT_LINK_TOPOLOGY",
            "STT_RUNTIME_WWW_PROBE",
            "from faster_whisper import WhisperModel",
            "FPM_SOCKET",
            "APPLY_READY_STRUCTURE_FUNCTION",
            "EXPECTED_PERMISSION_DRIFT",
            "WILL_CREATE_PRIVATE_UPLOADS",
            "AUDIT_RESULT",
        ):
            self.assertIn(marker, command)
        for mutator in ("chmod", "chown", "mkdir", "install", "setfacl", "touch", "rmdir"):
            self.assertIsNone(re.search(rf"\b{mutator}\b", command), mutator)
        self.assertNotRegex(command, r"\brm\s")
        self.assertNotRegex(command, r"\bmv\s")

    def test_apply_uses_complete_preinventory_not_find_exec_mutation(self) -> None:
        backup = "/www/backup/yiyunying/permission-hardening-20260814T120000Z-0123456789abcdef"
        command = self.module.apply_command(self.module.EXPECTED_REMOTE_ROOT, "www", "www", backup)
        for marker in (
            "reject_unknown_links_and_hardlinks",
            "validate_full_structure",
            "managed_hash",
            "classified_hash",
            "inventory_identity_hash",
            "inventory-identity-before.sha256",
            "expected-post-classified.sha256",
            "expected-post-shape.sha256",
            "created-node-identity.sha256",
            "inventory-immutable-dirs.nul",
            "inventories.sha256",
            "harden_inventory",
            "verify_inventory",
            "transaction-ledger.tsv",
            "ledger_append newdir",
            "ledger_append probe",
            "cleanup_transaction_artifacts",
            "AUTOMATIC_ROLLBACK|recovery_required",
            "trap automatic_rollback ERR",
            "getfacl -R -P -p",
            'chmod 0600 "$LEDGER"',
            "verify_complete_permission_matrix",
            "validate_completed_ledger",
        ):
            self.assertIn(marker, command)
        self.assertNotRegex(command, r"find[^\n]*-exec(?:dir)?\s+(?:chmod|chown)")
        self.assertNotRegex(command, r"rmdir[^\n]*\|\|\s*true")
        self.assertNotIn("created-paths.txt", command)
        self.assertLess(command.index("validate_full_structure"), command.index("getfacl -R -P -p"))
        self.assertLess(command.index("getfacl -R -P -p"), command.index("trap automatic_rollback ERR"))
        self.assertLess(command.index("trap automatic_rollback ERR"), command.index("ledger_append newdir"))
        commit = command.index("state=committed")
        self.assertLess(command.rfind("validate_full_structure"), commit)
        self.assertLess(command.rfind("verify_complete_permission_matrix"), commit)
        self.assertLess(command.rfind("inventory_identity_hash"), commit)
        self.assertLess(command.rfind("managed_path_hash"), commit)

    def test_stt_gate_is_strict_root_www_read_only_and_executes_as_www(self) -> None:
        backup = "/www/backup/yiyunying/permission-hardening-20260814T120000Z-0123456789abcdef"
        command = self.module.apply_command(self.module.EXPECTED_REMOTE_ROOT, "www", "www", backup)
        gate = command.split("validate_stt_gate()", 1)[1].split("validate_full_structure()", 1)[0]
        for marker in (
            "! -user root",
            '! -group "$RUNTIME_GROUP"',
            "! -perm 0750",
            "! -perm 0640",
            '-user "$RUNTIME_USER"',
            '750|root|$RUNTIME_GROUP',
            "-I -S -B -c",
            "from faster_whisper import WhisperModel",
            '"$RUNTIME_USER"',
            "escaped",
        ):
            self.assertIn(marker, gate)
        self.assertNotIn("chmod", gate)
        self.assertNotIn("chown", gate)

    def test_apply_failure_cleanup_is_ledgered_and_fail_closed(self) -> None:
        library = self.module.transaction_shell_library()
        for marker in (
            "validate_transaction_ledger",
            "cleanup_ledger_kind probe",
            "cleanup_ledger_kind newdir",
            "if [ ! -d \"$path\" ] || ! rmdir -- \"$path\"",
            "state=recovery_required",
            "exit 97",
        ):
            self.assertIn(marker, library)
        self.assertNotRegex(library, r"rmdir[^\n]*\|\|\s*true")
        self.assertNotRegex(library, r"\brm[^\n]*\|\|\s*true")

    def transaction_fixture(self, *, cleanup_failure: bool, acl_failure: bool) -> tuple[subprocess.CompletedProcess[str], Path, tempfile.TemporaryDirectory[str]]:
        temporary = tempfile.TemporaryDirectory()
        root = Path(temporary.name) / "root"
        backup = Path(temporary.name) / "backup"
        root.mkdir()
        backup.mkdir()
        (root / "storage/private").mkdir(parents=True)
        (root / "storage/cache").mkdir(parents=True)
        setup = ""
        if cleanup_failure:
            setup = ': > "$ROOT/storage/private/uploads/unledgered-blocker"\n'
        source = f'''set -Eeuo pipefail
ROOT={shell_quote(root)}
BACKUP={shell_quote(backup)}
snapshot="$BACKUP/permissions-before.acl"
tree_hash="$BACKUP/tree-before.sha256"
: > "$snapshot"
find "$ROOT" -xdev -printf '%P\\0%y\\0%D\\0%i\\0%n\\0' | LC_ALL=C sort -z | sha256sum > "$tree_hash"
FAKE_ACL_RC={1 if acl_failure else 0}
setfacl() {{ return "$FAKE_ACL_RC"; }}
{self.module.transaction_shell_library()}
: > "$LEDGER"
chmod 0600 "$LEDGER"
ledger_append newdir "$ROOT/storage/private/uploads"
mkdir "$ROOT/storage/private/uploads"
{setup}ledger_append probe "$ROOT/storage/private/uploads/permission-probe-fixture"
: > "$ROOT/storage/private/uploads/permission-probe-fixture"
trap automatic_rollback ERR
false
'''
        return self.run_bash(source), backup, temporary

    def test_dynamic_failure_trap_cleans_probe_and_new_directory_then_restores(self) -> None:
        result, backup, temporary = self.transaction_fixture(cleanup_failure=False, acl_failure=False)
        try:
            self.assertEqual(1, result.returncode, result.stderr)
            status = (backup / "automatic-rollback.status").read_text(encoding="utf-8")
            self.assertIn("state=restored", status)
            self.assertIn("cleanup_status=clean", status)
            self.assertFalse((Path(temporary.name) / "root/storage/private/uploads").exists())
        finally:
            temporary.cleanup()

    def test_dynamic_cleanup_failure_reports_recovery_required(self) -> None:
        result, backup, temporary = self.transaction_fixture(cleanup_failure=True, acl_failure=False)
        try:
            self.assertEqual(97, result.returncode, result.stderr)
            status = (backup / "automatic-rollback.status").read_text(encoding="utf-8")
            self.assertIn("state=recovery_required", status)
            self.assertIn("cleanup_status=failed", status)
            self.assertNotIn("state=restored", status)
        finally:
            temporary.cleanup()

    def test_dynamic_acl_restore_failure_reports_recovery_required(self) -> None:
        result, backup, temporary = self.transaction_fixture(cleanup_failure=False, acl_failure=True)
        try:
            self.assertEqual(97, result.returncode, result.stderr)
            status = (backup / "automatic-rollback.status").read_text(encoding="utf-8")
            self.assertIn("state=recovery_required", status)
            self.assertIn("acl_status=failed", status)
            self.assertNotIn("state=restored", status)
        finally:
            temporary.cleanup()

    def test_dynamic_whole_tree_gate_rejects_unknown_hardlinks(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "root"
            (root / "storage/stt").mkdir(parents=True)
            (root / "app").mkdir()
            known = root / "storage/stt/a"
            known.write_bytes(b"known")
            os.link(known, root / "storage/stt/b")
            gate = f'''set -eu
ROOT={shell_quote(root)}
{self.module.preflight_link_gate_shell()}
reject_unknown_links_and_hardlinks
'''
            accepted = self.run_bash(gate)
            self.assertEqual(0, accepted.returncode, accepted.stderr)
            unknown = root / "app/a"
            unknown.write_bytes(b"unknown")
            os.link(unknown, root / "app/b")
            rejected = self.run_bash(gate)
            self.assertNotEqual(0, rejected.returncode, rejected.stderr)

    def post_commit_integrity_fixture(
        self, injection: str
    ) -> tuple[subprocess.CompletedProcess[str], Path, tempfile.TemporaryDirectory[str]]:
        temporary = tempfile.TemporaryDirectory()
        base = Path(temporary.name)
        root = base / "root"
        backup = base / "backup"
        inventory = backup / "inventory.nul"
        (root / "public").mkdir(parents=True)
        (root / "storage/private").mkdir(parents=True)
        (root / "storage/stt").mkdir(parents=True)
        (root / "app").mkdir()
        known = root / "app/known.txt"
        known.write_text("original\n", encoding="utf-8")
        backup.mkdir()
        inventory.write_bytes(
            (root / "app").resolve().as_posix().encode("utf-8")
            + b"\0"
            + known.resolve().as_posix().encode("utf-8")
            + b"\0"
        )
        if injection == "replace":
            injected = 'rm -- "$ROOT/app/known.txt"\nprintf "replacement\\n" > "$ROOT/app/known.txt"'
            failing_gate = 'test "$(inventory_identity_hash)" = "$identity_before"'
        elif injection == "foreign":
            injected = 'printf "foreign\\n" > "$ROOT/app/foreign.txt"'
            failing_gate = 'test "$(managed_path_hash)" = "$classified_before"'
        else:
            raise ValueError(injection)
        source = f'''set -Eeuo pipefail
ROOT={shell_quote(root)}
BACKUP={shell_quote(backup)}
snapshot="$BACKUP/permissions-before.acl"
tree_hash="$BACKUP/tree-before.sha256"
inventory_files=({shell_quote(inventory)})
: > "$snapshot"
find "$ROOT" -xdev -printf '%P\\0%y\\0%D\\0%i\\0%n\\0' | LC_ALL=C sort -z | sha256sum > "$tree_hash"
setfacl() {{ return 0; }}
{self.module.post_commit_integrity_shell_library()}
{self.module.transaction_shell_library()}
: > "$LEDGER"
identity_before=$(inventory_identity_hash)
classified_before=$(classified_path_hash)
test "$(managed_path_hash)" = "$classified_before"
trap automatic_rollback ERR
{injected}
{failing_gate}
'''
        return self.run_bash(source), backup, temporary

    def test_dynamic_post_commit_gate_rejects_inventory_inode_replacement(self) -> None:
        result, backup, temporary = self.post_commit_integrity_fixture("replace")
        try:
            self.assertEqual(97, result.returncode, result.stderr)
            status = (backup / "automatic-rollback.status").read_text(encoding="utf-8")
            self.assertIn("state=recovery_required", status)
            self.assertIn("shape_status=failed", status)
            self.assertNotIn("state=restored", status)
        finally:
            temporary.cleanup()

    def test_dynamic_post_commit_gate_rejects_foreign_path(self) -> None:
        result, backup, temporary = self.post_commit_integrity_fixture("foreign")
        try:
            self.assertEqual(97, result.returncode, result.stderr)
            status = (backup / "automatic-rollback.status").read_text(encoding="utf-8")
            self.assertIn("state=recovery_required", status)
            self.assertIn("shape_status=failed", status)
            self.assertNotIn("state=restored", status)
        finally:
            temporary.cleanup()

    def test_generated_audit_apply_and_rollback_are_bash_syntax_valid(self) -> None:
        backup = "/www/backup/yiyunying/permission-hardening-20260814T120000Z-0123456789abcdef"
        snapshot = backup + "/permissions-before.acl"
        commands = (
            self.module.audit_command(self.module.EXPECTED_REMOTE_ROOT, "www", "www"),
            self.module.apply_command(self.module.EXPECTED_REMOTE_ROOT, "www", "www", backup),
            self.module.rollback_command(self.module.EXPECTED_REMOTE_ROOT, snapshot),
        )
        for command in commands:
            if self.bash is None:
                self.skipTest("no local Bash; use production read-only bash -n")
            result = subprocess.run(
                [str(self.bash), "-n"],
                input=command,
                text=True,
                encoding="utf-8",
                capture_output=True,
                check=False,
            )
            self.assertEqual(0, result.returncode, result.stderr)

    def test_apply_and_rollback_require_explicit_maintenance_ack(self) -> None:
        argv = ["--host", "154.12.25.203", "--user", "root", "--known-hosts", "known_hosts", "--apply"]
        with mock.patch.object(self.module, "connect") as connect, self.assertRaises(RuntimeError):
            self.module.main(argv)
        connect.assert_not_called()
        snapshot = "/www/backup/yiyunying/permission-hardening-20260814T120000Z-0123456789abcdef/permissions-before.acl"
        rollback = self.module.rollback_command(self.module.EXPECTED_REMOTE_ROOT, snapshot)
        for marker in (
            "permissions-before.acl.sha256",
            "cleanup_transaction_artifacts",
            "tree-before.sha256",
            "manual_rollback_failed",
            "state=recovery_required",
            'setfacl --restore="$SNAPSHOT"',
        ):
            self.assertIn(marker, rollback)
        with self.assertRaises(ValueError):
            self.module.rollback_command(self.module.EXPECTED_REMOTE_ROOT, "/tmp/permissions-before.acl")

    def test_known_hosts_rejects_reparse_and_symlink_input(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            regular = root / "known_hosts"
            regular.write_text("host key\n", encoding="utf-8")
            self.assertEqual(regular.resolve(), self.module.validate_local_regular_file(regular, "known-hosts"))
            reparse_flag = getattr(self.module.stat, "FILE_ATTRIBUTE_REPARSE_POINT", 1024)
            fake = mock.Mock(st_mode=regular.stat().st_mode, st_file_attributes=reparse_flag)
            with mock.patch.object(self.module.os, "lstat", return_value=fake), self.assertRaises(ValueError):
                self.module.validate_local_regular_file(regular, "known-hosts")
            link = root / "known_hosts-link"
            try:
                link.symlink_to(regular)
            except OSError:
                return
            with self.assertRaises(ValueError):
                self.module.validate_local_regular_file(link, "known-hosts")

    def test_nginx_upload_location_cannot_reach_fastcgi(self) -> None:
        source = NGINX.read_text(encoding="utf-8")
        self.assertIn("location ^~ /uploads/", source)
        self.assertRegex(source, r"php.*phtml.*phar.*cgi.*svg")
        self.assertIn('if ($uri ~* "', source)
        self.assertNotIn("location ~*", source)
        self.assertIn("disable_symlinks on;", source)
        self.assertIn("limit_except GET HEAD", source)
        self.assertIn("try_files $uri =404;", source)
        self.assertIn('X-Content-Type-Options "nosniff" always', source)
        self.assertNotIn("fastcgi_pass", source)

    def test_runbook_and_check_wiring_cover_permission_contract(self) -> None:
        source = DOC.read_text(encoding="utf-8")
        for marker in (
            "root:www 0640",
            "listen.mode=0660",
            "不要对活动 socket 临时 `chmod`",
            "不要对 STT 使用 `chmod -R 0640`",
            "9713 组硬链接",
            "语音转写当前不可启动",
            "同一维护窗口内的精确回滚",
            "APPLY_READY_STRUCTURE_FUNCTION=yes",
            "EXPECTED_PERMISSION_DRIFT=yes",
            "WILL_CREATE_PRIVATE_UPLOADS=yes",
            "写 committed 回执前",
            "同名替换 inventory 节点",
        ):
            self.assertIn(marker, source)
        check = CHECK.read_text(encoding="utf-8")
        self.assertGreaterEqual(check.count("test-production-permission-hardening.py"), 3)
        self.assertIn("harden-production-permissions.py", check)
        self.assertIn("nginx-uploads-static-only.conf.example", check)


if __name__ == "__main__":
    unittest.main()
