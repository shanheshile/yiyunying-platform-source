<?php
declare(strict_types=1);

use Yiyunying\Core\Database;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool is CLI-only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once __DIR__ . '/catalog-public-upload-type.php';
require_once __DIR__ . '/catalog-public-quarantine-contract.php';
require $root . '/bootstrap.php';

$apply = in_array('--apply', $argv, true);
$maintenanceConfirmed = in_array('--maintenance-confirmed', $argv, true);
$releaseVersion = quarantineCliOption($argv, '--release-version');
if ($releaseVersion === null || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $releaseVersion) !== 1) {
    fwrite(STDERR, "A stable --release-version is required.\n");
    exit(1);
}
if ($apply && !$maintenanceConfirmed) {
    fwrite(STDERR, "Refusing apply without --maintenance-confirmed and a completed public/uploads backup.\n");
    exit(1);
}
$identity = quarantineReleaseIdentity($root, $releaseVersion);
$lock = Database::one("SELECT GET_LOCK('yiyunying_catalog_private_migration', 0) AS acquired");
if ((int) ($lock['acquired'] ?? 0) !== 1) {
    fwrite(STDERR, "Another catalog migration is already running.\n");
    exit(1);
}
register_shutdown_function(static function (): void {
    try { Database::one("SELECT RELEASE_LOCK('yiyunying_catalog_private_migration') AS released"); } catch (Throwable) {}
});

$batch = gmdate('Ymd-His') . 'Z-' . bin2hex(random_bytes(6));
$reportDirectory = $root . '/storage/private/catalog-migration-reports';
quarantineEnsurePrivateDirectory($reportDirectory);
$reportPath = $reportDirectory . '/catalog-public-quarantine-' . $batch . '.json';
$pdo = Database::connection();
$ownsTransaction = false;
$moved = [];
$journalPath = '';
$restorePath = '';
$databaseCommitted = false;

