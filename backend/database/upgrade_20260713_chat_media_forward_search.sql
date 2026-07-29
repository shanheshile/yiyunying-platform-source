SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `message_forward_bundles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `creator_user_id` BIGINT UNSIGNED NOT NULL,
  `source_type` VARCHAR(20) NOT NULL,
  `source_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `item_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `snapshot_json` LONGTEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_message_forward_bundles_owner` (`app_id`, `creator_user_id`, `created_at`),
  CONSTRAINT `fk_message_forward_bundles_user` FOREIGN KEY (`creator_user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_forward_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `bundle_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(30) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_message_forward_links_target` (`app_id`, `target_type`, `target_id`),
  KEY `idx_message_forward_links_bundle` (`bundle_id`, `id`),
  CONSTRAINT `fk_message_forward_links_bundle` FOREIGN KEY (`bundle_id`)
    REFERENCES `message_forward_bundles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_search_histories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `scope_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `keyword` VARCHAR(200) NOT NULL,
  `search_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_searched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_search_histories_keyword` (`user_id`, `scope_type`, `target_id`, `keyword`),
  KEY `idx_chat_search_histories_recent` (`app_id`, `user_id`, `last_searched_at`),
  CONSTRAINT `fk_chat_search_histories_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `app_settings` (`admin_id`, `app_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT app.admin_id, app.id, setting.setting_key, setting.setting_value, 'int', NOW(), NOW()
FROM `apps` app
JOIN (
  SELECT 'upload_image_max_bytes' AS setting_key, '20971520' AS setting_value
  UNION ALL SELECT 'upload_video_max_bytes', '209715200'
  UNION ALL SELECT 'upload_audio_max_bytes', '52428800'
  UNION ALL SELECT 'upload_file_max_bytes', '104857600'
) setting
ON 1 = 1
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('20260713_chat_media_forward_search', '聊天媒体分类上限、结构化只读合并转发与上下文搜索历史', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

SET FOREIGN_KEY_CHECKS = 1;
