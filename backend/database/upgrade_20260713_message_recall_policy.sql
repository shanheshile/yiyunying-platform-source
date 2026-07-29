-- 易运盈后台 2026-07-13 消息撤回分层策略升级
-- 兼容 MySQL 5.7+/8.0 与 MariaDB 10.3+，可重复执行。
SET NAMES utf8mb4;

INSERT INTO `platform_settings`
  (`platform_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT p.id, defaults.setting_key, defaults.setting_value, defaults.value_type, NOW(), NOW()
FROM `platform_accounts` p
CROSS JOIN (
  SELECT 'default_message_recall_seconds' AS setting_key, '120' AS setting_value, 'int' AS value_type
  UNION ALL SELECT 'force_message_recall_seconds', '0', 'bool'
  UNION ALL SELECT 'allow_child_message_recall_override', '1', 'bool'
  UNION ALL SELECT 'message_recall_inherit', '1', 'bool'
) defaults
WHERE p.deleted_at IS NULL
ON DUPLICATE KEY UPDATE `updated_at` = VALUES(`updated_at`);

INSERT INTO `app_settings`
  (`admin_id`, `app_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT a.admin_id, a.id, 'message_recall_inherit', '1', 'bool', NOW(), NOW()
FROM `apps` a
WHERE a.deleted_at IS NULL
ON DUPLICATE KEY UPDATE `updated_at` = VALUES(`updated_at`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('20260713_message_recall_policy', '私聊与群聊限时撤回、客服不可撤回、三级继承与强制同步规则', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
