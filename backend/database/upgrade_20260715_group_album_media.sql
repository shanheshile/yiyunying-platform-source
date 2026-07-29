SET NAMES utf8mb4;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_album_photos' AND COLUMN_NAME = 'media_type') = 0,
  'ALTER TABLE `chat_room_album_photos` ADD COLUMN `media_type` VARCHAR(20) NOT NULL DEFAULT ''image'' AFTER `image_url`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_album_photos' AND COLUMN_NAME = 'mime_type') = 0,
  'ALTER TABLE `chat_room_album_photos` ADD COLUMN `mime_type` VARCHAR(120) NOT NULL DEFAULT ''image/jpeg'' AFTER `media_type`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_album_photos' AND COLUMN_NAME = 'size_bytes') = 0,
  'ALTER TABLE `chat_room_album_photos` ADD COLUMN `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `mime_type`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_album_photos' AND COLUMN_NAME = 'thumbnail_url') = 0,
  'ALTER TABLE `chat_room_album_photos` ADD COLUMN `thumbnail_url` VARCHAR(1000) NOT NULL DEFAULT '''' AFTER `size_bytes`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_album_photos' AND COLUMN_NAME = 'download_count') = 0,
  'ALTER TABLE `chat_room_album_photos` ADD COLUMN `download_count` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `thumbnail_url`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.15-group-album-media', '群相册图片与视频统一媒体元数据', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
