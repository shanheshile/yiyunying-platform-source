<?php
declare(strict_types=1);

/**
 * Fail-closed primitives for the two-item public catalog conflict repair.
 *
 * The executable tool deliberately keeps database access out of this file so
 * plan validation, backup verification and path discovery can be tested with
 * fixtures and without production credentials.
 */

const CATALOG_CONFLICT_REPAIR_ACTION_JPEG = 'jpeg_to_png_register';
const CATALOG_CONFLICT_REPAIR_ACTION_HEIC = 'heic_to_png_sync';

/** @return array<string,mixed> */
function catalogConflictRepairValidatePlan(array $plan): array
{
    return catalogConflictRepairValidateRuntimePlan($plan);
}

/** @return array<string,mixed> */
function catalogConflictRepairValidateSourcePlan(array $plan): array
{
    catalogConflictRepairAssertExactKeys($plan, ['schema', 'plan_kind', 'batch', 'items'], 'source plan');
    if (($plan['schema'] ?? null) !== 2 || ($plan['plan_kind'] ?? null) !== 'source') {
        throw new InvalidArgumentException('Source repair plan must use schema 2 and plan_kind source');
    }
    $batch = catalogConflictRepairValidateBatch($plan['batch'] ?? null);
    return [
        'schema' => 2,
        'plan_kind' => 'source',
        'batch' => $batch,
        'items' => catalogConflictRepairValidateItems($plan['items'] ?? null, false),
    ];
}

/** @return array<string,mixed> */
function catalogConflictRepairValidateRuntimePlan(array $plan): array
{
    catalogConflictRepairAssertExactKeys($plan, ['schema', 'plan_kind', 'batch', 'backup', 'items'], 'runtime plan');
    if (($plan['schema'] ?? null) !== 2 || ($plan['plan_kind'] ?? null) !== 'runtime') {
        throw new InvalidArgumentException('Runtime repair plan must use schema 2 and plan_kind runtime');
    }
    $batch = catalogConflictRepairValidateBatch($plan['batch'] ?? null);
    $backup = catalogConflictRepairValidateBackupReceipt($plan['backup'] ?? null);
    return [
        'schema' => 2,
        'plan_kind' => 'runtime',
        'batch' => $batch,
        'backup' => $backup,
        'items' => catalogConflictRepairValidateItems($plan['items'] ?? null, true),
    ];
}

/** @return array<string,mixed> */
function catalogConflictRepairValidateBackupReceipt(mixed $backup): array
{
    if (!is_array($backup)) throw new InvalidArgumentException('Repair plan backup receipt is missing');
    catalogConflictRepairAssertExactKeys(
        $backup,
        ['confirmed', 'confirmed_at_utc', 'database', 'public_uploads'],
        'backup'
    );
    if (($backup['confirmed'] ?? null) !== true
        || !catalogConflictRepairUtcTimestamp((string) ($backup['confirmed_at_utc'] ?? ''))) {
        throw new InvalidArgumentException('Repair plan requires a complete, hash-bound backup receipt');
    }
    $databaseBackup = catalogConflictRepairValidateBackupArtifact($backup['database'] ?? null, 'database_gzip');
    $uploadsBackup = catalogConflictRepairValidateBackupArtifact($backup['public_uploads'] ?? null, 'public_uploads_tar_gzip');
    return [
        'confirmed' => true,
        'confirmed_at_utc' => (string) $backup['confirmed_at_utc'],
        'database' => $databaseBackup,
        'public_uploads' => $uploadsBackup,
    ];
}

function catalogConflictRepairValidateBatch(mixed $value): string
{
    $batch = trim((string) $value);
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,79}$/D', $batch) !== 1) {
        throw new InvalidArgumentException('Repair plan batch is invalid');
    }
    return $batch;
}

