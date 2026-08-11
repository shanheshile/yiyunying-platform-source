<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$install = (string) file_get_contents($root . '/database/install.sql');
$tables = [
    'user_asset_purchases',
    'user_transfer_policies',
    'hierarchy_activities',
    'hierarchy_activity_targets',
    'hierarchy_activity_prizes',
    'hierarchy_activity_entries',
    'hierarchy_activity_submissions',
    'hierarchy_balance_logs',
];

$sql = <<<'SQL'
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

SQL;

foreach ($tables as $table) {
    $pattern = '/CREATE TABLE IF NOT EXISTS `' . preg_quote($table, '/') . '`\s*\(.*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/s';
    if (preg_match($pattern, $install, $match) !== 1) {
        throw new RuntimeException('Cannot find table definition: ' . $table);
    }
    $sql .= $match[0] . "\n\n";
}

$sql .= <<<'SQL'
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

SQL;

$sql .= <<<'SQL'
-- 安全边界：增量升级只更新已有租户结构和设置，不创建、启用或重置任何平台、管理员、应用或用户身份。
-- 新的 2 级授权平台必须由已认证的 1 级平台通过业务接口创建，并在创建时设置独立强密码。

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
SQL;

$path = $root . '/database/upgrade_20260712_complete_balance_hierarchy.sql';
file_put_contents($path, $sql);
echo 'Generated ' . basename($path) . ' (' . count($tables) . " new tables)\n";
