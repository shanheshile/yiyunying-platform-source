<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$generator = (string) file_get_contents($root . '/tools/generate-upgrade.php');
$auditor = (string) file_get_contents($root . '/tools/audit-default-credentials.php');
$check = (string) file_get_contents($root . '/tools/check.ps1');

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach ([
    'authorized / 123456',
    'pbkdf2_sha256$120000$yiyunying-install-20260712$',
    "'yiyunying-authorized', 'authorized'",
] as $forbiddenSeed) {
    $assert(!str_contains($generator, $forbiddenSeed), '升级生成器仍包含已知身份种子：' . $forbiddenSeed);
}
$assert(
    str_contains($generator, '增量升级只更新已有租户结构和设置，不创建、启用或重置任何平台、管理员、应用或用户身份'),
    '升级生成器缺少禁止创建身份的安全边界'
);

$assert(str_contains($auditor, "PHP_SAPI !== 'cli'"), '默认凭据审计必须仅允许 CLI');
$assert(str_contains($auditor, 'Database::connection()'), '默认凭据审计必须读取当前部署数据库');
foreach ([
    'platform_accounts WHERE status = 1 AND deleted_at IS NULL',
    'admins WHERE status = 1',
    'users WHERE status = 1 AND deleted_at IS NULL',
    'apps WHERE status = 1 AND deleted_at IS NULL',
] as $activeScope) {
    $assert(str_contains($auditor, $activeScope), '默认凭据审计缺少启用范围：' . $activeScope);
}
$assert(str_contains($auditor, "password_verify('123456', \$hash)"), '现代密码哈希必须验证已知默认密码');
$assert(str_contains($auditor, "Password::verify('123456', \$hash)"), '历史 PBKDF2 哈希必须验证已知默认密码');
$assert(
    str_contains($auditor, 'f91c5f67d4576f675ad08233695845b790f7bc9549386f2a89777aa32f992170'),
    '审计必须检查旧演示 app_secret 的单向指纹'
);
$assert(!str_contains($auditor, 'yiyunying-demo-secret-2026'), '审计工具不得保存旧演示 app_secret 原文');
$assert(
    preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE)\b/i', $auditor) !== 1,
    '默认凭据审计必须保持只读，不得包含数据库写操作'
);
$assert(
    preg_match('/(?:echo|fwrite|printf|sprintf)[^;\r\n]*(?:password_hash|app_secret_hash|knownDemoSecretHash|storedHash|\$hash)/i', $auditor) !== 1,
    '默认凭据审计不得输出哈希或密钥材料'
);
$assert(str_contains($auditor, '仅显示数量和数据库 ID'), '审计输出必须声明只显示数量和 ID');
$assert(str_contains($auditor, 'exit(1);'), '命中默认凭据时必须退出非 0');
$assert(str_contains($auditor, 'exit(2);'), '数据库不可读时必须退出非 0');
$assert(str_contains($auditor, 'exit(0);'), '无命中时必须明确成功退出');

$assert(substr_count($check, 'tools\audit-default-credentials.php') === 1, 'check.ps1 required 必须包含只读审计 CLI');
$assert(substr_count($check, 'tools\test-default-credential-audit-contract.php') >= 2, 'check.ps1 必须 required 并执行离线合同');

if ($failures !== []) {
    fwrite(STDERR, "默认凭据审计静态合同失败：\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "默认凭据审计静态合同：通过（升级无种子、CLI 只读、输出无敏感值）\n";
