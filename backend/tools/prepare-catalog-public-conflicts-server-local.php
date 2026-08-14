<?php
declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '0');
@ini_set('html_errors', '0');

use Yiyunying\Core\Database;

require_once __DIR__ . '/catalog-public-upload-type.php';
require_once __DIR__ . '/catalog-public-quarantine-contract.php';
require_once __DIR__ . '/catalog-public-conflict-repair-contract.php';
require_once __DIR__ . '/catalog-conflict-server-local-preparation-contract.php';
define('YIYUNYING_CONFLICT_REPAIR_LIBRARY_ONLY', true);
require_once __DIR__ . '/repair-catalog-public-conflicts.php';

if (!defined('YIYUNYING_CONFLICT_SERVER_LOCAL_LIBRARY_ONLY')) {
    exit(catalogConflictServerLocalMain($argv));
}

function catalogConflictServerLocalMain(array $arguments): int
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "This maintenance tool is CLI-only.\n");
        return 2;
    }
    try {
        $options = catalogConflictServerLocalParseOptions($arguments);
    } catch (Throwable) {
        fwrite(STDERR, "Catalog server-local preparation arguments were rejected.\n");
        return 2;
    }

    $root = dirname(__DIR__);
    $publicRoot = $root . '/public';
    $uploadsRoot = $publicRoot . '/uploads';
    $stage = (string) $options['output_directory'];
    $stageCreated = false;
    $lockHeld = false;
    $success = false;
    $errorHandlerInstalled = false;
    $sensitiveNames = ['jpeg-source.input', 'heic-source.input'];
    $knownNames = [
        ...$sensitiveNames,
        'jpeg-ffmpeg.png.partial', 'heic-ffmpeg.png.partial',
        'jpeg-prepared.png.partial', 'heic-prepared.png.partial',
        'jpeg-prepared.png', 'heic-prepared.png', 'source-plan.json',
    ];
    try {
        error_reporting(E_ALL);
        set_error_handler(static function (int $severity): bool {
            if ((error_reporting() & $severity) === 0) return false;
            throw new ErrorException('Server-local operation emitted a warning', 0, $severity);
        });
        $errorHandlerInstalled = true;
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Root execution is required');
        }
        umask(0077);
        require $root . '/bootstrap.php';

        $lock = Database::one('SELECT GET_LOCK(?, 0) AS acquired', [CATALOG_CONFLICT_REPAIR_LOCK]);
        if ((int) ($lock['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('Catalog maintenance lock is unavailable');
        }
        $lockHeld = true;
        catalogConflictRepairAssertGateClosed();

        $backup = catalogConflictServerLocalBackupReceipt(
            (string) $options['database_backup'],
            (string) $options['uploads_backup']
        );
        catalogConflictRepairAssertBackupFresh($backup);
        catalogConflictRepairAssertBackupArtifacts($backup, $publicRoot, true);
        catalogConflictServerLocalAssertRuntime();

        $bindings = catalogConflictServerLocalBindings();
        $paths = catalogConflictRepairDiscoverPaths(
            $uploadsRoot,
            array_map(static fn(array $binding): string => (string) $binding['path_sha256'], $bindings)
        );
        $states = [];
        foreach ($bindings as $action => $binding) {
            $path = $paths[$binding['path_sha256']] ?? null;
            if (!is_array($path)) throw new RuntimeException('A fixed conflict did not resolve uniquely');
            $fingerprint = catalogConflictRepairFingerprint((string) $path['absolute'], $uploadsRoot);
            if (!catalogConflictRepairFingerprintMatches($fingerprint, $binding['preimage'])
                || catalogConflictRepairContentKind((string) $path['absolute']) !== $binding['content_kind']) {
                throw new RuntimeException('A fixed conflict preimage changed');
            }
            $database = catalogConflictRepairDatabaseState((string) $path['relative'], [], false);
            catalogConflictServerLocalExpectedState($database, $binding);
            $states[$action] = ['path' => $path, 'database' => $database, 'fingerprint' => $fingerprint];
        }

        catalogConflictServerLocalCreateStage($stage);
        $stageCreated = true;
        $artifacts = [];
        foreach ($bindings as $action => $binding) {
            $prefix = $action === CATALOG_CONFLICT_REPAIR_ACTION_JPEG ? 'jpeg' : 'heic';
            $input = $stage . '/' . $prefix . '-source.input';
            $ffmpegOutput = $stage . '/' . $prefix . '-ffmpeg.png.partial';
            $partial = $stage . '/' . $prefix . '-prepared.png.partial';
            $output = $stage . '/' . $prefix . '-prepared.png';
            catalogConflictRepairCopyVerified(
                (string) $states[$action]['path']['absolute'],
                $input,
                $binding['preimage'],
                $stage,
                0600,
                false
            );
            if (catalogConflictRepairContentKind($input) !== $binding['content_kind']) {
                throw new RuntimeException('Private conversion input signature changed');
            }
            $probeProcess = catalogConflictServerLocalRun(
                catalogConflictServerLocalProbeCommand($input), 15, 32768
            );
            if ($probeProcess['stderr'] !== '') throw new RuntimeException('FFprobe emitted an unexpected diagnostic');
            $probe = catalogConflictServerLocalParseProbe($probeProcess['stdout'], $binding);

            $conversion = catalogConflictServerLocalRun(
                catalogConflictServerLocalConvertCommand($input, $ffmpegOutput), 60, 65536
            );
            if ($conversion['stdout'] !== '' || $conversion['stderr'] !== '') {
                throw new RuntimeException('FFmpeg emitted an unexpected diagnostic');
            }
            if (!@chmod($ffmpegOutput, 0600)) throw new RuntimeException('FFmpeg PNG mode could not be fixed');
            $sanitized = catalogConflictServerLocalStripAncillaryPng($ffmpegOutput, $partial);
            if (!@unlink($ffmpegOutput)) throw new RuntimeException('FFmpeg intermediate cleanup failed');
            $size = @filesize($partial);
            $hash = @hash_file('sha256', $partial);
            $dimensions = @getimagesize($partial);
            if (!is_int($size) || $size < 1 || $size > 512 * 1024 * 1024 || !is_string($hash)
                || catalogConflictRepairHash($hash) === null || !is_array($dimensions)
                || (int) ($dimensions[0] ?? 0) !== $probe['width']
                || (int) ($dimensions[1] ?? 0) !== $probe['height']
                || $sanitized['width'] !== $probe['width'] || $sanitized['height'] !== $probe['height']
                || $sanitized['size_bytes'] !== $size || !hash_equals($sanitized['sha256'], strtolower($hash))) {
                throw new RuntimeException('Prepared PNG fingerprint could not be established');
            }
            $replacement = [
                'sha256' => strtolower($hash),
                'size_bytes' => $size,
                'width' => $probe['width'],
                'height' => $probe['height'],
                'metadata_policy' => 'no_ancillary_chunks_v1',
            ];
            catalogConflictRepairAssertPng($partial, $stage, $replacement);
            if (!@rename($partial, $output) || !@chmod($output, 0600)) {
                throw new RuntimeException('Prepared PNG could not be published privately');
            }
            catalogConflictRepairAssertPng($output, $stage, $replacement);
            if (!@unlink($input)) throw new RuntimeException('Sensitive private conversion input cleanup failed');
            $artifacts[$action] = [
                'database' => $states[$action]['database'],
                'replacement' => $replacement,
            ];
        }

        // Bind the source plan only after both original files and their full
        // database reference contracts are independently read back unchanged.
        foreach ($bindings as $action => $binding) {
            $path = $states[$action]['path'];
            $after = catalogConflictRepairFingerprint((string) $path['absolute'], $uploadsRoot);
            if (!catalogConflictRepairFingerprintMatches($after, $binding['preimage'])
                || catalogConflictRepairContentKind((string) $path['absolute']) !== $binding['content_kind']) {
                throw new RuntimeException('Conflict source changed during preparation');
            }
            $databaseAfter = catalogConflictRepairDatabaseState((string) $path['relative'], [], false);
            $expectedAfter = catalogConflictServerLocalExpectedState($databaseAfter, $binding);
            $candidate = [
                'path_sha256' => $binding['path_sha256'],
                'preimage' => $binding['preimage'],
                'replacement' => $artifacts[$action]['replacement'],
                'expected' => $expectedAfter,
                'action' => $action,
                'registration' => $binding['registration'],
            ];
            if (!catalogConflictRepairDatabaseMatchesPending($databaseAfter, $candidate)) {
                throw new RuntimeException('Conflict database state changed during preparation');
            }
            $artifacts[$action]['database'] = $databaseAfter;
        }
        $plan = catalogConflictServerLocalBuildSourcePlan((string) $options['batch'], $artifacts);
        $planPath = $stage . '/source-plan.json';
        catalogConflictRepairWritePrivateJson($planPath, $plan, $publicRoot);
        $planBytes = @file_get_contents($planPath);
        if (!is_string($planBytes) || strlen($planBytes) < 1 || strlen($planBytes) > 131072) {
            throw new RuntimeException('Server-local source plan readback failed');
        }
        $decoded = json_decode($planBytes, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('Server-local source plan readback is invalid');
        catalogConflictRepairValidateSourcePlan($decoded);
        $entries = array_values(array_diff(scandir($stage) ?: [], ['.', '..']));
        sort($entries, SORT_STRING);
        if ($entries !== ['heic-prepared.png', 'jpeg-prepared.png', 'source-plan.json']) {
            throw new RuntimeException('Server-local stage contains an unexpected entry');
        }

        $receiptItems = [];
        foreach ($plan['items'] as $item) {
            $receiptItems[] = [
                'action' => $item['action'],
                'path_sha256' => $item['path_sha256'],
                'replacement_sha256' => $item['replacement']['sha256'],
                'replacement_size_bytes' => $item['replacement']['size_bytes'],
            ];
        }
        usort($receiptItems, static fn(array $left, array $right): int => strcmp($left['action'], $right['action']));
        $receipt = [
            'schema' => 1,
            'status' => 'prepared',
            'batch' => $plan['batch'],
            'source_plan_sha256' => hash('sha256', $planBytes),
            'items' => $receiptItems,
        ];
        $success = true;
        echo json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        return 0;
    } catch (Throwable) {
        $cleanup = $stageCreated ? catalogConflictServerLocalCleanupStage($stage, $knownNames) : true;
        fwrite(STDERR, 'Catalog server-local preparation failed: '
            . ($cleanup ? 'preparation_failed_closed' : 'cleanup_required') . PHP_EOL);
        return 1;
    } finally {
        if (!$success && $stageCreated) {
            // The catch already attempted a bounded cleanup.  Never recurse or
            // follow a link here; a residual root-only stage needs review.
            catalogConflictServerLocalCleanupStage($stage, $knownNames);
        }
        if ($lockHeld) {
            try { Database::one('SELECT RELEASE_LOCK(?) AS released', [CATALOG_CONFLICT_REPAIR_LOCK]); } catch (Throwable) {}
        }
        if ($errorHandlerInstalled) restore_error_handler();
    }
}

/** @return array<string,string> */
function catalogConflictServerLocalParseOptions(array $arguments): array
{
    $valueOptions = [
        '--output-directory' => 'output_directory',
        '--batch' => 'batch',
        '--database-backup' => 'database_backup',
        '--public-uploads-backup' => 'uploads_backup',
    ];
    $flags = ['--maintenance-confirmed', '--backup-confirmed', '--gate-confirmed'];
    $values = [];
    $seenFlags = [];
    for ($index = 1; $index < count($arguments); $index++) {
        $argument = (string) $arguments[$index];
        if (isset($valueOptions[$argument])) {
            if (isset($values[$valueOptions[$argument]]) || !isset($arguments[$index + 1])) {
                throw new InvalidArgumentException('Duplicate or missing option value');
            }
            $value = trim((string) $arguments[++$index]);
            if ($value === '' || str_starts_with($value, '--') || str_contains($value, "\0")) {
                throw new InvalidArgumentException('Option value is invalid');
            }
            $values[$valueOptions[$argument]] = $value;
            continue;
        }
        if (in_array($argument, $flags, true)) {
            if (isset($seenFlags[$argument])) throw new InvalidArgumentException('Duplicate confirmation');
            $seenFlags[$argument] = true;
            continue;
        }
        throw new InvalidArgumentException('Unknown server-local preparation option');
    }
    if (count($values) !== count($valueOptions) || count($seenFlags) !== count($flags)) {
        throw new InvalidArgumentException('All server-local preparation boundaries must be confirmed');
    }
    $values['output_directory'] = catalogConflictServerLocalValidateStagePath($values['output_directory']);
    $values['batch'] = catalogConflictRepairValidateBatch($values['batch']);
    return $values;
}

/** @return array<string,mixed> */
function catalogConflictServerLocalBackupReceipt(string $databasePath, string $uploadsPath): array
{
    $artifact = static function (string $path, string $format): array {
        $real = @realpath($path);
        $stat = @lstat($path);
        $size = @filesize($path);
        $hash = @hash_file('sha256', $path);
        $mtime = @filemtime($path);
        if ($real === false || $real !== $path || !is_array($stat) || is_link($path) || !is_file($path)
            || (int) ($stat['uid'] ?? -1) !== 0 || (int) ($stat['gid'] ?? -1) !== 0
            || (int) ($stat['nlink'] ?? 0) !== 1 || (((int) ($stat['mode'] ?? 0)) & 0777) !== 0600
            || !is_int($size) || $size < 1 || !is_string($hash) || !is_int($mtime)) {
            throw new RuntimeException('A current maintenance backup is not root-only');
        }
        return catalogConflictRepairValidateBackupArtifact([
            'path' => $real,
            'size_bytes' => $size,
            'sha256' => strtolower($hash),
            'format' => $format,
            'mtime_epoch' => $mtime,
        ], $format);
    };
    return catalogConflictRepairValidateBackupReceipt([
        'confirmed' => true,
        'confirmed_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'database' => $artifact($databasePath, 'database_gzip'),
        'public_uploads' => $artifact($uploadsPath, 'public_uploads_tar_gzip'),
    ]);
}

function catalogConflictServerLocalCreateStage(string $stage): void
{
    catalogConflictServerLocalValidateStagePath($stage);
    if (file_exists($stage) || is_link($stage) || !@mkdir($stage, 0700, false) || !@chmod($stage, 0700)) {
        throw new RuntimeException('Server-local stage creation was not exclusive');
    }
    $stat = @lstat($stage);
    if (!is_array($stat) || is_link($stage) || !is_dir($stage)
        || (int) ($stat['uid'] ?? -1) !== 0 || (int) ($stat['gid'] ?? -1) !== 0
        || (((int) ($stat['mode'] ?? 0)) & 0777) !== 0700) {
        throw new RuntimeException('Server-local stage is not root-only');
    }
}

/** @param list<string> $knownNames */
function catalogConflictServerLocalCleanupStage(string $stage, array $knownNames): bool
{
    try { catalogConflictServerLocalValidateStagePath($stage); } catch (Throwable) { return false; }
    if (is_link($stage)) return false;
    if (!file_exists($stage)) return true;
    $stat = @lstat($stage);
    if (!is_array($stat) || !is_dir($stage) || (int) ($stat['uid'] ?? -1) !== 0
        || (((int) ($stat['mode'] ?? 0)) & 0777) !== 0700) return false;
    $entries = array_values(array_diff(scandir($stage) ?: [], ['.', '..']));
    foreach ($entries as $name) {
        if (!in_array($name, $knownNames, true)) return false;
        $path = $stage . '/' . $name;
        $entryStat = @lstat($path);
        if (!is_array($entryStat) || is_link($path) || !is_file($path)
            || (int) ($entryStat['uid'] ?? -1) !== 0 || (int) ($entryStat['nlink'] ?? 0) !== 1
            || !@unlink($path)) return false;
    }
    return @rmdir($stage);
}
