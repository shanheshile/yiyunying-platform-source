<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'media' => $root . '/app/Services/MessageMediaService.php',
    'stickers' => $root . '/app/Controllers/User/StickerController.php',
    'inspection' => $root . '/app/Services/SubmissionInspectionService.php',
    'admin_catalog' => $root . '/app/Controllers/Admin/ResourceController.php',
    'user_catalog' => $root . '/app/Controllers/User/ResourceController.php',
    'upload_library' => $root . '/app/Services/UploadLibraryService.php',
];

$source = [];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Upload reference write TOCTOU contract failed: missing {$name} at {$path}\n");
        exit(1);
    }
    $source[$name] = (string) file_get_contents($path);
}

$compact = static fn(string $value): string => trim((string) preg_replace('/\s+/', ' ', $value));
$method = static function (string $value, string $signature, string $nextSignature) use ($compact): string {
    $start = strpos($value, $signature);
    $end = $start === false ? false : strpos($value, $nextSignature, $start + strlen($signature));
    if ($start === false || $end === false || $end <= $start) return '';
    return $compact(substr($value, $start, $end - $start));
};

$mediaSave = $method(
    $source['media'],
    'public static function save(',
    'public static function replace('
);
$mediaReplace = $method(
    $source['media'],
    'public static function replace(',
    'private static function insertAttachments('
);
$mediaLock = $method(
    $source['media'],
    'private static function lockAttachmentReferences(',
    'public static function assertPrivateForumUploads('
);
$stickerAdd = $method(
    $source['stickers'],
    'public static function addSticker(',
    'public static function deleteSticker('
);
$stickerBatch = $method(
    $source['stickers'],
    'public static function batchAdd(',
    'public static function batchDelete('
);
$stickerLock = $method(
    $source['stickers'],
    'private static function lockStickerUploads(',
    'private static function stickerPayload('
);
$stickerPayload = $method(
    $source['stickers'],
    'private static function stickerPayload(',
    'private static function insertSticker('
);
$coverLock = $method(
    $source['inspection'],
    'public static function lockCatalogCoverReference(',
    'private static function lockUploadReferenceRow('
);
$referenceRowLock = $method(
    $source['inspection'],
    'private static function lockUploadReferenceRow(',
    'private static function assertLockedUploadHash('
);
$catalogWriteBlocks = [
    'administrator resource update' => [
        $method($source['admin_catalog'], 'public static function updateResource(', 'public static function deleteResource('),
        'UPDATE resources',
    ],
    'administrator store create' => [
        $method($source['admin_catalog'], 'public static function createStoreApp(', 'public static function updateStoreApp('),
        'INSERT INTO store_apps',
    ],
    'administrator store update' => [
        $method($source['admin_catalog'], 'public static function updateStoreApp(', 'public static function deleteStoreApp('),
        'UPDATE store_apps',
    ],
    'user resource create' => [
        $method($source['user_catalog'], 'public static function submit(', 'public static function buy('),
        'INSERT INTO resources',
    ],
    'user store create' => [
        $method($source['user_catalog'], 'public static function submitStoreApp(', 'public static function storeApps('),
        'INSERT INTO store_apps',
    ],
];
$catalogWritesSafe = true;
foreach ($catalogWriteBlocks as [$block, $mutation]) {
    $transaction = strpos($block, 'SubmissionInspectionService::catalogWriteTransaction(');
    $sourceLock = strpos($block, 'SubmissionInspectionService::lockCatalogUploadReference(');
    $coverReferenceLock = strpos($block, 'SubmissionInspectionService::lockCatalogCoverReference(');
    $write = strpos($block, $mutation);
    $catalogWritesSafe = $catalogWritesSafe
        && $transaction !== false
        && $sourceLock !== false
        && $coverReferenceLock !== false
        && $write !== false
        && $transaction < $sourceLock
        && $sourceLock < $coverReferenceLock
        && $coverReferenceLock < $write;
}
$inspection = $compact($source['inspection']);
$adminCatalog = $compact($source['admin_catalog']);
$userCatalog = $compact($source['user_catalog']);
$uploadLibrary = $compact($source['upload_library']);

