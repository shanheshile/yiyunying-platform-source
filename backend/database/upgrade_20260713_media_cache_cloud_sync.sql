-- 易运盈后台 2026-07-13 大媒体、分类搜索、本地缓存与云端同步升级
-- 兼容 MySQL 5.7+/8.0 与 MariaDB 10.3+，可重复执行。
SET NAMES utf8mb4;

SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads' AND COLUMN_NAME = 'original_size_bytes') = 0,
  'ALTER TABLE `uploads` ADD COLUMN `original_size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `size_bytes`', 'SELECT 1');
PREPARE column_stmt FROM @column_sql; EXECUTE column_stmt; DEALLOCATE PREPARE column_stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads' AND COLUMN_NAME = 'optimized_size_bytes') = 0,
  'ALTER TABLE `uploads` ADD COLUMN `optimized_size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `original_size_bytes`', 'SELECT 1');
PREPARE column_stmt FROM @column_sql; EXECUTE column_stmt; DEALLOCATE PREPARE column_stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads' AND COLUMN_NAME = 'upload_mode') = 0,
  'ALTER TABLE `uploads` ADD COLUMN `upload_mode` VARCHAR(20) NOT NULL DEFAULT ''original'' AFTER `optimized_size_bytes`', 'SELECT 1');
PREPARE column_stmt FROM @column_sql; EXECUTE column_stmt; DEALLOCATE PREPARE column_stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads' AND COLUMN_NAME = 'optimization_status') = 0,
  'ALTER TABLE `uploads` ADD COLUMN `optimization_status` VARCHAR(40) NOT NULL DEFAULT ''not_required'' AFTER `upload_mode`', 'SELECT 1');
PREPARE column_stmt FROM @column_sql; EXECUTE column_stmt; DEALLOCATE PREPARE column_stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads' AND COLUMN_NAME = 'original_file_url') = 0,
  'ALTER TABLE `uploads` ADD COLUMN `original_file_url` VARCHAR(1000) NOT NULL DEFAULT '''' AFTER `optimization_status`', 'SELECT 1');
PREPARE column_stmt FROM @column_sql; EXECUTE column_stmt; DEALLOCATE PREPARE column_stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads' AND COLUMN_NAME = 'optimized_file_url') = 0,
  'ALTER TABLE `uploads` ADD COLUMN `optimized_file_url` VARCHAR(1000) NOT NULL DEFAULT '''' AFTER `original_file_url`', 'SELECT 1');
PREPARE column_stmt FROM @column_sql; EXECUTE column_stmt; DEALLOCATE PREPARE column_stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads' AND COLUMN_NAME = 'thumbnail_url') = 0,
  'ALTER TABLE `uploads` ADD COLUMN `thumbnail_url` VARCHAR(1000) NOT NULL DEFAULT '''' AFTER `optimized_file_url`', 'SELECT 1');
PREPARE column_stmt FROM @column_sql; EXECUTE column_stmt; DEALLOCATE PREPARE column_stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads' AND COLUMN_NAME = 'is_animated') = 0,
  'ALTER TABLE `uploads` ADD COLUMN `is_animated` TINYINT NOT NULL DEFAULT 0 AFTER `thumbnail_url`', 'SELECT 1');
PREPARE column_stmt FROM @column_sql; EXECUTE column_stmt; DEALLOCATE PREPARE column_stmt;

UPDATE `uploads`
SET `original_size_bytes` = `size_bytes`,
    `upload_mode` = IF(`upload_mode` = '', 'original', `upload_mode`),
    `optimization_status` = IF(`optimization_status` = '', 'not_required', `optimization_status`)
WHERE `original_size_bytes` = 0;

SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_search_histories' AND COLUMN_NAME = 'content_filter') = 0,
  'ALTER TABLE `chat_search_histories` ADD COLUMN `content_filter` VARCHAR(30) NOT NULL DEFAULT ''all'' AFTER `keyword`', 'SELECT 1');
PREPARE column_stmt FROM @column_sql; EXECUTE column_stmt; DEALLOCATE PREPARE column_stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_search_histories' AND COLUMN_NAME = 'filter_json') = 0,
  'ALTER TABLE `chat_search_histories` ADD COLUMN `filter_json` LONGTEXT NULL AFTER `content_filter`', 'SELECT 1');
PREPARE column_stmt FROM @column_sql; EXECUTE column_stmt; DEALLOCATE PREPARE column_stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_search_histories' AND COLUMN_NAME = 'filter_hash') = 0,
  'ALTER TABLE `chat_search_histories` ADD COLUMN `filter_hash` CHAR(64) NOT NULL DEFAULT '''' AFTER `filter_json`', 'SELECT 1');
