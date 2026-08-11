SET @schema_name := DATABASE();

SET @has_moment_content_kind := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'user_moments'
    AND COLUMN_NAME = 'content_kind'
);
SET @moment_content_kind_sql := IF(
  @has_moment_content_kind = 0,
  'ALTER TABLE user_moments ADD COLUMN content_kind VARCHAR(20) NOT NULL DEFAULT ''moment'' AFTER content',
  'SELECT 1'
);
PREPARE moment_content_kind_statement FROM @moment_content_kind_sql;
EXECUTE moment_content_kind_statement;
DEALLOCATE PREPARE moment_content_kind_statement;

SET @has_moment_kind_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @schema_name
    AND TABLE_NAME = 'user_moments'
    AND INDEX_NAME = 'idx_user_moments_kind_feed'
);
SET @moment_kind_index_sql := IF(
  @has_moment_kind_index = 0,
  'ALTER TABLE user_moments ADD KEY idx_user_moments_kind_feed (admin_id, app_id, content_kind, audit_status, status, deleted_at, created_at)',
  'SELECT 1'
);
PREPARE moment_kind_index_statement FROM @moment_kind_index_sql;
EXECUTE moment_kind_index_statement;
DEALLOCATE PREPARE moment_kind_index_statement;

INSERT INTO app_feature_flags
  (admin_id, app_id, feature_code, enabled, config_json, created_at, updated_at)
SELECT admin_id, id, feature_code, 1, NULL, NOW(), NOW()
FROM apps
CROSS JOIN (
  SELECT 'short_videos' AS feature_code
  UNION ALL SELECT 'short_video_publish'
  UNION ALL SELECT 'short_video_comments'
  UNION ALL SELECT 'short_video_likes'
  UNION ALL SELECT 'short_video_favorites'
  UNION ALL SELECT 'short_video_forwards'
) AS short_video_features
ON DUPLICATE KEY UPDATE feature_code = VALUES(feature_code);

INSERT INTO schema_migrations (`version`, `description`, `applied_at`)
VALUES ('2026.08.11-short-video-controls', 'Dedicated short-video content type and feature controls', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
