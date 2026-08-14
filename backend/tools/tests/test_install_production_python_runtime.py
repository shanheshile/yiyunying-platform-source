#!/usr/bin/env python3
"""Offline safety tests for install-production-python-runtime.py."""

from __future__ import annotations

from importlib.machinery import SourceFileLoader
import io
import json
import os
from pathlib import Path
import shutil
import stat
import struct
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest import mock


TOOLS = Path(__file__).resolve().parents[1]
MODULE_PATH = TOOLS / "install-production-python-runtime.py"
MODULE = SourceFileLoader("install_production_python_runtime", str(MODULE_PATH)).load_module()


def shell_function(script: str, name: str) -> str:
    start = script.index(f"{name}() {{")
    end = script.index("\n}\n", start) + 2
    return script[start:end]


def local_bash() -> str:
    candidates = [shutil.which("bash")]
    git = shutil.which("git")
    if git:
        git_root = Path(git).resolve().parent.parent
        candidates.extend((str(git_root / "bin" / "bash.exe"), str(git_root / "usr" / "bin" / "sh.exe")))
    candidates.append("/bin/bash")
    for candidate in candidates:
        if not candidate or not Path(candidate).is_file():
            continue
        result = subprocess.run(
            [candidate, "--version"], capture_output=True, text=True, timeout=10, check=False
        )
        if result.returncode == 0 and "GNU bash" in result.stdout:
            return candidate
    raise unittest.SkipTest("GNU Bash is unavailable for POSIX fault injection")


def bash_environment(bash: str) -> dict[str, str]:
    environment = os.environ.copy()
    environment["PATH"] = str(Path(bash).parent) + os.pathsep + environment.get("PATH", "")
    return environment


def run_bash(source: str, *arguments: str, timeout: int = 15) -> subprocess.CompletedProcess[str]:
    bash = local_bash()
    return subprocess.run(
        [bash, "--noprofile", "--norc", "-c", source, "runtime-test", *arguments],
        capture_output=True,
        env=bash_environment(bash),
        text=True,
        timeout=timeout,
        check=False,
    )


def member(
    relative: str,
    *,
    kind: str = "file",
    size: int = 1,
    mode: int = 0o664,
    link: str = "",
    digest: str = "a" * 64,
):
    return MODULE.ProjectedMember(
        MODULE.PROJECTION_PREFIX + relative,
        relative,
        kind,
        size if kind == "file" else 0,
        mode if kind == "file" else 0o777,
        link,
        digest if kind == "file" else "",
    )


def elf64(*, interpreter: bool = False, needed: bool = False) -> bytes:
    phnum = 1
    data_offset = 64 + 56 * phnum
    dynamic = b""
    p_type = 1
    if interpreter:
        p_type = 3
        dynamic = b"/lib/ld-musl-x86_64.so.1\0"
    elif needed:
        p_type = 2
        dynamic = struct.pack("<qQqQ", 1, 1, 0, 0)
    payload = bytearray(data_offset + len(dynamic))
    payload[:7] = b"\x7fELF\x02\x01\x01"
    struct.pack_into("<HHI", payload, 16, 2, 62, 1)
    struct.pack_into("<Q", payload, 32, 64)
    struct.pack_into("<HHH", payload, 52, 64, 56, phnum)
    struct.pack_into("<IIQQQQQQ", payload, 64, p_type, 5, data_offset, 0, 0, len(dynamic), len(dynamic), 8)
    payload[data_offset:] = dynamic
    return bytes(payload)


class ConstantsTest(unittest.TestCase):
    def test_frozen_artifact_and_target(self) -> None:
        self.assertEqual(35_579_339, MODULE.ARTIFACT_SIZE)
        self.assertEqual(
            "4f5ba66719827d2c97e6562987e8f1c79b2f2e2d661548b6fc2e02d04828a798",
            MODULE.ARTIFACT_SHA256,
        )
        self.assertEqual(
            "56ae61726d6f9e3620be87724d5b5fd8ec835b08761986b5fd46fa1d78c21c9c",
            MODULE.CONTENT_MANIFEST_SHA256,
        )
        self.assertEqual(52_390_506, MODULE.DERIVED_PAYLOAD_SIZE)
        self.assertEqual(
            "8c36fc15be9e1acbe2869342551470d200a6241aba23ef2bf8b1f7d976e05a89",
            MODULE.DERIVED_PAYLOAD_SHA256,
        )
        self.assertEqual("/opt/yiyunying/python-runtime/3.12.13-20260718", MODULE.TARGET_DIRECTORY)
        self.assertEqual("/usr/local/bin/python3", MODULE.STABLE_PATH)
        self.assertEqual(
            "/opt/yiyunying/python-runtime/.previous-target-3.12.13-20260718",
            MODULE.PREVIOUS_TARGET_RECEIPT,
        )

    def test_rejected_dynamic_artifact_is_not_a_constant(self) -> None:
        source = MODULE_PATH.read_text(encoding="utf-8")
        self.assertNotIn("d62168126b2d92e5db649cfe89fb13bf165654c027707c0ef80d7823757c9b1d", source)
        self.assertNotIn("install_only_stripped", source)


class ArchiveContractTest(unittest.TestCase):
    def test_member_name_rejects_traversal_and_other_top_level(self) -> None:
        traversal = tarfile.TarInfo("python/install/../escape")
        foreign = tarfile.TarInfo("other/file")
        with self.assertRaises(RuntimeError):
            MODULE.canonical_member_name(traversal)
        with self.assertRaises(RuntimeError):
            MODULE.canonical_member_name(foreign)

    def test_symbolic_link_must_remain_inside_python(self) -> None:
        self.assertEqual(
            "python/install/bin/python3.12",
            MODULE.normalized_link_target("python/install/bin/python3", "python3.12"),
        )
        for target in ("/etc/passwd", "../../../../etc/passwd", "..\\escape", ""):
            with self.subTest(target=target), self.assertRaises(RuntimeError):
                MODULE.normalized_link_target("python/install/bin/python3", target)

    def test_graph_rejects_broken_cycle_and_symlink_ancestor(self) -> None:
        good = [member("bin/python3.12"), member("bin/python3", kind="symlink", link="python3.12")]
        MODULE.validate_member_graph(
            {"python/install/bin/python3.12", "python/install/bin/python3"},
            {"python/install/bin/python3": "python3.12"},
            good,
        )
        with self.assertRaises(RuntimeError):
            MODULE.validate_member_graph(
                {"python/install/bin/python3"},
                {"python/install/bin/python3": "missing"},
                [member("bin/python3", kind="symlink", link="missing")],
            )
        with self.assertRaises(RuntimeError):
            MODULE.validate_member_graph(
                {"python/install/lib", "python/install/lib/file"},
                {"python/install/lib": "other"},
                [member("lib", kind="symlink", link="other"), member("lib/file")],
            )

    def test_manifest_sorting_is_bytewise_over_complete_records(self) -> None:
        values = [
            member("z", kind="symlink", link="a"),
            member("a", size=3, digest="b" * 64),
        ]
        lines = [
            b"L\0z\0a\n",
            b"F\0a\0" + b"3" + b"\0" + b"b" * 64 + b"\n",
        ]
        import hashlib

        expected = hashlib.sha256(b"".join(sorted(lines))).hexdigest()
        self.assertEqual(expected, MODULE.manifest_digest(values))

    def test_mode_normalization_removes_all_source_group_write(self) -> None:
        self.assertEqual(0o644, MODULE.normalized_mode(member("plain", mode=0o664)))
        self.assertEqual(0o755, MODULE.normalized_mode(member("exec", mode=0o775)))
        self.assertEqual(
            0o777,
            MODULE.normalized_mode(member("link", kind="symlink", link="plain")),
        )


class ElfContractTest(unittest.TestCase):
    def test_static_x86_64_et_exec_passes(self) -> None:
        result = MODULE.inspect_static_elf(elf64())
        self.assertEqual(2, result["type"])
        self.assertEqual(62, result["machine"])
        self.assertEqual(0, result["interpreter_segments"])
        self.assertEqual(0, result["dynamic_segments"])

    def test_interpreter_and_needed_dependencies_fail(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "not fully static"):
            MODULE.inspect_static_elf(elf64(interpreter=True))
        with self.assertRaisesRegex(RuntimeError, "not fully static"):
            MODULE.inspect_static_elf(elf64(needed=True))


