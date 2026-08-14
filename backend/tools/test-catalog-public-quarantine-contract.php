<?php
declare(strict_types=1);

require_once __DIR__ . '/catalog-public-quarantine-contract.php';
define('YIYUNYING_QUARANTINE_LIBRARY_ONLY', true);
require_once __DIR__ . '/quarantine-catalog-public-files.php';

$checks = [
    [catalogPublicQuarantineDecision('safe', true, 0, 0, 0), 'retain', 'trusted_managed_avatar'],
    [catalogPublicQuarantineDecision('safe', false, 1, 0, 0), 'retain', 'registered_safe_upload'],
    [catalogPublicQuarantineDecision('svg', false, 1, 2, 0), 'conflict', 'registered_unsafe_path_reference'],
    [catalogPublicQuarantineDecision('unknown', false, 1, 0, 1), 'disable_and_quarantine', 'registered_unsafe_id_reference_preserved'],
    [catalogPublicQuarantineDecision('unknown', false, 1, 0, 0), 'disable_and_quarantine', 'registered_unsafe_unreferenced'],
    [catalogPublicQuarantineDecision('safe', false, 0, 0, 0), 'quarantine', 'safe_orphan'],
    [catalogPublicQuarantineDecision('svg', false, 0, 0, 0), 'quarantine', 'unsafe_unregistered'],
    [catalogPublicQuarantineDecision('safe', false, 0, 1, 0), 'conflict', 'unregistered_database_reference'],
];
foreach ($checks as [$actual, $action, $reason]) {
    if ($actual['action'] !== $action || $actual['reason'] !== $reason) {
        fwrite(STDERR, "Quarantine decision contract failed\n");
        exit(1);
    }
}

if (catalogPublicQuarantineCanonicalRelative('uploads/2/2026/08/a.png') !== 'uploads/2/2026/08/a.png'
    || catalogPublicQuarantineCanonicalRelative('../uploads/a.png') !== null
    || catalogPublicQuarantineCanonicalRelative('uploads/a/../b.png') !== null
    || catalogPublicQuarantineCanonicalRelative('downloads/a.png') !== null) {
    fwrite(STDERR, "Quarantine path contract failed\n");
    exit(1);
}

$referencePaths = [];
for ($index = 0; $index < 431; $index++) {
    $referencePaths[] = 'uploads/contracts/' . $index . '/fixture.bin';
}
$referenceQuery = quarantinePathReferenceAggregateQuery('messages', 'content', $referencePaths);
if (QUARANTINE_REFERENCE_PATH_BATCH_SIZE !== 512
    || count(array_chunk($referencePaths, QUARANTINE_REFERENCE_PATH_BATCH_SIZE)) !== 1
    || substr_count($referenceQuery['sql'], 'SUM(CASE WHEN `content` LIKE ?') !== 431
    || !str_contains($referenceQuery['sql'], 'FROM `messages` WHERE `content` LIKE ?')
    || count($referenceQuery['params']) !== 432
    || $referenceQuery['params'][431] !== '%uploads/%') {
    fwrite(STDERR, "Quarantine reference query-count contract failed\n");
    exit(1);
}
$installSql = file_get_contents(dirname(__DIR__) . '/database/install.sql');
$schemaTables = [];
if (!is_string($installSql)
    || preg_match_all(
        '/CREATE TABLE IF NOT EXISTS `([^`]+)`\s*\((.*?)\)\s*ENGINE=/s',
        $installSql,
        $schemaTables,
        PREG_SET_ORDER
    ) === false) {
    fwrite(STDERR, "Quarantine reference schema contract failed\n");
    exit(1);
}
$excludedReferenceTables = [
    'uploads', 'catalog_file_migrations', 'upload_file_deletions', 'catalog_legacy_url_quarantines',
];
$schemaTextColumns = 0;
foreach ($schemaTables as $schemaTable) {
    if (in_array((string) $schemaTable[1], $excludedReferenceTables, true)) continue;
    $matches = [];
    $matched = preg_match_all(
        '/^\s*`[^`]+`\s+(?:char|varchar|tinytext|text|mediumtext|longtext|json)\b/im',
        (string) $schemaTable[2],
        $matches
    );
    if ($matched === false) {
        fwrite(STDERR, "Quarantine reference schema-column contract failed\n");
        exit(1);
    }
    $schemaTextColumns += $matched;
}
$optimizedQueries = $schemaTextColumns * count(array_chunk($referencePaths, QUARANTINE_REFERENCE_PATH_BATCH_SIZE));
$legacyQueries = $schemaTextColumns * count(array_chunk($referencePaths, 20));
if (count($schemaTables) !== 223 || $schemaTextColumns !== 734
    || $optimizedQueries !== 734 || $legacyQueries !== 16148 || $optimizedQueries >= $legacyQueries) {
    fwrite(STDERR, "Quarantine reference complexity contract failed\n");
    exit(1);
}
$wildcardQuery = quarantinePathReferenceAggregateQuery(
    'messages',
    'content',
    ['uploads/contracts/100%_!name.bin']
);
if (($wildcardQuery['params'][0] ?? null) !== '%uploads/contracts/100!%!_!!name.bin%') {
    fwrite(STDERR, "Quarantine reference escaping contract failed\n");
    exit(1);
}
$oversizedRejected = false;
try {
    quarantinePathReferenceAggregateQuery(
        'messages',
        'content',
        array_fill(0, QUARANTINE_REFERENCE_PATH_BATCH_SIZE + 1, 'uploads/contracts/fixture.bin')
    );
} catch (InvalidArgumentException) {
    $oversizedRejected = true;
}
$nonUploadRejected = false;
try {
    quarantinePathReferenceAggregateQuery('messages', 'content', ['downloads/fixture.bin']);
} catch (InvalidArgumentException) {
    $nonUploadRejected = true;
}
$quarantineSource = file_get_contents(__DIR__ . '/quarantine-catalog-public-files.php');
if (!$oversizedRejected || !$nonUploadRejected || !is_string($quarantineSource)
    || !str_contains($quarantineSource, 'array_chunk($paths, QUARANTINE_REFERENCE_PATH_BATCH_SIZE)')
    || !str_contains($quarantineSource, "'quarantine_progress='")) {
    fwrite(STDERR, "Quarantine reference boundary contract failed\n");
    exit(1);
}

$base = sys_get_temp_dir() . '/yiyunying-quarantine-contract-' . bin2hex(random_bytes(6));
$uploads = $base . '/public/uploads';
if (!mkdir($uploads, 0700, true)) {
    fwrite(STDERR, "Unable to create quarantine fixture\n");
    exit(1);
}
$file = $uploads . '/fixture.bin';
file_put_contents($file, 'before');
$fingerprint = catalogPublicQuarantineFingerprint($file, $uploads);
catalogPublicQuarantineAssertFingerprint($file, $uploads, $fingerprint);
file_put_contents($file, 'after');
$changedRejected = false;
try {
    catalogPublicQuarantineAssertFingerprint($file, $uploads, $fingerprint);
} catch (RuntimeException) {
    $changedRejected = true;
}
@unlink($file);
@rmdir($uploads);
@rmdir(dirname($uploads));
@rmdir($base);
if (!$changedRejected) {
    fwrite(STDERR, "Quarantine CAS contract failed\n");
    exit(1);
}

echo "Catalog public quarantine contract: passed\n";
