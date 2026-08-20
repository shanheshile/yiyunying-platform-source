-- 易运盈后台四级平台治理与完整最大闭环数据库
-- 兼容 MySQL 5.7+/8.0 与 MariaDB 10.3+
-- 本文件必须以 UTF-8（无 BOM）保存，并导入到 appht 数据库。
--
-- 首次启动安全说明：本文件不内置任何可登录账号、密码或应用密钥。
-- 如需在导入后立即登录，必须在同一个数据库会话中先显式设置以下变量，再 SOURCE 本文件：
--   @YY_BOOTSTRAP_ROOT_PLATFORM_KEY / @YY_BOOTSTRAP_ROOT_ACCOUNT / @YY_BOOTSTRAP_ROOT_PASSWORD_HASH
--   @YY_BOOTSTRAP_AUTHORIZED_PLATFORM_KEY / @YY_BOOTSTRAP_AUTHORIZED_ACCOUNT / @YY_BOOTSTRAP_AUTHORIZED_PASSWORD_HASH（可选）
--   @YY_BOOTSTRAP_ADMIN_ACCOUNT / @YY_BOOTSTRAP_ADMIN_PASSWORD_HASH（可选）
--   @YY_BOOTSTRAP_APP_KEY / @YY_BOOTSTRAP_APP_SECRET_HASH（可选）
--   @YY_BOOTSTRAP_USER_UID / @YY_BOOTSTRAP_USER_ACCOUNT / @YY_BOOTSTRAP_USER_PASSWORD_HASH（可选）
-- 密码必须由 PHP password_hash() 生成；APP_SECRET_HASH 必须是随机 app_secret 的 64 位 SHA-256 十六进制值。
-- 任一层所需变量缺失或格式不正确时，该层及其下级只会创建随机不可认证、status=0 的占位数据。
-- 不要把真实明文、哈希或密钥写回本文件，也不要把含真实变量的本地 SQL 文件提交到版本库。

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version` VARCHAR(50) NOT NULL,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `platform_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_by_platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `level` TINYINT UNSIGNED NOT NULL,
  `platform_key` VARCHAR(80) NOT NULL,
  `account` VARCHAR(64) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `nickname` VARCHAR(100) NOT NULL DEFAULT '',
  `avatar` VARCHAR(500) NOT NULL DEFAULT '',
  `email` VARCHAR(190) DEFAULT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `disabled_reason` VARCHAR(500) NOT NULL DEFAULT '',
  `membership_level` VARCHAR(40) NOT NULL DEFAULT 'authorized',
  `membership_started_at` DATETIME DEFAULT NULL,
  `membership_expired_at` DATETIME DEFAULT NULL,
  `admin_quota` INT UNSIGNED NOT NULL DEFAULT 0,
  `integral` BIGINT NOT NULL DEFAULT 0,
  `access_start_time` TIME DEFAULT NULL,
  `access_end_time` TIME DEFAULT NULL,
  `allowed_weekdays` VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
  `permissions_json` LONGTEXT,
  `register_ip` VARCHAR(64) NOT NULL DEFAULT '',
  `last_login_ip` VARCHAR(64) DEFAULT NULL,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_accounts_key` (`platform_key`),
  UNIQUE KEY `uk_platform_accounts_account` (`account`),
  KEY `idx_platform_accounts_parent_level` (`parent_id`, `level`, `status`),
  CONSTRAINT `fk_platform_accounts_parent` FOREIGN KEY (`parent_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_platform_accounts_creator` FOREIGN KEY (`created_by_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `platform_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `device` VARCHAR(100) NOT NULL DEFAULT '',
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
  `expired_at` DATETIME NOT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `last_used_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_tokens_hash` (`token_hash`),
  KEY `idx_platform_tokens_owner_expire` (`platform_id`, `expired_at`),
  CONSTRAINT `fk_platform_tokens_owner` FOREIGN KEY (`platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `platform_login_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `account` VARCHAR(64) NOT NULL DEFAULT '',
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
  `result` TINYINT NOT NULL DEFAULT 0,
  `reason` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_platform_login_owner_time` (`platform_id`, `created_at`),
  KEY `idx_platform_login_ip_time` (`ip`, `created_at`),
  CONSTRAINT `fk_platform_login_owner` FOREIGN KEY (`platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `platform_operation_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `actor_level` TINYINT UNSIGNED NOT NULL,
  `module` VARCHAR(50) NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `target_type` VARCHAR(50) NOT NULL DEFAULT '',
  `target_id` BIGINT UNSIGNED DEFAULT NULL,
  `before_json` LONGTEXT,
  `after_json` LONGTEXT,
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_platform_ops_owner_time` (`platform_id`, `created_at`),
  KEY `idx_platform_ops_target` (`target_type`, `target_id`),
  CONSTRAINT `fk_platform_ops_owner` FOREIGN KEY (`platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `platform_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `setting_key` VARCHAR(80) NOT NULL,
  `setting_value` LONGTEXT,
  `value_type` VARCHAR(20) NOT NULL DEFAULT 'string',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_settings_key` (`platform_id`, `setting_key`),
  CONSTRAINT `fk_platform_settings_owner` FOREIGN KEY (`platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `platform_exchange_products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `product_code` VARCHAR(80) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT,
  `product_type` VARCHAR(40) NOT NULL,
  `grant_json` LONGTEXT NOT NULL,
  `price_integral` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `stock` BIGINT UNSIGNED DEFAULT NULL,
  `sold_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `per_admin_limit` INT UNSIGNED NOT NULL DEFAULT 0,
  `per_admin_daily_limit` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `start_at` DATETIME DEFAULT NULL,
  `end_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_exchange_product_code` (`platform_id`, `product_code`),
  UNIQUE KEY `uk_platform_exchange_product_id_owner` (`id`, `platform_id`),
  KEY `idx_platform_exchange_product_status` (`platform_id`, `status`, `sort_order`),
  CONSTRAINT `fk_platform_exchange_product_owner` FOREIGN KEY (`platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admins` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `account` VARCHAR(64) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `nickname` VARCHAR(100) NOT NULL DEFAULT '',
  `avatar` VARCHAR(500) NOT NULL DEFAULT '',
  `email` VARCHAR(190) DEFAULT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `register_ip` VARCHAR(64) NOT NULL DEFAULT '',
  `last_login_ip` VARCHAR(64) DEFAULT NULL,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admins_platform_account` (`platform_id`, `account`),
  UNIQUE KEY `uk_admins_id_platform` (`id`, `platform_id`),
  KEY `idx_admins_platform_status` (`platform_id`, `status`),
  CONSTRAINT `fk_admins_platform` FOREIGN KEY (`platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `issued_by_platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `token_type` VARCHAR(30) NOT NULL DEFAULT 'direct',
  `token_hash` CHAR(64) NOT NULL,
  `device` VARCHAR(100) NOT NULL DEFAULT '',
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
  `expired_at` DATETIME NOT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `last_used_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_tokens_hash` (`token_hash`),
  KEY `idx_admin_tokens_admin_expire` (`admin_id`, `expired_at`),
  CONSTRAINT `fk_admin_tokens_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_admin_tokens_platform_issuer` FOREIGN KEY (`issued_by_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_login_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `account` VARCHAR(64) NOT NULL DEFAULT '',
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
  `result` TINYINT NOT NULL DEFAULT 0,
  `reason` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_login_admin_time` (`admin_id`, `created_at`),
  KEY `idx_admin_login_platform_ip_time` (`platform_id`, `ip`, `created_at`),
  CONSTRAINT `fk_admin_login_platform` FOREIGN KEY (`platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_admin_login_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_entitlements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `membership_level` VARCHAR(40) NOT NULL DEFAULT 'trial',
  `membership_status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `membership_started_at` DATETIME NOT NULL,
  `membership_expired_at` DATETIME NOT NULL,
  `app_quota` INT UNSIGNED NOT NULL DEFAULT 1,
  `remote_document_quota` INT UNSIGNED NOT NULL DEFAULT 3,
  `integral` BIGINT NOT NULL DEFAULT 15,
  `access_start_time` TIME DEFAULT NULL,
  `access_end_time` TIME DEFAULT NULL,
  `allowed_weekdays` VARCHAR(30) NOT NULL DEFAULT '1,2,3,4,5,6,7',
  `last_granted_by_platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_entitlements_admin` (`admin_id`),
  KEY `idx_admin_entitlements_platform_expire` (`platform_id`, `membership_expired_at`),
  CONSTRAINT `fk_admin_entitlements_admin` FOREIGN KEY (`admin_id`, `platform_id`) REFERENCES `admins` (`id`, `platform_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_admin_entitlements_granter` FOREIGN KEY (`last_granted_by_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_permissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `permission_code` VARCHAR(80) NOT NULL,
  `allowed` TINYINT NOT NULL DEFAULT 1,
  `config_json` LONGTEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_permissions_code` (`admin_id`, `permission_code`),
  KEY `idx_admin_permissions_platform` (`platform_id`, `admin_id`),
  CONSTRAINT `fk_admin_permissions_admin` FOREIGN KEY (`admin_id`, `platform_id`) REFERENCES `admins` (`id`, `platform_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_public_profiles` (
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `official_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `download_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `official_qq_group` VARCHAR(100) NOT NULL DEFAULT '',
  `official_qq_group_link` VARCHAR(1000) NOT NULL DEFAULT '',
  `alipay_qr_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `wechat_qr_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `software_intro` TEXT,
  `about_us` TEXT,
  `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`admin_id`),
  CONSTRAINT `fk_admin_public_profiles_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_sponsor_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `sponsor_name` VARCHAR(100) NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `channel` VARCHAR(20) NOT NULL DEFAULT 'manual',
  `note` VARCHAR(500) NOT NULL DEFAULT '',
  `paid_at` DATETIME NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_admin_sponsor_rank` (`admin_id`, `status`, `amount`, `paid_at`, `id`),
  CONSTRAINT `fk_admin_sponsor_records_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_registration_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `account` VARCHAR(64) NOT NULL DEFAULT '',
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
  `result` TINYINT NOT NULL DEFAULT 0,
  `reason` VARCHAR(255) NOT NULL DEFAULT '',
  `gift_json` LONGTEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_registration_platform_time` (`platform_id`, `created_at`),
  KEY `idx_admin_registration_platform_ip` (`platform_id`, `ip`, `created_at`),
  CONSTRAINT `fk_admin_registration_platform` FOREIGN KEY (`platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_admin_registration_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_entitlement_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `actor_platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `change_type` VARCHAR(40) NOT NULL,
  `before_json` LONGTEXT,
  `change_json` LONGTEXT,
  `after_json` LONGTEXT,
  `remark` VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_entitlement_logs_admin_time` (`admin_id`, `created_at`),
  CONSTRAINT `fk_admin_entitlement_logs_admin` FOREIGN KEY (`admin_id`, `platform_id`) REFERENCES `admins` (`id`, `platform_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_admin_entitlement_logs_actor` FOREIGN KEY (`actor_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_purchase_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `order_no` VARCHAR(64) NOT NULL,
  `purchase_type` VARCHAR(40) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `request_json` LONGTEXT,
  `grant_json` LONGTEXT,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `admin_note` VARCHAR(500) NOT NULL DEFAULT '',
  `platform_note` VARCHAR(500) NOT NULL DEFAULT '',
  `handled_by_platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `handled_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_purchase_orders_no` (`order_no`),
  KEY `idx_admin_purchase_platform_status` (`platform_id`, `status`, `created_at`),
  KEY `idx_admin_purchase_admin` (`admin_id`, `created_at`),
  CONSTRAINT `fk_admin_purchase_admin` FOREIGN KEY (`admin_id`, `platform_id`) REFERENCES `admins` (`id`, `platform_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_admin_purchase_handler` FOREIGN KEY (`handled_by_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_platform_feedbacks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `type` VARCHAR(40) NOT NULL DEFAULT 'feedback',
  `title` VARCHAR(200) NOT NULL,
  `content` LONGTEXT NOT NULL,
  `images_json` LONGTEXT,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `reply_content` LONGTEXT,
  `replied_by_platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `replied_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_platform_feedback_status` (`platform_id`, `status`, `created_at`),
  KEY `idx_admin_platform_feedback_admin` (`admin_id`, `created_at`),
  CONSTRAINT `fk_admin_platform_feedback_admin` FOREIGN KEY (`admin_id`, `platform_id`) REFERENCES `admins` (`id`, `platform_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_admin_platform_feedback_replier` FOREIGN KEY (`replied_by_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_exchange_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `order_no` VARCHAR(64) NOT NULL,
  `idempotency_key` VARCHAR(100) NOT NULL,
  `product_code` VARCHAR(80) NOT NULL,
  `product_name` VARCHAR(150) NOT NULL,
  `product_type` VARCHAR(40) NOT NULL,
  `unit_price_integral` BIGINT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `total_integral` BIGINT UNSIGNED NOT NULL,
  `grant_json` LONGTEXT NOT NULL,
  `before_entitlement_json` LONGTEXT NOT NULL,
  `after_entitlement_json` LONGTEXT NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'completed',
  `refunded_by_platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `refund_reason` VARCHAR(500) NOT NULL DEFAULT '',
  `refunded_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_exchange_order_no` (`order_no`),
  UNIQUE KEY `uk_admin_exchange_idempotency` (`admin_id`, `idempotency_key`),
  KEY `idx_admin_exchange_platform_time` (`platform_id`, `created_at`),
  KEY `idx_admin_exchange_admin_time` (`admin_id`, `created_at`),
  KEY `idx_admin_exchange_product_status` (`product_id`, `status`),
  CONSTRAINT `fk_admin_exchange_order_admin` FOREIGN KEY (`admin_id`, `platform_id`) REFERENCES `admins` (`id`, `platform_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_admin_exchange_order_product` FOREIGN KEY (`product_id`, `platform_id`) REFERENCES `platform_exchange_products` (`id`, `platform_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_admin_exchange_order_refunder` FOREIGN KEY (`refunded_by_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_integral_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `change_value` BIGINT NOT NULL,
  `before_value` BIGINT NOT NULL,
  `after_value` BIGINT NOT NULL,
  `scene` VARCHAR(50) NOT NULL,
  `ref_type` VARCHAR(50) NOT NULL DEFAULT '',
  `ref_id` BIGINT UNSIGNED DEFAULT NULL,
  `remark` VARCHAR(500) NOT NULL DEFAULT '',
  `actor_platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_integral_logs_admin_time` (`admin_id`, `created_at`),
  KEY `idx_admin_integral_logs_platform_scene` (`platform_id`, `scene`, `created_at`),
  KEY `idx_admin_integral_logs_ref` (`ref_type`, `ref_id`),
  CONSTRAINT `fk_admin_integral_logs_admin` FOREIGN KEY (`admin_id`, `platform_id`) REFERENCES `admins` (`id`, `platform_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_admin_integral_logs_actor` FOREIGN KEY (`actor_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `platform_daily_statistics` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `stat_date` DATE NOT NULL,
  `admin_registered` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `admin_login_success` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `admin_login_failed` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `admin_active` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `purchase_created` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `purchase_fulfilled` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `point_exchange_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `point_exchange_integral` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `point_refund_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `point_refund_integral` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_daily_stats` (`platform_id`, `stat_date`),
  CONSTRAINT `fk_platform_daily_stats_owner` FOREIGN KEY (`platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `apps` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_key` VARCHAR(80) NOT NULL,
  `app_secret_hash` CHAR(64) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `app_type` VARCHAR(30) NOT NULL DEFAULT 'general',
  `logo` VARCHAR(500) NOT NULL DEFAULT '',
  `description` TEXT,
  `status` TINYINT NOT NULL DEFAULT 1,
  `disabled_reason` VARCHAR(255) DEFAULT NULL,
  `version` VARCHAR(40) NOT NULL DEFAULT '1.0.0',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_apps_app_key` (`app_key`),
  UNIQUE KEY `uk_apps_id_admin` (`id`, `admin_id`),
  KEY `idx_apps_admin_status` (`admin_id`, `status`),
  CONSTRAINT `fk_apps_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `setting_key` VARCHAR(64) NOT NULL,
  `setting_value` LONGTEXT,
  `value_type` VARCHAR(20) NOT NULL DEFAULT 'string',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_settings_key` (`app_id`, `setting_key`),
  KEY `idx_app_settings_tenant` (`admin_id`, `app_id`),
  CONSTRAINT `fk_app_settings_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_operation_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `module` VARCHAR(50) NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `target_id` BIGINT UNSIGNED DEFAULT NULL,
  `before_json` LONGTEXT,
  `after_json` LONGTEXT,
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_ops_tenant_time` (`admin_id`, `app_id`, `created_at`),
  KEY `idx_admin_ops_module_action` (`module`, `action`),
  CONSTRAINT `fk_admin_ops_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_admin_ops_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` VARCHAR(32) NOT NULL,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `account` VARCHAR(64) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `email` VARCHAR(190) DEFAULT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `register_ip` VARCHAR(64) NOT NULL DEFAULT '',
  `last_login_ip` VARCHAR(64) DEFAULT NULL,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_uid` (`uid`),
  UNIQUE KEY `uk_users_app_account` (`app_id`, `account`),
  UNIQUE KEY `uk_users_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_users_tenant_status` (`admin_id`, `app_id`, `status`),
  KEY `idx_users_app_email` (`app_id`, `email`),
  CONSTRAINT `fk_users_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_profiles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `nickname` VARCHAR(80) NOT NULL DEFAULT '',
  `qq` VARCHAR(30) NOT NULL DEFAULT '',
  `avatar` VARCHAR(500) NOT NULL DEFAULT '',
  `background` VARCHAR(500) NOT NULL DEFAULT '',
  `signature` VARCHAR(500) NOT NULL DEFAULT '',
  `gender` VARCHAR(20) NOT NULL DEFAULT '',
  `birthday` DATE DEFAULT NULL,
  `region` VARCHAR(120) NOT NULL DEFAULT '',
  `title` VARCHAR(100) NOT NULL DEFAULT '',
  `public_profile` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_profiles_user` (`user_id`),
  KEY `idx_user_profiles_tenant` (`admin_id`, `app_id`),
  CONSTRAINT `fk_user_profiles_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_message_preferences` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `accept_stranger_messages` TINYINT NOT NULL DEFAULT 1,
  `allow_friend_requests` TINYINT NOT NULL DEFAULT 1,
  `system_notification_enabled` TINYINT NOT NULL DEFAULT 1,
  `private_notification_enabled` TINYINT NOT NULL DEFAULT 1,
  `group_notification_enabled` TINYINT NOT NULL DEFAULT 1,
  `profile_notes_visible` TINYINT NOT NULL DEFAULT 1,
  `profile_forum_visible` TINYINT NOT NULL DEFAULT 1,
  `profile_bounties_visible` TINYINT NOT NULL DEFAULT 1,
  `profile_following_visible` TINYINT NOT NULL DEFAULT 1,
  `profile_followers_visible` TINYINT NOT NULL DEFAULT 1,
  `allow_card_add` TINYINT NOT NULL DEFAULT 1,
  `allow_qr_add` TINYINT NOT NULL DEFAULT 1,
  `allow_uid_search` TINYINT NOT NULL DEFAULT 1,
  `allow_phone_search` TINYINT NOT NULL DEFAULT 0,
  `allow_email_search` TINYINT NOT NULL DEFAULT 0,
  `allow_group_member_add` TINYINT NOT NULL DEFAULT 1,
  `allow_group_invitations` TINYINT NOT NULL DEFAULT 1,
  `show_online_status` TINYINT NOT NULL DEFAULT 1,
  `read_receipts_enabled` TINYINT NOT NULL DEFAULT 1,
  `room_notification_enabled` TINYINT NOT NULL DEFAULT 1,
  `forum_notification_enabled` TINYINT NOT NULL DEFAULT 1,
  `bounty_notification_enabled` TINYINT NOT NULL DEFAULT 1,
  `mention_notification_enabled` TINYINT NOT NULL DEFAULT 1,
  `notification_preview_enabled` TINYINT NOT NULL DEFAULT 1,
  `notification_sound_enabled` TINYINT NOT NULL DEFAULT 1,
  `notification_vibration_enabled` TINYINT NOT NULL DEFAULT 1,
  `remote_login_protection` TINYINT NOT NULL DEFAULT 1,
  `dynamic_enabled` TINYINT NOT NULL DEFAULT 1,
  `dynamic_visible_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `dynamic_visibility_mode` VARCHAR(20) NOT NULL DEFAULT 'public',
  `dynamic_allow_user_ids_json` LONGTEXT DEFAULT NULL,
  `dynamic_deny_user_ids_json` LONGTEXT DEFAULT NULL,
  `dynamic_visible_to_friends` TINYINT NOT NULL DEFAULT 1,
  `dynamic_visible_to_followers` TINYINT NOT NULL DEFAULT 1,
  `dynamic_visible_to_strangers` TINYINT NOT NULL DEFAULT 1,
  `dynamic_visible_to_hidden_contacts` TINYINT NOT NULL DEFAULT 1,
  `dynamic_visible_to_special_care` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_message_preferences_user` (`user_id`),
  KEY `idx_user_message_preferences_tenant` (`admin_id`, `app_id`),
  CONSTRAINT `fk_user_message_preferences_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_wallets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `integral` BIGINT NOT NULL DEFAULT 0,
  `experience` BIGINT NOT NULL DEFAULT 0,
  `balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `document_credit` BIGINT NOT NULL DEFAULT 0,
  `vip_expired_at` DATETIME DEFAULT NULL,
  `level_code` VARCHAR(40) NOT NULL DEFAULT 'normal',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_wallets_user` (`user_id`),
  KEY `idx_user_wallets_tenant` (`admin_id`, `app_id`),
  CONSTRAINT `fk_user_wallets_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_wallet_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `asset_type` VARCHAR(40) NOT NULL,
  `change_value` DECIMAL(20,2) NOT NULL DEFAULT 0.00,
  `before_value` DECIMAL(20,2) NOT NULL DEFAULT 0.00,
  `after_value` DECIMAL(20,2) NOT NULL DEFAULT 0.00,
  `scene` VARCHAR(50) NOT NULL DEFAULT '',
  `ref_type` VARCHAR(50) NOT NULL DEFAULT '',
  `ref_id` BIGINT UNSIGNED DEFAULT NULL,
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wallet_logs_user_time` (`user_id`, `created_at`),
  KEY `idx_wallet_logs_tenant_scene` (`admin_id`, `app_id`, `scene`),
  CONSTRAINT `fk_wallet_logs_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_asset_purchases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `order_no` VARCHAR(48) NOT NULL,
  `product_type` ENUM('document_credit','vip_days') NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `unit_price` DECIMAL(18,2) NOT NULL,
  `total_amount` DECIMAL(18,2) NOT NULL,
  `pay_asset` VARCHAR(40) NOT NULL DEFAULT 'balance',
  `status` ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
  `completed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_asset_purchases_no` (`order_no`),
  KEY `idx_user_asset_purchases_user` (`user_id`, `created_at`),
  KEY `idx_user_asset_purchases_tenant` (`admin_id`, `app_id`, `status`, `created_at`),
  CONSTRAINT `fk_user_asset_purchases_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_transfer_policies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `can_send` TINYINT NOT NULL DEFAULT 1,
  `can_receive` TINYINT NOT NULL DEFAULT 1,
  `single_limit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `daily_send_limit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `daily_receive_limit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `daily_pair_limit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `blocked_send_to_json` LONGTEXT,
  `blocked_receive_from_json` LONGTEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_transfer_policies_user` (`user_id`),
  KEY `idx_user_transfer_policies_tenant` (`admin_id`, `app_id`),
  CONSTRAINT `fk_user_transfer_policies_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `device` VARCHAR(100) NOT NULL DEFAULT '',
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
  `expired_at` DATETIME NOT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `last_used_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_tokens_hash` (`token_hash`),
  KEY `idx_user_tokens_user_expire` (`user_id`, `expired_at`),
  KEY `idx_user_tokens_tenant` (`admin_id`, `app_id`),
  CONSTRAINT `fk_user_tokens_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_login_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
  `result` TINYINT NOT NULL DEFAULT 0,
  `reason` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_login_tenant_time` (`admin_id`, `app_id`, `created_at`),
  KEY `idx_user_login_user_time` (`user_id`, `created_at`),
  CONSTRAINT `fk_user_login_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_login_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `owner_type` VARCHAR(20) NOT NULL DEFAULT 'user',
  `title` VARCHAR(200) NOT NULL,
  `content_type` VARCHAR(20) NOT NULL DEFAULT 'text',
  `content` LONGTEXT,
  `tags_json` LONGTEXT,
  `word_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_public` TINYINT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `version_no` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_documents_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_documents_user_time` (`app_id`, `user_id`, `updated_at`),
  KEY `idx_documents_owner_time` (`app_id`, `owner_type`, `updated_at`),
  KEY `idx_documents_public_status` (`app_id`, `is_public`, `status`),
  CONSTRAINT `fk_documents_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_documents_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_versions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `owner_type` VARCHAR(20) NOT NULL DEFAULT 'user',
  `title` VARCHAR(200) NOT NULL,
  `content_type` VARCHAR(20) NOT NULL DEFAULT 'text',
  `content` LONGTEXT,
  `word_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `version_no` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_versions_no` (`document_id`, `version_no`),
  KEY `idx_document_versions_tenant` (`admin_id`, `app_id`),
  CONSTRAINT `fk_document_versions_document` FOREIGN KEY (`document_id`, `app_id`, `admin_id`) REFERENCES `documents` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_document_versions_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `card_batches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `card_type` VARCHAR(30) NOT NULL DEFAULT 'mixed',
  `value_json` LONGTEXT NOT NULL,
  `total_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `used_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `max_use` INT UNSIGNED NOT NULL DEFAULT 1,
  `status` TINYINT NOT NULL DEFAULT 1,
  `expired_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_card_batches_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_card_batches_tenant_status` (`admin_id`, `app_id`, `status`),
  CONSTRAINT `fk_card_batches_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cards` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  `card_code` VARCHAR(64) NOT NULL,
  `card_type` VARCHAR(30) NOT NULL DEFAULT 'mixed',
  `value_json` LONGTEXT NOT NULL,
  `max_use` INT UNSIGNED NOT NULL DEFAULT 1,
  `used_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `expired_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cards_app_code` (`app_id`, `card_code`),
  UNIQUE KEY `uk_cards_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_cards_batch_status` (`batch_id`, `status`),
  CONSTRAINT `fk_cards_batch` FOREIGN KEY (`batch_id`, `app_id`, `admin_id`) REFERENCES `card_batches` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `card_redeem_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `card_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `reward_json` LONGTEXT NOT NULL,
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_card_redeem_card_user` (`card_id`, `user_id`),
  KEY `idx_card_redeem_tenant_time` (`admin_id`, `app_id`, `created_at`),
  KEY `idx_card_redeem_user_time` (`user_id`, `created_at`),
  CONSTRAINT `fk_card_redeem_card` FOREIGN KEY (`card_id`, `app_id`, `admin_id`) REFERENCES `cards` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_card_redeem_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `content` LONGTEXT NOT NULL,
  `type` VARCHAR(30) NOT NULL DEFAULT 'notice',
  `is_popup` TINYINT NOT NULL DEFAULT 0,
  `display_enabled` TINYINT NOT NULL DEFAULT 1,
  `popup_frequency` VARCHAR(20) NOT NULL DEFAULT 'once',
  `audience_type` VARCHAR(20) NOT NULL DEFAULT 'all',
  `audience_json` LONGTEXT,
  `status` TINYINT NOT NULL DEFAULT 1,
  `start_at` DATETIME DEFAULT NULL,
  `end_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notices_tenant_status_time` (`admin_id`, `app_id`, `status`, `created_at`),
  CONSTRAINT `fk_notices_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_versions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `version_name` VARCHAR(40) NOT NULL,
  `version_code` INT UNSIGNED NOT NULL,
  `min_supported_version_code` INT UNSIGNED NOT NULL DEFAULT 0,
  `apk_url` VARCHAR(500) NOT NULL,
  `package_name` VARCHAR(190) NOT NULL DEFAULT '',
  `sha256` CHAR(64) NOT NULL DEFAULT '',
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `update_content` LONGTEXT NOT NULL,
  `force_update` TINYINT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_versions_code` (`app_id`, `version_code`),
  KEY `idx_app_versions_tenant_status` (`admin_id`, `app_id`, `status`),
  CONSTRAINT `fk_app_versions_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_operation_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `module` VARCHAR(50) NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `target_id` BIGINT UNSIGNED DEFAULT NULL,
  `detail_json` LONGTEXT,
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_ops_user_time` (`user_id`, `created_at`),
  KEY `idx_user_ops_tenant_module` (`admin_id`, `app_id`, `module`),
  CONSTRAINT `fk_user_ops_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_request_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trace_id` VARCHAR(50) NOT NULL,
  `admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `actor_type` VARCHAR(20) NOT NULL DEFAULT 'public',
  `actor_id` BIGINT UNSIGNED DEFAULT NULL,
  `method` VARCHAR(10) NOT NULL,
  `path` VARCHAR(255) NOT NULL,
  `http_status` SMALLINT UNSIGNED NOT NULL,
  `result_code` INT NOT NULL,
  `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `ip` VARCHAR(64) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_api_request_trace` (`trace_id`),
  KEY `idx_api_request_tenant_time` (`admin_id`, `app_id`, `created_at`),
  KEY `idx_api_request_path_time` (`path`, `created_at`),
  CONSTRAINT `fk_api_request_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `statistics_daily` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `stat_date` DATE NOT NULL,
  `new_users` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `user_logins` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `document_created` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `document_updated` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `document_deleted` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `card_redeemed` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `api_requests` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `app_views` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `unique_visitors` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `heartbeat_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_statistics_daily_app_date` (`app_id`, `stat_date`),
  KEY `idx_statistics_daily_tenant` (`admin_id`, `app_id`),
  CONSTRAINT `fk_statistics_daily_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_error_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trace_id` VARCHAR(50) NOT NULL,
  `admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `path` VARCHAR(255) NOT NULL DEFAULT '',
  `error_class` VARCHAR(255) NOT NULL DEFAULT '',
  `error_message` VARCHAR(1000) NOT NULL DEFAULT '',
  `error_file` VARCHAR(500) NOT NULL DEFAULT '',
  `error_line` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_system_errors_trace` (`trace_id`),
  KEY `idx_system_errors_tenant_time` (`admin_id`, `app_id`, `created_at`),
  CONSTRAINT `fk_system_errors_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_feature_flags` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `feature_code` VARCHAR(64) NOT NULL,
  `enabled` TINYINT NOT NULL DEFAULT 1,
  `config_json` LONGTEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_features_code` (`app_id`, `feature_code`),
  KEY `idx_app_features_tenant` (`admin_id`, `app_id`),
  CONSTRAINT `fk_app_features_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_feature_permissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `feature_code` VARCHAR(64) NOT NULL,
  `enabled` TINYINT NOT NULL DEFAULT 1,
  `config_json` LONGTEXT,
  `updated_by_type` VARCHAR(20) NOT NULL DEFAULT 'admin',
  `updated_by_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_feature_permission` (`app_id`, `user_id`, `feature_code`),
  KEY `idx_user_feature_permission_tenant` (`admin_id`, `app_id`, `user_id`),
  CONSTRAINT `fk_user_feature_permission_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `app_domains` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `domain` VARCHAR(255) NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_domains_domain` (`app_id`, `domain`),
  KEY `idx_app_domains_tenant` (`admin_id`, `app_id`),
  CONSTRAINT `fk_app_domains_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_api_keys` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `key_name` VARCHAR(100) NOT NULL,
  `key_hash` CHAR(64) NOT NULL,
  `scopes_json` LONGTEXT,
  `status` TINYINT NOT NULL DEFAULT 1,
  `expired_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_api_keys_hash` (`key_hash`),
  KEY `idx_app_api_keys_tenant` (`admin_id`, `app_id`, `status`),
  CONSTRAINT `fk_app_api_keys_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_refresh_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `user_token_id` BIGINT UNSIGNED DEFAULT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expired_at` DATETIME NOT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_refresh_hash` (`token_hash`),
  KEY `idx_user_refresh_user_expire` (`user_id`, `expired_at`),
  CONSTRAINT `fk_user_refresh_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_refresh_access` FOREIGN KEY (`user_token_id`) REFERENCES `user_tokens` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_bans` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `ban_type` VARCHAR(40) NOT NULL DEFAULT 'all',
  `reason` VARCHAR(500) NOT NULL DEFAULT '',
  `start_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `end_at` DATETIME DEFAULT NULL,
  `operator_admin_id` BIGINT UNSIGNED NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_bans_user_status` (`user_id`, `status`, `end_at`),
  KEY `idx_user_bans_tenant` (`admin_id`, `app_id`),
  CONSTRAINT `fk_user_bans_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_bans_operator` FOREIGN KEY (`operator_admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_tags` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `color` VARCHAR(20) NOT NULL DEFAULT '#64748b',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_tags_name` (`app_id`, `name`),
  UNIQUE KEY `uk_user_tags_id_app_admin` (`id`, `app_id`, `admin_id`),
  CONSTRAINT `fk_user_tags_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_tag_relations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `tag_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_tag_relation` (`user_id`, `tag_id`),
  KEY `idx_user_tag_rel_tenant` (`admin_id`, `app_id`),
  CONSTRAINT `fk_user_tag_rel_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_tag_rel_tag` FOREIGN KEY (`tag_id`, `app_id`, `admin_id`) REFERENCES `user_tags` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_sign_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `sign_date` DATE NOT NULL,
  `reward_integral` BIGINT NOT NULL DEFAULT 0,
  `reward_experience` BIGINT NOT NULL DEFAULT 0,
  `reward_credit` BIGINT NOT NULL DEFAULT 0,
  `continuous_days` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_sign_date` (`app_id`, `user_id`, `sign_date`),
  KEY `idx_user_sign_tenant` (`admin_id`, `app_id`),
  CONSTRAINT `fk_user_sign_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invite_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `invite_code` VARCHAR(32) NOT NULL,
  `max_use` INT UNSIGNED NOT NULL DEFAULT 0,
  `used_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `reward_json` LONGTEXT,
  `status` TINYINT NOT NULL DEFAULT 1,
  `expired_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invite_codes_code` (`app_id`, `invite_code`),
  KEY `idx_invite_codes_user` (`user_id`, `status`),
  CONSTRAINT `fk_invite_codes_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invite_relations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `invite_code` VARCHAR(32) NOT NULL,
  `inviter_user_id` BIGINT UNSIGNED NOT NULL,
  `invited_user_id` BIGINT UNSIGNED NOT NULL,
  `reward_status` TINYINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invite_rel_invited` (`invited_user_id`),
  KEY `idx_invite_rel_inviter` (`inviter_user_id`, `created_at`),
  CONSTRAINT `fk_invite_rel_inviter` FOREIGN KEY (`inviter_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invite_rel_invited` FOREIGN KEY (`invited_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `verification_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `scene` VARCHAR(40) NOT NULL,
  `target` VARCHAR(190) NOT NULL,
  `code_hash` CHAR(64) NOT NULL,
  `payload_json` LONGTEXT,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `used_at` DATETIME DEFAULT NULL,
  `expired_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_verification_lookup` (`app_id`, `scene`, `target`, `created_at`),
  CONSTRAINT `fk_verification_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `identity_bindings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject_type` VARCHAR(20) NOT NULL,
  `subject_id` BIGINT UNSIGNED NOT NULL,
  `platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `identity_type` VARCHAR(20) NOT NULL,
  `identity_value` VARCHAR(190) NOT NULL,
  `identity_hash` CHAR(64) NOT NULL,
  `verified_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_identity_bindings_value` (`identity_type`, `identity_hash`),
  UNIQUE KEY `uk_identity_bindings_subject` (`subject_type`, `subject_id`, `identity_type`),
  KEY `idx_identity_bindings_tenant` (`platform_id`, `admin_id`, `app_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `identity_unbind_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject_type` VARCHAR(20) NOT NULL,
  `subject_id` BIGINT UNSIGNED NOT NULL,
  `platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `identity_type` VARCHAR(20) NOT NULL,
  `identity_value` VARCHAR(190) NOT NULL,
  `reviewer_type` VARCHAR(20) NOT NULL,
  `reviewer_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `reason` VARCHAR(500) NOT NULL DEFAULT '',
  `review_remark` VARCHAR(500) NOT NULL DEFAULT '',
  `reviewed_by_type` VARCHAR(20) DEFAULT NULL,
  `reviewed_by_id` BIGINT UNSIGNED DEFAULT NULL,
  `review_mode` VARCHAR(20) DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_identity_unbind_reviewer` (`reviewer_type`, `reviewer_id`, `status`, `id`),
  KEY `idx_identity_unbind_subject` (`subject_type`, `subject_id`, `status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_folders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `parent_id` BIGINT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(100) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_doc_folders_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_doc_folders_user_parent` (`user_id`, `parent_id`),
  CONSTRAINT `fk_doc_folders_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_doc_folders_parent` FOREIGN KEY (`parent_id`) REFERENCES `document_folders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_folder_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `folder_id` BIGINT UNSIGNED NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_doc_folder_item_document` (`document_id`),
  KEY `idx_doc_folder_items_folder` (`folder_id`, `created_at`),
  CONSTRAINT `fk_doc_folder_items_folder` FOREIGN KEY (`folder_id`, `app_id`, `admin_id`) REFERENCES `document_folders` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_doc_folder_items_document` FOREIGN KEY (`document_id`, `app_id`, `admin_id`) REFERENCES `documents` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_doc_folder_items_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_shares` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `share_code` VARCHAR(48) NOT NULL,
  `password_hash` VARCHAR(255) DEFAULT NULL,
  `expired_at` DATETIME DEFAULT NULL,
  `view_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_shares_code` (`share_code`),
  UNIQUE KEY `uk_document_shares_document_fixed` (`document_id`),
  KEY `idx_document_shares_document` (`document_id`, `status`),
  CONSTRAINT `fk_document_shares_document` FOREIGN KEY (`document_id`, `app_id`, `admin_id`) REFERENCES `documents` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_document_shares_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_quota_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `change_value` BIGINT NOT NULL,
  `before_value` BIGINT NOT NULL,
  `after_value` BIGINT NOT NULL,
  `scene` VARCHAR(50) NOT NULL,
  `ref_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_document_quota_user_time` (`user_id`, `created_at`),
  CONSTRAINT `fk_document_quota_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `banners` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL DEFAULT '',
  `image_url` VARCHAR(500) NOT NULL,
  `link_url` VARCHAR(500) NOT NULL DEFAULT '',
  `position` VARCHAR(40) NOT NULL DEFAULT 'home',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `start_at` DATETIME DEFAULT NULL,
  `end_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_banners_app_position` (`app_id`, `position`, `status`, `sort_order`),
  CONSTRAINT `fk_banners_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `remote_configs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `config_key` VARCHAR(100) NOT NULL,
  `config_value` LONGTEXT,
  `value_type` VARCHAR(20) NOT NULL DEFAULT 'string',
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_remote_configs_key` (`app_id`, `config_key`),
  KEY `idx_remote_configs_tenant` (`admin_id`, `app_id`),
  CONSTRAINT `fk_remote_configs_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `resource_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `resource_type` VARCHAR(30) NOT NULL DEFAULT 'app_store' COMMENT 'app_store/source_market',
  `name` VARCHAR(100) NOT NULL,
  `icon` VARCHAR(500) NOT NULL DEFAULT '',
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_categories_name` (`app_id`, `resource_type`, `name`),
  UNIQUE KEY `uk_resource_categories_id_app_admin` (`id`, `app_id`, `admin_id`),
  CONSTRAINT `fk_resource_categories_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `resources` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `resource_type` VARCHAR(30) NOT NULL DEFAULT 'app_store' COMMENT 'app_store/source_market',
  `category_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` LONGTEXT,
  `cover_url` VARCHAR(500) NOT NULL DEFAULT '',
  `download_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `file_sha256` CHAR(64) NOT NULL DEFAULT '',
  `risk_level` VARCHAR(20) NOT NULL DEFAULT 'review',
  `risk_reason` VARCHAR(1000) NOT NULL DEFAULT '',
  `source_upload_id` BIGINT UNSIGNED DEFAULT NULL,
  `cover_upload_id` BIGINT UNSIGNED DEFAULT NULL,
  `metadata_json` LONGTEXT,
  `tags_json` LONGTEXT,
  `images_json` LONGTEXT,
  `attachments_json` LONGTEXT,
  `price_integral` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `price_money` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `audit_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `audit_reason` VARCHAR(500) NOT NULL DEFAULT '',
  `audited_by` BIGINT UNSIGNED DEFAULT NULL,
  `audited_at` DATETIME DEFAULT NULL,
  `is_top` TINYINT NOT NULL DEFAULT 0,
  `is_recommended` TINYINT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `view_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `download_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resources_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_resources_category_status` (`app_id`, `category_id`, `audit_status`, `status`),
  KEY `idx_resources_type_status` (`app_id`, `resource_type`, `audit_status`, `status`, `created_at`),
  KEY `idx_resources_risk` (`app_id`, `risk_level`, `audit_status`),
  KEY `idx_resources_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_resources_category` FOREIGN KEY (`category_id`, `app_id`, `admin_id`) REFERENCES `resource_categories` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_resources_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resources_auditor` FOREIGN KEY (`audited_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `resource_files` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `resource_id` BIGINT UNSIGNED NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_url` VARCHAR(1000) NOT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `file_type` VARCHAR(100) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_resource_files_resource` (`resource_id`),
  CONSTRAINT `fk_resource_files_resource` FOREIGN KEY (`resource_id`, `app_id`, `admin_id`) REFERENCES `resources` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `resource_comments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `resource_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `parent_id` BIGINT UNSIGNED DEFAULT NULL,
  `content` TEXT NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_resource_comments_resource` (`resource_id`, `status`, `created_at`),
  CONSTRAINT `fk_resource_comments_resource` FOREIGN KEY (`resource_id`, `app_id`, `admin_id`) REFERENCES `resources` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resource_comments_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resource_comments_parent` FOREIGN KEY (`parent_id`) REFERENCES `resource_comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `resource_ratings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `resource_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `score` TINYINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_ratings_user` (`resource_id`, `user_id`),
  CONSTRAINT `fk_resource_ratings_resource` FOREIGN KEY (`resource_id`, `app_id`, `admin_id`) REFERENCES `resources` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resource_ratings_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `resource_purchases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `resource_id` BIGINT UNSIGNED NOT NULL,
  `buyer_user_id` BIGINT UNSIGNED NOT NULL,
  `seller_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `price_integral` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `asset_type` VARCHAR(20) NOT NULL DEFAULT 'balance',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_purchase_buyer` (`resource_id`, `buyer_user_id`),
  CONSTRAINT `fk_resource_purchase_resource` FOREIGN KEY (`resource_id`, `app_id`, `admin_id`) REFERENCES `resources` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_resource_purchase_buyer` FOREIGN KEY (`buyer_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_resource_purchase_seller` FOREIGN KEY (`seller_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `icon` VARCHAR(500) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_store_categories_name` (`app_id`, `name`),
  UNIQUE KEY `uk_store_categories_id_app_admin` (`id`, `app_id`, `admin_id`),
  CONSTRAINT `fk_store_categories_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_apps` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED DEFAULT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(150) NOT NULL,
  `package_name` VARCHAR(190) NOT NULL,
  `version_name` VARCHAR(40) NOT NULL,
  `version_code` INT UNSIGNED NOT NULL DEFAULT 1,
  `icon_url` VARCHAR(500) NOT NULL DEFAULT '',
  `apk_url` VARCHAR(1000) NOT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `description` LONGTEXT,
  `metadata_json` LONGTEXT,
  `file_sha256` CHAR(64) NOT NULL DEFAULT '',
  `risk_level` VARCHAR(20) NOT NULL DEFAULT 'review',
  `risk_reason` VARCHAR(1000) NOT NULL DEFAULT '',
  `source_upload_id` BIGINT UNSIGNED DEFAULT NULL,
  `icon_upload_id` BIGINT UNSIGNED DEFAULT NULL,
  `audit_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `audit_reason` VARCHAR(500) NOT NULL DEFAULT '',
  `audited_by` BIGINT UNSIGNED DEFAULT NULL,
  `audited_at` DATETIME DEFAULT NULL,
  `price_integral` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `download_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_store_apps_package_version` (`app_id`, `package_name`, `version_code`),
  UNIQUE KEY `uk_store_apps_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_store_apps_category_status` (`app_id`, `category_id`, `status`),
  KEY `idx_store_apps_audit` (`app_id`, `audit_status`, `risk_level`, `status`),
  CONSTRAINT `fk_store_apps_category` FOREIGN KEY (`category_id`, `app_id`, `admin_id`) REFERENCES `store_categories` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_store_apps_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_store_apps_auditor` FOREIGN KEY (`audited_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_app_purchases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `store_app_id` BIGINT UNSIGNED NOT NULL,
  `buyer_user_id` BIGINT UNSIGNED NOT NULL,
  `seller_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `price_balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `asset_type` VARCHAR(20) NOT NULL DEFAULT 'balance',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_store_app_purchase_buyer` (`store_app_id`, `buyer_user_id`),
  KEY `idx_store_app_purchases_buyer` (`app_id`, `buyer_user_id`, `created_at`),
  CONSTRAINT `fk_store_app_purchase_app` FOREIGN KEY (`store_app_id`, `app_id`, `admin_id`) REFERENCES `store_apps` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_store_app_purchase_buyer` FOREIGN KEY (`buyer_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_store_app_purchase_seller` FOREIGN KEY (`seller_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_app_images` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `store_app_id` BIGINT UNSIGNED NOT NULL,
  `image_url` VARCHAR(1000) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_store_app_images_app` (`store_app_id`, `sort_order`),
  CONSTRAINT `fk_store_app_images_app` FOREIGN KEY (`store_app_id`, `app_id`, `admin_id`) REFERENCES `store_apps` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_plates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `icon` VARCHAR(500) NOT NULL DEFAULT '',
  `description` VARCHAR(1000) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_plates_name` (`app_id`, `name`),
  UNIQUE KEY `uk_forum_plates_id_app_admin` (`id`, `app_id`, `admin_id`),
  CONSTRAINT `fk_forum_plates_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `plate_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `icon` VARCHAR(500) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_categories_name` (`app_id`, `plate_id`, `name`),
  UNIQUE KEY `uk_forum_categories_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_forum_categories_plate` (`app_id`, `plate_id`, `status`, `sort_order`),
  CONSTRAINT `fk_forum_categories_plate` FOREIGN KEY (`plate_id`, `app_id`, `admin_id`)
    REFERENCES `forum_plates` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_tags` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `plate_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(80) NOT NULL,
  `aliases_json` LONGTEXT,
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_tags_name` (`app_id`, `plate_id`, `name`),
  KEY `idx_forum_tags_category` (`app_id`, `plate_id`, `category_id`, `status`, `sort_order`),
  CONSTRAINT `fk_forum_tags_plate` FOREIGN KEY (`plate_id`, `app_id`, `admin_id`)
    REFERENCES `forum_plates` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_tags_category` FOREIGN KEY (`category_id`) REFERENCES `forum_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_structure_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `request_type` VARCHAR(20) NOT NULL,
  `plate_id` BIGINT UNSIGNED DEFAULT NULL,
  `category_id` BIGINT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(100) NOT NULL,
  `aliases_json` LONGTEXT,
  `description` VARCHAR(1000) NOT NULL DEFAULT '',
  `reason` VARCHAR(1000) NOT NULL DEFAULT '',
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `reviewer_admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `review_comment` VARCHAR(1000) NOT NULL DEFAULT '',
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_forum_structure_requests_status` (`app_id`, `status`, `request_type`, `created_at`),
  KEY `idx_forum_structure_requests_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_forum_structure_requests_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_structure_requests_plate` FOREIGN KEY (`plate_id`) REFERENCES `forum_plates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_forum_structure_requests_category` FOREIGN KEY (`category_id`) REFERENCES `forum_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_forum_structure_requests_reviewer` FOREIGN KEY (`reviewer_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_moderators` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `plate_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `permissions_json` LONGTEXT,
  `status` TINYINT NOT NULL DEFAULT 1,
  `granted_by_admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_moderators_plate_user` (`plate_id`, `user_id`),
  KEY `idx_forum_moderators_user` (`admin_id`, `app_id`, `user_id`, `status`),
  CONSTRAINT `fk_forum_moderators_plate` FOREIGN KEY (`plate_id`, `app_id`, `admin_id`)
    REFERENCES `forum_plates` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_moderators_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_moderators_granter` FOREIGN KEY (`granted_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_posts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `plate_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED DEFAULT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `client_draft_id` CHAR(36) DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `content` LONGTEXT NOT NULL,
  `images_json` LONGTEXT,
  `tags_json` LONGTEXT,
  `is_top` TINYINT NOT NULL DEFAULT 0,
  `is_essence` TINYINT NOT NULL DEFAULT 0,
  `is_locked` TINYINT NOT NULL DEFAULT 0,
  `audit_status` VARCHAR(20) NOT NULL DEFAULT 'approved',
  `audit_reason` VARCHAR(500) NOT NULL DEFAULT '',
  `audited_by` BIGINT UNSIGNED DEFAULT NULL,
  `audited_at` DATETIME DEFAULT NULL,
  `view_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `unique_view_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `like_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `comment_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `heat_score` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `hot_label` VARCHAR(40) NOT NULL DEFAULT '',
  `last_activity_at` DATETIME DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_posts_id_app_admin` (`id`, `app_id`, `admin_id`),
  UNIQUE KEY `uk_forum_posts_client_draft` (`app_id`, `user_id`, `client_draft_id`),
  KEY `idx_forum_posts_plate_order` (`app_id`, `plate_id`, `is_top`, `created_at`),
  KEY `idx_forum_posts_category_order` (`app_id`, `category_id`, `created_at`),
  KEY `idx_forum_posts_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_forum_posts_plate` FOREIGN KEY (`plate_id`, `app_id`, `admin_id`) REFERENCES `forum_plates` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_posts_category` FOREIGN KEY (`category_id`) REFERENCES `forum_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_forum_posts_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
  ,CONSTRAINT `fk_forum_posts_auditor` FOREIGN KEY (`audited_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_comments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `parent_id` BIGINT UNSIGNED DEFAULT NULL,
  `root_comment_id` BIGINT UNSIGNED DEFAULT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `content` TEXT NOT NULL,
  `tags_json` LONGTEXT,
  `mentions_json` LONGTEXT,
  `is_pinned` TINYINT NOT NULL DEFAULT 0,
  `pin_order` INT NOT NULL DEFAULT 0,
  `like_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `favorite_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `audit_status` VARCHAR(20) NOT NULL DEFAULT 'approved',
  `audit_reason` VARCHAR(500) NOT NULL DEFAULT '',
  `audited_by` BIGINT UNSIGNED DEFAULT NULL,
  `audited_at` DATETIME DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_comments_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_forum_comments_post` (`post_id`, `status`, `created_at`),
  KEY `idx_forum_comments_root` (`post_id`, `root_comment_id`, `status`, `id`),
  CONSTRAINT `fk_forum_comments_post` FOREIGN KEY (`post_id`, `app_id`, `admin_id`) REFERENCES `forum_posts` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_comments_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_comments_parent` FOREIGN KEY (`parent_id`) REFERENCES `forum_comments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_comments_auditor` FOREIGN KEY (`audited_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_likes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(20) NOT NULL DEFAULT 'post',
  `target_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_likes_target_user` (`app_id`, `user_id`, `target_type`, `target_id`),
  CONSTRAINT `fk_forum_likes_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_favorites` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_favorites_post_user` (`app_id`, `post_id`, `user_id`),
  CONSTRAINT `fk_forum_favorites_post` FOREIGN KEY (`post_id`, `app_id`, `admin_id`) REFERENCES `forum_posts` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_favorites_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_reports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `report_tag_id` BIGINT UNSIGNED DEFAULT NULL,
  `reason` VARCHAR(1000) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `handled_by` BIGINT UNSIGNED DEFAULT NULL,
  `handled_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_forum_reports_tenant_status` (`admin_id`, `app_id`, `status`),
  CONSTRAINT `fk_forum_reports_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_reports_admin` FOREIGN KEY (`handled_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `content_reports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(40) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `reason` VARCHAR(1000) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `reply` VARCHAR(1000) NOT NULL DEFAULT '',
  `handled_by` BIGINT UNSIGNED DEFAULT NULL,
  `handled_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_content_reports_tenant_status` (`admin_id`, `app_id`, `status`),
  CONSTRAINT `fk_content_reports_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_content_reports_admin` FOREIGN KEY (`handled_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `type` VARCHAR(20) NOT NULL DEFAULT 'private',
  `user_a_id` BIGINT UNSIGNED NOT NULL,
  `user_b_id` BIGINT UNSIGNED NOT NULL,
  `last_message_id` BIGINT UNSIGNED DEFAULT NULL,
  `last_message_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_conversations_private` (`app_id`, `type`, `user_a_id`, `user_b_id`),
  UNIQUE KEY `uk_conversations_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_conversations_user_a` (`user_a_id`, `last_message_at`),
  KEY `idx_conversations_user_b` (`user_b_id`, `last_message_at`),
  CONSTRAINT `fk_conversations_user_a` FOREIGN KEY (`user_a_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conversations_user_b` FOREIGN KEY (`user_b_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `voice_calls` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `caller_user_id` BIGINT UNSIGNED NOT NULL,
  `callee_user_id` BIGINT UNSIGNED NOT NULL,
  `conversation_id` BIGINT UNSIGNED DEFAULT NULL,
  `private_message_id` BIGINT UNSIGNED DEFAULT NULL,
  `room_message_id` BIGINT UNSIGNED DEFAULT NULL,
  `context_type` VARCHAR(20) NOT NULL DEFAULT 'private',
  `context_id` BIGINT UNSIGNED DEFAULT NULL,
  `context_name` VARCHAR(120) NOT NULL DEFAULT '',
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
  KEY `idx_voice_calls_context` (`app_id`, `context_type`, `context_id`, `created_at`),
  KEY `idx_voice_calls_private_message` (`private_message_id`),
  KEY `idx_voice_calls_room_message` (`room_message_id`),
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

CREATE TABLE IF NOT EXISTS `messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `conversation_id` BIGINT UNSIGNED DEFAULT NULL,
  `sender_type` VARCHAR(20) NOT NULL,
  `sender_id` BIGINT UNSIGNED NOT NULL,
  `receiver_user_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL DEFAULT '',
  `content_type` VARCHAR(20) NOT NULL DEFAULT 'text',
  `content` LONGTEXT NOT NULL,
  `tags_json` LONGTEXT,
  `reply_to_message_id` BIGINT UNSIGNED DEFAULT NULL,
  `is_read` TINYINT NOT NULL DEFAULT 0,
  `read_at` DATETIME DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_messages_conversation` (`conversation_id`, `id`),
  KEY `idx_messages_receiver_unread` (`receiver_user_id`, `is_read`, `created_at`),
  KEY `idx_messages_reply` (`reply_to_message_id`),
  CONSTRAINT `fk_messages_conversation` FOREIGN KEY (`conversation_id`, `app_id`, `admin_id`) REFERENCES `conversations` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_receiver` FOREIGN KEY (`receiver_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_reply` FOREIGN KEY (`reply_to_message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_edit_histories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `channel_type` VARCHAR(20) NOT NULL,
  `channel_id` BIGINT UNSIGNED NOT NULL,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `editor_user_id` BIGINT UNSIGNED NOT NULL,
  `old_content` LONGTEXT NOT NULL,
  `new_content` LONGTEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_message_edit_histories_message` (`app_id`, `channel_type`, `message_id`, `id`),
  KEY `idx_message_edit_histories_channel` (`app_id`, `channel_type`, `channel_id`, `created_at`),
  KEY `idx_message_edit_histories_editor` (`editor_user_id`, `created_at`),
  CONSTRAINT `fk_message_edit_histories_editor` FOREIGN KEY (`editor_user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `friend_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `from_user_id` BIGINT UNSIGNED NOT NULL,
  `to_user_id` BIGINT UNSIGNED NOT NULL,
  `message` VARCHAR(500) NOT NULL DEFAULT '',
  `requester_remark` VARCHAR(100) NOT NULL DEFAULT '',
  `requester_group_id` BIGINT UNSIGNED DEFAULT NULL,
  `hide_my_dynamic` TINYINT NOT NULL DEFAULT 0,
  `hide_their_dynamic` TINYINT NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `decision_reason` VARCHAR(255) NOT NULL DEFAULT '',
  `ignore_reason` VARCHAR(255) NOT NULL DEFAULT '',
  `ignored_at` DATETIME DEFAULT NULL,
  `expired_at` DATETIME NOT NULL,
  `handled_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_friend_requests_pair` (`app_id`, `from_user_id`, `to_user_id`, `status`),
  KEY `idx_friend_requests_to` (`to_user_id`, `status`, `created_at`),
  CONSTRAINT `fk_friend_requests_from` FOREIGN KEY (`from_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_friend_requests_to` FOREIGN KEY (`to_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `friends` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `friend_user_id` BIGINT UNSIGNED NOT NULL,
  `remark` VARCHAR(100) NOT NULL DEFAULT '',
  `special_care` TINYINT NOT NULL DEFAULT 0,
  `relationship_label` VARCHAR(60) NOT NULL DEFAULT '',
  `clue_note` VARCHAR(500) NOT NULL DEFAULT '',
  `only_chat` TINYINT NOT NULL DEFAULT 0,
  `hide_my_notes` TINYINT NOT NULL DEFAULT 0,
  `hide_their_notes` TINYINT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_friends_pair` (`app_id`, `user_id`, `friend_user_id`),
  KEY `idx_friends_user_status` (`user_id`, `status`),
  CONSTRAINT `fk_friends_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_friends_friend` FOREIGN KEY (`friend_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `chat_rooms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `icon` VARCHAR(500) NOT NULL DEFAULT '',
  `description` VARCHAR(1000) NOT NULL DEFAULT '',
  `tags_json` LONGTEXT,
  `room_kind` VARCHAR(20) NOT NULL DEFAULT 'group' COMMENT 'group/chat_room',
  `is_public` TINYINT NOT NULL DEFAULT 1,
  `status` TINYINT NOT NULL DEFAULT 1,
  `dissolved_at` DATETIME DEFAULT NULL,
  `restore_until` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_rooms_name` (`app_id`, `name`),
  UNIQUE KEY `uk_chat_rooms_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_chat_rooms_kind` (`app_id`, `room_kind`, `status`, `created_at`),
  CONSTRAINT `fk_chat_rooms_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_members` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `role` VARCHAR(20) NOT NULL DEFAULT 'member',
  `mute_until` DATETIME DEFAULT NULL,
  `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `history_visible_from` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_room_members_user` (`room_id`, `user_id`),
  CONSTRAINT `fk_chat_room_members_room` FOREIGN KEY (`room_id`, `app_id`, `admin_id`) REFERENCES `chat_rooms` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_members_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS `chat_room_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `sender_type` VARCHAR(20) NOT NULL DEFAULT 'user',
  `sender_admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `content_type` VARCHAR(20) NOT NULL DEFAULT 'text',
  `content` LONGTEXT NOT NULL,
  `tags_json` LONGTEXT,
  `reply_to_message_id` BIGINT UNSIGNED DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chat_room_messages_room` (`room_id`, `id`),
  KEY `idx_chat_room_messages_sender_admin` (`sender_admin_id`, `created_at`),
  CONSTRAINT `fk_chat_room_messages_room` FOREIGN KEY (`room_id`, `app_id`, `admin_id`) REFERENCES `chat_rooms` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_messages_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_messages_sender_admin` FOREIGN KEY (`sender_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_chat_room_messages_reply` FOREIGN KEY (`reply_to_message_id`) REFERENCES `chat_room_messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_policies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `owner_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `join_mode` VARCHAR(20) NOT NULL DEFAULT 'open',
  `max_members` INT UNSIGNED NOT NULL DEFAULT 500,
  `allow_member_invite` TINYINT NOT NULL DEFAULT 1,
  `mute_all` TINYINT NOT NULL DEFAULT 0,
  `announcement` VARCHAR(2000) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_room_policies_room` (`room_id`),
  KEY `idx_chat_room_policies_owner` (`app_id`, `owner_user_id`),
  CONSTRAINT `fk_chat_room_policies_room` FOREIGN KEY (`room_id`, `app_id`, `admin_id`) REFERENCES `chat_rooms` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_policies_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_invitations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `inviter_user_id` BIGINT UNSIGNED NOT NULL,
  `invitee_user_id` BIGINT UNSIGNED NOT NULL,
  `message` VARCHAR(500) NOT NULL DEFAULT '',
  `share_history` TINYINT(1) NOT NULL DEFAULT 1,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `decision_reason` VARCHAR(255) NOT NULL DEFAULT '',
  `ignore_reason` VARCHAR(255) NOT NULL DEFAULT '',
  `ignored_at` DATETIME DEFAULT NULL,
  `expired_at` DATETIME DEFAULT NULL,
  `responded_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_room_invitations_target` (`room_id`, `invitee_user_id`),
  KEY `idx_chat_room_invitations_user` (`app_id`, `invitee_user_id`, `status`, `created_at`),
  CONSTRAINT `fk_chat_room_invitations_room` FOREIGN KEY (`room_id`, `app_id`, `admin_id`) REFERENCES `chat_rooms` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_invitations_inviter` FOREIGN KEY (`inviter_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_invitations_invitee` FOREIGN KEY (`invitee_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_join_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `message` VARCHAR(500) NOT NULL DEFAULT '',
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `decision_reason` VARCHAR(255) NOT NULL DEFAULT '',
  `ignore_reason` VARCHAR(255) NOT NULL DEFAULT '',
  `ignored_at` DATETIME DEFAULT NULL,
  `expired_at` DATETIME NOT NULL,
  `handled_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `handled_by_admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `handled_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_room_join_requests_user` (`room_id`, `user_id`),
  KEY `idx_chat_room_join_requests_room` (`room_id`, `status`, `created_at`),
  CONSTRAINT `fk_chat_room_join_requests_room` FOREIGN KEY (`room_id`, `app_id`, `admin_id`) REFERENCES `chat_rooms` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_join_requests_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_join_requests_handler_user` FOREIGN KEY (`handled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_chat_room_join_requests_handler_admin` FOREIGN KEY (`handled_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_reads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `last_read_message_id` BIGINT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_room_reads_user` (`room_id`, `user_id`),
  CONSTRAINT `fk_chat_room_reads_room` FOREIGN KEY (`room_id`, `app_id`, `admin_id`) REFERENCES `chat_rooms` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_reads_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_reads_message` FOREIGN KEY (`last_read_message_id`) REFERENCES `chat_room_messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `service_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `assigned_admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `subject` VARCHAR(200) NOT NULL DEFAULT '',
  `status` VARCHAR(20) NOT NULL DEFAULT 'open',
  `last_message_at` DATETIME DEFAULT NULL,
  `closed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_service_sessions_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_service_sessions_user_status` (`user_id`, `status`),
  KEY `idx_service_sessions_tenant_status` (`admin_id`, `app_id`, `status`),
  CONSTRAINT `fk_service_sessions_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_service_sessions_assigned` FOREIGN KEY (`assigned_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `service_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `sender_type` VARCHAR(20) NOT NULL,
  `sender_id` BIGINT UNSIGNED NOT NULL,
  `reply_to_message_id` BIGINT UNSIGNED DEFAULT NULL,
  `content` LONGTEXT NOT NULL,
  `is_read` TINYINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_service_messages_session` (`session_id`, `id`),
  KEY `idx_service_messages_reply` (`reply_to_message_id`),
  CONSTRAINT `fk_service_messages_session` FOREIGN KEY (`session_id`, `app_id`, `admin_id`) REFERENCES `service_sessions` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_service_messages_reply` FOREIGN KEY (`reply_to_message_id`) REFERENCES `service_messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  KEY `idx_communication_takeover_audit_message` (`admin_id`, `app_id`, `channel_type`, `message_id`, `action`),
  CONSTRAINT `fk_communication_takeover_audit_app` FOREIGN KEY (`app_id`, `admin_id`)
    REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_channels` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `channel_code` VARCHAR(40) NOT NULL,
  `name` VARCHAR(100) NOT NULL DEFAULT '',
  `config_json` LONGTEXT,
  `enabled` TINYINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payment_channels_code` (`app_id`, `channel_code`),
  CONSTRAINT `fk_payment_channels_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `order_no` VARCHAR(64) NOT NULL,
  `order_type` VARCHAR(40) NOT NULL,
  `target_id` BIGINT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `pay_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `pay_channel` VARCHAR(40) NOT NULL DEFAULT '',
  `buyer_info_json` LONGTEXT,
  `snapshot_json` LONGTEXT,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `paid_at` DATETIME DEFAULT NULL,
  `closed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_orders_order_no` (`order_no`),
  UNIQUE KEY `uk_orders_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_orders_user_status` (`user_id`, `status`, `created_at`),
  KEY `idx_orders_tenant_status` (`admin_id`, `app_id`, `status`),
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `channel_code` VARCHAR(40) NOT NULL,
  `trade_no` VARCHAR(190) NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL,
  `callback_json` LONGTEXT,
  `status` VARCHAR(20) NOT NULL DEFAULT 'paid',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payments_channel_trade` (`app_id`, `channel_code`, `trade_no`),
  KEY `idx_payments_order` (`order_id`),
  CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`, `app_id`, `admin_id`) REFERENCES `orders` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_callback_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `channel_code` VARCHAR(40) NOT NULL,
  `order_no` VARCHAR(64) NOT NULL DEFAULT '',
  `request_json` LONGTEXT,
  `verified` TINYINT NOT NULL DEFAULT 0,
  `result` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payment_callbacks_order` (`order_no`, `created_at`),
  CONSTRAINT `fk_payment_callbacks_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_goods` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `catalog_code` VARCHAR(30) NOT NULL DEFAULT 'shop' COMMENT 'shop/balance_shop',
  `category_id` BIGINT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(200) NOT NULL,
  `cover_url` VARCHAR(500) NOT NULL DEFAULT '',
  `description` LONGTEXT,
  `goods_type` VARCHAR(20) NOT NULL DEFAULT 'virtual',
  `delivery_required` TINYINT NOT NULL DEFAULT 0,
  `tags_json` LONGTEXT,
  `images_json` LONGTEXT,
  `attachments_json` LONGTEXT,
  `price_integral` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `price_money` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `stock` INT NOT NULL DEFAULT 0,
  `sales_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shop_goods_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_shop_goods_tenant_status` (`admin_id`, `app_id`, `status`),
  KEY `idx_shop_goods_category` (`app_id`, `category_id`, `status`, `created_at`),
  KEY `idx_shop_goods_catalog_status` (`app_id`, `catalog_code`, `status`, `created_at`, `id`),
  CONSTRAINT `fk_shop_goods_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `catalog_code` VARCHAR(30) NOT NULL DEFAULT 'shop' COMMENT 'shop/balance_shop',
  `parent_id` BIGINT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(100) NOT NULL,
  `icon_url` VARCHAR(500) NOT NULL DEFAULT '',
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shop_categories_tenant_name` (`admin_id`, `app_id`, `catalog_code`, `parent_id`, `name`),
  KEY `idx_shop_categories_tenant_status` (`admin_id`, `app_id`, `status`, `sort_order`),
  CONSTRAINT `fk_shop_categories_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `catalog_code` VARCHAR(30) NOT NULL DEFAULT 'shop' COMMENT 'shop/balance_shop',
  `user_id` BIGINT UNSIGNED NOT NULL,
  `goods_id` BIGINT UNSIGNED NOT NULL,
  `goods_name` VARCHAR(200) NOT NULL DEFAULT '',
  `goods_cover_url` VARCHAR(500) NOT NULL DEFAULT '',
  `goods_type` VARCHAR(20) NOT NULL DEFAULT 'virtual',
  `order_no` VARCHAR(64) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `unit_price_integral` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `unit_price_money` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `amount_integral` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `amount_money` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `buyer_info_json` LONGTEXT,
  `status` VARCHAR(20) NOT NULL DEFAULT 'paid',
  `paid_at` DATETIME DEFAULT NULL,
  `fulfilled_at` DATETIME DEFAULT NULL,
  `closed_at` DATETIME DEFAULT NULL,
  `shipping_company` VARCHAR(100) NOT NULL DEFAULT '',
  `tracking_no` VARCHAR(120) NOT NULL DEFAULT '',
  `fulfillment_note` VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shop_orders_order_no` (`order_no`),
  KEY `idx_shop_orders_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_shop_orders_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shop_orders_goods` FOREIGN KEY (`goods_id`, `app_id`, `admin_id`) REFERENCES `shop_goods` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_goods_comments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `goods_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `parent_id` BIGINT UNSIGNED DEFAULT NULL,
  `content` TEXT NOT NULL,
  `score` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shop_goods_comments_goods` (`goods_id`, `status`, `created_at`),
  KEY `idx_shop_goods_comments_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_shop_goods_comments_goods` FOREIGN KEY (`goods_id`, `app_id`, `admin_id`) REFERENCES `shop_goods` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shop_goods_comments_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_goods_reactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `goods_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `reaction_type` VARCHAR(20) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shop_goods_reactions` (`goods_id`, `user_id`, `reaction_type`),
  KEY `idx_shop_goods_reactions_user` (`user_id`, `reaction_type`, `created_at`),
  CONSTRAINT `fk_shop_goods_reactions_goods` FOREIGN KEY (`goods_id`, `app_id`, `admin_id`) REFERENCES `shop_goods` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shop_goods_reactions_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_goods_forwards` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `goods_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(30) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `recommend_text` VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shop_goods_forwards_goods` (`goods_id`, `created_at`),
  KEY `idx_shop_goods_forwards_target` (`app_id`, `target_type`, `target_id`, `created_at`),
  CONSTRAINT `fk_shop_goods_forwards_goods` FOREIGN KEY (`goods_id`, `app_id`, `admin_id`) REFERENCES `shop_goods` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shop_goods_forwards_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_comment_reactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `comment_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `reaction_type` VARCHAR(20) NOT NULL DEFAULT 'like',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shop_comment_reactions` (`comment_id`, `user_id`, `reaction_type`),
  KEY `idx_shop_comment_reactions_user` (`user_id`, `reaction_type`, `created_at`),
  CONSTRAINT `fk_shop_comment_reactions_comment` FOREIGN KEY (`comment_id`) REFERENCES `shop_goods_comments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shop_comment_reactions_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_order_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `order_source` VARCHAR(20) NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `order_no` VARCHAR(64) NOT NULL,
  `event_code` VARCHAR(40) NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `detail` VARCHAR(500) NOT NULL DEFAULT '',
  `actor_type` VARCHAR(20) NOT NULL DEFAULT 'system',
  `actor_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `metadata_json` LONGTEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shop_order_events_order` (`app_id`, `order_no`, `created_at`),
  KEY `idx_shop_order_events_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_shop_order_events_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `red_packets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `creator_type` VARCHAR(20) NOT NULL DEFAULT 'user' COMMENT 'platform/admin/user/system',
  `creator_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `packet_type` VARCHAR(20) NOT NULL DEFAULT 'random',
  `packet_label` VARCHAR(30) NOT NULL DEFAULT '拼手气红包',
  `distribution_mode` VARCHAR(20) NOT NULL DEFAULT 'count_split' COMMENT 'count_split/random_grab',
  `eligibility_mode` VARCHAR(20) NOT NULL DEFAULT 'selected' COMMENT 'context_all/selected',
  `scene_type` VARCHAR(30) NOT NULL DEFAULT 'chat' COMMENT 'chat/forum_tip/bounty_tip/earned_reward/activity',
  `delivery_scope` VARCHAR(20) NOT NULL DEFAULT 'private' COMMENT 'private/group/chat_room/service/activity',
  `context_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会话、群聊、聊天室或活动编号',
  `source_type` VARCHAR(40) NOT NULL DEFAULT '',
  `source_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `total_amount` DECIMAL(18,2) UNSIGNED NOT NULL,
  `total_count` INT UNSIGNED NOT NULL,
  `remain_amount` DECIMAL(18,2) UNSIGNED NOT NULL,
  `remain_count` INT UNSIGNED NOT NULL,
  `message` VARCHAR(255) NOT NULL DEFAULT '',
  `return_policy` VARCHAR(30) NOT NULL DEFAULT 'recipient_return' COMMENT 'recipient_return/manager_only/none',
  `status` TINYINT NOT NULL DEFAULT 1,
  `expired_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_red_packets_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_red_packets_tenant_status` (`admin_id`, `app_id`, `status`, `expired_at`),
  KEY `idx_red_packets_delivery` (`app_id`, `delivery_scope`, `context_id`, `created_at`),
  KEY `idx_red_packets_scene_source` (`app_id`, `scene_type`, `source_type`, `source_id`, `status`, `created_at`),
  CONSTRAINT `fk_red_packets_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `red_packet_claims` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `packet_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(18,2) UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_red_packet_claim_user` (`packet_id`, `user_id`),
  CONSTRAINT `fk_red_packet_claim_packet` FOREIGN KEY (`packet_id`, `app_id`, `admin_id`) REFERENCES `red_packets` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_red_packet_claim_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `red_packet_recipients` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `packet_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_red_packet_recipient` (`packet_id`, `user_id`),
  KEY `idx_red_packet_recipient_user` (`app_id`, `user_id`, `packet_id`),
  CONSTRAINT `fk_red_packet_recipient_packet` FOREIGN KEY (`packet_id`, `app_id`, `admin_id`) REFERENCES `red_packets` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_red_packet_recipient_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `red_packet_returns` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `packet_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(18,2) UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_red_packet_return_user` (`packet_id`, `user_id`),
  KEY `idx_red_packet_return_user` (`app_id`, `user_id`, `packet_id`),
  CONSTRAINT `fk_red_packet_return_packet` FOREIGN KEY (`packet_id`, `app_id`, `admin_id`) REFERENCES `red_packets` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_red_packet_return_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_transfers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `from_user_id` BIGINT UNSIGNED NOT NULL,
  `to_user_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL,
  `message` VARCHAR(255) NOT NULL DEFAULT '',
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `expired_at` DATETIME NOT NULL,
  `accepted_at` DATETIME DEFAULT NULL,
  `refunded_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_transfers_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_user_transfers_sender` (`app_id`, `from_user_id`, `status`, `id`),
  KEY `idx_user_transfers_receiver` (`app_id`, `to_user_id`, `status`, `id`),
  CONSTRAINT `fk_user_transfers_sender` FOREIGN KEY (`from_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_transfers_receiver` FOREIGN KEY (`to_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lottery_prizes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `prize_type` VARCHAR(40) NOT NULL,
  `value_json` LONGTEXT NOT NULL,
  `weight` INT UNSIGNED NOT NULL DEFAULT 1,
  `stock` INT NOT NULL DEFAULT 0,
  `daily_limit` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lottery_prizes_id_app_admin` (`id`, `app_id`, `admin_id`),
  CONSTRAINT `fk_lottery_prizes_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lottery_draws` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `prize_id` BIGINT UNSIGNED NOT NULL,
  `reward_json` LONGTEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lottery_draws_user_time` (`user_id`, `created_at`),
  CONSTRAINT `fk_lottery_draws_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lottery_draws_prize` FOREIGN KEY (`prize_id`, `app_id`, `admin_id`) REFERENCES `lottery_prizes` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `votes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` LONGTEXT,
  `multi_select` TINYINT NOT NULL DEFAULT 0,
  `max_select` INT UNSIGNED NOT NULL DEFAULT 1,
  `status` TINYINT NOT NULL DEFAULT 1,
  `start_at` DATETIME DEFAULT NULL,
  `end_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_votes_id_app_admin` (`id`, `app_id`, `admin_id`),
  CONSTRAINT `fk_votes_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vote_options` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `vote_id` BIGINT UNSIGNED NOT NULL,
  `option_text` VARCHAR(500) NOT NULL,
  `vote_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vote_options_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_vote_options_vote` (`vote_id`, `sort_order`),
  CONSTRAINT `fk_vote_options_vote` FOREIGN KEY (`vote_id`, `app_id`, `admin_id`) REFERENCES `votes` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vote_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `vote_id` BIGINT UNSIGNED NOT NULL,
  `option_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vote_records_option_user` (`vote_id`, `option_id`, `user_id`),
  KEY `idx_vote_records_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_vote_records_vote` FOREIGN KEY (`vote_id`, `app_id`, `admin_id`) REFERENCES `votes` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vote_records_option` FOREIGN KEY (`option_id`, `app_id`, `admin_id`) REFERENCES `vote_options` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vote_records_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `remote_files` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `owner_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `parent_id` BIGINT UNSIGNED DEFAULT NULL,
  `file_type` VARCHAR(20) NOT NULL DEFAULT 'file',
  `name` VARCHAR(255) NOT NULL,
  `content` LONGTEXT,
  `file_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `mime_type` VARCHAR(150) NOT NULL DEFAULT '',
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `visibility` VARCHAR(20) NOT NULL DEFAULT 'public',
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_remote_files_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_remote_files_parent` (`app_id`, `parent_id`, `status`),
  KEY `idx_remote_files_owner` (`owner_user_id`, `created_at`),
  CONSTRAINT `fk_remote_files_owner` FOREIGN KEY (`owner_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_remote_files_parent` FOREIGN KEY (`parent_id`) REFERENCES `remote_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `remote_file_versions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `file_id` BIGINT UNSIGNED NOT NULL,
  `version_no` INT UNSIGNED NOT NULL,
  `content` LONGTEXT,
  `file_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_remote_file_versions_no` (`file_id`, `version_no`),
  CONSTRAINT `fk_remote_file_versions_file` FOREIGN KEY (`file_id`, `app_id`, `admin_id`) REFERENCES `remote_files` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `uploads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `scene` VARCHAR(40) NOT NULL DEFAULT 'general',
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(1000) NOT NULL,
  `file_url` VARCHAR(1000) NOT NULL,
  `mime_type` VARCHAR(150) NOT NULL DEFAULT '',
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `original_size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `optimized_size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `upload_mode` VARCHAR(20) NOT NULL DEFAULT 'original',
  `optimization_status` VARCHAR(40) NOT NULL DEFAULT 'not_required',
  `original_file_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `optimized_file_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `thumbnail_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `is_animated` TINYINT NOT NULL DEFAULT 0,
  `sha256` CHAR(64) NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_uploads_tenant_scene` (`admin_id`, `app_id`, `scene`, `created_at`),
  KEY `idx_uploads_content_fingerprint` (`admin_id`, `app_id`, `sha256`, `size_bytes`, `status`),
  KEY `idx_uploads_file_path` (`file_path`(191)),
  KEY `idx_uploads_scene_sha256` (`scene`, `sha256`),
  CONSTRAINT `fk_uploads_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `catalog_file_migrations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `upload_id` BIGINT UNSIGNED NOT NULL,
  `old_file_path` VARCHAR(1000) NOT NULL,
  `new_file_path` VARCHAR(1000) NOT NULL,
  `file_sha256` CHAR(64) NOT NULL DEFAULT '',
  `file_size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `cleanup_status` VARCHAR(20) NOT NULL DEFAULT 'cleanup_pending',
  `cleanup_error` VARCHAR(500) NOT NULL DEFAULT '',
  `cleaned_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_catalog_file_migration_upload` (`upload_id`),
  KEY `idx_catalog_file_migration_cleanup` (`admin_id`, `app_id`, `cleanup_status`),
  KEY `idx_catalog_file_migration_old_path` (`old_file_path`(191)),
  KEY `idx_catalog_file_migration_sha256` (`file_sha256`),
  CONSTRAINT `fk_catalog_file_migration_upload` FOREIGN KEY (`upload_id`) REFERENCES `uploads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `catalog_legacy_url_quarantines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `catalog_kind` VARCHAR(20) NOT NULL,
  `catalog_id` BIGINT UNSIGNED NOT NULL,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `legacy_url` MEDIUMTEXT NOT NULL,
  `legacy_url_sha256` CHAR(64) NOT NULL,
  `legacy_size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `legacy_file_sha256` CHAR(64) NOT NULL DEFAULT '',
  `reason_code` VARCHAR(80) NOT NULL,
  `release_version` VARCHAR(40) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_catalog_legacy_quarantine_row` (`catalog_kind`, `catalog_id`, `admin_id`, `app_id`),
  KEY `idx_catalog_legacy_quarantine_app` (`admin_id`, `app_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `upload_file_deletions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `upload_id` BIGINT UNSIGNED DEFAULT NULL,
  `file_path` VARCHAR(1000) NOT NULL,
  `path_sha256` CHAR(64) NOT NULL,
  `cleanup_status` VARCHAR(20) NOT NULL DEFAULT 'cleanup_pending',
  `cleanup_error` VARCHAR(500) NOT NULL DEFAULT '',
  `cleaned_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_upload_file_deletion_path` (`path_sha256`),
  KEY `idx_upload_file_deletion_status` (`cleanup_status`, `updated_at`),
  CONSTRAINT `fk_upload_file_deletion_upload` FOREIGN KEY (`upload_id`) REFERENCES `uploads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `message_forward_bundles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `creator_user_id` BIGINT UNSIGNED NOT NULL,
  `source_type` VARCHAR(20) NOT NULL,
  `source_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `item_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `anonymity_mode` VARCHAR(20) NOT NULL DEFAULT 'none',
  `anonymity_map_json` LONGTEXT,
  `snapshot_json` LONGTEXT NOT NULL,
  `audit_snapshot_json` LONGTEXT,
  `source_context_json` LONGTEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_message_forward_bundles_owner` (`app_id`, `creator_user_id`, `created_at`),
  CONSTRAINT `fk_message_forward_bundles_user` FOREIGN KEY (`creator_user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_forward_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `bundle_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(30) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_message_forward_links_target` (`app_id`, `target_type`, `target_id`),
  KEY `idx_message_forward_links_bundle` (`bundle_id`, `id`),
  CONSTRAINT `fk_message_forward_links_bundle` FOREIGN KEY (`bundle_id`)
    REFERENCES `message_forward_bundles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_search_histories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `scope_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `keyword` VARCHAR(200) NOT NULL,
  `content_filter` VARCHAR(30) NOT NULL DEFAULT 'all',
  `filter_json` LONGTEXT,
  `filter_hash` CHAR(64) NOT NULL DEFAULT '',
  `search_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_searched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_search_histories_filter` (`user_id`, `scope_type`, `target_id`, `filter_hash`),
  KEY `idx_chat_search_histories_recent` (`app_id`, `user_id`, `last_searched_at`),
  CONSTRAINT `fk_chat_search_histories_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cloud_sync_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `data_type` VARCHAR(20) NOT NULL,
  `scope_type` VARCHAR(20) NOT NULL DEFAULT '',
  `target_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `item_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `date_from` DATETIME DEFAULT NULL,
  `date_to` DATETIME DEFAULT NULL,
  `filter_json` LONGTEXT,
  `snapshot_json` LONGTEXT NOT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `charged_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cloud_sync_snapshots_owner` (`app_id`, `user_id`, `data_type`, `created_at`),
  KEY `idx_cloud_sync_snapshots_scope` (`app_id`, `user_id`, `scope_type`, `target_id`, `created_at`),
  CONSTRAINT `fk_cloud_sync_snapshots_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS `feedbacks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `type` VARCHAR(40) NOT NULL DEFAULT 'feedback',
  `title` VARCHAR(200) NOT NULL,
  `content` LONGTEXT NOT NULL,
  `images_json` LONGTEXT,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `reply_content` LONGTEXT,
  `replied_by` BIGINT UNSIGNED DEFAULT NULL,
  `replied_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_feedbacks_tenant_status` (`admin_id`, `app_id`, `status`, `created_at`),
  KEY `idx_feedbacks_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_feedbacks_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_feedbacks_admin` FOREIGN KEY (`replied_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bot_qa` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `question` VARCHAR(500) NOT NULL,
  `answer` LONGTEXT NOT NULL,
  `keywords` VARCHAR(1000) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bot_qa_tenant_status` (`admin_id`, `app_id`, `status`),
  CONSTRAINT `fk_bot_qa_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `governance_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `issuer_platform_id` BIGINT UNSIGNED NOT NULL,
  `issuer_level` TINYINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED DEFAULT NULL,
  `target_level` TINYINT UNSIGNED DEFAULT NULL,
  `feature_code` VARCHAR(100) NOT NULL,
  `effect` VARCHAR(20) NOT NULL,
  `value_json` LONGTEXT,
  `forced` TINYINT NOT NULL DEFAULT 1,
  `priority` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `starts_at` DATETIME DEFAULT NULL,
  `ends_at` DATETIME DEFAULT NULL,
  `remark` VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_governance_issuer` (`issuer_platform_id`, `status`, `feature_code`),
  KEY `idx_governance_target` (`target_type`, `target_id`, `target_level`, `feature_code`, `status`),
  CONSTRAINT `fk_governance_issuer` FOREIGN KEY (`issuer_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `level_forum_posts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `root_platform_id` BIGINT UNSIGNED NOT NULL,
  `scope_platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `target_level` TINYINT UNSIGNED NOT NULL,
  `author_type` VARCHAR(20) NOT NULL,
  `author_id` BIGINT UNSIGNED NOT NULL,
  `author_name` VARCHAR(100) NOT NULL,
  `category_code` VARCHAR(30) NOT NULL DEFAULT 'general',
  `title` VARCHAR(200) NOT NULL,
  `content` LONGTEXT NOT NULL,
  `attachments_json` LONGTEXT,
  `is_top` TINYINT NOT NULL DEFAULT 0,
  `like_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `favorite_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `comment_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_level_forum_feed` (`root_platform_id`, `target_level`, `scope_platform_id`, `app_id`, `status`, `is_top`, `id`),
  KEY `idx_level_forum_author` (`author_type`, `author_id`, `status`),
  CONSTRAINT `fk_level_forum_root` FOREIGN KEY (`root_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_level_forum_scope` FOREIGN KEY (`scope_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_level_forum_app` FOREIGN KEY (`app_id`) REFERENCES `apps` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `level_forum_reports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `reporter_type` VARCHAR(20) NOT NULL,
  `reporter_id` BIGINT UNSIGNED NOT NULL,
  `reason` VARCHAR(500) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `handled_by_type` VARCHAR(20) DEFAULT NULL,
  `handled_by_id` BIGINT UNSIGNED DEFAULT NULL,
  `handle_remark` VARCHAR(500) NOT NULL DEFAULT '',
  `handled_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_level_forum_reports_post` (`post_id`, `status`, `id`),
  KEY `idx_level_forum_reports_actor` (`reporter_type`, `reporter_id`, `status`),
  CONSTRAINT `fk_level_forum_reports_post` FOREIGN KEY (`post_id`) REFERENCES `level_forum_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `level_forum_comments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `parent_id` BIGINT UNSIGNED DEFAULT NULL,
  `author_type` VARCHAR(20) NOT NULL,
  `author_id` BIGINT UNSIGNED NOT NULL,
  `author_name` VARCHAR(100) NOT NULL,
  `content` TEXT NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_level_forum_comments_post` (`post_id`, `status`, `id`),
  CONSTRAINT `fk_level_forum_comments_post` FOREIGN KEY (`post_id`) REFERENCES `level_forum_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_level_forum_comments_parent` FOREIGN KEY (`parent_id`) REFERENCES `level_forum_comments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `level_forum_reactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `actor_type` VARCHAR(20) NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `reaction_type` VARCHAR(20) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_level_forum_reaction` (`post_id`, `actor_type`, `actor_id`, `reaction_type`),
  KEY `idx_level_forum_reaction_actor` (`actor_type`, `actor_id`, `reaction_type`),
  CONSTRAINT `fk_level_forum_reactions_post` FOREIGN KEY (`post_id`) REFERENCES `level_forum_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bounty_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(1000) NOT NULL DEFAULT '',
  `icon` VARCHAR(500) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bounty_categories_name` (`app_id`, `name`),
  UNIQUE KEY `uk_bounty_categories_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_bounty_categories_list` (`admin_id`, `app_id`, `status`, `sort_order`, `id`),
  CONSTRAINT `fk_bounty_categories_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bounty_category_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(1000) NOT NULL DEFAULT '',
  `reason` VARCHAR(1000) NOT NULL DEFAULT '',
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `reviewer_admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `review_comment` VARCHAR(1000) NOT NULL DEFAULT '',
  `created_category_id` BIGINT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bounty_category_requests_status` (`admin_id`, `app_id`, `status`, `id`),
  KEY `idx_bounty_category_requests_user` (`user_id`, `status`, `id`),
  CONSTRAINT `fk_bounty_category_requests_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bounty_category_requests_reviewer` FOREIGN KEY (`reviewer_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bounty_category_requests_created` FOREIGN KEY (`created_category_id`) REFERENCES `bounty_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bounties` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED DEFAULT NULL,
  `creator_user_id` BIGINT UNSIGNED NOT NULL,
  `winner_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `winner_submission_id` BIGINT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` LONGTEXT NOT NULL,
  `requirements_json` LONGTEXT,
  `attachments_json` LONGTEXT,
  `reward_integral` BIGINT UNSIGNED NOT NULL,
  `submission_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `like_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `favorite_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'open',
  `audit_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `audit_reason` VARCHAR(500) NOT NULL DEFAULT '',
  `audited_by` BIGINT UNSIGNED DEFAULT NULL,
  `audited_at` DATETIME DEFAULT NULL,
  `deadline_at` DATETIME DEFAULT NULL,
  `awarded_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bounties_id_app_admin` (`id`, `app_id`, `admin_id`),
  KEY `idx_bounties_feed` (`admin_id`, `app_id`, `status`, `id`),
  KEY `idx_bounties_review` (`admin_id`, `app_id`, `audit_status`, `id`),
  KEY `idx_bounties_creator` (`creator_user_id`, `status`),
  KEY `idx_bounties_category` (`admin_id`, `app_id`, `category_id`, `status`, `id`),
  CONSTRAINT `fk_bounties_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bounties_creator` FOREIGN KEY (`creator_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bounties_category` FOREIGN KEY (`category_id`) REFERENCES `bounty_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bounties_winner` FOREIGN KEY (`winner_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bounty_submissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `bounty_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `content` LONGTEXT NOT NULL,
  `attachments_json` LONGTEXT,
  `status` VARCHAR(20) NOT NULL DEFAULT 'submitted',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bounty_submission_user` (`bounty_id`, `user_id`),
  KEY `idx_bounty_submissions_bounty` (`bounty_id`, `status`, `id`),
  CONSTRAINT `fk_bounty_submissions_bounty` FOREIGN KEY (`bounty_id`, `app_id`, `admin_id`) REFERENCES `bounties` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bounty_submissions_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bounty_reactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bounty_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `reaction_type` VARCHAR(20) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bounty_reactions` (`bounty_id`, `user_id`, `reaction_type`),
  KEY `idx_bounty_reactions_user` (`user_id`, `reaction_type`, `id`),
  CONSTRAINT `fk_bounty_reactions_bounty` FOREIGN KEY (`bounty_id`) REFERENCES `bounties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bounty_reactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `resource_reactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `resource_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `reaction_type` VARCHAR(20) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource_reactions` (`resource_id`, `user_id`, `reaction_type`),
  KEY `idx_resource_reactions_user` (`user_id`, `reaction_type`, `id`),
  CONSTRAINT `fk_resource_reactions_resource` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resource_reactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_app_reactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `reaction_type` VARCHAR(20) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_store_app_reactions` (`store_app_id`, `user_id`, `reaction_type`),
  KEY `idx_store_app_reactions_user` (`user_id`, `reaction_type`, `id`),
  CONSTRAINT `fk_store_app_reactions_app` FOREIGN KEY (`store_app_id`) REFERENCES `store_apps` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_store_app_reactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_follows` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `follower_user_id` BIGINT UNSIGNED NOT NULL,
  `followed_user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_follows` (`app_id`, `follower_user_id`, `followed_user_id`),
  KEY `idx_user_follows_followed` (`app_id`, `followed_user_id`, `id`),
  CONSTRAINT `fk_user_follows_follower` FOREIGN KEY (`follower_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_follows_followed` FOREIGN KEY (`followed_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_blacklist` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `blocked_user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_blacklist` (`app_id`, `user_id`, `blocked_user_id`),
  KEY `idx_user_blacklist_blocked` (`app_id`, `blocked_user_id`),
  CONSTRAINT `fk_user_blacklist_owner` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_blacklist_target` FOREIGN KEY (`blocked_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_profile_likes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `liker_user_id` BIGINT UNSIGNED NOT NULL,
  `target_user_id` BIGINT UNSIGNED NOT NULL,
  `like_date` DATE NOT NULL,
  `like_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_profile_likes_daily` (`app_id`, `liker_user_id`, `target_user_id`, `like_date`),
  KEY `idx_user_profile_likes_target` (`app_id`, `target_user_id`, `id`),
  CONSTRAINT `fk_user_profile_likes_liker` FOREIGN KEY (`liker_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_profile_likes_target` FOREIGN KEY (`target_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `gift_catalog` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `gift_code` VARCHAR(40) NOT NULL,
  `gift_name` VARCHAR(80) NOT NULL,
  `icon_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `price` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `status` TINYINT NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_gift_catalog_code` (`app_id`, `gift_code`),
  KEY `idx_gift_catalog_tenant` (`admin_id`, `app_id`, `status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_gift_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `from_user_id` BIGINT UNSIGNED NOT NULL,
  `to_user_id` BIGINT UNSIGNED NOT NULL,
  `gift_id` BIGINT UNSIGNED DEFAULT NULL,
  `gift_code` VARCHAR(40) NOT NULL,
  `gift_name` VARCHAR(80) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `message` VARCHAR(300) NOT NULL DEFAULT '',
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `expired_at` DATETIME DEFAULT NULL,
  `accepted_at` DATETIME DEFAULT NULL,
  `refunded_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_gift_records_wall` (`app_id`, `to_user_id`, `id`),
  KEY `idx_user_gift_records_sender` (`app_id`, `from_user_id`, `id`),
  CONSTRAINT `fk_user_gift_records_sender` FOREIGN KEY (`from_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_gift_records_receiver` FOREIGN KEY (`to_user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `notification_type` VARCHAR(40) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `content` TEXT NOT NULL,
  `data_json` LONGTEXT,
  `is_read` TINYINT NOT NULL DEFAULT 0,
  `read_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_notifications_inbox` (`app_id`, `user_id`, `is_read`, `id`),
  CONSTRAINT `fk_user_notifications_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_withdrawals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL,
  `channel` VARCHAR(40) NOT NULL,
  `account_name` VARCHAR(100) NOT NULL,
  `account_no` VARCHAR(200) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `review_remark` VARCHAR(500) NOT NULL DEFAULT '',
  `reviewed_by_admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_withdrawals_user` (`app_id`, `user_id`, `status`, `id`),
  KEY `idx_user_withdrawals_admin` (`admin_id`, `app_id`, `status`, `id`),
  CONSTRAINT `fk_user_withdrawals_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_withdrawals_reviewer` FOREIGN KEY (`reviewed_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_user_states` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `is_deleted` TINYINT NOT NULL DEFAULT 0,
  `is_favorite` TINYINT NOT NULL DEFAULT 0,
  `read_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_message_user_states` (`message_id`, `user_id`),
  KEY `idx_message_user_states_user` (`user_id`, `is_favorite`, `is_deleted`, `id`),
  CONSTRAINT `fk_message_user_states_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_message_user_states_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_recalls` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `actor_type` VARCHAR(20) NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `reason` VARCHAR(500) NOT NULL DEFAULT '',
  `notice_text` VARCHAR(200) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_message_recalls_message` (`message_id`),
  CONSTRAINT `fk_message_recalls_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversation_preferences` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `is_pinned` TINYINT NOT NULL DEFAULT 0,
  `is_bottomed` TINYINT NOT NULL DEFAULT 0,
  `is_hidden` TINYINT NOT NULL DEFAULT 0,
  `is_muted` TINYINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_conversation_preferences_target` (`user_id`, `target_type`, `target_id`),
  KEY `idx_conversation_preferences_center` (`app_id`, `user_id`, `is_pinned`, `is_hidden`, `updated_at`),
  CONSTRAINT `fk_conversation_preferences_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `communication_message_states` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `scope_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `is_deleted` TINYINT NOT NULL DEFAULT 0,
  `is_favorite` TINYINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_communication_message_states` (`user_id`, `scope_type`, `message_id`),
  KEY `idx_communication_message_states_favorite` (`app_id`, `user_id`, `is_favorite`, `is_deleted`, `updated_at`),
  CONSTRAINT `fk_communication_message_states_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `composer_drafts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(30) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `content` LONGTEXT NOT NULL,
  `attachments_json` LONGTEXT,
  `tags_json` LONGTEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_composer_drafts_target` (`user_id`, `target_type`, `target_id`),
  KEY `idx_composer_drafts_owner` (`app_id`, `user_id`, `updated_at`),
  CONSTRAINT `fk_composer_drafts_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_avatar_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `avatar_url` VARCHAR(1000) NOT NULL,
  `sha256` CHAR(64) NOT NULL DEFAULT '',
  `is_current` TINYINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_avatar_history_url` (`user_id`, `avatar_url`(128)),
  KEY `idx_user_avatar_history_current` (`app_id`, `user_id`, `is_current`, `id`),
  CONSTRAINT `fk_user_avatar_history_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `content_favorites` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `content_type` VARCHAR(30) NOT NULL,
  `content_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_content_favorites_target` (`user_id`, `content_type`, `content_id`),
  KEY `idx_content_favorites_owner` (`app_id`, `user_id`, `content_type`, `id`),
  CONSTRAINT `fk_content_favorites_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_files` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `uploader_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `parent_id` BIGINT UNSIGNED DEFAULT NULL,
  `is_folder` TINYINT NOT NULL DEFAULT 0,
  `name` VARCHAR(255) NOT NULL,
  `file_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `mime_type` VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `download_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chat_room_files_room` (`room_id`, `status`, `id`),
  KEY `idx_chat_room_files_parent` (`room_id`, `parent_id`, `status`, `is_folder`, `id`),
  CONSTRAINT `fk_chat_room_files_room` FOREIGN KEY (`room_id`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_files_user` FOREIGN KEY (`uploader_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_chat_room_files_parent` FOREIGN KEY (`parent_id`) REFERENCES `chat_room_files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_albums` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `creator_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `name` VARCHAR(120) NOT NULL,
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chat_room_albums_room` (`room_id`, `status`, `id`),
  CONSTRAINT `fk_chat_room_albums_room` FOREIGN KEY (`room_id`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_albums_user` FOREIGN KEY (`creator_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_album_photos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `album_id` BIGINT UNSIGNED NOT NULL,
  `uploader_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `image_url` VARCHAR(1000) NOT NULL,
  `media_type` VARCHAR(20) NOT NULL DEFAULT 'image',
  `mime_type` VARCHAR(120) NOT NULL DEFAULT 'image/jpeg',
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `thumbnail_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `download_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `caption` VARCHAR(500) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chat_room_album_photos_album` (`album_id`, `status`, `id`),
  CONSTRAINT `fk_chat_room_album_photos_album` FOREIGN KEY (`album_id`) REFERENCES `chat_room_albums` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_album_photos_user` FOREIGN KEY (`uploader_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_votes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `creator_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT,
  `multiple_choice` TINYINT NOT NULL DEFAULT 0,
  `min_select` INT UNSIGNED NOT NULL DEFAULT 1,
  `max_select` INT UNSIGNED NOT NULL DEFAULT 1,
  `allow_change` TINYINT NOT NULL DEFAULT 0,
  `anonymous` TINYINT NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `ends_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chat_room_votes_room` (`room_id`, `status`, `id`),
  CONSTRAINT `fk_chat_room_votes_room` FOREIGN KEY (`room_id`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_votes_user` FOREIGN KEY (`creator_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_vote_options` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vote_id` BIGINT UNSIGNED NOT NULL,
  `option_text` VARCHAR(300) NOT NULL,
  `image_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `vote_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_chat_room_vote_options_vote` (`vote_id`, `sort_order`, `id`),
  CONSTRAINT `fk_chat_room_vote_options_vote` FOREIGN KEY (`vote_id`) REFERENCES `chat_room_votes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_vote_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vote_id` BIGINT UNSIGNED NOT NULL,
  `option_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_room_vote_record` (`vote_id`, `option_id`, `user_id`),
  KEY `idx_chat_room_vote_records_user` (`vote_id`, `user_id`),
  CONSTRAINT `fk_chat_room_vote_records_vote` FOREIGN KEY (`vote_id`) REFERENCES `chat_room_votes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_vote_records_option` FOREIGN KEY (`option_id`) REFERENCES `chat_room_vote_options` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_vote_records_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_solitaire` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `creator_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `ends_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chat_room_solitaire_room` (`room_id`, `status`, `id`),
  CONSTRAINT `fk_chat_room_solitaire_room` FOREIGN KEY (`room_id`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_solitaire_user` FOREIGN KEY (`creator_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_room_solitaire_entries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `solitaire_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `content` VARCHAR(1000) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_room_solitaire_entry` (`solitaire_id`, `user_id`),
  KEY `idx_chat_room_solitaire_entries` (`solitaire_id`, `id`),
  CONSTRAINT `fk_chat_room_solitaire_entries_parent` FOREIGN KEY (`solitaire_id`) REFERENCES `chat_room_solitaire` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_room_solitaire_entries_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_view_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `view_count` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `last_viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_view_history` (`post_id`, `user_id`),
  KEY `idx_forum_view_history_user` (`user_id`, `last_viewed_at`),
  CONSTRAINT `fk_forum_view_history_post` FOREIGN KEY (`post_id`) REFERENCES `forum_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_view_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_rewards` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `from_user_id` BIGINT UNSIGNED NOT NULL,
  `to_user_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `integral` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_forum_rewards_target` (`app_id`, `target_type`, `target_id`, `id`),
  KEY `idx_forum_rewards_from` (`from_user_id`, `id`),
  CONSTRAINT `fk_forum_rewards_app` FOREIGN KEY (`app_id`) REFERENCES `apps` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_rewards_from` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_rewards_to` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_paid_contents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `price_integral` BIGINT UNSIGNED NOT NULL,
  `asset_type` VARCHAR(20) NOT NULL DEFAULT 'balance',
  `preview_content` TEXT NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_paid_contents_post` (`post_id`),
  CONSTRAINT `fk_forum_paid_contents_post` FOREIGN KEY (`post_id`) REFERENCES `forum_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_post_purchases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `buyer_user_id` BIGINT UNSIGNED NOT NULL,
  `seller_user_id` BIGINT UNSIGNED NOT NULL,
  `price_integral` BIGINT UNSIGNED NOT NULL,
  `asset_type` VARCHAR(20) NOT NULL DEFAULT 'balance',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_post_purchase` (`post_id`, `buyer_user_id`),
  KEY `idx_forum_post_purchases_buyer` (`buyer_user_id`, `id`),
  CONSTRAINT `fk_forum_post_purchases_post` FOREIGN KEY (`post_id`) REFERENCES `forum_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_post_purchases_buyer` FOREIGN KEY (`buyer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_post_purchases_seller` FOREIGN KEY (`seller_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_unique_views` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `viewer_key` CHAR(64) NOT NULL,
  `user_id` BIGINT UNSIGNED DEFAULT NULL,
  `view_count` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `first_viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_unique_views` (`post_id`, `viewer_key`),
  KEY `idx_forum_unique_views_app` (`app_id`, `post_id`, `last_viewed_at`),
  CONSTRAINT `fk_forum_unique_views_post` FOREIGN KEY (`post_id`) REFERENCES `forum_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_unique_views_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_post_sections` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `author_user_id` BIGINT UNSIGNED NOT NULL,
  `section_type` VARCHAR(20) NOT NULL DEFAULT 'free',
  `title` VARCHAR(160) NOT NULL DEFAULT '',
  `content` LONGTEXT NOT NULL,
  `tags_json` LONGTEXT,
  `price_balance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `asset_type` VARCHAR(20) NOT NULL DEFAULT 'balance',
  `unlock_at` DATETIME DEFAULT NULL,
  `preview_content` VARCHAR(1000) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_forum_post_sections_order` (`post_id`, `sort_order`),
  KEY `idx_forum_post_sections_app` (`app_id`, `post_id`, `status`, `sort_order`),
  CONSTRAINT `fk_forum_post_sections_post` FOREIGN KEY (`post_id`) REFERENCES `forum_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_post_sections_author` FOREIGN KEY (`author_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_section_purchases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `section_id` BIGINT UNSIGNED NOT NULL,
  `buyer_user_id` BIGINT UNSIGNED NOT NULL,
  `seller_user_id` BIGINT UNSIGNED NOT NULL,
  `price_balance` DECIMAL(18,2) NOT NULL,
  `asset_type` VARCHAR(20) NOT NULL DEFAULT 'balance',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_section_purchase` (`section_id`, `buyer_user_id`),
  KEY `idx_forum_section_purchases_buyer` (`app_id`, `buyer_user_id`, `id`),
  CONSTRAINT `fk_forum_section_purchases_section` FOREIGN KEY (`section_id`) REFERENCES `forum_post_sections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_section_purchases_buyer` FOREIGN KEY (`buyer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_forum_section_purchases_seller` FOREIGN KEY (`seller_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_content_favorites` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_content_favorites` (`app_id`, `user_id`, `target_type`, `target_id`),
  KEY `idx_forum_content_favorites_target` (`app_id`, `target_type`, `target_id`),
  CONSTRAINT `fk_forum_content_favorites_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_personal_positions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `position` VARCHAR(20) NOT NULL DEFAULT 'top',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_personal_positions` (`user_id`, `target_type`, `target_id`),
  KEY `idx_forum_personal_positions_order` (`app_id`, `user_id`, `target_type`, `position`, `sort_order`),
  CONSTRAINT `fk_forum_personal_positions_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_content_forwards` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `destination_type` VARCHAR(20) NOT NULL,
  `destination_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_forum_content_forwards_target` (`app_id`, `target_type`, `target_id`, `id`),
  KEY `idx_forum_content_forwards_user` (`user_id`, `id`),
  CONSTRAINT `fk_forum_content_forwards_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `software_update_policies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `issuer_type` VARCHAR(20) NOT NULL,
  `issuer_id` BIGINT UNSIGNED NOT NULL,
  `issuer_level` TINYINT UNSIGNED NOT NULL,
  `edition_code` VARCHAR(40) NOT NULL,
  `target_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED DEFAULT NULL,
  `target_level` TINYINT UNSIGNED DEFAULT NULL,
  `version_name` VARCHAR(40) NOT NULL,
  `version_code` INT UNSIGNED NOT NULL,
  `min_supported_version_code` INT UNSIGNED NOT NULL DEFAULT 0,
  `download_url` VARCHAR(1000) NOT NULL,
  `package_name` VARCHAR(190) NOT NULL DEFAULT '',
  `sha256` CHAR(64) NOT NULL DEFAULT '',
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `release_notes` LONGTEXT NOT NULL,
  `force_update` TINYINT NOT NULL DEFAULT 0,
  `priority` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `starts_at` DATETIME DEFAULT NULL,
  `ends_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_software_update_match` (`edition_code`, `target_type`, `target_id`, `target_level`, `status`, `version_code`),
  KEY `idx_software_update_issuer` (`issuer_type`, `issuer_id`, `status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `maintenance_policies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `issuer_type` VARCHAR(20) NOT NULL,
  `issuer_id` BIGINT UNSIGNED NOT NULL,
  `issuer_level` TINYINT UNSIGNED NOT NULL,
  `edition_code` VARCHAR(40) NOT NULL,
  `target_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED DEFAULT NULL,
  `target_level` TINYINT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` LONGTEXT NOT NULL,
  `forced` TINYINT NOT NULL DEFAULT 1,
  `allowlist_json` LONGTEXT,
  `priority` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `starts_at` DATETIME DEFAULT NULL,
  `ends_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_maintenance_match` (`edition_code`, `target_type`, `target_id`, `target_level`, `status`, `priority`),
  KEY `idx_maintenance_issuer` (`issuer_type`, `issuer_id`, `status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `festival_theme_policies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `issuer_type` VARCHAR(20) NOT NULL,
  `issuer_id` BIGINT UNSIGNED NOT NULL,
  `issuer_level` TINYINT UNSIGNED NOT NULL,
  `edition_code` VARCHAR(40) NOT NULL,
  `target_type` VARCHAR(20) NOT NULL,
  `target_id` BIGINT UNSIGNED DEFAULT NULL,
  `target_level` TINYINT UNSIGNED DEFAULT NULL,
  `theme_code` VARCHAR(80) NOT NULL,
  `title` VARCHAR(160) NOT NULL,
  `greeting` VARCHAR(500) NOT NULL DEFAULT '',
  `background_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `accent_color` VARCHAR(20) NOT NULL DEFAULT '#1677FF',
  `action_text` VARCHAR(80) NOT NULL DEFAULT '',
  `action_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `config_json` LONGTEXT,
  `priority` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_festival_theme_match` (`edition_code`, `target_type`, `target_id`, `target_level`, `status`, `starts_at`, `ends_at`),
  KEY `idx_festival_theme_issuer` (`issuer_type`, `issuer_id`, `status`, `id`),
  UNIQUE KEY `uk_festival_theme_issuer_code_start` (`issuer_type`, `issuer_id`, `edition_code`, `theme_code`, `starts_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_knowledge_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `root_platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `scope_type` ENUM('global','platform','admin','app') NOT NULL DEFAULT 'app',
  `platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `title` VARCHAR(200) NOT NULL,
  `content` LONGTEXT NOT NULL,
  `keywords` VARCHAR(1000) NOT NULL DEFAULT '',
  `source_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `priority` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_by_type` VARCHAR(20) NOT NULL DEFAULT 'platform',
  `created_by_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_knowledge_scope` (`scope_type`, `root_platform_id`, `platform_id`, `admin_id`, `app_id`, `status`, `priority`),
  KEY `idx_ai_knowledge_creator` (`created_by_type`, `created_by_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_conversations_user` (`app_id`, `user_id`, `updated_at`, `id`),
  CONSTRAINT `fk_ai_conversations_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `role` ENUM('user','assistant','system','tool') NOT NULL,
  `content` LONGTEXT NOT NULL,
  `provider` VARCHAR(40) NOT NULL DEFAULT '',
  `model` VARCHAR(120) NOT NULL DEFAULT '',
  `metadata_json` LONGTEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ai_messages_conversation` (`conversation_id`, `id`),
  KEY `idx_ai_messages_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_ai_messages_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `root_platform_id` BIGINT UNSIGNED NOT NULL,
  `scope_platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `owner_type` VARCHAR(20) NOT NULL,
  `owner_id` BIGINT UNSIGNED NOT NULL,
  `target_level` TINYINT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `icon` VARCHAR(500) NOT NULL DEFAULT '',
  `color` VARCHAR(20) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_poll_categories_scope` (`root_platform_id`, `target_level`, `scope_platform_id`, `app_id`, `status`, `sort_order`),
  KEY `idx_poll_categories_owner` (`owner_type`, `owner_id`, `status`),
  CONSTRAINT `fk_poll_categories_root` FOREIGN KEY (`root_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_poll_categories_scope` FOREIGN KEY (`scope_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_poll_categories_app` FOREIGN KEY (`app_id`) REFERENCES `apps` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `universal_polls` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `root_platform_id` BIGINT UNSIGNED NOT NULL,
  `scope_platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `creator_type` VARCHAR(20) NOT NULL,
  `creator_id` BIGINT UNSIGNED NOT NULL,
  `creator_name` VARCHAR(100) NOT NULL,
  `target_level` TINYINT UNSIGNED NOT NULL,
  `scene_type` VARCHAR(30) NOT NULL DEFAULT 'activity' COMMENT 'chat/forum/bounty/activity',
  `source_type` VARCHAR(40) NOT NULL DEFAULT '',
  `source_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `title` VARCHAR(200) NOT NULL,
  `description` LONGTEXT,
  `multiple_choice` TINYINT NOT NULL DEFAULT 0,
  `min_select` INT UNSIGNED NOT NULL DEFAULT 1,
  `max_select` INT UNSIGNED NOT NULL DEFAULT 1,
  `anonymous` TINYINT NOT NULL DEFAULT 0,
  `allow_change` TINYINT NOT NULL DEFAULT 0,
  `result_visibility` VARCHAR(20) NOT NULL DEFAULT 'after_vote',
  `ballot_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `starts_at` DATETIME DEFAULT NULL,
  `ends_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_universal_polls_feed` (`root_platform_id`, `target_level`, `scope_platform_id`, `app_id`, `status`, `id`),
  KEY `idx_universal_polls_creator` (`creator_type`, `creator_id`, `status`, `id`),
  KEY `idx_universal_polls_scene` (`scene_type`, `source_type`, `source_id`, `status`, `id`),
  CONSTRAINT `fk_universal_polls_root` FOREIGN KEY (`root_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_universal_polls_scope` FOREIGN KEY (`scope_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_universal_polls_app` FOREIGN KEY (`app_id`) REFERENCES `apps` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `universal_poll_category_links` (
  `poll_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`poll_id`, `category_id`),
  KEY `idx_universal_poll_category` (`category_id`, `poll_id`),
  CONSTRAINT `fk_universal_poll_links_poll` FOREIGN KEY (`poll_id`) REFERENCES `universal_polls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_universal_poll_links_category` FOREIGN KEY (`category_id`) REFERENCES `poll_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `universal_poll_options` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `poll_id` BIGINT UNSIGNED NOT NULL,
  `option_text` VARCHAR(500) NOT NULL,
  `image_url` VARCHAR(1000) NOT NULL DEFAULT '',
  `vote_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_universal_poll_options` (`poll_id`, `sort_order`, `id`),
  CONSTRAINT `fk_universal_poll_options_poll` FOREIGN KEY (`poll_id`) REFERENCES `universal_polls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `universal_poll_ballots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `poll_id` BIGINT UNSIGNED NOT NULL,
  `actor_type` VARCHAR(20) NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_universal_poll_ballot` (`poll_id`, `actor_type`, `actor_id`),
  KEY `idx_universal_poll_ballot_actor` (`actor_type`, `actor_id`, `id`),
  CONSTRAINT `fk_universal_poll_ballots_poll` FOREIGN KEY (`poll_id`) REFERENCES `universal_polls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `universal_poll_choices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ballot_id` BIGINT UNSIGNED NOT NULL,
  `option_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_universal_poll_choice` (`ballot_id`, `option_id`),
  KEY `idx_universal_poll_choice_option` (`option_id`, `id`),
  CONSTRAINT `fk_universal_poll_choices_ballot` FOREIGN KEY (`ballot_id`) REFERENCES `universal_poll_ballots` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_universal_poll_choices_option` FOREIGN KEY (`option_id`) REFERENCES `universal_poll_options` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hierarchy_activities` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `root_platform_id` BIGINT UNSIGNED NOT NULL,
  `owner_type` ENUM('platform','admin') NOT NULL,
  `owner_id` BIGINT UNSIGNED NOT NULL,
  `owner_level` TINYINT UNSIGNED NOT NULL,
  `owner_platform_id` BIGINT UNSIGNED NOT NULL,
  `owner_admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `activity_type` ENUM('red_packet','lottery','bounty') NOT NULL,
  `funding_mode` ENUM('balance','issued') NOT NULL DEFAULT 'balance',
  `title` VARCHAR(200) NOT NULL,
  `description` LONGTEXT,
  `packet_mode` ENUM('equal','random') DEFAULT NULL,
  `total_balance` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `remaining_balance` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `total_slots` INT UNSIGNED NOT NULL DEFAULT 1,
  `remaining_slots` INT UNSIGNED NOT NULL DEFAULT 1,
  `per_actor_limit` INT UNSIGNED NOT NULL DEFAULT 1,
  `rules_json` LONGTEXT,
  `status` ENUM('draft','active','completed','closed','cancelled') NOT NULL DEFAULT 'active',
  `starts_at` DATETIME DEFAULT NULL,
  `ends_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hierarchy_activities_feed` (`root_platform_id`, `status`, `activity_type`, `starts_at`, `ends_at`, `id`),
  KEY `idx_hierarchy_activities_owner` (`owner_type`, `owner_id`, `status`, `id`),
  KEY `idx_hierarchy_activities_branch` (`owner_platform_id`, `owner_admin_id`, `owner_level`, `status`),
  CONSTRAINT `fk_hierarchy_activities_root` FOREIGN KEY (`root_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hierarchy_activities_platform` FOREIGN KEY (`owner_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hierarchy_activities_admin` FOREIGN KEY (`owner_admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hierarchy_activity_targets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id` BIGINT UNSIGNED NOT NULL,
  `target_scope` ENUM('both','visibility','participation') NOT NULL DEFAULT 'both',
  `target_type` ENUM('level','platform','admin','app','actor') NOT NULL,
  `target_level` TINYINT UNSIGNED DEFAULT NULL,
  `target_id` BIGINT UNSIGNED DEFAULT NULL,
  `actor_type` VARCHAR(20) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hierarchy_activity_targets_match` (`activity_id`, `target_scope`, `target_type`, `target_level`, `target_id`, `actor_type`),
  CONSTRAINT `fk_hierarchy_activity_targets_activity` FOREIGN KEY (`activity_id`) REFERENCES `hierarchy_activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hierarchy_activity_prizes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `reward_balance` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `weight` INT UNSIGNED NOT NULL DEFAULT 1,
  `stock` INT UNSIGNED NOT NULL DEFAULT 1,
  `remaining_stock` INT UNSIGNED NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hierarchy_activity_prizes_draw` (`activity_id`, `remaining_stock`, `sort_order`, `id`),
  CONSTRAINT `fk_hierarchy_activity_prizes_activity` FOREIGN KEY (`activity_id`) REFERENCES `hierarchy_activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hierarchy_activity_entries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id` BIGINT UNSIGNED NOT NULL,
  `actor_type` ENUM('platform','admin','user') NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `actor_level` TINYINT UNSIGNED NOT NULL,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `entry_type` ENUM('claim','draw','award') NOT NULL,
  `prize_id` BIGINT UNSIGNED DEFAULT NULL,
  `reward_balance` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hierarchy_activity_entries_actor` (`activity_id`, `actor_type`, `actor_id`, `entry_type`, `id`),
  KEY `idx_hierarchy_activity_entries_time` (`platform_id`, `admin_id`, `app_id`, `created_at`),
  CONSTRAINT `fk_hierarchy_activity_entries_activity` FOREIGN KEY (`activity_id`) REFERENCES `hierarchy_activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hierarchy_activity_entries_prize` FOREIGN KEY (`prize_id`) REFERENCES `hierarchy_activity_prizes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hierarchy_activity_submissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id` BIGINT UNSIGNED NOT NULL,
  `actor_type` ENUM('platform','admin','user') NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `actor_level` TINYINT UNSIGNED NOT NULL,
  `platform_id` BIGINT UNSIGNED NOT NULL,
  `admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `content` LONGTEXT NOT NULL,
  `attachments_json` LONGTEXT,
  `status` ENUM('submitted','accepted','rejected','cancelled') NOT NULL DEFAULT 'submitted',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_hierarchy_activity_submission_actor` (`activity_id`, `actor_type`, `actor_id`),
  KEY `idx_hierarchy_activity_submissions_status` (`activity_id`, `status`, `id`),
  CONSTRAINT `fk_hierarchy_activity_submissions_activity` FOREIGN KEY (`activity_id`) REFERENCES `hierarchy_activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hierarchy_balance_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `root_platform_id` BIGINT UNSIGNED NOT NULL,
  `actor_type` ENUM('platform','admin','user') NOT NULL,
  `actor_id` BIGINT UNSIGNED NOT NULL,
  `actor_level` TINYINT UNSIGNED NOT NULL,
  `change_value` DECIMAL(20,2) NOT NULL,
  `before_value` DECIMAL(20,2) NOT NULL,
  `after_value` DECIMAL(20,2) NOT NULL,
  `scene` VARCHAR(60) NOT NULL,
  `ref_type` VARCHAR(40) NOT NULL DEFAULT '',
  `ref_id` BIGINT UNSIGNED DEFAULT NULL,
  `operator_type` VARCHAR(20) NOT NULL DEFAULT '',
  `operator_id` BIGINT UNSIGNED DEFAULT NULL,
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hierarchy_balance_logs_actor` (`actor_type`, `actor_id`, `created_at`),
  KEY `idx_hierarchy_balance_logs_root` (`root_platform_id`, `scene`, `created_at`),
  CONSTRAINT `fk_hierarchy_balance_logs_root` FOREIGN KEY (`root_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_visit_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `visitor_hash` CHAR(64) NOT NULL,
  `visit_date` DATE NOT NULL,
  `visit_count` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `source` VARCHAR(50) NOT NULL DEFAULT 'app',
  `last_path` VARCHAR(255) NOT NULL DEFAULT '',
  `last_ip_hash` CHAR(64) NOT NULL DEFAULT '',
  `last_user_agent` VARCHAR(500) NOT NULL DEFAULT '',
  `first_visited_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_visited_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_visit_daily_visitor` (`app_id`, `visit_date`, `visitor_hash`),
  KEY `idx_app_visit_tenant_time` (`admin_id`, `app_id`, `last_visited_at`),
  CONSTRAINT `fk_app_visit_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_presence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'online',
  `device` VARCHAR(100) NOT NULL DEFAULT '',
  `last_ip_hash` CHAR(64) NOT NULL DEFAULT '',
  `last_heartbeat_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `online_until` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_presence_user` (`user_id`),
  KEY `idx_user_presence_online` (`app_id`, `online_until`, `status`),
  CONSTRAINT `fk_user_presence_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `card_login_bindings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `card_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `device_hash` CHAR(64) NOT NULL,
  `device_secret_hash` CHAR(64) NOT NULL,
  `device_label` VARCHAR(100) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1,
  `bound_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login_at` DATETIME DEFAULT NULL,
  `expired_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_card_login_binding_card` (`card_id`),
  UNIQUE KEY `uk_card_login_binding_device` (`app_id`, `device_hash`),
  KEY `idx_card_login_binding_user` (`app_id`, `user_id`, `status`),
  CONSTRAINT `fk_card_login_binding_card` FOREIGN KEY (`card_id`, `app_id`, `admin_id`) REFERENCES `cards` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_card_login_binding_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_report_tags` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_forum_report_tag_name` (`app_id`, `name`),
  KEY `idx_forum_report_tag_tenant` (`admin_id`, `app_id`, `status`, `sort_order`),
  CONSTRAINT `fk_forum_report_tag_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- 安全引导变量只读取调用方在当前数据库会话中显式注入的值。
-- 密码哈希限定为 PHP password_hash() 支持的 bcrypt/Argon2 格式，防止误把明文当作哈希入库。
SET @yy_bootstrap_root_ready = IF(
  CHAR_LENGTH(TRIM(COALESCE(@YY_BOOTSTRAP_ROOT_PLATFORM_KEY, ''))) BETWEEN 1 AND 80
  AND CHAR_LENGTH(TRIM(COALESCE(@YY_BOOTSTRAP_ROOT_ACCOUNT, ''))) BETWEEN 1 AND 64
  AND CHAR_LENGTH(COALESCE(@YY_BOOTSTRAP_ROOT_PASSWORD_HASH, '')) BETWEEN 50 AND 255
  AND (
    @YY_BOOTSTRAP_ROOT_PASSWORD_HASH LIKE '$2y$%'
    OR @YY_BOOTSTRAP_ROOT_PASSWORD_HASH LIKE '$2a$%'
    OR @YY_BOOTSTRAP_ROOT_PASSWORD_HASH LIKE '$argon2i$%'
    OR @YY_BOOTSTRAP_ROOT_PASSWORD_HASH LIKE '$argon2id$%'
  ), 1, 0
);
SET @yy_bootstrap_authorized_ready = IF(
  @yy_bootstrap_root_ready = 1
  AND CHAR_LENGTH(TRIM(COALESCE(@YY_BOOTSTRAP_AUTHORIZED_PLATFORM_KEY, ''))) BETWEEN 1 AND 80
  AND CHAR_LENGTH(TRIM(COALESCE(@YY_BOOTSTRAP_AUTHORIZED_ACCOUNT, ''))) BETWEEN 1 AND 64
  AND CHAR_LENGTH(COALESCE(@YY_BOOTSTRAP_AUTHORIZED_PASSWORD_HASH, '')) BETWEEN 50 AND 255
  AND (
    @YY_BOOTSTRAP_AUTHORIZED_PASSWORD_HASH LIKE '$2y$%'
    OR @YY_BOOTSTRAP_AUTHORIZED_PASSWORD_HASH LIKE '$2a$%'
    OR @YY_BOOTSTRAP_AUTHORIZED_PASSWORD_HASH LIKE '$argon2i$%'
    OR @YY_BOOTSTRAP_AUTHORIZED_PASSWORD_HASH LIKE '$argon2id$%'
  ), 1, 0
);
SET @yy_bootstrap_admin_ready = IF(
  @yy_bootstrap_root_ready = 1
  AND CHAR_LENGTH(TRIM(COALESCE(@YY_BOOTSTRAP_ADMIN_ACCOUNT, ''))) BETWEEN 1 AND 64
  AND CHAR_LENGTH(COALESCE(@YY_BOOTSTRAP_ADMIN_PASSWORD_HASH, '')) BETWEEN 50 AND 255
  AND (
    @YY_BOOTSTRAP_ADMIN_PASSWORD_HASH LIKE '$2y$%'
    OR @YY_BOOTSTRAP_ADMIN_PASSWORD_HASH LIKE '$2a$%'
    OR @YY_BOOTSTRAP_ADMIN_PASSWORD_HASH LIKE '$argon2i$%'
    OR @YY_BOOTSTRAP_ADMIN_PASSWORD_HASH LIKE '$argon2id$%'
  ), 1, 0
);
SET @yy_bootstrap_app_ready = IF(
  @yy_bootstrap_admin_ready = 1
  AND CHAR_LENGTH(TRIM(COALESCE(@YY_BOOTSTRAP_APP_KEY, ''))) BETWEEN 1 AND 80
  AND COALESCE(@YY_BOOTSTRAP_APP_SECRET_HASH, '') REGEXP '^[0-9A-Fa-f]{64}$', 1, 0
);
SET @yy_bootstrap_user_ready = IF(
  @yy_bootstrap_app_ready = 1
  AND CHAR_LENGTH(TRIM(COALESCE(@YY_BOOTSTRAP_USER_UID, ''))) BETWEEN 1 AND 32
  AND CHAR_LENGTH(TRIM(COALESCE(@YY_BOOTSTRAP_USER_ACCOUNT, ''))) BETWEEN 1 AND 64
  AND CHAR_LENGTH(COALESCE(@YY_BOOTSTRAP_USER_PASSWORD_HASH, '')) BETWEEN 50 AND 255
  AND (
    @YY_BOOTSTRAP_USER_PASSWORD_HASH LIKE '$2y$%'
    OR @YY_BOOTSTRAP_USER_PASSWORD_HASH LIKE '$2a$%'
    OR @YY_BOOTSTRAP_USER_PASSWORD_HASH LIKE '$argon2i$%'
    OR @YY_BOOTSTRAP_USER_PASSWORD_HASH LIKE '$argon2id$%'
  ), 1, 0
);

SET @yy_disabled_password_hash = CONCAT('disabled$', SHA2(CONCAT(UUID(), UUID(), RAND(), NOW(6)), 256));
SET @yy_disabled_app_secret_hash = SHA2(CONCAT(UUID(), UUID(), RAND(), NOW(6)), 256);
-- 调用方可能在 SOURCE 前、使用客户端默认 utf8mb4_general_ci 注入变量。
-- 统一转换为表使用的排序规则，避免 MariaDB/MySQL 在后续等值查询时出现 1267 混用错误。
SET @yy_root_platform_key = CONVERT(IF(@yy_bootstrap_root_ready = 1, TRIM(@YY_BOOTSTRAP_ROOT_PLATFORM_KEY), 'bootstrap-disabled-owner') USING utf8mb4) COLLATE utf8mb4_unicode_ci;
SET @yy_root_account = CONVERT(IF(@yy_bootstrap_root_ready = 1, TRIM(@YY_BOOTSTRAP_ROOT_ACCOUNT), 'bootstrap-disabled-owner') USING utf8mb4) COLLATE utf8mb4_unicode_ci;
SET @yy_root_password_hash = CONVERT(IF(@yy_bootstrap_root_ready = 1, @YY_BOOTSTRAP_ROOT_PASSWORD_HASH, @yy_disabled_password_hash) USING utf8mb4) COLLATE utf8mb4_unicode_ci;
SET @yy_authorized_platform_key = CONVERT(IF(@yy_bootstrap_authorized_ready = 1, TRIM(@YY_BOOTSTRAP_AUTHORIZED_PLATFORM_KEY), 'bootstrap-disabled-authorized') USING utf8mb4) COLLATE utf8mb4_unicode_ci;
SET @yy_authorized_account = CONVERT(IF(@yy_bootstrap_authorized_ready = 1, TRIM(@YY_BOOTSTRAP_AUTHORIZED_ACCOUNT), 'bootstrap-disabled-authorized') USING utf8mb4) COLLATE utf8mb4_unicode_ci;
SET @yy_authorized_password_hash = CONVERT(IF(@yy_bootstrap_authorized_ready = 1, @YY_BOOTSTRAP_AUTHORIZED_PASSWORD_HASH, @yy_disabled_password_hash) USING utf8mb4) COLLATE utf8mb4_unicode_ci;
SET @yy_admin_account = CONVERT(IF(@yy_bootstrap_admin_ready = 1, TRIM(@YY_BOOTSTRAP_ADMIN_ACCOUNT), 'bootstrap-disabled-admin') USING utf8mb4) COLLATE utf8mb4_unicode_ci;
SET @yy_admin_password_hash = CONVERT(IF(@yy_bootstrap_admin_ready = 1, @YY_BOOTSTRAP_ADMIN_PASSWORD_HASH, @yy_disabled_password_hash) USING utf8mb4) COLLATE utf8mb4_unicode_ci;
SET @yy_app_key = CONVERT(IF(@yy_bootstrap_app_ready = 1, TRIM(@YY_BOOTSTRAP_APP_KEY), 'bootstrap-disabled-app') USING utf8mb4) COLLATE utf8mb4_unicode_ci;
SET @yy_app_secret_hash = CONVERT(IF(@yy_bootstrap_app_ready = 1, LOWER(@YY_BOOTSTRAP_APP_SECRET_HASH), @yy_disabled_app_secret_hash) USING utf8mb4) COLLATE utf8mb4_unicode_ci;
SET @yy_user_uid = CONVERT(IF(@yy_bootstrap_user_ready = 1, TRIM(@YY_BOOTSTRAP_USER_UID), 'bootstrap-disabled-user') USING utf8mb4) COLLATE utf8mb4_unicode_ci;
SET @yy_user_account = CONVERT(IF(@yy_bootstrap_user_ready = 1, TRIM(@YY_BOOTSTRAP_USER_ACCOUNT), 'bootstrap-disabled-user') USING utf8mb4) COLLATE utf8mb4_unicode_ci;
SET @yy_user_password_hash = CONVERT(IF(@yy_bootstrap_user_ready = 1, @YY_BOOTSTRAP_USER_PASSWORD_HASH, @yy_disabled_password_hash) USING utf8mb4) COLLATE utf8mb4_unicode_ci;

-- 1 级平台所有者：只有三项 ROOT 变量完整且安全时才启用，否则创建不可登录占位账号。
INSERT INTO `platform_accounts`
  (`parent_id`, `created_by_platform_id`, `level`, `platform_key`, `account`, `password_hash`,
   `nickname`, `avatar`, `email`, `phone`, `status`, `disabled_reason`, `membership_level`, `membership_started_at`,
   `membership_expired_at`, `admin_quota`, `integral`, `permissions_json`, `register_ip`, `created_at`, `updated_at`)
VALUES
  (NULL, NULL, 1, @yy_root_platform_key, @yy_root_account, @yy_root_password_hash,
   IF(@yy_bootstrap_root_ready = 1, '易运盈平台所有者', '未配置的平台所有者'), '', NULL, NULL,
   @yy_bootstrap_root_ready,
   IF(@yy_bootstrap_root_ready = 1, '', '首次安装未显式注入平台所有者身份，已安全禁用'),
   'owner', IF(@yy_bootstrap_root_ready = 1, NOW(), NULL), NULL, 0, 0, NULL, '127.0.0.1', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `level` = 1,
  `password_hash` = IF(@yy_bootstrap_root_ready = 1, VALUES(`password_hash`), `password_hash`),
  `status` = VALUES(`status`),
  `disabled_reason` = VALUES(`disabled_reason`),
  `deleted_at` = NULL,
  `updated_at` = NOW();

CREATE TABLE IF NOT EXISTS `user_moments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `content` TEXT NOT NULL,
  `content_kind` VARCHAR(20) NOT NULL DEFAULT 'moment',
  `location_name` VARCHAR(200) NOT NULL DEFAULT '',
  `latitude` DECIMAL(10,7) DEFAULT NULL,
  `longitude` DECIMAL(10,7) DEFAULT NULL,
  `visibility_mode` VARCHAR(20) NOT NULL DEFAULT 'inherit',
  `visible_days` SMALLINT UNSIGNED DEFAULT NULL,
  `visibility_user_ids_json` LONGTEXT DEFAULT NULL,
  `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `pin_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `audit_status` VARCHAR(20) NOT NULL DEFAULT 'approved',
  `audit_reason` VARCHAR(500) NOT NULL DEFAULT '',
  `audited_by` BIGINT UNSIGNED DEFAULT NULL,
  `audited_at` DATETIME DEFAULT NULL,
  `edited_at` DATETIME DEFAULT NULL,
  `deleted_at` DATETIME DEFAULT NULL,
  `delete_expires_at` DATETIME DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_moments_feed` (`admin_id`, `app_id`, `status`, `deleted_at`, `created_at`),
  KEY `idx_user_moments_owner` (`user_id`, `created_at`),
  KEY `idx_user_moments_pinned` (`user_id`, `is_pinned`, `pin_order`, `created_at`),
  KEY `idx_user_moments_moderation` (`admin_id`, `app_id`, `audit_status`, `status`, `deleted_at`, `created_at`),
  KEY `idx_user_moments_kind_feed` (`admin_id`, `app_id`, `content_kind`, `audit_status`, `status`, `deleted_at`, `created_at`),
  KEY `idx_user_moments_purge` (`app_id`, `delete_expires_at`),
  CONSTRAINT `fk_user_moments_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`)
    REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_moments_auditor` FOREIGN KEY (`audited_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moment_likes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `moment_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_moment_likes_user` (`moment_id`, `user_id`),
  KEY `idx_moment_likes_tenant` (`admin_id`, `app_id`, `user_id`, `id`),
  CONSTRAINT `fk_moment_likes_moment` FOREIGN KEY (`moment_id`) REFERENCES `user_moments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_moment_likes_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moment_comments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `moment_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `parent_id` BIGINT UNSIGNED DEFAULT NULL,
  `sticker_id` BIGINT UNSIGNED DEFAULT NULL,
  `content` VARCHAR(2000) NOT NULL,
  `audit_status` VARCHAR(20) NOT NULL DEFAULT 'approved',
  `audit_reason` VARCHAR(500) NOT NULL DEFAULT '',
  `audited_by` BIGINT UNSIGNED DEFAULT NULL,
  `audited_at` DATETIME DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_moment_comments_feed` (`moment_id`, `status`, `id`),
  KEY `idx_moment_comments_user` (`app_id`, `user_id`, `id`),
  KEY `idx_moment_comments_moderation` (`admin_id`, `app_id`, `audit_status`, `status`, `created_at`),
  CONSTRAINT `fk_moment_comments_moment` FOREIGN KEY (`moment_id`) REFERENCES `user_moments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_moment_comments_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_moment_comments_parent` FOREIGN KEY (`parent_id`) REFERENCES `moment_comments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_moment_comments_sticker` FOREIGN KEY (`sticker_id`) REFERENCES `stickers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_moment_comments_auditor` FOREIGN KEY (`audited_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moment_comment_likes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `comment_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_moment_comment_likes_user` (`comment_id`, `user_id`),
  KEY `idx_moment_comment_likes_tenant` (`admin_id`, `app_id`, `user_id`, `id`),
  CONSTRAINT `fk_moment_comment_likes_comment` FOREIGN KEY (`comment_id`) REFERENCES `moment_comments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_moment_comment_likes_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moment_favorites` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `moment_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_moment_favorites_user` (`moment_id`, `user_id`),
  KEY `idx_moment_favorites_user` (`app_id`, `user_id`, `id`),
  CONSTRAINT `fk_moment_favorites_moment` FOREIGN KEY (`moment_id`) REFERENCES `user_moments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_moment_favorites_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `moment_forwards` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `moment_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `target_type` VARCHAR(20) NOT NULL DEFAULT 'external',
  `target_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_moment_forwards_moment` (`moment_id`, `id`),
  KEY `idx_moment_forwards_user` (`app_id`, `user_id`, `id`),
  CONSTRAINT `fk_moment_forwards_moment` FOREIGN KEY (`moment_id`) REFERENCES `user_moments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_moment_forwards_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `business_catalogs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `catalog_code` VARCHAR(30) NOT NULL COMMENT 'resource/shop/balance_shop',
  `catalog_name` VARCHAR(50) NOT NULL,
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_business_catalogs_app_code` (`app_id`, `catalog_code`),
  KEY `idx_business_catalogs_tenant` (`admin_id`, `app_id`, `status`, `sort_order`),
  CONSTRAINT `fk_business_catalogs_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `interaction_scene_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `root_platform_id` BIGINT UNSIGNED NOT NULL,
  `scope_platform_id` BIGINT UNSIGNED DEFAULT NULL,
  `admin_id` BIGINT UNSIGNED DEFAULT NULL,
  `app_id` BIGINT UNSIGNED DEFAULT NULL,
  `entity_type` VARCHAR(30) NOT NULL COMMENT 'red_packet/vote/lottery/reward',
  `entity_id` BIGINT UNSIGNED NOT NULL,
  `scene_type` VARCHAR(30) NOT NULL COMMENT 'chat/forum/bounty/earned/activity',
  `source_type` VARCHAR(40) NOT NULL DEFAULT '',
  `source_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `creator_type` VARCHAR(20) NOT NULL,
  `creator_id` BIGINT UNSIGNED NOT NULL,
  `target_level` TINYINT UNSIGNED NOT NULL DEFAULT 4,
  `visible_levels_json` LONGTEXT,
  `manageable_levels_json` LONGTEXT,
  `policy_json` LONGTEXT,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_interaction_scene_entity` (`entity_type`, `entity_id`),
  KEY `idx_interaction_scene_source` (`scene_type`, `source_type`, `source_id`, `status`),
  KEY `idx_interaction_scene_scope` (`root_platform_id`, `scope_platform_id`, `app_id`, `target_level`, `status`),
  CONSTRAINT `fk_interaction_scene_root` FOREIGN KEY (`root_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_interaction_scene_scope` FOREIGN KEY (`scope_platform_id`) REFERENCES `platform_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_interaction_scene_app` FOREIGN KEY (`app_id`) REFERENCES `apps` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_reward_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `scene_code` VARCHAR(60) NOT NULL,
  `scene_name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `enabled` TINYINT NOT NULL DEFAULT 0,
  `reward_json` LONGTEXT NOT NULL,
  `grant_mode` VARCHAR(20) NOT NULL DEFAULT 'automatic' COMMENT 'automatic/after_review/manual',
  `cycle_type` VARCHAR(20) NOT NULL DEFAULT 'unlimited' COMMENT 'once/daily/weekly/monthly/unlimited',
  `cycle_limit` INT UNSIGNED NOT NULL DEFAULT 0,
  `user_total_limit` INT UNSIGNED NOT NULL DEFAULT 0,
  `conditions_json` LONGTEXT,
  `audience_json` LONGTEXT,
  `manager_level` TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `inherited_from_type` VARCHAR(20) NOT NULL DEFAULT '',
  `inherited_from_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `force_sync` TINYINT NOT NULL DEFAULT 0,
  `created_by_type` VARCHAR(20) NOT NULL DEFAULT 'system',
  `created_by_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_reward_rules_scene` (`app_id`, `scene_code`),
  UNIQUE KEY `uk_app_reward_rules_id_tenant` (`id`, `app_id`, `admin_id`),
  KEY `idx_app_reward_rules_manage` (`admin_id`, `app_id`, `enabled`, `status`, `scene_code`),
  CONSTRAINT `fk_app_reward_rules_app` FOREIGN KEY (`app_id`, `admin_id`) REFERENCES `apps` (`id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_reward_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` BIGINT UNSIGNED NOT NULL,
  `app_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `rule_id` BIGINT UNSIGNED NOT NULL,
  `scene_code` VARCHAR(60) NOT NULL,
  `ref_type` VARCHAR(40) NOT NULL DEFAULT '',
  `ref_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `period_key` VARCHAR(40) NOT NULL DEFAULT '',
  `dedupe_key` VARCHAR(191) NOT NULL,
  `reward_json` LONGTEXT NOT NULL,
  `context_json` LONGTEXT,
  `status` VARCHAR(20) NOT NULL DEFAULT 'granted' COMMENT 'pending/granted/rejected/reversed',
  `reason` VARCHAR(500) NOT NULL DEFAULT '',
  `actor_type` VARCHAR(20) NOT NULL DEFAULT 'system',
  `actor_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `granted_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_reward_events_dedupe` (`app_id`, `dedupe_key`),
  KEY `idx_app_reward_events_user` (`app_id`, `user_id`, `scene_code`, `created_at`),
  KEY `idx_app_reward_events_manage` (`admin_id`, `app_id`, `status`, `created_at`),
  CONSTRAINT `fk_app_reward_events_rule` FOREIGN KEY (`rule_id`, `app_id`, `admin_id`) REFERENCES `app_reward_rules` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_app_reward_events_user` FOREIGN KEY (`user_id`, `app_id`, `admin_id`) REFERENCES `users` (`id`, `app_id`, `admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @root_platform_id = (
  SELECT `id` FROM `platform_accounts`
  WHERE `platform_key` = @yy_root_platform_key AND `account` = @yy_root_account
  LIMIT 1
);

INSERT INTO `platform_settings`
  (`platform_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
VALUES
  (@root_platform_id, 'admin_registration_enabled', '1', 'bool', NOW(), NOW()),
  (@root_platform_id, 'admin_login_enabled', '1', 'bool', NOW(), NOW()),
  (@root_platform_id, 'downstream_user_enabled', '1', 'bool', NOW(), NOW()),
  (@root_platform_id, 'admin_daily_register_limit', '100', 'int', NOW(), NOW()),
  (@root_platform_id, 'admin_ip_daily_register_limit', '3', 'int', NOW(), NOW()),
  (@root_platform_id, 'admin_ip_total_register_limit', '10', 'int', NOW(), NOW()),
  (@root_platform_id, 'admin_account_min_length', '3', 'int', NOW(), NOW()),
  (@root_platform_id, 'admin_account_max_length', '32', 'int', NOW(), NOW()),
  (@root_platform_id, 'admin_free_trial_days', '3', 'int', NOW(), NOW()),
  (@root_platform_id, 'admin_free_app_quota', '1', 'int', NOW(), NOW()),
  (@root_platform_id, 'admin_free_remote_document_quota', '3', 'int', NOW(), NOW()),
  (@root_platform_id, 'admin_free_balance', '15', 'int', NOW(), NOW()),
  (@root_platform_id, 'operator_free_trial_days', '3', 'int', NOW(), NOW()),
  (@root_platform_id, 'operator_free_admin_quota', '10', 'int', NOW(), NOW()),
  (@root_platform_id, 'operator_free_balance', '15', 'int', NOW(), NOW()),
  (@root_platform_id, 'default_chat_poll_interval_ms', '5000', 'int', NOW(), NOW()),
  (@root_platform_id, 'force_chat_poll_interval', '0', 'bool', NOW(), NOW()),
  (@root_platform_id, 'min_chat_poll_interval_ms', '1000', 'int', NOW(), NOW()),
  (@root_platform_id, 'max_chat_poll_interval_ms', '60000', 'int', NOW(), NOW()),
  (@root_platform_id, 'default_message_recall_seconds', '120', 'int', NOW(), NOW()),
  (@root_platform_id, 'force_message_recall_seconds', '0', 'bool', NOW(), NOW()),
  (@root_platform_id, 'allow_child_message_recall_override', '1', 'bool', NOW(), NOW()),
  (@root_platform_id, 'message_recall_inherit', '1', 'bool', NOW(), NOW()),
  (@root_platform_id, 'default_relationship_request_valid_days', '30', 'int', NOW(), NOW()),
  (@root_platform_id, 'force_relationship_request_valid_days', '0', 'bool', NOW(), NOW()),
  (@root_platform_id, 'allow_child_relationship_request_valid_days_override', '1', 'bool', NOW(), NOW()),
  (@root_platform_id, 'relationship_request_valid_days_inherit', '1', 'bool', NOW(), NOW()),
  (@root_platform_id, 'balance_exchange_enabled', '1', 'bool', NOW(), NOW()),
  (@root_platform_id, 'balance_exchange_max_quantity_per_order', '100', 'int', NOW(), NOW()),
  (@root_platform_id, 'balance_exchange_admin_daily_limit', '0', 'int', NOW(), NOW())
  ,(@root_platform_id, 'data_console_enabled', '0', 'bool', NOW(), NOW())
  ,(@root_platform_id, 'balance_display_name', '余额', 'string', NOW(), NOW())
  ,(@root_platform_id, 'authorized_platform_membership_required', '1', 'bool', NOW(), NOW())
  ,(@root_platform_id, 'authorized_platform_vip_only', '0', 'bool', NOW(), NOW())
  ,(@root_platform_id, 'admin_membership_required', '1', 'bool', NOW(), NOW())
  ,(@root_platform_id, 'admin_vip_only', '0', 'bool', NOW(), NOW())
  ,(@root_platform_id, 'admin_balance_purchase_enabled', '1', 'bool', NOW(), NOW())
  ,(@root_platform_id, 'admin_document_purchase_enabled', '1', 'bool', NOW(), NOW())
  ,(@root_platform_id, 'admin_membership_purchase_enabled', '1', 'bool', NOW(), NOW())
  ,(@root_platform_id, 'hierarchical_activities_enabled', '1', 'bool', NOW(), NOW())
  ,(@root_platform_id, 'hierarchical_activity_max_budget', '1000000000', 'int', NOW(), NOW())
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `value_type` = VALUES(`value_type`), `updated_at` = NOW();

INSERT INTO `platform_exchange_products`
  (`platform_id`, `product_code`, `name`, `description`, `product_type`, `grant_json`,
   `price_integral`, `stock`, `sold_count`, `per_admin_limit`, `per_admin_daily_limit`,
   `status`, `sort_order`, `created_at`, `updated_at`)
VALUES
  (@root_platform_id, 'remote_document_1', '1 个远程文档名额', '兑换后立即增加 1 个远程文档名额', 'remote_document_quota',
   '{"remote_document_quota":1}', 5, NULL, 0, 0, 0, 1, 10, NOW(), NOW()),
  (@root_platform_id, 'vip_day_1', '1 天 VIP', '兑换后立即延长 1 天会员', 'membership_days',
   '{"vip_days":1,"membership_level":"vip"}', 10, NULL, 0, 0, 0, 1, 20, NOW(), NOW()),
  (@root_platform_id, 'app_quota_1', '1 个 App 名额', '兑换后立即增加 1 个 App 创建名额', 'app_quota',
   '{"app_quota":1}', 50, NULL, 0, 0, 0, 1, 30, NOW(), NOW()),
  (@root_platform_id, 'growth_bundle', '成长组合包', '包含 30 天 VIP、1 个 App 名额和 10 个远程文档名额', 'bundle',
   '{"vip_days":30,"membership_level":"vip","app_quota":1,"remote_document_quota":10}',
   100, NULL, 0, 0, 0, 1, 40, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`), `product_type` = VALUES(`product_type`),
  `grant_json` = VALUES(`grant_json`), `price_integral` = VALUES(`price_integral`),
  `status` = VALUES(`status`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

-- 可选 2 级授权平台：只有三项 AUTHORIZED 变量完整且上级已启用时才启用。
-- 未注入时仍保留禁用占位关系，便于完整验证外键和数据结构，但任何已知密码都无法登录。
INSERT INTO `platform_accounts`
  (`parent_id`, `created_by_platform_id`, `level`, `platform_key`, `account`, `password_hash`,
   `nickname`, `avatar`, `email`, `phone`, `status`, `disabled_reason`, `membership_level`, `membership_started_at`,
   `membership_expired_at`, `admin_quota`, `integral`, `permissions_json`, `register_ip`, `created_at`, `updated_at`)
VALUES
  (@root_platform_id, @root_platform_id, 2, @yy_authorized_platform_key, @yy_authorized_account,
   @yy_authorized_password_hash,
   IF(@yy_bootstrap_authorized_ready = 1, '授权平台', '未配置的授权平台'), '', NULL, NULL,
   @yy_bootstrap_authorized_ready,
   IF(@yy_bootstrap_authorized_ready = 1, '', '首次安装未显式注入授权平台身份，已安全禁用'),
   'vip', IF(@yy_bootstrap_authorized_ready = 1, NOW(), NULL),
   IF(@yy_bootstrap_authorized_ready = 1, DATE_ADD(NOW(), INTERVAL 3650 DAY), NULL),
   10, 100, NULL, '127.0.0.1', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `parent_id` = @root_platform_id, `created_by_platform_id` = @root_platform_id,
  `level` = 2,
  `password_hash` = IF(@yy_bootstrap_authorized_ready = 1, VALUES(`password_hash`), `password_hash`),
  `status` = VALUES(`status`), `disabled_reason` = VALUES(`disabled_reason`), `membership_level` = 'vip',
  `membership_expired_at` = IF(
    @yy_bootstrap_authorized_ready = 1,
    GREATEST(COALESCE(`membership_expired_at`, NOW()), DATE_ADD(NOW(), INTERVAL 3650 DAY)),
    `membership_expired_at`
  ),
  `admin_quota` = GREATEST(`admin_quota`, 10), `deleted_at` = NULL, `updated_at` = NOW();

SET @authorized_platform_id = (
  SELECT `id` FROM `platform_accounts`
  WHERE `platform_key` = @yy_authorized_platform_key AND `account` = @yy_authorized_account
  LIMIT 1
);

INSERT INTO `platform_settings`
  (`platform_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
SELECT @authorized_platform_id, `setting_key`, `setting_value`, `value_type`, NOW(), NOW()
FROM `platform_settings`
WHERE `platform_id` = @root_platform_id AND @authorized_platform_id IS NOT NULL
ON DUPLICATE KEY UPDATE
  `setting_value` = VALUES(`setting_value`), `value_type` = VALUES(`value_type`), `updated_at` = NOW();

INSERT INTO `platform_exchange_products`
  (`platform_id`, `product_code`, `name`, `description`, `product_type`, `grant_json`,
   `price_integral`, `stock`, `sold_count`, `per_admin_limit`, `per_admin_daily_limit`,
   `status`, `sort_order`, `start_at`, `end_at`, `created_at`, `updated_at`, `deleted_at`)
SELECT @authorized_platform_id, `product_code`, `name`, `description`, `product_type`, `grant_json`,
       `price_integral`, `stock`, 0, `per_admin_limit`, `per_admin_daily_limit`,
       `status`, `sort_order`, `start_at`, `end_at`, NOW(), NOW(), NULL
FROM `platform_exchange_products`
WHERE `platform_id` = @root_platform_id AND @authorized_platform_id IS NOT NULL
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`), `product_type` = VALUES(`product_type`),
  `grant_json` = VALUES(`grant_json`), `price_integral` = VALUES(`price_integral`),
  `status` = VALUES(`status`), `sort_order` = VALUES(`sort_order`), `deleted_at` = NULL, `updated_at` = NOW();

-- 可选 3 级管理员：只有 ADMIN 账号和 password_hash 完整且上级已启用时才启用。
INSERT INTO `admins`
  (`platform_id`, `account`, `password_hash`, `nickname`, `avatar`, `email`, `phone`, `status`, `register_ip`, `created_at`, `updated_at`)
VALUES
  (@root_platform_id, @yy_admin_account, @yy_admin_password_hash,
   IF(@yy_bootstrap_admin_ready = 1, '后台管理员', '未配置的后台管理员'), '', NULL, NULL,
   @yy_bootstrap_admin_ready, '127.0.0.1', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `password_hash` = IF(@yy_bootstrap_admin_ready = 1, VALUES(`password_hash`), `password_hash`),
  `status` = VALUES(`status`),
  `updated_at` = NOW();

SET @admin_id = (
  SELECT `id` FROM `admins`
  WHERE `platform_id` = @root_platform_id AND `account` = @yy_admin_account
  LIMIT 1
);

INSERT INTO `admin_entitlements`
  (`platform_id`, `admin_id`, `membership_level`, `membership_status`, `membership_started_at`,
   `membership_expired_at`, `app_quota`, `remote_document_quota`, `integral`, `allowed_weekdays`,
   `last_granted_by_platform_id`, `created_at`, `updated_at`)
VALUES
  (@root_platform_id, @admin_id, 'trial', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY),
   2, 3, 15, '1,2,3,4,5,6,7', @root_platform_id, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `membership_status` = 'active',
  `membership_expired_at` = GREATEST(`membership_expired_at`, DATE_ADD(NOW(), INTERVAL 3 DAY)),
  `app_quota` = GREATEST(`app_quota`, 2),
  `remote_document_quota` = GREATEST(`remote_document_quota`, 3),
  `integral` = GREATEST(`integral`, 15),
  `updated_at` = NOW();

INSERT INTO `admin_entitlement_logs`
  (`platform_id`, `admin_id`, `actor_platform_id`, `change_type`, `before_json`, `change_json`, `after_json`, `remark`, `created_at`)
SELECT @root_platform_id, @admin_id, @root_platform_id, 'install_gift', NULL,
       '{"vip_days":3,"app_quota":2,"remote_document_quota":3,"integral":15}', NULL,
       '安装默认权益，其中一个应用名额供演示应用使用', NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `admin_entitlement_logs` WHERE `admin_id` = @admin_id AND `change_type` = 'install_gift'
);

INSERT INTO `admin_integral_logs`
  (`platform_id`, `admin_id`, `change_value`, `before_value`, `after_value`, `scene`,
   `ref_type`, `ref_id`, `remark`, `actor_platform_id`, `created_at`)
SELECT @root_platform_id, @admin_id, 15, 0, 15, 'install_gift', 'admin', @admin_id,
       '安装默认赠送 15 平台余额', @root_platform_id, NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `admin_integral_logs` WHERE `admin_id` = @admin_id AND `scene` = 'install_gift'
);

-- 可选引导应用：APP_KEY 与随机 APP_SECRET_HASH 必须显式注入；缺失时应用保持禁用。
INSERT INTO `apps`
  (`admin_id`, `app_key`, `app_secret_hash`, `name`, `logo`, `description`, `status`, `version`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @yy_app_key, @yy_app_secret_hash,
   IF(@yy_bootstrap_app_ready = 1, '易运盈引导应用', '未配置的引导应用'), '',
   '用于首次安装结构初始化；启用身份必须由部署者显式注入', @yy_bootstrap_app_ready, '1.0.0', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `app_secret_hash` = IF(@yy_bootstrap_app_ready = 1, VALUES(`app_secret_hash`), `app_secret_hash`),
  `status` = VALUES(`status`),
  `updated_at` = NOW();

SET @app_id = (SELECT `id` FROM `apps` WHERE `app_key` = @yy_app_key AND `admin_id` = @admin_id LIMIT 1);

INSERT INTO `business_catalogs`
  (`admin_id`, `app_id`, `catalog_code`, `catalog_name`, `description`, `sort_order`, `status`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @app_id, 'resource', '资源', '资源大类，下设应用商店和源码商城', 10, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'shop', '商店', '由上级面向对应下级发布的综合商品', 20, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'balance_shop', '余额商店', '仅使用应用内余额购买的虚拟商品', 30, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `catalog_name` = VALUES(`catalog_name`),
  `description` = VALUES(`description`),
  `sort_order` = VALUES(`sort_order`),
  `status` = VALUES(`status`),
  `updated_at` = NOW();

INSERT INTO `app_reward_rules`
  (`admin_id`, `app_id`, `scene_code`, `scene_name`, `description`, `enabled`, `grant_mode`,
   `reward_json`, `cycle_type`, `cycle_limit`, `user_total_limit`,
   `status`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @app_id, 'register', '注册奖励', '用户首次完成注册后发放', 0, 'automatic', '{}', 'once', 1, 1, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'login', '登录奖励', '用户成功登录后按配置周期发放', 0, 'automatic', '{}', 'daily', 1, 0, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'daily_sign', '签到奖励', '用户每日签到后发放；未启用时沿用旧版签到奖励配置', 0, 'automatic', '{}', 'daily', 1, 0, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'invite_success', '邀请奖励', '被邀请用户完成注册后向邀请人发放', 0, 'automatic', '{}', 'unlimited', 0, 0, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'forum_post_create', '发帖奖励', '帖子发布并满足规则后发放', 0, 'automatic', '{}', 'unlimited', 0, 0, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'forum_plate_create', '建设论坛奖励', '板块创建并通过审核后发放', 0, 'after_review', '{}', 'unlimited', 0, 0, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'valid_report', '有效举报奖励', '举报被审核为有效后发放', 0, 'after_review', '{}', 'unlimited', 0, 0, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'valid_feedback', '有效反馈奖励', '反馈被审核为有效后发放', 0, 'after_review', '{}', 'unlimited', 0, 0, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'comment_create', '有效评论奖励', '评论发布并满足规则后发放', 0, 'automatic', '{}', 'unlimited', 0, 0, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'reply_create', '有效回复奖励', '回复发布并满足规则后发放', 0, 'automatic', '{}', 'unlimited', 0, 0, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `scene_name` = VALUES(`scene_name`),
  `description` = VALUES(`description`),
  `updated_at` = NOW();

INSERT INTO `app_settings`
  (`admin_id`, `app_id`, `setting_key`, `setting_value`, `value_type`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @app_id, 'registration_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'registration_nickname_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'registration_nickname_required', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'registration_email_enabled', '0', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'registration_email_required', '0', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'registration_phone_enabled', '0', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'registration_phone_required', '0', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'identity_unbind_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'login_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'document_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'card_redeem_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'card_login_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'public_app_statistics_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'heartbeat_online_seconds', '180', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'initial_document_credit', '20', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'document_create_cost', '1', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'document_max_count', '1000', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'document_share_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'account_min_length', '3', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'account_max_length', '32', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'daily_register_limit', '1000', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'register_ip_daily_limit', '10', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'password_reset_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'profile_edit_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'profile_public_default', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'moment_like_non_friend_visible', '0', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'moment_post_audit', '0', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'moment_comment_audit', '0', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'profile_like_per_action_limit', '10', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'profile_like_daily_limit', '50', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'sign_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'sign_reward_balance', '10', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'sign_reward_experience', '5', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'sign_reward_credit', '0', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'invite_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'invite_reward_balance', '20', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'private_message_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'accept_stranger_messages_default', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'forum_post_audit', '0', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'forum_comment_audit', '0', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'resource_user_submit_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'resource_submit_audit', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'store_user_submit_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'store_submit_audit', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'catalog_private_migration_ready', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'upload_max_bytes', '104857600', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'upload_image_max_bytes', '104857600', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'upload_video_max_bytes', '1073741824', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'upload_audio_max_bytes', '104857600', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'upload_file_max_bytes', '536870912', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'media_optimize_by_default', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'media_original_upload_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'sticker_optimize_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'sticker_target_max_bytes', '524288', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'lottery_daily_limit', '3', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'wallet_transfer_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'wallet_transfer_max', '1000000', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'chat_poll_interval_ms', '5000', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'user_group_create_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'user_group_max_owned', '10', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'group_default_max_members', '500', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'user_chatroom_create_enabled', '1', 'bool', NOW(), NOW()),
  (@admin_id, @app_id, 'user_chatroom_max_owned', '10', 'int', NOW(), NOW()),
  (@admin_id, @app_id, 'chatroom_default_max_members', '500', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'group_restore_days', '7', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'bounty_min_reward_balance', '1', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'bounty_max_reward_balance', '1000000', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'withdrawal_enabled', '1', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'withdrawal_min_amount', '1', 'float', NOW(), NOW())
  ,(@admin_id, @app_id, 'withdrawal_max_amount', '100000', 'float', NOW(), NOW())
  ,(@admin_id, @app_id, 'message_recall_seconds', '120', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'message_recall_inherit', '1', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'relationship_request_valid_days', '30', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'relationship_request_valid_days_inherit', '1', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_reward_enabled', '1', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_paid_content_enabled', '1', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_unlock_max_price_balance', '1000000000', 'float', NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_unlock_max_future_days', '3650', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_hot_enabled', '1', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_hot_score_threshold', '40', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_hot_window_days', '14', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_self_post_pin_limit', '5', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_self_comment_pin_limit', '3', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_personal_plate_pin_limit', '20', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_personal_post_pin_limit', '20', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_paid_section_max_count', '30', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'user_poll_create_enabled', '1', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'economy_primary_asset', 'balance', 'string', NOW(), NOW())
  ,(@admin_id, @app_id, 'user_initial_balance', '0', 'float', NOW(), NOW())
  ,(@admin_id, @app_id, 'user_initial_activity_credit', '0', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'user_free_vip_days', '0', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'user_login_vip_only', '0', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'document_credit_separate', '1', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'balance_document_purchase_enabled', '0', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'document_credit_balance_price', '1', 'float', NOW(), NOW())
  ,(@admin_id, @app_id, 'balance_membership_purchase_enabled', '0', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'vip_day_balance_price', '1', 'float', NOW(), NOW())
  ,(@admin_id, @app_id, 'balance_activity_enabled', '1', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'cloud_chat_backup_enabled', '1', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'cloud_chat_backup_vip_required', '0', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'cloud_chat_backup_price', '0', 'float', NOW(), NOW())
  ,(@admin_id, @app_id, 'cloud_sticker_sync_enabled', '1', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'cloud_sticker_sync_vip_required', '0', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'cloud_sticker_sync_price', '0', 'float', NOW(), NOW())
  ,(@admin_id, @app_id, 'cloud_favorite_sync_enabled', '1', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'cloud_favorite_sync_vip_required', '0', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'cloud_favorite_sync_price', '0', 'float', NOW(), NOW())
  ,(@admin_id, @app_id, 'cloud_backup_max_items', '5000', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'cloud_backup_retention_days', '3650', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'chat_local_cache_days', '90', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'media_cache_max_bytes', '536870912', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'auto_download_cache_enabled', '1', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'auto_cache_allowed_categories', '["chat_record","profile","image","video","voice","audio","document","file","sticker"]', 'json', NOW(), NOW())
  ,(@admin_id, @app_id, 'auto_cache_default_max_bytes', '536870912', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'auto_cache_max_bytes_limit', '2147483648', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'auto_cache_retention_days', '90', 'int', NOW(), NOW())
  ,(@admin_id, @app_id, 'auto_cache_network', 'wifi_mobile', 'string', NOW(), NOW())
  ,(@admin_id, @app_id, 'auto_cache_force_wifi_only', '0', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'auto_cache_policy_version', '2026.08.01', 'string', NOW(), NOW())
  ,(@admin_id, @app_id, 'video_autoplay_enabled', '1', 'bool', NOW(), NOW())
  ,(@admin_id, @app_id, 'video_autoplay_network', 'wifi_mobile', 'string', NOW(), NOW())
  ,(@admin_id, @app_id, 'video_autoplay_default_network', 'wifi', 'string', NOW(), NOW())
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `value_type` = VALUES(`value_type`), `updated_at` = NOW();

INSERT INTO `app_feature_flags`
  (`admin_id`, `app_id`, `feature_code`, `enabled`, `config_json`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @app_id, 'user_account', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'user_profile', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'sign_invite', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'documents', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'notices', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'resources', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'store', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'forum', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'messages', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'chat_rooms', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'service', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'cards', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'payments', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'remote_files', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'shop', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'red_packets', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'lottery', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'votes', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'feedback', 1, NULL, NOW(), NOW()),
  (@admin_id, @app_id, 'bot', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'bounties', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'level_forum', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'social', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'short_videos', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'short_video_publish', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'short_video_comments', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'short_video_likes', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'short_video_favorites', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'short_video_forwards', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'notifications', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'withdrawals', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'chat_extensions', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'chat_camera', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'chat_album', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'chat_contact_card', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'chat_call_record_label', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'group_avatar_upload', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'chatroom_avatar_upload', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_plate_avatar_upload', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_chapters', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_paid_unlock', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_scheduled_unlock', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_attachment_unlock', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'forum_media_filename_privacy', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'balance_document_purchase', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'balance_membership_purchase', 1, NULL, NOW(), NOW())
  ,(@admin_id, @app_id, 'hierarchical_activities', 1, NULL, NOW(), NOW())
ON DUPLICATE KEY UPDATE `enabled` = VALUES(`enabled`), `updated_at` = NOW();

INSERT INTO `resource_categories`
  (`admin_id`, `app_id`, `resource_type`, `name`, `icon`, `description`, `sort_order`, `status`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @app_id, 'source_market', 'Android Java 源码', '', 'Android 原生 Java 源码、示例与完整工程', 130, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'source_market', 'iApp 源码', '', 'iApp 源码、界面示例与可复用模块', 120, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'source_market', 'Lua 源码', '', 'Lua 脚本、源码模块与完整示例', 110, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'source_market', 'Web 源码', '', '网页、前端界面与 Web 完整工程源码', 100, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'source_market', 'PHP 源码', '', 'PHP 服务端源码、接口与完整工程', 90, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'source_market', 'Python 源码', '', 'Python 脚本、服务与完整工程源码', 80, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'source_market', 'JavaScript 源码', '', 'JavaScript、Node.js 与前端框架源码', 70, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'source_market', 'HarmonyOS 源码', '', 'HarmonyOS、ArkTS 与鸿蒙应用源码', 60, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'source_market', 'iOS 源码', '', 'iOS、Swift 与苹果应用完整源码', 50, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'source_market', 'C/C++ 源码', '', 'C、C++ 源码、库与完整工程', 40, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'source_market', '数据库源码', '', '数据库脚本、结构、查询与迁移源码', 30, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'source_market', '通用模块', '', '好友聊天、群聊、登录注册、论坛、文档和商城等独立模块', 20, 1, NOW(), NOW()),
  (@admin_id, @app_id, 'source_market', '其他源码', '', '未归入标准技术分类的其他源码与示例', 10, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `sort_order` = VALUES(`sort_order`), `status` = 1, `updated_at` = NOW();

INSERT INTO `store_categories`
  (`admin_id`, `app_id`, `name`, `icon`, `sort_order`, `status`, `created_at`)
VALUES
  (@admin_id, @app_id, '应用', '', 0, 1, NOW())
ON DUPLICATE KEY UPDATE `status` = 1;

INSERT INTO `forum_plates`
  (`admin_id`, `app_id`, `name`, `icon`, `description`, `sort_order`, `status`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @app_id, '综合交流', '', '经验、教程、问题与日常交流', 20, 1, NOW(), NOW()),
  (@admin_id, @app_id, '游戏交流', '', '按游戏和玩法浏览讨论内容', 10, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `status` = 1, `updated_at` = NOW();

SET @general_plate_id = (SELECT `id` FROM `forum_plates` WHERE `app_id` = @app_id AND `name` = '综合交流' LIMIT 1);
SET @game_plate_id = (SELECT `id` FROM `forum_plates` WHERE `app_id` = @app_id AND `name` = '游戏交流' LIMIT 1);

INSERT INTO `forum_categories`
  (`admin_id`, `app_id`, `plate_id`, `name`, `description`, `icon`, `sort_order`, `status`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @app_id, @general_plate_id, '经验交流', '分享经验、作品、教程与使用心得', '', 20, 1, NOW(), NOW()),
  (@admin_id, @app_id, @general_plate_id, '问题求助', '描述问题、环境和已尝试的解决办法', '', 10, 1, NOW(), NOW()),
  (@admin_id, @app_id, @game_plate_id, '王者荣耀', '王者荣耀攻略、组队与赛事讨论', '', 20, 1, NOW(), NOW()),
  (@admin_id, @app_id, @game_plate_id, '我的世界', 'Minecraft 建造、模组、服务器与玩法讨论', '', 10, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `status` = 1, `updated_at` = NOW();

SET @king_category_id = (SELECT `id` FROM `forum_categories` WHERE `app_id` = @app_id AND `plate_id` = @game_plate_id AND `name` = '王者荣耀' LIMIT 1);
SET @minecraft_category_id = (SELECT `id` FROM `forum_categories` WHERE `app_id` = @app_id AND `plate_id` = @game_plate_id AND `name` = '我的世界' LIMIT 1);

INSERT INTO `forum_tags`
  (`admin_id`, `app_id`, `plate_id`, `category_id`, `name`, `aliases_json`, `description`, `sort_order`, `status`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @app_id, @game_plate_id, @king_category_id, '王者荣耀', '["王者","Honor of Kings"]', '王者荣耀的规范标签', 20, 1, NOW(), NOW()),
  (@admin_id, @app_id, @game_plate_id, @minecraft_category_id, '我的世界', '["MC","Minecraft","麦块"]', '我的世界的规范标签及常用别名', 10, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE `category_id` = VALUES(`category_id`), `aliases_json` = VALUES(`aliases_json`),
  `description` = VALUES(`description`), `status` = 1, `updated_at` = NOW();

INSERT INTO `forum_report_tags`
  (`admin_id`, `app_id`, `name`, `description`, `sort_order`, `status`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @app_id, '广告营销', '广告、引流或营销内容', 40, 1, NOW(), NOW()),
  (@admin_id, @app_id, '违法违规', '涉嫌违法或违反平台规则', 30, 1, NOW(), NOW()),
  (@admin_id, @app_id, '人身攻击', '侮辱、骚扰或人身攻击', 20, 1, NOW(), NOW()),
  (@admin_id, @app_id, '其他问题', '不属于以上分类的问题', 10, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE `status` = 1, `description` = VALUES(`description`), `updated_at` = NOW();

INSERT INTO `chat_rooms`
  (`admin_id`, `app_id`, `name`, `icon`, `description`, `room_kind`, `is_public`, `status`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @app_id, '公共聊天室', '', '易运盈演示聊天室', 'chat_room', 1, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE `room_kind` = 'chat_room', `status` = 1, `updated_at` = NOW();

INSERT INTO `payment_channels`
  (`admin_id`, `app_id`, `channel_code`, `name`, `config_json`, `enabled`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @app_id, 'bootstrap-disabled', '未配置支付通道', '{"gateway_url":""}', 0, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `config_json` = VALUES(`config_json`), `enabled` = 0, `updated_at` = NOW();

INSERT INTO `remote_configs`
  (`admin_id`, `app_id`, `config_key`, `config_value`, `value_type`, `description`, `status`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @app_id, 'welcome_text', '欢迎使用易运盈后台', 'string', '启动欢迎语', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE `config_value` = VALUES(`config_value`), `updated_at` = NOW();

-- 可选 4 级用户：UID、账号和 password_hash 必须显式注入，且上级应用必须已启用。
INSERT INTO `users`
  (`uid`, `admin_id`, `app_id`, `account`, `password_hash`, `email`, `phone`, `status`, `register_ip`, `created_at`, `updated_at`)
VALUES
  (@yy_user_uid, @admin_id, @app_id, @yy_user_account, @yy_user_password_hash,
   NULL, NULL, @yy_bootstrap_user_ready, '127.0.0.1', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `password_hash` = IF(@yy_bootstrap_user_ready = 1, VALUES(`password_hash`), `password_hash`),
  `status` = VALUES(`status`),
  `updated_at` = NOW();

SET @user_id = (
  SELECT `id` FROM `users`
  WHERE `app_id` = @app_id AND `account` = @yy_user_account AND `uid` = @yy_user_uid
  LIMIT 1
);

INSERT INTO `user_profiles`
  (`admin_id`, `app_id`, `user_id`, `nickname`, `qq`, `avatar`, `background`, `signature`, `gender`, `title`, `public_profile`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @app_id, @user_id, '默认用户', '', '', '', '易运盈后台测试账号', '', '', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`);

INSERT INTO `user_wallets`
  (`admin_id`, `app_id`, `user_id`, `integral`, `experience`, `balance`, `document_credit`, `level_code`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @app_id, @user_id, 0, 0, 0.00, 20, 'normal', NOW(), NOW())
ON DUPLICATE KEY UPDATE `user_id` = VALUES(`user_id`);

SET @room_id = (SELECT `id` FROM `chat_rooms` WHERE `app_id` = @app_id AND `name` = '公共聊天室' LIMIT 1);

INSERT INTO `chat_room_policies`
  (`admin_id`, `app_id`, `room_id`, `owner_user_id`, `join_mode`, `max_members`, `allow_member_invite`, `mute_all`, `announcement`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @app_id, @room_id, NULL, 'open', 500, 1, 0, '欢迎进入公共聊天室', NOW(), NOW())
ON DUPLICATE KEY UPDATE `join_mode` = VALUES(`join_mode`), `updated_at` = NOW();

INSERT INTO `chat_room_members`
  (`admin_id`, `app_id`, `room_id`, `user_id`, `role`, `joined_at`)
VALUES
  (@admin_id, @app_id, @room_id, @user_id, 'member', NOW())
ON DUPLICATE KEY UPDATE `role` = VALUES(`role`);

INSERT INTO `shop_goods`
  (`admin_id`, `app_id`, `catalog_code`, `name`, `cover_url`, `description`, `price_integral`, `price_money`, `stock`, `sales_count`, `status`, `created_at`, `updated_at`)
SELECT @admin_id, @app_id, 'balance_shop', '演示余额商品', '', '用于测试余额商店购买闭环', 20, 0.00, 100, 0, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `shop_goods` WHERE `app_id` = @app_id AND `name` = '演示余额商品'
);

INSERT INTO `lottery_prizes`
  (`admin_id`, `app_id`, `name`, `prize_type`, `value_json`, `weight`, `stock`, `daily_limit`, `status`, `created_at`, `updated_at`)
SELECT @admin_id, @app_id, '10余额', 'balance', '{"balance":10}', 100, 1000, 0, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `lottery_prizes` WHERE `app_id` = @app_id AND `name` = '10余额'
);

INSERT INTO `votes`
  (`admin_id`, `app_id`, `title`, `description`, `multi_select`, `max_select`, `status`, `created_at`, `updated_at`)
SELECT @admin_id, @app_id, '你最常用的功能', '演示投票', 0, 1, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `votes` WHERE `app_id` = @app_id AND `title` = '你最常用的功能'
);

SET @vote_id = (SELECT `id` FROM `votes` WHERE `app_id` = @app_id AND `title` = '你最常用的功能' LIMIT 1);

INSERT INTO `vote_options`
  (`admin_id`, `app_id`, `vote_id`, `option_text`, `vote_count`, `sort_order`, `created_at`)
SELECT @admin_id, @app_id, @vote_id, '文档中心', 0, 1, NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `vote_options` WHERE `vote_id` = @vote_id AND `option_text` = '文档中心');

INSERT INTO `vote_options`
  (`admin_id`, `app_id`, `vote_id`, `option_text`, `vote_count`, `sort_order`, `created_at`)
SELECT @admin_id, @app_id, @vote_id, '资源论坛', 0, 2, NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `vote_options` WHERE `vote_id` = @vote_id AND `option_text` = '资源论坛');

INSERT INTO `bot_qa`
  (`admin_id`, `app_id`, `question`, `answer`, `keywords`, `sort_order`, `status`, `created_at`, `updated_at`)
SELECT @admin_id, @app_id, '如何创建文档', '登录后进入文档中心，选择新建文档即可。', '文档,新建,创建', 1, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `bot_qa` WHERE `app_id` = @app_id AND `question` = '如何创建文档');

INSERT INTO `notices`
  (`admin_id`, `app_id`, `title`, `content`, `type`, `is_popup`, `status`, `created_at`, `updated_at`)
SELECT @admin_id, @app_id, '欢迎使用易运盈后台', '完整后台已经安装完成，可测试账号、内容、社区、通信、交易、文件、反馈和审计等全部业务模块。', 'notice', 1, 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `notices` WHERE `app_id` = @app_id AND `title` = '欢迎使用易运盈后台' AND `deleted_at` IS NULL
);

INSERT INTO `app_versions`
  (`admin_id`, `app_id`, `version_name`, `version_code`, `apk_url`, `update_content`, `force_update`, `status`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @app_id, '1.0.0', 1, '', '首次安装未发布安装包，请由管理员校验后发布', 0, 0, NOW(), NOW())
ON DUPLICATE KEY UPDATE `version_name` = VALUES(`version_name`), `status` = 0, `updated_at` = NOW();

-- 引导卡密固定为禁用占位，避免全新安装产生任何公开可兑换凭据。
INSERT INTO `card_batches`
  (`admin_id`, `app_id`, `name`, `card_type`, `value_json`, `total_count`, `used_count`, `max_use`, `status`, `created_at`, `updated_at`)
SELECT @admin_id, @app_id, '安全禁用引导批次', 'mixed', '{"balance":100,"document_credit":10,"vip_days":7}', 1, 0, 1, 0, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `card_batches` WHERE `app_id` = @app_id AND `name` = '安全禁用引导批次'
);

SET @batch_id = (SELECT `id` FROM `card_batches` WHERE `app_id` = @app_id AND `name` = '安全禁用引导批次' ORDER BY `id` ASC LIMIT 1);

INSERT INTO `cards`
  (`admin_id`, `app_id`, `batch_id`, `card_code`, `card_type`, `value_json`, `max_use`, `used_count`, `status`, `created_at`, `updated_at`)
VALUES
  (@admin_id, @app_id, @batch_id, 'BOOTSTRAP-DISABLED-CARD', 'mixed',
   '{"balance":100,"document_credit":10,"vip_days":7}', 1, 0, 0, NOW(), NOW())
ON DUPLICATE KEY UPDATE `status` = 0, `updated_at` = NOW();

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.12-core-schema', '核心账号、应用、用户、文档、卡密、公告版本与日志结构', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.12-maximum-loop', '易运盈后台最大闭环：内容、社区、通信、交易、文件、反馈与完整审计', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.12-platform-governance', '四级平台、会员额度、注册限制、购买反馈与强制轮询治理', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.12-balance-exchange', '平台余额商品、自动兑换、余额流水、限购库存与受约束退款闭环', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.13-message-recall-policy', '私聊与群聊限时撤回、客服不可撤回、三级继承与强制同步规则', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.13-multimedia-social', '统一多媒体附件、个人表情包、撤回审计与用户监管资料', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.13-media-cache-cloud-sync', '大文件优化上传、分类聊天搜索、本地缓存策略与跨设备云端快照', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.14-forum-experience', '论坛分节付费、唯一访客、热度排序、评论互动、个人置顶置底和会话置底', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.14-chat-identity-settings', '匿名双轨审计、客服实名、好友权限、个人名片与礼物墙', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.15-group-album-media', '群相册图片与视频统一媒体元数据', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.15-message-replies', '私聊与客服引用消息', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.15-speech-transcription', '语音消息转写缓存与媒体元数据回写', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.15-upload-limits', '图片视频与通用文件上传上限升级', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.15-voice-calls', '应用内网络语音通话状态与 WebRTC 信令', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.15-video-calls', '应用内视频通话与摄像头切换', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.21-business-catalog-rewards', '资源、商店、余额商店目录及全场景奖励规则闭环', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);

INSERT INTO `schema_migrations` (`version`, `description`, `applied_at`)
VALUES ('2026.07.22-random-red-packet-money', '拼手气红包、两位小数与运气王标识', NOW())
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = VALUES(`applied_at`);
