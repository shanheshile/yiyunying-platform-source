<?php
declare(strict_types=1);

use Yiyunying\Core\Database;

require_once __DIR__ . '/catalog-public-upload-type.php';
require_once __DIR__ . '/catalog-public-quarantine-contract.php';
require_once __DIR__ . '/catalog-public-conflict-repair-contract.php';

const CATALOG_CONFLICT_REPAIR_LOCK = 'yiyunying_catalog_private_migration';
const CATALOG_CONFLICT_REPAIR_EXCLUDED_TABLES = [
    'uploads', 'catalog_file_migrations', 'upload_file_deletions', 'catalog_legacy_url_quarantines',
];

if (!defined('YIYUNYING_CONFLICT_REPAIR_LIBRARY_ONLY')) {
    exit(catalogConflictRepairMain($argv));
}

function catalogConflictRepairMain(array $arguments): int
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "This maintenance tool is CLI-only.\n");
        return 1;
    }
    $apply = in_array('--apply', $arguments, true);
    $recover = in_array('--recover', $arguments, true);
    $maintenance = in_array('--maintenance-confirmed', $arguments, true);
    $backup = in_array('--backup-confirmed', $arguments, true);
    $planPath = catalogConflictRepairCliOption($arguments, '--plan');
    if ($planPath === null) {
        fwrite(STDERR, "Usage: php tools/repair-catalog-public-conflicts.php --plan <root-only.json> [--apply|--recover] --maintenance-confirmed --backup-confirmed\n");
        return 2;
    }
    if (($apply && $recover) || !$maintenance || !$backup) {
        fwrite(STDERR, "Every mode requires --maintenance-confirmed and --backup-confirmed; --apply and --recover are exclusive.\n");
        return 2;
    }

    $root = dirname(__DIR__);
    $publicRoot = $root . '/public';
    $uploadsRoot = $publicRoot . '/uploads';
    $reportPath = '';
    $planHash = '';
    $batch = 'unloaded';
    $lockHeld = false;
    try {
        require $root . '/bootstrap.php';
        $loaded = catalogConflictRepairLoadPrivatePlan($planPath, $publicRoot);
        $plan = $loaded['plan'];
        $planHash = $loaded['plan_sha256'];
        $batch = (string) $plan['batch'];
        if (!$recover) {
            // A first apply/dry-run is bound to the current maintenance
            // window. Recovery instead validates the exact journal-bound
            // backups without imposing a new four-hour age limit.
            catalogConflictRepairAssertBackupFresh($plan['backup']);
            catalogConflictRepairAssertBackupArtifacts($plan['backup'], $publicRoot, true);
        }

        $lock = Database::one('SELECT GET_LOCK(?, 0) AS acquired', [CATALOG_CONFLICT_REPAIR_LOCK]);
        if ((int) ($lock['acquired'] ?? 0) !== 1) throw new RuntimeException('Another catalog maintenance operation is active');
        $lockHeld = true;
        catalogConflictRepairAssertGateClosed();

        $paths = catalogConflictRepairDiscoverPaths(
            $uploadsRoot,
            array_map(static fn(array $item): string => $item['path_sha256'], $plan['items'])
        );
        $reportDirectory = $root . '/storage/private/catalog-conflict-repair-reports';
        $reportPath = $reportDirectory . '/repair-' . $batch . '-' . bin2hex(random_bytes(4)) . '.json';
        $unfinished = catalogConflictRepairFindUnfinishedJournals($root, $batch, $planHash, $publicRoot);
        if ($unfinished !== [] && !$recover) {
            throw new RuntimeException('An unfinished journal requires explicit recovery');
        }
        if (count($unfinished) > 1) throw new RuntimeException('Multiple unfinished journals require manual recovery');
        if ($recover) {
            if ($unfinished === []) {
                $verified = catalogConflictRepairInspect($plan, $paths, $uploadsRoot, false);
                if (!catalogConflictRepairRecoveryZeroWorkAllowed($verified)) {
                    throw new RuntimeException('Recovery without an unfinished journal requires both items already complete');
                }
                $recoveryResult = ['outcome' => 'zero_work', 'journal_sha256' => ''];
            } else {
                $recoveryResult = catalogConflictRepairRecover(
                    $root, $plan, $planHash, $paths, $unfinished[0], $uploadsRoot, $publicRoot
                );
            }
            $postRecovery = catalogConflictRepairInspect($plan, $paths, $uploadsRoot, false);
            $forward = $recoveryResult['outcome'] === 'completed_forward';
            $rolledBack = $recoveryResult['outcome'] === 'rolled_back';
            if (($forward && count($postRecovery['complete']) !== 2)
                || ($rolledBack && count($postRecovery['pending']) !== 2)
                || (!$forward && !$rolledBack && $recoveryResult['outcome'] !== 'zero_work')) {
                throw new RuntimeException('Recovery post-readback is not decisive');
            }
            $summary = catalogConflictRepairSummary('recover', $batch, $planHash, $postRecovery);
            $summary['passed'] = true;
            $summary['zero_work'] = $recoveryResult['outcome'] === 'zero_work';
            $summary['recovery_outcome'] = $recoveryResult['outcome'];
            $summary['journal_sha256'] = $recoveryResult['journal_sha256'];
            $summary['finished_at_utc'] = gmdate(DATE_ATOM);
            catalogConflictRepairWritePrivateJson($reportPath, $summary, $publicRoot);
            catalogConflictRepairPrintSummary($summary, $reportPath);
            return 0;
        }
        $preflight = catalogConflictRepairInspect($plan, $paths, $uploadsRoot, false);
        $summary = catalogConflictRepairSummary($apply ? 'apply' : 'dry-run', $batch, $planHash, $preflight);

        if ($preflight['conflicts'] !== []) {
            $summary['finished_at_utc'] = gmdate(DATE_ATOM);
            catalogConflictRepairWritePrivateJson($reportPath, $summary, $publicRoot);
            catalogConflictRepairPrintSummary($summary, $reportPath);
            fwrite(STDERR, "Conflict repair preflight failed; no file or database write was attempted.\n");
            return 1;
        }
        if (!$apply || $preflight['pending'] === []) {
            $summary['passed'] = true;
            $summary['zero_work'] = $preflight['pending'] === [];
            $summary['finished_at_utc'] = gmdate(DATE_ATOM);
            catalogConflictRepairWritePrivateJson($reportPath, $summary, $publicRoot);
            catalogConflictRepairPrintSummary($summary, $reportPath);
            return 0;
        }
        if (count($preflight['pending']) !== 2 || $preflight['complete'] !== []) {
            throw new RuntimeException('Apply requires both plan items in the exact preimage state');
        }

        foreach ($preflight['pending'] as $state) {
            catalogConflictRepairAssertPrivateReplacement($state['item']['replacement'], $publicRoot);
        }
        $result = catalogConflictRepairApply($root, $plan, $planHash, $paths, $preflight, $uploadsRoot, $publicRoot);
        $post = catalogConflictRepairInspect($plan, $paths, $uploadsRoot, false);
        if ($post['pending'] !== [] || $post['conflicts'] !== [] || count($post['complete']) !== 2) {
            throw new RuntimeException('Post-commit readback did not prove both repairs complete');
        }
        $summary = catalogConflictRepairSummary('apply', $batch, $planHash, $post);
        $summary['passed'] = true;
        $summary['zero_work'] = false;
        $summary['repaired'] = $result['repaired'];
        $summary['journal_sha256'] = $result['journal_sha256'];
        $summary['finished_at_utc'] = gmdate(DATE_ATOM);
        catalogConflictRepairWritePrivateJson($reportPath, $summary, $publicRoot);
        catalogConflictRepairPrintSummary($summary, $reportPath);
        return 0;
    } catch (Throwable $exception) {
        $failure = [
            'schema' => 1,
            'mode' => $recover ? 'recover' : ($apply ? 'apply' : 'dry-run'),
            'batch' => $batch,
            'plan_sha256' => $planHash,
            'passed' => false,
            'error_code' => catalogConflictRepairErrorCode($exception),
            'finished_at_utc' => gmdate(DATE_ATOM),
        ];
        if ($reportPath !== '') {
            try { catalogConflictRepairWritePrivateJson($reportPath, $failure, $publicRoot); } catch (Throwable) {}
        }
        fwrite(STDERR, 'Catalog conflict repair failed: ' . $failure['error_code'] . "\n");
        return 1;
    } finally {
        if ($lockHeld) {
            try { Database::one('SELECT RELEASE_LOCK(?) AS released', [CATALOG_CONFLICT_REPAIR_LOCK]); } catch (Throwable) {}
        }
    }
}