/** @return list<array<string,mixed>> */
function catalogConflictRepairValidateItems(mixed $items, bool $runtime): array
{
    if (!is_array($items) || !array_is_list($items) || count($items) !== 2) {
        throw new InvalidArgumentException('Repair plan must contain exactly two conflict items');
    }

    $normalized = [];
    $seenActions = [];
    $seenPaths = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) throw new InvalidArgumentException('Repair plan item is invalid');
        catalogConflictRepairAssertExactKeys(
            $item,
            ['path_sha256', 'preimage', 'replacement', 'expected', 'action', 'registration'],
            'item'
        );
        $action = (string) ($item['action'] ?? '');
        if (!in_array($action, [CATALOG_CONFLICT_REPAIR_ACTION_JPEG, CATALOG_CONFLICT_REPAIR_ACTION_HEIC], true)) {
            throw new InvalidArgumentException('Repair action is not allowlisted');
        }
        if (isset($seenActions[$action])) throw new InvalidArgumentException('Repair action is duplicated');
        $seenActions[$action] = true;
        $pathHash = catalogConflictRepairHash($item['path_sha256'] ?? null);
        if ($pathHash === null || isset($seenPaths[$pathHash])) {
            throw new InvalidArgumentException('Repair path hash is invalid or duplicated');
        }
        $seenPaths[$pathHash] = true;
        $preimage = catalogConflictRepairValidateImageFingerprint($item['preimage'] ?? null, false);
        $replacement = catalogConflictRepairValidateReplacement($item['replacement'] ?? null, $runtime);
        if (hash_equals($preimage['sha256'], $replacement['sha256'])) {
            throw new InvalidArgumentException('Repair output must not equal its preimage');
        }
        $expected = catalogConflictRepairValidateExpected($item['expected'] ?? null, $action);
        $registration = catalogConflictRepairValidateRegistration($item['registration'] ?? null, $action);
        $normalized[] = [
            'path_sha256' => $pathHash,
            'preimage' => $preimage,
            'replacement' => $replacement,
            'expected' => $expected,
            'action' => $action,
            'registration' => $registration,
        ];
    }
    if (count($seenActions) !== 2) throw new InvalidArgumentException('Both repair actions are required');

    return $normalized;
}

/** @return array{path:string,size_bytes:int,sha256:string,format:string,mtime_epoch:int} */
function catalogConflictRepairValidateBackupArtifact(mixed $value, string $format): array
{
    if (!is_array($value)) throw new InvalidArgumentException('Backup artifact receipt is missing');
    catalogConflictRepairAssertExactKeys($value, ['path', 'size_bytes', 'sha256', 'format', 'mtime_epoch'], 'backup artifact');
    $path = trim((string) ($value['path'] ?? ''));
    $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
    $size = filter_var($value['size_bytes'] ?? null, FILTER_VALIDATE_INT);
    $mtime = filter_var($value['mtime_epoch'] ?? null, FILTER_VALIDATE_INT);
    $hash = catalogConflictRepairHash($value['sha256'] ?? null);
    if (!$absolute || normalizeRepairPath($path) !== normalizeRepairPath(str_replace('/./', '/', $path))
        || str_contains($path, "\0") || strlen($path) > 1000 || !is_int($size) || $size < 1
        || $size > 1024 * 1024 * 1024 * 1024 || !is_int($mtime) || $mtime < 946684800
        || $mtime > 4102444800 || $hash === null || ($value['format'] ?? null) !== $format) {
        throw new InvalidArgumentException('Backup artifact receipt is invalid');
    }
    return ['path' => $path, 'size_bytes' => $size, 'sha256' => $hash, 'format' => $format, 'mtime_epoch' => $mtime];
}

