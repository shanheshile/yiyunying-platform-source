<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Platform;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\ExchangeService;
use Yiyunying\Services\PlatformService;

final class ExchangeController
{
    public static function products(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'billing.manage');
        [$scope, $query] = self::platformScope($actor, $request, 'p');
        $where = [$scope, 'p.deleted_at IS NULL'];
        if ($request->input('status') !== null && $request->input('status') !== '') {
            $where[] = 'p.status = ?';
            $query[] = (int) $request->input('status');
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(p.name LIKE ? OR p.product_code LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($query, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM platform_exchange_products p WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $rows = Database::all(
            "SELECT p.*, pa.platform_key, pa.nickname AS platform_name
             FROM platform_exchange_products p
             INNER JOIN platform_accounts pa ON pa.id = p.platform_id
             WHERE {$whereSql} ORDER BY p.sort_order, p.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = [];
        foreach ($rows as $row) {
            $item = ExchangeService::productData($row);
            $item['platform_key'] = $row['platform_key'];
            $item['platform_name'] = $row['platform_name'];
            $items[] = $item;
        }
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function createProduct(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'billing.manage');
        $target = self::targetPlatform($actor, (int) $request->input('platform_id', 0));
        $data = ExchangeService::validateProductInput($request->all());
        if (Database::one(
            'SELECT id FROM platform_exchange_products WHERE platform_id = ? AND product_code = ?',
            [(int) $target['id'], $data['product_code']]
        )) {
            throw new HttpException('当前平台下 product_code 已存在', 0, 409);
        }
        $productId = Database::insert(
            'INSERT INTO platform_exchange_products
             (platform_id, product_code, name, description, product_type, grant_json,
              price_integral, stock, sold_count, per_admin_limit, per_admin_daily_limit,
              status, sort_order, start_at, end_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (int) $target['id'], $data['product_code'], $data['name'], $data['description'],
                $data['product_type'], $data['grant_json'], $data['price_integral'], $data['stock'],
                $data['per_admin_limit'], $data['per_admin_daily_limit'], $data['status'],
                $data['sort_order'], $data['start_at'], $data['end_at'],
            ]
        );
        $product = self::ownedProduct($actor, $productId);
        PlatformService::log($request, $actor, 'point_exchange_product', 'create', 'exchange_product', $productId, null, ExchangeService::productData($product));
        return Response::success(['product' => ExchangeService::productData($product)], '余额兑换商品创建成功', 201);
    }

    public static function product(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $product = self::ownedProduct($actor, (int) $params['product_id']);
        $data = ExchangeService::productData($product);
        $statistics = Database::one(
            "SELECT
               COALESCE(SUM(CASE WHEN status = 'completed' THEN quantity ELSE 0 END), 0) AS completed_quantity,
               COALESCE(SUM(CASE WHEN status = 'completed' THEN total_integral ELSE 0 END), 0) AS received_integral,
               COALESCE(SUM(CASE WHEN status = 'refunded' THEN quantity ELSE 0 END), 0) AS refunded_quantity
             FROM admin_exchange_orders WHERE product_id = ?",
            [(int) $product['id']]
        ) ?? [];
        $statistics['received_balance'] = (int) ($statistics['received_integral'] ?? 0);
        unset($statistics['received_integral']);
        $data['statistics'] = $statistics;
        return Response::success(['product' => $data]);
    }

    public static function updateProduct(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'billing.manage');
        $product = self::ownedProduct($actor, (int) $params['product_id']);
        $data = ExchangeService::validateProductInput($request->all(), $product);
        if (Database::one(
            'SELECT id FROM platform_exchange_products
             WHERE platform_id = ? AND product_code = ? AND id <> ?',
            [(int) $product['platform_id'], $data['product_code'], (int) $product['id']]
        )) {
            throw new HttpException('当前平台下 product_code 已存在', 0, 409);
        }
        Database::execute(
            'UPDATE platform_exchange_products SET product_code = ?, name = ?, description = ?,
             product_type = ?, grant_json = ?, price_integral = ?, stock = ?, per_admin_limit = ?,
             per_admin_daily_limit = ?, status = ?, sort_order = ?, start_at = ?, end_at = ?,
             updated_at = NOW() WHERE id = ?',
            [
                $data['product_code'], $data['name'], $data['description'], $data['product_type'],
                $data['grant_json'], $data['price_integral'], $data['stock'], $data['per_admin_limit'],
                $data['per_admin_daily_limit'], $data['status'], $data['sort_order'],
                $data['start_at'], $data['end_at'], (int) $product['id'],
            ]
        );
        $after = self::ownedProduct($actor, (int) $product['id']);
        PlatformService::log(
            $request,
            $actor,
            'point_exchange_product',
            'update',
            'exchange_product',
            (int) $product['id'],
            ExchangeService::productData($product),
            ExchangeService::productData($after)
        );
        return Response::success(['product' => ExchangeService::productData($after)], '余额兑换商品已更新');
    }

    public static function enableProduct(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::setProductStatus($request, $params, 1);
    }

    public static function disableProduct(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::setProductStatus($request, $params, 0);
    }

    public static function deleteProduct(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'billing.manage');
        $product = self::ownedProduct($actor, (int) $params['product_id']);
        if ((string) $request->input('confirm', '') !== 'DELETE') {
            throw new HttpException('请传 confirm=DELETE 确认删除余额兑换商品', 0, 422);
        }
        Database::execute(
            'UPDATE platform_exchange_products SET status = -1, deleted_at = NOW(), updated_at = NOW() WHERE id = ?',
            [(int) $product['id']]
        );
        PlatformService::log($request, $actor, 'point_exchange_product', 'delete', 'exchange_product', (int) $product['id'], ExchangeService::productData($product));
        return Response::success([], '余额兑换商品已删除，历史订单保留');
    }

    public static function orders(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'billing.manage');
        [$scope, $query] = self::platformScope($actor, $request, 'o');
        $where = [$scope];
        foreach (['status', 'product_type'] as $field) {
            $value = trim((string) $request->input($field, ''));
            if ($value !== '') {
                $where[] = "o.{$field} = ?";
                $query[] = $value;
            }
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(o.order_no LIKE ? OR o.product_name LIKE ? OR a.account LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($query, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM admin_exchange_orders o
             INNER JOIN admins a ON a.id = o.admin_id WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $rows = Database::all(
            "SELECT o.*, a.account AS admin_account, a.nickname AS admin_nickname,
                    p.platform_key, p.nickname AS platform_name
             FROM admin_exchange_orders o
             INNER JOIN admins a ON a.id = o.admin_id
             INNER JOIN platform_accounts p ON p.id = o.platform_id
             WHERE {$whereSql} ORDER BY o.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = array_map(static fn(array $row): array => ExchangeService::orderData($row), $rows);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function order(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $order = self::ownedOrder($actor, (int) $params['exchange_id']);
        return Response::success(['order' => ExchangeService::orderData($order)]);
    }

    public static function refund(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'billing.manage');
        $order = self::ownedOrder($actor, (int) $params['exchange_id']);
        $result = ExchangeService::refund($actor, (int) $order['id'], (string) $request->input('refund_reason', ''));
        PlatformService::log($request, $actor, 'point_exchange', 'refund', 'admin_exchange_order', (int) $order['id'], ExchangeService::orderData($order), $result['order']);
        return Response::success($result, '余额兑换订单已退款，余额和未使用权益已原子退回');
    }

    public static function integralLogs(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'billing.manage');
        [$scope, $query] = self::platformScope($actor, $request, 'l');
        $where = [$scope];
        $scene = trim((string) $request->input('scene', ''));
        if ($scene !== '') {
            $where[] = 'l.scene = ?';
            $query[] = $scene;
        }
        $adminId = (int) $request->input('admin_id', 0);
        if ($adminId > 0) {
            PlatformService::ownedAdmin($actor, $adminId);
            $where[] = 'l.admin_id = ?';
            $query[] = $adminId;
        }
        $whereSql = implode(' AND ', $where);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM admin_integral_logs l WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT l.*, a.account AS admin_account, a.nickname AS admin_nickname
             FROM admin_integral_logs l INNER JOIN admins a ON a.id = l.admin_id
             WHERE {$whereSql} ORDER BY l.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        foreach ($items as &$item) {
            foreach (['id', 'platform_id', 'admin_id', 'change_value', 'before_value', 'after_value'] as $key) {
                $item[$key] = (int) $item[$key];
            }
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    private static function setProductStatus(Request $request, array $params, int $status): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'billing.manage');
        $product = self::ownedProduct($actor, (int) $params['product_id']);
        Database::execute('UPDATE platform_exchange_products SET status = ?, updated_at = NOW() WHERE id = ?', [
            $status, (int) $product['id'],
        ]);
        PlatformService::log($request, $actor, 'point_exchange_product', $status === 1 ? 'enable' : 'disable', 'exchange_product', (int) $product['id']);
        return Response::success(['product_id' => (int) $product['id'], 'status' => $status], $status === 1 ? '余额兑换商品已上架' : '余额兑换商品已下架');
    }

    private static function targetPlatform(array $actor, int $requested): array
    {
        if ((int) $actor['level'] === 2) {
            return $actor;
        }
        if ($requested <= 0 || $requested === (int) $actor['id']) {
            return $actor;
        }
        return PlatformService::ownedOperator($actor, $requested);
    }

    private static function ownedProduct(array $actor, int $productId): array
    {
        $sql = 'SELECT p.* FROM platform_exchange_products p
                INNER JOIN platform_accounts pa ON pa.id = p.platform_id
                WHERE p.id = ? AND p.deleted_at IS NULL';
        $query = [$productId];
        if ((int) $actor['level'] === 2) {
            $sql .= ' AND p.platform_id = ?';
            $query[] = (int) $actor['id'];
        } else {
            $sql .= ' AND (p.platform_id = ? OR pa.parent_id = ?)';
            $query[] = (int) $actor['id'];
            $query[] = (int) $actor['id'];
        }
        $product = Database::one($sql, $query);
        if ($product === null) {
            throw new HttpException('余额兑换商品不存在或不在当前平台范围内', 404, 404);
        }
        return $product;
    }

    private static function ownedOrder(array $actor, int $orderId): array
    {
        $sql = 'SELECT o.* FROM admin_exchange_orders o
                INNER JOIN platform_accounts p ON p.id = o.platform_id
                WHERE o.id = ?';
        $query = [$orderId];
        if ((int) $actor['level'] === 2) {
            $sql .= ' AND o.platform_id = ?';
            $query[] = (int) $actor['id'];
        } else {
            $sql .= ' AND (o.platform_id = ? OR p.parent_id = ?)';
            $query[] = (int) $actor['id'];
            $query[] = (int) $actor['id'];
        }
        $order = Database::one($sql, $query);
        if ($order === null) {
            throw new HttpException('兑换订单不存在或不在当前平台范围内', 404, 404);
        }
        return $order;
    }

    private static function platformScope(array $actor, Request $request, string $alias): array
    {
        if ((int) $actor['level'] === 2) {
            return ["{$alias}.platform_id = ?", [(int) $actor['id']]];
        }
        $platformId = (int) $request->input('platform_id', 0);
        if ($platformId > 0) {
            if ($platformId !== (int) $actor['id']) {
                PlatformService::ownedOperator($actor, $platformId);
            }
            return ["{$alias}.platform_id = ?", [$platformId]];
        }
        return [
            "({$alias}.platform_id = ? OR {$alias}.platform_id IN
              (SELECT id FROM platform_accounts WHERE parent_id = ? AND level = 2 AND deleted_at IS NULL))",
            [(int) $actor['id'], (int) $actor['id']],
        ];
    }
}
