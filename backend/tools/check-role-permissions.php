<?php
declare(strict_types=1);

use Yiyunying\Core\HttpException;
use Yiyunying\Services\RolePermissionService;

require dirname(__DIR__) . '/bootstrap.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$sets = [
    '2级授权平台' => [RolePermissionService::platformDefinitions(), 13],
    '3级管理员' => [RolePermissionService::adminDefinitions(), 13],
    '4级用户' => [RolePermissionService::userDefinitions(), 29],
];
foreach ($sets as $role => [$definitions, $expected]) {
    $check(count($definitions) === $expected, $role . '权限数量错误');
    $codes = [];
    foreach ($definitions as $definition) {
        foreach (['code', 'title', 'group', 'description'] as $field) {
            $check(trim((string) ($definition[$field] ?? '')) !== '', $role . '权限缺少字段：' . $field);
        }
        $codes[] = (string) ($definition['code'] ?? '');
    }
    $check(count($codes) === count(array_unique($codes)), $role . '权限代码存在重复');
}

$owner = RolePermissionService::ownerPayload([
    'id' => 1,
    'account' => 'owner',
    'nickname' => '平台总控',
    'status' => 1,
]);
$check(($owner['target']['level'] ?? 0) === 1, '总控权限对象层级错误');
$check(($owner['actor_level'] ?? 0) === 1, '总控操作层级错误');
$check(($owner['access_state']['unlimited'] ?? false) === true, '总控未标记永久全权');
$check(($owner['access_state']['editable'] ?? true) === false, '总控内置权限不应允许编辑');
$check(($owner['summary']['enabled'] ?? 0) === ($owner['summary']['total'] ?? -1), '总控权限未全部生效');
foreach ($owner['groups'] ?? [] as $group) {
    foreach ($group['items'] ?? [] as $item) {
        $check(($item['configured_enabled'] ?? false) === true, '总控本级配置不是允许');
        $check(($item['effective_enabled'] ?? false) === true, '总控最终权限不是允许');
        $check(($item['editable'] ?? true) === false, '总控内置项目意外可编辑');
        $check(($item['source'] ?? '') === '系统总控内置权限', '总控权限来源错误');
    }
}

$platform = RolePermissionService::platformPayload([
    'id' => 2,
    'account' => 'operator',
    'nickname' => '授权平台',
    'status' => 1,
    'permissions_json' => json_encode([
        'admins.manage' => ['allowed' => false, 'config' => ['reason' => '测试']],
    ], JSON_UNESCAPED_UNICODE),
], 2, false);
$check(($platform['target']['level'] ?? 0) === 2, '授权平台目标层级错误');
$check(($platform['access_state']['editable'] ?? true) === false, '授权平台自身权限应只读');
$check(($platform['summary']['disabled'] ?? 0) === 1, '授权平台关闭权限统计错误');
$platformItems = [];
foreach ($platform['groups'] ?? [] as $group) {
    foreach ($group['items'] ?? [] as $item) {
        $platformItems[$item['code']] = $item;
    }
}
$check(($platformItems['admins.manage']['effective_enabled'] ?? true) === false, '授权平台最终权限未反映配置');
$check(($platformItems['admins.manage']['editable'] ?? true) === false, '授权平台自身权限项目应只读');
$check(($platformItems['admins.manage']['source'] ?? '') === '1 级总控授权', '授权平台权限来源错误');

foreach ([
    [static fn(): array => RolePermissionService::normalizePlatformInput(['unknown' => true]), '平台未知权限未拒绝'],
    [static fn(): array => RolePermissionService::normalizeAdminInput(['unknown' => true]), '管理员未知权限未拒绝'],
    [static fn(): array => RolePermissionService::normalizeUserInput(['unknown' => true]), '用户未知权限未拒绝'],
    [static fn(): array => RolePermissionService::normalizeUserInput([]), '空权限配置未拒绝'],
] as [$operation, $message]) {
    try {
        $operation();
        $check(false, $message);
    } catch (HttpException) {
        $check(true, $message);
    }
}

$routes = file_get_contents(dirname(__DIR__) . '/routes/api.php') ?: '';
foreach ([
    "get('/api/platform/permissions'",
    "get('/api/admin/permissions'",
    "get('/api/user/permissions'",
    "get('/api/platform/operators/{operator_id}/permissions'",
    "put('/api/platform/operators/{operator_id}/permissions'",
    "get('/api/platform/admins/{admin_id}/permissions'",
    "put('/api/platform/admins/{admin_id}/permissions'",
    "get('/api/platform/apps/{app_id}/users/{user_id}/permissions'",
    "put('/api/platform/apps/{app_id}/users/{user_id}/permissions'",
    "get('/api/admin/apps/{app_id}/users/{user_id}/permissions'",
    "put('/api/admin/apps/{app_id}/users/{user_id}/permissions'",
] as $route) {
    $check(str_contains($routes, $route), '缺少权限路由：' . $route);
}

$android = file_get_contents(dirname(__DIR__, 2)
    . '/yiyunying-android/app/src/main/java/xyz/jjmxg/yiyunying/ui/permission/RolePermissionActivity.java') ?: '';
