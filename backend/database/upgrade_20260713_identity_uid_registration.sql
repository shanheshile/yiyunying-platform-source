-- 统一 UID、注册联系方式策略、验证码与逐级解绑审核
-- 兼容 MySQL 5.7+/8.0 与 MariaDB 10.3+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @has_users_uid = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'uid'
);
SET @sql = IF(
  @has_users_uid = 0,
  'ALTER TABLE `users` ADD COLUMN `uid` VARCHAR(32) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `users`
SET `uid` = CAST(10000000000 + `id` AS CHAR)
WHERE `uid` IS NULL OR `uid` = '';

SET @has_users_uid_unique = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uk_users_uid'
);
SET @sql = IF(
  @has_users_uid_unique = 0,
  'ALTER TABLE `users` ADD UNIQUE KEY `uk_users_uid` (`uid`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `identity_bindings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject_type` VARCHAR(20) NOT NULL,
  `subject_id` BIGINT UNSIGNED NOT NULL,
  `platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `identity_type` VARCHAR(20) NOT NULL,
  `identity_value` VARCHAR(190) NOT NULL,
  `identity_hash` CHAR(64) NOT NULL,
  `verified_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_identity_bindings_value` (`identity_type`, `identity_hash`),
  UNIQUE KEY `uk_identity_bindings_subject` (`subject_type`, `subject_id`, `identity_type`),
  KEY `idx_identity_bindings_tenant` (`platform_id`, `admin_id`, `app_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `identity_unbind_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject_type` VARCHAR(20) NOT NULL,
  `subject_id` BIGINT UNSIGNED NOT NULL,
  `platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `identity_type` VARCHAR(20) NOT NULL,
  `identity_value` VARCHAR(190) NOT NULL,
  `reviewer_type` VARCHAR(20) NOT NULL,
  `reviewer_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `reason` VARCHAR(500) NOT NULL DEFAULT '',
  `review_remark` VARCHAR(500) NOT NULL DEFAULT '',
  `reviewed_by_type` VARCHAR(20) DEFAULT NULL,
  `reviewed_by_id` BIGINT UNSIGNED DEFAULT NULL,
  `review_mode` VARCHAR(20) DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_identity_unbind_reviewer` (`reviewer_type`, `reviewer_id`, `status`, `id`),
  KEY `idx_identity_unbind_subject` (`subject_type`, `subject_id`, `status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 现有联系方式只会绑定第一个有效主体；如果历史数据重复，后续必须先审核解绑再重新绑定。
INSERT IGNORE INTO `identity_bindings`
  (`subject_type`, `subject_id`, `platform_id`, `admin_id`, `app_id`, `identity_type`, `identity_value`, `identity_hash`, `verified_at`, `created_at`, `updated_at`)
SELECT 'user', u.id, a.platform_id, u.admin_id, u.app_id, 'email', LOWER(TRIM(u.email)),
       SHA2(CONCAT('email:', LOWER(TRIM(u.email))), 256), u.created_at, NOW(), NOW()
FROM users u INNER JOIN admins a ON a.id = u.admin_id
WHERE u.email IS NOT NULL AND TRIM(u.email) <> '' AND u.deleted_at IS NULL;

INSERT IGNORE INTO `identity_bindings`
  (`subject_type`, `subject_id`, `platform_id`, `admin_id`, `app_id`, `identity_type`, `identity_value`, `identity_hash`, `verified_at`, `created_at`, `updated_at`)
SELECT 'user', u.id, a.platform_id, u.admin_id, u.app_id, 'phone', REPLACE(REPLACE(REPLACE(TRIM(u.phone), ' ', ''), '-', ''), '(', ''),
       SHA2(CONCAT('phone:', REPLACE(REPLACE(REPLACE(TRIM(u.phone), ' ', ''), '-', ''), '(', '')), 256), u.created_at, NOW(), NOW()
FROM users u INNER JOIN admins a ON a.id = u.admin_id
WHERE u.phone IS NOT NULL AND TRIM(u.phone) <> '' AND u.deleted_at IS NULL;

INSERT IGNORE INTO `identity_bindings`
  (`subject_type`, `subject_id`, `platform_id`, `admin_id`, `identity_type`, `identity_value`, `identity_hash`, `verified_at`, `created_at`, `updated_at`)
SELECT 'admin', a.id, a.platform_id, a.id, 'email', LOWER(TRIM(a.email)),
       SHA2(CONCAT('email:', LOWER(TRIM(a.email))), 256), a.created_at, NOW(), NOW()
FROM admins a WHERE a.email IS NOT NULL AND TRIM(a.email) <> '';

INSERT IGNORE INTO `identity_bindings`
  (`subject_type`, `subject_id`, `platform_id`, `identity_type`, `identity_value`, `identity_hash`, `verified_at`, `created_at`, `updated_at`)
SELECT 'platform', p.id, p.id, 'email', LOWER(TRIM(p.email)),
       SHA2(CONCAT('email:', LOWER(TRIM(p.email))), 256), p.created_at, NOW(), NOW()
FROM platform_accounts p WHERE p.email IS NOT NULL AND TRIM(p.email) <> '' AND p.deleted_at IS NULL;

INSERT INTO `app_settings`
  (`admin_id`, `app_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT a.admin_id, a.id, defaults.setting_key, defaults.setting_value, defaults.value_type, NOW(), NOW()
FROM apps a
INNER JOIN (
  SELECT 'registration_nickname_enabled' AS setting_key, '1' AS setting_value, 'bool' AS value_type
  UNION ALL SELECT 'registration_nickname_required', '1', 'bool'
  UNION ALL SELECT 'registration_email_enabled', '0', 'bool'
  UNION ALL SELECT 'registration_email_required', '0', 'bool'
  UNION ALL SELECT 'registration_phone_enabled', '0', 'bool'
  UNION ALL SELECT 'registration_phone_required', '0', 'bool'
  UNION ALL SELECT 'identity_unbind_enabled', '1', 'bool'
) defaults
WHERE a.deleted_at IS NULL
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO `schema_migrations` (`version`, `description`)
VALUES ('20260713_identity_uid_registration', '统一 UID、联系方式唯一绑定、注册验证与逐级解绑审核')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

SET FOREIGN_KEY_CHECKS = 1;
