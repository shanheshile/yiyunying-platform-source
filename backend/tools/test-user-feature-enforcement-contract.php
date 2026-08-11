<?php
declare(strict_types=1);

use Yiyunying\Services\RolePermissionService;

require dirname(__DIR__) . '/bootstrap.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $message;
};
$read = static fn(string $path): string => (string) file_get_contents(dirname(__DIR__) . '/' . $path);
$block = static function (string $source, string $start, string $end): string {
    $from = strpos($source, $start);
    if ($from === false) return '';
    $to = strpos($source, $end, $from + strlen($start));
    return $to === false ? '' : substr($source, $from, $to - $from);
};

$allowed = [
    'effective_enabled' => true,
    'locked' => false,
    'source' => 'admin_app',
];
$appDenied = RolePermissionService::combineUserFeatureState(
    'forum', false, true, true, false,
    ['effective_enabled' => false, 'locked' => false, 'source' => 'admin_app']
);
$check($appDenied['enabled'] === false, 'forum app deny 未压住最终权限');
$check($appDenied['locked'] === true, 'forum app deny 未锁定用户侧状态');
$check($appDenied['source'] === 'admin_app', 'forum app deny 来源错误');

$userDenied = RolePermissionService::combineUserFeatureState(
    'messages', true, false, true, true,
    ['effective_enabled' => false, 'locked' => false, 'source' => 'admin_app']
);
$check($userDenied['enabled'] === false, 'messages user deny 未压住最终权限');
$check($userDenied['locked'] === false, 'messages user deny 被误报为上级锁定');
$check($userDenied['source'] === 'user_permission', 'messages user deny 来源错误');

$forcedDenied = RolePermissionService::combineUserFeatureState(
    'store', true, true, true, false,
    ['effective_enabled' => false, 'locked' => true, 'source' => 'platform_force']
);
$check($forcedDenied['enabled'] === false, 'store forced deny 未压住最终权限');
$check($forcedDenied['locked'] === true, 'store forced deny 未返回 locked');
$check($forcedDenied['source'] === 'platform_force', 'store forced deny 来源错误');

$forcedAllowOverUserDeny = RolePermissionService::combineUserFeatureState(
    'messages', true, false, true, true,
    ['effective_enabled' => true, 'locked' => true, 'source' => 'platform_force']
);
$check($forcedAllowOverUserDeny['enabled'] === false, 'forced allow 不得提升个人 deny');
$legacyAllowed = RolePermissionService::combineUserFeatureState(
    'forum', true, true, false, false, $allowed
);
$check($legacyAllowed['enabled'] === true, '普通旧功能缺失记录应保持兼容允许');
$check($legacyAllowed['source'] === 'legacy_default', '普通旧功能缺失记录来源错误');

foreach ([
    '/api/user/forum-posts' => 'forum',
    '/api/user/conversations/7/messages' => 'messages',
    '/api/user/store-apps' => 'store',
] as $path => $code) {
    $check(RolePermissionService::userFeatureForPath($path) === $code,
        "路由 {$path} 未映射到 {$code}");
}
$check(RolePermissionService::userFeatureForPath('/api/user/features') === null,
    '功能 bootstrap 不应被任一业务权限自锁');
$check(RolePermissionService::userFeatureForPath('/api/user/uploads') === null,
    '动态上传场景不应被路径误判为 remote_files');
$check(RolePermissionService::userFeatureForPath('/api/user/unmapped-compatible-route') === null,
    '未映射旧路由必须保持兼容放行');

$role = $read('app/Services/RolePermissionService.php');
$app = $read('app/Services/AppService.php');
$auth = $read('app/Services/AuthService.php');
$authController = $read('app/Controllers/User/AuthController.php');
$forum = $read('app/Controllers/User/ForumController.php');
$communication = $read('app/Controllers/User/CommunicationController.php');
$resource = $read('app/Controllers/User/ResourceController.php');
$favorites = $read('app/Controllers/User/FavoriteController.php');
$adminUsers = $read('app/Controllers/Admin/UserController.php');
$oversight = $read('app/Controllers/Platform/OversightController.php');
$routes = $read('routes/api.php');

$check(str_contains($role, '$requested = $codes ?? $knownCodes'),
    'effectiveUserFeatures 默认值未覆盖全部 USER_DEFINITIONS');
$check(str_contains($role, ': !in_array($code, self::SHORT_VIDEO_CODES, true)'),
    '普通旧功能与短视频缺失 app flag 的兼容策略未区分');
$check(str_contains($role, 'WHERE admin_id = ? AND app_id = ? AND user_id = ?'),
    '个人权限查询缺少完整租户边界');
foreach (['enabled', 'effective_enabled', 'locked', 'source'] as $field) {
    $check(str_contains($app, "'{$field}' =>"), "/api/user/features 缺少兼容字段 {$field}");
}
$check(str_contains($app, 'RolePermissionService::effectiveUserFeatures($user)'),
    '用户 feature bootstrap 未使用全量个人有效权限');
$check(str_contains($authController, 'AppService::effectiveFeaturesForUser($user)'),
    '用户 feature bootstrap 控制器未接中央解析器');
$check(str_contains($routes, "get('/api/user/features'"), '缺少 /api/user/features 路由');
$check(str_contains($auth, 'RolePermissionService::userFeatureForPath($request->path())')
    && str_contains($auth, 'self::requireUserFeature($user, $featureCode)'),
    '公共用户鉴权入口未叠加命中路由的个人有效权限');
$check(str_contains($role, 'if (!self::isUserFeature($code))')
    && str_contains($role, 'AppService::requireFeature'),
    '非 USER_DEFINITIONS 扩展开关未保留 app-only 兼容分支');

$check(str_contains($forum, "AuthService::user(\$request, 'forum')"),
    'forum 控制器未显式接中央用户权限入口');
$check(str_contains($communication, 'AuthService::user($request, $feature)'),
    'messages 控制器未显式接中央用户权限入口');
$check(str_contains($resource, 'AuthService::user($request, $feature)'),
    'store/resources 控制器未显式接中央用户权限入口');
$check(str_contains($favorites, "'messages', 'forum', 'social', 'documents', 'bounties'")
    && str_contains($favorites, "\$apps = \$allowed('store') ?"),
    '聚合收藏未过滤被关闭的 messages/forum/store 内容');

$adminSave = $block($adminUsers, 'public static function savePermissions', 'public static function communications');
$platformSave = $block($oversight, 'public static function saveUserPermissions', 'public static function communications');
foreach (['管理员' => $adminSave, '平台' => $platformSave] as $label => $saveBlock) {
    $transactionAt = strpos($saveBlock, 'Database::transaction');
    $loopAt = strpos($saveBlock, 'foreach ($permissions as $code => $value)');
    $upsertAt = strpos($saveBlock, 'ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)');
    $check($saveBlock !== '' && $transactionAt !== false,
        "{$label}批量权限保存缺少事务");
    $check($transactionAt !== false && $loopAt !== false && $loopAt > $transactionAt
        && str_contains($saveBlock, 'assertUserPermissionMutable'),
        "{$label}批量权限保存未在事务中逐项校验");
    $check($upsertAt !== false && $loopAt !== false && $upsertAt > $loopAt,
        "{$label}批量权限保存缺少幂等 upsert");
}

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'user_feature_definitions' => count(RolePermissionService::userFeatureCodes()),
    'failures' => $failures,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
exit($failures === [] ? 0 : 1);
