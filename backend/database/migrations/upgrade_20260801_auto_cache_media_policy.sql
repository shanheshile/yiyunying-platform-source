-- 易运盈后台 2.7.4：自动缓存与视频自动播放策略。
-- 仅为缺少配置的应用补默认值，保留管理员已经设置的值，可重复执行。
SET NAMES utf8mb4;

INSERT INTO `app_settings`
  (`admin_id`, `app_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT app.admin_id, app.id, defaults.setting_key, defaults.setting_value, defaults.value_type, NOW(), NOW()
FROM `apps` app CROSS JOIN (
  SELECT 'auto_download_cache_enabled' setting_key, '1' setting_value, 'bool' value_type
  UNION ALL SELECT 'auto_cache_allowed_categories', '["chat_record","profile","image","video","voice","audio","document","file","sticker"]', 'json'
  UNION ALL SELECT 'auto_cache_default_max_bytes', '536870912', 'int'
  UNION ALL SELECT 'auto_cache_max_bytes_limit', '2147483648', 'int'
  UNION ALL SELECT 'auto_cache_retention_days', '90', 'int'
  UNION ALL SELECT 'auto_cache_network', 'wifi_mobile', 'string'
  UNION ALL SELECT 'auto_cache_force_wifi_only', '0', 'bool'
  UNION ALL SELECT 'auto_cache_policy_version', '2026.08.01', 'string'
  UNION ALL SELECT 'video_autoplay_enabled', '1', 'bool'
  UNION ALL SELECT 'video_autoplay_network', 'wifi_mobile', 'string'
  UNION ALL SELECT 'video_autoplay_default_network', 'wifi', 'string'
) defaults
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.08.01-auto-cache-media-policy', '自动缓存、缓存网络与视频自动播放策略', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);