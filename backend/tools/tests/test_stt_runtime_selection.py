from __future__ import annotations

import argparse
from contextlib import redirect_stdout
import importlib.util
import io
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import types
import unittest
from unittest import mock


BACKEND = Path(__file__).resolve().parents[2]
TRANSCRIBE = BACKEND / "tools" / "stt" / "transcribe.py"
SPEECH_CONTROLLER = BACKEND / "app" / "Controllers" / "User" / "SpeechController.php"
APP_CONFIG = BACKEND / "config" / "app.php"


def load_transcribe_module():
    spec = importlib.util.spec_from_file_location("yiyunying_stt_transcribe_test", TRANSCRIBE)
    if spec is None or spec.loader is None:
        raise RuntimeError("unable to load STT transcribe module")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class SttRuntimeSelectionTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.module = load_transcribe_module()

    def test_current_model_is_fixed_and_authoritative(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            current = root / "storage/stt/current/model/base"
            current.mkdir(parents=True)
            with mock.patch.dict(os.environ, {"YIYUNYING_STT_MODEL_DIR": str(root / "legacy")}, clear=False):
                source, download_root, formal = self.module.resolve_model_source("medium", root)
            self.assertEqual(str(current.resolve()), source)
            self.assertIsNone(download_root)
            self.assertTrue(formal)

    def test_legacy_cache_and_configured_model_remain_available(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            legacy = root / "legacy-model-cache"
            with mock.patch.dict(os.environ, {"YIYUNYING_STT_MODEL_DIR": str(legacy)}, clear=False):
                source, download_root, formal = self.module.resolve_model_source("small", root)
            self.assertEqual("small", source)
            self.assertEqual(str(legacy.resolve()), download_root)
            self.assertFalse(formal)

    def test_offline_environment_overrides_network_enabled_values(self) -> None:
        network_enabled = {name: "0" for name in self.module.OFFLINE_ENVIRONMENT}
        with mock.patch.dict(os.environ, network_enabled, clear=False):
            self.module.force_offline_mode()
            for name, value in self.module.OFFLINE_ENVIRONMENT.items():
                self.assertEqual(value, os.environ[name])

    def test_main_uses_current_model_without_download_root(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            current = root / "storage/stt/current/model/base"
            current.mkdir(parents=True)
            source_audio = root / "probe.wav"
            source_audio.write_bytes(b"RIFF-test-fixture")
            output = root / "result/transcript.txt"
            observed: dict[str, object] = {}

            class FakeWhisperModel:
                def __init__(self, model_source: str, **options: object) -> None:
                    observed["model_source"] = model_source
                    observed["options"] = options

                def transcribe(self, source: str, **options: object):
                    observed["audio_source"] = source
                    return [types.SimpleNamespace(text=" offline pass ")], None

            fake_package = types.ModuleType("faster_whisper")
            fake_package.WhisperModel = FakeWhisperModel
            parsed = argparse.Namespace(
                input=str(source_audio), output=str(output), language="zh", model="medium",
                runtime_probe=False,
            )
            with mock.patch.object(self.module, "arguments", return_value=parsed), \
                    mock.patch.object(self.module, "project_root", return_value=root), \
                    mock.patch.dict(os.environ, {
                        "YIYUNYING_STT_DEVICE": "cuda",
                        "YIYUNYING_STT_COMPUTE_TYPE": "float16",
                    }, clear=False), \
                    mock.patch.dict(sys.modules, {"faster_whisper": fake_package}), \
                    redirect_stdout(io.StringIO()):
                result = self.module.main()

            self.assertEqual(0, result)
            self.assertEqual("offline pass", output.read_text(encoding="utf-8"))
            self.assertEqual(str(current.resolve()), observed["model_source"])
            options = observed["options"]
            self.assertIsInstance(options, dict)
            self.assertIs(options["local_files_only"], True)
            self.assertNotIn("download_root", options)
            self.assertEqual("cpu", options["device"])
            self.assertEqual("int8", options["compute_type"])

    def test_legacy_main_is_offline_and_does_not_create_cache(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            legacy = root / "legacy-model-cache"
            source_audio = root / "probe.wav"
            source_audio.write_bytes(b"RIFF-test-fixture")
            output = root / "transcript.txt"
            observed: dict[str, object] = {}

            class FakeWhisperModel:
                def __init__(self, model_source: str, **options: object) -> None:
                    observed["model_source"] = model_source
                    observed["options"] = options

                def transcribe(self, source: str, **options: object):
                    return [types.SimpleNamespace(text=" legacy pass ")], None

            fake_package = types.ModuleType("faster_whisper")
            fake_package.WhisperModel = FakeWhisperModel
            parsed = argparse.Namespace(
                input=str(source_audio), output=str(output), language="auto", model="base",
                runtime_probe=False,
            )
            with mock.patch.object(self.module, "arguments", return_value=parsed), \
                    mock.patch.object(self.module, "project_root", return_value=root), \
                    mock.patch.dict(os.environ, {"YIYUNYING_STT_MODEL_DIR": str(legacy)}, clear=False), \
                    mock.patch.dict(sys.modules, {"faster_whisper": fake_package}), \
                    redirect_stdout(io.StringIO()):
                result = self.module.main()

            self.assertEqual(0, result)
            self.assertEqual("base", observed["model_source"])
            options = observed["options"]
            self.assertIsInstance(options, dict)
            self.assertIs(options["local_files_only"], True)
            self.assertEqual(str(legacy.resolve()), options["download_root"])
            self.assertFalse(legacy.exists(), "offline fallback must not create a download cache")

    def test_controller_prefers_current_then_keeps_legacy_fallback(self) -> None:
        source = SPEECH_CONTROLLER.read_text(encoding="utf-8")
        resolver = source.split("private static function resolveLocalCommand", 1)[1]
        resolver = resolver.split("private static function safeProcessDetail", 1)[0]
        current = "$root . '/storage/stt/current/python/bin/python3'"
        legacy = "$root . '/storage/stt/venv/bin/python3'"
        self.assertIn(current, resolver)
        self.assertIn(legacy, resolver)
        candidates = resolver.split("$candidates =", 1)[1]
        self.assertLess(candidates.index("$currentPython"), candidates.index("$configured"))
        self.assertLess(candidates.index("$configured"), candidates.index("$legacyPython"))

    def test_runtime_probe_accepts_silence_and_writes_json_receipt(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            current = root / "storage/stt/current/model/base"
            current.mkdir(parents=True)
            candidate = root / "fresh-release/model/base"
            candidate.mkdir(parents=True)
            source_audio = root / "probe.wav"
            source_audio.write_bytes(b"RIFF-test-fixture")
            output = root / "probe.json"

            class FakeWhisperModel:
                def __init__(self, _model_source: str, **_options: object) -> None:
                    pass

                def transcribe(self, _source: str, **_options: object):
                    return [], types.SimpleNamespace(duration=1.0)

            fake_package = types.ModuleType("faster_whisper")
            fake_package.WhisperModel = FakeWhisperModel
            parsed = argparse.Namespace(
                input=str(source_audio), output=str(output), language="en", model=str(candidate),
                runtime_probe=True,
            )
            with mock.patch.object(self.module, "arguments", return_value=parsed), \
                    mock.patch.object(self.module, "project_root", return_value=root), \
                    mock.patch.dict(sys.modules, {"faster_whisper": fake_package}), \
                    redirect_stdout(io.StringIO()):
                result = self.module.main()

            self.assertEqual(0, result)
            self.assertEqual('{"runtime_probe":true,"segments":0}', output.read_text(encoding="utf-8"))

    def test_runtime_probe_ignores_preexisting_current_and_requires_explicit_local_model(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            current = root / "storage/stt/current/model/base"
            current.mkdir(parents=True)
            candidate = root / "new-release/model/base"
            candidate.mkdir(parents=True)
            source, download_root, formal = self.module.resolve_model_source(
                str(candidate), root, runtime_probe=True,
            )
            self.assertEqual(str(candidate.resolve()), source)
            self.assertNotEqual(str(current.resolve()), source)
            self.assertIsNone(download_root)
            self.assertTrue(formal)
            with self.assertRaisesRegex(ValueError, "existing local model"):
                self.module.resolve_model_source("base", root, runtime_probe=True)

    def test_formal_flag_is_bound_to_single_current_resolution(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            release_a = root / "storage/stt/releases/release-a/model/base"
            release_b = root / "storage/stt/releases/release-b/model/base"
            release_a.mkdir(parents=True)
            release_b.mkdir(parents=True)
            current = root / "storage/stt/current"
            try:
                current.symlink_to(release_a.parents[1], target_is_directory=True)
            except OSError as exc:
                self.skipTest(f"directory symlinks unavailable: {exc}")
            source, download_root, formal = self.module.resolve_model_source("medium", root)
            current.unlink()
            current.symlink_to(release_b.parents[1], target_is_directory=True)
            self.assertEqual(str(release_a.resolve()), source)
            self.assertIsNone(download_root)
            self.assertTrue(formal, "formal mode must remain bound to release A after current switches to B")

    def test_config_prefers_current_python_before_legacy_and_system(self) -> None:
        source = APP_CONFIG.read_text(encoding="utf-8")
        current = "$root . '/storage/stt/current/python/bin/python3'"
        legacy = "$root . '/storage/stt/venv/bin/python3'"
        self.assertIn(current, source)
        self.assertIn(legacy, source)
        self.assertLess(source.index(current), source.index(legacy))
        self.assertLess(source.index(legacy), source.index("$systemPythonCandidates"))

    def test_attachment_flow_allows_receiver_but_direct_upload_remains_owner_only(self) -> None:
        source = SPEECH_CONTROLLER.read_text(encoding="utf-8")
        transcribe = source.split("public static function transcribe", 1)[1]
        transcribe = transcribe.split("private static function accessibleAudioAttachment", 1)[0]
        self.assertIn("if ($attachment === null)", transcribe)
        self.assertIn(
            "WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ? AND status = 1",
            transcribe,
        )
        self.assertIn(
            "WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1",
            transcribe,
        )
        self.assertLess(
            transcribe.index("accessibleAudioAttachment($user, $request)"),
            transcribe.index("WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1"),
        )
        attachment_gate = source.split("private static function accessibleAudioAttachment", 1)[1]
        self.assertIn("target_type = ? AND target_id = ?", attachment_gate)
        self.assertIn("你无权转写这条语音消息", attachment_gate)

    def test_controller_cleanup_unlinks_directory_symlink_without_following_it(self) -> None:
        if os.name == "nt":
            self.skipTest("production directory-symlink unlink fixture requires Unix")
        php = shutil.which("php")
        if php is None:
            self.skipTest("PHP executable unavailable")
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            output = root / "stt-output"
            outside = root / "must-survive"
            output.mkdir()
            outside.mkdir()
            sentinel = outside / "sentinel.txt"
            sentinel.write_text("keep", encoding="utf-8")
            link = output / "escape"
            try:
                link.symlink_to(outside, target_is_directory=True)
            except OSError as exc:
                self.skipTest(f"directory symlinks unavailable: {exc}")

            harness = root / "cleanup-fixture.php"
            harness.write_text(
                "<?php\n"
                "require $argv[1];\n"
                "$method = new ReflectionMethod("
                "'Yiyunying\\\\Controllers\\\\User\\\\SpeechController', 'removeDirectory');\n"
                "$method->setAccessible(true);\n"
                "$method->invoke(null, $argv[2]);\n",
                encoding="utf-8",
            )
            completed = subprocess.run(
                [php, str(harness), str(SPEECH_CONTROLLER), str(output)],
                capture_output=True,
                text=True,
                timeout=20,
                check=False,
            )
            self.assertEqual(0, completed.returncode, completed.stderr)
            self.assertTrue(sentinel.is_file(), "cleanup followed the symlink outside its root")
            self.assertEqual("keep", sentinel.read_text(encoding="utf-8"))
            self.assertFalse(link.exists(), "cleanup must unlink the symlink itself")
            self.assertFalse(output.exists(), "the confined output directory should be removed")


if __name__ == "__main__":
    unittest.main()
