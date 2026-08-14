<?php
declare(strict_types=1);

require_once __DIR__ . '/catalog-public-upload-type.php';
require_once __DIR__ . '/catalog-public-quarantine-contract.php';
require_once __DIR__ . '/catalog-public-conflict-repair-contract.php';
define('YIYUNYING_CONFLICT_REPAIR_LIBRARY_ONLY', true);
require_once __DIR__ . '/repair-catalog-public-conflicts.php';

function repairTestFail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function repairTestRejected(callable $callback): bool
{
    try { $callback(); } catch (Throwable) { return true; }
    return false;
}

function repairTestPngChunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('H*', hash('crc32b', $type . $data));
}

$hashA = str_repeat('a', 64);
$hashB = str_repeat('b', 64);
$preA = str_repeat('c', 64);
$preB = str_repeat('d', 64);
$postA = str_repeat('e', 64);
$postB = str_repeat('f', 64);
$absoluteA = PHP_OS_FAMILY === 'Windows' ? 'C:\\private\\a.png' : '/private/a.png';
$absoluteB = PHP_OS_FAMILY === 'Windows' ? 'C:\\private\\b.png' : '/private/b.png';
$validPlan = [
    'schema' => 2,
    'plan_kind' => 'runtime',
    'batch' => 'catalog-repair-20260814',
    'backup' => [
        'confirmed' => true,
        'confirmed_at_utc' => gmdate('Y-m-d\\TH:i:s\\Z'),
        'database' => [
            'path' => PHP_OS_FAMILY === 'Windows' ? 'C:\\private\\database.sql.gz' : '/private/database.sql.gz',
            'size_bytes' => 123, 'sha256' => str_repeat('1', 64), 'format' => 'database_gzip',
            'mtime_epoch' => time(),
        ],
        'public_uploads' => [
            'path' => PHP_OS_FAMILY === 'Windows' ? 'C:\\private\\public-uploads.tar.gz' : '/private/public-uploads.tar.gz',
            'size_bytes' => 456, 'sha256' => str_repeat('2', 64), 'format' => 'public_uploads_tar_gzip',
            'mtime_epoch' => time(),
        ],
    ],
    'items' => [
        [
            'path_sha256' => $hashA,
            'preimage' => ['sha256' => $preA, 'size_bytes' => 101],
            'replacement' => [
                'path' => $absoluteA, 'sha256' => $postA, 'size_bytes' => 91,
                'width' => 1, 'height' => 1, 'metadata_policy' => 'no_ancillary_chunks_v1',
            ],
            'expected' => [
                'admin_id' => 2, 'app_id' => 3, 'path_references' => 8,
                'upload_id_references' => 0, 'upload_rows' => 0, 'media_attachment_rows' => 0,
            ],
            'action' => CATALOG_CONFLICT_REPAIR_ACTION_JPEG,
            'registration' => ['user_id' => null, 'scene' => 'chat_image', 'original_name' => 'legacy.png'],
        ],
        [
            'path_sha256' => $hashB,
            'preimage' => ['sha256' => $preB, 'size_bytes' => 202],
            'replacement' => [
                'path' => $absoluteB, 'sha256' => $postB, 'size_bytes' => 92,
                'width' => 1, 'height' => 1, 'metadata_policy' => 'no_ancillary_chunks_v1',
            ],
            'expected' => [
                'admin_id' => 2, 'app_id' => 3, 'path_references' => 3,
                'upload_id_references' => 1, 'upload_rows' => 1, 'media_attachment_rows' => 1,
            ],
            'action' => CATALOG_CONFLICT_REPAIR_ACTION_HEIC,
            'registration' => null,
        ],
    ],
];
$normalized = catalogConflictRepairValidatePlan($validPlan);
$sourcePlan = $validPlan;
unset($sourcePlan['backup']);
$sourcePlan['plan_kind'] = 'source';
foreach ($sourcePlan['items'] as &$sourceItem) unset($sourceItem['replacement']['path']);
unset($sourceItem);
$normalizedSource = catalogConflictRepairValidateSourcePlan($sourcePlan);
if (count($normalized['items']) !== 2 || count($normalizedSource['items']) !== 2
    || $normalized['items'][0]['expected']['path_references'] !== 8
    || isset($normalizedSource['items'][0]['replacement']['path'])
    || isset($normalized['items'][0]['converter'])) {
    repairTestFail('Conflict repair plan normalization failed');
}

