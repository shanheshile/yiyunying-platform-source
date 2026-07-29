-- 三大业务目录、互动场景和统一奖励规则。
-- 兼容 MySQL 5.7/8.0，可重复执行。

CREATE TABLE IF NOT EXISTS `business_catalogs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `catalog_code` VARCHAR(30) NOT NULL COMMENT 'resource/shop/balance_shop',
  `catalog_name` VARCHAR(50) NOT NULL,
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_business_catalogs_app_code` (`app_id`, `catalog_code`),
  KEY `idx_business_catalogs_tenant` (`admin_id`, `app_id`, `status`, `sort_order`),
  CONSTRAINT `fk_business_catalogs_app` FOREIGN KEY (`app_id`, `admin_id`)
    REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `business_catalogs`
  (`admin_id`, `app_id`, `catalog_code`, `catalog_name`, `description`, `sort_order`, `status`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, 'resource', '资源', '资源下固定包含应用商店和源码商城两个子类', 30, 1, NOW(), NOW()
FROM `apps`
ON DUPLICATE KEY UPDATE `catalog_name` = VALUES(`catalog_name`), `description` = VALUES(`description`), `sort_order` = VALUES(`sort_order`);

INSERT INTO `business_catalogs`
  (`admin_id`, `app_id`, `catalog_code`, `catalog_name`, `description`, `sort_order`, `status`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, 'shop', '商店', '支持现金、余额或组合支付的商品', 20, 1, NOW(), NOW()
FROM `apps`
ON DUPLICATE KEY UPDATE `catalog_name` = VALUES(`catalog_name`), `description` = VALUES(`description`), `sort_order` = VALUES(`sort_order`);

INSERT INTO `business_catalogs`
  (`admin_id`, `app_id`, `catalog_code`, `catalog_name`, `description`, `sort_order`, `status`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, 'balance_shop', '余额商店', '仅使用软件余额购买的虚拟或实体商品', 10, 1, NOW(), NOW()
FROM `apps`
ON DUPLICATE KEY UPDATE `catalog_name` = VALUES(`catalog_name`), `description` = VALUES(`description`), `sort_order` = VALUES(`sort_order`);

SET @has_resource_category_type := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resource_categories' AND COLUMN_NAME = 'resource_type'
);
SET @resource_category_type_sql := IF(
  @has_resource_category_type = 0,
  'ALTER TABLE `resource_categories` ADD COLUMN `resource_type` VARCHAR(30) NOT NULL DEFAULT ''app_store'' AFTER `app_id`',
  'SELECT 1'
);
PREPARE resource_category_type_statement FROM @resource_category_type_sql;
EXECUTE resource_category_type_statement;
DEALLOCATE PREPARE resource_category_type_statement;

SET @resource_category_unique_columns := (
  SELECT GROUP_CONCAT(`COLUMN_NAME` ORDER BY `SEQ_IN_INDEX` SEPARATOR ',')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resource_categories' AND INDEX_NAME = 'uk_resource_categories_name'
);
SET @resource_category_unique_sql := CASE
  WHEN @resource_category_unique_columns = 'app_id,resource_type,name' THEN 'SELECT 1'
  WHEN @resource_category_unique_columns IS NULL THEN 'ALTER TABLE `resource_categories` ADD UNIQUE KEY `uk_resource_categories_name` (`app_id`, `resource_type`, `name`)'
  ELSE 'ALTER TABLE `resource_categories` DROP INDEX `uk_resource_categories_name`, ADD UNIQUE KEY `uk_resource_categories_name` (`app_id`, `resource_type`, `name`)'
END;
PREPARE resource_category_unique_statement FROM @resource_category_unique_sql;
EXECUTE resource_category_unique_statement;
DEALLOCATE PREPARE resource_category_unique_statement;

SET @has_resource_type := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resources' AND COLUMN_NAME = 'resource_type'
);
SET @resource_type_sql := IF(
  @has_resource_type = 0,
  'ALTER TABLE `resources` ADD COLUMN `resource_type` VARCHAR(30) NOT NULL DEFAULT ''app_store'' AFTER `app_id`',
  'SELECT 1'
);
PREPARE resource_type_statement FROM @resource_type_sql;
EXECUTE resource_type_statement;
DEALLOCATE PREPARE resource_type_statement;

SET @has_resource_price_money := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resources' AND COLUMN_NAME = 'price_money'
);
SET @resource_price_money_sql := IF(
  @has_resource_price_money = 0,
  'ALTER TABLE `resources` ADD COLUMN `price_money` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `price_integral`',
  'SELECT 1'
);
PREPARE resource_price_money_statement FROM @resource_price_money_sql;
EXECUTE resource_price_money_statement;
DEALLOCATE PREPARE resource_price_money_statement;

