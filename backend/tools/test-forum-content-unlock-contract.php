<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'install' => $root . '/database/install.sql',
    'migration' => $root . '/database/migrations/upgrade_20260810_forum_content_unlocks.sql',
    'experience' => $root . '/app/Services/ForumExperienceService.php',
    'media' => $root . '/app/Services/MessageMediaService.php',
    'app' => $root . '/app/Services/AppService.php',
];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
}

$install = file_get_contents($paths['install']);
$migration = file_get_contents($paths['migration']);
$experience = file_get_contents($paths['experience']);
$media = file_get_contents($paths['media']);
$app = file_get_contents($paths['app']);
$features = [
    'forum_chapters',
    'forum_paid_unlock',
    'forum_scheduled_unlock',
    'forum_attachment_unlock',
    'forum_media_filename_privacy',
];
$checks = [
    'install has unlock time' => str_contains($install, '`unlock_at` DATETIME DEFAULT NULL'),
    'install has locked preview' => str_contains($install, '`preview_content` VARCHAR(1000)'),
    'install fixes section and purchase assets to balance' => substr_count($install, "`asset_type` VARCHAR(20) NOT NULL DEFAULT 'balance'") >= 2,
    'migration backfills immutable balance asset fields' => substr_count($migration, "ADD COLUMN asset_type VARCHAR(20) NOT NULL DEFAULT ''balance''") >= 2,
    'migration is portable' => !preg_match('/\bDELIMITER\b|CREATE\s+PROCEDURE/i', $migration),
    'migration preserves administrator choices' => !str_contains($migration, '`enabled` = VALUES(`enabled`)'),
    'scheduled release is evaluated server side' => str_contains($experience, 'scheduledUnlocked'),
    'paid or scheduled mode is accepted' => str_contains($experience, "'paid_or_scheduled'"),
    'locked sections hide content and attachments' => str_contains($experience, "\$section['attachments'] = []"),
    'private section media is hydrated only after entitlement redaction' =>
        str_contains($experience, "MessageMediaService::hydrate(\$unlockedItems, 'forum_section'")
        && strpos($experience, 'MessageMediaService::hydrate($unlockedItems')
            > strpos($experience, "\$section['attachments'] = []"),
    'section purchase only accepts approved posts' => str_contains($experience, "p.audit_status = 'approved'"),
    'section asset type is immutable and balance only' => str_contains($experience, '内容节资产类型创建后不可修改')
        && str_contains($experience, "\$assetType !== 'balance'"),
    'section purchase adjusts the persisted asset' => str_contains($experience, 'WalletService::adjust($user, $assetType')
        && str_contains($experience, 'asset_type, created_at'),
    'protected existing attachments enforce feature policy' => str_contains($experience, 'sectionAttachmentCount')
        && str_contains($experience, 'forum_attachment_unlock'),
    'content-only section updates do not replace attachments' => str_contains($experience, 'if ($attachmentsChanged)')
        && str_contains($experience, "if (\$payload !== null) MessageMediaService::replace"),
    'sold sections are immutable under the section row lock' => str_contains($experience, 'author_user_id = ? AND status = 1 FOR UPDATE')
        && str_contains($experience, 'SELECT id FROM forum_section_purchases WHERE section_id = ? LIMIT 1')
        && str_contains($experience, '已有购买记录，为保护购买者权益禁止修改')
        && str_contains($experience, '0, 409'),
    'section deletion shares the purchase-safe row lock' => substr_count($experience, 'author_user_id = ? AND status = 1 FOR UPDATE') >= 2
        && str_contains($experience, '不能删除或修改'),
    'single section creation locks the post before enforcing quota' => str_contains($experience, 'public static function createSection')
        && str_contains($experience, "SELECT id FROM forum_posts\n                 WHERE id = ?")
        && str_contains($experience, "deleted_at IS NULL\n                 FOR UPDATE")
        && strpos($experience, 'FOR UPDATE') < strpos($experience, 'SELECT COUNT(*) AS total FROM forum_post_sections'),
    'buy affordance requires login and effective runtime controls' => str_contains($experience, '$paidRuntimeEnabled = $user !== null')
        && str_contains($experience, "featureEnabled(\$appId, 'forum_paid_unlock'")
        && str_contains($experience, "setting(\$appId, 'forum_paid_content_enabled'")
        && str_contains($experience, "\$section['can_buy'] = \$legacyPostUnlocked")
        && str_contains($experience, '&& $paidRuntimeEnabled'),
    'scheduled release obeys its runtime feature while purchases survive' => str_contains($experience, '$scheduledRuntimeEnabled')
        && str_contains($experience, "featureEnabled(\$appId, 'forum_scheduled_unlock'")
        && str_contains($experience, "(\$type === 'paid' && \$purchased)")
        && str_contains($experience, "(\$type === 'paid_or_scheduled' && (\$purchased || \$scheduled))"),
    'new unlock times require strict zoned RFC3339' => str_contains($experience, 'normalizeNewUnlockAt')
        && str_contains($experience, 'parseRfc3339UnlockAt')
        && str_contains($experience, '带时区的 RFC3339 日期时间')
        && str_contains($experience, "createFromFormat('!Y-m-d\\TH:i:s.uP'")
        && str_contains($experience, 'unlock_at 必须晚于当前时间'),
    'stored UTC unlock times use a separate strict parser' => str_contains($experience, 'normalizeStoredUnlockAt')
        && str_contains($experience, "'!Y-m-d H:i:s'")
        && !str_contains($experience, 'strtotime($raw)'),
    'unlock horizon is administrator bounded' => str_contains($experience, 'forum_unlock_max_future_days')
        && str_contains($experience, 'DEFAULT_UNLOCK_MAX_FUTURE_DAYS = 3650')
        && str_contains($experience, 'ABSOLUTE_UNLOCK_MAX_FUTURE_DAYS = 36500'),
    'unlock price uses an administrator bounded balance cap' => str_contains($experience, 'forum_unlock_max_price_balance')
        && str_contains($experience, 'DEFAULT_UNLOCK_MAX_PRICE_BALANCE = 1000000000.0')
        && str_contains($experience, '$price > $maximumPrice')
        && str_contains($experience, '$section[\'price_balance\'] <= $maximumPrice')
        && str_contains($experience, '价格不符合当前管理员限制，暂不可购买'),
    'published media names are sanitized as an immutable invariant' => str_contains($media, 'isPublicMediaTarget')
        && str_contains($media, 'PUBLIC_MEDIA_TARGET_TYPES')
        && !str_contains($media, "featureEnabled(\$appId, 'forum_media_filename_privacy'"),
    'filename privacy feature cannot be reported or saved as disabled' =>
        str_contains($app, "\$featureCode === 'forum_media_filename_privacy' ? true")
        && str_contains($app, "if (\$featureCode === 'forum_media_filename_privacy')")
        && str_contains($app, '$enabled = true;'),
    'forum comments are covered by name privacy' => str_contains($media, "'forum_comment'"),
    'public metadata is sanitized before persistence' => str_contains($media, 'json_encode($metadata')
        && str_contains($media, 'sanitizePublicMetadata($metadata)'),
    'public metadata is sanitized after hydration enrichment' => substr_count($media, 'sanitizePublicMetadata($metadata)') >= 2,
];
foreach ($features as $feature) {
    $checks["install seeds {$feature}"] = str_contains($install, "'{$feature}'");
    $checks["new apps seed {$feature}"] = str_contains($app, "'{$feature}'");
    $checks["existing apps migrate {$feature}"] = str_contains($migration, "'{$feature}'");
}
foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Forum content unlock contract failed: {$name}\n");
        exit(1);
    }
}