$wrongReferences = $validPlan;
$wrongReferences['items'][0]['expected']['path_references'] = 7;
$wrongHeicReferences = $validPlan;
$wrongHeicReferences['items'][1]['expected']['path_references'] = 999;
$converterInjection = $validPlan;
$converterInjection['items'][0]['converter'] = [
    'executable' => '/bin/anything', 'arguments' => ['{input}', '{output}'],
];
$weakBackup = $validPlan;
$weakBackup['backup']['confirmed'] = false;
$badMetadataPolicy = $validPlan;
$badMetadataPolicy['items'][1]['replacement']['metadata_policy'] = 'keep_metadata';
if (!repairTestRejected(fn() => catalogConflictRepairValidatePlan($wrongReferences))
    || !repairTestRejected(fn() => catalogConflictRepairValidatePlan($wrongHeicReferences))
    || !repairTestRejected(fn() => catalogConflictRepairValidatePlan($converterInjection))
    || !repairTestRejected(fn() => catalogConflictRepairValidatePlan($weakBackup))
    || !repairTestRejected(fn() => catalogConflictRepairValidatePlan($badMetadataPolicy))) {
    repairTestFail('Conflict repair plan did not fail closed');
}
$agedPlan = $validPlan;
$agedPlan['backup']['confirmed_at_utc'] = gmdate('Y-m-d\\TH:i:s\\Z', time() - 5 * 3600);
$agedPlan['backup']['database']['mtime_epoch'] = time() - 5 * 3600;
$agedPlan['backup']['public_uploads']['mtime_epoch'] = time() - 5 * 3600;
catalogConflictRepairValidatePlan($agedPlan);
if (!repairTestRejected(fn() => catalogConflictRepairAssertBackupFresh($agedPlan['backup']))) {
    repairTestFail('First apply backup freshness gate did not reject an aged receipt');
}

$base = sys_get_temp_dir() . '/yiyunying-conflict-repair-' . bin2hex(random_bytes(5));
$uploads = $base . '/public/uploads';
$private = $base . '/private';
if (!mkdir($uploads, 0700, true) || !mkdir($private, 0700, true)) repairTestFail('Unable to create repair fixtures');
$jpegPath = $uploads . '/jpeg-as-png.png';
$heicPath = $uploads . '/heic-as-png.png';
$jpegBytes = "\xFF\xD8\xFF\xE0" . str_repeat('J', 80);
$heicBytes = pack('N', 24) . 'ftypheic' . str_repeat("\0", 12);
file_put_contents($jpegPath, $jpegBytes);
file_put_contents($heicPath, $heicBytes);
if (catalogConflictRepairContentKind($jpegPath) !== 'jpeg'
    || catalogConflictRepairContentKind($heicPath) !== 'heic') {
    repairTestFail('Conflict repair source signature classification failed');
}
$relativeJpeg = 'uploads/jpeg-as-png.png';
$relativeHeic = 'uploads/heic-as-png.png';
$discovered = catalogConflictRepairDiscoverPaths($uploads, [
    catalogConflictRepairPathHash($relativeJpeg), catalogConflictRepairPathHash($relativeHeic),
]);
if (count($discovered) !== 2
    || ($discovered[catalogConflictRepairPathHash($relativeJpeg)]['relative'] ?? '') !== $relativeJpeg) {
    repairTestFail('Conflict repair path-hash discovery failed');
}

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
if (!is_string($png)) repairTestFail('PNG fixture decode failed');
$prepared = $private . '/prepared.png';
file_put_contents($prepared, $png);
@chmod($prepared, 0600);
$pngExpected = [
    'sha256' => hash('sha256', $png), 'size_bytes' => strlen($png),
    'width' => 1, 'height' => 1,
];
if (!catalogConflictRepairPngHasNoAncillaryMetadata($prepared)) repairTestFail('Clean PNG metadata policy failed');
if (function_exists('imagecreatefrompng')) {
    catalogConflictRepairAssertPng($prepared, $private, $pngExpected);
} elseif (!repairTestRejected(fn() => catalogConflictRepairAssertPng($prepared, $private, $pngExpected))) {
    repairTestFail('PNG verification did not fail closed without GD');
}

$iendOffset = strrpos($png, "\x00\x00\x00\x00IEND");
if (!is_int($iendOffset)) repairTestFail('PNG IEND fixture missing');
$metadataPng = substr($png, 0, $iendOffset)
    . repairTestPngChunk('tEXt', "author\0secret")
    . substr($png, $iendOffset);
$metadataPath = $private . '/metadata.png';
file_put_contents($metadataPath, $metadataPng);
@chmod($metadataPath, 0600);
if (catalogConflictRepairPngHasNoAncillaryMetadata($metadataPath)) {
    repairTestFail('PNG metadata policy accepted a text chunk');
}

