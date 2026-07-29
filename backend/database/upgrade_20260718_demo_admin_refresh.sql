SET NAMES utf8mb4;
START TRANSACTION;

SET @demo_admin_id = (
  SELECT a.`id`
  FROM `admins` a
  INNER JOIN `platform_accounts` p ON p.`id` = a.`platform_id`
  WHERE p.`level` = 1 AND a.`account` = 'admin' AND a.`status` = 1
  ORDER BY a.`id` ASC
  LIMIT 1
);

UPDATE `admin_entitlements`
SET `membership_level` = IF(`membership_level` = '', 'trial', `membership_level`),
    `membership_status` = 'active',
    `membership_expired_at` = GREATEST(`membership_expired_at`, DATE_ADD(NOW(), INTERVAL 3 DAY)),
    `app_quota` = GREATEST(`app_quota`, 2),
    `remote_document_quota` = GREATEST(`remote_document_quota`, 3),
    `integral` = GREATEST(`integral`, 15),
    `updated_at` = NOW()
WHERE `admin_id` = @demo_admin_id;

INSERT INTO `admin_entitlement_logs`
  (`platform_id`, `admin_id`, `actor_platform_id`, `change_type`, `before_json`, `change_json`,
   `after_json`, `remark`, `created_at`)
SELECT a.`platform_id`, a.`id`, a.`platform_id`, 'deployment_test_refresh', NULL,
       '{"vip_days":3,"minimum_app_quota":2,"minimum_remote_document_quota":3,"minimum_balance":15}',
       NULL, '部署后恢复默认测试管理员的三天测试资格', NOW()
FROM `admins` a
WHERE a.`id` = @demo_admin_id
  AND NOT EXISTS (
    SELECT 1 FROM `admin_entitlement_logs` l
    WHERE l.`admin_id` = a.`id` AND l.`change_type` = 'deployment_test_refresh'
  );

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.18-demo-admin-refresh', '恢复默认测试管理员三天测试资格并修复重复安装闭环', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);

COMMIT;
