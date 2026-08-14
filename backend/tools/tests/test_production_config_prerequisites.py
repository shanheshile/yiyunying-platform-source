from __future__ import annotations

import base64
import contextlib
import hashlib
import http.server
import importlib.util
import json
import os
from pathlib import Path
import re
import socket
import stat
import subprocess
import sys
import tempfile
import threading
import unittest
from unittest import mock


TOOLS = Path(__file__).resolve().parents[1]
SCRIPT = TOOLS / "apply-production-config-prerequisites.py"
SPEC = importlib.util.spec_from_file_location("production_config_prerequisites", SCRIPT)
assert SPEC and SPEC.loader
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


def digest(path: Path) -> tuple[int, str]:
    payload = path.read_bytes()
    return len(payload), hashlib.sha256(payload).hexdigest()


def canonical(path: Path) -> dict[str, object]:
    meta = os.lstat(path)
    rows: list[str] = []
    files = 0
    payload_size = 0
    if path.is_file() and not path.is_symlink():
        size, content = digest(path)
        rows.append(
            "\t".join(
                map(
                    str,
                    (
                        ".",
                        "f",
                        stat.S_IMODE(meta.st_mode),
                        meta.st_uid,
                        meta.st_gid,
                        meta.st_dev,
                        meta.st_ino,
                        meta.st_nlink,
                        size,
                        meta.st_mtime_ns,
                        content,
                    ),
                )
            )
        )
        files = 1
        payload_size = size
        kind = "file"
    else:
        kind = "directory"
        for base, dirs, names in os.walk(path, followlinks=False):
            dirs.sort()
            names.sort()
            base_path = Path(base)
            for name in ["."] + dirs + names:
                item = base_path if name == "." else base_path / name
                relative = "." if item == path else item.relative_to(path).as_posix()
                current = os.lstat(item)
                if stat.S_ISDIR(current.st_mode):
                    tag, size, content = "d", 0, "-"
                elif stat.S_ISREG(current.st_mode):
                    tag = "f"
                    size, content = digest(item)
                    files += 1
                    payload_size += size
                elif stat.S_ISLNK(current.st_mode):
                    tag = "l"
                    raw = os.readlink(item).encode()
                    size, content = len(raw), hashlib.sha256(raw).hexdigest()
                else:
                    tag, size, content = "x", current.st_size, "-"
                rows.append(
                    "\t".join(
                        map(
                            str,
                            (
                                relative,
                                tag,
                                stat.S_IMODE(current.st_mode),
                                current.st_uid,
                                current.st_gid,
                                current.st_dev,
                                current.st_ino,
                                current.st_nlink,
                                size,
                                current.st_mtime_ns,
                                content,
                            ),
                        )
                    )
                )
        rows = sorted(set(rows), key=lambda value: value.encode())
    encoded = ("canonical-manifest-v1\n" + "\n".join(rows) + "\n").encode()
    return {
        "kind": kind,
        "canonical_manifest_v1_sha256": hashlib.sha256(encoded).hexdigest(),
        "payload_size": payload_size,
        "node_count": len(rows),
        "file_count": files,
    }


def expected_node(path: Path, archive_name: str) -> dict[str, object]:
    meta = os.lstat(path)
    result: dict[str, object] = {
        "path": str(path),
        "path_sha256": hashlib.sha256(str(path).encode()).hexdigest(),
        "device": meta.st_dev,
        "inode": meta.st_ino,
        "nlink": meta.st_nlink,
        "uid": meta.st_uid,
        "gid": meta.st_gid,
        "mode": stat.S_IMODE(meta.st_mode),
        "size": meta.st_size,
        "mtime_ns": meta.st_mtime_ns,
        "archive_name": archive_name,
    }
    result.update(canonical(path))
    return result


def indent_snippet(snippet: str, indentation: str = "    ") -> str:
    return "\n".join(indentation + line if line else "" for line in snippet.rstrip("\r\n").splitlines()) + "\n"


