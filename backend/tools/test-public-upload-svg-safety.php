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
        && str_contains($source['migrator'], 'mime_content_type($path)'),
    str_contains($source['verifier'], '公开上传目录仍存在可执行 SVG 文件')
        && str_contains($source['verifier'], 'mime_content_type($path)'),
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
echo "Public upload SVG safety contract: passed\n";
