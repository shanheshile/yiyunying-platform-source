<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'user' => $root . '/app/Controllers/User/FileFeedbackController.php',
    'admin' => $root . '/app/Controllers/Admin/FileFeedbackController.php',
    'platform' => $root . '/app/Controllers/Platform/OversightController.php',
    'storage' => $root . '/app/Services/UploadStorageService.php',
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
        || preg_match('/[\'\"]svg[\'\"]/', $match[1]) === 1) {
        fwrite(STDERR, "Public SVG safety failed: {$controller} upload allowlist still accepts svg\n");
        exit(1);
    }
}
$checks = [
    str_contains($source['storage'], "\$extension === 'svg'")
        && str_contains($source['storage'], "image/svg+xml")
        && !str_contains($source['storage'], "!\$privateUpload && (\$extension === 'svg'"),
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
$temporaryDirectory = sys_get_temp_dir() . '/yiyunying-catalog-type-' . bin2hex(random_bytes(6));
if (!mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
    fwrite(STDERR, "Unable to create catalog type test directory\n");
    exit(1);
}
try {
    $fixtures = [
        'valid.png' => "\x89PNG\x0D\x0A\x1A\x0A\x00\x00\x00\x0DIHDR" . str_repeat("\x00", 24),
        'valid.jpg' => "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 24),
        'valid.webp' => "RIFF\x10\x00\x00\x00WEBPVP8X" . str_repeat("\x00", 16),
        'disguised.png' => "<?xml version=\"1.0\"?><svg xmlns=\"http://www.w3.org/2000/svg\"></svg>",
        'unknown.bin' => "not a supported public upload type",
        'mismatched.png' => "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 24),
        'named.svg' => "\x89PNG\x0D\x0A\x1A\x0A\x00\x00\x00\x0DIHDR" . str_repeat("\x00", 24),
    ];
    foreach ($fixtures as $name => $contents) {
        if (file_put_contents($temporaryDirectory . '/' . $name, $contents) !== strlen($contents)) {
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
} catch (Throwable $exception) {
    fwrite(STDERR, 'Public SVG safety dynamic contract failed: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    foreach (array_keys($fixtures ?? []) as $name) @unlink($temporaryDirectory . '/' . $name);
    @rmdir($temporaryDirectory);
}
echo "Public upload SVG safety contract: passed\n";
