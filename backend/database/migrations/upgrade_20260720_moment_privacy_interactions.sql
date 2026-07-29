-- 生活动态：全局/单条可见范围与互动闭环。
-- 可重复执行，适用于已安装的易运盈后台。

SET @schema_name = DATABASE();

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'dynamic_enabled'),
  'SELECT 1',
  'ALTER TABLE `user_message_preferences` ADD COLUMN `dynamic_enabled` TINYINT NOT NULL DEFAULT 1 AFTER `notification_vibration_enabled`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'dynamic_visible_days'),
  'SELECT 1',
  'ALTER TABLE `user_message_preferences` ADD COLUMN `dynamic_visible_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `dynamic_enabled`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'dynamic_visibility_mode'),
  'SELECT 1',
  'ALTER TABLE `user_message_preferences` ADD COLUMN `dynamic_visibility_mode` VARCHAR(20) NOT NULL DEFAULT ''public'' AFTER `dynamic_visible_days`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'dynamic_allow_user_ids_json'),
  'SELECT 1',
  'ALTER TABLE `user_message_preferences` ADD COLUMN `dynamic_allow_user_ids_json` LONGTEXT DEFAULT NULL AFTER `dynamic_visibility_mode`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'dynamic_deny_user_ids_json'),
  'SELECT 1',
  'ALTER TABLE `user_message_preferences` ADD COLUMN `dynamic_deny_user_ids_json` LONGTEXT DEFAULT NULL AFTER `dynamic_allow_user_ids_json`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'user_moments' AND COLUMN_NAME = 'visibility_mode'),
  'SELECT 1',
  'ALTER TABLE `user_moments` ADD COLUMN `visibility_mode` VARCHAR(20) NOT NULL DEFAULT ''inherit'' AFTER `longitude`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'user_moments' AND COLUMN_NAME = 'visible_days'),
  'SELECT 1',
  'ALTER TABLE `user_moments` ADD COLUMN `visible_days` SMALLINT UNSIGNED DEFAULT NULL AFTER `visibility_mode`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'user_moments' AND COLUMN_NAME = 'visibility_user_ids_json'),
  'SELECT 1',
  'ALTER TABLE `user_moments` ADD COLUMN `visibility_user_ids_json` LONGTEXT DEFAULT NULL AFTER `visible_days`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `moment_likes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `moment_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_moment_likes_user` (`moment_id`, `user_id`),
  KEY `idx_moment_likes_tenant` (`admin_id`, `app_id`, `user_id`, `id`),
  CONSTRAINT `fk_moment_likes_moment` FOREIGN KEY (`moment_id`) REFERENCES `user_moments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_moment_likes_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moment_comments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `moment_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `parent_id` BIGINT UNSIGNED DEFAULT NULL,
  `content` VARCHAR(2000) NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_moment_comments_feed` (`moment_id`, `status`, `id`),
  KEY `idx_moment_comments_user` (`app_id`, `user_id`, `id`),
  CONSTRAINT `fk_moment_comments_moment` FOREIGN KEY (`moment_id`) REFERENCES `user_moments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_moment_comments_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_moment_comments_parent` FOREIGN KEY (`parent_id`) REFERENCES `moment_comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moment_favorites` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `moment_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_moment_favorites_user` (`moment_id`, `user_id`),
  KEY `idx_moment_favorites_user` (`app_id`, `user_id`, `id`),
  CONSTRAINT `fk_moment_favorites_moment` FOREIGN KEY (`moment_id`) REFERENCES `user_moments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_moment_favorites_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moment_forwards` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `moment_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(20) NOT NULL DEFAULT 'external',
  `target_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_moment_forwards_moment` (`moment_id`, `id`),
  KEY `idx_moment_forwards_user` (`app_id`, `user_id`, `id`),
  CONSTRAINT `fk_moment_forwards_moment` FOREIGN KEY (`moment_id`) REFERENCES `user_moments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_moment_forwards_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.20-moment-privacy-interactions', '生活动态可见范围、指定人员与点赞评论收藏转发闭环', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
