-- 商城分类、商品互动与订单追踪闭环。兼容 MySQL 5.7/8.0，可重复执行。

CREATE TABLE IF NOT EXISTS `shop_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `parent_id` BIGINT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(100) NOT NULL,
  `icon_url` VARCHAR(500) NOT NULL DEFAULT '',
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shop_categories_tenant_name` (`admin_id`, `app_id`, `parent_id`, `name`),
  KEY `idx_shop_categories_tenant_status` (`admin_id`, `app_id`, `status`, `sort_order`),
  CONSTRAINT `fk_shop_categories_app` FOREIGN KEY (`app_id`, `admin_id`)
    REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_shop_goods_category_id := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_goods' AND COLUMN_NAME = 'category_id'
);
SET @shop_goods_category_id_sql := IF(
  @has_shop_goods_category_id = 0,
  'ALTER TABLE `shop_goods` ADD COLUMN `category_id` BIGINT UNSIGNED DEFAULT NULL AFTER `app_id`',
  'SELECT 1'
);
PREPARE shop_goods_category_id_statement FROM @shop_goods_category_id_sql;
EXECUTE shop_goods_category_id_statement;
DEALLOCATE PREPARE shop_goods_category_id_statement;

SET @has_shop_goods_type := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_goods' AND COLUMN_NAME = 'goods_type'
);
SET @shop_goods_type_sql := IF(
  @has_shop_goods_type = 0,
  'ALTER TABLE `shop_goods` ADD COLUMN `goods_type` VARCHAR(20) NOT NULL DEFAULT ''virtual'' AFTER `description`',
  'SELECT 1'
);
PREPARE shop_goods_type_statement FROM @shop_goods_type_sql;
EXECUTE shop_goods_type_statement;
DEALLOCATE PREPARE shop_goods_type_statement;

SET @has_shop_goods_delivery := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_goods' AND COLUMN_NAME = 'delivery_required'
);
SET @shop_goods_delivery_sql := IF(
  @has_shop_goods_delivery = 0,
  'ALTER TABLE `shop_goods` ADD COLUMN `delivery_required` TINYINT NOT NULL DEFAULT 0 AFTER `goods_type`',
  'SELECT 1'
);
PREPARE shop_goods_delivery_statement FROM @shop_goods_delivery_sql;
EXECUTE shop_goods_delivery_statement;
DEALLOCATE PREPARE shop_goods_delivery_statement;

SET @has_shop_goods_tags := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_goods' AND COLUMN_NAME = 'tags_json'
);
SET @shop_goods_tags_sql := IF(
  @has_shop_goods_tags = 0,
  'ALTER TABLE `shop_goods` ADD COLUMN `tags_json` LONGTEXT AFTER `delivery_required`',
  'SELECT 1'
);
PREPARE shop_goods_tags_statement FROM @shop_goods_tags_sql;
EXECUTE shop_goods_tags_statement;
DEALLOCATE PREPARE shop_goods_tags_statement;

SET @has_shop_goods_images := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_goods' AND COLUMN_NAME = 'images_json'
);
SET @shop_goods_images_sql := IF(
  @has_shop_goods_images = 0,
  'ALTER TABLE `shop_goods` ADD COLUMN `images_json` LONGTEXT AFTER `tags_json`',
  'SELECT 1'
);
PREPARE shop_goods_images_statement FROM @shop_goods_images_sql;
EXECUTE shop_goods_images_statement;
DEALLOCATE PREPARE shop_goods_images_statement;

SET @has_shop_goods_attachments := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_goods' AND COLUMN_NAME = 'attachments_json'
);
SET @shop_goods_attachments_sql := IF(
  @has_shop_goods_attachments = 0,
  'ALTER TABLE `shop_goods` ADD COLUMN `attachments_json` LONGTEXT AFTER `images_json`',
  'SELECT 1'
);
PREPARE shop_goods_attachments_statement FROM @shop_goods_attachments_sql;
EXECUTE shop_goods_attachments_statement;
DEALLOCATE PREPARE shop_goods_attachments_statement;

SET @has_shop_goods_category_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_goods' AND INDEX_NAME = 'idx_shop_goods_category'
);
SET @shop_goods_category_index_sql := IF(
  @has_shop_goods_category_index = 0,
  'ALTER TABLE `shop_goods` ADD KEY `idx_shop_goods_category` (`app_id`, `category_id`, `status`, `created_at`)',
  'SELECT 1'
);
PREPARE shop_goods_category_index_statement FROM @shop_goods_category_index_sql;
EXECUTE shop_goods_category_index_statement;
DEALLOCATE PREPARE shop_goods_category_index_statement;

SET @has_orders_buyer_info := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'buyer_info_json'
);
SET @orders_buyer_info_sql := IF(
  @has_orders_buyer_info = 0,
  'ALTER TABLE `orders` ADD COLUMN `buyer_info_json` LONGTEXT AFTER `pay_channel`',
  'SELECT 1'
);
PREPARE orders_buyer_info_statement FROM @orders_buyer_info_sql;
EXECUTE orders_buyer_info_statement;
DEALLOCATE PREPARE orders_buyer_info_statement;

