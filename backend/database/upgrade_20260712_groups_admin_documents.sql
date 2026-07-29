-- 易运盈后台：群聊闭环 + 管理员文档 CRUD 升级
-- 适用于已经导入旧版 install.sql 的 MySQL 5.7+/8.0 或 MariaDB 10.3+。
-- 请先备份数据库，再以 appht 数据库为当前库执行。本脚本可重复执行。

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DELIMITER $$

DROP PROCEDURE IF EXISTS `yy_exec_if_missing_column`$$
CREATE PROCEDURE `yy_exec_if_missing_column`(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_sql TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
  ) THEN
    SET @yy_sql = p_sql;
    PREPARE yy_stmt FROM @yy_sql;
    EXECUTE yy_stmt;
    DEALLOCATE PREPARE yy_stmt;
  END IF;
END$$

DROP PROCEDURE IF EXISTS `yy_exec_if_missing_index`$$
CREATE PROCEDURE `yy_exec_if_missing_index`(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_sql TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index
  ) THEN
    SET @yy_sql = p_sql;
    PREPARE yy_stmt FROM @yy_sql;
    EXECUTE yy_stmt;
    DEALLOCATE PREPARE yy_stmt;
  END IF;
END$$

DROP PROCEDURE IF EXISTS `yy_drop_fk_if_exists`$$
CREATE PROCEDURE `yy_drop_fk_if_exists`(IN p_table VARCHAR(64), IN p_fk VARCHAR(64))
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND CONSTRAINT_NAME = p_fk
  ) THEN
    SET @yy_sql = CONCAT('ALTER TABLE `', p_table, '` DROP FOREIGN KEY `', p_fk, '`');
    PREPARE yy_stmt FROM @yy_sql;
    EXECUTE yy_stmt;
    DEALLOCATE PREPARE yy_stmt;
  END IF;
END$$

DROP PROCEDURE IF EXISTS `yy_exec_if_missing_fk`$$
CREATE PROCEDURE `yy_exec_if_missing_fk`(IN p_table VARCHAR(64), IN p_fk VARCHAR(64), IN p_sql TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND CONSTRAINT_NAME = p_fk
  ) THEN
    SET @yy_sql = p_sql;
    PREPARE yy_stmt FROM @yy_sql;
    EXECUTE yy_stmt;
    DEALLOCATE PREPARE yy_stmt;
  END IF;
END$$

DELIMITER ;

CALL `yy_drop_fk_if_exists`('documents', 'fk_documents_user');
ALTER TABLE `documents` MODIFY `user_id` BIGINT UNSIGNED NULL;
CALL `yy_exec_if_missing_column`('documents', 'owner_type',
  'ALTER TABLE `documents` ADD COLUMN `owner_type` VARCHAR(20) NOT NULL DEFAULT ''user'' AFTER `user_id`');
CALL `yy_exec_if_missing_index`('documents', 'idx_documents_owner_time',
  'ALTER TABLE `documents` ADD KEY `idx_documents_owner_time` (`app_id`, `owner_type`, `updated_at`)');
CALL `yy_exec_if_missing_fk`('documents', 'fk_documents_app',
  'ALTER TABLE `documents` ADD CONSTRAINT `fk_documents_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE');
CALL `yy_exec_if_missing_fk`('documents', 'fk_documents_user',
  'ALTER TABLE `documents` ADD CONSTRAINT `fk_documents_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE');

CALL `yy_drop_fk_if_exists`('document_versions', 'fk_document_versions_user');
ALTER TABLE `document_versions` MODIFY `user_id` BIGINT UNSIGNED NULL;
CALL `yy_exec_if_missing_column`('document_versions', 'owner_type',
  'ALTER TABLE `document_versions` ADD COLUMN `owner_type` VARCHAR(20) NOT NULL DEFAULT ''user'' AFTER `user_id`');
