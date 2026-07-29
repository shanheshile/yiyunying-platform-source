-- 解绑审核按直属层级独立处理，并记录总控强制介入
SET NAMES utf8mb4;

SET @has_review_mode = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'identity_unbind_requests'
    AND COLUMN_NAME = 'review_mode'
);
SET @sql = IF(
  @has_review_mode = 0,
  'ALTER TABLE `identity_unbind_requests` ADD COLUMN `review_mode` VARCHAR(20) DEFAULT NULL AFTER `reviewed_by_id`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO `schema_migrations` (`version`, `description`)
VALUES ('20260713_identity_review_scope', '解绑申请直属审核与总控强制处理分离')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
