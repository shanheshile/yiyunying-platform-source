<?php
declare(strict_types=1);

use Yiyunying\Core\Database;
use Yiyunying\Services\UploadStorageService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is CLI-only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once __DIR__ . '/catalog-public-upload-type.php';
require_once __DIR__ . '/catalog-private-retention.php';
require $root . '/bootstrap.php';

$apply = in_array('--apply', $argv, true);
$maintenanceConfirmed = in_array('--maintenance-confirmed', $argv, true);
$releaseVersion = cliOption($argv, '--release-version');
if ($releaseVersion === null || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/', $releaseVersion) !== 1) {
    fwrite(STDERR, "A valid --release-version is required so the migration evidence is bound to one release.\n");
    exit(1);
}
$releaseIdentity = loadReleaseIdentity($root, $releaseVersion);
if ($apply && !$maintenanceConfirmed) {
    fwrite(STDERR, "Refusing apply mode without --maintenance-confirmed. Stop catalog writes and take DB/public/uploads backups first.\n");
    exit(1);
}
$stamp = gmdate('Ymd-His') . 'Z-' . bin2hex(random_bytes(4));
$reportDir = $root . '/storage/private/catalog-migration-reports';
$reportPath = $reportDir . '/catalog-private-migration-' . $stamp . '.json';
$progressPath = $reportDir . '/catalog-private-migration-' . $stamp . '.jsonl';
$planPath = $reportDir . '/catalog-private-migration-' . $stamp . '.plan.jsonl';
if (!is_dir($reportDir) && !mkdir($reportDir, 0700, true) && !is_dir($reportDir)) {
    fwrite(STDERR, "Unable to create migration report directory.\n");
    exit(1);
}
$lock = Database::one("SELECT GET_LOCK('yiyunying_catalog_private_migration', 0) AS acquired");
if ((int) ($lock['acquired'] ?? 0) !== 1) {
    fwrite(STDERR, "Another catalog migration is already running.\n");
    exit(1);
}
register_shutdown_function(static function (): void {
    try {
        Database::one("SELECT RELEASE_LOCK('yiyunying_catalog_private_migration') AS released");
    } catch (Throwable) {
    }
});
$definitions = [
    [
        'kind' => 'resource', 'table' => 'resources', 'url_column' => 'download_url', 'label' => '源码资源',
    ],
    [
        'kind' => 'store_app', 'table' => 'store_apps', 'url_column' => 'apk_url', 'label' => '应用安装包',
    ],
];
$summary = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'release_version' => $releaseVersion,
    'release_code' => $releaseIdentity['version_code'],
    'release_identity_sha256' => $releaseIdentity['sha256'],
    'schema_migration' => '2026.08.11-resource-store-review-closure',
    'started_at_utc' => gmdate(DATE_ATOM),
    'scanned' => 0,
    'already_private' => 0,
    'inert_verified' => 0,
    'would_migrate' => 0,
    'migrated' => 0,
    'failed' => 0,
    'unresolved' => 0,
    'residual_public_uploads' => 0,
    'residual_cleanup_journal' => 0,
    'residual_legacy_urls' => 0,
    'residual_public_files' => 0,
    'residual_invalid_catalog_hashes' => 0,
    'residual_catalog_metadata_mismatches' => 0,
    'unsafe_public_entries' => 0,
    'required_copy_bytes' => 0,
    'storage_free_bytes' => 0,
    'storage_writable' => false,
    'runtime_gate_activated' => false,
    'issues' => [],
    'issues_truncated' => 0,
];

if ($apply) setCatalogMigrationGate(false);

preflightCatalogRows($definitions, $summary, $progressPath, $planPath);
$storageProbe = is_dir($root . '/storage/private') ? $root . '/storage/private' : $root . '/storage';
$summary['storage_writable'] = is_dir($storageProbe) && is_writable($storageProbe);
$freeBytes = @disk_free_space($storageProbe);
$summary['storage_free_bytes'] = $freeBytes === false ? 0 : (int) $freeBytes;
$minimumFree = $summary['required_copy_bytes'] + 67108864;
if ($summary['required_copy_bytes'] > 0
    && (!$summary['storage_writable'] || $summary['storage_free_bytes'] < $minimumFree)) {
    $summary['failed']++;
    $summary['unresolved']++;
    addIssue($summary, [
        'kind' => 'storage_preflight',
        'error' => '私有存储不可写或剩余空间不足（至少需迁移字节数外加 64 MiB 安全余量）',
    ]);
}

if ($apply && $summary['failed'] === 0 && $summary['unresolved'] === 0) {
    try {
        validateCatalogPlan($definitions, $planPath);
        applyCatalogPlans($definitions, $summary, $progressPath);
    } catch (Throwable $exception) {
        $summary['failed']++;
        $summary['unresolved']++;
        addIssue($summary, [
            'kind' => 'plan_validation',
            'error' => mb_substr($exception->getMessage(), 0, 300),
        ]);
    }
}

