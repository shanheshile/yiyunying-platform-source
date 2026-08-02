-- Rich moment comments: sticker replies and independently likeable comments.
SET NAMES utf8mb4;
SET @schema_name = DATABASE();

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'moment_comments'
          AND COLUMN_NAME = 'sticker_id'
    ),
    'SELECT 1',
    'ALTER TABLE moment_comments ADD COLUMN sticker_id BIGINT UNSIGNED NULL AFTER parent_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = @schema_name
          AND TABLE_NAME = 'moment_comments'
          AND CONSTRAINT_NAME = 'fk_moment_comments_sticker'
    ),
    'SELECT 1',
    'ALTER TABLE moment_comments ADD CONSTRAINT fk_moment_comments_sticker FOREIGN KEY (sticker_id) REFERENCES stickers(id) ON DELETE SET NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `moment_comment_likes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `comment_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_moment_comment_likes_user` (`comment_id`, `user_id`),
  KEY `idx_moment_comment_likes_tenant` (`admin_id`, `app_id`, `user_id`, `id`),
  CONSTRAINT `fk_moment_comment_likes_comment` FOREIGN KEY (`comment_id`) REFERENCES `moment_comments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_moment_comment_likes_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (`version`, `description`, `applied_at`)
VALUES ('2026.08.02-moment-comment-interactions', 'Sticker and like support for moment comments', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
