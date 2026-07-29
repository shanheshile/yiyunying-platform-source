SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `friend_groups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_friend_groups_name` (`app_id`, `user_id`, `name`),
  CONSTRAINT `fk_friend_groups_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `friend_group_members` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `friend_user_id` BIGINT UNSIGNED NOT NULL,
  `group_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_friend_group_members_friend` (`app_id`, `user_id`, `friend_user_id`),
  KEY `idx_friend_group_members_group` (`group_id`, `user_id`),
  CONSTRAINT `fk_friend_group_members_group` FOREIGN KEY (`group_id`) REFERENCES `friend_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_friend_group_members_friend` FOREIGN KEY (`friend_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_user_groups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_room_user_groups_name` (`app_id`, `user_id`, `name`),
  CONSTRAINT `fk_chat_room_user_groups_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_user_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `group_id` BIGINT UNSIGNED DEFAULT NULL,
  `remark` VARCHAR(100) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_room_user_settings_room` (`app_id`, `user_id`, `room_id`),
  KEY `idx_chat_room_user_settings_group` (`group_id`, `user_id`),
  CONSTRAINT `fk_chat_room_user_settings_room` FOREIGN KEY (`room_id`, `app_id`, `admin_id`) REFERENCES `chat_rooms` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_user_settings_group` FOREIGN KEY (`group_id`) REFERENCES `chat_room_user_groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