/** @return array{sha256:string,size_bytes:int,width?:int,height?:int} */
function catalogConflictRepairValidateImageFingerprint(mixed $value, bool $png): array
{
    if (!is_array($value)) throw new InvalidArgumentException('Image fingerprint is missing');
    $keys = $png ? ['sha256', 'size_bytes', 'width', 'height'] : ['sha256', 'size_bytes'];
    catalogConflictRepairAssertExactKeys($value, $keys, $png ? 'postimage' : 'preimage');
    $hash = catalogConflictRepairHash($value['sha256'] ?? null);
    $size = filter_var($value['size_bytes'] ?? null, FILTER_VALIDATE_INT);
    if ($hash === null || !is_int($size) || $size < 1 || $size > 512 * 1024 * 1024) {
        throw new InvalidArgumentException('Image fingerprint is outside the repair boundary');
    }
    $result = ['sha256' => $hash, 'size_bytes' => $size];
    if ($png) {
        $width = filter_var($value['width'] ?? null, FILTER_VALIDATE_INT);
        $height = filter_var($value['height'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($width) || !is_int($height) || $width < 1 || $height < 1
            || $width > 8192 || $height > 8192 || $width * $height > 40000000) {
            throw new InvalidArgumentException('PNG dimensions are outside the repair boundary');
        }
        $result['width'] = $width;
        $result['height'] = $height;
    }
    return $result;
}

/** @return array{path?:string,sha256:string,size_bytes:int,width:int,height:int,metadata_policy:string} */
function catalogConflictRepairValidateReplacement(mixed $value, bool $runtime = true): array
{
    if (!is_array($value)) throw new InvalidArgumentException('Prepared replacement is missing');
    catalogConflictRepairAssertExactKeys(
        $value,
        $runtime
            ? ['path', 'sha256', 'size_bytes', 'width', 'height', 'metadata_policy']
            : ['sha256', 'size_bytes', 'width', 'height', 'metadata_policy'],
        'replacement'
    );
    $path = $runtime ? trim((string) ($value['path'] ?? '')) : '';
    if ($runtime) {
        $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
        if (!$absolute || str_contains($path, "\0") || strlen($path) > 1000) {
            throw new InvalidArgumentException('Prepared replacement path is invalid');
        }
    }
    if (($value['metadata_policy'] ?? null) !== 'no_ancillary_chunks_v1') {
        throw new InvalidArgumentException('Prepared replacement metadata policy is invalid');
    }
    $fingerprint = catalogConflictRepairValidateImageFingerprint([
        'sha256' => $value['sha256'] ?? null,
        'size_bytes' => $value['size_bytes'] ?? null,
        'width' => $value['width'] ?? null,
        'height' => $value['height'] ?? null,
    ], true);
    return ($runtime ? ['path' => $path] : []) + $fingerprint + ['metadata_policy' => 'no_ancillary_chunks_v1'];
}

/** @return array{admin_id:int,app_id:int,path_references:int,upload_id_references:int,upload_rows:int,media_attachment_rows:int} */
function catalogConflictRepairValidateExpected(mixed $value, string $action): array
{
    if (!is_array($value)) throw new InvalidArgumentException('Expected database state is missing');
    $keys = ['admin_id', 'app_id', 'path_references', 'upload_id_references', 'upload_rows', 'media_attachment_rows'];
    catalogConflictRepairAssertExactKeys($value, $keys, 'expected');
    $result = [];
    foreach ($keys as $key) {
        $number = filter_var($value[$key] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($number) || $number < 0) throw new InvalidArgumentException('Expected count or tenant is invalid');
        $result[$key] = $number;
    }
    if ($result['admin_id'] < 1 || $result['app_id'] < 1) {
        throw new InvalidArgumentException('Expected tenant identifiers must be positive');
    }
    if ($action === CATALOG_CONFLICT_REPAIR_ACTION_JPEG
        && ($result['path_references'] !== 8 || $result['upload_rows'] !== 0
            || $result['upload_id_references'] !== 0 || $result['media_attachment_rows'] !== 0)) {
        throw new InvalidArgumentException('JPEG repair must preserve eight references and create one new upload');
    }
    if ($action === CATALOG_CONFLICT_REPAIR_ACTION_HEIC
        && ($result['path_references'] !== 3 || $result['upload_rows'] !== 1 || $result['media_attachment_rows'] !== 1
            || $result['upload_id_references'] !== 1)) {
        throw new InvalidArgumentException('HEIC repair requires exactly three path references, one upload-id reference, one upload and one media attachment');
    }
    return $result;
}

/** @return array{user_id:?int,scene:string,original_name:string}|null */
function catalogConflictRepairValidateRegistration(mixed $value, string $action): ?array
{
    if ($action === CATALOG_CONFLICT_REPAIR_ACTION_HEIC) {
        if ($value !== null) throw new InvalidArgumentException('HEIC repair cannot create an upload registration');
        return null;
    }
    if (!is_array($value)) throw new InvalidArgumentException('JPEG repair registration is missing');
    catalogConflictRepairAssertExactKeys($value, ['user_id', 'scene', 'original_name'], 'registration');
    $userId = $value['user_id'] ?? null;
    if ($userId !== null) {
        $userId = filter_var($userId, FILTER_VALIDATE_INT);
        if (!is_int($userId) || $userId < 1) throw new InvalidArgumentException('Registration user is invalid');
    }
    $scene = trim((string) ($value['scene'] ?? ''));
    $original = basename(trim((string) ($value['original_name'] ?? '')));
    if ($scene === '' || strlen($scene) > 40 || $original === '' || strlen($original) > 255
        || strtolower(pathinfo($original, PATHINFO_EXTENSION)) !== 'png') {
        throw new InvalidArgumentException('Registration metadata is invalid');
    }
    return ['user_id' => $userId, 'scene' => $scene, 'original_name' => $original];
}

/** @return array{plan:array<string,mixed>,plan_sha256:string,resolved_path:string} */
function catalogConflictRepairLoadPrivatePlan(string $path, string $publicRoot): array
{
    if ($path === '' || !is_file($path) || is_link($path)) throw new RuntimeException('Private repair plan is missing or linked');
    $resolved = realpath($path);
    $public = realpath($publicRoot);
    $stat = @lstat($path);
    if ($resolved === false || $public === false || !is_array($stat) || (int) ($stat['nlink'] ?? 0) !== 1
        || str_starts_with(str_replace('\\', '/', $resolved), rtrim(str_replace('\\', '/', $public), '/') . '/')) {
        throw new RuntimeException('Private repair plan path is unsafe');
    }
    if (PHP_OS_FAMILY !== 'Windows' && (((int) ($stat['mode'] ?? 0) & 0777) !== 0600)) {
        throw new RuntimeException('Private repair plan must have mode 0600');
    }
    if (function_exists('posix_geteuid') && isset($stat['uid']) && (int) $stat['uid'] !== posix_geteuid()) {
        throw new RuntimeException('Private repair plan must be owned by the current user');
    }
    $size = @filesize($resolved);
    $bytes = is_int($size) && $size > 0 && $size <= 131072 ? @file_get_contents($resolved) : false;
    if (!is_string($bytes)) throw new RuntimeException('Private repair plan cannot be read safely');
    $decoded = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) throw new RuntimeException('Private repair plan is not an object');
    return [
        'plan' => catalogConflictRepairValidatePlan($decoded),
        'plan_sha256' => strtolower(hash('sha256', $bytes)),
        'resolved_path' => $resolved,
    ];
}

