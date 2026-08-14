<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sources = [
    'limit' => $root . '/app/Services/UploadLimitService.php',
    'storage' => $root . '/app/Services/UploadStorageService.php',
    'media' => $root . '/app/Services/MediaOptimizationService.php',
    'message' => $root . '/app/Services/MessageMediaService.php',
    'submission' => $root . '/app/Services/SubmissionInspectionService.php',
    'sticker_controller' => $root . '/app/Controllers/User/StickerController.php',
    'cloud_sync' => $root . '/app/Services/CloudSyncService.php',
    'public_resource_controller' => $root . '/app/Controllers/PublicApi/ResourceController.php',
    'resource_controller' => $root . '/app/Controllers/Admin/ResourceController.php',
    'user_resource_controller' => $root . '/app/Controllers/User/ResourceController.php',
    'android_modules' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/domain/module/ModuleRegistry.java',
    'smoke_maximum' => $root . '/tools/smoke-maximum.ps1',
    'smoke_multimedia' => $root . '/tools/smoke-multimedia-visual.ps1',
    'api_docs' => $root . '/docs/API_FULL.md',
    'composer' => $root . '/composer.json',
    'deploy' => $root . '/tools/deploy-ssh.py',
    'deploy_test' => $root . '/tools/test-deploy-ssh-safety.py',
    'user_controller' => $root . '/app/Controllers/User/FileFeedbackController.php',
    'admin_controller' => $root . '/app/Controllers/Admin/FileFeedbackController.php',
    'platform_controller' => $root . '/app/Controllers/Platform/OversightController.php',
];
$text = [];
foreach ($sources as $name => $path) {
    $contents = file_get_contents($path);
    if (!is_string($contents) || $contents === '') {
        fwrite(STDERR, "Unable to read upload security source: {$name}\n");
        exit(1);
    }
    $text[$name] = $contents;
}
$composer = json_decode($text['composer'], true, 32, JSON_THROW_ON_ERROR);
$staticChecks = [
    !str_contains($text['limit'], "\$file['type']")
        && str_contains($text['limit'], 'MediaOptimizationService::probeClientUpload($tmp)')
        && str_contains($text['limit'], 'MediaOptimizationService::MAX_JSON_BYTES')
        && str_contains($text['limit'], "'image' => MediaOptimizationService::MAX_IMAGE_BYTES")
        && str_contains($text['limit'], "'image' => min(MediaOptimizationService::MAX_IMAGE_BYTES, \$configured)")
        && str_contains($text['limit'], '@filesize($tmp)')
        && str_contains($text['limit'], 'asort($limits, SORT_NUMERIC)'),
    strpos($text['media'], "if (\$extension === 'json' && \$size > self::MAX_JSON_BYTES)")
        < strpos($text['media'], '$jsonBytes = @file_get_contents($path)'),
    str_contains($text['media'], 'invalid_pdf_structure')
        && str_contains($text['media'], 'invalid_zip_structure')
        && str_contains($text['media'], 'invalid_wav_structure')
        && str_contains($text['media'], 'invalid_ogg_structure')
        && str_contains($text['media'], 'invalid_iso_media_structure')
        && str_contains($text['media'], 'invalid_gzip_structure')
        && str_contains($text['media'], 'invalid_tar_structure')
        && str_contains($text['media'], 'invalid_rtf_structure')
        && str_contains($text['media'], 'verifyZipEntryPayload')
        && str_contains($text['media'], 'zipRequiredEntriesPresent')
        && str_contains($text['media'], 'inflate_get_read_len')
        && str_contains($text['media'], '$covered === $directoryOffset'),
    str_contains($text['media'], "self::FFPROBE_BINARY, '-v', 'error'")
        && str_contains($text['media'], "self::FFMPEG_BINARY, '-nostdin'")
        && str_contains($text['media'], '/opt/yiyunying/media-runtime/current/ffprobe')
        && str_contains($text['media'], "'-count_packets'")
        && str_contains($text['media'], "\$mediaType . '_probe_failed'")
        && str_contains($text['media'], 'PROCESS_OUTPUT_BYTES')
        && str_contains($text['media'], 'PROBE_TIMEOUT_SECONDS')
        && str_contains($text['media'], 'proc_open(')
        && !str_contains($text['media'], '@exec(')
        && !str_contains($text['media'], "'client_optimized'"),
    str_contains($text['media'], 'animated_webp_not_supported')
        && str_contains($text['media'], 'animated_png_not_supported')
        && str_contains($text['media'], 'animated_gif_not_supported')
        && str_contains($text['media'], 'unchangedFromInspection')
        && str_contains($text['media'], 'stream=codec_type,codec_name')
        && str_contains($text['media'], 'probeSemanticsMatch'),
    str_contains($text['media'], 'decoderMemoryAvailable')
        && str_contains($text['media'], 'memory_get_usage(true)')
        && str_contains($text['media'], 'MAX_IMAGE_INPUT_BYTES')
        && str_contains($text['media'], 'MAX_IMAGE_PIXELS = 12000000'),
    str_contains($text['storage'], '$preMoveSize = @filesize($tmp)')
        && str_contains($text['storage'], '$postInspectionHash = @hash_file(\'sha256\', $tmp)')
        && str_contains($text['storage'], '$movedSize = @filesize($originalPath)')
        && str_contains($text['storage'], 'self::canonicalUploadDirectory($storageRoot, $relativeDir)')
        && str_contains($text['storage'], 'self::persistProcessedUpload($candidate, $createdPaths)')
        && str_contains($text['storage'], '$primaryFailure = null')
        && str_contains($text['storage'], 'if (!$committed) {')
        && str_contains($text['storage'], "error_log('upload_cleanup_after_failure: '")
        && str_contains($text['storage'], 'AND upload_mode = ? AND mime_type = ?')
        && str_contains($text['storage'], 'SELECT GET_LOCK(?, 10) AS acquired')
        && str_contains($text['storage'], 'self::selectReusableUploadCandidates(')
        && str_contains($text['storage'], '$cache[$cacheKey] = self::validateReusablePhysicalUpload')
        && str_contains($text['storage'], 'physical_validation_count')
        && str_contains($text['storage'], 'self::storedPhysicalFingerprint(')
        && str_contains($text['storage'], "Database::connection()->inTransaction()")
        && !str_contains($text['storage'], 'ORDER BY id LIMIT 50 FOR UPDATE'),
    str_contains($text['storage'], '$fileUrl === $expectedUrl')
        && str_contains($text['storage'], '$originalUrl === $expectedUrl')
        && str_contains($text['storage'], '$thumbnailUrl === $expectedThumbnail')
        && str_contains($text['storage'], "(int) (\$stat['nlink'] ?? 0) !== 1")
        && str_contains($text['storage'], "(bool) (\$inspection['is_animated'] ?? false)"),
    str_contains($text['storage'], 'public static function validatedPublicUpload(array $upload)')
        && str_contains($text['storage'], 'self::prevalidatedReusableUpload($normalized)')
        && str_contains($text['storage'], "\$normalized['thumbnail_url'] = str_starts_with(\$mime, 'image/') ? \$canonicalUrl : ''")
        && str_contains($text['storage'], "'row_fingerprint' => self::uploadRowFingerprint(\$upload)")
        && str_contains($text['storage'], 'public static function assertLockedPublicUpload('),
    str_contains($text['message'], 'UploadStorageService::trustedMediaMetadata($upload)')
        && str_contains($text['message'], "\$trustedUploadMetadata['width']")
        && str_contains($text['message'], "\$trustedUploadMetadata['duration_ms']")
        && str_contains($text['message'], 'cacheTrustedUploadMetadata')
        && str_contains($text['message'], 'untrusted_external')
        && str_contains($text['message'], 'assertPublicAttachmentTrust')
        && str_contains($text['message'], "'resource', 'resource_comment', 'store_app'")
        && str_contains($text['message'], 'canonicalizePublicHydrationRow')
        && str_contains($text['message'], 'assertStoredPublicAttachmentTrust')
        && str_contains($text['message'], 'canonical_upload_url')
        && str_contains($text['message'], 'verified_sticker_pack_id')
        && str_contains($text['message'], 'sticker_upload_id')
        && str_contains($text['message'], 'UploadStorageService::validatedPublicImageUpload')
        && str_contains($text['message'], 'prevalidateStoredPublicAttachmentTrust')
        && str_contains($text['message'], "'direct_validations' => \$directValidations")
        && str_contains($text['message'], 'UploadStorageService::validatedPublicUpload($row)')
        && str_contains($text['message'], 'UploadStorageService::assertLockedPublicUpload($lockedDirect, $token)')
        && str_contains($text['message'], 'UploadStorageService::assertLockedPublicImageUpload')
        && str_contains($text['message'], 'unsafeRelativePath')
        && str_contains($text['message'], "file_path LIKE 'uploads/%'")
        && str_contains($text['message'], 'sup.id IS NULL'),
    str_contains($text['submission'], '封面或图标不能直接提交外链')
        && str_contains($text['submission'], '多个 ID 字段互相冲突')
        && str_contains($text['submission'], "'cover_url' => \$cover !== null")
        && str_contains($text['resource_controller'], "array_key_exists('image_urls', \$data)")
        && str_contains($text['submission'], 'canonicalizeCatalogPresentation')
        && str_contains($text['submission'], 'UploadStorageService::validatedPublicImageUpload')
        && str_contains($text['resource_controller'], '每张商店应用图片必须引用一个 upload_id')
        && substr_count($text['resource_controller'], 'MessageMediaService::assertStoredPublicAttachmentTrust(') >= 2
        && str_contains($text['resource_controller'], "MessageMediaService::publicImageList((array) (\$item['attachments'] ?? []))")
        && str_contains($text['user_resource_controller'], "MessageMediaService::publicImageList((array) (\$app['attachments'] ?? []))")
        && !str_contains($text['resource_controller'], "static fn(string \$url): array => ['media_type' => 'image', 'url' => \$url]"),
    str_contains($text['android_modules'], 'integerRequired("source_upload_id", "资源上传编号（resource_source 场景）")')
        && str_contains($text['android_modules'], 'integerRequired("source_upload_id", "安装包上传编号（store_app_package 场景）")')
        && !str_contains($text['android_modules'], 'req("download_url", "下载地址"), integer("price_balance", "余额价格")')
        && !str_contains($text['android_modules'], 'field("cover_url", "封面地址"), field("download_url", "下载地址")'),
    str_contains($text['smoke_maximum'], "'resource_source'")
        && str_contains($text['smoke_maximum'], "'store_app_package'")
        && str_contains($text['smoke_multimedia'], "Invoke-Upload \$headersA \$fixturePath 'resource_source'")
        && str_contains($text['smoke_multimedia'], 'attachments = @(@{ upload_id = [int]$forumUploadA.upload_id }, $media[2])')
        && !str_contains($text['smoke_multimedia'], "download_url = 'https://example.com/media/resource.zip'"),
    str_contains($text['api_docs'], 'source_upload_id,icon_upload_id,attachments')
        && str_contains($text['api_docs'], '不接受 `apk_url/icon_url/images/image_urls`')
        && str_contains($text['api_docs'], '每个附件必须且只能提交 `upload_id` 或 `sticker_id`'),
    str_contains($text['sticker_controller'], '添加表情必须提交已验证公开图片的 upload_id')
        && str_contains($text['sticker_controller'], 'prevalidateStickerUploads')
        && str_contains($text['sticker_controller'], 'UploadStorageService::assertLockedPublicImageUpload')
        && str_contains($text['sticker_controller'], 'WHERE pack_id = ? AND upload_id = ?')
        && !str_contains($text['sticker_controller'], "\$item['image_url'] ??"),
    str_contains($text['cloud_sync'], "'schema_version' => 2, 'data_type' => 'stickers'")
        && str_contains($text['cloud_sync'], '旧版 URL 表情备份仅可查看，不能恢复')
        && str_contains($text['cloud_sync'], 'prepareStickerRestore')
        && str_contains($text['cloud_sync'], 'UploadStorageService::assertLockedPublicImageUpload'),
    str_contains($text['public_resource_controller'], "MessageMediaService::hydrate(\$items, 'resource'")
        && str_contains($text['public_resource_controller'], "'resource_comment'")
        && str_contains($text['submission'], 'canonicalizeCatalogPresentation($row, false)')
        && str_contains($text['submission'], 'canonicalizeCatalogPresentation(array $row, bool $strict = false)'),
    isset($composer['require']['ext-gd']) && isset($composer['require']['ext-zlib']),
    str_contains($text['deploy'], '/opt/yiyunying/media-runtime/current/ffmpeg')
        && str_contains($text['deploy'], 'IMG_JPG, IMG_PNG, IMG_WEBP')
        && str_contains($text['deploy'], '"imagecreatefromstring"')
        && str_contains($text['deploy'], 'yiyunying-media-preflight')
        && str_contains($text['deploy'], 'libx264')
        && str_contains($text['deploy'], 'VIDEO_PACKETS')
        && str_contains($text['deploy_test'], 'MEDIA_FFMPEG_BIN')
        && str_contains($text['deploy_test'], 'MEDIA_FFPROBE_BIN'),
    !str_contains($text['user_controller'], "'7z'")
        && !str_contains($text['user_controller'], "'rar'")
        && !str_contains($text['user_controller'], "'bz2'")
        && !str_contains($text['user_controller'], "'xz'")
        && !str_contains($text['user_controller'], "'doc',")
        && !str_contains($text['admin_controller'], "'7z'")
        && !str_contains($text['platform_controller'], "'7z'"),
];
if (in_array(false, $staticChecks, true)) {
    $failed = [];
    foreach ($staticChecks as $index => $passed) if ($passed !== true) $failed[] = (string) ($index + 1);
    fwrite(STDERR, 'Upload security static contract failed: checks ' . implode(', ', $failed) . "\n");
    exit(1);
}

