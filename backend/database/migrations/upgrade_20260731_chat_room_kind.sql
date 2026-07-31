SET NAMES utf8mb4;
SET @schema_name = DATABASE();

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'chat_rooms' AND COLUMN_NAME = 'room_kind'),
  'SELECT 1',
  'ALTER TABLE `chat_rooms` ADD COLUMN `room_kind` VARCHAR(20) NOT NULL DEFAULT ''group'' COMMENT ''group/chat_room'' AFTER `tags_json`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `chat_rooms` r
LEFT JOIN `chat_room_policies` p ON p.`room_id` = r.`id`
SET r.`room_kind` = CASE WHEN p.`owner_user_id` IS NULL THEN 'chat_room' ELSE 'group' END
WHERE r.`room_kind` IS NULL
   OR r.`room_kind` = ''
   OR (r.`room_kind` = 'group' AND p.`owner_user_id` IS NULL);

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'chat_rooms' AND INDEX_NAME = 'idx_chat_rooms_kind'),
  'SELECT 1',
  'ALTER TABLE `chat_rooms` ADD INDEX `idx_chat_rooms_kind` (`app_id`, `room_kind`, `status`, `created_at`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `app_settings`
  (`admin_id`, `app_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT a.`admin_id`, a.`id`, cfg.`setting_key`, cfg.`setting_value`, cfg.`value_type`, NOW(), NOW()
FROM `apps` a
CROSS JOIN (
  SELECT 'user_chatroom_create_enabled' AS `setting_key`, '1' AS `setting_value`, 'bool' AS `value_type`
  UNION ALL SELECT 'user_chatroom_max_owned', '10', 'int'
  UNION ALL SELECT 'chatroom_default_max_members', '500', 'int'
) cfg;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.31-chat-room-kind', 'Stable group and chat-room classification with independent limits', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);