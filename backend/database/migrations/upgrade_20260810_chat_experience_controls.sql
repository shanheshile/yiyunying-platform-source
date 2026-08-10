-- Chat composer and call-record presentation controls for existing apps.
-- Idempotent: existing administrator choices are intentionally preserved.

INSERT INTO `app_feature_flags`
  (`admin_id`, `app_id`, `feature_code`, `enabled`, `config_json`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, 'chat_camera', 1, NULL, NOW(), NOW()
FROM `apps`
ON DUPLICATE KEY UPDATE `feature_code` = VALUES(`feature_code`);

INSERT INTO `app_feature_flags`
  (`admin_id`, `app_id`, `feature_code`, `enabled`, `config_json`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, 'chat_album', 1, NULL, NOW(), NOW()
FROM `apps`
ON DUPLICATE KEY UPDATE `feature_code` = VALUES(`feature_code`);

INSERT INTO `app_feature_flags`
  (`admin_id`, `app_id`, `feature_code`, `enabled`, `config_json`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, 'chat_contact_card', 1, NULL, NOW(), NOW()
FROM `apps`
ON DUPLICATE KEY UPDATE `feature_code` = VALUES(`feature_code`);

INSERT INTO `app_feature_flags`
  (`admin_id`, `app_id`, `feature_code`, `enabled`, `config_json`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, 'chat_call_record_label', 1, NULL, NOW(), NOW()
FROM `apps`
ON DUPLICATE KEY UPDATE `feature_code` = VALUES(`feature_code`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES (
  '2026.08.10-chat-experience-controls',
  'Chat camera, album, contact card and call-record presentation controls',
  NOW()
)
ON DUPLICATE KEY UPDATE
  `description` = VALUES(`description`),
  `applied_at` = VALUES(`applied_at`);
