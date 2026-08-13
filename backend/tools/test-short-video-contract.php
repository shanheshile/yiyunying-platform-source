<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'install' => $root . '/database/install.sql',
    'migration' => $root . '/database/migrations/upgrade_20260811_short_video_controls.sql',
    'moments' => $root . '/app/Controllers/User/MomentController.php',
    'favorites' => $root . '/app/Controllers/User/FavoriteController.php',
    'user_auth' => $root . '/app/Controllers/User/AuthController.php',
    'public_bootstrap' => $root . '/app/Controllers/PublicApi/BootstrapController.php',
    'admin' => $root . '/app/Controllers/Admin/ContentModerationController.php',
    'admin_apps' => $root . '/app/Controllers/Admin/AppController.php',
    'routes' => $root . '/routes/api.php',
    'app_service' => $root . '/app/Services/AppService.php',
    'role_permissions' => $root . '/app/Services/RolePermissionService.php',
    'settings' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/ui/settings/SettingsFragment.java',
    'home' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/ui/home/FeatureHubFragment.java',
    'home_policy' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/ui/home/HomeFeaturePolicy.java',
    'user_shell' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/ui/home/UserShellFragment.java',
    'policy' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/ui/moment/ShortVideoFeaturePolicy.java',
    'timeline' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/ui/moment/MomentTimelineActivity.java',
    'modules' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/domain/module/ModuleRegistry.java',
    'chat_activity' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/ui/chat/ChatActivity.java',
    'chat_adapter' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/ui/chat/ChatAdapter.java',
];

$source = [];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $source[$name] = (string) file_get_contents($path);
}

$features = [
    'short_videos', 'short_video_publish', 'short_video_comments',
    'short_video_likes', 'short_video_favorites', 'short_video_forwards',
];

$userMomentsTable = '';
$forumCommentsTable = '';
$saveFeaturesBlock = '';
if (preg_match('/CREATE TABLE IF NOT EXISTS `user_moments` \((.*?)\n\) ENGINE=/s', $source['install'], $match) === 1) {
    $userMomentsTable = (string) ($match[1] ?? '');
}
if (preg_match('/CREATE TABLE IF NOT EXISTS `forum_comments` \((.*?)\n\) ENGINE=/s', $source['install'], $match) === 1) {
    $forumCommentsTable = (string) ($match[1] ?? '');
}
if (preg_match('/public static function saveFeatures\(.*?\n    public static function domains/s', $source['admin_apps'], $match) === 1) {
    $saveFeaturesBlock = (string) ($match[0] ?? '');
}

