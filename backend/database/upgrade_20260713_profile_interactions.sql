SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `user_profile_likes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `liker_user_id` BIGINT UNSIGNED NOT NULL,
  `target_user_id` BIGINT UNSIGNED NOT NULL,
  `like_date` DATE NOT NULL,
  `like_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_profile_likes_daily` (`app_id`, `liker_user_id`, `target_user_id`, `like_date`),
  KEY `idx_user_profile_likes_target` (`app_id`, `target_user_id`, `id`),
  CONSTRAINT `fk_user_profile_likes_liker` FOREIGN KEY (`liker_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_profile_likes_target` FOREIGN KEY (`target_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('20260713_profile_interactions', '用户11位公开号、主页点赞和公开动态', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
