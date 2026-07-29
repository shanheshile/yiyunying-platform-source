-- Remote-login protection preference. Compatible with MySQL 5.7/8.0 and safe to rerun.
SET NAMES utf8mb4;

SET @has_remote_login_protection := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_message_preferences'
    AND COLUMN_NAME = 'remote_login_protection'
);
SET @remote_login_protection_sql := IF(
  @has_remote_login_protection = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `remote_login_protection` TINYINT NOT NULL DEFAULT 1 AFTER `notification_vibration_enabled`',
  'SELECT 1'
);
PREPARE remote_login_protection_statement FROM @remote_login_protection_sql;
EXECUTE remote_login_protection_statement;
DEALLOCATE PREPARE remote_login_protection_statement;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
SELECT '2026.07.22-remote-login-protection', 'Remote-login protection preference and new-device alert', NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `schema_migrations`
  WHERE `version` = '2026.07.22-remote-login-protection'
);
