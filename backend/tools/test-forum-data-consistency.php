<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'forum' => $root . '/app/Controllers/User/ForumController.php',
    'favorite' => $root . '/app/Controllers/User/FavoriteController.php',
    'structure' => $root . '/app/Controllers/User/ForumStructureController.php',
    'social' => $root . '/app/Controllers/User/SocialController.php',
    'communication' => $root . '/app/Controllers/User/CommunicationController.php',
    'app' => $root . '/app/Services/AppService.php',
    'install' => $root . '/database/install.sql',
    'migration' => $root . '/database/migrations/upgrade_20260810_forum_data_consistency.sql',
    'composer' => $root . '/../android/app/src/main/java/xyz/jjmxg/yiyunying/ui/forum/ForumComposerActivity.java',
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
    'authenticated post detail allows only approved posts or their author' =>
        str_contains($source['forum'], 'public static function post(int $appId, int $postId, ?int $viewerUserId = null)')
        && str_contains($source['forum'], "(p.audit_status = 'approved' OR p.user_id = ?)"),
    'favorites and history enforce approved-or-author visibility' =>
        substr_count($source['forum'], 'OR p.user_id = ?)') >= 4
        && str_contains($source['favorite'], 'OR p.user_id = ?)'),
    'content actions enforce parent post and comment visibility' =>
        substr_count($source['forum'], 'self::assertContentVisible') >= 3
        && str_contains($source['forum'], "(comment.audit_status = 'approved' OR comment.user_id = ?)"),
    'category counts do not leak pending posts' =>
        str_contains($source['structure'], 'OR p.user_id = ?)'),
    'profile post list shows pending posts only to their author' =>
        str_contains($source['social'], "(audit_status = 'approved' OR user_id = ?)"),
    'forward-to-forum rejects hidden posts from other users' =>
        str_contains($source['communication'], "(audit_status = 'approved' OR user_id = ?)"),
    'legacy paid rows persist immutable balance asset type' =>
        substr_count($source['install'], "`asset_type` VARCHAR(20) NOT NULL DEFAULT 'balance'") >= 4
        && str_contains($source['forum'], "if (\$assetType !== 'balance')")
        && str_contains($source['forum'], 'asset_type, preview_content'),
    'legacy purchase and wallet logs use balance' =>
        str_contains($source['forum'], '(post_id, buyer_user_id, seller_user_id, price_integral, asset_type, created_at)')
        && str_contains($source['forum'], "\$assetType = 'balance'")
        && str_contains($source['forum'], 'WalletService::adjust($user, $assetType'),
    'whole-post paid attachments require feature and private storage' =>
        str_contains($source['forum'], 'assertWholePostAttachmentsProtectable')
        && str_contains($source['forum'], "'forum_attachment_unlock'")
        && str_contains($source['forum'], "upload.file_path NOT LIKE 'private/%'")
        && str_contains($source['forum'], 'attachment.sticker_id IS NOT NULL')
        && str_contains($source['forum'], 'assertPaidPostPayloadProtectable($payload)'),
    'post publishing has a tenant-user-draft unique key' =>
        str_contains($source['install'], '`client_draft_id` CHAR(36) DEFAULT NULL')
        && str_contains($source['install'], 'UNIQUE KEY `uk_forum_posts_client_draft` (`app_id`, `user_id`, `client_draft_id`)'),
    'server serializes same-user draft publishing without affected-row inference' =>
        str_contains($source['forum'], "SELECT id FROM users WHERE id = ? AND app_id = ? FOR UPDATE")
        && str_contains($source['forum'], 'self::draftPostResult')
        && !str_contains($source['forum'], '$affected'),
    'idempotent replay skips operation log and reward' =>
        str_contains($source['forum'], 'if (!$idempotentReplay) LogService::userOperation')
        && str_contains($source['forum'], 'if (!$idempotentReplay && $auditStatus ===')
        && str_contains($source['forum'], "'idempotent_replay' => true"),
    'android draft persists and sends a UUID idempotency key' =>
        str_contains($source['composer'], 'UUID.randomUUID().toString()')
        && substr_count($source['composer'], 'client_draft_id') >= 3,
    'unlock safety limits are seeded for new and existing apps' =>
        str_contains($source['app'], "'forum_unlock_max_price_balance' => 1000000000.0")
        && str_contains($source['app'], "'forum_unlock_max_future_days' => 3650")
        && str_contains($source['migration'], "'forum_unlock_max_price_balance'")
        && str_contains($source['migration'], "'forum_unlock_max_future_days'"),
    'migration backfills legacy balance assets and is portable' =>
        substr_count($source['migration'], "SET asset_type = 'balance'") >= 2
        && !preg_match('/\bDELIMITER\b|CREATE\s+PROCEDURE/i', $source['migration']),
    'migration preserves administrator settings' =>
        !str_contains($source['migration'], 'setting_value = VALUES(setting_value)'),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Forum data consistency contract failed: {$name}\n");
        exit(1);
    }
}

echo "Forum data consistency contract: passed\n";
