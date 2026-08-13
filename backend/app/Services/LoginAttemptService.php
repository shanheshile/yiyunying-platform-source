<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class LoginAttemptService
{
    public static function assertPlatformAllowed(string $account, string $ip): void
    {
        $cutoff = self::cutoff();
        $row = Database::one(
            'SELECT
                COALESCE(SUM(CASE WHEN account = ? AND ip = ? THEN 1 ELSE 0 END), 0) AS identity_ip_failures,
                COALESCE(SUM(CASE WHEN account = ? THEN 1 ELSE 0 END), 0) AS identity_failures,
                COALESCE(SUM(CASE WHEN ip = ? THEN 1 ELSE 0 END), 0) AS ip_failures,
                MIN(created_at) AS first_failure_at
             FROM platform_login_logs WHERE result = 0 AND created_at >= ?',
            [$account, $ip, $account, $ip, $cutoff]
        ) ?? [];
        self::assertCountsAllowed($row);
    }

    public static function assertAdminAllowed(int $platformId, string $account, string $ip): void
    {
        $cutoff = self::cutoff();
        $row = Database::one(
            'SELECT
                COALESCE(SUM(CASE WHEN account = ? AND ip = ? THEN 1 ELSE 0 END), 0) AS identity_ip_failures,
                COALESCE(SUM(CASE WHEN account = ? THEN 1 ELSE 0 END), 0) AS identity_failures,
                COALESCE(SUM(CASE WHEN ip = ? THEN 1 ELSE 0 END), 0) AS ip_failures,
                MIN(created_at) AS first_failure_at
             FROM admin_login_logs
             WHERE platform_id = ? AND result = 0 AND created_at >= ?',
            [$account, $ip, $account, $ip, $platformId, $cutoff]
        ) ?? [];
        self::assertCountsAllowed($row);
    }

    public static function assertUserAllowed(int $appId, ?int $userId, string $ip): void
    {
        $cutoff = self::cutoff();
        $row = Database::one(
            'SELECT
                COALESCE(SUM(CASE WHEN user_id = ? AND ip = ? THEN 1 ELSE 0 END), 0) AS identity_ip_failures,
                COALESCE(SUM(CASE WHEN user_id = ? THEN 1 ELSE 0 END), 0) AS identity_failures,
                COALESCE(SUM(CASE WHEN ip = ? THEN 1 ELSE 0 END), 0) AS ip_failures,
                MIN(created_at) AS first_failure_at
             FROM user_login_logs
             WHERE app_id = ? AND result = 0 AND created_at >= ?',
            [$userId, $ip, $userId, $ip, $appId, $cutoff]
        ) ?? [];
        self::assertCountsAllowed($row);
    }

    public static function blockedByCounts(int $identityIpFailures, int $identityFailures, int $ipFailures): bool
    {
        return $identityIpFailures >= self::identityIpLimit()
            || $identityFailures >= self::identityLimit()
            || $ipFailures >= self::ipLimit();
    }

    private static function assertCountsAllowed(array $row): void
    {
        if (!self::blockedByCounts(
            (int) ($row['identity_ip_failures'] ?? 0),
            (int) ($row['identity_failures'] ?? 0),
            (int) ($row['ip_failures'] ?? 0)
        )) {
            return;
        }

        $firstFailureAt = strtotime((string) ($row['first_failure_at'] ?? ''));
        $retryAfter = self::windowSeconds();
        if ($firstFailureAt !== false) {
            $retryAfter = max(1, $retryAfter - (time() - $firstFailureAt));
        }
        throw new HttpException('登录失败次数过多，请稍后再试', 429, 429, [
            'retry_after_seconds' => $retryAfter,
        ]);
    }

    private static function cutoff(): string
    {
        return date('Y-m-d H:i:s', time() - self::windowSeconds());
    }

    private static function windowSeconds(): int
    {
        return min(86400, max(60, (int) config('security.login_failure_window_seconds', 900)));
    }

    private static function identityIpLimit(): int
    {
        return min(100, max(1, (int) config('security.login_failure_identity_ip_limit', 5)));
    }

    private static function identityLimit(): int
    {
        return min(500, max(self::identityIpLimit(), (int) config('security.login_failure_identity_limit', 15)));
    }

    private static function ipLimit(): int
    {
        return min(1000, max(self::identityIpLimit(), (int) config('security.login_failure_ip_limit', 30)));
    }
}
