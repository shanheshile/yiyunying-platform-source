<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use Yiyunying\Services\MaintenanceWriteGuard;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Router;

$paths = [
    'router' => $root . '/app/Core/Router.php',
    'guard' => $root . '/app/Services/MaintenanceWriteGuard.php',
    'lifecycle' => $root . '/app/Services/LifecycleService.php',
    'request' => $root . '/app/Core/Request.php',
    'config' => $root . '/config/app.php',
    'env_example' => $root . '/.env.example',
    'deploy_doc' => $root . '/deploy/DEPLOY.md',
    'php_fpm_env' => $root . '/deploy/php-fpm-env.example',
    'routes' => $root . '/routes/api.php',
    'schema' => $root . '/database/install.sql',
];
$source = [];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Maintenance write guard contract missing {$name}: {$path}\n");
        exit(1);
    }
    $source[$name] = (string) file_get_contents($path);
}

$method = static function (string $name): ReflectionMethod {
    $reflection = new ReflectionMethod(MaintenanceWriteGuard::class, $name);
    $reflection->setAccessible(true);
    return $reflection;
};
$isWrite = $method('isWriteMethod');
$isRecovery = $method('isRecoveryPath');
$routeAppId = $method('routeAppId');
$shouldBlock = $method('shouldBlock');
$failureData = $method('maintenanceFailureData');
$providedAppKey = $method('providedAppKey');

$appKeyRequest = static function (?string $headerKey, ?string $bodyKey, ?string $queryKey = null): Request {
    $savedServer = $_SERVER;
    $savedGet = $_GET;
    $savedPost = $_POST;
    try {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/user/register',
            'REMOTE_ADDR' => '198.51.100.10',
        ];
        if ($headerKey !== null) {
            $_SERVER['HTTP_X_APP_KEY'] = $headerKey;
        }
        $_GET = $queryKey === null ? [] : ['app_key' => $queryKey];
        $_POST = $bodyKey === null ? [] : ['app_key' => $bodyKey];
        return new Request();
    } finally {
        $_SERVER = $savedServer;
        $_GET = $savedGet;
        $_POST = $savedPost;
    }
};

$appIdentityMismatchRejected = static function (Request $request) use ($providedAppKey): bool {
    try {
        $providedAppKey->invoke(null, $request);
        return false;
    } catch (HttpException $exception) {
        return $exception->httpStatus === 422
            && ($exception->data['reason_code'] ?? '') === 'app_identity_mismatch';
    }
};
$ipAllowlisted = new ReflectionMethod(Yiyunying\Services\LifecycleService::class, 'clientIpAllowlisted');
$ipAllowlisted->setAccessible(true);

