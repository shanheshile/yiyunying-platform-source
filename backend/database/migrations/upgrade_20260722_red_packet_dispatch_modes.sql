-- 红包发放方式与参与范围。兼容 MySQL 5.7/8.0，可重复执行。
SET NAMES utf8mb4;

SET @has_distribution_mode := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'red_packets' AND COLUMN_NAME = 'distribution_mode'
);
SET @distribution_mode_sql := IF(
  @has_distribution_mode = 0,
  'ALTER TABLE `red_packets` ADD COLUMN `distribution_mode` VARCHAR(20) NOT NULL DEFAULT ''count_split'' COMMENT ''count_split/random_grab'' AFTER `packet_label`',
  'SELECT 1'
);
PREPARE distribution_mode_statement FROM @distribution_mode_sql;
EXECUTE distribution_mode_statement;
DEALLOCATE PREPARE distribution_mode_statement;

SET @has_eligibility_mode := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'red_packets' AND COLUMN_NAME = 'eligibility_mode'
);
SET @eligibility_mode_sql := IF(
  @has_eligibility_mode = 0,
  'ALTER TABLE `red_packets` ADD COLUMN `eligibility_mode` VARCHAR(20) NOT NULL DEFAULT ''selected'' COMMENT ''context_all/selected'' AFTER `distribution_mode`',
  'SELECT 1'
);
PREPARE eligibility_mode_statement FROM @eligibility_mode_sql;
EXECUTE eligibility_mode_statement;
DEALLOCATE PREPARE eligibility_mode_statement;

-- 字段首次新增时回填全部历史红包；重复执行时只修复非法值，避免覆盖新红包的显式配置。
SET @red_packet_backfill_sql := IF(
  @has_eligibility_mode = 0,
  'UPDATE `red_packets` packet
   SET packet.`distribution_mode` = CASE
         WHEN packet.`distribution_mode` = ''single_race'' THEN ''random_grab''
         WHEN packet.`distribution_mode` IN (''count_split'', ''random_grab'') THEN packet.`distribution_mode`
         ELSE ''count_split''
       END,
       packet.`eligibility_mode` = CASE
         WHEN EXISTS (
           SELECT 1 FROM `red_packet_recipients` recipient WHERE recipient.`packet_id` = packet.`id`
         ) THEN ''selected''
         ELSE ''context_all''
       END',
  'UPDATE `red_packets` packet
   SET packet.`distribution_mode` = CASE
         WHEN packet.`distribution_mode` = ''single_race'' THEN ''random_grab''
         WHEN packet.`distribution_mode` IN (''count_split'', ''random_grab'') THEN packet.`distribution_mode`
         ELSE ''count_split''
       END,
       packet.`eligibility_mode` = CASE
         WHEN packet.`eligibility_mode` IN (''context_all'', ''selected'') THEN packet.`eligibility_mode`
         WHEN EXISTS (
           SELECT 1 FROM `red_packet_recipients` recipient WHERE recipient.`packet_id` = packet.`id`
         ) THEN ''selected''
         ELSE ''context_all''
       END
   WHERE packet.`distribution_mode` IS NULL
      OR packet.`distribution_mode` = ''single_race''
      OR packet.`distribution_mode` NOT IN (''count_split'', ''random_grab'')
      OR packet.`eligibility_mode` IS NULL
      OR packet.`eligibility_mode` NOT IN (''context_all'', ''selected'')'
);
PREPARE red_packet_backfill_statement FROM @red_packet_backfill_sql;
EXECUTE red_packet_backfill_statement;
DEALLOCATE PREPARE red_packet_backfill_statement;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
SELECT '2026.07.22-red-packet-dispatch-modes', '红包按份数发、金额池随机抢与参与范围', NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `schema_migrations` WHERE `version` = '2026.07.22-red-packet-dispatch-modes'
);
