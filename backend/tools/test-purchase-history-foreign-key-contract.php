<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'install' => $root . '/database/install.sql',
    'migration' => $root . '/database/migrations/upgrade_20260811_resource_store_review_closure.sql',
    'controller' => $root . '/app/Controllers/Admin/ResourceController.php',
];

$source = [];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $source[$name] = (string) file_get_contents($path);
}

/**
 * Return one CREATE TABLE body, normalised for stable static assertions.
 */
function tableBody(string $sql, string $table): string
{
    $pattern = '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?'
        . preg_quote($table, '/')
        . '`?\s*\((.*?)\)\s*ENGINE\s*=/is';
    if (preg_match($pattern, $sql, $matches) !== 1) {
        fwrite(STDERR, "Purchase history foreign-key contract failed: missing table {$table}\n");
        exit(1);
    }

    return normaliseSql($matches[1]);
}

function normaliseSql(string $sql): string
{
    return trim((string) preg_replace('/\s+/', ' ', str_replace('`', '', $sql)));
}

function methodSlice(string $source, string $method, string $nextMethod): string
{
    $startNeedle = "public static function {$method}(";
    $endNeedle = "public static function {$nextMethod}(";
    $start = strpos($source, $startNeedle);
    $end = $start === false ? false : strpos($source, $endNeedle, $start + strlen($startNeedle));
    if ($start === false || $end === false) {
        fwrite(STDERR, "Catalog retention contract failed: missing method boundary {$method}\n");
        exit(1);
    }

    return normaliseSql(substr($source, $start, $end - $start));
}

$installResource = tableBody($source['install'], 'resource_purchases');
$installStore = tableBody($source['install'], 'store_app_purchases');
$installResources = tableBody($source['install'], 'resources');
$installStoreApps = tableBody($source['install'], 'store_apps');
$migrationStore = tableBody($source['migration'], 'store_app_purchases');
$migration = normaliseSql($source['migration']);
$deleteCategory = methodSlice($source['controller'], 'deleteCategory', 'resources');
$deleteStoreCategory = methodSlice($source['controller'], 'deleteStoreCategory', 'storeApps');

$resourceSubject = 'CONSTRAINT fk_resource_purchase_resource FOREIGN KEY (resource_id, app_id, admin_id) REFERENCES resources (id, app_id, admin_id) ON DELETE RESTRICT';
$resourceBuyer = 'CONSTRAINT fk_resource_purchase_buyer FOREIGN KEY (buyer_user_id, app_id, admin_id) REFERENCES users (id, app_id, admin_id) ON DELETE RESTRICT';
$resourceSeller = 'CONSTRAINT fk_resource_purchase_seller FOREIGN KEY (seller_user_id) REFERENCES users (id) ON DELETE SET NULL';
$storeSubject = 'CONSTRAINT fk_store_app_purchase_app FOREIGN KEY (store_app_id, app_id, admin_id) REFERENCES store_apps (id, app_id, admin_id) ON DELETE RESTRICT';
$storeBuyer = 'CONSTRAINT fk_store_app_purchase_buyer FOREIGN KEY (buyer_user_id, app_id, admin_id) REFERENCES users (id, app_id, admin_id) ON DELETE RESTRICT';
$storeSeller = 'CONSTRAINT fk_store_app_purchase_seller FOREIGN KEY (seller_user_id) REFERENCES users (id) ON DELETE SET NULL';
$resourceSubjectShape = 'FOREIGN KEY (resource_id, app_id, admin_id) REFERENCES resources (id, app_id, admin_id) ON DELETE RESTRICT';
$resourceBuyerShape = 'FOREIGN KEY (buyer_user_id, app_id, admin_id) REFERENCES users (id, app_id, admin_id) ON DELETE RESTRICT';
$sellerShape = 'FOREIGN KEY (seller_user_id) REFERENCES users (id) ON DELETE SET NULL';
$storeSubjectShape = 'FOREIGN KEY (store_app_id, app_id, admin_id) REFERENCES store_apps (id, app_id, admin_id) ON DELETE RESTRICT';
$resourceCategory = 'CONSTRAINT fk_resources_category FOREIGN KEY (category_id, app_id, admin_id) REFERENCES resource_categories (id, app_id, admin_id) ON DELETE RESTRICT';
$storeCategory = 'CONSTRAINT fk_store_apps_category FOREIGN KEY (category_id, app_id, admin_id) REFERENCES store_categories (id, app_id, admin_id) ON DELETE RESTRICT';
$resourceCategoryShape = 'FOREIGN KEY (category_id, app_id, admin_id) REFERENCES resource_categories (id, app_id, admin_id) ON DELETE RESTRICT';
$storeCategoryShape = 'FOREIGN KEY (category_id, app_id, admin_id) REFERENCES store_categories (id, app_id, admin_id) ON DELETE RESTRICT';

