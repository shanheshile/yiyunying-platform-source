-- 易运盈后台 2026-07-13 标签、详情穿透与交互导航升级
-- 兼容 MySQL 5.7+/8.0 与 MariaDB 10.3+，可重复执行。
SET NAMES utf8mb4;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents' AND COLUMN_NAME = 'tags_json'),
  'SELECT 1', 'ALTER TABLE `documents` ADD COLUMN `tags_json` LONGTEXT NULL AFTER `content`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_posts' AND COLUMN_NAME = 'tags_json'),
  'SELECT 1', 'ALTER TABLE `forum_posts` ADD COLUMN `tags_json` LONGTEXT NULL AFTER `images_json`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'tags_json'),
  'SELECT 1', 'ALTER TABLE `messages` ADD COLUMN `tags_json` LONGTEXT NULL AFTER `content`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_rooms' AND COLUMN_NAME = 'tags_json'),
  'SELECT 1', 'ALTER TABLE `chat_rooms` ADD COLUMN `tags_json` LONGTEXT NULL AFTER `description`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_messages' AND COLUMN_NAME = 'tags_json'),
  'SELECT 1', 'ALTER TABLE `chat_room_messages` ADD COLUMN `tags_json` LONGTEXT NULL AFTER `content`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('20260713_interaction_navigation', '内容标签、详情穿透、消息转发和可视化导航底座', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
