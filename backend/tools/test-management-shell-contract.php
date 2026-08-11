<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'routes' => $root . '/routes/api.php',
    'app_controller' => $root . '/app/Controllers/Admin/AppController.php',
    'auth_controller' => $root . '/app/Controllers/Admin/AuthController.php',
    'community_controller' => $root . '/app/Controllers/Admin/CommunityController.php',
    'workbench_controller' => $root . '/app/Controllers/Admin/ManagementWorkbenchController.php',
    'branding_controller' => $root . '/app/Controllers/PublicApi/BrandingController.php',
    'branding_service' => $root . '/app/Services/AdminBrandingService.php',
    'forum_service' => $root . '/app/Services/LevelForumService.php',
    'submission_service' => $root . '/app/Services/SubmissionInspectionService.php',
    'migration' => $root . '/database/migrations/upgrade_20260811_management_shell_restructure.sql',
    'install' => $root . '/database/install.sql',
    'api_full' => $root . '/docs/API_FULL.md',
    'routes_doc' => $root . '/docs/ROUTES.md',
    'api_html' => $root . '/public/api-docs.html',
    'check' => $root . '/tools/check.ps1',
    'catalog' => dirname($root) . '/android/app/src/main/assets/api_catalog.json',
];

$source = [];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Management-shell contract missing {$name}: {$path}\n");
        exit(1);
    }
    $source[$name] = (string) file_get_contents($path);
}

require_once $root . '/bootstrap.php';

$expectedSeeds = [
    ['Android Java 源码', 'Android 原生 Java 源码、示例与完整工程', 130],
    ['iApp 源码', 'iApp 源码、界面示例与可复用模块', 120],
    ['Lua 源码', 'Lua 脚本、源码模块与完整示例', 110],
    ['Web 源码', '网页、前端界面与 Web 完整工程源码', 100],
    ['PHP 源码', 'PHP 服务端源码、接口与完整工程', 90],
    ['Python 源码', 'Python 脚本、服务与完整工程源码', 80],
    ['JavaScript 源码', 'JavaScript、Node.js 与前端框架源码', 70],
    ['HarmonyOS 源码', 'HarmonyOS、ArkTS 与鸿蒙应用源码', 60],
    ['iOS 源码', 'iOS、Swift 与苹果应用完整源码', 50],
    ['C/C++ 源码', 'C、C++ 源码、库与完整工程', 40],
    ['数据库源码', '数据库脚本、结构、查询与迁移源码', 30],
    ['通用模块', '好友聊天、群聊、登录注册、论坛、文档和商城等独立模块', 20],
    ['其他源码', '未归入标准技术分类的其他源码与示例', 10],
];
$actualSeeds = array_map(
    static fn (array $seed): array => [$seed['name'], $seed['description'], $seed['sort_order']],
    Yiyunying\Services\SubmissionInspectionService::sourceCategorySeeds()
);

$catalog = json_decode($source['catalog'], true, 512, JSON_THROW_ON_ERROR);
$catalogRoutes = [];
foreach ($catalog as $route) {
    $catalogRoutes[(string) $route['method'] . ' ' . (string) $route['path']] = true;
}
/** @var Yiyunying\Core\Router $router */
$router = require $root . '/routes/api.php';
$registeredRoutes = [];
foreach ($router->routes() as $route) {
    $registeredRoutes[(string) $route['method'] . ' ' . (string) $route['path']] = true;
}
$requiredRoutes = [
    'GET /api/public/branding',
    'GET /api/admin/security/sessions',
    'DELETE /api/admin/security/sessions/{session_id}',
    'DELETE /api/admin/account',
    'GET /api/admin/workbench',
    'PUT /api/admin/workbench/public-profile',
    'GET /api/admin/sponsors',
    'POST /api/admin/sponsors',
    'PUT /api/admin/sponsors/{sponsor_id}',
    'DELETE /api/admin/sponsors/{sponsor_id}',
    'POST /api/admin/community/posts/{post_id}/pin',
    'POST /api/admin/community/posts/{post_id}/reports',
    'POST /api/admin/apps/{app_id}/key/verify',
];
$routesCovered = true;
foreach ($requiredRoutes as $route) {
    $path = substr($route, strpos($route, ' ') + 1);
    $routesCovered = $routesCovered
        && isset($registeredRoutes[$route])
        && isset($catalogRoutes[$route])
        && str_contains($source['routes_doc'], '`' . $path . '`')
        && str_contains($source['api_full'], '`' . $path . '`')
        && str_contains($source['api_html'], '"path":"' . $path . '"');
}

