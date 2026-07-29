<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Platform;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AdminProvisionService;
use Yiyunying\Services\PlatformService;

final class BillingController
{
    public static function purchaseOrders(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'billing.manage');
        [$scope, $query] = self::platformScope($actor, $request, 'o');
        $where = [$scope];
        $status = trim((string) $request->input('status', ''));
        if ($status !== '') {
            $where[] = 'o.status = ?';
            $query[] = $status;
        }
        $type = trim((string) $request->input('purchase_type', ''));
        if ($type !== '') {
            $where[] = 'o.purchase_type = ?';
            $query[] = $type;
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(o.order_no LIKE ? OR a.account LIKE ? OR a.nickname LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($query, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM admin_purchase_orders o
             INNER JOIN admins a ON a.id = o.admin_id WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT o.*, a.account AS admin_account, a.nickname AS admin_nickname,
                    p.platform_key, p.nickname AS platform_name
             FROM admin_purchase_orders o
             INNER JOIN admins a ON a.id = o.admin_id
             INNER JOIN platform_accounts p ON p.id = o.platform_id
             WHERE {$whereSql} ORDER BY o.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        foreach ($items as &$item) {
            self::decodeOrder($item);
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function fulfillPurchaseOrder(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'billing.manage');
        $order = self::ownedOrder($actor, (int) $params['order_id']);
        if ((string) $order['status'] !== 'pending') {
            throw new HttpException('只有 pending 购买申请可以发放', 0, 409);
        }
        $grantInput = $request->input('grant', []);
        if (!is_array($grantInput)) {
            throw new HttpException('grant 必须是对象', 0, 422);
        }
        $grant = self::grantForOrder($order, $grantInput);
        $remark = mb_substr(trim((string) $request->input('platform_note', '购买申请已发放')), 0, 500);
        $after = Database::transaction(static function () use ($request, $actor, $order, $grant, $remark): array {
            $locked = Database::one('SELECT status FROM admin_purchase_orders WHERE id = ? FOR UPDATE', [(int) $order['id']]);
            if ($locked === null || (string) $locked['status'] !== 'pending') {
                throw new HttpException('购买申请状态已变化，请刷新后重试', 0, 409);
            }
            $admin = PlatformService::ownedAdmin($actor, (int) $order['admin_id']);
            $entitlement = AdminProvisionService::adjustEntitlement($actor, $admin, $grant, $remark);
            Database::execute(
                'UPDATE admin_purchase_orders SET status = ?, grant_json = ?, platform_note = ?,
                 handled_by_platform_id = ?, handled_at = NOW(), updated_at = NOW() WHERE id = ?',
                [
                    'fulfilled', json_encode($grant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $remark, (int) $actor['id'], (int) $order['id'],
                ]
            );
            PlatformService::increment((int) $order['platform_id'], 'purchase_fulfilled');
            return $entitlement;
        });
        PlatformService::log(
            $request,
            $actor,
            'purchase',
            'fulfill',
            'admin_purchase_order',
            (int) $order['id'],
            null,
            $grant
        );
        return Response::success([
            'order_id' => (int) $order['id'],
            'status' => 'fulfilled',
            'grant' => $grant,
            'entitlement' => self::entitlementData($after),
        ], '购买权益已发放');
    }

    public static function rejectPurchaseOrder(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'billing.manage');
        $order = self::ownedOrder($actor, (int) $params['order_id']);
        if ((string) $order['status'] !== 'pending') {
            throw new HttpException('只有 pending 购买申请可以拒绝', 0, 409);
        }
        $note = mb_substr(trim((string) $request->input('platform_note', '')), 0, 500);
        if ($note === '') {
            throw new HttpException('拒绝时必须填写 platform_note', 0, 422);
        }
        Database::execute(
            'UPDATE admin_purchase_orders SET status = ?, platform_note = ?, handled_by_platform_id = ?,
             handled_at = NOW(), updated_at = NOW() WHERE id = ? AND status = ?',
            ['rejected', $note, (int) $actor['id'], (int) $order['id'], 'pending']
        );
        PlatformService::log($request, $actor, 'purchase', 'reject', 'admin_purchase_order', (int) $order['id'], null, [
            'platform_note' => $note,
        ]);
        return Response::success(['order_id' => (int) $order['id'], 'status' => 'rejected'], '购买申请已拒绝');
    }

    public static function feedbacks(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'feedback.manage');
        [$scope, $query] = self::platformScope($actor, $request, 'f');
        $where = [$scope];
        $status = trim((string) $request->input('status', ''));
        if ($status !== '') {
            $where[] = 'f.status = ?';
            $query[] = $status;
        }
        $type = trim((string) $request->input('type', ''));
        if ($type !== '') {
            $where[] = 'f.type = ?';
            $query[] = $type;
        }
        $whereSql = implode(' AND ', $where);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM admin_platform_feedbacks f WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT f.*, a.account AS admin_account, a.nickname AS admin_nickname,
                    p.platform_key, p.nickname AS platform_name
             FROM admin_platform_feedbacks f
             INNER JOIN admins a ON a.id = f.admin_id
             INNER JOIN platform_accounts p ON p.id = f.platform_id
             WHERE {$whereSql} ORDER BY f.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        foreach ($items as &$item) {
            $item['images'] = self::decodeJsonArray($item['images_json'] ?? null);
            unset($item['images_json']);
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function replyFeedback(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'feedback.manage');
        $feedback = self::ownedFeedback($actor, (int) $params['feedback_id']);
        $reply = trim((string) $request->input('reply_content', ''));
        if ($reply === '') {
            throw new HttpException('reply_content 不能为空', 0, 422);
        }
        $status = trim((string) $request->input('status', 'replied'));
        if (!in_array($status, ['replied', 'closed'], true)) {
            throw new HttpException('status 仅支持 replied 或 closed', 0, 422);
        }
        Database::execute(
            'UPDATE admin_platform_feedbacks SET status = ?, reply_content = ?, replied_by_platform_id = ?,
             replied_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$status, mb_substr($reply, 0, 20000), (int) $actor['id'], (int) $feedback['id']]
        );
        PlatformService::log($request, $actor, 'feedback', 'reply', 'admin_platform_feedback', (int) $feedback['id'], null, [
            'status' => $status,
        ]);
        return Response::success(['feedback_id' => (int) $feedback['id'], 'status' => $status], '反馈已回复');
    }

    private static function ownedOrder(array $actor, int $orderId): array
    {
        $sql = 'SELECT * FROM admin_purchase_orders WHERE id = ?';
        $query = [$orderId];
        if ((int) $actor['level'] === 2) {
            $sql .= ' AND platform_id = ?';
            $query[] = (int) $actor['id'];
        } else {
            $sql .= ' AND (platform_id = ? OR platform_id IN
                    (SELECT id FROM platform_accounts WHERE parent_id = ? AND level = 2 AND deleted_at IS NULL))';
            $query[] = (int) $actor['id'];
            $query[] = (int) $actor['id'];
        }
        $order = Database::one($sql, $query);
        if ($order === null) {
            throw new HttpException('购买申请不存在或不在当前平台范围内', 404, 404);
        }
        return $order;
    }

    private static function ownedFeedback(array $actor, int $feedbackId): array
    {
        $sql = 'SELECT * FROM admin_platform_feedbacks WHERE id = ?';
        $query = [$feedbackId];
        if ((int) $actor['level'] === 2) {
            $sql .= ' AND platform_id = ?';
            $query[] = (int) $actor['id'];
        } else {
            $sql .= ' AND (platform_id = ? OR platform_id IN
                    (SELECT id FROM platform_accounts WHERE parent_id = ? AND level = 2 AND deleted_at IS NULL))';
            $query[] = (int) $actor['id'];
            $query[] = (int) $actor['id'];
        }
        $feedback = Database::one($sql, $query);
        if ($feedback === null) {
            throw new HttpException('反馈不存在或不在当前平台范围内', 404, 404);
        }
        return $feedback;
    }