try {
    $plan = quarantineBuildPlan($root, false);
    $summary = [
        'schema' => 1,
        'mode' => $apply ? 'apply' : 'dry-run',
        'release_version' => $releaseVersion,
        'release_code' => $identity['version_code'],
        'release_identity_sha256' => $identity['sha256'],
        'batch' => $batch,
        'started_at_utc' => gmdate(DATE_ATOM),
        'scanned_files' => $plan['scanned_files'],
        'retained' => $plan['retained'],
        'would_quarantine' => count($plan['candidates']),
        'quarantined' => 0,
        'registered_disabled' => 0,
        'conflicts' => count($plan['conflicts']),
        'action_counts' => $plan['action_counts'],
        'reason_counts' => $plan['reason_counts'],
        'unsafe_type_counts' => $plan['unsafe_type_counts'],
        'reference_type_counts' => $plan['reference_type_counts'],
        'entries' => array_map(static fn(array $item): array => [
            'original_path_sha256' => hash('sha256', $item['relative']),
            'size_bytes' => $item['size'],
            'file_sha256' => $item['sha256'],
            'reason' => $item['decision']['reason'],
        ], $plan['candidates']),
        'conflict_entries' => array_map(static fn(array $item): array => [
            'original_path_sha256' => hash('sha256', $item['relative']),
            'size_bytes' => $item['size'],
            'file_sha256' => $item['sha256'],
            'reason' => $item['decision']['reason'],
            'reference_count' => $item['business_references'],
        ], $plan['conflicts']),
        'passed' => false,
    ];

    if ($plan['conflicts'] !== []) {
        if ($ownsTransaction) { $pdo->rollBack(); $ownsTransaction = false; }
        $summary['finished_at_utc'] = gmdate(DATE_ATOM);
        quarantineWriteAtomicPrivateJson($reportPath, $summary);
        quarantinePrintSummary($summary, $reportPath);
        fwrite(STDERR, "Quarantine conflicts detected; the entire batch was left unchanged.\n");
        exit(1);
    }

    if (!$apply) {
        $summary['passed'] = true;
        $summary['finished_at_utc'] = gmdate(DATE_ATOM);
        quarantineWriteAtomicPrivateJson($reportPath, $summary);
        quarantinePrintSummary($summary, $reportPath);
        exit($plan['candidates'] === [] ? 0 : 2);
    }

    $preflightDigest = quarantinePlanDigest($plan);
    $pdo->beginTransaction();
    $ownsTransaction = true;
    $lockedPlan = quarantineBuildPlan($root, true);
    if (!hash_equals($preflightDigest, quarantinePlanDigest($lockedPlan))) {
        throw new RuntimeException('Public upload inventory changed after quarantine preflight');
    }
    $plan = $lockedPlan;

    if ($plan['candidates'] !== []) {
        [$journalPath, $restorePath, $moved] = quarantineApplyBatch($root, $batch, $plan['candidates'], $releaseVersion, $identity);
        foreach ($plan['candidates'] as $candidate) {
            if ($candidate['decision']['action'] !== 'disable_and_quarantine') continue;
            foreach ($candidate['uploads'] as $upload) {
                $changed = Database::execute(
                    "UPDATE uploads SET status = 0, file_url = '', original_file_url = '',
                       optimized_file_url = '', thumbnail_url = ''
                     WHERE id = ? AND file_path = ? AND status = ? AND sha256 = ? AND size_bytes = ?
                       AND file_url <=> ? AND original_file_url <=> ?
                       AND optimized_file_url <=> ? AND thumbnail_url <=> ?",
                    [
                        (int) $upload['id'], (string) $upload['file_path'], (int) $upload['status'],
                        (string) $upload['sha256'], (int) $upload['size_bytes'],
                        (string) $upload['file_url'], (string) $upload['original_file_url'],
                        (string) $upload['optimized_file_url'], (string) $upload['thumbnail_url'],
                    ]
                );
                if ($changed !== 1) throw new RuntimeException('Registered upload changed during quarantine CAS');
                $summary['registered_disabled']++;
            }
        }
    }

    if ($journalPath !== '') quarantineMarkJournalState($journalPath, 'commit_ready');
    $pdo->commit();
    $ownsTransaction = false;
    $databaseCommitted = true;
    $summary['quarantined'] = count($moved);
    $summary['passed'] = true;
    $summary['journal_sha256'] = $journalPath === '' ? '' : strtolower((string) hash_file('sha256', $journalPath));
    $summary['restore_journal_sha256'] = $restorePath === '' ? '' : strtolower((string) hash_file('sha256', $restorePath));
    $summary['finished_at_utc'] = gmdate(DATE_ATOM);
    if ($journalPath !== '') quarantineMarkJournalState($journalPath, 'complete');
    quarantineWriteAtomicPrivateJson($reportPath, $summary);
    quarantinePrintSummary($summary, $reportPath);
    exit(0);
} catch (Throwable $exception) {
    if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
    $rollbackFailures = $databaseCommitted ? ['database_committed_manual_or_outer_rollback_required'] : quarantineRollbackMoves($moved);
    if (!$databaseCommitted && $journalPath !== '') {
        try { quarantineMarkJournalState($journalPath, 'rolled_back'); } catch (Throwable) {}
    }
    $failure = [
        'schema' => 1, 'mode' => $apply ? 'apply' : 'dry-run', 'release_version' => $releaseVersion,
        'release_code' => $identity['version_code'], 'release_identity_sha256' => $identity['sha256'],
        'batch' => $batch, 'passed' => false, 'error' => mb_substr($exception->getMessage(), 0, 500),
        'rollback_failures' => $rollbackFailures, 'recovery_required' => $rollbackFailures !== [],
        'finished_at_utc' => gmdate(DATE_ATOM),
    ];
    try { quarantineWriteAtomicPrivateJson($reportPath, $failure); } catch (Throwable) {}
    fwrite(STDERR, 'Catalog public quarantine failed; rollback_failures=' . count($rollbackFailures) . "\n");
    exit(1);
}

