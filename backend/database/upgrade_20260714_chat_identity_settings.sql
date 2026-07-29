SET NAMES utf8mb4;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_forward_bundles' AND COLUMN_NAME = 'audit_snapshot_json') = 0,
  'ALTER TABLE `message_forward_bundles` ADD COLUMN `audit_snapshot_json` LONGTEXT NULL AFTER `snapshot_json`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_forward_bundles' AND COLUMN_NAME = 'source_context_json') = 0,
  'ALTER TABLE `message_forward_bundles` ADD COLUMN `source_context_json` LONGTEXT NULL AFTER `audit_snapshot_json`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `message_forward_bundles`
SET `audit_snapshot_json` = `snapshot_json`
WHERE `audit_snapshot_json` IS NULL OR `audit_snapshot_json` = '';

SET @index_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'communication_takeover_audits'
     AND INDEX_NAME = 'idx_communication_takeover_audit_message') = 0,
  'ALTER TABLE `communication_takeover_audits` ADD INDEX `idx_communication_takeover_audit_message` (`admin_id`, `app_id`, `channel_type`, `message_id`, `action`)',
  'SELECT 1'
);
PREPARE stmt FROM @index_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles' AND COLUMN_NAME = 'region') = 0,
  'ALTER TABLE `user_profiles` ADD COLUMN `region` VARCHAR(120) NOT NULL DEFAULT '''' AFTER `birthday`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friends' AND COLUMN_NAME = 'special_care') = 0,
  'ALTER TABLE `friends` ADD COLUMN `special_care` TINYINT NOT NULL DEFAULT 0 AFTER `remark`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friends' AND COLUMN_NAME = 'relationship_label') = 0,
  'ALTER TABLE `friends` ADD COLUMN `relationship_label` VARCHAR(60) NOT NULL DEFAULT '''' AFTER `special_care`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friends' AND COLUMN_NAME = 'clue_note') = 0,
  'ALTER TABLE `friends` ADD COLUMN `clue_note` VARCHAR(500) NOT NULL DEFAULT '''' AFTER `relationship_label`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friends' AND COLUMN_NAME = 'only_chat') = 0,
  'ALTER TABLE `friends` ADD COLUMN `only_chat` TINYINT NOT NULL DEFAULT 0 AFTER `clue_note`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friends' AND COLUMN_NAME = 'hide_my_notes') = 0,
  'ALTER TABLE `friends` ADD COLUMN `hide_my_notes` TINYINT NOT NULL DEFAULT 0 AFTER `only_chat`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friends' AND COLUMN_NAME = 'hide_their_notes') = 0,
  'ALTER TABLE `friends` ADD COLUMN `hide_their_notes` TINYINT NOT NULL DEFAULT 0 AFTER `hide_my_notes`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friends' AND COLUMN_NAME = 'updated_at') = 0,
  'ALTER TABLE `friends` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `user_gift_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `from_user_id` BIGINT UNSIGNED NOT NULL,
  `to_user_id` BIGINT UNSIGNED NOT NULL,
  `gift_code` VARCHAR(40) NOT NULL,
  `gift_name` VARCHAR(80) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `message` VARCHAR(300) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_gift_records_wall` (`app_id`, `to_user_id`, `id`),
  KEY `idx_user_gift_records_sender` (`app_id`, `from_user_id`, `id`),
  CONSTRAINT `fk_user_gift_records_sender` FOREIGN KEY (`from_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_gift_records_receiver` FOREIGN KEY (`to_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.14-chat-identity-settings', '匿名双轨审计、客服实名、好友权限、个人名片与礼物墙', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