function catalogConflictRepairPathHash(string $relative): string
{
    return strtolower(hash('sha256', $relative));
}

/** @param list<string> $wanted @return array<string,array{relative:string,absolute:string}> */
function catalogConflictRepairDiscoverPaths(string $uploadsRoot, array $wanted): array
{
    $root = realpath($uploadsRoot);
    if ($root === false || !is_dir($uploadsRoot) || is_link($uploadsRoot)) {
        throw new RuntimeException('Public uploads root is missing or unsafe');
    }
    $wantedMap = [];
    foreach ($wanted as $hash) {
        $normalized = catalogConflictRepairHash($hash);
        if ($normalized === null || isset($wantedMap[$normalized])) throw new InvalidArgumentException('Wanted path hash is invalid');
        $wantedMap[$normalized] = [];
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink()) throw new RuntimeException('A symbolic link exists below public/uploads');
        if ($entry->isDir()) continue;
        if (!$entry->isFile()) throw new RuntimeException('A non-regular public upload entry exists');
        $inside = ltrim(str_replace('\\', '/', substr($entry->getPathname(), strlen(rtrim($uploadsRoot, '/\\')))), '/');
        if ($inside === '.gitkeep') continue;
        $relative = catalogPublicQuarantineCanonicalRelative('uploads/' . $inside);
        if ($relative === null) throw new RuntimeException('A non-canonical public upload path exists');
        $hash = catalogConflictRepairPathHash($relative);
        if (isset($wantedMap[$hash])) $wantedMap[$hash][] = ['relative' => $relative, 'absolute' => $entry->getPathname()];
    }
    $result = [];
    foreach ($wantedMap as $hash => $matches) {
        if (count($matches) !== 1) throw new RuntimeException('Path hash did not resolve to exactly one public upload');
        $result[$hash] = $matches[0];
    }
    return $result;
}