$checks = [
    'media save and replace make lock plus reference writes one transaction' =>
        str_contains($mediaSave, 'Database::transaction(')
        && str_contains($mediaSave, 'self::lockAttachmentReferences($payload); self::insertAttachments(')
        && str_contains($mediaReplace, 'Database::transaction(')
        && str_contains($mediaReplace, 'self::lockAttachmentReferences($payload);')
        && str_contains($mediaReplace, 'DELETE FROM media_attachments')
        && str_contains($mediaReplace, 'self::insertAttachments('),
    'media replace follows upload before reference mutation lock order' =>
        strpos($mediaReplace, 'self::lockAttachmentReferences($payload);')
            < strpos($mediaReplace, 'DELETE FROM media_attachments')
        && strpos($mediaReplace, 'DELETE FROM media_attachments')
            < strpos($mediaReplace, 'self::insertAttachments('),
    'media attachment upload locks are deterministic and tenant owner status scoped' =>
        str_contains($mediaLock, 'sort($uploadIds, SORT_NUMERIC);')
        && str_contains($mediaLock, 'admin_id = ? AND app_id = ? AND status = 1')
        && str_contains($mediaLock, "where .= ' AND user_id = ?'")
        && str_contains($mediaLock, "where .= ' AND user_id IS NULL'")
        && str_contains($mediaLock, 'ORDER BY id FOR UPDATE')
        && str_contains($mediaLock, 'count($lockedUploads) !== count($uploadIds)')
        && str_contains($mediaLock, ', 0, 409)'),
    'media sticker references are locked after upload rows and scoped to active owner and pack' =>
        strpos($mediaLock, 'SELECT id FROM uploads') < strpos($mediaLock, 'SELECT s.id FROM stickers')
        && str_contains($mediaLock, 's.admin_id = ? AND s.app_id = ? AND s.user_id = ?')
        && str_contains($mediaLock, 's.status = 1 AND p.status = 1 ORDER BY s.id FOR UPDATE')
        && str_contains($mediaLock, 'count($lockedStickers) !== count($stickerIds)'),
    'single sticker insert locks the upload inside its write transaction' =>
        str_contains($stickerAdd, 'Database::transaction(')
        && strpos($stickerAdd, 'self::lockStickerUploads(') < strpos($stickerAdd, 'self::insertSticker('),
    'batch sticker insert locks every upload once before any insert' =>
        str_contains($stickerBatch, 'Database::transaction(')
        && str_contains($stickerBatch, 'self::lockStickerUploads($user, array_keys($uploadIds))')
        && strpos($stickerBatch, 'self::lockStickerUploads(') < strpos($stickerBatch, 'self::insertSticker('),
    'sticker upload lock revalidates tenant owner status mime and locked URL' =>
        str_contains($stickerLock, 'sort($uploadIds, SORT_NUMERIC);')
        && str_contains($stickerLock, 'admin_id = ? AND app_id = ? AND user_id = ? AND status = 1')
        && str_contains($stickerLock, 'ORDER BY id FOR UPDATE')
        && str_contains($stickerLock, "'image/'")
        && str_contains($stickerLock, "\$upload['file_url']")
        && str_contains($stickerPayload, "\$upload['file_url']")
        && !str_contains($stickerPayload, 'SELECT * FROM uploads'),
    'catalog inspection records cover scene and fingerprint from the verified upload' =>
        str_contains($inspection, "'store_app_icon'")
        && str_contains($inspection, "'resource_cover'")
        && str_contains($inspection, "\$cover['mime_type']")
        && str_contains($inspection, "\$cover['sha256']")
        && str_contains($inspection, "'cover_sha256' => \$coverHash"),
    'catalog cover final gate uses the common tenant scene status owner row lock' =>
        str_contains($coverLock, 'self::lockUploadReferenceRow(')
        && str_contains($coverLock, "\$upload['mime_type']")
        && str_contains($coverLock, "\$upload['file_path']")
        && str_contains($coverLock, "\$upload['file_url']")
        && str_contains($coverLock, 'self::assertLockedUploadHash($upload, $expectedSha256)')
        && str_contains($referenceRowLock, 'id = ? AND admin_id = ? AND app_id = ? AND scene = ? AND status = 1')
        && str_contains($referenceRowLock, "\$where .= ' AND user_id = ?'")
        && str_contains($referenceRowLock, 'SELECT * FROM uploads WHERE {$where} FOR UPDATE'),
    'every resource and store catalog create or update locks cover before catalog mutation' =>
        $catalogWritesSafe
        && substr_count($source['admin_catalog'], 'SubmissionInspectionService::lockCatalogCoverReference(') === 3
        && substr_count($source['user_catalog'], 'SubmissionInspectionService::lockCatalogCoverReference(') === 2
        && substr_count($adminCatalog, "(string) (\$inspection['cover_sha256'] ?? '')") === 3
        && substr_count($userCatalog, "(string) (\$inspection['cover_sha256'] ?? '')") === 2,
    'delete path uses the same upload before reference lock direction' =>
        strpos($uploadLibrary, 'SELECT * FROM uploads WHERE') < strpos($uploadLibrary, 'SELECT id, target_type, target_id FROM media_attachments')
        && strpos($uploadLibrary, 'SELECT * FROM uploads WHERE') < strpos($uploadLibrary, 'SELECT id FROM resources')
        && strpos($uploadLibrary, 'SELECT * FROM uploads WHERE') < strpos($uploadLibrary, 'SELECT id FROM store_apps')
        && strpos($uploadLibrary, 'SELECT * FROM uploads WHERE') < strpos($uploadLibrary, 'SELECT id FROM stickers')
        && substr_count($uploadLibrary, 'FOR UPDATE') >= 5,
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Upload reference write TOCTOU contract failed: {$name}\n");
        exit(1);
    }
}

echo "Upload reference write TOCTOU contract: passed\n";