/** @param array{pending:list<array>,complete:list<array>,conflicts:list<array>} $inspection */
function catalogConflictRepairRecoveryZeroWorkAllowed(array $inspection): bool
{
    return count($inspection['complete'] ?? []) === 2
        && ($inspection['pending'] ?? null) === []
        && ($inspection['conflicts'] ?? null) === [];
}

function catalogConflictRepairAssertGateClosed(): void
{
    $row = Database::one(
        "SELECT COUNT(*) AS total FROM apps a
         LEFT JOIN app_settings s ON s.admin_id = a.admin_id AND s.app_id = a.id
          AND s.setting_key = 'catalog_private_migration_ready'
         WHERE a.deleted_at IS NULL
           AND (s.setting_value IS NULL OR LOWER(TRIM(s.setting_value)) NOT IN ('0','false','off','no'))"
    );
    if ((int) ($row['total'] ?? -1) !== 0) throw new RuntimeException('Catalog runtime gate is not closed');
}

/** @return array{pending:list<array>,complete:list<array>,conflicts:list<array>} */
function catalogConflictRepairInspect(array $plan, array $paths, string $uploadsRoot, bool $lockRows): array
{
    $result = ['pending' => [], 'complete' => [], 'conflicts' => []];
    foreach ($plan['items'] as $item) {
        $item['_batch'] = (string) $plan['batch'];
        $path = $paths[$item['path_sha256']] ?? null;
        if (!is_array($path)) throw new RuntimeException('Resolved repair path disappeared');
        $item['_relative'] = (string) $path['relative'];
        $state = catalogConflictRepairInspectItem($item, $path, $uploadsRoot, $lockRows);
        $result[$state['state']][] = $state;
    }
    return $result;
}

/** @return array<string,mixed> */
function catalogConflictRepairInspectItem(array $item, array $path, string $uploadsRoot, bool $lockRows): array
{
    if (strtolower(pathinfo((string) $path['relative'], PATHINFO_EXTENSION)) !== 'png') {
        return catalogConflictRepairState('conflicts', $item, $path, 'target_extension_changed');
    }
    $fingerprint = catalogConflictRepairFingerprint($path['absolute'], $uploadsRoot);
    $contentKind = catalogConflictRepairContentKind($path['absolute']);
    $database = catalogConflictRepairDatabaseState($path['relative'], $item, $lockRows);
    $preimage = catalogConflictRepairFingerprintMatches($fingerprint, $item['preimage']);
    $replacement = catalogConflictRepairFingerprintMatches($fingerprint, $item['replacement']);
    $expectedSource = $item['action'] === CATALOG_CONFLICT_REPAIR_ACTION_JPEG ? 'jpeg' : 'heic';

    if ($preimage && $contentKind === $expectedSource && catalogConflictRepairDatabaseMatchesPending($database, $item)) {
        return catalogConflictRepairState('pending', $item, $path, 'ready', $database, $fingerprint);
    }
    if ($replacement && $contentKind === 'png' && catalogConflictRepairDatabaseMatchesComplete($database, $item)) {
        try {
            catalogConflictRepairAssertPng($path['absolute'], $uploadsRoot, $item['replacement']);
            catalogConflictRepairAssertReferenceState($database, $item, true);
            return catalogConflictRepairState('complete', $item, $path, 'already_repaired', $database, $fingerprint);
        } catch (Throwable) {
            return catalogConflictRepairState('conflicts', $item, $path, 'postcondition_mismatch', $database, $fingerprint);
        }
    }
    return catalogConflictRepairState('conflicts', $item, $path, 'file_or_database_state_mismatch', $database, $fingerprint);
}

/** @return array<string,mixed> */
function catalogConflictRepairState(
    string $state,
    array $item,
    array $path,
    string $reason,
    array $database = [],
    array $fingerprint = []
): array {
    return [
        'state' => $state,
        'reason' => $reason,
        'item' => $item,
        'relative' => $path['relative'],
        'absolute' => $path['absolute'],
        'database' => $database,
        'fingerprint' => $fingerprint,
    ];
}

/** @return array<string,mixed> */
function catalogConflictRepairDatabaseState(string $relative, array $item, bool $lockRows): array
{
    $suffix = $lockRows ? ' FOR UPDATE' : '';
    $uploads = Database::all('SELECT * FROM uploads WHERE file_path = ? ORDER BY id' . $suffix, [$relative]);
    $uploadIds = array_map(static fn(array $row): int => (int) $row['id'], $uploads);
    $attachments = [];
    if ($uploadIds !== []) {
        $marks = implode(',', array_fill(0, count($uploadIds), '?'));
        $attachments = Database::all(
            "SELECT * FROM media_attachments WHERE upload_id IN ({$marks}) ORDER BY id" . $suffix,
            $uploadIds
        );
    }
    $references = catalogConflictRepairDiscoverReferences($relative, $uploadIds, $lockRows);
    return [
        'uploads' => $uploads,
        'attachments' => $attachments,
        'path_references' => $references['path_references'],
        'upload_id_references' => $references['upload_id_references'],
        'reference_tenants' => $references['tenants'],
    ];
}

