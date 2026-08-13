<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adminUser = (string) file_get_contents($root . '/app/Controllers/Admin/UserController.php');
$platformOperator = (string) file_get_contents($root . '/app/Controllers/Platform/OperatorController.php');
$platformAdmin = (string) file_get_contents($root . '/app/Controllers/Platform/AdminController.php');
$adminApp = (string) file_get_contents($root . '/app/Controllers/Admin/AppController.php');
$userAuth = (string) file_get_contents($root . '/app/Controllers/User/AuthController.php');
$credentialAudit = (string) file_get_contents($root . '/tools/audit-default-credentials.php');

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$functionBody = static function (string $source, string $name): string {
    $tokens = token_get_all($source);
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }
        $nameIndex = $index + 1;
        while ($nameIndex < $count) {
            $candidate = $tokens[$nameIndex];
            if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $nameIndex++;
                continue;
            }
            if ($candidate === '&') {
                $nameIndex++;
                continue;
            }
            break;
        }
        if ($nameIndex >= $count || !is_array($tokens[$nameIndex])
            || $tokens[$nameIndex][0] !== T_STRING || $tokens[$nameIndex][1] !== $name) {
            continue;
        }
        $body = '';
        $depth = 0;
        $opened = false;
        for ($cursor = $index; $cursor < $count; $cursor++) {
            $piece = $tokens[$cursor];
            $text = is_array($piece) ? $piece[1] : $piece;
            $body .= $text;
            if (is_array($piece) && in_array($piece[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                // token_get_all emits the matching interpolated-string closing brace as a character token.
                $depth++;
            } elseif (!is_array($piece) && $piece === '{') {
                $depth++;
                $opened = true;
            } elseif (!is_array($piece) && $piece === '}') {
                $depth--;
                if ($opened && $depth === 0) {
                    return $body;
                }
            }
        }
    }
    throw new RuntimeException('Function not found: ' . $name);
};

$userUpdate = $functionBody($adminUser, 'update');
$userReset = $functionBody($adminUser, 'resetPassword');
$userBan = $functionBody($adminUser, 'ban');
$userDelete = $functionBody($adminUser, 'delete');
$userImport = $functionBody($adminUser, 'import');
$userRevoke = $functionBody($adminUser, 'revokeUserSessions');
$assert(str_contains($userUpdate, '$disableRequested') && str_contains($userUpdate, 'self::revokeUserSessions'), '用户状态停用必须在更新事务内撤销会话');
$assert(str_contains($userReset, 'self::revokeUserSessions'), '管理员重置用户密码必须撤销 access 与 refresh');
$assert(!str_contains($userReset, 'identity_bindings') && !str_contains($userReset, 'identity_unbind_requests'), '重置密码不得删除身份绑定或更改解绑申请');
$assert(str_contains($userBan, 'self::revokeUserSessions'), '登录封禁必须撤销 access 与 refresh');
$assert(str_contains($userDelete, 'self::revokeUserSessions'), '删除用户必须撤销 access 与 refresh');
$assert(str_contains($userRevoke, 'UPDATE user_tokens SET revoked_at = NOW()')
    && str_contains($userRevoke, 'UPDATE user_refresh_tokens SET revoked_at = NOW()')
    && substr_count($userRevoke, 'revoked_at IS NULL') === 2, '用户会话辅助方法必须幂等撤销两类令牌');
$assert(!str_contains($userImport, "?? '123456'")
    && str_contains($userImport, "?? ''")
    && str_contains($userImport, "hash_equals('123456', \$password)")
    && str_contains($userImport, 'strlen($password) > 72'), '批量导入必须要求显式强密码并拒绝 123456');

$operatorStatus = $functionBody($platformOperator, 'status');
$assert(str_contains($operatorStatus, 'Database::transaction')
    && str_contains($operatorStatus, 'UPDATE user_tokens SET revoked_at = NOW()')
    && str_contains($operatorStatus, 'UPDATE user_refresh_tokens SET revoked_at = NOW()'), '平台停用必须在同一事务撤销下游 access 与 refresh');

