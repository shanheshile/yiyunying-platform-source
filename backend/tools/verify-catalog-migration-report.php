<?php
declare(strict_types=1);

use Yiyunying\Core\Database;
use Yiyunying\Services\SubmissionInspectionService;
use Yiyunying\Services\UploadStorageService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is CLI-only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once __DIR__ . '/catalog-public-upload-type.php';
require_once __DIR__ . '/catalog-private-retention.php';
require $root . '/bootstrap.php';

$reportArgument = cliOption($argv, '--report');
$releaseVersion = cliOption($argv, '--release-version');
$maxAge = (int) (cliOption($argv, '--max-age-seconds') ?? '3600');
$activate = in_array('--activate', $argv, true);
$maintenanceConfirmed = in_array('--maintenance-confirmed', $argv, true);
if ($reportArgument === null || $releaseVersion === null || !$activate || !$maintenanceConfirmed
    || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/', $releaseVersion) !== 1
    || $maxAge < 60 || $maxAge > 86400) {
    fwrite(STDERR, "Usage: php tools/verify-catalog-migration-report.php --report <file> --release-version <x.y.z> --activate --maintenance-confirmed [--max-age-seconds 3600]\n");
    exit(1);
}
try {
    $releaseIdentity = loadReleaseIdentity($root, $releaseVersion);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Backend release identity rejected: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$reportDirectory = $root . '/storage/private/catalog-migration-reports';
$reportDirectoryReal = realpath($reportDirectory);
$reportReal = realpath($reportArgument);
if ($reportDirectoryReal === false || $reportReal === false || is_link($reportArgument)
    || !is_file($reportReal) || dirname($reportReal) !== $reportDirectoryReal
    || preg_match('/^catalog-private-migration-[0-9]{8}-[0-9]{6}Z-[a-f0-9]{8}\.json$/', basename($reportReal)) !== 1) {
    fwrite(STDERR, "The report must be a regular migration report inside storage/private/catalog-migration-reports.\n");
    exit(1);
}
$reportSize = filesize($reportReal);
if ($reportSize === false || $reportSize < 2 || $reportSize > 2097152) {
    fwrite(STDERR, "The migration report size is invalid.\n");
    exit(1);
}

try {
    $reportBytes = file_get_contents($reportReal);
    if (!is_string($reportBytes)) throw new RuntimeException('无法读取迁移报告');
    $report = json_decode($reportBytes, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($report)) throw new RuntimeException('迁移报告结构无效');
    validateReport($report, $releaseIdentity, $maxAge);
} catch (Throwable $exception) {
    fwrite(STDERR, "Migration report rejected: " . $exception->getMessage() . "\n");
    exit(2);
}

$lock = Database::one("SELECT GET_LOCK('yiyunying_catalog_private_migration', 0) AS acquired");
if ((int) ($lock['acquired'] ?? 0) !== 1) {
    fwrite(STDERR, "Another catalog migration or catalog write is active.\n");
    exit(2);
}
register_shutdown_function(static function (): void {
    try {
        Database::one("SELECT RELEASE_LOCK('yiyunying_catalog_private_migration') AS released");
    } catch (Throwable) {
    }
});

$pendingReceipt = null;
$publishedReceipt = null;
try {
    // Close every catalog endpoint before the independent read-back starts.
    setCatalogMigrationGate(false);
    assertDatabaseAndPrivateFilesReady();

    $reportHash = hash('sha256', $reportBytes);
    $preparedAt = gmdate(DATE_ATOM);
    $receipt = [
        'status' => 'activation_prepared',
        'release_version' => $releaseVersion,
        'release_code' => $releaseIdentity['version_code'],
        'release_identity_sha256' => $releaseIdentity['sha256'],
        'schema_migration' => '2026.08.11-resource-store-review-closure',
        'report_file' => basename($reportReal),
        'report_sha256' => $reportHash,
        'prepared_at_utc' => $preparedAt,
    ];
    $receiptBytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($receiptBytes)) throw new RuntimeException('无法生成迁移激活凭据');
    $receiptPath = $reportDirectoryReal . '/catalog-private-activation-'
        . preg_replace('/[^0-9A-Za-z.-]/', '-', $releaseVersion) . '-'
        . gmdate('Ymd-His') . 'Z.json';
    $pendingReceipt = $receiptPath . '.pending-' . bin2hex(random_bytes(8));
    writeNewPrivateFile($pendingReceipt, $receiptBytes . PHP_EOL);
    if (file_exists($receiptPath) || !@rename($pendingReceipt, $receiptPath)) {
        throw new RuntimeException('无法原子发布迁移激活凭据');
    }
    $pendingReceipt = null;
    $publishedReceipt = $receiptPath;
    @chmod($receiptPath, 0600);

    Database::transaction(static function (): void {
        $apps = Database::all('SELECT id FROM apps WHERE deleted_at IS NULL ORDER BY id FOR UPDATE');
        setCatalogMigrationGate(true);
        $notReady = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM apps a
             LEFT JOIN app_settings s ON s.app_id = a.id
               AND s.setting_key = 'catalog_private_migration_ready'
             WHERE a.deleted_at IS NULL
               AND (s.id IS NULL OR s.setting_value NOT IN ('1', 'true') OR s.value_type <> 'bool')"
        )['total'] ?? 0);
        if ($notReady !== 0 || count($apps) < 1) {
            throw new RuntimeException('未能为全部有效应用激活目录安全门禁');
        }
    });

    $receipt['status'] = 'activated';
    $receipt['runtime_gate_readback'] = true;
    $receipt['activated_at_utc'] = gmdate(DATE_ATOM);
    $activatedReceiptBytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($activatedReceiptBytes)) throw new RuntimeException('无法生成已激活凭据');
    replacePrivateFileAtomically($receiptPath, $activatedReceiptBytes . PHP_EOL);

    echo "Catalog private migration activation: passed\n";
    echo 'release_version=' . $releaseVersion . PHP_EOL;
    echo 'report_sha256=' . $reportHash . PHP_EOL;
    echo 'receipt=' . $receiptPath . PHP_EOL;
} catch (Throwable $exception) {
    try {
        setCatalogMigrationGate(false);
    } catch (Throwable) {
    }
    if (is_string($pendingReceipt) && str_starts_with($pendingReceipt, $reportDirectoryReal . DIRECTORY_SEPARATOR)) {
        @unlink($pendingReceipt);
    }
    if (is_string($publishedReceipt) && str_starts_with($publishedReceipt, $reportDirectoryReal . DIRECTORY_SEPARATOR)) {
        @unlink($publishedReceipt);
    }
    fwrite(STDERR, "Catalog migration activation failed; runtime gate remains closed: " . $exception->getMessage() . "\n");
    exit(2);
}

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

