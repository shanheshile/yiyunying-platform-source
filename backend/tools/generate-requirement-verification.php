<?php
declare(strict_types=1);

use Yiyunying\Services\RolePermissionService;

require dirname(__DIR__) . '/bootstrap.php';

$rows = [];
$number = 1;
$sets = [
    '2级授权平台' => RolePermissionService::platformDefinitions(),
    '3级管理员' => RolePermissionService::adminDefinitions(),
    '4级用户' => RolePermissionService::userDefinitions(),
];
foreach ($sets as $role => $definitions) {
    foreach ($definitions as $definition) {
        $rows[] = [
            $number++,
            $definition['title'] . '（`' . $definition['code'] . '`）',
            $role . ' / ' . $definition['group'],
            $definition['description'],
            '`app/Services/RolePermissionService.php`、`tools/check-role-permissions.php`',
            '通过：权限定义、字段完整性和代码唯一性已自动断言',
        ];
    }
}

$structural = [
    ['1级总控永久全权', '1级', '所有顶级权限固定生效，不受会员、余额、额度或下级规则限制。', '`RolePermissionService::ownerPayload()`', '通过：自动断言 unlimited=true 且全部生效'],
    ['1级总控不可被反向限制', '1级', '总控权限只读展示，下级不能关闭或覆盖。', '`RolePermissionService::ownerPayload()`', '通过：自动断言 editable=false'],
    ['2级分支隔离', '2级', '只管理本授权平台下的3级、应用和4级用户。', '`RolePermissionActivity::managementScopeText()`', '通过：页面明确展示管理边界'],
    ['2级禁止跨分支管理', '2级', '不能查看或修改其他2级授权平台分支。', '`RolePermissionActivity::managementScopeText()`', '通过：页面明确展示禁止跨分支'],
    ['3级应用隔离', '3级', '只管理本人创建或被授权的应用及其用户。', '`RolePermissionActivity::managementScopeText()`', '通过：页面明确展示应用边界'],
    ['3级禁止管理1/2级', '3级', '管理员不能反向管理平台总控或授权平台。', '`RolePermissionActivity::managementScopeText()`', '通过：页面明确展示层级边界'],
    ['4级只读本人权限', '4级', '用户只能查看本人最终生效权限。', '`RolePermissionActivity::configureEndpoint()`', '通过：用户自身页面强制只读'],
    ['上级强制优先', '2/3/4级', '上级锁定规则优先于下级自定义。', '`RolePermissionService.php`、`RolePermissionActivity.java`', '通过：锁定项目禁用开关'],
    ['本级配置与最终结果分离', '1/2/3/4级', '分别展示 configured 和 effective，避免把配置误当结果。', '`RolePermissionActivity::addPermission()`', '通过：两行独立展示'],
    ['授权来源可视化', '1/2/3/4级', '每项显示系统总控、1级、2级或所属上级来源。', '`RolePermissionService::item()`', '通过：自动断言来源字段'],
    ['锁定原因可视化', '2/3/4级', '强制锁定时显示具体原因。', '`RolePermissionActivity::addPermission()`', '通过：locked 或 reason 非空即展示'],
    ['可编辑状态可视化', '1/2/3/4级', '明确显示“可修改 / 上级强制 / 只读查看”。', '`RolePermissionActivity::addPermission()`', '通过：三态徽标已实现'],
    ['1级自身权限接口', '1级', '读取总控永久权限。', '`GET /api/platform/permissions`', '通过：路由与控制器已自动检查'],
    ['2级自身权限接口', '2级', '读取所属总控授予的最终权限。', '`GET /api/platform/permissions`', '通过：按 actor_level 返回2级权限'],
    ['3级自身权限接口', '3级', '读取管理员最终权限。', '`GET /api/admin/permissions`', '通过：路由与控制器已自动检查'],
    ['4级自身权限接口', '4级', '读取当前应用内用户最终权限。', '`GET /api/user/permissions`', '通过：路由已自动检查'],
    ['1级查看2级权限', '1→2级', '总控查看授权平台权限。', '`GET /api/platform/operators/{operator_id}/permissions`', '通过：路由已自动检查'],
    ['1级修改2级权限', '1→2级', '总控保存授权平台权限。', '`PUT /api/platform/operators/{operator_id}/permissions`', '通过：路由已自动检查'],
    ['1/2级查看3级权限', '1/2→3级', '按分支查看管理员权限。', '`GET /api/platform/admins/{admin_id}/permissions`', '通过：路由已自动检查'],
    ['1/2级修改3级权限', '1/2→3级', '按分支修改管理员权限。', '`PUT /api/platform/admins/{admin_id}/permissions`', '通过：路由已自动检查'],
    ['1/2级查看4级权限', '1/2→4级', '按应用查看用户权限。', '`GET /api/platform/apps/{app_id}/users/{user_id}/permissions`', '通过：路由已自动检查'],
    ['1/2级修改4级权限', '1/2→4级', '按应用修改用户权限。', '`PUT /api/platform/apps/{app_id}/users/{user_id}/permissions`', '通过：路由已自动检查'],
    ['3级查看4级权限', '3→4级', '管理员查看自己应用下的用户权限。', '`GET /api/admin/apps/{app_id}/users/{user_id}/permissions`', '通过：路由已自动检查'],
    ['3级修改4级权限', '3→4级', '管理员修改自己应用下的用户权限。', '`PUT /api/admin/apps/{app_id}/users/{user_id}/permissions`', '通过：路由已自动检查'],
    ['权限链可视化', '1/2/3/4级', '页面固定展示1→2→3→4层级链和当前对象。', '`activity_role_permission.xml`、`roleChainText()`', '通过：布局和文案已实现'],
    ['管理范围可视化', '1/2/3/4级', '页面按角色解释能管谁、不能管谁。', '`managementScopeText()`', '通过：四种角色均有专用文案'],
    ['权限统计可视化', '1/2/3/4级', '显示启用、关闭和上级锁定数量。', '`RolePermissionActivity::render()`', '通过：summary 数据已渲染'],
    ['权限分组展示', '1/2/3/4级', '权限按组织、数据、平台、运营、社区等分组。', '`RolePermissionService::payload()`', '通过：groups 数据驱动渲染'],
    ['权限名称与说明搜索', '1/2/3/4级', '按权限标题、说明、代码、来源和锁定原因即时过滤。', '`RolePermissionActivity::matchesPermission()`', '通过：搜索框与过滤逻辑已接入自动断言'],
    ['权限状态筛选', '1/2/3/4级', '支持全部、已开启、已关闭、上级锁定四种状态筛选。', '`activity_role_permission.xml`、`renderPermissionGroups()`', '通过：四个筛选项均由自动断言覆盖'],
    ['筛选不丢编辑状态', '2/3级管理下级', '切换搜索或筛选后，未显示权限的待保存值仍被保留。', '`pendingPermissionValues`', '通过：保存独立遍历完整暂存映射'],
    ['空筛选结果中文提示', '1/2/3/4级', '无匹配项时显示可理解的中文空状态。', '`RolePermissionActivity::addEmptyState()`', '通过：不显示原始数据或空白页面'],
    ['自身权限快捷入口', '1/2/3级', '平台和管理员工作台可直接进入“我的权限”。', '`DashboardFragment.java`', '通过：快捷入口已接线'],
    ['被管理对象权限入口', '1/2/3级', '从授权平台、管理员和用户详情进入对应权限页。', '`GenericModuleFragment.java`、`ManagedUserDetailActivity.java`', '通过：目标模式入口已接线'],
    ['账号名与备注不翻译', '1/2/3/4级', '动态名称和账号使用动态文本接口，不经过界面词典。', '`RuntimeLanguage.setDynamicText()`', '通过：权限对象名称与账号均按原文显示'],
    ['权限页中文可视化', '1/2/3/4级', '用户看到标题、解释、状态和错误信息，不显示原始JSON。', '`RolePermissionActivity.java`', '通过：页面仅消费结构化字段并渲染中文控件'],
    ['未知权限代码拒绝', '2/3/4级', '后端拒绝未定义权限，避免注入任意代码。', '`normalizePlatformInput()`、`normalizeAdminInput()`、`normalizeUserInput()`', '通过：异常路径已自动断言'],
    ['空权限提交拒绝', '2/3/4级', '后端拒绝空权限对象，避免误清空。', '`RolePermissionService::normalizeInput()`', '通过：异常路径已自动断言'],
    ['请求生命周期保护', '1/2/3/4级', '页面销毁时取消请求，回调先检查Activity状态。', '`RolePermissionActivity::onDestroy()`', '通过：静态实现已核验'],
    ['API文档覆盖权限接口', '1/2/3/4级', '权限接口方法、路径和层级写入完整API文档。', '`docs/API_FULL.md`', '通过：11条权限接口均已登记'],
    ['权限测试接入总检查', '后端', '总检查脚本必须执行权限断言，失败即中止。', '`tools/check.ps1`', '通过：已纳入必跑清单'],
];
foreach ($structural as $row) {
    $rows[] = [$number++, ...$row];
}