if ($apply) {
    while (true) {
        $totalBefore = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM catalog_file_migrations WHERE cleanup_status <> 'cleaned'"
        )['total'] ?? 0);
        if ($totalBefore === 0) break;
        $pending = Database::all(
            "SELECT upload_id, admin_id, app_id FROM catalog_file_migrations
             WHERE cleanup_status <> 'cleaned' ORDER BY id LIMIT 500"
        );
        if ($pending === []) break;
        foreach ($pending as $entry) {
            try {
                UploadStorageService::reconcileCatalogPublicCleanup(
                    (int) $entry['upload_id'], (int) $entry['admin_id'], (int) $entry['app_id']
                );
            } catch (Throwable $exception) {
                $summary['failed']++;
                $summary['unresolved']++;
                addIssue($summary, [
                    'kind' => 'cleanup_journal', 'id' => (int) $entry['upload_id'],
                    'error' => mb_substr($exception->getMessage(), 0, 300),
                ]);
            }
        }
        $remaining = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM catalog_file_migrations WHERE cleanup_status <> 'cleaned'"
        )['total'] ?? 0);
        if ($remaining === 0 || $remaining >= $totalBefore) break;
    }
}

$summary['residual_public_uploads'] = countResidualPublicCatalogUploads();
$summary['residual_cleanup_journal'] = (int) (Database::one(
    "SELECT COUNT(*) AS total FROM catalog_file_migrations WHERE cleanup_status <> 'cleaned'"
)['total'] ?? 0);
$summary['residual_legacy_urls'] = (int) (Database::one(
    "SELECT
       (SELECT COUNT(*) FROM resources WHERE TRIM(download_url) <> '')
       + (SELECT COUNT(*) FROM store_apps WHERE TRIM(apk_url) <> '') AS total"
)['total'] ?? 0);
$summary['residual_invalid_catalog_hashes'] = countInvalidCatalogHashes();
$summary['residual_catalog_metadata_mismatches'] = countCatalogMetadataMismatches();
scanPublicCatalogResidue($root . '/public', $summary);
$summary['finished_at_utc'] = gmdate(DATE_ATOM);
$migrationPassed = $apply
    && $summary['failed'] === 0
    && $summary['unresolved'] === 0
    && $summary['residual_public_uploads'] === 0
    && $summary['residual_cleanup_journal'] === 0
    && $summary['residual_legacy_urls'] === 0
    && $summary['residual_public_files'] === 0
    && $summary['residual_invalid_catalog_hashes'] === 0
    && $summary['residual_catalog_metadata_mismatches'] === 0
    && $summary['unsafe_public_entries'] === 0;
$summary['passed'] = $migrationPassed;
$json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    fwrite(STDERR, "Unable to write migration report.\n");
    exit(1);
}
writeAtomicPrivateFile($reportPath, $json . PHP_EOL);

echo 'Catalog private migration mode: ' . $summary['mode'] . PHP_EOL;
foreach ([
    'scanned', 'already_private', 'inert_verified', 'would_migrate', 'migrated', 'failed', 'unresolved',
    'residual_public_uploads', 'residual_cleanup_journal', 'residual_legacy_urls',
    'residual_public_files', 'residual_invalid_catalog_hashes', 'unsafe_public_entries',
    'residual_catalog_metadata_mismatches',
    'required_copy_bytes', 'storage_free_bytes',
] as $key) {
    echo $key . '=' . $summary[$key] . PHP_EOL;
}
echo 'report=' . $reportPath . PHP_EOL;
echo 'plan=' . $planPath . PHP_EOL;
if (!$apply) {
    fwrite(STDERR, "Dry-run only. Stop writes, back up DB/public/uploads, then use --apply --maintenance-confirmed.\n");
    $dryRunBlocked = $summary['would_migrate'] > 0
        || $summary['failed'] > 0
        || $summary['unresolved'] > 0
        || $summary['residual_public_uploads'] > 0
        || $summary['residual_cleanup_journal'] > 0
        || $summary['residual_legacy_urls'] > 0
        || $summary['residual_public_files'] > 0
        || $summary['residual_invalid_catalog_hashes'] > 0
        || $summary['residual_catalog_metadata_mismatches'] > 0
        || $summary['unsafe_public_entries'] > 0;
    exit($dryRunBlocked ? 2 : 0);
}

