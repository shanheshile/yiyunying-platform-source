<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\ExchangeService;
use Yiyunying\Services\LogService;

final class ExchangeController
{
    public static function products(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $where = [
            'platform_id = ?', 'status = 1', 'deleted_at IS NULL',
            '(start_at IS NULL OR start_at <= NOW())', '(end_at IS NULL OR end_at >= NOW())',
        ];
        $query = [(int) $admin['platform_id']];
        $type = trim((string) $request->input('product_type', ''));
        if ($type !== '') {
            $where[] = 'product_type = ?';
            $query[] = $type;
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(name LIKE ? OR product_code LIKE ? OR description LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($query, $like, $like, $like);
        }
        $rows = Database::all(
            'SELECT * FROM platform_exchange_products WHERE ' . implode(' AND ', $where)
            . ' ORDER BY sort_order, id',
            $query
        );
        $items = [];
        foreach ($rows as $row) {
            $quote = ExchangeService::quote($admin, (int) $row['id'], 1);
            $item = ExchangeService::productData($row);
            $item['quote_for_one'] = [
                'can_exchange' => $quote['can_exchange'],
                'reasons' => $quote['reasons'],
                'balance_before' => $quote['balance_before'],
                'balance_after' => $quote['balance_after'],
                'usage' => $quote['usage'],
            ];
            $items[] = $item;
        }
        return Response::success([
            'balance' => (int) $admin['admin_integral'],
            'items' => $items,
        ]);
    }

    public static function product(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $product = ExchangeService::productForAdmin($admin, (int) $params['product_id'], true);
        return Response::success([
            'product' => ExchangeService::productData($product),
            'quote_for_one' => ExchangeService::quote($admin, (int) $product['id'], 1),
        ]);
    }

    public static function quote(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $productId = (int) $request->input('product_id', 0);
        $quantity = (int) $request->input('quantity', 1);
        if ($productId <= 0) {
            throw new HttpException('product_id 不能为空', 0, 422);
        }
        return Response::success(['quote' => ExchangeService::quote($admin, $productId, $quantity)]);
    }

    public static function exchange(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $productId = (int) $request->input('product_id', 0);
        $quantity = (int) $request->input('quantity', 1);
        if ($productId <= 0) {
            throw new HttpException('product_id 不能为空', 0, 422);
        }
        $idempotencyKey = trim((string) (
            $request->header('idempotency-key')
            ?? $request->input('idempotency_key', '')
        ));
        if ($idempotencyKey === '') {
            $idempotencyKey = 'auto:' . bin2hex(random_bytes(16));
        }
        $result = ExchangeService::exchange($admin, $productId, $quantity, $idempotencyKey);
        if (!$result['idempotent']) {
            LogService::adminOperation(
                $request,
                (int) $admin['id'],
                null,
                'point_exchange',
                'complete',
                (int) $result['order']['id'],
                null,
                [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'total_balance' => (int) $result['order']['total_balance'],
                ]
            );
        }
        $result['idempotency_key'] = $idempotencyKey;
        return Response::success($result, $result['idempotent'] ? '重复请求已返回原兑换结果' : '余额兑换成功', $result['idempotent'] ? 200 : 201);
    }

    public static function orders(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $where = ['admin_id = ?'];
        $query = [(int) $admin['id']];
        $status = trim((string) $request->input('status', ''));
        if ($status !== '') {
            $where[] = 'status = ?';
            $query[] = $status;
        }
        $productType = trim((string) $request->input('product_type', ''));
        if ($productType !== '') {
            $where[] = 'product_type = ?';
            $query[] = $productType;
        }
        $whereSql = implode(' AND ', $where);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM admin_exchange_orders WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $rows = Database::all(
            "SELECT * FROM admin_exchange_orders WHERE {$whereSql}
             ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = array_map(static fn(array $row): array => ExchangeService::orderData($row), $rows);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function order(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $order = Database::one(
            'SELECT * FROM admin_exchange_orders WHERE id = ? AND admin_id = ?',
            [(int) $params['exchange_id'], (int) $admin['id']]
        );
        if ($order === null) {
            throw new HttpException('兑换订单不存在', 404, 404);
        }
        return Response::success(['order' => ExchangeService::orderData($order)]);
    }

    public static function integralLogs(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $where = ['admin_id = ?'];
        $query = [(int) $admin['id']];
        $scene = trim((string) $request->input('scene', ''));
        if ($scene !== '') {
            $where[] = 'scene = ?';
            $query[] = $scene;
        }
        $whereSql = implode(' AND ', $where);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM admin_integral_logs WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT * FROM admin_integral_logs WHERE {$whereSql}
             ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
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
}