SET @has_resource_metadata := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resources' AND COLUMN_NAME = 'metadata_json'
);
SET @resource_metadata_sql := IF(
  @has_resource_metadata = 0,
  'ALTER TABLE `resources` ADD COLUMN `metadata_json` LONGTEXT AFTER `download_url`',
  'SELECT 1'
);
PREPARE resource_metadata_statement FROM @resource_metadata_sql;
EXECUTE resource_metadata_statement;
DEALLOCATE PREPARE resource_metadata_statement;

SET @has_resource_tags := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resources' AND COLUMN_NAME = 'tags_json'
);
SET @resource_tags_sql := IF(
  @has_resource_tags = 0,
  'ALTER TABLE `resources` ADD COLUMN `tags_json` LONGTEXT AFTER `metadata_json`',
  'SELECT 1'
);
PREPARE resource_tags_statement FROM @resource_tags_sql;
EXECUTE resource_tags_statement;
DEALLOCATE PREPARE resource_tags_statement;

SET @has_resource_images := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resources' AND COLUMN_NAME = 'images_json'
);
SET @resource_images_sql := IF(
  @has_resource_images = 0,
  'ALTER TABLE `resources` ADD COLUMN `images_json` LONGTEXT AFTER `tags_json`',
  'SELECT 1'
);
PREPARE resource_images_statement FROM @resource_images_sql;
EXECUTE resource_images_statement;
DEALLOCATE PREPARE resource_images_statement;

SET @has_resource_attachments := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resources' AND COLUMN_NAME = 'attachments_json'
);
SET @resource_attachments_sql := IF(
  @has_resource_attachments = 0,
  'ALTER TABLE `resources` ADD COLUMN `attachments_json` LONGTEXT AFTER `images_json`',
  'SELECT 1'
);
PREPARE resource_attachments_statement FROM @resource_attachments_sql;
EXECUTE resource_attachments_statement;
DEALLOCATE PREPARE resource_attachments_statement;

SET @has_shop_category_catalog := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_categories' AND COLUMN_NAME = 'catalog_code'
);
SET @shop_category_catalog_sql := IF(
  @has_shop_category_catalog = 0,
  'ALTER TABLE `shop_categories` ADD COLUMN `catalog_code` VARCHAR(30) NOT NULL DEFAULT ''shop'' AFTER `app_id`',
  'SELECT 1'
);
PREPARE shop_category_catalog_statement FROM @shop_category_catalog_sql;
EXECUTE shop_category_catalog_statement;
DEALLOCATE PREPARE shop_category_catalog_statement;

SET @shop_category_unique_columns := (
  SELECT GROUP_CONCAT(`COLUMN_NAME` ORDER BY `SEQ_IN_INDEX` SEPARATOR ',')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_categories' AND INDEX_NAME = 'uk_shop_categories_tenant_name'
);
SET @shop_category_unique_sql := CASE
  WHEN @shop_category_unique_columns = 'admin_id,app_id,catalog_code,parent_id,name' THEN 'SELECT 1'
  WHEN @shop_category_unique_columns IS NULL THEN 'ALTER TABLE `shop_categories` ADD UNIQUE KEY `uk_shop_categories_tenant_name` (`admin_id`, `app_id`, `catalog_code`, `parent_id`, `name`)'
  ELSE 'ALTER TABLE `shop_categories` DROP INDEX `uk_shop_categories_tenant_name`, ADD UNIQUE KEY `uk_shop_categories_tenant_name` (`admin_id`, `app_id`, `catalog_code`, `parent_id`, `name`)'