require_once $root . '/app/Core/HttpException.php';
require_once $paths['experience'];
$unlockParser = new ReflectionMethod(\Yiyunying\Services\ForumExperienceService::class, 'parseRfc3339UnlockAt');
$unlockParser->setAccessible(true);
$utcDate = $unlockParser->invoke(null, '2030-01-02T03:04:05Z');
$offsetDate = $unlockParser->invoke(null, '2030-01-02T11:04:05+08:00');
$strictRfc3339Passed = $utcDate instanceof DateTimeImmutable
    && $offsetDate instanceof DateTimeImmutable
    && $utcDate->getTimestamp() === $offsetDate->getTimestamp();
foreach (['2030-02-30T03:04:05Z', '2030-01-02 03:04:05', '2030-01-02T03:04:05+24:00'] as $invalid) {
    try {
        $unlockParser->invoke(null, $invalid);
        $strictRfc3339Passed = false;
    } catch (ReflectionException $exception) {
        throw $exception;
    } catch (Throwable $exception) {
        if (!$exception instanceof \Yiyunying\Core\HttpException || $exception->httpStatus !== 422) {
            $strictRfc3339Passed = false;
        }
    }
}
if (!$strictRfc3339Passed) {
    fwrite(STDERR, "Forum content unlock contract failed: strict RFC3339 parser behavior\n");
    exit(1);
}

require_once $paths['media'];
$targetPolicy = new ReflectionMethod(\Yiyunying\Services\MessageMediaService::class, 'isPublicMediaTarget');
$targetPolicy->setAccessible(true);
$targetPolicyPassed = $targetPolicy->invoke(null, 'forum_post') === true
    && $targetPolicy->invoke(null, 'forum_section') === true
    && $targetPolicy->invoke(null, 'moment_comment') === true
    && $targetPolicy->invoke(null, 'private_message') === false
    && $targetPolicy->invoke(null, 'group_message') === false
    && $targetPolicy->invoke(null, 'note') === false;
if (!$targetPolicyPassed) {
    fwrite(STDERR, "Forum content unlock contract failed: public privacy scope isolation\n");
    exit(1);
}
$sanitizer = new ReflectionMethod(\Yiyunying\Services\MessageMediaService::class, 'sanitizePublicMetadata');
$sanitizer->setAccessible(true);
$sanitized = $sanitizer->invoke(null, [
    'filename' => 'secret.jpg',
    'displayName' => 'secret.jpg',
    'caption' => '保留说明',
    'nested' => [
        'original_file_name' => 'nested-secret.png',
        'items' => [
            ['localPath' => 'C:\\Users\\name\\private.png', 'label' => '保留标签'],
            ['content-uri' => 'content://private/media/1', 'width' => 120],
        ],
    ],
]);
$sanitizerPassed = $sanitized === [
    'caption' => '保留说明',
    'nested' => [
        'items' => [
            ['label' => '保留标签'],
            ['width' => 120],
        ],
    ],
];
if (!$sanitizerPassed) {
    fwrite(STDERR, "Forum content unlock contract failed: recursive public metadata privacy\n");
    exit(1);
}
echo "Forum content unlock contract: passed\n";
