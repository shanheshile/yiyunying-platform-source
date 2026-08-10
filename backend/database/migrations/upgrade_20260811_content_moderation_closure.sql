-- Real moderation closure for moments and moment comments.
-- Existing content stays approved; new content follows the two administrator settings below.
SET NAMES utf8mb4;
SET @schema_name = DATABASE();

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'user_moments' AND COLUMN_NAME = 'audit_status'),
  'SELECT 1',
  'ALTER TABLE user_moments ADD COLUMN audit_status VARCHAR(20) NOT NULL DEFAULT ''approved'' AFTER pin_order'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'user_moments' AND COLUMN_NAME = 'audit_reason'),
  'SELECT 1',
  'ALTER TABLE user_moments ADD COLUMN audit_reason VARCHAR(500) NOT NULL DEFAULT '''' AFTER audit_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'user_moments' AND COLUMN_NAME = 'audited_by'),
  'SELECT 1',
  'ALTER TABLE user_moments ADD COLUMN audited_by BIGINT UNSIGNED NULL AFTER audit_reason'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'user_moments' AND COLUMN_NAME = 'audited_at'),
  'SELECT 1',
  'ALTER TABLE user_moments ADD COLUMN audited_at DATETIME NULL AFTER audited_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'user_moments' AND INDEX_NAME = 'idx_user_moments_moderation'),
  'SELECT 1',
  'ALTER TABLE user_moments ADD KEY idx_user_moments_moderation (admin_id, app_id, audit_status, status, deleted_at, created_at)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @schema_name AND TABLE_NAME = 'user_moments' AND CONSTRAINT_NAME = 'fk_user_moments_auditor'),
  'SELECT 1',
  'ALTER TABLE user_moments ADD CONSTRAINT fk_user_moments_auditor FOREIGN KEY (audited_by) REFERENCES admins(id) ON DELETE SET NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'moment_comments' AND COLUMN_NAME = 'audit_status'),
  'SELECT 1',
  'ALTER TABLE moment_comments ADD COLUMN audit_status VARCHAR(20) NOT NULL DEFAULT ''approved'' AFTER content'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'moment_comments' AND COLUMN_NAME = 'audit_reason'),
  'SELECT 1',
  'ALTER TABLE moment_comments ADD COLUMN audit_reason VARCHAR(500) NOT NULL DEFAULT '''' AFTER audit_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'moment_comments' AND COLUMN_NAME = 'audited_by'),
  'SELECT 1',
  'ALTER TABLE moment_comments ADD COLUMN audited_by BIGINT UNSIGNED NULL AFTER audit_reason'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'moment_comments' AND COLUMN_NAME = 'audited_at'),
  'SELECT 1',
  'ALTER TABLE moment_comments ADD COLUMN audited_at DATETIME NULL AFTER audited_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'moment_comments' AND INDEX_NAME = 'idx_moment_comments_moderation'),
  'SELECT 1',
  'ALTER TABLE moment_comments ADD KEY idx_moment_comments_moderation (admin_id, app_id, audit_status, status, created_at)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @schema_name AND TABLE_NAME = 'moment_comments' AND CONSTRAINT_NAME = 'fk_moment_comments_auditor'),
  'SELECT 1',
  'ALTER TABLE moment_comments ADD CONSTRAINT fk_moment_comments_auditor FOREIGN KEY (audited_by) REFERENCES admins(id) ON DELETE SET NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'forum_comments' AND COLUMN_NAME = 'mentions_json'),
  'SELECT 1',
  'ALTER TABLE forum_comments ADD COLUMN mentions_json LONGTEXT NULL AFTER tags_json'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO app_settings
  (admin_id, app_id, setting_key, setting_value, value_type, created_at, updated_at)
SELECT admin_id, id, 'moment_post_audit', '0', 'bool', NOW(), NOW() FROM apps
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO app_settings
  (admin_id, app_id, setting_key, setting_value, value_type, created_at, updated_at)
SELECT admin_id, id, 'moment_comment_audit', '0', 'bool', NOW(), NOW() FROM apps
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO schema_migrations (`version`, `description`, `applied_at`)
VALUES ('2026.08.11-content-moderation-closure', 'Moment and comment moderation with decisions, reasons and auditors', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
