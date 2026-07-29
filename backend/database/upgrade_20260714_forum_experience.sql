-- 易运盈后台 2026-07-14 论坛分节、热度、个人排序与会话置底升级
-- 兼容 MySQL 5.7+/8.0 与 MariaDB 10.3+，可重复执行。
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_posts' AND COLUMN_NAME = 'unique_view_count'),
  'SELECT 1', 'ALTER TABLE `forum_posts` ADD COLUMN `unique_view_count` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `view_count`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_posts' AND COLUMN_NAME = 'heat_score'),
  'SELECT 1', 'ALTER TABLE `forum_posts` ADD COLUMN `heat_score` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `comment_count`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_posts' AND COLUMN_NAME = 'hot_label'),
  'SELECT 1', 'ALTER TABLE `forum_posts` ADD COLUMN `hot_label` VARCHAR(40) NOT NULL DEFAULT '''' AFTER `heat_score`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_posts' AND COLUMN_NAME = 'last_activity_at'),
  'SELECT 1', 'ALTER TABLE `forum_posts` ADD COLUMN `last_activity_at` DATETIME NULL AFTER `hot_label`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_comments' AND COLUMN_NAME = 'tags_json'),
  'SELECT 1', 'ALTER TABLE `forum_comments` ADD COLUMN `tags_json` LONGTEXT NULL AFTER `content`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_comments' AND COLUMN_NAME = 'is_pinned'),
  'SELECT 1', 'ALTER TABLE `forum_comments` ADD COLUMN `is_pinned` TINYINT NOT NULL DEFAULT 0 AFTER `tags_json`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_comments' AND COLUMN_NAME = 'pin_order'),
  'SELECT 1', 'ALTER TABLE `forum_comments` ADD COLUMN `pin_order` INT NOT NULL DEFAULT 0 AFTER `is_pinned`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_comments' AND COLUMN_NAME = 'like_count'),
  'SELECT 1', 'ALTER TABLE `forum_comments` ADD COLUMN `like_count` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `pin_order`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_comments' AND COLUMN_NAME = 'favorite_count'),
  'SELECT 1', 'ALTER TABLE `forum_comments` ADD COLUMN `favorite_count` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `like_count`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversation_preferences' AND COLUMN_NAME = 'is_bottomed'),
  'SELECT 1', 'ALTER TABLE `conversation_preferences` ADD COLUMN `is_bottomed` TINYINT NOT NULL DEFAULT 0 AFTER `is_pinned`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `forum_unique_views` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `viewer_key` CHAR(64) NOT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `view_count` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `first_viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_unique_views` (`post_id`, `viewer_key`),
  KEY `idx_forum_unique_views_app` (`app_id`, `post_id`, `last_viewed_at`),
  CONSTRAINT `fk_forum_unique_views_post` FOREIGN KEY (`post_id`) REFERENCES `forum_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_unique_views_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_post_sections` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `author_user_id` BIGINT UNSIGNED NOT NULL,
  `section_type` VARCHAR(20) NOT NULL DEFAULT 'free',
  `title` VARCHAR(160) NOT NULL DEFAULT '',
  `content` LONGTEXT NOT NULL,
  `tags_json` LONGTEXT NULL,
  `price_balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_forum_post_sections_order` (`post_id`, `sort_order`),
  KEY `idx_forum_post_sections_app` (`app_id`, `post_id`, `status`, `sort_order`),
  CONSTRAINT `fk_forum_post_sections_post` FOREIGN KEY (`post_id`) REFERENCES `forum_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_post_sections_author` FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_section_purchases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `section_id` BIGINT UNSIGNED NOT NULL,
  `buyer_user_id` BIGINT UNSIGNED NOT NULL,
  `seller_user_id` BIGINT UNSIGNED NOT NULL,
  `price_balance` DECIMAL(18,2) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_section_purchase` (`section_id`, `buyer_user_id`),
  KEY `idx_forum_section_purchases_buyer` (`app_id`, `buyer_user_id`, `id`),
  CONSTRAINT `fk_forum_section_purchases_section` FOREIGN KEY (`section_id`) REFERENCES `forum_post_sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_section_purchases_buyer` FOREIGN KEY (`buyer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_section_purchases_seller` FOREIGN KEY (`seller_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_content_favorites` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_content_favorites` (`app_id`, `user_id`, `target_type`, `target_id`),
  KEY `idx_forum_content_favorites_target` (`app_id`, `target_type`, `target_id`),
  CONSTRAINT `fk_forum_content_favorites_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_personal_positions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `position` VARCHAR(20) NOT NULL DEFAULT 'top',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_personal_positions` (`user_id`, `target_type`, `target_id`),
  KEY `idx_forum_personal_positions_order` (`app_id`, `user_id`, `target_type`, `position`, `sort_order`),
  CONSTRAINT `fk_forum_personal_positions_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_content_forwards` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `destination_type` VARCHAR(20) NOT NULL,
  `destination_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_forum_content_forwards_target` (`app_id`, `target_type`, `target_id`, `id`),
  KEY `idx_forum_content_forwards_user` (`user_id`, `id`),
  CONSTRAINT `fk_forum_content_forwards_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `forum_posts`
SET `unique_view_count` = `view_count`,
    `heat_score` = `view_count` + (`like_count` * 4) + (`comment_count` * 6),
    `last_activity_at` = COALESCE(`updated_at`, `created_at`)
WHERE `unique_view_count` = 0;

INSERT INTO `app_settings`
  (`admin_id`, `app_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT a.admin_id, a.id, defaults.setting_key, defaults.setting_value, defaults.value_type, NOW(), NOW()
FROM apps a
INNER JOIN (
  SELECT 'forum_hot_enabled' AS setting_key, '1' AS setting_value, 'bool' AS value_type
  UNION ALL SELECT 'forum_hot_score_threshold', '40', 'int'
  UNION ALL SELECT 'forum_hot_window_days', '14', 'int'
  UNION ALL SELECT 'forum_self_post_pin_limit', '5', 'int'
  UNION ALL SELECT 'forum_self_comment_pin_limit', '3', 'int'
  UNION ALL SELECT 'forum_personal_plate_pin_limit', '20', 'int'
  UNION ALL SELECT 'forum_personal_post_pin_limit', '20', 'int'
  UNION ALL SELECT 'forum_paid_section_max_count', '30', 'int'
) defaults
WHERE a.deleted_at IS NULL
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('20260714_forum_experience', '论坛分节付费、唯一访客、热度排序、评论互动、个人置顶置底和会话置底', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

SET FOREIGN_KEY_CHECKS = 1;