foreach (['update', 'resetPassword', 'delete', 'status'] as $method) {
    $body = $functionBody($platformAdmin, $method);
    $assert(str_contains($body, 'Database::transaction'), '平台管理员 ' . $method . ' 必须使用事务');
    $assert(str_contains($body, 'self::revoke'), '平台管理员 ' . $method . ' 必须撤销自身与下游会话');
}
$adminRevoke = $functionBody($platformAdmin, 'revoke');
$assert(str_contains($adminRevoke, 'UPDATE admin_tokens SET revoked_at = NOW()')
    && str_contains($adminRevoke, 'UPDATE user_tokens SET revoked_at = NOW()')
    && str_contains($adminRevoke, 'UPDATE user_refresh_tokens SET revoked_at = NOW()'), '管理员撤销必须覆盖 admin/access/refresh');

$appDelete = $functionBody($adminApp, 'delete');
$appStatus = $functionBody($adminApp, 'setStatus');
$appRevoke = $functionBody($adminApp, 'revokeAppSessions');
$assert(str_contains($appDelete, 'Database::transaction') && str_contains($appDelete, 'self::revokeAppSessions'), '删除应用必须原子撤销会话');
$assert(str_contains($appStatus, 'Database::transaction') && str_contains($appStatus, 'self::revokeAppSessions'), '停用应用必须原子撤销会话');
$assert(str_contains($appRevoke, 'UPDATE user_tokens SET revoked_at = NOW()')
    && str_contains($appRevoke, 'UPDATE user_refresh_tokens SET revoked_at = NOW()'), '应用会话撤销必须覆盖 access 与 refresh');

$refresh = $functionBody($userAuth, 'refresh');
foreach ([
    'Database::transaction',
    'LIMIT 1 FOR UPDATE',
    'INNER JOIN admins ad',
    'INNER JOIN platform_accounts p',
    "row['user_status']",
    "row['app_status']",
    "row['admin_status']",
    "row['platform_status']",
    'AdminAccessService::context',
    'AdminAccessService::assertDownstreamAccess',
    'UPDATE user_refresh_tokens SET revoked_at = NOW()',
    'UPDATE user_tokens SET revoked_at = NOW()',
    'self::issueUserToken',
] as $requiredFragment) {
    $assert(str_contains($refresh, $requiredFragment), 'refresh 安全闭环缺少：' . $requiredFragment);
}
$refreshRevokeAt = strpos($refresh, 'UPDATE user_refresh_tokens SET revoked_at = NOW()');
$accessRevokeAt = strpos($refresh, 'UPDATE user_tokens SET revoked_at = NOW()');
$issueAt = strpos($refresh, 'self::issueUserToken');
$assert($refreshRevokeAt !== false && $accessRevokeAt !== false && $issueAt !== false
    && $refreshRevokeAt < $issueAt && $accessRevokeAt < $issueAt, 'refresh 必须先撤销旧令牌再签发新令牌');

foreach ([
    'platform_accounts WHERE deleted_at IS NULL',
    'SELECT id, password_hash FROM admins ORDER BY id ASC',
    'users WHERE deleted_at IS NULL',
    'apps WHERE deleted_at IS NULL',
] as $scope) {
    $assert(str_contains($credentialAudit, $scope), '默认凭据审计缺少未删除身份范围：' . $scope);
}
foreach (['platform_accounts', 'admins', 'users', 'apps'] as $table) {
    $assert(!str_contains($credentialAudit, $table . ' WHERE status = 1'), '停用身份不得绕过默认凭据审计：' . $table);
}

if ($failures !== []) {
    fwrite(STDERR, "账号会话撤销静态合同失败：\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "账号会话撤销静态合同：通过（身份绑定保留、停用撤销、refresh 原子轮换、默认凭据全范围）\n";