class CommandSafetyTest(unittest.TestCase):
    def test_preflight_is_read_only_and_never_discovers_python_from_path(self) -> None:
        script = MODULE.preflight_script()
        self.assertNotIn("command -v python", script)
        self.assertNotIn("/www/", script)
        self.assertNotIn("panel", script.lower())
        self.assertNotIn("stt", script.lower())
        self.assertIn('"$TARGET/bin/python3"', script)
        self.assertIn("-I -S -B", script)
        for mutation in ("tar -x", "install -d", "mkdir --", "mv -T", "ln -s", "rm -rf"):
            self.assertNotIn(mutation, script)

    def test_execute_normalizes_then_audits_and_atomically_switches(self) -> None:
        script = MODULE.remote_install_script()
        self.assertIn("chmod 0755", script)
        self.assertIn("chmod 0644", script)
        self.assertIn("chown -h root:root", script)
        self.assertIn("mv -T --", script)
        self.assertIn("mv -Tf --", script)
        self.assertIn("rollback_stable", script)
        self.assertIn("set -o noclobber", script)
        self.assertIn("chmod 0400", script)
        self.assertIn(
            "PYTHON_RUNTIME_FAILURE_PHASE=%s;EXIT_CODE=%s", script
        )
        self.assertIn("exec >/dev/null 2>&1", script)
        self.assertNotIn("command -v python", script)
        self.assertNotIn("curl", script)
        self.assertNotIn("wget", script)

    def test_remote_validation_is_tmp_only_and_reuses_exact_validation_functions(self) -> None:
        execute = MODULE.remote_install_script()
        validate = MODULE.remote_validate_script()
        self.assertEqual(
            shell_function(execute, "audit_tree"),
            shell_function(validate, "audit_tree"),
        )
        self.assertEqual(
            shell_function(execute, "python_smoke"),
            shell_function(validate, "python_smoke"),
        )
        self.assertIn(
            '/tmp/.yiyunying-python-runtime-validate-$VERSION-$TOKEN', validate
        )
        self.assertIn('tar -xzf "$ARCHIVE" -C "$WORK"', validate)
        self.assertIn('cleanup_validate_work', validate)
        self.assertIn('python_smoke "$WORK/bin/python3" "$WORK"', validate)
        for forbidden in (
            "/opt/",
            "/usr/local",
            "install-lock",
            "previous-target",
            "STABLE=",
            "RECEIPT=",
            "mv -T",
            "ln -s",
        ):
            self.assertNotIn(forbidden, validate)

    def test_stage_namespace_and_marker_are_token_bound(self) -> None:
        path = "/tmp/.yiyunying-python-runtime-3.12.13-" + "a" * 32 + ".tar.gz"
        marker = MODULE.stage_marker(path)
        self.assertEqual(b"YY_PYTHON_STAGE_V1:" + b"a" * 32 + b"\n", marker)
        self.assertIn("set -euC", MODULE.create_stage_command(path))
        for bad in (
            "/tmp/python.tar.gz",
            "/tmp/.yiyunying-python-runtime-3.12.13-../../x.tar.gz",
            path + ".extra",
        ):
            with self.assertRaises(RuntimeError):
                MODULE.stage_marker(bad)

    def test_bash_command_clears_environment_and_pins_bash(self) -> None:
        command = MODULE.bash_command("printf pass")
        self.assertTrue(command.startswith("env -i PATH=/usr/bin:/bin LC_ALL=C LANG=C"))
        self.assertIn("/bin/bash --noprofile --norc", command)

    def test_find_producer_status_and_root_symlink_guards_are_explicit(self) -> None:
        for label, script in (
            ("preflight", MODULE.preflight_script()),
            ("execute", MODULE.remote_install_script()),
            ("remote-validate", MODULE.remote_validate_script()),
        ):
            with self.subTest(label=label):
                audit = shell_function(script, "audit_tree")
                self.assertNotIn('test -z "$(find', audit)
                self.assertNotIn("< <(find", audit)
                self.assertNotIn('test "$(' , script)
                self.assertEqual(5, audit.count('if ! found=$(find "$root"'))
                self.assertIn('if ! find "$root" -xdev -type l -exec', audit)
                if label != "remote-validate":
                    root_guard = shell_function(script, "validate_root_directory")
                    self.assertIn(
                        'if ! test -d "$path" || test -L "$path"; then return 1; fi',
                        root_guard,
                    )

    def test_posix_find_failure_blocks_preflight_and_execute_switch_or_delete(self) -> None:
        bash = local_bash()
        for label, script in (
            ("preflight", MODULE.preflight_script()),
            ("execute", MODULE.remote_install_script()),
        ):
            audit = shell_function(script, "audit_tree")
            for failure_position in range(1, 7):
                harness = r'''set -euo pipefail
FAIL_AT=$1
COUNT_FILE=$(mktemp)
ROOT=$(mktemp -d)
PRESERVED=$(mktemp)
SWITCHED="${COUNT_FILE}.switched"
cleanup() { rm -rf -- "$ROOT"; rm -f -- "$COUNT_FILE" "$PRESERVED" "$SWITCHED"; }
trap cleanup EXIT
printf '0\n' > "$COUNT_FILE"
find() {
  local count
  count=$(cat -- "$COUNT_FILE")
  count=$((count + 1))
  printf '%s\n' "$count" > "$COUNT_FILE"
  if test "$count" -eq "$FAIL_AT"; then return 97; fi
  return 0
}
stat() { printf '755|root|root\n'; }
''' + audit + r'''
if audit_tree "$ROOT"; then
  : > "$SWITCHED"
  rm -f -- "$PRESERVED"
fi
test ! -e "$SWITCHED"
test -f "$PRESERVED"
test "$(cat -- "$COUNT_FILE")" -eq "$FAIL_AT"
'''
                result = subprocess.run(
                    [bash, "--noprofile", "--norc", "-c", harness, "audit-find", str(failure_position)],
                    capture_output=True,
                    env=bash_environment(bash),
                    text=True,
                    timeout=15,
                    check=False,
                )
                self.assertEqual(
                    0,
                    result.returncode,
                    f"{label} find #{failure_position} did not fail closed: "
                    f"stdout={result.stdout!r} stderr={result.stderr!r}",
                )

    def test_posix_root_directory_symlink_is_rejected_before_stat(self) -> None:
        bash = local_bash()
        for label, script in (
            ("preflight", MODULE.preflight_script()),
            ("execute", MODULE.remote_install_script()),
        ):
            guard = shell_function(script, "validate_root_directory")
            harness = r'''set -euo pipefail
LINK=/simulated-root-directory-link
STAT_CALLED=$(mktemp)
rm -f -- "$STAT_CALLED"
cleanup() { rm -f -- "$STAT_CALLED"; }
trap cleanup EXIT
test() {
  if [ "$#" -eq 2 ] && [ "$2" = "$LINK" ] && { [ "$1" = -d ] || [ "$1" = -L ]; }; then return 0; fi
  builtin test "$@"
}
stat() { : > "$STAT_CALLED"; printf '0|0|755|directory\n'; }
''' + guard + r'''
if validate_root_directory "$LINK"; then exit 41; fi
builtin test ! -e "$STAT_CALLED"
'''
            result = subprocess.run(
                [bash, "--noprofile", "--norc", "-c", harness, "root-symlink"],
                capture_output=True,
                env=bash_environment(bash),
                text=True,
                timeout=15,
                check=False,
            )
            self.assertEqual(
                0,
                result.returncode,
                f"{label} accepted a symlink ancestor: "
                f"stdout={result.stdout!r} stderr={result.stderr!r}",
            )

    def test_generated_shell_is_valid_gnu_bash(self) -> None:
        bash = local_bash()
        stage = "/tmp/.yiyunying-python-runtime-3.12.13-" + "e" * 32 + ".tar.gz"
        payload = {"size": MODULE.DERIVED_PAYLOAD_SIZE, "sha256": MODULE.DERIVED_PAYLOAD_SHA256}
        for label, script in (
            ("preflight", MODULE.preflight_script()),
            ("execute", MODULE.remote_install_script()),
            ("remote-validate", MODULE.remote_validate_script()),
            ("create-stage", MODULE.create_stage_command(stage)),
            (
                "cleanup-confirmed",
                MODULE.cleanup_stage_command(stage, ownership_confirmed=True),
            ),
        ):
            result = subprocess.run(
                [bash, "--noprofile", "--norc", "-n"],
                capture_output=True,
                env=bash_environment(bash),
                input=script,
                text=True,
                timeout=15,
                check=False,
            )
            self.assertEqual(
                0,
                result.returncode,
                f"{label} Bash syntax failed: {result.stderr!r}",
            )

    def test_posix_every_remote_phase_emits_one_strict_diagnostic_line(self) -> None:
        execute = MODULE.remote_install_script()
        execute_on_error = shell_function(execute, "on_error")
        install_harness = r'''set -Eeuo pipefail
exec 4>&2
PHASE="$1"
SWITCHED=0
LINK_STAGE_HELD=0
ROLLBACK_LINK_HELD=0
LINK_STAGE=/link-stage
TARGET=/target
ROLLBACK_LINK=/rollback-link
PREVIOUS_TARGET=/previous
WORK=/work
WORK_HELD=0
LOCK=/lock
rollback_stable() { return 0; }
cleanup_owned_link() { return 0; }
cleanup_work() { return 0; }
release_lock() { return 0; }
''' + execute_on_error + r'''
trap on_error ERR INT TERM HUP
false
'''
        for phase in sorted(MODULE.REMOTE_FAILURE_PHASES):
            result = run_bash(install_harness, phase)
            self.assertEqual(1, result.returncode, phase)
            self.assertEqual(
                f"PYTHON_RUNTIME_FAILURE_PHASE={phase};EXIT_CODE=1\n",
                result.stderr,
                phase,
            )
            self.assertEqual("", result.stdout, phase)

        validate = MODULE.remote_validate_script()
        validate_on_error = shell_function(validate, "on_error")
        validation_phases = (
            "archive",
            "extract",
            "normalize",
            "tree-audit",
            "python-smoke",
            "cleanup",
        )
        validate_harness = r'''set -Eeuo pipefail
exec 4>&2
PHASE="$1"
cleanup_validate_work() { return 0; }
''' + validate_on_error + r'''
trap on_error ERR INT TERM HUP
false
'''
        for phase in validation_phases:
            result = run_bash(validate_harness, phase)
            self.assertEqual(1, result.returncode, phase)
            self.assertEqual(
                f"PYTHON_RUNTIME_FAILURE_PHASE={phase};EXIT_CODE=1\n",
                result.stderr,
                phase,
            )

    def test_posix_int_and_term_use_the_same_single_line_protocol(self) -> None:
        on_error = shell_function(MODULE.remote_validate_script(), "on_error")
        harness = r'''set -Eeuo pipefail
exec 4>&2
PHASE=python-smoke
cleanup_validate_work() { return 0; }
''' + on_error + r'''
trap on_error ERR INT TERM HUP
kill -s "$1" "$$"
'''
        for signal in ("INT", "TERM"):
            result = run_bash(harness, signal)
            self.assertEqual(130, result.returncode, signal)
            self.assertEqual(
                "PYTHON_RUNTIME_FAILURE_PHASE=python-smoke;EXIT_CODE=130\n",
                result.stderr,
                signal,
            )

    def test_posix_validation_cleanup_failure_is_the_only_reported_phase(self) -> None:
        on_error = shell_function(MODULE.remote_validate_script(), "on_error")
        harness = r'''set -Eeuo pipefail
exec 4>&2
PHASE=python-smoke
cleanup_validate_work() { return 97; }
''' + on_error + r'''
trap on_error ERR INT TERM HUP
false
'''
        result = run_bash(harness)
        self.assertEqual(93, result.returncode)
        self.assertEqual(
            "PYTHON_RUNTIME_FAILURE_PHASE=cleanup;EXIT_CODE=93\n", result.stderr
        )

    def test_posix_work_cleanup_requires_this_process_creation_bit(self) -> None:
        for label, script, function_name in (
            ("execute", MODULE.remote_install_script(), "cleanup_work"),
            ("remote-validate", MODULE.remote_validate_script(), "cleanup_validate_work"),
        ):
            cleanup_function = shell_function(script, function_name)
            harness = r'''set -euo pipefail
WORK=/preexisting/correct-looking-work
WORK_HELD=0
CALLED=$(mktemp)
command rm -f -- "$CALLED"
cleanup() { command rm -f -- "$CALLED"; }
trap cleanup EXIT
stat() { : > "$CALLED"; return 0; }
rm() { : > "$CALLED"; return 0; }
''' + cleanup_function + r'''
''' + function_name + r'''
test ! -e "$CALLED"
'''
            result = run_bash(harness)
            self.assertEqual(0, result.returncode, f"{label}: {result.stderr}")

    def test_posix_pipeline_producers_cannot_supply_value_then_fail(self) -> None:
        execute = MODULE.remote_install_script()
        archive_hash_line = next(
            line for line in execute.splitlines() if line.startswith("if ! archive_hash=")
        )
        on_error = shell_function(execute, "on_error")
        hash_harness = r'''set -Eeuo pipefail
exec 4>&2
PHASE=archive
PAYLOAD_SHA=''' + MODULE.DERIVED_PAYLOAD_SHA256 + r'''
ARCHIVE=/ignored
fail() { return "$1"; }
SWITCHED=0
LINK_STAGE_HELD=0
ROLLBACK_LINK_HELD=0
LINK_STAGE=/link-stage
TARGET=/target
ROLLBACK_LINK=/rollback-link
PREVIOUS_TARGET=/previous
WORK=/work
WORK_HELD=0
LOCK=/lock
rollback_stable() { return 0; }
cleanup_owned_link() { return 0; }
cleanup_work() { return 0; }
release_lock() { return 0; }
sha256sum() { printf '%s  %s\n' "$PAYLOAD_SHA" "$ARCHIVE"; return 97; }
''' + on_error + r'''
trap on_error ERR INT TERM HUP
''' + archive_hash_line + "\nexit 0\n"
        result = run_bash(hash_harness)
        self.assertEqual(24, result.returncode, result.stderr)
        self.assertEqual(
            "PYTHON_RUNTIME_FAILURE_PHASE=archive;EXIT_CODE=24\n", result.stderr
        )

        preflight = MODULE.preflight_script()
        free_line = next(line for line in preflight.splitlines() if line.startswith("if ! free="))
        free_harness = r'''set -euo pipefail
ancestor=/ignored
df() { printf 'Filesystem 1-blocks Used Available Capacity Mounted\nmock 2 1 1 50%% /\n'; return 97; }
''' + free_line + "\nexit 0\n"
        result = run_bash(free_harness)
        self.assertEqual(1, result.returncode, result.stderr)

    def test_posix_stage_producer_failures_never_delete(self) -> None:
        create_stage = "/tmp/.yiyunying-python-runtime-3.12.13-" + "c" * 32 + ".tar.gz"
        create_command = MODULE.create_stage_command(create_stage)
        create_harness = r'''set -euo pipefail
STAGE=''' + create_stage + r'''
if [ -e "$STAGE" ] || [ -L "$STAGE" ]; then exit 70; fi
cleanup() { command rm -f -- "$STAGE"; }
trap cleanup EXIT
chown() { return 0; }
chmod() { return 0; }
stat() { printf '600|root|root|''' + str(len(MODULE.stage_marker(create_stage))) + r'''\n'; return 97; }
set +e
( ''' + create_command + r''' )
status=$?
set -e
test "$status" -eq 3
test -f "$STAGE"
'''
        result = run_bash(create_harness)
        self.assertEqual(0, result.returncode, result.stderr)

        stat_stage = "/tmp/.yiyunying-python-runtime-3.12.13-" + "d" * 32 + ".tar.gz"
        stat_command = MODULE.cleanup_stage_command(
            stat_stage, ownership_confirmed=True
        )
        stat_harness = r'''set -euo pipefail
STAGE=''' + stat_stage + r'''
if [ -e "$STAGE" ] || [ -L "$STAGE" ]; then exit 70; fi
printf 'fixture' > "$STAGE"
ERROR_FILE=$(mktemp)
cleanup() { command rm -f -- "$STAGE" "$ERROR_FILE"; }
trap cleanup EXIT
stat() { printf '600|root|root\n'; return 97; }
set +e
( ''' + stat_command + r''' ) 2> "$ERROR_FILE"
status=$?
set -e
test "$status" -ne 0
test -f "$STAGE"
grep -q RECOVERY_REQUIRED "$ERROR_FILE"
'''
        result = run_bash(stat_harness)
        self.assertEqual(0, result.returncode, result.stderr)

        with self.assertRaisesRegex(RuntimeError, "confirmed creation ownership"):
            MODULE.cleanup_stage_command(stat_stage, ownership_confirmed=False)

        readback_harness = r'''set -euo pipefail
STAGE=''' + stat_stage + r'''
if [ -e "$STAGE" ] || [ -L "$STAGE" ]; then exit 70; fi
printf 'fixture' > "$STAGE"
ERROR_FILE=$(mktemp)
cleanup() { command rm -f -- "$STAGE" "$ERROR_FILE"; }
trap cleanup EXIT
stat() { printf '600|root|root\n'; }
rm() { return 0; }
set +e
( ''' + stat_command + r''' ) 2> "$ERROR_FILE"
status=$?
set -e
test "$status" -ne 0
test -f "$STAGE"
grep -q RECOVERY_REQUIRED "$ERROR_FILE"
'''
        result = run_bash(readback_harness)
        self.assertEqual(0, result.returncode, result.stderr)

    def test_posix_conditional_cleanup_and_rollback_fail_closed(self) -> None:
        script = MODULE.remote_install_script()
        cleanup_link = shell_function(script, "cleanup_owned_link")
        cleanup_work = shell_function(script, "cleanup_work")
        release_lock = shell_function(script, "release_lock")
        rollback = shell_function(script, "rollback_stable")
        harness = r'''set -euo pipefail
REMOVED=$(mktemp); command rm -f -- "$REMOVED"
REGULAR=$(mktemp)
cleanup() { command rm -rf -- "$REGULAR" "$WORK" "$LOCK" "$REMOVED"; }
trap cleanup EXIT
rm() { : > "$REMOVED"; return 0; }
''' + cleanup_link + r'''
if cleanup_owned_link "$REGULAR" /expected; then exit 51; fi
test -f "$REGULAR" && test ! -e "$REMOVED"
RUNTIME_ROOT=$(mktemp -d)
VERSION_DIR=v
TOKEN=t
WORK="$RUNTIME_ROOT/.stage-$VERSION_DIR-$TOKEN"
WORK_HELD=1
command mkdir -p -- "$WORK"
stat() { printf 'nobody|root\n'; }
''' + cleanup_work + r'''
if cleanup_work; then exit 52; fi
test -d "$WORK" && test ! -e "$REMOVED"
LOCK=$(mktemp -d)
LOCK_HELD=1
stat() { printf '755|root|root\n'; }
''' + release_lock + r'''
if release_lock; then exit 53; fi
test -d "$LOCK" && test "$LOCK_HELD" -eq 1
PREVIOUS_KIND=link
PREVIOUS_TARGET=/invalid/previous/bin/python3
ROLLBACK_LINK=/simulated/rollback
STABLE=/simulated/stable
TARGET=/simulated/target
SWITCHED=1
test() {
  if [ "$#" -eq 2 ] && [ "$1" = -L ] && [ "$2" = "$STABLE" ]; then return 0; fi
  builtin test "$@"
}
stat() { printf 'root|root|symbolic link\n'; }
readlink() { printf '%s\n' "$TARGET/bin/python3"; }
validate_stable_target() { return 97; }
ln() { : > "$REMOVED"; return 0; }
chown() { : > "$REMOVED"; return 0; }
mv() { : > "$REMOVED"; return 0; }
python_smoke() { : > "$REMOVED"; return 0; }
''' + rollback + r'''
if rollback_stable; then exit 54; fi
test "$SWITCHED" -eq 1 && test ! -e "$REMOVED"
'''
        result = run_bash(harness)
        self.assertEqual(0, result.returncode, result.stderr)

        on_error = shell_function(script, "on_error")
        recovery_harness = r'''set +e
rollback_stable() { return 97; }
cleanup_owned_link() { return 0; }
cleanup_work() { return 0; }
release_lock() { return 0; }
LINK_STAGE=/link-stage
TARGET=/target
ROLLBACK_LINK=/rollback-link
PREVIOUS_TARGET=/previous
WORK=/work
WORK_HELD=0
LOCK=/lock
LINK_STAGE_HELD=0
ROLLBACK_LINK_HELD=0
''' + on_error + r'''
false
exec 4>&2
on_error
'''
        result = run_bash(recovery_harness)
        self.assertEqual(90, result.returncode)
        self.assertEqual(
            "PYTHON_RUNTIME_FAILURE_PHASE=cleanup;EXIT_CODE=90\n", result.stderr
        )

    def test_posix_lock_and_post_switch_failures_enter_error_handler(self) -> None:
        script = MODULE.remote_install_script()
        release_lock = shell_function(script, "release_lock")
        on_error = shell_function(script, "on_error")
        lock_start = script.index('(umask 077; mkdir -- "$LOCK")')
        lock_end = script.index('\n\nif [ -e "$STABLE"', lock_start)
        lock_steps = script[lock_start:lock_end]
        self.assertLess(lock_steps.index("LOCK_HELD=1"), lock_steps.index("chown root:root"))
        lock_harness = r'''set -Eeuo pipefail
LOCK=/simulated/lock
LOCK_HELD=0
WORK_HELD=0
LINK_STAGE_HELD=0
ROLLBACK_LINK_HELD=0
LINK_STAGE=/link-stage
TARGET=/target
ROLLBACK_LINK=/rollback-link
PREVIOUS_TARGET=/previous
WORK=/work
rollback_stable() { return 0; }
cleanup_owned_link() { return 0; }
cleanup_work() { return 0; }
test() {
  if [ "$#" -eq 2 ] && [ "$2" = "$LOCK" ] && [ "$1" = -d ]; then return 0; fi
  if [ "$#" -eq 2 ] && [ "$2" = "$LOCK" ] && [ "$1" = -L ]; then return 1; fi
  builtin test "$@"
}
stat() { printf '700|root|root\n'; }
rmdir() { printf 'LOCK_RELEASED\n' >&2; return 0; }
''' + release_lock + "\n" + on_error + r'''
exec 4>&2
PHASE=lock
trap on_error ERR INT TERM HUP
mkdir() { return 0; }
chown() { return 97; }
chmod() { return 0; }
''' + lock_steps + "\n"
        result = run_bash(lock_harness)
        self.assertEqual(97, result.returncode)
        self.assertIn("LOCK_RELEASED", result.stderr)

        post_start = script.index('if ! test -L "$STABLE"; then fail 32; fi')
        post_end = script.index('\naudit_tree "$TARGET"', post_start)
        post_switch_checks = script[post_start:post_end]
        self.assertNotIn("exit 32", post_switch_checks)
        post_harness = r'''set -Eeuo pipefail
STABLE=/simulated/stable
TARGET=/simulated/target
LINK_STAGE=/link-stage
ROLLBACK_LINK=/rollback-link
PREVIOUS_TARGET=/previous
WORK=/work
WORK_HELD=0
LOCK=/lock
LINK_STAGE_HELD=0
ROLLBACK_LINK_HELD=0
fail() { return "$1"; }
rollback_stable() { printf 'ROLLBACK_CALLED\n' >&2; return 0; }
cleanup_owned_link() { return 0; }
cleanup_work() { return 0; }
release_lock() { printf 'LOCK_RELEASED\n' >&2; return 0; }
test() {
  if [ "$#" -eq 2 ] && [ "$1" = -L ] && [ "$2" = "$STABLE" ]; then return 0; fi
  builtin test "$@"
}
readlink() { printf '/wrong/current/target\n'; }
''' + on_error + r'''
exec 4>&2
PHASE=stable-switch
trap on_error ERR INT TERM HUP
''' + post_switch_checks + "\n"
        result = run_bash(post_harness)
        self.assertEqual(32, result.returncode)
        self.assertIn("ROLLBACK_CALLED", result.stderr)
        self.assertIn("LOCK_RELEASED", result.stderr)


