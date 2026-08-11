<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'install' => $root . '/database/install.sql',
    'migration' => $root . '/database/migrations/upgrade_20260811_resource_store_review_closure.sql',
    'routes' => $root . '/routes/api.php',
    'admin' => $root . '/app/Controllers/Admin/ResourceController.php',
    'user' => $root . '/app/Controllers/User/ResourceController.php',
    'public' => $root . '/app/Controllers/PublicApi/ResourceController.php',
    'favorite' => $root . '/app/Controllers/User/FavoriteController.php',
    'app_service' => $root . '/app/Services/AppService.php',
    'settings' => $root . '/app/Services/SettingDescriptorService.php',
    'inspection' => $root . '/app/Services/SubmissionInspectionService.php',
    'media' => $root . '/app/Services/MessageMediaService.php',
    'upload_storage' => $root . '/app/Services/UploadStorageService.php',
    'upload_library' => $root . '/app/Services/UploadLibraryService.php',
    'catalog_download' => $root . '/app/Services/CatalogDownloadService.php',
    'response' => $root . '/app/Core/Response.php',
    'migrator' => $root . '/tools/migrate-catalog-private-files.php',
    'migration_verifier' => $root . '/tools/verify-catalog-migration-report.php',
    'modules' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/domain/module/ModuleRegistry.java',
    'generic' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/ui/module/GenericModuleFragment.java',
    'display_text' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/ui/common/DisplayText.java',
    'hall' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/ui/resource/ResourceHallActivity.java',
    'submit' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/ui/resource/ResourceSubmitActivity.java',
    'hall_layout' => dirname($root) . '/android/app/src/main/res/layout/activity_resource_hall.xml',
];

$source = [];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $source[$name] = (string) file_get_contents($path);
}

