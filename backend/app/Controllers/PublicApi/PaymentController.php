<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\PublicApi;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AppService;
use Yiyunying\Services\PaymentService;
use Yiyunying\Services\NotificationService;

final class PaymentController
{
    public static function callback(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $payload = $request->all();
        $channelCode = trim((string) $params['channel']);
        $appKey = trim((string) ($payload['app_key'] ?? ''));
        $orderNo = trim((string) ($payload['order_no'] ?? ''));
        $tradeNo = trim((string) ($payload['trade_no'] ?? ''));
        $sign = trim((string) ($payload['sign'] ?? ''));
        $app = null;
        $verified = false;
        $resultText = 'invalid_request';
        try {
            if ($appKey === '' || $orderNo === '' || $tradeNo === '' || $sign === '') {
                throw new HttpException('支付回调参数不完整', 0, 422);
            }
            $app = AppService::byKey($appKey, false);
            $request->setAttribute('admin_id', (int) $app['admin_id']);
            $request->setAttribute('app_id', (int) $app['id']);
            $channel = PaymentService::channel((int) $app['admin_id'], (int) $app['id'], $channelCode);
            $secret = (string) ($channel['config']['secret'] ?? '');
            if ($secret === '' || !hash_equals(PaymentService::signature($payload, $secret), $sign)) {
                throw new HttpException('支付回调签名错误', 403, 403);
            }
            $timestamp = (int) ($payload['timestamp'] ?? 0);
            if ($timestamp <= 0 || abs(time() - $timestamp) > 900) {
                throw new HttpException('支付回调时间戳已过期', 403, 403);
            }
            if (strtolower((string) ($payload['status'] ?? 'paid')) !== 'paid') {
                throw new HttpException('支付状态不是 paid', 0, 422);
            }
            $verified = true;
            $result = Database::transaction(static function () use ($app, $channelCode, $orderNo, $tradeNo, $payload): array {
                $order = Database::one(
                    'SELECT * FROM orders WHERE order_no = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                    [$orderNo, (int) $app['admin_id'], (int) $app['id']]
                );
                if ($order === null) {
                    throw new HttpException('订单不存在', 404, 404);
                }
                if ((string) $order['pay_channel'] !== $channelCode) {
                    throw new HttpException('支付渠道与订单不匹配', 0, 409);
                }
                $amount = round((float) ($payload['amount'] ?? -1), 2);
                if (abs($amount - (float) $order['pay_amount']) > 0.001) {
                    throw new HttpException('支付金额与订单金额不一致', 0, 409);
                }
                if ((string) $order['status'] === 'paid') {
                    return ['order_id' => (int) $order['id'], 'order_no' => $orderNo, 'idempotent' => true,
                        'user_id' => (int) $order['user_id'], 'title' => (string) $order['title'], 'amount' => (float) $order['pay_amount']];
                }
                if ((string) $order['status'] !== 'pending') {
                    throw new HttpException('当前订单状态不允许支付', 0, 409);
                }
                Database::execute(
                    'INSERT INTO payments
                     (admin_id, app_id, order_id, channel_code, trade_no, amount, callback_json, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [
                        (int) $app['admin_id'], (int) $app['id'], (int) $order['id'], $channelCode,
                        $tradeNo, $amount, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'paid',
                    ]
                );
                PaymentService::fulfill($order);
                Database::execute(
                    "UPDATE orders SET status = 'paid', paid_at = NOW(), updated_at = NOW() WHERE id = ?",
                    [(int) $order['id']]
                );
                return ['order_id' => (int) $order['id'], 'order_no' => $orderNo, 'idempotent' => false,
                    'user_id' => (int) $order['user_id'], 'title' => (string) $order['title'], 'amount' => (float) $order['pay_amount']];
            });
            if (!$result['idempotent']) {
                $user = NotificationService::user((int) $app['admin_id'], (int) $app['id'], (int) $result['user_id']);
                if ($user !== null) NotificationService::send(
                    $user, 'order_paid', '订单支付成功', (string) $result['title'] . '已支付成功',
                    ['order_id' => (int) $result['order_id'], 'order_no' => (string) $result['order_no'], 'amount' => (float) $result['amount'], 'status' => 'paid']
                );
            }
            $resultText = $result['idempotent'] ? 'already_paid' : 'paid';
            return Response::success($result, $result['idempotent'] ? '回调已处理' : '支付成功');
        } finally {
            Database::execute(
                'INSERT INTO payment_callback_logs
                 (admin_id, app_id, channel_code, order_no, request_json, verified, result, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $app === null ? null : (int) $app['admin_id'],
                    $app === null ? null : (int) $app['id'],
                    $channelCode, $orderNo,
                    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $verified ? 1 : 0, $resultText,
                ]
            );
        }
    }
}