$seedsMirrored = $actualSeeds === $expectedSeeds;
foreach ($expectedSeeds as [$name, $description, $sortOrder]) {
    $seedsMirrored = $seedsMirrored
        && str_contains($source['migration'], "'{$name}'")
        && str_contains($source['migration'], "'{$description}'")
        && str_contains($source['migration'], (string) $sortOrder)
        && str_contains($source['install'], "'{$name}'")
        && str_contains($source['install'], "'{$description}'");
}

$checks = [
    'one canonical Chinese source taxonomy is shared by app creation and migration' =>
        $seedsMirrored
        && (str_contains($source['app_controller'], 'SubmissionInspectionService::seedResourceCategories((int) $admin[\'id\'], $id, \'source_market\')')
            || str_contains($source['app_controller'], 'SubmissionInspectionService::seedResourceCategories((int) $admin[\'id\'], $appId, \'source_market\')'))
        && !str_contains($source['app_controller'], 'function seedSourceCategories(')
        && str_contains($source['migration'], 'UPDATE resources AS resource')
        && str_contains($source['migration'], "WHEN 'Rust' THEN '其他源码'")
        && str_contains($source['migration'], 'SET status = 0, updated_at = NOW()'),
    'workbench branding sponsors security account community and key verification routes are generated everywhere' =>
        $routesCovered,
    'workbench aggregates profile quotas devices public profile and ranked sponsors' =>
        str_contains($source['workbench_controller'], 'public static function overview(')
        && str_contains($source['workbench_controller'], "'active_devices'")
        && str_contains($source['workbench_controller'], "'api_apps_remaining'")
        && str_contains($source['workbench_controller'], "'public_profile'")
        && str_contains($source['workbench_controller'], "'sponsors'")
        && str_contains($source['workbench_controller'], 'ORDER BY amount DESC, paid_at ASC, id ASC'),
    'sponsor records are manually confirmed and audited instead of pretending to be payment callbacks' =>
        str_contains($source['workbench_controller'], 'public static function createSponsor(')
        && str_contains($source['workbench_controller'], "['manual', 'alipay', 'wechat', 'other']")
        && str_contains($source['workbench_controller'], "'赞助记录已登记并自动排序'")
        && str_contains($source['workbench_controller'], 'LogService::adminOperation('),
    'payment QR configuration accepts only validated URLs and has no fake avatar upload' =>
        str_contains($source['branding_service'], "'alipay_qr_url', 'wechat_qr_url'")
        && str_contains($source['branding_service'], 'self::assertUrl($field, $value)')
        && !str_contains($source['workbench_controller'], 'ProfileAvatarService')
        && !str_contains($source['workbench_controller'], 'uploadPaymentQr')
        && !str_contains($source['routes'], '/payment-qr'),
    'security sessions expose current and active state while account deletion is confirmed and revokes tokens' =>
        str_contains($source['auth_controller'], 'public static function sessions(')
        && str_contains($source['auth_controller'], "\$item['is_current']")
        && str_contains($source['auth_controller'], "\$item['active']")
        && str_contains($source['auth_controller'], 'public static function revokeSession(')
        && str_contains($source['auth_controller'], "\$data['confirm'] !== '注销账号'")
        && str_contains($source['auth_controller'], 'Password::verify(')
        && str_contains($source['auth_controller'], 'UPDATE admin_tokens SET revoked_at = COALESCE(revoked_at, NOW())'),
    'community pin is scope controlled and reports are idempotent pending records' =>
        str_contains($source['community_controller'], 'LevelForumService::pin(')
        && str_contains($source['community_controller'], 'LevelForumService::report(')
        && str_contains($source['forum_service'], "throw new HttpException('无权置顶该交流帖子'")
        && str_contains($source['forum_service'], "status IN (?, ?)")
        && str_contains($source['forum_service'], 'INSERT INTO level_forum_reports'),
    'app key verification includes live token state account ownership and unique id' =>
        str_contains($source['app_controller'], 'public static function verifyKey(')
        && str_contains($source['app_controller'], "'token_valid' => true")
        && str_contains($source['app_controller'], "'app_key_valid' => true")
        && str_contains($source['app_controller'], "'api_unique_id' => (string) \$app['app_key']"),
    'management migration owns all new schema and is registered' =>
        str_contains($source['migration'], 'CREATE TABLE IF NOT EXISTS admin_public_profiles')
        && str_contains($source['migration'], 'CREATE TABLE IF NOT EXISTS admin_sponsor_records')
        && str_contains($source['migration'], 'CREATE TABLE IF NOT EXISTS level_forum_reports')
        && str_contains($source['migration'], "'2026.08.11-management-shell-restructure'"),
    'global check requires and executes this management contract and migration' =>
        str_contains($source['check'], "'database\\migrations\\upgrade_20260811_management_shell_restructure.sql'")
        && str_contains($source['check'], "'tools\\test-management-shell-contract.php'")
        && str_contains($source['check'], "Join-Path \$root 'tools\\test-management-shell-contract.php'")
        && str_contains($source['check'], 'Management-shell contract checks: passed'),
];

