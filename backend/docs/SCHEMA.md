# 易运盈后台数据库结构参考

> 本文件由 `php tools/generate-reference.php` 从 `database/install.sql` 生成。

- 数据表：215
- 字符集：`utf8mb4`
- 租户边界：平台层按 `platform_id`，后台层按 `admin_id`，应用业务层按 `admin_id + app_id` 隔离

## `schema_migrations`

| 字段 | SQL 定义 |
| --- | --- |
| `version` | `VARCHAR(50) NOT NULL` |
| `description` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `applied_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`version\`)`

## `platform_accounts`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `parent_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `created_by_platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `level` | `TINYINT UNSIGNED NOT NULL` |
| `platform_key` | `VARCHAR(80) NOT NULL` |
| `account` | `VARCHAR(64) NOT NULL` |
| `password_hash` | `VARCHAR(255) NOT NULL` |
| `nickname` | `VARCHAR(100) NOT NULL DEFAULT ''` |
| `avatar` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `email` | `VARCHAR(190) DEFAULT NULL` |
| `phone` | `VARCHAR(40) DEFAULT NULL` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `disabled_reason` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `membership_level` | `VARCHAR(40) NOT NULL DEFAULT 'authorized'` |
| `membership_started_at` | `DATETIME DEFAULT NULL` |
| `membership_expired_at` | `DATETIME DEFAULT NULL` |
| `admin_quota` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `integral` | `BIGINT NOT NULL DEFAULT 0` |
| `access_start_time` | `TIME DEFAULT NULL` |
| `access_end_time` | `TIME DEFAULT NULL` |
| `allowed_weekdays` | `VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7'` |
| `permissions_json` | `LONGTEXT` |
| `register_ip` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `last_login_ip` | `VARCHAR(64) DEFAULT NULL` |
| `last_login_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |
| `deleted_at` | `DATETIME DEFAULT NULL` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_platform_accounts_key\` (\`platform_key\`)`
- `UNIQUE KEY \`uk_platform_accounts_account\` (\`account\`)`
- `KEY \`idx_platform_accounts_parent_level\` (\`parent_id\`, \`level\`, \`status\`)`
- `CONSTRAINT \`fk_platform_accounts_parent\` FOREIGN KEY (\`parent_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_platform_accounts_creator\` FOREIGN KEY (\`created_by_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE SET NULL`

## `platform_tokens`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `token_hash` | `CHAR(64) NOT NULL` |
| `device` | `VARCHAR(100) NOT NULL DEFAULT ''` |
| `ip` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `user_agent` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `expired_at` | `DATETIME NOT NULL` |
| `revoked_at` | `DATETIME DEFAULT NULL` |
| `last_used_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_platform_tokens_hash\` (\`token_hash\`)`
- `KEY \`idx_platform_tokens_owner_expire\` (\`platform_id\`, \`expired_at\`)`
- `CONSTRAINT \`fk_platform_tokens_owner\` FOREIGN KEY (\`platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`

## `platform_login_logs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `account` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `ip` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `user_agent` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `result` | `TINYINT NOT NULL DEFAULT 0` |
| `reason` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_platform_login_owner_time\` (\`platform_id\`, \`created_at\`)`
- `KEY \`idx_platform_login_ip_time\` (\`ip\`, \`created_at\`)`
- `CONSTRAINT \`fk_platform_login_owner\` FOREIGN KEY (\`platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE SET NULL`

## `platform_operation_logs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `actor_level` | `TINYINT UNSIGNED NOT NULL` |
| `module` | `VARCHAR(50) NOT NULL` |
| `action` | `VARCHAR(50) NOT NULL` |
| `target_type` | `VARCHAR(50) NOT NULL DEFAULT ''` |
| `target_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `before_json` | `LONGTEXT` |
| `after_json` | `LONGTEXT` |
| `ip` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_platform_ops_owner_time\` (\`platform_id\`, \`created_at\`)`
- `KEY \`idx_platform_ops_target\` (\`target_type\`, \`target_id\`)`
- `CONSTRAINT \`fk_platform_ops_owner\` FOREIGN KEY (\`platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`

## `platform_settings`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `setting_key` | `VARCHAR(80) NOT NULL` |
| `setting_value` | `LONGTEXT` |
| `value_type` | `VARCHAR(20) NOT NULL DEFAULT 'string'` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_platform_settings_key\` (\`platform_id\`, \`setting_key\`)`
- `CONSTRAINT \`fk_platform_settings_owner\` FOREIGN KEY (\`platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`

## `platform_exchange_products`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `product_code` | `VARCHAR(80) NOT NULL` |
| `name` | `VARCHAR(150) NOT NULL` |
| `description` | `TEXT` |
| `product_type` | `VARCHAR(40) NOT NULL` |
| `grant_json` | `LONGTEXT NOT NULL` |
| `price_integral` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `stock` | `BIGINT UNSIGNED DEFAULT NULL` |
| `sold_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `per_admin_limit` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `per_admin_daily_limit` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `start_at` | `DATETIME DEFAULT NULL` |
| `end_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |
| `deleted_at` | `DATETIME DEFAULT NULL` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_platform_exchange_product_code\` (\`platform_id\`, \`product_code\`)`
- `UNIQUE KEY \`uk_platform_exchange_product_id_owner\` (\`id\`, \`platform_id\`)`
- `KEY \`idx_platform_exchange_product_status\` (\`platform_id\`, \`status\`, \`sort_order\`)`
- `CONSTRAINT \`fk_platform_exchange_product_owner\` FOREIGN KEY (\`platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`

## `admins`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `account` | `VARCHAR(64) NOT NULL` |
| `password_hash` | `VARCHAR(255) NOT NULL` |
| `nickname` | `VARCHAR(100) NOT NULL DEFAULT ''` |
| `avatar` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `email` | `VARCHAR(190) DEFAULT NULL` |
| `phone` | `VARCHAR(40) DEFAULT NULL` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `register_ip` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `last_login_ip` | `VARCHAR(64) DEFAULT NULL` |
| `last_login_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_admins_platform_account\` (\`platform_id\`, \`account\`)`
- `UNIQUE KEY \`uk_admins_id_platform\` (\`id\`, \`platform_id\`)`
- `KEY \`idx_admins_platform_status\` (\`platform_id\`, \`status\`)`
- `CONSTRAINT \`fk_admins_platform\` FOREIGN KEY (\`platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`

## `admin_tokens`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `issued_by_platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `token_type` | `VARCHAR(30) NOT NULL DEFAULT 'direct'` |
| `token_hash` | `CHAR(64) NOT NULL` |
| `device` | `VARCHAR(100) NOT NULL DEFAULT ''` |
| `ip` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `user_agent` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `expired_at` | `DATETIME NOT NULL` |
| `revoked_at` | `DATETIME DEFAULT NULL` |
| `last_used_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_admin_tokens_hash\` (\`token_hash\`)`
- `KEY \`idx_admin_tokens_admin_expire\` (\`admin_id\`, \`expired_at\`)`
- `CONSTRAINT \`fk_admin_tokens_admin\` FOREIGN KEY (\`admin_id\`) REFERENCES \`admins\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_admin_tokens_platform_issuer\` FOREIGN KEY (\`issued_by_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE SET NULL`

## `admin_login_logs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `account` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `ip` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `user_agent` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `result` | `TINYINT NOT NULL DEFAULT 0` |
| `reason` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_admin_login_admin_time\` (\`admin_id\`, \`created_at\`)`
- `KEY \`idx_admin_login_platform_ip_time\` (\`platform_id\`, \`ip\`, \`created_at\`)`
- `CONSTRAINT \`fk_admin_login_platform\` FOREIGN KEY (\`platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_admin_login_admin\` FOREIGN KEY (\`admin_id\`) REFERENCES \`admins\` (\`id\`) ON DELETE SET NULL`

## `admin_entitlements`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `membership_level` | `VARCHAR(40) NOT NULL DEFAULT 'trial'` |
| `membership_status` | `VARCHAR(20) NOT NULL DEFAULT 'active'` |
| `membership_started_at` | `DATETIME NOT NULL` |
| `membership_expired_at` | `DATETIME NOT NULL` |
| `app_quota` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `remote_document_quota` | `INT UNSIGNED NOT NULL DEFAULT 3` |
| `integral` | `BIGINT NOT NULL DEFAULT 15` |
| `access_start_time` | `TIME DEFAULT NULL` |
| `access_end_time` | `TIME DEFAULT NULL` |
| `allowed_weekdays` | `VARCHAR(30) NOT NULL DEFAULT '1,2,3,4,5,6,7'` |
| `last_granted_by_platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_admin_entitlements_admin\` (\`admin_id\`)`
- `KEY \`idx_admin_entitlements_platform_expire\` (\`platform_id\`, \`membership_expired_at\`)`
- `CONSTRAINT \`fk_admin_entitlements_admin\` FOREIGN KEY (\`admin_id\`, \`platform_id\`) REFERENCES \`admins\` (\`id\`, \`platform_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_admin_entitlements_granter\` FOREIGN KEY (\`last_granted_by_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE SET NULL`

## `admin_permissions`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `permission_code` | `VARCHAR(80) NOT NULL` |
| `allowed` | `TINYINT NOT NULL DEFAULT 1` |
| `config_json` | `LONGTEXT` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_admin_permissions_code\` (\`admin_id\`, \`permission_code\`)`
- `KEY \`idx_admin_permissions_platform\` (\`platform_id\`, \`admin_id\`)`
- `CONSTRAINT \`fk_admin_permissions_admin\` FOREIGN KEY (\`admin_id\`, \`platform_id\`) REFERENCES \`admins\` (\`id\`, \`platform_id\`) ON DELETE CASCADE`

## `admin_registration_logs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `account` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `ip` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `user_agent` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `result` | `TINYINT NOT NULL DEFAULT 0` |
| `reason` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `gift_json` | `LONGTEXT` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_admin_registration_platform_time\` (\`platform_id\`, \`created_at\`)`
- `KEY \`idx_admin_registration_platform_ip\` (\`platform_id\`, \`ip\`, \`created_at\`)`
- `CONSTRAINT \`fk_admin_registration_platform\` FOREIGN KEY (\`platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_admin_registration_admin\` FOREIGN KEY (\`admin_id\`) REFERENCES \`admins\` (\`id\`) ON DELETE SET NULL`

## `admin_entitlement_logs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `actor_platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `change_type` | `VARCHAR(40) NOT NULL` |
| `before_json` | `LONGTEXT` |
| `change_json` | `LONGTEXT` |
| `after_json` | `LONGTEXT` |
| `remark` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_admin_entitlement_logs_admin_time\` (\`admin_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_admin_entitlement_logs_admin\` FOREIGN KEY (\`admin_id\`, \`platform_id\`) REFERENCES \`admins\` (\`id\`, \`platform_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_admin_entitlement_logs_actor\` FOREIGN KEY (\`actor_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE SET NULL`

## `admin_purchase_orders`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `order_no` | `VARCHAR(64) NOT NULL` |
| `purchase_type` | `VARCHAR(40) NOT NULL` |
| `quantity` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `amount` | `DECIMAL(18,2) NOT NULL DEFAULT 0.00` |
| `request_json` | `LONGTEXT` |
| `grant_json` | `LONGTEXT` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `admin_note` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `platform_note` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `handled_by_platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `handled_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_admin_purchase_orders_no\` (\`order_no\`)`
- `KEY \`idx_admin_purchase_platform_status\` (\`platform_id\`, \`status\`, \`created_at\`)`
- `KEY \`idx_admin_purchase_admin\` (\`admin_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_admin_purchase_admin\` FOREIGN KEY (\`admin_id\`, \`platform_id\`) REFERENCES \`admins\` (\`id\`, \`platform_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_admin_purchase_handler\` FOREIGN KEY (\`handled_by_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE SET NULL`

## `admin_platform_feedbacks`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `type` | `VARCHAR(40) NOT NULL DEFAULT 'feedback'` |
| `title` | `VARCHAR(200) NOT NULL` |
| `content` | `LONGTEXT NOT NULL` |
| `images_json` | `LONGTEXT` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `reply_content` | `LONGTEXT` |
| `replied_by_platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `replied_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_admin_platform_feedback_status\` (\`platform_id\`, \`status\`, \`created_at\`)`
- `KEY \`idx_admin_platform_feedback_admin\` (\`admin_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_admin_platform_feedback_admin\` FOREIGN KEY (\`admin_id\`, \`platform_id\`) REFERENCES \`admins\` (\`id\`, \`platform_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_admin_platform_feedback_replier\` FOREIGN KEY (\`replied_by_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE SET NULL`

## `admin_exchange_orders`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `product_id` | `BIGINT UNSIGNED NOT NULL` |
| `order_no` | `VARCHAR(64) NOT NULL` |
| `idempotency_key` | `VARCHAR(100) NOT NULL` |
| `product_code` | `VARCHAR(80) NOT NULL` |
| `product_name` | `VARCHAR(150) NOT NULL` |
| `product_type` | `VARCHAR(40) NOT NULL` |
| `unit_price_integral` | `BIGINT UNSIGNED NOT NULL` |
| `quantity` | `INT UNSIGNED NOT NULL` |
| `total_integral` | `BIGINT UNSIGNED NOT NULL` |
| `grant_json` | `LONGTEXT NOT NULL` |
| `before_entitlement_json` | `LONGTEXT NOT NULL` |
| `after_entitlement_json` | `LONGTEXT NOT NULL` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'completed'` |
| `refunded_by_platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `refund_reason` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `refunded_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_admin_exchange_order_no\` (\`order_no\`)`
- `UNIQUE KEY \`uk_admin_exchange_idempotency\` (\`admin_id\`, \`idempotency_key\`)`
- `KEY \`idx_admin_exchange_platform_time\` (\`platform_id\`, \`created_at\`)`
- `KEY \`idx_admin_exchange_admin_time\` (\`admin_id\`, \`created_at\`)`
- `KEY \`idx_admin_exchange_product_status\` (\`product_id\`, \`status\`)`
- `CONSTRAINT \`fk_admin_exchange_order_admin\` FOREIGN KEY (\`admin_id\`, \`platform_id\`) REFERENCES \`admins\` (\`id\`, \`platform_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_admin_exchange_order_product\` FOREIGN KEY (\`product_id\`, \`platform_id\`) REFERENCES \`platform_exchange_products\` (\`id\`, \`platform_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_admin_exchange_order_refunder\` FOREIGN KEY (\`refunded_by_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE SET NULL`

## `admin_integral_logs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `change_value` | `BIGINT NOT NULL` |
| `before_value` | `BIGINT NOT NULL` |
| `after_value` | `BIGINT NOT NULL` |
| `scene` | `VARCHAR(50) NOT NULL` |
| `ref_type` | `VARCHAR(50) NOT NULL DEFAULT ''` |
| `ref_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `remark` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `actor_platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_admin_integral_logs_admin_time\` (\`admin_id\`, \`created_at\`)`
- `KEY \`idx_admin_integral_logs_platform_scene\` (\`platform_id\`, \`scene\`, \`created_at\`)`
- `KEY \`idx_admin_integral_logs_ref\` (\`ref_type\`, \`ref_id\`)`
- `CONSTRAINT \`fk_admin_integral_logs_admin\` FOREIGN KEY (\`admin_id\`, \`platform_id\`) REFERENCES \`admins\` (\`id\`, \`platform_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_admin_integral_logs_actor\` FOREIGN KEY (\`actor_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE SET NULL`

## `platform_daily_statistics`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `stat_date` | `DATE NOT NULL` |
| `admin_registered` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `admin_login_success` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `admin_login_failed` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `admin_active` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `purchase_created` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `purchase_fulfilled` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `point_exchange_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `point_exchange_integral` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `point_refund_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `point_refund_integral` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_platform_daily_stats\` (\`platform_id\`, \`stat_date\`)`
- `CONSTRAINT \`fk_platform_daily_stats_owner\` FOREIGN KEY (\`platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`

## `apps`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_key` | `VARCHAR(80) NOT NULL` |
| `app_secret_hash` | `CHAR(64) NOT NULL` |
| `name` | `VARCHAR(100) NOT NULL` |
| `logo` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `description` | `TEXT` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `disabled_reason` | `VARCHAR(255) DEFAULT NULL` |
| `version` | `VARCHAR(40) NOT NULL DEFAULT '1.0.0'` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |
| `deleted_at` | `DATETIME DEFAULT NULL` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_apps_app_key\` (\`app_key\`)`
- `UNIQUE KEY \`uk_apps_id_admin\` (\`id\`, \`admin_id\`)`
- `KEY \`idx_apps_admin_status\` (\`admin_id\`, \`status\`)`
- `CONSTRAINT \`fk_apps_admin\` FOREIGN KEY (\`admin_id\`) REFERENCES \`admins\` (\`id\`) ON DELETE CASCADE`

