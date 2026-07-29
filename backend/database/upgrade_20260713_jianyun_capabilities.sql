-- 简云能力取长补短：访问统计、用户在线、登录卡密、论坛审核与群恢复
-- 兼容 MySQL 5.7+/8.0 与 MariaDB 10.3+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `app_visit_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `visitor_hash` CHAR(64) NOT NULL,
  `visit_date` DATE NOT NULL,
  `visit_count` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `source` VARCHAR(50) NOT NULL DEFAULT 'app',
  `last_path` VARCHAR(255) NOT NULL DEFAULT '',
  `last_ip_hash` CHAR(64) NOT NULL DEFAULT '',
  `last_user_agent` VARCHAR(500) NOT NULL DEFAULT '',
  `first_visited_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_visited_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_visit_daily_visitor` (`app_id`, `visit_date`, `visitor_hash`),
  KEY `idx_app_visit_tenant_time` (`admin_id`, `app_id`, `last_visited_at`),
  CONSTRAINT `fk_app_visit_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_presence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'online',
  `device` VARCHAR(100) NOT NULL DEFAULT '',
  `last_ip_hash` CHAR(64) NOT NULL DEFAULT '',
  `last_heartbeat_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `online_until` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_presence_user` (`user_id`),
  KEY `idx_user_presence_online` (`app_id`, `online_until`, `status`),
  CONSTRAINT `fk_user_presence_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `card_login_bindings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `card_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `device_hash` CHAR(64) NOT NULL,
  `device_secret_hash` CHAR(64) NOT NULL,
  `device_label` VARCHAR(100) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `bound_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` DATETIME DEFAULT NULL,
  `expired_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_card_login_binding_card` (`card_id`),
  UNIQUE KEY `uk_card_login_binding_device` (`app_id`, `device_hash`),
  KEY `idx_card_login_binding_user` (`app_id`, `user_id`, `status`),
  CONSTRAINT `fk_card_login_binding_card` FOREIGN KEY (`card_id`, `app_id`, `admin_id`) REFERENCES `cards` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_card_login_binding_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_report_tags` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_report_tag_name` (`app_id`, `name`),
  KEY `idx_forum_report_tag_tenant` (`admin_id`, `app_id`, `status`, `sort_order`),
  CONSTRAINT `fk_forum_report_tag_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MySQL 5.7 没有 ADD COLUMN IF NOT EXISTS，逐列使用 information_schema 判断。
SET @has_column = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'statistics_daily' AND COLUMN_NAME = 'app_views');
SET @sql = IF(@has_column = 0, 'ALTER TABLE `statistics_daily` ADD COLUMN `app_views` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `api_requests`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'statistics_daily' AND COLUMN_NAME = 'unique_visitors');
SET @sql = IF(@has_column = 0, 'ALTER TABLE `statistics_daily` ADD COLUMN `unique_visitors` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `app_views`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'statistics_daily' AND COLUMN_NAME = 'heartbeat_count');
SET @sql = IF(@has_column = 0, 'ALTER TABLE `statistics_daily` ADD COLUMN `heartbeat_count` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `unique_visitors`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_posts' AND COLUMN_NAME = 'audit_reason');
SET @sql = IF(@has_column = 0, 'ALTER TABLE `forum_posts` ADD COLUMN `audit_reason` VARCHAR(500) NOT NULL DEFAULT '''' AFTER `audit_status`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_posts' AND COLUMN_NAME = 'audited_by');
SET @sql = IF(@has_column = 0, 'ALTER TABLE `forum_posts` ADD COLUMN `audited_by` BIGINT UNSIGNED NULL AFTER `audit_reason`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_posts' AND COLUMN_NAME = 'audited_at');
SET @sql = IF(@has_column = 0, 'ALTER TABLE `forum_posts` ADD COLUMN `audited_at` DATETIME NULL AFTER `audited_by`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_comments' AND COLUMN_NAME = 'audit_status');
SET @sql = IF(@has_column = 0, 'ALTER TABLE `forum_comments` ADD COLUMN `audit_status` VARCHAR(20) NOT NULL DEFAULT ''approved'' AFTER `content`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_comments' AND COLUMN_NAME = 'audit_reason');
SET @sql = IF(@has_column = 0, 'ALTER TABLE `forum_comments` ADD COLUMN `audit_reason` VARCHAR(500) NOT NULL DEFAULT '''' AFTER `audit_status`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_comments' AND COLUMN_NAME = 'audited_by');
SET @sql = IF(@has_column = 0, 'ALTER TABLE `forum_comments` ADD COLUMN `audited_by` BIGINT UNSIGNED NULL AFTER `audit_reason`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_comments' AND COLUMN_NAME = 'audited_at');
SET @sql = IF(@has_column = 0, 'ALTER TABLE `forum_comments` ADD COLUMN `audited_at` DATETIME NULL AFTER `audited_by`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_reports' AND COLUMN_NAME = 'report_tag_id');
SET @sql = IF(@has_column = 0, 'ALTER TABLE `forum_reports` ADD COLUMN `report_tag_id` BIGINT UNSIGNED NULL AFTER `user_id`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_rooms' AND COLUMN_NAME = 'dissolved_at');
SET @sql = IF(@has_column = 0, 'ALTER TABLE `chat_rooms` ADD COLUMN `dissolved_at` DATETIME NULL AFTER `status`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @has_column = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_rooms' AND COLUMN_NAME = 'restore_until');
SET @sql = IF(@has_column = 0, 'ALTER TABLE `chat_rooms` ADD COLUMN `restore_until` DATETIME NULL AFTER `dissolved_at`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `app_settings`
  (`admin_id`, `app_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT a.admin_id, a.id, defaults.setting_key, defaults.setting_value, defaults.value_type, NOW(), NOW()
FROM apps a
INNER JOIN (
  SELECT 'card_login_enabled' AS setting_key, '1' AS setting_value, 'bool' AS value_type
  UNION ALL SELECT 'public_app_statistics_enabled', '1', 'bool'
  UNION ALL SELECT 'heartbeat_online_seconds', '180', 'int'
  UNION ALL SELECT 'forum_comment_audit', '0', 'bool'
  UNION ALL SELECT 'group_restore_days', '7', 'int'
) defaults
WHERE a.deleted_at IS NULL
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT IGNORE INTO `forum_report_tags`
  (`admin_id`, `app_id`, `name`, `description`, `sort_order`, `status`, `created_at`, `updated_at`)
SELECT a.admin_id, a.id, defaults.name, defaults.description, defaults.sort_order, 1, NOW(), NOW()
FROM apps a
INNER JOIN (
  SELECT '广告营销' AS name, '广告、引流或营销内容' AS description, 40 AS sort_order
  UNION ALL SELECT '违法违规', '涉嫌违法或违反平台规则', 30
  UNION ALL SELECT '人身攻击', '侮辱、骚扰或人身攻击', 20
  UNION ALL SELECT '其他问题', '不属于以上分类的问题', 10
) defaults
WHERE a.deleted_at IS NULL;

INSERT INTO `schema_migrations` (`version`, `description`)
VALUES ('20260713_jianyun_capabilities', '访问统计、在线心跳、登录卡密、论坛审核举报与群恢复')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

SET FOREIGN_KEY_CHECKS = 1;
