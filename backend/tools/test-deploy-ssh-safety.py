#!/usr/bin/env python3
"""Static release-safety contract for deploy-ssh.py (no network required)."""

from __future__ import annotations

import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shlex
import shutil
import subprocess
import tarfile
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock


DEPLOY_PATH = Path(__file__).with_name("deploy-ssh.py")
SOURCE = DEPLOY_PATH.read_text(encoding="utf-8")
SPEC = importlib.util.spec_from_file_location("deploy_ssh_under_test", DEPLOY_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Unable to load deploy-ssh.py for command construction tests")
DEPLOY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DEPLOY)


class DeploySshSafetyContractTest(unittest.TestCase):
    def test_ssh_requires_a_pinned_known_hosts_file(self) -> None:
        self.assertIn('parser.add_argument(\n        "--known-hosts",\n        required=True,', SOURCE)
        self.assertIn("client.load_host_keys(args.known_hosts)", SOURCE)
        self.assertIn("paramiko.RejectPolicy()", SOURCE)
        self.assertNotIn("AutoAddPolicy", SOURCE)

    def test_remote_command_timeout_is_forwarded_and_output_is_preserved(self) -> None:
        class FakeChannel:
            def __init__(self) -> None:
                self.timeout: int | None = None

            def settimeout(self, timeout: int) -> None:
                self.timeout = timeout

            def recv_exit_status(self) -> int:
                return 0

        class FakeStream:
            def __init__(self, payload: bytes, channel: FakeChannel) -> None:
                self.payload = payload
                self.channel = channel

            def read(self) -> bytes:
                return self.payload

        channel = FakeChannel()
        observed: dict[str, object] = {}

        class FakeClient:
            def exec_command(self, command: str, **kwargs: object) -> tuple[object, FakeStream, FakeStream]:
                observed["command"] = command
                observed.update(kwargs)
                return object(), FakeStream(b"offline output\n", channel), FakeStream(b"", channel)

        with mock.patch("builtins.print") as print_mock:
            output = DEPLOY.run_with_status(
                FakeClient(), "offline-command", "offline-timeout-contract", {0}
            )

        self.assertEqual(DEPLOY.REMOTE_COMMAND_TIMEOUT_SECONDS, 7200)
        self.assertEqual(observed["command"], "offline-command")
        self.assertFalse(observed["get_pty"])
        self.assertEqual(observed["timeout"], 7200)
        self.assertEqual(channel.timeout, 7200)
        self.assertEqual(output, "offline output\n")
        print_mock.assert_called_once_with("[offline-timeout-contract] offline output")

    def test_remote_command_timeout_fails_closed_and_closes_channel(self) -> None:
        class FakeChannel:
            def __init__(self) -> None:
                self.timeout: int | None = None
                self.closed = False

            def settimeout(self, timeout: int) -> None:
                self.timeout = timeout

            def close(self) -> None:
                self.closed = True

        class TimeoutStream:
            def __init__(self, channel: FakeChannel) -> None:
                self.channel = channel

            def read(self) -> bytes:
                raise TimeoutError("offline timeout")

        channel = FakeChannel()

        class FakeClient:
            def exec_command(self, *_args: object, **_kwargs: object) -> tuple[object, TimeoutStream, TimeoutStream]:
                stream = TimeoutStream(channel)
                return object(), stream, stream

        with self.assertRaisesRegex(
            RuntimeError, "offline-timeout timed out after 7200 seconds"
        ) as raised:
            DEPLOY.run_with_status(FakeClient(), "offline-command", "offline-timeout", {0})

        self.assertIsInstance(raised.exception.__cause__, TimeoutError)
        self.assertEqual(channel.timeout, 7200)
        self.assertTrue(channel.closed)

    def test_database_backup_is_checked_before_compression(self) -> None:
        self.assertIn('dump_sql_q = quote(backup_dir + "/database.sql")', SOURCE)
        self.assertIn("--triggers {database_q} > {dump_sql_q}", SOURCE)
        self.assertIn("test -s {dump_sql_q}; gzip -c {dump_sql_q} > {dump_path_q}", SOURCE)
        self.assertIn("gzip -t {dump_path_q}", SOURCE)
        self.assertNotIn("| gzip -c", SOURCE)

    def test_release_archive_is_bound_to_identity_and_build_commit(self) -> None:
        self.assertIn('parser.add_argument("--release-identity", required=True)', SOURCE)
        self.assertIn('parser.add_argument("--build-source-commit", required=True)', SOURCE)
        self.assertIn('re.fullmatch(r"[0-9a-f]{40}", commit)', SOURCE)
        self.assertIn('identity.get("version_name") != release_version', SOURCE)
        self.assertIn('archive.pax_headers.get("comment", "")', SOURCE)
        self.assertIn('"backend/config/release-identity.json"', SOURCE)
        self.assertIn('"backend/tools/audit-default-credentials.php"', SOURCE)
        self.assertIn('"backend/tools/backfill-catalog-source-uploads.php"', SOURCE)
        self.assertIn('"backend/tools/quarantine-catalog-public-files.php"', SOURCE)
        self.assertIn('Archive release identity does not match --release-identity', SOURCE)
        self.assertIn('ACTUAL_IDENTITY_SHA256=$(sha256sum', SOURCE)
        self.assertIn(r'test \"${{ACTUAL_IDENTITY_SHA256%% *}}\"', SOURCE)

    def test_archive_sha256_is_precomputed_and_checked_without_echoing_hashes(self) -> None:
        with tempfile.TemporaryDirectory() as temporary_directory:
            archive = Path(temporary_directory) / "release.tar.gz"
            archive.write_bytes(b"archive-fixture")
            expected = hashlib.sha256(b"archive-fixture").hexdigest()
            self.assertEqual(DEPLOY.sha256_file(str(archive)), expected)

        command = DEPLOY.archive_sha256_check_command(
            "/tmp/yiyunying-release.tar.gz",
            expected,
        )
        self.assertIn("ACTUAL_ARCHIVE_SHA256=$(sha256sum", command)
        self.assertIn(f'= {expected}', command)
        self.assertNotIn("echo", command)
        self.assertLess(
            SOURCE.index('archive_sha256 = sha256_file(args.archive)'),
            SOURCE.index('identity_sha256, build_source_commit = validate_release_archive('),
        )
        self.assertLess(
            SOURCE.index('"archive-sha256-check"'),
            SOURCE.index('"archive-check"'),
        )
        with self.assertRaisesRegex(ValueError, "lowercase hexadecimal"):
            DEPLOY.archive_sha256_check_command("/tmp/release.tar.gz", "not-a-hash")

    def test_deployment_temporary_paths_are_exclusive_root_only_and_exactly_cleaned(self) -> None:
        slug = "20260815-120102-0123456789abcdef"
        archive = f"/tmp/yiyunying-backend-{slug}.tar.gz"
        stage = f"/tmp/yiyunying-stage-{slug}"
        digest = "a" * 64

        archive_create = DEPLOY.deployment_archive_create_command(archive)
        archive_cleanup = DEPLOY.deployment_archive_cleanup_command(
            archive, digest, ownership_confirmed=False
        )
        stage_create = DEPLOY.deployment_stage_create_command(stage)
        stage_cleanup = DEPLOY.deployment_stage_cleanup_command(stage)

        self.assertIn("set -euC", archive_create)
        self.assertIn("root|root|600|1", archive_create)
        self.assertIn("YY_DEPLOY_ARCHIVE_V1", archive_create)
        self.assertIn("root|root|600|1", archive_cleanup)
        self.assertIn("unowned-or-partial-deployment-archive", archive_cleanup)
        self.assertIn("rm -f --", archive_cleanup)
        self.assertNotIn("*", archive_cleanup)

        self.assertIn("set -euC", stage_create)
        self.assertIn("directory|root|root|700", stage_create)
        self.assertIn("YY_DEPLOY_STAGE_V1", stage_create)
        self.assertNotIn("mkdir -p", stage_create)
        self.assertIn("directory|root|root|700", stage_cleanup)
        self.assertIn("-type l", stage_cleanup)
        self.assertIn("-links +1", stage_cleanup)
        self.assertIn("findmnt -rn -o TARGET", stage_cleanup)
        self.assertIn("rm -rf --", stage_cleanup)
        self.assertNotIn("*", stage_cleanup)

        for unsafe in (
            "/tmp/yiyunying-backend-current.tar.gz",
            "/tmp/yiyunying-backend-20260815-120102-0123456789abcdef.tar.gz/extra",
            "/tmp/yiyunying-stage-current",
            "/tmp/yiyunying-stage-20260815-120102-0123456789abcdef/extra",
        ):
            with self.subTest(unsafe=unsafe):
                if "backend" in unsafe:
                    with self.assertRaisesRegex(ValueError, "reviewed deployment namespace"):
                        DEPLOY.deployment_archive_create_command(unsafe)
                else:
                    with self.assertRaisesRegex(ValueError, "reviewed deployment namespace"):
                        DEPLOY.deployment_stage_create_command(unsafe)

        # Execute the generated guard in a real POSIX shell.  Each producer
        # below is a real executable that returns non-zero on demand; a failed
        # inspection must leave the exact owned stage untouched.
        shell = shutil.which("sh")
        if shell is None:
            git = shutil.which("git")
            if git is not None:
                bundled_shell = Path(git).resolve().parent.parent / "usr" / "bin" / "sh.exe"
                if bundled_shell.is_file():
                    shell = str(bundled_shell)
        self.assertIsNotNone(shell, "A POSIX shell is required for cleanup fault injection")
        assert shell is not None

        with tempfile.TemporaryDirectory() as temporary_directory:
            fake_bin = Path(temporary_directory) / "bin"
            fake_bin.mkdir()
            wrappers = {
                "stat": """#!/bin/sh
case "$2" in
  '%F|%U|%G|%a') printf '%s\\n' 'directory|root|root|700' ;;
  '%F|%U|%G|%a|%h') printf '%s\\n' 'regular file|root|root|600|1' ;;
  *) exit 31 ;;
esac
""",
                "find": """#!/bin/sh
count=0
if [ -f "$FIND_COUNTER" ]; then count=$(cat "$FIND_COUNTER") || exit 30; fi
count=$((count + 1))
printf '%s\\n' "$count" > "$FIND_COUNTER" || exit 30
if [ "$count" -eq "$FAIL_FIND_CALL" ]; then exit 23; fi
exit 0
""",
                "findmnt": """#!/bin/sh
if [ "${FAIL_FINDMNT:-0}" -eq 1 ]; then exit 24; fi
exit 0
""",
            }
            for name, source in wrappers.items():
                wrapper = fake_bin / name
                wrapper.write_bytes(source.encode("utf-8"))
                wrapper.chmod(0o755)

            shell_tools = str(Path(shell).resolve().parent)
            environment = os.environ.copy()
            environment["PATH"] = str(fake_bin) + os.pathsep + shell_tools
            dynamic_slug = (
                "20260815-120103-"
                + hashlib.sha256(temporary_directory.encode("utf-8")).hexdigest()[:16]
            )
            dynamic_stage = f"/tmp/yiyunying-stage-{dynamic_slug}"
            dynamic_marker = DEPLOY.deployment_stage_marker_path(dynamic_stage)
            marker_value = DEPLOY.deployment_stage_marker(dynamic_stage).rstrip("\n")
            counter = f"/tmp/yiyunying-find-counter-{dynamic_slug}"
            setup = (
                f"rm -rf -- {shlex.quote(dynamic_stage)}; "
                f"rm -f -- {shlex.quote(counter)}; "
                f"mkdir -- {shlex.quote(dynamic_stage)}; "
                f"printf '%s\\n' {shlex.quote(marker_value)} > {shlex.quote(dynamic_marker)}"
            )
            teardown = (
                f"rm -rf -- {shlex.quote(dynamic_stage)}; "
                f"rm -f -- {shlex.quote(counter)}"
            )
            cleanup_command = DEPLOY.deployment_stage_cleanup_command(dynamic_stage)

            try:
                for failed_find in (1, 2, 3):
                    with self.subTest(failed_producer=f"find-{failed_find}"):
                        subprocess.run(
                            [shell, "-c", setup],
                            env=environment,
                            check=True,
                            capture_output=True,
                            text=True,
                        )
                        fault_environment = environment | {
                            "FIND_COUNTER": counter,
                            "FAIL_FIND_CALL": str(failed_find),
                            "FAIL_FINDMNT": "0",
                        }
                        result = subprocess.run(
                            [shell, "-c", cleanup_command],
                            env=fault_environment,
                            check=False,
                            capture_output=True,
                            text=True,
                        )
                        self.assertEqual(result.returncode, DEPLOY.DEPLOYMENT_CLEANUP_FAILURE_STATUS)
                        self.assertEqual(
                            result.stderr,
                            "RECOVERY_REQUIRED=deployment-stage-cleanup-boundary\n",
                        )
                        stage_readback = subprocess.run(
                            [shell, "-c", f"test -d {shlex.quote(dynamic_stage)}"],
                            env=environment,
                            check=False,
                        )
                        self.assertEqual(stage_readback.returncode, 0)

                with self.subTest(failed_producer="findmnt"):
                    subprocess.run(
                        [shell, "-c", setup],
                        env=environment,
                        check=True,
                        capture_output=True,
                        text=True,
                    )
                    fault_environment = environment | {
                        "FIND_COUNTER": counter,
                        "FAIL_FIND_CALL": "0",
                        "FAIL_FINDMNT": "1",
                    }
                    result = subprocess.run(
                        [shell, "-c", cleanup_command],
                        env=fault_environment,
                        check=False,
                        capture_output=True,
                        text=True,
                    )
                    self.assertEqual(result.returncode, DEPLOY.DEPLOYMENT_CLEANUP_FAILURE_STATUS)
                    self.assertEqual(
                        result.stderr,
                        "RECOVERY_REQUIRED=deployment-stage-cleanup-boundary\n",
                    )
                    stage_readback = subprocess.run(
                        [shell, "-c", f"test -d {shlex.quote(dynamic_stage)}"],
                        env=environment,
                        check=False,
                    )
                    self.assertEqual(stage_readback.returncode, 0)
            finally:
                subprocess.run(
                    [shell, "-c", teardown],
                    env=environment,
                    check=False,
                    capture_output=True,
                )

    def test_default_credential_audit_precedes_all_backup_and_maintenance_work(self) -> None:
        stage_guard = SOURCE.index("stage_backend + '/tools/audit-default-credentials.php'")
        runtime = SOURCE.index('"runtime-dependency-preflight"')
        config = SOURCE.index('"application-config-preflight"')
        audit = SOURCE.index('"default-credential-read-only-audit"')
        backup_directory = SOURCE.index('"backup-directory"')
        code_backup = SOURCE.index('"code-backup"')
        maintenance_scope = SOURCE.index("maintenance_attempted = False")
        maintenance = SOURCE.index('"catalog-maintenance"')
        migration = SOURCE.index('f"database-migration-{index}"')
        self.assertLess(stage_guard, runtime)
        self.assertLess(runtime, config)
        self.assertLess(stage_guard, config)
        self.assertLess(config, audit)
        self.assertLess(audit, backup_directory)
        self.assertLess(audit, code_backup)
        self.assertLess(audit, maintenance_scope)
        self.assertLess(audit, maintenance)
        self.assertLess(audit, migration)
        self.assertIn(
            r'\"$PHP_BIN\" tools/audit-default-credentials.php',
            SOURCE,
        )

    def test_runtime_dependency_preflight_is_strict_and_complete(self) -> None:
        command = DEPLOY.runtime_dependency_preflight_command()
        self.assertIn(f"PHP_BIN={DEPLOY.PHP82_BIN}", command)
        self.assertIn('test -x "$PHP_BIN"', command)
        self.assertIn('test ! -L "$PHP_BIN"', command)
        self.assertNotIn("command -v php", command)
        self.assertIn("PHP_VERSION_ID < 80200", command)
        for extension in ("PDO", "pdo_mysql", "mbstring", "json", "hash", "gd", "zlib"):
            self.assertIn(extension, command)
        for function in (
            "getimagesize", "imagecreatefromstring", "imagejpeg", "imagepng", "imagewebp",
            "imagetypes", "proc_open", "proc_get_status", "proc_terminate", "proc_close",
            "inflate_init", "inflate_add", "inflate_get_status", "inflate_get_read_len", "tempnam", "sys_get_temp_dir",
            "disk_free_space", "hash_file", "json_encode",
        ):
            self.assertIn(function, command)
        for codec in ("IMG_JPG", "IMG_PNG", "IMG_WEBP"):
            self.assertIn(codec, command)
        self.assertNotIn("fileinfo", command)
        for tool in (
            "tar", "sha256sum", "gzip", "rsync", "curl", "stat", "readlink",
            "mktemp", "grep", "find", "findmnt", "awk", "timeout",
        ):
            self.assertIn(tool, command)
        self.assertIn(DEPLOY.MEDIA_FFMPEG_BIN, command)
        self.assertIn(DEPLOY.MEDIA_FFPROBE_BIN, command)
        self.assertNotIn('command -v "ffmpeg"', command)
        self.assertNotIn('command -v "ffprobe"', command)
        for media_gate in (
            '"$FFMPEG_BIN" -version', '"$FFPROBE_BIN" -version', "libx264", "aac", "VIDEO_PACKETS",
            "AUDIO_PACKETS", "MEDIA_DURATION", "input.mp4", "output.mp4", "codec_type",
        ):
            self.assertIn(media_gate, command)
        for integrity_gate in (
            DEPLOY.MEDIA_RUNTIME_ROOT,
            DEPLOY.MEDIA_RUNTIME_VERSION,
            DEPLOY.MEDIA_FFMPEG_SHA256,
            DEPLOY.MEDIA_FFPROBE_SHA256,
            str(DEPLOY.MEDIA_FFMPEG_SIZE),
            str(DEPLOY.MEDIA_FFPROBE_SIZE),
            'stat -c %U:%G',
            'stat -c %h',
            'stat -c %a',
            'readlink -f',
        ):
            self.assertIn(integrity_gate, command)
        self.assertLess(command.index(DEPLOY.MEDIA_FFMPEG_SHA256), command.index('"$FFMPEG_BIN" -version'))
        self.assertLess(command.index(DEPLOY.MEDIA_FFPROBE_SHA256), command.index('"$FFPROBE_BIN" -version'))
        self.assertIn(DEPLOY.MYSQL_BIN_FALLBACK, command)
        self.assertIn(DEPLOY.MYSQLDUMP_BIN_FALLBACK, command)
        self.assertIn(DEPLOY.PHP_FPM82_INIT_SCRIPT, command)
        self.assertIn(DEPLOY.PHP_FPM82_SYSTEMD_SERVICE, command)

        repair_command = DEPLOY.runtime_dependency_preflight_command(
            require_catalog_conflict_repair=True
        )
        for extension_or_function in ("gd", "imagecreatefrompng", "imagesx", "imagesy"):
            self.assertIn(extension_or_function, repair_command)

        runtime = SOURCE.index('"runtime-dependency-preflight"')
        for later_label in (
            '"application-config-preflight"',
            '"default-credential-read-only-audit"',
            '"backup-directory"',
            '"code-backup"',
            '"catalog-maintenance"',
            'f"database-migration-{index}"',
            '"deploy-files"',
        ):
            self.assertLess(runtime, SOURCE.index(later_label))

    def test_catalog_conflict_local_source_plan_and_prepared_pngs_are_hash_bound(self) -> None:
        with tempfile.TemporaryDirectory() as temporary_directory:
            root = Path(temporary_directory)
            jpeg = root / "jpeg.png"
            heic = root / "heic.png"
            jpeg_bytes = b"\x89PNG\r\n\x1a\njpeg-fixture"
            heic_bytes = b"\x89PNG\r\n\x1a\nheic-fixture"
            jpeg.write_bytes(jpeg_bytes)
            heic.write_bytes(heic_bytes)

            def item(action: str, path_hash: str, payload: bytes) -> dict[str, object]:
                return {
                    "path_sha256": path_hash,
                    "preimage": {"sha256": "c" * 64, "size_bytes": 42},
                    "replacement": {
                        "sha256": hashlib.sha256(payload).hexdigest(),
                        "size_bytes": len(payload),
                        "width": 1,
                        "height": 1,
                        "metadata_policy": "no_ancillary_chunks_v1",
                    },
                    "expected": {
                        "admin_id": 2,
                        "app_id": 3,
                        "path_references": 8 if action == DEPLOY.CATALOG_CONFLICT_ACTION_JPEG else 3,
                        "upload_id_references": 0 if action == DEPLOY.CATALOG_CONFLICT_ACTION_JPEG else 1,
                        "upload_rows": 0 if action == DEPLOY.CATALOG_CONFLICT_ACTION_JPEG else 1,
                        "media_attachment_rows": 0 if action == DEPLOY.CATALOG_CONFLICT_ACTION_JPEG else 1,
                    },
                    "action": action,
                    "registration": (
                        {"user_id": None, "scene": "chat_image", "original_name": "legacy.png"}
                        if action == DEPLOY.CATALOG_CONFLICT_ACTION_JPEG
                        else None
                    ),
                }

            plan_data = {
                "schema": 2,
                "plan_kind": "source",
                "batch": "fixture-batch-20260814",
                "items": [
                    item(DEPLOY.CATALOG_CONFLICT_ACTION_JPEG, "a" * 64, jpeg_bytes),
                    item(DEPLOY.CATALOG_CONFLICT_ACTION_HEIC, "b" * 64, heic_bytes),
                ],
            }
            plan = root / "plan.json"
            plan.write_text(json.dumps(plan_data, separators=(",", ":")), encoding="utf-8")
            for path in (plan, jpeg, heic):
                path.chmod(0o600)

            loaded = DEPLOY.load_catalog_conflict_inputs(str(plan), str(jpeg), str(heic))
            self.assertEqual(loaded["plan_sha256"], hashlib.sha256(plan.read_bytes()).hexdigest())
            self.assertEqual(
                loaded["prepared"][DEPLOY.CATALOG_CONFLICT_ACTION_HEIC]["sha256"],
                hashlib.sha256(heic_bytes).hexdigest(),
            )
            command = DEPLOY.catalog_conflict_stage_readback_command(
                [
                    ("/tmp/private/source-plan.json", loaded["plan_size_bytes"], loaded["plan_sha256"]),
                    (
                        "/tmp/private/heic.png",
                        loaded["prepared"][DEPLOY.CATALOG_CONFLICT_ACTION_HEIC]["size_bytes"],
                        loaded["prepared"][DEPLOY.CATALOG_CONFLICT_ACTION_HEIC]["sha256"],
                    ),
                ]
            )
            self.assertIn("stat -c %a", command)
            self.assertIn("sha256sum", command)
            self.assertIn("test ! -L", command)

            plan_data["items"][1]["expected"]["path_references"] = 999
            plan.write_text(json.dumps(plan_data), encoding="utf-8")
            with self.assertRaisesRegex(RuntimeError, "reference counts"):
                DEPLOY.load_catalog_conflict_inputs(str(plan), str(jpeg), str(heic))

    def test_runtime_plan_is_server_generated_from_this_backup_and_reports_are_proved(self) -> None:
        command = DEPLOY.catalog_conflict_runtime_plan_command(
            "/srv/app/backend",
            "/tmp/private/source.json",
            "/tmp/private/runtime.json",
            "/tmp/private/jpeg.png",
            "/tmp/private/heic.png",
            "/www/backup/yiyunying/random/database.sql.gz",
            "/www/backup/yiyunying/random/public-uploads.tar.gz",
        )
        self.assertIn("catalogConflictRepairValidateSourcePlan", command)
        self.assertIn("catalogConflictRepairValidateRuntimePlan", command)
        self.assertIn("database.sql.gz", command)
        self.assertIn("public-uploads.tar.gz", command)
        self.assertIn("mtime_epoch", command)
        self.assertIn("hash_file", command)
        self.assertIn("chmod", command)
        self.assertIn("0600", command)

        basename = DEPLOY.parse_catalog_conflict_report_basename(
            "pending=0\nalready_repaired=2\nconflicts=0\nrepaired=2\nzero_work=0\n"
            "report=repair-fixture-12345678.json\n"
        )
        self.assertEqual(basename, "repair-fixture-12345678.json")
        with self.assertRaisesRegex(RuntimeError, "safe report basename"):
            DEPLOY.parse_catalog_conflict_report_basename("report=/private/report.json\n")
        apply_assertion = DEPLOY.catalog_conflict_report_assertion_command(
            "/srv/app/storage/private/catalog-conflict-repair-reports/" + basename,
            "apply",
        )
        dry_assertion = DEPLOY.catalog_conflict_report_assertion_command(
            "/srv/app/storage/private/catalog-conflict-repair-reports/readback.json",
            "dry-run",
        )
        for evidence in ("passed", "repaired", "zero_work", "conflicts", "already_repaired"):
            self.assertIn(evidence, apply_assertion)
            self.assertIn(evidence, dry_assertion)

    def test_release_archive_missing_conflict_repair_tool_fails_before_connection(self) -> None:
        commit = "a" * 40
        with tempfile.TemporaryDirectory() as temporary_directory:
            root = Path(temporary_directory)
            identity = root / "identity.json"
            identity_bytes = b'{"version_name":"1.0.0"}'
            identity.write_bytes(identity_bytes)
            archive_path = root / "release.tar.gz"
            members = set(DEPLOY.REQUIRED_RELEASE_FILES)
            members.update(f"backend/{path}" for path in DEPLOY.REQUIRED_RELEASE_MIGRATIONS)
            members.remove("backend/tools/repair-catalog-public-conflicts.php")
            with tarfile.open(
                archive_path,
                "w:gz",
                format=tarfile.PAX_FORMAT,
                pax_headers={"comment": commit},
            ) as archive:
                for name in sorted(members):
                    payload = identity_bytes if name == "backend/config/release-identity.json" else b"fixture"
                    info = tarfile.TarInfo(name)
                    info.size = len(payload)
                    archive.addfile(info, io.BytesIO(payload))
            with self.assertRaisesRegex(RuntimeError, "repair-catalog-public-conflicts.php"):
                DEPLOY.validate_release_archive(
                    str(archive_path), str(identity), "1.0.0", commit
                )

    def test_failed_runtime_preflight_never_enters_backup_or_maintenance(self) -> None:
        labels: list[str] = []
        commands: dict[str, str] = {}
        keepalive_intervals: list[int] = []
        sftp_timeouts: list[int] = []
        archive_confirms: list[bool] = []

        def fake_run(_client: object, command: str, label: str) -> str:
            labels.append(label)
            commands[label] = command
            if label == "archive-sha256-check":
                self.assertTrue(fake_sftp.closed)
            if label == "runtime-dependency-preflight":
                raise RuntimeError("runtime dependency missing")
            return ""

        class FakeSftpChannel:
            def settimeout(self, timeout: int) -> None:
                sftp_timeouts.append(timeout)

        class FakeSftp:
            def __init__(self) -> None:
                self.closed = False
                self.modes: list[int] = []

            def get_channel(self) -> FakeSftpChannel:
                return FakeSftpChannel()

            def put(self, *_args: object, **kwargs: object) -> None:
                archive_confirms.append(bool(kwargs["confirm"]))
                return None

            def chmod(self, _path: str, mode: int) -> None:
                self.modes.append(mode)

            def close(self) -> None:
                self.closed = True
                return None

        fake_sftp = FakeSftp()

        class FakeTransport:
            def is_active(self) -> bool:
                return True

            def set_keepalive(self, interval: int) -> None:
                keepalive_intervals.append(interval)

            def get_remote_server_key(self) -> object:
                return SimpleNamespace(get_fingerprint=lambda: b"\x01\x02")

        transport = FakeTransport()

        class FakeClient:
            def load_host_keys(self, _path: str) -> None:
                return None

            def set_missing_host_key_policy(self, _policy: object) -> None:
                return None

            def connect(self, *_args: object, **_kwargs: object) -> None:
                return None

            def get_transport(self) -> FakeTransport:
                return transport

            def open_sftp(self) -> FakeSftp:
                return fake_sftp

            def close(self) -> None:
                return None

        fake_paramiko = SimpleNamespace(
            SSHClient=FakeClient,
            RejectPolicy=lambda: object(),
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
            "--catalog-conflict-repair-mode", "local",
            "--catalog-conflict-repair-plan", "private-plan.json",
            "--catalog-conflict-repair-jpeg-png", "jpeg.png",
            "--catalog-conflict-repair-heic-png", "heic.png",
            "--health-url", "https://example.invalid/api/health",
            "--db-name", "app",
            "--db-user", "app",
        ]
        for migration in DEPLOY.REQUIRED_RELEASE_MIGRATIONS:
            arguments.extend(("--migration", migration))

        with (
            mock.patch.dict(
                os.environ,
                {"YY_SSH_PASSWORD": "not-printed", "YY_DB_PASSWORD": "not-printed"},
            ),
            mock.patch.dict("sys.modules", {"paramiko": fake_paramiko}),
            mock.patch("sys.argv", arguments),
            mock.patch.object(os.path, "isfile", return_value=True),
            mock.patch.object(
                DEPLOY,
                "validate_release_archive",
                return_value=("b" * 64, "a" * 40),
            ),
            mock.patch.object(DEPLOY, "sha256_file", return_value="c" * 64),
            mock.patch.object(
                DEPLOY,
                "load_catalog_conflict_inputs",
                return_value={
                    "plan_path": "private-plan.json",
                    "plan_sha256": "d" * 64,
                    "plan_size_bytes": 100,
                    "batch": "fixture-batch",
                    "prepared": {
                        DEPLOY.CATALOG_CONFLICT_ACTION_JPEG: {
                            "path": "jpeg.png", "sha256": "e" * 64, "size_bytes": 10,
                        },
                        DEPLOY.CATALOG_CONFLICT_ACTION_HEIC: {
                            "path": "heic.png", "sha256": "f" * 64, "size_bytes": 10,
                        },
                    },
                },
            ),
            mock.patch.object(DEPLOY, "run", side_effect=fake_run),
        ):
            with self.assertRaisesRegex(RuntimeError, "runtime dependency missing"):
                DEPLOY.main()

        self.assertEqual(
            labels,
            [
                "preflight", "deployment-archive-create", "archive-sha256-check",
                "archive-check", "deployment-stage-create", "stage-files",
                "runtime-dependency-preflight", "deployment-stage-cleanup",
                "deployment-archive-cleanup",
            ],
        )
        self.assertEqual(DEPLOY.SSH_KEEPALIVE_SECONDS, 15)
        self.assertEqual(keepalive_intervals, [15])
        self.assertEqual(DEPLOY.SFTP_CHANNEL_TIMEOUT_SECONDS, 300)
        self.assertEqual(sftp_timeouts, [300])
        self.assertEqual(archive_confirms, [False])
        self.assertEqual(fake_sftp.modes, [0o600])
        self.assertIn("root|root|600", commands["deployment-archive-create"])
        self.assertIn("directory|root|root|700", commands["deployment-stage-create"])
        self.assertIn("rm -rf --", commands["deployment-stage-cleanup"])
        self.assertIn("rm -f --", commands["deployment-archive-cleanup"])
        for capability in ("gd", "imagecreatefrompng", "imagesx", "imagesy"):
            self.assertIn(capability, commands["runtime-dependency-preflight"])
        self.assertNotIn("backup-directory", labels)
        self.assertNotIn("catalog-maintenance", labels)

    def test_archive_hash_mismatch_closes_sftp_and_never_reaches_unpack_or_maintenance(self) -> None:
        labels: list[str] = []
        commands: dict[str, str] = {}
        confirms: list[bool] = []

        class FakeSftpChannel:
            timeout: int | None = None

            def settimeout(self, timeout: int) -> None:
                self.timeout = timeout

        channel = FakeSftpChannel()

        class FakeSftp:
            closed = False

            def get_channel(self) -> FakeSftpChannel:
                return channel

            def put(self, *_args: object, **kwargs: object) -> None:
                confirms.append(bool(kwargs["confirm"]))

            def chmod(self, _path: str, _mode: int) -> None:
                return None

            def close(self) -> None:
                self.closed = True

        sftp = FakeSftp()

        def fake_run(_client: object, command: str, label: str) -> str:
            labels.append(label)
            commands[label] = command
            if label == "archive-sha256-check":
                self.assertTrue(sftp.closed)
                raise RuntimeError("archive-sha256-check failed (1)")
            return ""

        class FakeTransport:
            def is_active(self) -> bool:
                return True

            def set_keepalive(self, _interval: int) -> None:
                return None

            def get_remote_server_key(self) -> object:
                return SimpleNamespace(get_fingerprint=lambda: b"\x01\x02")

        transport = FakeTransport()

        class FakeClient:
            def load_host_keys(self, _path: str) -> None:
                return None

            def set_missing_host_key_policy(self, _policy: object) -> None:
                return None

            def connect(self, *_args: object, **_kwargs: object) -> None:
                return None

            def get_transport(self) -> FakeTransport:
                return transport

            def open_sftp(self) -> FakeSftp:
                return sftp

            def close(self) -> None:
                return None

        fake_paramiko = SimpleNamespace(SSHClient=FakeClient, RejectPolicy=lambda: object())
        arguments = [
            "deploy-ssh.py", "--host", "example.invalid", "--user", "deploy",
            "--known-hosts", "known-hosts", "--archive", "release.tar.gz",
            "--remote-root", "/srv/yiyunying/backend", "--release-version", "1.0.0",
            "--release-identity", "identity.json", "--build-source-commit", "a" * 40,
            "--maintenance-command", "maintenance-enter",
            "--maintenance-release-command", "maintenance-exit",
            "--health-url", "https://example.invalid/api/health",
            "--db-name", "app", "--db-user", "app",
        ]
        for migration in DEPLOY.REQUIRED_RELEASE_MIGRATIONS:
            arguments.extend(("--migration", migration))

        with (
            mock.patch.dict(
                os.environ,
                {"YY_SSH_PASSWORD": "secret-ssh", "YY_DB_PASSWORD": "secret-db"},
            ),
            mock.patch.dict("sys.modules", {"paramiko": fake_paramiko}),
            mock.patch("sys.argv", arguments),
            mock.patch.object(os.path, "isfile", return_value=True),
            mock.patch.object(DEPLOY, "sha256_file", return_value="c" * 64),
            mock.patch.object(
                DEPLOY, "validate_release_archive", return_value=("b" * 64, "a" * 40)
            ),
            mock.patch.object(DEPLOY, "run", side_effect=fake_run),
        ):
            with self.assertRaisesRegex(RuntimeError, "archive-sha256-check failed"):
                DEPLOY.main()

        self.assertEqual(
            labels,
            [
                "preflight", "deployment-archive-create", "archive-sha256-check",
                "deployment-archive-cleanup",
            ],
        )
        self.assertEqual(confirms, [False])
        self.assertEqual(channel.timeout, 300)
        self.assertTrue(sftp.closed)
        self.assertIn("c" * 64, commands["archive-sha256-check"])
        for forbidden in (
            "archive-check", "stage-files", "runtime-dependency-preflight",
            "backup-directory", "catalog-maintenance",
        ):
            self.assertNotIn(forbidden, labels)

    def test_dynamic_cleanup_fault_preserves_primary_and_attempts_both_targets(self) -> None:
        class FakeTransport:
            def is_active(self) -> bool:
                return True

            def set_keepalive(self, _interval: int) -> None:
                return None

            def get_remote_server_key(self) -> object:
                return SimpleNamespace(get_fingerprint=lambda: b"\x01\x02")

        for injected_cleanup_label in (
            "deployment-stage-cleanup",
            "deployment-archive-cleanup",
        ):
            with self.subTest(injected_cleanup_label=injected_cleanup_label):
                labels: list[str] = []
                client_closed: list[bool] = []

                class FakeClient:
                    def load_host_keys(self, _path: str) -> None:
                        return None

                    def set_missing_host_key_policy(self, _policy: object) -> None:
                        return None

                    def connect(self, *_args: object, **_kwargs: object) -> None:
                        return None

                    def get_transport(self) -> FakeTransport:
                        return FakeTransport()

                    def close(self) -> None:
                        client_closed.append(True)

                def fake_run(_client: object, _command: str, label: str) -> str:
                    labels.append(label)
                    if label == "runtime-dependency-preflight":
                        raise RuntimeError("primary-runtime-fault")
                    if label == injected_cleanup_label:
                        raise RuntimeError("injected-cleanup-fault")
                    return ""

                fake_paramiko = SimpleNamespace(
                    SSHClient=FakeClient,
                    RejectPolicy=lambda: object(),
                )
                arguments = [
                    "deploy-ssh.py", "--host", "example.invalid", "--user", "deploy",
                    "--known-hosts", "known-hosts", "--archive", "release.tar.gz",
                    "--remote-root", "/srv/yiyunying/backend",
                    "--release-version", "1.0.0", "--release-identity", "identity.json",
                    "--build-source-commit", "a" * 40,
                    "--maintenance-command", "maintenance-enter",
                    "--maintenance-release-command", "maintenance-exit",
                    "--health-url", "https://example.invalid/api/health",
                    "--db-name", "app", "--db-user", "app",
                ]
                for migration in DEPLOY.REQUIRED_RELEASE_MIGRATIONS:
                    arguments.extend(("--migration", migration))

                with (
                    mock.patch.dict(
                        os.environ,
                        {"YY_SSH_PASSWORD": "secret-ssh", "YY_DB_PASSWORD": "secret-db"},
                    ),
                    mock.patch.dict("sys.modules", {"paramiko": fake_paramiko}),
                    mock.patch("sys.argv", arguments),
                    mock.patch.object(os.path, "isfile", return_value=True),
                    mock.patch.object(DEPLOY, "sha256_file", return_value="c" * 64),
                    mock.patch.object(
                        DEPLOY,
                        "validate_release_archive",
                        return_value=("b" * 64, "a" * 40),
                    ),
                    mock.patch.object(DEPLOY, "run_sftp_operation", return_value=None),
                    mock.patch.object(DEPLOY, "run", side_effect=fake_run),
                ):
                    with self.assertRaisesRegex(
                        RuntimeError,
                        r"primary-runtime-fault; RECOVERY_REQUIRED: .*failed=",
                    ) as raised:
                        DEPLOY.main()

                self.assertIsInstance(raised.exception.__cause__, RuntimeError)
                self.assertIn("primary-runtime-fault", str(raised.exception.__cause__))
                self.assertIn("deployment-stage-cleanup", labels)
                self.assertIn("deployment-archive-cleanup", labels)
                self.assertLess(
                    labels.index("deployment-stage-cleanup"),
                    labels.index("deployment-archive-cleanup"),
                )
                self.assertEqual(client_closed, [True])
                self.assertNotIn("catalog-maintenance", labels)

    def test_sftp_failure_is_labeled_and_does_not_expose_exception_message(self) -> None:
        class FakeChannel:
            def settimeout(self, _timeout: int) -> None:
                return None

        class FakeSftp:
            closed = False

            def get_channel(self) -> FakeChannel:
                return FakeChannel()

            def close(self) -> None:
                self.closed = True

        sftp = FakeSftp()
        client = SimpleNamespace(open_sftp=lambda: sftp)

        def fail_with_sensitive_message(_sftp: object) -> None:
            raise RuntimeError("password=must-not-leak remote=/secret/path")

        with self.assertRaisesRegex(
            RuntimeError,
            r"archive-upload failed during transfer \(RuntimeError\)",
        ) as raised:
            DEPLOY.run_sftp_operation(client, "archive-upload", fail_with_sensitive_message)

        self.assertNotIn("must-not-leak", str(raised.exception))
        self.assertNotIn("/secret/path", str(raised.exception))
        self.assertTrue(sftp.closed)

        sftp.closed = False

        def fail_with_sensitive_timeout(_sftp: object) -> None:
            raise TimeoutError("token=timeout-secret")

        with self.assertRaisesRegex(
            RuntimeError,
            r"archive-upload timed out during transfer; sftp-timeout=300s",
        ) as timeout_raised:
            DEPLOY.run_sftp_operation(client, "archive-upload", fail_with_sensitive_timeout)

        self.assertNotIn("timeout-secret", str(timeout_raised.exception))
        self.assertTrue(sftp.closed)

    def test_mutable_backups_are_taken_only_after_maintenance_stops_writes(self) -> None:
        uploads = SOURCE.index('"public-uploads-backup"')
        database = SOURCE.index('"database-backup"')
        maintenance = SOURCE.index('"catalog-maintenance"')
        self.assertLess(maintenance, uploads)
        self.assertLess(maintenance, database)
        self.assertIn("public-uploads.tar.gz", SOURCE)
        self.assertIn('"uploads-rollback"', SOURCE)

    def test_destructive_paths_require_a_specific_absolute_remote_root(self) -> None:
        self.assertIn("args.remote_root = normalize_remote_root(args.remote_root)", SOURCE)
        self.assertIn('normalized == "/"', SOURCE)
        self.assertIn("len(components) < 2", SOURCE)
        self.assertNotIn("-exec rm -rf {} +", SOURCE)
        self.assertIn("rsync -a --delete", SOURCE)

    def test_rollback_only_restores_resources_that_may_have_changed(self) -> None:
        self.assertIn('label == "code-rollback" and not code_changed', SOURCE)
        self.assertIn('label == "uploads-rollback" and not uploads_changed', SOURCE)
        self.assertIn('label == "database-rollback" and not database_changed', SOURCE)
        self.assertIn("maintenance_attempted = True", SOURCE)
        self.assertIn('"health-check-after-maintenance-release"', SOURCE)

    def test_release_migrations_are_complete_and_ordered(self) -> None:
        expected = [
            "upgrade_20260811_content_moderation_closure.sql",
            "upgrade_20260811_short_video_controls.sql",
            "upgrade_20260811_resource_store_review_closure.sql",
            "upgrade_20260811_management_shell_restructure.sql",
            "upgrade_20260814_secure_mail_settings.sql",
        ]
        offsets = [SOURCE.index(name) for name in expected]
        self.assertEqual(offsets, sorted(offsets))
        self.assertIn("assert_required_release_migrations(args.migration)", SOURCE)
        self.assertIn("normalized.count(item) != 1", SOURCE)

    def test_catalog_gate_sequence_finishes_before_new_code(self) -> None:
        labels = [
            '"catalog-maintenance"',
            'f"database-migration-{index}"',
            '"catalog-gate-closed-readback"',
            '"deploy-files"',
            '"catalog-binding-dry-run"',
            '"catalog-public-quarantine-dry-run"',
            '"catalog-binding-apply"',
            '"catalog-binding-report-check"',
            '"catalog-public-quarantine-apply"',
            '"catalog-public-quarantine-report-check"',
            '"catalog-dry-run"',
            '"catalog-apply"',
            '"catalog-apply-report-check"',
            '"catalog-verify-activate"',
            '"catalog-gate-readback"',
            '"php-start-or-restart"',
            '"health-check"',
            '"catalog-maintenance-release"',
        ]
        offsets = [SOURCE.index(label) for label in labels]
        self.assertEqual(offsets, sorted(offsets))
        self.assertIn("--apply --maintenance-confirmed", SOURCE)
        self.assertIn("--activate --maintenance-confirmed", SOURCE)
        self.assertIn("'catalog-apply-report=passed'", SOURCE)
        self.assertIn("'residual_catalog_metadata_mismatches'", SOURCE)
        self.assertIn("catalog-gate=true", SOURCE)
        self.assertIn("'catalog-binding-report=passed'", SOURCE)
        self.assertIn("'catalog-public-quarantine-report=passed'", SOURCE)

    def test_catalog_tools_target_the_live_root_after_code_switch(self) -> None:
        self.assertIn('f"cd {quote(args.remote_root)}; " + catalog_php', SOURCE)
        self.assertNotIn('f"cd {quote(stage_dir)}; " + catalog_php', SOURCE)
        deploy = SOURCE.index('"deploy-files"')
        dry_run = SOURCE.index('"catalog-dry-run"')
        self.assertLess(deploy, dry_run)
        self.assertIn("catalog-gate=false", SOURCE)
        gate_closed = SOURCE.index('"catalog-gate-closed-readback"')
        migration = SOURCE.index('f"database-migration-{index}"')
        self.assertGreater(gate_closed, migration)
        self.assertLess(gate_closed, deploy)

    def test_catalog_gate_php_and_data_round_trip_as_separate_shell_arguments(self) -> None:
        php_bootstrap = DEPLOY.strict_php82_bootstrap()
        cases = (
            (("0", "false"), 29, "catalog-gate=false"),
            (("1", "true"), 30, "catalog-gate=true"),
        )
        for accepted_values, failure_status, success_message in cases:
            with self.subTest(success_message=success_message):
                command = DEPLOY.catalog_gate_readback_command(
                    "/srv/yiyunying backend",
                    php_bootstrap,
                    accepted_values,
                    failure_status,
                    success_message,
                )
                arguments = shlex.split(command, posix=True)
                php_index = arguments.index("-r")
                php_source = arguments[php_index + 1]
                php_arguments = arguments[php_index + 2 :]

                self.assertEqual(
                    php_arguments,
                    [
                        "catalog_private_migration_ready",
                        "bool",
                        *accepted_values,
                        str(failure_status),
                        success_message,
                    ],
                )
                self.assertNotIn("catalog_private_migration_ready", php_source)
                self.assertNotIn("'", php_source)
                self.assertIn("s.setting_key = ?", php_source)
                self.assertIn("s.value_type <> ?", php_source)
                self.assertIn("s.setting_value NOT IN (?, ?)", php_source)
                self.assertIn(
                    "[$argv[1], $argv[2], $argv[3], $argv[4]]",
                    php_source,
                )

        with self.assertRaisesRegex(ValueError, "exactly two"):
            DEPLOY.catalog_gate_readback_command(
                "/srv/yiyunying",
                php_bootstrap,
                ("true",),
                30,
                "catalog-gate=true",
            )

    def test_catalog_gate_generated_php_parses_with_local_php(self) -> None:
        php_executable = os.environ.get("PHP82_BIN") or shutil.which("php")
        if php_executable is None:
            self.skipTest("No local PHP executable; strict shell construction test still applies")

        command = DEPLOY.catalog_gate_readback_command(
            "/srv/yiyunying",
            "",
            ("0", "false"),
            29,
            "catalog-gate=false",
        )
        arguments = shlex.split(command, posix=True)
        php_source = arguments[arguments.index("-r") + 1]
        with tempfile.TemporaryDirectory() as temporary_directory:
            php_file = Path(temporary_directory) / "catalog-gate-readback.php"
            php_file.write_text("<?php\n" + php_source + "\n", encoding="utf-8")
            try:
                result = subprocess.run(
                    [php_executable, "-l", str(php_file)],
                    capture_output=True,
                    check=False,
                    text=True,
                )
            except OSError:
                self.skipTest("The discovered PHP launcher is not executable")
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)

    def test_maintenance_can_stop_php_fpm_before_a_real_restart(self) -> None:
        self.assertIn("it may stop PHP-FPM", SOURCE)
        self.assertIn('f"{quote(PHP_FPM82_INIT_SCRIPT)} restart', SOURCE)
        self.assertIn('f"|| {quote(PHP_FPM82_INIT_SCRIPT)} start', SOURCE)
        self.assertIn(
            'f"then systemctl restart {quote(PHP_FPM82_SYSTEMD_SERVICE)}', SOURCE
        )
        self.assertIn(
            'f"|| systemctl start {quote(PHP_FPM82_SYSTEMD_SERVICE)}', SOURCE
        )
        self.assertNotIn("php-fpm-82 reload", SOURCE)
        restart = SOURCE.index('"php-start-or-restart"')
        migration = SOURCE.index('f"database-migration-{index}"')
        release = SOURCE.index('"catalog-maintenance-release"')
        self.assertGreater(restart, migration)
        self.assertLess(restart, release)

    def test_failure_path_rolls_back_before_exiting_maintenance(self) -> None:
        rollback = SOURCE.index('"code-rollback"')
        release = SOURCE.index('"catalog-maintenance-release-after-rollback"')
        self.assertLess(rollback, release)
        self.assertIn('"database-rollback"', SOURCE)
        self.assertIn("rollback incomplete", SOURCE)


if __name__ == "__main__":
    unittest.main(verbosity=2)
