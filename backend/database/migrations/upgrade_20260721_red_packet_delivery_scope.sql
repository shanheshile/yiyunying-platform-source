-- 红包投放范围：严格区分私聊、群聊、聊天室、客服和活动红包。
-- MySQL 5.7/8.0 均可重复执行。

SET NAMES utf8mb4;

SET @has_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'red_packets' AND COLUMN_NAME = 'delivery_scope'
);
SET @sql = IF(
  @has_column = 0,
  'ALTER TABLE `red_packets` ADD COLUMN `delivery_scope` VARCHAR(20) NOT NULL DEFAULT ''private'' COMMENT ''private/group/chat_room/service/activity'' AFTER `packet_type`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'red_packets' AND COLUMN_NAME = 'context_id'
);
SET @sql = IF(
  @has_column = 0,
  'ALTER TABLE `red_packets` ADD COLUMN `context_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''会话、群聊、聊天室或活动编号'' AFTER `delivery_scope`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_index = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'red_packets' AND INDEX_NAME = 'idx_red_packets_delivery'
);
SET @sql = IF(
  @has_index = 0,
  'ALTER TABLE `red_packets` ADD KEY `idx_red_packets_delivery` (`app_id`, `delivery_scope`, `context_id`, `created_at`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `red_packets`
SET `delivery_scope` = 'private'
WHERE `delivery_scope` IS NULL OR `delivery_scope` = '';

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.21-red-packet-delivery-scope', '红包私聊、群聊、聊天室、客服和活动投放范围闭环', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
