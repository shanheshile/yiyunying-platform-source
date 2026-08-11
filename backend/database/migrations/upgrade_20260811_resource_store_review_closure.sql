SET @schema_name = DATABASE();

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'resource_purchases' AND COLUMN_NAME = 'asset_type'),
    'SELECT 1',
    'ALTER TABLE resource_purchases ADD COLUMN asset_type VARCHAR(20) NOT NULL DEFAULT ''balance'' AFTER price_integral'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS upload_file_deletions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    upload_id BIGINT UNSIGNED DEFAULT NULL,
    file_path VARCHAR(1000) NOT NULL,
    path_sha256 CHAR(64) NOT NULL,
    cleanup_status VARCHAR(20) NOT NULL DEFAULT 'cleanup_pending',
    cleanup_error VARCHAR(500) NOT NULL DEFAULT '',
    cleaned_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_upload_file_deletion_path (path_sha256),
    KEY idx_upload_file_deletion_status (cleanup_status, updated_at),
    CONSTRAINT fk_upload_file_deletion_upload FOREIGN KEY (upload_id)
        REFERENCES uploads (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_app_purchases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id BIGINT UNSIGNED NOT NULL,
    app_id BIGINT UNSIGNED NOT NULL,
    store_app_id BIGINT UNSIGNED NOT NULL,
    buyer_user_id BIGINT UNSIGNED NOT NULL,
    seller_user_id BIGINT UNSIGNED DEFAULT NULL,
    price_balance DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    asset_type VARCHAR(20) NOT NULL DEFAULT 'balance',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_store_app_purchase_buyer (store_app_id, buyer_user_id),
    KEY idx_store_app_purchases_buyer (app_id, buyer_user_id, created_at),
    CONSTRAINT fk_store_app_purchase_app FOREIGN KEY (store_app_id, app_id, admin_id)
        REFERENCES store_apps (id, app_id, admin_id) ON DELETE RESTRICT,
    CONSTRAINT fk_store_app_purchase_buyer FOREIGN KEY (buyer_user_id, app_id, admin_id)
        REFERENCES users (id, app_id, admin_id) ON DELETE RESTRICT,
    CONSTRAINT fk_store_app_purchase_seller FOREIGN KEY (seller_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purchase history is an accounting record. Subjects and buyers cannot be
-- removed while referenced; seller attribution may be anonymised by setting it
-- to NULL. The seller constraint intentionally references users(id) alone:
-- MySQL SET NULL would otherwise try to null the non-null app/admin tenant keys.
-- Locate constraints by their identifying column instead of assuming a name,
-- because older installations may use generated constraint names.
SET @purchase_fk_name = (
    SELECT kcu.CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE AS kcu
    WHERE kcu.CONSTRAINT_SCHEMA = @schema_name
      AND kcu.TABLE_NAME = 'resource_purchases'
      AND kcu.COLUMN_NAME = 'resource_id'
      AND kcu.REFERENCED_TABLE_SCHEMA = @schema_name
      AND kcu.REFERENCED_TABLE_NAME = 'resources'
    ORDER BY kcu.ORDINAL_POSITION
    LIMIT 1
);
SET @purchase_fk_delete_rule = (
    SELECT rc.DELETE_RULE
    FROM information_schema.REFERENTIAL_CONSTRAINTS AS rc
    WHERE rc.CONSTRAINT_SCHEMA = @schema_name
      AND rc.TABLE_NAME = 'resource_purchases'
      AND rc.CONSTRAINT_NAME = @purchase_fk_name
    LIMIT 1
);
SET @sql = IF(
    @purchase_fk_name IS NULL,
    'ALTER TABLE `resource_purchases` ADD CONSTRAINT `fk_resource_purchase_resource` FOREIGN KEY (`resource_id`, `app_id`, `admin_id`) REFERENCES `resources` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT',
    IF(
        @purchase_fk_delete_rule IN ('RESTRICT', 'NO ACTION'),
        'SELECT 1',
        CONCAT(
            'ALTER TABLE `resource_purchases` DROP FOREIGN KEY `',
            REPLACE(@purchase_fk_name, '`', '``'),
            '`, ADD CONSTRAINT `',
            REPLACE(@purchase_fk_name, '`', '``'),
            '` FOREIGN KEY (`resource_id`, `app_id`, `admin_id`) REFERENCES `resources` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT'
        )
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @purchase_fk_name = (
    SELECT kcu.CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE AS kcu
    WHERE kcu.CONSTRAINT_SCHEMA = @schema_name
      AND kcu.TABLE_NAME = 'resource_purchases'
      AND kcu.COLUMN_NAME = 'buyer_user_id'
      AND kcu.REFERENCED_TABLE_SCHEMA = @schema_name
      AND kcu.REFERENCED_TABLE_NAME = 'users'
    ORDER BY kcu.ORDINAL_POSITION
    LIMIT 1
);
SET @purchase_fk_delete_rule = (
    SELECT rc.DELETE_RULE
    FROM information_schema.REFERENTIAL_CONSTRAINTS AS rc
    WHERE rc.CONSTRAINT_SCHEMA = @schema_name
      AND rc.TABLE_NAME = 'resource_purchases'
      AND rc.CONSTRAINT_NAME = @purchase_fk_name
    LIMIT 1
);
SET @sql = IF(
    @purchase_fk_name IS NULL,
    'ALTER TABLE `resource_purchases` ADD CONSTRAINT `fk_resource_purchase_buyer` FOREIGN KEY (`buyer_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT',
    IF(
        @purchase_fk_delete_rule IN ('RESTRICT', 'NO ACTION'),
        'SELECT 1',
        CONCAT(
            'ALTER TABLE `resource_purchases` DROP FOREIGN KEY `',
            REPLACE(@purchase_fk_name, '`', '``'),
            '`, ADD CONSTRAINT `',
            REPLACE(@purchase_fk_name, '`', '``'),
            '` FOREIGN KEY (`buyer_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT'
        )
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @purchase_fk_name = (
    SELECT kcu.CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE AS kcu
    WHERE kcu.CONSTRAINT_SCHEMA = @schema_name
      AND kcu.TABLE_NAME = 'resource_purchases'
      AND kcu.COLUMN_NAME = 'seller_user_id'
      AND kcu.REFERENCED_TABLE_SCHEMA = @schema_name
      AND kcu.REFERENCED_TABLE_NAME = 'users'
    ORDER BY kcu.ORDINAL_POSITION
    LIMIT 1
);
SET @purchase_fk_delete_rule = (
    SELECT rc.DELETE_RULE
    FROM information_schema.REFERENTIAL_CONSTRAINTS AS rc
    WHERE rc.CONSTRAINT_SCHEMA = @schema_name
      AND rc.TABLE_NAME = 'resource_purchases'
      AND rc.CONSTRAINT_NAME = @purchase_fk_name
    LIMIT 1
);
SET @purchase_fk_column_count = (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE AS kcu
    WHERE kcu.CONSTRAINT_SCHEMA = @schema_name
      AND kcu.TABLE_NAME = 'resource_purchases'
      AND kcu.CONSTRAINT_NAME = @purchase_fk_name
);
SET @sql = IF(
    @purchase_fk_name IS NULL,
    'ALTER TABLE `resource_purchases` ADD CONSTRAINT `fk_resource_purchase_seller` FOREIGN KEY (`seller_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL',
    IF(
        @purchase_fk_delete_rule = 'SET NULL' AND @purchase_fk_column_count = 1,
        'SELECT 1',
        CONCAT(
            'ALTER TABLE `resource_purchases` DROP FOREIGN KEY `',
            REPLACE(@purchase_fk_name, '`', '``'),
            '`, ADD CONSTRAINT `',
            REPLACE(@purchase_fk_name, '`', '``'),
            '` FOREIGN KEY (`seller_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL'
        )
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @purchase_fk_name = (
    SELECT kcu.CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE AS kcu
    WHERE kcu.CONSTRAINT_SCHEMA = @schema_name
      AND kcu.TABLE_NAME = 'store_app_purchases'
      AND kcu.COLUMN_NAME = 'store_app_id'
      AND kcu.REFERENCED_TABLE_SCHEMA = @schema_name
      AND kcu.REFERENCED_TABLE_NAME = 'store_apps'
    ORDER BY kcu.ORDINAL_POSITION
    LIMIT 1
);
SET @purchase_fk_delete_rule = (
    SELECT rc.DELETE_RULE
    FROM information_schema.REFERENTIAL_CONSTRAINTS AS rc
    WHERE rc.CONSTRAINT_SCHEMA = @schema_name
      AND rc.TABLE_NAME = 'store_app_purchases'
      AND rc.CONSTRAINT_NAME = @purchase_fk_name
    LIMIT 1
);
SET @sql = IF(
    @purchase_fk_name IS NULL,
    'ALTER TABLE `store_app_purchases` ADD CONSTRAINT `fk_store_app_purchase_app` FOREIGN KEY (`store_app_id`, `app_id`, `admin_id`) REFERENCES `store_apps` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT',
    IF(
        @purchase_fk_delete_rule IN ('RESTRICT', 'NO ACTION'),
        'SELECT 1',
        CONCAT(
            'ALTER TABLE `store_app_purchases` DROP FOREIGN KEY `',
            REPLACE(@purchase_fk_name, '`', '``'),
            '`, ADD CONSTRAINT `',
            REPLACE(@purchase_fk_name, '`', '``'),
            '` FOREIGN KEY (`store_app_id`, `app_id`, `admin_id`) REFERENCES `store_apps` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT'
        )
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @purchase_fk_name = (
    SELECT kcu.CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE AS kcu
    WHERE kcu.CONSTRAINT_SCHEMA = @schema_name
      AND kcu.TABLE_NAME = 'store_app_purchases'
      AND kcu.COLUMN_NAME = 'buyer_user_id'
      AND kcu.REFERENCED_TABLE_SCHEMA = @schema_name
      AND kcu.REFERENCED_TABLE_NAME = 'users'
    ORDER BY kcu.ORDINAL_POSITION
    LIMIT 1
);
SET @purchase_fk_delete_rule = (
    SELECT rc.DELETE_RULE
    FROM information_schema.REFERENTIAL_CONSTRAINTS AS rc
    WHERE rc.CONSTRAINT_SCHEMA = @schema_name
      AND rc.TABLE_NAME = 'store_app_purchases'
      AND rc.CONSTRAINT_NAME = @purchase_fk_name
    LIMIT 1
);
SET @sql = IF(
    @purchase_fk_name IS NULL,
    'ALTER TABLE `store_app_purchases` ADD CONSTRAINT `fk_store_app_purchase_buyer` FOREIGN KEY (`buyer_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT',
    IF(
        @purchase_fk_delete_rule IN ('RESTRICT', 'NO ACTION'),
        'SELECT 1',
        CONCAT(
            'ALTER TABLE `store_app_purchases` DROP FOREIGN KEY `',
            REPLACE(@purchase_fk_name, '`', '``'),
            '`, ADD CONSTRAINT `',
            REPLACE(@purchase_fk_name, '`', '``'),
            '` FOREIGN KEY (`buyer_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT'
        )
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @purchase_fk_name = (
    SELECT kcu.CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE AS kcu
    WHERE kcu.CONSTRAINT_SCHEMA = @schema_name
      AND kcu.TABLE_NAME = 'store_app_purchases'
      AND kcu.COLUMN_NAME = 'seller_user_id'
      AND kcu.REFERENCED_TABLE_SCHEMA = @schema_name
      AND kcu.REFERENCED_TABLE_NAME = 'users'
    ORDER BY kcu.ORDINAL_POSITION
    LIMIT 1
);
SET @purchase_fk_delete_rule = (
    SELECT rc.DELETE_RULE
    FROM information_schema.REFERENTIAL_CONSTRAINTS AS rc
    WHERE rc.CONSTRAINT_SCHEMA = @schema_name
      AND rc.TABLE_NAME = 'store_app_purchases'
      AND rc.CONSTRAINT_NAME = @purchase_fk_name
    LIMIT 1
);
SET @purchase_fk_column_count = (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE AS kcu
    WHERE kcu.CONSTRAINT_SCHEMA = @schema_name
      AND kcu.TABLE_NAME = 'store_app_purchases'
      AND kcu.CONSTRAINT_NAME = @purchase_fk_name
);
SET @sql = IF(
    @purchase_fk_name IS NULL,
    'ALTER TABLE `store_app_purchases` ADD CONSTRAINT `fk_store_app_purchase_seller` FOREIGN KEY (`seller_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL',
    IF(
        @purchase_fk_delete_rule = 'SET NULL' AND @purchase_fk_column_count = 1,
        'SELECT 1',
        CONCAT(
            'ALTER TABLE `store_app_purchases` DROP FOREIGN KEY `',
            REPLACE(@purchase_fk_name, '`', '``'),
            '`, ADD CONSTRAINT `',
            REPLACE(@purchase_fk_name, '`', '``'),
            '` FOREIGN KEY (`seller_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL'
        )
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Categories are durable catalog identities. A category with any current or
-- soft-deleted child remains undeletable; controller row locks close the
-- check/delete race, while these constraints provide the database backstop.
SET @category_fk_name = (
    SELECT kcu.CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE AS kcu
    WHERE kcu.CONSTRAINT_SCHEMA = @schema_name
      AND kcu.TABLE_NAME = 'resources'
      AND kcu.COLUMN_NAME = 'category_id'
      AND kcu.REFERENCED_TABLE_SCHEMA = @schema_name
      AND kcu.REFERENCED_TABLE_NAME = 'resource_categories'
    ORDER BY kcu.ORDINAL_POSITION
    LIMIT 1
);
SET @category_fk_delete_rule = (
    SELECT rc.DELETE_RULE
    FROM information_schema.REFERENTIAL_CONSTRAINTS AS rc
    WHERE rc.CONSTRAINT_SCHEMA = @schema_name
      AND rc.TABLE_NAME = 'resources'
      AND rc.CONSTRAINT_NAME = @category_fk_name
    LIMIT 1
);
SET @sql = IF(
    @category_fk_name IS NULL,
    'ALTER TABLE `resources` ADD CONSTRAINT `fk_resources_category` FOREIGN KEY (`category_id`, `app_id`, `admin_id`) REFERENCES `resource_categories` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT',
    IF(
        @category_fk_delete_rule IN ('RESTRICT', 'NO ACTION'),
        'SELECT 1',
        CONCAT(
            'ALTER TABLE `resources` DROP FOREIGN KEY `',
            REPLACE(@category_fk_name, '`', '``'),
            '`, ADD CONSTRAINT `',
            REPLACE(@category_fk_name, '`', '``'),
            '` FOREIGN KEY (`category_id`, `app_id`, `admin_id`) REFERENCES `resource_categories` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT'
        )
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @category_fk_name = (
    SELECT kcu.CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE AS kcu
    WHERE kcu.CONSTRAINT_SCHEMA = @schema_name
      AND kcu.TABLE_NAME = 'store_apps'
      AND kcu.COLUMN_NAME = 'category_id'
      AND kcu.REFERENCED_TABLE_SCHEMA = @schema_name
      AND kcu.REFERENCED_TABLE_NAME = 'store_categories'
    ORDER BY kcu.ORDINAL_POSITION
    LIMIT 1
);
SET @category_fk_delete_rule = (
    SELECT rc.DELETE_RULE
    FROM information_schema.REFERENTIAL_CONSTRAINTS AS rc
    WHERE rc.CONSTRAINT_SCHEMA = @schema_name
      AND rc.TABLE_NAME = 'store_apps'
      AND rc.CONSTRAINT_NAME = @category_fk_name
    LIMIT 1
);
SET @sql = IF(
    @category_fk_name IS NULL,
    'ALTER TABLE `store_apps` ADD CONSTRAINT `fk_store_apps_category` FOREIGN KEY (`category_id`, `app_id`, `admin_id`) REFERENCES `store_categories` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT',
    IF(
        @category_fk_delete_rule IN ('RESTRICT', 'NO ACTION'),
        'SELECT 1',
        CONCAT(
            'ALTER TABLE `store_apps` DROP FOREIGN KEY `',
            REPLACE(@category_fk_name, '`', '``'),
            '`, ADD CONSTRAINT `',
            REPLACE(@category_fk_name, '`', '``'),
            '` FOREIGN KEY (`category_id`, `app_id`, `admin_id`) REFERENCES `store_categories` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT'
        )
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS catalog_file_migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id BIGINT UNSIGNED NOT NULL,
    app_id BIGINT UNSIGNED NOT NULL,
    upload_id BIGINT UNSIGNED NOT NULL,
    old_file_path VARCHAR(1000) NOT NULL,
    new_file_path VARCHAR(1000) NOT NULL,
    file_sha256 CHAR(64) NOT NULL DEFAULT '',
    file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    cleanup_status VARCHAR(20) NOT NULL DEFAULT 'cleanup_pending',
    cleanup_error VARCHAR(500) NOT NULL DEFAULT '',
    cleaned_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_catalog_file_migration_upload (upload_id),
    KEY idx_catalog_file_migration_cleanup (admin_id, app_id, cleanup_status),
    KEY idx_catalog_file_migration_old_path (old_file_path(191)),
    KEY idx_catalog_file_migration_sha256 (file_sha256),
    CONSTRAINT fk_catalog_file_migration_upload FOREIGN KEY (upload_id)
        REFERENCES uploads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'catalog_file_migrations' AND COLUMN_NAME = 'file_size_bytes'),
    'SELECT 1',
    'ALTER TABLE catalog_file_migrations ADD COLUMN file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER file_sha256'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'uploads' AND INDEX_NAME = 'idx_uploads_file_path'),
    'SELECT 1',
    'ALTER TABLE uploads ADD INDEX idx_uploads_file_path (file_path(191))'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'uploads' AND INDEX_NAME = 'idx_uploads_scene_sha256'),
    'SELECT 1',
    'ALTER TABLE uploads ADD INDEX idx_uploads_scene_sha256 (scene, sha256)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'catalog_file_migrations' AND INDEX_NAME = 'idx_catalog_file_migration_old_path'),
    'SELECT 1',
    'ALTER TABLE catalog_file_migrations ADD INDEX idx_catalog_file_migration_old_path (old_file_path(191))'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'catalog_file_migrations' AND INDEX_NAME = 'idx_catalog_file_migration_sha256'),
    'SELECT 1',
    'ALTER TABLE catalog_file_migrations ADD INDEX idx_catalog_file_migration_sha256 (file_sha256)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'resources' AND COLUMN_NAME = 'audited_by'),
    'SELECT 1',
    'ALTER TABLE resources ADD COLUMN audited_by BIGINT UNSIGNED NULL AFTER audit_reason'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'resources' AND COLUMN_NAME = 'audited_at'),
    'SELECT 1',
    'ALTER TABLE resources ADD COLUMN audited_at DATETIME NULL AFTER audited_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @schema_name AND TABLE_NAME = 'resources' AND CONSTRAINT_NAME = 'fk_resources_auditor'),
    'SELECT 1',
    'ALTER TABLE resources ADD CONSTRAINT fk_resources_auditor FOREIGN KEY (audited_by) REFERENCES admins(id) ON DELETE SET NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'store_apps' AND COLUMN_NAME = 'audited_by'),
    'SELECT 1',
    'ALTER TABLE store_apps ADD COLUMN audited_by BIGINT UNSIGNED NULL AFTER audit_reason'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'store_apps' AND COLUMN_NAME = 'audited_at'),
    'SELECT 1',
    'ALTER TABLE store_apps ADD COLUMN audited_at DATETIME NULL AFTER audited_by'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = @schema_name AND TABLE_NAME = 'store_apps' AND CONSTRAINT_NAME = 'fk_store_apps_auditor'),
    'SELECT 1',
    'ALTER TABLE store_apps ADD CONSTRAINT fk_store_apps_auditor FOREIGN KEY (audited_by) REFERENCES admins(id) ON DELETE SET NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE resources
SET audit_status = 'pending', audit_reason = '', status = 0, audited_by = NULL, audited_at = NULL
WHERE audit_status NOT IN ('pending', 'approved', 'rejected', 'on_hold');

UPDATE store_apps
SET audit_status = 'pending', audit_reason = '', status = 0, audited_by = NULL, audited_at = NULL
WHERE audit_status NOT IN ('pending', 'approved', 'rejected', 'on_hold');

UPDATE resources
SET audit_status = 'on_hold', audit_reason = '目录价格超出安全整数范围，请管理员重新核价',
    status = 0, audited_by = NULL, audited_at = NULL
WHERE price_integral < 0 OR price_integral > 1000000000 OR price_integral <> FLOOR(price_integral);

UPDATE store_apps
SET audit_status = 'on_hold', audit_reason = '目录价格超出安全整数范围，请管理员重新核价',
    status = 0, audited_by = NULL, audited_at = NULL
WHERE price_integral < 0 OR price_integral > 1000000000 OR price_integral <> FLOOR(price_integral);

UPDATE resources
SET audit_status = 'on_hold', audit_reason = '旧条目缺少受控上传记录，请重新上传文件后再审核',
    status = 0, audited_by = NULL, audited_at = NULL
WHERE source_upload_id IS NULL AND deleted_at IS NULL;

UPDATE store_apps
SET audit_status = 'on_hold', audit_reason = '旧条目缺少受控安装包记录，请重新上传文件后再审核',
    status = 0, audited_by = NULL, audited_at = NULL
WHERE source_upload_id IS NULL AND deleted_at IS NULL;

INSERT IGNORE INTO app_settings
    (admin_id, app_id, setting_key, setting_value, value_type, created_at, updated_at)
SELECT admin_id, id, 'resource_user_submit_enabled', '1', 'bool', NOW(), NOW()
FROM apps WHERE deleted_at IS NULL;

INSERT IGNORE INTO app_settings
    (admin_id, app_id, setting_key, setting_value, value_type, created_at, updated_at)
SELECT admin_id, id, 'resource_submit_audit', '1', 'bool', NOW(), NOW()
FROM apps WHERE deleted_at IS NULL;

INSERT IGNORE INTO app_settings
    (admin_id, app_id, setting_key, setting_value, value_type, created_at, updated_at)
SELECT admin_id, id, 'store_user_submit_enabled', '1', 'bool', NOW(), NOW()
FROM apps WHERE deleted_at IS NULL;

INSERT IGNORE INTO app_settings
    (admin_id, app_id, setting_key, setting_value, value_type, created_at, updated_at)
SELECT admin_id, id, 'store_submit_audit', '1', 'bool', NOW(), NOW()
FROM apps WHERE deleted_at IS NULL;

INSERT INTO app_settings
    (admin_id, app_id, setting_key, setting_value, value_type, created_at, updated_at)
SELECT admin_id, id, 'catalog_private_migration_ready', '0', 'bool', NOW(), NOW()
FROM apps WHERE deleted_at IS NULL
ON DUPLICATE KEY UPDATE setting_value = '0', value_type = 'bool', updated_at = NOW();

INSERT IGNORE INTO schema_migrations (version, applied_at)
VALUES ('2026.08.11-resource-store-review-closure', NOW());
