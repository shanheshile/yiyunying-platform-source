<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$indexPath = $root . '/public/control/index.php';
$scriptPath = $root . '/public/control/control.js';
$stylePath = $root . '/public/control/control.css';
$routesPath = $root . '/routes/api.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

foreach ([$indexPath, $scriptPath, $stylePath, $routesPath] as $required) {
    $assert(is_file($required), '缺少 Root 总控文件：' . basename($required));
}
if ($failures !== []) {
    fwrite(STDERR, "Root 总控安全合同失败：\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

$index = (string) file_get_contents($indexPath);
$script = (string) file_get_contents($scriptPath);
$style = (string) file_get_contents($stylePath);
$routes = (string) file_get_contents($routesPath);

foreach ([
    "Content-Security-Policy: default-src 'self'",
    "frame-ancestors 'none'",
    "form-action 'self'",
    "connect-src 'self'",
    'Cache-Control: no-store',
    'Referrer-Policy: no-referrer',
    'X-Content-Type-Options: nosniff',
    'X-Frame-Options: DENY',
    'Permissions-Policy:',
    'https://appht.jjmxg.xyz/control/',
] as $securityHeader) {
    $assert(str_contains($index, $securityHeader), '入口缺少安全响应约束：' . $securityHeader);
}
$assert(str_contains($index, "['GET', 'HEAD']"), '入口必须拒绝回退表单 POST，避免凭据被页面处理');
$assert(str_contains($index, 'type="password"') && str_contains($index, 'autocomplete="current-password"'), '登录密码框必须使用密码类型和正确 autocomplete');
$assert(str_contains($index, 'action="/control/" method="post"'), '无脚本回退不得把密码放入查询字符串');
$assert(str_contains($index, 'src="/control/control.js"') && str_contains($index, 'href="/control/control.css"'), '总控必须使用同源外部脚本和样式');
$assert(!preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $index), 'CSP 页面不得包含内联脚本');
$assert(!preg_match('/\sstyle\s*=\s*["\']/i', $index), 'CSP 页面不得包含内联 style 属性');
$assert(!str_contains($index, '123456'), '总控页面不得固化已知默认密码');

foreach (['localStorage', 'sessionStorage', 'indexedDB', 'document.cookie', 'window.name'] as $persistentStore) {
    $assert(!str_contains($script, $persistentStore), '访问令牌不得进入持久客户端状态：' . $persistentStore);
}
foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'eval(', 'new Function'] as $unsafeDom) {
    $assert(!str_contains($script, $unsafeDom), '总控不得使用不安全 DOM/代码执行能力：' . $unsafeDom);
}
$assert(str_contains($script, 'let bearerToken = "";'), 'Bearer 必须仅保存在脚本闭包内存');
$assert(str_contains($script, 'headers.set("Authorization", `Bearer ${authToken}`)'), 'Bearer 必须只通过 Authorization 请求头发送');
$assert(!preg_match('/(?:access_token|bearerToken|authToken)\s*[=:].{0,60}(?:URLSearchParams|searchParams)/s', $script), '访问令牌不得写入 URL');
$assert(str_contains($script, 'passwordInput.value = "";'), '密码提交及退出后必须清空输入框');
$assert(str_contains($script, 'window.addEventListener("pagehide"'), '页面关闭时必须清理内存身份');
$assert(str_contains($script, 'IDLE_TIMEOUT_MS = 15 * 60 * 1000'), 'Root 总控必须有短时闲置退出');
$assert(str_contains($script, 'api.meWith(candidateToken)'), '登录后必须通过服务端 /me 回读身份');
$assert(str_contains($script, 'Number(platform.level) !== 1') && str_contains($script, 'String(platform.account || "") !== ROOT_ACCOUNT'), '必须同时校验一级身份和 root 账号');
$assert(str_contains($script, 'api.logoutWith(candidateToken)') && str_contains($script, 'api.logoutWith(current)'), '拒绝身份和主动退出都必须撤销服务端 Token');
$assert(str_contains($script, 'window.location.protocol !== "https:"'), '客户端必须再次阻断正式 HTTP 登录');
$assert(str_contains($script, 'url.origin !== window.location.origin'), '请求层必须阻断跨源接口');
$assert(str_contains($script, 'TYPED_ROUTES') && str_contains($script, '控制台拒绝未列入类型化白名单的接口'), '必须使用运行时类型化接口白名单');

foreach ([
    '/api/platform/operators',
    '/api/platform/admins',
    '/api/platform/apps',
    '/users/${positiveId(userId, "用户 ID")}/permissions',
    '/api/platform/software-updates',
    '/api/platform/maintenances',
] as $typedCapability) {
    $assert(str_contains($script, $typedCapability), '总控缺少要求的类型化能力：' . $typedCapability);
}
foreach (['/api/platform/data-console', '/impersonate', '/password', '/mail-settings', '/ai-knowledge'] as $forbiddenEndpoint) {
    $assert(!str_contains($script, $forbiddenEndpoint), 'Root 网页总控不得调用高风险或超范围接口：' . $forbiddenEndpoint);
}
foreach (['deleteOperator', 'deleteAdmin', 'deleteApp'] as $permanentDelete) {
    $assert(!str_contains($script, $permanentDelete), '总控不得开放平台、管理员或应用永久删除：' . $permanentDelete);
}
$assert(substr_count($script, 'method: "DELETE"') === 2, '网页总控只允许删除版本策略和维护策略');
$assert(str_contains($script, 'confirmDanger(') && str_contains($script, '确认短语不匹配'), '所有危险写操作必须二次确认并核对短语');
$assert(str_contains($script, 'download.hostname !== "appht.jjmxg.xyz"') && str_contains($script, '!download.pathname.startsWith("/downloads/")'), '版本策略必须约束为主域名 HTTPS 下载路径');
$assert(str_contains($index, 'href="/download-center/api-docs/"') && str_contains($index, 'href="/api-docs.html"'), '总控必须提供具体文档入口');
$assert(!str_contains($style, '@import') && !preg_match('#url\(["\']?https?://#i', $style), '样式不得加载第三方资源');

foreach ([
    "post('/api/platform/login'",
    "get('/api/platform/me'",
    "get('/api/platform/operators'",
    "get('/api/platform/admins'",
    "get('/api/platform/apps'",
    "get('/api/platform/apps/{app_id}/users'",
    "get('/api/platform/apps/{app_id}/users/{user_id}/permissions'",
    "put('/api/platform/apps/{app_id}/users/{user_id}/permissions'",
    "get('/api/platform/software-updates'",
    "post('/api/platform/software-updates'",
    "get('/api/platform/maintenances'",
    "post('/api/platform/maintenances'",
] as $registeredRoute) {
    $assert(str_contains($routes, $registeredRoute), '页面使用的后端路由未注册：' . $registeredRoute);
}

if ($failures !== []) {
    fwrite(STDERR, "Root 总控安全合同失败：\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Root 总控安全合同：通过（HTTPS、Root 回读、内存 Bearer、CSP、类型化 API、二次确认）\n";