/** @return array{size_bytes:int,sha256:string,nlink:int,mode:int} */
function catalogConflictRepairFingerprint(string $path, string $allowedRoot): array
{
    $root = realpath($allowedRoot);
    $resolved = realpath($path);
    $stat = @lstat($path);
    if ($root === false || $resolved === false || !is_file($path) || is_link($path) || !is_array($stat)
        || (int) ($stat['nlink'] ?? 0) !== 1
        || !str_starts_with(str_replace('\\', '/', $resolved), rtrim(str_replace('\\', '/', $root), '/') . '/')) {
        throw new RuntimeException('Repair file is missing, linked, hard-linked or outside its root');
    }
    $size = @filesize($path);
    $hash = @hash_file('sha256', $path);
    if (!is_int($size) || $size < 1 || !is_string($hash) || catalogConflictRepairHash($hash) === null) {
        throw new RuntimeException('Repair file fingerprint failed');
    }
    return [
        'size_bytes' => $size,
        'sha256' => strtolower($hash),
        'nlink' => 1,
        'mode' => ((int) ($stat['mode'] ?? 0)) & 0777,
    ];
}

/** @param array{sha256:string,size_bytes:int} $expected */
function catalogConflictRepairFingerprintMatches(array $actual, array $expected): bool
{
    return (int) ($actual['size_bytes'] ?? -1) === $expected['size_bytes']
        && is_string($actual['sha256'] ?? null)
        && hash_equals($expected['sha256'], strtolower((string) $actual['sha256']));
}

/** @return 'jpeg'|'heic'|'png'|'unknown' */
function catalogConflictRepairContentKind(string $path): string
{
    $prefix = catalogMigrationReadFilePrefix($path, 8192);
    if ($prefix === null) return 'unknown';
    if (catalogMigrationSignatureMatchesExtension($prefix, 'png')) return 'png';
    if (catalogMigrationSignatureMatchesExtension($prefix, 'jpg')) return 'jpeg';
    if (catalogMigrationSignatureMatchesExtension($prefix, 'heic')) return 'heic';
    return 'unknown';
}

/** @param array{sha256:string,size_bytes:int,width:int,height:int} $expected @return array{size_bytes:int,sha256:string,nlink:int,mode:int,width:int,height:int} */
function catalogConflictRepairAssertPng(string $path, string $allowedRoot, array $expected): array
{
    $fingerprint = catalogConflictRepairFingerprint($path, $allowedRoot);
    if (!catalogConflictRepairFingerprintMatches($fingerprint, $expected)
        || catalogConflictRepairContentKind($path) !== 'png'
        || catalogMigrationAssessPublicUploadFile($path) !== 'safe'
        || !catalogConflictRepairPngHasNoAncillaryMetadata($path)) {
        throw new RuntimeException('Converted output failed its PNG fingerprint or type contract');
    }
    if (!function_exists('imagecreatefrompng')) {
        throw new RuntimeException('GD PNG decoder capability is required');
    }
    $image = @getimagesize($path);
    if (!is_array($image) || strtolower((string) ($image['mime'] ?? '')) !== 'image/png'
        || (int) ($image[0] ?? 0) !== $expected['width']
        || (int) ($image[1] ?? 0) !== $expected['height']) {
        throw new RuntimeException('Converted output failed its decoded PNG dimensions');
    }
    $previousHandler = set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });
    try {
        $decoded = imagecreatefrompng($path);
        if ($decoded === false || imagesx($decoded) !== $expected['width'] || imagesy($decoded) !== $expected['height']) {
            throw new RuntimeException('Converted output failed complete PNG pixel decode');
        }
    } catch (Throwable $exception) {
        throw new RuntimeException('Converted output failed complete PNG pixel decode', 0, $exception);
    } finally {
        // PHP 8.5 deprecates explicit destruction of GdImage objects. Legacy
        // resource handles still need an explicit release; objects are scoped.
        if (isset($decoded) && is_resource($decoded)) @imagedestroy($decoded);
        restore_error_handler();
    }
    return $fingerprint + ['width' => $expected['width'], 'height' => $expected['height']];
}

