SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `user_transfers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `from_user_id` BIGINT UNSIGNED NOT NULL,
  `to_user_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL,
  `message` VARCHAR(255) NOT NULL DEFAULT '',
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `expired_at` DATETIME NOT NULL,
  `accepted_at` DATETIME DEFAULT NULL,
  `refunded_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_transfers_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_user_transfers_sender` (`app_id`, `from_user_id`, `status`, `id`),
  KEY `idx_user_transfers_receiver` (`app_id`, `to_user_id`, `status`, `id`),
  CONSTRAINT `fk_user_transfers_sender` FOREIGN KEY (`from_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_transfers_receiver` FOREIGN KEY (`to_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gift_catalog` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `gift_code` VARCHAR(40) NOT NULL,
  `gift_name` VARCHAR(80) NOT NULL,
  `icon_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `price` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `status` TINYINT NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_gift_catalog_code` (`app_id`, `gift_code`),
  KEY `idx_gift_catalog_tenant` (`admin_id`, `app_id`, `status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_gift_records' AND COLUMN_NAME = 'gift_id') = 0,
  'ALTER TABLE `user_gift_records` ADD COLUMN `gift_id` BIGINT UNSIGNED NULL AFTER `to_user_id`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_gift_records' AND COLUMN_NAME = 'unit_price') = 0,
  'ALTER TABLE `user_gift_records` ADD COLUMN `unit_price` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `quantity`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_gift_records' AND COLUMN_NAME = 'total_amount') = 0,
  'ALTER TABLE `user_gift_records` ADD COLUMN `total_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `unit_price`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_gift_records' AND COLUMN_NAME = 'status') = 0,
  'ALTER TABLE `user_gift_records` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT ''accepted'' AFTER `message`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_gift_records' AND COLUMN_NAME = 'expired_at') = 0,
  'ALTER TABLE `user_gift_records` ADD COLUMN `expired_at` DATETIME NULL AFTER `status`, ADD COLUMN `accepted_at` DATETIME NULL AFTER `expired_at`, ADD COLUMN `refunded_at` DATETIME NULL AFTER `accepted_at`',
  'SELECT 1'
);
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `gift_catalog` (`admin_id`, `app_id`, `gift_code`, `gift_name`, `price`, `sort_order`, `created_at`, `updated_at`)
SELECT app.`admin_id`, app.`id`, defaults.`gift_code`, defaults.`gift_name`, defaults.`price`, defaults.`sort_order`, NOW(), NOW()
FROM `apps` app
JOIN (
  SELECT 'flower' AS gift_code, '鲜花' AS gift_name, 1.00 AS price, 10 AS sort_order
  UNION ALL SELECT 'cake', '蛋糕', 5.00, 20
  UNION ALL SELECT 'applause', '掌声', 2.00, 30
  UNION ALL SELECT 'blessing', '祝福', 3.00, 40
) defaults
WHERE app.`deleted_at` IS NULL
ON DUPLICATE KEY UPDATE `gift_name` = VALUES(`gift_name`), `price` = VALUES(`price`), `sort_order` = VALUES(`sort_order`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.14-chat-commerce', '聊天收藏、红包、待收转账、名片和礼物业务闭环', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
