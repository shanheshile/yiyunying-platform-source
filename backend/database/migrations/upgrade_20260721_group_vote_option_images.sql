-- 群投票选项图片。兼容 MySQL 5.7/8.0，可重复执行。
SET @has_group_vote_option_image := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'chat_room_vote_options'
    AND COLUMN_NAME = 'image_url'
);

SET @group_vote_option_image_sql := IF(
  @has_group_vote_option_image = 0,
  'ALTER TABLE `chat_room_vote_options` ADD COLUMN `image_url` VARCHAR(1000) NOT NULL DEFAULT '''' AFTER `option_text`',
  'SELECT 1'
);
PREPARE group_vote_option_image_statement FROM @group_vote_option_image_sql;
EXECUTE group_vote_option_image_statement;
DEALLOCATE PREPARE group_vote_option_image_statement;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
SELECT '2026.07.21-group-vote-option-images', '群投票可视化图片选项', NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `schema_migrations` WHERE `version` = '2026.07.21-group-vote-option-images'
);
