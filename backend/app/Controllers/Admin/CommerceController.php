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
use Yiyunying\Services\MessageMediaService;
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

    public static function shopGoodsComments(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $adminId = (int) $admin['id'];
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['comment.admin_id = ?', 'comment.app_id = ?'];
        $query = [$adminId, $appId];

        $statuses = self::shopGoodsCommentStatusFilter($request);
        if ($statuses !== null) {
            $where[] = 'comment.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($query, ...$statuses);
        }
        foreach (['goods_id', 'user_id', 'score'] as $field) {
            if ($request->input($field) !== null && $request->input($field) !== '') {
                $where[] = "comment.{$field} = ?";
                $query[] = (int) $request->input($field);
            }
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(comment.content LIKE ? OR goods.name LIKE ? OR author.account LIKE ? OR author.uid LIKE ? OR profile.nickname LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($query, $like, $like, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total
             FROM shop_goods_comments comment
             INNER JOIN shop_goods goods
                     ON goods.id = comment.goods_id
                    AND goods.admin_id = comment.admin_id AND goods.app_id = comment.app_id
             INNER JOIN users author
                     ON author.id = comment.user_id
                    AND author.admin_id = comment.admin_id AND author.app_id = comment.app_id
             LEFT JOIN user_profiles profile
                    ON profile.user_id = author.id
                   AND profile.admin_id = comment.admin_id AND profile.app_id = comment.app_id
             WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            self::shopGoodsCommentSelect() . " WHERE {$whereSql}
             ORDER BY comment.created_at DESC, comment.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = MessageMediaService::hydrate($items, 'shop_goods_comment', $appId);
        $items = array_map(
            static fn(array $item): array => self::decorateShopGoodsComment($item),
            $items
        );
        $data = Pagination::data($items, $total, $page, $limit);
        $data['status_summary'] = self::shopGoodsCommentStatusSummary($adminId, $appId);
        return Response::success($data);
    }

    public static function showShopGoodsComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $adminId = (int) $admin['id'];
        $commentId = (int) $params['comment_id'];
        $comment = self::shopGoodsCommentRow($adminId, $appId, $commentId);
        if ($comment === null) throw new HttpException('商品评论不存在', 404, 404);
        $replies = Database::all(
            self::shopGoodsCommentSelect() .
            ' WHERE comment.parent_id = ? AND comment.admin_id = ? AND comment.app_id = ?
              ORDER BY comment.created_at ASC, comment.id ASC LIMIT 100',
            [$commentId, $adminId, $appId]
        );
        $comment = MessageMediaService::hydrate([$comment], 'shop_goods_comment', $appId)[0];
        $replies = MessageMediaService::hydrate($replies, 'shop_goods_comment', $appId);
        return Response::success([
            'comment' => self::decorateShopGoodsComment($comment),
            'replies' => array_map(
                static fn(array $item): array => self::decorateShopGoodsComment($item),
                $replies
            ),
            'replies_truncated' => (int) ($comment['reply_count'] ?? 0) > count($replies),
        ]);
    }

    public static function hideShopGoodsComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::transitionShopGoodsComment($request, $params, 2, 'hide', '商品评论及其回复已隐藏');
    }

    public static function restoreShopGoodsComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::transitionShopGoodsComment($request, $params, 1, 'restore', '商品评论已恢复，其回复仍保持原状态');
    }

    public static function deleteShopGoodsComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::transitionShopGoodsComment($request, $params, -1, 'delete', '商品评论及其回复已删除');
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

    private static function shopGoodsCommentSelect(): string
    {
        return 'SELECT comment.*, goods.name AS goods_name, goods.goods_type, goods.status AS goods_status,
                       author.uid, author.account,
                       COALESCE(NULLIF(profile.nickname, \'\'), author.account) AS nickname,
                       profile.avatar AS avatar_url,
                       parent.content AS parent_content, parent.status AS parent_status,
                       parent_author.account AS parent_account,
                       COALESCE(NULLIF(parent_profile.nickname, \'\'), parent_author.account) AS parent_nickname,
                       (SELECT COUNT(*) FROM shop_goods_comments child
                        WHERE child.parent_id = comment.id
                          AND child.goods_id = comment.goods_id
                          AND child.admin_id = comment.admin_id AND child.app_id = comment.app_id) AS reply_count,
                       (SELECT COUNT(*) FROM shop_goods_comments active_child
                        WHERE active_child.parent_id = comment.id
                          AND active_child.goods_id = comment.goods_id
                          AND active_child.admin_id = comment.admin_id AND active_child.app_id = comment.app_id
                          AND active_child.status IN (1, 2)) AS active_reply_count,
                       (SELECT COUNT(*) FROM shop_comment_reactions likes
                        WHERE likes.comment_id = comment.id AND likes.reaction_type = \'like\') AS likes_count
                FROM shop_goods_comments comment
                INNER JOIN shop_goods goods
                        ON goods.id = comment.goods_id
                       AND goods.admin_id = comment.admin_id AND goods.app_id = comment.app_id
                INNER JOIN users author
                        ON author.id = comment.user_id
                       AND author.admin_id = comment.admin_id AND author.app_id = comment.app_id
                LEFT JOIN user_profiles profile
                       ON profile.user_id = author.id
                      AND profile.admin_id = comment.admin_id AND profile.app_id = comment.app_id
                LEFT JOIN shop_goods_comments parent
                       ON parent.id = comment.parent_id AND parent.goods_id = comment.goods_id
                      AND parent.admin_id = comment.admin_id AND parent.app_id = comment.app_id
                LEFT JOIN users parent_author
                       ON parent_author.id = parent.user_id
                      AND parent_author.admin_id = comment.admin_id AND parent_author.app_id = comment.app_id
                LEFT JOIN user_profiles parent_profile
                       ON parent_profile.user_id = parent_author.id
                      AND parent_profile.admin_id = comment.admin_id AND parent_profile.app_id = comment.app_id';
    }

    private static function shopGoodsCommentRow(int $adminId, int $appId, int $commentId): ?array
    {
        return Database::one(
            self::shopGoodsCommentSelect()
            . ' WHERE comment.id = ? AND comment.admin_id = ? AND comment.app_id = ?',
            [$commentId, $adminId, $appId]
        );
    }

    private static function shopGoodsCommentStatusFilter(Request $request): ?array
    {
        $raw = $request->input('status');
        if ($raw === null || trim((string) $raw) === '') return null;
        $key = strtolower(trim((string) $raw));
        $statuses = [
            '1' => [1], 'visible' => [1],
            '2' => [2], 'hidden' => [2],
            '0' => [0], 'legacy_deleted' => [0],
            '-1' => [-1], 'soft_deleted' => [-1],
            'deleted' => [0, -1],
        ];
        if (!array_key_exists($key, $statuses)) {
            throw new HttpException(
                '评论状态仅支持 visible、hidden、deleted、legacy_deleted、soft_deleted 或 1、2、0、-1',
                0,
                422
            );
        }
        return $statuses[$key];
    }

    private static function shopGoodsCommentStatusSummary(int $adminId, int $appId): array
    {
        $summary = ['visible' => 0, 'hidden' => 0, 'deleted' => 0, 'legacy_deleted' => 0];
        foreach (Database::all(
            'SELECT status, COUNT(*) AS total FROM shop_goods_comments
             WHERE admin_id = ? AND app_id = ? GROUP BY status',
            [$adminId, $appId]
        ) as $row) {
            $status = (int) $row['status'];
            $total = (int) $row['total'];
            if ($status === 1) $summary['visible'] += $total;
            elseif ($status === 2) $summary['hidden'] += $total;
            elseif ($status === 0) {
                $summary['deleted'] += $total;
                $summary['legacy_deleted'] += $total;
            } elseif ($status === -1) $summary['deleted'] += $total;
        }
        $summary['total'] = $summary['visible'] + $summary['hidden'] + $summary['deleted'];
        return $summary;
    }

    private static function decorateShopGoodsComment(array $comment): array
    {
        $status = (int) ($comment['status'] ?? 0);
        $state = [
            1 => ['visible', '正常显示'],
            2 => ['hidden', '已隐藏'],
            0 => ['legacy_deleted', '历史已删除'],
            -1 => ['deleted', '已删除'],
        ][$status] ?? ['unknown', '未知状态'];
        $score = max(0, min(5, (int) ($comment['score'] ?? 0)));
        $comment['visibility_status'] = $state[0];
        $comment['status_label'] = $state[1];
        $comment['score'] = $score;
        $comment['score_label'] = $score > 0 ? $score . ' 星' : '未评分';
        $comment['can_hide'] = $status === 1;
        $comment['can_restore'] = $status === 2;
        $comment['can_delete'] = in_array($status, [1, 2], true);
        $comment['content_excerpt'] = mb_substr((string) ($comment['content'] ?? ''), 0, 180);
        return $comment;
    }

    private static function transitionShopGoodsComment(
        Request $request,
        array $params,
        int $targetStatus,
        string $action,
        string $successMessage
    ): \Yiyunying\Core\ApiResponse {
        [$admin, $appId] = self::context($request, $params);
        $adminId = (int) $admin['id'];
        $commentId = (int) $params['comment_id'];
        $reason = trim(mb_substr((string) $request->input('reason', ''), 0, 500));
        $result = Database::transaction(static function () use (
            $request, $adminId, $appId, $commentId, $targetStatus, $action, $reason
        ): array {
            $before = Database::one(
                'SELECT comment.* FROM shop_goods_comments comment
                 INNER JOIN shop_goods goods
                         ON goods.id = comment.goods_id
                        AND goods.admin_id = comment.admin_id AND goods.app_id = comment.app_id
                 WHERE comment.id = ? AND comment.admin_id = ? AND comment.app_id = ? FOR UPDATE',
                [$commentId, $adminId, $appId]
            );
            if ($before === null) throw new HttpException('商品评论不存在', 404, 404);
            $currentStatus = (int) $before['status'];
            $terminal = in_array($currentStatus, [0, -1], true);
            if ($terminal && $targetStatus !== -1) {
                throw new HttpException('用户已删除或已软删除的商品评论不能隐藏或恢复', 0, 409);
            }
            if ($targetStatus === 1 && $currentStatus === 1) {
                return ['changed' => false, 'affected_count' => 0];
            }
            if ($targetStatus === 1 && $currentStatus !== 2) {
                throw new HttpException('只有管理员隐藏的商品评论可以恢复', 0, 409);
            }
            if ($targetStatus === 2 && !in_array($currentStatus, [1, 2], true)) {
                throw new HttpException('当前商品评论不能隐藏', 0, 409);
            }

            $goodsId = (int) $before['goods_id'];
            $rows = Database::all(
                'SELECT id, parent_id, status FROM shop_goods_comments
                 WHERE goods_id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [$goodsId, $adminId, $appId]
            );
            if ($targetStatus === 1) {
                self::assertRestorableShopGoodsCommentParentChain($rows, $before);
            }
            $subtreeIds = $targetStatus === 1
                ? [$commentId]
                : self::shopGoodsCommentSubtreeIds($rows, $commentId);
            $subtreeLookup = array_fill_keys($subtreeIds, true);
            $changedIds = [];
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if (!isset($subtreeLookup[$id])) continue;
                $status = (int) $row['status'];
                $eligible = $targetStatus === 1 ? $status === 2
                    : ($targetStatus === 2 ? $status === 1 : in_array($status, [1, 2], true));
                if ($eligible) $changedIds[] = $id;
            }
            foreach (array_chunk($changedIds, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                Database::execute(
                    "UPDATE shop_goods_comments SET status = ?, updated_at = NOW()
                     WHERE id IN ({$placeholders}) AND goods_id = ? AND admin_id = ? AND app_id = ?",
                    array_merge([$targetStatus], $chunk, [$goodsId, $adminId, $appId])
                );
            }
            if ($changedIds === []) {
                return ['changed' => false, 'affected_count' => 0];
            }
            $after = Database::one(
                'SELECT * FROM shop_goods_comments
                 WHERE id = ? AND goods_id = ? AND admin_id = ? AND app_id = ?',
                [$commentId, $goodsId, $adminId, $appId]
            ) ?? $before;
            $auditIds = array_slice($changedIds, 0, 100);
            $beforeLog = $before;
            $beforeLog['affected_comment_ids'] = $auditIds;
            $beforeLog['affected_count'] = count($changedIds);
            $afterLog = $after;
            $afterLog['affected_comment_ids'] = $auditIds;
            $afterLog['affected_count'] = count($changedIds);
            $afterLog['affected_ids_truncated'] = count($changedIds) > count($auditIds);
            $afterLog['reason'] = $reason;
            LogService::adminOperation(
                $request, $adminId, $appId, 'shop_goods_comment_moderation',
                $action, $commentId, $beforeLog, $afterLog
            );
            return ['changed' => true, 'affected_count' => count($changedIds)];
        });

        $comment = self::shopGoodsCommentRow($adminId, $appId, $commentId);
        if ($comment !== null) {
            $comment = MessageMediaService::hydrate([$comment], 'shop_goods_comment', $appId)[0];
            $comment = self::decorateShopGoodsComment($comment);
        }
        return Response::success([
            'comment' => $comment,
            'changed' => (bool) $result['changed'],
            'affected_count' => (int) $result['affected_count'],
        ], $result['changed'] ? $successMessage : '商品评论状态未变化，无需重复处理');
    }

    private static function shopGoodsCommentSubtreeIds(array $rows, int $rootId): array
    {
        $children = [];
        foreach ($rows as $row) {
            $parentId = (int) ($row['parent_id'] ?? 0);
            $children[$parentId][] = (int) $row['id'];
        }
        $result = [];
        $visited = [];
        $queue = [$rootId];
        $cursor = 0;
        while ($cursor < count($queue)) {
            $id = $queue[$cursor++];
            if (isset($visited[$id])) continue;
            $visited[$id] = true;
            $result[] = $id;
            foreach ($children[$id] ?? [] as $childId) $queue[] = $childId;
        }
        return $result;
    }

    private static function assertRestorableShopGoodsCommentParentChain(array $rows, array $comment): void
    {
        $byId = [];
        foreach ($rows as $row) $byId[(int) $row['id']] = $row;
        $parentId = (int) ($comment['parent_id'] ?? 0);
        $visited = [];
        while ($parentId > 0) {
            if (isset($visited[$parentId])) {
                throw new HttpException('商品评论回复关系异常，暂时不能恢复', 0, 409);
            }
            $visited[$parentId] = true;
            $parent = $byId[$parentId] ?? null;
            if ($parent === null) {
                throw new HttpException('上级商品评论不存在，暂时不能恢复该回复', 0, 409);
            }
            $status = (int) $parent['status'];
            if (in_array($status, [0, -1], true)) {
                throw new HttpException('上级商品评论已删除，不能恢复该回复', 0, 409);
            }
            if ($status !== 1) {
                throw new HttpException('请先恢复上级商品评论，再恢复该回复', 0, 409);
            }
            $parentId = (int) ($parent['parent_id'] ?? 0);
        }
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
