<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Validator;
use Yiyunying\Services\AdminAccessService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\PlatformService;

final class BillingController
{
    private const PURCHASE_TYPES = ['vip_days', 'app_quota', 'remote_document_quota', 'balance', 'custom'];
    private const FEEDBACK_TYPES = ['feedback', 'bug', 'feature', 'billing', 'policy'];

    public static function entitlement(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $counts = Database::one(
            'SELECT
               (SELECT COUNT(*) FROM apps WHERE admin_id = ? AND deleted_at IS NULL) AS app_used,
               (SELECT COUNT(*) FROM remote_files WHERE admin_id = ? AND file_type = ? AND deleted_at IS NULL) AS remote_document_used,
               (SELECT COUNT(*) FROM users WHERE admin_id = ? AND deleted_at IS NULL) AS downstream_users,
               (SELECT COUNT(*) FROM documents WHERE admin_id = ? AND deleted_at IS NULL) AS downstream_documents,
               (SELECT COUNT(*) FROM admin_exchange_orders WHERE admin_id = ? AND status = ?) AS completed_exchanges,
               (SELECT COALESCE(SUM(total_integral), 0) FROM admin_exchange_orders WHERE admin_id = ? AND status = ?) AS spent_integral',
            [
                (int) $admin['id'], (int) $admin['id'], 'file', (int) $admin['id'], (int) $admin['id'],
                (int) $admin['id'], 'completed', (int) $admin['id'], 'completed',
            ]
        ) ?? [];
        $platform = Database::one(
            'SELECT id, level, platform_key, nickname FROM platform_accounts WHERE id = ?',
            [(int) $admin['platform_id']]
        );
        return Response::success([
            'admin_id' => (int) $admin['id'],
            'platform' => $platform === null ? null : [
                'id' => (int) $platform['id'],
                'level' => (int) $platform['level'],
                'platform_key' => $platform['platform_key'],
                'nickname' => $platform['nickname'],
            ],
            'access' => AdminAccessService::accessState($admin),
            'membership' => [
                'level' => $admin['membership_level'],
                'status' => $admin['membership_status'],
                'started_at' => $admin['membership_started_at'],
                'expired_at' => $admin['membership_expired_at'],
                'access_start_time' => $admin['access_start_time'],
                'access_end_time' => $admin['access_end_time'],
                'allowed_weekdays' => $admin['allowed_weekdays'],
            ],
            'quotas' => [
                'apps' => ['used' => (int) ($counts['app_used'] ?? 0), 'limit' => (int) $admin['app_quota']],
                'remote_documents' => [
                    'used' => (int) ($counts['remote_document_used'] ?? 0),
                    'limit' => (int) $admin['remote_document_quota'],
                ],
                'balance' => (int) $admin['admin_integral'],
            ],
            'downstream' => [
                'users' => (int) ($counts['downstream_users'] ?? 0),
                'documents' => (int) ($counts['downstream_documents'] ?? 0),
            ],
            'balance_exchange' => [
                'completed_orders' => (int) ($counts['completed_exchanges'] ?? 0),
                'spent_balance' => (int) ($counts['spent_integral'] ?? 0),
            ],
        ]);
    }

    public static function purchaseOrders(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['admin_id = ?'];
        $query = [(int) $admin['id']];
        $status = trim((string) $request->input('status', ''));
        if ($status !== '') {
            $where[] = 'status = ?';
            $query[] = $status;
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM admin_purchase_orders WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT * FROM admin_purchase_orders WHERE {$whereSql}
             ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        foreach ($items as &$item) {
            self::decodeOrder($item);
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function createPurchaseOrder(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $data = $request->all();
        Validator::required($data, ['purchase_type']);
        $type = trim((string) $data['purchase_type']);
        if (!in_array($type, self::PURCHASE_TYPES, true)) {
            throw new HttpException('purchase_type 不支持', 0, 422, ['allowed' => self::PURCHASE_TYPES]);
        }
        $quantity = max(1, min(1000000, (int) ($data['quantity'] ?? 1)));
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount < 0) {
            throw new HttpException('amount 不能小于 0', 0, 422);
        }
        $requestData = $data['request'] ?? [];
        if (!is_array($requestData)) {
            throw new HttpException('request 必须是对象', 0, 422);
        }
        $orderNo = self::orderNo();
        $orderId = Database::insert(
            'INSERT INTO admin_purchase_orders
             (platform_id, admin_id, order_no, purchase_type, quantity, amount, request_json,
              status, admin_note, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (int) $admin['platform_id'], (int) $admin['id'], $orderNo, $type, $quantity, $amount,
                json_encode($requestData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'pending', mb_substr(trim((string) ($data['note'] ?? '')), 0, 500),
            ]
        );
        PlatformService::increment((int) $admin['platform_id'], 'purchase_created');
        LogService::adminOperation($request, (int) $admin['id'], null, 'platform_purchase', 'create', $orderId);
        $order = Database::one('SELECT * FROM admin_purchase_orders WHERE id = ?', [$orderId]) ?? [];
        self::decodeOrder($order);
        return Response::success(['order' => $order], '购买申请已提交，等待所属平台处理', 201);
    }

    public static function feedbacks(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM admin_platform_feedbacks WHERE admin_id = ?',
            [(int) $admin['id']]
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT * FROM admin_platform_feedbacks WHERE admin_id = ?
             ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            [(int) $admin['id']]
        );
        foreach ($items as &$item) {
            $item['images'] = self::decodeJsonArray($item['images_json'] ?? null);
            unset($item['images_json']);
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function createFeedback(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $data = $request->all();
        Validator::required($data, ['title', 'content']);
        $type = trim((string) ($data['type'] ?? 'feedback'));
        if (!in_array($type, self::FEEDBACK_TYPES, true)) {
            throw new HttpException('type 不支持', 0, 422, ['allowed' => self::FEEDBACK_TYPES]);
        }
        $images = $data['images'] ?? [];
        if (!is_array($images) || count($images) > 9) {
            throw new HttpException('images 必须是最多 9 项的数组', 0, 422);
        }
        $images = array_values(array_map(
            static fn($value): string => mb_substr(trim((string) $value), 0, 1000),
            $images
        ));
        $feedbackId = Database::insert(
            'INSERT INTO admin_platform_feedbacks
             (platform_id, admin_id, type, title, content, images_json, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (int) $admin['platform_id'], (int) $admin['id'], $type,
                Validator::string($data['title'], 'title', 2, 200),
                Validator::string($data['content'], 'content', 2, 20000),
                $images === [] ? null : json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'pending',
            ]
        );
        LogService::adminOperation($request, (int) $admin['id'], null, 'platform_feedback', 'create', $feedbackId);
        return Response::success([
            'feedback_id' => $feedbackId,
            'target_platform_id' => (int) $admin['platform_id'],
            'status' => 'pending',
        ], '反馈已提交给所属 1/2 级平台', 201);
    }

    private static function orderNo(): string
    {
        do {
            $orderNo = 'AP' . date('YmdHis') . strtoupper(bin2hex(random_bytes(5)));
        } while (Database::one('SELECT id FROM admin_purchase_orders WHERE order_no = ?', [$orderNo]));
        return $orderNo;
    }

    private static function decodeOrder(array &$order): void
    {
        $order['request'] = self::decodeJsonArray($order['request_json'] ?? null);
        $order['grant'] = self::decodeJsonArray($order['grant_json'] ?? null);
        unset($order['request_json'], $order['grant_json']);
    }

    private static function decodeJsonArray($json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