/** @return array{scanned_files:int,retained:int,candidates:array,conflicts:array,action_counts:array,reason_counts:array,unsafe_type_counts:array,reference_type_counts:array} */
function quarantineBuildPlan(string $root, bool $lockRows): array
{
    $uploadsRoot = $root . '/public/uploads';
    $resolvedRoot = realpath($uploadsRoot);
    if ($resolvedRoot === false || is_link($uploadsRoot) || !is_dir($uploadsRoot)) {
        throw new RuntimeException('public/uploads root is missing or unsafe');
    }
    $items = [];
    $scanned = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        if ($entry->isLink()) throw new RuntimeException('A symbolic link exists below public/uploads');
        if ($entry->isDir()) continue;
        if (!$entry->isFile()) throw new RuntimeException('A non-regular entry exists below public/uploads');
        $relativeInsideUploads = ltrim(str_replace('\\', '/', substr($path, strlen(rtrim($uploadsRoot, '/\\')))), '/');
        if ($relativeInsideUploads === '.gitkeep') continue;
        $relative = catalogPublicQuarantineCanonicalRelative('uploads/' . $relativeInsideUploads);
        if ($relative === null) throw new RuntimeException('A non-canonical public upload path was found');
        $fingerprint = catalogPublicQuarantineFingerprint($path, $uploadsRoot);
        $assessment = catalogMigrationAssessPublicUploadFile($path);
        $stat = @lstat($path);
        $managed = $assessment === 'safe' && is_array($stat)
            && quarantineTrustedManagedAvatar($relative, $path, $stat);
        $items[$relative] = [
            'relative' => $relative, 'absolute' => $path, 'size' => $fingerprint['size'],
            'sha256' => $fingerprint['sha256'], 'type_assessment' => $assessment,
            'managed_avatar' => $managed, 'uploads' => [], 'business_references' => 0,
            'path_references' => 0, 'upload_id_references' => 0,
            'reference_types' => [],
        ];
        $scanned++;
    }
    if ($items === []) return [
        'scanned_files' => 0, 'retained' => 0, 'candidates' => [], 'conflicts' => [],
        'action_counts' => [], 'reason_counts' => [], 'unsafe_type_counts' => [], 'reference_type_counts' => [],
    ];

    foreach (array_chunk(array_keys($items), 100) as $paths) {
        $marks = implode(',', array_fill(0, count($paths), '?'));
        $rows = Database::all(
            "SELECT id, file_path, status, sha256, size_bytes, file_url, original_file_url,
                    optimized_file_url, thumbnail_url
             FROM uploads WHERE file_path IN ({$marks}) ORDER BY id" . ($lockRows ? ' FOR UPDATE' : ''),
            $paths
        );
        foreach ($rows as $row) $items[(string) $row['file_path']]['uploads'][] = $row;
    }
    quarantineDiscoverBusinessReferences($items);

    $candidates = [];
    $conflicts = [];
    $retained = 0;
    $actionCounts = [];
    $reasonCounts = [];
    $unsafeTypes = [];
    $referenceTypes = [];
    foreach ($items as $relative => $item) {
        $decision = catalogPublicQuarantineDecision(
            $item['type_assessment'], $item['managed_avatar'], count($item['uploads']),
            $item['path_references'], $item['upload_id_references']
        );
        $item['decision'] = $decision;
        $actionCounts[$decision['action']] = ($actionCounts[$decision['action']] ?? 0) + 1;
        $reasonCounts[$decision['reason']] = ($reasonCounts[$decision['reason']] ?? 0) + 1;
        if ($item['type_assessment'] !== 'safe') {
            $unsafeTypes[$item['type_assessment']] = ($unsafeTypes[$item['type_assessment']] ?? 0) + 1;
        }
        foreach ($item['reference_types'] as $type => $count) {
            $referenceTypes[$type] = ($referenceTypes[$type] ?? 0) + $count;
        }
        if ($decision['action'] === 'retain') { $retained++; continue; }
        if ($decision['action'] === 'conflict') { $conflicts[] = $item; continue; }
        $candidates[] = $item;
    }
    ksort($actionCounts); ksort($reasonCounts); ksort($unsafeTypes); ksort($referenceTypes);
    return [
        'scanned_files' => $scanned, 'retained' => $retained, 'candidates' => $candidates,
        'conflicts' => $conflicts, 'action_counts' => $actionCounts, 'reason_counts' => $reasonCounts,
        'unsafe_type_counts' => $unsafeTypes, 'reference_type_counts' => $referenceTypes,
    ];
}

