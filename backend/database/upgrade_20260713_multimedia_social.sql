-- 易运盈后台 2026-07-13 统一多媒体消息、个人表情包与撤回审计升级
-- 兼容 MySQL 5.7+/8.0 与 MariaDB 10.3+，可重复执行。
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `sticker_packs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `cover_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `sticker_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sticker_packs_owner_name` (`app_id`, `user_id`, `name`),
  UNIQUE KEY `uk_sticker_packs_id_tenant` (`id`, `app_id`, `admin_id`),
  KEY `idx_sticker_packs_owner` (`user_id`, `status`, `sort_order`),
  CONSTRAINT `fk_sticker_packs_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stickers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `pack_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `upload_id` BIGINT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(100) NOT NULL DEFAULT '',
  `image_url` VARCHAR(1000) NOT NULL,
  `thumbnail_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `width` INT UNSIGNED NOT NULL DEFAULT 0,
  `height` INT UNSIGNED NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_stickers_pack_url` (`pack_id`, `image_url`(191)),
  KEY `idx_stickers_pack` (`pack_id`, `status`, `sort_order`, `id`),
  CONSTRAINT `fk_stickers_pack` FOREIGN KEY (`pack_id`, `app_id`, `admin_id`)
    REFERENCES `sticker_packs` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stickers_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stickers_upload` FOREIGN KEY (`upload_id`) REFERENCES `uploads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `media_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `owner_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `owner_admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `target_type` VARCHAR(40) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `media_type` VARCHAR(20) NOT NULL,
  `upload_id` BIGINT UNSIGNED DEFAULT NULL,
  `sticker_id` BIGINT UNSIGNED DEFAULT NULL,
  `url` VARCHAR(1000) NOT NULL,
  `thumbnail_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `file_name` VARCHAR(255) NOT NULL DEFAULT '',
  `mime_type` VARCHAR(150) NOT NULL DEFAULT '',
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `width` INT UNSIGNED NOT NULL DEFAULT 0,
  `height` INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `metadata_json` LONGTEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_media_attachments_target` (`app_id`, `target_type`, `target_id`, `sort_order`, `id`),
  KEY `idx_media_attachments_owner` (`owner_user_id`, `created_at`),
  CONSTRAINT `fk_media_attachments_upload` FOREIGN KEY (`upload_id`) REFERENCES `uploads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_media_attachments_sticker` FOREIGN KEY (`sticker_id`) REFERENCES `stickers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_recall_audits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `channel_type` VARCHAR(20) NOT NULL,
  `channel_id` BIGINT UNSIGNED NOT NULL,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `sender_type` VARCHAR(20) NOT NULL,
  `sender_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `original_content_type` VARCHAR(20) NOT NULL,
  `original_content` LONGTEXT NOT NULL,
  `original_attachments_json` LONGTEXT,
  `recalled_by_type` VARCHAR(20) NOT NULL,
  `recalled_by_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `reason` VARCHAR(500) NOT NULL DEFAULT '',
  `recalled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_message_recall_audits_message` (`app_id`, `channel_type`, `message_id`),
  KEY `idx_message_recall_audits_scope` (`admin_id`, `app_id`, `channel_type`, `recalled_at`),
  KEY `idx_message_recall_audits_sender` (`app_id`, `sender_type`, `sender_id`, `recalled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('20260713_multimedia_social', '统一多媒体附件、个人表情包、聊天撤回审计与关联资料底座', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
