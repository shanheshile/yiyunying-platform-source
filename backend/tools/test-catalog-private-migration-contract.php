<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'migrator' => $root . '/tools/migrate-catalog-private-files.php',
    'verifier' => $root . '/tools/verify-catalog-migration-report.php',
    'backfill' => $root . '/tools/backfill-catalog-source-uploads.php',
    'retention' => $root . '/tools/catalog-private-retention.php',
    'inspection' => $root . '/app/Services/SubmissionInspectionService.php',
    'app_service' => $root . '/app/Services/AppService.php',
    'admin_app' => $root . '/app/Controllers/Admin/AppController.php',
    'admin_catalog' => $root . '/app/Controllers/Admin/ResourceController.php',
    'user_catalog' => $root . '/app/Controllers/User/ResourceController.php',
    'chat_rooms' => $root . '/app/Services/ChatRoomService.php',
    'release_identity' => $root . '/config/release-identity.json',
    'version_tool' => dirname($root) . '/android/tools/version.ps1',
    'android_version' => dirname($root) . '/android/version.properties',
    'download_package' => dirname($root) . '/download-site/package.json',
    'install' => $root . '/database/install.sql',
    'migration' => $root . '/database/migrations/upgrade_20260811_resource_store_review_closure.sql',
];
$source = [];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $source[$name] = (string) file_get_contents($path);
}
require_once $paths['retention'];
$releaseIdentity = json_decode($source['release_identity'], true);
$downloadPackage = json_decode($source['download_package'], true);
$androidVersionName = preg_match('/^VERSION_NAME=(.+)$/m', $source['android_version'], $versionNameMatch) === 1
    ? trim($versionNameMatch[1]) : '';
$androidVersionCode = preg_match('/^VERSION_CODE=([1-9][0-9]*)\r?$/m', $source['android_version'], $versionCodeMatch) === 1
    ? (int) $versionCodeMatch[1] : 0;
$releaseChainMatches = is_array($releaseIdentity) && is_array($downloadPackage)
    && (string) ($releaseIdentity['version_name'] ?? '') === $androidVersionName
    && (int) ($releaseIdentity['version_code'] ?? 0) === $androidVersionCode
    && (string) ($downloadPackage['version'] ?? '') === $androidVersionName;