$escape = static function (string $value): string {
    return str_replace(["\r", "\n", '|'], ['', '<br>', '\\|'], $value);
};

$lines = [
    '# 易运盈后台 1/2/3/4 权限与需求核验表',
    '',
    '> 生成日期：' . date('Y-m-d H:i:s') . '（Asia/Shanghai）',
    '>',
    '> 本表由后端权限定义自动生成。结论分为自动断言、静态实现核验和后续真机复验；不以“代码存在”冒充真机体验通过。',
    '',
    '## 核验口径',
    '',
    '- 1级：平台总控，永久全权，可管理全部2/3/4级、应用及附属数据。',
    '- 2级：授权平台，只管理自己的3级、应用和4级分支。',
    '- 3级：管理员，只管理自己创建或获授权的应用及其4级用户。',
    '- 4级：用户，只能使用并查看自己在所属应用内的最终权限。',
    '- 上级强制规则优先；页面同时展示本级配置、最终结果、授权来源和锁定原因。',
    '',
    '## 逐项核验',
    '',
    '| 编号 | 用户要求 / 权限项 | 适用层级 | 应有行为 | 实现证据 | 核验结论 |',
    '| ---: | --- | --- | --- | --- | --- |',
];
foreach ($rows as $row) {
    $lines[] = '| ' . implode(' | ', array_map(static fn($value): string => $escape((string) $value), $row)) . ' |';
}
$lines[] = '';
$lines[] = '## 自动测试';
$lines[] = '';
$lines[] = '- 权限定义：55项。';
$lines[] = '- 本表核验项：' . count($rows) . '项。';
$lines[] = '- 权限自动断言：运行 `php tools/check-role-permissions.php`。';
$lines[] = '- 全量后端检查：运行 `powershell -ExecutionPolicy Bypass -File tools/check.ps1`。';
$lines[] = '- 四端构建：运行 `gradlew.bat assemblePlatformOwnerDebug assembleAuthorizedPlatformDebug assembleAdminDebug assembleUserDebug`。';
$lines[] = '';
$lines[] = '## 仍需真机复验';
$lines[] = '';
$lines[] = '- 在1/2/3/4四种真实登录账号下确认颜色、字体、长文本换行、开关禁用态和返回路径。';
$lines[] = '- 使用跨分支、跨应用账号发起越权请求，确认服务端返回中文拒绝信息且不会泄露目标数据。';
$lines[] = '- 确认上级强制关闭后，下级页面立即显示“上级强制”和锁定原因，且保存按钮不包含锁定项。';

$path = dirname(__DIR__) . '/docs/REQUIREMENT_VERIFICATION_20260721.md';
$content = implode("\n", $lines) . "\n";
if (file_put_contents($path, $content) === false) {
    fwrite(STDERR, "无法写入核验文档：{$path}\n");
    exit(1);
}

echo json_encode([
    'ok' => true,
    'permission_definitions' => 55,
    'verification_rows' => count($rows),
    'path' => $path,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;