$trnsPng = substr($png, 0, $iendOffset)
    . repairTestPngChunk('tRNS', "\x00")
    . substr($png, $iendOffset);
$trnsPath = $private . '/transparency-metadata.png';
file_put_contents($trnsPath, $trnsPng);
@chmod($trnsPath, 0600);
if (catalogConflictRepairPngHasNoAncillaryMetadata($trnsPath)) {
    repairTestFail('PNG critical-chunks-only policy accepted tRNS');
}

$recovery = $private . '/recovery';
mkdir($recovery, 0700);
$copy = $recovery . '/copy.preimage';
catalogConflictRepairCopyVerified(
    $jpegPath,
    $copy,
    ['sha256' => hash('sha256', $jpegBytes), 'size_bytes' => strlen($jpegBytes)],
    $recovery,
    0600,
    true
);
$copied = catalogConflictRepairFingerprint($copy, $recovery);
if ($copied['nlink'] !== 1
    || (PHP_OS_FAMILY !== 'Windows' && $copied['mode'] !== 0600)
    || $copied['sha256'] !== hash('sha256', $jpegBytes)) {
    repairTestFail('Root-only recovery copy contract failed');
}

if (catalogConflictRepairDecodeMetadata(null) !== []
    || catalogConflictRepairDecodeMetadata('') !== []
    || catalogConflictRepairDecodeMetadata('{}') !== []
    || catalogConflictRepairDecodeMetadata('{"kept":true}') !== ['kept' => true]
    || !repairTestRejected(fn() => catalogConflictRepairDecodeMetadata('{broken'))
    || !repairTestRejected(fn() => catalogConflictRepairDecodeMetadata('[]'))) {
    repairTestFail('Media metadata preflight contract failed');
}

if (catalogConflictRepairRecoveryDecision([
        ['file' => 'new', 'database' => 'new'], ['file' => 'old', 'database' => 'new'],
    ]) !== 'forward'
    || catalogConflictRepairRecoveryDecision([
        ['file' => 'new', 'database' => 'old'], ['file' => 'old', 'database' => 'old'],
    ]) !== 'rollback'
    || catalogConflictRepairRecoveryDecision([
        ['file' => 'new', 'database' => 'new'], ['file' => 'old', 'database' => 'old'],
    ]) !== 'manual'
    || catalogConflictRepairRecoveryDecision([
        ['file' => 'other', 'database' => 'old'], ['file' => 'old', 'database' => 'old'],
    ]) !== 'manual') {
    repairTestFail('Commit-uncertainty recovery decision contract failed');
}
$databaseFixture = [
    'uploads' => [['id' => 1, 'mime_type' => 'image/heic', 'status' => 1]],
    'attachments' => [['id' => 2, 'metadata_json' => null]],
    'path_references' => 3, 'upload_id_references' => 1, 'reference_tenants' => ['2:3' => 4],
];
$databaseDigest = catalogConflictRepairDatabaseDigest($databaseFixture);
$databaseFixture['uploads'][0]['mime_type'] = 'image/png';
if (catalogConflictRepairHash($databaseDigest) === null
    || hash_equals($databaseDigest, catalogConflictRepairDatabaseDigest($databaseFixture))) {
    repairTestFail('Exact database before/after digest contract failed');
}