## `app_settings`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `setting_key` | `VARCHAR(64) NOT NULL` |
| `setting_value` | `LONGTEXT` |
| `value_type` | `VARCHAR(20) NOT NULL DEFAULT 'string'` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_app_settings_key\` (\`app_id\`, \`setting_key\`)`
- `KEY \`idx_app_settings_tenant\` (\`admin_id\`, \`app_id\`)`
- `CONSTRAINT \`fk_app_settings_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `admin_operation_logs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `module` | `VARCHAR(50) NOT NULL` |
| `action` | `VARCHAR(50) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `before_json` | `LONGTEXT` |
| `after_json` | `LONGTEXT` |
| `ip` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_admin_ops_tenant_time\` (\`admin_id\`, \`app_id\`, \`created_at\`)`
- `KEY \`idx_admin_ops_module_action\` (\`module\`, \`action\`)`
- `CONSTRAINT \`fk_admin_ops_admin\` FOREIGN KEY (\`admin_id\`) REFERENCES \`admins\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_admin_ops_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `users`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `uid` | `VARCHAR(32) NOT NULL` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `account` | `VARCHAR(64) NOT NULL` |
| `password_hash` | `VARCHAR(255) NOT NULL` |
| `email` | `VARCHAR(190) DEFAULT NULL` |
| `phone` | `VARCHAR(40) DEFAULT NULL` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `register_ip` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `last_login_ip` | `VARCHAR(64) DEFAULT NULL` |
| `last_login_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |
| `deleted_at` | `DATETIME DEFAULT NULL` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_users_uid\` (\`uid\`)`
- `UNIQUE KEY \`uk_users_app_account\` (\`app_id\`, \`account\`)`
- `UNIQUE KEY \`uk_users_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_users_tenant_status\` (\`admin_id\`, \`app_id\`, \`status\`)`
- `KEY \`idx_users_app_email\` (\`app_id\`, \`email\`)`
- `CONSTRAINT \`fk_users_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_profiles`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `nickname` | `VARCHAR(80) NOT NULL DEFAULT ''` |
| `qq` | `VARCHAR(30) NOT NULL DEFAULT ''` |
| `avatar` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `background` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `signature` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `gender` | `VARCHAR(20) NOT NULL DEFAULT ''` |
| `birthday` | `DATE DEFAULT NULL` |
| `region` | `VARCHAR(120) NOT NULL DEFAULT ''` |
| `title` | `VARCHAR(100) NOT NULL DEFAULT ''` |
| `public_profile` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_user_profiles_user\` (\`user_id\`)`
- `KEY \`idx_user_profiles_tenant\` (\`admin_id\`, \`app_id\`)`
- `CONSTRAINT \`fk_user_profiles_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_message_preferences`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `accept_stranger_messages` | `TINYINT NOT NULL DEFAULT 1` |
| `allow_friend_requests` | `TINYINT NOT NULL DEFAULT 1` |
| `system_notification_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `private_notification_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `group_notification_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `profile_notes_visible` | `TINYINT NOT NULL DEFAULT 1` |
| `profile_forum_visible` | `TINYINT NOT NULL DEFAULT 1` |
| `profile_bounties_visible` | `TINYINT NOT NULL DEFAULT 1` |
| `profile_following_visible` | `TINYINT NOT NULL DEFAULT 1` |
| `profile_followers_visible` | `TINYINT NOT NULL DEFAULT 1` |
| `allow_card_add` | `TINYINT NOT NULL DEFAULT 1` |
| `allow_qr_add` | `TINYINT NOT NULL DEFAULT 1` |
| `allow_uid_search` | `TINYINT NOT NULL DEFAULT 1` |
| `allow_phone_search` | `TINYINT NOT NULL DEFAULT 0` |
| `allow_email_search` | `TINYINT NOT NULL DEFAULT 0` |
| `allow_group_member_add` | `TINYINT NOT NULL DEFAULT 1` |
| `allow_group_invitations` | `TINYINT NOT NULL DEFAULT 1` |
| `show_online_status` | `TINYINT NOT NULL DEFAULT 1` |
| `read_receipts_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `room_notification_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `forum_notification_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `bounty_notification_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `mention_notification_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `notification_preview_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `notification_sound_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `notification_vibration_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `remote_login_protection` | `TINYINT NOT NULL DEFAULT 1` |
| `dynamic_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `dynamic_visible_days` | `SMALLINT UNSIGNED NOT NULL DEFAULT 0` |
| `dynamic_visibility_mode` | `VARCHAR(20) NOT NULL DEFAULT 'public'` |
| `dynamic_allow_user_ids_json` | `LONGTEXT DEFAULT NULL` |
| `dynamic_deny_user_ids_json` | `LONGTEXT DEFAULT NULL` |
| `dynamic_visible_to_friends` | `TINYINT NOT NULL DEFAULT 1` |
| `dynamic_visible_to_followers` | `TINYINT NOT NULL DEFAULT 1` |
| `dynamic_visible_to_strangers` | `TINYINT NOT NULL DEFAULT 1` |
| `dynamic_visible_to_hidden_contacts` | `TINYINT NOT NULL DEFAULT 1` |
| `dynamic_visible_to_special_care` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_user_message_preferences_user\` (\`user_id\`)`
- `KEY \`idx_user_message_preferences_tenant\` (\`admin_id\`, \`app_id\`)`
- `CONSTRAINT \`fk_user_message_preferences_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_wallets`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `integral` | `BIGINT NOT NULL DEFAULT 0` |
| `experience` | `BIGINT NOT NULL DEFAULT 0` |
| `balance` | `DECIMAL(18,2) NOT NULL DEFAULT 0.00` |
| `document_credit` | `BIGINT NOT NULL DEFAULT 0` |
| `vip_expired_at` | `DATETIME DEFAULT NULL` |
| `level_code` | `VARCHAR(40) NOT NULL DEFAULT 'normal'` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_user_wallets_user\` (\`user_id\`)`
- `KEY \`idx_user_wallets_tenant\` (\`admin_id\`, \`app_id\`)`
- `CONSTRAINT \`fk_user_wallets_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_wallet_logs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `asset_type` | `VARCHAR(40) NOT NULL` |
| `change_value` | `DECIMAL(20,2) NOT NULL DEFAULT 0.00` |
| `before_value` | `DECIMAL(20,2) NOT NULL DEFAULT 0.00` |
| `after_value` | `DECIMAL(20,2) NOT NULL DEFAULT 0.00` |
| `scene` | `VARCHAR(50) NOT NULL DEFAULT ''` |
| `ref_type` | `VARCHAR(50) NOT NULL DEFAULT ''` |
| `ref_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `remark` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_wallet_logs_user_time\` (\`user_id\`, \`created_at\`)`
- `KEY \`idx_wallet_logs_tenant_scene\` (\`admin_id\`, \`app_id\`, \`scene\`)`
- `CONSTRAINT \`fk_wallet_logs_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_asset_purchases`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `order_no` | `VARCHAR(48) NOT NULL` |
| `product_type` | `ENUM('document_credit','vip_days') NOT NULL` |
| `quantity` | `INT UNSIGNED NOT NULL` |
| `unit_price` | `DECIMAL(18,2) NOT NULL` |
| `total_amount` | `DECIMAL(18,2) NOT NULL` |
| `pay_asset` | `VARCHAR(40) NOT NULL DEFAULT 'balance'` |
| `status` | `ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending'` |
| `completed_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_user_asset_purchases_no\` (\`order_no\`)`
- `KEY \`idx_user_asset_purchases_user\` (\`user_id\`, \`created_at\`)`
- `KEY \`idx_user_asset_purchases_tenant\` (\`admin_id\`, \`app_id\`, \`status\`, \`created_at\`)`
- `CONSTRAINT \`fk_user_asset_purchases_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_transfer_policies`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `can_send` | `TINYINT NOT NULL DEFAULT 1` |
| `can_receive` | `TINYINT NOT NULL DEFAULT 1` |
| `single_limit` | `DECIMAL(18,2) NOT NULL DEFAULT 0.00` |
| `daily_send_limit` | `DECIMAL(18,2) NOT NULL DEFAULT 0.00` |
| `daily_receive_limit` | `DECIMAL(18,2) NOT NULL DEFAULT 0.00` |
| `daily_pair_limit` | `DECIMAL(18,2) NOT NULL DEFAULT 0.00` |
| `blocked_send_to_json` | `LONGTEXT` |
| `blocked_receive_from_json` | `LONGTEXT` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_user_transfer_policies_user\` (\`user_id\`)`
- `KEY \`idx_user_transfer_policies_tenant\` (\`admin_id\`, \`app_id\`)`
- `CONSTRAINT \`fk_user_transfer_policies_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_tokens`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `token_hash` | `CHAR(64) NOT NULL` |
| `device` | `VARCHAR(100) NOT NULL DEFAULT ''` |
| `ip` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `user_agent` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `expired_at` | `DATETIME NOT NULL` |
| `revoked_at` | `DATETIME DEFAULT NULL` |
| `last_used_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_user_tokens_hash\` (\`token_hash\`)`
- `KEY \`idx_user_tokens_user_expire\` (\`user_id\`, \`expired_at\`)`
- `KEY \`idx_user_tokens_tenant\` (\`admin_id\`, \`app_id\`)`
- `CONSTRAINT \`fk_user_tokens_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_login_logs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `ip` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `user_agent` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `result` | `TINYINT NOT NULL DEFAULT 0` |
| `reason` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_user_login_tenant_time\` (\`admin_id\`, \`app_id\`, \`created_at\`)`
- `KEY \`idx_user_login_user_time\` (\`user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_user_login_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_user_login_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `documents`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `owner_type` | `VARCHAR(20) NOT NULL DEFAULT 'user'` |
| `title` | `VARCHAR(200) NOT NULL` |
| `content_type` | `VARCHAR(20) NOT NULL DEFAULT 'text'` |
| `content` | `LONGTEXT` |
| `tags_json` | `LONGTEXT` |
| `word_count` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `is_public` | `TINYINT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `version_no` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |
| `deleted_at` | `DATETIME DEFAULT NULL` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_documents_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_documents_user_time\` (\`app_id\`, \`user_id\`, \`updated_at\`)`
- `KEY \`idx_documents_owner_time\` (\`app_id\`, \`owner_type\`, \`updated_at\`)`
- `KEY \`idx_documents_public_status\` (\`app_id\`, \`is_public\`, \`status\`)`
- `CONSTRAINT \`fk_documents_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_documents_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `document_versions`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `document_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `owner_type` | `VARCHAR(20) NOT NULL DEFAULT 'user'` |
| `title` | `VARCHAR(200) NOT NULL` |
| `content_type` | `VARCHAR(20) NOT NULL DEFAULT 'text'` |
| `content` | `LONGTEXT` |
| `word_count` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `version_no` | `INT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_document_versions_no\` (\`document_id\`, \`version_no\`)`
- `KEY \`idx_document_versions_tenant\` (\`admin_id\`, \`app_id\`)`
- `CONSTRAINT \`fk_document_versions_document\` FOREIGN KEY (\`document_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`documents\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_document_versions_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `card_batches`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `name` | `VARCHAR(100) NOT NULL` |
| `card_type` | `VARCHAR(30) NOT NULL DEFAULT 'mixed'` |
| `value_json` | `LONGTEXT NOT NULL` |
| `total_count` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `used_count` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `max_use` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `expired_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_card_batches_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_card_batches_tenant_status\` (\`admin_id\`, \`app_id\`, \`status\`)`
- `CONSTRAINT \`fk_card_batches_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `cards`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `batch_id` | `BIGINT UNSIGNED NOT NULL` |
| `card_code` | `VARCHAR(64) NOT NULL` |
| `card_type` | `VARCHAR(30) NOT NULL DEFAULT 'mixed'` |
| `value_json` | `LONGTEXT NOT NULL` |
| `max_use` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `used_count` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `expired_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_cards_app_code\` (\`app_id\`, \`card_code\`)`
- `UNIQUE KEY \`uk_cards_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_cards_batch_status\` (\`batch_id\`, \`status\`)`
- `CONSTRAINT \`fk_cards_batch\` FOREIGN KEY (\`batch_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`card_batches\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `card_redeem_logs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `card_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `reward_json` | `LONGTEXT NOT NULL` |
| `ip` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_card_redeem_card_user\` (\`card_id\`, \`user_id\`)`
- `KEY \`idx_card_redeem_tenant_time\` (\`admin_id\`, \`app_id\`, \`created_at\`)`
- `KEY \`idx_card_redeem_user_time\` (\`user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_card_redeem_card\` FOREIGN KEY (\`card_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`cards\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_card_redeem_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `notices`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `title` | `VARCHAR(200) NOT NULL` |
| `content` | `LONGTEXT NOT NULL` |
| `type` | `VARCHAR(30) NOT NULL DEFAULT 'notice'` |
| `is_popup` | `TINYINT NOT NULL DEFAULT 0` |
| `display_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `popup_frequency` | `VARCHAR(20) NOT NULL DEFAULT 'once'` |
| `audience_type` | `VARCHAR(20) NOT NULL DEFAULT 'all'` |
| `audience_json` | `LONGTEXT` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `start_at` | `DATETIME DEFAULT NULL` |
| `end_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |
| `deleted_at` | `DATETIME DEFAULT NULL` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_notices_tenant_status_time\` (\`admin_id\`, \`app_id\`, \`status\`, \`created_at\`)`
- `CONSTRAINT \`fk_notices_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `app_versions`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `version_name` | `VARCHAR(40) NOT NULL` |
| `version_code` | `INT UNSIGNED NOT NULL` |
| `min_supported_version_code` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `apk_url` | `VARCHAR(500) NOT NULL` |
| `package_name` | `VARCHAR(190) NOT NULL DEFAULT ''` |
| `sha256` | `CHAR(64) NOT NULL DEFAULT ''` |
| `size_bytes` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `update_content` | `LONGTEXT NOT NULL` |
| `force_update` | `TINYINT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |
| `deleted_at` | `DATETIME DEFAULT NULL` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_app_versions_code\` (\`app_id\`, \`version_code\`)`
- `KEY \`idx_app_versions_tenant_status\` (\`admin_id\`, \`app_id\`, \`status\`)`
- `CONSTRAINT \`fk_app_versions_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_operation_logs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `module` | `VARCHAR(50) NOT NULL` |
| `action` | `VARCHAR(50) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `detail_json` | `LONGTEXT` |
| `ip` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_user_ops_user_time\` (\`user_id\`, \`created_at\`)`
- `KEY \`idx_user_ops_tenant_module\` (\`admin_id\`, \`app_id\`, \`module\`)`
- `CONSTRAINT \`fk_user_ops_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `api_request_logs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `trace_id` | `VARCHAR(50) NOT NULL` |
| `admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `app_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `actor_type` | `VARCHAR(20) NOT NULL DEFAULT 'public'` |
| `actor_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `method` | `VARCHAR(10) NOT NULL` |
| `path` | `VARCHAR(255) NOT NULL` |
| `http_status` | `SMALLINT UNSIGNED NOT NULL` |
| `result_code` | `INT NOT NULL` |
| `duration_ms` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `ip` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `user_agent` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_api_request_trace\` (\`trace_id\`)`
- `KEY \`idx_api_request_tenant_time\` (\`admin_id\`, \`app_id\`, \`created_at\`)`
- `KEY \`idx_api_request_path_time\` (\`path\`, \`created_at\`)`
- `CONSTRAINT \`fk_api_request_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `statistics_daily`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `stat_date` | `DATE NOT NULL` |
| `new_users` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `user_logins` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `document_created` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `document_updated` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `document_deleted` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `card_redeemed` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `api_requests` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `app_views` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `unique_visitors` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `heartbeat_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_statistics_daily_app_date\` (\`app_id\`, \`stat_date\`)`
- `KEY \`idx_statistics_daily_tenant\` (\`admin_id\`, \`app_id\`)`
- `CONSTRAINT \`fk_statistics_daily_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `system_error_logs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `trace_id` | `VARCHAR(50) NOT NULL` |
| `admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `app_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `path` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `error_class` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `error_message` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `error_file` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `error_line` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_system_errors_trace\` (\`trace_id\`)`
- `KEY \`idx_system_errors_tenant_time\` (\`admin_id\`, \`app_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_system_errors_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `app_feature_flags`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `feature_code` | `VARCHAR(64) NOT NULL` |
| `enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `config_json` | `LONGTEXT` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_app_features_code\` (\`app_id\`, \`feature_code\`)`
- `KEY \`idx_app_features_tenant\` (\`admin_id\`, \`app_id\`)`
- `CONSTRAINT \`fk_app_features_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_feature_permissions`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `feature_code` | `VARCHAR(64) NOT NULL` |
| `enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `config_json` | `LONGTEXT` |
| `updated_by_type` | `VARCHAR(20) NOT NULL DEFAULT 'admin'` |
| `updated_by_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_user_feature_permission\` (\`app_id\`, \`user_id\`, \`feature_code\`)`
- `KEY \`idx_user_feature_permission_tenant\` (\`admin_id\`, \`app_id\`, \`user_id\`)`
- `CONSTRAINT \`fk_user_feature_permission_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `app_domains`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `domain` | `VARCHAR(255) NOT NULL` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_app_domains_domain\` (\`app_id\`, \`domain\`)`
- `KEY \`idx_app_domains_tenant\` (\`admin_id\`, \`app_id\`)`
- `CONSTRAINT \`fk_app_domains_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `app_api_keys`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `key_name` | `VARCHAR(100) NOT NULL` |
| `key_hash` | `CHAR(64) NOT NULL` |
| `scopes_json` | `LONGTEXT` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `expired_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_app_api_keys_hash\` (\`key_hash\`)`
- `KEY \`idx_app_api_keys_tenant\` (\`admin_id\`, \`app_id\`, \`status\`)`
- `CONSTRAINT \`fk_app_api_keys_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_refresh_tokens`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_token_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `token_hash` | `CHAR(64) NOT NULL` |
| `expired_at` | `DATETIME NOT NULL` |
| `revoked_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_user_refresh_hash\` (\`token_hash\`)`
- `KEY \`idx_user_refresh_user_expire\` (\`user_id\`, \`expired_at\`)`
- `CONSTRAINT \`fk_user_refresh_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_user_refresh_access\` FOREIGN KEY (\`user_token_id\`) REFERENCES \`user_tokens\` (\`id\`) ON DELETE SET NULL`

## `user_bans`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `ban_type` | `VARCHAR(40) NOT NULL DEFAULT 'all'` |
| `reason` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `start_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `end_at` | `DATETIME DEFAULT NULL` |
| `operator_admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_user_bans_user_status\` (\`user_id\`, \`status\`, \`end_at\`)`
- `KEY \`idx_user_bans_tenant\` (\`admin_id\`, \`app_id\`)`
- `CONSTRAINT \`fk_user_bans_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_user_bans_operator\` FOREIGN KEY (\`operator_admin_id\`) REFERENCES \`admins\` (\`id\`) ON DELETE CASCADE`

## `user_tags`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `name` | `VARCHAR(50) NOT NULL` |
| `color` | `VARCHAR(20) NOT NULL DEFAULT '#64748b'` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_user_tags_name\` (\`app_id\`, \`name\`)`
- `UNIQUE KEY \`uk_user_tags_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `CONSTRAINT \`fk_user_tags_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_tag_relations`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `tag_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_user_tag_relation\` (\`user_id\`, \`tag_id\`)`
- `KEY \`idx_user_tag_rel_tenant\` (\`admin_id\`, \`app_id\`)`
- `CONSTRAINT \`fk_user_tag_rel_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_user_tag_rel_tag\` FOREIGN KEY (\`tag_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`user_tags\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_sign_logs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `sign_date` | `DATE NOT NULL` |
| `reward_integral` | `BIGINT NOT NULL DEFAULT 0` |
| `reward_experience` | `BIGINT NOT NULL DEFAULT 0` |
| `reward_credit` | `BIGINT NOT NULL DEFAULT 0` |
| `continuous_days` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_user_sign_date\` (\`app_id\`, \`user_id\`, \`sign_date\`)`
- `KEY \`idx_user_sign_tenant\` (\`admin_id\`, \`app_id\`)`
- `CONSTRAINT \`fk_user_sign_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `invite_codes`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `invite_code` | `VARCHAR(32) NOT NULL` |
| `max_use` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `used_count` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `reward_json` | `LONGTEXT` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `expired_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_invite_codes_code\` (\`app_id\`, \`invite_code\`)`
- `KEY \`idx_invite_codes_user\` (\`user_id\`, \`status\`)`
- `CONSTRAINT \`fk_invite_codes_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `invite_relations`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `invite_code` | `VARCHAR(32) NOT NULL` |
| `inviter_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `invited_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `reward_status` | `TINYINT NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_invite_rel_invited\` (\`invited_user_id\`)`
- `KEY \`idx_invite_rel_inviter\` (\`inviter_user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_invite_rel_inviter\` FOREIGN KEY (\`inviter_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_invite_rel_invited\` FOREIGN KEY (\`invited_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `verification_codes`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `scene` | `VARCHAR(40) NOT NULL` |
| `target` | `VARCHAR(190) NOT NULL` |
| `code_hash` | `CHAR(64) NOT NULL` |
| `payload_json` | `LONGTEXT` |
| `attempts` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `used_at` | `DATETIME DEFAULT NULL` |
| `expired_at` | `DATETIME NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_verification_lookup\` (\`app_id\`, \`scene\`, \`target\`, \`created_at\`)`
- `CONSTRAINT \`fk_verification_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `identity_bindings`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `subject_type` | `VARCHAR(20) NOT NULL` |
| `subject_id` | `BIGINT UNSIGNED NOT NULL` |
| `platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `app_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `identity_type` | `VARCHAR(20) NOT NULL` |
| `identity_value` | `VARCHAR(190) NOT NULL` |
| `identity_hash` | `CHAR(64) NOT NULL` |
| `verified_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_identity_bindings_value\` (\`identity_type\`, \`identity_hash\`)`
- `UNIQUE KEY \`uk_identity_bindings_subject\` (\`subject_type\`, \`subject_id\`, \`identity_type\`)`
- `KEY \`idx_identity_bindings_tenant\` (\`platform_id\`, \`admin_id\`, \`app_id\`)`