function loadReleaseIdentity(string $root, string $expectedVersion): array
{
    $path = $root . '/config/release-identity.json';
    if (!is_file($path) || is_link($path)) {
        fwrite(STDERR, "Missing trusted backend release identity.\n");
        exit(1);
    }
    try {
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) throw new RuntimeException('无法读取后端发布身份');
        $identity = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($identity)
            || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) ($identity['version_name'] ?? '')) !== 1
            || !is_int($identity['version_code'] ?? null)
            || (int) $identity['version_code'] <= 0) {
            throw new RuntimeException('后端发布身份格式无效');
        }
        if (!hash_equals((string) $identity['version_name'], $expectedVersion)) {
            throw new RuntimeException('命令行版本与当前部署后端版本不一致');
        }
        return [
            'version_name' => (string) $identity['version_name'],
            'version_code' => (int) $identity['version_code'],
            'sha256' => hash('sha256', $bytes),
        ];
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Backend release identity rejected: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
if (!$summary['passed']) {
    fwrite(STDERR, "Catalog private migration gate failed; do not deploy or activate the new release.\n");
    exit(2);
}
echo "Catalog private migration data gate: passed; runtime remains closed until the report verifier activates it.\n";

function cliOption(array $arguments, string $name): ?string
{
    foreach ($arguments as $index => $argument) {
        if (str_starts_with((string) $argument, $name . '=')) {
            return trim(substr((string) $argument, strlen($name) + 1));
        }
        if ($argument === $name && isset($arguments[$index + 1])) {
            return trim((string) $arguments[$index + 1]);
        }
    }
    return null;
}

function writeAtomicPrivateFile(string $path, string $contents): void
{
    $temporary = $path . '.partial-' . bin2hex(random_bytes(8));
    $handle = @fopen($temporary, 'xb');
    if ($handle === false) throw new RuntimeException('无法创建迁移报告临时文件');
    try {
        if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
            throw new RuntimeException('无法完整写入迁移报告');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new RuntimeException('无法将迁移报告同步到磁盘');
        }
    } finally {
        fclose($handle);
    }
    @chmod($temporary, 0600);
    if (file_exists($path)) {
        @unlink($temporary);
        throw new RuntimeException('迁移报告目标已存在，拒绝覆盖');
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('无法原子发布迁移报告');
    }
    @chmod($path, 0600);
}

function setCatalogMigrationGate(bool $ready): void
{
    $value = $ready ? '1' : '0';
    Database::execute(
        "INSERT INTO app_settings
         (admin_id, app_id, setting_key, setting_value, value_type, created_at, updated_at)
         SELECT admin_id, id, 'catalog_private_migration_ready', ?, 'bool', NOW(), NOW()
         FROM apps WHERE deleted_at IS NULL
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = 'bool', updated_at = NOW()",
        [$value]
    );
}

function catalogRows(array $definition): Generator
{
    $table = $definition['table'];
    $urlColumn = $definition['url_column'];
    $resourceTypeSelect = $definition['kind'] === 'resource'
        ? 'resource_type'
        : "'app_store' AS resource_type";
    $afterId = 0;
    while (true) {
        $rows = Database::all(
            "SELECT id, admin_id, app_id, source_upload_id, {$urlColumn} AS legacy_url,
                    {$resourceTypeSelect}, audit_status, status, deleted_at, updated_at
             FROM {$table} WHERE id > ? ORDER BY id LIMIT 500",
            [$afterId]
        );
        if ($rows === []) return;
        foreach ($rows as $row) {
            $afterId = (int) $row['id'];
            yield $row;
        }
    }
}

function catalogItem(array $definition, array $row): array
{
    return [
        'kind' => $definition['kind'],
        'id' => (int) $row['id'],
        'admin_id' => (int) $row['admin_id'],
        'app_id' => (int) $row['app_id'],
    ];
}

