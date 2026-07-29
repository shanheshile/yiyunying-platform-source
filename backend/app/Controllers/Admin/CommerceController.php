<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Validator;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\RedPacketManagementService;

final class CommerceController
{
    public static function orders(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        return self::paged($request, 'orders', 'o', [
            'o.admin_id = ?' => (int) $admin['id'],
            'o.app_id = ?' => $appId,
        ], ['order_type', 'status'],
            'SELECT o.*, u.account, p.nickname FROM orders o INNER JOIN users u ON u.id = o.user_id LEFT JOIN user_profiles p ON p.user_id = u.id',
            'o.id DESC');
    }

    public static function payments(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        return self::paged($request, 'payments', 'p', [
            'p.admin_id = ?' => (int) $admin['id'],
            'p.app_id = ?' => $appId,
        ], ['channel_code', 'status'],
            'SELECT p.*, o.order_no, o.order_type, o.user_id FROM payments p INNER JOIN orders o ON o.id = p.order_id',
            'p.id DESC');
    }

    public static function paymentChannels(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $items = Database::all(
            'SELECT id, channel_code, name, config_json, enabled, created_at, updated_at
             FROM payment_channels WHERE admin_id = ? AND app_id = ? ORDER BY id',
            [(int) $admin['id'], $appId]
        );
        foreach ($items as &$item) {
            $config = json_decode((string) ($item['config_json'] ?? '{}'), true);
            $item['config_json'] = is_array($config) ? self::maskSecrets($config) : [];
        }
        unset($item);
        return Response::success(['items' => $items]);
    }

    public static function savePaymentChannel(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $data = $request->all();
        Validator::required($data, ['channel_code', 'enabled']);
        $code = Validator::string($data['channel_code'], 'channel_code', 2, 40);
        if (preg_match('/^[a-z][a-z0-9_-]+$/', $code) !== 1) {
            throw new HttpException('channel_code 格式不正确', 0, 422);
        }
        $config = $data['config_json'] ?? [];
        if (is_string($config)) {
            $config = json_decode($config, true);
        }
        if (!is_array($config)) {
            throw new HttpException('config_json 必须是 JSON 对象', 0, 422);
        }
        $existing = Database::one(
            'SELECT config_json FROM payment_channels WHERE admin_id = ? AND app_id = ? AND channel_code = ?',
            [(int) $admin['id'], $appId, $code]
        );
        $old = $existing === null ? [] : json_decode((string) $existing['config_json'], true);
        if (is_array($old)) {
            foreach ($old as $key => $value) {
                if (isset($config[$key]) && $config[$key] === '********') {
                    $config[$key] = $value;
                }
            }
        }
        if (!isset($config['secret']) || trim((string) $config['secret']) === '') {
            $config['secret'] = bin2hex(random_bytes(24));
        }
        Database::execute(
            'INSERT INTO payment_channels
             (admin_id, app_id, channel_code, name, config_json, enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), config_json = VALUES(config_json),
                 enabled = VALUES(enabled), updated_at = NOW()',
            [
                (int) $admin['id'], $appId, $code,
                mb_substr((string) ($data['name'] ?? $code), 0, 100),
                json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                Validator::boolean($data['enabled'], 'enabled') ? 1 : 0,
            ]
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'payment', 'channel_save', null, null, ['channel_code' => $code]);
        return Response::success(['channel_code' => $code, 'config' => self::maskSecrets($config)], '支付渠道已保存');
    }

    public static function goods(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        return self::paged($request, 'shop_goods', 'g', [
            'g.admin_id = ?' => (int) $admin['id'],
            'g.app_id = ?' => $appId,
        ], ['status'], 'SELECT g.* FROM shop_goods g', 'g.id DESC');
    }

    public static function createGoods(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $data = $request->all();
        Validator::required($data, ['name', 'stock']);
        $id = Database::insert(
            'INSERT INTO shop_goods
             (admin_id, app_id, name, cover_url, description, price_integral, price_money,
              stock, sales_count, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())',
            [
                (int) $admin['id'], $appId, Validator::string($data['name'], 'name', 1, 200),
                mb_substr((string) ($data['cover_url'] ?? ''), 0, 500), (string) ($data['description'] ?? ''),
                max(0, (int) ($data['price_balance'] ?? 0)),
                max(0, round((float) ($data['price_money'] ?? 0), 2)),
                Validator::integer($data['stock'], 'stock', 0, 100000000),
                Validator::boolean($data['status'] ?? true, 'status') ? 1 : 0,
            ]
        );
        return Response::success(['goods_id' => $id], '商品创建成功', 201);
    }