class ReceiptTest(unittest.TestCase):
    def valid(self) -> dict[str, object]:
        return {
            "PYTHON_RUNTIME_INSTALL": "pass",
            "artifact_sha256": MODULE.ARTIFACT_SHA256,
            "payload_sha256": "c" * 64,
            "platform": "linux/amd64",
            "previous": "missing",
            "repeat": False,
            "rollback_receipt": MODULE.PREVIOUS_TARGET_RECEIPT,
            "stable": MODULE.STABLE_PATH,
            "switched": True,
            "target": MODULE.TARGET_DIRECTORY,
            "version": MODULE.VERSION,
        }

    def test_exact_receipt_passes(self) -> None:
        receipt = MODULE.parse_install_receipt(
            json.dumps(self.valid(), separators=(",", ":")), "c" * 64
        )
        self.assertTrue(receipt["switched"])

    def test_duplicate_extra_wrong_hash_and_multiple_lines_fail(self) -> None:
        duplicate = (
            '{"PYTHON_RUNTIME_INSTALL":"pass","PYTHON_RUNTIME_INSTALL":"pass"}'
        )
        with self.assertRaises(RuntimeError):
            MODULE.parse_install_receipt(duplicate, "c" * 64)
        extra = self.valid() | {"extra": True}
        with self.assertRaises(RuntimeError):
            MODULE.parse_install_receipt(json.dumps(extra), "c" * 64)
        wrong = self.valid() | {"payload_sha256": "d" * 64}
        with self.assertRaises(RuntimeError):
            MODULE.parse_install_receipt(json.dumps(wrong), "c" * 64)
        with self.assertRaises(RuntimeError):
            MODULE.parse_install_receipt(json.dumps(self.valid()) + "\n{}", "c" * 64)
        for whitespace_polluted in (
            json.dumps(self.valid()) + "\n\n",
            json.dumps(self.valid()) + "\n \n",
            " " + json.dumps(self.valid()),
            json.dumps(self.valid()) + " ",
        ):
            with self.assertRaises(RuntimeError):
                MODULE.parse_install_receipt(whitespace_polluted, "c" * 64)

    def test_remote_validation_receipt_is_exact(self) -> None:
        valid = {
            "PYTHON_RUNTIME_REMOTE_VALIDATE": "pass",
            "payload_sha256": "c" * 64,
            "platform": "linux/amd64",
            "version": MODULE.VERSION,
        }
        self.assertEqual(
            valid,
            MODULE.parse_remote_validate_receipt(
                json.dumps(valid, separators=(",", ":")), "c" * 64
            ),
        )
        for invalid in (
            valid | {"extra": True},
            valid | {"payload_sha256": "d" * 64},
            valid | {"PYTHON_RUNTIME_REMOTE_VALIDATE": "maybe"},
        ):
            with self.assertRaises(RuntimeError):
                MODULE.parse_remote_validate_receipt(json.dumps(invalid), "c" * 64)
        with self.assertRaises(RuntimeError):
            MODULE.parse_remote_validate_receipt(json.dumps(valid) + "\n{}", "c" * 64)