function inspectCatalogRow(array $definition, array $row): array
{
    $item = catalogItem($definition, $row);
    $scene = $definition['kind'] === 'resource'
        ? \Yiyunying\Services\SubmissionInspectionService::catalogScene((string) $row['resource_type'])
        : 'store_app_package';
    $legacyUrl = trim((string) ($row['legacy_url'] ?? ''));
    $purchaseTable = $definition['kind'] === 'resource' ? 'resource_purchases' : 'store_app_purchases';
    $purchaseColumn = $definition['kind'] === 'resource' ? 'resource_id' : 'store_app_id';
    $hasPurchase = Database::one(
        "SELECT id FROM {$purchaseTable} WHERE {$purchaseColumn} = ? LIMIT 1",
        [(int) $row['id']]
    ) !== null;
    $quarantineEvidence = Database::one(
        'SELECT legacy_url, legacy_url_sha256, reason_code FROM catalog_legacy_url_quarantines
         WHERE catalog_kind = ? AND catalog_id = ? AND admin_id = ? AND app_id = ? LIMIT 1',
        [$definition['kind'], $item['id'], $item['admin_id'], $item['app_id']]
    );
    // Purchased rows normally retain private bytes. Reserved example.com demo
    // records are the fail-closed exception: their purchase history remains,
    // but the unusable public URL is privately preserved and disabled.
    $inert = catalogPrivateRowIsInert($row, $hasPurchase, $quarantineEvidence);
    $uploadId = max(0, (int) ($row['source_upload_id'] ?? 0));
    if ($uploadId <= 0) {
        if ($inert && $legacyUrl === '') {
            return $item + ['action' => 'inert_missing', 'copy_bytes' => 0];
        }
        throw new RuntimeException('缺少 source_upload_id；保留旧地址并要求人工隔离或重新上传');
    }
    $upload = Database::one(
        'SELECT id, scene, status, file_path FROM uploads WHERE id = ? AND admin_id = ? AND app_id = ?',
        [$uploadId, $item['admin_id'], $item['app_id']]
    );
    if ($upload === null) {
        if ($inert && $legacyUrl === '') {
            return $item + ['action' => 'inert_missing', 'copy_bytes' => 0];
        }
        throw new RuntimeException('上传记录不存在');
    }
    if (strtolower((string) ($upload['scene'] ?? '')) !== $scene) {
        throw new RuntimeException('上传记录场景不匹配，必须人工核对其公开文件并修复引用');
    }
    if (!$inert && (int) ($upload['status'] ?? 0) !== 1) {
        throw new RuntimeException('公开条目引用了已停用上传，必须重新上传并重新审核');
    }
    $relative = ltrim(str_replace('\\', '/', (string) ($upload['file_path'] ?? '')), '/');
    $state = UploadStorageService::storedPathState($relative);
    if ($inert && $legacyUrl === '' && ($state['status'] ?? '') === 'missing') {
        return $item + [
            'action' => 'inert_missing', 'copy_bytes' => 0, 'upload_id' => $uploadId,
        ];
    }
    $preflight = UploadStorageService::preflightCatalogMigration(
        $uploadId, $item['admin_id'], $item['app_id'], $scene, $inert
    );
    return $item + [
        'action' => (bool) $preflight['already_private'] ? 'private' : 'migrate',
        'upload_id' => $uploadId,
        'scene' => $scene,
        'allow_inactive' => $inert,
        'copy_bytes' => (int) ($preflight['copy_bytes'] ?? 0),
        'copy_key' => hash('sha256', $item['admin_id'] . ':' . $item['app_id'] . ':' . $relative),
        'file_sha256' => strtolower((string) ($preflight['sha256'] ?? $preflight['upload']['sha256'] ?? '')),
    ];
}

function preflightCatalogRows(
    array $definitions,
    array &$summary,
    string $progressPath,
    string $planPath
): void
{
    $copyKeys = [];
    foreach ($definitions as $definition) {
        foreach (catalogRows($definition) as $row) {
            $summary['scanned']++;
            $item = catalogItem($definition, $row);
            try {
                $plan = inspectCatalogRow($definition, $row);
                if ($plan['action'] === 'inert_missing') {
                    $summary['inert_verified']++;
                } elseif ($plan['action'] === 'private') {
                    $summary['already_private']++;
                } else {
                    $summary['would_migrate']++;
                    $key = (string) $plan['copy_key'];
                    if (!isset($copyKeys[$key])) {
                        $copyKeys[$key] = true;
                        $summary['required_copy_bytes'] += (int) $plan['copy_bytes'];
                    }
                }
                appendPlan($planPath, $definition, $row, $plan);
                appendProgress($progressPath, $item + ['result' => 'preflight_' . $plan['action']]);
            } catch (Throwable $exception) {
                $summary['failed']++;
                $summary['unresolved']++;
                $issue = $item + ['error' => mb_substr($exception->getMessage(), 0, 300)];
                addIssue($summary, $issue);
                appendProgress($progressPath, $issue + ['result' => 'preflight_failed']);
            }
        }
    }
}

function appendPlan(string $path, array $definition, array $row, array $plan): void
{
    $entry = [
        'kind' => (string) $definition['kind'],
        'id' => (int) $row['id'],
        'fingerprint' => catalogPlanFingerprint($definition, $row, $plan),
    ];
    $json = json_encode($entry, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('无法写入迁移预检计划');
    }
    @chmod($path, 0600);
}

function catalogPlanFingerprint(array $definition, array $row, array $plan): string
{
    $snapshot = [
        'kind' => (string) $definition['kind'],
        'id' => (int) $row['id'],
        'admin_id' => (int) $row['admin_id'],
        'app_id' => (int) $row['app_id'],
        'source_upload_id' => $row['source_upload_id'] === null ? null : (int) $row['source_upload_id'],
        'legacy_url' => (string) ($row['legacy_url'] ?? ''),
        'resource_type' => (string) ($row['resource_type'] ?? ''),
        'audit_status' => (string) ($row['audit_status'] ?? ''),
        'status' => (int) ($row['status'] ?? 0),
        'deleted_at' => $row['deleted_at'],
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'action' => (string) $plan['action'],
        'upload_id' => (int) ($plan['upload_id'] ?? 0),
        'scene' => (string) ($plan['scene'] ?? ''),
        'allow_inactive' => (bool) ($plan['allow_inactive'] ?? false),
        'copy_bytes' => (int) ($plan['copy_bytes'] ?? 0),
        'copy_key' => (string) ($plan['copy_key'] ?? ''),
        'file_sha256' => (string) ($plan['file_sha256'] ?? ''),
    ];
    $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException('无法生成迁移预检指纹');
    return hash('sha256', $json);
}

