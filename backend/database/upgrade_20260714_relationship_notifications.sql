SET NAMES utf8mb4;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'allow_friend_requests') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `allow_friend_requests` TINYINT NOT NULL DEFAULT 1 AFTER `accept_stranger_messages`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @index_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friend_requests' AND INDEX_NAME = 'uk_friend_requests_pending') > 0,
  'ALTER TABLE `friend_requests` DROP INDEX `uk_friend_requests_pending`',
  'SELECT 1'
);
PREPARE stmt FROM @index_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @index_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friend_requests' AND INDEX_NAME = 'idx_friend_requests_pair') = 0,
  'ALTER TABLE `friend_requests` ADD INDEX `idx_friend_requests_pair` (`app_id`, `from_user_id`, `to_user_id`, `status`)',
  'SELECT 1'
);
PREPARE stmt FROM @index_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friend_requests' AND COLUMN_NAME = 'requester_remark') = 0,
  'ALTER TABLE `friend_requests` ADD COLUMN `requester_remark` VARCHAR(100) NOT NULL DEFAULT '''' AFTER `message`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friend_requests' AND COLUMN_NAME = 'requester_group_id') = 0,
  'ALTER TABLE `friend_requests` ADD COLUMN `requester_group_id` BIGINT UNSIGNED NULL AFTER `requester_remark`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friend_requests' AND COLUMN_NAME = 'hide_my_dynamic') = 0,
  'ALTER TABLE `friend_requests` ADD COLUMN `hide_my_dynamic` TINYINT NOT NULL DEFAULT 0 AFTER `requester_group_id`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friend_requests' AND COLUMN_NAME = 'hide_their_dynamic') = 0,
  'ALTER TABLE `friend_requests` ADD COLUMN `hide_their_dynamic` TINYINT NOT NULL DEFAULT 0 AFTER `hide_my_dynamic`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friend_requests' AND COLUMN_NAME = 'decision_reason') = 0,
  'ALTER TABLE `friend_requests` ADD COLUMN `decision_reason` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `status`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_invitations' AND COLUMN_NAME = 'decision_reason') = 0,
  'ALTER TABLE `chat_room_invitations` ADD COLUMN `decision_reason` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `status`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_join_requests' AND COLUMN_NAME = 'decision_reason') = 0,
  'ALTER TABLE `chat_room_join_requests` ADD COLUMN `decision_reason` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `status`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friend_requests' AND COLUMN_NAME = 'ignore_reason') = 0,
  'ALTER TABLE `friend_requests` ADD COLUMN `ignore_reason` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `decision_reason`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friend_requests' AND COLUMN_NAME = 'ignored_at') = 0,
  'ALTER TABLE `friend_requests` ADD COLUMN `ignored_at` DATETIME NULL AFTER `ignore_reason`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'friend_requests' AND COLUMN_NAME = 'expired_at') = 0,
  'ALTER TABLE `friend_requests` ADD COLUMN `expired_at` DATETIME NULL AFTER `ignored_at`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE `friend_requests` SET `expired_at` = DATE_ADD(`created_at`, INTERVAL 30 DAY) WHERE `expired_at` IS NULL;
ALTER TABLE `friend_requests` MODIFY COLUMN `expired_at` DATETIME NOT NULL;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_invitations' AND COLUMN_NAME = 'ignore_reason') = 0,
  'ALTER TABLE `chat_room_invitations` ADD COLUMN `ignore_reason` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `decision_reason`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_invitations' AND COLUMN_NAME = 'ignored_at') = 0,
  'ALTER TABLE `chat_room_invitations` ADD COLUMN `ignored_at` DATETIME NULL AFTER `ignore_reason`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE `chat_room_invitations` SET `expired_at` = DATE_ADD(`created_at`, INTERVAL 30 DAY) WHERE `expired_at` IS NULL;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_join_requests' AND COLUMN_NAME = 'ignore_reason') = 0,
  'ALTER TABLE `chat_room_join_requests` ADD COLUMN `ignore_reason` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `decision_reason`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_join_requests' AND COLUMN_NAME = 'ignored_at') = 0,
  'ALTER TABLE `chat_room_join_requests` ADD COLUMN `ignored_at` DATETIME NULL AFTER `ignore_reason`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_join_requests' AND COLUMN_NAME = 'expired_at') = 0,
  'ALTER TABLE `chat_room_join_requests` ADD COLUMN `expired_at` DATETIME NULL AFTER `ignored_at`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE `chat_room_join_requests` SET `expired_at` = DATE_ADD(`created_at`, INTERVAL 30 DAY) WHERE `expired_at` IS NULL;
ALTER TABLE `chat_room_join_requests` MODIFY COLUMN `expired_at` DATETIME NOT NULL;

INSERT INTO `platform_settings`
  (`platform_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT `id`, 'default_relationship_request_valid_days', '30', 'int', NOW(), NOW()
FROM `platform_accounts` WHERE `deleted_at` IS NULL
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);
INSERT INTO `platform_settings`
  (`platform_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT `id`, 'force_relationship_request_valid_days', '0', 'bool', NOW(), NOW()
FROM `platform_accounts` WHERE `deleted_at` IS NULL
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);
INSERT INTO `platform_settings`
  (`platform_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT `id`, 'allow_child_relationship_request_valid_days_override', '1', 'bool', NOW(), NOW()
FROM `platform_accounts` WHERE `deleted_at` IS NULL
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);
INSERT INTO `platform_settings`
  (`platform_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT `id`, 'relationship_request_valid_days_inherit', '1', 'bool', NOW(), NOW()
FROM `platform_accounts` WHERE `deleted_at` IS NULL
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);

INSERT INTO `app_settings`
  (`admin_id`, `app_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, 'relationship_request_valid_days', '30', 'int', NOW(), NOW()
FROM `apps` WHERE `deleted_at` IS NULL
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);
INSERT INTO `app_settings`
  (`admin_id`, `app_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, 'relationship_request_valid_days_inherit', '1', 'bool', NOW(), NOW()
FROM `apps` WHERE `deleted_at` IS NULL
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.14-relationship-notifications', '关系通知、可继续处理的忽略状态、分层有效期与好友申请设置', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