$receiptWrite = strpos($source['verifier'], 'writeNewPrivateFile($pendingReceipt');
$receiptPublish = strpos($source['verifier'], 'rename($pendingReceipt, $receiptPath)');
$gateActivation = strpos($source['verifier'], 'setCatalogMigrationGate(true)');
$legacyEvidenceUrl = 'https://old.example.test/retired.zip';
$quarantineEvidence = [
    'legacy_url' => $legacyEvidenceUrl,
    'legacy_url_sha256' => hash('sha256', $legacyEvidenceUrl),
    'reason_code' => 'foreign_origin',
];
$purchasedEvidenceUrl = 'https://example.com/retired-demo-resource.zip';
$purchasedDemoEvidence = [
    'legacy_url' => $purchasedEvidenceUrl,
    'legacy_url_sha256' => hash('sha256', $purchasedEvidenceUrl),
    'reason_code' => 'reserved_example_origin_purchase_unavailable',
];
$checks = [
    'retention policy only exempts purchased reserved-domain fixtures with private evidence' =>
        !catalogPrivateRowIsInert([
            'deleted_at' => null, 'source_upload_id' => null, 'legacy_url' => '',
            'status' => 0, 'audit_status' => 'on_hold',
        ], true, $quarantineEvidence)
        && catalogPrivateRowIsInert([
            'deleted_at' => null, 'source_upload_id' => null, 'legacy_url' => '',
            'status' => 0, 'audit_status' => 'on_hold',
        ], true, $purchasedDemoEvidence)
        && !catalogPrivateRowIsInert([
            'deleted_at' => null, 'source_upload_id' => null, 'legacy_url' => '',
            'status' => 0, 'audit_status' => 'on_hold',
        ], true, array_replace($purchasedDemoEvidence, ['legacy_url' => 'https://vendor.example.net/file.zip']))
        && !catalogPrivateRowIsInert([
            'deleted_at' => null, 'source_upload_id' => null, 'legacy_url' => '',
            'status' => 0, 'audit_status' => 'on_hold',
        ], true, array_replace($purchasedDemoEvidence, ['legacy_url_sha256' => str_repeat('0', 64)]))
        && !catalogPrivateRowIsInert([
            'deleted_at' => null, 'source_upload_id' => null, 'legacy_url' => '',
            'status' => 1, 'audit_status' => 'on_hold',
        ], false, $quarantineEvidence)
        && catalogPrivateRowIsInert([
            'deleted_at' => null, 'source_upload_id' => null, 'legacy_url' => '',
            'status' => 0, 'audit_status' => 'on_hold',
        ], false, $quarantineEvidence),
    'apply binds evidence to a release and never opens runtime before a durable report' =>
        str_contains($source['migrator'], "cliOption(\$argv, '--release-version')")
        && str_contains($source['migrator'], "'release_version' => \$releaseVersion")
        && str_contains($source['migrator'], "'release_code' => \$releaseIdentity['version_code']")
        && str_contains($source['migrator'], "'release_identity_sha256' => \$releaseIdentity['sha256']")
        && str_contains($source['migrator'], 'loadReleaseIdentity($root, $releaseVersion)')
        && str_contains($source['migrator'], "'runtime_gate_activated' => false")
        && str_contains($source['migrator'], 'writeAtomicPrivateFile($reportPath')
        && !str_contains($source['migrator'], 'setCatalogMigrationGate(true)'),
    'binding apply report proves purchased rows remain present after commit' =>
        str_contains($source['backfill'], "'preserved_purchase_count'")
        && str_contains($source['backfill'], '$purchaseCount !== (int) $plan'),
    'activation requires a fresh exact report and all residual counters at zero' =>
        str_contains($source['verifier'], "'mode'] ?? null) !== 'apply'")
        && str_contains($source['verifier'], "'release_version'] ?? null) !== \$releaseVersion")
        && str_contains($source['verifier'], "'release_code'] ?? 0")
        && str_contains($source['verifier'], "'release_identity_sha256'] ?? null")
        && str_contains($source['verifier'], 'max-age-seconds')
        && str_contains($source['verifier'], 'residual_catalog_metadata_mismatches')
        && str_contains($source['verifier'], 'unsafe_public_entries')
        && str_contains($source['verifier'], 'assertDatabaseAndPrivateFilesReady()'),
    'activation receipt is durable before the database gate can become true' =>
        is_int($receiptWrite) && is_int($receiptPublish) && is_int($gateActivation)
        && $receiptWrite < $receiptPublish && $receiptPublish < $gateActivation
        && str_contains($source['verifier'], 'setCatalogMigrationGate(false)')
        && str_contains($source['verifier'], 'runtime gate remains closed'),
    'independent readback rescans the entire public tree and private retained items' =>
        str_contains($source['verifier'], 'assertNoPublicCatalogResidue(')
        && str_contains($source['verifier'], 'RecursiveDirectoryIterator')
        && str_contains($source['verifier'], '公开目录存在符号链接或重解析入口')
        && str_contains($source['verifier'], 'uploads 中存在未登记文件')
        && str_contains($source['verifier'], 'verifiedPrivateCatalogUpload(')
        && str_contains($source['verifier'], "'2026.08.11-resource-store-review-closure'"),
    'public scanners keep memory bounded and only exempt validated managed avatar files' =>
        str_contains($source['migrator'], 'scanPublicCatalogResidueBatch($batch, $summary)')
        && str_contains($source['verifier'], 'assertPublicCatalogBatch($batch)')
        && str_contains($source['migrator'], 'count($batch) >= 200')
        && str_contains($source['verifier'], 'count($batch) >= 200')
        && !str_contains($source['migrator'], '$allUploadPaths')
        && !str_contains($source['verifier'], '$allUploadPaths')
        && str_contains($source['migrator'], 'isTrustedManagedAvatar(')
        && str_contains($source['verifier'], 'isTrustedManagedAvatar(')
        && str_contains($source['migrator'], 'getimagesize($path)')
        && str_contains($source['verifier'], 'getimagesize($path)')
        && str_contains($source['chat_rooms'], "ROOM_CHATROOM = 'chat_room'")
        && str_contains($source['migrator'], 'uploads/avatars/(admin|platform|user|forum_plate|group|chat_room)')
        && str_contains($source['verifier'], 'uploads/avatars/(admin|platform|user|forum_plate|group|chat_room)')
        && !str_contains($source['migrator'], '|chatroom)')
        && !str_contains($source['verifier'], '|chatroom)'),
    'bounded public scan batches use dedicated upload and migration lookup indexes' =>
        str_contains($source['migrator'], "AND file_path IN ({\$pathMarks})")
        && str_contains($source['migrator'], "AND sha256 IN ({\$hashMarks})")
        && str_contains($source['verifier'], "AND file_path IN ({\$pathMarks})")
        && str_contains($source['verifier'], "AND sha256 IN ({\$hashMarks})")
        && str_contains($source['install'], 'idx_uploads_file_path')
        && str_contains($source['install'], 'idx_uploads_scene_sha256')
        && str_contains($source['install'], 'idx_catalog_file_migration_old_path')
        && str_contains($source['install'], 'idx_catalog_file_migration_sha256')
        && str_contains($source['migration'], "INDEX_NAME = 'idx_uploads_file_path'")
        && str_contains($source['migration'], "INDEX_NAME = 'idx_uploads_scene_sha256'")
        && str_contains($source['migration'], "INDEX_NAME = 'idx_catalog_file_migration_old_path'")
        && str_contains($source['migration'], "INDEX_NAME = 'idx_catalog_file_migration_sha256'"),
    'only deleted or privately audited inactive rows bypass missing bytes while wrong scenes block' =>
        str_contains($source['migrator'], "if (\$upload === null)")
        && str_contains($source['migrator'], '上传记录场景不匹配，必须人工核对其公开文件并修复引用')
        && str_contains($source['migrator'], 'catalogPrivateRowIsInert(')
        && str_contains($source['verifier'], 'catalogPrivateRowIsInert(')
        && str_contains($source['retention'], 'reserved_example_origin_purchase_unavailable')
        && str_contains($source['retention'], "\$row['deleted_at']")
        && str_contains($source['retention'], "['on_hold', 'rejected']")
        && str_contains($source['migrator'], 'catalog_legacy_url_quarantines')
        && str_contains($source['verifier'], 'catalog_legacy_url_quarantines')
        && str_contains($source['migrator'], 'uploads/.gitkeep'),
    'catalog mutations share the app gate row without globally serializing normal traffic' =>
        str_contains($source['inspection'], 'catalogWriteTransaction(')
        && str_contains($source['inspection'], 'LOCK IN SHARE MODE')
        && !str_contains(substr(
            $source['inspection'],
            strpos($source['inspection'], 'public static function catalogWriteTransaction'),
            strpos($source['inspection'], 'public static function catalogSchemaTransaction')
                - strpos($source['inspection'], 'public static function catalogWriteTransaction')
        ), 'GET_LOCK')
        && substr_count($source['admin_catalog'], 'catalogWriteTransaction(') >= 7
        && substr_count($source['user_catalog'], 'catalogWriteTransaction(') >= 5,
    'app creation shares the migration lock and inherits the internal closed or open state' =>
        str_contains($source['admin_app'], 'catalogSchemaTransaction(')
        && str_contains($source['inspection'], "GET_LOCK('yiyunying_catalog_private_migration', 0)")
        && str_contains($source['app_service'], "s.setting_key = 'catalog_private_migration_ready'")
        && str_contains($source['app_service'], "'catalog_private_migration_ready', ?, 'bool'")
        && str_contains($source['app_service'], 'INTERNAL_SETTING_KEYS'),
    'backend release identity is versioned with Android and download-site state' =>
        $releaseChainMatches
        && str_contains($source['version_tool'], '$backendReleaseFile')
        && str_contains($source['version_tool'], 'Read-BackendReleaseIdentity')
        && str_contains($source['version_tool'], 'Write-BackendReleaseIdentity')
        && str_contains($source['version_tool'], '版本链不一致：Android='),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Catalog private migration contract failed: {$name}\n");
        exit(1);
    }
}

echo "Catalog private migration contract: passed\n";
