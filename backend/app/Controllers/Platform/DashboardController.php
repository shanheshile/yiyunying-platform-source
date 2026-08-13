<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Platform;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Validator;
use Yiyunying\Services\AdminAccessService;
use Yiyunying\Services\AppService;
use Yiyunying\Services\PlatformService;
use Yiyunying\Services\SettingDescriptorService;

final class DashboardController
{
    public static function dashboard(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'data.view');
        [$adminWhere, $adminParams, $platformId] = self::scope($actor, $request);
        $summary = [
            'operators' => (int) $actor['level'] === 1 ? self::scalar(
                'SELECT COUNT(*) AS total FROM platform_accounts WHERE level = 2 AND parent_id = ? AND deleted_at IS NULL',
                [(int) $actor['id']]
            ) : 0,
            'admins' => self::scalar("SELECT COUNT(*) AS total FROM admins a WHERE {$adminWhere} AND a.status <> -1", $adminParams),
            'active_admins_7d' => self::scalar(
                "SELECT COUNT(*) AS total FROM admins a WHERE {$adminWhere} AND a.status = 1
                 AND a.last_login_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                $adminParams
            ),
            'expired_admins' => self::scalar(
                "SELECT COUNT(*) AS total FROM admins a INNER JOIN admin_entitlements e ON e.admin_id = a.id
                 WHERE {$adminWhere} AND e.membership_expired_at <= NOW()",
                $adminParams
            ),
            'apps' => self::scalar(
                "SELECT COUNT(*) AS total FROM apps ap INNER JOIN admins a ON a.id = ap.admin_id
                 WHERE {$adminWhere} AND ap.deleted_at IS NULL",
                $adminParams
            ),
            'users' => self::scalar(
                "SELECT COUNT(*) AS total FROM users u INNER JOIN admins a ON a.id = u.admin_id
                 WHERE {$adminWhere} AND u.deleted_at IS NULL",
                $adminParams
            ),
            'active_users_7d' => self::scalar(
                "SELECT COUNT(DISTINCT l.user_id) AS total FROM user_login_logs l
                 INNER JOIN admins a ON a.id = l.admin_id WHERE {$adminWhere}
                 AND l.result = 1 AND l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                $adminParams
            ),
            'documents' => self::scalar(
                "SELECT COUNT(*) AS total FROM documents d INNER JOIN admins a ON a.id = d.admin_id
                 WHERE {$adminWhere} AND d.deleted_at IS NULL",
                $adminParams
            ),
            'today_admin_registrations' => self::scalar(
                'SELECT COUNT(*) AS total FROM admin_registration_logs r WHERE ' . self::platformLogWhere($actor, $platformId, 'r')
                . ' AND r.result = 1 AND r.created_at >= CURDATE()',
                self::platformLogParams($actor, $platformId)
            ),
            'today_admin_logins' => self::scalar(
                'SELECT COUNT(*) AS total FROM admin_login_logs l WHERE ' . self::platformLogWhere($actor, $platformId, 'l')
                . ' AND l.result = 1 AND l.created_at >= CURDATE()',
                self::platformLogParams($actor, $platformId)
            ),
        ];
        $finance = Database::one(
            "SELECT COUNT(*) AS paid_orders, COALESCE(SUM(o.pay_amount), 0) AS paid_amount
             FROM orders o INNER JOIN admins a ON a.id = o.admin_id
             WHERE {$adminWhere} AND o.status = 'paid'",
            $adminParams
        ) ?? [];
        $exchange = Database::one(
            "SELECT COUNT(*) AS completed_orders, COALESCE(SUM(x.total_integral), 0) AS spent_integral
             FROM admin_exchange_orders x INNER JOIN admins a ON a.id = x.admin_id
             WHERE {$adminWhere} AND x.status = 'completed'",
            $adminParams
        ) ?? [];
        $statsWhere = self::platformLogWhere($actor, $platformId, 's');
        $daily = Database::all(
            "SELECT s.stat_date, SUM(s.admin_registered) AS admin_registered,
                    SUM(s.admin_login_success) AS admin_login_success,
                    SUM(s.admin_login_failed) AS admin_login_failed,
                    SUM(s.purchase_created) AS purchase_created,
                    SUM(s.purchase_fulfilled) AS purchase_fulfilled,
                    SUM(s.point_exchange_count) AS point_exchange_count,
                    SUM(s.point_exchange_integral) AS point_exchange_integral,
                    SUM(s.point_refund_count) AS point_refund_count,
                    SUM(s.point_refund_integral) AS point_refund_integral
             FROM platform_daily_statistics s WHERE {$statsWhere}
             AND s.stat_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
             GROUP BY s.stat_date ORDER BY s.stat_date",
            self::platformLogParams($actor, $platformId)
        );
        return Response::success([
            'scope' => [
                'actor_level' => (int) $actor['level'],
                'platform_id' => $platformId,
                'global' => (int) $actor['level'] === 1 && $platformId === null,
            ],
            'summary' => $summary,
            'finance' => [
                'paid_orders' => (int) ($finance['paid_orders'] ?? 0),
                'paid_amount' => round((float) ($finance['paid_amount'] ?? 0), 2),
                'balance_exchange_orders' => (int) ($exchange['completed_orders'] ?? 0),
                'balance_exchange_amount' => (int) ($exchange['spent_integral'] ?? 0),
            ],
            'daily' => $daily,
        ]);
    }

    public static function settings(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $settings = PlatformService::settings((int) $actor['id']);
        return Response::success([
            'settings' => $settings,
            'setting_descriptors' => SettingDescriptorService::describe($settings),
            'chat_polling_policy' => PlatformService::chatPollingPolicy((int) $actor['id']),
            'message_recall_policy' => PlatformService::messageRecallPolicy((int) $actor['id']),
        ]);
    }

    public static function saveSettings(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'settings.manage');
        $settings = $request->input('settings', []);
        if (!is_array($settings) || $settings === []) {
            throw new HttpException('settings 必须是非空对象', 0, 422);
        }
        $before = PlatformService::settings((int) $actor['id']);
        $after = PlatformService::saveSettings((int) $actor['id'], $settings);
        PlatformService::log($request, $actor, 'settings', 'update', 'platform', (int) $actor['id'], $before, $after);
        return Response::success([
            'settings' => $after,
            'setting_descriptors' => SettingDescriptorService::describe($after),
            'chat_polling_policy' => PlatformService::chatPollingPolicy((int) $actor['id']),
            'message_recall_policy' => PlatformService::messageRecallPolicy((int) $actor['id']),
        ], '平台规则已保存');
    }

    public static function ipStatistics(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        [$unusedWhere, $unusedParams, $platformId] = self::scope($actor, $request);
        $where = self::platformLogWhere($actor, $platformId, 'r');
        $query = self::platformLogParams($actor, $platformId);
        if (trim((string) $request->input('ip', '')) !== '') {
            $where .= ' AND r.ip = ?';
            $query[] = trim((string) $request->input('ip'));
        }
        $items = Database::all(
            "SELECT r.platform_id, r.ip,
                    COUNT(*) AS attempts,
                    SUM(CASE WHEN r.result = 1 THEN 1 ELSE 0 END) AS successful,
                    SUM(CASE WHEN r.result = 0 THEN 1 ELSE 0 END) AS failed,
                    SUM(CASE WHEN r.result = 1 AND r.created_at >= CURDATE() THEN 1 ELSE 0 END) AS today_successful,
                    MIN(r.created_at) AS first_seen_at, MAX(r.created_at) AS last_seen_at
             FROM admin_registration_logs r WHERE {$where}
             GROUP BY r.platform_id, r.ip ORDER BY successful DESC, attempts DESC LIMIT 1000",
            $query
        );
        return Response::success(['items' => $items]);
    }

    public static function registrationLogs(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        [$unusedWhere, $unusedParams, $platformId] = self::scope($actor, $request);
        $where = self::platformLogWhere($actor, $platformId, 'r');
        $query = self::platformLogParams($actor, $platformId);
        if (trim((string) $request->input('ip', '')) !== '') {
            $where .= ' AND r.ip = ?';
            $query[] = trim((string) $request->input('ip'));
        }
        return self::paged(
            $request,
            "SELECT r.*, p.nickname AS platform_name FROM admin_registration_logs r
             INNER JOIN platform_accounts p ON p.id = r.platform_id WHERE {$where}",
            "SELECT COUNT(*) AS total FROM admin_registration_logs r WHERE {$where}",
            $query
        );
    }

    public static function adminLoginLogs(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        [$unusedWhere, $unusedParams, $platformId] = self::scope($actor, $request);
        $where = self::platformLogWhere($actor, $platformId, 'l');
        $query = self::platformLogParams($actor, $platformId);
        return self::paged(
            $request,
            "SELECT l.*, a.nickname, p.nickname AS platform_name FROM admin_login_logs l
             LEFT JOIN admins a ON a.id = l.admin_id
             LEFT JOIN platform_accounts p ON p.id = l.platform_id WHERE {$where}",
            "SELECT COUNT(*) AS total FROM admin_login_logs l WHERE {$where}",
            $query
        );
    }

    public static function operationLogs(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $where = self::platformLogWhere($actor, null, 'o');
        $query = self::platformLogParams($actor, null);
        return self::paged(
            $request,
            "SELECT o.* FROM platform_operation_logs o WHERE {$where}",
            "SELECT COUNT(*) AS total FROM platform_operation_logs o WHERE {$where}",
            $query
        );
    }

    public static function apps(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        [$adminWhere, $query] = self::adminScopeOnly($actor, $request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = [$adminWhere, 'ap.deleted_at IS NULL'];
        if (trim((string) $request->input('keyword', '')) !== '') {
            $where[] = '(ap.name LIKE ? OR ap.app_key LIKE ? OR a.account LIKE ?)';
            $key = '%' . trim((string) $request->input('keyword')) . '%';
            array_push($query, $key, $key, $key);
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM apps ap INNER JOIN admins a ON a.id = ap.admin_id WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT ap.*, a.account AS admin_account, a.platform_id,
                    (SELECT COUNT(*) FROM users u WHERE u.app_id = ap.id AND u.deleted_at IS NULL) AS user_count
             FROM apps ap INNER JOIN admins a ON a.id = ap.admin_id
             WHERE {$whereSql} ORDER BY ap.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        foreach ($items as &$item) {
            unset($item['app_secret_hash']);
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function app(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $app = PlatformService::ownedApp($actor, (int) $params['app_id']);
        unset($app['app_secret_hash']);
        $app['configured_settings'] = AppService::settings((int) $app['id']);
        $app['settings'] = AppService::effectiveSettings((int) $app['id']);
        $app['setting_descriptors'] = SettingDescriptorService::describe($app['settings']);
        $app['chat_polling_policy'] = AppService::chatPollingPolicy((int) $app['id'], $app['configured_settings']);
        $app['message_recall_policy'] = AppService::messageRecallPolicy((int) $app['id'], $app['configured_settings']);
        $app['counts'] = Database::one(
            'SELECT
               (SELECT COUNT(*) FROM users WHERE app_id = ? AND deleted_at IS NULL) AS users,
               (SELECT COUNT(*) FROM documents WHERE app_id = ? AND deleted_at IS NULL) AS documents,
               (SELECT COUNT(*) FROM forum_posts WHERE app_id = ? AND deleted_at IS NULL) AS posts,
               (SELECT COUNT(*) FROM messages WHERE app_id = ?) AS messages,
               (SELECT COUNT(*) FROM chat_rooms WHERE app_id = ?) AS chat_rooms',
            [(int) $app['id'], (int) $app['id'], (int) $app['id'], (int) $app['id'], (int) $app['id']]
        );
        return Response::success(['app' => $app]);
    }

    public static function updateApp(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'data.manage');
        $app = PlatformService::ownedApp($actor, (int) $params['app_id']);
        $status = $request->input('status', $app['status']);
        if (!in_array((int) $status, [0, 1], true)) {
            throw new HttpException('status 仅支持 0 或 1', 0, 422);
        }
        Database::execute(
            'UPDATE apps SET name = ?, logo = ?, description = ?, status = ?, disabled_reason = ?, updated_at = NOW() WHERE id = ?',
            [
                mb_substr((string) $request->input('name', $app['name']), 0, 100),
                mb_substr((string) $request->input('logo', $app['logo']), 0, 500),
                (string) $request->input('description', $app['description']), (int) $status,
                (int) $status === 1 ? null : mb_substr((string) $request->input('reason', ''), 0, 255),
                (int) $app['id'],
            ]
        );
        PlatformService::log($request, $actor, 'app', 'update', 'app', (int) $app['id']);
        return Response::success(['app_id' => (int) $app['id'], 'status' => (int) $status], '应用已更新');
    }

    public static function saveAppSettings(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'data.manage');
        $app = PlatformService::ownedApp($actor, (int) $params['app_id']);
        $settings = $request->input('settings', []);
        if (!is_array($settings) || $settings === []) {
            $settings = [];
            foreach ([
                'resource_user_submit_enabled', 'resource_submit_audit',
                'store_user_submit_enabled', 'store_submit_audit',
            ] as $key) {
                if ($request->input($key) !== null) {
                    $settings[$key] = Validator::boolean($request->input($key), $key);
                }
            }
        }
        if (!is_array($settings) || $settings === []) {
            throw new HttpException('settings 必须是非空对象', 0, 422);
        }
        if (array_key_exists('chat_poll_interval_ms', $settings)) {
            $settings['chat_poll_interval_ms'] = AdminAccessService::validateChatPollInterval(
                (int) $app['platform_id'],
                (int) $settings['chat_poll_interval_ms'],
                true
            );
        }
        if (array_key_exists('message_recall_seconds', $settings)
            && !array_key_exists('message_recall_inherit', $settings)) {
            $settings['message_recall_inherit'] = false;
        }
        $configured = AppService::saveSettings((int) $app['admin_id'], (int) $app['id'], $settings);
        $after = AppService::effectiveSettings((int) $app['id']);
        $policy = AppService::chatPollingPolicy((int) $app['id'], $configured);
        PlatformService::log($request, $actor, 'app', 'settings_update', 'app', (int) $app['id'], null, $settings);
        return Response::success([
            'settings' => $after,
            'configured_settings' => $configured,
            'setting_descriptors' => SettingDescriptorService::describe($after),
            'chat_polling_policy' => $policy,
            'message_recall_policy' => AppService::messageRecallPolicy((int) $app['id'], $configured),
        ], '应用配置已由平台更新');
    }

    public static function deleteApp(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'data.manage');
        $app = PlatformService::ownedApp($actor, (int) $params['app_id']);
        if ((string) $request->input('confirm', '') !== 'DELETE') {
            throw new HttpException('请传 confirm=DELETE 确认连带删除应用全部数据', 0, 422);
        }
        PlatformService::log($request, $actor, 'app', 'hard_delete', 'app', (int) $app['id'], $app);
        Database::execute('DELETE FROM apps WHERE id = ?', [(int) $app['id']]);
        return Response::success([], '应用及全部附属数据已连带删除');
    }

    private static function scope(array $actor, Request $request): array
    {
        [$where, $params, $platformId] = self::scopeBase($actor, $request);
        return [$where, $params, $platformId];
    }

    private static function adminScopeOnly(array $actor, Request $request): array
    {
        [$where, $params] = self::scopeBase($actor, $request);
        return [$where, $params];
    }

    private static function scopeBase(array $actor, Request $request): array
    {
        if ((int) $actor['level'] === 2) {
            return ['a.platform_id = ?', [(int) $actor['id']], (int) $actor['id']];
        }
        $platformId = (int) $request->input('platform_id', 0);
        if ($platformId > 0) {
            if ($platformId !== (int) $actor['id']) {
                PlatformService::ownedOperator($actor, $platformId);
            }
            return ['a.platform_id = ?', [$platformId], $platformId];
        }
        return [
            '(a.platform_id = ? OR a.platform_id IN
              (SELECT id FROM platform_accounts WHERE parent_id = ? AND level = 2 AND deleted_at IS NULL))',
            [(int) $actor['id'], (int) $actor['id']],
            null,
        ];
    }

    private static function platformLogWhere(array $actor, ?int $platformId, string $alias): string
    {
        if ((int) $actor['level'] === 2) {
            return "{$alias}.platform_id = ?";
        }
        return $platformId === null
            ? "({$alias}.platform_id = ? OR {$alias}.platform_id IN
                (SELECT id FROM platform_accounts WHERE parent_id = ? AND level = 2 AND deleted_at IS NULL))"
            : "{$alias}.platform_id = ?";
    }

    private static function platformLogParams(array $actor, ?int $platformId): array
    {
        if ((int) $actor['level'] === 2) {
            return [(int) $actor['id']];
        }
        return $platformId === null
            ? [(int) $actor['id'], (int) $actor['id']]
            : [$platformId];
    }

    private static function scalar(string $sql, array $params): int
    {
        return (int) (Database::one($sql, $params)['total'] ?? 0);
    }

    private static function paged(Request $request, string $selectSql, string $countSql, array $query): \Yiyunying\Core\ApiResponse
    {
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one($countSql, $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT * FROM ({$selectSql}) scoped_rows ORDER BY scoped_rows.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }
}