/** @param array{path:string,sha256:string,size_bytes:int,width:int,height:int,metadata_policy:string} $replacement */
function catalogConflictRepairAssertPrivateReplacement(array $replacement, string $publicRoot): array
{
    $path = $replacement['path'];
    $resolved = realpath($path);
    $public = realpath($publicRoot);
    $stat = @lstat($path);
    if ($resolved === false || $public === false || normalizeRepairPath($resolved) !== normalizeRepairPath($path)
        || !is_file($path) || is_link($path) || !is_array($stat) || (int) ($stat['nlink'] ?? 0) !== 1
        || str_starts_with(normalizeRepairPath($resolved), rtrim(normalizeRepairPath($public), '/') . '/')) {
        throw new RuntimeException('Prepared PNG must be a unique regular file outside the public root');
    }
    if (PHP_OS_FAMILY !== 'Windows' && (((int) ($stat['mode'] ?? 0) & 0777) !== 0600)) {
        throw new RuntimeException('Prepared PNG must have mode 0600');
    }
    if (function_exists('posix_geteuid') && isset($stat['uid']) && (int) $stat['uid'] !== posix_geteuid()) {
        throw new RuntimeException('Prepared PNG must be owned by the current user');
    }
    return catalogConflictRepairAssertPng($path, dirname($resolved), $replacement);
}

function catalogConflictRepairAssertBackupArtifacts(array $backup, string $publicRoot, bool $requireFresh = true): void
{
    if (PHP_OS_FAMILY !== 'Linux') throw new RuntimeException('Production backup verification requires Linux');
    $database = catalogConflictRepairAssertPrivateBackupArtifact($backup['database'], $publicRoot, $requireFresh);
    $uploads = catalogConflictRepairAssertPrivateBackupArtifact($backup['public_uploads'], $publicRoot, $requireFresh);
    if ($database['resolved_path'] === $uploads['resolved_path']) {
        throw new RuntimeException('Database and uploads backups must be distinct files');
    }

    $gzip = catalogConflictRepairSystemBinary(['/usr/bin/gzip', '/bin/gzip']);
    catalogConflictRepairRunFixedArchiveCommand([$gzip, '-t', '--', $database['resolved_path']], 120);
    $handle = @gzopen($database['resolved_path'], 'rb');
    if ($handle === false) throw new RuntimeException('Database gzip could not be decompressed');
    $decompressedBytes = 0;
    $nonWhitespace = false;
    try {
        while (!gzeof($handle)) {
            $chunk = gzread($handle, 1024 * 1024);
            if (!is_string($chunk)) throw new RuntimeException('Database gzip decompression failed');
            $decompressedBytes += strlen($chunk);
            if (!$nonWhitespace && preg_match('/\S/', $chunk) === 1) $nonWhitespace = true;
            if ($decompressedBytes > 16 * 1024 * 1024 * 1024) {
                throw new RuntimeException('Database backup decompressed size exceeds the review boundary');
            }
        }
    } finally {
        gzclose($handle);
    }
    if ($decompressedBytes < 1 || !$nonWhitespace) throw new RuntimeException('Database backup decompresses to empty content');

    $tar = catalogConflictRepairSystemBinary(['/usr/bin/tar', '/bin/tar']);
    $listing = catalogConflictRepairRunFixedArchiveCommand([$tar, '-tzf', $uploads['resolved_path']], 120, 16 * 1024 * 1024);
    $hasUploads = false;
    foreach (preg_split('/\r?\n/', trim($listing)) ?: [] as $entry) {
        $entry = str_replace('\\', '/', trim($entry));
        if ($entry === '') continue;
        $canonical = str_starts_with($entry, './') ? substr($entry, 2) : $entry;
        if (str_starts_with($canonical, '/') || preg_match('#(^|/)\.\.(/|$)#', $canonical) === 1) {
            throw new RuntimeException('Uploads backup contains an unsafe archive entry');
        }
        if ($canonical === 'public/uploads' || str_starts_with($canonical, 'public/uploads/')) $hasUploads = true;
    }
    if (!$hasUploads) throw new RuntimeException('Uploads backup does not contain public/uploads');
}