class RemoteFailureProtocolTest(unittest.TestCase):
    def test_allowlisted_phase_and_matching_exit_are_required(self) -> None:
        self.assertEqual(
            "python-smoke",
            MODULE.parse_remote_failure_diagnostic(
                "PYTHON_RUNTIME_FAILURE_PHASE=python-smoke;EXIT_CODE=17", 17
            ),
        )
        invalid = (
            ("PYTHON_RUNTIME_FAILURE_PHASE=unknown;EXIT_CODE=17", 17),
            ("PYTHON_RUNTIME_FAILURE_PHASE=python-smoke;EXIT_CODE=18", 17),
            ("PYTHON_RUNTIME_FAILURE_PHASE=python-smoke;EXIT_CODE=17\nextra", 17),
            ("free form stderr", 17),
            ("", 17),
        )
        for diagnostic, status in invalid:
            with self.subTest(diagnostic=diagnostic), self.assertRaises(RuntimeError):
                MODULE.parse_remote_failure_diagnostic(diagnostic, status)
        with self.assertRaisesRegex(RuntimeError, "unknown phase"):
            MODULE.parse_remote_failure_diagnostic(
                "PYTHON_RUNTIME_FAILURE_PHASE=stable-switch;EXIT_CODE=17",
                17,
                MODULE.REMOTE_VALIDATE_FAILURE_PHASES,
            )

    def test_phased_runner_never_echoes_remote_failure_text(self) -> None:
        client = mock.Mock()
        with mock.patch.object(
            MODULE,
            "collect_remote_result",
            return_value=(
                17,
                "",
                "PYTHON_RUNTIME_FAILURE_PHASE=python-smoke;EXIT_CODE=17",
            ),
        ):
            with self.assertRaises(MODULE.RemotePhaseFailure) as raised:
                MODULE.run_remote_phased(client, "ignored", "validation", "secret")
        self.assertEqual("python-smoke", raised.exception.phase)
        self.assertNotIn("secret", str(raised.exception))

        for result in (
            (17, "unexpected stdout", "PYTHON_RUNTIME_FAILURE_PHASE=archive;EXIT_CODE=17"),
            (17, "", "unreviewed/path: failure"),
            (0, "receipt", "unexpected stderr"),
        ):
            with mock.patch.object(MODULE, "collect_remote_result", return_value=result):
                with self.assertRaises(RuntimeError) as protocol_error:
                    MODULE.run_remote_phased(client, "ignored", "validation", "secret")
            self.assertNotIn("unreviewed/path", str(protocol_error.exception))

    def test_strict_silent_remote_call_rejects_any_stdout_or_stderr(self) -> None:
        client = mock.Mock()
        for result in ((0, " ", ""), (0, "", "\n"), (0, "output", "error")):
            with self.subTest(result=result), mock.patch.object(
                MODULE, "collect_remote_result", return_value=result
            ):
                with self.assertRaises(RuntimeError):
                    MODULE.run_remote(
                        client,
                        "ignored",
                        "strict cleanup",
                        "secret",
                        emit_output=False,
                        require_empty_stdout=True,
                        require_empty_stderr=True,
                    )


