<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Throwable;
use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Token;

final class MaintenanceWriteGuard
{
    private const WRITE_METHODS = ['POST', 'PUT', 'DELETE'];

    /**
     * Recovery and maintenance-control writes must remain reachable while an application is maintained.
     * Patterns are deliberately exact; ordinary application business writes are never allowlisted by prefix.
     */
    private const RECOVERY_PATHS = [
        '#^/api/platform/(?:login|logout)/?$#',
        '#^/api/admin/(?:login|logout)/?$#',
        '#^/api/user/(?:login|logout|token/refresh|password|password/reset|password/reset/code)/?$#',
        '#^/api/public/card-(?:login|auto-login)/?$#',
        '#^/api/platform/maintenances(?:/[0-9]+)?/?$#',
        '#^/api/admin/apps/[0-9]+/maintenances(?:/[0-9]+)?/?$#',
    ];

    public static function enforce(Request $request, array $routeParams = []): void
    {
        if (!self::isWriteMethod($request->method()) || self::isRecoveryPath($request->path())) {
            return;
        }

        try {
            [$appId, $trustedUserToken] = self::resolveApp($request, $routeParams);
            if ($appId === null) {
                return;
            }
            $context = LifecycleService::maintenanceContextForApp($appId);
            if ($context === null) {
                return;
            }
            $maintenance = LifecycleService::activeMaintenance($context, $request->clientIp());
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ((bool) config('app.debug', false)) {
                error_log('[maintenance-write-guard] ' . get_class($exception) . ': ' . $exception->getMessage());
            }
            throw new HttpException(
                '暂时无法确认应用维护状态，请稍后重试',
                503,
                503,
                ['reason_code' => 'maintenance_state_unavailable', 'retry_after' => 60]
            );
        }

        if (!self::shouldBlock($maintenance)) {
            return;
        }

        throw new HttpException(
            '应用维护中，暂不接受写入请求',
            503,
            503,
            self::maintenanceFailureData($maintenance, $trustedUserToken)
        );
    }

    private static function isWriteMethod(string $method): bool
    {
        return in_array(strtoupper($method), self::WRITE_METHODS, true);
    }

    private static function isRecoveryPath(string $path): bool
    {
        foreach (self::RECOVERY_PATHS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{0:?int,1:bool} application ID and whether a valid user token may receive ends_at
     */
    private static function resolveApp(Request $request, array $routeParams): array
    {
        if (preg_match('#^/api/(?:admin|platform)/apps/[^/]+(?:/|$)#', $request->path()) === 1) {
            return [self::routeAppId($routeParams['app_id'] ?? null), false];
        }

        if (str_starts_with($request->path(), '/api/user/')) {
            $bearer = trim((string) ($request->bearerToken() ?? ''));
            if ($bearer !== '') {
                $row = Database::one(
                    'SELECT t.app_id, a.app_key FROM user_tokens t
                     INNER JOIN users u ON u.id = t.user_id AND u.app_id = t.app_id AND u.admin_id = t.admin_id
                     INNER JOIN apps a ON a.id = t.app_id AND a.admin_id = t.admin_id
                     WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expired_at > NOW()
                       AND u.status = 1 AND u.deleted_at IS NULL
                       AND a.status = 1 AND a.deleted_at IS NULL
                     LIMIT 1',
                    [Token::hash($bearer)]
                );
                if ($row === null) {
                    return [null, false];
                }
                $providedAppKey = self::providedAppKey($request);
                if ($providedAppKey !== '' && !hash_equals((string) $row['app_key'], $providedAppKey)) {
                    throw self::appIdentityMismatch();
                }
                $trusted = $providedAppKey !== '' && hash_equals((string) $row['app_key'], $providedAppKey);
                return [(int) $row['app_id'], $trusted];
            }

            $refresh = trim((string) $request->input('refresh_token', ''));
            if ($refresh !== '') {
                $row = Database::one(
                    'SELECT r.app_id FROM user_refresh_tokens r
                     INNER JOIN users u ON u.id = r.user_id AND u.app_id = r.app_id AND u.admin_id = r.admin_id
                     INNER JOIN apps a ON a.id = r.app_id AND a.admin_id = r.admin_id
                     WHERE r.token_hash = ? AND r.revoked_at IS NULL AND r.expired_at > NOW()
                       AND u.status = 1 AND u.deleted_at IS NULL
                       AND a.status = 1 AND a.deleted_at IS NULL
                     LIMIT 1',
                    [Token::hash($refresh)]
                );
                return $row === null ? [null, false] : [(int) $row['app_id'], false];
            }
        }

        if (str_starts_with($request->path(), '/api/user/')
            || str_starts_with($request->path(), '/api/public/')) {
            $appKey = self::providedAppKey($request);
            if ($appKey === '') {
                return [null, false];
            }
            $row = Database::one(
                'SELECT id FROM apps WHERE app_key = ? AND status = 1 AND deleted_at IS NULL LIMIT 1',
                [$appKey]
            );
            return $row === null ? [null, false] : [(int) $row['id'], false];
        }

        return [null, false];
    }

    /**
     * Resolve the same application identity seen by public/user controllers.
     * A conflicting header and body/query identity is rejected before any handler can write.
     */
    private static function providedAppKey(Request $request): string
    {
        $headerAppKey = trim((string) $request->header('x-app-key', ''));
        $bodyAppKey = trim((string) $request->bodyInput('app_key', ''));
        $queryAppKey = trim((string) $request->queryInput('app_key', ''));
        $provided = array_values(array_filter(
            [$bodyAppKey, $queryAppKey, $headerAppKey],
            static fn(string $value): bool => $value !== ''
        ));
        if ($provided !== []) {
            $expected = $provided[0];
            foreach (array_slice($provided, 1) as $candidate) {
                if (!hash_equals($expected, $candidate)) {
                    throw self::appIdentityMismatch();
                }
            }
        }
        if ($bodyAppKey !== '') {
            return $bodyAppKey;
        }
        if ($queryAppKey !== '') {
            return $queryAppKey;
        }
        return $headerAppKey;
    }

    private static function appIdentityMismatch(): HttpException
    {
        return new HttpException(
            "\u{5E94}\u{7528}\u{8EAB}\u{4EFD}\u{4E0D}\u{4E00}\u{81F4}",
            0,
            422,
            ['reason_code' => 'app_identity_mismatch']
        );
    }

    private static function routeAppId(mixed $value): ?int
    {
        $rawAppId = (string) $value;
        if (!ctype_digit($rawAppId)) {
            return null;
        }
        $appId = (int) $rawAppId;
        return $appId > 0 ? $appId : null;
    }

    private static function shouldBlock(?array $maintenance): bool
    {
        return $maintenance !== null && (int) ($maintenance['forced'] ?? 0) !== 0;
    }

    private static function maintenanceFailureData(array $maintenance, bool $trustedUserToken): array
    {
        $retryAfter = 60;
        $endsAt = trim((string) ($maintenance['ends_at'] ?? ''));
        if ($endsAt !== '') {
            $endTime = strtotime($endsAt);
            if ($endTime !== false) {
                $retryAfter = max(1, min(3600, $endTime - time()));
            }
        }
        $data = ['reason_code' => 'app_maintenance', 'retry_after' => $retryAfter];
        if ($trustedUserToken && $endsAt !== '') {
            $data['ends_at'] = $endsAt;
        }
        return $data;
    }
}