class Fixture:
    def __init__(self, fault: str = "") -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.base = Path(self.temporary.name)
        self.root = self.base / "backend"
        self.root.mkdir()
        self.evidence = self.base / "evidence"
        self.evidence.mkdir()
        os.chmod(self.evidence, 0o700)
        for name in ("app", "config", "database", "deploy", "docs", "routes", "tools"):
            (self.root / name).mkdir()
        self.nodes = [self.root / "legacy-stage", self.root / "orphan.log"]
        self.nodes[0].mkdir()
        (self.nodes[0] / "payload.bin").write_bytes(b"legacy-stage")
        self.nodes[1].write_bytes(b"orphan-log")
        self.dotenv = self.root / ".env"
        self.dotenv.write_text(
            "DB_HOST=127.0.0.1\nDB_PORT=3306\nDB_NAME=app\nDB_USER=app\nDB_PASSWORD=secret\n",
            encoding="utf-8",
        )
        ai = "\n".join(f"env[{name}] = configured" for name in sorted(self.ai_keys()))
        self.originals = {
            "fpm": (
                "[www]\nlisten.owner = www\nlisten.group = www\nlisten.mode = 0666\n"
                "clear_env = no\n" + ai + "\n"
            ).encode(),
            "php_ini": b"[PHP]\ncgi.fix_pathinfo = 1\n",
            "nginx": (
                "server {\n    location /uploads/ {\n        try_files $uri =404;\n    }\n"
                "    location ~ \\.php$ { fastcgi_pass unix:/tmp/php.sock; }\n}\n"
            ).encode(),
        }
        self.configs = {
            "fpm": self.base / "php-fpm.conf",
            "php_ini": self.base / "php.ini",
            "nginx": self.base / "site.conf",
        }
        for label, path in self.configs.items():
            path.write_bytes(self.originals[label])
            os.chmod(path, 0o600 if label == "nginx" else 0o644)
        self.snippet = MODULE.load_nginx_snippet()
        nginx_before = self.originals["nginx"].decode()
        start = nginx_before.index("    location /uploads/")
        end = nginx_before.index("    location ~", start)
        self.candidates = {
            "fpm": self.originals["fpm"].replace(b"listen.mode = 0666", b"listen.mode = 0660", 1).replace(b"clear_env = no", b"clear_env = yes", 1),
            "php_ini": self.originals["php_ini"].replace(b"cgi.fix_pathinfo = 1", b"cgi.fix_pathinfo = 0", 1),
            "nginx": (nginx_before[:start] + indent_snippet(self.snippet) + nginx_before[end:]).encode(),
        }
        self.expected_nodes = [expected_node(path, f"{index:02d}-{path.name}") for index, path in enumerate(self.nodes, 1)]
        self.expected_configs: list[dict[str, object]] = []
        for label, path in self.configs.items():
            meta = os.lstat(path)
            size, sha = digest(path)
            self.expected_configs.append(
                {
                    "label": label,
                    "path": str(path),
                    "path_sha256": hashlib.sha256(str(path).encode()).hexdigest(),
                    "sha256": sha,
                    "device": meta.st_dev,
                    "inode": meta.st_ino,
                    "nlink": meta.st_nlink,
                    "uid": meta.st_uid,
                    "gid": meta.st_gid,
                    "mode": stat.S_IMODE(meta.st_mode),
                    "size": size,
                    "mtime_ns": meta.st_mtime_ns,
                    "candidate_sha256": hashlib.sha256(self.candidates[label]).hexdigest(),
                }
            )
        self.command = self.base / "ok.py"
        self.command.write_text("raise SystemExit(0)\n", encoding="utf-8")
        self.health = self.base / "health.json"
        self.health.write_text(json.dumps({"code": 1, "status": "ok", "database": "connected"}), encoding="utf-8")
        ok = [sys.executable, str(self.command)]
        self.layout = {
            "test_mode": True,
            "real_linux_primitives": False,
            "fault": fault,
            "root": str(self.root),
            "evidence_parent": str(self.evidence),
            "fpm_config": str(self.configs["fpm"]),
            "php_ini": str(self.configs["php_ini"]),
            "nginx_config": str(self.configs["nginx"]),
            "dotenv": str(self.dotenv),
            "fpm_test": ok,
            "php_test": ok,
            "nginx_test": ok,
            "fpm_reload": ok,
            "nginx_reload": ok,
            "socket": str(self.base / "socket"),
            "health_url": "unused",
            "uploads_url_prefix": "unused",
            "test_health": str(self.health),
        }

    @staticmethod
    def ai_keys() -> set[str]:
        return {
            "AI_ENABLED", "AI_PROVIDER", "AI_API_URL", "AI_MODEL", "AI_CONNECT_TIMEOUT", "AI_TIMEOUT",
            "AI_MAX_TOKENS", "AI_TEMPERATURE", "AI_HISTORY_LIMIT", "AI_KNOWLEDGE_LIMIT",
            "AI_CONTEXT_DOCUMENT_LIMIT", "AI_CONTEXT_CHARS_PER_DOCUMENT", "AI_HISTORY_MESSAGE_CHARS",
            "AI_RETRY_AFTER_SECONDS", "AI_FALLBACK_ENABLED", "AI_PUBLIC_KNOWLEDGE_ENABLED",
            "AI_PUBLIC_KNOWLEDGE_TIMEOUT", "AI_PUBLIC_KNOWLEDGE_CACHE_SECONDS", "AI_PUBLIC_KNOWLEDGE_LIMIT",
        }

    def run(self, action: str, token: str = "a" * 32) -> subprocess.CompletedProcess[str]:
        source = MODULE.build_remote_source(self.layout, self.expected_nodes, self.expected_configs, self.snippet)
        return subprocess.run(
            [
                sys.executable,
                "-I",
                "-S",
                "-B",
                "-c",
                "import sys;exec(compile(sys.stdin.read(),'<fixture>','exec'))",
                action,
                token,
            ],
            input=source,
            text=True,
            capture_output=True,
            timeout=60,
            check=False,
        )

    def archives(self) -> list[Path]:
        return sorted(
            (path for path in self.evidence.iterdir() if not path.name.startswith(".production-config-prerequisites-")),
            key=lambda path: path.name,
        )

    def journals(self) -> list[Path]:
        return sorted(self.evidence.glob(".production-config-prerequisites-*.status.json"), key=lambda path: path.name)

    def close(self) -> None:
        self.temporary.cleanup()


