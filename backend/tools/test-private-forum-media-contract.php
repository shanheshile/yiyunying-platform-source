<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'upload' => $root . '/app/Services/UploadStorageService.php',
    'upload_controller' => $root . '/app/Controllers/User/FileFeedbackController.php',
    'media' => $root . '/app/Services/MessageMediaService.php',
    'experience' => $root . '/app/Services/ForumExperienceService.php',
    'private' => $root . '/app/Services/PrivateForumMediaService.php',
    'response' => $root . '/app/Core/Response.php',
    'routes' => $root . '/routes/api.php',
    'config' => $root . '/config/app.php',
];
$source = [];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $source[$name] = (string) file_get_contents($path);
}
$checks = [
    'protected forum and catalog binaries use private storage' => str_contains($source['upload'], "'forum_section', 'resource_source', 'store_app_package'")
        && !str_contains($source['upload'], "'forum_post', 'forum_section'")
        && str_contains($source['upload'], "'/storage/'"),
    'forum post and comment uploads remain public' => str_contains($source['upload_controller'], "'forum_post', 'forum_comment' => ['forum']"),
    'protected forum upload requires forum and unlock capabilities' => str_contains(
        $source['upload_controller'],
        "'forum_section' => ['forum', 'forum_chapters', 'forum_attachment_unlock']"
    ),
    'private uploads do not receive permanent public urls' => str_contains($source['upload'], "\$url = \$privateUpload ? ''"),
    'normalizer accepts only persisted private upload paths' => str_contains($source['media'], "str_starts_with(ltrim")
        && str_contains($source['media'], "'private/'"),
    'hydration replaces private paths with short signed urls' => str_contains($source['media'], 'PrivateForumMediaService::signedUrl'),
    'protected sections reject public upload references' => str_contains($source['media'], 'assertPrivateForumUploads')
        && str_contains($source['media'], 'assertStoredPrivateForumAttachments')
        && str_contains($source['media'], '不支持贴纸或公开媒体地址')
        && str_contains($source['media'], "scene = 'forum_section'")
        && str_contains($source['media'], "COALESCE(up.scene, '') <> 'forum_section'")
        && str_contains($source['experience'], 'MessageMediaService::assertPrivateForumUploads')
        && str_contains($source['experience'], 'MessageMediaService::assertStoredPrivateForumAttachments'),
    'signature is hmac and time bounded' => str_contains($source['private'], "hash_hmac('sha256'")
        && str_contains($source['private'], 'URL_TTL_SECONDS'),
    'download resolves only storage private paths' => str_contains($source['private'], 'privatePhysicalPath')
        && str_contains($source['private'], "ma.target_type = 'forum_section'")
        && str_contains($source['private'], "up.scene = 'forum_section'")
        && str_contains($source['private'], "up.file_path LIKE 'private/%'"),
    'active private content is downloaded instead of executed' => str_contains($source['private'], 'deliveryPolicy')
        && str_contains($source['private'], "'application/octet-stream'")
        && str_contains($source['private'], "'attachment'")
        && str_contains($source['private'], "'audio/'")
        && str_contains($source['private'], "'video/'"),
    'binary response supports range requests' => str_contains($source['response'], "HTTP_RANGE")
        && str_contains($source['response'], "Content-Range"),
    'signed media route exists' => str_contains($source['routes'], "/api/public/forum-media/{attachment_id}"),
    'dedicated media signing key exists' => str_contains($source['config'], 'MEDIA_SIGNING_KEY'),
    'media signing fails closed on missing or reused keys' => str_contains($source['private'], 'strlen($key) < 32')
        && str_contains($source['private'], 'hash_equals($key, $qrKey)')
        && str_contains($source['private'], 'knownPlaceholders'),
];
foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Private forum media contract failed: {$name}\n");
        exit(1);
    }
}

require $root . '/bootstrap.php';

$scenePolicy = new ReflectionMethod(
    \Yiyunying\Controllers\User\FileFeedbackController::class,
    'uploadFeaturesForScene'
);
$scenePolicy->setAccessible(true);
$scenePolicyPassed = $scenePolicy->invoke(null, 'forum_post') === ['forum']
    && $scenePolicy->invoke(null, 'forum_comment') === ['forum']
    && $scenePolicy->invoke(null, 'forum_section') === ['forum', 'forum_chapters', 'forum_attachment_unlock']
    && $scenePolicy->invoke(null, 'unknown_scene') === ['remote_files'];
if (!$scenePolicyPassed) {
    fwrite(STDERR, "Private forum media contract failed: upload scene feature policy\n");
    exit(1);
}

$sceneNormalizer = new ReflectionMethod(
    \Yiyunying\Controllers\User\FileFeedbackController::class,
    'normalizeUploadScene'
);
$sceneNormalizer->setAccessible(true);
$sceneNormalizerPassed = $sceneNormalizer->invoke(null, ' CHAT_CAMERA ') === 'chat_camera'
    && $sceneNormalizer->invoke(null, '论坛评论') === 'forum_comment'
    && $sceneNormalizer->invoke(null, '') === 'general';
if (!$sceneNormalizerPassed) {
    fwrite(STDERR, "Private forum media contract failed: upload scene normalization\n");
    exit(1);
}

$privateScene = new ReflectionMethod(\Yiyunying\Services\UploadStorageService::class, 'privateScene');
$privateScene->setAccessible(true);
$privateScenePassed = $privateScene->invoke(null, 'forum_section') === true
    && $privateScene->invoke(null, 'forum_post') === false
    && $privateScene->invoke(null, 'forum_comment') === false;
