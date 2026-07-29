-- 易运盈后台：群聊通话上下文与聊天系统记录（兼容 MySQL 5.7/8.0）
SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'voice_calls' AND COLUMN_NAME = 'context_type') = 0,
  'ALTER TABLE `voice_calls` ADD COLUMN `context_type` VARCHAR(20) NOT NULL DEFAULT ''private'' AFTER `conversation_id`',
  'SELECT 1'
);
PREPARE column_statement FROM @column_sql;
EXECUTE column_statement;
DEALLOCATE PREPARE column_statement;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'voice_calls' AND COLUMN_NAME = 'context_id') = 0,
  'ALTER TABLE `voice_calls` ADD COLUMN `context_id` BIGINT UNSIGNED DEFAULT NULL AFTER `context_type`',
  'SELECT 1'
);
PREPARE column_statement FROM @column_sql;
EXECUTE column_statement;
DEALLOCATE PREPARE column_statement;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'voice_calls' AND COLUMN_NAME = 'context_name') = 0,
  'ALTER TABLE `voice_calls` ADD COLUMN `context_name` VARCHAR(120) NOT NULL DEFAULT '''' AFTER `context_id`',
  'SELECT 1'
);
PREPARE column_statement FROM @column_sql;
EXECUTE column_statement;
DEALLOCATE PREPARE column_statement;

SET @index_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'voice_calls' AND INDEX_NAME = 'idx_voice_calls_context') = 0,
  'ALTER TABLE `voice_calls` ADD INDEX `idx_voice_calls_context` (`app_id`, `context_type`, `context_id`, `created_at`)',
  'SELECT 1'
);
PREPARE index_statement FROM @index_sql;
EXECUTE index_statement;
DEALLOCATE PREPARE index_statement;

UPDATE `voice_calls`
SET `context_type` = 'private'
WHERE `context_type` IS NULL OR `context_type` NOT IN ('private', 'room');

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.15-call-context', '群聊通话上下文与聊天系统记录', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