END;
PREPARE shop_category_unique_statement FROM @shop_category_unique_sql;
EXECUTE shop_category_unique_statement;
DEALLOCATE PREPARE shop_category_unique_statement;

SET @has_shop_goods_catalog := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_goods' AND COLUMN_NAME = 'catalog_code'
);
SET @shop_goods_catalog_sql := IF(
  @has_shop_goods_catalog = 0,
  'ALTER TABLE `shop_goods` ADD COLUMN `catalog_code` VARCHAR(30) NOT NULL DEFAULT ''shop'' AFTER `app_id`',
  'SELECT 1'
);
PREPARE shop_goods_catalog_statement FROM @shop_goods_catalog_sql;
EXECUTE shop_goods_catalog_statement;
DEALLOCATE PREPARE shop_goods_catalog_statement;

SET @has_shop_order_catalog := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'catalog_code'
);
SET @shop_order_catalog_sql := IF(
  @has_shop_order_catalog = 0,
  'ALTER TABLE `shop_orders` ADD COLUMN `catalog_code` VARCHAR(30) NOT NULL DEFAULT ''shop'' AFTER `app_id`',
  'SELECT 1'
);
PREPARE shop_order_catalog_statement FROM @shop_order_catalog_sql;
EXECUTE shop_order_catalog_statement;
DEALLOCATE PREPARE shop_order_catalog_statement;

SET @has_red_packet_scene := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'red_packets' AND COLUMN_NAME = 'scene_type'
);
SET @red_packet_scene_sql := IF(
  @has_red_packet_scene = 0,
  'ALTER TABLE `red_packets` ADD COLUMN `scene_type` VARCHAR(30) NOT NULL DEFAULT ''chat'' AFTER `packet_type`',
  'SELECT 1'
);
PREPARE red_packet_scene_statement FROM @red_packet_scene_sql;
EXECUTE red_packet_scene_statement;
DEALLOCATE PREPARE red_packet_scene_statement;

