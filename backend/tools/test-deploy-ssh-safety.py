#!/usr/bin/env python3
"""Static release-safety contract for deploy-ssh.py (no network required)."""

from __future__ import annotations

import importlib.util
import os
from pathlib import Path
import shlex
import shutil
import subprocess
import tempfile
import unittest


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
        self.assertIn('Archive release identity does not match --release-identity', SOURCE)
        self.assertIn('ACTUAL_IDENTITY_SHA256=$(sha256sum', SOURCE)
        self.assertIn(r'test \"${{ACTUAL_IDENTITY_SHA256%% *}}\"', SOURCE)

    def test_default_credential_audit_precedes_all_backup_and_maintenance_work(self) -> None:
        stage_guard = SOURCE.index("stage_backend + '/tools/audit-default-credentials.php'")
        config = SOURCE.index('"application-config-preflight"')
        audit = SOURCE.index('"default-credential-read-only-audit"')
        backup_directory = SOURCE.index('"backup-directory"')
        code_backup = SOURCE.index('"code-backup"')
        maintenance_scope = SOURCE.index("maintenance_attempted = False")
        maintenance = SOURCE.index('"catalog-maintenance"')
        migration = SOURCE.index('f"database-migration-{index}"')
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
        php_bootstrap = (
            "PHP_BIN=/www/server/php/82/bin/php; "
            'if [ ! -x "$PHP_BIN" ]; then PHP_BIN=$(command -v php || true); fi; '
            'test -n "$PHP_BIN"; '
        )
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
            result = subprocess.run(
                [php_executable, "-l", str(php_file)],
                capture_output=True,
                check=False,
                text=True,
            )
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)

    def test_maintenance_can_stop_php_fpm_before_a_real_restart(self) -> None:
        self.assertIn("it may stop PHP-FPM", SOURCE)
        self.assertIn("/etc/init.d/php-fpm-82 restart", SOURCE)
        self.assertIn("/etc/init.d/php-fpm-82 start", SOURCE)
        self.assertIn("systemctl restart php8.2-fpm", SOURCE)
        self.assertIn("systemctl start php8.2-fpm", SOURCE)
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