function validateCatalogPlan(array $definitions, string $planPath): void
{
    $handle = @fopen($planPath, 'rb');
    if ($handle === false) throw new RuntimeException('无法读取迁移预检计划');
    try {
        foreach ($definitions as $definition) {
            foreach (catalogRows($definition) as $row) {
                $line = fgets($handle);
                $entry = $line === false ? null : json_decode(trim($line), true);
                $plan = inspectCatalogRow($definition, $row);
                $fingerprint = catalogPlanFingerprint($definition, $row, $plan);
                if (!is_array($entry)
                    || (string) ($entry['kind'] ?? '') !== (string) $definition['kind']
                    || (int) ($entry['id'] ?? 0) !== (int) $row['id']
                    || !hash_equals($fingerprint, strtolower((string) ($entry['fingerprint'] ?? '')))) {
                    throw new RuntimeException('预检后目录条目或文件发生变化，拒绝开始迁移');
                }
            }
        }
        while (($remaining = fgets($handle)) !== false) {
            if (trim($remaining) !== '') throw new RuntimeException('预检计划包含当前数据库中不存在的条目');
        }
    } finally {
        fclose($handle);
    }
}

function applyCatalogPlans(array $definitions, array &$summary, string $progressPath): void
{
    foreach ($definitions as $definition) {
        foreach (catalogRows($definition) as $row) {
            $item = catalogItem($definition, $row);
            try {
                $plan = inspectCatalogRow($definition, $row);
                if ($plan['action'] === 'inert_missing') {
                    appendProgress($progressPath, $item + ['result' => 'archived_without_public_bytes']);
                    continue;
                }
                $uploadId = (int) $plan['upload_id'];
                $allowInactive = (bool) $plan['allow_inactive'];
                if ($plan['action'] === 'migrate') {
                    UploadStorageService::ensurePrivateCatalogUpload(
                        $uploadId, $item['admin_id'], $item['app_id'], (string) $plan['scene'], $allowInactive
                    );
                    $summary['migrated']++;
                } else {
                    UploadStorageService::reconcileCatalogPublicCleanup(
                        $uploadId, $item['admin_id'], $item['app_id']
                    );
                }
                clearLegacyUrlCas($definition, $row);
                $verifiedUpload = UploadStorageService::verifiedPrivateCatalogUpload(
                    $uploadId, $item['admin_id'], $item['app_id'], (string) $plan['scene'], true, $allowInactive
                );
                syncCatalogMetadataCas($definition, $row, $verifiedUpload);
                appendProgress($progressPath, $item + ['result' => 'applied_verified']);
            } catch (Throwable $exception) {
                $summary['failed']++;
                $summary['unresolved']++;
                $issue = $item + ['error' => mb_substr($exception->getMessage(), 0, 300)];
                addIssue($summary, $issue);
                appendProgress($progressPath, $issue + ['result' => 'apply_failed']);
                if ($row['deleted_at'] === null) {
                    try {
                        holdLegacyItemCas($definition, $row, '文件私有化未完成：' . $issue['error']);
                    } catch (Throwable $holdException) {
                        $summary['unresolved']++;
                        addIssue($summary, $item + [
                            'error' => '无法安全暂定条目：' . mb_substr($holdException->getMessage(), 0, 240),
                        ]);
                    }
                }
            }
        }
    }
}

function countResidualPublicCatalogUploads(): int
{
    $count = 0;
    $afterId = 0;
    while (true) {
        $rows = Database::all(
            "SELECT id, status, file_path FROM uploads
             WHERE id > ? AND scene IN ('resource_source', 'store_app_package') ORDER BY id LIMIT 500",
            [$afterId]
        );
        if ($rows === []) break;
        foreach ($rows as $row) {
            $afterId = (int) $row['id'];
            $relative = ltrim(str_replace('\\', '/', (string) ($row['file_path'] ?? '')), '/');
            if (str_starts_with($relative, 'private/')) continue;
            $state = UploadStorageService::storedPathState($relative);
            if ((int) ($row['status'] ?? 0) === 1 || ($state['status'] ?? '') !== 'missing') $count++;
        }
    }
    return $count;
}