SET @has_red_packet_source_type := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'red_packets' AND COLUMN_NAME = 'source_type'
);
SET @red_packet_source_type_sql := IF(
  @has_red_packet_source_type = 0,
  'ALTER TABLE `red_packets` ADD COLUMN `source_type` VARCHAR(40) NOT NULL DEFAULT '''' AFTER `context_id`',
  'SELECT 1'
);
PREPARE red_packet_source_type_statement FROM @red_packet_source_type_sql;
EXECUTE red_packet_source_type_statement;
DEALLOCATE PREPARE red_packet_source_type_statement;

SET @has_red_packet_source_id := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'red_packets' AND COLUMN_NAME = 'source_id'
);
SET @red_packet_source_id_sql := IF(
  @has_red_packet_source_id = 0,
  'ALTER TABLE `red_packets` ADD COLUMN `source_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `source_type`',
  'SELECT 1'
);
PREPARE red_packet_source_id_statement FROM @red_packet_source_id_sql;
EXECUTE red_packet_source_id_statement;
DEALLOCATE PREPARE red_packet_source_id_statement;

SET @has_red_packet_creator_type := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'red_packets' AND COLUMN_NAME = 'creator_type'
);
SET @red_packet_creator_type_sql := IF(
  @has_red_packet_creator_type = 0,
  'ALTER TABLE `red_packets` ADD COLUMN `creator_type` VARCHAR(20) NOT NULL DEFAULT ''user'' AFTER `user_id`',
  'SELECT 1'
);
PREPARE red_packet_creator_type_statement FROM @red_packet_creator_type_sql;
EXECUTE red_packet_creator_type_statement;
DEALLOCATE PREPARE red_packet_creator_type_statement;

SET @has_red_packet_creator_id := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'red_packets' AND COLUMN_NAME = 'creator_id'
);
SET @red_packet_creator_id_sql := IF(
  @has_red_packet_creator_id = 0,
  'ALTER TABLE `red_packets` ADD COLUMN `creator_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `creator_type`',
  'SELECT 1'
);
PREPARE red_packet_creator_id_statement FROM @red_packet_creator_id_sql;
EXECUTE red_packet_creator_id_statement;
DEALLOCATE PREPARE red_packet_creator_id_statement;

SET @has_red_packet_return_policy := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'red_packets' AND COLUMN_NAME = 'return_policy'
);
SET @red_packet_return_policy_sql := IF(
  @has_red_packet_return_policy = 0,
  'ALTER TABLE `red_packets` ADD COLUMN `return_policy` VARCHAR(30) NOT NULL DEFAULT ''recipient_return'' AFTER `message`',
  'SELECT 1'
);
PREPARE red_packet_return_policy_statement FROM @red_packet_return_policy_sql;
EXECUTE red_packet_return_policy_statement;
DEALLOCATE PREPARE red_packet_return_policy_statement;

SET @red_packet_user_nullable := (
  SELECT `IS_NULLABLE` FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'red_packets' AND COLUMN_NAME = 'user_id' LIMIT 1
);
SET @red_packet_user_nullable_sql := IF(
  @red_packet_user_nullable = 'NO',
  'ALTER TABLE `red_packets` MODIFY COLUMN `user_id` BIGINT UNSIGNED DEFAULT NULL',
  'SELECT 1'
);
PREPARE red_packet_user_nullable_statement FROM @red_packet_user_nullable_sql;
EXECUTE red_packet_user_nullable_statement;
DEALLOCATE PREPARE red_packet_user_nullable_statement;

UPDATE `red_packets`
SET `scene_type` = CASE
  WHEN `delivery_scope` = 'activity' THEN 'activity'
  ELSE 'chat'
END,
`creator_type` = 'user', `creator_id` = `user_id`,
`return_policy` = CASE WHEN `delivery_scope` = 'private' THEN 'recipient_return' ELSE 'manager_only' END
WHERE `creator_id` = 0 OR `scene_type` = '';

SET @has_poll_scene := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'universal_polls' AND COLUMN_NAME = 'scene_type'
);
SET @poll_scene_sql := IF(
  @has_poll_scene = 0,
  'ALTER TABLE `universal_polls` ADD COLUMN `scene_type` VARCHAR(30) NOT NULL DEFAULT ''activity'' AFTER `target_level`',
  'SELECT 1'
);
PREPARE poll_scene_statement FROM @poll_scene_sql;
EXECUTE poll_scene_statement;
DEALLOCATE PREPARE poll_scene_statement;

SET @has_poll_source_type := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'universal_polls' AND COLUMN_NAME = 'source_type'
);
SET @poll_source_type_sql := IF(
  @has_poll_source_type = 0,
  'ALTER TABLE `universal_polls` ADD COLUMN `source_type` VARCHAR(40) NOT NULL DEFAULT '''' AFTER `scene_type`',
  'SELECT 1'
);
PREPARE poll_source_type_statement FROM @poll_source_type_sql;
EXECUTE poll_source_type_statement;
DEALLOCATE PREPARE poll_source_type_statement;

SET @has_poll_source_id := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'universal_polls' AND COLUMN_NAME = 'source_id'
);
SET @poll_source_id_sql := IF(
  @has_poll_source_id = 0,
  'ALTER TABLE `universal_polls` ADD COLUMN `source_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `source_type`',
  'SELECT 1'
);
PREPARE poll_source_id_statement FROM @poll_source_id_sql;
EXECUTE poll_source_id_statement;
DEALLOCATE PREPARE poll_source_id_statement;

SET @has_resource_type_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resources' AND INDEX_NAME = 'idx_resources_type_status'
);
SET @resource_type_index_sql := IF(
  @has_resource_type_index = 0,
  'ALTER TABLE `resources` ADD KEY `idx_resources_type_status` (`app_id`, `resource_type`, `audit_status`, `status`, `created_at`)',
  'SELECT 1'
);
PREPARE resource_type_index_statement FROM @resource_type_index_sql;
EXECUTE resource_type_index_statement;
DEALLOCATE PREPARE resource_type_index_statement;