SET @has_orders_snapshot := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'snapshot_json'
);
SET @orders_snapshot_sql := IF(
  @has_orders_snapshot = 0,
  'ALTER TABLE `orders` ADD COLUMN `snapshot_json` LONGTEXT AFTER `buyer_info_json`',
  'SELECT 1'
);
PREPARE orders_snapshot_statement FROM @orders_snapshot_sql;
EXECUTE orders_snapshot_statement;
DEALLOCATE PREPARE orders_snapshot_statement;

SET @has_shop_orders_goods_name := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'goods_name'
);
SET @shop_orders_goods_name_sql := IF(
  @has_shop_orders_goods_name = 0,
  'ALTER TABLE `shop_orders` ADD COLUMN `goods_name` VARCHAR(200) NOT NULL DEFAULT '''' AFTER `goods_id`',
  'SELECT 1'
);
PREPARE shop_orders_goods_name_statement FROM @shop_orders_goods_name_sql;
EXECUTE shop_orders_goods_name_statement;
DEALLOCATE PREPARE shop_orders_goods_name_statement;

SET @has_shop_orders_cover := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'goods_cover_url'
);
SET @shop_orders_cover_sql := IF(
  @has_shop_orders_cover = 0,
  'ALTER TABLE `shop_orders` ADD COLUMN `goods_cover_url` VARCHAR(500) NOT NULL DEFAULT '''' AFTER `goods_name`',
  'SELECT 1'
);
PREPARE shop_orders_cover_statement FROM @shop_orders_cover_sql;
EXECUTE shop_orders_cover_statement;
DEALLOCATE PREPARE shop_orders_cover_statement;

SET @has_shop_orders_goods_type := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'goods_type'
);
SET @shop_orders_goods_type_sql := IF(
  @has_shop_orders_goods_type = 0,
  'ALTER TABLE `shop_orders` ADD COLUMN `goods_type` VARCHAR(20) NOT NULL DEFAULT ''virtual'' AFTER `goods_cover_url`',
  'SELECT 1'
);
PREPARE shop_orders_goods_type_statement FROM @shop_orders_goods_type_sql;
EXECUTE shop_orders_goods_type_statement;
DEALLOCATE PREPARE shop_orders_goods_type_statement;

SET @has_shop_orders_unit_balance := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'unit_price_integral'
);
SET @shop_orders_unit_balance_sql := IF(
  @has_shop_orders_unit_balance = 0,
  'ALTER TABLE `shop_orders` ADD COLUMN `unit_price_integral` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `quantity`',
  'SELECT 1'
);
PREPARE shop_orders_unit_balance_statement FROM @shop_orders_unit_balance_sql;
EXECUTE shop_orders_unit_balance_statement;
DEALLOCATE PREPARE shop_orders_unit_balance_statement;

SET @has_shop_orders_unit_money := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'unit_price_money'
);
SET @shop_orders_unit_money_sql := IF(
  @has_shop_orders_unit_money = 0,
  'ALTER TABLE `shop_orders` ADD COLUMN `unit_price_money` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `unit_price_integral`',
  'SELECT 1'
);
PREPARE shop_orders_unit_money_statement FROM @shop_orders_unit_money_sql;
EXECUTE shop_orders_unit_money_statement;
DEALLOCATE PREPARE shop_orders_unit_money_statement;

SET @has_shop_orders_buyer_info := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'buyer_info_json'
);
SET @shop_orders_buyer_info_sql := IF(
  @has_shop_orders_buyer_info = 0,
  'ALTER TABLE `shop_orders` ADD COLUMN `buyer_info_json` LONGTEXT AFTER `amount_money`',
  'SELECT 1'
);
PREPARE shop_orders_buyer_info_statement FROM @shop_orders_buyer_info_sql;
EXECUTE shop_orders_buyer_info_statement;
DEALLOCATE PREPARE shop_orders_buyer_info_statement;

SET @has_shop_orders_paid_at := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'paid_at'
);
SET @shop_orders_paid_at_sql := IF(
  @has_shop_orders_paid_at = 0,
  'ALTER TABLE `shop_orders` ADD COLUMN `paid_at` DATETIME DEFAULT NULL AFTER `status`',
  'SELECT 1'
);
PREPARE shop_orders_paid_at_statement FROM @shop_orders_paid_at_sql;
EXECUTE shop_orders_paid_at_statement;
DEALLOCATE PREPARE shop_orders_paid_at_statement;

SET @has_shop_orders_fulfilled_at := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'fulfilled_at'
);
SET @shop_orders_fulfilled_at_sql := IF(
  @has_shop_orders_fulfilled_at = 0,
  'ALTER TABLE `shop_orders` ADD COLUMN `fulfilled_at` DATETIME DEFAULT NULL AFTER `paid_at`',
  'SELECT 1'
);
PREPARE shop_orders_fulfilled_at_statement FROM @shop_orders_fulfilled_at_sql;
EXECUTE shop_orders_fulfilled_at_statement;
DEALLOCATE PREPARE shop_orders_fulfilled_at_statement;