$journalDirectory = $private . '/journal-state';
mkdir($journalDirectory, 0700);
$journalPath = $journalDirectory . '/journal-fixture.json';
$journalPlanHash = str_repeat('9', 64);
catalogConflictRepairWritePrivateJson($journalPath, [
    'schema' => 1, 'state' => 'intent', 'batch' => 'fixture-batch', 'attempt' => 'fixture-attempt',
    'plan_sha256' => $journalPlanHash, 'runtime_plan_sha256' => $journalPlanHash,
    'backup' => $validPlan['backup'],
    'replaced_path_hashes' => [], 'created_at_utc' => gmdate(DATE_ATOM),
    'entries' => [
        [
            'path_sha256' => $hashA, 'preimage_sha256' => $preA, 'preimage_size_bytes' => 101,
            'replacement_sha256' => $postA, 'replacement_size_bytes' => 91, 'original_mode' => 0644,
            'database_before_sha256' => str_repeat('3', 64), 'database_after_sha256' => '',
        ],
        [
            'path_sha256' => $hashB, 'preimage_sha256' => $preB, 'preimage_size_bytes' => 202,
            'replacement_sha256' => $postB, 'replacement_size_bytes' => 92, 'original_mode' => 0644,
            'database_before_sha256' => str_repeat('4', 64), 'database_after_sha256' => '',
        ],
    ],
], dirname($uploads));
putenv('YIYUNYING_CONFLICT_REPAIR_TESTING=1');
putenv('YIYUNYING_CONFLICT_REPAIR_FAULT=journal_before_publish');
$journalFailure = repairTestRejected(fn() => catalogConflictRepairRewriteJournal(
    $journalPath, 'replacing_files', dirname($uploads), ['replaced_path_hashes' => [$hashA]]
));
putenv('YIYUNYING_CONFLICT_REPAIR_FAULT');
$journalAfterFailure = json_decode((string) file_get_contents($journalPath), true, 16, JSON_THROW_ON_ERROR);
if (!$journalFailure || ($journalAfterFailure['state'] ?? null) !== 'intent') {
    repairTestFail('Journal publication fault was not atomic');
}
catalogConflictRepairRewriteJournal(
    $journalPath, 'replacing_files', dirname($uploads), ['replaced_path_hashes' => [$hashA]]
);
catalogConflictRepairRewriteJournal($journalPath, 'rolled_back', dirname($uploads));
$loadedJournal = catalogConflictRepairLoadJournal($journalPath, 'fixture-batch', $journalPlanHash);
if (($loadedJournal['state'] ?? null) !== 'rolled_back') repairTestFail('Journal state machine contract failed');
$commitJournalPath = $journalDirectory . '/journal-commit-fixture.json';
$commitJournal = $loadedJournal;
$commitJournal['state'] = 'intent';
$commitJournal['replaced_path_hashes'] = [];
foreach ($commitJournal['entries'] as &$entry) $entry['database_after_sha256'] = '';
unset($entry);
catalogConflictRepairWritePrivateJson($commitJournalPath, $commitJournal, dirname($uploads));
catalogConflictRepairRewriteJournal($commitJournalPath, 'files_replaced', dirname($uploads));
catalogConflictRepairRewriteJournal($commitJournalPath, 'commit_ready', dirname($uploads), [
    'database_after_sha256_by_path' => [$hashA => str_repeat('5', 64), $hashB => str_repeat('6', 64)],
]);
$commitReady = catalogConflictRepairLoadJournal($commitJournalPath, 'fixture-batch', $journalPlanHash);
if (($commitReady['state'] ?? null) !== 'commit_ready'
    || ($commitReady['entries'][0]['database_after_sha256'] ?? '') !== str_repeat('5', 64)) {
    repairTestFail('Journal database after-image binding failed');
}
catalogConflictRepairRewriteJournal($commitJournalPath, 'db_committed', dirname($uploads));
catalogConflictRepairRewriteJournal($commitJournalPath, 'complete', dirname($uploads));
putenv('YIYUNYING_CONFLICT_REPAIR_FAULT=before_commit');
$databaseFault = repairTestRejected(fn() => catalogConflictRepairFault('before_commit'));
putenv('YIYUNYING_CONFLICT_REPAIR_FAULT=recover_after_first_file');
$fileFault = repairTestRejected(fn() => catalogConflictRepairFault('recover_after_first_file'));
putenv('YIYUNYING_CONFLICT_REPAIR_FAULT');
putenv('YIYUNYING_CONFLICT_REPAIR_TESTING');
if (!$databaseFault || !$fileFault) repairTestFail('Database/file fault-injection checkpoint failed');

$completeInspection = ['pending' => [], 'complete' => [[], []], 'conflicts' => []];
$conflictedInspection = ['pending' => [], 'complete' => [[]], 'conflicts' => [[]]];
if (!catalogConflictRepairRecoveryZeroWorkAllowed($completeInspection)
    || catalogConflictRepairRecoveryZeroWorkAllowed($conflictedInspection)) {
    repairTestFail('Recovery without journal did not fail closed on a conflict');
}

$summary = catalogConflictRepairSummary('apply', 'fixture-batch', str_repeat('a', 64), [
    'pending' => [],
    'complete' => [
        ['item' => ['path_sha256' => $hashA, 'action' => CATALOG_CONFLICT_REPAIR_ACTION_JPEG], 'reason' => 'already_repaired'],
        ['item' => ['path_sha256' => $hashB, 'action' => CATALOG_CONFLICT_REPAIR_ACTION_HEIC], 'reason' => 'already_repaired'],
    ],
    'conflicts' => [],
]);
if ($summary['pending'] !== 0 || $summary['already_repaired'] !== 2
    || str_contains(json_encode($summary, JSON_THROW_ON_ERROR), 'uploads/')) {
    repairTestFail('Conflict repair zero-work/report redaction contract failed');
}
ob_start();
catalogConflictRepairPrintSummary($summary, '/root/private/reports/report-fixture.json');
$printedSummary = (string) ob_get_clean();
if (str_contains($printedSummary, '/root/') || !str_contains($printedSummary, 'report=report-fixture.json')) {
    repairTestFail('Conflict repair CLI report path was not reduced to a basename');
}