## `identity_unbind_requests`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `subject_type` | `VARCHAR(20) NOT NULL` |
| `subject_id` | `BIGINT UNSIGNED NOT NULL` |
| `platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `app_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `identity_type` | `VARCHAR(20) NOT NULL` |
| `identity_value` | `VARCHAR(190) NOT NULL` |
| `reviewer_type` | `VARCHAR(20) NOT NULL` |
| `reviewer_id` | `BIGINT UNSIGNED NOT NULL` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `reason` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `review_remark` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `reviewed_by_type` | `VARCHAR(20) DEFAULT NULL` |
| `reviewed_by_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `review_mode` | `VARCHAR(20) DEFAULT NULL` |
| `reviewed_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_identity_unbind_reviewer\` (\`reviewer_type\`, \`reviewer_id\`, \`status\`, \`id\`)`
- `KEY \`idx_identity_unbind_subject\` (\`subject_type\`, \`subject_id\`, \`status\`, \`id\`)`

## `document_folders`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `parent_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `name` | `VARCHAR(100) NOT NULL` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_doc_folders_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_doc_folders_user_parent\` (\`user_id\`, \`parent_id\`)`
- `CONSTRAINT \`fk_doc_folders_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_doc_folders_parent\` FOREIGN KEY (\`parent_id\`) REFERENCES \`document_folders\` (\`id\`) ON DELETE SET NULL`

## `document_folder_items`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `folder_id` | `BIGINT UNSIGNED NOT NULL` |
| `document_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_doc_folder_item_document\` (\`document_id\`)`
- `KEY \`idx_doc_folder_items_folder\` (\`folder_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_doc_folder_items_folder\` FOREIGN KEY (\`folder_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`document_folders\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_doc_folder_items_document\` FOREIGN KEY (\`document_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`documents\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_doc_folder_items_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `document_shares`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `document_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `share_code` | `VARCHAR(48) NOT NULL` |
| `password_hash` | `VARCHAR(255) DEFAULT NULL` |
| `expired_at` | `DATETIME DEFAULT NULL` |
| `view_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_document_shares_code\` (\`share_code\`)`
- `UNIQUE KEY \`uk_document_shares_document_fixed\` (\`document_id\`)`
- `KEY \`idx_document_shares_document\` (\`document_id\`, \`status\`)`
- `CONSTRAINT \`fk_document_shares_document\` FOREIGN KEY (\`document_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`documents\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_document_shares_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `document_quota_logs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `change_value` | `BIGINT NOT NULL` |
| `before_value` | `BIGINT NOT NULL` |
| `after_value` | `BIGINT NOT NULL` |
| `scene` | `VARCHAR(50) NOT NULL` |
| `ref_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_document_quota_user_time\` (\`user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_document_quota_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `banners`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `title` | `VARCHAR(200) NOT NULL DEFAULT ''` |
| `image_url` | `VARCHAR(500) NOT NULL` |
| `link_url` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `position` | `VARCHAR(40) NOT NULL DEFAULT 'home'` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `start_at` | `DATETIME DEFAULT NULL` |
| `end_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_banners_app_position\` (\`app_id\`, \`position\`, \`status\`, \`sort_order\`)`
- `CONSTRAINT \`fk_banners_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `remote_configs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `config_key` | `VARCHAR(100) NOT NULL` |
| `config_value` | `LONGTEXT` |
| `value_type` | `VARCHAR(20) NOT NULL DEFAULT 'string'` |
| `description` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_remote_configs_key\` (\`app_id\`, \`config_key\`)`
- `KEY \`idx_remote_configs_tenant\` (\`admin_id\`, \`app_id\`)`
- `CONSTRAINT \`fk_remote_configs_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `resource_categories`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `resource_type` | `VARCHAR(30) NOT NULL DEFAULT 'app_store' COMMENT 'app_store/source_market'` |
| `name` | `VARCHAR(100) NOT NULL` |
| `icon` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `description` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_resource_categories_name\` (\`app_id\`, \`resource_type\`, \`name\`)`
- `UNIQUE KEY \`uk_resource_categories_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `CONSTRAINT \`fk_resource_categories_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `resources`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `resource_type` | `VARCHAR(30) NOT NULL DEFAULT 'app_store' COMMENT 'app_store/source_market'` |
| `category_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `title` | `VARCHAR(200) NOT NULL` |
| `description` | `LONGTEXT` |
| `cover_url` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `download_url` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `size_bytes` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `file_sha256` | `CHAR(64) NOT NULL DEFAULT ''` |
| `risk_level` | `VARCHAR(20) NOT NULL DEFAULT 'review'` |
| `risk_reason` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `source_upload_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `cover_upload_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `metadata_json` | `LONGTEXT` |
| `tags_json` | `LONGTEXT` |
| `images_json` | `LONGTEXT` |
| `attachments_json` | `LONGTEXT` |
| `price_integral` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `price_money` | `DECIMAL(18,2) NOT NULL DEFAULT 0.00` |
| `audit_status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `audit_reason` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `is_top` | `TINYINT NOT NULL DEFAULT 0` |
| `is_recommended` | `TINYINT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `view_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `download_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |
| `deleted_at` | `DATETIME DEFAULT NULL` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_resources_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_resources_category_status\` (\`app_id\`, \`category_id\`, \`audit_status\`, \`status\`)`
- `KEY \`idx_resources_type_status\` (\`app_id\`, \`resource_type\`, \`audit_status\`, \`status\`, \`created_at\`)`
- `KEY \`idx_resources_risk\` (\`app_id\`, \`risk_level\`, \`audit_status\`)`
- `KEY \`idx_resources_user\` (\`user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_resources_category\` FOREIGN KEY (\`category_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`resource_categories\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_resources_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `resource_files`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `resource_id` | `BIGINT UNSIGNED NOT NULL` |
| `file_name` | `VARCHAR(255) NOT NULL` |
| `file_url` | `VARCHAR(1000) NOT NULL` |
| `file_size` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `file_type` | `VARCHAR(100) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_resource_files_resource\` (\`resource_id\`)`
- `CONSTRAINT \`fk_resource_files_resource\` FOREIGN KEY (\`resource_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`resources\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `resource_comments`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `resource_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `parent_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `content` | `TEXT NOT NULL` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_resource_comments_resource\` (\`resource_id\`, \`status\`, \`created_at\`)`
- `CONSTRAINT \`fk_resource_comments_resource\` FOREIGN KEY (\`resource_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`resources\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_resource_comments_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_resource_comments_parent\` FOREIGN KEY (\`parent_id\`) REFERENCES \`resource_comments\` (\`id\`) ON DELETE CASCADE`

## `resource_ratings`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `resource_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `score` | `TINYINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_resource_ratings_user\` (\`resource_id\`, \`user_id\`)`
- `CONSTRAINT \`fk_resource_ratings_resource\` FOREIGN KEY (\`resource_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`resources\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_resource_ratings_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `resource_purchases`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `resource_id` | `BIGINT UNSIGNED NOT NULL` |
| `buyer_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `seller_user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `price_integral` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_resource_purchase_buyer\` (\`resource_id\`, \`buyer_user_id\`)`
- `CONSTRAINT \`fk_resource_purchase_resource\` FOREIGN KEY (\`resource_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`resources\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_resource_purchase_buyer\` FOREIGN KEY (\`buyer_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_resource_purchase_seller\` FOREIGN KEY (\`seller_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `store_categories`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `name` | `VARCHAR(100) NOT NULL` |
| `icon` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_store_categories_name\` (\`app_id\`, \`name\`)`
- `UNIQUE KEY \`uk_store_categories_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `CONSTRAINT \`fk_store_categories_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `store_apps`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `category_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `name` | `VARCHAR(150) NOT NULL` |
| `package_name` | `VARCHAR(190) NOT NULL` |
| `version_name` | `VARCHAR(40) NOT NULL` |
| `version_code` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `icon_url` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `apk_url` | `VARCHAR(1000) NOT NULL` |
| `size_bytes` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `description` | `LONGTEXT` |
| `metadata_json` | `LONGTEXT` |
| `file_sha256` | `CHAR(64) NOT NULL DEFAULT ''` |
| `risk_level` | `VARCHAR(20) NOT NULL DEFAULT 'review'` |
| `risk_reason` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `source_upload_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `icon_upload_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `audit_status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `audit_reason` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `price_integral` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `download_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |
| `deleted_at` | `DATETIME DEFAULT NULL` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_store_apps_package_version\` (\`app_id\`, \`package_name\`, \`version_code\`)`
- `UNIQUE KEY \`uk_store_apps_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_store_apps_category_status\` (\`app_id\`, \`category_id\`, \`status\`)`
- `KEY \`idx_store_apps_audit\` (\`app_id\`, \`audit_status\`, \`risk_level\`, \`status\`)`
- `CONSTRAINT \`fk_store_apps_category\` FOREIGN KEY (\`category_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`store_categories\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_store_apps_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `store_app_images`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `store_app_id` | `BIGINT UNSIGNED NOT NULL` |
| `image_url` | `VARCHAR(1000) NOT NULL` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_store_app_images_app\` (\`store_app_id\`, \`sort_order\`)`
- `CONSTRAINT \`fk_store_app_images_app\` FOREIGN KEY (\`store_app_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`store_apps\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `forum_plates`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `name` | `VARCHAR(100) NOT NULL` |
| `icon` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `description` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_forum_plates_name\` (\`app_id\`, \`name\`)`
- `UNIQUE KEY \`uk_forum_plates_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `CONSTRAINT \`fk_forum_plates_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `forum_categories`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `plate_id` | `BIGINT UNSIGNED NOT NULL` |
| `name` | `VARCHAR(100) NOT NULL` |
| `description` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `icon` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_forum_categories_name\` (\`app_id\`, \`plate_id\`, \`name\`)`
- `UNIQUE KEY \`uk_forum_categories_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_forum_categories_plate\` (\`app_id\`, \`plate_id\`, \`status\`, \`sort_order\`)`
- `CONSTRAINT \`fk_forum_categories_plate\` FOREIGN KEY (\`plate_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`forum_plates\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `forum_tags`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `plate_id` | `BIGINT UNSIGNED NOT NULL` |
| `category_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `name` | `VARCHAR(80) NOT NULL` |
| `aliases_json` | `LONGTEXT` |
| `description` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_forum_tags_name\` (\`app_id\`, \`plate_id\`, \`name\`)`
- `KEY \`idx_forum_tags_category\` (\`app_id\`, \`plate_id\`, \`category_id\`, \`status\`, \`sort_order\`)`
- `CONSTRAINT \`fk_forum_tags_plate\` FOREIGN KEY (\`plate_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`forum_plates\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_tags_category\` FOREIGN KEY (\`category_id\`) REFERENCES \`forum_categories\` (\`id\`) ON DELETE SET NULL`

## `forum_structure_requests`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `request_type` | `VARCHAR(20) NOT NULL` |
| `plate_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `category_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `name` | `VARCHAR(100) NOT NULL` |
| `aliases_json` | `LONGTEXT` |
| `description` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `reason` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `reviewer_admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `review_comment` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `reviewed_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_forum_structure_requests_status\` (\`app_id\`, \`status\`, \`request_type\`, \`created_at\`)`
- `KEY \`idx_forum_structure_requests_user\` (\`user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_forum_structure_requests_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_structure_requests_plate\` FOREIGN KEY (\`plate_id\`) REFERENCES \`forum_plates\` (\`id\`) ON DELETE SET NULL`
- `CONSTRAINT \`fk_forum_structure_requests_category\` FOREIGN KEY (\`category_id\`) REFERENCES \`forum_categories\` (\`id\`) ON DELETE SET NULL`
- `CONSTRAINT \`fk_forum_structure_requests_reviewer\` FOREIGN KEY (\`reviewer_admin_id\`) REFERENCES \`admins\` (\`id\`) ON DELETE SET NULL`

## `forum_moderators`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `plate_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `permissions_json` | `LONGTEXT` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `granted_by_admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_forum_moderators_plate_user\` (\`plate_id\`, \`user_id\`)`
- `KEY \`idx_forum_moderators_user\` (\`admin_id\`, \`app_id\`, \`user_id\`, \`status\`)`
- `CONSTRAINT \`fk_forum_moderators_plate\` FOREIGN KEY (\`plate_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`forum_plates\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_moderators_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_moderators_granter\` FOREIGN KEY (\`granted_by_admin_id\`) REFERENCES \`admins\` (\`id\`) ON DELETE SET NULL`

## `forum_posts`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `plate_id` | `BIGINT UNSIGNED NOT NULL` |
| `category_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `title` | `VARCHAR(200) NOT NULL` |
| `content` | `LONGTEXT NOT NULL` |
| `images_json` | `LONGTEXT` |
| `tags_json` | `LONGTEXT` |
| `is_top` | `TINYINT NOT NULL DEFAULT 0` |
| `is_essence` | `TINYINT NOT NULL DEFAULT 0` |
| `is_locked` | `TINYINT NOT NULL DEFAULT 0` |
| `audit_status` | `VARCHAR(20) NOT NULL DEFAULT 'approved'` |
| `audit_reason` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `audited_by` | `BIGINT UNSIGNED DEFAULT NULL` |
| `audited_at` | `DATETIME DEFAULT NULL` |
| `view_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `unique_view_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `like_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `comment_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `heat_score` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `hot_label` | `VARCHAR(40) NOT NULL DEFAULT ''` |
| `last_activity_at` | `DATETIME DEFAULT NULL` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |
| `deleted_at` | `DATETIME DEFAULT NULL` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_forum_posts_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_forum_posts_plate_order\` (\`app_id\`, \`plate_id\`, \`is_top\`, \`created_at\`)`
- `KEY \`idx_forum_posts_category_order\` (\`app_id\`, \`category_id\`, \`created_at\`)`
- `KEY \`idx_forum_posts_user\` (\`user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_forum_posts_plate\` FOREIGN KEY (\`plate_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`forum_plates\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_posts_category\` FOREIGN KEY (\`category_id\`) REFERENCES \`forum_categories\` (\`id\`) ON DELETE SET NULL`
- `CONSTRAINT \`fk_forum_posts_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `,CONSTRAINT \`fk_forum_posts_auditor\` FOREIGN KEY (\`audited_by\`) REFERENCES \`admins\` (\`id\`) ON DELETE SET NULL`

## `forum_comments`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `post_id` | `BIGINT UNSIGNED NOT NULL` |
| `parent_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `root_comment_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `content` | `TEXT NOT NULL` |
| `tags_json` | `LONGTEXT` |
| `is_pinned` | `TINYINT NOT NULL DEFAULT 0` |
| `pin_order` | `INT NOT NULL DEFAULT 0` |
| `like_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `favorite_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `audit_status` | `VARCHAR(20) NOT NULL DEFAULT 'approved'` |
| `audit_reason` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `audited_by` | `BIGINT UNSIGNED DEFAULT NULL` |
| `audited_at` | `DATETIME DEFAULT NULL` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_forum_comments_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_forum_comments_post\` (\`post_id\`, \`status\`, \`created_at\`)`
- `KEY \`idx_forum_comments_root\` (\`post_id\`, \`root_comment_id\`, \`status\`, \`id\`)`
- `CONSTRAINT \`fk_forum_comments_post\` FOREIGN KEY (\`post_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`forum_posts\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_comments_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_comments_parent\` FOREIGN KEY (\`parent_id\`) REFERENCES \`forum_comments\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_comments_auditor\` FOREIGN KEY (\`audited_by\`) REFERENCES \`admins\` (\`id\`) ON DELETE SET NULL`

## `forum_likes`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `target_type` | `VARCHAR(20) NOT NULL DEFAULT 'post'` |
| `target_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_forum_likes_target_user\` (\`app_id\`, \`user_id\`, \`target_type\`, \`target_id\`)`
- `CONSTRAINT \`fk_forum_likes_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `forum_favorites`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `post_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_forum_favorites_post_user\` (\`app_id\`, \`post_id\`, \`user_id\`)`
- `CONSTRAINT \`fk_forum_favorites_post\` FOREIGN KEY (\`post_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`forum_posts\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_favorites_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `forum_reports`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `target_type` | `VARCHAR(20) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `report_tag_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `reason` | `VARCHAR(1000) NOT NULL` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `handled_by` | `BIGINT UNSIGNED DEFAULT NULL` |
| `handled_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_forum_reports_tenant_status\` (\`admin_id\`, \`app_id\`, \`status\`)`
- `CONSTRAINT \`fk_forum_reports_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_reports_admin\` FOREIGN KEY (\`handled_by\`) REFERENCES \`admins\` (\`id\`) ON DELETE SET NULL`

## `content_reports`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `target_type` | `VARCHAR(40) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED NOT NULL` |
| `reason` | `VARCHAR(1000) NOT NULL` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `reply` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `handled_by` | `BIGINT UNSIGNED DEFAULT NULL` |
| `handled_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_content_reports_tenant_status\` (\`admin_id\`, \`app_id\`, \`status\`)`
- `CONSTRAINT \`fk_content_reports_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_content_reports_admin\` FOREIGN KEY (\`handled_by\`) REFERENCES \`admins\` (\`id\`) ON DELETE SET NULL`

## `conversations`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `type` | `VARCHAR(20) NOT NULL DEFAULT 'private'` |
| `user_a_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_b_id` | `BIGINT UNSIGNED NOT NULL` |
| `last_message_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `last_message_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_conversations_private\` (\`app_id\`, \`type\`, \`user_a_id\`, \`user_b_id\`)`
- `UNIQUE KEY \`uk_conversations_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_conversations_user_a\` (\`user_a_id\`, \`last_message_at\`)`
- `KEY \`idx_conversations_user_b\` (\`user_b_id\`, \`last_message_at\`)`
- `CONSTRAINT \`fk_conversations_user_a\` FOREIGN KEY (\`user_a_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_conversations_user_b\` FOREIGN KEY (\`user_b_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `voice_calls`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `caller_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `callee_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `conversation_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `private_message_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `room_message_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `context_type` | `VARCHAR(20) NOT NULL DEFAULT 'private'` |
| `context_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `context_name` | `VARCHAR(120) NOT NULL DEFAULT ''` |
| `call_type` | `VARCHAR(20) NOT NULL DEFAULT 'audio'` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'ringing'` |
| `started_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `answered_at` | `DATETIME DEFAULT NULL` |
| `ended_at` | `DATETIME DEFAULT NULL` |
| `expires_at` | `DATETIME NOT NULL` |
| `ended_by_user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `duration_seconds` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_voice_calls_caller_status\` (\`app_id\`, \`caller_user_id\`, \`status\`, \`created_at\`)`
- `KEY \`idx_voice_calls_callee_status\` (\`app_id\`, \`callee_user_id\`, \`status\`, \`created_at\`)`
- `KEY \`idx_voice_calls_expiry\` (\`status\`, \`expires_at\`)`
- `KEY \`idx_voice_calls_context\` (\`app_id\`, \`context_type\`, \`context_id\`, \`created_at\`)`
- `KEY \`idx_voice_calls_private_message\` (\`private_message_id\`)`
- `KEY \`idx_voice_calls_room_message\` (\`room_message_id\`)`
- `CONSTRAINT \`fk_voice_calls_caller\` FOREIGN KEY (\`caller_user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_voice_calls_callee\` FOREIGN KEY (\`callee_user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_voice_calls_conversation\` FOREIGN KEY (\`conversation_id\`)`
- `REFERENCES \`conversations\` (\`id\`) ON DELETE SET NULL`
- `CONSTRAINT \`fk_voice_calls_ended_by\` FOREIGN KEY (\`ended_by_user_id\`)`
- `REFERENCES \`users\` (\`id\`) ON DELETE SET NULL`