function validateReport(array $report, array $releaseIdentity, int $maxAge): void
{
    $releaseVersion = (string) $releaseIdentity['version_name'];
    if (($report['mode'] ?? null) !== 'apply' || ($report['passed'] ?? null) !== true
        || ($report['runtime_gate_activated'] ?? null) !== false
        || ($report['release_version'] ?? null) !== $releaseVersion
        || (int) ($report['release_code'] ?? 0) !== (int) $releaseIdentity['version_code']
        || !is_string($report['release_identity_sha256'] ?? null)
        || !hash_equals((string) $releaseIdentity['sha256'], (string) $report['release_identity_sha256'])
        || ($report['schema_migration'] ?? null) !== '2026.08.11-resource-store-review-closure'
        || ($report['storage_writable'] ?? null) !== true) {
        throw new RuntimeException('迁移报告状态、版本或架构标识不匹配');
    }
    foreach ([
        'failed', 'unresolved', 'residual_public_uploads', 'residual_cleanup_journal',
        'residual_legacy_urls', 'residual_public_files', 'residual_invalid_catalog_hashes',
        'residual_catalog_metadata_mismatches', 'unsafe_public_entries', 'issues_truncated',
    ] as $key) {
        if (!array_key_exists($key, $report) || (int) $report[$key] !== 0) {
            throw new RuntimeException('迁移报告仍有未清零项目：' . $key);
        }
    }
    if (!isset($report['issues']) || !is_array($report['issues']) || $report['issues'] !== []) {
        throw new RuntimeException('迁移报告仍包含问题明细');
    }
    $started = strtotime((string) ($report['started_at_utc'] ?? ''));
    $finished = strtotime((string) ($report['finished_at_utc'] ?? ''));
    $now = time();
    if ($started === false || $finished === false || $finished < $started
        || $finished > $now + 300 || $now - $finished > $maxAge) {
        throw new RuntimeException('迁移报告时间无效或已经过期');
    }
}