    public static function updateGoods(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $goods = self::owned('shop_goods', (int) $params['goods_id'], (int) $admin['id'], $appId, '商品');
        Database::execute(
            'UPDATE shop_goods SET name = ?, cover_url = ?, description = ?, price_integral = ?,
             price_money = ?, stock = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [
                mb_substr((string) $request->input('name', $goods['name']), 0, 200),
                mb_substr((string) $request->input('cover_url', $goods['cover_url']), 0, 500),
                (string) $request->input('description', $goods['description']),
                max(0, (int) $request->input('price_balance', $goods['price_integral'])),
                max(0, round((float) $request->input('price_money', $goods['price_money']), 2)),
                Validator::integer($request->input('stock', $goods['stock']), 'stock', 0, 100000000),
                Validator::boolean($request->input('status', (bool) $goods['status']), 'status') ? 1 : 0,
                (int) $goods['id'],
            ]
        );
        return Response::success(['goods_id' => (int) $goods['id']], '商品已更新');
    }

    public static function deleteGoods(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $goods = self::owned('shop_goods', (int) $params['goods_id'], (int) $admin['id'], $appId, '商品');
        Database::execute('UPDATE shop_goods SET status = 0, updated_at = NOW() WHERE id = ?', [(int) $goods['id']]);
        return Response::success(['goods_id' => (int) $goods['id']], '商品已下架');
    }

    public static function lotteryPrizes(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $items = Database::all('SELECT * FROM lottery_prizes WHERE admin_id = ? AND app_id = ? ORDER BY id DESC', [
            (int) $admin['id'], $appId,
        ]);
        foreach ($items as &$item) {
            $item['value_json'] = json_decode((string) $item['value_json'], true) ?: [];
        }
        unset($item);
        return Response::success(['items' => $items]);
    }