## `voice_call_signals`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `call_id` | `BIGINT UNSIGNED NOT NULL` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `from_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `signal_type` | `VARCHAR(20) NOT NULL` |
| `payload_json` | `LONGTEXT NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_voice_call_signals_call\` (\`call_id\`, \`id\`)`
- `KEY \`idx_voice_call_signals_sender\` (\`from_user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_voice_call_signals_call\` FOREIGN KEY (\`call_id\`) REFERENCES \`voice_calls\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_voice_call_signals_sender\` FOREIGN KEY (\`from_user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `messages`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `conversation_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `sender_type` | `VARCHAR(20) NOT NULL` |
| `sender_id` | `BIGINT UNSIGNED NOT NULL` |
| `receiver_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `title` | `VARCHAR(200) NOT NULL DEFAULT ''` |
| `content_type` | `VARCHAR(20) NOT NULL DEFAULT 'text'` |
| `content` | `LONGTEXT NOT NULL` |
| `tags_json` | `LONGTEXT` |
| `reply_to_message_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `is_read` | `TINYINT NOT NULL DEFAULT 0` |
| `read_at` | `DATETIME DEFAULT NULL` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_messages_conversation\` (\`conversation_id\`, \`id\`)`
- `KEY \`idx_messages_receiver_unread\` (\`receiver_user_id\`, \`is_read\`, \`created_at\`)`
- `KEY \`idx_messages_reply\` (\`reply_to_message_id\`)`
- `CONSTRAINT \`fk_messages_conversation\` FOREIGN KEY (\`conversation_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`conversations\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_messages_receiver\` FOREIGN KEY (\`receiver_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_messages_reply\` FOREIGN KEY (\`reply_to_message_id\`) REFERENCES \`messages\` (\`id\`) ON DELETE SET NULL`

## `message_edit_histories`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `channel_type` | `VARCHAR(20) NOT NULL` |
| `channel_id` | `BIGINT UNSIGNED NOT NULL` |
| `message_id` | `BIGINT UNSIGNED NOT NULL` |
| `editor_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `old_content` | `LONGTEXT NOT NULL` |
| `new_content` | `LONGTEXT NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_message_edit_histories_message\` (\`app_id\`, \`channel_type\`, \`message_id\`, \`id\`)`
- `KEY \`idx_message_edit_histories_channel\` (\`app_id\`, \`channel_type\`, \`channel_id\`, \`created_at\`)`
- `KEY \`idx_message_edit_histories_editor\` (\`editor_user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_message_edit_histories_editor\` FOREIGN KEY (\`editor_user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `friend_requests`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `from_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `to_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `message` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `requester_remark` | `VARCHAR(100) NOT NULL DEFAULT ''` |
| `requester_group_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `hide_my_dynamic` | `TINYINT NOT NULL DEFAULT 0` |
| `hide_their_dynamic` | `TINYINT NOT NULL DEFAULT 0` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `decision_reason` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `ignore_reason` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `ignored_at` | `DATETIME DEFAULT NULL` |
| `expired_at` | `DATETIME NOT NULL` |
| `handled_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_friend_requests_pair\` (\`app_id\`, \`from_user_id\`, \`to_user_id\`, \`status\`)`
- `KEY \`idx_friend_requests_to\` (\`to_user_id\`, \`status\`, \`created_at\`)`
- `CONSTRAINT \`fk_friend_requests_from\` FOREIGN KEY (\`from_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_friend_requests_to\` FOREIGN KEY (\`to_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `friends`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `friend_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `remark` | `VARCHAR(100) NOT NULL DEFAULT ''` |
| `special_care` | `TINYINT NOT NULL DEFAULT 0` |
| `relationship_label` | `VARCHAR(60) NOT NULL DEFAULT ''` |
| `clue_note` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `only_chat` | `TINYINT NOT NULL DEFAULT 0` |
| `hide_my_notes` | `TINYINT NOT NULL DEFAULT 0` |
| `hide_their_notes` | `TINYINT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_friends_pair\` (\`app_id\`, \`user_id\`, \`friend_user_id\`)`
- `KEY \`idx_friends_user_status\` (\`user_id\`, \`status\`)`
- `CONSTRAINT \`fk_friends_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_friends_friend\` FOREIGN KEY (\`friend_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `friend_groups`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `name` | `VARCHAR(60) NOT NULL` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_friend_groups_name\` (\`app_id\`, \`user_id\`, \`name\`)`
- `CONSTRAINT \`fk_friend_groups_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `friend_group_members`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `friend_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `group_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_friend_group_members_friend\` (\`app_id\`, \`user_id\`, \`friend_user_id\`)`
- `KEY \`idx_friend_group_members_group\` (\`group_id\`, \`user_id\`)`
- `CONSTRAINT \`fk_friend_group_members_group\` FOREIGN KEY (\`group_id\`) REFERENCES \`friend_groups\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_friend_group_members_friend\` FOREIGN KEY (\`friend_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `chat_rooms`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `name` | `VARCHAR(100) NOT NULL` |
| `icon` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `description` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `tags_json` | `LONGTEXT` |
| `room_kind` | `VARCHAR(20) NOT NULL DEFAULT 'group' COMMENT 'group/chat_room'` |
| `is_public` | `TINYINT NOT NULL DEFAULT 1` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `dissolved_at` | `DATETIME DEFAULT NULL` |
| `restore_until` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_chat_rooms_name\` (\`app_id\`, \`name\`)`
- `UNIQUE KEY \`uk_chat_rooms_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_chat_rooms_kind\` (\`app_id\`, \`room_kind\`, \`status\`, \`created_at\`)`
- `CONSTRAINT \`fk_chat_rooms_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `chat_room_members`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `room_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `role` | `VARCHAR(20) NOT NULL DEFAULT 'member'` |
| `mute_until` | `DATETIME DEFAULT NULL` |
| `joined_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `history_visible_from` | `DATETIME DEFAULT NULL` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_chat_room_members_user\` (\`room_id\`, \`user_id\`)`
- `CONSTRAINT \`fk_chat_room_members_room\` FOREIGN KEY (\`room_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`chat_rooms\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_members_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `chat_room_user_groups`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `name` | `VARCHAR(60) NOT NULL` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_chat_room_user_groups_name\` (\`app_id\`, \`user_id\`, \`name\`)`
- `CONSTRAINT \`fk_chat_room_user_groups_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `chat_room_user_settings`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `room_id` | `BIGINT UNSIGNED NOT NULL` |
| `group_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `remark` | `VARCHAR(100) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_chat_room_user_settings_room\` (\`app_id\`, \`user_id\`, \`room_id\`)`
- `KEY \`idx_chat_room_user_settings_group\` (\`group_id\`, \`user_id\`)`
- `CONSTRAINT \`fk_chat_room_user_settings_room\` FOREIGN KEY (\`room_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`chat_rooms\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_user_settings_group\` FOREIGN KEY (\`group_id\`) REFERENCES \`chat_room_user_groups\` (\`id\`) ON DELETE SET NULL`

## `chat_room_messages`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `room_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `sender_type` | `VARCHAR(20) NOT NULL DEFAULT 'user'` |
| `sender_admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `content_type` | `VARCHAR(20) NOT NULL DEFAULT 'text'` |
| `content` | `LONGTEXT NOT NULL` |
| `tags_json` | `LONGTEXT` |
| `reply_to_message_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_chat_room_messages_room\` (\`room_id\`, \`id\`)`
- `KEY \`idx_chat_room_messages_sender_admin\` (\`sender_admin_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_chat_room_messages_room\` FOREIGN KEY (\`room_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`chat_rooms\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_messages_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_messages_sender_admin\` FOREIGN KEY (\`sender_admin_id\`) REFERENCES \`admins\` (\`id\`) ON DELETE SET NULL`
- `CONSTRAINT \`fk_chat_room_messages_reply\` FOREIGN KEY (\`reply_to_message_id\`) REFERENCES \`chat_room_messages\` (\`id\`) ON DELETE SET NULL`

## `chat_room_policies`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `room_id` | `BIGINT UNSIGNED NOT NULL` |
| `owner_user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `join_mode` | `VARCHAR(20) NOT NULL DEFAULT 'open'` |
| `max_members` | `INT UNSIGNED NOT NULL DEFAULT 500` |
| `allow_member_invite` | `TINYINT NOT NULL DEFAULT 1` |
| `mute_all` | `TINYINT NOT NULL DEFAULT 0` |
| `announcement` | `VARCHAR(2000) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_chat_room_policies_room\` (\`room_id\`)`
- `KEY \`idx_chat_room_policies_owner\` (\`app_id\`, \`owner_user_id\`)`
- `CONSTRAINT \`fk_chat_room_policies_room\` FOREIGN KEY (\`room_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`chat_rooms\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_policies_owner\` FOREIGN KEY (\`owner_user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE SET NULL`

## `chat_room_invitations`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `room_id` | `BIGINT UNSIGNED NOT NULL` |
| `inviter_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `invitee_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `message` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `share_history` | `TINYINT(1) NOT NULL DEFAULT 1` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `decision_reason` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `ignore_reason` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `ignored_at` | `DATETIME DEFAULT NULL` |
| `expired_at` | `DATETIME DEFAULT NULL` |
| `responded_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_chat_room_invitations_target\` (\`room_id\`, \`invitee_user_id\`)`
- `KEY \`idx_chat_room_invitations_user\` (\`app_id\`, \`invitee_user_id\`, \`status\`, \`created_at\`)`
- `CONSTRAINT \`fk_chat_room_invitations_room\` FOREIGN KEY (\`room_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`chat_rooms\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_invitations_inviter\` FOREIGN KEY (\`inviter_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_invitations_invitee\` FOREIGN KEY (\`invitee_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `chat_room_join_requests`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `room_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `message` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `decision_reason` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `ignore_reason` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `ignored_at` | `DATETIME DEFAULT NULL` |
| `expired_at` | `DATETIME NOT NULL` |
| `handled_by_user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `handled_by_admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `handled_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_chat_room_join_requests_user\` (\`room_id\`, \`user_id\`)`
- `KEY \`idx_chat_room_join_requests_room\` (\`room_id\`, \`status\`, \`created_at\`)`
- `CONSTRAINT \`fk_chat_room_join_requests_room\` FOREIGN KEY (\`room_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`chat_rooms\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_join_requests_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_join_requests_handler_user\` FOREIGN KEY (\`handled_by_user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE SET NULL`
- `CONSTRAINT \`fk_chat_room_join_requests_handler_admin\` FOREIGN KEY (\`handled_by_admin_id\`) REFERENCES \`admins\` (\`id\`) ON DELETE SET NULL`

## `chat_room_reads`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `room_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `last_read_message_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_chat_room_reads_user\` (\`room_id\`, \`user_id\`)`
- `CONSTRAINT \`fk_chat_room_reads_room\` FOREIGN KEY (\`room_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`chat_rooms\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_reads_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_reads_message\` FOREIGN KEY (\`last_read_message_id\`) REFERENCES \`chat_room_messages\` (\`id\`) ON DELETE SET NULL`

## `service_sessions`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `assigned_admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `subject` | `VARCHAR(200) NOT NULL DEFAULT ''` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'open'` |
| `last_message_at` | `DATETIME DEFAULT NULL` |
| `closed_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_service_sessions_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_service_sessions_user_status\` (\`user_id\`, \`status\`)`
- `KEY \`idx_service_sessions_tenant_status\` (\`admin_id\`, \`app_id\`, \`status\`)`
- `CONSTRAINT \`fk_service_sessions_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_service_sessions_assigned\` FOREIGN KEY (\`assigned_admin_id\`) REFERENCES \`admins\` (\`id\`) ON DELETE SET NULL`

## `service_messages`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `session_id` | `BIGINT UNSIGNED NOT NULL` |
| `sender_type` | `VARCHAR(20) NOT NULL` |
| `sender_id` | `BIGINT UNSIGNED NOT NULL` |
| `reply_to_message_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `content` | `LONGTEXT NOT NULL` |
| `is_read` | `TINYINT NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_service_messages_session\` (\`session_id\`, \`id\`)`
- `KEY \`idx_service_messages_reply\` (\`reply_to_message_id\`)`
- `CONSTRAINT \`fk_service_messages_session\` FOREIGN KEY (\`session_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`service_sessions\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_service_messages_reply\` FOREIGN KEY (\`reply_to_message_id\`) REFERENCES \`service_messages\` (\`id\`) ON DELETE SET NULL`

## `communication_takeover_policies`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `platform_view_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `platform_send_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `platform_private_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `platform_group_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `platform_service_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `admin_view_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `admin_send_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `admin_private_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `admin_group_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `admin_service_enabled` | `TINYINT NOT NULL DEFAULT 1` |
| `system_display_name` | `VARCHAR(40) NOT NULL DEFAULT '系统消息'` |
| `policy_locked_for_level` | `TINYINT UNSIGNED NOT NULL DEFAULT 0` |
| `locked_by_platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `updated_by_type` | `VARCHAR(20) NOT NULL DEFAULT 'system'` |
| `updated_by_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_communication_takeover_policy_app\` (\`app_id\`)`
- `KEY \`idx_communication_takeover_policy_tenant\` (\`admin_id\`, \`app_id\`)`
- `KEY \`idx_communication_takeover_policy_lock\` (\`locked_by_platform_id\`, \`policy_locked_for_level\`)`
- `CONSTRAINT \`fk_communication_takeover_policy_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`)`
- `REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_communication_takeover_policy_platform\` FOREIGN KEY (\`locked_by_platform_id\`)`
- `REFERENCES \`platform_accounts\` (\`id\`) ON DELETE SET NULL`

