-- Level-1 secure mail configuration. SMTP passwords are AES-256-GCM envelopes;
-- plaintext secrets must never be inserted into this table.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `platform_mail_settings` (
  `id` TINYINT UNSIGNED NOT NULL,
  `transport` VARCHAR(20) NOT NULL DEFAULT 'disabled',
  `from_address` VARCHAR(190) NOT NULL DEFAULT '',
  `from_name` VARCHAR(100) NOT NULL DEFAULT '',
  `smtp_host` VARCHAR(253) NOT NULL DEFAULT '',
  `smtp_port` INT UNSIGNED NOT NULL DEFAULT 587,
  `smtp_encryption` VARCHAR(20) NOT NULL DEFAULT 'tls',
  `smtp_username` VARCHAR(255) NOT NULL DEFAULT '',
  `smtp_password_ciphertext` LONGTEXT,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `updated_by_platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_platform_mail_settings_updater` (`updated_by_platform_id`),
  CONSTRAINT `fk_platform_mail_settings_updater` FOREIGN KEY (`updated_by_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.08.14-secure-mail-settings', 'Root-only authenticated encrypted mail configuration and test delivery audit', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