/** @return array{path_references:int,upload_id_references:int,tenants:array<string,int>} */
function catalogConflictRepairDiscoverReferences(string $relative, array $uploadIds, bool $lockRows): array
{
    $columns = Database::all(
        "SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE
         FROM information_schema.COLUMNS c
         INNER JOIN information_schema.TABLES t
           ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
         WHERE c.TABLE_SCHEMA = DATABASE() ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION"
    );
    $tableColumns = [];
    foreach ($columns as $column) {
        $table = (string) $column['TABLE_NAME'];
        $name = (string) $column['COLUMN_NAME'];
        $tableColumns[$table][$name] = strtolower((string) $column['DATA_TYPE']);
    }
    $textTypes = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json'];
    $pathCount = 0;
    $uploadIdCount = 0;
    $tenants = [];
    foreach ($tableColumns as $table => $definitions) {
        if (in_array($table, CATALOG_CONFLICT_REPAIR_EXCLUDED_TABLES, true)) continue;
        $hasTenant = isset($definitions['admin_id'], $definitions['app_id']);
        foreach ($definitions as $column => $type) {
            if (!in_array($type, $textTypes, true)) continue;
            $rows = Database::all(
                'SELECT ' . ($hasTenant ? '`admin_id`, `app_id`' : '1 AS tenant_unknown')
                . ' FROM ' . catalogConflictRepairIdentifier($table)
                . ' WHERE ' . catalogConflictRepairIdentifier($column) . " LIKE ? ESCAPE '!'"
                . ($lockRows ? ' FOR UPDATE' : ''),
                ['%' . catalogConflictRepairEscapeLike($relative) . '%']
            );
            if ($rows !== [] && !$hasTenant) throw new RuntimeException('A path reference has no tenant columns');
            foreach ($rows as $row) {
                $pathCount++;
                catalogConflictRepairAddTenant($tenants, (int) $row['admin_id'], (int) $row['app_id']);
            }
        }
        if ($uploadIds === []) continue;
        foreach ($definitions as $column => $_type) {
            if ($column !== 'upload_id' && !str_ends_with($column, '_upload_id')) continue;
            $marks = implode(',', array_fill(0, count($uploadIds), '?'));
            $rows = Database::all(
                'SELECT ' . ($hasTenant ? '`admin_id`, `app_id`' : '1 AS tenant_unknown')
                . ' FROM ' . catalogConflictRepairIdentifier($table)
                . ' WHERE ' . catalogConflictRepairIdentifier($column) . " IN ({$marks})"
                . ($lockRows ? ' FOR UPDATE' : ''),
                $uploadIds
            );
            if ($rows !== [] && !$hasTenant) throw new RuntimeException('An upload reference has no tenant columns');
            foreach ($rows as $row) {
                $uploadIdCount++;
                catalogConflictRepairAddTenant($tenants, (int) $row['admin_id'], (int) $row['app_id']);
            }
        }
    }
    ksort($tenants, SORT_STRING);
    return ['path_references' => $pathCount, 'upload_id_references' => $uploadIdCount, 'tenants' => $tenants];
}

function catalogConflictRepairAddTenant(array &$tenants, int $adminId, int $appId): void
{
    if ($adminId < 1 || $appId < 1) throw new RuntimeException('A repair reference has an invalid tenant');
    $key = $adminId . ':' . $appId;
    $tenants[$key] = ($tenants[$key] ?? 0) + 1;
}

function catalogConflictRepairAssertReferenceState(array $database, array $item, bool $complete): void
{
    $expected = $item['expected'];
    if ((int) $database['path_references'] !== $expected['path_references']
        || (int) $database['upload_id_references'] !== $expected['upload_id_references']) {
        throw new RuntimeException('Repair reference counts changed');
    }
    $tenantKey = $expected['admin_id'] . ':' . $expected['app_id'];
    foreach ($database['reference_tenants'] as $key => $_count) {
        if ($key !== $tenantKey) throw new RuntimeException('Repair references cross a tenant boundary');
    }
    foreach (array_merge($database['uploads'], $database['attachments']) as $row) {
        if ((int) ($row['admin_id'] ?? 0) !== $expected['admin_id']
            || (int) ($row['app_id'] ?? 0) !== $expected['app_id']) {
            throw new RuntimeException('Repair database rows cross a tenant boundary');
        }
    }
    $expectedUploads = $complete && $item['action'] === CATALOG_CONFLICT_REPAIR_ACTION_JPEG
        ? 1 : $expected['upload_rows'];
    if (count($database['uploads']) !== $expectedUploads
        || count($database['attachments']) !== $expected['media_attachment_rows']) {
        throw new RuntimeException('Repair row counts changed');
    }
}

function catalogConflictRepairDatabaseMatchesPending(array $database, array $item): bool
{
    try { catalogConflictRepairAssertReferenceState($database, $item, false); } catch (Throwable) { return false; }
    if ($item['action'] === CATALOG_CONFLICT_REPAIR_ACTION_JPEG) return $database['uploads'] === [];
    foreach ($database['uploads'] as $upload) if ((int) ($upload['status'] ?? 0) !== 1) return false;
    foreach ($database['attachments'] as $attachment) {
        if (strtolower((string) ($attachment['media_type'] ?? '')) !== 'image') return false;
        try { catalogConflictRepairDecodeMetadata($attachment['metadata_json'] ?? null); } catch (Throwable) { return false; }
    }
    return $database['uploads'] !== [] && count($database['attachments']) === $item['expected']['media_attachment_rows'];
}

function catalogConflictRepairDatabaseMatchesComplete(array $database, array $item): bool
{
    try { catalogConflictRepairAssertReferenceState($database, $item, true); } catch (Throwable) { return false; }
    $replacement = $item['replacement'];
    foreach ($database['uploads'] as $upload) {
        if ((string) $upload['mime_type'] !== 'image/png'
            || (int) $upload['size_bytes'] !== $replacement['size_bytes']
            || (int) $upload['original_size_bytes'] !== $item['preimage']['size_bytes']
            || (int) $upload['optimized_size_bytes'] !== $replacement['size_bytes']
            || (string) $upload['upload_mode'] !== 'optimized'
            || (string) $upload['optimization_status'] !== 'converted_legacy'
            || (string) $upload['original_file_url'] !== ''
            || (string) $upload['optimized_file_url'] !== (string) $upload['file_url']
            || (string) $upload['thumbnail_url'] !== ''
            || (int) $upload['is_animated'] !== 0
            || !hash_equals($replacement['sha256'], strtolower((string) $upload['sha256']))
            || (int) $upload['status'] !== 1) return false;
    }
    if ($item['action'] === CATALOG_CONFLICT_REPAIR_ACTION_JPEG) {
        $upload = $database['uploads'][0] ?? null;
        $registration = $item['registration'];
        if (!is_array($upload) || (string) $upload['scene'] !== $registration['scene']
            || (string) $upload['original_name'] !== $registration['original_name']
            || (($upload['user_id'] === null ? null : (int) $upload['user_id']) !== $registration['user_id'])) return false;
    }
    foreach ($database['attachments'] as $attachment) {
        $metadata = json_decode((string) ($attachment['metadata_json'] ?? ''), true);
        if (strtolower((string) ($attachment['media_type'] ?? '')) !== 'image'
            || (string) $attachment['mime_type'] !== 'image/png'
            || (int) $attachment['size_bytes'] !== $replacement['size_bytes']
            || (int) $attachment['width'] !== $replacement['width']
            || (int) $attachment['height'] !== $replacement['height']
            || !is_array($metadata)
            || ($metadata['catalog_conflict_repair']['batch'] ?? null) !== ($item['_batch'] ?? null)
            || ($metadata['catalog_conflict_repair']['optimization_status'] ?? null) !== 'converted_legacy'
            || ($metadata['catalog_conflict_repair']['mime_type'] ?? null) !== 'image/png') return false;
        $uploadExists = false;
        foreach ($database['uploads'] as $candidate) {
            if ((int) $candidate['id'] === (int) $attachment['upload_id']) $uploadExists = true;
        }
        if (!$uploadExists || !str_contains((string) $attachment['url'], (string) ($item['_relative'] ?? ''))) return false;
    }
    return true;
}