$checks = [
    'only state-changing HTTP methods enter the guard' =>
        $isWrite->invoke(null, 'POST') === true
        && $isWrite->invoke(null, 'PUT') === true
        && $isWrite->invoke(null, 'DELETE') === true
        && $isWrite->invoke(null, 'GET') === false
        && $isWrite->invoke(null, 'OPTIONS') === false,
    'single application identity sources resolve with body query header precedence' =>
        $providedAppKey->invoke(null, $appKeyRequest('APP_A', null, null)) === 'APP_A'
        && $providedAppKey->invoke(null, $appKeyRequest(null, 'APP_A', null)) === 'APP_A'
        && $providedAppKey->invoke(null, $appKeyRequest(null, null, 'APP_A')) === 'APP_A'
        && $providedAppKey->invoke(null, $appKeyRequest(null, null, null)) === '',
    'matching application identity sources resolve to one tenant' =>
        $providedAppKey->invoke(null, $appKeyRequest('APP_A', 'APP_A', 'APP_A')) === 'APP_A',
    'conflicting application identity sources fail closed before tenant lookup' =>
        $appIdentityMismatchRejected($appKeyRequest('APP_B', 'APP_A', null))
        && $appIdentityMismatchRejected($appKeyRequest(null, 'APP_A', 'APP_B'))
        && $appIdentityMismatchRejected($appKeyRequest('APP_B', null, 'APP_A')),
    'login logout refresh password recovery and password change remain reachable' =>
        $isRecovery->invoke(null, '/api/platform/login')
        && $isRecovery->invoke(null, '/api/platform/logout')
        && $isRecovery->invoke(null, '/api/admin/login')
        && $isRecovery->invoke(null, '/api/admin/logout')
        && $isRecovery->invoke(null, '/api/user/login')
        && $isRecovery->invoke(null, '/api/user/logout')
        && $isRecovery->invoke(null, '/api/user/token/refresh')
        && $isRecovery->invoke(null, '/api/user/password')
        && $isRecovery->invoke(null, '/api/user/password/reset/code')
        && $isRecovery->invoke(null, '/api/user/password/reset'),
    'card login and exact maintenance control routes remain reachable' =>
        $isRecovery->invoke(null, '/api/public/card-login')
        && $isRecovery->invoke(null, '/api/public/card-auto-login')
        && $isRecovery->invoke(null, '/api/platform/maintenances')
        && $isRecovery->invoke(null, '/api/platform/maintenances/42')
        && $isRecovery->invoke(null, '/api/admin/apps/7/maintenances')
        && $isRecovery->invoke(null, '/api/admin/apps/7/maintenances/42'),
    'ordinary and similarly named business writes are not allowlisted' =>
        !$isRecovery->invoke(null, '/api/user/forum-posts')
        && !$isRecovery->invoke(null, '/api/user/password/reset/extra')
        && !$isRecovery->invoke(null, '/api/admin/apps/7/maintenance-reports')
        && !$isRecovery->invoke(null, '/api/admin/apps/7/festival-themes')
        && !$isRecovery->invoke(null, '/api/public/payment/callback/demo')
        && !$isRecovery->invoke(null, '/api/public/verification-code/email'),
    'route app resolution cannot bypass maintenance through controller integer casts' =>
        $routeAppId->invoke(null, '7') === 7
        && $routeAppId->invoke(null, '7abc') === null
        && $routeAppId->invoke(null, '+7') === null
        && $routeAppId->invoke(null, '7.9') === null
        && $routeAppId->invoke(null, 'abc7') === null
        && $routeAppId->invoke(null, '0') === null,
    'only forced active maintenance blocks writes' =>
        $shouldBlock->invoke(null, null) === false
        && $shouldBlock->invoke(null, ['forced' => 0]) === false
        && $shouldBlock->invoke(null, ['forced' => '0']) === false
        && $shouldBlock->invoke(null, ['forced' => 1]) === true
        && $shouldBlock->invoke(null, ['forced' => '1']) === true
        && $shouldBlock->invoke(null, ['forced' => 2]) === true,
    'maintenance IP allowlist treats equivalent IPv6 spellings as the same address' =>
        $ipAllowlisted->invoke(null, '2001:db8::1', ['2001:0db8:0:0:0:0:0:1']) === true
        && $ipAllowlisted->invoke(null, '2001:db8::1', ['[2001:db8::1]']) === true
        && $ipAllowlisted->invoke(null, '203.0.113.7', ['203.0.113.7']) === true
        && $ipAllowlisted->invoke(null, '203.0.113.7', ['203.0.113.8']) === false,
    'untrusted maintenance response contains no tenant policy details' =>
        $failureData->invoke(null, [
            'id' => 42, 'title' => 'private title', 'message' => 'private message',
            'ends_at' => '2099-01-01 00:00:00',
        ], false) === ['reason_code' => 'app_maintenance', 'retry_after' => 3600],
    'only a valid user token response may receive the maintenance end time' =>
        $failureData->invoke(null, ['ends_at' => '2099-01-01 00:00:00'], true) === [
            'reason_code' => 'app_maintenance', 'retry_after' => 3600,
            'ends_at' => '2099-01-01 00:00:00',
        ],
];

$clientIp = static function (string $remoteAddress, ?string $forwardedFor, array $trustedProxies): string {
    $savedServer = $_SERVER;
    $savedGet = $_GET;
    $savedPost = $_POST;
    $savedConfig = $GLOBALS['yiyunying_config'];
    try {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => $remoteAddress,
        ];
        if ($forwardedFor !== null) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }
        $_GET = [];
        $_POST = [];
        $GLOBALS['yiyunying_config']['security']['trusted_proxies'] = $trustedProxies;
        return (new Request())->clientIp();
    } finally {
        $_SERVER = $savedServer;
        $_GET = $savedGet;
        $_POST = $savedPost;
        $GLOBALS['yiyunying_config'] = $savedConfig;
    }
};

$checks['forwarded addresses are ignored by default and cannot be spoofed through an untrusted peer'] =
    $clientIp('198.51.100.10', '203.0.113.42', []) === '198.51.100.10'
    && $clientIp('198.51.100.10', '203.0.113.42', ['10.0.0.1']) === '198.51.100.10';
$checks['explicit IPv4 proxy addresses and CIDRs return the first valid forwarded client'] =
    $clientIp('10.0.0.1', '203.0.113.42, 10.0.0.2', ['10.0.0.1']) === '203.0.113.42'
    && $clientIp('10.20.30.40', 'unknown, invalid, 198.51.100.7, 10.0.0.2', ['10.0.0.0/8']) === '198.51.100.7';