CALL `yy_exec_if_missing_fk`('document_versions', 'fk_document_versions_user',
  'ALTER TABLE `document_versions` ADD CONSTRAINT `fk_document_versions_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE');

CALL `yy_drop_fk_if_exists`('chat_room_messages', 'fk_chat_room_messages_user');
ALTER TABLE `chat_room_messages` MODIFY `user_id` BIGINT UNSIGNED NULL;
CALL `yy_exec_if_missing_column`('chat_room_messages', 'sender_type',
  'ALTER TABLE `chat_room_messages` ADD COLUMN `sender_type` VARCHAR(20) NOT NULL DEFAULT ''user'' AFTER `user_id`');
CALL `yy_exec_if_missing_column`('chat_room_messages', 'sender_admin_id',
  'ALTER TABLE `chat_room_messages` ADD COLUMN `sender_admin_id` BIGINT UNSIGNED NULL AFTER `sender_type`');
CALL `yy_exec_if_missing_column`('chat_room_messages', 'reply_to_message_id',
  'ALTER TABLE `chat_room_messages` ADD COLUMN `reply_to_message_id` BIGINT UNSIGNED NULL AFTER `content`');
CALL `yy_exec_if_missing_index`('chat_room_messages', 'idx_chat_room_messages_sender_admin',
  'ALTER TABLE `chat_room_messages` ADD KEY `idx_chat_room_messages_sender_admin` (`sender_admin_id`, `created_at`)');
CALL `yy_exec_if_missing_fk`('chat_room_messages', 'fk_chat_room_messages_user',
  'ALTER TABLE `chat_room_messages` ADD CONSTRAINT `fk_chat_room_messages_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE');
CALL `yy_exec_if_missing_fk`('chat_room_messages', 'fk_chat_room_messages_sender_admin',
  'ALTER TABLE `chat_room_messages` ADD CONSTRAINT `fk_chat_room_messages_sender_admin` FOREIGN KEY (`sender_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL');
CALL `yy_exec_if_missing_fk`('chat_room_messages', 'fk_chat_room_messages_reply',
  'ALTER TABLE `chat_room_messages` ADD CONSTRAINT `fk_chat_room_messages_reply` FOREIGN KEY (`reply_to_message_id`) REFERENCES `chat_room_messages` (`id`) ON DELETE SET NULL');

