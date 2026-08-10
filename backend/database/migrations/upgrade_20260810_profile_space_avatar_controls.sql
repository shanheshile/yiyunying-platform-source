-- Administrator-controlled avatar uploads for groups, chatrooms and forum plates.
-- Idempotent: existing administrator choices are intentionally preserved.

INSERT INTO `app_feature_flags`
  (`admin_id`, `app_id`, `feature_code`, `enabled`, `config_json`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, 'group_avatar_upload', 1, NULL, NOW(), NOW()
FROM `apps`
ON DUPLICATE KEY UPDATE `feature_code` = VALUES(`feature_code`);

INSERT INTO `app_feature_flags`
  (`admin_id`, `app_id`, `feature_code`, `enabled`, `config_json`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, 'chatroom_avatar_upload', 1, NULL, NOW(), NOW()
FROM `apps`
ON DUPLICATE KEY UPDATE `feature_code` = VALUES(`feature_code`);

INSERT INTO `app_feature_flags`
  (`admin_id`, `app_id`, `feature_code`, `enabled`, `config_json`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, 'forum_plate_avatar_upload', 1, NULL, NOW(), NOW()
FROM `apps`
ON DUPLICATE KEY UPDATE `feature_code` = VALUES(`feature_code`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES (
  '2026.08.10-profile-space-avatar-controls',
  'Group, chatroom and forum plate avatar upload controls',
  NOW()
)
ON DUPLICATE KEY UPDATE
  `description` = VALUES(`description`),
  `applied_at` = VALUES(`applied_at`);
