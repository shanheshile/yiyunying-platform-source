-- 红包接收人主动退回：接收人可将自己的未领取份额退回发送人。
-- 可重复执行，适用于已安装的易运盈后台。

CREATE TABLE IF NOT EXISTS `red_packet_returns` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `packet_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `amount` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_red_packet_return_user` (`packet_id`, `user_id`),
  KEY `idx_red_packet_return_user` (`app_id`, `user_id`, `packet_id`),
  CONSTRAINT `fk_red_packet_return_packet` FOREIGN KEY (`packet_id`, `app_id`, `admin_id`) REFERENCES `red_packets` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_red_packet_return_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.20-red-packet-recipient-returns', '红包接收人主动退回及资金流水闭环', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
