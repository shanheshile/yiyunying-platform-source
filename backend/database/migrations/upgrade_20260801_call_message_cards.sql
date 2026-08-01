-- Keep one caller-owned chat card for each call and update it in place.
SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'voice_calls' AND COLUMN_NAME = 'private_message_id') = 0,
  'ALTER TABLE `voice_calls` ADD COLUMN `private_message_id` BIGINT UNSIGNED DEFAULT NULL AFTER `conversation_id`',
  'SELECT 1'
);
PREPARE column_statement FROM @column_sql;
EXECUTE column_statement;
DEALLOCATE PREPARE column_statement;

SET @column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'voice_calls' AND COLUMN_NAME = 'room_message_id') = 0,
  'ALTER TABLE `voice_calls` ADD COLUMN `room_message_id` BIGINT UNSIGNED DEFAULT NULL AFTER `private_message_id`',
  'SELECT 1'
);
PREPARE column_statement FROM @column_sql;
EXECUTE column_statement;
DEALLOCATE PREPARE column_statement;

SET @index_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'voice_calls' AND INDEX_NAME = 'idx_voice_calls_private_message') = 0,
  'ALTER TABLE `voice_calls` ADD INDEX `idx_voice_calls_private_message` (`private_message_id`)',
  'SELECT 1'
);
PREPARE index_statement FROM @index_sql;
EXECUTE index_statement;
DEALLOCATE PREPARE index_statement;

SET @index_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'voice_calls' AND INDEX_NAME = 'idx_voice_calls_room_message') = 0,
  'ALTER TABLE `voice_calls` ADD INDEX `idx_voice_calls_room_message` (`room_message_id`)',
  'SELECT 1'
);
PREPARE index_statement FROM @index_sql;
EXECUTE index_statement;
DEALLOCATE PREPARE index_statement;

UPDATE `voice_calls` vc
SET vc.`private_message_id` = (
  SELECT MIN(m.`id`)
  FROM `messages` m
  WHERE m.`conversation_id` = vc.`conversation_id`
    AND m.`title` = CAST(vc.`id` AS CHAR)
    AND m.`tags_json` LIKE '%通话记录%'
)
WHERE vc.`context_type` = 'private'
  AND vc.`conversation_id` IS NOT NULL
  AND vc.`private_message_id` IS NULL;

UPDATE `messages` m
INNER JOIN `voice_calls` vc ON vc.`private_message_id` = m.`id`
SET m.`sender_type` = 'user',
    m.`sender_id` = vc.`caller_user_id`,
    m.`receiver_user_id` = vc.`callee_user_id`,
    m.`content_type` = 'call',
    m.`content` = CONCAT(
      IF(vc.`call_type` = 'video', '视频通话', '语音通话'), CHAR(10),
      CASE vc.`status`
        WHEN 'ringing' THEN '等待对方接听'
        WHEN 'active' THEN '正在通话'
        WHEN 'declined' THEN '对方未接听'
        WHEN 'cancelled' THEN '已取消'
        WHEN 'missed' THEN '无人接听'
        ELSE CONCAT('通话时间：', FLOOR(vc.`duration_seconds` / 60), '分', MOD(vc.`duration_seconds`, 60), '秒')
      END
    ),
    m.`tags_json` = '["通话记录"]',
    m.`status` = 1;

UPDATE `messages` duplicate_message
INNER JOIN `voice_calls` vc
  ON duplicate_message.`conversation_id` = vc.`conversation_id`
 AND duplicate_message.`title` = CAST(vc.`id` AS CHAR)
SET duplicate_message.`status` = 0
WHERE vc.`private_message_id` IS NOT NULL
  AND duplicate_message.`id` <> vc.`private_message_id`
  AND duplicate_message.`sender_type` = 'system'
  AND duplicate_message.`tags_json` LIKE '%通话记录%';

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.08.01-call-message-cards', '通话记录改为发起方消息并原位更新', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