class MainBoundaryTest(unittest.TestCase):
    def arguments(self, *extra: str) -> list[str]:
        return [
            "--host",
            "example.invalid",
            "--known-hosts",
            "known_hosts",
            "--artifact",
            MODULE.ARTIFACT_NAME,
            *extra,
        ]

    def test_execute_requires_both_confirmations(self) -> None:
        with mock.patch.dict(os.environ, {"YY_SSH_PASSWORD": "pw"}, clear=True):
            with self.assertRaisesRegex(RuntimeError, "both reviewed confirmation"):
                MODULE.main(self.arguments("--execute"))

    def test_remote_validation_has_a_distinct_exact_confirmation(self) -> None:
        with mock.patch.dict(os.environ, {"YY_SSH_PASSWORD": "pw"}, clear=True):
            with self.assertRaisesRegex(RuntimeError, "exact validation confirmation"):
                MODULE.main(self.arguments("--remote-validate"))
            with self.assertRaisesRegex(RuntimeError, "exact validation confirmation"):
                MODULE.main(
                    self.arguments(
                        "--remote-validate",
                        "--confirm",
                        MODULE.EXECUTE_CONFIRMATION,
                    )
                )
            with self.assertRaisesRegex(RuntimeError, "exact validation confirmation"):
                MODULE.main(
                    self.arguments(
                        "--remote-validate",
                        "--confirm",
                        MODULE.REMOTE_VALIDATE_CONFIRMATION,
                        "--maintenance-confirmed",
                        MODULE.MAINTENANCE_CONFIRMATION,
                    )
                )

    def test_unconfirmed_stage_is_never_adopted_or_deleted_in_either_mode(self) -> None:
        artifact = MODULE.ArtifactInspection(
            str(MODULE_PATH),
            MODULE.ARTIFACT_SIZE,
            MODULE.ARTIFACT_SHA256,
            (1, 2, MODULE.ARTIFACT_SIZE, 3),
            tuple(),
            tuple(),
            MODULE.PYTHON_BINARY_SHA256,
        )
        payload = {
            "path": str(MODULE_PATH),
            "size": MODULE.DERIVED_PAYLOAD_SIZE,
            "sha256": MODULE.DERIVED_PAYLOAD_SHA256,
            "fingerprint": (1, 2, MODULE.DERIVED_PAYLOAD_SIZE, 3),
        }
        token = "e" * 32
        mode_arguments = (
            (
                "remote-validate",
                self.arguments(
                    "--remote-validate",
                    "--confirm",
                    MODULE.REMOTE_VALIDATE_CONFIRMATION,
                ),
            ),
            (
                "execute",
                self.arguments(
                    "--execute",
                    "--confirm",
                    MODULE.EXECUTE_CONFIRMATION,
                    "--maintenance-confirmed",
                    MODULE.MAINTENANCE_CONFIRMATION,
                ),
            ),
        )
        for mode, arguments in mode_arguments:
            for failure_type, failure_text in (
                (RuntimeError, "correct-looking preexisting stage collision"),
                (TimeoutError, "create response lost after remote mutation"),
            ):
                with self.subTest(mode=mode, failure=failure_type.__name__):
                    labels: list[str] = []
                    client = mock.Mock()

                    def remote_result(
                        _client, _command, label, _password, **_kwargs
                    ):
                        labels.append(label)
                        if label == "Python runtime preflight":
                            return 0, ""
                        if label == "Python payload stage creation":
                            raise failure_type(failure_text)
                        raise AssertionError(f"unexpected remote command: {label}")

                    stdout = io.StringIO()
                    with mock.patch.dict(
                        os.environ, {"YY_SSH_PASSWORD": "pw"}, clear=True
                    ), mock.patch.object(
                        MODULE, "inspect_artifact", return_value=artifact
                    ), mock.patch.object(
                        MODULE, "connect", return_value=client
                    ), mock.patch.object(
                        MODULE, "build_derived_payload", return_value=payload
                    ), mock.patch.object(
                        MODULE, "upload_payload"
                    ) as upload, mock.patch.object(
                        MODULE, "remove_derived_payload"
                    ), mock.patch.object(
                        MODULE.secrets, "token_hex", return_value=token
                    ), mock.patch.object(
                        MODULE, "run_remote", side_effect=remote_result
                    ), mock.patch.object(
                        MODULE, "run_remote_phased"
                    ) as phased, mock.patch.object(
                        MODULE, "cleanup_stage_command"
                    ) as cleanup_command, mock.patch.object(
                        MODULE.sys, "stdout", stdout
                    ):
                        with self.assertRaises(MODULE.RecoveryRequired) as raised:
                            MODULE.main(arguments)
                    message = str(raised.exception)
                    expected_stage = (
                        f"/tmp/.yiyunying-python-runtime-{MODULE.VERSION}-{token}.tar.gz"
                    )
                    self.assertIn(f'"remote_stage":"{expected_stage}"', message)
                    self.assertIn(f'"token":"{token}"', message)
                    self.assertIn(
                        '"stage_creation_ownership_unconfirmed"', message
                    )
                    self.assertNotIn(failure_text, message)
                    self.assertNotIn("RECEIPT=", stdout.getvalue())
                    self.assertEqual(
                        ["Python runtime preflight", "Python payload stage creation"],
                        labels,
                    )
                    cleanup_command.assert_not_called()
                    upload.assert_not_called()
                    phased.assert_not_called()
    def test_dry_run_rejects_execute_tokens(self) -> None:
        with mock.patch.dict(os.environ, {"YY_SSH_PASSWORD": "pw"}, clear=True):
            with self.assertRaisesRegex(RuntimeError, "explicit stateful mode"):
                MODULE.main(self.arguments("--confirm", MODULE.EXECUTE_CONFIRMATION))

    def test_dry_run_never_builds_or_uploads_payload(self) -> None:
        artifact = MODULE.ArtifactInspection(
            str(MODULE_PATH),
            MODULE.ARTIFACT_SIZE,
            MODULE.ARTIFACT_SHA256,
            (1, 2, MODULE.ARTIFACT_SIZE, 3),
            tuple(),
            tuple(),
            MODULE.PYTHON_BINARY_SHA256,
        )
        client = mock.Mock()
        with mock.patch.dict(os.environ, {"YY_SSH_PASSWORD": "pw"}, clear=True), mock.patch.object(
            MODULE, "inspect_artifact", return_value=artifact
        ), mock.patch.object(MODULE, "connect", return_value=client), mock.patch.object(
            MODULE, "run_remote", return_value=(0, "PYTHON_RUNTIME_PREFLIGHT=pass\n")
        ), mock.patch.object(MODULE, "build_derived_payload") as build, mock.patch.object(
            MODULE, "upload_payload"
        ) as upload:
            self.assertEqual(0, MODULE.main(self.arguments()))
        build.assert_not_called()
        upload.assert_not_called()
        client.close.assert_called_once()

    def test_local_payload_cleanup_is_identity_bound(self) -> None:
        handle, name = tempfile.mkstemp(prefix="yy-python-cleanup-")
        os.close(handle)
        path = Path(name)
        try:
            info = os.lstat(path)
            payload = {
                "path": str(path),
                "fingerprint": (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns),
            }
            MODULE.remove_derived_payload(payload)
            self.assertFalse(path.exists())
        finally:
            if path.exists():
                path.unlink()

    def test_remote_validation_builds_the_same_payload_but_never_installs(self) -> None:
        artifact = MODULE.ArtifactInspection(
            str(MODULE_PATH),
            MODULE.ARTIFACT_SIZE,
            MODULE.ARTIFACT_SHA256,
            (1, 2, MODULE.ARTIFACT_SIZE, 3),
            tuple(),
            tuple(),
            MODULE.PYTHON_BINARY_SHA256,
        )
        payload = {
            "path": str(MODULE_PATH),
            "size": MODULE.DERIVED_PAYLOAD_SIZE,
            "sha256": MODULE.DERIVED_PAYLOAD_SHA256,
            "fingerprint": (1, 2, MODULE.DERIVED_PAYLOAD_SIZE, 3),
        }
        token = "b" * 32
        receipt = json.dumps(
            {
                "PYTHON_RUNTIME_REMOTE_VALIDATE": "pass",
                "payload_sha256": MODULE.DERIVED_PAYLOAD_SHA256,
                "platform": "linux/amd64",
                "version": MODULE.VERSION,
            },
            separators=(",", ":"),
        )
        client = mock.Mock()
        arguments = self.arguments(
            "--remote-validate",
            "--confirm",
            MODULE.REMOTE_VALIDATE_CONFIRMATION,
        )
        with mock.patch.dict(
            os.environ, {"YY_SSH_PASSWORD": "pw"}, clear=True
        ), mock.patch.object(
            MODULE, "inspect_artifact", return_value=artifact
        ), mock.patch.object(
            MODULE, "connect", return_value=client
        ), mock.patch.object(
            MODULE, "build_derived_payload", return_value=payload
        ) as build, mock.patch.object(
            MODULE, "upload_payload"
        ) as upload, mock.patch.object(
            MODULE, "remove_derived_payload"
        ), mock.patch.object(
            MODULE.secrets, "token_hex", return_value=token
        ), mock.patch.object(
            MODULE, "run_remote", return_value=(0, "")
        ), mock.patch.object(
            MODULE, "run_remote_phased", return_value=receipt
        ) as phased, mock.patch.object(
            MODULE, "installer_command"
        ) as install_command:
            self.assertEqual(0, MODULE.main(arguments))
        build.assert_called_once_with(artifact)
        upload.assert_called_once()
        phased_command = phased.call_args.args[1]
        self.assertNotIn("/opt/", phased_command)
        self.assertNotIn("/usr/local", phased_command)
        install_command.assert_not_called()

    def test_pass_receipts_follow_stage_close_and_local_cleanup_in_both_modes(self) -> None:
        artifact = MODULE.ArtifactInspection(
            str(MODULE_PATH),
            MODULE.ARTIFACT_SIZE,
            MODULE.ARTIFACT_SHA256,
            (1, 2, MODULE.ARTIFACT_SIZE, 3),
            tuple(),
            tuple(),
            MODULE.PYTHON_BINARY_SHA256,
        )
        payload = {
            "path": str(MODULE_PATH),
            "size": MODULE.DERIVED_PAYLOAD_SIZE,
            "sha256": MODULE.DERIVED_PAYLOAD_SHA256,
            "fingerprint": (1, 2, MODULE.DERIVED_PAYLOAD_SIZE, 3),
        }
        validate_receipt = json.dumps(
            {
                "PYTHON_RUNTIME_REMOTE_VALIDATE": "pass",
                "payload_sha256": MODULE.DERIVED_PAYLOAD_SHA256,
                "platform": "linux/amd64",
                "version": MODULE.VERSION,
            },
            separators=(",", ":"),
        )
        install_receipt = json.dumps(
            {
                "PYTHON_RUNTIME_INSTALL": "pass",
                "artifact_sha256": MODULE.ARTIFACT_SHA256,
                "payload_sha256": MODULE.DERIVED_PAYLOAD_SHA256,
                "platform": "linux/amd64",
                "previous": "missing",
                "repeat": False,
                "rollback_receipt": MODULE.PREVIOUS_TARGET_RECEIPT,
                "stable": MODULE.STABLE_PATH,
                "switched": True,
                "target": MODULE.TARGET_DIRECTORY,
                "version": MODULE.VERSION,
            },
            separators=(",", ":"),
        )
        cases = (
            (
                "remote-validate",
                self.arguments(
                    "--remote-validate",
                    "--confirm",
                    MODULE.REMOTE_VALIDATE_CONFIRMATION,
                ),
                validate_receipt,
                "PYTHON_RUNTIME_REMOTE_VALIDATE_RECEIPT=",
            ),
            (
                "execute",
                self.arguments(
                    "--execute",
                    "--confirm",
                    MODULE.EXECUTE_CONFIRMATION,
                    "--maintenance-confirmed",
                    MODULE.MAINTENANCE_CONFIRMATION,
                ),
                install_receipt,
                "PYTHON_RUNTIME_RECEIPT=",
            ),
        )
        for mode, arguments, receipt, receipt_prefix in cases:
            with self.subTest(mode=mode):
                events: list[str] = []
                client = mock.Mock()
                client.close.side_effect = lambda: events.append("ssh-close")

                def remote_result(_client, _command, label, _password, **_kwargs):
                    if label == "Python payload stage cleanup":
                        events.append("remote-stage-cleanup")
                        self.assertFalse(_kwargs["emit_output"])
                        self.assertTrue(_kwargs["require_empty_stdout"])
                        self.assertTrue(_kwargs["require_empty_stderr"])
                    return 0, ""

                def remove_payload(_payload):
                    events.append("local-payload-cleanup")

                def record_print(*values, **_kwargs):
                    events.append("print:" + " ".join(str(value) for value in values))

                with mock.patch.dict(
                    os.environ, {"YY_SSH_PASSWORD": "pw"}, clear=True
                ), mock.patch.object(
                    MODULE, "inspect_artifact", return_value=artifact
                ), mock.patch.object(
                    MODULE, "connect", return_value=client
                ), mock.patch.object(
                    MODULE, "build_derived_payload", return_value=payload
                ), mock.patch.object(
                    MODULE, "upload_payload"
                ), mock.patch.object(
                    MODULE, "remove_derived_payload", side_effect=remove_payload
                ), mock.patch.object(
                    MODULE.secrets, "token_hex", return_value="f" * 32
                ), mock.patch.object(
                    MODULE, "run_remote", side_effect=remote_result
                ), mock.patch.object(
                    MODULE, "run_remote_phased", return_value=receipt
                ), mock.patch(
                    "builtins.print", side_effect=record_print
                ):
                    self.assertEqual(0, MODULE.main(arguments))
                receipt_event = next(
                    index
                    for index, event in enumerate(events)
                    if event.startswith("print:" + receipt_prefix)
                )
                for boundary in (
                    "remote-stage-cleanup",
                    "ssh-close",
                    "local-payload-cleanup",
                ):
                    self.assertLess(events.index(boundary), receipt_event, events)

    def test_remote_validation_phase_is_recovered_with_tmp_only_identifiers(self) -> None:
        artifact = MODULE.ArtifactInspection(
            str(MODULE_PATH),
            MODULE.ARTIFACT_SIZE,
            MODULE.ARTIFACT_SHA256,
            (1, 2, MODULE.ARTIFACT_SIZE, 3),
            tuple(),
            tuple(),
            MODULE.PYTHON_BINARY_SHA256,
        )
        payload = {
            "path": str(MODULE_PATH),
            "size": MODULE.DERIVED_PAYLOAD_SIZE,
            "sha256": MODULE.DERIVED_PAYLOAD_SHA256,
            "fingerprint": (1, 2, MODULE.DERIVED_PAYLOAD_SIZE, 3),
        }
        token = "c" * 32
        arguments = self.arguments(
            "--remote-validate",
            "--confirm",
            MODULE.REMOTE_VALIDATE_CONFIRMATION,
        )
        client = mock.Mock()
        with mock.patch.dict(
            os.environ, {"YY_SSH_PASSWORD": "pw"}, clear=True
        ), mock.patch.object(
            MODULE, "inspect_artifact", return_value=artifact
        ), mock.patch.object(
            MODULE, "connect", return_value=client
        ), mock.patch.object(
            MODULE, "build_derived_payload", return_value=payload
        ), mock.patch.object(
            MODULE, "upload_payload"
        ), mock.patch.object(
            MODULE, "remove_derived_payload"
        ), mock.patch.object(
            MODULE.secrets, "token_hex", return_value=token
        ), mock.patch.object(
            MODULE, "run_remote", return_value=(0, "")
        ), mock.patch.object(
            MODULE,
            "run_remote_phased",
            side_effect=MODULE.RemotePhaseFailure("python-smoke", 17),
        ):
            with self.assertRaises(RuntimeError) as raised:
                MODULE.main(arguments)
        message = str(raised.exception)
        self.assertIn('"failure_phase":"python-smoke"', message)
        self.assertIn(f'"token":"{token}"', message)
        self.assertIn(
            f'"work":"/tmp/.yiyunying-python-runtime-validate-{MODULE.VERSION}-{token}"',
            message,
        )
        self.assertNotIn("/opt/", message)
        self.assertNotIn("/usr/local", message)

    def test_remote_validation_stage_cleanup_uncertainty_overrides_success(self) -> None:
        artifact = MODULE.ArtifactInspection(
            str(MODULE_PATH),
            MODULE.ARTIFACT_SIZE,
            MODULE.ARTIFACT_SHA256,
            (1, 2, MODULE.ARTIFACT_SIZE, 3),
            tuple(),
            tuple(),
            MODULE.PYTHON_BINARY_SHA256,
        )
        payload = {
            "path": str(MODULE_PATH),
            "size": MODULE.DERIVED_PAYLOAD_SIZE,
            "sha256": MODULE.DERIVED_PAYLOAD_SHA256,
            "fingerprint": (1, 2, MODULE.DERIVED_PAYLOAD_SIZE, 3),
        }
        receipt = json.dumps(
            {
                "PYTHON_RUNTIME_REMOTE_VALIDATE": "pass",
                "payload_sha256": MODULE.DERIVED_PAYLOAD_SHA256,
                "platform": "linux/amd64",
                "version": MODULE.VERSION,
            },
            separators=(",", ":"),
        )
        client = mock.Mock()
        cleanup_kwargs: dict[str, object] = {}

        def remote_result(_client, _command, label, _password, **_kwargs):
            if label == "Python payload stage cleanup":
                cleanup_kwargs.update(_kwargs)
                raise RuntimeError("untrusted remote cleanup detail")
            return 0, ""

        arguments = self.arguments(
            "--remote-validate",
            "--confirm",
            MODULE.REMOTE_VALIDATE_CONFIRMATION,
        )
        stdout = io.StringIO()
        with mock.patch.dict(
            os.environ, {"YY_SSH_PASSWORD": "pw"}, clear=True
        ), mock.patch.object(
            MODULE, "inspect_artifact", return_value=artifact
        ), mock.patch.object(
            MODULE, "connect", return_value=client
        ), mock.patch.object(
            MODULE, "build_derived_payload", return_value=payload
        ), mock.patch.object(
            MODULE, "upload_payload"
        ), mock.patch.object(
            MODULE, "remove_derived_payload"
        ), mock.patch.object(
            MODULE.secrets, "token_hex", return_value="d" * 32
        ), mock.patch.object(
            MODULE, "run_remote", side_effect=remote_result
        ), mock.patch.object(
            MODULE, "run_remote_phased", return_value=receipt
        ), mock.patch.object(
            MODULE.sys, "stdout", stdout
        ):
            with self.assertRaises(MODULE.RecoveryRequired) as raised:
                MODULE.main(arguments)
        message = str(raised.exception)
        self.assertIn('"remote_stage_cleanup_unconfirmed"', message)
        self.assertNotIn("untrusted remote cleanup detail", message)
        self.assertNotIn("PYTHON_RUNTIME_REMOTE_VALIDATE_RECEIPT=", stdout.getvalue())
        self.assertEqual(False, cleanup_kwargs.get("emit_output"))
        self.assertEqual(True, cleanup_kwargs.get("require_empty_stdout"))
        self.assertEqual(True, cleanup_kwargs.get("require_empty_stderr"))

    def test_primary_recovery_identifiers_survive_all_secondary_failures(self) -> None:
        artifact = MODULE.ArtifactInspection(
            str(MODULE_PATH),
            MODULE.ARTIFACT_SIZE,
            MODULE.ARTIFACT_SHA256,
            (1, 2, MODULE.ARTIFACT_SIZE, 3),
            tuple(),
            tuple(),
            MODULE.PYTHON_BINARY_SHA256,
        )
        payload = {
            "path": str(MODULE_PATH),
            "size": MODULE.DERIVED_PAYLOAD_SIZE,
            "sha256": MODULE.DERIVED_PAYLOAD_SHA256,
            "fingerprint": (1, 2, MODULE.DERIVED_PAYLOAD_SIZE, 3),
        }
        token = "9" * 32
        client = mock.Mock()
        client.close.side_effect = RuntimeError("sensitive ssh close detail")

        def remote_result(_client, _command, label, _password, **_kwargs):
            if label == "Python payload stage cleanup":
                raise RuntimeError("sensitive stage cleanup detail")
            return 0, ""

        arguments = self.arguments(
            "--execute",
            "--confirm",
            MODULE.EXECUTE_CONFIRMATION,
            "--maintenance-confirmed",
            MODULE.MAINTENANCE_CONFIRMATION,
        )
        stdout = io.StringIO()
        with mock.patch.dict(
            os.environ, {"YY_SSH_PASSWORD": "secret-password"}, clear=True
        ), mock.patch.object(
            MODULE, "inspect_artifact", return_value=artifact
        ), mock.patch.object(
            MODULE, "connect", return_value=client
        ), mock.patch.object(
            MODULE, "build_derived_payload", return_value=payload
        ), mock.patch.object(
            MODULE, "upload_payload"
        ), mock.patch.object(
            MODULE,
            "remove_derived_payload",
            side_effect=RuntimeError("sensitive local cleanup detail"),
        ), mock.patch.object(
            MODULE.secrets, "token_hex", return_value=token
        ), mock.patch.object(
            MODULE, "run_remote", side_effect=remote_result
        ), mock.patch.object(
            MODULE,
            "run_remote_phased",
            side_effect=MODULE.RemotePhaseFailure("python-smoke", 17),
        ), mock.patch.object(
            MODULE.sys, "stdout", stdout
        ):
            with self.assertRaises(MODULE.RecoveryRequired) as raised:
                MODULE.main(arguments)
        message = str(raised.exception)
        self.assertIn('"failure_phase":"python-smoke"', message)
        self.assertIn(f'"token":"{token}"', message)
        self.assertIn(
            f'"work":"{MODULE.RUNTIME_ROOT}/.stage-{MODULE.VERSION_DIRECTORY}-{token}"',
            message,
        )
        for path in (
            MODULE.LOCK_DIRECTORY,
            MODULE.TARGET_DIRECTORY,
            MODULE.STABLE_PATH,
            MODULE.PREVIOUS_TARGET_RECEIPT,
        ):
            self.assertIn(path, message)
        for uncertainty in (
            "remote_stage_cleanup_unconfirmed",
            "ssh_close_unconfirmed",
            "local_payload_cleanup_unconfirmed",
        ):
            self.assertIn(f'"{uncertainty}"', message)
        for secret in (
            "secret-password",
            "sensitive stage cleanup detail",
            "sensitive ssh close detail",
            "sensitive local cleanup detail",
        ):
            self.assertNotIn(secret, message)
        self.assertNotIn("PYTHON_RUNTIME_RECEIPT=", stdout.getvalue())

    def test_execute_cli_unifies_interrupt_and_system_exit_as_recovery(self) -> None:
        for failure in (KeyboardInterrupt(), SystemExit(9)):
            with self.subTest(failure=type(failure).__name__):
                stderr = io.StringIO()
                with mock.patch.object(MODULE, "main", side_effect=failure), mock.patch.object(
                    MODULE.sys, "stderr", stderr
                ):
                    self.assertEqual(1, MODULE.cli(["--execute"]))
                self.assertIn("RECOVERY_REQUIRED", stderr.getvalue())

    def test_uncertain_remote_install_reports_exact_nonsecret_recovery_paths(self) -> None:
        artifact = MODULE.ArtifactInspection(
            str(MODULE_PATH),
            MODULE.ARTIFACT_SIZE,
            MODULE.ARTIFACT_SHA256,
            (1, 2, MODULE.ARTIFACT_SIZE, 3),
            tuple(),
            tuple(),
            MODULE.PYTHON_BINARY_SHA256,
        )
        payload = {
            "path": str(MODULE_PATH),
            "size": MODULE.DERIVED_PAYLOAD_SIZE,
            "sha256": MODULE.DERIVED_PAYLOAD_SHA256,
            "fingerprint": (1, 2, MODULE.DERIVED_PAYLOAD_SIZE, 3),
        }
        token = "a" * 32
        arguments = self.arguments(
            "--execute",
            "--confirm",
            MODULE.EXECUTE_CONFIRMATION,
            "--maintenance-confirmed",
            MODULE.MAINTENANCE_CONFIRMATION,
        )
        for failure in (
            KeyboardInterrupt("sensitive-remote-output"),
            SystemExit("sensitive-remote-output"),
        ):
            with self.subTest(failure=type(failure).__name__):
                client = mock.Mock()

                with mock.patch.dict(
                    os.environ, {"YY_SSH_PASSWORD": "secret-password"}, clear=True
                ), mock.patch.object(
                    MODULE, "inspect_artifact", return_value=artifact
                ), mock.patch.object(
                    MODULE, "connect", return_value=client
                ), mock.patch.object(
                    MODULE, "build_derived_payload", return_value=payload
                ), mock.patch.object(
                    MODULE, "upload_payload"
                ), mock.patch.object(
                    MODULE, "remove_derived_payload"
                ), mock.patch.object(
                    MODULE.secrets, "token_hex", return_value=token
                ), mock.patch.object(
                    MODULE, "run_remote", return_value=(0, "")
                ), mock.patch.object(
                    MODULE, "run_remote_phased", side_effect=failure
                ):
                    with self.assertRaises(RuntimeError) as raised:
                        MODULE.main(arguments)
                message = str(raised.exception)
                self.assertIn("RECOVERY_REQUIRED", message)
                self.assertIn('"failure_phase":"unavailable"', message)
                self.assertIn(f'"token":"{token}"', message)
                self.assertIn(
                    f'"work":"{MODULE.RUNTIME_ROOT}/.stage-{MODULE.VERSION_DIRECTORY}-{token}"',
                    message,
                )
                for path in (
                    MODULE.LOCK_DIRECTORY,
                    MODULE.TARGET_DIRECTORY,
                    MODULE.STABLE_PATH,
                    MODULE.PREVIOUS_TARGET_RECEIPT,
                ):
                    self.assertIn(path, message)
                self.assertNotIn("sensitive-remote-output", message)
                self.assertNotIn("secret-password", message)


if __name__ == "__main__":
    unittest.main(verbosity=2)
