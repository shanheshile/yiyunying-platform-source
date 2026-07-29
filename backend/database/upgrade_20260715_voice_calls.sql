SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `voice_calls` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `caller_user_id` BIGINT UNSIGNED NOT NULL,
  `callee_user_id` BIGINT UNSIGNED NOT NULL,
  `conversation_id` BIGINT UNSIGNED DEFAULT NULL,
  `call_type` VARCHAR(20) NOT NULL DEFAULT 'audio',
  `status` VARCHAR(20) NOT NULL DEFAULT 'ringing',
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `answered_at` DATETIME DEFAULT NULL,
  `ended_at` DATETIME DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `ended_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `duration_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_voice_calls_caller_status` (`app_id`, `caller_user_id`, `status`, `created_at`),
  KEY `idx_voice_calls_callee_status` (`app_id`, `callee_user_id`, `status`, `created_at`),
  KEY `idx_voice_calls_expiry` (`status`, `expires_at`),
  CONSTRAINT `fk_voice_calls_caller` FOREIGN KEY (`caller_user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_voice_calls_callee` FOREIGN KEY (`callee_user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_voice_calls_conversation` FOREIGN KEY (`conversation_id`)
    REFERENCES `conversations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_voice_calls_ended_by` FOREIGN KEY (`ended_by_user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `voice_call_signals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `call_id` BIGINT UNSIGNED NOT NULL,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `from_user_id` BIGINT UNSIGNED NOT NULL,
  `signal_type` VARCHAR(20) NOT NULL,
  `payload_json` LONGTEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_voice_call_signals_call` (`call_id`, `id`),
  KEY `idx_voice_call_signals_sender` (`from_user_id`, `created_at`),
  CONSTRAINT `fk_voice_call_signals_call` FOREIGN KEY (`call_id`) REFERENCES `voice_calls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_voice_call_signals_sender` FOREIGN KEY (`from_user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.15-voice-calls', '应用内网络语音通话状态与 WebRTC 信令', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
