<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class PaymentService
{
    public static function orderNo(): string
    {
        return 'YY' . date('YmdHis') . strtoupper(bin2hex(random_bytes(5)));
    }

    public static function signature(array $payload, string $secret): string
    {
        unset($payload['sign']);
        ksort($payload, SORT_STRING);
        $pairs = [];
        foreach ($payload as $key => $value) {
            if (is_array($value) || is_object($value) || $value === null) {
                continue;
            }
            $pairs[] = (string) $key . '=' . (string) $value;
        }
        return hash_hmac('sha256', implode('&', $pairs), $secret);
    }

    public static function channel(int $adminId, int $appId, string $code, bool $requireEnabled = true): array
    {
        $sql = 'SELECT * FROM payment_channels WHERE admin_id = ? AND app_id = ? AND channel_code = ?';
        $params = [$adminId, $appId, $code];
        if ($requireEnabled) {
            $sql .= ' AND enabled = 1';
        }
        $channel = Database::one($sql, $params);
        if ($channel === null) {
            throw new HttpException('支付渠道不存在或未启用', 0, 422);
        }
        $config = json_decode((string) ($channel['config_json'] ?? '{}'), true);
        $channel['config'] = is_array($config) ? $config : [];
        return $channel;
    }

    public static function fulfill(array $order): void
    {
        $user = [
            'id' => (int) $order['user_id'],
            'admin_id' => (int) $order['admin_id'],
            'app_id' => (int) $order['app_id'],
        ];
        $type = (string) $order['order_type'];
        if ($type === 'shop_goods') {
            $goods = Database::one(
                'SELECT * FROM shop_goods WHERE id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [(int) $order['target_id'], (int) $order['admin_id'], (int) $order['app_id']]
            );
            if ($goods === null || (int) $goods['status'] !== 1 || (int) $goods['stock'] < (int) $order['quantity']) {
                throw new HttpException('商品不存在、已下架或库存不足', 0, 409);
            }
            Database::execute(
                'UPDATE shop_goods SET stock = stock - ?, sales_count = sales_count + ?, updated_at = NOW() WHERE id = ?',
                [(int) $order['quantity'], (int) $order['quantity'], (int) $goods['id']]
            );
            $autoCompleted = (string) ($goods['goods_type'] ?? 'virtual') === 'virtual'
                && (int) ($goods['delivery_required'] ?? 0) === 0;
            $shopOrderId = Database::insert(
                'INSERT INTO shop_orders
                 (admin_id, app_id, user_id, goods_id, goods_name, goods_cover_url, goods_type,
                  order_no, quantity, unit_price_integral, unit_price_money, amount_integral,
                  amount_money, buyer_info_json, status, paid_at, fulfilled_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0, ?, ?, ?, NOW(), ?, NOW(), NOW())',
                [
                    (int) $order['admin_id'], (int) $order['app_id'], (int) $order['user_id'],
                    (int) $goods['id'], (string) $goods['name'], (string) $goods['cover_url'],
                    (string) ($goods['goods_type'] ?? 'virtual'), (string) $order['order_no'],
                    (int) $order['quantity'], (float) $goods['price_money'], (float) $order['pay_amount'],
                    $order['buyer_info_json'] ?? null, $autoCompleted ? 'completed' : 'paid',
                    $autoCompleted ? date('Y-m-d H:i:s') : null,
                ]
            );
            $shopOrder = [
                'id' => $shopOrderId,
                'admin_id' => (int) $order['admin_id'],
                'app_id' => (int) $order['app_id'],
                'user_id' => (int) $order['user_id'],
                'order_no' => (string) $order['order_no'],
            ];
            OrderTrackingService::record($shopOrder, 'shop', 'paid', '付款成功', '现金支付已确认，商城订单已生成');
            if ($autoCompleted) {
                OrderTrackingService::record($shopOrder, 'shop', 'completed', '交付完成', '虚拟商品已自动完成交付');
            }
            return;
        }
        if ($type === 'balance_recharge') {
            WalletService::adjust($user, 'balance', (float) $order['pay_amount'], 'payment_recharge', 'order', (int) $order['id']);
            return;
        }
        if ($type === 'document_credit') {
            $credits = max(1, (int) $order['target_id']);
            WalletService::adjust($user, 'document_credit', $credits, 'payment_document_credit', 'order', (int) $order['id']);
            return;
        }
        if ($type === 'vip') {
            $days = max(1, (int) $order['target_id']);
            WalletService::addVipDays($user, $days, 'payment_vip', 'order', (int) $order['id']);
            return;
        }
        throw new HttpException('订单类型没有对应的交付逻辑', -1, 500);
    }
}