function loadReleaseIdentity(string $root, string $expectedVersion): array
{
    $path = $root . '/config/release-identity.json';
    if (!is_file($path) || is_link($path)) throw new RuntimeException('缺少可信后端发布身份');
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
}

function assertDatabaseAndPrivateFilesReady(): void
{
    $migration = Database::one(
        'SELECT version FROM schema_migrations WHERE version = ? LIMIT 1',
        ['2026.08.11-resource-store-review-closure']
    );
    if ($migration === null) throw new RuntimeException('资源与应用审核迁移尚未登记');
    $pending = (int) (Database::one(
        "SELECT COUNT(*) AS total FROM catalog_file_migrations WHERE cleanup_status <> 'cleaned'"
    )['total'] ?? 0);
    if ($pending !== 0) throw new RuntimeException('公开文件清理日志仍有未完成记录');
    $legacy = (int) (Database::one(
        "SELECT (SELECT COUNT(*) FROM resources WHERE TRIM(download_url) <> '')
              + (SELECT COUNT(*) FROM store_apps WHERE TRIM(apk_url) <> '') AS total"
    )['total'] ?? 0);
    if ($legacy !== 0) throw new RuntimeException('目录仍保留旧公开地址');

    foreach ([
        [
            'table' => 'resources', 'kind' => 'resource', 'purchase_table' => 'resource_purchases',
            'purchase_column' => 'resource_id',
        ],
        [
            'table' => 'store_apps', 'kind' => 'store_app', 'purchase_table' => 'store_app_purchases',
            'purchase_column' => 'store_app_id',
        ],
    ] as $definition) {
        $afterId = 0;
        while (true) {
            $resourceType = $definition['kind'] === 'resource' ? 'c.resource_type' : "'app_store'";
            $legacyUrl = $definition['kind'] === 'resource' ? 'c.download_url' : 'c.apk_url';
            $rows = Database::all(
                "SELECT c.id, c.admin_id, c.app_id, c.source_upload_id, c.size_bytes, c.file_sha256,
                        c.status, c.audit_status, c.deleted_at, {$legacyUrl} AS legacy_url,
                        {$resourceType} AS resource_type,
                        EXISTS(SELECT 1 FROM {$definition['purchase_table']} p
                               WHERE p.{$definition['purchase_column']} = c.id) AS has_purchase
                 FROM {$definition['table']} c
                 WHERE c.id > ? ORDER BY c.id LIMIT 250",
                [$afterId]
            );
            if ($rows === []) break;
            foreach ($rows as $row) {
                $afterId = (int) $row['id'];
                $quarantineEvidence = Database::one(
                    'SELECT legacy_url_sha256 FROM catalog_legacy_url_quarantines
                     WHERE catalog_kind = ? AND catalog_id = ? AND admin_id = ? AND app_id = ? LIMIT 1',
                    [$definition['kind'], (int) $row['id'], (int) $row['admin_id'], (int) $row['app_id']]
                );
                $hasQuarantineEvidence = $quarantineEvidence !== null
                    && preg_match('/^[a-f0-9]{64}$/D', strtolower((string) ($quarantineEvidence['legacy_url_sha256'] ?? ''))) === 1;
                if (catalogPrivateRowIsInert(
                    $row,
                    (int) ($row['has_purchase'] ?? 0) === 1,
                    $hasQuarantineEvidence
                )) continue;
                $uploadId = (int) ($row['source_upload_id'] ?? 0);
                if ($uploadId <= 0) throw new RuntimeException('仍需保留的目录条目缺少私有上传');
                $scene = $definition['kind'] === 'resource'
                    ? SubmissionInspectionService::catalogScene((string) $row['resource_type'])
                    : 'store_app_package';
                $upload = UploadStorageService::verifiedPrivateCatalogUpload(
                    $uploadId, (int) $row['admin_id'], (int) $row['app_id'], $scene, true
                );
                $hash = strtolower((string) ($upload['sha256'] ?? ''));
                if ((int) ($row['size_bytes'] ?? 0) !== (int) ($upload['size_bytes'] ?? 0)
                    || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1
                    || !hash_equals($hash, strtolower((string) ($row['file_sha256'] ?? '')))) {
                    throw new RuntimeException('目录条目与私有上传元数据不一致');
                }
            }
        }
    }

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
            if ((int) ($row['status'] ?? 0) === 1 || ($state['status'] ?? '') !== 'missing') {
                throw new RuntimeException('仍存在可访问的公开目录上传');
            }
        }
    }
    assertNoPublicCatalogResidue(dirname(__DIR__) . '/public');
}

