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
use Yiyunying\Services\MessageMediaService;
use Yiyunying\Services\NotificationService;
use Yiyunying\Services\OrderTrackingService;
use Yiyunying\Services\PaymentService;
use Yiyunying\Services\WalletService;

final class ShopController
{
    public static function categories(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $items = Database::all(
            'SELECT c.id, c.parent_id, c.name, c.icon_url, c.description, c.sort_order,
                    (SELECT COUNT(*) FROM shop_goods g
                     WHERE g.category_id = c.id AND g.admin_id = c.admin_id
                       AND g.app_id = c.app_id AND g.status = 1) AS goods_count
             FROM shop_categories c
             WHERE c.admin_id = ? AND c.app_id = ? AND c.status = 1
             ORDER BY c.parent_id, c.sort_order, c.id',
            [(int) $user['admin_id'], (int) $user['app_id']]
        );
        return Response::success([
            'items' => $items,
            'tree' => self::categoryTree($items),
        ]);
    }

    public static function goods(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['g.admin_id = ?', 'g.app_id = ?', 'g.status = 1'];
        $query = [(int) $user['admin_id'], (int) $user['app_id']];
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(g.name LIKE ? OR g.description LIKE ? OR g.tags_json LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($query, $like, $like, $like);
        }
        $categoryId = max(0, (int) $request->input('category_id', 0));
        if ($categoryId > 0) {
            $where[] = 'g.category_id = ?';
            $query[] = $categoryId;
        }
        $goodsType = trim((string) $request->input('goods_type', ''));
        if ($goodsType !== '') {
            $where[] = 'g.goods_type = ?';
            $query[] = $goodsType;
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM shop_goods g WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            self::goodsSelect() . " WHERE {$whereSql}
             ORDER BY g.sales_count DESC, g.id DESC LIMIT {$limit} OFFSET {$offset}",
            array_merge([(int) $user['id'], (int) $user['id']], $query)
        );
        return Response::success(Pagination::data(
            self::normalizeGoodsList($items, (int) $user['app_id']),
            $total,
            $page,
            $limit
        ));
    }

    public static function showGoods(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $goods = self::findGoods($user, (int) $params['goods_id'], true);
        $items = self::normalizeGoodsList([$goods], (int) $user['app_id']);
        $goods = $items[0];
        $goods['recent_comments'] = self::commentRows($user, (int) $goods['id'], 0, 5);
        return Response::success(['item' => $goods]);
    }

