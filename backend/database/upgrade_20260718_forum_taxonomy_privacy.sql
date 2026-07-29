SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `forum_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `plate_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `icon` VARCHAR(500) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_categories_name` (`app_id`, `plate_id`, `name`),
  UNIQUE KEY `uk_forum_categories_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_forum_categories_plate` (`app_id`, `plate_id`, `status`, `sort_order`),
  CONSTRAINT `fk_forum_categories_plate` FOREIGN KEY (`plate_id`, `app_id`, `admin_id`)
    REFERENCES `forum_plates` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_tags` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `plate_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(80) NOT NULL,
  `aliases_json` LONGTEXT,
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_tags_name` (`app_id`, `plate_id`, `name`),
  KEY `idx_forum_tags_category` (`app_id`, `plate_id`, `category_id`, `status`, `sort_order`),
  CONSTRAINT `fk_forum_tags_plate` FOREIGN KEY (`plate_id`, `app_id`, `admin_id`)
    REFERENCES `forum_plates` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_tags_category` FOREIGN KEY (`category_id`) REFERENCES `forum_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_structure_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `request_type` VARCHAR(20) NOT NULL,
  `plate_id` BIGINT UNSIGNED DEFAULT NULL,
  `category_id` BIGINT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(100) NOT NULL,
  `aliases_json` LONGTEXT,
  `description` VARCHAR(1000) NOT NULL DEFAULT '',
  `reason` VARCHAR(1000) NOT NULL DEFAULT '',
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `reviewer_admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `review_comment` VARCHAR(1000) NOT NULL DEFAULT '',
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_forum_structure_requests_status` (`app_id`, `status`, `request_type`, `created_at`),
  KEY `idx_forum_structure_requests_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_forum_structure_requests_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_structure_requests_plate` FOREIGN KEY (`plate_id`) REFERENCES `forum_plates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_forum_structure_requests_category` FOREIGN KEY (`category_id`) REFERENCES `forum_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_forum_structure_requests_reviewer` FOREIGN KEY (`reviewer_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_posts' AND COLUMN_NAME = 'category_id') = 0,
  'ALTER TABLE `forum_posts` ADD COLUMN `category_id` BIGINT UNSIGNED NULL AFTER `plate_id`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @index_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_posts' AND INDEX_NAME = 'idx_forum_posts_category_order') = 0,
  'ALTER TABLE `forum_posts` ADD INDEX `idx_forum_posts_category_order` (`app_id`, `category_id`, `created_at`)', 'SELECT 1');
PREPARE stmt FROM @index_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_posts' AND CONSTRAINT_NAME = 'fk_forum_posts_category') = 0,
  'ALTER TABLE `forum_posts` ADD CONSTRAINT `fk_forum_posts_category` FOREIGN KEY (`category_id`) REFERENCES `forum_categories` (`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @fk_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'profile_notes_visible') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `profile_notes_visible` TINYINT NOT NULL DEFAULT 1 AFTER `group_notification_enabled`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'profile_forum_visible') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `profile_forum_visible` TINYINT NOT NULL DEFAULT 1 AFTER `profile_notes_visible`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'profile_bounties_visible') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `profile_bounties_visible` TINYINT NOT NULL DEFAULT 1 AFTER `profile_forum_visible`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'profile_following_visible') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `profile_following_visible` TINYINT NOT NULL DEFAULT 1 AFTER `profile_bounties_visible`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_message_preferences' AND COLUMN_NAME = 'profile_followers_visible') = 0,
  'ALTER TABLE `user_message_preferences` ADD COLUMN `profile_followers_visible` TINYINT NOT NULL DEFAULT 1 AFTER `profile_following_visible`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `forum_plates`
  (`admin_id`, `app_id`, `name`, `icon`, `description`, `sort_order`, `status`, `created_at`, `updated_at`)
