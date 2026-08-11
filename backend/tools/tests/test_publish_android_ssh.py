import importlib.util
from pathlib import Path
import sys
import types
import unittest


try:
    import paramiko  # noqa: F401
except ModuleNotFoundError:
    paramiko_stub = types.ModuleType("paramiko")

    class SSHClient:
        pass

    paramiko_stub.SSHClient = SSHClient
    sys.modules["paramiko"] = paramiko_stub


SCRIPT = Path(__file__).resolve().parents[1] / "publish-android-ssh.py"
SPEC = importlib.util.spec_from_file_location("publish_android_ssh", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class DownloadBaseUrlTest(unittest.TestCase):
    def test_site_origin_gets_downloads_path(self):
        self.assertEqual(
            MODULE.normalize_download_base_url("http://appht.jjmxg.xyz"),
            "http://appht.jjmxg.xyz/downloads",
        )

    def test_downloads_path_is_not_duplicated(self):
        self.assertEqual(
            MODULE.normalize_download_base_url("https://example.test/downloads/"),
            "https://example.test/downloads",
        )

    def test_rejects_relative_url(self):
        with self.assertRaises(RuntimeError):
            MODULE.normalize_download_base_url("/downloads")


if __name__ == "__main__":
    unittest.main()
