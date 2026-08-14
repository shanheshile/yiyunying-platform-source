<?php
declare(strict_types=1);

define('YIYUNYING_CONFLICT_SERVER_LOCAL_LIBRARY_ONLY', true);
require_once __DIR__ . '/prepare-catalog-public-conflicts-server-local.php';

function serverLocalTestFail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function serverLocalTestRejected(callable $callback): bool
{
    try { $callback(); } catch (Throwable) { return true; }
    return false;
}

function serverLocalTestPngChunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('H*', hash('crc32b', $type . $data));
}

$bindings = catalogConflictServerLocalBindings();
if (array_keys($bindings) !== [CATALOG_CONFLICT_REPAIR_ACTION_JPEG, CATALOG_CONFLICT_REPAIR_ACTION_HEIC]
    || $bindings[CATALOG_CONFLICT_REPAIR_ACTION_JPEG]['path_sha256']
        !== '6dba5a3f5092e15bad671d0d59c117f101e52ea58cd284079709568af52e3d29'
    || $bindings[CATALOG_CONFLICT_REPAIR_ACTION_HEIC]['preimage']['sha256']
        !== '6ff415d4bbad54d44b316075f7aef9d96a8210540902de36daa15db58e5d8e7c') {
    serverLocalTestFail('Server-local fixed bindings changed');
}

$stage = '/tmp/yiyunying-catalog-conflict-20260814-201530-0123456789abcdef';
if (catalogConflictServerLocalValidateStagePath($stage) !== $stage
    || !serverLocalTestRejected(fn() => catalogConflictServerLocalValidateStagePath('/tmp/yiyunying-catalog-conflict-old'))
    || !serverLocalTestRejected(fn() => catalogConflictServerLocalValidateStagePath('/tmp/yiyunying-catalog-conflict-20260814-201530-0123456789abcdef/../x'))) {
    serverLocalTestFail('Server-local stage path boundary failed');
}

$databaseA = [
    'uploads' => [],
    'attachments' => [],
    'path_references' => 8,
    'upload_id_references' => 0,
    'reference_tenants' => ['2:3' => 8],
];
$databaseB = [
    'uploads' => [['id' => 11, 'admin_id' => 2, 'app_id' => 3, 'status' => 1]],
    'attachments' => [[
        'id' => 12, 'admin_id' => 2, 'app_id' => 3, 'upload_id' => 11,
        'media_type' => 'image', 'metadata_json' => null,
    ]],
    'path_references' => 3,
    'upload_id_references' => 1,
    'reference_tenants' => ['2:3' => 4],
];
$expectedA = catalogConflictServerLocalExpectedState(
    $databaseA, $bindings[CATALOG_CONFLICT_REPAIR_ACTION_JPEG]
);
$expectedB = catalogConflictServerLocalExpectedState(
    $databaseB, $bindings[CATALOG_CONFLICT_REPAIR_ACTION_HEIC]
);
$crossTenant = $databaseB;
$crossTenant['attachments'][0]['app_id'] = 4;
$badCount = $databaseA;
$badCount['path_references'] = 7;
if ($expectedA['admin_id'] !== 2 || $expectedA['app_id'] !== 3
    || $expectedB['upload_rows'] !== 1 || $expectedB['media_attachment_rows'] !== 1
    || !serverLocalTestRejected(fn() => catalogConflictServerLocalExpectedState(
        $crossTenant, $bindings[CATALOG_CONFLICT_REPAIR_ACTION_HEIC]
    ))
    || !serverLocalTestRejected(fn() => catalogConflictServerLocalExpectedState(
        $badCount, $bindings[CATALOG_CONFLICT_REPAIR_ACTION_JPEG]
    ))) {
    serverLocalTestFail('Server-local tenant/reference contract failed');
}

