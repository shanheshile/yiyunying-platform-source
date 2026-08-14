<?php
declare(strict_types=1);

use Yiyunying\Core\Database;
use Yiyunying\Services\SubmissionInspectionService;
use Yiyunying\Services\UploadStorageService;

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/catalog-legacy-upload-binding.php';

$apply = in_array('--apply', $argv, true);
$maintenance = in_array('--maintenance-confirmed', $argv, true);
if ($apply && !$maintenance) {
    fwrite(STDERR, "Refusing apply mode without --maintenance-confirmed.\n");
    exit(2);
}
$releaseVersion = optionValue($argv, '--release-version');
if ($releaseVersion === '' || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/D', $releaseVersion) !== 1) {
    fwrite(STDERR, "A valid --release-version is required.\n");
    exit(2);
}

$lock = Database::one("SELECT GET_LOCK('yiyunying_catalog_private_migration', 0) AS acquired");
if ((int) ($lock['acquired'] ?? 0) !== 1) {
    fwrite(STDERR, "Another catalog maintenance operation is active.\n");
    exit(3);
}
register_shutdown_function(static function (): void {
    try { Database::one("SELECT RELEASE_LOCK('yiyunying_catalog_private_migration') AS released"); } catch (Throwable) {}
});

$openGates = (int) (Database::one(
    "SELECT COUNT(*) AS total FROM apps a
     LEFT JOIN app_settings s ON s.admin_id = a.admin_id AND s.app_id = a.id
      AND s.setting_key = 'catalog_private_migration_ready'
     WHERE a.deleted_at IS NULL AND (s.setting_value IS NULL OR LOWER(TRIM(s.setting_value)) NOT IN ('0','false','off','no'))"
)['total'] ?? 0);
if ($openGates !== 0) {
    fwrite(STDERR, "Catalog gate must be closed for every active application.\n");
    exit(4);
}

$definitions = [
    [
        'kind' => 'resource', 'table' => 'resources', 'url' => 'download_url',
        'purchase_table' => 'resource_purchases', 'purchase_column' => 'resource_id',
    ],
    [
        'kind' => 'store_app', 'table' => 'store_apps', 'url' => 'apk_url',
        'purchase_table' => 'store_app_purchases', 'purchase_column' => 'store_app_id',
    ],
];
$applicationUrl = array_values(array_unique(array_filter([
    (string) config('app.url'),
    optionValue($argv, '--allowed-origin'),
], static fn (string $value): bool => trim($value) !== '')));
$bindingPlans = [];
$quarantinePlans = [];
$issues = [];
foreach ($definitions as $definition) {
    $rows = Database::all(
        "SELECT id, admin_id, app_id, user_id, source_upload_id, {$definition['url']} AS legacy_url,
                size_bytes, file_sha256, " . ($definition['kind'] === 'resource' ? "resource_type" : "'app_store' AS resource_type") . ",
                audit_status, status, deleted_at, updated_at
         FROM {$definition['table']}
         WHERE source_upload_id IS NULL AND TRIM({$definition['url']}) <> '' ORDER BY id"
    );
    foreach ($rows as $row) {
        $scene = $definition['kind'] === 'resource'
            ? SubmissionInspectionService::catalogScene((string) $row['resource_type'])
            : 'store_app_package';
        $hasPurchase = Database::one(
            "SELECT id FROM {$definition['purchase_table']} WHERE {$definition['purchase_column']} = ? LIMIT 1",
            [(int) $row['id']]
        ) !== null;
        $catalog = $row + ['scene' => $scene, 'has_purchase' => $hasPurchase];
        $canonical = catalogLegacyCanonicalPath((string) $row['legacy_url'], (int) $row['app_id'], $applicationUrl);
        $uploads = [];
        if ($canonical['ok'] ?? false) {
            $uploads = Database::all(
                'SELECT id, admin_id, app_id, user_id, scene, file_path, size_bytes, sha256, status
                 FROM uploads WHERE file_path = ? ORDER BY id',
                [(string) $canonical['path']]
            );
        }
        $result = catalogLegacyResolveBinding($catalog, $uploads, $applicationUrl, 'inspectPublicUpload');
        $identity = ['kind' => $definition['kind'], 'id' => (int) $row['id'], 'admin_id' => (int) $row['admin_id'], 'app_id' => (int) $row['app_id']];
        if (!($result['ok'] ?? false)) {
            $eligibility = catalogLegacyQuarantineEligibility($catalog);
            if ($eligibility['ok'] ?? false) {
                $legacyUrl = (string) $row['legacy_url'];
                $quarantinePlans[] = $identity + [
                    'table' => $definition['table'], 'url_column' => $definition['url'],
                    'purchase_table' => $definition['purchase_table'],
                    'purchase_column' => $definition['purchase_column'],
                    'legacy_url' => $legacyUrl, 'legacy_url_sha256' => hash('sha256', $legacyUrl),
                    'legacy_size_bytes' => max(0, (int) ($row['size_bytes'] ?? 0)),
                    'legacy_file_sha256' => strtolower(trim((string) ($row['file_sha256'] ?? ''))),
                    'updated_at' => (string) $row['updated_at'],
                    'audit_status' => (string) $row['audit_status'], 'status' => (int) $row['status'],
                    'deleted_at' => $row['deleted_at'],
                    'reason' => (string) ($result['reason'] ?? 'unresolved'),
                ];
                continue;
            }
            $issues[] = $identity + [
                'reason' => (string) ($result['reason'] ?? 'unresolved'),
                'quarantine_blocker' => (string) ($eligibility['reason'] ?? 'not_eligible'),
            ];
            continue;
        }
        $uploadId = (int) $result['upload_id'];
        $bindingPlans[] = $identity + [
            'table' => $definition['table'], 'url_column' => $definition['url'],
            'legacy_url' => (string) $row['legacy_url'], 'updated_at' => (string) $row['updated_at'],
            'scene' => $scene, 'upload_id' => $uploadId,
            'path' => (string) $result['path'],
            'path_sha256' => (string) $result['path_sha256'],
            'file_sha256' => (string) $result['file_sha256'], 'size_bytes' => (int) $result['size_bytes'],
        ];
    }
}

