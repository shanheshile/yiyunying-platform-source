-- 个人资料动态置顶。兼容 MySQL 5.7/8.0，可重复执行。
SET @has_moment_is_pinned := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_moments' AND COLUMN_NAME = 'is_pinned'
);
SET @moment_is_pinned_sql := IF(
  @has_moment_is_pinned = 0,
  'ALTER TABLE `user_moments` ADD COLUMN `is_pinned` TINYINT(1) NOT NULL DEFAULT 0 AFTER `visibility_user_ids_json`',
  'SELECT 1'
);
PREPARE moment_is_pinned_statement FROM @moment_is_pinned_sql;
EXECUTE moment_is_pinned_statement;
DEALLOCATE PREPARE moment_is_pinned_statement;

SET @has_moment_pin_order := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_moments' AND COLUMN_NAME = 'pin_order'
);
SET @moment_pin_order_sql := IF(
  @has_moment_pin_order = 0,
  'ALTER TABLE `user_moments` ADD COLUMN `pin_order` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_pinned`',
  'SELECT 1'
);
PREPARE moment_pin_order_statement FROM @moment_pin_order_sql;
EXECUTE moment_pin_order_statement;
DEALLOCATE PREPARE moment_pin_order_statement;

SET @has_moment_pin_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_moments' AND INDEX_NAME = 'idx_user_moments_pinned'
);
SET @moment_pin_index_sql := IF(
  @has_moment_pin_index = 0,
  'ALTER TABLE `user_moments` ADD KEY `idx_user_moments_pinned` (`user_id`, `is_pinned`, `pin_order`, `created_at`)',
  'SELECT 1'
);
PREPARE moment_pin_index_statement FROM @moment_pin_index_sql;
EXECUTE moment_pin_index_statement;
DEALLOCATE PREPARE moment_pin_index_statement;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
SELECT '2026.07.21-moment-pins', '个人资料动态置顶与自定义顺序', NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `schema_migrations` WHERE `version` = '2026.07.21-moment-pins'
);
