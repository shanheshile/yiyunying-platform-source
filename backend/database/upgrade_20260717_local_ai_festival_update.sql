-- 易运盈后台 2026-07-17：本地 AI、节日界面与应用内更新增量升级
-- 执行前请备份数据库。本脚本兼容 MySQL 5.7+/8.0 与 MariaDB 10.3+，可重复执行。
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS `yy_add_column`;
DELIMITER $$
CREATE PROCEDURE `yy_add_column`(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column
  ) THEN
    SET @yy_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
    PREPARE yy_stmt FROM @yy_sql;
    EXECUTE yy_stmt;
    DEALLOCATE PREPARE yy_stmt;
  END IF;
END$$
DELIMITER ;

CALL `yy_add_column`('app_versions', 'package_name', 'VARCHAR(190) NOT NULL DEFAULT '''' AFTER `apk_url`');
CALL `yy_add_column`('app_versions', 'sha256', 'CHAR(64) NOT NULL DEFAULT '''' AFTER `package_name`');
CALL `yy_add_column`('app_versions', 'size_bytes', 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `sha256`');
CALL `yy_add_column`('software_update_policies', 'package_name', 'VARCHAR(190) NOT NULL DEFAULT '''' AFTER `download_url`');
CALL `yy_add_column`('software_update_policies', 'sha256', 'CHAR(64) NOT NULL DEFAULT '''' AFTER `package_name`');
CALL `yy_add_column`('software_update_policies', 'size_bytes', 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `sha256`');

DROP PROCEDURE IF EXISTS `yy_add_column`;

CREATE TABLE IF NOT EXISTS `festival_theme_policies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `issuer_type` VARCHAR(20) NOT NULL,
  `issuer_id` BIGINT UNSIGNED NOT NULL,
  `issuer_level` TINYINT UNSIGNED NOT NULL,
  `edition_code` VARCHAR(40) NOT NULL,
  `target_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED DEFAULT NULL,
  `target_level` TINYINT UNSIGNED DEFAULT NULL,
  `theme_code` VARCHAR(80) NOT NULL,
  `title` VARCHAR(160) NOT NULL,
  `greeting` VARCHAR(500) NOT NULL DEFAULT '',
  `background_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `accent_color` VARCHAR(20) NOT NULL DEFAULT '#1677FF',
  `action_text` VARCHAR(80) NOT NULL DEFAULT '',
  `action_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `config_json` LONGTEXT,
  `priority` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_festival_theme_match` (`edition_code`, `target_type`, `target_id`, `target_level`, `status`, `starts_at`, `ends_at`),
  KEY `idx_festival_theme_issuer` (`issuer_type`, `issuer_id`, `status`, `id`),
  UNIQUE KEY `uk_festival_theme_issuer_code_start` (`issuer_type`, `issuer_id`, `edition_code`, `theme_code`, `starts_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_knowledge_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `root_platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `scope_type` ENUM('global','platform','admin','app') NOT NULL DEFAULT 'app',
  `platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `content` LONGTEXT NOT NULL,
  `keywords` VARCHAR(1000) NOT NULL DEFAULT '',
  `source_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `priority` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_by_type` VARCHAR(20) NOT NULL DEFAULT 'platform',
  `created_by_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_knowledge_scope` (`scope_type`, `root_platform_id`, `platform_id`, `admin_id`, `app_id`, `status`, `priority`),
  KEY `idx_ai_knowledge_creator` (`created_by_type`, `created_by_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_conversations_user` (`app_id`, `user_id`, `updated_at`, `id`),
  CONSTRAINT `fk_ai_conversations_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `role` ENUM('user','assistant','system','tool') NOT NULL,
  `content` LONGTEXT NOT NULL,
  `provider` VARCHAR(40) NOT NULL DEFAULT '',
  `model` VARCHAR(120) NOT NULL DEFAULT '',
  `metadata_json` LONGTEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_messages_conversation` (`conversation_id`, `id`),
  KEY `idx_ai_messages_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_ai_messages_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
