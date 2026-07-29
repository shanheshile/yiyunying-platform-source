SET NAMES utf8mb4;

SET @had_audit_column = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bounties' AND COLUMN_NAME = 'audit_status');

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bounties' AND COLUMN_NAME = 'audit_status') = 0,
  'ALTER TABLE `bounties` ADD COLUMN `audit_status` VARCHAR(20) NOT NULL DEFAULT ''pending'' AFTER `status`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bounties' AND COLUMN_NAME = 'audit_reason') = 0,
  'ALTER TABLE `bounties` ADD COLUMN `audit_reason` VARCHAR(500) NOT NULL DEFAULT '''' AFTER `audit_status`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bounties' AND COLUMN_NAME = 'audited_by') = 0,
  'ALTER TABLE `bounties` ADD COLUMN `audited_by` BIGINT UNSIGNED NULL AFTER `audit_reason`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bounties' AND COLUMN_NAME = 'audited_at') = 0,
  'ALTER TABLE `bounties` ADD COLUMN `audited_at` DATETIME NULL AFTER `audited_by`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @index_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bounties' AND INDEX_NAME = 'idx_bounties_review') = 0,
  'ALTER TABLE `bounties` ADD INDEX `idx_bounties_review` (`admin_id`, `app_id`, `audit_status`, `id`)',
  'SELECT 1'
);
PREPARE stmt FROM @index_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @data_sql = IF(
  @had_audit_column = 0,
  'UPDATE `bounties` SET `audit_status` = ''approved'', `audit_reason` = '''' WHERE `audit_status` = ''pending''',
  'SELECT 1'
);
PREPARE stmt FROM @data_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.18-management-review-notes', '悬赏审核、管理视角与笔记日期检索', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
