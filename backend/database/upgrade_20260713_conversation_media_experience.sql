-- 易运盈后台 2026-07-13 会话、媒体、草稿与头像历史升级
-- 兼容 MySQL 5.7+/8.0 与 MariaDB 10.3+，可重复执行。
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `conversation_preferences` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `is_pinned` TINYINT NOT NULL DEFAULT 0,
  `is_hidden` TINYINT NOT NULL DEFAULT 0,
  `is_muted` TINYINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_conversation_preferences_target` (`user_id`, `target_type`, `target_id`),
  KEY `idx_conversation_preferences_center` (`app_id`, `user_id`, `is_pinned`, `is_hidden`, `updated_at`),
  CONSTRAINT `fk_conversation_preferences_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `communication_message_states` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `scope_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `is_deleted` TINYINT NOT NULL DEFAULT 0,
  `is_favorite` TINYINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_communication_message_states` (`user_id`, `scope_type`, `message_id`),
  KEY `idx_communication_message_states_favorite` (`app_id`, `user_id`, `is_favorite`, `is_deleted`, `updated_at`),
  CONSTRAINT `fk_communication_message_states_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `composer_drafts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(30) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `content` LONGTEXT NOT NULL,
  `attachments_json` LONGTEXT,
  `tags_json` LONGTEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_composer_drafts_target` (`user_id`, `target_type`, `target_id`),
  KEY `idx_composer_drafts_owner` (`app_id`, `user_id`, `updated_at`),
  CONSTRAINT `fk_composer_drafts_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_avatar_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `avatar_url` VARCHAR(1000) NOT NULL,
  `sha256` CHAR(64) NOT NULL DEFAULT '',
  `is_current` TINYINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_avatar_history_url` (`user_id`, `avatar_url`(128)),
  KEY `idx_user_avatar_history_current` (`app_id`, `user_id`, `is_current`, `id`),
  CONSTRAINT `fk_user_avatar_history_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `content_favorites` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `content_type` VARCHAR(30) NOT NULL,
  `content_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_content_favorites_target` (`user_id`, `content_type`, `content_id`),
  KEY `idx_content_favorites_owner` (`app_id`, `user_id`, `content_type`, `id`),
  CONSTRAINT `fk_content_favorites_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_notice_text = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_recalls' AND COLUMN_NAME = 'notice_text'
);
SET @notice_sql = IF(@has_notice_text = 0,
  'ALTER TABLE `message_recalls` ADD COLUMN `notice_text` VARCHAR(200) NOT NULL DEFAULT '''' AFTER `reason`',
  'SELECT 1');
PREPARE notice_stmt FROM @notice_sql;
EXECUTE notice_stmt;
DEALLOCATE PREPARE notice_stmt;

SET @has_upload_fingerprint = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads' AND INDEX_NAME = 'idx_uploads_content_fingerprint'
);
SET @upload_index_sql = IF(@has_upload_fingerprint = 0,
  'ALTER TABLE `uploads` ADD KEY `idx_uploads_content_fingerprint` (`admin_id`, `app_id`, `sha256`, `size_bytes`, `status`)',
  'SELECT 1');
PREPARE upload_index_stmt FROM @upload_index_sql;
EXECUTE upload_index_stmt;
DEALLOCATE PREPARE upload_index_stmt;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('20260713_conversation_media_experience', '会话置顶、本地消息状态、编辑草稿、头像历史与上传内容指纹', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
