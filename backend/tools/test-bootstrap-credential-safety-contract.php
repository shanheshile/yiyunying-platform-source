<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$install = (string) file_get_contents($root . '/database/install.sql');
$checker = (string) file_get_contents($root . '/tools/check-deployment.ps1');
$deployDoc = (string) file_get_contents($root . '/deploy/DEPLOY.md');
$apiGenerator = (string) file_get_contents($root . '/tools/generate-api-html.php');
$publicApiDoc = (string) file_get_contents($root . '/public/api-docs.html');

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach ([
    'yiyunying-demo-secret-2026',
    'change-me-demo-secret',
    'YY-DEMO-123456',
    'pbkdf2_sha256$120000$yiyunying-install-20260712$',
    'pbkdf2_sha256$120000$yiyunying-user-20260712$',
] as $forbidden) {
    $assert(!str_contains($install, $forbidden), 'install.sql 仍包含已知凭据：' . $forbidden);
}

foreach ([
    '@YY_BOOTSTRAP_ROOT_PLATFORM_KEY',
    '@YY_BOOTSTRAP_ROOT_ACCOUNT',
    '@YY_BOOTSTRAP_ROOT_PASSWORD_HASH',
    '@YY_BOOTSTRAP_AUTHORIZED_PLATFORM_KEY',
    '@YY_BOOTSTRAP_AUTHORIZED_ACCOUNT',
    '@YY_BOOTSTRAP_AUTHORIZED_PASSWORD_HASH',
    '@YY_BOOTSTRAP_ADMIN_ACCOUNT',
    '@YY_BOOTSTRAP_ADMIN_PASSWORD_HASH',
    '@YY_BOOTSTRAP_APP_KEY',
    '@YY_BOOTSTRAP_APP_SECRET_HASH',
    '@YY_BOOTSTRAP_USER_UID',
    '@YY_BOOTSTRAP_USER_ACCOUNT',
    '@YY_BOOTSTRAP_USER_PASSWORD_HASH',
] as $requiredVariable) {
    $assert(str_contains($install, $requiredVariable), 'install.sql 缺少显式引导变量：' . $requiredVariable);
}

$assert(
    str_contains($install, "SET @yy_disabled_password_hash = CONCAT('disabled$', SHA2(CONCAT(UUID(), UUID(), RAND(), NOW(6)), 256));"),
    '未配置密码时必须生成随机不可认证哈希'
);
$assert(
    str_contains($install, 'SET @yy_disabled_app_secret_hash = SHA2(CONCAT(UUID(), UUID(), RAND(), NOW(6)), 256);'),
    '未配置 app_secret 时必须生成随机哈希'
);
$assert(substr_count($install, '`status` = VALUES(`status`)') >= 4, '各级占位身份必须保持条件 status');
$assert(
    str_contains($install, "'bootstrap-disabled', '未配置支付通道', '{\"gateway_url\":\"\"}', 0"),
    '全新安装不得启用已知支付密钥'
);
$assert(
    str_contains($install, "'BOOTSTRAP-DISABLED-CARD'") && str_contains($install, 'ON DUPLICATE KEY UPDATE `status` = 0'),
    '全新安装不得产生公开可兑换卡密'
);

$paramEnd = strpos($checker, '$ErrorActionPreference');
$paramBlock = $paramEnd === false ? $checker : substr($checker, 0, $paramEnd);
foreach ([
    'BaseUrl', 'PlatformAccount', 'PlatformPassword', 'PlatformKey', 'AdminAccount',
    'AdminPassword', 'AppKey', 'UserAccount', 'UserPassword',
] as $parameter) {
    $assert(
        preg_match('/\[Parameter\(Mandatory\s*=\s*\$true\)\][^\r\n]*\$' . preg_quote($parameter, '/') . '\b/', $paramBlock) === 1,
        '生产检查参数必须 Mandatory：' . $parameter
    );
    $assert(
        preg_match('/\$' . preg_quote($parameter, '/') . '\s*=/', $paramBlock) !== 1,
        '生产检查参数不得有默认值：' . $parameter
    );
}

$assert(substr_count($paramBlock, '[System.Security.SecureString]') === 3, '三个登录密码都必须使用 SecureString');
foreach (['appht.jjmxg.xyz', "'123456'", 'yiyunying-root', 'yiyunying-demo'] as $forbidden) {
    $assert(!str_contains($checker, $forbidden), '生产检查脚本仍包含固定生产身份：' . $forbidden);
}
$assert(str_contains($checker, '[Uri]::TryCreate'), '生产检查脚本必须验证显式 BaseUrl');
$assert(str_contains($checker, 'platform_key = $PlatformKey'), '平台登录必须提交显式 PlatformKey');
$assert(substr_count($checker, 'app_key = $AppKey') >= 2, '管理员和用户登录必须提交显式 AppKey');

$assert(str_contains($deployDoc, '首次安装身份注入'), '部署文档缺少首次安装身份注入说明');
$assert(str_contains($deployDoc, 'status=0'), '部署文档必须说明未注入身份保持禁用');
$assert(str_contains($deployDoc, 'password_hash()'), '部署文档必须说明密码哈希生成方式');
$assert(str_contains($deployDoc, '-AsSecureString'), '部署文档必须示范安全传入检查密码');

foreach ([$apiGenerator, $publicApiDoc] as $publicCredentialSource) {
    $assert(!str_contains($publicCredentialSource, '默认测试账号'), '公开 API 文档不得宣称存在默认测试账号');
    $assert(!str_contains($publicCredentialSource, 'root / 123456'), '公开 API 文档不得展示已知平台凭据');
    $assert(!str_contains($publicCredentialSource, 'yiyunying-demo-secret-2026'), '公开 API 文档不得展示固定 app_secret');
}
$assert(str_contains($apiGenerator, '不提供默认登录身份'), 'API 文档生成器必须保留无默认身份提示');
$assert(str_contains($publicApiDoc, '不提供默认登录身份'), '生成后的公开 API 文档必须保留无默认身份提示');

if ($failures !== []) {
    fwrite(STDERR, "默认凭据安全合同失败：\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "默认凭据安全合同：通过（显式注入、禁用占位、检查参数无默认值）\n";
