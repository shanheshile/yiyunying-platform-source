<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\User;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Validator;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\NotificationService;
use Yiyunying\Services\PaymentService;
use Yiyunying\Services\RedPacketAmountService;
use Yiyunying\Services\RedPacketRuleService;
use Yiyunying\Services\TransferPolicyService;
use Yiyunying\Services\WalletService;

final class CommerceController
{
    public static function orders(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'payments');
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['app_id = ?', 'user_id = ?'];
        $query = [(int) $user['app_id'], (int) $user['id']];
        if (trim((string) $request->input('status', '')) !== '') {
            $where[] = 'status = ?';
            $query[] = trim((string) $request->input('status'));
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM orders WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT * FROM orders WHERE {$whereSql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function createOrder(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'payments');
        $data = $request->all();
        Validator::required($data, ['order_type', 'target_id', 'quantity', 'pay_channel']);
        $type = trim((string) $data['order_type']);
        if (!in_array($type, ['shop_goods', 'balance_recharge', 'document_credit', 'vip'], true)) {
            throw new HttpException('不支持的订单类型', 0, 422);
        }
        $targetId = Validator::integer($data['target_id'], 'target_id', 1, PHP_INT_MAX);
        $quantity = Validator::integer($data['quantity'], 'quantity', 1, 10000);
        $channelCode = Validator::string($data['pay_channel'], 'pay_channel', 2, 40);
        $channel = PaymentService::channel((int) $user['admin_id'], (int) $user['app_id'], $channelCode);
        [$title, $amount] = self::orderPrice($user, $type, $targetId, $quantity, $data);
        if ($amount <= 0) {
            throw new HttpException('订单金额必须大于 0', 0, 422);
        }
        $orderNo = PaymentService::orderNo();
        $orderId = Database::insert(
            'INSERT INTO orders
             (admin_id, app_id, user_id, order_no, order_type, target_id, title, quantity,
              amount, pay_amount, pay_channel, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $orderNo,
                $type, $targetId, $title, $quantity, $amount, $amount, $channelCode, 'pending',
            ]
        );
        $payload = [
            'app_key' => (string) $user['app_key'],
            'order_no' => $orderNo,
            'amount' => number_format($amount, 2, '.', ''),
            'timestamp' => time(),
        ];
        $secret = (string) ($channel['config']['secret'] ?? '');
        $payload['sign'] = PaymentService::signature($payload, $secret);
        $gateway = trim((string) ($channel['config']['gateway_url'] ?? ''));
        LogService::userOperation($request, $user, 'order', 'create', $orderId, ['order_type' => $type]);
        NotificationService::send(
            $user, 'order_created', '订单已创建', $title . '订单已创建，请按页面提示完成支付',
            ['order_id' => $orderId, 'order_no' => $orderNo, 'order_type' => $type, 'amount' => $amount, 'status' => 'pending']
        );
        return Response::success([
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'amount' => $amount,
            'channel' => $channelCode,
            'gateway_url' => $gateway,
            'pay_params' => $payload,
            'callback_url' => rtrim((string) config('app.url'), '/') . '/api/public/payment/callback/' . rawurlencode($channelCode),
        ], '订单创建成功', 201);
    }

    public static function cancelOrder(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'payments');
        $affected = Database::execute(
            "UPDATE orders SET status = 'closed', closed_at = NOW(), updated_at = NOW()
             WHERE id = ? AND app_id = ? AND user_id = ? AND status = 'pending'",
            [(int) $params['order_id'], (int) $user['app_id'], (int) $user['id']]
        );
        if ($affected === 0) {
            throw new HttpException('订单不存在或不能取消', 0, 409);
        }
        NotificationService::send(
            $user, 'order_cancelled', '订单已取消', '订单已关闭，不会继续扣款',
            ['order_id' => (int) $params['order_id'], 'status' => 'closed']
        );
        return Response::success(['order_id' => (int) $params['order_id'], 'status' => 'closed'], '订单已取消');
    }

