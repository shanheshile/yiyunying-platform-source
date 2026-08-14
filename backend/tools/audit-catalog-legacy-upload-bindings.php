<?php
declare(strict_types=1);

$arguments = $argv;
$backendRoot = auditOption($arguments, '--backend-root');
$helperPath = auditOption($arguments, '--helper');
$backendRoot = $backendRoot !== '' ? realpath($backendRoot) : dirname(__DIR__);
if (!is_string($backendRoot) || !is_file($backendRoot . '/bootstrap.php')) {
    fwrite(STDERR, "A valid --backend-root is required.\n");
    exit(2);
}
if ($helperPath === '') $helperPath = __DIR__ . '/catalog-legacy-upload-binding.php';
if (!is_file($helperPath)) {
    fwrite(STDERR, "A valid --helper is required.\n");
    exit(2);
}

require_once $backendRoot . '/bootstrap.php';
require_once $helperPath;

use Yiyunying\Core\Database;

$definitions = [
    ['kind' => 'resource', 'table' => 'resources', 'url' => 'download_url'],
    ['kind' => 'store_app', 'table' => 'store_apps', 'url' => 'apk_url'],
];
$applicationUrl = array_values(array_unique(array_filter([
    (string) config('app.url'),
    auditOption($arguments, '--allowed-origin'),
], static fn (string $value): bool => trim($value) !== '')));
$summary = ['rows' => 0, 'resolvable' => 0, 'reasons' => []];
$resolvedUploadCounts = [];
foreach ($definitions as $definition) {
    $rows = Database::all(
        "SELECT id, admin_id, app_id, user_id, {$definition['url']} AS legacy_url,
                size_bytes, file_sha256, " . ($definition['kind'] === 'resource' ? 'resource_type' : "'app_store' AS resource_type") . ",
                deleted_at
         FROM {$definition['table']} WHERE source_upload_id IS NULL AND TRIM({$definition['url']}) <> '' ORDER BY id"
    );
    foreach ($rows as $row) {
        $summary['rows']++;
        $scene = $definition['kind'] === 'resource'
            ? auditCatalogScene((string) $row['resource_type'])
            : 'store_app_package';
        $canonical = catalogLegacyCanonicalPath((string) $row['legacy_url'], (int) $row['app_id'], $applicationUrl);
        $uploads = [];
        if ($canonical['ok'] ?? false) {
            $uploads = Database::all(
                'SELECT id, admin_id, app_id, user_id, scene, file_path, size_bytes, sha256, status
                 FROM uploads WHERE file_path = ? ORDER BY id',
                [(string) $canonical['path']]
            );
        }
        $result = catalogLegacyResolveBinding($row + ['scene' => $scene], $uploads, $applicationUrl, 'auditInspectPublicUpload');
        if ($result['ok'] ?? false) {
            $summary['resolvable']++;
            $uploadId = (int) $result['upload_id'];
            $resolvedUploadCounts[$uploadId] = (int) ($resolvedUploadCounts[$uploadId] ?? 0) + 1;
        } else {
            $reason = (string) ($result['reason'] ?? 'unresolved');
            $summary['reasons'][$reason] = (int) ($summary['reasons'][$reason] ?? 0) + 1;
        }
    }
}
ksort($summary['reasons']);
$summary['unresolved'] = $summary['rows'] - $summary['resolvable'];
$summary['distinct_uploads'] = count($resolvedUploadCounts);
$distribution = [];
foreach ($resolvedUploadCounts as $rowCount) {
    $distribution[(string) $rowCount] = (int) ($distribution[(string) $rowCount] ?? 0) + 1;
}
ksort($distribution, SORT_NUMERIC);
$summary['catalog_rows_per_upload_distribution'] = $distribution;
fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
exit($summary['unresolved'] === 0 ? 0 : 5);

function auditOption(array $arguments, string $name): string
{
    $index = array_search($name, $arguments, true);
    return $index === false ? '' : trim((string) ($arguments[$index + 1] ?? ''));
}

function auditCatalogScene(string $resourceType): string
{
    return in_array(strtolower(trim($resourceType)), ['source', 'source_store', 'source_market', 'code'], true)
        ? 'resource_source'
        : 'store_app_package';
}

/** @return array{status:string,size_bytes?:int,sha256?:string,nlink?:int} */
function auditInspectPublicUpload(string $relative): array
{
    global $backendRoot;
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, 'private/')) {
        return ['status' => 'unsafe'];
    }
    $root = realpath($backendRoot . '/public');
    if ($root === false) return ['status' => 'missing'];
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $current = $root;
    foreach (explode('/', $relative) as $part) {
        if ($part === '' || $part === '.' || $part === '..') return ['status' => 'unsafe'];
        $current .= '/' . $part;
        if (!file_exists($current) && !is_link($current)) return ['status' => 'missing'];
        if (is_link($current)) return ['status' => 'unsafe'];
    }
    $path = realpath($current);
    if ($path === false || !is_file($path)
        || !str_starts_with(str_replace('\\', '/', $path), $root . '/')) return ['status' => 'unsafe'];
    $stat = @lstat($path);
    $size = @filesize($path);
    $hash = @hash_file('sha256', $path);
    if (!is_array($stat) || $size === false || !is_string($hash)) return ['status' => 'unsafe'];
    return ['status' => 'file', 'size_bytes' => (int) $size, 'sha256' => strtolower($hash), 'nlink' => (int) ($stat['nlink'] ?? 0)];
}