function countInvalidCatalogHashes(): int
{
    $count = (int) (Database::one(
        "SELECT COUNT(*) AS total FROM catalog_file_migrations
         WHERE cleanup_status <> 'cleaned'
           AND (file_sha256 IS NULL OR file_sha256 NOT REGEXP '^[0-9A-Fa-f]{64}$')"
    )['total'] ?? 0);
    $afterId = 0;
    while (true) {
        $rows = Database::all(
            "SELECT id, status, file_path, sha256 FROM uploads
             WHERE id > ? AND scene IN ('resource_source', 'store_app_package') ORDER BY id LIMIT 500",
            [$afterId]
        );
        if ($rows === []) break;
        foreach ($rows as $row) {
            $afterId = (int) $row['id'];
            if (preg_match('/^[A-Fa-f0-9]{64}$/', trim((string) ($row['sha256'] ?? ''))) === 1) continue;
            $state = UploadStorageService::storedPathState((string) ($row['file_path'] ?? ''));
            if ((int) ($row['status'] ?? 0) === 1 || ($state['status'] ?? '') !== 'missing') $count++;
        }
    }
    return $count;
}

function countCatalogMetadataMismatches(): int
{
    $resource = Database::one(
        "SELECT COUNT(*) AS total FROM resources r
         LEFT JOIN uploads up ON up.id = r.source_upload_id AND up.admin_id = r.admin_id AND up.app_id = r.app_id
         LEFT JOIN catalog_legacy_url_quarantines q
           ON q.catalog_kind = 'resource' AND q.catalog_id = r.id AND q.admin_id = r.admin_id AND q.app_id = r.app_id
         WHERE r.deleted_at IS NULL
           AND NOT (q.id IS NOT NULL AND r.source_upload_id IS NULL AND TRIM(r.download_url) = ''
             AND r.status = 0 AND r.audit_status IN ('on_hold','rejected')
             AND (NOT EXISTS (SELECT 1 FROM resource_purchases rp WHERE rp.resource_id = r.id)
               OR q.reason_code = 'reserved_example_origin_purchase_unavailable'))
           AND (up.id IS NULL OR up.file_path NOT LIKE 'private/%'
             OR LOWER(r.file_sha256) <> LOWER(up.sha256) OR r.size_bytes <> up.size_bytes)"
    );
    $store = Database::one(
        "SELECT COUNT(*) AS total FROM store_apps s
         LEFT JOIN uploads up ON up.id = s.source_upload_id AND up.admin_id = s.admin_id AND up.app_id = s.app_id
         LEFT JOIN catalog_legacy_url_quarantines q
           ON q.catalog_kind = 'store_app' AND q.catalog_id = s.id AND q.admin_id = s.admin_id AND q.app_id = s.app_id
         WHERE s.deleted_at IS NULL
           AND NOT (q.id IS NOT NULL AND s.source_upload_id IS NULL AND TRIM(s.apk_url) = ''
             AND s.status = 0 AND s.audit_status IN ('on_hold','rejected')
             AND (NOT EXISTS (SELECT 1 FROM store_app_purchases sp WHERE sp.store_app_id = s.id)
               OR q.reason_code = 'reserved_example_origin_purchase_unavailable'))
           AND (up.id IS NULL OR up.file_path NOT LIKE 'private/%'
             OR LOWER(s.file_sha256) <> LOWER(up.sha256) OR s.size_bytes <> up.size_bytes)"
    );
    return (int) ($resource['total'] ?? 0) + (int) ($store['total'] ?? 0);
}

function syncCatalogMetadataCas(array $definition, array $row, array $upload): void
{
    $table = $definition['table'];
    $uploadId = (int) ($upload['id'] ?? 0);
    $hash = strtolower(trim((string) ($upload['sha256'] ?? '')));
    $size = max(0, (int) ($upload['size_bytes'] ?? 0));
    if ($uploadId <= 0 || $size <= 0 || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
        throw new RuntimeException('私有文件元数据无效，拒绝回填目录条目');
    }
    Database::execute(
        "UPDATE {$table} SET size_bytes = ?, file_sha256 = ?, updated_at = NOW()
         WHERE id = ? AND admin_id = ? AND app_id = ? AND source_upload_id = ?",
        [$size, $hash, (int) $row['id'], (int) $row['admin_id'], (int) $row['app_id'], $uploadId]
    );
    $current = Database::one(
        "SELECT source_upload_id, size_bytes, file_sha256 FROM {$table}
         WHERE id = ? AND admin_id = ? AND app_id = ?",
        [(int) $row['id'], (int) $row['admin_id'], (int) $row['app_id']]
    );
    if ($current === null || (int) ($current['source_upload_id'] ?? 0) !== $uploadId
        || (int) ($current['size_bytes'] ?? 0) !== $size
        || !hash_equals($hash, strtolower((string) ($current['file_sha256'] ?? '')))) {
        throw new RuntimeException('目录条目在迁移期间发生变化，未写入可信文件元数据');
    }
}

