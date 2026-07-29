SET NAMES utf8mb4;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_forward_bundles' AND COLUMN_NAME = 'anonymity_mode') = 0,
  'ALTER TABLE `message_forward_bundles` ADD COLUMN `anonymity_mode` VARCHAR(20) NOT NULL DEFAULT ''none'' AFTER `item_count`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_forward_bundles' AND COLUMN_NAME = 'anonymity_map_json') = 0,
  'ALTER TABLE `message_forward_bundles` ADD COLUMN `anonymity_map_json` LONGTEXT NULL AFTER `anonymity_mode`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `message_forward_bundles`
SET `anonymity_mode` = 'none'
WHERE `anonymity_mode` IS NULL OR `anonymity_mode` NOT IN ('none', 'selected', 'full');
