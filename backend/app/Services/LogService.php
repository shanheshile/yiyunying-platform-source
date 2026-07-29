<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Throwable;
use Yiyunying\Core\ApiResponse;
use Yiyunying\Core\Database;
use Yiyunying\Core\Request;
use Yiyunying\Core\Trace;

final class LogService
{
    private const STAT_COLUMNS = [
        'new_users',
        'user_logins',
        'document_created',
        'document_updated',
        'document_deleted',
        'card_redeemed',
        'api_requests',
    ];

    public static function adminOperation(
        Request $request,
        int $adminId,
        ?int $appId,
        string $module,
        string $action,
        ?int $targetId = null,
        ?array $before = null,
        ?array $after = null
    ): void {
        Database::execute(
            'INSERT INTO admin_operation_logs
             (admin_id, app_id, module, action, target_id, before_json, after_json, ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $adminId,
                $appId,
                $module,
                $action,
                $targetId,
                self::json($before),
                self::json($after),
                $request->clientIp(),
            ]
        );
    }

    public static function userOperation(
        Request $request,
        array $user,
        string $module,
        string $action,
        ?int $targetId = null,
        ?array $detail = null
    ): void {
        Database::execute(
            'INSERT INTO user_operation_logs
             (admin_id, app_id, user_id, module, action, target_id, detail_json, ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                (int) $user['admin_id'],
                (int) $user['app_id'],
                (int) $user['id'],
                $module,
                $action,
                $targetId,
                self::json($detail),
                $request->clientIp(),
            ]
        );
    }

    public static function increment(int $adminId, int $appId, string $column, int $amount = 1): void
    {
        if (!in_array($column, self::STAT_COLUMNS, true)) {
            throw new \InvalidArgumentException('不允许的统计字段');
        }
        Database::execute(
            "INSERT INTO statistics_daily (admin_id, app_id, stat_date, {$column}, created_at, updated_at)
             VALUES (?, ?, CURDATE(), ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE {$column} = {$column} + VALUES({$column}), updated_at = NOW()",
            [$adminId, $appId, $amount]
        );
    }

    public static function api(Request $request, ApiResponse $response, float $startedAt): void
    {
        try {
            $adminId = $request->attribute('admin_id');
            $appId = $request->attribute('app_id');
            Database::execute(
                'INSERT INTO api_request_logs
                 (trace_id, admin_id, app_id, actor_type, actor_id, method, path, http_status, result_code, duration_ms, ip, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    Trace::id(),
                    $adminId,
                    $appId,
                    (string) $request->attribute('actor_type', 'public'),
                    $request->attribute('actor_id'),
                    $request->method(),
                    mb_substr($request->path(), 0, 255),
                    $response->httpStatus,
                    (int) ($response->body['code'] ?? -1),
                    (int) round((microtime(true) - $startedAt) * 1000),
                    $request->clientIp(),
                    $request->userAgent(),
                ]
            );
            if ($adminId !== null && $appId !== null) {
                self::increment((int) $adminId, (int) $appId, 'api_requests');
            }
        } catch (Throwable $ignored) {
            error_log('[易运盈后台] API 日志写入失败：' . $ignored->getMessage());
        }
    }

    public static function error(Request $request, Throwable $exception): void
    {
        try {
            Database::execute(
                'INSERT INTO system_error_logs
                 (trace_id, admin_id, app_id, path, error_class, error_message, error_file, error_line, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    Trace::id(),
                    $request->attribute('admin_id'),
                    $request->attribute('app_id'),
                    mb_substr($request->path(), 0, 255),
                    get_class($exception),
                    mb_substr($exception->getMessage(), 0, 1000),
                    mb_substr($exception->getFile(), 0, 500),
                    $exception->getLine(),
                ]
            );
        } catch (Throwable $ignored) {
            error_log('[易运盈后台] ' . Trace::id() . ' ' . $exception->getMessage());
        }
    }

    private static function json(?array $value): ?string
    {
        return $value === null
            ? null
            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