/** @param array<string,array> $items */
function quarantineDiscoverBusinessReferences(array &$items): void
{
    $excluded = ['uploads', 'catalog_file_migrations', 'upload_file_deletions', 'catalog_legacy_url_quarantines'];
    $columns = Database::all(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','json')
         ORDER BY TABLE_NAME, ORDINAL_POSITION"
    );
    $paths = array_keys($items);
    foreach ($columns as $column) {
        $table = (string) $column['TABLE_NAME'];
        $name = (string) $column['COLUMN_NAME'];
        if (in_array($table, $excluded, true)) continue;
        $tableQ = quarantineIdentifier($table);
        $columnQ = quarantineIdentifier($name);
        foreach (array_chunk($paths, 20) as $chunk) {
            $expressions = [];
            $params = [];
            foreach ($chunk as $index => $path) {
                $expressions[] = "SUM(CASE WHEN {$columnQ} LIKE ? ESCAPE '!' THEN 1 ELSE 0 END) AS c{$index}";
                $params[] = '%' . quarantineEscapeLike($path) . '%';
            }
            $row = Database::one('SELECT ' . implode(', ', $expressions) . " FROM {$tableQ}", $params) ?? [];
            foreach ($chunk as $index => $path) {
                $count = (int) ($row['c' . $index] ?? 0);
                if ($count <= 0) continue;
                $items[$path]['business_references'] += $count;
                $items[$path]['path_references'] += $count;
                $items[$path]['reference_types'][$table . '.' . $name] = $count;
            }
        }
    }

    $ids = [];
    foreach ($items as $path => $item) foreach ($item['uploads'] as $upload) $ids[(int) $upload['id']] = $path;
    if ($ids === []) return;
    $idColumns = Database::all(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND (COLUMN_NAME = 'upload_id' OR COLUMN_NAME LIKE '%\\_upload\\_id')
         ORDER BY TABLE_NAME, ORDINAL_POSITION"
    );
    foreach ($idColumns as $column) {
        $table = (string) $column['TABLE_NAME'];
        $name = (string) $column['COLUMN_NAME'];
        if (in_array($table, $excluded, true)) continue;
        foreach (array_chunk(array_keys($ids), 100) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $rows = Database::all(
                'SELECT ' . quarantineIdentifier($name) . ' AS upload_id, COUNT(*) AS total FROM '
                . quarantineIdentifier($table) . ' WHERE ' . quarantineIdentifier($name)
                . " IN ({$marks}) GROUP BY " . quarantineIdentifier($name),
                $chunk
            );
            foreach ($rows as $row) {
                $id = (int) $row['upload_id'];
                if (!isset($ids[$id])) continue;
                $path = $ids[$id];
                $count = (int) $row['total'];
                $items[$path]['business_references'] += $count;
                $items[$path]['upload_id_references'] += $count;
                $items[$path]['reference_types'][$table . '.' . $name] =
                    ($items[$path]['reference_types'][$table . '.' . $name] ?? 0) + $count;
            }
        }
    }
}