$probeA = json_encode(['streams' => [[
    'codec_name' => 'mjpeg', 'codec_type' => 'video', 'width' => 749, 'height' => 421,
]]], JSON_THROW_ON_ERROR);
$probeB = json_encode(['programs' => [], 'stream_groups' => [], 'streams' => [[
    'codec_name' => 'hevc', 'codec_type' => 'video', 'width' => 640, 'height' => 480,
]]], JSON_THROW_ON_ERROR);
$parsedA = catalogConflictServerLocalParseProbe($probeA, $bindings[CATALOG_CONFLICT_REPAIR_ACTION_JPEG]);
$parsedB = catalogConflictServerLocalParseProbe($probeB, $bindings[CATALOG_CONFLICT_REPAIR_ACTION_HEIC]);
$wrongCodec = json_encode(['streams' => [[
    'codec_name' => 'h264', 'codec_type' => 'video', 'width' => 640, 'height' => 480,
]]], JSON_THROW_ON_ERROR);
$extraStream = json_encode(['streams' => [
    ['codec_name' => 'hevc', 'codec_type' => 'video', 'width' => 640, 'height' => 480],
    ['codec_name' => 'aac', 'codec_type' => 'audio', 'width' => 1, 'height' => 1],
]], JSON_THROW_ON_ERROR);
if ($parsedA['width'] !== 749 || $parsedB['height'] !== 480
    || !serverLocalTestRejected(fn() => catalogConflictServerLocalParseProbe(
        $wrongCodec, $bindings[CATALOG_CONFLICT_REPAIR_ACTION_HEIC]
    ))
    || !serverLocalTestRejected(fn() => catalogConflictServerLocalParseProbe(
        $extraStream, $bindings[CATALOG_CONFLICT_REPAIR_ACTION_HEIC]
    ))) {
    serverLocalTestFail('Server-local FFprobe receipt contract failed');
}

$input = $stage . '/heic-source.input';
$output = $stage . '/heic-ffmpeg.png.partial';
$probeCommand = catalogConflictServerLocalProbeCommand($input);
$convertCommand = catalogConflictServerLocalConvertCommand($input, $output);
$joinedProbe = implode("\0", $probeCommand);
$joinedConvert = implode("\0", $convertCommand);
if ($probeCommand[0] !== CATALOG_CONFLICT_SERVER_LOCAL_FFPROBE
    || $convertCommand[0] !== CATALOG_CONFLICT_SERVER_LOCAL_FFMPEG
    || !in_array('-nostdin', $probeCommand, true) || !in_array('-nostdin', $convertCommand, true)
    || !str_contains($joinedProbe, "protocol_whitelist\0file")
    || !str_contains($joinedConvert, "protocol_whitelist\0file")
    || !str_contains($joinedConvert, "map_metadata\0-1")
    || !str_contains($joinedConvert, "map_chapters\0-1")
    || str_contains($joinedProbe . $joinedConvert, 'http:')
    || str_contains($joinedProbe . $joinedConvert, 'https:')
    || !serverLocalTestRejected(fn() => catalogConflictServerLocalRun(['/bin/echo', 'unsafe'], 1))) {
    serverLocalTestFail('Server-local fixed process contract failed');
}

$pngBase = sys_get_temp_dir() . '/yiyunying-server-local-png-' . bin2hex(random_bytes(5));
$rawPng = $pngBase . '.raw';
$cleanPng = $pngBase . '.clean';
$rgbaScanline = "\0\xFF\0\0\xFF";
$pngBytes = "\x89PNG\r\n\x1a\n"
    . serverLocalTestPngChunk('IHDR', pack('NNC5', 1, 1, 8, 6, 0, 0, 0))
    . serverLocalTestPngChunk('tEXt', "source\0must-be-removed")
    . serverLocalTestPngChunk('IDAT', gzcompress($rgbaScanline, 9))
    . serverLocalTestPngChunk('IEND', '');
file_put_contents($rawPng, $pngBytes);
$stripped = catalogConflictServerLocalStripAncillaryPng($rawPng, $cleanPng);
$cleanBytes = file_get_contents($cleanPng);
if ($stripped['width'] !== 1 || $stripped['height'] !== 1 || !is_string($cleanBytes)
    || str_contains($cleanBytes, 'tEXt') || !catalogConflictRepairPngHasNoAncillaryMetadata($cleanPng)
    || !serverLocalTestRejected(fn() => catalogConflictServerLocalStripAncillaryPng($rawPng, $cleanPng))) {
    serverLocalTestFail('Server-local PNG ancillary sanitizer contract failed');
}
@unlink($cleanPng);
@unlink($rawPng);