/** @return array{resolved_path:string,size_bytes:int,sha256:string} */
function catalogConflictRepairAssertPrivateBackupArtifact(array $artifact, string $publicRoot, bool $requireFresh = true): array
{
    $path = (string) $artifact['path'];
    $resolved = realpath($path);
    $public = realpath($publicRoot);
    $stat = @lstat($path);
    if ($resolved === false || $public === false || normalizeRepairPath($resolved) !== normalizeRepairPath($path)
        || !is_file($path) || is_link($path) || !is_array($stat) || (int) ($stat['nlink'] ?? 0) !== 1
        || (((int) ($stat['mode'] ?? 0)) & 0777) !== 0600
        || !isset($stat['uid']) || !function_exists('posix_geteuid') || (int) $stat['uid'] !== posix_geteuid()
        || str_starts_with(normalizeRepairPath($resolved), rtrim(normalizeRepairPath($public), '/') . '/')) {
        throw new RuntimeException('Backup artifact ownership, mode or path is unsafe');
    }
    $size = @filesize($resolved);
    $hash = @hash_file('sha256', $resolved);
    $age = time() - (int) ($stat['mtime'] ?? 0);
    if (!is_int($size) || $size !== (int) $artifact['size_bytes'] || !is_string($hash)
        || !hash_equals((string) $artifact['sha256'], strtolower($hash))
        || (int) ($stat['mtime'] ?? 0) !== (int) ($artifact['mtime_epoch'] ?? -1)
        || ($requireFresh && ($age < -300 || $age > 4 * 3600))) {
        throw new RuntimeException('Backup artifact fingerprint readback failed');
    }
    return ['resolved_path' => $resolved, 'size_bytes' => $size, 'sha256' => strtolower($hash)];
}

function catalogConflictRepairSystemBinary(array $candidates): string
{
    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        $stat = $resolved === false ? false : @lstat($resolved);
        if ($resolved !== false && is_file($resolved) && is_executable($resolved) && is_array($stat)
            && (int) ($stat['uid'] ?? -1) === 0 && ((((int) ($stat['mode'] ?? 0)) & 0022) === 0)) return $resolved;
    }
    throw new RuntimeException('Required system archive verifier is unavailable or unsafe');
}

function catalogConflictRepairRunFixedArchiveCommand(array $command, int $timeoutSeconds, int $maximumOutput = 65536): string
{
    $pipes = [];
    $process = @proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['LC_ALL' => 'C', 'PATH' => '/usr/bin:/bin'],
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) throw new RuntimeException('Archive verifier could not start');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $started = microtime(true);
    $exitCode = null;
    $timedOut = false;
    do {
        $stdout .= (string) stream_get_contents($pipes[1], 65536);
        $stderr .= (string) stream_get_contents($pipes[2], 65536);
        if (strlen($stdout) > $maximumOutput || strlen($stderr) > 65536) {
            proc_terminate($process);
            $timedOut = true;
            break;
        }
        $status = proc_get_status($process);
        if (!(bool) ($status['running'] ?? false)) {
            $exitCode = (int) ($status['exitcode'] ?? -1);
            break;
        }
        if (microtime(true) - $started > $timeoutSeconds) {
            proc_terminate($process);
            $timedOut = true;
            break;
        }
        usleep(20000);
    } while (true);
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closed = proc_close($process);
    if ($exitCode === null && $closed >= 0) $exitCode = $closed;
    if ($timedOut || $exitCode !== 0) throw new RuntimeException('Backup archive verification failed');
    return $stdout;
}

