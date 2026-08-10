-- Forum visibility, immutable legacy paid assets and idempotent post publishing.
-- Safe to run repeatedly and does not overwrite administrator setting choices.
SET NAMES utf8mb4;
SET @schema_name = DATABASE();

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'forum_posts'
          AND COLUMN_NAME = 'client_draft_id'
    ),
    'SELECT 1',
    'ALTER TABLE forum_posts ADD COLUMN client_draft_id CHAR(36) NULL AFTER user_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE forum_posts SET client_draft_id = NULL WHERE client_draft_id = '';
UPDATE forum_posts duplicate_post
INNER JOIN forum_posts original_post
  ON original_post.app_id = duplicate_post.app_id
 AND original_post.user_id = duplicate_post.user_id
 AND original_post.client_draft_id = duplicate_post.client_draft_id
 AND original_post.id < duplicate_post.id
SET duplicate_post.client_draft_id = NULL
WHERE duplicate_post.client_draft_id IS NOT NULL;

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'forum_posts'
          AND INDEX_NAME = 'uk_forum_posts_client_draft'
    ),
    'SELECT 1',
    'ALTER TABLE forum_posts ADD UNIQUE KEY uk_forum_posts_client_draft (app_id, user_id, client_draft_id)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'forum_paid_contents'
          AND COLUMN_NAME = 'asset_type'
    ),
    'SELECT 1',
    'ALTER TABLE forum_paid_contents ADD COLUMN asset_type VARCHAR(20) NOT NULL DEFAULT ''balance'' AFTER price_integral'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'forum_post_purchases'
          AND COLUMN_NAME = 'asset_type'
    ),
    'SELECT 1',
    'ALTER TABLE forum_post_purchases ADD COLUMN asset_type VARCHAR(20) NOT NULL DEFAULT ''balance'' AFTER price_integral'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE forum_paid_contents SET asset_type = 'balance' WHERE asset_type IS NULL OR asset_type <> 'balance';
UPDATE forum_post_purchases SET asset_type = 'balance' WHERE asset_type IS NULL OR asset_type <> 'balance';

INSERT INTO app_settings
  (admin_id, app_id, setting_key, setting_value, value_type, created_at, updated_at)
SELECT admin_id, id, 'forum_unlock_max_price_balance', '1000000000', 'float', NOW(), NOW() FROM apps
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO app_settings
  (admin_id, app_id, setting_key, setting_value, value_type, created_at, updated_at)
SELECT admin_id, id, 'forum_unlock_max_future_days', '3650', 'int', NOW(), NOW() FROM apps
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO app_settings
  (admin_id, app_id, setting_key, setting_value, value_type, created_at, updated_at)
SELECT admin_id, id, 'forum_paid_section_max_count', '30', 'int', NOW(), NOW() FROM apps
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO schema_migrations (`version`, `description`, `applied_at`)
VALUES (
  '2026.08.10-forum-data-consistency',
  'Forum approved visibility, legacy balance assets and idempotent client draft publishing',
  NOW()
)
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
