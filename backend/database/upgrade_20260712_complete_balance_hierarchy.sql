-- 易运盈后台 2026-07-12 完整模块增量升级
-- 执行前请备份数据库。本脚本兼容 MySQL 5.7+/8.0 与 MariaDB 10.3+，可重复执行。
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS `yy_add_column`;
DELIMITER $$
CREATE PROCEDURE `yy_add_column`(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column
  ) THEN
    SET @yy_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
    PREPARE yy_stmt FROM @yy_sql;
    EXECUTE yy_stmt;
    DEALLOCATE PREPARE yy_stmt;
  END IF;
END$$
DELIMITER ;

CALL `yy_add_column`('platform_accounts', 'access_start_time', 'TIME DEFAULT NULL AFTER `integral`');
CALL `yy_add_column`('platform_accounts', 'access_end_time', 'TIME DEFAULT NULL AFTER `access_start_time`');
CALL `yy_add_column`('platform_accounts', 'allowed_weekdays', 'VARCHAR(20) NOT NULL DEFAULT ''1,2,3,4,5,6,7'' AFTER `access_end_time`');
CALL `yy_add_column`('notices', 'display_enabled', 'TINYINT NOT NULL DEFAULT 1 AFTER `is_popup`');
CALL `yy_add_column`('notices', 'popup_frequency', 'VARCHAR(20) NOT NULL DEFAULT ''once'' AFTER `display_enabled`');
CALL `yy_add_column`('notices', 'audience_type', 'VARCHAR(20) NOT NULL DEFAULT ''all'' AFTER `popup_frequency`');
CALL `yy_add_column`('notices', 'audience_json', 'LONGTEXT AFTER `audience_type`');
CALL `yy_add_column`('app_versions', 'min_supported_version_code', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `version_code`');
CALL `yy_add_column`('chat_room_votes', 'min_select', 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER `multiple_choice`');
CALL `yy_add_column`('chat_room_votes', 'max_select', 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER `min_select`');
CALL `yy_add_column`('chat_room_votes', 'allow_change', 'TINYINT NOT NULL DEFAULT 0 AFTER `max_select`');
DROP PROCEDURE IF EXISTS `yy_add_column`;
CREATE TABLE IF NOT EXISTS `user_asset_purchases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `order_no` VARCHAR(48) NOT NULL,
  `product_type` ENUM('document_credit','vip_days') NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `unit_price` DECIMAL(18,2) NOT NULL,
  `total_amount` DECIMAL(18,2) NOT NULL,
  `pay_asset` VARCHAR(40) NOT NULL DEFAULT 'balance',
  `status` ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
  `completed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_asset_purchases_no` (`order_no`),
  KEY `idx_user_asset_purchases_user` (`user_id`, `created_at`),
  KEY `idx_user_asset_purchases_tenant` (`admin_id`, `app_id`, `status`, `created_at`),
  CONSTRAINT `fk_user_asset_purchases_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_transfer_policies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `can_send` TINYINT NOT NULL DEFAULT 1,
  `can_receive` TINYINT NOT NULL DEFAULT 1,
  `single_limit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `daily_send_limit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `daily_receive_limit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `daily_pair_limit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `blocked_send_to_json` LONGTEXT,
  `blocked_receive_from_json` LONGTEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_transfer_policies_user` (`user_id`),
  KEY `idx_user_transfer_policies_tenant` (`admin_id`, `app_id`),
  CONSTRAINT `fk_user_transfer_policies_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hierarchy_activities` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `root_platform_id` BIGINT UNSIGNED NOT NULL,
  `owner_type` ENUM('platform','admin') NOT NULL,
  `owner_id` BIGINT UNSIGNED NOT NULL,
  `owner_level` TINYINT UNSIGNED NOT NULL,
  `owner_platform_id` BIGINT UNSIGNED NOT NULL,
  `owner_admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `activity_type` ENUM('red_packet','lottery','bounty') NOT NULL,
  `funding_mode` ENUM('balance','issued') NOT NULL DEFAULT 'balance',
  `title` VARCHAR(200) NOT NULL,
  `description` LONGTEXT,
  `packet_mode` ENUM('equal','random') DEFAULT NULL,
  `total_balance` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `remaining_balance` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `total_slots` INT UNSIGNED NOT NULL DEFAULT 1,
  `remaining_slots` INT UNSIGNED NOT NULL DEFAULT 1,
  `per_actor_limit` INT UNSIGNED NOT NULL DEFAULT 1,
  `rules_json` LONGTEXT,
  `status` ENUM('draft','active','completed','closed','cancelled') NOT NULL DEFAULT 'active',
  `starts_at` DATETIME DEFAULT NULL,
  `ends_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hierarchy_activities_feed` (`root_platform_id`, `status`, `activity_type`, `starts_at`, `ends_at`, `id`),
  KEY `idx_hierarchy_activities_owner` (`owner_type`, `owner_id`, `status`, `id`),
  KEY `idx_hierarchy_activities_branch` (`owner_platform_id`, `owner_admin_id`, `owner_level`, `status`),
  CONSTRAINT `fk_hierarchy_activities_root` FOREIGN KEY (`root_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hierarchy_activities_platform` FOREIGN KEY (`owner_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hierarchy_activities_admin` FOREIGN KEY (`owner_admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hierarchy_activity_targets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id` BIGINT UNSIGNED NOT NULL,
  `target_scope` ENUM('both','visibility','participation') NOT NULL DEFAULT 'both',
  `target_type` ENUM('level','platform','admin','app','actor') NOT NULL,
  `target_level` TINYINT UNSIGNED DEFAULT NULL,
  `target_id` BIGINT UNSIGNED DEFAULT NULL,
  `actor_type` VARCHAR(20) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hierarchy_activity_targets_match` (`activity_id`, `target_scope`, `target_type`, `target_level`, `target_id`, `actor_type`),
  CONSTRAINT `fk_hierarchy_activity_targets_activity` FOREIGN KEY (`activity_id`) REFERENCES `hierarchy_activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hierarchy_activity_prizes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `reward_balance` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `weight` INT UNSIGNED NOT NULL DEFAULT 1,
  `stock` INT UNSIGNED NOT NULL DEFAULT 1,
  `remaining_stock` INT UNSIGNED NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hierarchy_activity_prizes_draw` (`activity_id`, `remaining_stock`, `sort_order`, `id`),
  CONSTRAINT `fk_hierarchy_activity_prizes_activity` FOREIGN KEY (`activity_id`) REFERENCES `hierarchy_activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hierarchy_activity_entries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id` BIGINT UNSIGNED NOT NULL,
  `actor_type` ENUM('platform','admin','user') NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `actor_level` TINYINT UNSIGNED NOT NULL,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `entry_type` ENUM('claim','draw','award') NOT NULL,
  `prize_id` BIGINT UNSIGNED DEFAULT NULL,
  `reward_balance` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hierarchy_activity_entries_actor` (`activity_id`, `actor_type`, `actor_id`, `entry_type`, `id`),
  KEY `idx_hierarchy_activity_entries_time` (`platform_id`, `admin_id`, `app_id`, `created_at`),
  CONSTRAINT `fk_hierarchy_activity_entries_activity` FOREIGN KEY (`activity_id`) REFERENCES `hierarchy_activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hierarchy_activity_entries_prize` FOREIGN KEY (`prize_id`) REFERENCES `hierarchy_activity_prizes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hierarchy_activity_submissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id` BIGINT UNSIGNED NOT NULL,
  `actor_type` ENUM('platform','admin','user') NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `actor_level` TINYINT UNSIGNED NOT NULL,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `content` LONGTEXT NOT NULL,
  `attachments_json` LONGTEXT,
  `status` ENUM('submitted','accepted','rejected','cancelled') NOT NULL DEFAULT 'submitted',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_hierarchy_activity_submission_actor` (`activity_id`, `actor_type`, `actor_id`),
  KEY `idx_hierarchy_activity_submissions_status` (`activity_id`, `status`, `id`),
  CONSTRAINT `fk_hierarchy_activity_submissions_activity` FOREIGN KEY (`activity_id`) REFERENCES `hierarchy_activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hierarchy_balance_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `root_platform_id` BIGINT UNSIGNED NOT NULL,
  `actor_type` ENUM('platform','admin','user') NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `actor_level` TINYINT UNSIGNED NOT NULL,
  `change_value` DECIMAL(20,2) NOT NULL,
  `before_value` DECIMAL(20,2) NOT NULL,
  `after_value` DECIMAL(20,2) NOT NULL,
  `scene` VARCHAR(60) NOT NULL,
  `ref_type` VARCHAR(40) NOT NULL DEFAULT '',
  `ref_id` BIGINT UNSIGNED DEFAULT NULL,
  `operator_type` VARCHAR(20) NOT NULL DEFAULT '',
  `operator_id` BIGINT UNSIGNED DEFAULT NULL,
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hierarchy_balance_logs_actor` (`actor_type`, `actor_id`, `created_at`),
  KEY `idx_hierarchy_balance_logs_root` (`root_platform_id`, `scene`, `created_at`),
  CONSTRAINT `fk_hierarchy_balance_logs_root` FOREIGN KEY (`root_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS `yy_add_column`;
DELIMITER $$
CREATE PROCEDURE `yy_add_column`(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column
  ) THEN
    SET @yy_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
    PREPARE yy_stmt FROM @yy_sql;
    EXECUTE yy_stmt;
    DEALLOCATE PREPARE yy_stmt;
  END IF;
END$$
DELIMITER ;
CALL `yy_add_column`('hierarchy_activity_targets', 'target_scope', 'ENUM(''both'',''visibility'',''participation'') NOT NULL DEFAULT ''both'' AFTER `activity_id`');
DROP PROCEDURE IF EXISTS `yy_add_column`;
-- 补齐默认 2 级授权平台测试账号：authorized / 123456。
SET @root_platform_id = (SELECT `id` FROM `platform_accounts` WHERE `platform_key` = 'yiyunying-root' LIMIT 1);

INSERT INTO `platform_accounts`
  (`parent_id`, `created_by_platform_id`, `level`, `platform_key`, `account`, `password_hash`,
   `nickname`, `avatar`, `email`, `phone`, `status`, `membership_level`, `membership_started_at`,
   `membership_expired_at`, `admin_quota`, `integral`, `permissions_json`, `register_ip`, `created_at`, `updated_at`)
SELECT @root_platform_id, @root_platform_id, 2, 'yiyunying-authorized', 'authorized',
       'pbkdf2_sha256$120000$yiyunying-install-20260712$0F9tF7l3mI4vx8XBpcv6xSso9hiPS4rOdVShF+eMvUc=',
       '默认授权平台', '', NULL, NULL, 1, 'vip', NOW(), DATE_ADD(NOW(), INTERVAL 3650 DAY),
       10, 100, NULL, '127.0.0.1', NOW(), NOW()
FROM DUAL
WHERE @root_platform_id IS NOT NULL
ON DUPLICATE KEY UPDATE
  `parent_id` = @root_platform_id, `created_by_platform_id` = @root_platform_id,
  `level` = 2, `status` = 1, `membership_level` = 'vip',
  `membership_expired_at` = GREATEST(COALESCE(`membership_expired_at`, NOW()), DATE_ADD(NOW(), INTERVAL 3650 DAY)),
  `admin_quota` = GREATEST(`admin_quota`, 10), `deleted_at` = NULL, `updated_at` = NOW();

SET @authorized_platform_id = (SELECT `id` FROM `platform_accounts` WHERE `platform_key` = 'yiyunying-authorized' LIMIT 1);

INSERT INTO `platform_settings`
  (`platform_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT @authorized_platform_id, `setting_key`, `setting_value`, `value_type`, NOW(), NOW()
FROM `platform_settings`
WHERE `platform_id` = @root_platform_id AND @authorized_platform_id IS NOT NULL
ON DUPLICATE KEY UPDATE
  `setting_value` = VALUES(`setting_value`), `value_type` = VALUES(`value_type`), `updated_at` = NOW();

INSERT INTO `platform_exchange_products`
  (`platform_id`, `product_code`, `name`, `description`, `product_type`, `grant_json`,
   `price_integral`, `stock`, `sold_count`, `per_admin_limit`, `per_admin_daily_limit`,
   `status`, `sort_order`, `start_at`, `end_at`, `created_at`, `updated_at`, `deleted_at`)
SELECT @authorized_platform_id, `product_code`, `name`, `description`, `product_type`, `grant_json`,
       `price_integral`, `stock`, 0, `per_admin_limit`, `per_admin_daily_limit`,
       `status`, `sort_order`, `start_at`, `end_at`, NOW(), NOW(), NULL
FROM `platform_exchange_products`
WHERE `platform_id` = @root_platform_id AND @authorized_platform_id IS NOT NULL
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`), `product_type` = VALUES(`product_type`),
  `grant_json` = VALUES(`grant_json`), `price_integral` = VALUES(`price_integral`),
  `status` = VALUES(`status`), `sort_order` = VALUES(`sort_order`), `deleted_at` = NULL, `updated_at` = NOW();

-- 新平台规则。1 级与 2 级均可设置，但只有 1 级能够使用 issued 官方发放模式。
INSERT INTO `platform_settings` (`platform_id`,`setting_key`,`setting_value`,`value_type`,`created_at`,`updated_at`)
SELECT p.id, x.setting_key, x.setting_value, x.value_type, NOW(), NOW()
FROM `platform_accounts` p
CROSS JOIN (
  SELECT 'data_console_enabled' setting_key, '1' setting_value, 'bool' value_type
  UNION ALL SELECT 'balance_display_name','余额','string'
  UNION ALL SELECT 'authorized_platform_membership_required','1','bool'
  UNION ALL SELECT 'authorized_platform_vip_only','0','bool'
  UNION ALL SELECT 'admin_membership_required','1','bool'
  UNION ALL SELECT 'admin_vip_only','0','bool'
  UNION ALL SELECT 'admin_balance_purchase_enabled','1','bool'
  UNION ALL SELECT 'admin_document_purchase_enabled','1','bool'
  UNION ALL SELECT 'admin_membership_purchase_enabled','1','bool'
  UNION ALL SELECT 'admin_free_balance','15','int'
  UNION ALL SELECT 'balance_exchange_enabled','1','bool'
  UNION ALL SELECT 'balance_exchange_max_quantity_per_order','100','int'
  UNION ALL SELECT 'balance_exchange_admin_daily_limit','0','int'
  UNION ALL SELECT 'hierarchical_activities_enabled','1','bool'
  UNION ALL SELECT 'hierarchical_activity_max_budget','1000000000','int'
) x
WHERE 1 = 1
ON DUPLICATE KEY UPDATE `updated_at` = VALUES(`updated_at`);

INSERT INTO `platform_settings` (`platform_id`,`setting_key`,`setting_value`,`value_type`,`created_at`,`updated_at`)
SELECT `platform_id`, 'admin_free_balance', `setting_value`, `value_type`, NOW(), NOW()
FROM `platform_settings` WHERE `setting_key` = 'admin_free_integral'
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `value_type` = VALUES(`value_type`), `updated_at` = NOW();

INSERT INTO `platform_settings` (`platform_id`,`setting_key`,`setting_value`,`value_type`,`created_at`,`updated_at`)
SELECT `platform_id`,
  CASE `setting_key`
    WHEN 'point_exchange_enabled' THEN 'balance_exchange_enabled'
    WHEN 'point_exchange_max_quantity_per_order' THEN 'balance_exchange_max_quantity_per_order'
    WHEN 'point_exchange_admin_daily_integral_limit' THEN 'balance_exchange_admin_daily_limit'
  END,
  `setting_value`, `value_type`, NOW(), NOW()
FROM `platform_settings`
WHERE `setting_key` IN ('point_exchange_enabled','point_exchange_max_quantity_per_order','point_exchange_admin_daily_integral_limit')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `value_type` = VALUES(`value_type`), `updated_at` = NOW();

-- 新应用经济、会员与互动规则。文档额度与余额默认互相独立，购买默认关闭，由 admin 主动开启。
INSERT INTO `app_settings` (`admin_id`,`app_id`,`setting_key`,`setting_value`,`value_type`,`created_at`,`updated_at`)
SELECT a.admin_id, a.id, x.setting_key, x.setting_value, x.value_type, NOW(), NOW()
FROM `apps` a
CROSS JOIN (
  SELECT 'economy_primary_asset' setting_key, 'balance' setting_value, 'string' value_type
  UNION ALL SELECT 'user_initial_balance','0','float'
  UNION ALL SELECT 'user_initial_activity_credit','0','int'
  UNION ALL SELECT 'user_free_vip_days','0','int'
  UNION ALL SELECT 'user_login_vip_only','0','bool'
  UNION ALL SELECT 'document_credit_separate','1','bool'
  UNION ALL SELECT 'balance_document_purchase_enabled','0','bool'
  UNION ALL SELECT 'document_credit_balance_price','1','float'
  UNION ALL SELECT 'balance_membership_purchase_enabled','0','bool'
  UNION ALL SELECT 'vip_day_balance_price','1','float'
  UNION ALL SELECT 'balance_activity_enabled','1','bool'
  UNION ALL SELECT 'sign_reward_balance','10','int'
  UNION ALL SELECT 'invite_reward_balance','20','int'
  UNION ALL SELECT 'bounty_min_reward_balance','1','int'
  UNION ALL SELECT 'bounty_max_reward_balance','1000000','int'
) x
WHERE 1 = 1
ON DUPLICATE KEY UPDATE `updated_at` = VALUES(`updated_at`);

INSERT INTO `app_settings` (`admin_id`,`app_id`,`setting_key`,`setting_value`,`value_type`,`created_at`,`updated_at`)
SELECT `admin_id`, `app_id`,
  CASE `setting_key`
    WHEN 'sign_reward_integral' THEN 'sign_reward_balance'
    WHEN 'invite_reward_integral' THEN 'invite_reward_balance'
    WHEN 'bounty_min_reward_integral' THEN 'bounty_min_reward_balance'
    WHEN 'bounty_max_reward_integral' THEN 'bounty_max_reward_balance'
  END,
  `setting_value`, `value_type`, NOW(), NOW()
FROM `app_settings`
WHERE `setting_key` IN ('sign_reward_integral','invite_reward_integral','bounty_min_reward_integral','bounty_max_reward_integral')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `value_type` = VALUES(`value_type`), `updated_at` = NOW();

INSERT INTO `app_feature_flags` (`admin_id`,`app_id`,`feature_code`,`enabled`,`config_json`,`created_at`,`updated_at`)
SELECT a.admin_id, a.id, f.feature_code, 1, NULL, NOW(), NOW()
FROM `apps` a
CROSS JOIN (
  SELECT 'balance_document_purchase' feature_code
  UNION ALL SELECT 'balance_membership_purchase'
  UNION ALL SELECT 'hierarchical_activities'
) f
WHERE 1 = 1
ON DUPLICATE KEY UPDATE `updated_at` = VALUES(`updated_at`);

INSERT INTO `schema_migrations` (`version`,`description`,`applied_at`)
VALUES ('20260712_complete_balance_hierarchy', '余额体系、层级活动、转账策略、公告更新与群工具', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

SET FOREIGN_KEY_CHECKS = 1;