SET @has_shop_goods_catalog_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_goods' AND INDEX_NAME = 'idx_shop_goods_catalog_status'
);
SET @shop_goods_catalog_index_sql := IF(
  @has_shop_goods_catalog_index = 0,
  'ALTER TABLE `shop_goods` ADD KEY `idx_shop_goods_catalog_status` (`app_id`, `catalog_code`, `status`, `created_at`, `id`)',
  'SELECT 1'
);
PREPARE shop_goods_catalog_index_statement FROM @shop_goods_catalog_index_sql;
EXECUTE shop_goods_catalog_index_statement;
DEALLOCATE PREPARE shop_goods_catalog_index_statement;

SET @has_red_packet_scene_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'red_packets' AND INDEX_NAME = 'idx_red_packets_scene_source'
);
SET @red_packet_scene_index_sql := IF(
  @has_red_packet_scene_index = 0,
  'ALTER TABLE `red_packets` ADD KEY `idx_red_packets_scene_source` (`app_id`, `scene_type`, `source_type`, `source_id`, `status`, `created_at`)',
  'SELECT 1'
);
PREPARE red_packet_scene_index_statement FROM @red_packet_scene_index_sql;
EXECUTE red_packet_scene_index_statement;
DEALLOCATE PREPARE red_packet_scene_index_statement;

SET @has_poll_scene_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'universal_polls' AND INDEX_NAME = 'idx_universal_polls_scene'
);
SET @poll_scene_index_sql := IF(
  @has_poll_scene_index = 0,
  'ALTER TABLE `universal_polls` ADD KEY `idx_universal_polls_scene` (`scene_type`, `source_type`, `source_id`, `status`, `id`)',
  'SELECT 1'
);
PREPARE poll_scene_index_statement FROM @poll_scene_index_sql;
EXECUTE poll_scene_index_statement;
DEALLOCATE PREPARE poll_scene_index_statement;