    public static function saveLotteryPrize(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $data = $request->all();
        Validator::required($data, ['name', 'prize_type', 'value_json', 'weight', 'stock']);
        $rewards = is_string($data['value_json']) ? json_decode($data['value_json'], true) : $data['value_json'];
        if (!is_array($rewards)) {
            throw new HttpException('value_json 必须是 JSON 对象', 0, 422);
        }
        $id = (int) ($data['id'] ?? 0);
        $isUpdate = $id > 0;
        if ($id > 0) {
            self::owned('lottery_prizes', $id, (int) $admin['id'], $appId, '奖品');
            Database::execute(
                'UPDATE lottery_prizes SET name = ?, prize_type = ?, value_json = ?, weight = ?, stock = ?,
                 daily_limit = ?, status = ?, updated_at = NOW() WHERE id = ?',
                self::prizeValues($request, $rewards, $id)
            );
        } else {
            $id = Database::insert(
                'INSERT INTO lottery_prizes
                 (admin_id, app_id, name, prize_type, value_json, weight, stock, daily_limit, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                array_merge([(int) $admin['id'], $appId], self::prizeValues($request, $rewards))
            );
        }
        return Response::success(['prize_id' => $id], '抽奖奖品已保存', $isUpdate ? 200 : 201);
    }

    public static function deleteLotteryPrize(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $prize = self::owned('lottery_prizes', (int) $params['prize_id'], (int) $admin['id'], $appId, '奖品');
        Database::execute('UPDATE lottery_prizes SET status = 0 WHERE id = ?', [(int) $prize['id']]);
        return Response::success(['prize_id' => (int) $prize['id']], '奖品已停用');
    }

    public static function votes(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $items = Database::all('SELECT * FROM votes WHERE admin_id = ? AND app_id = ? ORDER BY id DESC', [
            (int) $admin['id'], $appId,
        ]);
        foreach ($items as &$item) {
            $item['options'] = Database::all('SELECT * FROM vote_options WHERE vote_id = ? ORDER BY sort_order, id', [(int) $item['id']]);
        }
        unset($item);
        return Response::success(['items' => $items]);
    }

    public static function createVote(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $data = $request->all();
        Validator::required($data, ['title', 'options']);
        $options = array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), (array) $data['options'])));
        if (count($options) < 2 || count($options) > 50) {
            throw new HttpException('投票选项数量必须为 2-50 个', 0, 422);
        }
        $multi = Validator::boolean(
            $data['multiple_choice'] ?? $data['multi_select'] ?? $data['allow_multiple'] ?? false,
            'multiple_choice'
        );
        $maxSelect = $multi ? min(count($options), max(2, (int) ($data['max_select'] ?? count($options)))) : 1;
        $voteId = Database::transaction(static function () use ($admin, $appId, $data, $options, $multi, $maxSelect): int {
            $id = Database::insert(
                'INSERT INTO votes
                 (admin_id, app_id, title, description, multi_select, max_select, status, start_at, end_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    (int) $admin['id'], $appId, mb_substr((string) $data['title'], 0, 200),
                    (string) ($data['description'] ?? ''), $multi ? 1 : 0, $maxSelect,
                    Validator::boolean($data['status'] ?? true, 'status') ? 1 : 0,
                    Validator::nullableDateTime($data['start_at'] ?? null, 'start_at'),
                    Validator::nullableDateTime($data['end_at'] ?? null, 'end_at'),
                ]
            );
            foreach ($options as $index => $text) {
                Database::execute(
                    'INSERT INTO vote_options
                     (admin_id, app_id, vote_id, option_text, vote_count, sort_order, created_at)
                     VALUES (?, ?, ?, ?, 0, ?, NOW())',
                    [(int) $admin['id'], $appId, $id, mb_substr($text, 0, 500), $index]
                );
            }
            return $id;
        });
        return Response::success(['vote_id' => $voteId], '投票创建成功', 201);
    }

    public static function closeVote(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $vote = self::owned('votes', (int) $params['vote_id'], (int) $admin['id'], $appId, '投票');
        Database::execute('UPDATE votes SET status = 0, updated_at = NOW() WHERE id = ?', [(int) $vote['id']]);
        return Response::success(['vote_id' => (int) $vote['id']], '投票已关闭');
    }

    public static function redPackets(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        return self::paged($request, 'red_packets', 'r', [
            'r.admin_id = ?' => (int) $admin['id'],
            'r.app_id = ?' => $appId,
        ], ['status'], 'SELECT r.*, u.account, p.nickname FROM red_packets r INNER JOIN users u ON u.id = r.user_id LEFT JOIN user_profiles p ON p.user_id = u.id', 'r.id DESC');
    }

    public static function forceRefundRedPacket(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $packetId = (int) $params['packet_id'];
        $result = RedPacketManagementService::forceRefund((int) $admin['id'], $appId, $packetId);
        LogService::adminOperation(
            $request,
            (int) $admin['id'],
            $appId,
            'red_packet',
            'force_refund',
            $packetId,
            $result['packet'],
            ['refund_amount' => $result['refund_amount'], 'asset_type' => $result['asset_type']]
        );
        return Response::success([
            'packet_id' => $packetId,
            'refund_amount' => $result['refund_amount'],
            'status' => 'refunded',
        ], '红包已强制结束，剩余金额已退回发送者');
    }

    private static function paged(
        Request $request,
        string $table,
        string $alias,
        array $fixed,
        array $filters,
        string $select,
        string $order
    ): \Yiyunying\Core\ApiResponse {
        $where = [];
        $query = [];
        foreach ($fixed as $expression => $value) {
            $where[] = $expression;
            $query[] = $value;
        }
        foreach ($filters as $filter) {
            if ($request->input($filter) !== null && $request->input($filter) !== '') {
                $where[] = $alias . '.' . $filter . ' = ?';
                $query[] = $request->input($filter);
            }
        }
        $whereSql = implode(' AND ', $where);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM {$table} {$alias} WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all("{$select} WHERE {$whereSql} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}", $query);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    private static function prizeValues(Request $request, array $rewards, ?int $id = null): array
    {
        $values = [
            mb_substr((string) $request->input('name'), 0, 150),
            mb_substr((string) $request->input('prize_type'), 0, 40),
            json_encode($rewards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            Validator::integer($request->input('weight'), 'weight', 1, 1000000),
            Validator::integer($request->input('stock'), 'stock', 0, 100000000),
            Validator::integer($request->input('daily_limit', 0), 'daily_limit', 0, 1000000),
            Validator::boolean($request->input('status', true), 'status') ? 1 : 0,
        ];
        if ($id !== null) {
            $values[] = $id;
        }
        return $values;
    }

    private static function owned(string $table, int $id, int $adminId, int $appId, string $label): array
    {
        $row = Database::one("SELECT * FROM {$table} WHERE id = ? AND admin_id = ? AND app_id = ?", [$id, $adminId, $appId]);
        if ($row === null) {
            throw new HttpException($label . '不存在', 404, 404);
        }
        return $row;
    }

    private static function maskSecrets(array $config): array
    {
        foreach ($config as $key => &$value) {
            if (preg_match('/secret|key|password|token/i', (string) $key) === 1 && (string) $value !== '') {
                $value = '********';
            }
        }
        unset($value);
        return $config;
    }

    private static function context(Request $request, array $params): array
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        return [$admin, $appId];
    }
}