/** @return list<array{path:string,data:array}> */
function catalogConflictRepairFindUnfinishedJournals(
    string $root,
    string $batch,
    string $planHash,
    string $publicRoot
): array {
    $directory = $root . '/storage/private/catalog-conflict-recovery/' . $batch;
    if (!file_exists($directory) && !is_link($directory)) return [];
    catalogConflictRepairEnsurePrivateDirectory($directory, $publicRoot);
    $journals = [];
    foreach (glob($directory . '/journal-*.json', GLOB_NOSORT) ?: [] as $path) {
        $data = catalogConflictRepairLoadJournal($path, $batch, null);
        if (!in_array((string) $data['state'], ['complete', 'rolled_back'], true)) {
            $data = catalogConflictRepairLoadJournal($path, $batch, $planHash);
            $journals[] = ['path' => $path, 'data' => $data];
        }
    }
    usort($journals, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
    return $journals;
}

function catalogConflictRepairLoadJournal(string $path, string $batch, ?string $planHash): array
{
    $stat = @lstat($path);
    $size = @filesize($path);
    if (!is_file($path) || is_link($path) || !is_array($stat) || (int) ($stat['nlink'] ?? 0) !== 1
        || (PHP_OS_FAMILY !== 'Windows' && ((((int) ($stat['mode'] ?? 0)) & 0777) !== 0600))
        || (function_exists('posix_geteuid') && (int) ($stat['uid'] ?? -1) !== posix_geteuid())
        || !is_int($size) || $size < 1 || $size > 1024 * 1024) {
        throw new RuntimeException('An unfinished repair journal is unsafe');
    }
    $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    $states = ['intent', 'replacing_files', 'files_replaced', 'commit_ready', 'db_committed', 'recovery_required', 'complete', 'rolled_back'];
    if (!is_array($data) || ($data['schema'] ?? null) !== 1 || ($data['batch'] ?? null) !== $batch
        || catalogConflictRepairHash($data['plan_sha256'] ?? null) === null
        || catalogConflictRepairHash($data['runtime_plan_sha256'] ?? null) === null
        || !hash_equals(strtolower((string) $data['plan_sha256']), strtolower((string) $data['runtime_plan_sha256']))
        || ($planHash !== null && !hash_equals($planHash, strtolower((string) $data['plan_sha256'])))
        || !in_array((string) ($data['state'] ?? ''), $states, true)
        || !is_array($data['backup'] ?? null)
        || !is_array($data['entries'] ?? null) || count($data['entries']) !== 2) {
        throw new RuntimeException('An unfinished repair journal failed its plan binding');
    }
    try {
        $data['backup'] = catalogConflictRepairValidateBackupReceipt($data['backup']);
    } catch (Throwable $exception) {
        throw new RuntimeException('An unfinished repair journal backup binding is invalid', 0, $exception);
    }
    $hashes = [];
    foreach ($data['entries'] as $entry) {
        $hash = catalogConflictRepairHash($entry['path_sha256'] ?? null);
        if ($hash === null || isset($hashes[$hash]) || catalogConflictRepairHash($entry['preimage_sha256'] ?? null) === null
            || catalogConflictRepairHash($entry['replacement_sha256'] ?? null) === null
            || catalogConflictRepairHash($entry['database_before_sha256'] ?? null) === null
            || (($entry['database_after_sha256'] ?? '') !== ''
                && catalogConflictRepairHash($entry['database_after_sha256'] ?? null) === null)
            || !is_int($entry['preimage_size_bytes'] ?? null) || !is_int($entry['replacement_size_bytes'] ?? null)
            || !in_array((int) ($entry['original_mode'] ?? -1), [0600, 0640, 0644], true)) {
            throw new RuntimeException('An unfinished repair journal entry is invalid');
        }
        $hashes[$hash] = true;
    }
    if (!is_array($data['replaced_path_hashes'] ?? null) || !array_is_list($data['replaced_path_hashes'])) {
        throw new RuntimeException('An unfinished repair journal replacement ledger is invalid');
    }
    $replaced = [];
    foreach ($data['replaced_path_hashes'] as $hash) {
        $normalized = catalogConflictRepairHash($hash);
        if ($normalized === null || !isset($hashes[$normalized]) || isset($replaced[$normalized])) {
            throw new RuntimeException('An unfinished repair journal replacement ledger is invalid');
        }
        $replaced[$normalized] = true;
    }
    return $data;
}

/** @param list<array{file:string,database:string}> $observations */
function catalogConflictRepairRecoveryDecision(array $observations): string
{
    if (count($observations) !== 2) return 'manual';
    $databaseStates = [];
    foreach ($observations as $observation) {
        if (!in_array($observation['file'] ?? '', ['old', 'new'], true)
            || !in_array($observation['database'] ?? '', ['old', 'new'], true)) return 'manual';
        $databaseStates[(string) $observation['database']] = true;
    }
    if (count($databaseStates) !== 1) return 'manual';
    return isset($databaseStates['new']) ? 'forward' : 'rollback';
}

/** @return array{outcome:string,journal_sha256:string} */
function catalogConflictRepairRecover(
    string $root,
    array $plan,
    string $planHash,
    array $paths,
    array $journalRecord,
    string $uploadsRoot,
    string $publicRoot
): array {
    $journal = $journalRecord['path'];
    $journalData = catalogConflictRepairLoadJournal($journal, (string) $plan['batch'], $planHash);
    $planBackupDigest = hash('sha256', json_encode(
        catalogConflictRepairCanonicalize($plan['backup']),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
    ));
    $journalBackupDigest = hash('sha256', json_encode(
        catalogConflictRepairCanonicalize($journalData['backup']),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
    ));
    if (!hash_equals($planBackupDigest, $journalBackupDigest)) {
        throw new RuntimeException('Recovery journal backup binding does not match the runtime plan');
    }
    catalogConflictRepairAssertBackupArtifacts($journalData['backup'], $publicRoot, false);
    $pdo = Database::connection();
    $pdo->beginTransaction();
    try {
    $journalEntries = [];
    foreach ($journalData['entries'] as $entry) $journalEntries[(string) $entry['path_sha256']] = $entry;
    $observations = [];
    $states = [];
    foreach ($plan['items'] as $item) {
        $path = $paths[$item['path_sha256']];
        $boundJournal = $journalEntries[$item['path_sha256']] ?? null;
        if (!is_array($boundJournal)
            || !hash_equals($item['preimage']['sha256'], (string) ($boundJournal['preimage_sha256'] ?? ''))
            || !hash_equals($item['replacement']['sha256'], (string) ($boundJournal['replacement_sha256'] ?? ''))
            || (int) ($boundJournal['preimage_size_bytes'] ?? -1) !== $item['preimage']['size_bytes']
            || (int) ($boundJournal['replacement_size_bytes'] ?? -1) !== $item['replacement']['size_bytes']) {
            throw new RuntimeException('Recovery journal media fingerprints do not match the plan');
        }
        $item['_batch'] = (string) $plan['batch'];
        $item['_relative'] = (string) $path['relative'];
        $fingerprint = catalogConflictRepairFingerprint($path['absolute'], $uploadsRoot);
        $fileState = catalogConflictRepairFingerprintMatches($fingerprint, $item['preimage']) ? 'old'
            : (catalogConflictRepairFingerprintMatches($fingerprint, $item['replacement']) ? 'new' : 'other');
        if ($fileState === 'new') catalogConflictRepairAssertPng($path['absolute'], $uploadsRoot, $item['replacement']);
        $database = catalogConflictRepairDatabaseState($path['relative'], $item, true);
        $databaseDigest = catalogConflictRepairDatabaseDigest($database);
        $databaseState = catalogConflictRepairDatabaseMatchesPending($database, $item)
                && hash_equals((string) $boundJournal['database_before_sha256'], $databaseDigest)
            ? 'old'
            : (catalogConflictRepairDatabaseMatchesComplete($database, $item)
                && catalogConflictRepairHash($boundJournal['database_after_sha256'] ?? null) !== null
                && hash_equals((string) $boundJournal['database_after_sha256'], $databaseDigest)
                ? 'new' : 'other');
        $observations[] = ['file' => $fileState, 'database' => $databaseState];
        $states[$item['path_sha256']] = [
            'item' => $item,
            'path' => $path,
            'file_state' => $fileState,
            'database_state' => $databaseState,
            'journal' => $boundJournal,
        ];
    }
    $decision = catalogConflictRepairRecoveryDecision($observations);
    if ($decision === 'manual') {
        try { catalogConflictRepairRewriteJournal($journal, 'recovery_required', $publicRoot, ['recovery_decision' => 'manual']); } catch (Throwable) {}
        throw new RuntimeException('Journal recovery cannot prove one atomic database outcome');
    }
    catalogConflictRepairRewriteJournal($journal, 'recovery_required', $publicRoot, ['recovery_decision' => $decision]);
    $processed = 0;
    foreach ($states as $pathHash => $state) {
        $journalEntry = $state['journal'];
        if (!is_array($journalEntry)) throw new RuntimeException('Recovery journal is missing a path hash');
        $mode = (int) $journalEntry['original_mode'];
        if ($decision === 'forward' && $state['file_state'] === 'old') {
            catalogConflictRepairAssertPrivateReplacement($state['item']['replacement'], $publicRoot);
            catalogConflictRepairAtomicRestore(
                $state['item']['replacement']['path'],
                $state['path']['absolute'],
                $state['item']['replacement'],
                $uploadsRoot,
                $mode,
                true
            );
        } elseif ($decision === 'rollback' && $state['file_state'] === 'new') {
            $recoveryFile = dirname($journal) . '/' . $pathHash . '.preimage';
            catalogConflictRepairAtomicRestore(
                $recoveryFile,
                $state['path']['absolute'],
                $state['item']['preimage'],
                $uploadsRoot,
                $mode,
                false
            );
        }
        $processed++;
        catalogConflictRepairFault($processed === 1 ? 'recover_after_first_file' : 'recover_after_file');
    }
    $readback = catalogConflictRepairInspect($plan, $paths, $uploadsRoot, true);
    if ($decision === 'forward') {
        if (count($readback['complete']) !== 2 || $readback['pending'] !== [] || $readback['conflicts'] !== []) {
            throw new RuntimeException('Forward recovery readback failed');
        }
        $terminalState = 'complete';
        $outcome = 'completed_forward';
    } else {
        if (count($readback['pending']) !== 2 || $readback['complete'] !== [] || $readback['conflicts'] !== []) {
            throw new RuntimeException('Rollback recovery readback failed');
        }
        $terminalState = 'rolled_back';
        $outcome = 'rolled_back';
    }
    $pdo->commit();
    catalogConflictRepairRewriteJournal($journal, $terminalState, $publicRoot, ['recovery_decision' => $decision]);
    $hash = @hash_file('sha256', $journal);
    if (!is_string($hash)) throw new RuntimeException('Recovered journal hash readback failed');
    return ['outcome' => $outcome, 'journal_sha256' => strtolower($hash)];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable) {}
        }
        throw $exception;
    }
}