    public static function goods(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'shop');
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one('SELECT COUNT(*) AS total FROM shop_goods WHERE app_id = ? AND status = 1', [
            (int) $user['app_id'],
        ])['total'] ?? 0);
        $items = Database::all(
            "SELECT * FROM shop_goods WHERE app_id = ? AND status = 1 ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            [(int) $user['app_id']]
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function buyGoods(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'shop');
        $quantity = Validator::integer($request->input('quantity', 1), 'quantity', 1, 10000);
        $goodsId = (int) $params['goods_id'];
        $goodsPreview = Database::one(
            'SELECT * FROM shop_goods WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1',
            [$goodsId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        if ($goodsPreview === null || (int) $goodsPreview['stock'] < $quantity) {
            throw new HttpException('商品不存在、已下架或库存不足', 0, 409);
        }
        if ((int) $goodsPreview['price_integral'] <= 0 && (float) $goodsPreview['price_money'] > 0) {
            return self::createShopPaymentOrder($request, $user, $goodsPreview, $quantity);
        }
        $result = Database::transaction(static function () use ($user, $quantity, $goodsId): array {
            $goods = Database::one(
                'SELECT * FROM shop_goods WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1 FOR UPDATE',
                [$goodsId, (int) $user['admin_id'], (int) $user['app_id']]
            );
            if ($goods === null || (int) $goods['stock'] < $quantity) {
                throw new HttpException('商品不存在、已下架或库存不足', 0, 409);
            }
            if ((int) $goods['price_integral'] <= 0 && (float) $goods['price_money'] > 0) {
                throw new HttpException('该商品需要先创建现金支付订单', 0, 422);
            }
            $cost = (int) $goods['price_integral'] * $quantity;
            if ($cost > 0) {
                $asset = WalletService::requireActivityEnabled((int) $user['app_id']);
                WalletService::adjust($user, $asset, -$cost, 'shop_buy', 'shop_goods', $goodsId, '余额购买商品');
            }
            $orderNo = PaymentService::orderNo();
            $orderId = Database::insert(
                'INSERT INTO shop_orders
                 (admin_id, app_id, user_id, goods_id, order_no, quantity, amount_integral, amount_money, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $goodsId, $orderNo, $quantity, $cost, 'paid']
            );
            Database::execute(
                'UPDATE shop_goods SET stock = stock - ?, sales_count = sales_count + ?, updated_at = NOW() WHERE id = ?',
                [$quantity, $quantity, $goodsId]
            );
            return ['shop_order_id' => $orderId, 'order_no' => $orderNo, 'cost_balance' => $cost];
        });
        NotificationService::send(
            $user, 'shop_purchase', '商品购买成功', '你购买的商品已生成订单，可在“我的订单”中查看',
            ['shop_order_id' => (int) $result['shop_order_id'], 'order_no' => (string) $result['order_no'], 'goods_id' => $goodsId, 'quantity' => $quantity]
        );
        return Response::success($result, '商品购买成功', 201);
    }

    private static function createShopPaymentOrder(Request $request, array $user, array $goods, int $quantity): \Yiyunying\Core\ApiResponse
    {
        $channelCode = Validator::string($request->input('pay_channel', ''), 'pay_channel', 2, 40);
        $channel = PaymentService::channel((int) $user['admin_id'], (int) $user['app_id'], $channelCode);
        $amount = round((float) $goods['price_money'] * $quantity, 2);
        if ($amount <= 0) throw new HttpException('商品支付金额必须大于 0', 0, 422);
        $orderNo = PaymentService::orderNo();
        $orderId = Database::insert(
            'INSERT INTO orders
             (admin_id, app_id, user_id, order_no, order_type, target_id, title, quantity,
              amount, pay_amount, pay_channel, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $orderNo,
                'shop_goods', (int) $goods['id'], (string) $goods['name'], $quantity,
                $amount, $amount, $channelCode, 'pending',
            ]
        );
        $payload = [
            'app_key' => (string) $user['app_key'],
            'order_no' => $orderNo,
            'amount' => number_format($amount, 2, '.', ''),
            'timestamp' => time(),
        ];
        $payload['sign'] = PaymentService::signature($payload, (string) ($channel['config']['secret'] ?? ''));
        LogService::userOperation($request, $user, 'order', 'shop_create', $orderId, ['goods_id' => (int) $goods['id'], 'quantity' => $quantity]);
        NotificationService::send(
            $user, 'order_created', '商品订单已创建', '《' . (string) $goods['name'] . '》订单已创建，请完成支付',
            ['order_id' => $orderId, 'order_no' => $orderNo, 'goods_id' => (int) $goods['id'], 'quantity' => $quantity, 'amount' => $amount]
        );
        return Response::success([
            'payment_required' => true,
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'amount' => $amount,
            'channel' => $channelCode,
            'gateway_url' => trim((string) ($channel['config']['gateway_url'] ?? '')),
            'pay_params' => $payload,
            'callback_url' => rtrim((string) config('app.url'), '/') . '/api/public/payment/callback/' . rawurlencode($channelCode),
        ], '商品订单已自动生成，请完成支付', 201);
    }

    public static function createRedPacket(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'red_packets');
        $rawRecipients = $request->input('to_user_ids', []);
        if (!is_array($rawRecipients)) $rawRecipients = [];
        if ($rawRecipients === [] && (int) $request->input('to_user_id', 0) > 0) {
            $rawRecipients[] = (int) $request->input('to_user_id');
        }
        $recipientIds = array_values(array_unique(array_filter(
            array_map('intval', $rawRecipients),
            static fn(int $id): bool => $id > 0
        )));
        if (count($recipientIds) > 500) {
            throw new HttpException('单次最多可指定 500 位红包参与人', 0, 422);
        }

        try {
            $distributionMode = RedPacketRuleService::distributionMode($request->input('distribution_mode', ''));
            $eligibilityMode = RedPacketRuleService::eligibilityMode(
                $request->input('eligibility_mode', ''),
                $recipientIds !== []
            );
        } catch (\InvalidArgumentException $exception) {
            throw new HttpException($exception->getMessage(), 0, 422);
        }

        $type = trim((string) $request->input('packet_type', 'random'));
        if (!in_array($type, ['equal', 'random'], true)) {
            throw new HttpException('红包金额类型仅支持等额或随机金额', 0, 422);
        }
        $deliveryScope = trim((string) $request->input('delivery_scope', 'private'));
        if ($deliveryScope === 'room') $deliveryScope = 'group';
        if (!in_array($deliveryScope, ['private', 'group', 'chat_room', 'service', 'activity'], true)) {
            throw new HttpException('红包投放范围仅支持私聊、群聊、聊天室、客服会话或活动', 0, 422);
        }
        $contextId = Validator::integer($request->input('context_id', 0), 'context_id', 0, PHP_INT_MAX);
        $contextUserId = Validator::integer($request->input('context_user_id', 0), 'context_user_id', 0, PHP_INT_MAX);
        $includeSender = self::booleanValue($request->input('include_sender', true), true);
        $receivers = self::redPacketEligibleUsers(
            $user,
            $deliveryScope,
            $contextId,
            $contextUserId,
            $eligibilityMode,
            $recipientIds,
            $includeSender
        );
        $participantCount = count($receivers);

        try {
            $totalAmount = RedPacketAmountService::normalize($request->input('total_amount'));
            $totalCents = RedPacketAmountService::parseCents($totalAmount);
            $totalCount = RedPacketRuleService::totalCount(
                $distributionMode,
                $request->input('total_count', $participantCount),
                $participantCount
            );
        } catch (\InvalidArgumentException $exception) {
            throw new HttpException($exception->getMessage(), 0, 422);
        }
        if ($distributionMode === RedPacketRuleService::DISTRIBUTION_COUNT_SPLIT && $totalCents < $totalCount) {
            throw new HttpException('红包总余额不足，每份红包至少需要 0.01 余额', 0, 422);
        }

        $packetLabel = mb_substr(trim((string) $request->input(
            'packet_label',
            RedPacketRuleService::distributionLabel($distributionMode)
        )), 0, 30);
        if ($packetLabel === '') {
            $packetLabel = RedPacketRuleService::distributionLabel($distributionMode);
        }
        $expiredAt = date(
            'Y-m-d H:i:s',
            time() + min(604800, max(60, (int) $request->input('expire_seconds', 86400)))
        );

        $packetId = Database::transaction(static function () use (
            $user, $receivers, $type, $packetLabel, $distributionMode, $eligibilityMode,
            $deliveryScope, $contextId, $totalAmount, $totalCount, $expiredAt, $request
        ): int {
            $asset = WalletService::requireActivityEnabled((int) $user['app_id']);
            WalletService::adjust($user, $asset, -(float) $totalAmount, 'red_packet_send', 'red_packet', null, '发红包');
            $packetId = Database::insert(
                'INSERT INTO red_packets
                 (admin_id, app_id, user_id, packet_type, packet_label, distribution_mode, eligibility_mode,
                  delivery_scope, context_id, total_amount, total_count, remain_amount, remain_count,
                  message, status, expired_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $type, $packetLabel,
                    $distributionMode, $eligibilityMode, $deliveryScope, $contextId,
                    $totalAmount, $totalCount, $totalAmount, $totalCount,
                    mb_substr((string) $request->input('message', '恭喜发财'), 0, 255), $expiredAt,
                ]
            );
            foreach ($receivers as $receiver) {
                Database::execute(
                    'INSERT INTO red_packet_recipients (admin_id, app_id, packet_id, user_id, created_at)
                     VALUES (?, ?, ?, ?, NOW())',
                    [(int) $user['admin_id'], (int) $user['app_id'], $packetId, (int) $receiver['id']]
                );
            }
            return $packetId;
        });

        foreach ($receivers as $receiver) {
            if ((int) $receiver['id'] === (int) $user['id']) continue;
            NotificationService::send($receiver, 'red_packet_pending', '收到一个红包', '你收到一个 24 小时内可领取的红包', [
                'packet_id' => $packetId,
                'from_user_id' => (int) $user['id'],
                'delivery_scope' => $deliveryScope,
                'context_id' => $contextId,
                'packet_type' => $type,
                'packet_label' => $packetLabel,
                'distribution_mode' => $distributionMode,
                'eligibility_mode' => $eligibilityMode,
            ]);
        }

        return Response::success([
            'packet_id' => $packetId,
            'delivery_scope' => $deliveryScope,
            'context_id' => $contextId,
            'packet_type' => $type,
            'packet_label' => $packetLabel,
            'distribution_mode' => $distributionMode,
            'distribution_label' => RedPacketRuleService::distributionLabel($distributionMode),
            'eligibility_mode' => $eligibilityMode,
            'eligibility_label' => RedPacketRuleService::eligibilityLabel($eligibilityMode),
            'participant_count' => $participantCount,
            'claim_rule' => RedPacketRuleService::claimRule($distributionMode, $eligibilityMode, $participantCount),
            'total_amount' => $totalAmount,
            'total_count' => $totalCount,
            'share_count' => $distributionMode === RedPacketRuleService::DISTRIBUTION_COUNT_SPLIT ? $totalCount : null,
            'remaining_share_count' => $distributionMode === RedPacketRuleService::DISTRIBUTION_COUNT_SPLIT ? $totalCount : null,
            'remaining_participant_count' => $distributionMode === RedPacketRuleService::DISTRIBUTION_RANDOM_GRAB ? $totalCount : null,
            'amount_pool_exhaustible' => $distributionMode === RedPacketRuleService::DISTRIBUTION_RANDOM_GRAB,
            'expired_at' => $expiredAt,
        ], '红包发送成功', 201);
    }

    public static function claimRedPacket(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'red_packets');
        self::expireCommerce($user);
        $packetId = (int) $params['packet_id'];
        $amount = Database::transaction(static function () use ($user, $packetId): string {
            $packet = Database::one(
                'SELECT * FROM red_packets WHERE id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [$packetId, (int) $user['admin_id'], (int) $user['app_id']]
            );
            if ($packet === null || (int) $packet['status'] !== 1 || strtotime((string) $packet['expired_at']) <= time()
                || (int) $packet['remain_count'] <= 0 || (float) $packet['remain_amount'] <= 0) {
                throw new HttpException('红包不存在、已过期或已领完', 0, 409);
            }
            $targeted = Database::one('SELECT COUNT(*) AS total FROM red_packet_recipients WHERE packet_id = ?', [$packetId]);
            if ((int) ($targeted['total'] ?? 0) > 0 && Database::one(
                'SELECT id FROM red_packet_recipients WHERE packet_id = ? AND user_id = ?',
                [$packetId, (int) $user['id']]
            ) === null) {
                throw new HttpException('你不在该红包的领取范围内', 403, 403);
            }
            if (Database::one('SELECT id FROM red_packet_claims WHERE packet_id = ? AND user_id = ?', [$packetId, (int) $user['id']])) {
                throw new HttpException('你已经领取过该红包', 0, 409);
            }
            if (Database::one('SELECT id FROM red_packet_returns WHERE packet_id = ? AND user_id = ?', [$packetId, (int) $user['id']])) {
                throw new HttpException('你已经把该红包退回给发送人', 0, 409);
            }
            $claim = self::redPacketSettlementAmount($packet);
            Database::execute(
                'INSERT INTO red_packet_claims (admin_id, app_id, packet_id, user_id, amount, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], $packetId, (int) $user['id'], $claim]
            );
            $remainingState = self::redPacketRemainingState($packet, $claim);
            Database::execute(
                'UPDATE red_packets SET status = ?, remain_amount = ?, remain_count = ? WHERE id = ?',
                [$remainingState['status'], $remainingState['remain_amount'], $remainingState['remain_count'], $packetId]
            );
            $asset = WalletService::requireActivityEnabled((int) $user['app_id']);
            WalletService::adjust($user, $asset, (float) $claim, 'red_packet_claim', 'red_packet', $packetId, '领取红包');
            return $claim;
        });
        return Response::success(['packet_id' => $packetId, 'amount' => $amount], '红包领取成功');
    }

    public static function redPackets(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'red_packets');
        self::expireCommerce($user);
        return Response::success(['items' => Database::all(
            'SELECT r.*, u.account, p.nickname,
                    EXISTS(SELECT 1 FROM red_packet_claims c WHERE c.packet_id = r.id AND c.user_id = ?) AS claimed,
                    EXISTS(SELECT 1 FROM red_packet_returns returned WHERE returned.packet_id = r.id AND returned.user_id = ?) AS returned
             FROM red_packets r INNER JOIN users u ON u.id = r.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE r.app_id = ? AND r.admin_id = ? AND (
                 r.user_id = ?
                 OR NOT EXISTS(SELECT 1 FROM red_packet_recipients legacy_scope WHERE legacy_scope.packet_id = r.id)
                 OR EXISTS(SELECT 1 FROM red_packet_recipients scope WHERE scope.packet_id = r.id AND scope.user_id = ?)
             ) ORDER BY r.id DESC LIMIT 100',
            [(int) $user['id'], (int) $user['id'], (int) $user['app_id'], (int) $user['admin_id'], (int) $user['id'], (int) $user['id']]
        )]);
    }

    public static function redPacketDetail(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'red_packets');
        self::expireCommerce($user);
        $packetId = (int) $params['packet_id'];
        $packet = Database::one(
            "SELECT packet.*, creator.account AS creator_account,
                    COALESCE(NULLIF(profile.nickname, ''), creator.account) AS creator_name
             FROM red_packets packet INNER JOIN users creator ON creator.id = packet.user_id
             LEFT JOIN user_profiles profile ON profile.user_id = creator.id
             WHERE packet.id = ? AND packet.admin_id = ? AND packet.app_id = ?",
            [$packetId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        if ($packet === null) throw new HttpException('红包不存在', 404, 404);
        $recipients = Database::all(
            "SELECT recipient.user_id, target.account,
                    COALESCE(NULLIF(profile.nickname, ''), target.account) AS nickname,
                    profile.avatar AS avatar_url,
                    claim.id AS claim_id, claim.amount AS claimed_amount, claim.created_at AS claimed_at,
                    returned.amount AS returned_amount, returned.created_at AS returned_at,
                    CASE
                        WHEN claim.id IS NOT NULL THEN 'claimed'
                        WHEN returned.id IS NOT NULL THEN 'returned'
                        ELSE 'pending'
                    END AS settlement_status
             FROM red_packet_recipients recipient INNER JOIN users target ON target.id = recipient.user_id
             LEFT JOIN user_profiles profile ON profile.user_id = target.id
             LEFT JOIN red_packet_claims claim ON claim.packet_id = recipient.packet_id AND claim.user_id = recipient.user_id
             LEFT JOIN red_packet_returns returned ON returned.packet_id = recipient.packet_id AND returned.user_id = recipient.user_id
             WHERE recipient.packet_id = ? ORDER BY recipient.id",
            [$packetId]
        );
        $isSender = (int) $packet['user_id'] === (int) $user['id'];
        $recipientEligible = $recipients === [] || count(array_filter(
            $recipients,
            static fn(array $recipient): bool => (int) $recipient['user_id'] === (int) $user['id']
        )) > 0;
        if (!$recipientEligible && !$isSender) throw new HttpException('红包不存在', 404, 404);
        $claims = Database::all(
            "SELECT claim.id, claim.user_id, claim.amount, claim.created_at, target.account,
                    COALESCE(NULLIF(profile.nickname, ''), target.account) AS nickname,
                    profile.avatar AS avatar_url
             FROM red_packet_claims claim INNER JOIN users target ON target.id = claim.user_id
             LEFT JOIN user_profiles profile ON profile.user_id = target.id
             WHERE claim.packet_id = ? ORDER BY claim.id",
            [$packetId]
        );
        $returns = Database::all(
            "SELECT returned.id, returned.user_id, returned.amount, returned.created_at, target.account,
                    COALESCE(NULLIF(profile.nickname, ''), target.account) AS nickname,
                    profile.avatar AS avatar_url
             FROM red_packet_returns returned INNER JOIN users target ON target.id = returned.user_id
             LEFT JOIN user_profiles profile ON profile.user_id = target.id
             WHERE returned.packet_id = ? ORDER BY returned.id",
            [$packetId]
        );
        $luckiestClaimId = 0;
        $luckiestAmount = '0.00';
        foreach ($claims as $claim) {
            if ($luckiestClaimId === 0 || RedPacketAmountService::compare($claim['amount'], $luckiestAmount) > 0) {
                $luckiestClaimId = (int) $claim['id'];
                $luckiestAmount = RedPacketAmountService::normalizeStored($claim['amount']);
            }
        }
        foreach ($claims as &$claim) {
            $claim['amount'] = RedPacketAmountService::normalizeStored($claim['amount']);
            $claim['is_luckiest'] = (int) $claim['id'] === $luckiestClaimId;
            $claim['amount_label'] = $claim['is_luckiest'] ? '运气王' : '已领取';
        }
        unset($claim);
        foreach ($returns as &$returned) {
            $returned['amount'] = RedPacketAmountService::normalizeStored($returned['amount']);
        }
        unset($returned);
        foreach ($recipients as &$recipient) {
            if ($recipient['claimed_amount'] !== null) {
                $recipient['claimed_amount'] = RedPacketAmountService::normalizeStored($recipient['claimed_amount']);
            }
            if ($recipient['returned_amount'] !== null) {
                $recipient['returned_amount'] = RedPacketAmountService::normalizeStored($recipient['returned_amount']);
            }
            $recipient['is_luckiest'] = $recipient['settlement_status'] === 'claimed'
                && (int) ($recipient['claim_id'] ?? 0) === $luckiestClaimId;
        }
        unset($recipient);
        $packet['packet_label'] = trim((string) ($packet['packet_label'] ?? '')) !== ''
            ? (string) $packet['packet_label']
            : ((string) $packet['packet_type'] === 'equal' ? '等额红包' : '拼手气红包');
        $distributionMode = RedPacketRuleService::distributionMode($packet['distribution_mode'] ?? 'count_split');
        $eligibilityMode = RedPacketRuleService::eligibilityMode(
            $packet['eligibility_mode'] ?? '',
            $recipients !== []
        );
        $packet['distribution_mode'] = $distributionMode;
        $packet['distribution_label'] = RedPacketRuleService::distributionLabel($distributionMode);
        $packet['eligibility_mode'] = $eligibilityMode;
        $packet['eligibility_label'] = RedPacketRuleService::eligibilityLabel($eligibilityMode);
        $packet['participant_count'] = count($recipients);
        $packet['claim_rule'] = RedPacketRuleService::claimRule($distributionMode, $eligibilityMode, count($recipients));
        $packet['total_amount'] = RedPacketAmountService::normalizeStored($packet['total_amount']);
        $packet['remain_amount'] = RedPacketAmountService::normalizeStored($packet['remain_amount']);
        $packet['luckiest_claim_id'] = $luckiestClaimId;
        $packet['luckiest_amount'] = $luckiestAmount;
        $packet['luckiest_label'] = $luckiestClaimId > 0 ? '运气王' : '';
        $packet['claims'] = $claims;
        $packet['returns'] = $returns;
        $packet['recipients'] = $recipients;
        $packet['claimed'] = count(array_filter($claims, static fn(array $claim): bool => (int) $claim['user_id'] === (int) $user['id'])) > 0;
        $packet['returned'] = count(array_filter($returns, static fn(array $returned): bool => (int) $returned['user_id'] === (int) $user['id'])) > 0;
        $deliveryScope = (string) ($packet['delivery_scope'] ?? 'private');
        $activeForRecipient = $recipientEligible
            && (int) $packet['status'] === 1 && !$packet['claimed'] && !$packet['returned']
            && (int) $packet['remain_count'] > 0 && strtotime((string) $packet['expired_at']) > time();
        $packet['can_claim'] = $activeForRecipient;
        $packet['can_return'] = $deliveryScope === 'private' && $activeForRecipient && $recipients !== []
            && (int) $packet['user_id'] !== (int) $user['id'];
        $packet['return_policy'] = $deliveryScope === 'private'
            ? '指定接收人可在领取前退回给发送人'
            : '群聊、聊天室、客服和活动红包不能由普通用户退回';
        // 兼容旧版客户端字段名；语义已经改为“接收人退回给发送人”。
        $packet['can_refund'] = $packet['can_return'];
        $packet['commerce_state'] = self::redPacketCommerceState($packet);
        return Response::success(['item' => $packet]);
    }

    public static function refundRedPacket(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'red_packets');
        $packetId = (int) $params['packet_id'];
        $result = Database::transaction(static function () use ($user, $packetId): array {
            $packet = Database::one(
                'SELECT * FROM red_packets WHERE id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [$packetId, (int) $user['admin_id'], (int) $user['app_id']]
            );
            if ($packet === null) {
                throw new HttpException('红包不存在', 404, 404);
            }
            if ((int) $packet['user_id'] === (int) $user['id']) {
                throw new HttpException('发送人不能主动收回红包，未领取部分将在到期后自动退回', 0, 409);
            }
            if ((string) ($packet['delivery_scope'] ?? 'private') !== 'private') {
                throw new HttpException('群聊、聊天室、客服和活动红包不能由普通用户退回', 403, 403);
            }
            if ((int) $packet['status'] !== 1 || strtotime((string) $packet['expired_at']) <= time()
                || (int) $packet['remain_count'] <= 0 || (float) $packet['remain_amount'] <= 0) {
                throw new HttpException('红包已领取、已退回或已过期', 0, 409);
            }
            if (Database::one(
                'SELECT id FROM red_packet_recipients WHERE packet_id = ? AND user_id = ?',
                [$packetId, (int) $user['id']]
            ) === null) {
                throw new HttpException('这个红包没有指定给你', 403, 403);
            }
            if (Database::one('SELECT id FROM red_packet_claims WHERE packet_id = ? AND user_id = ?', [$packetId, (int) $user['id']])) {
                throw new HttpException('你已经领取过该红包，不能再退回', 0, 409);
            }
            if (Database::one('SELECT id FROM red_packet_returns WHERE packet_id = ? AND user_id = ?', [$packetId, (int) $user['id']])) {
                throw new HttpException('你已经把该红包退回给发送人', 0, 409);
            }
            $refund = self::redPacketSettlementAmount($packet);
            Database::execute(
                'INSERT INTO red_packet_returns (admin_id, app_id, packet_id, user_id, amount, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], $packetId, (int) $user['id'], $refund]
            );
            $remainingState = self::redPacketRemainingState($packet, $refund);
            Database::execute(
                'UPDATE red_packets SET status = ?, remain_amount = ?, remain_count = ? WHERE id = ?',
                [$remainingState['status'], $remainingState['remain_amount'], $remainingState['remain_count'], $packetId]
            );
            $sender = Database::one(
                'SELECT * FROM users WHERE id = ? AND admin_id = ? AND app_id = ?',
                [(int) $packet['user_id'], (int) $user['admin_id'], (int) $user['app_id']]
            );
            if ($sender === null) throw new HttpException('红包发送人不存在', -1, 500);
            $asset = WalletService::requireActivityEnabled((int) $user['app_id']);
            WalletService::adjust($sender, $asset, (float) $refund, 'red_packet_recipient_return', 'red_packet', $packetId, '接收人退回红包');
            return ['amount' => $refund, 'sender' => $sender];
        });
        NotificationService::send(
            $result['sender'], 'red_packet_returned', '红包已退回',
            '一位接收人将红包退回给你，退回余额 ' . $result['amount'],
            ['packet_id' => $packetId, 'from_user_id' => (int) $user['id'], 'amount' => $result['amount']]
        );
        return Response::success([
            'packet_id' => $packetId,
            'return_amount' => $result['amount'],
            'refund_amount' => $result['amount'],
            'status' => 'returned',
        ], '红包已退回给发送人');
    }

    public static function createTransfers(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'payments');
        if (!AppService::setting((int) $user['app_id'], 'wallet_transfer_enabled', true)) {
            throw new HttpException('当前应用已关闭资产转账', 403, 403);
        }
        $rawRecipients = $request->input('to_user_ids', []);
        if (!is_array($rawRecipients)) $rawRecipients = [];
        if ($rawRecipients === [] && (int) $request->input('to_user_id', 0) > 0) $rawRecipients[] = (int) $request->input('to_user_id');
        $recipientIds = array_values(array_unique(array_filter(array_map('intval', $rawRecipients), static fn(int $id): bool => $id > 0)));
        if ($recipientIds === [] || count($recipientIds) > 10) throw new HttpException('请选择 1 至 10 位收款人', 0, 422);
        if (in_array((int) $user['id'], $recipientIds, true)) throw new HttpException('不能向自己转账', 0, 422);
        $asset = WalletService::primaryAsset((int) $user['app_id']);
        $amount = round((float) $request->input('amount', 0), $asset === 'balance' ? 2 : 0);
        $max = (float) AppService::setting((int) $user['app_id'], 'wallet_transfer_max', 1000000);
        if ($amount <= 0 || $amount > $max) throw new HttpException('单笔转账金额超出允许范围', 0, 422, ['max' => $max]);
        $placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
        $receivers = Database::all(
            "SELECT * FROM users WHERE id IN ({$placeholders}) AND admin_id = ? AND app_id = ? AND status = 1 AND deleted_at IS NULL",
            array_merge($recipientIds, [(int) $user['admin_id'], (int) $user['app_id']])
        );
        if (count($receivers) !== count($recipientIds)) throw new HttpException('收款人中包含不存在或不可用的用户', 0, 422);
        foreach ($receivers as $receiver) TransferPolicyService::assertAllowed($user, $receiver, $amount);
        $message = mb_substr(trim((string) $request->input('message', '')), 0, 255);
        $expiredAt = date('Y-m-d H:i:s', time() + min(604800, max(60, (int) $request->input('expire_seconds', 86400))));
        $items = Database::transaction(static function () use ($user, $receivers, $asset, $amount, $message, $expiredAt): array {
            WalletService::adjust($user, $asset, -$amount * count($receivers), 'transfer_escrow', 'user_transfer', null, '发起待收转账');
            $created = [];
            foreach ($receivers as $receiver) {
                $id = Database::insert(
                    'INSERT INTO user_transfers
                     (admin_id, app_id, from_user_id, to_user_id, amount, message, status, expired_at, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], (int) $receiver['id'], $amount, $message, 'pending', $expiredAt]
                );
                $created[] = ['id' => $id, 'to_user_id' => (int) $receiver['id'], 'amount' => $amount, 'status' => 'pending'];
            }
            return $created;
        });
        foreach ($receivers as $receiver) {
            NotificationService::send($receiver, 'transfer_pending', '收到一笔待确认转账', '请在转账详情中确认收款', [
                'from_user_id' => (int) $user['id'], 'amount' => $amount, 'asset_type' => 'balance',
            ]);
        }
        return Response::success(['items' => $items, 'transfer_id' => (int) ($items[0]['id'] ?? 0), 'expired_at' => $expiredAt], '转账已发出，等待收款', 201);
    }

    public static function transfers(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'payments');
        self::expireCommerce($user);
        return Response::success(['items' => Database::all(
            "SELECT transfer.*, sender.account AS sender_account, receiver.account AS receiver_account,
                    COALESCE(NULLIF(sender_profile.nickname, ''), sender.account) AS sender_name,
                    COALESCE(NULLIF(receiver_profile.nickname, ''), receiver.account) AS receiver_name
             FROM user_transfers transfer
             INNER JOIN users sender ON sender.id = transfer.from_user_id
             INNER JOIN users receiver ON receiver.id = transfer.to_user_id
             LEFT JOIN user_profiles sender_profile ON sender_profile.user_id = sender.id
             LEFT JOIN user_profiles receiver_profile ON receiver_profile.user_id = receiver.id
             WHERE transfer.app_id = ? AND (transfer.from_user_id = ? OR transfer.to_user_id = ?)
             ORDER BY transfer.id DESC LIMIT 200",
            [(int) $user['app_id'], (int) $user['id'], (int) $user['id']]
        )]);
    }

    public static function transferDetail(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'payments');
        self::expireCommerce($user);
        $item = self::transferForUser($user, (int) $params['transfer_id']);
        $item['can_accept'] = (int) $item['to_user_id'] === (int) $user['id'] && (string) $item['status'] === 'pending';
        $item['can_refund'] = (int) $item['to_user_id'] === (int) $user['id']
            && (string) $item['status'] === 'pending';
        $item['refund_policy'] = '仅收款人可将待收转账退回给原付款人；付款人不能自行收回';
        return Response::success(['item' => $item]);
    }

    public static function acceptTransfer(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'payments');
        $transferId = (int) $params['transfer_id'];
        $amount = Database::transaction(static function () use ($user, $transferId): float {
            $item = Database::one('SELECT * FROM user_transfers WHERE id = ? AND app_id = ? FOR UPDATE', [$transferId, (int) $user['app_id']]);
            if ($item === null || (int) $item['to_user_id'] !== (int) $user['id']) throw new HttpException('转账不存在或你不是收款人', 404, 404);
            if ((string) $item['status'] !== 'pending' || strtotime((string) $item['expired_at']) <= time()) throw new HttpException('转账已处理或已过期', 0, 409);
            Database::execute("UPDATE user_transfers SET status = 'accepted', accepted_at = NOW(), updated_at = NOW() WHERE id = ?", [$transferId]);
            $asset = WalletService::primaryAsset((int) $user['app_id']);
            WalletService::adjust($user, $asset, (float) $item['amount'], 'transfer_accept', 'user_transfer', $transferId, '确认收款');
            return (float) $item['amount'];
        });
        return Response::success(['transfer_id' => $transferId, 'amount' => $amount, 'status' => 'accepted'], '已确认收款');
    }

    public static function refundTransfer(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'payments');
        $transferId = (int) $params['transfer_id'];
        $amount = self::refundTransferRecord($user, $transferId, true);
        return Response::success(['transfer_id' => $transferId, 'amount' => $amount, 'status' => 'refunded'], '转账已退回');
    }

    public static function giftCatalog(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'payments');
        self::ensureGiftCatalog($user);
        return Response::success(['items' => Database::all(
            'SELECT id, gift_code, gift_name, icon_url, price FROM gift_catalog WHERE admin_id = ? AND app_id = ? AND status = 1 ORDER BY sort_order, id',
            [(int) $user['admin_id'], (int) $user['app_id']]
        )]);
    }

    public static function createGifts(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'payments');
        self::ensureGiftCatalog($user);
        $giftId = Validator::integer($request->input('gift_id'), 'gift_id', 1, PHP_INT_MAX);
        $gift = Database::one('SELECT * FROM gift_catalog WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1', [$giftId, (int) $user['admin_id'], (int) $user['app_id']]);
        if ($gift === null) throw new HttpException('礼物不存在或已下架', 404, 404);
        $rawRecipients = $request->input('to_user_ids', []);
        if (!is_array($rawRecipients)) $rawRecipients = [];
        if ($rawRecipients === [] && (int) $request->input('to_user_id', 0) > 0) $rawRecipients[] = (int) $request->input('to_user_id');
        $recipientIds = array_values(array_unique(array_filter(array_map('intval', $rawRecipients), static fn(int $id): bool => $id > 0)));
        if ($recipientIds === [] || count($recipientIds) > 10) throw new HttpException('请选择 1 至 10 位收礼人', 0, 422);
        if (in_array((int) $user['id'], $recipientIds, true)) throw new HttpException('不能给自己赠送礼物', 0, 422);
        $quantity = Validator::integer($request->input('quantity', 1), 'quantity', 1, 999);
        $placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
        $receivers = Database::all("SELECT * FROM users WHERE id IN ({$placeholders}) AND admin_id = ? AND app_id = ? AND status = 1 AND deleted_at IS NULL", array_merge($recipientIds, [(int) $user['admin_id'], (int) $user['app_id']]));
        if (count($receivers) !== count($recipientIds)) throw new HttpException('收礼人中包含不存在或不可用的用户', 0, 422);
        $unitPrice = round((float) $gift['price'], 2);
        $totalEach = round($unitPrice * $quantity, 2);
        $message = mb_substr(trim((string) $request->input('message', '送你一份礼物')), 0, 300);
        $expiredAt = date('Y-m-d H:i:s', time() + min(2592000, max(60, (int) $request->input('expire_seconds', 604800))));
        $items = Database::transaction(static function () use ($user, $receivers, $gift, $quantity, $unitPrice, $totalEach, $message, $expiredAt): array {
            if ($totalEach > 0) {
                $asset = WalletService::requireActivityEnabled((int) $user['app_id']);
                WalletService::adjust($user, $asset, -$totalEach * count($receivers), 'gift_escrow', 'gift', null, '赠送礼物');
            }
            $created = [];
            foreach ($receivers as $receiver) {
                $id = Database::insert(
                    'INSERT INTO user_gift_records
                     (admin_id, app_id, from_user_id, to_user_id, gift_id, gift_code, gift_name, quantity,
                      unit_price, total_amount, message, status, expired_at, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], (int) $receiver['id'], (int) $gift['id'], (string) $gift['gift_code'], (string) $gift['gift_name'], $quantity, $unitPrice, $totalEach, $message, 'pending', $expiredAt]
                );
                $created[] = ['id' => $id, 'to_user_id' => (int) $receiver['id'], 'gift_name' => (string) $gift['gift_name'], 'status' => 'pending'];
            }
            return $created;
        });
        foreach ($receivers as $receiver) NotificationService::send($receiver, 'gift_pending', '收到一份礼物', '请在礼物详情中查收', ['from_user_id' => (int) $user['id'], 'gift_name' => (string) $gift['gift_name'], 'quantity' => $quantity]);
        return Response::success(['items' => $items, 'gift_record_id' => (int) ($items[0]['id'] ?? 0), 'expired_at' => $expiredAt], '礼物已送出，等待查收', 201);
    }

    public static function gifts(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'payments');
        self::expireCommerce($user);
        return Response::success(['items' => Database::all(
            "SELECT gift.*, sender.account AS sender_account, receiver.account AS receiver_account,
                    COALESCE(NULLIF(sender_profile.nickname, ''), sender.account) AS sender_name,
                    COALESCE(NULLIF(receiver_profile.nickname, ''), receiver.account) AS receiver_name
             FROM user_gift_records gift INNER JOIN users sender ON sender.id = gift.from_user_id
             INNER JOIN users receiver ON receiver.id = gift.to_user_id
             LEFT JOIN user_profiles sender_profile ON sender_profile.user_id = sender.id
             LEFT JOIN user_profiles receiver_profile ON receiver_profile.user_id = receiver.id
             WHERE gift.app_id = ? AND (gift.from_user_id = ? OR gift.to_user_id = ?)
             ORDER BY gift.id DESC LIMIT 200",
            [(int) $user['app_id'], (int) $user['id'], (int) $user['id']]
        )]);
    }

    public static function giftDetail(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'payments');
        self::expireCommerce($user);
        $item = self::giftForUser($user, (int) $params['gift_id']);
        $item['can_accept'] = (int) $item['to_user_id'] === (int) $user['id'] && (string) $item['status'] === 'pending';
        $item['can_refund'] = (int) $item['to_user_id'] === (int) $user['id'] && (string) $item['status'] === 'pending';
        $item['refund_policy'] = '仅收礼人可将待查收礼物退回给原赠送人；赠送人不能自行收回';
        return Response::success(['item' => $item]);
    }

    public static function acceptGift(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'payments');
        $giftId = (int) $params['gift_id'];
        $affected = Database::execute(
            "UPDATE user_gift_records SET status = 'accepted', accepted_at = NOW()
             WHERE id = ? AND app_id = ? AND to_user_id = ? AND status = 'pending' AND expired_at > NOW()",
            [$giftId, (int) $user['app_id'], (int) $user['id']]
        );
        if ($affected === 0) throw new HttpException('礼物不存在、已处理或已过期', 0, 409);
        return Response::success(['gift_id' => $giftId, 'status' => 'accepted'], '礼物已收下');
    }

    public static function refundGift(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'payments');
        $giftId = (int) $params['gift_id'];
        $amount = self::refundGiftRecord($user, $giftId, true);
        return Response::success(['gift_id' => $giftId, 'refund_amount' => $amount, 'status' => 'refunded'], '礼物已退回');
    }

    public static function lotteryPrizes(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'lottery');
        return Response::success(['items' => Database::all(
            'SELECT id, name, prize_type, weight, stock, daily_limit FROM lottery_prizes
             WHERE app_id = ? AND status = 1 AND stock > 0 ORDER BY id',
            [(int) $user['app_id']]
        )]);
    }

    public static function drawLottery(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'lottery');
        $dailyLimit = max(0, (int) AppService::setting((int) $user['app_id'], 'lottery_daily_limit', 3));
        $today = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM lottery_draws WHERE app_id = ? AND user_id = ? AND created_at >= CURDATE()',
            [(int) $user['app_id'], (int) $user['id']]
        )['total'] ?? 0);
        if ($dailyLimit > 0 && $today >= $dailyLimit) {
            throw new HttpException('今日抽奖次数已用完', 0, 429);
        }
        $result = Database::transaction(static function () use ($user): array {
            $prizes = Database::all(
                'SELECT * FROM lottery_prizes WHERE admin_id = ? AND app_id = ? AND status = 1 AND stock > 0 FOR UPDATE',
                [(int) $user['admin_id'], (int) $user['app_id']]
            );
            if ($prizes === []) {
                throw new HttpException('当前没有可抽取的奖品', 0, 409);
            }
            $totalWeight = array_sum(array_map(static fn (array $p): int => max(1, (int) $p['weight']), $prizes));
            $hit = random_int(1, $totalWeight);
            $prize = $prizes[0];
            foreach ($prizes as $candidate) {
                $hit -= max(1, (int) $candidate['weight']);
                if ($hit <= 0) {
                    $prize = $candidate;
                    break;
                }
            }
            if ((int) $prize['daily_limit'] > 0) {
                $prizeToday = (int) (Database::one(
                    'SELECT COUNT(*) AS total FROM lottery_draws WHERE prize_id = ? AND user_id = ? AND created_at >= CURDATE()',
                    [(int) $prize['id'], (int) $user['id']]
                )['total'] ?? 0);
                if ($prizeToday >= (int) $prize['daily_limit']) {
                    throw new HttpException('该奖品今日中奖次数已达上限，请稍后再试', 0, 429);
                }
            }
            $rewards = json_decode((string) $prize['value_json'], true);
            $rewards = is_array($rewards) ? $rewards : [];
            $drawId = Database::insert(
                'INSERT INTO lottery_draws
                 (admin_id, app_id, user_id, prize_id, reward_json, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], (int) $prize['id'],
                    json_encode($rewards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
            Database::execute('UPDATE lottery_prizes SET stock = stock - 1 WHERE id = ?', [(int) $prize['id']]);
            if (isset($rewards['integral']) && !isset($rewards['balance'])) {
                $rewards[WalletService::primaryAsset((int) $user['app_id'])] = $rewards['integral'];
                unset($rewards['integral']);
            }
            $wallet = WalletService::applyRewards($user, $rewards, 'lottery_draw', 'lottery_draw', $drawId);
            $publicRewards = $rewards;
            if (isset($publicRewards[WalletService::primaryAsset((int) $user['app_id'])])) {
                $publicRewards['balance'] = $publicRewards[WalletService::primaryAsset((int) $user['app_id'])];
            }
            unset($publicRewards['integral']);
            return [
                'draw_id' => $drawId,
                'prize_id' => (int) $prize['id'],
                'name' => $prize['name'],
                'rewards' => $publicRewards,
                'wallet' => WalletService::publicWallet($wallet, (int) $user['app_id']),
            ];
        });
        NotificationService::send(
            $user, 'lottery_result', '抽奖结果', '本次抽奖获得：' . (string) $result['name'],
            ['draw_id' => (int) $result['draw_id'], 'prize_id' => (int) $result['prize_id'], 'rewards' => $result['rewards']]
        );
        return Response::success($result, '抽奖成功');
    }

    public static function votes(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'votes');
        $items = Database::all(
            'SELECT * FROM votes WHERE app_id = ? AND status = 1
             AND (start_at IS NULL OR start_at <= NOW()) AND (end_at IS NULL OR end_at >= NOW())
             ORDER BY id DESC',
            [(int) $user['app_id']]
        );
        foreach ($items as &$item) {
            $item['options'] = Database::all('SELECT id, option_text, vote_count FROM vote_options WHERE vote_id = ? ORDER BY sort_order, id', [(int) $item['id']]);
            $item['selected_option_ids'] = array_map('intval', array_column(Database::all(
                'SELECT option_id FROM vote_records WHERE vote_id = ? AND user_id = ?',
                [(int) $item['id'], (int) $user['id']]
            ), 'option_id'));
        }
        unset($item);
        return Response::success(['items' => $items]);
    }

    public static function submitVote(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'votes');
        $voteId = (int) $params['vote_id'];
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('option_ids', [])))));
        if ($ids === []) {
            throw new HttpException('至少选择一个投票选项', 0, 422);
        }
        Database::transaction(static function () use ($user, $voteId, $ids): void {
            $vote = Database::one(
                'SELECT * FROM votes WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1
                 AND (start_at IS NULL OR start_at <= NOW()) AND (end_at IS NULL OR end_at >= NOW()) FOR UPDATE',
                [$voteId, (int) $user['admin_id'], (int) $user['app_id']]
            );
            if ($vote === null) {
                throw new HttpException('投票不存在或不在开放时间', 404, 404);
            }
            if (Database::one('SELECT id FROM vote_records WHERE vote_id = ? AND user_id = ? LIMIT 1', [$voteId, (int) $user['id']])) {
                throw new HttpException('你已经参与过该投票', 0, 409);
            }
            $max = (int) $vote['multi_select'] === 1 ? (int) $vote['max_select'] : 1;
            if (count($ids) > $max) {
                throw new HttpException('选择数量超过投票限制', 0, 422, ['max_select' => $max]);
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $options = Database::all(
                "SELECT id FROM vote_options WHERE vote_id = ? AND id IN ({$placeholders}) FOR UPDATE",
                array_merge([$voteId], $ids)
            );
            if (count($options) !== count($ids)) {
                throw new HttpException('包含无效投票选项', 0, 422);
            }
            foreach ($ids as $optionId) {
                Database::execute(
                    'INSERT INTO vote_records
                     (admin_id, app_id, vote_id, option_id, user_id, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW())',
                    [(int) $user['admin_id'], (int) $user['app_id'], $voteId, $optionId, (int) $user['id']]
                );
                Database::execute('UPDATE vote_options SET vote_count = vote_count + 1 WHERE id = ?', [$optionId]);
            }
        });
        return Response::success(['vote_id' => $voteId, 'option_ids' => $ids], '投票提交成功');
    }

    public static function transfer(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        if (!AppService::setting((int) $user['app_id'], 'wallet_transfer_enabled', true)) {
            throw new HttpException('当前应用已关闭资产转账', 403, 403);
        }
        $asset = WalletService::primaryAsset((int) $user['app_id']);
        $toUserId = Validator::integer($request->input('to_user_id'), 'to_user_id', 1, PHP_INT_MAX);
        if ($toUserId === (int) $user['id']) {
            throw new HttpException('不能向自己转账', 0, 422);
        }
        $amount = round((float) $request->input('amount', 0), $asset === 'balance' ? 2 : 0);
        $max = (float) AppService::setting((int) $user['app_id'], 'wallet_transfer_max', 1000000);
        if ($amount <= 0 || $amount > $max) {
            throw new HttpException('转账金额超出允许范围', 0, 422, ['max' => $max]);
        }
        $receiver = Database::one(
            'SELECT * FROM users WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1 AND deleted_at IS NULL',
            [$toUserId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        if ($receiver === null) {
            throw new HttpException('收款用户不存在或不可用', 404, 404);
        }
        TransferPolicyService::assertAllowed($user, $receiver, $amount);
        Database::transaction(static function () use ($user, $receiver, $asset, $amount): void {
            WalletService::adjust($user, $asset, -$amount, 'wallet_transfer_out', 'user', (int) $receiver['id'], '用户转账');
            WalletService::adjust($receiver, $asset, $amount, 'wallet_transfer_in', 'user', (int) $user['id'], '用户收款');
        });
        NotificationService::send(
            $receiver, 'wallet_transfer_received', '收到一笔转账', '你收到余额 ' . $amount,
            ['from_user_id' => (int) $user['id'], 'amount' => $amount, 'asset_type' => 'balance']
        );
        return Response::success(['to_user_id' => $toUserId, 'asset_type' => 'balance', 'amount' => $amount], '转账成功');
    }

    private static function transferForUser(array $user, int $transferId): array
    {
        $item = Database::one(
            "SELECT transfer.*, sender.account AS sender_account, receiver.account AS receiver_account,
                    COALESCE(NULLIF(sender_profile.nickname, ''), sender.account) AS sender_name,
                    COALESCE(NULLIF(receiver_profile.nickname, ''), receiver.account) AS receiver_name,
                    sender_profile.avatar AS sender_avatar, receiver_profile.avatar AS receiver_avatar
             FROM user_transfers transfer
             INNER JOIN users sender ON sender.id = transfer.from_user_id
             INNER JOIN users receiver ON receiver.id = transfer.to_user_id
             LEFT JOIN user_profiles sender_profile ON sender_profile.user_id = sender.id
             LEFT JOIN user_profiles receiver_profile ON receiver_profile.user_id = receiver.id
             WHERE transfer.id = ? AND transfer.admin_id = ? AND transfer.app_id = ?
               AND (transfer.from_user_id = ? OR transfer.to_user_id = ?)",
            [$transferId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], (int) $user['id']]
        );
        if ($item === null) throw new HttpException('转账不存在或你无权查看', 404, 404);
        return $item;
    }

    private static function giftForUser(array $user, int $giftId): array
    {
        $item = Database::one(
            "SELECT gift.*, sender.account AS sender_account, receiver.account AS receiver_account,
                    COALESCE(NULLIF(sender_profile.nickname, ''), sender.account) AS sender_name,
                    COALESCE(NULLIF(receiver_profile.nickname, ''), receiver.account) AS receiver_name,
                    sender_profile.avatar AS sender_avatar, receiver_profile.avatar AS receiver_avatar,
                    catalog.icon_url
             FROM user_gift_records gift
             INNER JOIN users sender ON sender.id = gift.from_user_id
             INNER JOIN users receiver ON receiver.id = gift.to_user_id
             LEFT JOIN user_profiles sender_profile ON sender_profile.user_id = sender.id
             LEFT JOIN user_profiles receiver_profile ON receiver_profile.user_id = receiver.id
             LEFT JOIN gift_catalog catalog ON catalog.id = gift.gift_id
             WHERE gift.id = ? AND gift.admin_id = ? AND gift.app_id = ?
               AND (gift.from_user_id = ? OR gift.to_user_id = ?)",
            [$giftId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], (int) $user['id']]
        );
        if ($item === null) throw new HttpException('礼物不存在或你无权查看', 404, 404);
        return $item;
    }

    private static function refundTransferRecord(array $actor, int $transferId, bool $enforceParticipant): float
    {
        return Database::transaction(static function () use ($actor, $transferId, $enforceParticipant): float {
            $item = Database::one(
                'SELECT * FROM user_transfers WHERE id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [$transferId, (int) $actor['admin_id'], (int) $actor['app_id']]
            );
            if ($item === null) throw new HttpException('转账不存在', 404, 404);
            if ($enforceParticipant && (int) $actor['id'] !== (int) $item['to_user_id']) {
                if ((int) $actor['id'] === (int) $item['from_user_id']) {
                    throw new HttpException('付款人不能自行收回转账，只能由收款人退回；超时未收款会自动原路退回', 403, 403);
                }
                throw new HttpException('只有收款人可以退回该转账', 403, 403);
            }
            if ((string) $item['status'] !== 'pending') throw new HttpException('转账已处理，不能重复退回', 0, 409);
            $sender = Database::one('SELECT * FROM users WHERE id = ? AND admin_id = ? AND app_id = ?', [(int) $item['from_user_id'], (int) $actor['admin_id'], (int) $actor['app_id']]);
            if ($sender === null) throw new HttpException('转账发起人不存在', -1, 500);
            Database::execute("UPDATE user_transfers SET status = 'refunded', refunded_at = NOW(), updated_at = NOW() WHERE id = ?", [$transferId]);
            $asset = WalletService::primaryAsset((int) $actor['app_id']);
            WalletService::adjust($sender, $asset, (float) $item['amount'], 'transfer_refund', 'user_transfer', $transferId, '退回未领取转账');
            return (float) $item['amount'];
        });
    }

    private static function refundGiftRecord(array $actor, int $giftId, bool $enforceParticipant): float
    {
        return Database::transaction(static function () use ($actor, $giftId, $enforceParticipant): float {
            $item = Database::one(
                'SELECT * FROM user_gift_records WHERE id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [$giftId, (int) $actor['admin_id'], (int) $actor['app_id']]
            );
            if ($item === null) throw new HttpException('礼物不存在', 404, 404);
            if ($enforceParticipant && (int) $actor['id'] !== (int) $item['to_user_id']) {
                if ((int) $actor['id'] === (int) $item['from_user_id']) {
                    throw new HttpException('赠送人不能自行收回礼物，只能由收礼人退回；超时未查收会自动原路退回', 403, 403);
                }
                throw new HttpException('只有收礼人可以退回该礼物', 403, 403);
            }
            if ((string) $item['status'] !== 'pending') throw new HttpException('礼物已处理，不能重复退回', 0, 409);
            $sender = Database::one('SELECT * FROM users WHERE id = ? AND admin_id = ? AND app_id = ?', [(int) $item['from_user_id'], (int) $actor['admin_id'], (int) $actor['app_id']]);
            if ($sender === null) throw new HttpException('礼物赠送人不存在', -1, 500);
            Database::execute("UPDATE user_gift_records SET status = 'refunded', refunded_at = NOW() WHERE id = ?", [$giftId]);
            $amount = (float) $item['total_amount'];
            if ($amount > 0) {
                $asset = WalletService::primaryAsset((int) $actor['app_id']);
                WalletService::adjust($sender, $asset, $amount, 'gift_refund', 'gift', $giftId, '退回未查收礼物');
            }
            return $amount;
        });
    }

    private static function redPacketEligibleUsers(
        array $sender,
        string $deliveryScope,
        int $contextId,
        int $contextUserId,
        string $eligibilityMode,
        array $selectedIds,
        bool $includeSender
    ): array {
        $senderId = (int) $sender['id'];
        $eligibleIds = [];

        if (in_array($deliveryScope, ['group', 'chat_room'], true)) {
            if ($contextId <= 0) {
                throw new HttpException('请选择红包所在的群聊或聊天室', 0, 422);
            }
            $room = Database::one(
                'SELECT id FROM chat_rooms WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1 AND dissolved_at IS NULL',
                [$contextId, (int) $sender['admin_id'], (int) $sender['app_id']]
            );
            if ($room === null) throw new HttpException('群聊或聊天室不存在', 404, 404);
            if (Database::one(
                'SELECT id FROM chat_room_members WHERE room_id = ? AND user_id = ?',
                [$contextId, $senderId]
            ) === null) {
                throw new HttpException('你不是当前群聊或聊天室成员', 403, 403);
            }
            $memberIds = array_map('intval', array_column(Database::all(
                'SELECT user_id FROM chat_room_members WHERE room_id = ? ORDER BY id',
                [$contextId]
            ), 'user_id'));
            if ($eligibilityMode === RedPacketRuleService::ELIGIBILITY_CONTEXT_ALL) {
                $eligibleIds = $memberIds;
            } else {
                $outsideIds = array_diff($selectedIds, $memberIds);
                if ($outsideIds !== []) {
                    throw new HttpException('指定领取人中包含非群成员', 0, 422);
                }
                $eligibleIds = $selectedIds;
            }
        } elseif ($deliveryScope === 'private') {
            if ($eligibilityMode === RedPacketRuleService::ELIGIBILITY_CONTEXT_ALL && $contextId > 0) {
                $conversation = Database::one(
                    'SELECT user_a_id, user_b_id FROM conversations
                     WHERE id = ? AND admin_id = ? AND app_id = ? AND (user_a_id = ? OR user_b_id = ?)',
                    [$contextId, (int) $sender['admin_id'], (int) $sender['app_id'], $senderId, $senderId]
                );
                if ($conversation === null) throw new HttpException('私聊会话不存在或你无权访问', 404, 404);
                $eligibleIds = [(int) $conversation['user_a_id'], (int) $conversation['user_b_id']];
            } elseif ($eligibilityMode === RedPacketRuleService::ELIGIBILITY_CONTEXT_ALL && $contextUserId > 0) {
                $eligibleIds = [$senderId, $contextUserId];
            } else {
                $eligibleIds = $selectedIds;
            }
        } elseif ($deliveryScope === 'activity' && $eligibilityMode === RedPacketRuleService::ELIGIBILITY_CONTEXT_ALL) {
            $eligibleIds = array_map('intval', array_column(Database::all(
                'SELECT id FROM users WHERE admin_id = ? AND app_id = ? AND status = 1 AND deleted_at IS NULL ORDER BY id',
                [(int) $sender['admin_id'], (int) $sender['app_id']]
            ), 'id'));
        } elseif ($eligibilityMode === RedPacketRuleService::ELIGIBILITY_CONTEXT_ALL) {
            if ($contextUserId > 0) {
                $eligibleIds = [$senderId, $contextUserId];
            } elseif ($selectedIds !== []) {
                $eligibleIds = $selectedIds;
            } else {
                throw new HttpException('当前投放场景无法自动确定参与人，请选择指定人员', 0, 422);
            }
        } else {
            $eligibleIds = $selectedIds;
        }

        if ($includeSender) {
            $eligibleIds[] = $senderId;
        } else {
            $eligibleIds = array_values(array_filter(
                $eligibleIds,
                static fn(int $id): bool => $id !== $senderId
            ));
        }
        $eligibleIds = array_values(array_unique(array_filter(
            array_map('intval', $eligibleIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($eligibleIds === []) throw new HttpException('红包领取范围内至少需要一人', 0, 422);

        $placeholders = implode(',', array_fill(0, count($eligibleIds), '?'));
        $rows = Database::all(
            "SELECT * FROM users WHERE id IN ({$placeholders}) AND admin_id = ? AND app_id = ? AND status = 1 AND deleted_at IS NULL",
            array_merge($eligibleIds, [(int) $sender['admin_id'], (int) $sender['app_id']])
        );
        if (count($rows) !== count($eligibleIds)) {
            throw new HttpException('领取范围中包含不存在或不可用的用户', 0, 422);
        }
        $byId = [];
        foreach ($rows as $row) $byId[(int) $row['id']] = $row;
        $receivers = [];
        foreach ($eligibleIds as $eligibleId) $receivers[] = $byId[$eligibleId];
        return $receivers;
    }

    private static function booleanValue(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') return $default;
        if (is_bool($value)) return $value;
        if (is_int($value) || is_float($value)) return (int) $value === 1;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function redPacketCommerceState(array $packet): string
    {
        if ((bool) ($packet['returned'] ?? false)) return 'returned';
        if ((bool) ($packet['claimed'] ?? false)) return 'claimed';

        $status = (int) ($packet['status'] ?? 1);
        if ($status === 0) return 'completed';
        if ($status === 2) {
            $expiredAt = strtotime((string) ($packet['expired_at'] ?? ''));
            return $expiredAt !== false && $expiredAt <= time()
                ? 'expired'
                : 'refunded';
        }

        return 'pending';
    }

    private static function redPacketSettlementAmount(array $packet): string
    {
        try {
            $distributionMode = RedPacketRuleService::distributionMode($packet['distribution_mode'] ?? 'count_split');
            if ($distributionMode === RedPacketRuleService::DISTRIBUTION_RANDOM_GRAB) {
                return RedPacketAmountService::randomGrab($packet['remain_amount'], (int) $packet['remain_count']);
            }
            return RedPacketAmountService::allocate(
                $packet['remain_amount'],
                (int) $packet['remain_count'],
                (string) ($packet['packet_type'] ?? 'random')
            );
        } catch (\InvalidArgumentException $exception) {
            throw new HttpException($exception->getMessage(), 0, 409);
        }
    }

    /** @return array{status:int,remain_amount:string,remain_count:int} */
    private static function redPacketRemainingState(array $packet, string $settledAmount): array
    {
        $remainingCents = RedPacketAmountService::parseStoredCents($packet['remain_amount']);
        $settledCents = RedPacketAmountService::parseCents($settledAmount);
        $afterCents = max(0, $remainingCents - $settledCents);
        $afterCount = max(0, (int) $packet['remain_count'] - 1);

        return [
            'status' => ($afterCents === 0 || $afterCount === 0) ? 0 : 1,
            'remain_amount' => RedPacketAmountService::formatCents($afterCents),
            'remain_count' => $afterCount,
        ];
    }

    private static function expireCommerce(array $user): void
    {
        $transfers = Database::all(
            "SELECT id FROM user_transfers WHERE admin_id = ? AND app_id = ? AND status = 'pending' AND expired_at <= NOW() ORDER BY id LIMIT 100",
            [(int) $user['admin_id'], (int) $user['app_id']]
        );
        foreach ($transfers as $item) {
            try { self::refundTransferRecord($user, (int) $item['id'], false); } catch (HttpException $ignored) { }
        }
        $gifts = Database::all(
            "SELECT id FROM user_gift_records WHERE admin_id = ? AND app_id = ? AND status = 'pending' AND expired_at IS NOT NULL AND expired_at <= NOW() ORDER BY id LIMIT 100",
            [(int) $user['admin_id'], (int) $user['app_id']]
        );
        foreach ($gifts as $item) {
            try { self::refundGiftRecord($user, (int) $item['id'], false); } catch (HttpException $ignored) { }
        }
        $packets = Database::all(
            'SELECT id FROM red_packets WHERE admin_id = ? AND app_id = ? AND status = 1 AND expired_at <= NOW() ORDER BY id LIMIT 100',
            [(int) $user['admin_id'], (int) $user['app_id']]
        );
        foreach ($packets as $packetRow) {
            $packetId = (int) $packetRow['id'];
            try {
                Database::transaction(static function () use ($user, $packetId): void {
                    $packet = Database::one(
                        'SELECT * FROM red_packets WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1 FOR UPDATE',
                        [$packetId, (int) $user['admin_id'], (int) $user['app_id']]
                    );
                    if ($packet === null) return;

                    $refund = RedPacketAmountService::normalizeStored($packet['remain_amount']);
                    $refundCents = RedPacketAmountService::parseStoredCents($refund);
                    $sender = null;
                    if ($refundCents > 0) {
                        $sender = Database::one('SELECT * FROM users WHERE id = ? AND admin_id = ? AND app_id = ?', [(int) $packet['user_id'], (int) $user['admin_id'], (int) $user['app_id']]);
                        if ($sender === null) {
                            throw new HttpException('红包发放人不存在，暂无法结算', 0, 409);
                        }
                    }

                    Database::execute('UPDATE red_packets SET status = 2, remain_amount = 0, remain_count = 0 WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1', [$packetId, (int) $user['admin_id'], (int) $user['app_id']]);
                    if ($refundCents > 0 && $sender !== null) {
                        WalletService::adjust($sender, WalletService::primaryAsset((int) $user['app_id']), (float) $refund, 'red_packet_expired_refund', 'red_packet', $packetId, '红包到期自动退回');
                    }
                });
            } catch (HttpException $ignored) { }
        }
    }

    private static function ensureGiftCatalog(array $user): void
    {
        $defaults = [
            ['flower', '鲜花', 1.00, 10],
            ['cake', '蛋糕', 5.00, 20],
            ['applause', '掌声', 2.00, 30],
            ['blessing', '祝福', 3.00, 40],
        ];
        foreach ($defaults as [$code, $name, $price, $sort]) {
            Database::execute(
                'INSERT INTO gift_catalog (admin_id, app_id, gift_code, gift_name, price, status, sort_order, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE gift_name = VALUES(gift_name)',
                [(int) $user['admin_id'], (int) $user['app_id'], $code, $name, $price, $sort]
            );
        }
    }

    private static function orderPrice(array $user, string $type, int $targetId, int $quantity, array $data): array
    {
        if ($type === 'shop_goods') {
            $goods = Database::one(
                'SELECT * FROM shop_goods WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1',
                [$targetId, (int) $user['admin_id'], (int) $user['app_id']]
            );
            if ($goods === null || (int) $goods['stock'] < $quantity) {
                throw new HttpException('商品不存在、已下架或库存不足', 0, 409);
            }
            return [(string) $goods['name'], round((float) $goods['price_money'] * $quantity, 2)];
        }
        $amount = round((float) ($data['amount'] ?? 0), 2);
        $titles = [
            'balance_recharge' => '余额充值',
            'document_credit' => '文档额度购买',
            'vip' => '会员购买',
        ];
        return [$titles[$type], $amount];
    }

    private static function user(Request $request, string $feature): array
    {
        $user = AuthService::user($request);
        AppService::requireFeature((int) $user['app_id'], $feature);
        return $user;
    }
}
