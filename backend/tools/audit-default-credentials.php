<?php

declare(strict_types=1);

use Yiyunying\Core\Database;
use Yiyunying\Core\Password;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

require dirname(__DIR__) . '/bootstrap.php';

/**
 * @return list<int>
 */
function weakPasswordIds(\PDO $pdo, string $sql): array
{
    $ids = [];
    $statement = $pdo->query($sql);
    while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
        $hash = (string) ($row['password_hash'] ?? '');
        $usesLegacyPbkdf2 = strncmp($hash, 'pbkdf2_sha256$', 14) === 0;
        $matchesKnownDefault = $usesLegacyPbkdf2
            ? Password::verify('123456', $hash)
            : password_verify('123456', $hash);
        if ($matchesKnownDefault) {
            $ids[] = (int) $row['id'];
        }
    }
    sort($ids, SORT_NUMERIC);
    return array_values(array_unique($ids));
}

/**
 * @return list<int>
 */
function knownDemoSecretAppIds(\PDO $pdo): array
{
    // 仅保存已公开旧密钥的单向 SHA-256 指纹，不在工具中保留或输出原始 app_secret。
    $knownDemoSecretHash = 'f91c5f67d4576f675ad08233695845b790f7bc9549386f2a89777aa32f992170';
    $ids = [];
    $statement = $pdo->query(
        'SELECT id, app_secret_hash FROM apps WHERE deleted_at IS NULL ORDER BY id ASC'
    );
    while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
        $storedHash = strtolower(trim((string) ($row['app_secret_hash'] ?? '')));
        if (strlen($storedHash) === 64 && hash_equals($knownDemoSecretHash, $storedHash)) {
            $ids[] = (int) $row['id'];
        }
    }
    return array_values(array_unique($ids));
}

/**
 * @param list<int> $ids
 */
function printAuditResult(string $label, array $ids): void
{
    $displayIds = $ids === [] ? '无' : implode(',', $ids);
    fwrite(STDOUT, sprintf("%s命中：%d；ID：%s\n", $label, count($ids), $displayIds));
}

try {
    $pdo = Database::connection();
    $platformIds = weakPasswordIds(
        $pdo,
        'SELECT id, password_hash FROM platform_accounts WHERE deleted_at IS NULL ORDER BY id ASC'
    );
    $adminIds = weakPasswordIds(
        $pdo,
        'SELECT id, password_hash FROM admins ORDER BY id ASC'
    );
    $userIds = weakPasswordIds(
        $pdo,
        'SELECT id, password_hash FROM users WHERE deleted_at IS NULL ORDER BY id ASC'
    );
    $appIds = knownDemoSecretAppIds($pdo);
} catch (\Throwable $exception) {
    fwrite(STDERR, "默认凭据只读审计无法连接或读取数据库；未输出任何配置、哈希或密钥。\n");
    exit(2);
}

fwrite(STDOUT, "默认凭据只读审计结果（仅显示数量和数据库 ID）\n");
printAuditResult('平台账号', $platformIds);
printAuditResult('管理员账号', $adminIds);
printAuditResult('用户账号', $userIds);
printAuditResult('旧演示应用密钥', $appIds);

$total = count($platformIds) + count($adminIds) + count($userIds) + count($appIds);
fwrite(STDOUT, "总命中：{$total}\n");
if ($total > 0) {
    fwrite(STDERR, "审计失败：仍有未删除身份使用已知默认凭据；停用不能绕过发布门禁，请轮换后再发布。\n");
    exit(1);
}

fwrite(STDOUT, "审计通过：未发现未删除身份使用已知默认凭据。\n");
exit(0);