SET @has_shop_orders_closed_at := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'closed_at'
);
SET @shop_orders_closed_at_sql := IF(
  @has_shop_orders_closed_at = 0,
  'ALTER TABLE `shop_orders` ADD COLUMN `closed_at` DATETIME DEFAULT NULL AFTER `fulfilled_at`',
  'SELECT 1'
);
PREPARE shop_orders_closed_at_statement FROM @shop_orders_closed_at_sql;
EXECUTE shop_orders_closed_at_statement;
DEALLOCATE PREPARE shop_orders_closed_at_statement;

SET @has_shop_orders_shipping_company := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'shipping_company'
);
SET @shop_orders_shipping_company_sql := IF(
  @has_shop_orders_shipping_company = 0,
  'ALTER TABLE `shop_orders` ADD COLUMN `shipping_company` VARCHAR(100) NOT NULL DEFAULT '''' AFTER `closed_at`',
  'SELECT 1'
);
PREPARE shop_orders_shipping_company_statement FROM @shop_orders_shipping_company_sql;
EXECUTE shop_orders_shipping_company_statement;
DEALLOCATE PREPARE shop_orders_shipping_company_statement;

SET @has_shop_orders_tracking_no := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'tracking_no'
);
SET @shop_orders_tracking_no_sql := IF(
  @has_shop_orders_tracking_no = 0,
  'ALTER TABLE `shop_orders` ADD COLUMN `tracking_no` VARCHAR(120) NOT NULL DEFAULT '''' AFTER `shipping_company`',
  'SELECT 1'
);
PREPARE shop_orders_tracking_no_statement FROM @shop_orders_tracking_no_sql;
EXECUTE shop_orders_tracking_no_statement;
DEALLOCATE PREPARE shop_orders_tracking_no_statement;

SET @has_shop_orders_fulfillment_note := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'fulfillment_note'
);
SET @shop_orders_fulfillment_note_sql := IF(
  @has_shop_orders_fulfillment_note = 0,
  'ALTER TABLE `shop_orders` ADD COLUMN `fulfillment_note` VARCHAR(500) NOT NULL DEFAULT '''' AFTER `tracking_no`',
  'SELECT 1'
);
PREPARE shop_orders_fulfillment_note_statement FROM @shop_orders_fulfillment_note_sql;
EXECUTE shop_orders_fulfillment_note_statement;
DEALLOCATE PREPARE shop_orders_fulfillment_note_statement;

CREATE TABLE IF NOT EXISTS `shop_goods_comments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `goods_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `parent_id` BIGINT UNSIGNED DEFAULT NULL,
  `content` TEXT NOT NULL,
  `score` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shop_goods_comments_goods` (`goods_id`, `status`, `created_at`),
  KEY `idx_shop_goods_comments_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_shop_goods_comments_goods` FOREIGN KEY (`goods_id`, `app_id`, `admin_id`)
    REFERENCES `shop_goods` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shop_goods_comments_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_goods_reactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `goods_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `reaction_type` VARCHAR(20) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shop_goods_reactions` (`goods_id`, `user_id`, `reaction_type`),
  KEY `idx_shop_goods_reactions_user` (`user_id`, `reaction_type`, `created_at`),
  CONSTRAINT `fk_shop_goods_reactions_goods` FOREIGN KEY (`goods_id`, `app_id`, `admin_id`)
    REFERENCES `shop_goods` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shop_goods_reactions_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_comment_reactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `comment_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `reaction_type` VARCHAR(20) NOT NULL DEFAULT 'like',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shop_comment_reactions` (`comment_id`, `user_id`, `reaction_type`),
  KEY `idx_shop_comment_reactions_user` (`user_id`, `reaction_type`, `created_at`),
  CONSTRAINT `fk_shop_comment_reactions_comment` FOREIGN KEY (`comment_id`)
    REFERENCES `shop_goods_comments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shop_comment_reactions_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_goods_forwards` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `goods_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(30) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `recommend_text` VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shop_goods_forwards_goods` (`goods_id`, `created_at`),
  KEY `idx_shop_goods_forwards_target` (`app_id`, `target_type`, `target_id`, `created_at`),
  CONSTRAINT `fk_shop_goods_forwards_goods` FOREIGN KEY (`goods_id`, `app_id`, `admin_id`)
    REFERENCES `shop_goods` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shop_goods_forwards_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_order_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `order_source` VARCHAR(20) NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `order_no` VARCHAR(64) NOT NULL,
  `event_code` VARCHAR(40) NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `detail` VARCHAR(500) NOT NULL DEFAULT '',
  `actor_type` VARCHAR(20) NOT NULL DEFAULT 'system',
  `actor_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `metadata_json` LONGTEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shop_order_events_order` (`app_id`, `order_no`, `created_at`),
  KEY `idx_shop_order_events_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_shop_order_events_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
SELECT '2026.07.21-shop-commerce-closure', '商城分类、商品互动、统一订单与事件追踪', NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `schema_migrations` WHERE `version` = '2026.07.21-shop-commerce-closure'
);
