<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'install' => $root . '/database/install.sql',
    'migration' => $root . '/database/migrations/upgrade_20260801_forum_comment_threads.sql',
    'user_controller' => $root . '/app/Controllers/User/ForumController.php',
    'public_controller' => $root . '/app/Controllers/PublicApi/ForumController.php',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
}

$install = file_get_contents($files['install']);
$migration = file_get_contents($files['migration']);
$userController = file_get_contents($files['user_controller']);
$publicController = file_get_contents($files['public_controller']);
$checks = [
    'install column' => str_contains($install, '`root_comment_id` BIGINT UNSIGNED DEFAULT NULL'),
    'install index' => str_contains($install, 'idx_forum_comments_root'),
    'portable migration column' => str_contains($migration, 'root_comment_id'),
    'portable migration index' => str_contains($migration, 'idx_forum_comments_root'),
    'migration has no delimiter' => !preg_match('/\bDELIMITER\b|CREATE\s+PROCEDURE/i', $migration),
    'reply insert stores root' => str_contains($userController, 'parent_id, root_comment_id, user_id'),
    'reply root resolver' => str_contains($userController, 'resolveStoredCommentRoot'),
    'legacy root hydration' => str_contains($userController, 'hydrateCommentRoots'),
    'public API exposes root' => str_contains($publicController, 'AS root_comment_id'),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Forum comment thread contract failed: {$name}\n");
        exit(1);
    }
}

echo "Forum comment thread contract: passed\n";
