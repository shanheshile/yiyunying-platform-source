from __future__ import annotations

from contextlib import redirect_stderr, redirect_stdout
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest import mock
import builtins
import zipfile


BACKEND = Path(__file__).resolve().parents[2]
SCRIPT = BACKEND / "tools" / "prepare-offline-stt-runtime.py"
MANIFEST = BACKEND / "tools" / "stt" / "offline" / "artifacts.json"
BUILDER_MANIFEST = BACKEND / "tools" / "stt" / "offline" / "builder-tools.json"
LICENSE_EVIDENCE_MANIFEST = BACKEND / "tools" / "stt" / "offline" / "license-evidence.json"


def load_module():
    spec = importlib.util.spec_from_file_location("prepare_offline_stt_runtime_test", SCRIPT)
    if spec is None or spec.loader is None:
        raise RuntimeError("unable to load offline STT preparer")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


class FakeResponse:
    def __init__(self, payload: bytes, url: str) -> None:
        self.payload = payload
        self.url = url
        self.offset = 0
        self.headers = {"Content-Length": str(len(payload))}

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def geturl(self) -> str:
        return self.url

    def read(self, amount: int) -> bytes:
        chunk = self.payload[self.offset:self.offset + amount]
        self.offset += len(chunk)
        return chunk


class FakeOpener:
    def __init__(self, payload: bytes, url: str) -> None:
        self.payload = payload
        self.url = url

    def open(self, _request, timeout: int):
        if timeout <= 0:
            raise AssertionError("download timeout must be bounded")
        return FakeResponse(self.payload, self.url)


class PrepareOfflineSttRuntimeTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.module = load_module()

    def test_frozen_manifest_is_complete_linux_cp311_wheel_only(self) -> None:
        manifest, records = self.module.load_manifest(MANIFEST)
        wheels = [record for record in records if record.category == "wheel"]
        models = [record for record in records if record.category == "model"]
        self.assertEqual(36, len(records))
        self.assertEqual(30, len(wheels))
        self.assertEqual(4, len(models))
        self.assertEqual(372_946_184, sum(record.size for record in records))
        self.assertEqual(119_165_510, sum(record.size for record in wheels))
        self.assertEqual(147_882_941, sum(record.size for record in models))
        self.assertEqual("3.11.15", manifest["target"]["python"])
        self.assertEqual("2.17", manifest["target"]["minimum_glibc"])
        self.assertFalse(any(record.filename.endswith((".tar.gz", ".zip")) for record in wheels))
        self.assertFalse(any("win" in record.filename.lower() or "musllinux" in record.filename.lower() for record in wheels))
        setuptools = next(record for record in wheels if record.name == "setuptools")
        self.assertEqual("84.0.0", setuptools.version)
        self.module.validate_lock_file(records)

    def test_default_mode_is_read_only_even_when_artifacts_are_absent(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary) / "not-created"
            stream = io.StringIO()
            with redirect_stdout(stream):
                result = self.module.main(["--manifest", str(MANIFEST), "--output", str(output)])
            self.assertEqual(0, result)
            self.assertFalse(output.exists())
            self.assertIn('"network":false', stream.getvalue())
            self.assertIn("no directories, files, or network connections", stream.getvalue())

    def test_probe_is_exact_and_deterministic(self) -> None:
        payload = self.module.probe_bytes()
        self.assertEqual(32_044, len(payload))
        self.assertEqual(
            "d13e4f6fd2e70b6d93dbc1029412c4a00716e5539a9840d2dd746b414170df94",
            hashlib.sha256(payload).hexdigest(),
        )
        self.assertEqual(payload, self.module.probe_bytes())

    def test_download_uses_unique_partial_hash_check_and_atomic_publish(self) -> None:
        payload = b"reviewed-wheel-fixture"
        url = "https://files.pythonhosted.org/packages/reviewed.whl"
        record = self.module.Artifact(
            category="wheel",
            filename="reviewed.whl",
            size=len(payload),
            sha256=hashlib.sha256(payload).hexdigest(),
            url=url,
            name="reviewed",
            version="1",
            license="MIT",
        )
        with tempfile.TemporaryDirectory() as temporary:
            destination = Path(temporary) / record.filename
            with mock.patch.object(self.module, "build_opener", return_value=FakeOpener(payload, url)), \
                    mock.patch.object(self.module.shutil, "which", return_value=None), \
                    redirect_stdout(io.StringIO()):
                self.module.download_artifact(record, destination)
            self.assertEqual(payload, destination.read_bytes())
            self.assertEqual([], list(destination.parent.glob("*.partial")))
            self.assertEqual([], list(destination.parent.glob(".*.partial")))

    def test_bad_download_is_never_published_and_partials_are_cleaned(self) -> None:
        expected = b"expected"
        wrong = b"tampered"
        url = "https://files.pythonhosted.org/packages/reviewed.whl"
        record = self.module.Artifact(
            category="wheel",
            filename="reviewed.whl",
            size=len(expected),
            sha256=hashlib.sha256(expected).hexdigest(),
            url=url,
        )
        with tempfile.TemporaryDirectory() as temporary:
            destination = Path(temporary) / record.filename
            with mock.patch.object(self.module, "build_opener", return_value=FakeOpener(wrong, url)), \
                    mock.patch.object(self.module.shutil, "which", return_value=None), \
                    mock.patch.object(self.module.time, "sleep"), \
                    redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()), \
                    self.assertRaises(RuntimeError):
                self.module.download_artifact(record, destination)
            self.assertFalse(destination.exists())
            self.assertEqual([], list(destination.parent.iterdir()))

    def test_manifest_has_full_model_hashes_and_fixed_revision(self) -> None:
        data = json.loads(MANIFEST.read_text(encoding="utf-8"))
        self.assertEqual("ebe41f70d5b6dfa9166e2c581c45c9c0cfc57b66", data["model"]["revision"])
        expected = {
            "config.json": "56a6d8110d311f19c8f0471e562832c7527f146b567275bfca59fcf7c184da9a",
            "model.bin": "d01c3014881c9c6f3133c182f3d2887eb6ca1c789a7538c5c007196857a0a6a9",
            "tokenizer.json": "fb7b63191e9bb045082c79fd742a3106a12c99513ab30df4a0d47fa6cb6fd0ab",
            "vocabulary.txt": "34ce3fe1c5041027b3f8d42912270993f986dbc4bb34cf27f951e34a1e453913",
        }
        self.assertEqual(expected, {item["filename"]: item["sha256"] for item in data["model"]["files"]})

    def test_build_layout_rejects_stale_partial_or_unmanifested_byte(self) -> None:
        record = self.module.Artifact(
            category="wheel",
            filename="reviewed.whl",
            size=1,
            sha256=hashlib.sha256(b"x").hexdigest(),
            url="https://files.pythonhosted.org/reviewed.whl",
        )
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "source"
            destination = self.module.artifact_destination(root, record)
            destination.parent.mkdir(parents=True)
            destination.write_bytes(b"x")
            self.module.validate_artifact_source_layout(root, [record])
            stale = destination.parent / ".reviewed.whl.deadbeef.partial"
            stale.write_bytes(b"")
            with self.assertRaisesRegex(RuntimeError, "layout is not closed"):
                self.module.validate_artifact_source_layout(root, [record])

    def test_curl_path_is_https_only_bounded_and_range_receipted(self) -> None:
        source = SCRIPT.read_text(encoding="utf-8")
        for marker in (
            '"--ipv4"',
            '"--http1.1"',
            '"--max-time", "180"',
            '"--speed-time", "60"',
            '"--proto", "=https"',
            '"--proto-redir", "=https"',
            '"--range", f"{start}-{end}"',
            'status != "206"',
            'os.fstat(output.fileno()).st_size != end + 1',
        ):
            self.assertIn(marker, source)

    def test_builder_only_zstandard_identity_is_exact(self) -> None:
        record = self.module.builder_zstandard_record()
        self.assertEqual("zstandard", record.name)
        self.assertEqual("0.25.0", record.version)
        self.assertEqual(506_276, record.size)
        self.assertEqual(
            "ffef5a74088f1e09947aecf91011136665152e0b4b359c42be3373897fb39b01",
            record.sha256,
        )
        manifest = json.loads(BUILDER_MANIFEST.read_text(encoding="utf-8"))
        self.assertIn("builder wheel never included", manifest["purpose"])

    def test_companion_reader_never_falls_back_to_ambient_package_or_tar(self) -> None:
        original_import = builtins.__import__

        def guarded_import(name, *args, **kwargs):
            if name == "compression":
                raise ImportError("forced pre-3.14 test")
            return original_import(name, *args, **kwargs)

        with mock.patch("builtins.__import__", side_effect=guarded_import), \
                self.assertRaisesRegex(RuntimeError, "--zstandard-wheel"):
            self.module.companion_license_payload(Path("not-opened.tar.zst"), None)

    def test_builder_wheel_argument_is_build_only(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "valid only together with --build"):
            self.module.main(["--zstandard-wheel", "not-used.whl"])

    def test_build_requires_exact_builder_wheel_for_reproducible_notices(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "requires the exact --zstandard-wheel"):
            self.module.main(["--build"])

    def test_notices_exact_lock_and_keyboard_interrupt_policy_are_frozen(self) -> None:
        self.assertEqual(90_601, self.module.THIRD_PARTY_NOTICES_SIZE)
        self.assertEqual(
            "55cd6e0bca728d3d053389310bb8eacdefc95e803fb55d927965ba0ec19a170e",
            self.module.THIRD_PARTY_NOTICES_SHA256,
        )
        source = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("except Exception as exc:", source)
        self.assertNotIn("except BaseException as exc:\n            error = exc", source)

    def test_external_license_evidence_is_exact_and_non_executable(self) -> None:
        records = self.module.load_license_evidence()
        self.assertEqual(
            {"ctranslate2", "flatbuffers", "tokenizers"},
            {self.module.canonical_name(record.name) for record in records},
        )
        self.assertEqual(3, len(records))
        self.assertEqual(23_830, sum(record.size for record in records))
        self.assertTrue(all(record.category == "license-evidence" for record in records))
        manifest = json.loads(LICENSE_EVIDENCE_MANIFEST.read_text(encoding="utf-8"))
        self.assertIn("never used as executable", manifest["purpose"])

    def test_license_evidence_download_mode_never_selects_runtime_artifacts(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            selected = []

            def capture(record, _destination):
                selected.append(record)

            with mock.patch.object(self.module, "download_artifact", side_effect=capture), \
                    redirect_stdout(io.StringIO()):
                result = self.module.main([
                    "--manifest", str(MANIFEST),
                    "--output", temporary,
                    "--download-license-evidence",
                ])
            self.assertEqual(0, result)
            self.assertEqual(3, len(selected))
            self.assertTrue(all(record.category == "license-evidence" for record in selected))

    def test_license_evidence_only_cannot_be_combined_with_build(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "cannot be combined"):
            self.module.main(["--download-license-evidence", "--build"])

    def test_cached_real_wheels_match_external_license_evidence_set(self) -> None:
        _, records = self.module.load_manifest(MANIFEST)
        wheels = [record for record in records if record.category == "wheel"]
        source_root = self.module.DEFAULT_OUTPUT / "source"
        paths = [self.module.artifact_destination(source_root, record) for record in wheels]
        if not all(path.is_file() for path in paths):
            self.skipTest("frozen local wheelhouse is not present")
        missing_embedded = set()
        for record, path in zip(wheels, paths):
            self.module.verify_artifact(path, record)
            payloads = self.module.wheel_license_payload(record, path)
            if not any(not self.module.is_top_level_wheel_metadata(name) for name in payloads):
                missing_embedded.add(self.module.canonical_name(record.name))
        self.assertEqual(
            {self.module.canonical_name(record.name) for record in self.module.load_license_evidence()},
            missing_embedded,
        )

    def test_dependency_closure_purges_ambient_packaging_namespace(self) -> None:
        _, records = self.module.load_manifest(MANIFEST)
        wheels = [record for record in records if record.category == "wheel"]
        source_root = self.module.DEFAULT_OUTPUT / "source"
        if not all(self.module.artifact_destination(source_root, record).is_file() for record in wheels):
            self.skipTest("frozen local wheelhouse is not present")
        ambient = types.ModuleType("packaging")
        ambient.__version__ = "26.2"
        ambient.__file__ = "C:/ambient/packaging/__init__.py"
        with mock.patch.dict(sys.modules, {"packaging": ambient}, clear=False):
            closure = self.module.generate_dependency_closure(source_root, records)
        self.assertEqual(30, closure["component_count"])
        self.assertIsNot(sys.modules.get("packaging"), ambient)

    def test_model_manifest_must_exactly_match_artifact_contract(self) -> None:
        artifact_manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
        model_manifest = json.loads(BUILDER_MANIFEST.with_name("model-manifest.json").read_text(encoding="utf-8"))
        model_manifest["license"] = "tampered"
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / "model-manifest.json"
            path.write_text(json.dumps(model_manifest), encoding="utf-8")
            with self.assertRaisesRegex(RuntimeError, "differs from the frozen artifact contract"):
                self.module.validate_model_manifest(artifact_manifest["model"], path)

    def test_trusted_input_rejects_regular_hardlink(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            original = root / "original"
            alias = root / "alias"
            original.write_bytes(b"trusted")
            try:
                alias.hardlink_to(original)
            except OSError as exc:
                self.skipTest(f"hardlinks unavailable: {exc}")
            with self.assertRaisesRegex(RuntimeError, "unique regular non-link"):
                self.module.trusted_input_file(alias, "test input")

    def test_wheel_identity_ignores_vendored_dist_info_metadata(self) -> None:
        record = self.module.Artifact(
            category="wheel",
            filename="example-1.0-py3-none-any.whl",
            size=1,
            sha256="0" * 64,
            url="https://files.pythonhosted.org/example.whl",
            name="example",
            version="1.0",
            license="MIT",
        )
        with tempfile.TemporaryDirectory() as temporary:
            wheel = Path(temporary) / record.filename
            with zipfile.ZipFile(wheel, "w") as archive:
                archive.writestr("example-1.0.dist-info/METADATA", "Name: example\nVersion: 1.0\n")
                archive.writestr("example/_vendor/vendor-2.0.dist-info/METADATA", "Name: vendor\nVersion: 2.0\n")
                archive.writestr("example-1.0.dist-info/LICENSE", "MIT fixture")
            metadata = self.module.wheel_metadata(record, wheel)
            self.assertEqual("example", metadata["Name"])
            licenses = self.module.wheel_license_payload(record, wheel)
            self.assertIn("example-1.0.dist-info/LICENSE", licenses)


if __name__ == "__main__":
    unittest.main()