function clearLegacyUrlCas(array $definition, array $row): void
{
    $table = $definition['table'];
    $urlColumn = $definition['url_column'];
    $legacyUrl = (string) ($row['legacy_url'] ?? '');
    if (trim($legacyUrl) === '') return;
    $changed = Database::execute(
        "UPDATE {$table} SET {$urlColumn} = '', updated_at = NOW()
         WHERE id = ? AND admin_id = ? AND app_id = ? AND source_upload_id = ? AND {$urlColumn} = ?",
        [
            (int) $row['id'], (int) $row['admin_id'], (int) $row['app_id'],
            (int) $row['source_upload_id'], $legacyUrl,
        ]
    );
    if ($changed === 1) return;
    $current = Database::one(
        "SELECT source_upload_id, {$urlColumn} AS legacy_url FROM {$table}
         WHERE id = ? AND admin_id = ? AND app_id = ?",
        [(int) $row['id'], (int) $row['admin_id'], (int) $row['app_id']]
    );
    if ($current !== null
        && (int) ($current['source_upload_id'] ?? 0) === (int) $row['source_upload_id']
        && trim((string) ($current['legacy_url'] ?? '')) === '') {
        return;
    }
    throw new RuntimeException('条目在迁移期间发生变化，未清除旧地址');
}

function holdLegacyItemCas(array $definition, array $row, string $reason): void
{
    $table = $definition['table'];
    $urlColumn = $definition['url_column'];
    $sourceUploadId = $row['source_upload_id'] === null ? null : (int) $row['source_upload_id'];
    $changed = Database::execute(
        "UPDATE {$table} SET audit_status = 'on_hold', audit_reason = ?, status = 0,
         audited_by = NULL, audited_at = NULL, updated_at = NOW()
         WHERE id = ? AND admin_id = ? AND app_id = ? AND source_upload_id <=> ?
           AND {$urlColumn} = ? AND updated_at = ? AND deleted_at IS NULL",
        [
            mb_substr($reason, 0, 500), (int) $row['id'], (int) $row['admin_id'], (int) $row['app_id'],
            $sourceUploadId, (string) ($row['legacy_url'] ?? ''), (string) $row['updated_at'],
        ]
    );
    if ($changed !== 1) throw new RuntimeException('条目在迁移期间已变化，拒绝覆盖新状态');
}

function appendProgress(string $path, array $entry): void
{
    $entry['at_utc'] = gmdate(DATE_ATOM);
    $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('无法写入迁移进度日志');
    }
    @chmod($path, 0600);
}

function addIssue(array &$summary, array $issue): void
{
    if (count($summary['issues']) < 1000) {
        $summary['issues'][] = $issue;
    } else {
        $summary['issues_truncated']++;
    }
}

function scanPublicCatalogResidue(string $publicDirectory, array &$summary): void
{
    if (!is_dir($publicDirectory)) return;
    $publicRoot = realpath($publicDirectory);
    if ($publicRoot === false) {
        $summary['unsafe_public_entries']++;
        addIssue($summary, ['kind' => 'public_root_unresolved']);
        return;
    }
    $publicRoot = rtrim(str_replace('\\', '/', $publicRoot), '/');
    $batch = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($publicDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        $normalized = str_replace('\\', '/', $path);
        if (!str_starts_with($normalized, $publicRoot . '/')) {
            $summary['unsafe_public_entries']++;
            addIssue($summary, ['kind' => 'public_file_outside_root', 'path_sha256' => hash('sha256', $path)]);
            continue;
        }
        $relative = ltrim(substr($normalized, strlen($publicRoot)), '/');
        $underUploads = str_starts_with($relative, 'uploads/');
        if ($entry->isLink()) {
            $summary['unsafe_public_entries']++;
            addIssue($summary, ['kind' => 'unsafe_public_link', 'path_sha256' => hash('sha256', $path)]);
            continue;
        }
        if ($entry->isDir()) continue;
        if (!$entry->isFile()) {
            $summary['unsafe_public_entries']++;
            addIssue($summary, ['kind' => 'unknown_public_entry', 'path_sha256' => hash('sha256', $path)]);
            continue;
        }
        if ($relative === 'uploads/.gitkeep') {
            $placeholderStat = @lstat($path);
            if (is_array($placeholderStat) && (int) ($placeholderStat['size'] ?? -1) === 0
                && (int) ($placeholderStat['nlink'] ?? 0) === 1) {
                continue;
            }
            $summary['unsafe_public_entries']++;
            addIssue($summary, ['kind' => 'invalid_upload_placeholder']);
            continue;
        }
        if ($underUploads) {
            $typeAssessment = catalogMigrationAssessPublicUploadFile($path);
            if ($typeAssessment !== 'safe') {
                $summary['unsafe_public_entries']++;
                addIssue($summary, [
                    'kind' => $typeAssessment === 'svg' ? 'unsafe_public_svg' : 'unsafe_public_upload_type',
                    'path_sha256' => hash('sha256', $relative),
                ]);
                continue;
            }
        }
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || $hash === '') {
            $summary['unsafe_public_entries']++;
            addIssue($summary, ['kind' => 'unreadable_public_file', 'path_sha256' => hash('sha256', $path)]);
            continue;
        }
        $hash = strtolower($hash);
        $stat = @lstat($path);
        if (!is_array($stat) || (int) ($stat['nlink'] ?? 0) !== 1) {
            $summary['unsafe_public_entries']++;
            addIssue($summary, ['kind' => 'hardlinked_public_file', 'path_sha256' => hash('sha256', $relative)]);
            continue;
        }
        $batch[] = [
            'relative' => $relative,
            'hash' => $hash,
            'under_uploads' => $underUploads,
            'managed_upload' => $underUploads && isTrustedManagedAvatar($relative, $path, $stat),
        ];
        if (count($batch) >= 200) {
            scanPublicCatalogResidueBatch($batch, $summary);
            $batch = [];
        }
    }
    if ($batch !== []) scanPublicCatalogResidueBatch($batch, $summary);
}