## `communication_takeover_audits`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `actor_type` | `VARCHAR(20) NOT NULL` |
| `actor_id` | `BIGINT UNSIGNED NOT NULL` |
| `actor_level` | `TINYINT UNSIGNED NOT NULL` |
| `action` | `VARCHAR(30) NOT NULL` |
| `channel_type` | `VARCHAR(20) NOT NULL DEFAULT ''` |
| `channel_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `subject_user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `message_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `detail_json` | `LONGTEXT` |
| `ip` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_communication_takeover_audit_tenant\` (\`admin_id\`, \`app_id\`, \`created_at\`)`
- `KEY \`idx_communication_takeover_audit_actor\` (\`actor_type\`, \`actor_id\`, \`created_at\`)`
- `KEY \`idx_communication_takeover_audit_channel\` (\`app_id\`, \`channel_type\`, \`channel_id\`, \`created_at\`)`
- `KEY \`idx_communication_takeover_audit_user\` (\`subject_user_id\`, \`created_at\`)`
- `KEY \`idx_communication_takeover_audit_message\` (\`admin_id\`, \`app_id\`, \`channel_type\`, \`message_id\`, \`action\`)`
- `CONSTRAINT \`fk_communication_takeover_audit_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`)`
- `REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `payment_channels`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `channel_code` | `VARCHAR(40) NOT NULL` |
| `name` | `VARCHAR(100) NOT NULL DEFAULT ''` |
| `config_json` | `LONGTEXT` |
| `enabled` | `TINYINT NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_payment_channels_code\` (\`app_id\`, \`channel_code\`)`
- `CONSTRAINT \`fk_payment_channels_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `orders`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `order_no` | `VARCHAR(64) NOT NULL` |
| `order_type` | `VARCHAR(40) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `title` | `VARCHAR(200) NOT NULL` |
| `quantity` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `amount` | `DECIMAL(18,2) NOT NULL DEFAULT 0.00` |
| `pay_amount` | `DECIMAL(18,2) NOT NULL DEFAULT 0.00` |
| `pay_channel` | `VARCHAR(40) NOT NULL DEFAULT ''` |
| `buyer_info_json` | `LONGTEXT` |
| `snapshot_json` | `LONGTEXT` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `paid_at` | `DATETIME DEFAULT NULL` |
| `closed_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_orders_order_no\` (\`order_no\`)`
- `UNIQUE KEY \`uk_orders_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_orders_user_status\` (\`user_id\`, \`status\`, \`created_at\`)`
- `KEY \`idx_orders_tenant_status\` (\`admin_id\`, \`app_id\`, \`status\`)`
- `CONSTRAINT \`fk_orders_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `payments`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `order_id` | `BIGINT UNSIGNED NOT NULL` |
| `channel_code` | `VARCHAR(40) NOT NULL` |
| `trade_no` | `VARCHAR(190) NOT NULL` |
| `amount` | `DECIMAL(18,2) NOT NULL` |
| `callback_json` | `LONGTEXT` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'paid'` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_payments_channel_trade\` (\`app_id\`, \`channel_code\`, \`trade_no\`)`
- `KEY \`idx_payments_order\` (\`order_id\`)`
- `CONSTRAINT \`fk_payments_order\` FOREIGN KEY (\`order_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`orders\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `payment_callback_logs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `app_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `channel_code` | `VARCHAR(40) NOT NULL` |
| `order_no` | `VARCHAR(64) NOT NULL DEFAULT ''` |
| `request_json` | `LONGTEXT` |
| `verified` | `TINYINT NOT NULL DEFAULT 0` |
| `result` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_payment_callbacks_order\` (\`order_no\`, \`created_at\`)`
- `CONSTRAINT \`fk_payment_callbacks_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `shop_goods`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `catalog_code` | `VARCHAR(30) NOT NULL DEFAULT 'shop' COMMENT 'shop/balance_shop'` |
| `category_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `name` | `VARCHAR(200) NOT NULL` |
| `cover_url` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `description` | `LONGTEXT` |
| `goods_type` | `VARCHAR(20) NOT NULL DEFAULT 'virtual'` |
| `delivery_required` | `TINYINT NOT NULL DEFAULT 0` |
| `tags_json` | `LONGTEXT` |
| `images_json` | `LONGTEXT` |
| `attachments_json` | `LONGTEXT` |
| `price_integral` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `price_money` | `DECIMAL(18,2) NOT NULL DEFAULT 0.00` |
| `stock` | `INT NOT NULL DEFAULT 0` |
| `sales_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_shop_goods_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_shop_goods_tenant_status\` (\`admin_id\`, \`app_id\`, \`status\`)`
- `KEY \`idx_shop_goods_category\` (\`app_id\`, \`category_id\`, \`status\`, \`created_at\`)`
- `KEY \`idx_shop_goods_catalog_status\` (\`app_id\`, \`catalog_code\`, \`status\`, \`created_at\`, \`id\`)`
- `CONSTRAINT \`fk_shop_goods_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `shop_categories`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `catalog_code` | `VARCHAR(30) NOT NULL DEFAULT 'shop' COMMENT 'shop/balance_shop'` |
| `parent_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `name` | `VARCHAR(100) NOT NULL` |
| `icon_url` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `description` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_shop_categories_tenant_name\` (\`admin_id\`, \`app_id\`, \`catalog_code\`, \`parent_id\`, \`name\`)`
- `KEY \`idx_shop_categories_tenant_status\` (\`admin_id\`, \`app_id\`, \`status\`, \`sort_order\`)`
- `CONSTRAINT \`fk_shop_categories_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `shop_orders`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `catalog_code` | `VARCHAR(30) NOT NULL DEFAULT 'shop' COMMENT 'shop/balance_shop'` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `goods_id` | `BIGINT UNSIGNED NOT NULL` |
| `goods_name` | `VARCHAR(200) NOT NULL DEFAULT ''` |
| `goods_cover_url` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `goods_type` | `VARCHAR(20) NOT NULL DEFAULT 'virtual'` |
| `order_no` | `VARCHAR(64) NOT NULL` |
| `quantity` | `INT UNSIGNED NOT NULL` |
| `unit_price_integral` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `unit_price_money` | `DECIMAL(18,2) NOT NULL DEFAULT 0.00` |
| `amount_integral` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `amount_money` | `DECIMAL(18,2) NOT NULL DEFAULT 0.00` |
| `buyer_info_json` | `LONGTEXT` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'paid'` |
| `paid_at` | `DATETIME DEFAULT NULL` |
| `fulfilled_at` | `DATETIME DEFAULT NULL` |
| `closed_at` | `DATETIME DEFAULT NULL` |
| `shipping_company` | `VARCHAR(100) NOT NULL DEFAULT ''` |
| `tracking_no` | `VARCHAR(120) NOT NULL DEFAULT ''` |
| `fulfillment_note` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_shop_orders_order_no\` (\`order_no\`)`
- `KEY \`idx_shop_orders_user\` (\`user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_shop_orders_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_shop_orders_goods\` FOREIGN KEY (\`goods_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`shop_goods\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `shop_goods_comments`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `goods_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `parent_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `content` | `TEXT NOT NULL` |
| `score` | `TINYINT UNSIGNED NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_shop_goods_comments_goods\` (\`goods_id\`, \`status\`, \`created_at\`)`
- `KEY \`idx_shop_goods_comments_user\` (\`user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_shop_goods_comments_goods\` FOREIGN KEY (\`goods_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`shop_goods\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_shop_goods_comments_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `shop_goods_reactions`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `goods_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `reaction_type` | `VARCHAR(20) NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_shop_goods_reactions\` (\`goods_id\`, \`user_id\`, \`reaction_type\`)`
- `KEY \`idx_shop_goods_reactions_user\` (\`user_id\`, \`reaction_type\`, \`created_at\`)`
- `CONSTRAINT \`fk_shop_goods_reactions_goods\` FOREIGN KEY (\`goods_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`shop_goods\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_shop_goods_reactions_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `shop_goods_forwards`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `goods_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `target_type` | `VARCHAR(30) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `recommend_text` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_shop_goods_forwards_goods\` (\`goods_id\`, \`created_at\`)`
- `KEY \`idx_shop_goods_forwards_target\` (\`app_id\`, \`target_type\`, \`target_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_shop_goods_forwards_goods\` FOREIGN KEY (\`goods_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`shop_goods\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_shop_goods_forwards_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `shop_comment_reactions`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `comment_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `reaction_type` | `VARCHAR(20) NOT NULL DEFAULT 'like'` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_shop_comment_reactions\` (\`comment_id\`, \`user_id\`, \`reaction_type\`)`
- `KEY \`idx_shop_comment_reactions_user\` (\`user_id\`, \`reaction_type\`, \`created_at\`)`
- `CONSTRAINT \`fk_shop_comment_reactions_comment\` FOREIGN KEY (\`comment_id\`) REFERENCES \`shop_goods_comments\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_shop_comment_reactions_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `shop_order_events`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `order_source` | `VARCHAR(20) NOT NULL` |
| `order_id` | `BIGINT UNSIGNED NOT NULL` |
| `order_no` | `VARCHAR(64) NOT NULL` |
| `event_code` | `VARCHAR(40) NOT NULL` |
| `title` | `VARCHAR(150) NOT NULL` |
| `detail` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `actor_type` | `VARCHAR(20) NOT NULL DEFAULT 'system'` |
| `actor_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `metadata_json` | `LONGTEXT` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_shop_order_events_order\` (\`app_id\`, \`order_no\`, \`created_at\`)`
- `KEY \`idx_shop_order_events_user\` (\`user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_shop_order_events_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `red_packets`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `creator_type` | `VARCHAR(20) NOT NULL DEFAULT 'user' COMMENT 'platform/admin/user/system'` |
| `creator_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `packet_type` | `VARCHAR(20) NOT NULL DEFAULT 'random'` |
| `packet_label` | `VARCHAR(30) NOT NULL DEFAULT '拼手气红�` |
| `distribution_mode` | `VARCHAR(20) NOT NULL DEFAULT 'count_split' COMMENT 'count_split/random_grab'` |
| `eligibility_mode` | `VARCHAR(20) NOT NULL DEFAULT 'selected' COMMENT 'context_all/selected'` |
| `scene_type` | `VARCHAR(30) NOT NULL DEFAULT 'chat' COMMENT 'chat/forum_tip/bounty_tip/earned_reward/activity'` |
| `delivery_scope` | `VARCHAR(20) NOT NULL DEFAULT 'private' COMMENT 'private/group/chat_room/service/activity'` |
| `context_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '会话、群聊、聊天室或活动编号'` |
| `source_type` | `VARCHAR(40) NOT NULL DEFAULT ''` |
| `source_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `total_amount` | `DECIMAL(18,2) UNSIGNED NOT NULL` |
| `total_count` | `INT UNSIGNED NOT NULL` |
| `remain_amount` | `DECIMAL(18,2) UNSIGNED NOT NULL` |
| `remain_count` | `INT UNSIGNED NOT NULL` |
| `message` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `return_policy` | `VARCHAR(30) NOT NULL DEFAULT 'recipient_return' COMMENT 'recipient_return/manager_only/none'` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `expired_at` | `DATETIME NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `'`
- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_red_packets_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_red_packets_tenant_status\` (\`admin_id\`, \`app_id\`, \`status\`, \`expired_at\`)`
- `KEY \`idx_red_packets_delivery\` (\`app_id\`, \`delivery_scope\`, \`context_id\`, \`created_at\`)`
- `KEY \`idx_red_packets_scene_source\` (\`app_id\`, \`scene_type\`, \`source_type\`, \`source_id\`, \`status\`, \`created_at\`)`
- `CONSTRAINT \`fk_red_packets_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `red_packet_claims`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `packet_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `amount` | `DECIMAL(18,2) UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_red_packet_claim_user\` (\`packet_id\`, \`user_id\`)`
- `CONSTRAINT \`fk_red_packet_claim_packet\` FOREIGN KEY (\`packet_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`red_packets\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_red_packet_claim_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `red_packet_recipients`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `packet_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_red_packet_recipient\` (\`packet_id\`, \`user_id\`)`
- `KEY \`idx_red_packet_recipient_user\` (\`app_id\`, \`user_id\`, \`packet_id\`)`
- `CONSTRAINT \`fk_red_packet_recipient_packet\` FOREIGN KEY (\`packet_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`red_packets\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_red_packet_recipient_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `red_packet_returns`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `packet_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `amount` | `DECIMAL(18,2) UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_red_packet_return_user\` (\`packet_id\`, \`user_id\`)`
- `KEY \`idx_red_packet_return_user\` (\`app_id\`, \`user_id\`, \`packet_id\`)`
- `CONSTRAINT \`fk_red_packet_return_packet\` FOREIGN KEY (\`packet_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`red_packets\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_red_packet_return_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_transfers`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `from_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `to_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `amount` | `DECIMAL(18,2) NOT NULL` |
| `message` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `expired_at` | `DATETIME NOT NULL` |
| `accepted_at` | `DATETIME DEFAULT NULL` |
| `refunded_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_user_transfers_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_user_transfers_sender\` (\`app_id\`, \`from_user_id\`, \`status\`, \`id\`)`
- `KEY \`idx_user_transfers_receiver\` (\`app_id\`, \`to_user_id\`, \`status\`, \`id\`)`
- `CONSTRAINT \`fk_user_transfers_sender\` FOREIGN KEY (\`from_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_user_transfers_receiver\` FOREIGN KEY (\`to_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `lottery_prizes`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `name` | `VARCHAR(150) NOT NULL` |
| `prize_type` | `VARCHAR(40) NOT NULL` |
| `value_json` | `LONGTEXT NOT NULL` |
| `weight` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `stock` | `INT NOT NULL DEFAULT 0` |
| `daily_limit` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_lottery_prizes_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `CONSTRAINT \`fk_lottery_prizes_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `lottery_draws`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `prize_id` | `BIGINT UNSIGNED NOT NULL` |
| `reward_json` | `LONGTEXT NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_lottery_draws_user_time\` (\`user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_lottery_draws_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_lottery_draws_prize\` FOREIGN KEY (\`prize_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`lottery_prizes\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `votes`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `title` | `VARCHAR(200) NOT NULL` |
| `description` | `LONGTEXT` |
| `multi_select` | `TINYINT NOT NULL DEFAULT 0` |
| `max_select` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `start_at` | `DATETIME DEFAULT NULL` |
| `end_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_votes_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `CONSTRAINT \`fk_votes_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `vote_options`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `vote_id` | `BIGINT UNSIGNED NOT NULL` |
| `option_text` | `VARCHAR(500) NOT NULL` |
| `vote_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_vote_options_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_vote_options_vote\` (\`vote_id\`, \`sort_order\`)`
- `CONSTRAINT \`fk_vote_options_vote\` FOREIGN KEY (\`vote_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`votes\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `vote_records`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `vote_id` | `BIGINT UNSIGNED NOT NULL` |
| `option_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_vote_records_option_user\` (\`vote_id\`, \`option_id\`, \`user_id\`)`
- `KEY \`idx_vote_records_user\` (\`user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_vote_records_vote\` FOREIGN KEY (\`vote_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`votes\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_vote_records_option\` FOREIGN KEY (\`option_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`vote_options\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_vote_records_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `remote_files`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `owner_user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `parent_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `file_type` | `VARCHAR(20) NOT NULL DEFAULT 'file'` |
| `name` | `VARCHAR(255) NOT NULL` |
| `content` | `LONGTEXT` |
| `file_url` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `mime_type` | `VARCHAR(150) NOT NULL DEFAULT ''` |
| `size_bytes` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `visibility` | `VARCHAR(20) NOT NULL DEFAULT 'public'` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |
| `deleted_at` | `DATETIME DEFAULT NULL` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_remote_files_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_remote_files_parent\` (\`app_id\`, \`parent_id\`, \`status\`)`
- `KEY \`idx_remote_files_owner\` (\`owner_user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_remote_files_owner\` FOREIGN KEY (\`owner_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_remote_files_parent\` FOREIGN KEY (\`parent_id\`) REFERENCES \`remote_files\` (\`id\`) ON DELETE CASCADE`

## `remote_file_versions`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `file_id` | `BIGINT UNSIGNED NOT NULL` |
| `version_no` | `INT UNSIGNED NOT NULL` |
| `content` | `LONGTEXT` |
| `file_url` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `size_bytes` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_remote_file_versions_no\` (\`file_id\`, \`version_no\`)`
- `CONSTRAINT \`fk_remote_file_versions_file\` FOREIGN KEY (\`file_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`remote_files\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `uploads`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `scene` | `VARCHAR(40) NOT NULL DEFAULT 'general'` |
| `original_name` | `VARCHAR(255) NOT NULL` |
| `stored_name` | `VARCHAR(255) NOT NULL` |
| `file_path` | `VARCHAR(1000) NOT NULL` |
| `file_url` | `VARCHAR(1000) NOT NULL` |
| `mime_type` | `VARCHAR(150) NOT NULL DEFAULT ''` |
| `size_bytes` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `original_size_bytes` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `optimized_size_bytes` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `upload_mode` | `VARCHAR(20) NOT NULL DEFAULT 'original'` |
| `optimization_status` | `VARCHAR(40) NOT NULL DEFAULT 'not_required'` |
| `original_file_url` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `optimized_file_url` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `thumbnail_url` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `is_animated` | `TINYINT NOT NULL DEFAULT 0` |
| `sha256` | `CHAR(64) NOT NULL` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_uploads_tenant_scene\` (\`admin_id\`, \`app_id\`, \`scene\`, \`created_at\`)`
- `KEY \`idx_uploads_content_fingerprint\` (\`admin_id\`, \`app_id\`, \`sha256\`, \`size_bytes\`, \`status\`)`
- `CONSTRAINT \`fk_uploads_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `sticker_packs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `name` | `VARCHAR(100) NOT NULL` |
| `cover_url` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `sticker_count` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_sticker_packs_owner_name\` (\`app_id\`, \`user_id\`, \`name\`)`
- `UNIQUE KEY \`uk_sticker_packs_id_tenant\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_sticker_packs_owner\` (\`user_id\`, \`status\`, \`sort_order\`)`
- `CONSTRAINT \`fk_sticker_packs_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `stickers`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `pack_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `upload_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `name` | `VARCHAR(100) NOT NULL DEFAULT ''` |
| `image_url` | `VARCHAR(1000) NOT NULL` |
| `thumbnail_url` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `width` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `height` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_stickers_pack_url\` (\`pack_id\`, \`image_url\`(191))`
- `KEY \`idx_stickers_pack\` (\`pack_id\`, \`status\`, \`sort_order\`, \`id\`)`
- `CONSTRAINT \`fk_stickers_pack\` FOREIGN KEY (\`pack_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`sticker_packs\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_stickers_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_stickers_upload\` FOREIGN KEY (\`upload_id\`) REFERENCES \`uploads\` (\`id\`) ON DELETE SET NULL`

## `media_attachments`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `owner_user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `owner_admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `target_type` | `VARCHAR(40) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED NOT NULL` |
| `media_type` | `VARCHAR(20) NOT NULL` |
| `upload_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `sticker_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `url` | `VARCHAR(1000) NOT NULL` |
| `thumbnail_url` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `file_name` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `mime_type` | `VARCHAR(150) NOT NULL DEFAULT ''` |
| `size_bytes` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `width` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `height` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `duration_ms` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `metadata_json` | `LONGTEXT` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_media_attachments_target\` (\`app_id\`, \`target_type\`, \`target_id\`, \`sort_order\`, \`id\`)`
- `KEY \`idx_media_attachments_owner\` (\`owner_user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_media_attachments_upload\` FOREIGN KEY (\`upload_id\`) REFERENCES \`uploads\` (\`id\`) ON DELETE SET NULL`
- `CONSTRAINT \`fk_media_attachments_sticker\` FOREIGN KEY (\`sticker_id\`) REFERENCES \`stickers\` (\`id\`) ON DELETE SET NULL`

## `audio_transcriptions`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `upload_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `language` | `VARCHAR(20) NOT NULL DEFAULT 'zh'` |
| `transcript` | `LONGTEXT NOT NULL` |
| `provider` | `VARCHAR(80) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_audio_transcriptions_upload_language\` (\`app_id\`, \`upload_id\`, \`language\`)`
- `KEY \`idx_audio_transcriptions_user_time\` (\`user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_audio_transcriptions_upload\` FOREIGN KEY (\`upload_id\`) REFERENCES \`uploads\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_audio_transcriptions_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `message_forward_bundles`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `creator_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `source_type` | `VARCHAR(20) NOT NULL` |
| `source_id` | `BIGINT UNSIGNED NOT NULL` |
| `title` | `VARCHAR(255) NOT NULL` |
| `item_count` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `anonymity_mode` | `VARCHAR(20) NOT NULL DEFAULT 'none'` |
| `anonymity_map_json` | `LONGTEXT` |
| `snapshot_json` | `LONGTEXT NOT NULL` |
| `audit_snapshot_json` | `LONGTEXT` |
| `source_context_json` | `LONGTEXT` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_message_forward_bundles_owner\` (\`app_id\`, \`creator_user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_message_forward_bundles_user\` FOREIGN KEY (\`creator_user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `message_forward_links`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `bundle_id` | `BIGINT UNSIGNED NOT NULL` |
| `target_type` | `VARCHAR(30) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_message_forward_links_target\` (\`app_id\`, \`target_type\`, \`target_id\`)`
- `KEY \`idx_message_forward_links_bundle\` (\`bundle_id\`, \`id\`)`
- `CONSTRAINT \`fk_message_forward_links_bundle\` FOREIGN KEY (\`bundle_id\`)`
- `REFERENCES \`message_forward_bundles\` (\`id\`) ON DELETE CASCADE`

## `chat_search_histories`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `scope_type` | `VARCHAR(20) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `keyword` | `VARCHAR(200) NOT NULL` |
| `content_filter` | `VARCHAR(30) NOT NULL DEFAULT 'all'` |
| `filter_json` | `LONGTEXT` |
| `filter_hash` | `CHAR(64) NOT NULL DEFAULT ''` |
| `search_count` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `last_searched_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_chat_search_histories_filter\` (\`user_id\`, \`scope_type\`, \`target_id\`, \`filter_hash\`)`
- `KEY \`idx_chat_search_histories_recent\` (\`app_id\`, \`user_id\`, \`last_searched_at\`)`
- `CONSTRAINT \`fk_chat_search_histories_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `cloud_sync_snapshots`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `data_type` | `VARCHAR(20) NOT NULL` |
| `scope_type` | `VARCHAR(20) NOT NULL DEFAULT ''` |
| `target_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `title` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `item_count` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `date_from` | `DATETIME DEFAULT NULL` |
| `date_to` | `DATETIME DEFAULT NULL` |
| `filter_json` | `LONGTEXT` |
| `snapshot_json` | `LONGTEXT NOT NULL` |
| `size_bytes` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `charged_balance` | `DECIMAL(14,2) NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_cloud_sync_snapshots_owner\` (\`app_id\`, \`user_id\`, \`data_type\`, \`created_at\`)`
- `KEY \`idx_cloud_sync_snapshots_scope\` (\`app_id\`, \`user_id\`, \`scope_type\`, \`target_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_cloud_sync_snapshots_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `message_recall_audits`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `channel_type` | `VARCHAR(20) NOT NULL` |
| `channel_id` | `BIGINT UNSIGNED NOT NULL` |
| `message_id` | `BIGINT UNSIGNED NOT NULL` |
| `sender_type` | `VARCHAR(20) NOT NULL` |
| `sender_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `original_content_type` | `VARCHAR(20) NOT NULL` |
| `original_content` | `LONGTEXT NOT NULL` |
| `original_attachments_json` | `LONGTEXT` |
| `recalled_by_type` | `VARCHAR(20) NOT NULL` |
| `recalled_by_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `reason` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `recalled_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_message_recall_audits_message\` (\`app_id\`, \`channel_type\`, \`message_id\`)`
- `KEY \`idx_message_recall_audits_scope\` (\`admin_id\`, \`app_id\`, \`channel_type\`, \`recalled_at\`)`
- `KEY \`idx_message_recall_audits_sender\` (\`app_id\`, \`sender_type\`, \`sender_id\`, \`recalled_at\`)`

## `feedbacks`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `type` | `VARCHAR(40) NOT NULL DEFAULT 'feedback'` |
| `title` | `VARCHAR(200) NOT NULL` |
| `content` | `LONGTEXT NOT NULL` |
| `images_json` | `LONGTEXT` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `reply_content` | `LONGTEXT` |
| `replied_by` | `BIGINT UNSIGNED DEFAULT NULL` |
| `replied_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_feedbacks_tenant_status\` (\`admin_id\`, \`app_id\`, \`status\`, \`created_at\`)`
- `KEY \`idx_feedbacks_user\` (\`user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_feedbacks_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_feedbacks_admin\` FOREIGN KEY (\`replied_by\`) REFERENCES \`admins\` (\`id\`) ON DELETE SET NULL`

