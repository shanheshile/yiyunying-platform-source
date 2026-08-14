<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'user' => $root . '/app/Controllers/User/FileFeedbackController.php',
    'admin' => $root . '/app/Controllers/Admin/FileFeedbackController.php',
    'platform' => $root . '/app/Controllers/Platform/OversightController.php',
    'storage' => $root . '/app/Services/UploadStorageService.php',
    'media_optimization' => $root . '/app/Services/MediaOptimizationService.php',
    'private_forum' => $root . '/app/Services/PrivateForumMediaService.php',
    'inspection' => $root . '/app/Services/SubmissionInspectionService.php',
    'migrator' => $root . '/tools/migrate-catalog-private-files.php',
    'verifier' => $root . '/tools/verify-catalog-migration-report.php',
    'type_guard' => $root . '/tools/catalog-public-upload-type.php',
    'nginx' => $root . '/deploy/nginx-site.conf.example',
    'apache' => $root . '/deploy/apache-vhost.conf.example',
    'bt_nginx' => $root . '/deploy/宝塔-Nginx-上传413修复.conf.example',
];
$source = [];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $source[$name] = (string) file_get_contents($path);
}

foreach (['user', 'admin', 'platform'] as $controller) {
    if (preg_match('/ALLOWED(?:_UPLOAD)?_EXTENSIONS\s*=\s*\[(.*?)\];/s', $source[$controller], $match) !== 1
        || preg_match('/[\'\"](?:svg|heic|heif)[\'\"]/', $match[1]) === 1) {
        fwrite(STDERR, "Public image safety failed: {$controller} allowlist still accepts SVG/HEIC/HEIF\n");
        exit(1);
    }
}
$checks = [
    str_contains($source['storage'], "\$extension === 'svg'")
        && !str_contains($source['storage'], "!\$privateUpload && (\$extension === 'svg'")
        && str_contains($source['storage'], 'MediaOptimizationService::inspectClientUpload($tmp, $extension)')
        && str_contains($source['storage'], 'MediaOptimizationService::optimizationDisposition')
        && str_contains($source['storage'], 'self::selectReusableUploadCandidates(')
        && str_contains($source['storage'], 'MediaOptimizationService::isFatalOptimizationStatus($status)')
        && str_contains($source['storage'], '$fileUrl === $expectedUrl')
        && str_contains($source['storage'], "\$optimizedUrl = \$isOptimized ? \$url : ''")
        && str_contains($source['storage'], "'sha256' => \$mainSha256"),
    str_contains($source['media_optimization'], "'read_failed', 'decode_failed'")
        && str_contains($source['media_optimization'], 'content_extension_mismatch')
        && str_contains($source['media_optimization'], 'image_decoder_unavailable')
        && str_contains($source['media_optimization'], 'imagecopyresampled(')
        && str_contains($source['media_optimization'], 'TRANSCODE_TIMEOUT_SECONDS')
        && str_contains($source['media_optimization'], "self::inspectClientUpload(\$output, 'webp')")
        && str_contains($source['media_optimization'], "self::inspectClientUpload(\$output, 'mp4')"),
    str_contains($source['private_forum'], "\$declaredMime === 'image/svg+xml'")
        && str_contains($source['private_forum'], "\$actualMime === 'image/svg+xml'")
        && str_contains($source['private_forum'], 'PATHINFO_EXTENSION'),
    str_contains($source['inspection'], "=== 'image/svg+xml'")
        && str_contains($source['inspection'], '封面或图标不支持 SVG'),
    str_contains($source['migrator'], "'unsafe_public_svg'")
        && str_contains($source['migrator'], 'catalogMigrationAssessPublicUploadFile($path)')
        && !str_contains($source['migrator'], 'mime_content_type($path)'),
    str_contains($source['verifier'], '公开上传目录仍存在可执行 SVG 文件')
        && str_contains($source['verifier'], 'catalogMigrationAssessPublicUploadFile($path)')
        && !str_contains($source['verifier'], 'mime_content_type($path)'),
    str_contains($source['type_guard'], "function_exists('mime_content_type')")
        && str_contains($source['type_guard'], 'catalogMigrationReadFilePrefix($path, 8192)')
        && str_contains($source['type_guard'], "return 'unknown'")
        && str_contains($source['type_guard'], "'svgz'"),
    str_contains($source['nginx'], '^/uploads/.*\.svg$')
        && str_contains($source['nginx'], 'X-Content-Type-Options nosniff always'),
    str_contains($source['bt_nginx'], '^/uploads/.*\.svg$')
        && str_contains($source['bt_nginx'], 'X-Content-Type-Options nosniff always'),
    str_contains($source['apache'], '<FilesMatch "(?i)\.svg$">')
        && str_contains($source['apache'], 'X-Content-Type-Options "nosniff"'),
];
if (in_array(false, $checks, true)) {
    fwrite(STDERR, "Public SVG safety contract failed\n");
    exit(1);
}