function catalogConflictRepairAtomicRestore(
    string $privateSource,
    string $publicDestination,
    array $expected,
    string $uploadsRoot,
    int $mode,
    bool $png
): void {
    $stage = dirname($publicDestination) . '/.catalog-recovery-' . bin2hex(random_bytes(6)) . '.partial';
    catalogConflictRepairCopyVerified($privateSource, $stage, $expected, dirname($publicDestination), $mode, false);
    try {
        if ($png) catalogConflictRepairAssertPng($stage, dirname($publicDestination), $expected);
        if (!@rename($stage, $publicDestination)) throw new RuntimeException('Atomic recovery replacement failed');
        @chmod($publicDestination, $mode);
        $actual = catalogConflictRepairFingerprint($publicDestination, $uploadsRoot);
        if (!catalogConflictRepairFingerprintMatches($actual, $expected)) {
            throw new RuntimeException('Atomic recovery fingerprint readback failed');
        }
        if ($png) catalogConflictRepairAssertPng($publicDestination, $uploadsRoot, $expected);
    } finally {
        if (is_file($stage) && !is_link($stage)) @unlink($stage);
    }
}

/** @return array{repaired:int,journal_sha256:string} */
function catalogConflictRepairApply(
    string $root,
    array $plan,
    string $planHash,
    array $paths,
    array $preflight,
    string $uploadsRoot,
    string $publicRoot
): array {
    $batch = (string) $plan['batch'];
    $recovery = $root . '/storage/private/catalog-conflict-recovery/' . $batch;
    catalogConflictRepairEnsurePrivateDirectory($recovery, $publicRoot);
    $attempt = gmdate('Ymd-His') . 'Z-' . bin2hex(random_bytes(4));
    $journal = $recovery . '/journal-' . $attempt . '.json';
    $prepared = [];
    try {
    foreach ($preflight['pending'] as $state) {
        $item = $state['item'];
        $pathHash = $item['path_sha256'];
        $source = $state['absolute'];
        $sourceFingerprint = catalogConflictRepairFingerprint($source, $uploadsRoot);
        if (!catalogConflictRepairFingerprintMatches($sourceFingerprint, $item['preimage'])) {
            throw new RuntimeException('Repair source changed after preflight');
        }
        if (!in_array($sourceFingerprint['mode'], [0600, 0640, 0644], true)) {
            throw new RuntimeException('Repair source permissions are outside the reviewed boundary');
        }
        $replacementFingerprint = catalogConflictRepairAssertPrivateReplacement($item['replacement'], $publicRoot);
        $recoveryFile = $recovery . '/' . $pathHash . '.preimage';
        catalogConflictRepairCopyVerified(
            $source,
            $recoveryFile,
            $item['preimage'],
            $recovery,
            0600,
            true
        );
        $stage = dirname($source) . '/.catalog-repair-' . substr($pathHash, 0, 20) . '-' . bin2hex(random_bytes(4)) . '.partial.png';
        catalogConflictRepairCopyVerified(
            $item['replacement']['path'],
            $stage,
            $item['replacement'],
            dirname($source),
            $sourceFingerprint['mode'],
            false
        );
        catalogConflictRepairAssertPng($stage, dirname($source), $item['replacement']);
        $sourceStat = @lstat($source);
        $stageStat = @lstat($stage);
        $recoveryStat = @lstat($recovery);
        if (!is_array($sourceStat) || !is_array($stageStat) || !is_array($recoveryStat)
            || (int) ($sourceStat['dev'] ?? -1) !== (int) ($stageStat['dev'] ?? -2)
            || (int) ($sourceStat['dev'] ?? -1) !== (int) ($recoveryStat['dev'] ?? -3)) {
            throw new RuntimeException('Repair stage and recovery must be on the source filesystem');
        }
        $prepared[$pathHash] = [
            'source' => $source,
            'relative' => $state['relative'],
            'stage' => $stage,
            'recovery' => $recoveryFile,
            'mode' => $sourceFingerprint['mode'],
            'item' => $item,
            'database_before_sha256' => catalogConflictRepairDatabaseDigest($state['database']),
            'replacement_fingerprint' => $replacementFingerprint,
        ];
    }
    } catch (Throwable $exception) {
        foreach ($prepared as $entry) if (is_file($entry['stage']) && !is_link($entry['stage'])) @unlink($entry['stage']);
        throw $exception;
    }
    catalogConflictRepairWritePrivateJson($journal, [
        'schema' => 1,
        'state' => 'intent',
        'batch' => $batch,
        'attempt' => $attempt,
        'plan_sha256' => $planHash,
        'runtime_plan_sha256' => $planHash,
        'backup' => $plan['backup'],
        'replaced_path_hashes' => [],
        'created_at_utc' => gmdate(DATE_ATOM),
        'entries' => array_map(static fn(array $entry): array => [
            'path_sha256' => $entry['item']['path_sha256'],
            'preimage_sha256' => $entry['item']['preimage']['sha256'],
            'preimage_size_bytes' => $entry['item']['preimage']['size_bytes'],
            'replacement_sha256' => $entry['item']['replacement']['sha256'],
            'replacement_size_bytes' => $entry['item']['replacement']['size_bytes'],
            'original_mode' => $entry['mode'],
            'database_before_sha256' => $entry['database_before_sha256'],
            'database_after_sha256' => '',
        ], array_values($prepared)),
    ], $publicRoot);
    catalogConflictRepairFault('after_intent');

    $pdo = Database::connection();
    $replaced = [];
    $commitStarted = false;
    $commitConfirmed = false;
    try {
        $pdo->beginTransaction();
        $locked = catalogConflictRepairInspect($plan, $paths, $uploadsRoot, true);
        if (catalogConflictRepairStateDigest($locked) !== catalogConflictRepairStateDigest($preflight)) {
            throw new RuntimeException('Repair inventory changed after locked readback');
        }
        foreach ($locked['pending'] as $state) {
            $entry = $prepared[$state['item']['path_sha256']];
            if (!@rename($entry['stage'], $entry['source'])) throw new RuntimeException('Atomic repair replacement failed');
            @chmod($entry['source'], $entry['mode']);
            catalogConflictRepairAssertPng($entry['source'], $uploadsRoot, $entry['item']['replacement']);
            $replaced[] = $entry;
            catalogConflictRepairRewriteJournal(
                $journal,
                'replacing_files',
                $publicRoot,
                ['replaced_path_hashes' => array_map(static fn(array $value): string => $value['item']['path_sha256'], $replaced)]
            );
            catalogConflictRepairFault(count($replaced) === 1 ? 'after_first_file' : 'after_file');
            catalogConflictRepairApplyDatabase($entry, $state['database'], $batch);
        }
        catalogConflictRepairRewriteJournal($journal, 'files_replaced', $publicRoot);
        catalogConflictRepairFault('after_files_replaced');
        $readback = catalogConflictRepairInspect($plan, $paths, $uploadsRoot, true);
        if ($readback['pending'] !== [] || $readback['conflicts'] !== [] || count($readback['complete']) !== 2) {
            throw new RuntimeException('Locked repair readback failed');
        }
        $afterDigests = [];
        foreach ($readback['complete'] as $state) {
            $afterDigests[$state['item']['path_sha256']] = catalogConflictRepairDatabaseDigest($state['database']);
        }
        catalogConflictRepairRewriteJournal(
            $journal,
            'commit_ready',
            $publicRoot,
            ['database_after_sha256_by_path' => $afterDigests]
        );
        catalogConflictRepairFault('before_commit');
        $commitStarted = true;
        $pdo->commit();
        catalogConflictRepairFault('commit_response_unknown');
        $commitConfirmed = true;
        catalogConflictRepairFault('after_commit');
        catalogConflictRepairRewriteJournal($journal, 'db_committed', $publicRoot);
        catalogConflictRepairFault('after_db_committed');
        $postCommit = catalogConflictRepairInspect($plan, $paths, $uploadsRoot, false);
        if (count($postCommit['complete']) !== 2 || $postCommit['pending'] !== [] || $postCommit['conflicts'] !== []) {
            throw new RuntimeException('Post-commit database and file state is incomplete');
        }
        foreach ($postCommit['complete'] as $state) {
            $pathHash = $state['item']['path_sha256'];
            if (!isset($afterDigests[$pathHash])
                || !hash_equals($afterDigests[$pathHash], catalogConflictRepairDatabaseDigest($state['database']))) {
                throw new RuntimeException('Post-commit database after-image changed');
            }
        }
        catalogConflictRepairRewriteJournal($journal, 'complete', $publicRoot);
    } catch (Throwable $exception) {
        $rollbackConfirmed = false;
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); $rollbackConfirmed = true; } catch (Throwable) {}
        }
        if ($commitStarted && !$commitConfirmed && !$rollbackConfirmed) {
            try { catalogConflictRepairRewriteJournal($journal, 'recovery_required', $publicRoot); } catch (Throwable) {}
            throw new RuntimeException('Database commit response is uncertain; explicit recovery is required', 0, $exception);
        }
        if (!$commitConfirmed) {
            $failures = catalogConflictRepairRollbackFiles($replaced, $uploadsRoot);
            try { catalogConflictRepairRewriteJournal($journal, $failures === [] ? 'rolled_back' : 'recovery_required', $publicRoot); } catch (Throwable) {}
            if ($failures !== []) throw new RuntimeException('Repair rollback requires manual recovery');
        }
        throw $exception;
    } finally {
        foreach ($prepared as $entry) if (is_file($entry['stage']) && !is_link($entry['stage'])) @unlink($entry['stage']);
    }
    $journalHash = @hash_file('sha256', $journal);
    if (!is_string($journalHash)) throw new RuntimeException('Repair journal hash readback failed');
    return ['repaired' => count($replaced), 'journal_sha256' => strtolower($journalHash)];
}