## `bot_qa`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `question` | `VARCHAR(500) NOT NULL` |
| `answer` | `LONGTEXT NOT NULL` |
| `keywords` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_bot_qa_tenant_status\` (\`admin_id\`, \`app_id\`, \`status\`)`
- `CONSTRAINT \`fk_bot_qa_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `governance_rules`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `issuer_platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `issuer_level` | `TINYINT UNSIGNED NOT NULL` |
| `target_type` | `VARCHAR(20) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `target_level` | `TINYINT UNSIGNED DEFAULT NULL` |
| `feature_code` | `VARCHAR(100) NOT NULL` |
| `effect` | `VARCHAR(20) NOT NULL` |
| `value_json` | `LONGTEXT` |
| `forced` | `TINYINT NOT NULL DEFAULT 1` |
| `priority` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `starts_at` | `DATETIME DEFAULT NULL` |
| `ends_at` | `DATETIME DEFAULT NULL` |
| `remark` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_governance_issuer\` (\`issuer_platform_id\`, \`status\`, \`feature_code\`)`
- `KEY \`idx_governance_target\` (\`target_type\`, \`target_id\`, \`target_level\`, \`feature_code\`, \`status\`)`
- `CONSTRAINT \`fk_governance_issuer\` FOREIGN KEY (\`issuer_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`

## `level_forum_posts`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `root_platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `scope_platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `app_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `target_level` | `TINYINT UNSIGNED NOT NULL` |
| `author_type` | `VARCHAR(20) NOT NULL` |
| `author_id` | `BIGINT UNSIGNED NOT NULL` |
| `author_name` | `VARCHAR(100) NOT NULL` |
| `title` | `VARCHAR(200) NOT NULL` |
| `content` | `LONGTEXT NOT NULL` |
| `attachments_json` | `LONGTEXT` |
| `is_top` | `TINYINT NOT NULL DEFAULT 0` |
| `like_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `favorite_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `comment_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |
| `deleted_at` | `DATETIME DEFAULT NULL` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_level_forum_feed\` (\`root_platform_id\`, \`target_level\`, \`scope_platform_id\`, \`app_id\`, \`status\`, \`is_top\`, \`id\`)`
- `KEY \`idx_level_forum_author\` (\`author_type\`, \`author_id\`, \`status\`)`
- `CONSTRAINT \`fk_level_forum_root\` FOREIGN KEY (\`root_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_level_forum_scope\` FOREIGN KEY (\`scope_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_level_forum_app\` FOREIGN KEY (\`app_id\`) REFERENCES \`apps\` (\`id\`) ON DELETE CASCADE`

## `level_forum_comments`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `post_id` | `BIGINT UNSIGNED NOT NULL` |
| `parent_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `author_type` | `VARCHAR(20) NOT NULL` |
| `author_id` | `BIGINT UNSIGNED NOT NULL` |
| `author_name` | `VARCHAR(100) NOT NULL` |
| `content` | `TEXT NOT NULL` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `deleted_at` | `DATETIME DEFAULT NULL` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_level_forum_comments_post\` (\`post_id\`, \`status\`, \`id\`)`
- `CONSTRAINT \`fk_level_forum_comments_post\` FOREIGN KEY (\`post_id\`) REFERENCES \`level_forum_posts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_level_forum_comments_parent\` FOREIGN KEY (\`parent_id\`) REFERENCES \`level_forum_comments\` (\`id\`) ON DELETE SET NULL`

## `level_forum_reactions`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `post_id` | `BIGINT UNSIGNED NOT NULL` |
| `actor_type` | `VARCHAR(20) NOT NULL` |
| `actor_id` | `BIGINT UNSIGNED NOT NULL` |
| `reaction_type` | `VARCHAR(20) NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_level_forum_reaction\` (\`post_id\`, \`actor_type\`, \`actor_id\`, \`reaction_type\`)`
- `KEY \`idx_level_forum_reaction_actor\` (\`actor_type\`, \`actor_id\`, \`reaction_type\`)`
- `CONSTRAINT \`fk_level_forum_reactions_post\` FOREIGN KEY (\`post_id\`) REFERENCES \`level_forum_posts\` (\`id\`) ON DELETE CASCADE`

## `bounty_categories`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `name` | `VARCHAR(100) NOT NULL` |
| `description` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `icon` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_bounty_categories_name\` (\`app_id\`, \`name\`)`
- `UNIQUE KEY \`uk_bounty_categories_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_bounty_categories_list\` (\`admin_id\`, \`app_id\`, \`status\`, \`sort_order\`, \`id\`)`
- `CONSTRAINT \`fk_bounty_categories_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `bounty_category_requests`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `name` | `VARCHAR(100) NOT NULL` |
| `description` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `reason` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `reviewer_admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `review_comment` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `created_category_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `reviewed_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_bounty_category_requests_status\` (\`admin_id\`, \`app_id\`, \`status\`, \`id\`)`
- `KEY \`idx_bounty_category_requests_user\` (\`user_id\`, \`status\`, \`id\`)`
- `CONSTRAINT \`fk_bounty_category_requests_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_bounty_category_requests_reviewer\` FOREIGN KEY (\`reviewer_admin_id\`) REFERENCES \`admins\` (\`id\`) ON DELETE SET NULL`
- `CONSTRAINT \`fk_bounty_category_requests_created\` FOREIGN KEY (\`created_category_id\`) REFERENCES \`bounty_categories\` (\`id\`) ON DELETE SET NULL`

## `bounties`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `category_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `creator_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `winner_user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `winner_submission_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `title` | `VARCHAR(200) NOT NULL` |
| `description` | `LONGTEXT NOT NULL` |
| `requirements_json` | `LONGTEXT` |
| `attachments_json` | `LONGTEXT` |
| `reward_integral` | `BIGINT UNSIGNED NOT NULL` |
| `submission_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `like_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `favorite_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'open'` |
| `audit_status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `audit_reason` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `audited_by` | `BIGINT UNSIGNED DEFAULT NULL` |
| `audited_at` | `DATETIME DEFAULT NULL` |
| `deadline_at` | `DATETIME DEFAULT NULL` |
| `awarded_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |
| `deleted_at` | `DATETIME DEFAULT NULL` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_bounties_id_app_admin\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_bounties_feed\` (\`admin_id\`, \`app_id\`, \`status\`, \`id\`)`
- `KEY \`idx_bounties_review\` (\`admin_id\`, \`app_id\`, \`audit_status\`, \`id\`)`
- `KEY \`idx_bounties_creator\` (\`creator_user_id\`, \`status\`)`
- `KEY \`idx_bounties_category\` (\`admin_id\`, \`app_id\`, \`category_id\`, \`status\`, \`id\`)`
- `CONSTRAINT \`fk_bounties_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_bounties_creator\` FOREIGN KEY (\`creator_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_bounties_category\` FOREIGN KEY (\`category_id\`) REFERENCES \`bounty_categories\` (\`id\`) ON DELETE SET NULL`
- `CONSTRAINT \`fk_bounties_winner\` FOREIGN KEY (\`winner_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE RESTRICT`

## `bounty_submissions`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `bounty_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `content` | `LONGTEXT NOT NULL` |
| `attachments_json` | `LONGTEXT` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'submitted'` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_bounty_submission_user\` (\`bounty_id\`, \`user_id\`)`
- `KEY \`idx_bounty_submissions_bounty\` (\`bounty_id\`, \`status\`, \`id\`)`
- `CONSTRAINT \`fk_bounty_submissions_bounty\` FOREIGN KEY (\`bounty_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`bounties\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_bounty_submissions_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `bounty_reactions`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `bounty_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `reaction_type` | `VARCHAR(20) NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_bounty_reactions\` (\`bounty_id\`, \`user_id\`, \`reaction_type\`)`
- `KEY \`idx_bounty_reactions_user\` (\`user_id\`, \`reaction_type\`, \`id\`)`
- `CONSTRAINT \`fk_bounty_reactions_bounty\` FOREIGN KEY (\`bounty_id\`) REFERENCES \`bounties\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_bounty_reactions_user\` FOREIGN KEY (\`user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE`

## `resource_reactions`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `resource_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `reaction_type` | `VARCHAR(20) NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_resource_reactions\` (\`resource_id\`, \`user_id\`, \`reaction_type\`)`
- `KEY \`idx_resource_reactions_user\` (\`user_id\`, \`reaction_type\`, \`id\`)`
- `CONSTRAINT \`fk_resource_reactions_resource\` FOREIGN KEY (\`resource_id\`) REFERENCES \`resources\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_resource_reactions_user\` FOREIGN KEY (\`user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE`

## `store_app_reactions`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `store_app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `reaction_type` | `VARCHAR(20) NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_store_app_reactions\` (\`store_app_id\`, \`user_id\`, \`reaction_type\`)`
- `KEY \`idx_store_app_reactions_user\` (\`user_id\`, \`reaction_type\`, \`id\`)`
- `CONSTRAINT \`fk_store_app_reactions_app\` FOREIGN KEY (\`store_app_id\`) REFERENCES \`store_apps\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_store_app_reactions_user\` FOREIGN KEY (\`user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE`

## `user_follows`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `follower_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `followed_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_user_follows\` (\`app_id\`, \`follower_user_id\`, \`followed_user_id\`)`
- `KEY \`idx_user_follows_followed\` (\`app_id\`, \`followed_user_id\`, \`id\`)`
- `CONSTRAINT \`fk_user_follows_follower\` FOREIGN KEY (\`follower_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_user_follows_followed\` FOREIGN KEY (\`followed_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_blacklist`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `blocked_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_user_blacklist\` (\`app_id\`, \`user_id\`, \`blocked_user_id\`)`
- `KEY \`idx_user_blacklist_blocked\` (\`app_id\`, \`blocked_user_id\`)`
- `CONSTRAINT \`fk_user_blacklist_owner\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_user_blacklist_target\` FOREIGN KEY (\`blocked_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_profile_likes`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `liker_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `target_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `like_date` | `DATE NOT NULL` |
| `like_count` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_user_profile_likes_daily\` (\`app_id\`, \`liker_user_id\`, \`target_user_id\`, \`like_date\`)`
- `KEY \`idx_user_profile_likes_target\` (\`app_id\`, \`target_user_id\`, \`id\`)`
- `CONSTRAINT \`fk_user_profile_likes_liker\` FOREIGN KEY (\`liker_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_user_profile_likes_target\` FOREIGN KEY (\`target_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `gift_catalog`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `gift_code` | `VARCHAR(40) NOT NULL` |
| `gift_name` | `VARCHAR(80) NOT NULL` |
| `icon_url` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `price` | `DECIMAL(18,2) NOT NULL DEFAULT 0.00` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_gift_catalog_code\` (\`app_id\`, \`gift_code\`)`
- `KEY \`idx_gift_catalog_tenant\` (\`admin_id\`, \`app_id\`, \`status\`, \`sort_order\`)`

## `user_gift_records`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `from_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `to_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `gift_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `gift_code` | `VARCHAR(40) NOT NULL` |
| `gift_name` | `VARCHAR(80) NOT NULL` |
| `quantity` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `unit_price` | `DECIMAL(18,2) NOT NULL DEFAULT 0.00` |
| `total_amount` | `DECIMAL(18,2) NOT NULL DEFAULT 0.00` |
| `message` | `VARCHAR(300) NOT NULL DEFAULT ''` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `expired_at` | `DATETIME DEFAULT NULL` |
| `accepted_at` | `DATETIME DEFAULT NULL` |
| `refunded_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_user_gift_records_wall\` (\`app_id\`, \`to_user_id\`, \`id\`)`
- `KEY \`idx_user_gift_records_sender\` (\`app_id\`, \`from_user_id\`, \`id\`)`
- `CONSTRAINT \`fk_user_gift_records_sender\` FOREIGN KEY (\`from_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_user_gift_records_receiver\` FOREIGN KEY (\`to_user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_notifications`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `notification_type` | `VARCHAR(40) NOT NULL` |
| `title` | `VARCHAR(200) NOT NULL` |
| `content` | `TEXT NOT NULL` |
| `data_json` | `LONGTEXT` |
| `is_read` | `TINYINT NOT NULL DEFAULT 0` |
| `read_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_user_notifications_inbox\` (\`app_id\`, \`user_id\`, \`is_read\`, \`id\`)`
- `CONSTRAINT \`fk_user_notifications_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_withdrawals`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `amount` | `DECIMAL(18,2) NOT NULL` |
| `channel` | `VARCHAR(40) NOT NULL` |
| `account_name` | `VARCHAR(100) NOT NULL` |
| `account_no` | `VARCHAR(200) NOT NULL` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'pending'` |
| `review_remark` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `reviewed_by_admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `reviewed_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_user_withdrawals_user\` (\`app_id\`, \`user_id\`, \`status\`, \`id\`)`
- `KEY \`idx_user_withdrawals_admin\` (\`admin_id\`, \`app_id\`, \`status\`, \`id\`)`
- `CONSTRAINT \`fk_user_withdrawals_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_user_withdrawals_reviewer\` FOREIGN KEY (\`reviewed_by_admin_id\`) REFERENCES \`admins\` (\`id\`) ON DELETE SET NULL`

## `message_user_states`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `message_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `is_deleted` | `TINYINT NOT NULL DEFAULT 0` |
| `is_favorite` | `TINYINT NOT NULL DEFAULT 0` |
| `read_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_message_user_states\` (\`message_id\`, \`user_id\`)`
- `KEY \`idx_message_user_states_user\` (\`user_id\`, \`is_favorite\`, \`is_deleted\`, \`id\`)`
- `CONSTRAINT \`fk_message_user_states_message\` FOREIGN KEY (\`message_id\`) REFERENCES \`messages\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_message_user_states_user\` FOREIGN KEY (\`user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE`

## `message_recalls`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `message_id` | `BIGINT UNSIGNED NOT NULL` |
| `actor_type` | `VARCHAR(20) NOT NULL` |
| `actor_id` | `BIGINT UNSIGNED NOT NULL` |
| `reason` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `notice_text` | `VARCHAR(200) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_message_recalls_message\` (\`message_id\`)`
- `CONSTRAINT \`fk_message_recalls_message\` FOREIGN KEY (\`message_id\`) REFERENCES \`messages\` (\`id\`) ON DELETE CASCADE`

## `conversation_preferences`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `target_type` | `VARCHAR(20) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `is_pinned` | `TINYINT NOT NULL DEFAULT 0` |
| `is_bottomed` | `TINYINT NOT NULL DEFAULT 0` |
| `is_hidden` | `TINYINT NOT NULL DEFAULT 0` |
| `is_muted` | `TINYINT NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_conversation_preferences_target\` (\`user_id\`, \`target_type\`, \`target_id\`)`
- `KEY \`idx_conversation_preferences_center\` (\`app_id\`, \`user_id\`, \`is_pinned\`, \`is_hidden\`, \`updated_at\`)`
- `CONSTRAINT \`fk_conversation_preferences_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `communication_message_states`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `scope_type` | `VARCHAR(20) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED NOT NULL` |
| `message_id` | `BIGINT UNSIGNED NOT NULL` |
| `is_deleted` | `TINYINT NOT NULL DEFAULT 0` |
| `is_favorite` | `TINYINT NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_communication_message_states\` (\`user_id\`, \`scope_type\`, \`message_id\`)`
- `KEY \`idx_communication_message_states_favorite\` (\`app_id\`, \`user_id\`, \`is_favorite\`, \`is_deleted\`, \`updated_at\`)`
- `CONSTRAINT \`fk_communication_message_states_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `composer_drafts`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `target_type` | `VARCHAR(30) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `content` | `LONGTEXT NOT NULL` |
| `attachments_json` | `LONGTEXT` |
| `tags_json` | `LONGTEXT` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_composer_drafts_target\` (\`user_id\`, \`target_type\`, \`target_id\`)`
- `KEY \`idx_composer_drafts_owner\` (\`app_id\`, \`user_id\`, \`updated_at\`)`
- `CONSTRAINT \`fk_composer_drafts_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_avatar_history`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `avatar_url` | `VARCHAR(1000) NOT NULL` |
| `sha256` | `CHAR(64) NOT NULL DEFAULT ''` |
| `is_current` | `TINYINT NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_user_avatar_history_url\` (\`user_id\`, \`avatar_url\`(128))`
- `KEY \`idx_user_avatar_history_current\` (\`app_id\`, \`user_id\`, \`is_current\`, \`id\`)`
- `CONSTRAINT \`fk_user_avatar_history_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `content_favorites`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `content_type` | `VARCHAR(30) NOT NULL` |
| `content_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_content_favorites_target\` (\`user_id\`, \`content_type\`, \`content_id\`)`
- `KEY \`idx_content_favorites_owner\` (\`app_id\`, \`user_id\`, \`content_type\`, \`id\`)`
- `CONSTRAINT \`fk_content_favorites_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `chat_room_files`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `room_id` | `BIGINT UNSIGNED NOT NULL` |
| `uploader_user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `parent_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `is_folder` | `TINYINT NOT NULL DEFAULT 0` |
| `name` | `VARCHAR(255) NOT NULL` |
| `file_url` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `mime_type` | `VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream'` |
| `size_bytes` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `download_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_chat_room_files_room\` (\`room_id\`, \`status\`, \`id\`)`
- `KEY \`idx_chat_room_files_parent\` (\`room_id\`, \`parent_id\`, \`status\`, \`is_folder\`, \`id\`)`
- `CONSTRAINT \`fk_chat_room_files_room\` FOREIGN KEY (\`room_id\`) REFERENCES \`chat_rooms\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_files_user\` FOREIGN KEY (\`uploader_user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE SET NULL`
- `CONSTRAINT \`fk_chat_room_files_parent\` FOREIGN KEY (\`parent_id\`) REFERENCES \`chat_room_files\` (\`id\`) ON DELETE CASCADE`

## `chat_room_albums`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `room_id` | `BIGINT UNSIGNED NOT NULL` |
| `creator_user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `name` | `VARCHAR(120) NOT NULL` |
| `description` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_chat_room_albums_room\` (\`room_id\`, \`status\`, \`id\`)`
- `CONSTRAINT \`fk_chat_room_albums_room\` FOREIGN KEY (\`room_id\`) REFERENCES \`chat_rooms\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_albums_user\` FOREIGN KEY (\`creator_user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE SET NULL`

## `chat_room_album_photos`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `album_id` | `BIGINT UNSIGNED NOT NULL` |
| `uploader_user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `image_url` | `VARCHAR(1000) NOT NULL` |
| `media_type` | `VARCHAR(20) NOT NULL DEFAULT 'image'` |
| `mime_type` | `VARCHAR(120) NOT NULL DEFAULT 'image/jpeg'` |
| `size_bytes` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `thumbnail_url` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `download_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `caption` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_chat_room_album_photos_album\` (\`album_id\`, \`status\`, \`id\`)`
- `CONSTRAINT \`fk_chat_room_album_photos_album\` FOREIGN KEY (\`album_id\`) REFERENCES \`chat_room_albums\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_album_photos_user\` FOREIGN KEY (\`uploader_user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE SET NULL`

