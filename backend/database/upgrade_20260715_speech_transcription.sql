SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `audio_transcriptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `upload_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `language` VARCHAR(20) NOT NULL DEFAULT 'zh',
  `transcript` LONGTEXT NOT NULL,
  `provider` VARCHAR(80) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_audio_transcriptions_upload_language` (`app_id`, `upload_id`, `language`),
  KEY `idx_audio_transcriptions_user_time` (`user_id`, `created_at`),
  CONSTRAINT `fk_audio_transcriptions_upload` FOREIGN KEY (`upload_id`) REFERENCES `uploads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_audio_transcriptions_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.15-speech-transcription', '语音消息转写缓存与媒体元数据回写', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