function catalogConflictRepairApplyDatabase(array $entry, array $database, string $batch): void
{
    $item = $entry['item'];
    $replacement = $item['replacement'];
    $relative = $entry['relative'];
    if ($item['action'] === CATALOG_CONFLICT_REPAIR_ACTION_JPEG) {
        $registration = $item['registration'];
        $baseUrl = rtrim((string) config('app.url'), '/');
        if (!str_starts_with(strtolower($baseUrl), 'https://')) throw new RuntimeException('Public application URL is not HTTPS');
        $fileUrl = $baseUrl . '/' . $relative;
        Database::insert(
            "INSERT INTO uploads
             (admin_id, app_id, user_id, scene, original_name, stored_name, file_path, file_url,
              mime_type, size_bytes, original_size_bytes, optimized_size_bytes, upload_mode,
              optimization_status, original_file_url, optimized_file_url, thumbnail_url, is_animated,
              sha256, status, created_at)
             SELECT ?, ?, ?, ?, ?, ?, ?, ?, 'image/png', ?, ?, ?, 'optimized',
                    'converted_legacy', '', ?, '', 0, ?, 1, NOW()
             FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM uploads WHERE file_path = ?)",
            [
                $item['expected']['admin_id'], $item['expected']['app_id'], $registration['user_id'],
                $registration['scene'], $registration['original_name'], basename($relative), $relative, $fileUrl,
                $replacement['size_bytes'], $item['preimage']['size_bytes'], $replacement['size_bytes'],
                $fileUrl, $replacement['sha256'], $relative,
            ]
        );
        if (count(Database::all('SELECT id FROM uploads WHERE file_path = ? FOR UPDATE', [$relative])) !== 1) {
            throw new RuntimeException('JPEG upload registration was not unique');
        }
        return;
    }

    foreach ($database['uploads'] as $upload) {
        $fileUrl = (string) $upload['file_url'];
        if ($fileUrl === '' || !str_contains($fileUrl, $relative)) throw new RuntimeException('HEIC upload URL is not bound to its path');
        $changed = Database::execute(
            "UPDATE uploads SET mime_type = 'image/png', size_bytes = ?, original_size_bytes = ?,
                    optimized_size_bytes = ?, upload_mode = 'optimized', optimization_status = 'converted_legacy',
                    original_file_url = '', optimized_file_url = ?, thumbnail_url = '', is_animated = 0,
                    sha256 = ?, status = 1
             WHERE id = ? AND admin_id = ? AND app_id = ? AND file_path = ?
               AND mime_type <=> ? AND size_bytes = ? AND original_size_bytes = ? AND optimized_size_bytes = ?
               AND upload_mode <=> ? AND optimization_status <=> ? AND original_file_url <=> ?
               AND optimized_file_url <=> ? AND thumbnail_url <=> ? AND is_animated = ?
               AND sha256 <=> ? AND status = ?",
            [
                $replacement['size_bytes'], $item['preimage']['size_bytes'], $replacement['size_bytes'],
                $fileUrl, $replacement['sha256'], (int) $upload['id'], $item['expected']['admin_id'],
                $item['expected']['app_id'], $relative, (string) $upload['mime_type'], (int) $upload['size_bytes'],
                (int) $upload['original_size_bytes'], (int) $upload['optimized_size_bytes'],
                (string) $upload['upload_mode'], (string) $upload['optimization_status'],
                (string) $upload['original_file_url'], (string) $upload['optimized_file_url'],
                (string) $upload['thumbnail_url'], (int) $upload['is_animated'], (string) $upload['sha256'],
                (int) $upload['status'],
            ]
        );
        if ($changed !== 1) throw new RuntimeException('HEIC upload CAS did not update exactly one row');
    }
    foreach ($database['attachments'] as $attachment) {
        $upload = null;
        foreach ($database['uploads'] as $candidate) if ((int) $candidate['id'] === (int) $attachment['upload_id']) $upload = $candidate;
        if (!is_array($upload)) throw new RuntimeException('Media attachment upload binding changed');
        $metadata = catalogConflictRepairDecodeMetadata($attachment['metadata_json'] ?? null);
        $metadata['catalog_conflict_repair'] = [
            'batch' => $batch,
            'optimization_status' => 'converted_legacy',
            'mime_type' => 'image/png',
        ];
        $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $changed = Database::execute(
            "UPDATE media_attachments
             SET mime_type = 'image/png', size_bytes = ?, width = ?, height = ?, metadata_json = ?
             WHERE id = ? AND admin_id = ? AND app_id = ? AND upload_id = ?
               AND url <=> ? AND thumbnail_url <=> ? AND mime_type <=> ? AND size_bytes = ?
               AND width = ? AND height = ? AND metadata_json <=> ?",
            [
                $replacement['size_bytes'], $replacement['width'], $replacement['height'], $metadataJson,
                (int) $attachment['id'], $item['expected']['admin_id'], $item['expected']['app_id'],
                (int) $attachment['upload_id'], (string) $attachment['url'], (string) $attachment['thumbnail_url'],
                (string) $attachment['mime_type'], (int) $attachment['size_bytes'], (int) $attachment['width'],
                (int) $attachment['height'], $attachment['metadata_json'],
            ]
        );
        if ($changed !== 1) throw new RuntimeException('Media attachment CAS did not update exactly one row');
    }
}

