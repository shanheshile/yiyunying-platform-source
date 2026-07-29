SET @schema_name = DATABASE();

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'resources' AND COLUMN_NAME = 'size_bytes'),
    'SELECT 1',
    'ALTER TABLE resources ADD COLUMN size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER download_url'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'resources' AND COLUMN_NAME = 'file_sha256'),
    'SELECT 1',
    'ALTER TABLE resources ADD COLUMN file_sha256 CHAR(64) NOT NULL DEFAULT '''' AFTER download_url'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'resources' AND COLUMN_NAME = 'risk_level'),
    'SELECT 1',
    'ALTER TABLE resources ADD COLUMN risk_level VARCHAR(20) NOT NULL DEFAULT ''review'' AFTER file_sha256'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'resources' AND COLUMN_NAME = 'risk_reason'),
    'SELECT 1',
    'ALTER TABLE resources ADD COLUMN risk_reason VARCHAR(1000) NOT NULL DEFAULT '''' AFTER risk_level'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'resources'
          AND COLUMN_NAME = 'risk_reason'
          AND COALESCE(CHARACTER_MAXIMUM_LENGTH, 0) < 1000
    ),
    'ALTER TABLE resources MODIFY COLUMN risk_reason VARCHAR(1000) NOT NULL DEFAULT ''''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'resources' AND COLUMN_NAME = 'source_upload_id'),
    'SELECT 1',
    'ALTER TABLE resources ADD COLUMN source_upload_id BIGINT UNSIGNED NULL AFTER risk_reason'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'resources' AND COLUMN_NAME = 'cover_upload_id'),
    'SELECT 1',
    'ALTER TABLE resources ADD COLUMN cover_upload_id BIGINT UNSIGNED NULL AFTER source_upload_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'resources' AND INDEX_NAME = 'idx_resources_risk'),
    'SELECT 1',
    'ALTER TABLE resources ADD INDEX idx_resources_risk (app_id, risk_level, audit_status)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'store_apps' AND COLUMN_NAME = 'metadata_json'),
    'SELECT 1',
    'ALTER TABLE store_apps ADD COLUMN metadata_json LONGTEXT NULL AFTER description'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'store_apps' AND COLUMN_NAME = 'file_sha256'),
    'SELECT 1',
    'ALTER TABLE store_apps ADD COLUMN file_sha256 CHAR(64) NOT NULL DEFAULT '''' AFTER metadata_json'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'store_apps' AND COLUMN_NAME = 'risk_level'),
    'SELECT 1',
    'ALTER TABLE store_apps ADD COLUMN risk_level VARCHAR(20) NOT NULL DEFAULT ''review'' AFTER file_sha256'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'store_apps' AND COLUMN_NAME = 'risk_reason'),
    'SELECT 1',
    'ALTER TABLE store_apps ADD COLUMN risk_reason VARCHAR(1000) NOT NULL DEFAULT '''' AFTER risk_level'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'store_apps'
          AND COLUMN_NAME = 'risk_reason'
          AND COALESCE(CHARACTER_MAXIMUM_LENGTH, 0) < 1000
    ),
    'ALTER TABLE store_apps MODIFY COLUMN risk_reason VARCHAR(1000) NOT NULL DEFAULT ''''',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'store_apps' AND COLUMN_NAME = 'source_upload_id'),
    'SELECT 1',
    'ALTER TABLE store_apps ADD COLUMN source_upload_id BIGINT UNSIGNED NULL AFTER risk_reason'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'store_apps' AND COLUMN_NAME = 'icon_upload_id'),
    'SELECT 1',
    'ALTER TABLE store_apps ADD COLUMN icon_upload_id BIGINT UNSIGNED NULL AFTER source_upload_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'store_apps' AND COLUMN_NAME = 'audit_status'),
    'SELECT 1',
    'ALTER TABLE store_apps ADD COLUMN audit_status VARCHAR(20) NOT NULL DEFAULT ''pending'' AFTER icon_upload_id'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'store_apps' AND COLUMN_NAME = 'audit_reason'),
    'SELECT 1',
    'ALTER TABLE store_apps ADD COLUMN audit_reason VARCHAR(500) NOT NULL DEFAULT '''' AFTER audit_status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'store_apps' AND INDEX_NAME = 'idx_store_apps_audit'),
    'SELECT 1',
    'ALTER TABLE store_apps ADD INDEX idx_store_apps_audit (app_id, audit_status, risk_level, status)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE store_apps
SET audit_status = 'approved', risk_level = IF(risk_level = 'review', 'low', risk_level)
WHERE status = 1 AND audit_status = 'pending';

INSERT IGNORE INTO schema_migrations (version, applied_at)
VALUES ('2026.07.25-submission-risk-metadata', NOW());
