-- 拼手气红包、两位小数金额与运气王标签。兼容 MySQL 5.7/8.0，可重复执行。
SET NAMES utf8mb4;

SET @has_red_packet_label := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'red_packets' AND COLUMN_NAME = 'packet_label'
);
SET @red_packet_label_sql := IF(
  @has_red_packet_label = 0,
  'ALTER TABLE `red_packets` ADD COLUMN `packet_label` VARCHAR(30) NOT NULL DEFAULT ''拼手气红包'' AFTER `packet_type`',
  'SELECT 1'
);
PREPARE red_packet_label_statement FROM @red_packet_label_sql;
EXECUTE red_packet_label_statement;
DEALLOCATE PREPARE red_packet_label_statement;

ALTER TABLE `red_packets`
  MODIFY COLUMN `packet_type` VARCHAR(20) NOT NULL DEFAULT 'random',
  MODIFY COLUMN `total_amount` DECIMAL(18,2) UNSIGNED NOT NULL,
  MODIFY COLUMN `remain_amount` DECIMAL(18,2) UNSIGNED NOT NULL;

ALTER TABLE `red_packet_claims`
  MODIFY COLUMN `amount` DECIMAL(18,2) UNSIGNED NOT NULL;

ALTER TABLE `red_packet_returns`
  MODIFY COLUMN `amount` DECIMAL(18,2) UNSIGNED NOT NULL;

UPDATE `red_packets`
SET `packet_label` = CASE
  WHEN `packet_type` = 'equal' THEN '等额红包'
  ELSE '拼手气红包'
END
WHERE `packet_label` = '' OR `packet_label` IS NULL;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
SELECT '2026.07.22-random-red-packet-money', '拼手气红包、两位小数与运气王标识', NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `schema_migrations` WHERE `version` = '2026.07.22-random-red-packet-money'
);