    private static function platformScope(array $actor, Request $request, string $alias): array
    {
        if ((int) $actor['level'] === 2) {
            return ["{$alias}.platform_id = ?", [(int) $actor['id']]];
        }
        $platformId = (int) $request->input('platform_id', 0);
        if ($platformId <= 0) {
            return [
                "({$alias}.platform_id = ? OR {$alias}.platform_id IN
                  (SELECT id FROM platform_accounts WHERE parent_id = ? AND level = 2 AND deleted_at IS NULL))",
                [(int) $actor['id'], (int) $actor['id']],
            ];
        }
        if ($platformId !== (int) $actor['id']) {
            PlatformService::ownedOperator($actor, $platformId);
        }
        return ["{$alias}.platform_id = ?", [$platformId]];
    }

    private static function grantForOrder(array $order, array $input): array
    {
        $quantity = max(1, (int) $order['quantity']);
        $default = match ((string) $order['purchase_type']) {
            'vip_days' => ['add_vip_days' => $quantity],
            'app_quota' => ['app_quota_change' => $quantity],
            'remote_document_quota' => ['remote_document_quota_change' => $quantity],
            'balance' => ['integral_change' => $quantity],
            default => [],
        };
        $grant = $input === [] ? $default : $input;
        if (array_key_exists('balance', $grant)) { $grant['integral'] = $grant['balance']; unset($grant['balance']); }
        if (array_key_exists('balance_change', $grant)) { $grant['integral_change'] = $grant['balance_change']; unset($grant['balance_change']); }
        if ($grant === []) {
            throw new HttpException('custom 购买申请必须在发放时传 grant', 0, 422);
        }
        $allowed = [
            'membership_level', 'membership_status', 'membership_expired_at', 'add_vip_days',
            'app_quota', 'app_quota_change', 'remote_document_quota', 'remote_document_quota_change',
            'integral', 'integral_change', 'access_start_time', 'access_end_time', 'allowed_weekdays',
        ];
        foreach (array_keys($grant) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                throw new HttpException('grant 包含不支持的字段：' . (string) $key, 0, 422);
            }
        }
        return $grant;
    }

    private static function entitlementData(array $admin): array
    {
        return [
            'membership_level' => $admin['membership_level'],
            'membership_status' => $admin['membership_status'],
            'membership_expired_at' => $admin['membership_expired_at'],
            'app_quota' => (int) $admin['app_quota'],
            'remote_document_quota' => (int) $admin['remote_document_quota'],
            'balance' => (int) $admin['admin_integral'],
            'access_start_time' => $admin['access_start_time'],
            'access_end_time' => $admin['access_end_time'],
            'allowed_weekdays' => $admin['allowed_weekdays'],
        ];
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
