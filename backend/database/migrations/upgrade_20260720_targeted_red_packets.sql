SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `red_packet_recipients` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `packet_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_red_packet_recipient` (`packet_id`, `user_id`),
  KEY `idx_red_packet_recipient_user` (`app_id`, `user_id`, `packet_id`),
  CONSTRAINT `fk_red_packet_recipient_packet` FOREIGN KEY (`packet_id`, `app_id`, `admin_id`) REFERENCES `red_packets` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_red_packet_recipient_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.20-targeted-red-packets', '红包指定领取人和领取范围校验', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