function assertNoPublicCatalogResidue(string $publicDirectory): void
{
    $publicRoot = realpath($publicDirectory);
    if ($publicRoot === false || !is_dir($publicRoot)) {
        throw new RuntimeException('公开目录无法解析');
    }
    $publicRoot = rtrim(str_replace('\\', '/', $publicRoot), '/');
    $batch = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($publicRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        $normalized = str_replace('\\', '/', $path);
        if (!str_starts_with($normalized, $publicRoot . '/')) {
            throw new RuntimeException('公开目录出现越界条目');
        }
        $relative = ltrim(substr($normalized, strlen($publicRoot)), '/');
        $underUploads = str_starts_with($relative, 'uploads/');
        if ($entry->isLink()) throw new RuntimeException('公开目录存在符号链接或重解析入口');
        if ($entry->isDir()) continue;
        if (!$entry->isFile()) throw new RuntimeException('公开目录存在未知类型条目');
        $stat = @lstat($path);
        if (!is_array($stat) || (int) ($stat['nlink'] ?? 0) !== 1) {
            throw new RuntimeException('公开目录存在硬链接或无法验证的文件');
        }
        if ($relative === 'uploads/.gitkeep' && (int) ($stat['size'] ?? -1) === 0) continue;
        if ($underUploads) {
            $typeAssessment = catalogMigrationAssessPublicUploadFile($path);
            if ($typeAssessment === 'svg') {
                throw new RuntimeException('公开上传目录仍存在可执行 SVG 文件');
            }
            if ($typeAssessment !== 'safe') {
                throw new RuntimeException('公开上传目录存在无法安全识别的文件类型');
            }
        }
        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || $hash === '') throw new RuntimeException('公开文件无法校验');
        $batch[] = [
            'relative' => $relative,
            'hash' => strtolower($hash),
            'under_uploads' => $underUploads,
            'managed_upload' => $underUploads && isTrustedManagedAvatar($relative, $path, $stat),
        ];
        if (count($batch) >= 200) {
            assertPublicCatalogBatch($batch);
            $batch = [];
        }
    }
    if ($batch !== []) assertPublicCatalogBatch($batch);
}

function assertPublicCatalogBatch(array $batch): void
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
        if ((bool) $item['under_uploads']
            && !(bool) $item['managed_upload']
            && !isset($registered[$relative])) {
            throw new RuntimeException('uploads 中存在未登记文件');
        }
        if (isset($catalogPaths[$relative]) || isset($catalogHashes[$hash])) {
            throw new RuntimeException('公开目录仍含资源或应用商店原始文件字节');
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

function setCatalogMigrationGate(bool $ready): void
{
    Database::execute(
        "INSERT INTO app_settings
         (admin_id, app_id, setting_key, setting_value, value_type, created_at, updated_at)
         SELECT admin_id, id, 'catalog_private_migration_ready', ?, 'bool', NOW(), NOW()
         FROM apps WHERE deleted_at IS NULL
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = 'bool', updated_at = NOW()",
        [$ready ? '1' : '0']
    );
}

function writeNewPrivateFile(string $path, string $contents): void
{
    $handle = @fopen($path, 'xb');
    if ($handle === false) throw new RuntimeException('无法创建迁移激活凭据');
    try {
        if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
            throw new RuntimeException('无法完整写入迁移激活凭据');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new RuntimeException('无法将迁移激活凭据同步到磁盘');
        }
    } finally {
        fclose($handle);
    }
    @chmod($path, 0600);
}

function replacePrivateFileAtomically(string $path, string $contents): void
{
    $temporary = $path . '.partial-' . bin2hex(random_bytes(8));
    writeNewPrivateFile($temporary, $contents);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('无法原子更新迁移激活凭据');
    }
    @chmod($path, 0600);
}