/**
 * Accept critical PNG chunks only. Text, EXIF, time, ICC, tRNS and every
 * ancillary chunk are rejected; transparency must be encoded in RGBA pixels.
 */
function catalogConflictRepairPngHasNoAncillaryMetadata(string $path): bool
{
    $bytes = @file_get_contents($path);
    if (!is_string($bytes) || strlen($bytes) < 57 || !str_starts_with($bytes, "\x89PNG\x0D\x0A\x1A\x0A")) return false;
    $offset = 8;
    $chunks = 0;
    $seenIhdr = false;
    $seenIdat = false;
    $seenIend = false;
    $allowed = ['IHDR' => true, 'PLTE' => true, 'IDAT' => true, 'IEND' => true];
    $length = strlen($bytes);
    while ($offset + 12 <= $length && $chunks++ < 10000) {
        $sizeData = unpack('Nlength', substr($bytes, $offset, 4));
        $size = (int) ($sizeData['length'] ?? -1);
        $type = substr($bytes, $offset + 4, 4);
        if ($size < 0 || $size > 64 * 1024 * 1024 || preg_match('/^[A-Za-z]{4}$/D', $type) !== 1
            || !isset($allowed[$type]) || $offset + 12 + $size > $length) return false;
        $data = substr($bytes, $offset + 8, $size);
        $crc = substr($bytes, $offset + 8 + $size, 4);
        if (!hash_equals(strtolower(hash('crc32b', $type . $data)), strtolower(bin2hex($crc)))) return false;
        if (!$seenIhdr && ($type !== 'IHDR' || $size !== 13)) return false;
        if ($type === 'IHDR') {
            if ($seenIhdr || $chunks !== 1) return false;
            $seenIhdr = true;
        } elseif ($type === 'IDAT') {
            if (!$seenIhdr || $seenIend) return false;
            $seenIdat = true;
        } elseif ($type === 'IEND') {
            if (!$seenIdat || $seenIend || $size !== 0) return false;
            $seenIend = true;
        }
        $offset += 12 + $size;
        if ($seenIend) break;
    }
    return $seenIhdr && $seenIdat && $seenIend && $offset === $length;
}

function normalizeRepairPath(string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
}

function catalogConflictRepairEnsurePrivateDirectory(string $directory, string $publicRoot): void
{
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create private repair directory');
    }
    $resolved = realpath($directory);
    $public = realpath($publicRoot);
    if ($resolved === false || $public === false || is_link($directory)
        || str_starts_with(normalizeRepairPath($resolved), rtrim(normalizeRepairPath($public), '/') . '/')) {
        throw new RuntimeException('Repair recovery directory is unsafe');
    }
    @chmod($directory, 0700);
}

function catalogConflictRepairWritePrivateJson(string $path, array $data, string $publicRoot): void
{
    catalogConflictRepairEnsurePrivateDirectory(dirname($path), $publicRoot);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    $temporary = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.partial';
    $handle = @fopen($temporary, 'x+b');
    if ($handle === false) throw new RuntimeException('Unable to create private repair journal');
    try {
        @chmod($temporary, 0600);
        if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)
            || (function_exists('fsync') && !fsync($handle))) {
            throw new RuntimeException('Unable to write private repair journal');
        }
    } finally {
        fclose($handle);
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to publish private repair journal');
    }
    @chmod($path, 0600);
}

function catalogConflictRepairHash(mixed $value): ?string
{
    if (!is_string($value)) return null;
    $value = strtolower(trim($value));
    return preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? $value : null;
}

function catalogConflictRepairUtcTimestamp(string $value): bool
{
    if ($value === '' || !str_ends_with($value, 'Z')) return false;
    try {
        $time = new DateTimeImmutable($value);
        return $time->getOffset() === 0;
    } catch (Throwable) {
        return false;
    }
}

/** @param list<string> $keys */
function catalogConflictRepairAssertExactKeys(array $value, array $keys, string $label): void
{
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($keys, SORT_STRING);
    if ($actual !== $keys) throw new InvalidArgumentException("Unexpected {$label} fields");
}