class ContractTests(unittest.TestCase):
    def test_production_allowlists_are_frozen_and_unique(self) -> None:
        self.assertEqual(len(MODULE.EXPECTED_NODES), 7)
        self.assertEqual(len({item.path for item in MODULE.EXPECTED_NODES}), 7)
        self.assertEqual(len({item.canonical_manifest_v1_sha256 for item in MODULE.EXPECTED_NODES}), 7)
        self.assertEqual(len(MODULE.EXPECTED_CONFIGS), 3)
        self.assertTrue(all(re.fullmatch(r"[0-9a-f]{64}", item.candidate_sha256) for item in MODULE.EXPECTED_CONFIGS))

    def test_reviewed_snippet_is_bound_and_static_only(self) -> None:
        snippet = MODULE.load_nginx_snippet()
        self.assertIn("location ^~ /uploads/", snippet)
        self.assertIn("limit_except GET HEAD", snippet)
        self.assertIn("disable_symlinks on", snippet)
        self.assertNotIn("fastcgi", snippet.lower())

    def test_remote_source_contains_fail_closed_primitives(self) -> None:
        source = MODULE.production_remote_source()
        for required in (
            '"/usr/bin/mv","-T","--no-clobber"',
            "canonical-manifest-v1",
            "fd_refs",
            "source_refs",
            "fcntl.flock",
            "production-config-prerequisites-status-v1",
            "create_journal",
            "advance_journal",
            "validate_archived_nodes",
            "verify_original_hold",
            "status(token)",
            "reconcile(token)",
            "os.link(live,hold",
            "os.replace(candidate,live)",
            "syntax_tests()",
            "reload_and_readback",
            "upload_script_not_rejected",
            "recovery_required",
        ):
            self.assertIn(required, source)
        self.assertNotIn("rm -rf", source)

    def test_execute_requires_both_exact_confirmations(self) -> None:
        with mock.patch.dict(os.environ, {"YY_SSH_PASSWORD": "secret"}, clear=False):
            with self.assertRaisesRegex(RuntimeError, "both exact"):
                MODULE.main([
                    "--host", MODULE.EXPECTED_HOST,
                    "--known-hosts", __file__,
                    "--execute",
                ])

    def test_strict_receipt_rejects_duplicates_and_extra_lines(self) -> None:
        with self.assertRaises(RuntimeError):
            MODULE.strict_json_line('{"a":1,"a":2}')
        with self.assertRaises(RuntimeError):
            MODULE.strict_json_line('{}\n{}')

    def test_response_loss_after_execute_is_recovery_required(self) -> None:
        class Client:
            def exec_command(self, *_args, **_kwargs):
                raise TimeoutError("lost")

        with self.assertRaisesRegex(RuntimeError, "RECOVERY_REQUIRED"):
            MODULE.run_remote(Client(), "command", "secret", "apply")

    def test_malformed_execute_receipt_is_recovery_required(self) -> None:
        class Client:
            def close(self) -> None:
                return None

        argv = [
            "--host", MODULE.EXPECTED_HOST,
            "--known-hosts", __file__,
            "--execute",
            "--confirm", MODULE.EXECUTE_CONFIRMATION,
            "--maintenance-confirmed", MODULE.MAINTENANCE_CONFIRMATION,
        ]
        with (
            mock.patch.dict(os.environ, {"YY_SSH_PASSWORD": "secret"}, clear=False),
            mock.patch.object(MODULE, "connect", return_value=Client()),
            mock.patch.object(MODULE, "run_remote", return_value=(0, "not-json")),
            mock.patch.object(MODULE.secrets, "token_hex", return_value="b" * 32),
            self.assertRaisesRegex(RuntimeError, "RECOVERY_REQUIRED"),
        ):
            MODULE.main(argv)

    def test_receipt_validation_rejects_extra_or_wrong_fields(self) -> None:
        audit = {
            "CONFIG_PREREQUISITES_AUDIT": "pass",
            "schema": "production-config-prerequisites-v1",
            "nodes": 7,
            "fd_refs": 0,
            "source_refs": 0,
            "public": {"nodes": 32, "files": 29, "payload_size": 344903},
            "environment": {"db_from_dotenv": 5, "ai_from_pool": 19, "mail_state": "default-disabled"},
            "candidate_sha256": {item.label: item.candidate_sha256 for item in MODULE.EXPECTED_CONFIGS},
            "historical_algorithm_comparable": False,
            "write_actions": 0,
        }
        MODULE.validate_audit_receipt(audit)
        with self.assertRaisesRegex(RuntimeError, "fields"):
            MODULE.validate_audit_receipt({**audit, "unexpected": True})
        recovery = {
            "CONFIG_PREREQUISITES_APPLY": "recovery_required",
            "schema": "production-config-prerequisites-v1",
            "transaction_token": "c" * 32,
            "phase": "recovery_required",
            "journal_path_sha256": "d" * 64,
            "archive_path_sha256": "e" * 64,
            "failure_code": "lost_response",
            "components": ["state_uncertain"],
            "reconcile_required": True,
        }
        self.assertEqual(MODULE.validate_apply_receipt(97, recovery, "c" * 32), "recovery_required")
        with self.assertRaisesRegex(RuntimeError, "token"):
            MODULE.validate_apply_receipt(97, recovery, "f" * 32)

    def test_status_and_reconcile_cli_boundaries(self) -> None:
        with mock.patch.dict(os.environ, {"YY_SSH_PASSWORD": "secret"}, clear=False):
            with self.assertRaisesRegex(RuntimeError, "exact transaction token"):
                MODULE.main(["--host", MODULE.EXPECTED_HOST, "--known-hosts", __file__, "--status"])
            with self.assertRaisesRegex(RuntimeError, "both exact"):
                MODULE.main([
                    "--host", MODULE.EXPECTED_HOST,
                    "--known-hosts", __file__,
                    "--reconcile",
                    "--transaction-token", "a" * 32,
                ])


