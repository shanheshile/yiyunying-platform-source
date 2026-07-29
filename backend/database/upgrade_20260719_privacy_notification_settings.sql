-- 易运盈后台：补全添加方式、消息权限、通知渠道与动态可见对象。
-- 本文件可重复执行，已有字段不会重复添加。

SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'allow_card_add') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `allow_card_add` TINYINT NOT NULL DEFAULT 1 AFTER `profile_followers_visible`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'allow_qr_add') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `allow_qr_add` TINYINT NOT NULL DEFAULT 1 AFTER `allow_card_add`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'allow_uid_search') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `allow_uid_search` TINYINT NOT NULL DEFAULT 1 AFTER `allow_qr_add`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'allow_phone_search') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `allow_phone_search` TINYINT NOT NULL DEFAULT 0 AFTER `allow_uid_search`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'allow_email_search') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `allow_email_search` TINYINT NOT NULL DEFAULT 0 AFTER `allow_phone_search`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'allow_group_member_add') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `allow_group_member_add` TINYINT NOT NULL DEFAULT 1 AFTER `allow_email_search`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'allow_group_invitations') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `allow_group_invitations` TINYINT NOT NULL DEFAULT 1 AFTER `allow_group_member_add`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'show_online_status') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `show_online_status` TINYINT NOT NULL DEFAULT 1 AFTER `allow_group_invitations`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'read_receipts_enabled') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `read_receipts_enabled` TINYINT NOT NULL DEFAULT 1 AFTER `show_online_status`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'room_notification_enabled') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `room_notification_enabled` TINYINT NOT NULL DEFAULT 1 AFTER `read_receipts_enabled`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'forum_notification_enabled') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `forum_notification_enabled` TINYINT NOT NULL DEFAULT 1 AFTER `room_notification_enabled`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'bounty_notification_enabled') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `bounty_notification_enabled` TINYINT NOT NULL DEFAULT 1 AFTER `forum_notification_enabled`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'mention_notification_enabled') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `mention_notification_enabled` TINYINT NOT NULL DEFAULT 1 AFTER `bounty_notification_enabled`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'notification_preview_enabled') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `notification_preview_enabled` TINYINT NOT NULL DEFAULT 1 AFTER `mention_notification_enabled`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'notification_sound_enabled') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `notification_sound_enabled` TINYINT NOT NULL DEFAULT 1 AFTER `notification_preview_enabled`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'notification_vibration_enabled') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `notification_vibration_enabled` TINYINT NOT NULL DEFAULT 1 AFTER `notification_sound_enabled`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'dynamic_visible_to_friends') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `dynamic_visible_to_friends` TINYINT NOT NULL DEFAULT 1 AFTER `notification_vibration_enabled`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'dynamic_visible_to_followers') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `dynamic_visible_to_followers` TINYINT NOT NULL DEFAULT 1 AFTER `dynamic_visible_to_friends`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'dynamic_visible_to_strangers') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `dynamic_visible_to_strangers` TINYINT NOT NULL DEFAULT 1 AFTER `dynamic_visible_to_followers`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'dynamic_visible_to_hidden_contacts') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `dynamic_visible_to_hidden_contacts` TINYINT NOT NULL DEFAULT 1 AFTER `dynamic_visible_to_strangers`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'dynamic_visible_to_special_care') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `dynamic_visible_to_special_care` TINYINT NOT NULL DEFAULT 1 AFTER `dynamic_visible_to_hidden_contacts`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.19-privacy-notification-settings', '添加方式、消息权限、通知渠道与动态可见对象', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
