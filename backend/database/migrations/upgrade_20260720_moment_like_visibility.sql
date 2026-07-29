-- 朋友圈点赞者身份可见范围：默认仅共同好友可见。
INSERT INTO app_settings
    (admin_id, app_id, setting_key, setting_value, value_type, created_at, updated_at)
SELECT
    apps.admin_id,
    apps.id,
    'moment_like_non_friend_visible',
    '0',
    'bool',
    NOW(),
    NOW()
FROM apps
LEFT JOIN app_settings
    ON app_settings.app_id = apps.id
   AND app_settings.setting_key = 'moment_like_non_friend_visible'
WHERE app_settings.id IS NULL;