$checks['explicit IPv6 proxy addresses and CIDRs are normalized and supported'] =
    $clientIp('2001:0db8::1', '2001:db8:abcd::42, 198.51.100.7', ['2001:db8::1']) === '2001:db8:abcd::42'
    && $clientIp('2001:db8:1::9', '[2001:db8:ffff::5]', ['2001:db8::/32']) === '2001:db8:ffff::5';
$checks['empty or wholly malformed forwarded data fails safely to the direct peer'] =
    $clientIp('10.0.0.1', '', ['10.0.0.1']) === '10.0.0.1'
    && $clientIp('10.0.0.1', 'unknown, 203.0.113.1:443, bad-ip', ['10.0.0.1']) === '10.0.0.1'
    && $clientIp('10.0.0.1', '203.0.113.42', ['bad-rule', '10.0.0.0/99']) === '10.0.0.1'
    && $clientIp('198.51.100.10', '203.0.113.42', ['0.0.0.0/0']) === '198.51.100.10'
    && $clientIp('2001:db8::10', '2001:db8:ffff::5', ['::/0']) === '2001:db8::10'
    && $clientIp('not-an-ip', '203.0.113.42', ['0.0.0.0/0']) === '0.0.0.0';
$checks['trusted proxy configuration is explicit documented and empty by default'] =
    str_contains($source['config'], "explode(',', (string) \$env('TRUSTED_PROXIES', ''))")
    && str_contains($source['env_example'], 'TRUSTED_PROXIES=')
    && str_contains($source['env_example'], 'exact IPv4/IPv6 addresses or CIDRs')
    && str_contains($source['deploy_doc'], 'TRUSTED_PROXIES=10.20.0.0/16,2001:db8:100::/48')
    && str_contains($source['deploy_doc'], '覆盖而不是追加保留')
    && str_contains($source['php_fpm_env'], 'env[TRUSTED_PROXIES] = ""')
    && str_contains($source['request'], "config('security.trusted_proxies', [])")
    && str_contains($source['request'], 'self::isTrustedProxy($remoteAddress, $trustedProxies)');

$originalServer = $_SERVER;
$originalGet = $_GET;
$originalPost = $_POST;
try {
    $_SERVER['REQUEST_METHOD'] = 'PUT';
    $_SERVER['REQUEST_URI'] = '/api/admin/apps/7abc/probe';
    $_GET = [];
    $_POST = [];
    $router = new Router();
    $router->put('/api/admin/apps/{app_id}/probe', static fn() => Response::success());
    $malformedRejected = false;
    try {
        $router->dispatch(new Request());
    } catch (HttpException $exception) {
        $malformedRejected = $exception->httpStatus === 422;
    }
    $checks['router rejects non-canonical app IDs before guard and controller integer casts'] = $malformedRejected;
} finally {
    $_SERVER = $originalServer;
    $_GET = $originalGet;
    $_POST = $originalPost;
}

$routeParams = strpos($source['router'], "setAttribute('route_params', \$params)");
$requestedApp = strpos($source['router'], "setAttribute('requested_app_id'");
$guardCall = strpos($source['router'], 'MaintenanceWriteGuard::enforce($request, $params)');
$handlerCall = strpos($source['router'], "call_user_func(\$route['handler']");
$checks['router enforces after exact route resolution and before the controller'] =
    $routeParams !== false && $requestedApp !== false && $guardCall !== false && $handlerCall !== false
    && $routeParams < $requestedApp && $requestedApp < $guardCall && $guardCall < $handlerCall;

$checks['public lifecycle and write guard share one active-policy selector'] =
    str_contains($source['lifecycle'], 'public static function activeMaintenance(')
    && str_contains($source['lifecycle'], '$activeMaintenance = self::activeMaintenance($context, $clientIp);')
    && str_contains($source['guard'], 'LifecycleService::activeMaintenance($context, $request->clientIp())')
    && substr_count($source['lifecycle'], 'FROM maintenance_policies m') === 1;

$checks['policy matching is status and time bounded and tenant scoped'] =
    str_contains($source['lifecycle'], "m.edition_code IN ('all', ?)")
    && str_contains($source['lifecycle'], 'm.status = 1')
    && str_contains($source['lifecycle'], 'm.starts_at IS NULL OR m.starts_at <= NOW()')
    && str_contains($source['lifecycle'], 'm.ends_at IS NULL OR m.ends_at > NOW()')
    && str_contains($source['lifecycle'], "m.issuer_type = 'platform' AND m.issuer_id IN")
    && str_contains($source['lifecycle'], "m.issuer_type = 'admin' AND m.issuer_id = ?")
    && str_contains($source['lifecycle'], "target_type = 'app' AND target_id = ?")
    && str_contains($source['lifecycle'], "target_type = 'admin' AND target_id = ?")
    && str_contains($source['lifecycle'], "target_type = 'platform' AND target_id = ?")
    && str_contains($source['lifecycle'], "target_type = 'level' AND target_level = ?")
    && str_contains($source['lifecycle'], "target_type = 'global'");