foreach ([
    '/api/platform/permissions',
    '/api/admin/permissions',
    '/api/user/permissions',
    '/api/platform/operators/',
    '/api/platform/admins/',
    '/api/admin/apps/',
    '/api/platform/apps/',
    '本级配置',
    '最终结果',
    '授权来源',
    '锁定原因',
    'renderPermissionGroups',
    'matchesPermission',
    'pendingPermissionValues',
    '没有符合搜索或筛选条件的权限',
] as $needle) {
    $check(str_contains($android, $needle), 'Android 权限页缺少：' . $needle);
}

$managedPlatform = RolePermissionService::platformPayload([
    'id' => 22,
    'account' => 'branch_operator',
    'nickname' => '分支授权平台',
    'status' => 1,
    'permissions_json' => '{}',
], 1, true);
$check(($managedPlatform['target']['level'] ?? 0) === 2, '总控管理授权平台时目标层级错误');
$check(($managedPlatform['access_state']['editable'] ?? false) === true, '总控管理授权平台时未开放编辑');

$serviceSource = file_get_contents(dirname(__DIR__) . '/app/Services/RolePermissionService.php') ?: '';
foreach ([
    "actorLevel === 1",
    "actorLevel === 2",
    "'1 级总控授权'",
    "'2 级授权平台授权'",
    "'所属上级平台授权'",
] as $needle) {
    $check(str_contains($serviceSource, $needle), '管理员权限来源契约缺少：' . $needle);
}
$androidRoot = dirname(__DIR__, 2) . '/yiyunying-android/app/src/main';
$layoutPath = $androidRoot . '/res/layout/activity_role_permission.xml';
$layout = file_get_contents($layoutPath) ?: '';
foreach ([
    '@+id/roleChain',
    '@+id/managementScope',
    '@+id/permissionLegend',
    '@+id/permissionSearch',
    '@+id/permissionFilterGroup',
    '@+id/filterAll',
    '@+id/filterEnabled',
    '@+id/filterDisabled',
    '@+id/filterLocked',
    '@+id/groupsContainer',
    '@+id/saveButton',
    '权限与限制',
    '上级强制规则优先于下级自定义',
] as $needle) {
    $check(str_contains($layout, $needle), '权限页面布局缺少：' . $needle);
}
foreach ([
    '1级平台总控',
    '2级授权平台',
    '3级管理员',
    '4级用户',
    '可修改',
    '上级强制',
    '只读查看',
    'managementScopeText',
    'RuntimeLanguage.setDynamicText',
] as $needle) {
    $check(str_contains($android, $needle), 'Android 权限页角色说明缺少：' . $needle);
}

$dashboard = file_get_contents($androidRoot . '/java/xyz/jjmxg/yiyunying/ui/dashboard/DashboardFragment.java') ?: '';
$check(str_contains($dashboard, '我的权限'), '1/2/3 工作台缺少“我的权限”入口');
$check(str_contains($dashboard, 'RolePermissionActivity.openSelf'), '工作台自身权限入口未连接权限页');

$genericModule = file_get_contents($androidRoot . '/java/xyz/jjmxg/yiyunying/ui/module/GenericModuleFragment.java') ?: '';
foreach (['权限与限制', 'openPlatform', 'openAdmin', 'openUser'] as $needle) {
    $check(str_contains($genericModule, $needle), '分级管理列表缺少权限入口：' . $needle);
}

$managedUser = file_get_contents($androidRoot . '/java/xyz/jjmxg/yiyunying/ui/management/ManagedUserDetailActivity.java') ?: '';
$check(str_contains($managedUser, 'RolePermissionActivity'), '下级用户资料页缺少权限管理入口');
$userSettings = file_get_contents($androidRoot . '/java/xyz/jjmxg/yiyunying/ui/settings/UserSettingsActivity.java') ?: '';
$check(str_contains($userSettings, 'RolePermissionActivity'), '4 级用户设置缺少自身权限入口');

$apiDocs = file_get_contents(dirname(__DIR__) . '/docs/API_FULL.md') ?: '';
foreach ([
    '/api/platform/permissions',
    '/api/admin/permissions',
    '/api/user/permissions',
    '/api/platform/operators/{operator_id}/permissions',
    '/api/platform/admins/{admin_id}/permissions',
    '/api/platform/apps/{app_id}/users/{user_id}/permissions',
    '/api/admin/apps/{app_id}/users/{user_id}/permissions',
] as $route) {
    $check(str_contains($apiDocs, $route), '完整 API 文档缺少权限接口：' . $route);
}

$verificationPath = dirname(__DIR__) . '/docs/REQUIREMENT_VERIFICATION_20260721.md';
$verification = file_get_contents($verificationPath) ?: '';
preg_match_all('/^\|\s*\d+\s*\|/m', $verification, $verificationRows);
$check(count($verificationRows[0] ?? []) >= 50, '逐项需求核验表少于 50 条');
$check(str_contains($verification, '仍需真机复验'), '逐项核验表缺少未完成风险说明');
$check(is_file(dirname(__DIR__) . '/tools/generate-requirement-verification.php'), '缺少可重复生成的核验报告脚本');
echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'permission_definitions' => 55,
    'failures' => $failures,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
exit($failures === [] ? 0 : 1);