## `chat_room_votes`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `room_id` | `BIGINT UNSIGNED NOT NULL` |
| `creator_user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `title` | `VARCHAR(200) NOT NULL` |
| `description` | `TEXT` |
| `multiple_choice` | `TINYINT NOT NULL DEFAULT 0` |
| `min_select` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `max_select` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `allow_change` | `TINYINT NOT NULL DEFAULT 0` |
| `anonymous` | `TINYINT NOT NULL DEFAULT 0` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'active'` |
| `ends_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_chat_room_votes_room\` (\`room_id\`, \`status\`, \`id\`)`
- `CONSTRAINT \`fk_chat_room_votes_room\` FOREIGN KEY (\`room_id\`) REFERENCES \`chat_rooms\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_votes_user\` FOREIGN KEY (\`creator_user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE SET NULL`

## `chat_room_vote_options`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `vote_id` | `BIGINT UNSIGNED NOT NULL` |
| `option_text` | `VARCHAR(300) NOT NULL` |
| `image_url` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `vote_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_chat_room_vote_options_vote\` (\`vote_id\`, \`sort_order\`, \`id\`)`
- `CONSTRAINT \`fk_chat_room_vote_options_vote\` FOREIGN KEY (\`vote_id\`) REFERENCES \`chat_room_votes\` (\`id\`) ON DELETE CASCADE`

## `chat_room_vote_records`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `vote_id` | `BIGINT UNSIGNED NOT NULL` |
| `option_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_chat_room_vote_record\` (\`vote_id\`, \`option_id\`, \`user_id\`)`
- `KEY \`idx_chat_room_vote_records_user\` (\`vote_id\`, \`user_id\`)`
- `CONSTRAINT \`fk_chat_room_vote_records_vote\` FOREIGN KEY (\`vote_id\`) REFERENCES \`chat_room_votes\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_vote_records_option\` FOREIGN KEY (\`option_id\`) REFERENCES \`chat_room_vote_options\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_vote_records_user\` FOREIGN KEY (\`user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE`

## `chat_room_solitaire`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `room_id` | `BIGINT UNSIGNED NOT NULL` |
| `creator_user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `title` | `VARCHAR(200) NOT NULL` |
| `description` | `TEXT` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'active'` |
| `ends_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_chat_room_solitaire_room\` (\`room_id\`, \`status\`, \`id\`)`
- `CONSTRAINT \`fk_chat_room_solitaire_room\` FOREIGN KEY (\`room_id\`) REFERENCES \`chat_rooms\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_solitaire_user\` FOREIGN KEY (\`creator_user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE SET NULL`

## `chat_room_solitaire_entries`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `solitaire_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `content` | `VARCHAR(1000) NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_chat_room_solitaire_entry\` (\`solitaire_id\`, \`user_id\`)`
- `KEY \`idx_chat_room_solitaire_entries\` (\`solitaire_id\`, \`id\`)`
- `CONSTRAINT \`fk_chat_room_solitaire_entries_parent\` FOREIGN KEY (\`solitaire_id\`) REFERENCES \`chat_room_solitaire\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_chat_room_solitaire_entries_user\` FOREIGN KEY (\`user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE`

## `forum_view_history`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `post_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `view_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 1` |
| `last_viewed_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_forum_view_history\` (\`post_id\`, \`user_id\`)`
- `KEY \`idx_forum_view_history_user\` (\`user_id\`, \`last_viewed_at\`)`
- `CONSTRAINT \`fk_forum_view_history_post\` FOREIGN KEY (\`post_id\`) REFERENCES \`forum_posts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_view_history_user\` FOREIGN KEY (\`user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE`

## `forum_rewards`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `from_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `to_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `target_type` | `VARCHAR(20) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED NOT NULL` |
| `integral` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_forum_rewards_target\` (\`app_id\`, \`target_type\`, \`target_id\`, \`id\`)`
- `KEY \`idx_forum_rewards_from\` (\`from_user_id\`, \`id\`)`
- `CONSTRAINT \`fk_forum_rewards_app\` FOREIGN KEY (\`app_id\`) REFERENCES \`apps\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_rewards_from\` FOREIGN KEY (\`from_user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_rewards_to\` FOREIGN KEY (\`to_user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE`

## `forum_paid_contents`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `post_id` | `BIGINT UNSIGNED NOT NULL` |
| `price_integral` | `BIGINT UNSIGNED NOT NULL` |
| `preview_content` | `TEXT NOT NULL` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_forum_paid_contents_post\` (\`post_id\`)`
- `CONSTRAINT \`fk_forum_paid_contents_post\` FOREIGN KEY (\`post_id\`) REFERENCES \`forum_posts\` (\`id\`) ON DELETE CASCADE`

## `forum_post_purchases`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `post_id` | `BIGINT UNSIGNED NOT NULL` |
| `buyer_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `seller_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `price_integral` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_forum_post_purchase\` (\`post_id\`, \`buyer_user_id\`)`
- `KEY \`idx_forum_post_purchases_buyer\` (\`buyer_user_id\`, \`id\`)`
- `CONSTRAINT \`fk_forum_post_purchases_post\` FOREIGN KEY (\`post_id\`) REFERENCES \`forum_posts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_post_purchases_buyer\` FOREIGN KEY (\`buyer_user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_post_purchases_seller\` FOREIGN KEY (\`seller_user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE`

## `forum_unique_views`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `post_id` | `BIGINT UNSIGNED NOT NULL` |
| `viewer_key` | `CHAR(64) NOT NULL` |
| `user_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `view_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 1` |
| `first_viewed_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `last_viewed_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_forum_unique_views\` (\`post_id\`, \`viewer_key\`)`
- `KEY \`idx_forum_unique_views_app\` (\`app_id\`, \`post_id\`, \`last_viewed_at\`)`
- `CONSTRAINT \`fk_forum_unique_views_post\` FOREIGN KEY (\`post_id\`) REFERENCES \`forum_posts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_unique_views_user\` FOREIGN KEY (\`user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE SET NULL`

## `forum_post_sections`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `post_id` | `BIGINT UNSIGNED NOT NULL` |
| `author_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `section_type` | `VARCHAR(20) NOT NULL DEFAULT 'free'` |
| `title` | `VARCHAR(160) NOT NULL DEFAULT ''` |
| `content` | `LONGTEXT NOT NULL` |
| `tags_json` | `LONGTEXT` |
| `price_balance` | `DECIMAL(18,2) NOT NULL DEFAULT 0.00` |
| `unlock_at` | `DATETIME DEFAULT NULL` |
| `preview_content` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_forum_post_sections_order\` (\`post_id\`, \`sort_order\`)`
- `KEY \`idx_forum_post_sections_app\` (\`app_id\`, \`post_id\`, \`status\`, \`sort_order\`)`
- `CONSTRAINT \`fk_forum_post_sections_post\` FOREIGN KEY (\`post_id\`) REFERENCES \`forum_posts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_post_sections_author\` FOREIGN KEY (\`author_user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE`

## `forum_section_purchases`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `post_id` | `BIGINT UNSIGNED NOT NULL` |
| `section_id` | `BIGINT UNSIGNED NOT NULL` |
| `buyer_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `seller_user_id` | `BIGINT UNSIGNED NOT NULL` |
| `price_balance` | `DECIMAL(18,2) NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_forum_section_purchase\` (\`section_id\`, \`buyer_user_id\`)`
- `KEY \`idx_forum_section_purchases_buyer\` (\`app_id\`, \`buyer_user_id\`, \`id\`)`
- `CONSTRAINT \`fk_forum_section_purchases_section\` FOREIGN KEY (\`section_id\`) REFERENCES \`forum_post_sections\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_section_purchases_buyer\` FOREIGN KEY (\`buyer_user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_forum_section_purchases_seller\` FOREIGN KEY (\`seller_user_id\`) REFERENCES \`users\` (\`id\`) ON DELETE CASCADE`

## `forum_content_favorites`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `target_type` | `VARCHAR(20) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_forum_content_favorites\` (\`app_id\`, \`user_id\`, \`target_type\`, \`target_id\`)`
- `KEY \`idx_forum_content_favorites_target\` (\`app_id\`, \`target_type\`, \`target_id\`)`
- `CONSTRAINT \`fk_forum_content_favorites_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `forum_personal_positions`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `target_type` | `VARCHAR(20) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED NOT NULL` |
| `position` | `VARCHAR(20) NOT NULL DEFAULT 'top'` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_forum_personal_positions\` (\`user_id\`, \`target_type\`, \`target_id\`)`
- `KEY \`idx_forum_personal_positions_order\` (\`app_id\`, \`user_id\`, \`target_type\`, \`position\`, \`sort_order\`)`
- `CONSTRAINT \`fk_forum_personal_positions_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `forum_content_forwards`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `target_type` | `VARCHAR(20) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED NOT NULL` |
| `destination_type` | `VARCHAR(20) NOT NULL` |
| `destination_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_forum_content_forwards_target\` (\`app_id\`, \`target_type\`, \`target_id\`, \`id\`)`
- `KEY \`idx_forum_content_forwards_user\` (\`user_id\`, \`id\`)`
- `CONSTRAINT \`fk_forum_content_forwards_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `software_update_policies`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `issuer_type` | `VARCHAR(20) NOT NULL` |
| `issuer_id` | `BIGINT UNSIGNED NOT NULL` |
| `issuer_level` | `TINYINT UNSIGNED NOT NULL` |
| `edition_code` | `VARCHAR(40) NOT NULL` |
| `target_type` | `VARCHAR(20) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `target_level` | `TINYINT UNSIGNED DEFAULT NULL` |
| `version_name` | `VARCHAR(40) NOT NULL` |
| `version_code` | `INT UNSIGNED NOT NULL` |
| `min_supported_version_code` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `download_url` | `VARCHAR(1000) NOT NULL` |
| `package_name` | `VARCHAR(190) NOT NULL DEFAULT ''` |
| `sha256` | `CHAR(64) NOT NULL DEFAULT ''` |
| `size_bytes` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `release_notes` | `LONGTEXT NOT NULL` |
| `force_update` | `TINYINT NOT NULL DEFAULT 0` |
| `priority` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `starts_at` | `DATETIME DEFAULT NULL` |
| `ends_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_software_update_match\` (\`edition_code\`, \`target_type\`, \`target_id\`, \`target_level\`, \`status\`, \`version_code\`)`
- `KEY \`idx_software_update_issuer\` (\`issuer_type\`, \`issuer_id\`, \`status\`, \`id\`)`

## `maintenance_policies`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `issuer_type` | `VARCHAR(20) NOT NULL` |
| `issuer_id` | `BIGINT UNSIGNED NOT NULL` |
| `issuer_level` | `TINYINT UNSIGNED NOT NULL` |
| `edition_code` | `VARCHAR(40) NOT NULL` |
| `target_type` | `VARCHAR(20) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `target_level` | `TINYINT UNSIGNED DEFAULT NULL` |
| `title` | `VARCHAR(200) NOT NULL` |
| `message` | `LONGTEXT NOT NULL` |
| `forced` | `TINYINT NOT NULL DEFAULT 1` |
| `allowlist_json` | `LONGTEXT` |
| `priority` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `starts_at` | `DATETIME DEFAULT NULL` |
| `ends_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_maintenance_match\` (\`edition_code\`, \`target_type\`, \`target_id\`, \`target_level\`, \`status\`, \`priority\`)`
- `KEY \`idx_maintenance_issuer\` (\`issuer_type\`, \`issuer_id\`, \`status\`, \`id\`)`

## `festival_theme_policies`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `issuer_type` | `VARCHAR(20) NOT NULL` |
| `issuer_id` | `BIGINT UNSIGNED NOT NULL` |
| `issuer_level` | `TINYINT UNSIGNED NOT NULL` |
| `edition_code` | `VARCHAR(40) NOT NULL` |
| `target_type` | `VARCHAR(20) NOT NULL` |
| `target_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `target_level` | `TINYINT UNSIGNED DEFAULT NULL` |
| `theme_code` | `VARCHAR(80) NOT NULL` |
| `title` | `VARCHAR(160) NOT NULL` |
| `greeting` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `background_url` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `accent_color` | `VARCHAR(20) NOT NULL DEFAULT '#1677FF'` |
| `action_text` | `VARCHAR(80) NOT NULL DEFAULT ''` |
| `action_url` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `config_json` | `LONGTEXT` |
| `priority` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `starts_at` | `DATETIME NOT NULL` |
| `ends_at` | `DATETIME NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_festival_theme_match\` (\`edition_code\`, \`target_type\`, \`target_id\`, \`target_level\`, \`status\`, \`starts_at\`, \`ends_at\`)`
- `KEY \`idx_festival_theme_issuer\` (\`issuer_type\`, \`issuer_id\`, \`status\`, \`id\`)`
- `UNIQUE KEY \`uk_festival_theme_issuer_code_start\` (\`issuer_type\`, \`issuer_id\`, \`edition_code\`, \`theme_code\`, \`starts_at\`)`

## `ai_knowledge_documents`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `root_platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `scope_type` | `ENUM('global','platform','admin','app') NOT NULL DEFAULT 'app'` |
| `platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `app_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `title` | `VARCHAR(200) NOT NULL` |
| `content` | `LONGTEXT NOT NULL` |
| `keywords` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `source_url` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `priority` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_by_type` | `VARCHAR(20) NOT NULL DEFAULT 'platform'` |
| `created_by_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_ai_knowledge_scope\` (\`scope_type\`, \`root_platform_id\`, \`platform_id\`, \`admin_id\`, \`app_id\`, \`status\`, \`priority\`)`
- `KEY \`idx_ai_knowledge_creator\` (\`created_by_type\`, \`created_by_id\`, \`id\`)`

## `ai_conversations`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `title` | `VARCHAR(200) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_ai_conversations_user\` (\`app_id\`, \`user_id\`, \`updated_at\`, \`id\`)`
- `CONSTRAINT \`fk_ai_conversations_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `ai_messages`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `conversation_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `role` | `ENUM('user','assistant','system','tool') NOT NULL` |
| `content` | `LONGTEXT NOT NULL` |
| `provider` | `VARCHAR(40) NOT NULL DEFAULT ''` |
| `model` | `VARCHAR(120) NOT NULL DEFAULT ''` |
| `metadata_json` | `LONGTEXT` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_ai_messages_conversation\` (\`conversation_id\`, \`id\`)`
- `KEY \`idx_ai_messages_user\` (\`user_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_ai_messages_conversation\` FOREIGN KEY (\`conversation_id\`) REFERENCES \`ai_conversations\` (\`id\`) ON DELETE CASCADE`

## `poll_categories`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `root_platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `scope_platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `app_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `owner_type` | `VARCHAR(20) NOT NULL` |
| `owner_id` | `BIGINT UNSIGNED NOT NULL` |
| `target_level` | `TINYINT UNSIGNED NOT NULL` |
| `name` | `VARCHAR(100) NOT NULL` |
| `icon` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `color` | `VARCHAR(20) NOT NULL DEFAULT ''` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_poll_categories_scope\` (\`root_platform_id\`, \`target_level\`, \`scope_platform_id\`, \`app_id\`, \`status\`, \`sort_order\`)`
- `KEY \`idx_poll_categories_owner\` (\`owner_type\`, \`owner_id\`, \`status\`)`
- `CONSTRAINT \`fk_poll_categories_root\` FOREIGN KEY (\`root_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_poll_categories_scope\` FOREIGN KEY (\`scope_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_poll_categories_app\` FOREIGN KEY (\`app_id\`) REFERENCES \`apps\` (\`id\`) ON DELETE CASCADE`

## `universal_polls`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `root_platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `scope_platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `app_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `creator_type` | `VARCHAR(20) NOT NULL` |
| `creator_id` | `BIGINT UNSIGNED NOT NULL` |
| `creator_name` | `VARCHAR(100) NOT NULL` |
| `target_level` | `TINYINT UNSIGNED NOT NULL` |
| `scene_type` | `VARCHAR(30) NOT NULL DEFAULT 'activity' COMMENT 'chat/forum/bounty/activity'` |
| `source_type` | `VARCHAR(40) NOT NULL DEFAULT ''` |
| `source_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `title` | `VARCHAR(200) NOT NULL` |
| `description` | `LONGTEXT` |
| `multiple_choice` | `TINYINT NOT NULL DEFAULT 0` |
| `min_select` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `max_select` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `anonymous` | `TINYINT NOT NULL DEFAULT 0` |
| `allow_change` | `TINYINT NOT NULL DEFAULT 0` |
| `result_visibility` | `VARCHAR(20) NOT NULL DEFAULT 'after_vote'` |
| `ballot_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'active'` |
| `starts_at` | `DATETIME DEFAULT NULL` |
| `ends_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |
| `deleted_at` | `DATETIME DEFAULT NULL` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_universal_polls_feed\` (\`root_platform_id\`, \`target_level\`, \`scope_platform_id\`, \`app_id\`, \`status\`, \`id\`)`
- `KEY \`idx_universal_polls_creator\` (\`creator_type\`, \`creator_id\`, \`status\`, \`id\`)`
- `KEY \`idx_universal_polls_scene\` (\`scene_type\`, \`source_type\`, \`source_id\`, \`status\`, \`id\`)`
- `CONSTRAINT \`fk_universal_polls_root\` FOREIGN KEY (\`root_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_universal_polls_scope\` FOREIGN KEY (\`scope_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_universal_polls_app\` FOREIGN KEY (\`app_id\`) REFERENCES \`apps\` (\`id\`) ON DELETE CASCADE`

## `universal_poll_category_links`

| 字段 | SQL 定义 |
| --- | --- |
| `poll_id` | `BIGINT UNSIGNED NOT NULL` |
| `category_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`poll_id\`, \`category_id\`)`
- `KEY \`idx_universal_poll_category\` (\`category_id\`, \`poll_id\`)`
- `CONSTRAINT \`fk_universal_poll_links_poll\` FOREIGN KEY (\`poll_id\`) REFERENCES \`universal_polls\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_universal_poll_links_category\` FOREIGN KEY (\`category_id\`) REFERENCES \`poll_categories\` (\`id\`) ON DELETE CASCADE`

## `universal_poll_options`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `poll_id` | `BIGINT UNSIGNED NOT NULL` |
| `option_text` | `VARCHAR(500) NOT NULL` |
| `image_url` | `VARCHAR(1000) NOT NULL DEFAULT ''` |
| `vote_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_universal_poll_options\` (\`poll_id\`, \`sort_order\`, \`id\`)`
- `CONSTRAINT \`fk_universal_poll_options_poll\` FOREIGN KEY (\`poll_id\`) REFERENCES \`universal_polls\` (\`id\`) ON DELETE CASCADE`

## `universal_poll_ballots`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `poll_id` | `BIGINT UNSIGNED NOT NULL` |
| `actor_type` | `VARCHAR(20) NOT NULL` |
| `actor_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_universal_poll_ballot\` (\`poll_id\`, \`actor_type\`, \`actor_id\`)`
- `KEY \`idx_universal_poll_ballot_actor\` (\`actor_type\`, \`actor_id\`, \`id\`)`
- `CONSTRAINT \`fk_universal_poll_ballots_poll\` FOREIGN KEY (\`poll_id\`) REFERENCES \`universal_polls\` (\`id\`) ON DELETE CASCADE`