require_once $paths['type_guard'];
require_once $paths['media_optimization'];
$temporaryDirectory = sys_get_temp_dir() . '/yiyunying-catalog-type-' . bin2hex(random_bytes(6));
if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
    fwrite(STDERR, "Unable to create catalog type test directory\n");
    exit(1);
}
try {
    $fixtures = [
        'valid.png' => base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        ),
        'valid.jpg' => "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 24),
        'valid.webp' => "RIFF\x10\x00\x00\x00WEBPVP8X" . str_repeat("\x00", 16),
        'disguised.png' => "<?xml version=\"1.0\"?><svg xmlns=\"http://www.w3.org/2000/svg\"></svg>",
        'unknown.bin' => "not a supported public upload type",
        'mismatched.png' => "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 24),
        'named.svg' => "\x89PNG\x0D\x0A\x1A\x0A\x00\x00\x00\x0DIHDR" . str_repeat("\x00", 24),
        'jpeg-as-png.png' => base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////'
            . '2wBDAf//////////////////////////////////////////////////////////////////////////////////////'
            . 'wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/'
            . '9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAA'
            . 'AAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAA'
            . 'AAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAU'
            . 'EQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EH//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EH//xAAU'
            . 'EAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EH//2Q==',
            true
        ),
        'heic-as-png.png' => pack('N', 28) . 'ftypheic' . pack('N', 0) . 'mif1heicmiaf',
        'unsupported.heic' => pack('N', 28) . 'ftypheic' . pack('N', 0) . 'mif1heicmiaf',
    ];
    foreach ($fixtures as $name => $contents) {
        if (!is_string($contents)
            || file_put_contents($temporaryDirectory . '/' . $name, $contents) !== strlen($contents)) {
            throw new RuntimeException('Unable to create MIME fallback fixture');
        }
    }
    $dynamicChecks = [
        catalogMigrationAssessPublicUploadFile($temporaryDirectory . '/valid.png', true) === 'safe',
        catalogMigrationAssessPublicUploadFile($temporaryDirectory . '/valid.jpg', true) === 'safe',
        catalogMigrationAssessPublicUploadFile($temporaryDirectory . '/valid.webp', true) === 'safe',
        catalogMigrationAssessPublicUploadFile($temporaryDirectory . '/disguised.png', true) === 'svg',
        catalogMigrationAssessPublicUploadFile($temporaryDirectory . '/unknown.bin', true) === 'unknown',
        catalogMigrationAssessPublicUploadFile($temporaryDirectory . '/mismatched.png', true) === 'unknown',
        catalogMigrationAssessPublicUploadFile($temporaryDirectory . '/named.svg', true) === 'svg',
    ];
    if (in_array(false, $dynamicChecks, true)) {
        throw new RuntimeException('Catalog upload bounded fallback rejected a dynamic contract');
    }

    $trustedPng = \Yiyunying\Services\MediaOptimizationService::inspectClientUpload(
        $temporaryDirectory . '/valid.png',
        'png'
    );
    $jpegAsPng = \Yiyunying\Services\MediaOptimizationService::inspectClientUpload(
        $temporaryDirectory . '/jpeg-as-png.png',
        'png'
    );
    $heicAsPng = \Yiyunying\Services\MediaOptimizationService::inspectClientUpload(
        $temporaryDirectory . '/heic-as-png.png',
        'png'
    );
    $unsupportedHeic = \Yiyunying\Services\MediaOptimizationService::inspectClientUpload(
        $temporaryDirectory . '/unsupported.heic',
        'heic'
    );
    $originalPath = $temporaryDirectory . '/valid.png';
    $decodeFailed = \Yiyunying\Services\MediaOptimizationService::optimizationDisposition($originalPath, [
        'path' => $originalPath, 'status' => 'decode_failed',
    ]);
    $readFailed = \Yiyunying\Services\MediaOptimizationService::optimizationDisposition($originalPath, [
        'path' => $originalPath, 'status' => 'read_failed',
    ]);
    $optimizerUnavailable = \Yiyunying\Services\MediaOptimizationService::optimizationDisposition($originalPath, [
        'path' => $originalPath, 'status' => 'optimizer_unavailable',
    ]);
    $optimized = \Yiyunying\Services\MediaOptimizationService::optimizationDisposition($originalPath, [
        'path' => $temporaryDirectory . '/valid.optimized.webp', 'status' => 'optimized',
    ]);
    $invalidOptimized = \Yiyunying\Services\MediaOptimizationService::optimizationDisposition($originalPath, [
        'path' => $originalPath, 'status' => 'optimized',
    ]);
    $runtimeOptimization = null;
    $runtimeDisposition = null;
    if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
        $runtimeOptimization = \Yiyunying\Services\MediaOptimizationService::optimize(
            $originalPath,
            'image/png',
            'contract',
            65536
        );
        $runtimeDisposition = \Yiyunying\Services\MediaOptimizationService::optimizationDisposition(
            $originalPath,
            $runtimeOptimization
        );
    }
    $mediaChecks = [
        function_exists('imagecreatefromstring')
            ? (($trustedPng['accepted'] ?? false) === true
                && ($trustedPng['mime_type'] ?? '') === 'image/png'
                && (int) ($trustedPng['width'] ?? 0) === 1
                && (int) ($trustedPng['height'] ?? 0) === 1)
            : (($trustedPng['accepted'] ?? true) === false
                && ($trustedPng['reason'] ?? '') === 'image_decoder_unavailable'),
        ($jpegAsPng['accepted'] ?? true) === false
            && ($jpegAsPng['reason'] ?? '') === 'content_extension_mismatch'
            && ($jpegAsPng['kind'] ?? '') === 'jpeg',
        ($heicAsPng['accepted'] ?? true) === false
            && ($heicAsPng['reason'] ?? '') === 'content_extension_mismatch'
            && ($heicAsPng['kind'] ?? '') === 'heic',
        ($unsupportedHeic['accepted'] ?? true) === false
            && ($unsupportedHeic['reason'] ?? '') === 'unsupported_extension',
        ($decodeFailed['accepted'] ?? true) === false
            && ($decodeFailed['upload_mode'] ?? 'optimized') === ''
            && ($decodeFailed['publish_optimized_url'] ?? true) === false,
        ($readFailed['accepted'] ?? true) === false
            && ($readFailed['publish_optimized_url'] ?? true) === false,
        ($optimizerUnavailable['accepted'] ?? false) === true
            && ($optimizerUnavailable['upload_mode'] ?? '') === 'original'
            && ($optimizerUnavailable['publish_optimized_url'] ?? true) === false,
        ($optimized['accepted'] ?? false) === true
            && ($optimized['upload_mode'] ?? '') === 'optimized'
            && ($optimized['publish_optimized_url'] ?? false) === true,
        ($invalidOptimized['accepted'] ?? true) === false,
        !function_exists('imagecreatefromstring') || !function_exists('imagewebp')
            || (is_array($runtimeOptimization)
                && is_array($runtimeDisposition)
                && ($runtimeDisposition['accepted'] ?? false) === true
                && !in_array((string) ($runtimeOptimization['status'] ?? ''), ['read_failed', 'decode_failed'], true)
                && (($runtimeDisposition['upload_mode'] ?? '') !== 'optimized'
                    || (strtolower(pathinfo((string) ($runtimeOptimization['path'] ?? ''), PATHINFO_EXTENSION)) === 'webp'
                        && ($runtimeOptimization['mime_type'] ?? '') === 'image/webp'))),
    ];
    if (in_array(false, $mediaChecks, true)) {
        throw new RuntimeException('Trusted media upload dynamic contract failed');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Public SVG safety dynamic contract failed: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    foreach (array_keys($fixtures ?? []) as $name) @unlink($temporaryDirectory . '/' . $name);
    @rmdir($temporaryDirectory);
}
echo "Public upload SVG safety contract: passed\n";