    public static function comments(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $goods = self::findGoods($user, (int) $params['goods_id'], true);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM shop_goods_comments
             WHERE admin_id = ? AND app_id = ? AND goods_id = ? AND status = 1 AND parent_id = 0',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $goods['id']]
        )['total'] ?? 0);
        $items = self::commentRows($user, (int) $goods['id'], $offset, $limit);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function createComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $goods = self::findGoods($user, (int) $params['goods_id'], true);
        $data = $request->all();
        $payload = MessageMediaService::userPayload($user, $data);
        $parentId = max(0, (int) ($data['parent_id'] ?? 0));
        $score = max(0, min(5, (int) ($data['score'] ?? 0)));
        $parent = null;
        if ($parentId > 0) {
            $parent = Database::one(
                'SELECT * FROM shop_goods_comments
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND goods_id = ? AND status = 1',
                [$parentId, (int) $user['admin_id'], (int) $user['app_id'], (int) $goods['id']]
            );
            if ($parent === null) throw new HttpException('要回复的评论不存在', 404, 404);
            $parentId = (int) $parent['parent_id'] > 0 ? (int) $parent['parent_id'] : (int) $parent['id'];
            $score = 0;
        }
        $commentId = Database::transaction(static function () use ($user, $goods, $parentId, $score, $payload): int {
            $id = Database::insert(
                'INSERT INTO shop_goods_comments
                 (admin_id, app_id, goods_id, user_id, parent_id, content, score, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $goods['id'],
                    (int) $user['id'], $parentId, $payload['content'], $score,
                ]
            );
            MessageMediaService::save('shop_goods_comment', $id, $payload);
            return $id;
        });
        if ($parent !== null && (int) $parent['user_id'] !== (int) $user['id']) {
            $target = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], (int) $parent['user_id']);
            if ($target !== null) {
                NotificationService::send($target, 'shop_comment_reply', '商品评论有新回复',
                    '你在《' . (string) $goods['name'] . '》下的评论收到了回复', [
                        'goods_id' => (int) $goods['id'], 'comment_id' => $commentId, 'reply_user_id' => (int) $user['id'],
                    ]);
            }
        }
        LogService::userOperation($request, $user, 'shop_comment', 'create', $commentId, ['goods_id' => (int) $goods['id']]);
        return Response::success(['item' => self::findComment($user, $commentId)], '评论发布成功', 201);
    }

    public static function updateComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $comment = self::findComment($user, (int) $params['comment_id'], false);
        if ((int) $comment['user_id'] !== (int) $user['id']) throw new HttpException('只能修改自己的评论', 403, 403);
        $payload = MessageMediaService::userPayload($user, $request->all());
        Database::transaction(static function () use ($comment, $payload): void {
            Database::execute(
                'UPDATE shop_goods_comments SET content = ?, updated_at = NOW() WHERE id = ?',
                [$payload['content'], (int) $comment['id']]
            );
            MessageMediaService::replace('shop_goods_comment', (int) $comment['id'], $payload);
        });
        LogService::userOperation($request, $user, 'shop_comment', 'update', (int) $comment['id']);
        return Response::success(['item' => self::findComment($user, (int) $comment['id'])], '评论修改成功');
    }

    public static function deleteComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $comment = self::findComment($user, (int) $params['comment_id'], false);
        if ((int) $comment['user_id'] !== (int) $user['id']) throw new HttpException('只能删除自己的评论', 403, 403);
        Database::execute(
            'UPDATE shop_goods_comments SET status = -1, updated_at = NOW()
             WHERE admin_id = ? AND app_id = ? AND (id = ? OR parent_id = ?)',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $comment['id'], (int) $comment['id']]
        );
        LogService::userOperation($request, $user, 'shop_comment', 'delete', (int) $comment['id']);
        return Response::success(['comment_id' => (int) $comment['id']], '评论已删除');
    }

    public static function commentReaction(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $comment = self::findComment($user, (int) $params['comment_id'], false);
        $enabled = self::requestedEnabled($request);
        $existing = Database::one(
            "SELECT id FROM shop_comment_reactions
             WHERE admin_id = ? AND app_id = ? AND comment_id = ? AND user_id = ? AND reaction_type = 'like'",
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $comment['id'], (int) $user['id']]
        );
        $active = $enabled ?? ($existing === null);
        if ($active && $existing === null) {
            Database::insert(
                "INSERT INTO shop_comment_reactions
                 (admin_id, app_id, comment_id, user_id, reaction_type, created_at)
                 VALUES (?, ?, ?, ?, 'like', NOW())",
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $comment['id'], (int) $user['id']]
            );
        } elseif (!$active && $existing !== null) {
            Database::execute('DELETE FROM shop_comment_reactions WHERE id = ?', [(int) $existing['id']]);
        }
        $count = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM shop_comment_reactions WHERE comment_id = ? AND reaction_type = 'like'",
            [(int) $comment['id']]
        )['total'] ?? 0);
        return Response::success(['comment_id' => (int) $comment['id'], 'liked' => $active, 'likes_count' => $count],
            $active ? '已点赞' : '已取消点赞');
    }

    public static function reaction(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $goods = self::findGoods($user, (int) $params['goods_id'], true);
        $type = trim((string) $request->input('reaction_type', 'like'));
        if (!in_array($type, ['like', 'favorite'], true)) throw new HttpException('互动类型只支持点赞或收藏', 0, 422);
        $existing = Database::one(
            'SELECT id FROM shop_goods_reactions
             WHERE admin_id = ? AND app_id = ? AND goods_id = ? AND user_id = ? AND reaction_type = ?',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $goods['id'], (int) $user['id'], $type]
        );
        $enabled = self::requestedEnabled($request);
        $active = $enabled ?? ($existing === null);
        if ($active && $existing === null) {
            Database::insert(
                'INSERT INTO shop_goods_reactions
                 (admin_id, app_id, goods_id, user_id, reaction_type, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $goods['id'], (int) $user['id'], $type]
            );
        } elseif (!$active && $existing !== null) {
            Database::execute('DELETE FROM shop_goods_reactions WHERE id = ?', [(int) $existing['id']]);
        }
        $count = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM shop_goods_reactions WHERE goods_id = ? AND reaction_type = ?',
            [(int) $goods['id'], $type]
        )['total'] ?? 0);
        LogService::userOperation($request, $user, 'shop_goods', $active ? $type : 'cancel_' . $type, (int) $goods['id']);
        return Response::success([
            'goods_id' => (int) $goods['id'], 'reaction_type' => $type, 'active' => $active, 'count' => $count,
        ], $type === 'favorite' ? ($active ? '已收藏' : '已取消收藏') : ($active ? '已点赞' : '已取消点赞'));
    }

    public static function favorites(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $query = [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']];
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM shop_goods_reactions r
             INNER JOIN shop_goods g ON g.id = r.goods_id
             WHERE r.admin_id = ? AND r.app_id = ? AND r.user_id = ?
               AND r.reaction_type = 'favorite' AND g.status = 1",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            self::goodsSelect() . " INNER JOIN shop_goods_reactions favorite_record
                ON favorite_record.goods_id = g.id AND favorite_record.user_id = ?
               AND favorite_record.reaction_type = 'favorite'
             WHERE g.admin_id = ? AND g.app_id = ? AND g.status = 1
             ORDER BY favorite_record.id DESC LIMIT {$limit} OFFSET {$offset}",
            [(int) $user['id'], (int) $user['id'], (int) $user['id'], (int) $user['admin_id'], (int) $user['app_id']]
        );
        return Response::success(Pagination::data(
            self::normalizeGoodsList($items, (int) $user['app_id']), $total, $page, $limit
        ));
    }

    public static function forward(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::share($request, $params, false);
    }

    public static function recommend(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::share($request, $params, true);
    }

    public static function buy(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $goods = self::findGoods($user, (int) $params['goods_id'], true);
        $quantity = Validator::integer($request->input('quantity', 1), 'quantity', 1, 10000);
        $buyerInfo = self::buyerInfo($request, (int) $goods['delivery_required'] === 1);
        if ((int) $goods['price_integral'] <= 0 && (float) $goods['price_money'] > 0) {
            return self::createPaymentOrder($request, $user, $goods, $quantity, $buyerInfo);
        }
        $result = Database::transaction(static function () use ($user, $goods, $quantity, $buyerInfo): array {
            $locked = Database::one(
                'SELECT * FROM shop_goods
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1 FOR UPDATE',
                [(int) $goods['id'], (int) $user['admin_id'], (int) $user['app_id']]
            );
            if ($locked === null || (int) $locked['stock'] < $quantity) {
                throw new HttpException('商品不存在、已下架或库存不足', 0, 409);
            }
            $cost = (int) $locked['price_integral'] * $quantity;
            if ($cost > 0) {
                $asset = WalletService::requireActivityEnabled((int) $user['app_id']);
                WalletService::adjust($user, $asset, -$cost, 'shop_buy', 'shop_goods', (int) $locked['id'], '余额购买商品');
            }
            $completed = (int) $locked['delivery_required'] !== 1;
            $status = $completed ? 'completed' : 'paid';
            $orderNo = PaymentService::orderNo();
            $orderId = Database::insert(
                'INSERT INTO shop_orders
                 (admin_id, app_id, user_id, goods_id, goods_name, goods_cover_url, goods_type,
                  order_no, quantity, unit_price_integral, unit_price_money, amount_integral, amount_money,
                  buyer_info_json, status, paid_at, fulfilled_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW(), ?, NOW(), NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], (int) $locked['id'],
                    (string) $locked['name'], (string) $locked['cover_url'], (string) $locked['goods_type'],
                    $orderNo, $quantity, (int) $locked['price_integral'], (float) $locked['price_money'], $cost,
                    self::json($buyerInfo), $status, $completed ? date('Y-m-d H:i:s') : null,
                ]
            );
            Database::execute(
                'UPDATE shop_goods SET stock = stock - ?, sales_count = sales_count + ?, updated_at = NOW() WHERE id = ?',
                [$quantity, $quantity, (int) $locked['id']]
            );
            $order = [
                'id' => $orderId, 'admin_id' => (int) $user['admin_id'], 'app_id' => (int) $user['app_id'],
                'user_id' => (int) $user['id'], 'order_no' => $orderNo,
            ];
            OrderTrackingService::record($order, 'shop', 'created', '订单已创建', '商品库存已锁定', 'user', (int) $user['id']);
            OrderTrackingService::record($order, 'shop', 'paid', '余额支付成功', '已完成余额扣款', 'user', (int) $user['id'], ['amount_balance' => $cost]);
            if ($completed) {
                OrderTrackingService::record($order, 'shop', 'completed', '商品已交付', '虚拟商品已自动完成交付', 'system');
            }
            return [
                'payment_required' => false, 'order_source' => 'shop', 'order_id' => $orderId,
                'order_no' => $orderNo, 'status' => $status, 'status_text' => self::statusText($status),
                'amount_balance' => $cost, 'cost_balance' => $cost,
            ];
        });
        LogService::userOperation($request, $user, 'shop_order', 'buy', (int) $result['order_id'], ['goods_id' => (int) $goods['id']]);
        NotificationService::send($user, 'shop_purchase', '商品购买成功',
            '《' . (string) $goods['name'] . '》已生成订单，可在“我的订单”查看进度', $result);
        return Response::success($result, '商品购买成功', 201);
    }

    public static function orders(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, false);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $status = trim((string) $request->input('status', ''));
        $type = trim((string) $request->input('order_type', ''));
        $base = self::unifiedOrderSql();
        $where = ['unified.app_id = ?', 'unified.user_id = ?'];
        $query = [(int) $user['app_id'], (int) $user['id']];
        if ($status !== '') {
            $where[] = 'unified.status = ?';
            $query[] = $status;
        }
        if ($type !== '') {
            $where[] = 'unified.order_type = ?';
            $query[] = $type;
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM ({$base}) unified WHERE {$whereSql}",
            array_merge([(int) $user['app_id']], $query)
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT unified.* FROM ({$base}) unified WHERE {$whereSql}
             ORDER BY unified.created_at DESC, unified.id DESC LIMIT {$limit} OFFSET {$offset}",
            array_merge([(int) $user['app_id']], $query)
        );
        foreach ($items as &$item) self::normalizeOrder($item);
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function order(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, false);
        $source = self::orderSource((string) $params['order_source']);
        $order = self::findOrder($user, $source, (int) $params['order_id']);
        self::normalizeOrder($order);
        $order['events'] = OrderTrackingService::events((int) $user['app_id'], (string) $order['order_no']);
        if ($order['events'] === []) {
            $order['events'][] = [
                'event_code' => 'created', 'title' => '订单已创建', 'detail' => '',
                'actor_type' => 'system', 'actor_id' => 0, 'metadata' => [], 'created_at' => $order['created_at'],
            ];
        }
        return Response::success(['item' => $order]);
    }

    public static function cancelOrder(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, false);
        $source = self::orderSource((string) $params['order_source']);
        $orderId = (int) $params['order_id'];
        $result = Database::transaction(static function () use ($user, $source, $orderId): array {
            if ($source === 'payment') {
                $order = Database::one(
                    'SELECT * FROM orders WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ? FOR UPDATE',
                    [$orderId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
                );
                if ($order === null) throw new HttpException('订单不存在', 404, 404);
                if ((string) $order['status'] !== 'pending') throw new HttpException('只有待支付订单可以取消', 0, 409);
                Database::execute("UPDATE orders SET status = 'closed', closed_at = NOW(), updated_at = NOW() WHERE id = ?", [$orderId]);
                OrderTrackingService::record($order, 'payment', 'closed', '订单已取消', '用户取消了待支付订单', 'user', (int) $user['id']);
                return ['order_source' => 'payment', 'order_id' => $orderId, 'order_no' => (string) $order['order_no'], 'status' => 'closed'];
            }
            $order = Database::one(
                'SELECT * FROM shop_orders WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ? FOR UPDATE',
                [$orderId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
            );
            if ($order === null) throw new HttpException('订单不存在', 404, 404);
            if ((string) $order['status'] !== 'paid') {
                throw new HttpException('只有尚未开始处理的已支付订单可以直接取消', 0, 409);
            }
            $amount = (int) $order['amount_integral'];
            if ($amount > 0) {
                $asset = WalletService::requireActivityEnabled((int) $user['app_id']);
                WalletService::adjust($user, $asset, $amount, 'shop_order_refund', 'shop_order', $orderId, '取消商品订单退款');
            }
            Database::execute(
                "UPDATE shop_orders SET status = 'refunded', closed_at = NOW(), updated_at = NOW() WHERE id = ?",
                [$orderId]
            );
            Database::execute(
                'UPDATE shop_goods SET stock = stock + ?, sales_count = GREATEST(0, sales_count - ?), updated_at = NOW() WHERE id = ?',
                [(int) $order['quantity'], (int) $order['quantity'], (int) $order['goods_id']]
            );
            OrderTrackingService::record($order, 'shop', 'refunded', '订单已取消并退款',
                '余额已原路退回，商品库存已恢复', 'user', (int) $user['id'], ['amount_balance' => $amount]);
            return ['order_source' => 'shop', 'order_id' => $orderId, 'order_no' => (string) $order['order_no'], 'status' => 'refunded'];
        });
        $result['status_text'] = self::statusText((string) $result['status']);
        LogService::userOperation($request, $user, 'shop_order', 'cancel', $orderId, ['order_source' => $source]);
        return Response::success($result, '订单处理成功');
    }

    private static function user(Request $request, bool $requireShop = true): array
    {
        $user = AuthService::user($request, $requireShop ? 'shop' : null);
        AuthService::ensureNotBanned($user, ['all', 'shop']);
        return $user;
    }

    private static function findGoods(array $user, int $goodsId, bool $activeOnly): array
    {
        $sql = self::goodsSelect() . ' WHERE g.id = ? AND g.admin_id = ? AND g.app_id = ?';
        if ($activeOnly) $sql .= ' AND g.status = 1';
        $goods = Database::one($sql, [(int) $user['id'], (int) $user['id'], $goodsId, (int) $user['admin_id'], (int) $user['app_id']]);
        if ($goods === null) throw new HttpException('商品不存在或已下架', 404, 404);
        return $goods;
    }

    private static function goodsSelect(): string
    {
        return "SELECT g.*, c.name AS category_name, c.icon_url AS category_icon_url,
                       (SELECT COUNT(*) FROM shop_goods_comments comment_count
                        WHERE comment_count.goods_id = g.id AND comment_count.status = 1) AS comments_count,
                       (SELECT ROUND(AVG(score), 1) FROM shop_goods_comments score_average
                        WHERE score_average.goods_id = g.id AND score_average.status = 1 AND score_average.score > 0) AS score_average,
                       (SELECT COUNT(*) FROM shop_goods_reactions likes
                        WHERE likes.goods_id = g.id AND likes.reaction_type = 'like') AS likes_count,
                       (SELECT COUNT(*) FROM shop_goods_reactions favorites
                        WHERE favorites.goods_id = g.id AND favorites.reaction_type = 'favorite') AS favorites_count,
                       (SELECT COUNT(*) FROM shop_goods_forwards forwards WHERE forwards.goods_id = g.id) AS forwards_count,
                       EXISTS(SELECT 1 FROM shop_goods_reactions mine_like
                              WHERE mine_like.goods_id = g.id AND mine_like.user_id = ? AND mine_like.reaction_type = 'like') AS liked_by_me,
                       EXISTS(SELECT 1 FROM shop_goods_reactions mine_favorite
                              WHERE mine_favorite.goods_id = g.id AND mine_favorite.user_id = ? AND mine_favorite.reaction_type = 'favorite') AS favorited_by_me
                FROM shop_goods g LEFT JOIN shop_categories c ON c.id = g.category_id";
    }

    private static function normalizeGoodsList(array $items, int $appId): array
    {
        $items = MessageMediaService::hydrate($items, 'shop_goods', $appId);
        foreach ($items as &$item) {
            foreach (['tags_json' => 'tags', 'images_json' => 'images', 'attachments_json' => 'files'] as $raw => $target) {
                $item[$target] = self::jsonArray($item[$raw] ?? null);
                unset($item[$raw]);
            }
            foreach (['id', 'admin_id', 'app_id', 'category_id', 'delivery_required', 'price_integral', 'stock', 'sales_count',
                      'comments_count', 'likes_count', 'favorites_count', 'forwards_count'] as $field) {
                $item[$field] = (int) ($item[$field] ?? 0);
            }
            foreach (['liked_by_me', 'favorited_by_me'] as $field) $item[$field] = (bool) ($item[$field] ?? false);
            $item['price_money'] = (float) ($item['price_money'] ?? 0);
            $item['score_average'] = (float) ($item['score_average'] ?? 0);
            $item['delivery_required'] = (bool) $item['delivery_required'];
            $item['price_text'] = (int) $item['price_integral'] > 0
                ? '余额 ' . (int) $item['price_integral']
                : ((float) $item['price_money'] > 0 ? '¥' . number_format((float) $item['price_money'], 2) : '免费');
            $item['goods_type_text'] = self::goodsTypeText((string) ($item['goods_type'] ?? 'virtual'));
        }
        unset($item);
        return $items;
    }

    private static function commentRows(array $user, int $goodsId, int $offset, int $limit): array
    {
        $items = Database::all(
            "SELECT comment.*, target.account,
                    COALESCE(NULLIF(profile.nickname, ''), target.account) AS nickname,
                    profile.avatar AS avatar_url,
                    parent_user.account AS parent_account,
                    COALESCE(NULLIF(parent_profile.nickname, ''), parent_user.account) AS parent_nickname,
                    (SELECT COUNT(*) FROM shop_goods_comments child
                     WHERE child.parent_id = comment.id AND child.status = 1) AS reply_count,
                    (SELECT COUNT(*) FROM shop_comment_reactions likes
                     WHERE likes.comment_id = comment.id AND likes.reaction_type = 'like') AS likes_count,
                    EXISTS(SELECT 1 FROM shop_comment_reactions mine
                           WHERE mine.comment_id = comment.id AND mine.user_id = ? AND mine.reaction_type = 'like') AS liked_by_me
             FROM shop_goods_comments comment
             INNER JOIN users target ON target.id = comment.user_id
             LEFT JOIN user_profiles profile ON profile.user_id = target.id
             LEFT JOIN shop_goods_comments parent ON parent.id = comment.parent_id
             LEFT JOIN users parent_user ON parent_user.id = parent.user_id
             LEFT JOIN user_profiles parent_profile ON parent_profile.user_id = parent_user.id
             WHERE comment.admin_id = ? AND comment.app_id = ? AND comment.goods_id = ?
               AND comment.status = 1 AND comment.parent_id = 0
             ORDER BY comment.id DESC LIMIT {$limit} OFFSET {$offset}",
            [(int) $user['id'], (int) $user['admin_id'], (int) $user['app_id'], $goodsId]
        );
        $items = MessageMediaService::hydrate($items, 'shop_goods_comment', (int) $user['app_id']);
        foreach ($items as &$item) {
            $item['liked_by_me'] = (bool) $item['liked_by_me'];
            $item['can_edit'] = (int) $item['user_id'] === (int) $user['id'];
            $item['can_delete'] = $item['can_edit'];
            $item['replies'] = self::replyRows($user, (int) $item['id']);
        }
        unset($item);
        return $items;
    }

    private static function replyRows(array $user, int $parentId): array
    {
        $items = Database::all(
            "SELECT comment.*, target.account,
                    COALESCE(NULLIF(profile.nickname, ''), target.account) AS nickname,
                    profile.avatar AS avatar_url,
                    (SELECT COUNT(*) FROM shop_comment_reactions likes
                     WHERE likes.comment_id = comment.id AND likes.reaction_type = 'like') AS likes_count,
                    EXISTS(SELECT 1 FROM shop_comment_reactions mine
                           WHERE mine.comment_id = comment.id AND mine.user_id = ? AND mine.reaction_type = 'like') AS liked_by_me
             FROM shop_goods_comments comment
             INNER JOIN users target ON target.id = comment.user_id
             LEFT JOIN user_profiles profile ON profile.user_id = target.id
             WHERE comment.parent_id = ? AND comment.status = 1
             ORDER BY comment.id ASC LIMIT 100",
            [(int) $user['id'], $parentId]
        );
        $items = MessageMediaService::hydrate($items, 'shop_goods_comment', (int) $user['app_id']);
        foreach ($items as &$item) {
            $item['liked_by_me'] = (bool) $item['liked_by_me'];
            $item['can_edit'] = (int) $item['user_id'] === (int) $user['id'];
            $item['can_delete'] = $item['can_edit'];
        }
        unset($item);
        return $items;
    }

    private static function findComment(array $user, int $commentId, bool $hydrate = true): array
    {
        $item = Database::one(
            "SELECT comment.*, target.account,
                    COALESCE(NULLIF(profile.nickname, ''), target.account) AS nickname,
                    profile.avatar AS avatar_url
             FROM shop_goods_comments comment INNER JOIN users target ON target.id = comment.user_id
             LEFT JOIN user_profiles profile ON profile.user_id = target.id
             WHERE comment.id = ? AND comment.admin_id = ? AND comment.app_id = ? AND comment.status = 1",
            [$commentId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        if ($item === null) throw new HttpException('评论不存在', 404, 404);
        if ($hydrate) {
            $items = MessageMediaService::hydrate([$item], 'shop_goods_comment', (int) $user['app_id']);
            $item = $items[0];
        }
        $item['can_edit'] = (int) $item['user_id'] === (int) $user['id'];
        $item['can_delete'] = $item['can_edit'];
        return $item;
    }

    private static function share(Request $request, array $params, bool $recommend): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $goods = self::findGoods($user, (int) $params['goods_id'], true);
        $targetType = trim((string) $request->input('target_type', 'private'));
        $allowed = ['private', 'group', 'chat_room', 'forum', 'bounty', 'external'];
        if (!in_array($targetType, $allowed, true)) throw new HttpException('不支持的转发目标', 0, 422);
        $targetId = max(0, (int) $request->input('target_id', 0));
        if ($targetType !== 'external' && $targetId <= 0) throw new HttpException('请选择转发目标', 0, 422);
        $text = mb_substr(trim((string) $request->input('recommend_text', '')), 0, 500);
        $storedType = $recommend ? 'recommend_' . $targetType : $targetType;
        $forwardId = Database::insert(
            'INSERT INTO shop_goods_forwards
             (admin_id, app_id, goods_id, user_id, target_type, target_id, recommend_text, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $goods['id'], (int) $user['id'], $storedType, $targetId, $text]
        );
        if ($targetType === 'private' && $targetId !== (int) $user['id']) {
            $target = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], $targetId);
            if ($target !== null) {
                NotificationService::send($target, $recommend ? 'shop_goods_recommended' : 'shop_goods_forwarded',
                    $recommend ? '好友向你推荐了商品' : '好友向你转发了商品',
                    '《' . (string) $goods['name'] . '》', ['goods_id' => (int) $goods['id'], 'from_user_id' => (int) $user['id']]);
            }
        }
        LogService::userOperation($request, $user, 'shop_goods', $recommend ? 'recommend' : 'forward', (int) $goods['id'], [
            'target_type' => $targetType, 'target_id' => $targetId,
        ]);
        return Response::success([
            'forward_id' => $forwardId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'share_card' => [
                'card_type' => 'shop_goods', 'goods_id' => (int) $goods['id'], 'title' => (string) $goods['name'],
                'cover_url' => (string) $goods['cover_url'], 'price_text' => (int) $goods['price_integral'] > 0
                    ? '余额 ' . (int) $goods['price_integral']
                    : ((float) $goods['price_money'] > 0 ? '¥' . number_format((float) $goods['price_money'], 2) : '免费'),
                'recommend_text' => $text,
            ],
        ], $recommend ? '商品推荐已记录' : '商品转发已记录', 201);
    }

    private static function createPaymentOrder(
        Request $request,
        array $user,
        array $goods,
        int $quantity,
        array $buyerInfo
    ): \Yiyunying\Core\ApiResponse {
        if ((int) $goods['stock'] < $quantity) throw new HttpException('商品库存不足', 0, 409);
        $channelCode = Validator::string($request->input('pay_channel', ''), 'pay_channel', 2, 40);
        $channel = PaymentService::channel((int) $user['admin_id'], (int) $user['app_id'], $channelCode);
        $amount = round((float) $goods['price_money'] * $quantity, 2);
        if ($amount <= 0) throw new HttpException('支付金额必须大于 0', 0, 422);
        $orderNo = PaymentService::orderNo();
        $snapshot = [
            'goods_id' => (int) $goods['id'], 'goods_name' => (string) $goods['name'],
            'goods_cover_url' => (string) $goods['cover_url'], 'goods_type' => (string) $goods['goods_type'],
            'delivery_required' => (bool) $goods['delivery_required'], 'unit_price_money' => (float) $goods['price_money'],
        ];
        $orderId = Database::insert(
            'INSERT INTO orders
             (admin_id, app_id, user_id, order_no, order_type, target_id, title, quantity,
              amount, pay_amount, pay_channel, buyer_info_json, snapshot_json, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $orderNo,
                'shop_goods', (int) $goods['id'], (string) $goods['name'], $quantity,
                $amount, $amount, $channelCode, self::json($buyerInfo), self::json($snapshot), 'pending',
            ]
        );
        $order = [
            'id' => $orderId, 'admin_id' => (int) $user['admin_id'], 'app_id' => (int) $user['app_id'],
            'user_id' => (int) $user['id'], 'order_no' => $orderNo,
        ];
        OrderTrackingService::record($order, 'payment', 'created', '订单已创建', '等待完成在线支付', 'user', (int) $user['id']);
        $payload = [
            'app_key' => (string) $user['app_key'], 'order_no' => $orderNo,
            'amount' => number_format($amount, 2, '.', ''), 'timestamp' => time(),
        ];
        $payload['sign'] = PaymentService::signature($payload, (string) ($channel['config']['secret'] ?? ''));
        $result = [
            'payment_required' => true, 'order_source' => 'payment', 'order_id' => $orderId,
            'order_no' => $orderNo, 'status' => 'pending', 'status_text' => '待支付', 'amount_money' => $amount,
            'channel' => $channelCode, 'gateway_url' => trim((string) ($channel['config']['gateway_url'] ?? '')),
            'pay_params' => $payload,
            'callback_url' => rtrim((string) config('app.url'), '/') . '/api/public/payment/callback/' . rawurlencode($channelCode),
        ];
        LogService::userOperation($request, $user, 'shop_order', 'create_payment', $orderId, ['goods_id' => (int) $goods['id']]);
        NotificationService::send($user, 'order_created', '商品订单已创建',
            '《' . (string) $goods['name'] . '》等待支付', $result);
        return Response::success($result, '订单创建成功', 201);
    }

    private static function unifiedOrderSql(): string
    {
        return "SELECT 'shop' AS order_source, shop.id, shop.admin_id, shop.app_id, shop.user_id,
                       shop.order_no, 'shop_goods' AS order_type, shop.goods_id AS target_id,
                       shop.goods_name AS title, shop.goods_cover_url AS cover_url, shop.goods_type,
                       shop.quantity, shop.amount_integral AS amount_balance, shop.amount_money,
                       'balance' AS pay_channel, shop.buyer_info_json, NULL AS snapshot_json,
                       shop.status, shop.shipping_company, shop.tracking_no, shop.fulfillment_note,
                       shop.paid_at, shop.fulfilled_at, shop.closed_at, shop.created_at, shop.updated_at
                FROM shop_orders shop
                UNION ALL
                SELECT 'payment' AS order_source, payment.id, payment.admin_id, payment.app_id, payment.user_id,
                       payment.order_no, payment.order_type, payment.target_id, payment.title, '' AS cover_url,
                       payment.order_type AS goods_type, payment.quantity, 0 AS amount_balance,
                       payment.pay_amount AS amount_money, payment.pay_channel, payment.buyer_info_json,
                       payment.snapshot_json, payment.status, '' AS shipping_company, '' AS tracking_no,
                       '' AS fulfillment_note, payment.paid_at, NULL AS fulfilled_at, payment.closed_at,
                       payment.created_at, payment.updated_at
                FROM orders payment
                WHERE NOT (payment.order_type = 'shop_goods' AND EXISTS(
                    SELECT 1 FROM shop_orders fulfilled
                    WHERE fulfilled.app_id = ? AND fulfilled.order_no = payment.order_no
                ))";
    }

    private static function findOrder(array $user, string $source, int $orderId): array
    {
        if ($source === 'shop') {
            $item = Database::one(
                "SELECT 'shop' AS order_source, shop.*, 'shop_goods' AS order_type,
                        shop.goods_id AS target_id, shop.goods_name AS title,
                        shop.amount_integral AS amount_balance, 'balance' AS pay_channel,
                        NULL AS snapshot_json
                 FROM shop_orders shop
                 WHERE shop.id = ? AND shop.admin_id = ? AND shop.app_id = ? AND shop.user_id = ?",
                [$orderId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
            );
        } else {
            $item = Database::one(
                "SELECT 'payment' AS order_source, payment.*, payment.target_id,
                        payment.title, '' AS goods_cover_url, payment.order_type AS goods_type,
                        0 AS amount_balance, payment.pay_amount AS amount_money,
                        '' AS shipping_company, '' AS tracking_no, '' AS fulfillment_note,
                        NULL AS fulfilled_at
                 FROM orders payment
                 WHERE payment.id = ? AND payment.admin_id = ? AND payment.app_id = ? AND payment.user_id = ?",
                [$orderId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
            );
        }
        if ($item === null) throw new HttpException('订单不存在', 404, 404);
        return $item;
    }

    private static function normalizeOrder(array &$item): void
    {
        $item['buyer_info'] = self::jsonObject($item['buyer_info_json'] ?? null);
        $item['snapshot'] = self::jsonObject($item['snapshot_json'] ?? null);
        unset($item['buyer_info_json'], $item['snapshot_json']);
        foreach (['id', 'admin_id', 'app_id', 'user_id', 'target_id', 'quantity', 'amount_balance'] as $field) {
            $item[$field] = (int) ($item[$field] ?? 0);
        }
        $item['amount_money'] = (float) ($item['amount_money'] ?? 0);
        if (($item['cover_url'] ?? '') === '' && isset($item['goods_cover_url'])) {
            $item['cover_url'] = (string) $item['goods_cover_url'];
        }
        if (($item['cover_url'] ?? '') === '' && isset($item['snapshot']['goods_cover_url'])) {
            $item['cover_url'] = (string) $item['snapshot']['goods_cover_url'];
        }
        $item['status_text'] = self::statusText((string) ($item['status'] ?? 'pending'));
        $item['order_type_text'] = self::orderTypeText((string) ($item['order_type'] ?? ''));
        $item['can_cancel'] = ($item['order_source'] === 'payment' && $item['status'] === 'pending')
            || ($item['order_source'] === 'shop' && $item['status'] === 'paid');
        $item['progress'] = self::orderProgress((string) ($item['status'] ?? 'pending'));
    }

    private static function buyerInfo(Request $request, bool $required): array
    {
        $raw = $request->input('buyer_info', []);
        if (is_string($raw)) $raw = json_decode($raw, true);
        if (!is_array($raw)) $raw = [];
        $info = [
            'receiver_name' => mb_substr(trim((string) ($raw['receiver_name'] ?? '')), 0, 80),
            'phone' => mb_substr(trim((string) ($raw['phone'] ?? '')), 0, 30),
            'address' => mb_substr(trim((string) ($raw['address'] ?? '')), 0, 300),
            'remark' => mb_substr(trim((string) ($raw['remark'] ?? '')), 0, 300),
        ];
        if ($required && ($info['receiver_name'] === '' || $info['phone'] === '' || $info['address'] === '')) {
            throw new HttpException('实物商品必须填写收货人、联系电话和收货地址', 0, 422);
        }
        return $info;
    }

    private static function requestedEnabled(Request $request): ?bool
    {
        $value = $request->input('enabled', null);
        return $value === null ? null : Validator::boolean($value, 'enabled');
    }

    private static function orderSource(string $source): string
    {
        $source = strtolower(trim($source));
        if (!in_array($source, ['shop', 'payment'], true)) throw new HttpException('订单来源无效', 0, 422);
        return $source;
    }

    private static function categoryTree(array $items): array
    {
        $grouped = [];
        foreach ($items as $item) {
            $item['id'] = (int) $item['id'];
            $item['parent_id'] = (int) $item['parent_id'];
            $item['goods_count'] = (int) $item['goods_count'];
            $grouped[$item['parent_id']][] = $item;
        }
        $build = static function (int $parentId) use (&$build, $grouped): array {
            $result = [];
            foreach ($grouped[$parentId] ?? [] as $item) {
                $item['children'] = $build((int) $item['id']);
                $result[] = $item;
            }
            return $result;
        };
        return $build(0);
    }

    private static function jsonArray($raw): array
    {
        $value = is_array($raw) ? $raw : json_decode((string) ($raw ?? ''), true);
        return is_array($value) ? array_values($value) : [];
    }

    private static function jsonObject($raw): array
    {
        $value = is_array($raw) ? $raw : json_decode((string) ($raw ?? ''), true);
        return is_array($value) ? $value : [];
    }

    private static function json(array $value): ?string
    {
        return $value === [] ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function statusText(string $status): string
    {
        return [
            'pending' => '待支付', 'paid' => '已支付', 'processing' => '处理中',
            'shipped' => '已发货', 'completed' => '已完成', 'closed' => '已关闭',
            'cancelled' => '已取消', 'refunded' => '已退款', 'failed' => '支付失败',
        ][$status] ?? '状态待确认';
    }

    private static function orderTypeText(string $type): string
    {
        return [
            'shop_goods' => '商城商品', 'balance_recharge' => '余额充值',
            'document_credit' => '笔记额度', 'vip' => '会员服务',
        ][$type] ?? '其他订单';
    }

    private static function goodsTypeText(string $type): string
    {
        return [
            'virtual' => '虚拟商品', 'physical' => '实物商品', 'service' => '服务商品',
            'download' => '数字下载', 'membership' => '会员权益',
        ][$type] ?? '普通商品';
    }

    private static function orderProgress(string $status): int
    {
        return [
            'pending' => 10, 'paid' => 35, 'processing' => 55, 'shipped' => 80,
            'completed' => 100, 'closed' => 100, 'cancelled' => 100, 'refunded' => 100, 'failed' => 100,
        ][$status] ?? 0;
    }
}
