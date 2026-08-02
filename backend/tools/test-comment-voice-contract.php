<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'install' => $root . '/database/install.sql',
    'migration' => $root . '/database/migrations/upgrade_20260802_moment_comment_interactions.sql',
    'moment_controller' => $root . '/app/Controllers/User/MomentController.php',
    'forum_controller' => $root . '/app/Controllers/User/ForumController.php',
    'routes' => $root . '/routes/api.php',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
}

$install = file_get_contents($files['install']);
$migration = file_get_contents($files['migration']);
$momentController = file_get_contents($files['moment_controller']);
$forumController = file_get_contents($files['forum_controller']);
$routes = file_get_contents($files['routes']);

$checks = [
    'moment comment sticker column' => str_contains($install, '`sticker_id` BIGINT UNSIGNED DEFAULT NULL'),
    'moment comment likes table' => str_contains($install, 'CREATE TABLE IF NOT EXISTS `moment_comment_likes`'),
    'portable migration creates likes' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS `moment_comment_likes`'),
    'migration has no stored procedure' => !preg_match('/\bDELIMITER\b|CREATE\s+PROCEDURE/i', $migration),
    'moment comments persist media' => str_contains($momentController, "MessageMediaService::save('moment_comment'"),
    'moment comments hydrate media' => str_contains($momentController, "MessageMediaService::hydrate(\$items, 'moment_comment'"),
    'moment voice kind normalized' => str_contains($momentController, "['metadata']['audio_kind'] = 'voice'"),
    'moment voice lower duration bound' => str_contains($momentController, '$durationMs < 650'),
    'moment voice upper duration bound' => str_contains($momentController, '$durationMs > 60000'),
    'forum voice kind validated' => str_contains($forumController, "(\$metadata['audio_kind'] ?? '') !== 'voice'"),
    'forum voice media type validated' => str_contains($forumController, "(\$attachment['media_type'] ?? '') !== 'audio'"),
    'forum voice lower duration bound' => str_contains($forumController, '$durationMs < 650'),
    'forum voice upper duration bound' => str_contains($forumController, '$durationMs > 60000'),
    'moment comment like handler' => str_contains($momentController, 'function toggleCommentLike('),
    'moment comment like route' => str_contains($routes, "'/api/user/moments/{moment_id}/comments/{comment_id}/like'"),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Comment voice contract failed: {$name}\n");
        exit(1);
    }
}

echo "Comment voice contract: passed\n";