function catalogConflictRepairDecodeMetadata(mixed $raw): array
{
    if ($raw === null || (is_string($raw) && trim($raw) === '')) return [];
    if (!is_string($raw) || !str_starts_with(ltrim($raw), '{')) {
        throw new RuntimeException('Non-empty media metadata must be a JSON object');
    }
    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        throw new RuntimeException('Non-empty media metadata is invalid JSON', 0, $exception);
    }
    if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
        throw new RuntimeException('Non-empty media metadata must be a JSON object');
    }
    return $decoded;
}

function catalogConflictRepairCopyVerified(
    string $source,
    string $destination,
    array $expected,
    string $allowedRoot,
    int $mode,
    bool $reuseExact
): void {
    if (file_exists($destination) || is_link($destination)) {
        if (!$reuseExact) throw new RuntimeException('Repair staging destination already exists');
        $existing = catalogConflictRepairFingerprint($destination, $allowedRoot);
        if (!catalogConflictRepairFingerprintMatches($existing, $expected)) {
            throw new RuntimeException('Existing recovery copy does not match the preimage');
        }
        return;
    }
    $temporary = $destination . '.' . bin2hex(random_bytes(4)) . '.partial';
    $input = @fopen($source, 'rb');
    $output = @fopen($temporary, 'x+b');
    if ($input === false || $output === false) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        @unlink($temporary);
        throw new RuntimeException('Unable to create a verified repair copy');
    }
    try {
        @chmod($temporary, $mode);
        $copied = stream_copy_to_stream($input, $output);
        if (!is_int($copied) || $copied !== $expected['size_bytes'] || !fflush($output)
            || (function_exists('fsync') && !fsync($output))) throw new RuntimeException('Repair copy was incomplete');
    } finally {
        fclose($input);
        fclose($output);
    }
    if (!@rename($temporary, $destination)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to publish a verified repair copy');
    }
    @chmod($destination, $mode);
    $actual = catalogConflictRepairFingerprint($destination, $allowedRoot);
    if (!catalogConflictRepairFingerprintMatches($actual, $expected)) throw new RuntimeException('Repair copy hash readback failed');
}

/** @return list<string> */
function catalogConflictRepairRollbackFiles(array $replaced, string $uploadsRoot): array
{
    $failures = [];
    foreach (array_reverse($replaced) as $entry) {
        try {
            $rollback = dirname($entry['source']) . '/.catalog-repair-rollback-' . bin2hex(random_bytes(5)) . '.partial';
            catalogConflictRepairCopyVerified(
                $entry['recovery'], $rollback, $entry['item']['preimage'], dirname($entry['source']), $entry['mode'], false
            );
            if (!@rename($rollback, $entry['source'])) throw new RuntimeException('Atomic rollback replacement failed');
            @chmod($entry['source'], $entry['mode']);
            $actual = catalogConflictRepairFingerprint($entry['source'], $uploadsRoot);
            if (!catalogConflictRepairFingerprintMatches($actual, $entry['item']['preimage'])) {
                throw new RuntimeException('Rollback hash readback failed');
            }
        } catch (Throwable) {
            $failures[] = $entry['item']['path_sha256'];
        }
    }
    return $failures;
}

