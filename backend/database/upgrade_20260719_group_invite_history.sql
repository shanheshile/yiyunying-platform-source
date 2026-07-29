-- 易运盈后台：群邀请可选择是否向新成员开放入群前聊天记录。
-- 本文件可重复执行，已有字段不会重复添加。

SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_invitations' AND COLUMN_NAME = 'share_history') = 0,
  'ALTER TABLE `chat_room_invitations` ADD COLUMN `share_history` TINYINT(1) NOT NULL DEFAULT 1 AFTER `message`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_room_members' AND COLUMN_NAME = 'history_visible_from') = 0,
  'ALTER TABLE `chat_room_members` ADD COLUMN `history_visible_from` DATETIME DEFAULT NULL AFTER `joined_at`', 'SELECT 1');
PREPARE stmt FROM @column_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.19-group-invite-history', '群邀请历史消息可见边界', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