$replacementA = [
    'sha256' => str_repeat('a', 64), 'size_bytes' => 100, 'width' => 749, 'height' => 421,
    'metadata_policy' => 'no_ancillary_chunks_v1',
];
$replacementB = [
    'sha256' => str_repeat('b', 64), 'size_bytes' => 200, 'width' => 640, 'height' => 480,
    'metadata_policy' => 'no_ancillary_chunks_v1',
];
$plan = catalogConflictServerLocalBuildSourcePlan('catalog-repair-20260814', [
    CATALOG_CONFLICT_REPAIR_ACTION_JPEG => ['database' => $databaseA, 'replacement' => $replacementA],
    CATALOG_CONFLICT_REPAIR_ACTION_HEIC => ['database' => $databaseB, 'replacement' => $replacementB],
]);
if (($plan['schema'] ?? null) !== 2 || ($plan['plan_kind'] ?? null) !== 'source'
    || count($plan['items'] ?? []) !== 2
    || isset($plan['items'][0]['replacement']['path'])
    || str_contains(json_encode($plan, JSON_THROW_ON_ERROR), 'uploads/')) {
    serverLocalTestFail('Server-local redacted source plan contract failed');
}

$validOptions = [
    'tool.php', '--output-directory', $stage, '--batch', 'catalog-repair-20260814',
    '--database-backup', '/private/database.sql.gz',
    '--public-uploads-backup', '/private/public-uploads.tar.gz',
    '--maintenance-confirmed', '--backup-confirmed', '--gate-confirmed',
];
$options = catalogConflictServerLocalParseOptions($validOptions);
$missingGate = array_values(array_filter($validOptions, static fn(string $value): bool => $value !== '--gate-confirmed'));
$unknown = [...$validOptions, '--execute-anything'];
if ($options['output_directory'] !== $stage || $options['batch'] !== 'catalog-repair-20260814'
    || !serverLocalTestRejected(fn() => catalogConflictServerLocalParseOptions($missingGate))
    || !serverLocalTestRejected(fn() => catalogConflictServerLocalParseOptions($unknown))) {
    serverLocalTestFail('Server-local CLI confirmation contract failed');
}

$toolSource = file_get_contents(__DIR__ . '/prepare-catalog-public-conflicts-server-local.php');
$contractSource = file_get_contents(__DIR__ . '/catalog-conflict-server-local-preparation-contract.php');
if (!is_string($toolSource) || !is_string($contractSource)
    || !str_contains($toolSource, 'catalogConflictRepairAssertBackupArtifacts')
    || !str_contains($toolSource, 'catalogConflictRepairAssertGateClosed')
    || !str_contains($toolSource, 'catalogConflictRepairDatabaseMatchesPending')
    || !str_contains($toolSource, 'SELECT GET_LOCK')
    || !str_contains($toolSource, "'--maintenance-confirmed', '--backup-confirmed', '--gate-confirmed'")
    || !str_contains($toolSource, "ini_set('display_errors', '0')")
    || !str_contains($toolSource, "ini_set('log_errors', '0')")
    || !str_contains($toolSource, 'set_error_handler')
    || !str_contains($toolSource, 'restore_error_handler')
    || !str_contains($contractSource, CATALOG_CONFLICT_SERVER_LOCAL_FFMPEG_SHA256)
    || !str_contains($contractSource, CATALOG_CONFLICT_SERVER_LOCAL_FFPROBE_SHA256)
    || str_contains($toolSource, 'shell_exec(') || str_contains($toolSource, 'exec(')
    || str_contains($toolSource, '$exception->getMessage()')) {
    serverLocalTestFail('Server-local executable safety contract failed');
}

echo "Catalog server-local conflict preparation contract: passed\n";