$checks = [
    'fresh and upgraded schemas persist a dedicated content kind' =>
        $userMomentsTable !== ''
        && str_contains($userMomentsTable, '`content_kind` VARCHAR(20)')
        && !str_contains($forumCommentsTable, '`content_kind`')
        && str_contains($userMomentsTable, '`idx_user_moments_kind_feed`')
        && str_contains($source['migration'], "COLUMN_NAME = 'content_kind'")
        && str_contains($source['migration'], 'idx_user_moments_kind_feed'),
    'all six short-video controls are seeded for new and existing apps' =>
        array_reduce($features, static fn(bool $ok, string $feature): bool =>
            $ok
            && str_contains($source['migration'], "'{$feature}'")
            && str_contains($source['app_service'], "'{$feature}'")
            && str_contains($source['install'], "'{$feature}'"), true),
    'upgrade seed disambiguates insert-select upsert after the cross join' =>
        preg_match(
            '/\)\s+AS short_video_features\s+WHERE 1 = 1\s+ON DUPLICATE KEY UPDATE/s',
            $source['migration']
        ) === 1,
    'backend has a dedicated feed and immutable short-video content kind' =>
        str_contains($source['moments'], "content_kind = ?")
        && str_contains($source['moments'], "'short_video'")
        && str_contains($source['moments'], '短视频只能包含 1 个视频'),
    'backend gates publish and every interactive short-video action' =>
        array_reduce(array_slice($features, 1), static fn(bool $ok, string $feature): bool =>
            $ok && str_contains($source['moments'], "'{$feature}'"), true)
        && str_contains($source['moments'], 'RolePermissionService::requireUserFeature')
        && !str_contains($source['moments'], "featureEnabled(\$appId, \$featureCode, true)"),
    'all six controls are Chinese user permissions with a shared deny-dominant resolver' =>
        array_reduce($features, static fn(bool $ok, string $feature): bool =>
            $ok && str_contains($source['role_permissions'], "['code' => '{$feature}'"), true)
        && str_contains($source['role_permissions'], "'title' => '短视频功能'")
        && str_contains($source['role_permissions'], "'title' => '发布短视频'")
        && str_contains($source['role_permissions'], 'effectiveUserFeatures')
        && str_contains($source['role_permissions'], 'WHERE admin_id = ? AND app_id = ? AND user_id = ?')
        && str_contains($source['role_permissions'], '$effective = $configured &&')
        && str_contains($source['role_permissions'], '$appEnabled = isset($flags[$code])'),
    'authenticated bootstrap exposes only effective short-video booleans' =>
        str_contains($source['routes'], "get('/api/user/features'")
        && str_contains($source['user_auth'], 'AppService::effectiveFeaturesForUser($user)')
        && str_contains($source['app_service'], 'RolePermissionService::effectiveUserFeatures')
        && str_contains($source['app_service'], "'effective_enabled' => \$enabled")
        && str_contains($source['app_service'], "'enabled' => \$enabled")
        && str_contains($source['public_bootstrap'], "'features' => AppService::features")
        && !str_contains($source['public_bootstrap'], 'effectiveFeaturesForUser'),
    'favorites center applies the same per-user short-video decision' =>
        str_contains($source['favorites'], 'RolePermissionService::effectiveUserFeatures')
        && str_contains($source['favorites'], "['short_videos', 'short_video_favorites']")
        && str_contains($source['favorites'], "moment.content_kind <> 'short_video'")
        && !str_contains($source['favorites'], "featureEnabled((int) \$user['app_id'], 'short_videos', true)"),
    'administrator receives human-readable short-video controls' =>
        str_contains($source['settings'], 'labels.put("short_video_publish", "发布短视频")')
        && str_contains($source['settings'], 'labels.put("short_video_forwards", "短视频转发")')
        && str_contains($source['settings'], 'effective_features')
        && str_contains($source['settings'], 'lockedFeatureCodes')
        && str_contains($source['settings'], '上级平台')
        && str_contains($source['settings'], '强制规则（已锁定）'),
    'administrator feature batch validates first and saves atomically' =>
        $saveFeaturesBlock !== ''
        && str_contains($saveFeaturesBlock, '$preparedItems = []')
        && str_contains($saveFeaturesBlock, 'GovernanceService::assertFeatureMutable')
        && str_contains($saveFeaturesBlock, 'Database::transaction')
        && strpos($saveFeaturesBlock, 'GovernanceService::assertFeatureMutable')
            < strpos($saveFeaturesBlock, 'Database::transaction'),
    'administrator has separate Chinese tri-state review modules and endpoints' =>
        str_contains($source['routes'], '/api/admin/apps/{app_id}/short-videos')
        && str_contains($source['routes'], '/api/admin/apps/{app_id}/short-video-comments')
        && str_contains($source['admin'], "return self::momentList(\$request, \$params, 'short_video')")
        && str_contains($source['admin'], "return self::commentList(\$request, \$params, 'short_video')")
        && str_contains($source['modules'], '"short_videos", "短视频审核"')
        && str_contains($source['modules'], '"short_video_comments", "短视频评论审核"')
        && substr_count($source['modules'], '/short-videos/{moment_id}/audit') >= 3
        && substr_count($source['modules'], '/short-video-comments/{comment_id}/audit') >= 3,
    'ordinary moments and short videos cannot cross their detail or audit routes' =>
        str_contains($source['admin'], "return self::showMomentByKind(\$request, \$params, 'moment')")
        && str_contains($source['admin'], "return self::showMomentByKind(\$request, \$params, 'short_video')")
        && str_contains($source['admin'], "return self::auditMomentByKind(\$request, \$params, 'moment')")
        && str_contains($source['admin'], "return self::auditMomentByKind(\$request, \$params, 'short_video')")
        && str_contains($source['admin'], "return self::showCommentByKind(\$request, \$params, 'moment')")
        && str_contains($source['admin'], "return self::showCommentByKind(\$request, \$params, 'short_video')")
        && str_contains($source['admin'], "return self::auditCommentByKind(\$request, \$params, 'moment')")
        && str_contains($source['admin'], "return self::auditCommentByKind(\$request, \$params, 'short_video')")
        && substr_count($source['admin'], 'AND content_kind = ?') >= 2
        && str_contains($source['admin'], 'AND m.content_kind = ?'),
    'disabled short-video favorites do not leak through the favorites center' =>
        str_contains($source['favorites'], "'short_video_favorites'")
        && str_contains($source['favorites'], "moment.content_kind <> 'short_video'"),
    'user UI exposes a dedicated feed and one-video composer' =>
        str_contains($source['home'], '短视频')
        && str_contains($source['timeline'], 'openShortVideos')
        && str_contains($source['timeline'], 'MediaPickerActivity.videoIntent')
        && str_contains($source['policy'], 'return shortVideoMode ? 1 : 9'),
    'signed-in short-video UI consumes effective flags and fails closed' =>
        str_contains($source['timeline'], '"/api/user/features"')
        && !str_contains($source['timeline'], '"/api/public/features"')
        && str_contains($source['user_shell'], '"/api/user/features"')
        && !str_contains($source['user_shell'], '"/api/public/features"')
        && str_contains($source['policy'], 'effective_enabled')
        && substr_count($source['policy'], 'return false;') >= 3
        && str_contains($source['home_policy'], 'explicitlyEnabled(features, "short_videos")')
        && str_contains($source['home_policy'], 'effective_enabled'),
    'chat forwarding preserves short-video identity and Chinese presentation' =>
        str_contains($source['moments'], "'content_kind' => \$shortVideo ? 'short_video' : 'moment'")
        && str_contains($source['chat_activity'], 'openShortVideoMoment')
        && str_contains($source['chat_activity'], 'isMomentContentKind')
        && str_contains($source['chat_adapter'], 'momentLabel(metadata)')
        && str_contains($source['chat_adapter'], '? "短视频" : "动态"'),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Short-video contract failed: {$name}\n");
        exit(1);
    }
}

echo "Short-video contract: passed\n";
