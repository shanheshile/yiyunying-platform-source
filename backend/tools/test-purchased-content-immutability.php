<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'user_forum' => $root . '/app/Controllers/User/ForumController.php',
    'admin_forum' => $root . '/app/Controllers/Admin/ForumController.php',
    'experience' => $root . '/app/Services/ForumExperienceService.php',
    'uploads' => $root . '/app/Services/UploadLibraryService.php',
];
$source = [];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $source[$name] = (string) file_get_contents($path);
}

/** Extract one controller/service method so unrelated calls cannot satisfy a check. */
function methodSource(string $source, string $method): string
{
    $needle = '    public static function ' . $method . '(';
    $start = strpos($source, $needle);
    if ($start === false) return '';
    $nextPublic = strpos($source, "\n    public static function ", $start + strlen($needle));
    $nextPrivate = strpos($source, "\n    private static function ", $start + strlen($needle));
    $ends = array_values(array_filter([$nextPublic, $nextPrivate], static fn($value): bool => $value !== false));
    $end = $ends === [] ? strlen($source) : min($ends);
    return substr($source, $start, $end - $start);
}

$userUpdate = methodSource($source['user_forum'], 'updatePost');
$userDelete = methodSource($source['user_forum'], 'deletePost');
$adminCreatePlate = methodSource($source['admin_forum'], 'createPlate');
$adminUpdate = methodSource($source['admin_forum'], 'updatePost');
$adminDelete = methodSource($source['admin_forum'], 'deletePost');
$purchaseGuard = methodSource($source['experience'], 'assertPostPurchaseSafeMutation');
$uploadRemove = methodSource($source['uploads'], 'remove');

$referenceCheck = strpos($uploadRemove, 'FROM media_attachments');
$deactivate = strpos($uploadRemove, 'UPDATE uploads SET status = 0');
$checks = [
    'purchase guard locks whole-post purchases' =>
        str_contains($purchaseGuard, 'FROM forum_post_purchases WHERE post_id = ? LIMIT 1 FOR UPDATE'),
    'purchase guard locks section purchases belonging to the post' =>
        str_contains($purchaseGuard, 'FROM forum_section_purchases WHERE post_id = ? LIMIT 1 FOR UPDATE'),
    'purchase guard rejects destructive mutation with conflict status' =>
        str_contains($purchaseGuard, '保护购买者权益') && str_contains($purchaseGuard, '409'),
    'user content/media update locks the post and invokes purchase guard' =>
        str_contains($userUpdate, 'Database::transaction')
        && str_contains($userUpdate, 'deleted_at IS NULL FOR UPDATE')
        && str_contains($userUpdate, "array_key_exists('content', \$all)")
        && str_contains($userUpdate, "array_key_exists('attachments', \$all)")
        && str_contains($userUpdate, "array_key_exists('images', \$all)")
        && str_contains($userUpdate, 'assertPostPurchaseSafeMutation($postId)'),
    'user post deletion is purchase-safe under the post lock' =>
        str_contains($userDelete, 'Database::transaction')
        && str_contains($userDelete, 'deleted_at IS NULL FOR UPDATE')
        && str_contains($userDelete, 'assertPostPurchaseSafeMutation($postId)'),
    'admin destructive update is purchase-safe under the post lock' =>
        str_contains($adminUpdate, 'Database::transaction')
        && str_contains($adminUpdate, 'deleted_at IS NULL FOR UPDATE')
        && str_contains($adminUpdate, 'assertPostPurchaseSafeMutation($postId)'),
    'admin post deletion is purchase-safe under the post lock' =>
        str_contains($adminDelete, 'Database::transaction')
        && str_contains($adminDelete, 'deleted_at IS NULL FOR UPDATE')
        && str_contains($adminDelete, 'assertPostPurchaseSafeMutation($postId)'),
    'upload removal locks the upload row' =>
        str_contains($uploadRemove, 'Database::transaction')
        && str_contains($uploadRemove, "implode(' AND ', \$where) . ' FOR UPDATE'"),
    'referenced upload returns a clear conflict before deactivation' =>
        $referenceCheck !== false && $deactivate !== false && $referenceCheck < $deactivate
        && str_contains($uploadRemove, 'LIMIT 1 FOR UPDATE')
        && str_contains($uploadRemove, '仍被')
        && str_contains($uploadRemove, '409'),
    'non-empty plate icon creation requires the avatar feature' =>
        str_contains($adminCreatePlate, "if (\$icon !== '')")
        && str_contains($adminCreatePlate, "requireFeature(\$appId, 'forum_plate_avatar_upload')")
        && strpos($adminCreatePlate, 'requireFeature') < strpos($adminCreatePlate, 'Database::insert'),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Purchased-content immutability contract failed: {$name}\n");
        exit(1);
    }
}

echo "Purchased-content immutability contract: passed\n";
