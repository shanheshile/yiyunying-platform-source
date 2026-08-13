<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/bootstrap.php';

use Yiyunying\Core\HttpException;
use Yiyunying\Core\Password;
use Yiyunying\Services\LoginAttemptService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$originalSecurity = $GLOBALS['yiyunying_config']['security'] ?? [];
$GLOBALS['yiyunying_config']['security']['password_min_length'] = 8;
$GLOBALS['yiyunying_config']['security']['login_failure_window_seconds'] = 900;
$GLOBALS['yiyunying_config']['security']['login_failure_identity_ip_limit'] = 5;
$GLOBALS['yiyunying_config']['security']['login_failure_identity_limit'] = 15;
$GLOBALS['yiyunying_config']['security']['login_failure_ip_limit'] = 30;

try {
    foreach (['123456', ' 123456 ', 'PASSWORD', 'Admin123', '11111111'] as $weakPassword) {
        $assert(Password::isKnownWeak($weakPassword), 'known weak password was accepted: ' . $weakPassword);
        $assert(!Password::isAcceptable($weakPassword), 'known weak password passed the new-password policy: ' . $weakPassword);
    }
    $assert(Password::isAcceptable('R4ndom!Pass-2026'), 'independent strong password was rejected');
    try {
        Password::assertAcceptable('123456');
        $assert(false, 'assertAcceptable did not reject 123456');
    } catch (HttpException $exception) {
        $assert($exception->httpStatus === 422, 'weak-password rejection must be a validation error');
    }

    $assert(!LoginAttemptService::blockedByCounts(4, 14, 29), 'login limiter blocked below every threshold');
    $assert(LoginAttemptService::blockedByCounts(5, 0, 0), 'account+IP threshold was not enforced');
    $assert(LoginAttemptService::blockedByCounts(0, 15, 0), 'account threshold was not enforced');
    $assert(LoginAttemptService::blockedByCounts(0, 0, 30), 'IP threshold was not enforced');

    $dashboard = (string) file_get_contents($root . '/app/Controllers/Platform/DashboardController.php');
    $dataConsole = (string) file_get_contents($root . '/app/Controllers/Platform/DataConsoleController.php');
    $appService = (string) file_get_contents($root . '/app/Services/AppService.php');
    $config = (string) file_get_contents($root . '/config/app.php');
    $envExample = (string) file_get_contents($root . '/.env.example');
    $platformService = (string) file_get_contents($root . '/app/Services/PlatformService.php');
    $install = (string) file_get_contents($root . '/database/install.sql');
    $loginLimiter = (string) file_get_contents($root . '/app/Services/LoginAttemptService.php');

    $assert(str_contains($dashboard, "unset(\$item['app_secret_hash'])"), 'platform app list still exposes app_secret_hash');
    $assert(str_contains($dashboard, "unset(\$app['app_secret_hash'])"), 'platform app detail still exposes app_secret_hash');
    $assert(str_contains($platformService, "unset(\$app['app_secret_hash'])"), 'owned platform-app records still carry app_secret_hash into logs or downstream responses');
    $assert(substr_count($appService, "unset(\$app['app_secret_hash'])") >= 2, 'application lookups still carry app_secret_hash into logs or downstream responses');

    $assert(str_contains($config, "'data_console_enabled' => \$envBool('DATA_CONSOLE_ENABLED', false)"), 'data console environment gate is not default-off');
    $assert(str_contains($envExample, 'DATA_CONSOLE_ENABLED=false'), '.env.example does not keep data console disabled');
    $assert(str_contains($platformService, "'data_console_enabled' => false"), 'platform setting default still enables data console');
    $assert(str_contains($install, "'data_console_enabled', '0', 'bool'"), 'fresh install still enables data console');
    $assert(str_contains($dataConsole, "config('security.data_console_enabled', false)"), 'data console does not enforce the environment gate');
    $assert(str_contains($dataConsole, "PlatformService::setting((int) \$actor['id'], 'data_console_enabled', false)"), 'data console platform gate is not default-off');
    $assert(!str_contains($dataConsole, 'SELECT * FROM `{$table}`'), 'data console still selects hidden columns from generic tables');
    foreach ([
        'platform_accounts', 'admins', 'apps', 'users', 'app_api_keys', 'verification_codes',
        'identity_bindings', 'card_login_bindings', 'payment_channels', 'system_error_logs',
    ] as $hiddenTable) {
        $assert(str_contains($dataConsole, "'{$hiddenTable}'"), 'sensitive table is not hidden from data console: ' . $hiddenTable);
    }
    foreach (['publicColumns', 'isSensitiveColumn', '_hash$', 'api_key'] as $guard) {
        $assert(str_contains($dataConsole, $guard), 'data console sensitive-column guard missing: ' . $guard);
    }

    $passwordEntryPoints = [
        '/app/Controllers/Platform/AuthController.php',
        '/app/Controllers/Platform/OperatorController.php',
        '/app/Controllers/Platform/AdminController.php',
        '/app/Controllers/Admin/AuthController.php',
        '/app/Controllers/Admin/UserController.php',
        '/app/Controllers/User/AuthController.php',
        '/app/Services/AdminProvisionService.php',
    ];
    foreach ($passwordEntryPoints as $relativePath) {
        $source = (string) file_get_contents($root . $relativePath);
        $assert(
            str_contains($source, 'Password::assertAcceptable') || str_contains($source, 'Password::isAcceptable'),
            'password write entry point does not use the unified policy: ' . $relativePath
        );
    }

    foreach ([
        '/app/Controllers/Platform/AuthController.php' => 'assertPlatformAllowed',
        '/app/Controllers/Admin/AuthController.php' => 'assertAdminAllowed',
        '/app/Controllers/User/AuthController.php' => 'assertUserAllowed',
    ] as $relativePath => $method) {
        $source = (string) file_get_contents($root . $relativePath);
        $assert(str_contains($source, "LoginAttemptService::{$method}"), 'login limiter missing from ' . $relativePath);
    }
    $assert(str_contains($loginLimiter, "new HttpException('登录失败次数过多，请稍后再试', 429, 429"), 'login limiter must return HTTP 429');
    $assert(str_contains($loginLimiter, 'created_at >= ?'), 'login limiter must use a bounded sliding window');
} finally {
    $GLOBALS['yiyunying_config']['security'] = $originalSecurity;
}

if ($failures !== []) {
    fwrite(STDERR, "Account security boundary contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Account security boundary contract: passed\n";