// The production service deliberately uses a fixed ffprobe path. This
// contract-only namespace shim delegates every real process unchanged and
// substitutes a deterministic probe response solely for the exact temporary
// optimized-video fixture named in the environment below.
if (!function_exists('Yiyunying\\Services\\proc_open')) {
    eval(<<<'PHP'
namespace Yiyunying\Services;
function proc_open($command, array $descriptorSpec, &$pipes, ?string $cwd = null, ?array $envVars = null, ?array $options = null)
{
    $fixture = getenv('YIYUNYING_UPLOAD_CONTRACT_VIDEO');
    $candidate = is_array($command) && $command !== [] ? (string) $command[array_key_last($command)] : '';
    if (is_array($command)
        && (string) ($command[0] ?? '') === '/opt/yiyunying/media-runtime/current/ffprobe'
        && is_string($fixture) && $fixture !== ''
        && str_replace('\\', '/', $candidate) === str_replace('\\', '/', $fixture)) {
        $probe = json_encode([
            'streams' => [[
                'codec_type' => 'video', 'codec_name' => 'h264', 'width' => 16, 'height' => 16,
                'duration' => '1.000000', 'nb_read_packets' => '1',
            ]],
            'format' => ['duration' => '1.000000', 'format_name' => 'mov,mp4,m4a,3gp,3g2,mj2'],
        ], JSON_THROW_ON_ERROR);
        $command = [PHP_BINARY, '-r', 'fwrite(STDOUT, ' . var_export($probe, true) . ');'];
    }
    return \proc_open($command, $descriptorSpec, $pipes, $cwd, $envVars, $options ?? []);
}
PHP);
}

require_once $sources['media'];
require_once $sources['limit'];
require_once $root . '/app/Core/HttpException.php';
// The lightweight Windows lint runtime does not load mbstring. Production is
// fail-closed by Composer and the deploy preflight; these ASCII-only fallbacks
// keep this contract focused on attachment trust semantics.
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $offset, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($value, $offset) : substr($value, $offset, $length);
    }
}
if (!class_exists(\Yiyunying\Core\Database::class, false)) {
    require_once __DIR__ . '/fixtures/upload-security-database-stub.php';
}

function writeFixture(string $directory, string $name, string $bytes): string
{
    $path = $directory . '/' . $name;
    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Unable to write fixture: ' . $name);
    }
    return $path;
}

function isoBox(string $type, string $payload): string
{
    return pack('N', 8 + strlen($payload)) . $type . $payload;
}

function storedZip(string $name, string $contents): string
{
    return storedZipEntries([$name => $contents]);
}

/** @param array<string,string> $entries */
function storedZipEntries(array $entries, bool $deflate = false): string
{
    $local = '';
    $central = '';
    foreach ($entries as $name => $contents) {
        $crc = crc32($contents);
        $payload = $deflate ? gzdeflate($contents, 6) : $contents;
        if (!is_string($payload)) throw new RuntimeException('Unable to deflate ZIP fixture');
        $method = $deflate ? 8 : 0;
        $offset = strlen($local);
        $compressed = strlen($payload);
        $length = strlen($contents);
        $local .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, 0, 0, $crc, $compressed, $length, strlen($name), 0)
            . $name . $payload;
        $central .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50, 20, 20, 0, $method, 0, 0, $crc, $compressed, $length,
            strlen($name), 0, 0, 0, 0, 0, $offset
        ) . $name;
    }
    $count = count($entries);
    return $local . $central
        . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($local), 0);
}

function descriptorZip(string $name, string $contents): string
{
    $crc = crc32($contents);
    $payload = gzdeflate($contents, 6);
    if (!is_string($payload)) throw new RuntimeException('Unable to deflate descriptor ZIP fixture');
    $compressed = strlen($payload);
    $length = strlen($contents);
    $flags = 0x08;
    $local = pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, 8, 0, 0, 0, 0, 0, strlen($name), 0)
        . $name . $payload . pack('VVVV', 0x08074b50, $crc, $compressed, $length);
    $central = pack(
        'VvvvvvvVVVvvvvvVV',
        0x02014b50, 20, 20, $flags, 8, 0, 0, $crc, $compressed, $length,
        strlen($name), 0, 0, 0, 0, 0, 0
    ) . $name;
    return $local . $central
        . pack('VvvvvVVv', 0x06054b50, 0, 0, 1, 1, strlen($central), strlen($local), 0);
}

function tarFixture(string $name, string $contents): string
{
    $header = str_repeat("\0", 512);
    $header = substr_replace($header, str_pad($name, 100, "\0"), 0, 100);
    $header = substr_replace($header, sprintf('%07o', 0644) . "\0", 100, 8);
    $header = substr_replace($header, sprintf('%07o', 0) . "\0", 108, 8);
    $header = substr_replace($header, sprintf('%07o', 0) . "\0", 116, 8);
    $header = substr_replace($header, sprintf('%011o', strlen($contents)) . "\0", 124, 12);
    $header = substr_replace($header, sprintf('%011o', 0) . "\0", 136, 12);
    $header = substr_replace($header, str_repeat(' ', 8), 148, 8);
    $header = substr_replace($header, '0', 156, 1);
    $header = substr_replace($header, "ustar\0", 257, 6);
    $header = substr_replace($header, '00', 263, 2);
    $checksum = array_sum(unpack('C*', $header));
    $header = substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
    $padding = (512 - (strlen($contents) % 512)) % 512;
    return $header . $contents . str_repeat("\0", $padding + 1024);
}