/** @return array{0:string,1:string,2:array} */
function quarantineApplyBatch(string $root, string $batch, array $candidates, string $releaseVersion, array $identity): array
{
    $uploadsRoot = $root . '/public/uploads';
    $quarantineDirectory = $root . '/storage/private/quarantine/catalog-public/' . $batch;
    quarantineEnsurePrivateDirectory($quarantineDirectory);
    $destinationStat = @lstat($quarantineDirectory);
    if (!is_array($destinationStat)) throw new RuntimeException('Unable to stat quarantine directory');
    $journalPath = $quarantineDirectory . '/journal.json';
    $restorePath = $quarantineDirectory . '/restore-map.root-only.json';
    $journalEntries = [];
    $restoreEntries = [];
    foreach ($candidates as $index => &$candidate) {
        catalogPublicQuarantineAssertFingerprint($candidate['absolute'], $uploadsRoot, $candidate);
        $sourceStat = @lstat($candidate['absolute']);
        if (!is_array($sourceStat) || (int) ($sourceStat['dev'] ?? -1) !== (int) ($destinationStat['dev'] ?? -2)) {
            throw new RuntimeException('Quarantine must use atomic same-filesystem rename');
        }
        $destinationName = sprintf('%04d-%s.quarantined', $index + 1, substr(hash('sha256', $candidate['relative']), 0, 24));
        $candidate['destination'] = $quarantineDirectory . '/' . $destinationName;
        $candidate['destination_relative'] = 'private/quarantine/catalog-public/' . $batch . '/' . $destinationName;
        if (file_exists($candidate['destination']) || is_link($candidate['destination'])) {
            throw new RuntimeException('Quarantine destination already exists');
        }
        $journalEntries[] = [
            'original_path_sha256' => hash('sha256', $candidate['relative']),
            'new_path' => $candidate['destination_relative'],
            'size_bytes' => $candidate['size'], 'file_sha256' => $candidate['sha256'],
            'reason' => $candidate['decision']['reason'],
        ];
        $restoreEntries[] = [
            'original_relative_path' => $candidate['relative'],
            'quarantine_relative_path' => $candidate['destination_relative'],
            'original_mode' => (int) ($sourceStat['mode'] ?? 0) & 0777,
            'size_bytes' => $candidate['size'], 'file_sha256' => $candidate['sha256'],
        ];
    }
    unset($candidate);
    quarantineWriteAtomicPrivateJson($journalPath, [
        'schema' => 1, 'state' => 'intent', 'release_version' => $releaseVersion,
        'release_code' => $identity['version_code'], 'release_identity_sha256' => $identity['sha256'],
        'batch' => $batch, 'created_at_utc' => gmdate(DATE_ATOM), 'entries' => $journalEntries,
    ]);
    quarantineWriteAtomicPrivateJson($restorePath, [
        'schema' => 1, 'classification' => 'root-only-recovery-map', 'batch' => $batch,
        'created_at_utc' => gmdate(DATE_ATOM), 'entries' => $restoreEntries,
    ]);
    $moved = [];
    try {
        foreach ($candidates as $candidate) {
            catalogPublicQuarantineAssertFingerprint($candidate['absolute'], $uploadsRoot, $candidate);
            if (!@rename($candidate['absolute'], $candidate['destination'])) {
                throw new RuntimeException('Atomic quarantine rename failed');
            }
            @chmod($candidate['destination'], 0600);
            $post = catalogPublicQuarantineFingerprint($candidate['destination'], $quarantineDirectory);
            if ($post['size'] !== $candidate['size'] || !hash_equals($candidate['sha256'], $post['sha256'])) {
                throw new RuntimeException('Quarantined bytes failed post-rename verification');
            }
            $moved[] = [
                'source' => $candidate['absolute'], 'destination' => $candidate['destination'],
                'mode' => $restoreEntries[count($moved)]['original_mode'],
            ];
        }
    } catch (Throwable $exception) {
        $failures = quarantineRollbackMoves($moved);
        if ($failures !== []) throw new RuntimeException('Quarantine failed and rollback requires manual recovery');
        throw $exception;
    }
    return [$journalPath, $restorePath, $moved];
}

/** @param array<int,array{source:string,destination:string,mode:int}> $moved @return array<int,string> */
function quarantineRollbackMoves(array $moved): array
{
    $failures = [];
    foreach (array_reverse($moved) as $entry) {
        if (!is_file($entry['destination']) || is_link($entry['destination'])
            || file_exists($entry['source']) || !@rename($entry['destination'], $entry['source'])) {
            $failures[] = hash('sha256', $entry['source']);
            continue;
        }
        @chmod($entry['source'], $entry['mode']);
    }
    return $failures;
}

function quarantineMarkJournalState(string $journalPath, string $state): void
{
    if (!in_array($state, ['commit_ready', 'complete', 'rolled_back'], true)) {
        throw new InvalidArgumentException('Invalid quarantine journal state');
    }
    $data = json_decode((string) file_get_contents($journalPath), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !in_array(($data['state'] ?? null), ['intent', 'commit_ready'], true)) {
        throw new RuntimeException('Invalid quarantine journal');
    }
    $data['state'] = $state;
    $data['state_changed_at_utc'] = gmdate(DATE_ATOM);
    quarantineWriteAtomicPrivateJson($journalPath, $data);
}