SELECT a.`admin_id`, a.`id`, '游戏交流', '', '按游戏和玩法浏览讨论内容', 10, 1, NOW(), NOW()
FROM `apps` a
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `status` = 1, `updated_at` = NOW();

INSERT INTO `forum_categories`
  (`admin_id`, `app_id`, `plate_id`, `name`, `description`, `icon`, `sort_order`, `status`, `created_at`, `updated_at`)
SELECT p.`admin_id`, p.`app_id`, p.`id`, '经验交流', '分享经验、作品、教程与使用心得', '', 20, 1, NOW(), NOW()
FROM `forum_plates` p WHERE p.`name` = '综合交流'
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `status` = 1, `updated_at` = NOW();
INSERT INTO `forum_categories`
  (`admin_id`, `app_id`, `plate_id`, `name`, `description`, `icon`, `sort_order`, `status`, `created_at`, `updated_at`)
SELECT p.`admin_id`, p.`app_id`, p.`id`, '问题求助', '描述问题、环境和已尝试的解决办法', '', 10, 1, NOW(), NOW()
FROM `forum_plates` p WHERE p.`name` = '综合交流'
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `status` = 1, `updated_at` = NOW();
INSERT INTO `forum_categories`
  (`admin_id`, `app_id`, `plate_id`, `name`, `description`, `icon`, `sort_order`, `status`, `created_at`, `updated_at`)
SELECT p.`admin_id`, p.`app_id`, p.`id`, '王者荣耀', '王者荣耀攻略、组队与赛事讨论', '', 20, 1, NOW(), NOW()
FROM `forum_plates` p WHERE p.`name` = '游戏交流'
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `status` = 1, `updated_at` = NOW();
INSERT INTO `forum_categories`
  (`admin_id`, `app_id`, `plate_id`, `name`, `description`, `icon`, `sort_order`, `status`, `created_at`, `updated_at`)
SELECT p.`admin_id`, p.`app_id`, p.`id`, '我的世界', 'Minecraft 建造、模组、服务器与玩法讨论', '', 10, 1, NOW(), NOW()
FROM `forum_plates` p WHERE p.`name` = '游戏交流'
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `status` = 1, `updated_at` = NOW();

INSERT INTO `forum_tags`
  (`admin_id`, `app_id`, `plate_id`, `category_id`, `name`, `aliases_json`, `description`, `sort_order`, `status`, `created_at`, `updated_at`)
SELECT p.`admin_id`, p.`app_id`, p.`id`, c.`id`, '王者荣耀', '["王者","Honor of Kings"]', '王者荣耀的规范标签', 20, 1, NOW(), NOW()
FROM `forum_plates` p INNER JOIN `forum_categories` c ON c.`plate_id` = p.`id` AND c.`name` = '王者荣耀'
WHERE p.`name` = '游戏交流'
ON DUPLICATE KEY UPDATE `category_id` = VALUES(`category_id`), `aliases_json` = VALUES(`aliases_json`),
  `description` = VALUES(`description`), `status` = 1, `updated_at` = NOW();
INSERT INTO `forum_tags`
  (`admin_id`, `app_id`, `plate_id`, `category_id`, `name`, `aliases_json`, `description`, `sort_order`, `status`, `created_at`, `updated_at`)
SELECT p.`admin_id`, p.`app_id`, p.`id`, c.`id`, '我的世界', '["MC","Minecraft","麦块"]', '我的世界的规范标签及常用别名', 10, 1, NOW(), NOW()
FROM `forum_plates` p INNER JOIN `forum_categories` c ON c.`plate_id` = p.`id` AND c.`name` = '我的世界'
WHERE p.`name` = '游戏交流'
ON DUPLICATE KEY UPDATE `category_id` = VALUES(`category_id`), `aliases_json` = VALUES(`aliases_json`),
  `description` = VALUES(`description`), `status` = 1, `updated_at` = NOW();

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.18-forum-taxonomy-privacy', '论坛二级分类、规范标签、创建申请与动态隐私', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