// Exercise URL validation without touching a database or remote service.
$assertUrl = new ReflectionMethod(Yiyunying\Services\AdminBrandingService::class, 'assertUrl');
$assertUrl->setAccessible(true);
$assertUrl->invoke(null, 'alipay_qr_url', 'https://static.example.test/alipay.png');
$invalidQrRejected = false;
try {
    $assertUrl->invoke(null, 'wechat_qr_url', 'javascript:alert(1)');
} catch (Throwable $exception) {
    $cause = $exception->getPrevious() ?? $exception;
    $invalidQrRejected = $cause instanceof Yiyunying\Core\HttpException && $cause->httpStatus === 422;
}
$checks['payment QR runtime validator rejects non-http schemes'] = $invalidQrRejected;

$canonicalCategory = new ReflectionMethod(
    Yiyunying\Services\SubmissionInspectionService::class,
    'canonicalCategory'
);
$canonicalCategory->setAccessible(true);
$classificationCases = [
    [['', 'source_market', 'Android Java 登录示例', ''], 'Android Java 源码'],
    [['', 'source_market', 'Vue JavaScript 前端', ''], 'JavaScript 源码'],
    [['', 'source_market', 'style.css Web 界面', ''], 'Web 源码'],
    [['', 'source_market', 'main.cpp', ''], 'C/C++ 源码'],
    [['Rust', 'source_market', 'Rust 工程', ''], '其他源码'],
    [['通用模块', 'source_market', '好友聊天模块', ''], '通用模块'],
];
$classificationPassed = true;
foreach ($classificationCases as [$arguments, $expected]) {
    $classificationPassed = $classificationPassed
        && $canonicalCategory->invoke(null, ...$arguments) === $expected;
}
$checks['legacy names and representative source titles resolve to the canonical taxonomy'] =
    $classificationPassed;

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Management-shell contract failed: {$name}\n");
        exit(1);
    }
}

echo "Management-shell contract: passed\n";
