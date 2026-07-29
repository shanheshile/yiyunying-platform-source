-- 私聊与客服引用消息升级。可重复执行。

SET @database_name := DATABASE();

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@database_name AND TABLE_NAME='messages' AND COLUMN_NAME='reply_to_message_id'),
  'SELECT 1',
  'ALTER TABLE `messages` ADD COLUMN `reply_to_message_id` BIGINT UNSIGNED NULL AFTER `tags_json`'
);
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@database_name AND TABLE_NAME='messages' AND INDEX_NAME='idx_messages_reply'),
  'SELECT 1',
  'ALTER TABLE `messages` ADD KEY `idx_messages_reply` (`reply_to_message_id`)'
);
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@database_name AND TABLE_NAME='messages' AND CONSTRAINT_NAME='fk_messages_reply'),
  'SELECT 1',
  'ALTER TABLE `messages` ADD CONSTRAINT `fk_messages_reply` FOREIGN KEY (`reply_to_message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL'
);
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@database_name AND TABLE_NAME='service_messages' AND COLUMN_NAME='reply_to_message_id'),
  'SELECT 1',
  'ALTER TABLE `service_messages` ADD COLUMN `reply_to_message_id` BIGINT UNSIGNED NULL AFTER `sender_id`'
);
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@database_name AND TABLE_NAME='service_messages' AND INDEX_NAME='idx_service_messages_reply'),
  'SELECT 1',
  'ALTER TABLE `service_messages` ADD KEY `idx_service_messages_reply` (`reply_to_message_id`)'
);
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;

SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@database_name AND TABLE_NAME='service_messages' AND CONSTRAINT_NAME='fk_service_messages_reply'),
  'SELECT 1',
  'ALTER TABLE `service_messages` ADD CONSTRAINT `fk_service_messages_reply` FOREIGN KEY (`reply_to_message_id`) REFERENCES `service_messages` (`id`) ON DELETE SET NULL'
);
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.15-message-replies', '私聊与客服引用消息', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