$checks = [
    'fresh resource purchases retain subject and buyer while anonymising seller' =>
        str_contains($installResource, $resourceSubject)
        && str_contains($installResource, $resourceBuyer)
        && str_contains($installResource, $resourceSeller)
        && preg_match('/seller_user_id BIGINT UNSIGNED DEFAULT NULL/', $installResource) === 1
        && !str_contains($installResource, 'ON DELETE CASCADE'),
    'fresh store purchases retain subject and buyer while anonymising seller' =>
        str_contains($installStore, $storeSubject)
        && str_contains($installStore, $storeBuyer)
        && str_contains($installStore, $storeSeller)
        && preg_match('/seller_user_id BIGINT UNSIGNED DEFAULT NULL/', $installStore) === 1
        && !str_contains($installStore, 'ON DELETE CASCADE'),
    'migration creates new store purchase tables with the same retention policy' =>
        str_contains($migrationStore, $storeSubject)
        && str_contains($migrationStore, $storeBuyer)
        && str_contains($migrationStore, $storeSeller)
        && preg_match('/seller_user_id BIGINT UNSIGNED DEFAULT NULL/', $migrationStore) === 1
        && !str_contains($migrationStore, 'ON DELETE CASCADE'),
    'migration discovers all six purchase foreign keys from metadata' =>
        substr_count($source['migration'], "COLUMN_NAME = 'resource_id'") === 1
        && substr_count($source['migration'], "COLUMN_NAME = 'store_app_id'") === 1
        && substr_count($source['migration'], "COLUMN_NAME = 'buyer_user_id'") === 2
        && substr_count($source['migration'], "COLUMN_NAME = 'seller_user_id'") === 2
        && substr_count($source['migration'], 'information_schema.REFERENTIAL_CONSTRAINTS') >= 6
        && substr_count($source['migration'], '@purchase_fk_name IS NULL') === 6,
    'migration upgrades wrong rules atomically without an unprotected gap' =>
        substr_count($source['migration'], 'DROP FOREIGN KEY') >= 6
        && substr_count($source['migration'], "@purchase_fk_delete_rule IN ('RESTRICT', 'NO ACTION')") === 4
        && substr_count($source['migration'], "@purchase_fk_delete_rule = 'SET NULL' AND @purchase_fk_column_count = 1") === 2
        && substr_count($source['migration'], 'DROP FOREIGN KEY `') >= 6
        && substr_count($source['migration'], '`, ADD CONSTRAINT `') >= 6,
    'migration has create missing and replacement definitions for every purchase role' =>
        substr_count($migration, $resourceSubjectShape) === 2
        && substr_count($migration, $storeSubjectShape) === 3
        && substr_count($migration, $resourceBuyerShape) === 5
        && substr_count($migration, $sellerShape) === 5,
    'seller set-null constraints are valid single-column foreign keys' =>
        !preg_match('/FOREIGN KEY \(seller_user_id,\s*app_id,\s*admin_id\).*?ON DELETE SET NULL/i', $installResource)
        && !preg_match('/FOREIGN KEY \(seller_user_id,\s*app_id,\s*admin_id\).*?ON DELETE SET NULL/i', $installStore)
        && substr_count($source['migration'], '@purchase_fk_column_count = 1') === 2,
    'dynamic SQL is safe under ANSI_QUOTES and quotes discovered identifiers' =>
        !str_contains($source['migration'], '"')
        && substr_count($source['migration'], "REPLACE(@purchase_fk_name, '`', '``')") === 12,
    'migration never deletes or truncates purchase history' =>
        preg_match('/\bDELETE\s+FROM\s+`?(resource_purchases|store_app_purchases)`?/i', $source['migration']) !== 1
        && preg_match('/\bTRUNCATE(?:\s+TABLE)?\s+`?(resource_purchases|store_app_purchases)`?/i', $source['migration']) !== 1
        && preg_match('/\bDROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?(resource_purchases|store_app_purchases)`?/i', $source['migration']) !== 1,
    'fresh catalog category foreign keys preserve every current or historical child' =>
        str_contains($installResources, $resourceCategory)
        && str_contains($installStoreApps, $storeCategory),
    'migration discovers and idempotently replaces both category cascade rules' =>
        substr_count($source['migration'], "COLUMN_NAME = 'category_id'") === 2
        && substr_count($source['migration'], '@category_fk_name IS NULL') === 2
        && substr_count($source['migration'], "@category_fk_delete_rule IN ('RESTRICT', 'NO ACTION')") === 2
        && substr_count($source['migration'], "REPLACE(@category_fk_name, '`', '``')") === 4
        && substr_count($migration, $resourceCategoryShape) === 2
        && substr_count($migration, $storeCategoryShape) === 2,
    'resource category delete locks the tenant row and rejects all child history with 409' =>
        str_contains($deleteCategory, 'Database::transaction(static function')
        && str_contains($deleteCategory, 'SELECT * FROM resource_categories WHERE id = ? AND admin_id = ? AND app_id = ? FOR UPDATE')
        && str_contains($deleteCategory, 'SELECT id FROM resources WHERE category_id = ? AND admin_id = ? AND app_id = ? LIMIT 1 FOR UPDATE')
        && !str_contains($deleteCategory, 'deleted_at IS NULL')
        && str_contains($deleteCategory, "new HttpException('分类下存在资源历史（含已删除资源），不能删除；可停用分类但必须保留历史归属', 0, 409)")
        && str_contains($deleteCategory, 'DELETE FROM resource_categories WHERE id = ? AND admin_id = ? AND app_id = ?')
        && str_contains($deleteCategory, "'resource_category', 'delete'")
        && str_contains($deleteCategory, 'LogService::adminOperation('),
    'store category delete locks the tenant row and rejects all child history with 409' =>
        str_contains($deleteStoreCategory, 'Database::transaction(static function')
        && str_contains($deleteStoreCategory, 'SELECT * FROM store_categories WHERE id = ? AND admin_id = ? AND app_id = ? FOR UPDATE')
        && str_contains($deleteStoreCategory, 'SELECT id FROM store_apps WHERE category_id = ? AND admin_id = ? AND app_id = ? LIMIT 1 FOR UPDATE')
        && !str_contains($deleteStoreCategory, 'deleted_at IS NULL')
        && str_contains($deleteStoreCategory, "new HttpException('分类下存在应用历史（含已删除应用），不能删除；可停用分类但必须保留历史归属', 0, 409)")
        && str_contains($deleteStoreCategory, 'DELETE FROM store_categories WHERE id = ? AND admin_id = ? AND app_id = ?')
        && str_contains($deleteStoreCategory, "'store_category', 'delete'")
        && str_contains($deleteStoreCategory, 'LogService::adminOperation('),
    'category migration never deletes catalog history' =>
        preg_match('/\bDELETE\s+FROM\s+`?(resource_categories|store_categories|resources|store_apps)`?/i', $source['migration']) !== 1
        && preg_match('/\bTRUNCATE(?:\s+TABLE)?\s+`?(resource_categories|store_categories|resources|store_apps)`?/i', $source['migration']) !== 1,
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Purchase history foreign-key contract failed: {$name}\n");
        exit(1);
    }
}

echo "Purchase history foreign-key contract: passed\n";
