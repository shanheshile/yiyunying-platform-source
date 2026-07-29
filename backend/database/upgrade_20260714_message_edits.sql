SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `message_edit_histories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `channel_type` VARCHAR(20) NOT NULL,
  `channel_id` BIGINT UNSIGNED NOT NULL,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `editor_user_id` BIGINT UNSIGNED NOT NULL,
  `old_content` LONGTEXT NOT NULL,
  `new_content` LONGTEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_message_edit_histories_message` (`app_id`, `channel_type`, `message_id`, `id`),
  KEY `idx_message_edit_histories_channel` (`app_id`, `channel_type`, `channel_id`, `created_at`),
  KEY `idx_message_edit_histories_editor` (`editor_user_id`, `created_at`),
  CONSTRAINT `fk_message_edit_histories_editor` FOREIGN KEY (`editor_user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
