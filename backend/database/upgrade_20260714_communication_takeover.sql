SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `communication_takeover_policies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `platform_view_enabled` TINYINT NOT NULL DEFAULT 1,
  `platform_send_enabled` TINYINT NOT NULL DEFAULT 1,
  `platform_private_enabled` TINYINT NOT NULL DEFAULT 1,
  `platform_group_enabled` TINYINT NOT NULL DEFAULT 1,
  `platform_service_enabled` TINYINT NOT NULL DEFAULT 1,
  `admin_view_enabled` TINYINT NOT NULL DEFAULT 1,
  `admin_send_enabled` TINYINT NOT NULL DEFAULT 1,
  `admin_private_enabled` TINYINT NOT NULL DEFAULT 1,
  `admin_group_enabled` TINYINT NOT NULL DEFAULT 1,
  `admin_service_enabled` TINYINT NOT NULL DEFAULT 1,
  `system_display_name` VARCHAR(40) NOT NULL DEFAULT '系统消息',
  `policy_locked_for_level` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_by_platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by_type` VARCHAR(20) NOT NULL DEFAULT 'system',
  `updated_by_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_communication_takeover_policy_app` (`app_id`),
  KEY `idx_communication_takeover_policy_tenant` (`admin_id`, `app_id`),
  KEY `idx_communication_takeover_policy_lock` (`locked_by_platform_id`, `policy_locked_for_level`),
  CONSTRAINT `fk_communication_takeover_policy_app` FOREIGN KEY (`app_id`, `admin_id`)
    REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_communication_takeover_policy_platform` FOREIGN KEY (`locked_by_platform_id`)
    REFERENCES `platform_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `communication_takeover_audits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `actor_type` VARCHAR(20) NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `actor_level` TINYINT UNSIGNED NOT NULL,
  `action` VARCHAR(30) NOT NULL,
  `channel_type` VARCHAR(20) NOT NULL DEFAULT '',
  `channel_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `subject_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `message_id` BIGINT UNSIGNED DEFAULT NULL,
  `detail_json` LONGTEXT,
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_communication_takeover_audit_tenant` (`admin_id`, `app_id`, `created_at`),
  KEY `idx_communication_takeover_audit_actor` (`actor_type`, `actor_id`, `created_at`),
  KEY `idx_communication_takeover_audit_channel` (`app_id`, `channel_type`, `channel_id`, `created_at`),
  KEY `idx_communication_takeover_audit_user` (`subject_user_id`, `created_at`),
  CONSTRAINT `fk_communication_takeover_audit_app` FOREIGN KEY (`app_id`, `admin_id`)
    REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `communication_takeover_policies`
(`admin_id`, `app_id`, `system_display_name`, `updated_by_type`, `updated_by_id`, `created_at`, `updated_at`)
SELECT `admin_id`, `id`, '系统消息', 'system', 0, NOW(), NOW() FROM `apps` WHERE `deleted_at` IS NULL;
