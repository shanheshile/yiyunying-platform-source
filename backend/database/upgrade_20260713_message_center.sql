-- 易运盈后台 2026-07-13 消息中心、陌生人隐私与总控权益升级
-- 兼容 MySQL 5.7+/8.0 与 MariaDB 10.3+，可重复执行。
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `user_message_preferences` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `accept_stranger_messages` TINYINT NOT NULL DEFAULT 1,
  `system_notification_enabled` TINYINT NOT NULL DEFAULT 1,
  `private_notification_enabled` TINYINT NOT NULL DEFAULT 1,
  `group_notification_enabled` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_message_preferences_user` (`user_id`),
  KEY `idx_user_message_preferences_tenant` (`admin_id`, `app_id`),
  CONSTRAINT `fk_user_message_preferences_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `platform_settings`
  (`platform_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT p.id, defaults.setting_key, defaults.setting_value, defaults.value_type, NOW(), NOW()
FROM `platform_accounts` p
CROSS JOIN (
  SELECT 'operator_free_trial_days' AS setting_key, '3' AS setting_value, 'int' AS value_type
  UNION ALL SELECT 'operator_free_admin_quota', '10', 'int'
  UNION ALL SELECT 'operator_free_balance', '15', 'int'
) defaults
WHERE 1 = 1
ON DUPLICATE KEY UPDATE `updated_at` = VALUES(`updated_at`);

INSERT INTO `app_settings`
  (`admin_id`, `app_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT a.admin_id, a.id, 'accept_stranger_messages_default', '1', 'bool', NOW(), NOW()
FROM `apps` a
WHERE 1 = 1
ON DUPLICATE KEY UPDATE `updated_at` = VALUES(`updated_at`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('20260713_message_center', '统一消息中心、陌生人消息偏好、L1 无限权益与平台赠送规则', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
