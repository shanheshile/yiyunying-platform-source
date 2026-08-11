-- Four-tab administrator workbench, source categories, community categories and sponsor profile.
SET NAMES utf8mb4;
SET @schema_name := DATABASE();

SET @has_app_type := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'apps' AND COLUMN_NAME = 'app_type'
);
SET @app_type_sql := IF(
  @has_app_type = 0,
  'ALTER TABLE apps ADD COLUMN app_type VARCHAR(30) NOT NULL DEFAULT ''general'' AFTER name',
  'SELECT 1'
);
PREPARE app_type_statement FROM @app_type_sql;
EXECUTE app_type_statement;
DEALLOCATE PREPARE app_type_statement;

SET @has_community_category := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'level_forum_posts' AND COLUMN_NAME = 'category_code'
);
SET @community_category_sql := IF(
  @has_community_category = 0,
  'ALTER TABLE level_forum_posts ADD COLUMN category_code VARCHAR(30) NOT NULL DEFAULT ''general'' AFTER author_name',
  'SELECT 1'
);
PREPARE community_category_statement FROM @community_category_sql;
EXECUTE community_category_statement;
DEALLOCATE PREPARE community_category_statement;

CREATE TABLE IF NOT EXISTS admin_public_profiles (
  admin_id BIGINT UNSIGNED NOT NULL,
  official_url VARCHAR(1000) NOT NULL DEFAULT '',
  download_url VARCHAR(1000) NOT NULL DEFAULT '',
  official_qq_group VARCHAR(100) NOT NULL DEFAULT '',
  official_qq_group_link VARCHAR(1000) NOT NULL DEFAULT '',
  alipay_qr_url VARCHAR(1000) NOT NULL DEFAULT '',
  wechat_qr_url VARCHAR(1000) NOT NULL DEFAULT '',
  software_intro TEXT,
  about_us TEXT,
  revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (admin_id),
  CONSTRAINT fk_admin_public_profiles_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_sponsor_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id BIGINT UNSIGNED NOT NULL,
  sponsor_name VARCHAR(100) NOT NULL,
  amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  channel VARCHAR(20) NOT NULL DEFAULT 'manual',
  note VARCHAR(500) NOT NULL DEFAULT '',
  paid_at DATETIME NOT NULL,
  status TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_admin_sponsor_rank (admin_id, status, amount, paid_at, id),
  CONSTRAINT fk_admin_sponsor_records_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS level_forum_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id BIGINT UNSIGNED NOT NULL,
  reporter_type VARCHAR(20) NOT NULL,
  reporter_id BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(500) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  handled_by_type VARCHAR(20) DEFAULT NULL,
  handled_by_id BIGINT UNSIGNED DEFAULT NULL,
  handle_remark VARCHAR(500) NOT NULL DEFAULT '',
  handled_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_level_forum_reports_post (post_id, status, id),
  KEY idx_level_forum_reports_actor (reporter_type, reporter_id, status),
  CONSTRAINT fk_level_forum_reports_post FOREIGN KEY (post_id) REFERENCES level_forum_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO resource_categories
  (admin_id, app_id, resource_type, name, icon, description, sort_order, status, created_at, updated_at)
SELECT apps.admin_id, apps.id, 'source_market', source_categories.name, '', source_categories.description,
       source_categories.sort_order, 1, NOW(), NOW()
FROM apps
CROSS JOIN (
  SELECT 'Android Java 源码' AS name, 'Android 原生 Java 源码、示例与完整工程' AS description, 130 AS sort_order
  UNION ALL SELECT 'iApp 源码', 'iApp 源码、界面示例与可复用模块', 120
  UNION ALL SELECT 'Lua 源码', 'Lua 脚本、源码模块与完整示例', 110
  UNION ALL SELECT 'Web 源码', '网页、前端界面与 Web 完整工程源码', 100
  UNION ALL SELECT 'PHP 源码', 'PHP 服务端源码、接口与完整工程', 90
  UNION ALL SELECT 'Python 源码', 'Python 脚本、服务与完整工程源码', 80
  UNION ALL SELECT 'JavaScript 源码', 'JavaScript、Node.js 与前端框架源码', 70
  UNION ALL SELECT 'HarmonyOS 源码', 'HarmonyOS、ArkTS 与鸿蒙应用源码', 60
  UNION ALL SELECT 'iOS 源码', 'iOS、Swift 与苹果应用完整源码', 50
  UNION ALL SELECT 'C/C++ 源码', 'C、C++ 源码、库与完整工程', 40
  UNION ALL SELECT '数据库源码', '数据库脚本、结构、查询与迁移源码', 30
  UNION ALL SELECT '通用模块', '好友聊天、群聊、登录注册、论坛、文档和商城等独立模块', 20
  UNION ALL SELECT '其他源码', '未归入标准技术分类的其他源码与示例', 10
) AS source_categories
WHERE apps.deleted_at IS NULL
ON DUPLICATE KEY UPDATE description = VALUES(description), sort_order = VALUES(sort_order), status = 1, updated_at = NOW();

-- Preserve existing resources while consolidating all historical built-in names into the
-- canonical Chinese taxonomy. Custom administrator categories are left untouched.
UPDATE resources AS resource
JOIN resource_categories AS legacy
  ON legacy.id = resource.category_id
 AND legacy.admin_id = resource.admin_id
 AND legacy.app_id = resource.app_id
 AND legacy.resource_type = 'source_market'
JOIN resource_categories AS canonical
  ON canonical.admin_id = legacy.admin_id
 AND canonical.app_id = legacy.app_id
 AND canonical.resource_type = 'source_market'
 AND canonical.name = CASE legacy.name
   WHEN 'Android' THEN 'Android Java 源码'
   WHEN 'Java' THEN 'Android Java 源码'
   WHEN 'iApp' THEN 'iApp 源码'
   WHEN 'Lua' THEN 'Lua 源码'
   WHEN 'Web' THEN 'Web 源码'
   WHEN 'PHP' THEN 'PHP 源码'
   WHEN 'Python' THEN 'Python 源码'
   WHEN 'JavaScript' THEN 'JavaScript 源码'
   WHEN 'HarmonyOS' THEN 'HarmonyOS 源码'
   WHEN 'iOS' THEN 'iOS 源码'
   WHEN 'C/C++' THEN 'C/C++ 源码'
   WHEN '数据库' THEN '数据库源码'
   WHEN 'Rust' THEN '其他源码'
   WHEN '其他' THEN '其他源码'
   ELSE legacy.name
 END
SET resource.category_id = canonical.id, resource.updated_at = NOW()
WHERE legacy.name IN (
  'Android', 'Java', 'iApp', 'Lua', 'Web', 'PHP', 'Python', 'JavaScript',
  'HarmonyOS', 'iOS', 'C/C++', '数据库', 'Rust', '其他'
);

UPDATE resource_categories
SET status = 0, updated_at = NOW()
WHERE resource_type = 'source_market'
  AND name IN (
    'Android', 'Java', 'iApp', 'Lua', 'Web', 'PHP', 'Python', 'JavaScript',
    'HarmonyOS', 'iOS', 'C/C++', '数据库', 'Rust', '其他'
  );

INSERT INTO schema_migrations (version, description, applied_at)
VALUES ('2026.08.11-management-shell-restructure', 'Administrator four-tab workbench, sponsor profile and categorized community', NOW())
ON DUPLICATE KEY UPDATE description = VALUES(description), applied_at = VALUES(applied_at);
