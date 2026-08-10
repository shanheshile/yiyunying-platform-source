-- Forum chapters with paid and scheduled release policies.
-- Idempotent and safe for applications that already customized feature flags.
SET NAMES utf8mb4;
SET @schema_name = DATABASE();

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'forum_post_sections'
          AND COLUMN_NAME = 'asset_type'
    ),
    'SELECT 1',
    'ALTER TABLE forum_post_sections ADD COLUMN asset_type VARCHAR(20) NOT NULL DEFAULT ''balance'' AFTER price_balance'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'forum_section_purchases'
          AND COLUMN_NAME = 'asset_type'
    ),
    'SELECT 1',
    'ALTER TABLE forum_section_purchases ADD COLUMN asset_type VARCHAR(20) NOT NULL DEFAULT ''balance'' AFTER price_balance'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'forum_post_sections'
          AND COLUMN_NAME = 'unlock_at'
    ),
    'SELECT 1',
    'ALTER TABLE forum_post_sections ADD COLUMN unlock_at DATETIME NULL AFTER price_balance'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'forum_post_sections'
          AND COLUMN_NAME = 'preview_content'
    ),
    'SELECT 1',
    'ALTER TABLE forum_post_sections ADD COLUMN preview_content VARCHAR(1000) NOT NULL DEFAULT '''' AFTER unlock_at'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `app_feature_flags`
  (`admin_id`, `app_id`, `feature_code`, `enabled`, `config_json`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, 'forum_chapters', 1, NULL, NOW(), NOW() FROM `apps`
ON DUPLICATE KEY UPDATE `feature_code` = VALUES(`feature_code`);

INSERT INTO `app_feature_flags`
  (`admin_id`, `app_id`, `feature_code`, `enabled`, `config_json`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, 'forum_paid_unlock', 1, NULL, NOW(), NOW() FROM `apps`
ON DUPLICATE KEY UPDATE `feature_code` = VALUES(`feature_code`);

INSERT INTO `app_feature_flags`
  (`admin_id`, `app_id`, `feature_code`, `enabled`, `config_json`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, 'forum_scheduled_unlock', 1, NULL, NOW(), NOW() FROM `apps`
ON DUPLICATE KEY UPDATE `feature_code` = VALUES(`feature_code`);

INSERT INTO `app_feature_flags`
  (`admin_id`, `app_id`, `feature_code`, `enabled`, `config_json`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, 'forum_attachment_unlock', 1, NULL, NOW(), NOW() FROM `apps`
ON DUPLICATE KEY UPDATE `feature_code` = VALUES(`feature_code`);

INSERT INTO `app_feature_flags`
  (`admin_id`, `app_id`, `feature_code`, `enabled`, `config_json`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, 'forum_media_filename_privacy', 1, NULL, NOW(), NOW() FROM `apps`
ON DUPLICATE KEY UPDATE `feature_code` = VALUES(`feature_code`);

INSERT INTO schema_migrations (`version`, `description`, `applied_at`)
VALUES ('2026.08.10-forum-content-unlocks', 'Forum chapters, paid or scheduled release, attachment policies and public media name privacy', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
