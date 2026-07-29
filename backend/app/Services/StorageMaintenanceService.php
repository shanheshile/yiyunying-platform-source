<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;

final class StorageMaintenanceService
{
    public static function run(bool $execute): array
    {
        $batchSize = self::boundedConfig('maintenance.batch_size', 5000, 100, 50000);
        $rules = self::rules();
        $results = [];

        foreach ($rules as $rule) {
            if (!self::tableExists($rule['table'])) {
                $results[] = [
                    'table' => $rule['table'],
                    'description' => $rule['description'],
                    'status' => 'skipped',
                    'rows' => 0,
                ];
                continue;
            }

            $rows = $execute
                ? self::deleteBatch($rule['table'], $rule['where'], $batchSize)
                : self::countBatch($rule['table'], $rule['where'], $batchSize);
            $results[] = [
                'table' => $rule['table'],
                'description' => $rule['description'],
                'status' => $execute ? 'deleted' : 'preview',
                'rows' => $rows,
            ];
        }

        $cache = self::cleanWeatherCache($execute);
        $totalRows = array_sum(array_map(static fn (array $item): int => (int) $item['rows'], $results));

        return [
            'mode' => $execute ? 'execute' : 'dry-run',
            'batch_size' => $batchSize,
            'database_rows' => $totalRows,
            'weather_cache_files' => $cache['files'],
            'weather_cache_bytes' => $cache['bytes'],
            'items' => $results,
        ];
    }

    private static function rules(): array
    {
        $tokenDays = self::boundedConfig('maintenance.token_grace_days', 7, 1, 3650);
        $verificationDays = self::boundedConfig('maintenance.verification_days', 2, 1, 3650);
        $signalDays = self::boundedConfig('maintenance.voice_signal_days', 7, 1, 3650);
        $requestDays = self::boundedConfig('maintenance.request_log_days', 30, 1, 3650);
        $errorDays = self::boundedConfig('maintenance.error_log_days', 90, 1, 3650);
        $loginDays = self::boundedConfig('maintenance.login_log_days', 180, 1, 3650);
        $operationDays = self::boundedConfig('maintenance.operation_log_days', 365, 1, 3650);
        $notificationDays = self::boundedConfig('maintenance.read_notification_days', 180, 1, 3650);

        $tokenWhere = static fn (int $days): string => sprintf(
            '(expired_at < DATE_SUB(NOW(), INTERVAL %d DAY) OR (revoked_at IS NOT NULL AND revoked_at < DATE_SUB(NOW(), INTERVAL %d DAY)))',
            $days,
            $days
        );
        $olderThan = static fn (string $field, int $days): string => sprintf(
            '%s < DATE_SUB(NOW(), INTERVAL %d DAY)',
            $field,
            $days
        );

        return [
            ['table' => 'user_refresh_tokens', 'where' => $tokenWhere($tokenDays), 'description' => '过期或已撤销的用户刷新令牌'],
            ['table' => 'user_tokens', 'where' => $tokenWhere($tokenDays), 'description' => '过期或已撤销的用户访问令牌'],
            ['table' => 'admin_tokens', 'where' => $tokenWhere($tokenDays), 'description' => '过期或已撤销的管理员令牌'],
            ['table' => 'platform_tokens', 'where' => $tokenWhere($tokenDays), 'description' => '过期或已撤销的平台令牌'],
            ['table' => 'verification_codes', 'where' => $olderThan('expired_at', $verificationDays), 'description' => '过期验证码'],
            ['table' => 'voice_call_signals', 'where' => $olderThan('created_at', $signalDays), 'description' => '已失效的通话协商信令'],
            ['table' => 'api_request_logs', 'where' => $olderThan('created_at', $requestDays), 'description' => '历史接口请求日志'],
            ['table' => 'system_error_logs', 'where' => $olderThan('created_at', $errorDays), 'description' => '历史系统错误日志'],
            ['table' => 'platform_login_logs', 'where' => $olderThan('created_at', $loginDays), 'description' => '平台登录历史'],
            ['table' => 'admin_login_logs', 'where' => $olderThan('created_at', $loginDays), 'description' => '管理员登录历史'],
            ['table' => 'user_login_logs', 'where' => $olderThan('created_at', $loginDays), 'description' => '用户登录历史'],
            ['table' => 'platform_operation_logs', 'where' => $olderThan('created_at', $operationDays), 'description' => '平台操作审计历史'],
            ['table' => 'admin_operation_logs', 'where' => $olderThan('created_at', $operationDays), 'description' => '管理员操作审计历史'],
            ['table' => 'user_operation_logs', 'where' => $olderThan('created_at', $operationDays), 'description' => '用户操作审计历史'],
            [
                'table' => 'user_notifications',
                'where' => 'is_read = 1 AND ' . $olderThan('created_at', $notificationDays),
                'description' => '超过保留期的已读通知',
            ],
        ];
    }

    private static function tableExists(string $table): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1'
        );
        $statement->execute(['table' => $table]);
        return $statement->fetchColumn() !== false;
    }

    private static function countBatch(string $table, string $where, int $limit): int
    {
        $sql = sprintf(
            'SELECT COUNT(*) FROM (SELECT id FROM `%s` WHERE %s ORDER BY id ASC LIMIT %d) AS candidates',
            $table,
            $where,
            $limit
        );
        return (int) Database::connection()->query($sql)->fetchColumn();
    }

    private static function deleteBatch(string $table, string $where, int $limit): int
    {
        $sql = sprintf('DELETE FROM `%s` WHERE %s ORDER BY id ASC LIMIT %d', $table, $where, $limit);
        return Database::execute($sql);
    }

    private static function cleanWeatherCache(bool $execute): array
    {
        $root = dirname(__DIR__, 2) . '/storage/cache/weather';
        if (!is_dir($root)) {
            return ['files' => 0, 'bytes' => 0];
        }

        $staleSeconds = max(3600, (int) config('weather.stale_cache_seconds', 21600));
        $cutoff = time() - ($staleSeconds + 86400);
        $files = 0;
        $bytes = 0;
        foreach (glob($root . '/*.json') ?: [] as $path) {
            $modifiedAt = filemtime($path);
            if ($modifiedAt === false || $modifiedAt >= $cutoff) {
                continue;
            }
            $files++;
            $bytes += max(0, (int) filesize($path));
            if ($execute) {
                @unlink($path);
            }
        }
        return ['files' => $files, 'bytes' => $bytes];
    }

    private static function boundedConfig(string $key, int $default, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, (int) config($key, $default)));
    }
}