class DynamicTransactionTests(unittest.TestCase):
    def test_audit_is_read_only(self) -> None:
        fixture = Fixture()
        try:
            before = {str(path): path.read_bytes() if path.is_file() else sorted(item.name for item in path.iterdir()) for path in fixture.nodes + list(fixture.configs.values())}
            result = fixture.run("audit")
            self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
            receipt = json.loads(result.stdout)
            self.assertEqual(receipt["CONFIG_PREREQUISITES_AUDIT"], "pass")
            self.assertEqual(receipt["write_actions"], 0)
            after = {str(path): path.read_bytes() if path.is_file() else sorted(item.name for item in path.iterdir()) for path in fixture.nodes + list(fixture.configs.values())}
            self.assertEqual(before, after)
            self.assertEqual(list(fixture.evidence.iterdir()), [])
        finally:
            fixture.close()

    def test_success_archives_without_deletion_and_applies_all_configs(self) -> None:
        fixture = Fixture()
        try:
            result = fixture.run("apply")
            self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
            receipt = json.loads(result.stdout)
            self.assertEqual(receipt["CONFIG_PREREQUISITES_APPLY"], "pass")
            self.assertEqual(receipt["nodes_archived"], 2)
            self.assertTrue(all(not path.exists() for path in fixture.nodes))
            archives = fixture.archives()
            self.assertEqual(len(archives), 1)
            self.assertEqual(len(fixture.journals()), 1)
            self.assertFalse(archives[0].name.endswith(".partial"))
            archived = archives[0] / "archived-nodes"
            self.assertEqual(len(list(archived.iterdir())), 2)
            holds = archives[0] / "config-original-inodes"
            self.assertEqual(len(list(holds.iterdir())), 3)
            for label, path in fixture.configs.items():
                self.assertEqual(path.read_bytes().replace(b"\r\n", b"\n"), fixture.candidates[label])
            manifest = json.loads((archives[0] / "manifest.json").read_text(encoding="utf-8"))
            self.assertEqual(manifest["state"], "committed")
            journal = json.loads(fixture.journals()[0].read_text(encoding="utf-8"))
            self.assertEqual(journal["phase"], "committed")
            status_result = fixture.run("status")
            self.assertEqual(status_result.returncode, 0, status_result.stderr + status_result.stdout)
            status_receipt = json.loads(status_result.stdout)
            self.assertEqual(status_receipt["CONFIG_PREREQUISITES_STATUS"], "pass")
            self.assertEqual(status_receipt["write_actions"], 0)
        finally:
            fixture.close()

    def test_candidate_syntax_failure_restores_nodes_and_original_inodes(self) -> None:
        fixture = Fixture("syntax")
        original_inodes = {label: os.lstat(path).st_ino for label, path in fixture.configs.items()}
        try:
            result = fixture.run("apply")
            self.assertEqual(result.returncode, 2, result.stderr + result.stdout)
            receipt = json.loads(result.stdout)
            self.assertEqual(receipt["CONFIG_PREREQUISITES_APPLY"], "restored")
            self.assertTrue(all(path.exists() for path in fixture.nodes))
            for label, path in fixture.configs.items():
                self.assertEqual(path.read_bytes(), fixture.originals[label])
                self.assertEqual(os.lstat(path).st_ino, original_inodes[label])
            archives = fixture.archives()
            self.assertEqual(len(archives), 1)
            self.assertTrue(archives[0].name.endswith(".partial"))
            manifest = json.loads((archives[0] / "manifest.json").read_text(encoding="utf-8"))
            self.assertEqual(manifest["state"], "prepared")
            journal = json.loads(fixture.journals()[0].read_text(encoding="utf-8"))
            self.assertEqual(journal["phase"], "restored")
        finally:
            fixture.close()

    def test_restore_failure_returns_only_recovery_required(self) -> None:
        fixture = Fixture("syntax_rollback")
        try:
            result = fixture.run("apply")
            self.assertEqual(result.returncode, 97, result.stderr + result.stdout)
            receipt = json.loads(result.stdout)
            self.assertEqual(receipt["CONFIG_PREREQUISITES_APPLY"], "recovery_required")
            self.assertIn("config_restore", receipt["components"])
            self.assertTrue(any(path.name.endswith(".partial") for path in fixture.archives()))
            fixture.layout["fault"] = ""
            reconciled = fixture.run("reconcile")
            self.assertEqual(reconciled.returncode, 0, reconciled.stderr + reconciled.stdout)
            self.assertEqual(json.loads(reconciled.stdout)["CONFIG_PREREQUISITES_RECONCILE"], "restored")
        finally:
            fixture.close()

    def test_early_journal_and_manifest_uncertainty_is_status_visible_and_reconcilable(self) -> None:
        for fault in ("journal_after_file_fsync", "journal_after_replace", "manifest_prepared_after_replace"):
            with self.subTest(fault=fault):
                fixture = Fixture(fault)
                try:
                    result = fixture.run("apply")
                    self.assertEqual(result.returncode, 97, result.stderr + result.stdout)
                    self.assertEqual(json.loads(result.stdout)["CONFIG_PREREQUISITES_APPLY"], "recovery_required")
                    self.assertEqual(len(fixture.journals()), 1)
                    fixture.layout["fault"] = ""
                    status_result = fixture.run("status")
                    self.assertEqual(status_result.returncode, 97, status_result.stderr + status_result.stdout)
                    self.assertTrue(json.loads(status_result.stdout)["reconcile_required"])
                    reconciled = fixture.run("reconcile")
                    self.assertEqual(reconciled.returncode, 0, reconciled.stderr + reconciled.stdout)
                    self.assertEqual(json.loads(reconciled.stdout)["CONFIG_PREREQUISITES_RECONCILE"], "restored")
                finally:
                    fixture.close()

    def test_move_and_activation_uncertainty_require_explicit_reconcile(self) -> None:
        for fault in ("move_after_replace", "activation_after_replace"):
            with self.subTest(fault=fault):
                fixture = Fixture(fault)
                original_inodes = {label: os.lstat(path).st_ino for label, path in fixture.configs.items()}
                try:
                    result = fixture.run("apply")
                    self.assertEqual(result.returncode, 97, result.stderr + result.stdout)
                    fixture.layout["fault"] = ""
                    status_receipt = json.loads(fixture.run("status").stdout)
                    self.assertEqual(status_receipt["CONFIG_PREREQUISITES_STATUS"], "recovery_required")
                    reconciled = fixture.run("reconcile")
                    self.assertEqual(reconciled.returncode, 0, reconciled.stderr + reconciled.stdout)
                    self.assertEqual(json.loads(reconciled.stdout)["CONFIG_PREREQUISITES_RECONCILE"], "restored")
                    self.assertTrue(all(path.exists() for path in fixture.nodes))
                    for label, path in fixture.configs.items():
                        self.assertEqual(path.read_bytes(), fixture.originals[label])
                        self.assertEqual(os.lstat(path).st_ino, original_inodes[label])
                finally:
                    fixture.close()

    def test_late_archive_manifest_and_journal_uncertainty_reconcile_to_commit(self) -> None:
        for fault in ("archive_after_replace", "manifest_commit_after_replace", "journal_commit_after_replace"):
            with self.subTest(fault=fault):
                fixture = Fixture(fault)
                try:
                    result = fixture.run("apply")
                    self.assertEqual(result.returncode, 97, result.stderr + result.stdout)
                    receipt = json.loads(result.stdout)
                    self.assertEqual(receipt["CONFIG_PREREQUISITES_APPLY"], "recovery_required")
                    fixture.layout["fault"] = ""
                    reconciled = fixture.run("reconcile")
                    self.assertEqual(reconciled.returncode, 0, reconciled.stderr + reconciled.stdout)
                    self.assertEqual(json.loads(reconciled.stdout)["CONFIG_PREREQUISITES_RECONCILE"], "committed")
                    status_result = fixture.run("status")
                    self.assertEqual(status_result.returncode, 0, status_result.stderr + status_result.stdout)
                    self.assertEqual(json.loads(status_result.stdout)["phase"], "committed")
                finally:
                    fixture.close()

    def test_first_write_is_journaled_and_ordinary_early_failure_restores(self) -> None:
        fixture = Fixture("after_journal")
        try:
            result = fixture.run("apply")
            self.assertEqual(result.returncode, 2, result.stderr + result.stdout)
            self.assertEqual(len(fixture.journals()), 1)
            self.assertEqual(json.loads(fixture.journals()[0].read_text(encoding="utf-8"))["phase"], "restored")
            self.assertTrue(all(path.exists() for path in fixture.nodes))
            self.assertEqual(fixture.archives(), [])
        finally:
            fixture.close()

    def test_status_is_read_only_and_does_not_advance_journal(self) -> None:
        fixture = Fixture("move_after_replace")
        try:
            self.assertEqual(fixture.run("apply").returncode, 97)
            fixture.layout["fault"] = ""
            journal = fixture.journals()[0]
            before = journal.read_bytes()
            first = fixture.run("status")
            second = fixture.run("status")
            self.assertEqual(first.returncode, 97)
            self.assertEqual(second.returncode, 97)
            self.assertEqual(journal.read_bytes(), before)
            self.assertEqual(json.loads(first.stdout)["write_actions"], 0)
        finally:
            fixture.close()

    def test_tampered_archive_is_never_moved_back_as_a_valid_source(self) -> None:
        fixture = Fixture("move_after_replace")
        try:
            self.assertEqual(fixture.run("apply").returncode, 97)
            archive = fixture.archives()[0]
            (archive / "archived-nodes" / "01-legacy-stage" / "payload.bin").write_bytes(b"tampered")
            fixture.layout["fault"] = ""
            reconciled = fixture.run("reconcile")
            self.assertEqual(reconciled.returncode, 97, reconciled.stderr + reconciled.stdout)
            receipt = json.loads(reconciled.stdout)
            self.assertEqual(receipt["CONFIG_PREREQUISITES_RECONCILE"], "recovery_required")
            self.assertFalse(fixture.nodes[0].exists())
        finally:
            fixture.close()