function catalogConflictRepairRewriteJournal(
    string $path,
    string $state,
    string $publicRoot,
    array $changes = []
): void
{
    $allowed = [
        'intent' => ['replacing_files', 'files_replaced', 'rolled_back', 'recovery_required'],
        'replacing_files' => ['replacing_files', 'files_replaced', 'rolled_back', 'recovery_required'],
        'files_replaced' => ['commit_ready', 'rolled_back', 'recovery_required'],
        'commit_ready' => ['db_committed', 'complete', 'rolled_back', 'recovery_required'],
        'db_committed' => ['complete', 'recovery_required'],
        'recovery_required' => ['complete', 'rolled_back', 'recovery_required'],
    ];
    $data = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    $current = is_array($data) ? (string) ($data['state'] ?? '') : '';
    if (!is_array($data) || !in_array($state, $allowed[$current] ?? [], true)) {
        throw new RuntimeException('Repair journal transition is invalid');
    }
    foreach ($changes as $key => $value) {
        if ($key === 'database_after_sha256_by_path') {
            if (!is_array($value) || count($value) !== 2) {
                throw new InvalidArgumentException('Repair journal database-after map is invalid');
            }
            foreach ($data['entries'] as &$entry) {
                $pathHash = (string) ($entry['path_sha256'] ?? '');
                $afterHash = catalogConflictRepairHash($value[$pathHash] ?? null);
                if ($afterHash === null) throw new InvalidArgumentException('Repair journal database-after hash is invalid');
                $entry['database_after_sha256'] = $afterHash;
                unset($value[$pathHash]);
            }
            unset($entry);
            if ($value !== []) throw new InvalidArgumentException('Repair journal database-after map has unknown paths');
            continue;
        }
        if (!in_array($key, ['replaced_path_hashes', 'recovery_decision'], true)) {
            throw new InvalidArgumentException('Repair journal change field is invalid');
        }
        $data[$key] = $value;
    }
    $data['state'] = $state;
    $data['state_changed_at_utc'] = gmdate(DATE_ATOM);
    $temporary = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.partial';
    try {
        catalogConflictRepairWritePrivateJson($temporary, $data, $publicRoot);
        catalogConflictRepairFault('journal_before_publish');
        if (!@rename($temporary, $path)) throw new RuntimeException('Repair journal state publication failed');
    } catch (Throwable $exception) {
        @unlink($temporary);
        throw $exception;
    }
    @chmod($path, 0600);
}

function catalogConflictRepairFault(string $checkpoint): void
{
    if (getenv('YIYUNYING_CONFLICT_REPAIR_TESTING') !== '1') return;
    if (getenv('YIYUNYING_CONFLICT_REPAIR_FAULT') === $checkpoint) {
        throw new RuntimeException('Injected repair fault at ' . $checkpoint);
    }
}

function catalogConflictRepairDatabaseDigest(array $database): string
{
    $projection = [
        'uploads' => $database['uploads'] ?? [],
        'attachments' => $database['attachments'] ?? [],
        'path_references' => (int) ($database['path_references'] ?? -1),
        'upload_id_references' => (int) ($database['upload_id_references'] ?? -1),
        'reference_tenants' => $database['reference_tenants'] ?? [],
    ];
    return hash('sha256', json_encode(
        catalogConflictRepairCanonicalize($projection),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ));
}

function catalogConflictRepairCanonicalize(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = catalogConflictRepairCanonicalize($item);
    return $value;
}

function catalogConflictRepairStateDigest(array $inspection): string
{
    $projection = [];
    foreach (['pending', 'complete', 'conflicts'] as $state) {
        foreach ($inspection[$state] as $entry) {
            $projection[] = [
                'state' => $state,
                'path_sha256' => $entry['item']['path_sha256'],
                'file_sha256' => $entry['fingerprint']['sha256'] ?? '',
                'file_size' => $entry['fingerprint']['size_bytes'] ?? -1,
                'uploads' => array_map(static fn(array $row): string => hash(
                    'sha256', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                ), $entry['database']['uploads'] ?? []),
                'attachments' => array_map(static fn(array $row): string => hash(
                    'sha256', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                ), $entry['database']['attachments'] ?? []),
                'path_references' => (int) ($entry['database']['path_references'] ?? -1),
                'upload_id_references' => (int) ($entry['database']['upload_id_references'] ?? -1),
            ];
        }
    }
    usort($projection, static fn(array $left, array $right): int => strcmp($left['path_sha256'], $right['path_sha256']));
    return hash('sha256', json_encode($projection, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function catalogConflictRepairSummary(string $mode, string $batch, string $planHash, array $inspection): array
{
    $entries = [];
    foreach (['pending', 'complete', 'conflicts'] as $state) {
        foreach ($inspection[$state] as $entry) {
            $entries[] = [
                'path_sha256' => $entry['item']['path_sha256'],
                'action' => $entry['item']['action'],
                'state' => $state,
                'reason' => $entry['reason'],
            ];
        }
    }
    usort($entries, static fn(array $left, array $right): int => strcmp($left['path_sha256'], $right['path_sha256']));
    return [
        'schema' => 1,
        'mode' => $mode,
        'batch' => $batch,
        'plan_sha256' => $planHash,
        'pending' => count($inspection['pending']),
        'already_repaired' => count($inspection['complete']),
        'conflicts' => count($inspection['conflicts']),
        'repaired' => 0,
        'zero_work' => false,
        'entries' => $entries,
        'passed' => false,
        'started_at_utc' => gmdate(DATE_ATOM),
    ];
}

function catalogConflictRepairPrintSummary(array $summary, string $reportPath): void
{
    foreach (['pending', 'already_repaired', 'conflicts', 'repaired'] as $key) {
        echo $key . '=' . (int) ($summary[$key] ?? 0) . PHP_EOL;
    }
    echo 'zero_work=' . (($summary['zero_work'] ?? false) ? '1' : '0') . PHP_EOL;
    echo 'report=' . basename($reportPath) . PHP_EOL;
}

function catalogConflictRepairCliOption(array $arguments, string $name): ?string
{
    foreach ($arguments as $index => $argument) {
        if ($argument === $name && isset($arguments[$index + 1])) return trim((string) $arguments[$index + 1]);
    }
    return null;
}

function catalogConflictRepairIdentifier(string $identifier): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/D', $identifier) !== 1) throw new RuntimeException('Unsafe database identifier');
    return '`' . $identifier . '`';
}

function catalogConflictRepairEscapeLike(string $value): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
}

function catalogConflictRepairErrorCode(Throwable $exception): string
{
    $message = strtolower($exception->getMessage());
    return match (true) {
        str_contains($message, 'plan') => 'plan_rejected',
        str_contains($message, 'backup') => 'backup_not_confirmed',
        str_contains($message, 'gate') => 'catalog_gate_open',
        str_contains($message, 'lock'), str_contains($message, 'maintenance operation') => 'maintenance_lock_unavailable',
        str_contains($message, 'reference'), str_contains($message, 'tenant') => 'reference_contract_failed',
        str_contains($message, 'png'), str_contains($message, 'replacement') => 'replacement_contract_failed',
        str_contains($message, 'rollback'), str_contains($message, 'recovery') => 'recovery_required',
        default => 'repair_failed_closed',
    };
}

function catalogConflictRepairAssertBackupFresh(array $backup): void
{
    try {
        $confirmed = new DateTimeImmutable((string) $backup['confirmed_at_utc']);
    } catch (Throwable) {
        throw new RuntimeException('Backup receipt timestamp is invalid');
    }
    $age = time() - $confirmed->getTimestamp();
    if ($age < -300 || $age > 4 * 3600) {
        throw new RuntimeException('Backup receipt is outside the four-hour maintenance window');
    }
}