$checks['guard resolves only explicit app-bound namespaces and preserves cross-tenant controller auth'] =
    str_contains($source['guard'], "#^/api/(?:admin|platform)/apps/[^/]+(?:/|$)#")
    && str_contains($source['guard'], "str_starts_with(\$request->path(), '/api/user/')")
    && str_contains($source['guard'], "str_starts_with(\$request->path(), '/api/public/')")
    && str_contains($source['guard'], 'SELECT t.app_id, a.app_key FROM user_tokens t')
    && str_contains($source['guard'], 'SELECT r.app_id FROM user_refresh_tokens r')
    && str_contains($source['guard'], "hash_equals((string) \$row['app_key'], \$providedAppKey)")
    && !str_contains($source['guard'], "str_starts_with(\$request->path(), '/api/admin/')")
    && !str_contains($source['guard'], "str_starts_with(\$request->path(), '/api/platform/')");

$checks['valid bearer token rejects every explicit cross-application identity'] =
    str_contains(
        $source['guard'],
        "if (\$providedAppKey !== '' && !hash_equals((string) \$row['app_key'], \$providedAppKey)) {\n"
        . "                    throw self::appIdentityMismatch();\n"
        . '                }'
    );

$httpExceptionCatch = strpos($source['guard'], 'catch (HttpException $exception)');
$throwableCatch = strpos($source['guard'], 'catch (Throwable)');
$checks['intentional identity errors are rethrown before generic database fail closed'] =
    $httpExceptionCatch !== false && $throwableCatch !== false && $httpExceptionCatch < $throwableCatch
    && str_contains($source['guard'], "catch (HttpException \$exception) {\n            throw \$exception;\n        }");

$checks['maintenance lookup failure is fail closed with a generic 503'] =
    str_contains($source['guard'], 'catch (Throwable)')
    && str_contains($source['guard'], "'reason_code' => 'maintenance_state_unavailable'")
    && substr_count($source['guard'], "throw new HttpException(") >= 2
    && substr_count($source['guard'], "\n                503,\n                503,") >= 1;

$checks['schema supports active windows target hierarchy priorities and IP allowlists'] =
    str_contains($source['schema'], 'CREATE TABLE IF NOT EXISTS `maintenance_policies`')
    && str_contains($source['schema'], '`target_type` VARCHAR(20) NOT NULL')
    && str_contains($source['schema'], '`target_id` BIGINT UNSIGNED DEFAULT NULL')
    && str_contains($source['schema'], '`target_level` TINYINT UNSIGNED DEFAULT NULL')
    && str_contains($source['schema'], '`allowlist_json` LONGTEXT')
    && str_contains($source['schema'], '`status` TINYINT NOT NULL DEFAULT 1')
    && str_contains($source['schema'], '`starts_at` DATETIME DEFAULT NULL')
    && str_contains($source['schema'], '`ends_at` DATETIME DEFAULT NULL');

$checks['required recovery and maintenance routes are actually registered'] =
    str_contains($source['routes'], "get('/api/health'")
    && str_contains($source['routes'], "get('/api/public/bootstrap'")
    && str_contains($source['routes'], "get('/api/public/lifecycle'")
    && str_contains($source['routes'], "post('/api/user/token/refresh'")
    && str_contains($source['routes'], "post('/api/user/password/reset/code'")
    && str_contains($source['routes'], "post('/api/user/password/reset'")
    && str_contains($source['routes'], "post('/api/user/logout'")
    && str_contains($source['routes'], "post('/api/admin/logout'")
    && str_contains($source['routes'], "post('/api/platform/logout'")
    && str_contains($source['routes'], "put('/api/admin/apps/{app_id}/maintenances/{policy_id}'")
    && str_contains($source['routes'], "delete('/api/admin/apps/{app_id}/maintenances/{policy_id}'")
    && str_contains($source['routes'], "put('/api/platform/maintenances/{policy_id}'")
    && str_contains($source['routes'], "delete('/api/platform/maintenances/{policy_id}'");

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Maintenance write guard contract failed: {$name}\n");
        exit(1);
    }
}

echo "Maintenance write guard contract: passed\n";