$checks = [
    'portable idempotent migration adds both reviewer pairs' =>
        !preg_match('/\bDELIMITER\b|CREATE\s+PROCEDURE/i', $source['migration'])
        && substr_count($source['migration'], "COLUMN_NAME = 'audited_by'") === 2
        && substr_count($source['migration'], "COLUMN_NAME = 'audited_at'") === 2
        && str_contains($source['migration'], 'fk_resources_auditor')
        && str_contains($source['migration'], 'fk_store_apps_auditor'),
    'fresh schema persists reviewer and review time for resources and apps' =>
        substr_count($source['install'], '`audited_by` BIGINT UNSIGNED DEFAULT NULL') >= 4
        && str_contains($source['install'], 'fk_resources_auditor')
        && str_contains($source['install'], 'fk_store_apps_auditor'),
    'four independent submission and review controls have defaults descriptors and migration seeds' =>
        array_reduce([
            'resource_user_submit_enabled', 'resource_submit_audit',
            'store_user_submit_enabled', 'store_submit_audit',
        ], static fn(bool $ok, string $key): bool => $ok
            && str_contains($source['install'], "'{$key}'")
            && str_contains($source['migration'], "'{$key}'")
            && str_contains($source['app_service'], "'{$key}' => true")
            && str_contains($source['settings'], "'{$key}' =>"), true),
    'administrator routes expose list detail review edit and delete management' =>
        str_contains($source['routes'], "/resources/{resource_id}', [AdminResource::class, 'showResource']")
        && str_contains($source['routes'], "/store-apps/{store_app_id}', [AdminResource::class, 'showStoreApp']")
        && str_contains($source['routes'], "[AdminResource::class, 'updateStoreApp']")
        && str_contains($source['routes'], "[AdminResource::class, 'deleteStoreApp']")
        && str_contains($source['routes'], "[AdminResource::class, 'updateStoreCategory']")
        && str_contains($source['routes'], "[AdminResource::class, 'deleteStoreCategory']"),
    'three-state review validates reason and exposes hold labels' =>
        str_contains($source['admin'], "['approved', 'rejected', 'on_hold']")
        && str_contains($source['admin'], '暂定时必须填写原因与后续要求')
        && str_contains($source['inspection'], "'on_hold' => '暂定'"),
    'resource and app review use row locks stale-state guards audit logs and author notifications' =>
        substr_count($source['admin'], 'FOR UPDATE') >= 2
        && substr_count($source['admin'], 'assertExpectedSnapshot(') >= 2
        && str_contains($source['admin'], 'expected_review_revision')
        && str_contains($source['inspection'], 'reviewRevision(')
        && str_contains($source['media'], "['review_identity']")
        && str_contains($source['admin'], "'resource_moderation'")
        && str_contains($source['admin'], "'store_app_moderation'")
        && substr_count($source['admin'], 'notifyReview(') >= 3,
    'repeated identical decisions are idempotent without a second write' =>
        substr_count($source['admin'], "'changed' => false") >= 2
        && substr_count($source['admin'], "'already_reviewed'") >= 2,
    'binary changes always return approved items to pending review' =>
        str_contains($source['admin'], "\$auditStatus = 'pending';")
        && str_contains($source['admin'], "\$auditStatus = \$sourceChanged ? 'pending'")
        && substr_count($source['admin'], 'audited_at = ?') >= 2
        && substr_count($source['admin'], 'fileReferenceChanged(') >= 5,
    'concurrent review and edit operations cannot silently overwrite each other' =>
        substr_count($source['admin'], 'assertReviewSnapshot(') >= 3
        && substr_count($source['admin'], 'FOR UPDATE') >= 4
        && str_contains($source['admin'], 'expected_review_revision 为必填项')
        && !str_contains($source['admin'], 'expected_updated_at'),
    'public and normal user catalogs only expose approved enabled records' =>
        substr_count($source['public'], "'r.audit_status = ?'") >= 1
        && substr_count($source['user'], "\$query[] = 'approved'") >= 2,
    'authors can view their own review records but cannot interact until approved' =>
        substr_count($source['user'], 'OR user_id = ?') >= 1
        && str_contains($source['user'], 'OR r.user_id = ?')
        && substr_count($source['user'], "'interaction_enabled'") >= 4
        && str_contains($source['user'], "\$resource['comments'] = \$interactive ? Database::all"),
    'all favorite aggregations hide held and rejected resources and apps' =>
        substr_count($source['favorite'], "audit_status = 'approved'") >= 2
        && substr_count($source['user'], "audit_status = 'approved' AND") >= 2,
    'Android admin has explicit detail pass fail and hold actions for both catalogs' =>
        substr_count($source['modules'], '"查看审核详情", "GET"') >= 2
        && substr_count($source['modules'], '.fixed("audit_status", "approved")') >= 6
        && substr_count($source['modules'], '.fixed("audit_status", "rejected")') >= 6
        && substr_count($source['modules'], '.fixed("audit_status", "on_hold")') >= 6,
    'Android audit lists support status filters refresh and stale-decision protection' =>
        str_contains($source['generic'], 'configureAuditFilter()')
        && str_contains($source['generic'], 'query.put("audit_status", auditStatusFilter)')
        && str_contains($source['generic'], 'expected_audit_status')
        && str_contains($source['generic'], 'expected_review_revision')
        && str_contains($source['generic'], 'binding.swipeRefresh.setOnRefreshListener'),
    'Android catalog exposes own submissions purchases and safe historical downloads' =>
        str_contains($source['hall'], 'query.put("mine", "1")')
        && str_contains($source['hall'], 'query.put("purchased", "1")')
        && str_contains($source['hall_layout'], 'android:text="我的投稿"')
        && str_contains($source['hall_layout'], 'android:text="已购内容"')
        && str_contains($source['hall'], '历史已购 · 当前已下架')
        && str_contains($source['hall'], 'if (canDownload)'),
    'soft-deleted purchases retain buyer detail download and upload protection without reopening public access' =>
        substr_count($source['user'], "if (\$purchasedOnly) {") >= 2
        && substr_count($source['user'], "\$where[] = 'r.deleted_at IS NULL';") >= 2
        && substr_count($source['user'], "\$where[] = 's.deleted_at IS NULL';") >= 2
        && str_contains($source['catalog_download'], "if (\$deleted && !\$hasPurchase)")
        && str_contains($source['catalog_download'], "\$owner = !\$deleted")
        && str_contains($source['catalog_download'], "\$free = \$active")
        && str_contains($source['user'], "OR EXISTS(SELECT 1 FROM resource_purchases rp")
        && str_contains($source['user'], "OR EXISTS(SELECT 1 FROM store_app_purchases sap")
        && str_contains($source['upload_library'], 'SELECT 1 FROM resource_purchases rp WHERE rp.resource_id = resources.id')
        && str_contains($source['upload_library'], 'SELECT 1 FROM store_app_purchases sap WHERE sap.store_app_id = store_apps.id'),
    'Android keeps source submissions scoped and uses the application package download field' =>
        substr_count($source['hall'], 'query.put("resource_type", "source_market")') >= 2
        && str_contains($source['submit'], 'query.put("resource_type", "source_market")')
        && str_contains($source['submit'], 'body.addProperty("resource_type", "source_market")')
        && str_contains($source['hall'], 'itemMode == MODE_APPS ? "apk_url" : "download_url"')
        && substr_count($source['admin'], "input('resource_type', 'source_market')") >= 2
        && substr_count($source['modules'], '.fixed("resource_type", "source_market")') >= 2
        && !str_contains($source['modules'], '分类类型 source_market/app_store')
        && str_contains($source['display_text'], 'if ("source_market".equals(normalizedRaw)) return "源码商城";')
        && str_contains($source['display_text'], 'if ("app_store".equals(normalizedRaw)) return "应用商店";')
        && str_contains($source['display_text'], 'if ("review".equals(normalizedRaw)) return "需要人工复核";'),
    'catalog files are private and every purchase download uses controlled routes' =>
        str_contains($source['upload_storage'], "'resource_source', 'store_app_package'")
        && str_contains($source['submit'], 'source_upload_id')
        && str_contains($source['routes'], "/resources/{resource_id}/download")
        && str_contains($source['routes'], "/store-apps/{store_app_id}/buy")
        && str_contains($source['routes'], "/store-apps/{store_app_id}/download")
        && str_contains($source['catalog_download'], "'application/octet-stream'")
        && str_contains($source['catalog_download'], "'attachment'")
        && str_contains($source['response'], "'X-Content-Type-Options' => 'nosniff'")
        && str_contains($source['response'], "HTTP_IF_RANGE"),
    'purchases require an expected immutable price and source snapshot' =>
        substr_count($source['user'], 'expected_price_balance') >= 2
        && substr_count($source['user'], 'expected_source_upload_id') >= 2
        && str_contains($source['user'], 'expected_version_code')
        && substr_count($source['hall'], 'expected_price_balance') >= 2
        && substr_count($source['hall'], 'expected_source_upload_id') >= 2,
    'catalog migration is a report-bound fail-closed two-step gate' =>
        str_contains($source['migrator'], '--release-version')
        && str_contains($source['migrator'], 'runtime_gate_activated')
        && str_contains($source['migrator'], 'writeAtomicPrivateFile(')
        && str_contains($source['migration_verifier'], '--activate')
        && str_contains($source['migration_verifier'], 'validateReport(')
        && str_contains($source['migration_verifier'], 'assertDatabaseAndPrivateFilesReady(')
        && str_contains($source['inspection'], 'catalogWriteTransaction(')
        && str_contains($source['app_service'], 'INTERNAL_SETTING_KEYS')
        && str_contains($source['app_service'], "s.setting_key = 'catalog_private_migration_ready'"),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Resource/store review contract failed: {$name}\n");
        exit(1);
    }
}

echo "Resource/store review contract: passed\n";