$status = $issues === [] ? ($apply ? 'applying' : 'ready') : 'blocked';
if ($apply && $issues === []) {
    try {
        Database::transaction(static function () use ($bindingPlans, $quarantinePlans, $applicationUrl, $releaseVersion): void {
            foreach ($bindingPlans as $plan) {
                $row = Database::one(
                    "SELECT id, admin_id, app_id, user_id, source_upload_id, {$plan['url_column']} AS legacy_url,
                            size_bytes, file_sha256, " . ($plan['kind'] === 'resource' ? "resource_type" : "'app_store' AS resource_type") . ",
                            deleted_at, updated_at
                     FROM {$plan['table']} WHERE id = ? AND admin_id = ? AND app_id = ? FOR UPDATE",
                    [$plan['id'], $plan['admin_id'], $plan['app_id']]
                );
                if ($row === null || $row['source_upload_id'] !== null
                    || (string) $row['legacy_url'] !== $plan['legacy_url']
                    || (string) $row['updated_at'] !== $plan['updated_at']) {
                    throw new RuntimeException('Catalog row changed after preflight');
                }
                $upload = Database::all(
                    'SELECT id, admin_id, app_id, user_id, scene, file_path, size_bytes, sha256, status
                     FROM uploads WHERE file_path = ? ORDER BY id FOR UPDATE',
                    [$plan['path']]
                );
                $fresh = catalogLegacyResolveBinding($row + ['scene' => $plan['scene']], $upload, $applicationUrl, 'inspectPublicUpload');
                if (!($fresh['ok'] ?? false) || (int) ($fresh['upload_id'] ?? 0) !== $plan['upload_id']
                    || !hash_equals($plan['path_sha256'], (string) ($fresh['path_sha256'] ?? ''))
                    || !hash_equals($plan['file_sha256'], (string) ($fresh['file_sha256'] ?? ''))) {
                    throw new RuntimeException('Upload or physical file changed after preflight');
                }
                $changed = Database::execute(
                    "UPDATE {$plan['table']} SET source_upload_id = ?, size_bytes = ?, file_sha256 = ?, updated_at = NOW()
                     WHERE id = ? AND admin_id = ? AND app_id = ? AND source_upload_id IS NULL
                       AND {$plan['url_column']} = ? AND updated_at = ?",
                    [$plan['upload_id'], $plan['size_bytes'], $plan['file_sha256'], $plan['id'], $plan['admin_id'], $plan['app_id'], $plan['legacy_url'], $plan['updated_at']]
                );
                if ($changed !== 1) throw new RuntimeException('Catalog compare-and-swap failed');
            }
            foreach ($quarantinePlans as $plan) {
                $row = Database::one(
                    "SELECT id, admin_id, app_id, source_upload_id, {$plan['url_column']} AS legacy_url,
                            size_bytes, file_sha256, audit_status, status, deleted_at, updated_at
                     FROM {$plan['table']} WHERE id = ? AND admin_id = ? AND app_id = ? FOR UPDATE",
                    [$plan['id'], $plan['admin_id'], $plan['app_id']]
                );
                $hasPurchase = Database::one(
                    "SELECT id FROM {$plan['purchase_table']} WHERE {$plan['purchase_column']} = ? LIMIT 1 FOR UPDATE",
                    [$plan['id']]
                ) !== null;
                if ($row === null || (string) ($row['legacy_url'] ?? '') !== $plan['legacy_url']
                    || (string) ($row['updated_at'] ?? '') !== $plan['updated_at']) {
                    throw new RuntimeException('Catalog quarantine row changed after preflight');
                }
                $eligibility = catalogLegacyQuarantineEligibility($row + ['has_purchase' => $hasPurchase]);
                if (!($eligibility['ok'] ?? false)) {
                    throw new RuntimeException('Catalog quarantine eligibility changed after preflight');
                }
                $existing = Database::one(
                    'SELECT legacy_url, legacy_url_sha256, legacy_size_bytes, legacy_file_sha256, reason_code, release_version
                     FROM catalog_legacy_url_quarantines
                     WHERE catalog_kind = ? AND catalog_id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                    [$plan['kind'], $plan['id'], $plan['admin_id'], $plan['app_id']]
                );
                if ($existing === null) {
                    Database::execute(
                        'INSERT INTO catalog_legacy_url_quarantines
                         (catalog_kind, catalog_id, admin_id, app_id, legacy_url, legacy_url_sha256,
                          legacy_size_bytes, legacy_file_sha256, reason_code, release_version, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                        [
                            $plan['kind'], $plan['id'], $plan['admin_id'], $plan['app_id'],
                            $plan['legacy_url'], $plan['legacy_url_sha256'], $plan['legacy_size_bytes'],
                            $plan['legacy_file_sha256'], $plan['reason'], $releaseVersion,
                        ]
                    );
                } elseif ((string) $existing['legacy_url'] !== $plan['legacy_url']
                    || !hash_equals($plan['legacy_url_sha256'], strtolower((string) $existing['legacy_url_sha256']))) {
                    throw new RuntimeException('Existing catalog quarantine evidence does not match the source row');
                }
                $changed = Database::execute(
                    "UPDATE {$plan['table']}
                     SET {$plan['url_column']} = '', size_bytes = 0, file_sha256 = '', status = 0,
                         audit_status = 'on_hold', audit_reason = ?, audited_by = NULL, audited_at = NULL,
                         updated_at = NOW()
                     WHERE id = ? AND admin_id = ? AND app_id = ? AND source_upload_id IS NULL
                       AND {$plan['url_column']} = ? AND updated_at = ?",
                    [
                        'Legacy public URL quarantined; controlled re-upload and review are required.',
                        $plan['id'], $plan['admin_id'], $plan['app_id'], $plan['legacy_url'], $plan['updated_at'],
                    ]
                );
                if ($changed !== 1) throw new RuntimeException('Catalog quarantine compare-and-swap failed');
            }
        });
    } catch (Throwable $exception) {
        $status = 'apply_failed';
        $issues[] = ['reason' => 'transaction_rolled_back', 'detail' => mb_substr($exception->getMessage(), 0, 200)];
    }
    if ($status === 'applying') {
        try {
            verifyAppliedBindings($bindingPlans, $quarantinePlans);
            $status = 'applied_verified';
        } catch (Throwable $exception) {
            $status = 'applied_readback_failed';
            $issues[] = ['reason' => 'post_commit_readback_failed', 'detail' => mb_substr($exception->getMessage(), 0, 200)];
        }
    }
}

$safePlans = array_map(static function (array $plan): array {
    unset($plan['table'], $plan['url_column'], $plan['legacy_url'], $plan['updated_at'], $plan['path']);
    return $plan;
}, $bindingPlans);
$safeQuarantines = array_map(static function (array $plan): array {
    unset(
        $plan['table'], $plan['url_column'], $plan['purchase_table'], $plan['purchase_column'],
        $plan['legacy_url'], $plan['updated_at']
    );
    return $plan;
}, $quarantinePlans);
$report = [
    'schema_version' => 1, 'release_version' => $releaseVersion, 'mode' => $apply ? 'apply' : 'dry_run',
    'status' => $status, 'generated_at_utc' => gmdate(DATE_ATOM),
    'summary' => [
        'resolvable' => count($bindingPlans), 'quarantinable' => count($quarantinePlans),
        'unresolved' => count($issues),
        'applied' => $status === 'applied_verified' ? count($bindingPlans) : 0,
        'quarantined' => $status === 'applied_verified' ? count($quarantinePlans) : 0,
    ],
    'bindings' => $safePlans, 'quarantines' => $safeQuarantines, 'issues' => $issues,
];
$reportDirectory = dirname(__DIR__) . '/storage/private/catalog-migration-reports';
if (!is_dir($reportDirectory) && !mkdir($reportDirectory, 0700, true) && !is_dir($reportDirectory)) {
    throw new RuntimeException('Unable to create private report directory');
}
$reportPath = $reportDirectory . '/catalog-upload-binding-' . gmdate('Ymd-His') . 'Z-' . bin2hex(random_bytes(4)) . '.json';
atomicPrivateJson($reportPath, $report);
fwrite(STDOUT, 'CATALOG_BINDING_REPORT=' . $reportPath . PHP_EOL);
fwrite(STDOUT, json_encode($report['summary'], JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($status === 'blocked' ? 5 : (in_array($status, ['apply_failed', 'applied_readback_failed'], true) ? 6 : 0));

function optionValue(array $arguments, string $name): string
{
    $index = array_search($name, $arguments, true);
    return $index === false ? '' : trim((string) ($arguments[$index + 1] ?? ''));
}

/** @return array{status:string,size_bytes?:int,sha256?:string,nlink?:int} */
function inspectPublicUpload(string $relative): array
{
    $state = UploadStorageService::storedPathState($relative);
    if (($state['status'] ?? '') !== 'file') return ['status' => (string) ($state['status'] ?? 'unsafe')];
    $path = (string) $state['path'];
    $stat = @lstat($path);
    $size = @filesize($path);
    $hash = @hash_file('sha256', $path);
    if (!is_array($stat) || $size === false || !is_string($hash)) return ['status' => 'unsafe'];
    return ['status' => 'file', 'size_bytes' => (int) $size, 'sha256' => strtolower($hash), 'nlink' => (int) ($stat['nlink'] ?? 0)];
}

/** @param array<int,array<string,mixed>> $bindingPlans @param array<int,array<string,mixed>> $quarantinePlans */
function verifyAppliedBindings(array $bindingPlans, array $quarantinePlans): void
{
    foreach ($bindingPlans as $plan) {
        $row = Database::one(
            "SELECT source_upload_id, {$plan['url_column']} AS legacy_url, size_bytes, file_sha256
             FROM {$plan['table']} WHERE id = ? AND admin_id = ? AND app_id = ?",
            [$plan['id'], $plan['admin_id'], $plan['app_id']]
        );
        if ($row === null || (int) ($row['source_upload_id'] ?? 0) !== (int) $plan['upload_id']
            || (string) ($row['legacy_url'] ?? '') !== (string) $plan['legacy_url']
            || (int) ($row['size_bytes'] ?? 0) !== (int) $plan['size_bytes']
            || !hash_equals((string) $plan['file_sha256'], strtolower((string) ($row['file_sha256'] ?? '')))) {
            throw new RuntimeException('Post-commit binding readback failed');
        }
    }
    foreach ($quarantinePlans as $plan) {
        $row = Database::one(
            "SELECT source_upload_id, {$plan['url_column']} AS legacy_url, size_bytes, file_sha256,
                    status, audit_status
             FROM {$plan['table']} WHERE id = ? AND admin_id = ? AND app_id = ?",
            [$plan['id'], $plan['admin_id'], $plan['app_id']]
        );
        $evidence = Database::one(
            'SELECT legacy_url_sha256 FROM catalog_legacy_url_quarantines
             WHERE catalog_kind = ? AND catalog_id = ? AND admin_id = ? AND app_id = ?',
            [$plan['kind'], $plan['id'], $plan['admin_id'], $plan['app_id']]
        );
        if ($row === null || $row['source_upload_id'] !== null || trim((string) $row['legacy_url']) !== ''
            || (int) $row['size_bytes'] !== 0 || trim((string) $row['file_sha256']) !== ''
            || (int) $row['status'] !== 0 || (string) $row['audit_status'] !== 'on_hold'
            || $evidence === null
            || !hash_equals($plan['legacy_url_sha256'], strtolower((string) $evidence['legacy_url_sha256']))) {
            throw new RuntimeException('Post-commit quarantine readback failed');
        }
    }
}

function atomicPrivateJson(string $path, array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    $temporary = $path . '.partial-' . bin2hex(random_bytes(6));
    $handle = fopen($temporary, 'xb');
    if ($handle === false) throw new RuntimeException('Unable to create private report');
    try {
        if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
            throw new RuntimeException('Unable to persist private report');
        }
    } finally { fclose($handle); }
    @chmod($temporary, 0600);
    if (!rename($temporary, $path)) { @unlink($temporary); throw new RuntimeException('Unable to publish private report'); }
    @chmod($path, 0600);
}