/** @param array<string,mixed> $plan */
function quarantinePlanDigest(array $plan): string
{
    $project = static function (array $item): array {
        $uploadIds = array_map(static fn(array $upload): int => (int) $upload['id'], $item['uploads'] ?? []);
        sort($uploadIds, SORT_NUMERIC);
        return [
            'relative' => (string) $item['relative'], 'size' => (int) $item['size'],
            'sha256' => (string) $item['sha256'], 'assessment' => (string) $item['type_assessment'],
            'action' => (string) ($item['decision']['action'] ?? ''),
            'reason' => (string) ($item['decision']['reason'] ?? ''),
            'path_references' => (int) ($item['path_references'] ?? 0),
            'upload_id_references' => (int) ($item['upload_id_references'] ?? 0),
            'upload_ids' => $uploadIds,
        ];
    };
    $items = array_merge($plan['candidates'] ?? [], $plan['conflicts'] ?? []);
    $projected = array_map($project, $items);
    usort($projected, static fn(array $left, array $right): int => strcmp($left['relative'], $right['relative']));
    return hash('sha256', json_encode($projected, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function quarantineTrustedManagedAvatar(string $relative, string $path, array $stat): bool
{
    if (preg_match(
        '#^uploads/avatars/(admin|platform|user|forum_plate|group|chat_room)/[1-9][0-9]*/[a-f0-9]{24}\.(jpg|png|gif|webp)$#D',
        $relative,
        $matches
    ) !== 1) return false;
    $size = (int) ($stat['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) return false;
    $image = @getimagesize($path);
    if (!is_array($image)) return false;
    $width = (int) ($image[0] ?? 0); $height = (int) ($image[1] ?? 0);
    if ($width <= 0 || $height <= 0 || $width > 8192 || $height > 8192 || $width * $height > 40000000) return false;
    $expected = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    return strtolower((string) ($image['mime'] ?? '')) === $expected[strtolower((string) $matches[2])];
}

function quarantineReleaseIdentity(string $root, string $version): array
{
    $path = $root . '/config/release-identity.json';
    if (!is_file($path) || is_link($path)) throw new RuntimeException('Missing trusted release identity');
    $bytes = file_get_contents($path);
    $identity = is_string($bytes) ? json_decode($bytes, true, 16, JSON_THROW_ON_ERROR) : null;
    if (!is_array($identity) || (string) ($identity['version_name'] ?? '') !== $version
        || !is_int($identity['version_code'] ?? null)) throw new RuntimeException('Release identity mismatch');
    return ['version_code' => $identity['version_code'], 'sha256' => strtolower(hash('sha256', (string) $bytes))];
}

function quarantineCliOption(array $arguments, string $name): ?string
{
    foreach ($arguments as $index => $argument) if ($argument === $name && isset($arguments[$index + 1])) return trim((string) $arguments[$index + 1]);
    return null;
}

function quarantineIdentifier(string $identifier): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) throw new RuntimeException('Unsafe database identifier');
    return '`' . $identifier . '`';
}

function quarantineEscapeLike(string $value): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
}

function quarantineEnsurePrivateDirectory(string $directory): void
{
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create private quarantine directory');
    }
    if (is_link($directory)) throw new RuntimeException('Private quarantine directory cannot be a link');
    @chmod($directory, 0700);
}

function quarantineWriteAtomicPrivateJson(string $path, array $data): void
{
    $directory = dirname($path);
    quarantineEnsurePrivateDirectory($directory);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    $temporary = $directory . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.partial';
    $handle = @fopen($temporary, 'x+b');
    if ($handle === false) throw new RuntimeException('Unable to create private journal');
    try {
        @chmod($temporary, 0600);
        if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)
            || (function_exists('fsync') && !fsync($handle))) throw new RuntimeException('Unable to write private journal');
    } finally { fclose($handle); }
    if (!@rename($temporary, $path)) {
        if (PHP_OS_FAMILY !== 'Windows' || !file_exists($path) || !@unlink($path) || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish private journal');
        }
    }
    @chmod($path, 0600);
}

function quarantinePrintSummary(array $summary, string $reportPath): void
{
    foreach (['scanned_files', 'retained', 'would_quarantine', 'quarantined', 'registered_disabled', 'conflicts'] as $key) {
        echo $key . '=' . (int) ($summary[$key] ?? 0) . PHP_EOL;
    }
    echo 'report=' . $reportPath . PHP_EOL;
}