@unittest.skipUnless(sys.platform.startswith("linux") and Path("/usr/bin/mv").is_file(), "requires Linux /proc, flock and /usr/bin/mv")
class LinuxPrimitiveIntegrationTests(unittest.TestCase):
    def test_real_mv_fsync_fd_socket_and_loopback_http(self) -> None:
        import fcntl

        fixture = Fixture()

        class Handler(http.server.BaseHTTPRequestHandler):
            def do_GET(self) -> None:  # noqa: N802 - stdlib callback name
                if self.path == "/api/health":
                    payload = json.dumps({"code": 1, "data": {"status": "ok", "database": "connected"}}).encode()
                    self.send_response(200)
                    self.send_header("Content-Type", "application/json")
                    self.send_header("Content-Length", str(len(payload)))
                    self.end_headers()
                    self.wfile.write(payload)
                    return
                self.send_response(404)
                self.send_header("Content-Length", "0")
                self.end_headers()

            def log_message(self, _format: str, *_args: object) -> None:
                return None

        unix_socket = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        try:
            fixture.layout["real_linux_primitives"] = True
            fixture.layout["socket_uid"] = os.getuid()
            fixture.layout["socket_gid"] = os.getgid()
            unix_socket.bind(fixture.layout["socket"])
            os.chmod(fixture.layout["socket"], 0o660)
            port = server.server_address[1]
            fixture.layout["health_url"] = f"http://127.0.0.1:{port}/api/health"
            fixture.layout["uploads_url_prefix"] = f"http://127.0.0.1:{port}/uploads/"
            thread.start()

            descriptor = os.open(fixture.nodes[1], os.O_RDONLY)
            try:
                blocked = fixture.run("audit")
            finally:
                os.close(descriptor)
            self.assertEqual(blocked.returncode, 1, blocked.stderr + blocked.stdout)
            self.assertEqual(json.loads(blocked.stdout)["reason_code"], "node_reference_boundary")

            lock_descriptor = os.open(fixture.evidence, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
            try:
                fcntl.flock(lock_descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
                locked = fixture.run("apply")
            finally:
                fcntl.flock(lock_descriptor, fcntl.LOCK_UN)
                os.close(lock_descriptor)
            self.assertEqual(locked.returncode, 97, locked.stderr + locked.stdout)
            self.assertEqual(fixture.journals(), [])

            result = fixture.run("apply")
            self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
            archive = fixture.archives()[0]
            self.assertTrue((archive / "archived-nodes" / "02-orphan.log").is_file())
            self.assertEqual(stat.S_IMODE(os.lstat(archive).st_mode), 0o700)
            self.assertEqual(stat.S_IMODE(os.lstat(fixture.journals()[0]).st_mode), 0o600)
            self.assertEqual(json.loads(result.stdout)["holds_retained"], 3)
        finally:
            server.shutdown()
            server.server_close()
            unix_socket.close()
            thread.join(timeout=5)
            fixture.close()


if __name__ == "__main__":
    unittest.main()
