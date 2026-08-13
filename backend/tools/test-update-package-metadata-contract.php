<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use Yiyunying\Core\HttpException;
use Yiyunying\Services\LifecycleService;

$valid = [
    'version_code' => 59,
    'download_url' => 'https://downloads.example.com/yiyunying-user-v2.7.14.apk',
    'package_name' => 'xyz.jjmxg.yiyunying.user',
    'sha256' => str_repeat('aB', 32),
    'size_bytes' => 96538285,
];
$normalized = LifecycleService::requireUpdatePackageMetadata($valid);
if ($normalized['version_code'] !== 59
    || $normalized['size_bytes'] !== 96538285
    || $normalized['sha256'] !== str_repeat('ab', 32)) {
    fwrite(STDERR, "Update package metadata contract failed: valid metadata was not normalized\n");
    exit(1);
}

$without = static function (array $source, string $field): array {
    unset($source[$field]);
    return $source;
};
$invalidCases = [
    'missing package name' => $without($valid, 'package_name'),
    'zero version code' => array_replace($valid, ['version_code' => 0]),
    'zero size' => array_replace($valid, ['size_bytes' => 0]),
    'short sha256' => array_replace($valid, ['sha256' => str_repeat('a', 63)]),
    'non hexadecimal sha256' => array_replace($valid, ['sha256' => str_repeat('z', 64)]),
    'missing download url' => $without($valid, 'download_url'),
];
foreach ($invalidCases as $name => $candidate) {
    try {
        LifecycleService::requireUpdatePackageMetadata($candidate);
        fwrite(STDERR, "Update package metadata contract failed: {$name} was accepted\n");
        exit(1);
    } catch (HttpException $exception) {
        if ($exception->httpStatus !== 422) {
            fwrite(STDERR, "Update package metadata contract failed: {$name} returned {$exception->httpStatus}\n");
            exit(1);
        }
    }
}

$paths = [
    'service' => $root . '/app/Services/LifecycleService.php',
    'admin' => $root . '/app/Controllers/Admin/ContentController.php',
    'platform' => $root . '/app/Controllers/Platform/LifecycleController.php',
    'modules' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/domain/module/ModuleRegistry.java',
    'docs_generator' => $root . '/tools/generate-api-html.php',
];
$source = [];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Update package metadata contract failed: missing {$name}\n");
        exit(1);
    }
    $source[$name] = (string) file_get_contents($path);
}

$checks = [
    'public lifecycle excludes incomplete active rows' =>
        substr_count($source['service'], "size_bytes > 0 AND CHAR_LENGTH") >= 2
        && str_contains($source['service'], 'updatePackageMetadataComplete'),
    'public lifecycle rejects disabled app admin and platform identities' =>
        str_contains($source['service'], 'ap.status = 1 AND ap.deleted_at IS NULL')
        && str_contains($source['service'], 'a.status = 1 AND a.deleted_at IS NULL')
        && str_contains($source['service'], 'p.status = 1 AND p.deleted_at IS NULL')
        && str_contains($source['service'], 'PlatformService::byId')
        && str_contains($source['service'], 'PlatformService::byKey')
        && str_contains($source['service'], 'AdminAccessService::assertDownstreamAccess')
        && str_contains($source['service'], 'AdminAccessService::accessState'),
    'platform creation uses the fail-closed validator' =>
        str_contains($source['service'], 'createPlatformUpdate')
        && str_contains($source['service'], 'requireUpdatePackageMetadata($data)'),
    'application version publication persists the complete package identity' =>
        str_contains($source['admin'], "requireUpdatePackageMetadata(\$data, 'apk_url', 500)")
        && preg_match('/INSERT INTO app_versions.*?package_name, sha256, size_bytes/s', $source['admin']) === 1,
    'legacy policies remain readable and removable without package validation' =>
        str_contains($source['platform'], "return self::list(\$request, 'software_update_policies')")
        && preg_match('/private static function delete\(.*?Database::execute\("DELETE FROM \{\$table\}/s', $source['platform']) === 1,
    'Android management forms require the complete package identity' =>
        substr_count($source['modules'], 'req("package_name", "Android 包名")') >= 4
        && substr_count($source['modules'], 'integerRequired("size_bytes", "安装包字节数")') >= 4
        && substr_count($source['modules'], 'req("sha256", "安装包 SHA-256（64 位）")') >= 4,
    'generated API workbench documents complete update examples' =>
        str_contains($source['docs_generator'], "r.path==='/api/platform/software-updates'")
        && str_contains($source['docs_generator'], "r.path==='/api/admin/apps/{app_id}/versions'")
        && substr_count($source['docs_generator'], "sha256:'0123456789abcdef") >= 2,
];
foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Update package metadata contract failed: {$name}\n");
        exit(1);
    }
}

echo "Update package metadata contract: passed\n";
