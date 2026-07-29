-- 悬赏分类、悬赏附件和论坛版主闭环。兼容 MySQL 5.7/8.0，可重复执行。
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `bounty_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(1000) NOT NULL DEFAULT '',
  `icon` VARCHAR(500) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bounty_categories_name` (`app_id`, `name`),
  UNIQUE KEY `uk_bounty_categories_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_bounty_categories_list` (`admin_id`, `app_id`, `status`, `sort_order`, `id`),
  CONSTRAINT `fk_bounty_categories_app` FOREIGN KEY (`app_id`, `admin_id`)
    REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bounty_category_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(1000) NOT NULL DEFAULT '',
  `reason` VARCHAR(1000) NOT NULL DEFAULT '',
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `reviewer_admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `review_comment` VARCHAR(1000) NOT NULL DEFAULT '',
  `created_category_id` BIGINT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bounty_category_requests_status` (`admin_id`, `app_id`, `status`, `id`),
  KEY `idx_bounty_category_requests_user` (`user_id`, `status`, `id`),
  CONSTRAINT `fk_bounty_category_requests_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bounty_category_requests_reviewer` FOREIGN KEY (`reviewer_admin_id`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bounty_category_requests_created` FOREIGN KEY (`created_category_id`)
    REFERENCES `bounty_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_moderators` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `plate_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `permissions_json` LONGTEXT,
  `status` TINYINT NOT NULL DEFAULT 1,
  `granted_by_admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_moderators_plate_user` (`plate_id`, `user_id`),
  KEY `idx_forum_moderators_user` (`admin_id`, `app_id`, `user_id`, `status`),
  CONSTRAINT `fk_forum_moderators_plate` FOREIGN KEY (`plate_id`, `app_id`, `admin_id`)
    REFERENCES `forum_plates` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_moderators_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_moderators_granter` FOREIGN KEY (`granted_by_admin_id`)
    REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_bounty_category_id := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bounties' AND COLUMN_NAME = 'category_id'
);
SET @bounty_category_id_sql := IF(
  @has_bounty_category_id = 0,
  'ALTER TABLE `bounties` ADD COLUMN `category_id` BIGINT UNSIGNED DEFAULT NULL AFTER `app_id`',
  'SELECT 1'
);
PREPARE bounty_category_id_statement FROM @bounty_category_id_sql;
EXECUTE bounty_category_id_statement;
DEALLOCATE PREPARE bounty_category_id_statement;

SET @has_bounty_attachments := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bounties' AND COLUMN_NAME = 'attachments_json'
);
SET @bounty_attachments_sql := IF(
  @has_bounty_attachments = 0,
  'ALTER TABLE `bounties` ADD COLUMN `attachments_json` LONGTEXT NULL AFTER `requirements_json`',
  'SELECT 1'
);
PREPARE bounty_attachments_statement FROM @bounty_attachments_sql;
EXECUTE bounty_attachments_statement;
DEALLOCATE PREPARE bounty_attachments_statement;

SET @has_bounty_category_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bounties' AND INDEX_NAME = 'idx_bounties_category'
);
SET @bounty_category_index_sql := IF(
  @has_bounty_category_index = 0,
  'ALTER TABLE `bounties` ADD KEY `idx_bounties_category` (`admin_id`, `app_id`, `category_id`, `status`, `id`)',
  'SELECT 1'
);
PREPARE bounty_category_index_statement FROM @bounty_category_index_sql;
EXECUTE bounty_category_index_statement;
DEALLOCATE PREPARE bounty_category_index_statement;

SET @has_bounty_category_fk := (
  SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'bounties'
    AND CONSTRAINT_NAME = 'fk_bounties_category'
);
SET @bounty_category_fk_sql := IF(
  @has_bounty_category_fk = 0,
  'ALTER TABLE `bounties` ADD CONSTRAINT `fk_bounties_category` FOREIGN KEY (`category_id`) REFERENCES `bounty_categories` (`id`) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE bounty_category_fk_statement FROM @bounty_category_fk_sql;
EXECUTE bounty_category_fk_statement;
DEALLOCATE PREPARE bounty_category_fk_statement;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
SELECT '2026.07.22-bounty-moderation', '悬赏分类与附件、论坛版主管理闭环', NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `schema_migrations` WHERE `version` = '2026.07.22-bounty-moderation'
);