## `universal_poll_choices`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `ballot_id` | `BIGINT UNSIGNED NOT NULL` |
| `option_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_universal_poll_choice\` (\`ballot_id\`, \`option_id\`)`
- `KEY \`idx_universal_poll_choice_option\` (\`option_id\`, \`id\`)`
- `CONSTRAINT \`fk_universal_poll_choices_ballot\` FOREIGN KEY (\`ballot_id\`) REFERENCES \`universal_poll_ballots\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_universal_poll_choices_option\` FOREIGN KEY (\`option_id\`) REFERENCES \`universal_poll_options\` (\`id\`) ON DELETE CASCADE`

## `hierarchy_activities`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `root_platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `owner_type` | `ENUM('platform','admin') NOT NULL` |
| `owner_id` | `BIGINT UNSIGNED NOT NULL` |
| `owner_level` | `TINYINT UNSIGNED NOT NULL` |
| `owner_platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `owner_admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `activity_type` | `ENUM('red_packet','lottery','bounty') NOT NULL` |
| `funding_mode` | `ENUM('balance','issued') NOT NULL DEFAULT 'balance'` |
| `title` | `VARCHAR(200) NOT NULL` |
| `description` | `LONGTEXT` |
| `packet_mode` | `ENUM('equal','random') DEFAULT NULL` |
| `total_balance` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `remaining_balance` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `total_slots` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `remaining_slots` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `per_actor_limit` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `rules_json` | `LONGTEXT` |
| `status` | `ENUM('draft','active','completed','closed','cancelled') NOT NULL DEFAULT 'active'` |
| `starts_at` | `DATETIME DEFAULT NULL` |
| `ends_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_hierarchy_activities_feed\` (\`root_platform_id\`, \`status\`, \`activity_type\`, \`starts_at\`, \`ends_at\`, \`id\`)`
- `KEY \`idx_hierarchy_activities_owner\` (\`owner_type\`, \`owner_id\`, \`status\`, \`id\`)`
- `KEY \`idx_hierarchy_activities_branch\` (\`owner_platform_id\`, \`owner_admin_id\`, \`owner_level\`, \`status\`)`
- `CONSTRAINT \`fk_hierarchy_activities_root\` FOREIGN KEY (\`root_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_hierarchy_activities_platform\` FOREIGN KEY (\`owner_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_hierarchy_activities_admin\` FOREIGN KEY (\`owner_admin_id\`) REFERENCES \`admins\` (\`id\`) ON DELETE CASCADE`

## `hierarchy_activity_targets`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `activity_id` | `BIGINT UNSIGNED NOT NULL` |
| `target_scope` | `ENUM('both','visibility','participation') NOT NULL DEFAULT 'both'` |
| `target_type` | `ENUM('level','platform','admin','app','actor') NOT NULL` |
| `target_level` | `TINYINT UNSIGNED DEFAULT NULL` |
| `target_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `actor_type` | `VARCHAR(20) DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_hierarchy_activity_targets_match\` (\`activity_id\`, \`target_scope\`, \`target_type\`, \`target_level\`, \`target_id\`, \`actor_type\`)`
- `CONSTRAINT \`fk_hierarchy_activity_targets_activity\` FOREIGN KEY (\`activity_id\`) REFERENCES \`hierarchy_activities\` (\`id\`) ON DELETE CASCADE`

## `hierarchy_activity_prizes`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `activity_id` | `BIGINT UNSIGNED NOT NULL` |
| `name` | `VARCHAR(120) NOT NULL` |
| `reward_balance` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `weight` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `stock` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `remaining_stock` | `INT UNSIGNED NOT NULL DEFAULT 1` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_hierarchy_activity_prizes_draw\` (\`activity_id\`, \`remaining_stock\`, \`sort_order\`, \`id\`)`
- `CONSTRAINT \`fk_hierarchy_activity_prizes_activity\` FOREIGN KEY (\`activity_id\`) REFERENCES \`hierarchy_activities\` (\`id\`) ON DELETE CASCADE`

## `hierarchy_activity_entries`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `activity_id` | `BIGINT UNSIGNED NOT NULL` |
| `actor_type` | `ENUM('platform','admin','user') NOT NULL` |
| `actor_id` | `BIGINT UNSIGNED NOT NULL` |
| `actor_level` | `TINYINT UNSIGNED NOT NULL` |
| `platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `app_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `entry_type` | `ENUM('claim','draw','award') NOT NULL` |
| `prize_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `reward_balance` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_hierarchy_activity_entries_actor\` (\`activity_id\`, \`actor_type\`, \`actor_id\`, \`entry_type\`, \`id\`)`
- `KEY \`idx_hierarchy_activity_entries_time\` (\`platform_id\`, \`admin_id\`, \`app_id\`, \`created_at\`)`
- `CONSTRAINT \`fk_hierarchy_activity_entries_activity\` FOREIGN KEY (\`activity_id\`) REFERENCES \`hierarchy_activities\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_hierarchy_activity_entries_prize\` FOREIGN KEY (\`prize_id\`) REFERENCES \`hierarchy_activity_prizes\` (\`id\`) ON DELETE SET NULL`

## `hierarchy_activity_submissions`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `activity_id` | `BIGINT UNSIGNED NOT NULL` |
| `actor_type` | `ENUM('platform','admin','user') NOT NULL` |
| `actor_id` | `BIGINT UNSIGNED NOT NULL` |
| `actor_level` | `TINYINT UNSIGNED NOT NULL` |
| `platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `app_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `content` | `LONGTEXT NOT NULL` |
| `attachments_json` | `LONGTEXT` |
| `status` | `ENUM('submitted','accepted','rejected','cancelled') NOT NULL DEFAULT 'submitted'` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_hierarchy_activity_submission_actor\` (\`activity_id\`, \`actor_type\`, \`actor_id\`)`
- `KEY \`idx_hierarchy_activity_submissions_status\` (\`activity_id\`, \`status\`, \`id\`)`
- `CONSTRAINT \`fk_hierarchy_activity_submissions_activity\` FOREIGN KEY (\`activity_id\`) REFERENCES \`hierarchy_activities\` (\`id\`) ON DELETE CASCADE`

## `hierarchy_balance_logs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `root_platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `actor_type` | `ENUM('platform','admin','user') NOT NULL` |
| `actor_id` | `BIGINT UNSIGNED NOT NULL` |
| `actor_level` | `TINYINT UNSIGNED NOT NULL` |
| `change_value` | `DECIMAL(20,2) NOT NULL` |
| `before_value` | `DECIMAL(20,2) NOT NULL` |
| `after_value` | `DECIMAL(20,2) NOT NULL` |
| `scene` | `VARCHAR(60) NOT NULL` |
| `ref_type` | `VARCHAR(40) NOT NULL DEFAULT ''` |
| `ref_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `operator_type` | `VARCHAR(20) NOT NULL DEFAULT ''` |
| `operator_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `remark` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_hierarchy_balance_logs_actor\` (\`actor_type\`, \`actor_id\`, \`created_at\`)`
- `KEY \`idx_hierarchy_balance_logs_root\` (\`root_platform_id\`, \`scene\`, \`created_at\`)`
- `CONSTRAINT \`fk_hierarchy_balance_logs_root\` FOREIGN KEY (\`root_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`

## `app_visit_events`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `visitor_hash` | `CHAR(64) NOT NULL` |
| `visit_date` | `DATE NOT NULL` |
| `visit_count` | `BIGINT UNSIGNED NOT NULL DEFAULT 1` |
| `source` | `VARCHAR(50) NOT NULL DEFAULT 'app'` |
| `last_path` | `VARCHAR(255) NOT NULL DEFAULT ''` |
| `last_ip_hash` | `CHAR(64) NOT NULL DEFAULT ''` |
| `last_user_agent` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `first_visited_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `last_visited_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_app_visit_daily_visitor\` (\`app_id\`, \`visit_date\`, \`visitor_hash\`)`
- `KEY \`idx_app_visit_tenant_time\` (\`admin_id\`, \`app_id\`, \`last_visited_at\`)`
- `CONSTRAINT \`fk_app_visit_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_presence`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'online'` |
| `device` | `VARCHAR(100) NOT NULL DEFAULT ''` |
| `last_ip_hash` | `CHAR(64) NOT NULL DEFAULT ''` |
| `last_heartbeat_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `online_until` | `DATETIME NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_user_presence_user\` (\`user_id\`)`
- `KEY \`idx_user_presence_online\` (\`app_id\`, \`online_until\`, \`status\`)`
- `CONSTRAINT \`fk_user_presence_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `card_login_bindings`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `card_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `device_hash` | `CHAR(64) NOT NULL` |
| `device_secret_hash` | `CHAR(64) NOT NULL` |
| `device_label` | `VARCHAR(100) NOT NULL DEFAULT ''` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `bound_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `last_login_at` | `DATETIME DEFAULT NULL` |
| `expired_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_card_login_binding_card\` (\`card_id\`)`
- `UNIQUE KEY \`uk_card_login_binding_device\` (\`app_id\`, \`device_hash\`)`
- `KEY \`idx_card_login_binding_user\` (\`app_id\`, \`user_id\`, \`status\`)`
- `CONSTRAINT \`fk_card_login_binding_card\` FOREIGN KEY (\`card_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`cards\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_card_login_binding_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `forum_report_tags`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `name` | `VARCHAR(80) NOT NULL` |
| `description` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_forum_report_tag_name\` (\`app_id\`, \`name\`)`
- `KEY \`idx_forum_report_tag_tenant\` (\`admin_id\`, \`app_id\`, \`status\`, \`sort_order\`)`
- `CONSTRAINT \`fk_forum_report_tag_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `user_moments`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `content` | `TEXT NOT NULL` |
| `location_name` | `VARCHAR(200) NOT NULL DEFAULT ''` |
| `latitude` | `DECIMAL(10,7) DEFAULT NULL` |
| `longitude` | `DECIMAL(10,7) DEFAULT NULL` |
| `visibility_mode` | `VARCHAR(20) NOT NULL DEFAULT 'inherit'` |
| `visible_days` | `SMALLINT UNSIGNED DEFAULT NULL` |
| `visibility_user_ids_json` | `LONGTEXT DEFAULT NULL` |
| `is_pinned` | `TINYINT(1) NOT NULL DEFAULT 0` |
| `pin_order` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `edited_at` | `DATETIME DEFAULT NULL` |
| `deleted_at` | `DATETIME DEFAULT NULL` |
| `delete_expires_at` | `DATETIME DEFAULT NULL` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_user_moments_feed\` (\`admin_id\`, \`app_id\`, \`status\`, \`deleted_at\`, \`created_at\`)`
- `KEY \`idx_user_moments_owner\` (\`user_id\`, \`created_at\`)`
- `KEY \`idx_user_moments_pinned\` (\`user_id\`, \`is_pinned\`, \`pin_order\`, \`created_at\`)`
- `KEY \`idx_user_moments_purge\` (\`app_id\`, \`delete_expires_at\`)`
- `CONSTRAINT \`fk_user_moments_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`)`
- `REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `moment_likes`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `moment_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_moment_likes_user\` (\`moment_id\`, \`user_id\`)`
- `KEY \`idx_moment_likes_tenant\` (\`admin_id\`, \`app_id\`, \`user_id\`, \`id\`)`
- `CONSTRAINT \`fk_moment_likes_moment\` FOREIGN KEY (\`moment_id\`) REFERENCES \`user_moments\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_moment_likes_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `moment_comments`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `moment_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `parent_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `sticker_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `content` | `VARCHAR(2000) NOT NULL` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_moment_comments_feed\` (\`moment_id\`, \`status\`, \`id\`)`
- `KEY \`idx_moment_comments_user\` (\`app_id\`, \`user_id\`, \`id\`)`
- `CONSTRAINT \`fk_moment_comments_moment\` FOREIGN KEY (\`moment_id\`) REFERENCES \`user_moments\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_moment_comments_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_moment_comments_parent\` FOREIGN KEY (\`parent_id\`) REFERENCES \`moment_comments\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_moment_comments_sticker\` FOREIGN KEY (\`sticker_id\`) REFERENCES \`stickers\` (\`id\`) ON DELETE SET NULL`

## `moment_comment_likes`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `comment_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_moment_comment_likes_user\` (\`comment_id\`, \`user_id\`)`
- `KEY \`idx_moment_comment_likes_tenant\` (\`admin_id\`, \`app_id\`, \`user_id\`, \`id\`)`
- `CONSTRAINT \`fk_moment_comment_likes_comment\` FOREIGN KEY (\`comment_id\`) REFERENCES \`moment_comments\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_moment_comment_likes_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `moment_favorites`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `moment_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_moment_favorites_user\` (\`moment_id\`, \`user_id\`)`
- `KEY \`idx_moment_favorites_user\` (\`app_id\`, \`user_id\`, \`id\`)`
- `CONSTRAINT \`fk_moment_favorites_moment\` FOREIGN KEY (\`moment_id\`) REFERENCES \`user_moments\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_moment_favorites_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `moment_forwards`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `moment_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `target_type` | `VARCHAR(20) NOT NULL DEFAULT 'external'` |
| `target_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `KEY \`idx_moment_forwards_moment\` (\`moment_id\`, \`id\`)`
- `KEY \`idx_moment_forwards_user\` (\`app_id\`, \`user_id\`, \`id\`)`
- `CONSTRAINT \`fk_moment_forwards_moment\` FOREIGN KEY (\`moment_id\`) REFERENCES \`user_moments\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_moment_forwards_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`

## `business_catalogs`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `catalog_code` | `VARCHAR(30) NOT NULL COMMENT 'resource/shop/balance_shop'` |
| `catalog_name` | `VARCHAR(50) NOT NULL` |
| `description` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_business_catalogs_app_code\` (\`app_id\`, \`catalog_code\`)`
- `KEY \`idx_business_catalogs_tenant\` (\`admin_id\`, \`app_id\`, \`status\`, \`sort_order\`)`
- `CONSTRAINT \`fk_business_catalogs_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `interaction_scene_links`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `root_platform_id` | `BIGINT UNSIGNED NOT NULL` |
| `scope_platform_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `admin_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `app_id` | `BIGINT UNSIGNED DEFAULT NULL` |
| `entity_type` | `VARCHAR(30) NOT NULL COMMENT 'red_packet/vote/lottery/reward'` |
| `entity_id` | `BIGINT UNSIGNED NOT NULL` |
| `scene_type` | `VARCHAR(30) NOT NULL COMMENT 'chat/forum/bounty/earned/activity'` |
| `source_type` | `VARCHAR(40) NOT NULL DEFAULT ''` |
| `source_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `creator_type` | `VARCHAR(20) NOT NULL` |
| `creator_id` | `BIGINT UNSIGNED NOT NULL` |
| `target_level` | `TINYINT UNSIGNED NOT NULL DEFAULT 4` |
| `visible_levels_json` | `LONGTEXT` |
| `manageable_levels_json` | `LONGTEXT` |
| `policy_json` | `LONGTEXT` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_interaction_scene_entity\` (\`entity_type\`, \`entity_id\`)`
- `KEY \`idx_interaction_scene_source\` (\`scene_type\`, \`source_type\`, \`source_id\`, \`status\`)`
- `KEY \`idx_interaction_scene_scope\` (\`root_platform_id\`, \`scope_platform_id\`, \`app_id\`, \`target_level\`, \`status\`)`
- `CONSTRAINT \`fk_interaction_scene_root\` FOREIGN KEY (\`root_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_interaction_scene_scope\` FOREIGN KEY (\`scope_platform_id\`) REFERENCES \`platform_accounts\` (\`id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_interaction_scene_app\` FOREIGN KEY (\`app_id\`) REFERENCES \`apps\` (\`id\`) ON DELETE CASCADE`

## `app_reward_rules`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `scene_code` | `VARCHAR(60) NOT NULL` |
| `scene_name` | `VARCHAR(100) NOT NULL` |
| `description` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `enabled` | `TINYINT NOT NULL DEFAULT 0` |
| `reward_json` | `LONGTEXT NOT NULL` |
| `grant_mode` | `VARCHAR(20) NOT NULL DEFAULT 'automatic' COMMENT 'automatic/after_review/manual'` |
| `cycle_type` | `VARCHAR(20) NOT NULL DEFAULT 'unlimited' COMMENT 'once/daily/weekly/monthly/unlimited'` |
| `cycle_limit` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `user_total_limit` | `INT UNSIGNED NOT NULL DEFAULT 0` |
| `conditions_json` | `LONGTEXT` |
| `audience_json` | `LONGTEXT` |
| `manager_level` | `TINYINT UNSIGNED NOT NULL DEFAULT 3` |
| `inherited_from_type` | `VARCHAR(20) NOT NULL DEFAULT ''` |
| `inherited_from_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `force_sync` | `TINYINT NOT NULL DEFAULT 0` |
| `created_by_type` | `VARCHAR(20) NOT NULL DEFAULT 'system'` |
| `created_by_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `status` | `TINYINT NOT NULL DEFAULT 1` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_app_reward_rules_scene\` (\`app_id\`, \`scene_code\`)`
- `UNIQUE KEY \`uk_app_reward_rules_id_tenant\` (\`id\`, \`app_id\`, \`admin_id\`)`
- `KEY \`idx_app_reward_rules_manage\` (\`admin_id\`, \`app_id\`, \`enabled\`, \`status\`, \`scene_code\`)`
- `CONSTRAINT \`fk_app_reward_rules_app\` FOREIGN KEY (\`app_id\`, \`admin_id\`) REFERENCES \`apps\` (\`id\`, \`admin_id\`) ON DELETE CASCADE`

## `app_reward_events`

| 字段 | SQL 定义 |
| --- | --- |
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` |
| `admin_id` | `BIGINT UNSIGNED NOT NULL` |
| `app_id` | `BIGINT UNSIGNED NOT NULL` |
| `user_id` | `BIGINT UNSIGNED NOT NULL` |
| `rule_id` | `BIGINT UNSIGNED NOT NULL` |
| `scene_code` | `VARCHAR(60) NOT NULL` |
| `ref_type` | `VARCHAR(40) NOT NULL DEFAULT ''` |
| `ref_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `period_key` | `VARCHAR(40) NOT NULL DEFAULT ''` |
| `dedupe_key` | `VARCHAR(191) NOT NULL` |
| `reward_json` | `LONGTEXT NOT NULL` |
| `context_json` | `LONGTEXT` |
| `status` | `VARCHAR(20) NOT NULL DEFAULT 'granted' COMMENT 'pending/granted/rejected/reversed'` |
| `reason` | `VARCHAR(500) NOT NULL DEFAULT ''` |
| `actor_type` | `VARCHAR(20) NOT NULL DEFAULT 'system'` |
| `actor_id` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` |
| `granted_at` | `DATETIME DEFAULT NULL` |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` |

**索引与约束**

- `PRIMARY KEY (\`id\`)`
- `UNIQUE KEY \`uk_app_reward_events_dedupe\` (\`app_id\`, \`dedupe_key\`)`
- `KEY \`idx_app_reward_events_user\` (\`app_id\`, \`user_id\`, \`scene_code\`, \`created_at\`)`
- `KEY \`idx_app_reward_events_manage\` (\`admin_id\`, \`app_id\`, \`status\`, \`created_at\`)`
- `CONSTRAINT \`fk_app_reward_events_rule\` FOREIGN KEY (\`rule_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`app_reward_rules\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
- `CONSTRAINT \`fk_app_reward_events_user\` FOREIGN KEY (\`user_id\`, \`app_id\`, \`admin_id\`) REFERENCES \`users\` (\`id\`, \`app_id\`, \`admin_id\`) ON DELETE CASCADE`