if (!$privateScenePassed) {
    fwrite(STDERR, "Private forum media contract failed: private scene isolation\n");
    exit(1);
}

$deliveryPolicy = new ReflectionMethod(\Yiyunying\Services\PrivateForumMediaService::class, 'deliveryPolicy');
$deliveryPolicy->setAccessible(true);
$safeImage = $deliveryPolicy->invoke(null, 'image/png', 'image/png', '/private/photo.png', 9);
$html = $deliveryPolicy->invoke(null, 'text/html', 'text/html', '/private/file.pdf', 10);
$mismatch = $deliveryPolicy->invoke(null, 'image/png', 'text/html', '/private/fake.png', 11);
$pdf = $deliveryPolicy->invoke(null, 'application/pdf', 'application/pdf', '/private/report.pdf', 12);
$svg = $deliveryPolicy->invoke(null, 'image/png', 'image/svg+xml', '/private/fake.png', 13);
if (($safeImage['disposition'] ?? '') !== 'inline' || ($safeImage['mime_type'] ?? '') !== 'image/png'
    || ($html['disposition'] ?? '') !== 'attachment' || ($html['mime_type'] ?? '') !== 'application/octet-stream'
    || ($mismatch['disposition'] ?? '') !== 'attachment'
    || ($pdf['disposition'] ?? '') !== 'attachment' || ($pdf['download_name'] ?? '') !== 'forum-attachment-12.pdf'
    || ($svg['blocked'] ?? false) !== true) {
    fwrite(STDERR, "Private forum media contract failed: active content delivery policy\n");
    exit(1);
}

$originalSecurity = $GLOBALS['yiyunying_config']['security'];
try {
    foreach (['', 'too-short', 'local-development-only-change-me',
              'replace-with-a-different-random-secret'] as $invalidKey) {
        $GLOBALS['yiyunying_config']['security']['media_signing_key'] = $invalidKey;
        $GLOBALS['yiyunying_config']['security']['qr_signing_key'] = 'a-distinct-qr-key-that-is-long-enough-123';
        try {
            \Yiyunying\Services\PrivateForumMediaService::signedUrl(1, 1);
            fwrite(STDERR, "Private forum media contract failed: invalid signing key was accepted\n");
            exit(1);
        } catch (\Yiyunying\Core\HttpException $exception) {
            if ($exception->httpStatus !== 500) throw $exception;
        }
    }

    $sharedKey = 'same-secret-material-for-both-signers-123456';
    $GLOBALS['yiyunying_config']['security']['media_signing_key'] = $sharedKey;
    $GLOBALS['yiyunying_config']['security']['qr_signing_key'] = $sharedKey;
    try {
        \Yiyunying\Services\PrivateForumMediaService::signedUrl(1, 1);
        fwrite(STDERR, "Private forum media contract failed: reused QR key was accepted\n");
        exit(1);
    } catch (\Yiyunying\Core\HttpException $exception) {
        if ($exception->httpStatus !== 500) throw $exception;
    }

    $GLOBALS['yiyunying_config']['security']['media_signing_key'] =
        'independent-media-secret-material-1234567890';
    $GLOBALS['yiyunying_config']['security']['qr_signing_key'] =
        'independent-qr-secret-material-0987654321';
    $signed = \Yiyunying\Services\PrivateForumMediaService::signedUrl(7, 3);
    if (!str_contains($signed, '/api/public/forum-media/7?app_id=3&expires=')
        || !str_contains($signed, '&signature=')) {
        fwrite(STDERR, "Private forum media contract failed: valid key did not create a signed URL\n");
        exit(1);
    }
} finally {
    $GLOBALS['yiyunying_config']['security'] = $originalSecurity;
}

$rangeFile = tempnam(sys_get_temp_dir(), 'yiyun-range-');
if ($rangeFile === false) {
    fwrite(STDERR, "Private forum media contract failed: cannot create range fixture\n");
    exit(1);
}
try {
    file_put_contents($rangeFile, '0123456789');
    $validator = hash_file('sha256', $rangeFile);
    $_SERVER['HTTP_RANGE'] = 'bytes=-3';
    $_SERVER['HTTP_IF_RANGE'] = '"sha256-' . $validator . '"';
    $rangeResponse = \Yiyunying\Core\Response::file($rangeFile, 'video/mp4', 'inline', '', $validator);
    if ($rangeResponse->httpStatus !== 206 || $rangeResponse->fileOffset !== 7
        || $rangeResponse->fileLength !== 3
        || ($rangeResponse->headers['Content-Range'] ?? '') !== 'bytes 7-9/10'
        || ($rangeResponse->headers['ETag'] ?? '') !== '"sha256-' . $validator . '"'
        || trim((string) ($rangeResponse->headers['Last-Modified'] ?? '')) === '') {
        fwrite(STDERR, "Private forum media contract failed: suffix range response is incorrect\n");
        exit(1);
    }
    $_SERVER['HTTP_IF_RANGE'] = '"sha256-' . str_repeat('0', 64) . '"';
    $restartResponse = \Yiyunying\Core\Response::file($rangeFile, 'video/mp4', 'inline', '', $validator);
    if ($restartResponse->httpStatus !== 200 || $restartResponse->fileOffset !== 0
        || $restartResponse->fileLength !== 10 || isset($restartResponse->headers['Content-Range'])) {
        fwrite(STDERR, "Private forum media contract failed: mismatched If-Range did not restart the file\n");
        exit(1);
    }
} finally {
    unset($_SERVER['HTTP_RANGE'], $_SERVER['HTTP_IF_RANGE']);
    @unlink($rangeFile);
}
echo "Private forum media contract: passed\n";
