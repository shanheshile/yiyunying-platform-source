SET NAMES utf8mb4;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_files' AND COLUMN_NAME = 'parent_id') = 0,
  'ALTER TABLE `chat_room_files` ADD COLUMN `parent_id` BIGINT UNSIGNED NULL AFTER `uploader_user_id`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_files' AND COLUMN_NAME = 'is_folder') = 0,
  'ALTER TABLE `chat_room_files` ADD COLUMN `is_folder` TINYINT NOT NULL DEFAULT 0 AFTER `parent_id`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_files' AND COLUMN_NAME = 'download_count') = 0,
  'ALTER TABLE `chat_room_files` ADD COLUMN `download_count` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `size_bytes`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE `chat_room_files` MODIFY COLUMN `file_url` VARCHAR(1000) NOT NULL DEFAULT '';

SET @index_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_files' AND INDEX_NAME = 'idx_chat_room_files_parent') = 0,
  'ALTER TABLE `chat_room_files` ADD INDEX `idx_chat_room_files_parent` (`room_id`, `parent_id`, `status`, `is_folder`, `id`)',
  'SELECT 1'
);
PREPARE stmt FROM @index_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_sql = IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_files' AND CONSTRAINT_NAME = 'fk_chat_room_files_parent') = 0,
  'ALTER TABLE `chat_room_files` ADD CONSTRAINT `fk_chat_room_files_parent` FOREIGN KEY (`parent_id`) REFERENCES `chat_room_files` (`id`) ON DELETE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @fk_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.17-group-file-folders', '群文件夹、目录导航与下载计数', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