CREATE TABLE IF NOT EXISTS `chat_room_policies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `owner_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `join_mode` VARCHAR(20) NOT NULL DEFAULT 'open',
  `max_members` INT UNSIGNED NOT NULL DEFAULT 500,
  `allow_member_invite` TINYINT NOT NULL DEFAULT 1,
  `mute_all` TINYINT NOT NULL DEFAULT 0,
  `announcement` VARCHAR(2000) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_room_policies_room` (`room_id`),
  KEY `idx_chat_room_policies_owner` (`app_id`, `owner_user_id`),
  CONSTRAINT `fk_chat_room_policies_room` FOREIGN KEY (`room_id`, `app_id`, `admin_id`) REFERENCES `chat_rooms` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_policies_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_invitations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `inviter_user_id` BIGINT UNSIGNED NOT NULL,
  `invitee_user_id` BIGINT UNSIGNED NOT NULL,
  `message` VARCHAR(500) NOT NULL DEFAULT '',
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `expired_at` DATETIME DEFAULT NULL,
  `responded_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_room_invitations_target` (`room_id`, `invitee_user_id`),
  KEY `idx_chat_room_invitations_user` (`app_id`, `invitee_user_id`, `status`, `created_at`),
  CONSTRAINT `fk_chat_room_invitations_room` FOREIGN KEY (`room_id`, `app_id`, `admin_id`) REFERENCES `chat_rooms` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_invitations_inviter` FOREIGN KEY (`inviter_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_invitations_invitee` FOREIGN KEY (`invitee_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_join_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `message` VARCHAR(500) NOT NULL DEFAULT '',
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `handled_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `handled_by_admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `handled_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_room_join_requests_user` (`room_id`, `user_id`),
  KEY `idx_chat_room_join_requests_room` (`room_id`, `status`, `created_at`),
  CONSTRAINT `fk_chat_room_join_requests_room` FOREIGN KEY (`room_id`, `app_id`, `admin_id`) REFERENCES `chat_rooms` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_join_requests_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_join_requests_handler_user` FOREIGN KEY (`handled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_chat_room_join_requests_handler_admin` FOREIGN KEY (`handled_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_reads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `last_read_message_id` BIGINT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_room_reads_user` (`room_id`, `user_id`),
  CONSTRAINT `fk_chat_room_reads_room` FOREIGN KEY (`room_id`, `app_id`, `admin_id`) REFERENCES `chat_rooms` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_reads_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_reads_message` FOREIGN KEY (`last_read_message_id`) REFERENCES `chat_room_messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `chat_room_policies` p LEFT JOIN `users` u ON u.`id` = p.`owner_user_id`
SET p.`owner_user_id` = NULL WHERE p.`owner_user_id` IS NOT NULL AND u.`id` IS NULL;
UPDATE `chat_room_join_requests` jr LEFT JOIN `users` u ON u.`id` = jr.`handled_by_user_id`
SET jr.`handled_by_user_id` = NULL WHERE jr.`handled_by_user_id` IS NOT NULL AND u.`id` IS NULL;
CALL `yy_exec_if_missing_fk`('chat_room_policies', 'fk_chat_room_policies_owner',
  'ALTER TABLE `chat_room_policies` ADD CONSTRAINT `fk_chat_room_policies_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL');
CALL `yy_exec_if_missing_fk`('chat_room_join_requests', 'fk_chat_room_join_requests_handler_user',
  'ALTER TABLE `chat_room_join_requests` ADD CONSTRAINT `fk_chat_room_join_requests_handler_user` FOREIGN KEY (`handled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL');

UPDATE `documents` SET `owner_type` = 'user' WHERE `owner_type` = '' OR `owner_type` IS NULL;
UPDATE `document_versions` SET `owner_type` = 'user' WHERE `owner_type` = '' OR `owner_type` IS NULL;
UPDATE `chat_room_messages` SET `sender_type` = 'user' WHERE `sender_type` = '' OR `sender_type` IS NULL;

INSERT INTO `chat_room_policies`
  (`admin_id`, `app_id`, `room_id`, `owner_user_id`, `join_mode`, `max_members`, `allow_member_invite`, `mute_all`, `announcement`, `created_at`, `updated_at`)
SELECT r.`admin_id`, r.`app_id`, r.`id`, NULL, IF(r.`is_public` = 1, 'open', 'invite'), 500, 1, 0, '', NOW(), NOW()
FROM `chat_rooms` r
LEFT JOIN `chat_room_policies` p ON p.`room_id` = r.`id`
WHERE p.`id` IS NULL;

INSERT IGNORE INTO `app_settings`
  (`admin_id`, `app_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT a.`admin_id`, a.`id`, defaults.`setting_key`, defaults.`setting_value`, defaults.`value_type`, NOW(), NOW()
FROM `apps` a
CROSS JOIN (
  SELECT 'user_group_create_enabled' AS `setting_key`, '1' AS `setting_value`, 'bool' AS `value_type`
  UNION ALL SELECT 'user_group_max_owned', '10', 'int'
  UNION ALL SELECT 'group_default_max_members', '500', 'int'
) defaults;

DROP PROCEDURE IF EXISTS `yy_exec_if_missing_column`;
DROP PROCEDURE IF EXISTS `yy_exec_if_missing_index`;
DROP PROCEDURE IF EXISTS `yy_drop_fk_if_exists`;
DROP PROCEDURE IF EXISTS `yy_exec_if_missing_fk`;

SET FOREIGN_KEY_CHECKS = 1;
