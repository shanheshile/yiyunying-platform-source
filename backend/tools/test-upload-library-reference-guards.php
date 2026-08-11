<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/app/Services/UploadLibraryService.php';
if (!is_file($path)) {
    fwrite(STDERR, "Upload library reference guard contract failed: missing service {$path}\n");
    exit(1);
}
$source = (string) file_get_contents($path);
$start = strpos($source, 'public static function remove(');
$end = $start === false ? false : strpos($source, 'private static function decorate(', $start);
if ($start === false || $end === false) {
    fwrite(STDERR, "Upload library reference guard contract failed: remove method boundary\n");
    exit(1);
}
$removeRaw = substr($source, $start, $end - $start);
$remove = trim((string) preg_replace('/\s+/', ' ', $removeRaw));

$checks = [
    'upload owner row is tenant scoped and locked before reference checks' =>
        str_contains($remove, "id = ?', 'admin_id = ?', 'app_id = ?', 'status = 1'")
        && str_contains($remove, "SELECT * FROM uploads WHERE ' . implode(' AND ', \$where) . ' FOR UPDATE"),
    'resource source and cover references share the current-or-purchased retention rule' =>
        str_contains($remove, 'SELECT id FROM resources WHERE admin_id = ? AND app_id = ? AND (source_upload_id = ? OR cover_upload_id = ?)')
        && str_contains($remove, 'AND (deleted_at IS NULL OR EXISTS( SELECT 1 FROM resource_purchases rp WHERE rp.resource_id = resources.id')
        && str_contains($remove, 'rp.admin_id = resources.admin_id AND rp.app_id = resources.app_id')
        && str_contains($remove, 'LIMIT 1 FOR UPDATE')
        && str_contains($remove, '[$adminId, $appId, $uploadId, $uploadId]'),
    'store package and icon references share the current-or-purchased retention rule' =>
        str_contains($remove, 'SELECT id FROM store_apps WHERE admin_id = ? AND app_id = ? AND (source_upload_id = ? OR icon_upload_id = ?)')
        && str_contains($remove, 'AND (deleted_at IS NULL OR EXISTS( SELECT 1 FROM store_app_purchases sap WHERE sap.store_app_id = store_apps.id')
        && str_contains($remove, 'sap.admin_id = store_apps.admin_id AND sap.app_id = store_apps.app_id')
        && str_contains($remove, 'LIMIT 1 FOR UPDATE')
        && substr_count($remove, '[$adminId, $appId, $uploadId, $uploadId]') === 2,
    'active sticker upload references are tenant scoped and row locked' =>
        str_contains($remove, 'SELECT id FROM stickers WHERE admin_id = ? AND app_id = ? AND upload_id = ? AND status = 1 LIMIT 1 FOR UPDATE')
        && str_contains($remove, '[$adminId, $appId, $uploadId]')
        && str_contains($remove, '该上传文件仍被活跃表情引用')
        && str_contains($remove, '请先停用或删除对应表情'),
    'all new reference conflicts are explicit 409 responses' =>
        str_contains($remove, '该上传文件仍被资源文件或封面引用')
        && str_contains($remove, '该上传文件仍被应用安装包或图标引用')
        && substr_count($remove, '409') >= 4,
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Upload library reference guard contract failed: {$name}\n");
        exit(1);
    }
}

$orderedNeedles = [
    'SELECT * FROM uploads WHERE',
    'SELECT id, target_type, target_id FROM media_attachments',
    'SELECT id FROM resources',
    'SELECT id FROM store_apps',
    'SELECT id FROM stickers',
    'UPDATE uploads SET status = 0',
];
$previous = -1;
foreach ($orderedNeedles as $needle) {
    $position = strpos($remove, $needle);
    if ($position === false || $position <= $previous) {
        fwrite(STDERR, "Upload library reference guard contract failed: unsafe check order at {$needle}\n");
        exit(1);
    }
    $previous = $position;
}

echo "Upload library reference guard contract: passed\n";
