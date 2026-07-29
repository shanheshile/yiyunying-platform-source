<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use LogicException;
use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;

final class StatisticsController
{
    public static function overview(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        [$start, $end] = self::dateRange($request);
        $adminId = (int) $admin['id'];
        $summary = [
            'users' => self::count('users', $adminId, $appId, 'deleted_at IS NULL'),
            'active_users' => self::count('users', $adminId, $appId, 'status = 1 AND deleted_at IS NULL'),
            'documents' => self::count('documents', $adminId, $appId, 'deleted_at IS NULL'),
            'document_shares' => self::count('document_shares', $adminId, $appId, 'status = 1'),
            'resources' => self::count('resources', $adminId, $appId, 'deleted_at IS NULL'),
            'forum_posts' => self::count('forum_posts', $adminId, $appId, 'deleted_at IS NULL'),
            'messages' => self::count('messages', $adminId, $appId),
            'service_open' => self::count('service_sessions', $adminId, $appId, "status = 'open'"),
            'card_redeems' => self::count('card_redeem_logs', $adminId, $appId),
            'orders' => self::count('orders', $adminId, $appId),
            'paid_orders' => self::count('orders', $adminId, $appId, "status = 'paid'"),
            'feedback_pending' => self::count('feedbacks', $adminId, $appId, "status IN ('pending','processing')"),
            'uploads' => self::count('uploads', $adminId, $appId),
            'notices' => self::count('notices', $adminId, $appId, 'deleted_at IS NULL'),
            'versions' => self::count('app_versions', $adminId, $appId, 'deleted_at IS NULL'),
        ];
        $visitSummary = Database::one(
            'SELECT COALESCE(SUM(visit_count), 0) AS app_views,
                    COUNT(DISTINCT CASE WHEN visit_date = CURDATE() THEN visitor_hash END) AS unique_visitors_today
             FROM app_visit_events WHERE admin_id = ? AND app_id = ?',
            [$adminId, $appId]
        ) ?? [];
        $summary['app_views'] = (int) ($visitSummary['app_views'] ?? 0);
        $summary['unique_visitors_today'] = (int) ($visitSummary['unique_visitors_today'] ?? 0);
        $summary['online_users'] = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM user_presence
             WHERE admin_id = ? AND app_id = ? AND status = 'online' AND online_until > NOW()",
            [$adminId, $appId]
        )['total'] ?? 0);
        $summary['login_card_bindings'] = self::count('card_login_bindings', $adminId, $appId, 'status = 1');
        $finance = Database::one(
            "SELECT COALESCE(SUM(pay_amount), 0) AS paid_amount, COUNT(*) AS paid_count
             FROM orders WHERE admin_id = ? AND app_id = ? AND status = 'paid'
               AND paid_at BETWEEN ? AND ?",
            [$adminId, $appId, $start . ' 00:00:00', $end . ' 23:59:59']
        ) ?? ['paid_amount' => 0, 'paid_count' => 0];
        $api = Database::one(
            'SELECT COUNT(*) AS requests,
                    SUM(CASE WHEN http_status >= 400 THEN 1 ELSE 0 END) AS errors,
                    COALESCE(AVG(duration_ms), 0) AS avg_duration_ms
             FROM api_request_logs WHERE admin_id = ? AND app_id = ? AND created_at BETWEEN ? AND ?',
            [$adminId, $appId, $start . ' 00:00:00', $end . ' 23:59:59']
        ) ?? [];
        $daily = Database::all(
            'SELECT stat_date, new_users, user_logins, document_created, document_updated,
                    document_deleted, card_redeemed, api_requests, app_views,
                    unique_visitors, heartbeat_count
             FROM statistics_daily
             WHERE admin_id = ? AND app_id = ? AND stat_date BETWEEN ? AND ? ORDER BY stat_date ASC',
            [$adminId, $appId, $start, $end]
        );
        return Response::success([
            'range' => ['date_start' => $start, 'date_end' => $end],
            'summary' => $summary,
            'finance' => [
                'paid_amount' => round((float) $finance['paid_amount'], 2),
                'paid_count' => (int) $finance['paid_count'],
            ],
            'api' => [
                'requests' => (int) ($api['requests'] ?? 0),
                'errors' => (int) ($api['errors'] ?? 0),
                'avg_duration_ms' => round((float) ($api['avg_duration_ms'] ?? 0), 2),
            ],
            'daily' => $daily,
        ]);
    }

    public static function apiLogs(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::logs($request, $params, 'api');
    }

    public static function operationLogs(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::logs($request, $params, 'operation');
    }

    private static function logs(Request $request, array $params, string $type): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $table = $type === 'api' ? 'api_request_logs' : 'admin_operation_logs';
        $where = ['admin_id = ?', 'app_id = ?'];
        $query = [(int) $admin['id'], $appId];
        if ($type === 'api' && trim((string) $request->input('path', '')) !== '') {
            $where[] = 'path LIKE ?';
            $query[] = '%' . trim((string) $request->input('path')) . '%';
        }
        if ($type === 'api' && trim((string) $request->input('actor_type', '')) !== '') {
            $where[] = 'actor_type = ?';
            $query[] = trim((string) $request->input('actor_type'));
        }
        if ($type === 'api' && in_array($request->input('errors_only'), [true, 1, '1', 'true'], true)) {
            $where[] = 'http_status >= 400';
        }
        if ($type === 'operation' && trim((string) $request->input('module', '')) !== '') {
            $where[] = 'module = ?';
            $query[] = trim((string) $request->input('module'));
        }
        if ($type === 'operation' && trim((string) $request->input('action', '')) !== '') {
            $where[] = 'action = ?';
            $query[] = trim((string) $request->input('action'));
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM {$table} WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT * FROM {$table} WHERE {$whereSql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    private static function dateRange(Request $request): array
    {
        $start = (string) $request->input('date_start', date('Y-m-d', strtotime('-29 days')));
        $end = (string) $request->input('date_end', date('Y-m-d'));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) !== 1) {
            throw new HttpException('日期格式必须为 YYYY-MM-DD', 0, 422);
        }
        if ($start > $end) {
            throw new HttpException('date_start 不能晚于 date_end', 0, 422);
        }
        return [$start, $end];
    }

    private static function count(string $table, int $adminId, int $appId, string $extra = ''): int
    {
        $allowed = [
            'users', 'documents', 'document_shares', 'resources', 'forum_posts', 'messages',
            'service_sessions', 'card_redeem_logs', 'card_login_bindings', 'orders', 'feedbacks',
            'uploads', 'notices', 'app_versions',
        ];
        if (!in_array($table, $allowed, true)) {
            throw new LogicException('Unsupported statistics table');
        }
        $sql = "SELECT COUNT(*) AS total FROM {$table} WHERE admin_id = ? AND app_id = ?";
        if ($extra !== '') {
            $sql .= ' AND ' . $extra;
        }
        return (int) (Database::one($sql, [$adminId, $appId])['total'] ?? 0);
    }
}
