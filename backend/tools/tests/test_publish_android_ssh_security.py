import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest import mock


try:
    import paramiko  # noqa: F401
except ModuleNotFoundError:
    paramiko_stub = types.ModuleType("paramiko")

    class RejectPolicy:
        pass

    class SSHClient:
        pass

    paramiko_stub.RejectPolicy = RejectPolicy
    paramiko_stub.SSHClient = SSHClient
    sys.modules["paramiko"] = paramiko_stub


SCRIPT = Path(__file__).resolve().parents[1] / "publish-android-ssh.py"
SPEC = importlib.util.spec_from_file_location("publish_android_ssh_security", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)

SIGNER = "A" * 64
OLD_SIGNER = "B" * 64
EDITION_DATA = {
    "user": ("user", "example.yiyunying.user", "2.8.0-user"),
    "admin": ("admin", "example.yiyunying.admin", "2.8.0-admin"),
    "authorized_platform": (
        "authorized",
        "example.yiyunying.authorized",
        "2.8.0-authorized-platform",
    ),
    "platform_owner": (
        "owner",
        "example.yiyunying.owner",
        "2.8.0-platform-owner",
    ),
}


class ReleaseFixture:
    def __init__(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.release_dir = self.root / "releases" / "2.8.0"
        self.release_dir.mkdir(parents=True)
        self.releases = []
        self.entries = []
        for edition, (manifest_id, package_name, version_name) in EDITION_DATA.items():
            path = self.release_dir / f"{edition}.apk"
            path.write_bytes((edition + "-verified-apk").encode("utf-8"))
            size, sha256 = MODULE.digest(str(path))
            filename = f"{edition}.apk"
            self.releases.append(
                MODULE.Release(edition, package_name, str(path), filename, size, sha256)
            )
            self.entries.append(
                {
                    "id": manifest_id,
                    "fileName": filename,
                    "packageName": package_name,
                    "versionName": version_name,
                    "versionCode": 60,
                    "sizeBytes": size,
                    "sha256": sha256.upper(),
                    "signerSha256": SIGNER,
                }
            )
        self.manifest = self.release_dir / "release-manifest.json"
        self.manifest.write_text(
            json.dumps(
                {
                    "schemaVersion": 4,
                    "versionName": "2.8.0",
                    "versionCode": 60,
                    "buildSourceCommit": "1" * 40,
                    "releaseEvidenceCommit": "2" * 40,
                    "releaseTag": "v2.8.0-debug",
                    "finalizationStatus": "finalized",
                    "connectionIdentity": {
                        "apiBaseUrl": "https://release.example.test/api/",
                        "appKeySha256": "3" * 64,
                        "platformKeySha256": "4" * 64,
                        "authorizedPlatformKeySha256": "5" * 64,
                    },
                    "releaseNotes": ["安全发布"],
                    "releases": self.entries,
                }
            ),
            encoding="utf-8",
        )
        self.identity = self.root / "release-identity.json"
        self.identity.write_text(
            json.dumps({"version_name": "2.8.0", "version_code": 60}),
            encoding="utf-8",
        )
        manifest = json.loads(self.manifest.read_text(encoding="utf-8"))
        manifest["releaseIdentitySha256"] = hashlib.sha256(
            self.identity.read_bytes()
        ).hexdigest()
        self.manifest.write_text(json.dumps(manifest), encoding="utf-8")

    def inspect(self, apk_path, _aapt, _apksigner):
        release = next(item for item in self.releases if item.local_path == apk_path)
        _, package_name, version_name = EDITION_DATA[release.edition]
        return package_name, version_name, 60, SIGNER

    def close(self):
        self.temporary.cleanup()


class HostKeyPinningTest(unittest.TestCase):
    def test_connect_uses_only_explicit_known_hosts_and_reject_policy(self):
        client = mock.Mock()
        transport = mock.Mock()
        client.get_transport.return_value = transport
        args = argparse.Namespace(
            host="release.example.test",
            port=2222,
            user="publisher",
            known_hosts="C:/secure/release_known_hosts",
        )
        with mock.patch.object(MODULE.paramiko, "SSHClient", return_value=client):
            connected = MODULE.connect_ssh(args, "secret")
        self.assertIs(connected, client)
        client.load_host_keys.assert_called_once_with(args.known_hosts)
        policy = client.set_missing_host_key_policy.call_args.args[0]
        self.assertIsInstance(policy, MODULE.paramiko.RejectPolicy)
        self.assertFalse(hasattr(client, "load_system_host_keys") and client.load_system_host_keys.called)

    def test_source_never_enrolls_unknown_host_keys(self):
        source = SCRIPT.read_text(encoding="utf-8")
        self.assertNotIn("AutoAddPolicy", source)
        self.assertNotIn("load_system_host_keys", source)
        self.assertIn('parser.add_argument("--known-hosts", required=True)', source)


class LocalReleaseIdentityTest(unittest.TestCase):
    def setUp(self):
        self.fixture = ReleaseFixture()

    def tearDown(self):
        self.fixture.close()

    def validate(self):
        with mock.patch.object(MODULE, "inspect_apk", side_effect=self.fixture.inspect):
            return MODULE.validate_release_plan(
                self.fixture.releases,
                str(self.fixture.manifest),
                str(self.fixture.identity),
                "2.8.0",
                60,
                "aapt",
                "apksigner",
            )

    def test_complete_manifest_identity_hash_and_single_signer_are_accepted(self):
        releases, manifest = self.validate()
        self.assertEqual(set(MODULE.EDITIONS), {release.edition for release in releases})
        self.assertEqual({SIGNER}, {release.signer_sha256 for release in releases})
        self.assertTrue(all(release.range_size > 0 for release in releases))
        self.assertEqual("2.8.0", manifest["versionName"])

    def test_missing_or_repeated_edition_is_rejected_before_apk_inspection(self):
        repeated = self.fixture.releases[:-1] + [self.fixture.releases[0]]
        with mock.patch.object(MODULE, "inspect_apk") as inspect:
            with self.assertRaisesRegex(RuntimeError, "edition"):
                MODULE.validate_release_plan(
                    repeated,
                    str(self.fixture.manifest),
                    str(self.fixture.identity),
                    "2.8.0",
                    60,
                    "aapt",
                    "apksigner",
                )
        inspect.assert_not_called()

    def test_manifest_size_or_sha_mismatch_is_rejected(self):
        manifest = json.loads(self.fixture.manifest.read_text(encoding="utf-8"))
        manifest["releases"][0]["sizeBytes"] += 1
        self.fixture.manifest.write_text(json.dumps(manifest), encoding="utf-8")
        with mock.patch.object(MODULE, "inspect_apk", side_effect=self.fixture.inspect):
            with self.assertRaisesRegex(RuntimeError, "digest"):
                MODULE.validate_release_plan(
                    self.fixture.releases,
                    str(self.fixture.manifest),
                    str(self.fixture.identity),
                    "2.8.0",
                    60,
                    "aapt",
                    "apksigner",
                )

    def test_pending_or_unbound_manifest_is_rejected(self):
        manifest = json.loads(self.fixture.manifest.read_text(encoding="utf-8"))
        manifest["finalizationStatus"] = "pending"
        self.fixture.manifest.write_text(json.dumps(manifest), encoding="utf-8")
        with self.assertRaisesRegex(RuntimeError, "finalized"):
            self.validate()

    def test_previous_release_signer_is_authoritative(self):
        previous = self.fixture.root / "releases" / "2.7.99"
        previous.mkdir()
        (previous / "release-manifest.json").write_text(
            json.dumps(
                {
                    "versionCode": 59,
                    "releases": [{"signerSha256": OLD_SIGNER} for _ in range(4)],
                }
            ),
            encoding="utf-8",
        )
        with mock.patch.object(MODULE, "inspect_apk", side_effect=self.fixture.inspect):
            with self.assertRaisesRegex(RuntimeError, "trusted previous"):
                MODULE.validate_release_plan(
                    self.fixture.releases,
                    str(self.fixture.manifest),
                    str(self.fixture.identity),
                    "2.8.0",
                    60,
                    "aapt",
                    "apksigner",
                )

    def test_apksigner_output_with_two_signers_is_rejected(self):
        badging = "package: name='example.app' versionCode='60' versionName='2.8.0-user'\n"
        signatures = (
            f"Signer #1 certificate SHA-256 digest: {SIGNER}\n"
            f"Signer #2 certificate SHA-256 digest: {OLD_SIGNER}\n"
        )
        with mock.patch.object(MODULE, "run_local_tool", side_effect=[badging, signatures]):
            with self.assertRaisesRegex(RuntimeError, "exactly one signer"):
                MODULE.inspect_apk("app.apk", "aapt", "apksigner")


class AtomicPublicationContractTest(unittest.TestCase):
    def setUp(self):
        self.releases = []
        self.urls = {}
        for edition, (_, package_name, version_name) in EDITION_DATA.items():
            payload = edition.encode("utf-8")
            release = MODULE.Release(
                edition,
                package_name,
                f"C:/{edition}.apk",
                f"{edition}.apk",
                len(payload),
                hashlib.sha256(payload).hexdigest(),
                version_name,
                60,
                SIGNER,
                len(payload),
                hashlib.sha256(payload).hexdigest(),
            )
            self.releases.append(release)
            self.urls[edition] = f"https://download.example.test/downloads/2.8.0-token/{edition}.apk"

    def test_candidate_policies_start_disabled_and_do_not_touch_old_policy(self):
        sql = MODULE.build_candidate_policy_sql(
            7, 1, self.releases, self.urls, "安全发布"
        ).decode("utf-8")
        self.assertEqual(4, sql.count("INSERT INTO software_update_policies"))
        self.assertEqual(4, sql.count(", 0, NULL, NULL, NOW(), NOW());"))
        self.assertNotIn("UPDATE software_update_policies", sql)
        for release in self.releases:
            self.assertIn(MODULE.sql_string(release.version_name), sql)

    def test_post_activation_lifecycle_requires_exact_release_identity(self):
        release = self.releases[0]
        payload = {
            "code": 1,
            "data": {
                "edition_code": release.edition,
                "update": {
                    "available": True,
                    "version_name": release.version_name,
                    "version_code": release.version_code,
                    "download_url": self.urls[release.edition],
                    "package_name": release.package_name,
                    "sha256": release.sha256.upper(),
                    "size_bytes": release.size_bytes,
                    "force_update": False,
                },
            },
        }
        MODULE.validate_lifecycle_payload(release, self.urls[release.edition], payload)
        payload["data"]["update"]["version_name"] = "2.8.0"
        with self.assertRaisesRegex(RuntimeError, "version_name"):
            MODULE.validate_lifecycle_payload(release, self.urls[release.edition], payload)

    def test_http_requires_explicit_debug_only_confirmation(self):
        with self.assertRaisesRegex(RuntimeError, "refused by default"):
            MODULE.enforce_transport_policy(
                "http://download.example.test/downloads",
                "http://api.example.test/api/public/lifecycle",
                self.releases,
                False,
                "",
            )
        debug_releases = [
            MODULE.replace(
                release,
                package_name=release.package_name + ".debug",
                remote_filename=release.edition + "-debug.apk",
                version_name=release.version_name + "-debug",
            )
            for release in self.releases
        ]
        self.assertTrue(
            MODULE.enforce_transport_policy(
                "http://download.example.test/downloads",
                "http://api.example.test/api/public/lifecycle",
                debug_releases,
                True,
                MODULE.INSECURE_HTTP_CONFIRMATION,
            )
        )
        with self.assertRaisesRegex(RuntimeError, "restricted to the four Debug"):
            MODULE.enforce_transport_policy(
                "http://download.example.test/downloads",
                "http://api.example.test/api/public/lifecycle",
                self.releases,
                True,
                MODULE.INSECURE_HTTP_CONFIRMATION,
            )

    def test_activation_is_one_guarded_transaction_for_all_four_candidates(self):
        sql = MODULE.build_activation_sql(7, self.releases, self.urls)
        self.assertEqual(1, sql.count("START TRANSACTION;"))
        self.assertEqual(1, sql.count("COMMIT;"))
        self.assertIn("@candidate_count = 4", sql)
        self.assertIn("@activated_count = 4", sql)
        self.assertIn("ACTIVATED:", sql)
        activate_index = sql.index("SET status = 1")
        disable_old_index = sql.index("SET status = 0")
        self.assertLess(activate_index, disable_old_index)

    def test_failure_recovery_disables_candidate_and_restores_old_snapshot_atomically(self):
        sql = MODULE.build_failure_recovery_sql(7, self.releases, self.urls, [91, 92, 93, 94])
        self.assertEqual(1, sql.count("START TRANSACTION;"))
        self.assertEqual(1, sql.count("COMMIT;"))
        self.assertIn("SET status = 0", sql)
        self.assertIn("SET status = 1", sql)
        self.assertIn("id IN (91, 92, 93, 94)", sql)

    def test_public_acceptance_requires_full_sha_range_and_stable_etag(self):
        command = MODULE.public_verification_command(
            self.releases[0], self.urls[self.releases[0].edition], "/tmp/stage-token"
        )
        for required in (
            "curl -fsSL",
            "sha256sum",
            "Range: bytes=0-",
            'test "$STATUS" = 206',
            "CONTENT_RANGE",
            "ETAG",
            'test "$RANGE_ETAG" = "$ETAG"',
            self.releases[0].sha256,
            self.releases[0].range_sha256,
        ):
            self.assertIn(required, command)

    def test_backup_does_not_mask_mysqldump_failure_with_a_pipeline(self):
        args = argparse.Namespace(
            db_host="127.0.0.1", db_port=3306, db_user="release", db_name="release_db"
        )
        command = MODULE.policy_backup_command(args, "password", "/backup/unique")
        self.assertNotIn("| gzip", command)
        self.assertTrue(command.startswith("set -eu;"))
        self.assertIn("software_update_policies >", command)
        self.assertIn("test -s", command)
        self.assertIn("gzip -f", command)

    def test_source_orders_disabled_policy_and_public_acceptance_before_activation(self):
        source = SCRIPT.read_text(encoding="utf-8")
        insert_index = source.index('"insert-disabled-candidate-policies"')
        public_index = source.index('f"public-sha-range-etag-{release.edition}"')
        activation_index = source.index('"activate-four-policies-atomically"')
        lifecycle_index = source.index(
            "        for release in releases:\n            verify_lifecycle_release("
        )
        accepted_index = source.index("        activated = True")
        self.assertLess(insert_index, public_index)
        self.assertLess(public_index, activation_index)
        self.assertLess(activation_index, lifecycle_index)
        self.assertLess(lifecycle_index, accepted_index)
        self.assertIn("release_token = secrets.token_hex", source)
        self.assertIn('parser.add_argument("--lifecycle-url", required=True)', source)
        self.assertIn('parser.add_argument("--allow-insecure-http-debug", action="store_true")', source)
        self.assertIn("DEBUG_HTTP_NON_PRODUCTION_CONFIRMED", source)
        self.assertIn('manifest.get("finalizationStatus") != "finalized"', source)
        self.assertIn("validate_git_release_evidence(repository_root, manifest)", source)
        self.assertIn('"restore-old-and-disable-failed-candidates"', source)
        self.assertIn('cleanup_paths.append(release_dir)', source)


if __name__ == "__main__":
    unittest.main()
