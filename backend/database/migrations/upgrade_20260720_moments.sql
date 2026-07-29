CREATE TABLE IF NOT EXISTS `user_moments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `content` TEXT NOT NULL,
  `location_name` VARCHAR(200) NOT NULL DEFAULT '',
  `latitude` DECIMAL(10,7) DEFAULT NULL,
  `longitude` DECIMAL(10,7) DEFAULT NULL,
  `edited_at` DATETIME DEFAULT NULL,
  `deleted_at` DATETIME DEFAULT NULL,
  `delete_expires_at` DATETIME DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_moments_feed` (`admin_id`, `app_id`, `status`, `deleted_at`, `created_at`),
  KEY `idx_user_moments_owner` (`user_id`, `created_at`),
  KEY `idx_user_moments_purge` (`app_id`, `delete_expires_at`),
  CONSTRAINT `fk_user_moments_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.20-moments', '生活动态、九宫格媒体、附近位置和两分钟编辑删除恢复窗口', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
