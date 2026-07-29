-- 易运盈后台：应用内视频通话升级（兼容 MySQL 5.7/8.0）
SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'voice_calls' AND COLUMN_NAME = 'call_type') = 0,
  'ALTER TABLE `voice_calls` ADD COLUMN `call_type` VARCHAR(20) NOT NULL DEFAULT ''audio'' AFTER `conversation_id`',
  'SELECT 1'
);
PREPARE column_statement FROM @column_sql;
EXECUTE column_statement;
DEALLOCATE PREPARE column_statement;

UPDATE `voice_calls`
SET `call_type` = 'audio'
WHERE `call_type` IS NULL OR `call_type` NOT IN ('audio', 'video');

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.15-video-calls', '应用内视频通话与摄像头切换', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
