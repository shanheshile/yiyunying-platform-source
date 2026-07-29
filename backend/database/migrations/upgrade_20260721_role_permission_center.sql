-- 四级可视化权限中心：用户级功能覆盖。兼容 MySQL 5.7/8.0，可重复执行。
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `user_feature_permissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `feature_code` VARCHAR(64) NOT NULL,
  `enabled` TINYINT NOT NULL DEFAULT 1,
  `config_json` LONGTEXT,
  `updated_by_type` VARCHAR(20) NOT NULL DEFAULT 'admin',
  `updated_by_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_feature_permission` (`app_id`, `user_id`, `feature_code`),
  KEY `idx_user_feature_permission_tenant` (`admin_id`, `app_id`, `user_id`),
  CONSTRAINT `fk_user_feature_permission_user`
    FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
SELECT '2026.07.21-role-permission-center', '四级可视化权限中心与用户功能覆盖', NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `schema_migrations` WHERE `version` = '2026.07.21-role-permission-center'
);