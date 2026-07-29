-- 易运盈后台 2026-07-15 上传上限升级。
-- 仅提升仍使用旧默认值的应用；管理员自行设置过的较低上限不会被覆盖。

UPDATE `app_settings`
SET `setting_value` = '1073741824', `updated_at` = NOW()
WHERE `setting_key` = 'upload_video_max_bytes'
  AND CAST(`setting_value` AS UNSIGNED) = 524288000;

UPDATE `app_settings`
SET `setting_value` = '536870912', `updated_at` = NOW()
WHERE `setting_key` = 'upload_file_max_bytes'
  AND CAST(`setting_value` AS UNSIGNED) = 209715200;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.15-upload-limits', '图片视频与通用文件上传上限升级', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