CREATE TABLE IF NOT EXISTS `interaction_scene_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `root_platform_id` BIGINT UNSIGNED NOT NULL,
  `scope_platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `entity_type` VARCHAR(30) NOT NULL COMMENT 'red_packet/vote/lottery/reward',
  `entity_id` BIGINT UNSIGNED NOT NULL,
  `scene_type` VARCHAR(30) NOT NULL COMMENT 'chat/forum/bounty/earned/activity',
  `source_type` VARCHAR(40) NOT NULL DEFAULT '',
  `source_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `creator_type` VARCHAR(20) NOT NULL,
  `creator_id` BIGINT UNSIGNED NOT NULL,
  `target_level` TINYINT UNSIGNED NOT NULL DEFAULT 4,
  `visible_levels_json` LONGTEXT,
  `manageable_levels_json` LONGTEXT,
  `policy_json` LONGTEXT,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_interaction_scene_entity` (`entity_type`, `entity_id`),
  KEY `idx_interaction_scene_source` (`scene_type`, `source_type`, `source_id`, `status`),
  KEY `idx_interaction_scene_scope` (`root_platform_id`, `scope_platform_id`, `app_id`, `target_level`, `status`),
  CONSTRAINT `fk_interaction_scene_root` FOREIGN KEY (`root_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_interaction_scene_scope` FOREIGN KEY (`scope_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_interaction_scene_app` FOREIGN KEY (`app_id`) REFERENCES `apps` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_reward_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `scene_code` VARCHAR(60) NOT NULL,
  `scene_name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `enabled` TINYINT NOT NULL DEFAULT 0,
  `reward_json` LONGTEXT NOT NULL,
  `grant_mode` VARCHAR(20) NOT NULL DEFAULT 'automatic' COMMENT 'automatic/after_review/manual',
  `cycle_type` VARCHAR(20) NOT NULL DEFAULT 'unlimited' COMMENT 'once/daily/weekly/monthly/unlimited',
  `cycle_limit` INT UNSIGNED NOT NULL DEFAULT 0,
  `user_total_limit` INT UNSIGNED NOT NULL DEFAULT 0,
  `conditions_json` LONGTEXT,
  `audience_json` LONGTEXT,
  `manager_level` TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `inherited_from_type` VARCHAR(20) NOT NULL DEFAULT '',
  `inherited_from_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `force_sync` TINYINT NOT NULL DEFAULT 0,
  `created_by_type` VARCHAR(20) NOT NULL DEFAULT 'system',
  `created_by_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_reward_rules_scene` (`app_id`, `scene_code`),
  UNIQUE KEY `uk_app_reward_rules_id_tenant` (`id`, `app_id`, `admin_id`),
  KEY `idx_app_reward_rules_manage` (`admin_id`, `app_id`, `enabled`, `status`, `scene_code`),
  CONSTRAINT `fk_app_reward_rules_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_reward_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `rule_id` BIGINT UNSIGNED NOT NULL,
  `scene_code` VARCHAR(60) NOT NULL,
  `ref_type` VARCHAR(40) NOT NULL DEFAULT '',
  `ref_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `period_key` VARCHAR(40) NOT NULL DEFAULT '',
  `dedupe_key` VARCHAR(191) NOT NULL,
  `reward_json` LONGTEXT NOT NULL,
  `context_json` LONGTEXT,
  `status` VARCHAR(20) NOT NULL DEFAULT 'granted' COMMENT 'pending/granted/rejected/reversed',
  `reason` VARCHAR(500) NOT NULL DEFAULT '',
  `actor_type` VARCHAR(20) NOT NULL DEFAULT 'system',
  `actor_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `granted_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_reward_events_dedupe` (`app_id`, `dedupe_key`),
  KEY `idx_app_reward_events_user` (`app_id`, `user_id`, `scene_code`, `created_at`),
  KEY `idx_app_reward_events_manage` (`admin_id`, `app_id`, `status`, `created_at`),
  CONSTRAINT `fk_app_reward_events_rule` FOREIGN KEY (`rule_id`, `app_id`, `admin_id`) REFERENCES `app_reward_rules` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_app_reward_events_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `app_reward_rules`
  (`admin_id`, `app_id`, `scene_code`, `scene_name`, `description`, `enabled`, `reward_json`, `grant_mode`, `cycle_type`, `cycle_limit`, `created_by_type`, `status`, `created_at`, `updated_at`)
SELECT a.`admin_id`, a.`id`, scenes.`scene_code`, scenes.`scene_name`, scenes.`description`, 0,
       '{"balance":0,"experience":0,"integral":0,"document_credit":0,"vip_days":0}',
       scenes.`grant_mode`, scenes.`cycle_type`, scenes.`cycle_limit`, 'system', 1, NOW(), NOW()
FROM `apps` a
JOIN (
  SELECT 'register' AS scene_code, '注册奖励' AS scene_name, '用户首次完成注册后发放' AS description, 'automatic' AS grant_mode, 'once' AS cycle_type, 1 AS cycle_limit
  UNION ALL SELECT 'login', '登录奖励', '按配置周期完成有效登录后发放', 'automatic', 'daily', 1
  UNION ALL SELECT 'daily_sign', '签到奖励', '每日首次签到后发放', 'automatic', 'daily', 1
  UNION ALL SELECT 'invite_success', '邀请成功奖励', '受邀用户完成绑定后向邀请人发放', 'automatic', 'unlimited', 0
  UNION ALL SELECT 'forum_post_create', '发帖奖励', '成功发布并通过审核的帖子奖励', 'after_review', 'daily', 0
  UNION ALL SELECT 'forum_plate_create', '建设论坛奖励', '板块或二级分类申请通过后的建设奖励', 'after_review', 'unlimited', 0
  UNION ALL SELECT 'valid_report', '有效举报奖励', '举报被审核认定有效后发放', 'after_review', 'unlimited', 0
  UNION ALL SELECT 'valid_feedback', '有效反馈奖励', '反馈被审核认定有效后发放', 'after_review', 'unlimited', 0
  UNION ALL SELECT 'comment_create', '有效评论奖励', '有效评论发布或审核通过后发放', 'automatic', 'daily', 0
  UNION ALL SELECT 'reply_create', '有效回复奖励', '有效回复发布或审核通过后发放', 'automatic', 'daily', 0
) scenes
WHERE 1 = 1
ON DUPLICATE KEY UPDATE `scene_name` = VALUES(`scene_name`), `description` = VALUES(`description`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.21-business-catalog-rewards', '三大业务目录、资源双子类、红包投票场景和统一奖励规则', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