$toolSource = file_get_contents(__DIR__ . '/repair-catalog-public-conflicts.php');
$contractSource = file_get_contents(__DIR__ . '/catalog-public-conflict-repair-contract.php');
$deploySource = file_get_contents(__DIR__ . '/deploy-ssh.py');
if (!is_string($toolSource) || !is_string($contractSource)
    || !is_string($deploySource)
    || str_contains($toolSource, 'DELETE FROM')
    || !str_contains($toolSource, '--maintenance-confirmed')
    || !str_contains($toolSource, '--backup-confirmed')
    || !str_contains($toolSource, '--recover')
    || !str_contains($toolSource, 'catalog_private_migration_ready')
    || !str_contains($toolSource, 'GET_LOCK')
    || !str_contains($toolSource, 'FOR UPDATE')
    || !str_contains($toolSource, 'catalogConflictRepairRollbackFiles')
    || !str_contains($toolSource, 'commit_response_unknown')
    || !str_contains($toolSource, 'catalogConflictRepairFindUnfinishedJournals')
    || !str_contains($toolSource, 'catalogConflictRepairRecoveryZeroWorkAllowed')
    || !str_contains($toolSource, 'catalogConflictRepairAssertBackupArtifacts($journalData')
    || !str_contains($toolSource, "storage/private/catalog-conflict-recovery")
    || !str_contains($toolSource, 'UPDATE uploads SET')
    || !str_contains($toolSource, 'UPDATE media_attachments')
    || !str_contains($contractSource, 'no_ancillary_chunks_v1')
    || !str_contains($contractSource, "'plan_kind' => 'runtime'")
    || !str_contains($contractSource, 'mtime_epoch')
    || !str_contains($contractSource, 'imagecreatefrompng')
    || !str_contains($contractSource, "'-t'")
    || !str_contains($contractSource, "'-tzf'")) {
    repairTestFail('Conflict repair executable safety contract failed');
}
$maintenanceHook = strpos($deploySource, '"catalog-maintenance"');
$uploadsBackupHook = strpos($deploySource, '"public-uploads-backup"');
$databaseBackupHook = strpos($deploySource, '"database-backup"');
$gateHook = strpos($deploySource, '"catalog-gate-closed-readback"');
$repairHook = strpos($deploySource, '"catalog-conflict-repair-apply"');
$repairRuntimePlanHook = strpos($deploySource, '"catalog-conflict-runtime-plan-create"');
$repairReportHook = strpos($deploySource, '"catalog-conflict-repair-report-check"');
$repairReadbackHook = strpos($deploySource, '"catalog-conflict-repair-readback"');
$quarantineHook = strpos($deploySource, '"catalog-public-quarantine-dry-run"');
if (!str_contains($deploySource, '--catalog-conflict-repair-plan')
    || !str_contains($deploySource, '--catalog-conflict-repair-jpeg-png')
    || !str_contains($deploySource, '--catalog-conflict-repair-heic-png')
    || !str_contains($deploySource, 'catalog_conflict_runtime_plan_command')
    || !str_contains($deploySource, 'parse_catalog_conflict_report_basename')
    || !str_contains($deploySource, '--apply --maintenance-confirmed --backup-confirmed')
    || !is_int($maintenanceHook) || !is_int($uploadsBackupHook) || !is_int($databaseBackupHook)
    || !is_int($gateHook) || !is_int($repairRuntimePlanHook) || !is_int($repairHook)
    || !is_int($repairReportHook) || !is_int($repairReadbackHook) || !is_int($quarantineHook)
    || !($maintenanceHook < $uploadsBackupHook && $uploadsBackupHook < $databaseBackupHook
        && $databaseBackupHook < $gateHook && $gateHook < $repairRuntimePlanHook
        && $repairRuntimePlanHook < $repairHook && $repairHook < $repairReportHook
        && $repairReportHook < $repairReadbackHook && $repairReadbackHook < $quarantineHook)) {
    repairTestFail('Conflict repair deployment hook ordering contract failed');
}

foreach ([$commitJournalPath, $journalPath, $copy, $trnsPath, $metadataPath, $prepared, $jpegPath, $heicPath] as $file) @unlink($file);
@rmdir($journalDirectory);
@rmdir($recovery);
@rmdir($private);
@rmdir($uploads);
@rmdir(dirname($uploads));
@rmdir($base);

echo "Catalog public conflict repair contract: passed\n";