PREPARE column_stmt FROM @column_sql; EXECUTE column_stmt; DEALLOCATE PREPARE column_stmt;

UPDATE `chat_search_histories`
SET `filter_hash` = SHA2(CONCAT(`keyword`, '|', `content_filter`, '|', COALESCE(`filter_json`, '')), 256)
WHERE `filter_hash` = '';
SET @index_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_search_histories' AND INDEX_NAME = 'uk_chat_search_histories_keyword') > 0,
  'ALTER TABLE `chat_search_histories` DROP INDEX `uk_chat_search_histories_keyword`', 'SELECT 1');
PREPARE index_stmt FROM @index_sql; EXECUTE index_stmt; DEALLOCATE PREPARE index_stmt;
SET @index_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_search_histories' AND INDEX_NAME = 'uk_chat_search_histories_filter') = 0,
  'ALTER TABLE `chat_search_histories` ADD UNIQUE KEY `uk_chat_search_histories_filter` (`user_id`, `scope_type`, `target_id`, `filter_hash`)', 'SELECT 1');
PREPARE index_stmt FROM @index_sql; EXECUTE index_stmt; DEALLOCATE PREPARE index_stmt;

CREATE TABLE IF NOT EXISTS `cloud_sync_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `data_type` VARCHAR(20) NOT NULL,
  `scope_type` VARCHAR(20) NOT NULL DEFAULT '',
  `target_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `item_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `date_from` DATETIME DEFAULT NULL,
  `date_to` DATETIME DEFAULT NULL,
  `filter_json` LONGTEXT,
  `snapshot_json` LONGTEXT NOT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `charged_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cloud_sync_snapshots_owner` (`app_id`, `user_id`, `data_type`, `created_at`),
  KEY `idx_cloud_sync_snapshots_scope` (`app_id`, `user_id`, `scope_type`, `target_id`, `created_at`),
  CONSTRAINT `fk_cloud_sync_snapshots_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `app_settings` SET `setting_value` = '104857600', `updated_at` = NOW()
WHERE `setting_key` IN ('upload_max_bytes', 'upload_image_max_bytes') AND `setting_value` = '20971520';
UPDATE `app_settings` SET `setting_value` = '524288000', `updated_at` = NOW()
WHERE `setting_key` = 'upload_video_max_bytes' AND `setting_value` = '209715200';
UPDATE `app_settings` SET `setting_value` = '104857600', `updated_at` = NOW()
WHERE `setting_key` = 'upload_audio_max_bytes' AND `setting_value` = '52428800';
UPDATE `app_settings` SET `setting_value` = '209715200', `updated_at` = NOW()
WHERE `setting_key` = 'upload_file_max_bytes' AND `setting_value` = '104857600';

INSERT INTO `app_settings` (`admin_id`, `app_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT app.admin_id, app.id, defaults.setting_key, defaults.setting_value, defaults.value_type, NOW(), NOW()
FROM `apps` app CROSS JOIN (
  SELECT 'media_optimize_by_default' setting_key, '1' setting_value, 'bool' value_type
  UNION ALL SELECT 'media_original_upload_enabled', '1', 'bool'
  UNION ALL SELECT 'sticker_optimize_enabled', '1', 'bool'
  UNION ALL SELECT 'sticker_target_max_bytes', '524288', 'int'
  UNION ALL SELECT 'cloud_chat_backup_enabled', '1', 'bool'
  UNION ALL SELECT 'cloud_chat_backup_vip_required', '0', 'bool'
  UNION ALL SELECT 'cloud_chat_backup_price', '0', 'float'
  UNION ALL SELECT 'cloud_sticker_sync_enabled', '1', 'bool'
  UNION ALL SELECT 'cloud_sticker_sync_vip_required', '0', 'bool'
  UNION ALL SELECT 'cloud_sticker_sync_price', '0', 'float'
  UNION ALL SELECT 'cloud_favorite_sync_enabled', '1', 'bool'
  UNION ALL SELECT 'cloud_favorite_sync_vip_required', '0', 'bool'
  UNION ALL SELECT 'cloud_favorite_sync_price', '0', 'float'
  UNION ALL SELECT 'cloud_backup_max_items', '5000', 'int'
  UNION ALL SELECT 'cloud_backup_retention_days', '3650', 'int'
  UNION ALL SELECT 'chat_local_cache_days', '90', 'int'
  UNION ALL SELECT 'media_cache_max_bytes', '536870912', 'int'
) defaults
WHERE 1 = 1
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('20260713_media_cache_cloud_sync', '大文件优化上传、分类聊天搜索、本地缓存策略与跨设备云端快照', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