function pngChunk(string $type, string $payload): string
{
    return pack('N', strlen($payload)) . $type . $payload . pack('N', crc32($type . $payload));
}

function webpChunk(string $type, string $payload): string
{
    return $type . pack('V', strlen($payload)) . $payload . (strlen($payload) % 2 === 1 ? "\0" : '');
}

/** @return list<string> */
function cleanupUploadContractDirectory(string $directory): array
{
    $name = basename($directory);
    $tempRoot = realpath(sys_get_temp_dir());
    $parent = realpath(dirname($directory));
    if (preg_match('/^yiyunying-upload-hardening-[a-f0-9]{12}$/D', $name) !== 1
        || $tempRoot === false || $parent === false
        || str_replace('\\', '/', $tempRoot) !== str_replace('\\', '/', $parent)) {
        return ['unsafe_contract_temp_root'];
    }
    if (!file_exists($directory) && !is_link($directory)) return [];
    if (is_link($directory)) return unlink($directory) ? [] : [$directory];
    $failures = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        $removed = $item->isLink() || $item->isFile() ? unlink($path) : rmdir($path);
        if (!$removed) $failures[] = $path;
    }
    if (!rmdir($directory)) $failures[] = $directory;
    return $failures;
}

$directory = sys_get_temp_dir() . '/yiyunying-upload-hardening-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    fwrite(STDERR, "Unable to create upload hardening test directory\n");
    exit(1);
}
$paths = [];
if (!defined('YIYUNYING_ROOT')) define('YIYUNYING_ROOT', $directory);
if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return $key === 'app.url' ? 'https://contract.invalid' : $default;
    }
}
require_once $root . '/app/Services/UploadStorageService.php';
require_once $sources['message'];
require_once $sources['submission'];
require_once $sources['sticker_controller'];
$contractFailure = null;
$cleanupFailures = [];
try {
    $pdfBody = "%PDF-1.4\n1 0 obj\n<</Type/Catalog>>\nendobj\n";
    $xrefOffset = strlen($pdfBody);
    $minimalPdf = $pdfBody . "xref\n0 2\n0000000000 65535 f \n0000000009 00000 n \n"
        . "trailer\n<</Size 2/Root 1 0 R>>\nstartxref\n{$xrefOffset}\n%%EOF\n";
    $paths[] = $validPdf = writeFixture($directory, 'valid.pdf', $minimalPdf);
    $paths[] = $truncatedPdf = writeFixture($directory, 'truncated.pdf', "%PDF-1.7\n1 0 obj\n");
    $verifiedDirectory = $directory . '/public/uploads/1';
    if (!mkdir($verifiedDirectory, 0700, true) && !is_dir($verifiedDirectory)) {
        throw new RuntimeException('Unable to create verified upload fixture directory');
    }
    $paths[] = $verifiedPdf = writeFixture($verifiedDirectory, 'verified.pdf', $minimalPdf);
    $damagedApprovalBytes = "%PDF-1.7\n1 0 obj\n";
    $paths[] = $damagedApprovalPdf = writeFixture(
        $verifiedDirectory,
        'approval-damaged.pdf',
        $damagedApprovalBytes
    );
    $paths[] = $cleanupBatchSafe = writeFixture($verifiedDirectory, 'cleanup-batch-safe.bin', 'cleanup-safe');
    $paths[] = $cleanupHardlinkSource = writeFixture(
        $verifiedDirectory,
        'cleanup-hardlink-source.bin',
        'cleanup-hardlink'
    );
    $cleanupHardlinkAlias = $verifiedDirectory . '/cleanup-hardlink-alias.bin';
    if (!@link($cleanupHardlinkSource, $cleanupHardlinkAlias)) {
        throw new RuntimeException('Unable to create hardlink cleanup fixture');
    }
    $paths[] = $cleanupHardlinkAlias;

    $emptyZip = "PK\x05\x06" . str_repeat("\x00", 18);
    $paths[] = $validZip = writeFixture($directory, 'valid.zip', $emptyZip);
    $paths[] = $truncatedZip = writeFixture($directory, 'truncated.zip', "PK\x03\x04" . str_repeat("\x00", 8));
    $entryZipBytes = storedZip('safe.txt', 'verified');
    $paths[] = $entryZip = writeFixture($directory, 'entry.zip', $entryZipBytes);
    $paths[] = $deflatedZip = writeFixture(
        $directory,
        'deflated.zip',
        storedZipEntries(['deflated.txt' => str_repeat('verified-', 512)], true)
    );
    $paths[] = $descriptorZip = writeFixture(
        $directory,
        'descriptor.zip',
        descriptorZip('descriptor.txt', str_repeat('verified-descriptor-', 128))
    );
    $corruptZipBytes = substr_replace($entryZipBytes, pack('V', 9999), 18, 4);
    $paths[] = $corruptZip = writeFixture($directory, 'corrupt-local.zip', $corruptZipBytes);
    $crcCorruptZipBytes = $entryZipBytes;
    $crcCorruptOffset = 30 + strlen('safe.txt');
    $crcCorruptZipBytes[$crcCorruptOffset] = chr(ord($crcCorruptZipBytes[$crcCorruptOffset]) ^ 0x01);
    $paths[] = $crcCorruptZip = writeFixture($directory, 'corrupt-crc.zip', $crcCorruptZipBytes);
    $localFlagMismatchBytes = substr_replace($entryZipBytes, pack('v', 1), 6, 2);
    $paths[] = $localFlagMismatchZip = writeFixture(
        $directory,
        'local-flag-mismatch.zip',
        $localFlagMismatchBytes
    );
    $eocdOffset = strlen($entryZipBytes) - 22;
    $directoryOffsetFields = unpack('Voffset', substr($entryZipBytes, $eocdOffset + 16, 4));
    if (!is_array($directoryOffsetFields)) throw new RuntimeException('Unable to locate ZIP directory fixture');
    $directoryOffset = (int) $directoryOffsetFields['offset'];
    $hiddenGapZipBytes = substr($entryZipBytes, 0, $directoryOffset) . 'JUNK'
        . substr($entryZipBytes, $directoryOffset);
    $hiddenGapZipBytes = substr_replace(
        $hiddenGapZipBytes,
        pack('V', $directoryOffset + 4),
        $eocdOffset + 4 + 16,
        4
    );
    $paths[] = $hiddenGapZip = writeFixture($directory, 'hidden-gap.zip', $hiddenGapZipBytes);
    $hiddenTailZipBytes = substr($entryZipBytes, 0, $eocdOffset) . 'JUNK'
        . substr($entryZipBytes, $eocdOffset);
    $paths[] = $hiddenTailZip = writeFixture($directory, 'hidden-tail.zip', $hiddenTailZipBytes);

    $packageBase = ['[Content_Types].xml' => '<Types/>', '_rels/.rels' => '<Relationships/>'];
    $paths[] = $validDocx = writeFixture(
        $directory,
        'valid.docx',
        storedZipEntries($packageBase + ['word/document.xml' => '<document/>'], true)
    );
    $paths[] = $missingDocxEntry = writeFixture(
        $directory,
        'missing-document.docx',
        storedZipEntries($packageBase, true)
    );
    $paths[] = $validOdt = writeFixture(
        $directory,
        'valid.odt',
        storedZipEntries([
            'mimetype' => 'application/vnd.oasis.opendocument.text',
            'META-INF/manifest.xml' => '<manifest/>',
            'content.xml' => '<document-content/>',
        ], true)
    );
    $paths[] = $missingOdtEntry = writeFixture(
        $directory,
        'missing-content.odt',
        storedZipEntries([
            'mimetype' => 'application/vnd.oasis.opendocument.text',
            'META-INF/manifest.xml' => '<manifest/>',
        ], true)
    );
    $paths[] = $validApk = writeFixture(
        $directory,
        'valid.apk',
        storedZipEntries(['AndroidManifest.xml' => 'binary-manifest', 'classes.dex' => 'dex\n035\0'], true)
    );
    $paths[] = $missingApkPayload = writeFixture(
        $directory,
        'missing-code.apk',
        storedZipEntries(['AndroidManifest.xml' => 'binary-manifest'], true)
    );

    $gzipBytes = gzencode(str_repeat('verified-gzip-', 64), 6, ZLIB_ENCODING_GZIP);
    if (!is_string($gzipBytes)) throw new RuntimeException('Unable to build gzip fixture');
    $paths[] = $validGzip = writeFixture($directory, 'valid.gz', $gzipBytes);
    $corruptGzipBytes = $gzipBytes;
    $corruptGzipBytes[strlen($corruptGzipBytes) - 8] = chr(ord($corruptGzipBytes[strlen($corruptGzipBytes) - 8]) ^ 0x01);
    $paths[] = $corruptGzip = writeFixture($directory, 'corrupt.gz', $corruptGzipBytes);

    $tarBytes = tarFixture('safe.txt', 'verified tar payload');
    $paths[] = $validTar = writeFixture($directory, 'valid.tar', $tarBytes);
    $corruptTarBytes = $tarBytes;
    $corruptTarBytes[0] = $corruptTarBytes[0] === 's' ? 't' : 's';
    $paths[] = $corruptTar = writeFixture($directory, 'corrupt.tar', $corruptTarBytes);

    $paths[] = $validRtf = writeFixture($directory, 'valid.rtf', "{\\rtf1\\ansi verified {\\b payload}}");
    $paths[] = $unbalancedRtf = writeFixture($directory, 'unbalanced.rtf', "{\\rtf1\\ansi {broken}");

    $unsupportedFixtures = [
        '7z' => "7z\xBC\xAF\x27\x1C" . str_repeat("\0", 32),
        'rar' => "Rar!\x1A\x07\x01\x00" . str_repeat("\0", 32),
        'bz2' => 'BZh9' . str_repeat("\0", 32),
        'xz' => "\xFD7zXZ\x00" . str_repeat("\0", 32),
        'doc' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 512),
    ];
    $unsupportedPaths = [];
    foreach ($unsupportedFixtures as $extension => $bytes) {
        $paths[] = $unsupportedPaths[$extension] = writeFixture($directory, 'unsupported.' . $extension, $bytes);
    }

    $waveFormat = pack('vvVVvv', 1, 1, 8000, 16000, 2, 16);
    $waveData = "\x00\x00";
    $wave = 'RIFF' . pack('V', 4 + 8 + strlen($waveFormat) + 8 + strlen($waveData)) . 'WAVE'
        . 'fmt ' . pack('V', strlen($waveFormat)) . $waveFormat
        . 'data' . pack('V', strlen($waveData)) . $waveData;
    $paths[] = $validWave = writeFixture($directory, 'valid.wav', $wave);
    $paths[] = $truncatedWave = writeFixture($directory, 'truncated.wav', substr($wave, 0, -1));

    $oggPayload = "\x01vorbis";
    $ogg = 'OggS' . "\x00\x06" . pack('V2', 0, 0) . pack('V', 1) . pack('V', 0)
        . pack('V', 0) . "\x01" . chr(strlen($oggPayload)) . $oggPayload;
    $paths[] = $validOgg = writeFixture($directory, 'valid.ogg', $ogg);
    $paths[] = $truncatedOgg = writeFixture($directory, 'truncated.ogg', substr($ogg, 0, -1));

    $ftyp = isoBox('ftyp', 'isom' . pack('N', 0) . 'isommp42');
    $structuralMp4 = $ftyp . isoBox('moov', '') . isoBox('mdat', "\x00");
    $paths[] = $probeRejectedMp4 = writeFixture($directory, 'probe-rejected.mp4', $structuralMp4);
    $paths[] = $verifiedOptimizedVideo = writeFixture(
        $verifiedDirectory,
        'verified-optimized.mp4',
        $structuralMp4
    );
    $paths[] = $truncatedMp4 = writeFixture($directory, 'truncated.mp4', $ftyp . pack('N', 100) . 'moov');
    $paths[] = $probeRejectedM4a = writeFixture($directory, 'video-only.m4a', $structuralMp4);
    $paths[] = $truncatedMp3 = writeFixture($directory, 'truncated.mp3', 'ID3' . str_repeat("\0", 12));
    $paths[] = $truncatedAac = writeFixture($directory, 'truncated.aac', "\xFF\xF1" . str_repeat("\0", 12));
    $paths[] = $truncatedFlac = writeFixture($directory, 'truncated.flac', 'fLaC' . str_repeat("\0", 12));

    $paths[] = $animatedWebp = writeFixture($directory, 'animated.webp', base64_decode(
        'UklGRoQAAABXRUJQVlA4WAoAAAACAAAAAQAAAQAAQU5JTQYAAAAAAAAAAABBTk1GKAAAAAAAAAAAAAEAAAEAAGQAAA'
        . 'JWUDhMDwAAAC8BQAAABxD9j/4HIqL/AQBBTk1GKAAAAAAAAAAAAAEAAAEAAGQAAABWUDhMDwAAAC8BQAAABxDR//4H'
        . 'IqL/AQA=',
        true
    ));
    $maliciousWebpBody = webpChunk(
        'VP8X',
        "\x02\0\0\0" . "\x01\0\0" . "\x01\0\0"
    ) . webpChunk('ANIM', str_repeat("\0", 6))
        . webpChunk('ANMF', str_repeat("\0", 16) . webpChunk('VP8 ', "\0\0"));
    $paths[] = $maliciousAnimatedWebp = writeFixture(
        $directory,
        'malicious-animated.webp',
        'RIFF' . pack('V', 4 + strlen($maliciousWebpBody)) . 'WEBP' . $maliciousWebpBody
    );
    $validPngBytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true
    );
    if (!is_string($validPngBytes)) throw new RuntimeException('Unable to decode static PNG fixture');
    $paths[] = $validPng = writeFixture($directory, 'valid.png', $validPngBytes);
    $iendOffset = strrpos($validPngBytes, "\0\0\0\0IEND");
    if ($iendOffset === false) throw new RuntimeException('Static PNG fixture has no IEND chunk');
    $apngWithoutFramesBytes = substr($validPngBytes, 0, $iendOffset)
        . pngChunk('acTL', pack('NN', 2, 0))
        . substr($validPngBytes, $iendOffset);
    $paths[] = $apngWithoutFrames = writeFixture(
        $directory,
        'actl-without-frames.png',
        $apngWithoutFramesBytes
    );
    $staticGifBytes = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);
    if (!is_string($staticGifBytes) || !str_ends_with($staticGifBytes, ';')) {
        throw new RuntimeException('Unable to decode static GIF fixture');
    }
    $paths[] = $validStaticGif = writeFixture($directory, 'valid-static.gif', $staticGifBytes);
    $invalidSecondFrame = "\x2C" . pack('vvvvC', 0, 0, 1, 1, 0)
        . "\x02\x02\xFF\xFF\x00";
    $paths[] = $maliciousAnimatedGif = writeFixture(
        $directory,
        'invalid-second-frame.gif',
        substr($staticGifBytes, 0, -1) . $invalidSecondFrame . ';'
    );
    $vp8xPayload = "\0" . str_repeat("\0", 3) . str_repeat("\0", 6);
    $vp8xOnlyBytes = 'RIFF' . pack('V', 4 + 8 + strlen($vp8xPayload)) . 'WEBP'
        . 'VP8X' . pack('V', strlen($vp8xPayload)) . $vp8xPayload;
    $paths[] = $vp8xOnly = writeFixture($directory, 'vp8x-only.webp', $vp8xOnlyBytes);

    $largePngBytes = "\x89PNG\x0D\x0A\x1A\x0A"
        . pngChunk('IHDR', pack('NNCCCCC', 4000, 3000, 8, 6, 0, 0, 0))
        . pngChunk('IDAT', gzcompress("\0", 6))
        . pngChunk('IEND', '');
    $paths[] = $memoryBudgetPng = writeFixture($directory, 'memory-budget.png', $largePngBytes);

    $oversizedJson = $directory . '/oversized.json';
    $handle = fopen($oversizedJson, 'w+b');
    if ($handle === false || fwrite($handle, '{"value":') !== 9
        || !ftruncate($handle, \Yiyunying\Services\MediaOptimizationService::MAX_JSON_BYTES + 1)) {
        throw new RuntimeException('Unable to create sparse oversized JSON fixture');
    }
    fclose($handle);
    $paths[] = $oversizedJson;

    $videoAsJson = $directory . '/video-as-json.json';
    $handle = fopen($videoAsJson, 'w+b');
    if ($handle === false || fwrite($handle, $structuralMp4) !== strlen($structuralMp4)
        || !ftruncate($handle, \Yiyunying\Services\MediaOptimizationService::MAX_JSON_BYTES + 1)) {
        throw new RuntimeException('Unable to create sparse video-as-JSON fixture');
    }
    fclose($handle);
    $paths[] = $videoAsJson;

    $limits = [
        'json' => \Yiyunying\Services\MediaOptimizationService::MAX_JSON_BYTES,
        'image' => 100 * 1048576,
        'video' => 1024 * 1048576,
        'audio' => 100 * 1048576,
        'file' => 512 * 1048576,
    ];
    $oversizedSize = (int) filesize($oversizedJson);
    $jsonVideoMime = ['name' => 'oversized.json', 'type' => 'video/mp4', 'tmp_name' => $oversizedJson, 'size' => $oversizedSize];
    $jsonTextMime = $jsonVideoMime;
    $jsonTextMime['type'] = 'application/json';
    $videoJsonSize = (int) filesize($videoAsJson);
    $videoAsJsonFile = ['name' => 'video-as-json.json', 'type' => 'video/mp4', 'tmp_name' => $videoAsJson, 'size' => $videoJsonSize];
    $sizeMismatch = $jsonVideoMime;
    $sizeMismatch['size'] = 1;
    $jsonVideoResult = \Yiyunying\Services\UploadLimitService::evaluate($jsonVideoMime, $limits);
    $jsonTextResult = \Yiyunying\Services\UploadLimitService::evaluate($jsonTextMime, $limits);
    $videoAsJsonResult = \Yiyunying\Services\UploadLimitService::evaluate($videoAsJsonFile, $limits);
    $sizeMismatchResult = \Yiyunying\Services\UploadLimitService::evaluate($sizeMismatch, $limits);

    $validResults = [
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($validPdf, 'pdf'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($validZip, 'zip'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($entryZip, 'zip'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($deflatedZip, 'zip'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($descriptorZip, 'zip'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($validDocx, 'docx'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($validOdt, 'odt'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($validApk, 'apk'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($validGzip, 'gz'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($validTar, 'tar'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($validRtf, 'rtf'),
    ];
    $truncatedResults = [
        'invalid_pdf_structure' => \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($truncatedPdf, 'pdf'),
        'invalid_zip_structure' => \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($truncatedZip, 'zip'),
        'invalid_wav_structure' => \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($truncatedWave, 'wav'),
        'invalid_ogg_structure' => \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($truncatedOgg, 'ogg'),
        'invalid_iso_media_structure' => \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($truncatedMp4, 'mp4'),
    ];
    $probeRejected = \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($probeRejectedMp4, 'mp4');
    $probeRejectedM4aResult = \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($probeRejectedM4a, 'm4a');
    $audioProbeRejected = [
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($validWave, 'wav'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($validOgg, 'ogg'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($truncatedMp3, 'mp3'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($truncatedAac, 'aac'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($truncatedFlac, 'flac'),
    ];
    $corruptZipResult = \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($corruptZip, 'zip');
    $crcCorruptZipResult = \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($crcCorruptZip, 'zip');
    $malformedZipResults = [
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($localFlagMismatchZip, 'zip'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($hiddenGapZip, 'zip'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($hiddenTailZip, 'zip'),
    ];
    $missingPackageEntryResults = [
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($missingDocxEntry, 'docx'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($missingOdtEntry, 'odt'),
        \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($missingApkPayload, 'apk'),
    ];
    $corruptContainerResults = [
        'invalid_gzip_structure' => \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($corruptGzip, 'gz'),
        'invalid_tar_structure' => \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($corruptTar, 'tar'),
        'invalid_rtf_structure' => \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($unbalancedRtf, 'rtf'),
        'invalid_webp_structure' => \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($vp8xOnly, 'webp'),
    ];
    $unsupportedResults = [];
    foreach ($unsupportedPaths as $extension => $path) {
        $unsupportedResults[$extension] = \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($path, $extension);
    }
    $oversizedInspection = \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($oversizedJson, 'json');
    $animatedInspection = \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($animatedWebp, 'webp');
    $maliciousAnimatedInspection = \Yiyunying\Services\MediaOptimizationService::inspectClientUpload(
        $maliciousAnimatedWebp,
        'webp'
    );
    $pngInspection = \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($validPng, 'png');
    $apngInspection = \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($apngWithoutFrames, 'png');
    $staticGifInspection = \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($validStaticGif, 'gif');
    $animatedGifInspection = \Yiyunying\Services\MediaOptimizationService::inspectClientUpload(
        $maliciousAnimatedGif,
        'gif'
    );
    $previousMemoryLimit = (string) ini_get('memory_limit');
    $memoryLimitChange = @ini_set('memory_limit', '64M');
    if ($memoryLimitChange === false || (string) ini_get('memory_limit') !== '64M') {
        throw new RuntimeException('Unable to set deterministic 64M image decoder memory limit');
    }
    try {
        $memoryBudgetInspection = \Yiyunying\Services\MediaOptimizationService::inspectClientUpload($memoryBudgetPng, 'png');
    } finally {
        @ini_set('memory_limit', $previousMemoryLimit);
    }
    $verifiedRelative = 'uploads/1/verified.pdf';
    $verifiedUrl = 'https://contract.invalid/' . $verifiedRelative;
    $verifiedRow = [
        'id' => 1, 'admin_id' => 1, 'app_id' => 1, 'user_id' => 1, 'scene' => 'message',
        'original_name' => 'verified.pdf', 'stored_name' => 'verified.pdf',
        'file_path' => $verifiedRelative, 'file_url' => $verifiedUrl,
        'mime_type' => 'application/pdf', 'size_bytes' => strlen($minimalPdf),
        'original_size_bytes' => strlen($minimalPdf), 'optimized_size_bytes' => 0,
        'upload_mode' => 'original', 'optimization_status' => 'not_required',
        'original_file_url' => $verifiedUrl, 'optimized_file_url' => '', 'thumbnail_url' => '',
        'is_animated' => 0, 'sha256' => hash('sha256', $minimalPdf), 'status' => 1,
    ];
    $staleDomainRow = $verifiedRow;
    $staleDomainRow['file_url'] = 'https://retired.invalid/' . $verifiedRelative;
    $staleDomainRow['original_file_url'] = $staleDomainRow['file_url'];
    $staleDomainValidation = \Yiyunying\Services\UploadStorageService::validatedPublicUpload($staleDomainRow);
    $hydrateAttachmentRow = [
        'id' => 501, 'admin_id' => 1, 'app_id' => 1, 'owner_user_id' => 1, 'target_id' => 77,
        'media_type' => 'file', 'upload_id' => 1, 'sticker_id' => null,
        'url' => 'https://evil.invalid/stale.pdf', 'stored_thumbnail_url' => '', 'thumbnail_url' => '',
        'file_name' => 'private-client-name.pdf', 'mime_type' => 'application/pdf',
        'size_bytes' => strlen($minimalPdf), 'width' => 0, 'height' => 0, 'duration_ms' => 0,
        'sort_order' => 0, 'metadata_json' => '{}', 'upload_sha256' => hash('sha256', $minimalPdf),
        'original_file_url' => $staleDomainRow['file_url'], 'optimized_file_url' => $staleDomainRow['file_url'],
        'original_size_bytes' => strlen($minimalPdf), 'is_animated' => 0, 'upload_mode' => 'original',
        'optimization_status' => 'not_required', 'upload_file_path' => $verifiedRelative,
        'verified_upload_id' => 1, 'upload_status' => 1,
        'canonical_upload_url' => $staleDomainRow['file_url'], 'canonical_upload_thumbnail_url' => '',
        'canonical_original_file_url' => $staleDomainRow['file_url'], 'canonical_optimized_file_url' => '',
        'verified_sticker_id' => null, 'verified_sticker_pack_id' => null, 'sticker_upload_id' => null,
        'canonical_sticker_url' => '', 'canonical_sticker_thumbnail_url' => '',
    ];
    \Yiyunying\Core\Database::$allHandler = static function (string $sql, array $params) use (
        $hydrateAttachmentRow,
        $staleDomainRow
    ): array {
        if (str_contains($sql, 'FROM media_attachments ma') && str_contains($sql, 'LEFT JOIN uploads up')) {
            return [$hydrateAttachmentRow];
        }
        if (str_contains($sql, 'SELECT * FROM uploads WHERE id IN')) return [$staleDomainRow];
        return [];
    };
    try {
        $staleDomainHydrated = \Yiyunying\Services\MessageMediaService::hydrate(
            [['id' => 77]],
            'resource',
            1
        );
    } finally {
        \Yiyunying\Core\Database::$allHandler = null;
    }
    $staleDomainHydratedUrl = (string) ($staleDomainHydrated[0]['attachments'][0]['url'] ?? '');

    $optimizedVideoRelative = 'uploads/1/verified-optimized.mp4';
    $optimizedVideoUrl = 'https://contract.invalid/' . $optimizedVideoRelative;
    $optimizedVideoRetiredUrl = 'https://retired.invalid/' . $optimizedVideoRelative;
    $optimizedVideoSize = strlen($structuralMp4);
    $optimizedVideoRow = [
        'id' => 3, 'admin_id' => 1, 'app_id' => 1, 'user_id' => 1, 'scene' => 'message',
        'original_name' => 'verified-optimized.mp4', 'stored_name' => 'verified-optimized.mp4',
        'file_path' => $optimizedVideoRelative, 'file_url' => $optimizedVideoRetiredUrl,
        'mime_type' => 'video/mp4', 'size_bytes' => $optimizedVideoSize,
        'original_size_bytes' => $optimizedVideoSize + 1024, 'optimized_size_bytes' => $optimizedVideoSize,
        'upload_mode' => 'optimized', 'optimization_status' => 'optimized',
        'original_file_url' => '', 'optimized_file_url' => $optimizedVideoRetiredUrl, 'thumbnail_url' => '',
        'is_animated' => 0, 'sha256' => hash('sha256', $structuralMp4), 'status' => 1,
    ];
    $optimizedVideoFixture = realpath($verifiedOptimizedVideo);
    if (!is_string($optimizedVideoFixture) || $optimizedVideoFixture === ''
        || !putenv('YIYUNYING_UPLOAD_CONTRACT_VIDEO=' . $optimizedVideoFixture)) {
        throw new RuntimeException('Unable to register optimized-video probe fixture');
    }
    $optimizedVideoValidation = [];
    $optimizedVideoHydrated = [];
    $optimizedVideoApprovalAccepted = false;
    try {
        $optimizedVideoValidation = \Yiyunying\Services\UploadStorageService::validatedPublicUpload(
            $optimizedVideoRow
        );
        $optimizedVideoHydrateRow = [
            'id' => 503, 'admin_id' => 1, 'app_id' => 1, 'owner_user_id' => 1, 'target_id' => 79,
            'media_type' => 'video', 'upload_id' => 3, 'sticker_id' => null,
            'url' => $optimizedVideoRetiredUrl, 'stored_thumbnail_url' => '', 'thumbnail_url' => '',
            'file_name' => 'client-video.mp4', 'mime_type' => 'video/mp4',
            'size_bytes' => $optimizedVideoSize, 'width' => 0, 'height' => 0, 'duration_ms' => 0,
            'sort_order' => 0, 'metadata_json' => '{}', 'upload_sha256' => hash('sha256', $structuralMp4),
            'original_file_url' => '', 'optimized_file_url' => $optimizedVideoRetiredUrl,
            'original_size_bytes' => $optimizedVideoSize + 1024, 'is_animated' => 0,
            'upload_mode' => 'optimized', 'optimization_status' => 'optimized',
            'upload_file_path' => $optimizedVideoRelative, 'verified_upload_id' => 3, 'upload_status' => 1,
            'canonical_upload_url' => $optimizedVideoRetiredUrl, 'canonical_upload_thumbnail_url' => '',
            'canonical_original_file_url' => '', 'canonical_optimized_file_url' => $optimizedVideoRetiredUrl,
            'verified_sticker_id' => null, 'verified_sticker_pack_id' => null, 'sticker_upload_id' => null,
            'canonical_sticker_url' => '', 'canonical_sticker_thumbnail_url' => '',
        ];
        \Yiyunying\Core\Database::$allHandler = static function (string $sql, array $params) use (
            $optimizedVideoHydrateRow,
            $optimizedVideoRow
        ): array {
            if (str_contains($sql, 'FROM media_attachments ma') && str_contains($sql, 'LEFT JOIN uploads up')) {
                return [$optimizedVideoHydrateRow];
            }
            if (str_contains($sql, 'SELECT * FROM uploads WHERE id IN')) return [$optimizedVideoRow];
            return [];
        };
        $optimizedVideoHydrated = \Yiyunying\Services\MessageMediaService::hydrate(
            [['id' => 79]],
            'resource',
            1
        );
        \Yiyunying\Core\Database::$allHandler = static function (string $sql, array $params) use (
            $optimizedVideoRow
        ): array {
            if (str_contains($sql, 'SELECT id, upload_id, sticker_id FROM media_attachments')) {
                return [['id' => 903, 'upload_id' => 3, 'sticker_id' => null]];
            }
            if (str_contains($sql, 'ma.upload_id IS NOT NULL AND ma.sticker_id IS NULL')) {
                return [['attachment_reference_id' => 903] + $optimizedVideoRow];
            }
            if (str_contains($sql, 'INNER JOIN stickers s')) return [];
            return [];
        };
        $optimizedVideoApproval = \Yiyunying\Services\MessageMediaService::prevalidateStoredPublicAttachmentTrust(
            'resource',
            90,
            1,
            1
        );
        $optimizedVideoApprovalToken = $optimizedVideoApproval['direct_validations'][903] ?? null;
        if (!is_array($optimizedVideoApprovalToken)) {
            throw new RuntimeException('Optimized-video approval prevalidation token missing');
        }
        \Yiyunying\Services\UploadStorageService::assertLockedPublicUpload(
            ['attachment_reference_id' => 903] + $optimizedVideoRow,
            $optimizedVideoApprovalToken
        );
        $optimizedVideoApprovalAccepted = true;
    } finally {
        \Yiyunying\Core\Database::$allHandler = null;
        putenv('YIYUNYING_UPLOAD_CONTRACT_VIDEO');
    }

    $damagedApprovalRow = $verifiedRow;
    $damagedApprovalRow['id'] = 2;
    $damagedApprovalRow['stored_name'] = 'approval-damaged.pdf';
    $damagedApprovalRow['file_path'] = 'uploads/1/approval-damaged.pdf';
    $damagedApprovalRow['file_url'] = 'https://contract.invalid/uploads/1/approval-damaged.pdf';
    $damagedApprovalRow['original_file_url'] = $damagedApprovalRow['file_url'];
    $damagedApprovalRow['size_bytes'] = strlen($damagedApprovalBytes);
    $damagedApprovalRow['original_size_bytes'] = strlen($damagedApprovalBytes);
    $damagedApprovalRow['sha256'] = hash('sha256', $damagedApprovalBytes);
    $damagedApprovalRow['attachment_reference_id'] = 902;
    \Yiyunying\Core\Database::$allHandler = static function (string $sql, array $params) use (
        $damagedApprovalRow
    ): array {
        if (str_contains($sql, 'SELECT id, upload_id, sticker_id FROM media_attachments')) {
            return [['id' => 902, 'upload_id' => 2, 'sticker_id' => null]];
        }
        if (str_contains($sql, 'ma.upload_id IS NOT NULL AND ma.sticker_id IS NULL')) {
            return [$damagedApprovalRow];
        }
        if (str_contains($sql, 'INNER JOIN stickers s')) return [];
        return [];
    };
    $damagedDirectApprovalRejected = false;
    try {
        \Yiyunying\Services\MessageMediaService::prevalidateStoredPublicAttachmentTrust(
            'resource',
            88,
            1,
            1
        );
    } catch (Throwable) {
        $damagedDirectApprovalRejected = true;
    } finally {
        \Yiyunying\Core\Database::$allHandler = null;
    }
    $reuseVerifier = new ReflectionMethod(\Yiyunying\Services\UploadStorageService::class, 'verifiedReusableUpload');
    $reuseVerifier->setAccessible(true);
    $dedupeSelectionMethod = new ReflectionMethod(
        \Yiyunying\Services\UploadStorageService::class,
        'selectReusableUploadCandidates'
    );
    $dedupeSelectionMethod->setAccessible(true);
    $sharedRows = [];
    for ($rowIndex = 1; $rowIndex <= 61; $rowIndex++) {
        $shared = $verifiedRow;
        $shared['id'] = $rowIndex;
        $shared['user_id'] = $rowIndex === 61 ? 1 : 2;
        $shared['original_name'] = $rowIndex === 61 ? 'verified.pdf' : 'other-' . $rowIndex . '.pdf';
        $sharedRows[] = $shared;
    }
    $dedupeSelection = $dedupeSelectionMethod->invoke(
        null,
        $sharedRows,
        ['user_id' => 1, 'scene' => 'message', 'original_name' => 'verified.pdf'],
        microtime(true) + 10.0
    );
    $cleanupMethod = new ReflectionMethod(\Yiyunying\Services\UploadStorageService::class, 'cleanupCreatedFiles');
    $cleanupMethod->setAccessible(true);
    $hardlinkCleanupRejected = false;
    try {
        // array_reverse processes the hardlink first; aggregate cleanup must
        // still remove the following safe file before it reports failure.
        $cleanupMethod->invoke(null, [$cleanupBatchSafe, $cleanupHardlinkAlias]);
    } catch (ReflectionException $exception) {
        throw $exception;
    } catch (Throwable) {
        $hardlinkCleanupRejected = true;
    }
    $cleanupSafeRemoved = !file_exists($cleanupBatchSafe) && !is_link($cleanupBatchSafe);
    $hardlinkCleanupPreserved = is_file($cleanupHardlinkSource) && is_file($cleanupHardlinkAlias);
    $canonicalDirectoryMethod = new ReflectionMethod(
        \Yiyunying\Services\UploadStorageService::class,
        'canonicalUploadDirectory'
    );
    $canonicalDirectoryMethod->setAccessible(true);
    $canonicalDirectory = (string) $canonicalDirectoryMethod->invoke(
        null,
        $directory . '/public/',
        'uploads/1'
    );
    $canonicalDirectoryValid = realpath($verifiedDirectory) !== false
        && str_replace('\\', '/', $canonicalDirectory)
            === str_replace('\\', '/', (string) realpath($verifiedDirectory));
    $emptyPublicUrlRow = $verifiedRow;
    $emptyPublicUrlRow['file_url'] = '';
    $unexpectedThumbnailRow = $verifiedRow;
    $unexpectedThumbnailRow['thumbnail_url'] = $verifiedUrl;
    $fatalStatusRow = $verifiedRow;
    $fatalStatusRow['upload_mode'] = 'optimized';
    $fatalStatusRow['optimization_status'] = 'decode_failed';

    \Yiyunying\Core\Database::$uploadRows = [$verifiedRow];
    \Yiyunying\Core\Database::$allCalls = 0;
    $sameUploadAttachments = array_fill(0, 200, ['upload_id' => 1, 'media_type' => 'file']);
    $sameUploadPayload = \Yiyunying\Services\MessageMediaService::userPayload(
        ['id' => 1, 'admin_id' => 1, 'app_id' => 1],
        ['attachments' => $sameUploadAttachments]
    );
    $sameUploadDatabaseCalls = \Yiyunying\Core\Database::$allCalls;
    \Yiyunying\Core\Database::$uploadRows = [];

    $processRunner = new ReflectionMethod(\Yiyunying\Services\MediaOptimizationService::class, 'runProcess');
    $processRunner->setAccessible(true);
    $successProcess = $processRunner->invoke(null, [PHP_BINARY, '-r', 'fwrite(STDOUT, "ok");'], 5.0, 1024);
    $timeoutStarted = microtime(true);
    $timeoutProcess = $processRunner->invoke(null, [PHP_BINARY, '-r', 'usleep(900000);'], 0.15, 1024);
    $timeoutElapsed = microtime(true) - $timeoutStarted;
    $limitedProcess = $processRunner->invoke(
        null,
        [PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("x", 8192)); usleep(900000);'],
        5.0,
        512
    );
    $videoMethod = new ReflectionMethod(\Yiyunying\Services\MediaOptimizationService::class, 'video');
    $videoMethod->setAccessible(true);
    $trustedVideoInspection = [
        'accepted' => true, 'reason' => 'trusted_content', 'kind' => 'iso_media',
        'mime_type' => 'video/mp4', 'width' => 16, 'height' => 16,
        'duration_ms' => 1000, 'is_animated' => false,
    ];
    $videoFallback = $videoMethod->invoke(null, $probeRejectedMp4, 'video/mp4', $trustedVideoInspection);
    $probeSemanticsMethod = new ReflectionMethod(
        \Yiyunying\Services\MediaOptimizationService::class,
        'probeSemanticsMatch'
    );
    $probeSemanticsMethod->setAccessible(true);
    $probeSemanticPositive = $probeSemanticsMethod->invoke(
        null,
        'mp4',
        'video',
        'mov,mp4,m4a,3gp,3g2,mj2',
        'h264'
    );
    $probeSemanticNegative = $probeSemanticsMethod->invoke(
        null,
        'mp4',
        'video',
        'mp3',
        'mp3'
    );

    $cacheMethod = new ReflectionMethod(\Yiyunying\Services\MessageMediaService::class, 'cacheTrustedUploadMetadata');
    $cacheMethod->setAccessible(true);
    $validationCalls = 0;
    $duplicateUploadRows = array_fill(0, 200, ['id' => 77, 'file_path' => 'uploads/verified.bin']);
    $duplicateCache = $cacheMethod->invoke(
        null,
        $duplicateUploadRows,
        static function (array $row) use (&$validationCalls): array {
            $validationCalls++;
            return ['width' => 10, 'height' => 20, 'duration_ms' => 30, 'is_animated' => false];
        }
    );
    $externalAudioPayload = \Yiyunying\Services\MessageMediaService::userPayload(
        ['id' => 1, 'admin_id' => 1, 'app_id' => 1],
        ['attachments' => [[
            'media_type' => 'audio',
            'url' => 'https://untrusted.invalid/audio.mp3',
            'mime_type' => 'audio/mpeg',
            'size_bytes' => 999,
            'width' => 999,
            'height' => 999,
            'duration_ms' => 999,
            'metadata' => ['audio_kind' => 'voice'],
        ]]]
    );
    $externalAttachment = $externalAudioPayload['attachments'][0] ?? [];
    $publicTrustMethod = new ReflectionMethod(\Yiyunying\Services\MessageMediaService::class, 'assertPublicAttachmentTrust');
    $publicTrustMethod->setAccessible(true);
    $publicExternalRejected = false;
    try {
        $publicTrustMethod->invoke(null, 'forum_post', $externalAudioPayload);
    } catch (ReflectionException $exception) {
        throw $exception;
    } catch (Throwable) {
        $publicExternalRejected = true;
    }
    $resourceExternalRejected = false;
    $storeExternalRejected = false;
    foreach (['resource' => &$resourceExternalRejected, 'store_app' => &$storeExternalRejected] as $target => &$rejected) {
        try {
            $publicTrustMethod->invoke(null, $target, $externalAudioPayload);
        } catch (ReflectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            $rejected = true;
        }
    }
    unset($rejected);
    $canonicalizeMethod = new ReflectionMethod(
        \Yiyunying\Services\MessageMediaService::class,
        'canonicalizePublicHydrationRow'
    );
    $canonicalizeMethod->setAccessible(true);
    $canonicalPublicRow = [
        'upload_id' => 9, 'sticker_id' => null, 'verified_upload_id' => 9, 'upload_status' => 1,
        'admin_id' => 1, 'app_id' => 1, 'owner_user_id' => 1,
        'upload_file_path' => 'uploads/1/canonical.pdf', 'url' => 'https://evil.invalid/stale',
        'canonical_upload_url' => 'https://contract.invalid/uploads/1/canonical.pdf',
        'canonical_upload_thumbnail_url' => '', 'canonical_original_file_url' => '',
        'canonical_optimized_file_url' => '',
    ];
    $canonicalLiveUpload = [
        'id' => 9, 'admin_id' => 1, 'app_id' => 1, 'user_id' => 1,
        'file_path' => 'uploads/1/canonical.pdf', 'mime_type' => 'application/pdf',
        'size_bytes' => 42, 'sha256' => str_repeat('a', 64),
    ];
    $canonicalArgs = [
        'resource', &$canonicalPublicRow,
        [9 => $canonicalLiveUpload],
        [9 => ['width' => 0, 'height' => 0, 'duration_ms' => 0, 'is_animated' => false]],
    ];
    $canonicalPublicAccepted = $canonicalizeMethod->invokeArgs(null, $canonicalArgs);
    $legacyExternalRow = ['upload_id' => null, 'sticker_id' => null, 'url' => 'https://evil.invalid/image.png'];
    $legacyArgs = ['store_app', &$legacyExternalRow];
    $legacyPublicRejected = !$canonicalizeMethod->invokeArgs(null, $legacyArgs);
    $missingPathRow = [
        'upload_id' => 10, 'sticker_id' => null, 'verified_upload_id' => 10, 'upload_status' => 1,
        'upload_file_path' => '', 'canonical_upload_url' => 'https://evil.invalid/upload.pdf',
    ];
    $missingPathArgs = ['resource', &$missingPathRow];
    $missingPathRejected = !$canonicalizeMethod->invokeArgs(null, $missingPathArgs);
    $traversalPathRow = [
        'upload_id' => 11, 'sticker_id' => null, 'verified_upload_id' => 11, 'upload_status' => 1,
        'upload_file_path' => 'uploads/1/../escape.pdf',
        'canonical_upload_url' => 'https://evil.invalid/escape.pdf',
    ];
    $traversalPathArgs = ['forum_post', &$traversalPathRow];
    $traversalPathRejected = !$canonicalizeMethod->invokeArgs(null, $traversalPathArgs);
    $firstUploadIdMethod = new ReflectionMethod(
        \Yiyunying\Services\SubmissionInspectionService::class,
        'firstInt'
    );
    $firstUploadIdMethod->setAccessible(true);
    $matchingAliasId = $firstUploadIdMethod->invoke(
        null,
        ['source_upload_id' => 7, 'upload_id' => 7],
        ['source_upload_id', 'upload_id']
    );
    $conflictingAliasRejected = false;
    try {
        $firstUploadIdMethod->invoke(
            null,
            ['source_upload_id' => 7, 'upload_id' => 8],
            ['source_upload_id', 'upload_id']
        );
    } catch (ReflectionException $exception) {
        throw $exception;
    } catch (Throwable) {
        $conflictingAliasRejected = true;
    }
    $legacyCatalogPresentation = \Yiyunying\Services\SubmissionInspectionService::present([
        'id' => 13,
        'cover_url' => 'https://evil.invalid/cover.png',
        'cover_upload_id' => null,
        'icon_url' => 'https://evil.invalid/icon.png',
        'icon_upload_id' => null,
        'download_url' => 'https://evil.invalid/archive.zip',
        'apk_url' => 'https://evil.invalid/app.apk',
        'metadata_json' => '{}',
    ]);
    $stickerPayloadMethod = new ReflectionMethod(
        \Yiyunying\Controllers\User\StickerController::class,
        'stickerPayload'
    );
    $stickerPayloadMethod->setAccessible(true);
    $urlOnlyStickerRejected = false;
    try {
        $stickerPayloadMethod->invoke(null, ['image_url' => 'https://evil.invalid/sticker.png'], []);
    } catch (ReflectionException $exception) {
        throw $exception;
    } catch (Throwable) {
        $urlOnlyStickerRejected = true;
    }
    $canonicalStickerPayload = $stickerPayloadMethod->invoke(
        null,
        [
            'upload_id' => 91,
            'image_url' => 'https://evil.invalid/sticker.png',
            'thumbnail_url' => 'https://evil.invalid/thumb.png',
            'width' => 999,
            'height' => 999,
            'name' => 'verified',
        ],
        [91 => [
            'file_url' => 'https://contract.invalid/uploads/1/verified.png',
            'thumbnail_url' => 'https://contract.invalid/uploads/1/verified.png',
            'width' => 1,
            'height' => 1,
        ]]
    );

    $dynamicChecks = [
        !in_array(false, array_map(static fn(array $result): bool => ($result['accepted'] ?? false) === true, $validResults), true),
        !in_array(false, array_map(
            static fn(string $reason, array $result): bool => ($result['accepted'] ?? true) === false
                && ($result['reason'] ?? '') === $reason,
            array_keys($truncatedResults),
            array_values($truncatedResults)
        ), true),
        ($probeRejected['accepted'] ?? true) === false
            && in_array($probeRejected['reason'] ?? '', ['video_probe_unavailable', 'video_probe_failed'], true),
        ($probeRejectedM4aResult['accepted'] ?? true) === false
            && in_array($probeRejectedM4aResult['reason'] ?? '', ['audio_probe_unavailable', 'audio_probe_failed'], true),
        !in_array(false, array_map(
            static fn(array $result): bool => ($result['accepted'] ?? true) === false
                && in_array($result['reason'] ?? '', [
                    'audio_probe_unavailable', 'audio_probe_failed', 'invalid_wav_structure', 'invalid_ogg_structure',
                ], true),
            $audioProbeRejected
        ), true),
        ($corruptZipResult['accepted'] ?? true) === false
            && ($corruptZipResult['reason'] ?? '') === 'invalid_zip_structure',
        ($crcCorruptZipResult['accepted'] ?? true) === false
            && ($crcCorruptZipResult['reason'] ?? '') === 'invalid_zip_structure',
        !in_array(false, array_map(
            static fn(array $result): bool => ($result['accepted'] ?? true) === false
                && ($result['reason'] ?? '') === 'invalid_zip_structure',
            $malformedZipResults
        ), true),
        !in_array(false, array_map(
            static fn(array $result): bool => ($result['accepted'] ?? true) === false
                && ($result['reason'] ?? '') === 'invalid_zip_structure',
            $missingPackageEntryResults
        ), true),
        !in_array(false, array_map(
            static fn(string $reason, array $result): bool => ($result['accepted'] ?? true) === false
                && ($result['reason'] ?? '') === $reason,
            array_keys($corruptContainerResults),
            array_values($corruptContainerResults)
        ), true),
        !in_array(false, array_map(
            static fn(array $result): bool => ($result['accepted'] ?? true) === false
                && ($result['reason'] ?? '') === 'unsupported_extension',
            array_values($unsupportedResults)
        ), true),
        ($oversizedInspection['accepted'] ?? true) === false
            && ($oversizedInspection['reason'] ?? '') === 'json_too_large',
        ($jsonVideoResult['valid'] ?? true) === false
            && ($jsonVideoResult['category'] ?? '') === 'json'
            && ($jsonVideoResult['max_bytes'] ?? 0) === \Yiyunying\Services\MediaOptimizationService::MAX_JSON_BYTES,
        $jsonVideoResult === $jsonTextResult,
        ($videoAsJsonResult['valid'] ?? true) === false
            && ($videoAsJsonResult['category'] ?? '') === 'json'
            && ($videoAsJsonResult['detected_category'] ?? '') === 'video',
        ($sizeMismatchResult['valid'] ?? true) === false
            && ($sizeMismatchResult['reason'] ?? '') === 'size_mismatch_or_unreadable',
        ($memoryBudgetInspection['accepted'] ?? true) === false
            && ($memoryBudgetInspection['reason'] ?? '') === 'image_memory_budget_exceeded',
        $reuseVerifier->invoke(null, $verifiedRow) === true,
        ($staleDomainValidation['file_url'] ?? '') === $verifiedUrl
            && $staleDomainHydratedUrl === $verifiedUrl,
        ($optimizedVideoValidation['file_url'] ?? '') === $optimizedVideoUrl
            && ($optimizedVideoValidation['optimized_file_url'] ?? '') === $optimizedVideoUrl
            && ($optimizedVideoValidation['original_file_url'] ?? null) === ''
            && ($optimizedVideoValidation['thumbnail_url'] ?? null) === ''
            && (int) ($optimizedVideoValidation['duration_ms'] ?? 0) === 1000
            && ($optimizedVideoHydrated[0]['attachments'][0]['url'] ?? '') === $optimizedVideoUrl
            && $optimizedVideoApprovalAccepted,
        $damagedDirectApprovalRejected,
        $reuseVerifier->invoke(null, $emptyPublicUrlRow) === false,
        $reuseVerifier->invoke(null, $unexpectedThumbnailRow) === false,
        $reuseVerifier->invoke(null, $fatalStatusRow) === false,
        $cleanupSafeRemoved,
        $hardlinkCleanupRejected && $hardlinkCleanupPreserved,
        $canonicalDirectoryValid,
        ($dedupeSelection['physical_validation_count'] ?? 0) === 1
            && (int) ($dedupeSelection['same_owner']['id'] ?? 0) === 61,
        ($animatedInspection['accepted'] ?? true) === false
            && ($animatedInspection['reason'] ?? '') === 'animated_webp_not_supported',
        ($maliciousAnimatedInspection['accepted'] ?? true) === false,
        ($apngInspection['accepted'] ?? true) === false
            && ($apngInspection['reason'] ?? '') === 'animated_png_not_supported',
        ($animatedGifInspection['accepted'] ?? true) === false
            && ($animatedGifInspection['reason'] ?? '') === 'animated_gif_not_supported',
        function_exists('imagecreatefromstring')
            ? (($pngInspection['accepted'] ?? false) === true
                && (int) ($pngInspection['width'] ?? 0) === 1
                && (int) ($pngInspection['height'] ?? 0) === 1)
            : (($pngInspection['accepted'] ?? true) === false
                && ($pngInspection['reason'] ?? '') === 'image_decoder_unavailable'),
        function_exists('imagecreatefromstring')
            ? (($staticGifInspection['accepted'] ?? false) === true
                && ($staticGifInspection['is_animated'] ?? true) === false)
            : (($staticGifInspection['accepted'] ?? true) === false
                && ($staticGifInspection['reason'] ?? '') === 'image_decoder_unavailable'),
        ($successProcess['started'] ?? false) === true
            && ($successProcess['exit_code'] ?? -1) === 0
            && ($successProcess['stdout'] ?? '') === 'ok'
            && ($successProcess['timed_out'] ?? true) === false
            && ($successProcess['output_limited'] ?? true) === false,
        ($timeoutProcess['started'] ?? false) === true
            && ($timeoutProcess['timed_out'] ?? false) === true
            && $timeoutElapsed < 4.0,
        ($limitedProcess['started'] ?? false) === true
            && ($limitedProcess['output_limited'] ?? false) === true
            && strlen((string) ($limitedProcess['stdout'] ?? '') . (string) ($limitedProcess['stderr'] ?? '')) <= 512,
        in_array((string) ($videoFallback['status'] ?? ''), ['optimizer_unavailable', 'output_validation_failed'], true)
            && ($videoFallback['inspection'] ?? null) === $trustedVideoInspection
            && (\Yiyunying\Services\MediaOptimizationService::optimizationDisposition(
                $probeRejectedMp4,
                $videoFallback
            )['accepted'] ?? false) === true,
        $probeSemanticPositive === true && $probeSemanticNegative === false,
        $validationCalls === 1
            && count($duplicateCache['uploads'] ?? []) === 1
            && count($duplicateCache['metadata'] ?? []) === 1,
        count($sameUploadPayload['attachments'] ?? []) === 200
            && $sameUploadDatabaseCalls === 1,
        ($externalAttachment['metadata']['media_trust'] ?? '') === 'untrusted_external'
            && !isset($externalAttachment['metadata']['audio_kind'])
            && (int) ($externalAttachment['size_bytes'] ?? -1) === 0
            && (int) ($externalAttachment['width'] ?? -1) === 0
            && (int) ($externalAttachment['height'] ?? -1) === 0
            && (int) ($externalAttachment['duration_ms'] ?? -1) === 0,
        $publicExternalRejected && $resourceExternalRejected && $storeExternalRejected,
        $canonicalPublicAccepted === true
            && ($canonicalPublicRow['url'] ?? '') === 'https://contract.invalid/uploads/1/canonical.pdf'
            && $legacyPublicRejected && $missingPathRejected && $traversalPathRejected,
        $matchingAliasId === 7 && $conflictingAliasRejected,
        ($legacyCatalogPresentation['cover_url'] ?? null) === ''
            && ($legacyCatalogPresentation['icon_url'] ?? null) === ''
            && !array_key_exists('download_url', $legacyCatalogPresentation)
            && !array_key_exists('apk_url', $legacyCatalogPresentation),
        $urlOnlyStickerRejected
            && (int) ($canonicalStickerPayload['upload_id'] ?? 0) === 91
            && ($canonicalStickerPayload['url'] ?? '') === 'https://contract.invalid/uploads/1/verified.png'
            && ($canonicalStickerPayload['thumbnail_url'] ?? '') === 'https://contract.invalid/uploads/1/verified.png'
            && (int) ($canonicalStickerPayload['width'] ?? 0) === 1
            && (int) ($canonicalStickerPayload['height'] ?? 0) === 1,
    ];
    if (in_array(false, $dynamicChecks, true)) {
        $failed = [];
        foreach ($dynamicChecks as $index => $passed) if ($passed !== true) $failed[] = (string) ($index + 1);
        throw new RuntimeException('Upload security dynamic contract failed: checks ' . implode(', ', $failed));
    }

    $animatedOptimization = \Yiyunying\Services\MediaOptimizationService::optimize(
        $animatedWebp,
        'image/webp',
        'contract',
        65536
    );
    $animatedDisposition = \Yiyunying\Services\MediaOptimizationService::optimizationDisposition(
        $animatedWebp,
        $animatedOptimization
    );
    if (($animatedOptimization['status'] ?? '') !== 'decode_failed'
        || ($animatedDisposition['accepted'] ?? true) !== false) {
        throw new RuntimeException('Animated WebP fail-closed contract failed');
    }
    if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
        $pngOptimization = \Yiyunying\Services\MediaOptimizationService::optimize(
            $validPng,
            'image/png',
            'contract',
            65536
        );
        $pngDisposition = \Yiyunying\Services\MediaOptimizationService::optimizationDisposition(
            $validPng,
            $pngOptimization
        );
        if (($pngOptimization['path'] ?? $validPng) !== $validPng) $paths[] = (string) $pngOptimization['path'];
        if (($pngDisposition['accepted'] ?? false) !== true
            || in_array((string) ($pngOptimization['status'] ?? ''), ['read_failed', 'decode_failed'], true)) {
            throw new RuntimeException('GD PNG decode/resample contract failed');
        }
    }
} catch (Throwable $exception) {
    $contractFailure = $exception;
} finally {
    $cleanupFailures = cleanupUploadContractDirectory($directory);
}

if ($cleanupFailures !== [] && $contractFailure === null) {
    $contractFailure = new RuntimeException('Contract TEMP cleanup failed for ' . count($cleanupFailures) . ' item(s)');
}
if ($contractFailure !== null) {
    fwrite(STDERR, 'Upload security hardening contract failed: ' . $contractFailure->getMessage() . "\n");
    exit(1);
}
echo "Upload security hardening contract: passed\n";