function scanPublicCatalogResidueBatch(array $batch, array &$summary): void
{
    $paths = array_values(array_unique(array_column($batch, 'relative')));
    $hashes = array_values(array_unique(array_column($batch, 'hash')));
    $uploadPaths = array_values(array_unique(array_map(
        static fn(array $item): string => $item['under_uploads'] ? (string) $item['relative'] : '',
        $batch
    )));
    $uploadPaths = array_values(array_filter($uploadPaths, static fn(string $path): bool => $path !== ''));

    $registered = [];
    if ($uploadPaths !== []) {
        $rows = Database::all(
            'SELECT file_path FROM uploads WHERE file_path IN (' . implode(',', array_fill(0, count($uploadPaths), '?')) . ')',
            $uploadPaths
        );
        foreach ($rows as $row) $registered[(string) $row['file_path']] = true;
    }

    $catalogPaths = [];
    $catalogHashes = [];
    $pathMarks = implode(',', array_fill(0, count($paths), '?'));
    $hashMarks = implode(',', array_fill(0, count($hashes), '?'));
    $catalogRows = array_merge(
        Database::all(
            "SELECT file_path, sha256 FROM uploads
             WHERE scene IN ('resource_source', 'store_app_package') AND file_path IN ({$pathMarks})",
            $paths
        ),
        Database::all(
            "SELECT file_path, sha256 FROM uploads
             WHERE scene IN ('resource_source', 'store_app_package') AND sha256 IN ({$hashMarks})",
            $hashes
        )
    );
    foreach ($catalogRows as $row) {
        $relative = ltrim(str_replace('\\', '/', (string) ($row['file_path'] ?? '')), '/');
        if ($relative !== '') $catalogPaths[$relative] = true;
        $hash = strtolower(trim((string) ($row['sha256'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $hash) === 1) $catalogHashes[$hash] = true;
    }
    $journalRows = array_merge(
        Database::all(
            "SELECT old_file_path, file_sha256 FROM catalog_file_migrations WHERE old_file_path IN ({$pathMarks})",
            $paths
        ),
        Database::all(
            "SELECT old_file_path, file_sha256 FROM catalog_file_migrations WHERE file_sha256 IN ({$hashMarks})",
            $hashes
        )
    );
    foreach ($journalRows as $row) {
        $relative = ltrim(str_replace('\\', '/', (string) ($row['old_file_path'] ?? '')), '/');
        if ($relative !== '') $catalogPaths[$relative] = true;
        $hash = strtolower(trim((string) ($row['file_sha256'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $hash) === 1) $catalogHashes[$hash] = true;
    }

    foreach ($batch as $item) {
        $relative = (string) $item['relative'];
        $hash = (string) $item['hash'];
        $unregisteredUpload = (bool) $item['under_uploads']
            && !(bool) $item['managed_upload']
            && !isset($registered[$relative]);
        if (isset($catalogPaths[$relative]) || isset($catalogHashes[$hash]) || $unregisteredUpload) {
            $summary['residual_public_files']++;
            addIssue($summary, [
                'kind' => $unregisteredUpload ? 'unregistered_public_file' : 'catalog_bytes_still_public',
                'file_sha256' => $hash,
                'path_sha256' => hash('sha256', $relative),
            ]);
        }
    }
}

function isTrustedManagedAvatar(string $relative, string $path, array $stat): bool
{
    if (preg_match(
        '#^uploads/avatars/(admin|platform|user|forum_plate|group|chat_room)/[1-9][0-9]*/[a-f0-9]{24}\.(jpg|png|gif|webp)$#',
        $relative,
        $matches
    ) !== 1) return false;
    $size = (int) ($stat['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) return false;
    $image = @getimagesize($path);
    if (!is_array($image)) return false;
    $width = max(0, (int) ($image[0] ?? 0));
    $height = max(0, (int) ($image[1] ?? 0));
    if ($width <= 0 || $height <= 0 || $width > 8192 || $height > 8192 || ($width * $height) > 40000000) {
        return false;
    }
    $expectedMime = [
        'jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    ];
    return strtolower((string) ($image['mime'] ?? '')) === $expectedMime[strtolower((string) $matches[2])];
}
