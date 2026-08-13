#!/usr/bin/env python3
"""Offline runtime contract tests for the Android device upgrade gate."""

from __future__ import annotations

import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import textwrap
import unittest


ROOT = Path(__file__).resolve().parents[3]
SCRIPT = ROOT / "android" / "tools" / "verify-device-upgrade.ps1"
PACKAGE = "xyz.jjmxg.yiyunying.user"
SERIAL = "EXAMPLE-DEVICE-01"


class DeviceUpgradeGateStaticContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.source = SCRIPT.read_text(encoding="utf-8")

    def test_powershell_ast_parses_without_errors(self) -> None:
        command = (
            "$errors=$null; "
            "[System.Management.Automation.Language.Parser]::ParseFile("
            "'" + str(SCRIPT).replace("'", "''") + "',[ref]$null,[ref]$errors) | Out-Null; "
            "if ($errors.Count -gt 0) { $errors | ForEach-Object { $_.ToString() }; exit 1 }"
        )
        completed = subprocess.run(
            ["powershell.exe", "-NoProfile", "-Command", command],
            text=True,
            encoding="utf-8",
            errors="replace",
            capture_output=True,
            timeout=30,
            check=False,
        )
        self.assertEqual(0, completed.returncode, completed.stdout + completed.stderr)

    def test_role_serial_and_both_apks_are_mandatory(self) -> None:
        for name in ("Role", "Serial", "RcApk", "StableApk"):
            pattern = rf"(?s)\[Parameter\(Mandatory\s*=\s*\$true\)\].{{0,220}}\[string\]\s*\${name}\b"
            self.assertRegex(self.source, pattern)
        self.assertIn("[ValidateSet('user', 'admin', 'authorized', 'owner')]", self.source)

    def test_all_four_role_identities_and_release_codes_are_frozen(self) -> None:
        expected = {
            "user": ("xyz.jjmxg.yiyunying.user", "user"),
            "admin": ("xyz.jjmxg.yiyunying.admin", "admin"),
            "authorized": ("xyz.jjmxg.yiyunying.authorized", "authorized-platform"),
            "owner": ("xyz.jjmxg.yiyunying.platformowner", "platform-owner"),
        }
        for role, (package, suffix) in expected.items():
            self.assertRegex(
                self.source,
                rf"{role}\s*=\s*@\{{\s*Package\s*=\s*'{re.escape(package)}';\s*Suffix\s*=\s*'{suffix}'\s*\}}",
            )
        for marker in ("-ExpectedCode 61", "-ExpectedCode 62", "versionCode=62"):
            self.assertIn(marker, self.source)

    def test_apk_checks_complete_before_any_device_install(self) -> None:
        main = self.source[self.source.index("# All local APK evidence") :]
        ordered = (
            "Read-ApkEvidence -Path $RcApk",
            "Read-ApkEvidence -Path $StableApk",
            "Assert-ApkEvidence -Evidence $rc",
            "Assert-ApkEvidence -Evidence $stable",
            "Assert-DeviceOnline",
            "Install-ApkUpgradeOnly -Path $RcApk",
        )
        positions = [main.index(marker) for marker in ordered]
        self.assertEqual(sorted(positions), positions)
        self.assertEqual(2, main.count("-RequireNotDebuggable"))
        for marker in (
            "stable_signer_sha256",
            "Verified using v2 scheme",
            "APK Signature Scheme v2",
            "V2Verified",
            "RC and Stable APK signers do not match",
            "must have exactly one signer",
        ):
            self.assertIn(marker, self.source)

    def test_runtime_hazards_are_explicitly_guarded(self) -> None:
        for marker in (
            "aapt2.exe",
            "$match.Groups['package']",
            "$match.Groups['code']",
            "$match.Groups['name']",
            "$packageMatch.Groups['package']",
            "m(?:CurrentFocus|FocusedApp)",
            "Authorization: Bearer <redacted>",
            "access[_-]?token",
            "refresh[_-]?token",
        ):
            self.assertIn(marker, self.source)

    def test_upgrade_never_downgrades_or_uninstalls_and_preserves_identity(self) -> None:
        self.assertIn("@('install', '-r', $Path)", self.source)
        self.assertNotRegex(self.source, r"@\('install'[^\n]*'-d'")
        self.assertNotRegex(self.source, r"Invoke-Adb\s+-Arguments\s+@\('uninstall'")
        for marker in (
            "$stableInstalled.UserId -ne $rcInstalled.UserId",
            "$stableInstalled.DataDir -ne $rcInstalled.DataDir",
            "launchVerifiedBeforeAndAfter = $true",
            "@('-s', $Serial)",
            "matches.Count -ne 1",
        ):
            self.assertIn(marker, self.source)


class DeviceUpgradeGateRuntimeContractTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="device-upgrade-gate-")
        self.root = Path(self.temp.name)
        self.rc_apk = self.root / "user-code61-rc.apk"
        self.stable_apk = self.root / "user-code62-stable.apk"
        self.rc_apk.write_bytes(b"R" * (1024 * 1024 + 1))
        self.stable_apk.write_bytes(b"S" * (1024 * 1024 + 1))
        self.state = self.root / "state.txt"
        self.log = self.root / "calls.jsonl"
        self.fake = self.root / "fake_tool.py"
        self.fake.write_text(self._fake_tool_source(), encoding="utf-8")
        self.aapt2 = self._wrapper("aapt2")
        self.apksigner = self._wrapper("apksigner")
        self.adb = self._wrapper("adb")

    def tearDown(self) -> None:
        self.temp.cleanup()

    def _wrapper(self, tool: str) -> Path:
        path = self.root / f"{tool}.cmd"
        path.write_text(
            f'@echo off\r\n"{sys.executable}" "{self.fake}" {tool} %*\r\nexit /b %errorlevel%\r\n',
            encoding="utf-8",
        )
        return path

    @staticmethod
    def _fake_tool_source() -> str:
        return textwrap.dedent(
            r'''
            import json
            import os
            from pathlib import Path
            import sys

            tool, *args = sys.argv[1:]
            log = Path(os.environ["FAKE_TOOL_LOG"])
            with log.open("a", encoding="utf-8") as stream:
                stream.write(json.dumps({"tool": tool, "args": args}) + "\n")

            package = "xyz.jjmxg.yiyunying.user"
            version_name = "2.8.0-user"
            signer = os.environ["FAKE_SIGNER"]

            if tool == "aapt2":
                if os.environ.get("FAKE_AAPT_FAIL") == "1":
                    print("Authorization: Bearer EXAMPLE_SENSITIVE_VALUE token=EXAMPLE_SECOND_VALUE")
                    raise SystemExit(9)
                code = 61 if "code61" in args[-1].lower() else 62
                print(f"package: name='{package}' versionCode='{code}' versionName='{version_name}'")
                raise SystemExit(0)

            if tool == "apksigner":
                print("Verifies")
                print("Verified using v2 scheme (APK Signature Scheme v2): true")
                print(f"Signer #1 certificate SHA-256 digest: {signer}")
                raise SystemExit(0)

            if tool != "adb":
                raise SystemExit(90)
            if args == ["devices"]:
                state = os.environ.get("FAKE_DEVICE_STATE", "device")
                print("List of devices attached")
                print(f"EXAMPLE-DEVICE-01\t{state}")
                raise SystemExit(0)
            if len(args) < 3 or args[0:2] != ["-s", "EXAMPLE-DEVICE-01"]:
                raise SystemExit(91)
            command = args[2:]
            state_file = Path(os.environ["FAKE_ADB_STATE"])
            if command == ["get-state"]:
                print(os.environ.get("FAKE_DEVICE_STATE", "device"))
            elif command[0:2] == ["install", "-r"]:
                code = "61" if "code61" in command[-1].lower() else "62"
                state_file.write_text(code, encoding="ascii")
                print("Success")
            elif command[0:3] == ["shell", "dumpsys", "package"]:
                code = state_file.read_text(encoding="ascii")
                print(f"  Package [{package}] (abc):")
                print(f"    versionCode={code} minSdk=24 targetSdk=35")
                print(f"    versionName={version_name}")
                print("    userId=10234")
                print(f"    dataDir=/data/user/0/{package}")
            elif command[0:4] == ["shell", "am", "start", "-W"]:
                print("Status: ok")
            elif command[0:3] == ["shell", "pidof", package]:
                print("1234")
            elif command[0:4] == ["shell", "dumpsys", "window", "windows"]:
                print(f"Window #0 Window{{deadbeef u0 {package}/{package}.BackgroundActivity}}")
                if os.environ.get("FAKE_FOREGROUND", "1") == "1":
                    print(f"mCurrentFocus=Window{{abc u0 {package}/{package}.launcher.DefaultLauncher}}")
                else:
                    print("mCurrentFocus=Window{abc u0 com.android.launcher/.Launcher}")
            elif command[0:3] == ["shell", "am", "force-stop"]:
                pass
            elif command[0:2] == ["shell", "monkey"]:
                print("Events injected: 1")
            else:
                print("unexpected adb arguments", command)
                raise SystemExit(92)
            raise SystemExit(0)
            '''
        ).lstrip()

    def _run(self, **overrides: str) -> subprocess.CompletedProcess[str]:
        identity = json.loads((ROOT / "backend" / "config" / "release-identity.json").read_text(encoding="utf-8"))
        env = os.environ.copy()
        env.update(
            {
                "FAKE_TOOL_LOG": str(self.log),
                "FAKE_ADB_STATE": str(self.state),
                "FAKE_SIGNER": identity["stable_signer_sha256"],
            }
        )
        env.update(overrides)
        return subprocess.run(
            [
                "powershell.exe",
                "-NoProfile",
                "-ExecutionPolicy",
                "Bypass",
                "-File",
                str(SCRIPT),
                "-Role",
                "user",
                "-Serial",
                SERIAL,
                "-RcApk",
                str(self.rc_apk),
                "-StableApk",
                str(self.stable_apk),
                "-AaptPath",
                str(self.aapt2),
                "-ApkSignerPath",
                str(self.apksigner),
                "-AdbPath",
                str(self.adb),
                "-LaunchTimeoutSeconds",
                "5",
            ],
            cwd=ROOT,
            env=env,
            text=True,
            encoding="utf-8",
            errors="replace",
            capture_output=True,
            timeout=30,
            check=False,
        )

    def _calls(self) -> list[dict[str, object]]:
        return [json.loads(line) for line in self.log.read_text(encoding="utf-8").splitlines()]

    def test_full_fake_code61_to_code62_upgrade_passes(self) -> None:
        completed = self._run()
        self.assertEqual(0, completed.returncode, completed.stdout + completed.stderr)
        payload = json.loads(next(line for line in completed.stdout.splitlines() if line.startswith("{")))
        self.assertEqual("PASS", payload["status"])
        self.assertEqual(61, payload["fromVersionCode"])
        self.assertEqual(62, payload["toVersionCode"])
        self.assertTrue(payload["uidPreserved"])
        self.assertTrue(payload["dataDirPreserved"])
        self.assertEqual("62", self.state.read_text(encoding="ascii"))
        adb_calls = [entry["args"] for entry in self._calls() if entry["tool"] == "adb"]
        install_calls = [args for args in adb_calls if "install" in args]
        self.assertEqual(2, len(install_calls))
        self.assertTrue(all("-r" in args and "-d" not in args for args in install_calls))
        self.assertFalse(any("uninstall" in args for args in adb_calls))

    def test_offline_device_fails_before_install(self) -> None:
        completed = self._run(FAKE_DEVICE_STATE="offline")
        self.assertNotEqual(0, completed.returncode)
        calls = self._calls()
        self.assertFalse(any(entry["tool"] == "adb" and "install" in entry["args"] for entry in calls))
        self.assertNotIn('"status":"PASS"', completed.stdout + completed.stderr)

    def test_background_window_text_does_not_count_as_foreground(self) -> None:
        completed = self._run(FAKE_FOREGROUND="0")
        self.assertNotEqual(0, completed.returncode)
        self.assertIn("DEVICE_UPGRADE_GATE_FAIL", completed.stdout + completed.stderr)
        self.assertNotIn('"status":"PASS"', completed.stdout + completed.stderr)

    def test_tool_failure_redacts_bearer_and_token_values(self) -> None:
        completed = self._run(FAKE_AAPT_FAIL="1")
        self.assertNotEqual(0, completed.returncode)
        combined = completed.stdout + completed.stderr
        self.assertNotIn("EXAMPLE_SENSITIVE_VALUE", combined)
        self.assertNotIn("EXAMPLE_SECOND_VALUE", combined)
        self.assertIn("<redacted>", combined)


if __name__ == "__main__":
    unittest.main()
