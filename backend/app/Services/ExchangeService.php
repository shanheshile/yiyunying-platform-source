<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class ExchangeService
{
    private const PRODUCT_TYPES = ['membership_days', 'app_quota', 'remote_document_quota', 'bundle'];

    public static function seedDefaultProducts(int $platformId): void
    {
        $products = [
            ['remote_document_1', '1 个远程文档名额', '兑换后立即增加 1 个远程文档名额', 'remote_document_quota', ['remote_document_quota' => 1], 5, 10],
            ['vip_day_1', '1 天 VIP', '兑换后立即延长 1 天会员', 'membership_days', ['vip_days' => 1, 'membership_level' => 'vip'], 10, 20],
            ['app_quota_1', '1 个 App 名额', '兑换后立即增加 1 个 App 创建名额', 'app_quota', ['app_quota' => 1], 50, 30],
            ['growth_bundle', '成长组合包', '包含 30 天 VIP、1 个 App 名额和 10 个远程文档名额', 'bundle', [
                'vip_days' => 30,
                'membership_level' => 'vip',
                'app_quota' => 1,
                'remote_document_quota' => 10,
            ], 100, 40],
        ];
        foreach ($products as [$code, $name, $description, $type, $grant, $price, $sort]) {
            Database::execute(
                'INSERT INTO platform_exchange_products
                 (platform_id, product_code, name, description, product_type, grant_json,
                  price_integral, stock, sold_count, per_admin_limit, per_admin_daily_limit,
                  status, sort_order, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 0, 0, 0, 1, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
                  product_type = VALUES(product_type), grant_json = VALUES(grant_json),
                  price_integral = VALUES(price_integral), status = 1, deleted_at = NULL,
                  sort_order = VALUES(sort_order), updated_at = NOW()',
                [
                    $platformId, $code, $name, $description, $type,
                    json_encode($grant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $price, $sort,
                ]
            );
        }
    }

    public static function validateProductInput(array $data, ?array $current = null): array
    {
        $required = static function (string $field) use ($data, $current) {
            if (array_key_exists($field, $data)) {
                return $data[$field];
            }
            if ($current !== null && array_key_exists($field, $current)) {
                return $current[$field];
            }
            throw new HttpException($field . ' 不能为空', 0, 422);
        };

        $code = trim((string) $required('product_code'));
        if (preg_match('/^[a-z][a-z0-9_.-]{1,79}$/', $code) !== 1) {
            throw new HttpException('product_code 必须以小写字母开头，只能包含小写字母、数字、点、短横线和下划线', 0, 422);
        }
        $name = trim((string) $required('name'));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            throw new HttpException('name 长度必须在 2-150 个字符之间', 0, 422);
        }
        $type = trim((string) $required('product_type'));
        if (!in_array($type, self::PRODUCT_TYPES, true)) {
            throw new HttpException('product_type 不支持', 0, 422, ['allowed' => self::PRODUCT_TYPES]);
        }

        $grantInput = $data['grant'] ?? $data['grant_json'] ?? ($current['grant_json'] ?? null);
        if (is_string($grantInput)) {
            $grantInput = json_decode($grantInput, true);
        }
        if (!is_array($grantInput)) {
            throw new HttpException('grant 必须是对象', 0, 422);
        }
        $grant = self::validateGrant($grantInput, $type);
        $price = self::nonNegativeInt($data['price_balance'] ?? ($current['price_integral'] ?? null), 'price_balance', true);

        $stockValue = array_key_exists('stock', $data) ? $data['stock'] : ($current['stock'] ?? null);
        $stock = ($stockValue === null || $stockValue === '')
            ? null
            : self::nonNegativeInt($stockValue, 'stock');
        $perAdminLimit = self::nonNegativeInt(
            $data['per_admin_limit'] ?? ($current['per_admin_limit'] ?? 0),
            'per_admin_limit'
        );
        $dailyLimit = self::nonNegativeInt(
            $data['per_admin_daily_limit'] ?? ($current['per_admin_daily_limit'] ?? 0),
            'per_admin_daily_limit'
        );
        $status = (int) ($data['status'] ?? ($current['status'] ?? 1));
        if (!in_array($status, [0, 1], true)) {
            throw new HttpException('status 仅支持 0 或 1', 0, 422);
        }
        $startAt = self::dateTime($data['start_at'] ?? ($current['start_at'] ?? null), 'start_at');
        $endAt = self::dateTime($data['end_at'] ?? ($current['end_at'] ?? null), 'end_at');
        if ($startAt !== null && $endAt !== null && strtotime($startAt) >= strtotime($endAt)) {
            throw new HttpException('end_at 必须晚于 start_at', 0, 422);
        }

        return [
            'product_code' => $code,
            'name' => $name,
            'description' => mb_substr(trim((string) ($data['description'] ?? ($current['description'] ?? ''))), 0, 5000),
            'product_type' => $type,
            'grant' => $grant,
            'grant_json' => json_encode($grant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'price_integral' => $price,
            'stock' => $stock,
            'per_admin_limit' => $perAdminLimit,
            'per_admin_daily_limit' => $dailyLimit,
            'status' => $status,
            'sort_order' => (int) ($data['sort_order'] ?? ($current['sort_order'] ?? 0)),
            'start_at' => $startAt,
            'end_at' => $endAt,
        ];
    }

    public static function productForAdmin(array $admin, int $productId, bool $requireAvailable = false): array
    {
        $sql = 'SELECT * FROM platform_exchange_products
                WHERE id = ? AND platform_id = ? AND deleted_at IS NULL';
        $params = [$productId, (int) $admin['platform_id']];
        if ($requireAvailable) {
            $sql .= ' AND status = 1 AND (start_at IS NULL OR start_at <= NOW())
                      AND (end_at IS NULL OR end_at >= NOW())';
        }
        $product = Database::one($sql, $params);
        if ($product === null) {
            throw new HttpException('余额兑换商品不存在、未上架或不属于当前平台', 404, 404);
        }
        return $product;
    }

    public static function quote(array $admin, int $productId, int $quantity): array
    {
        $product = self::productForAdmin($admin, $productId, true);
        $entitlement = Database::one('SELECT * FROM admin_entitlements WHERE admin_id = ?', [(int) $admin['id']]);
        if ($entitlement === null) {
            throw new HttpException('admin 权益记录不存在', 404, 404);
        }
        return self::buildQuote(
            $product,
            (int) $admin['id'],
            (int) $entitlement['integral'],
            $quantity,
            (string) $entitlement['membership_status']
        );
    }

    public static function exchange(array $admin, int $productId, int $quantity, string $idempotencyKey): array
    {
        self::validateQuantity($quantity);
        $idempotencyKey = trim($idempotencyKey);
        if (strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 100
            || preg_match('/^[A-Za-z0-9_.:-]+$/', $idempotencyKey) !== 1) {
            throw new HttpException('idempotency_key 长度必须为 8-100，只能包含字母、数字、点、冒号、短横线和下划线', 0, 422);
        }

        $result = Database::transaction(static function () use ($admin, $productId, $quantity, $idempotencyKey): array {
            $adminId = (int) $admin['id'];
            $platformId = (int) $admin['platform_id'];
            $entitlement = Database::one(
                'SELECT * FROM admin_entitlements WHERE admin_id = ? AND platform_id = ? FOR UPDATE',
                [$adminId, $platformId]
            );
            if ($entitlement === null) {
                throw new HttpException('admin 权益记录不存在', 404, 404);
            }
            $existing = Database::one(
                'SELECT * FROM admin_exchange_orders WHERE admin_id = ? AND idempotency_key = ? FOR UPDATE',
                [$adminId, $idempotencyKey]
            );
            if ($existing !== null) {
                if ((int) $existing['product_id'] !== $productId || (int) $existing['quantity'] !== $quantity) {
                    throw new HttpException('同一 idempotency_key 不能用于不同的商品或数量', 0, 409);
                }
                return ['order' => $existing, 'idempotent' => true];
            }

            $product = Database::one(
                'SELECT * FROM platform_exchange_products
                 WHERE id = ? AND platform_id = ? AND deleted_at IS NULL
                   AND status = 1 AND (start_at IS NULL OR start_at <= NOW())
                   AND (end_at IS NULL OR end_at >= NOW()) FOR UPDATE',
                [$productId, $platformId]
            );
            if ($product === null) {
                throw new HttpException('余额兑换商品不存在、未上架、未开始或已结束', 404, 404);
            }

            $quote = self::buildQuote(
                $product,
                $adminId,
                (int) $entitlement['integral'],
                $quantity,
                (string) $entitlement['membership_status']
            );
            if (!$quote['can_exchange']) {
                throw new HttpException('当前不能兑换该商品', 0, 422, ['quote' => $quote]);
            }

            $before = self::entitlementSnapshot($entitlement);
            $after = self::applyGrant($before, $quote['total_grant']);
            $after['integral'] = (int) $before['integral'] - (int) $quote['total_balance'];
            $orderNo = self::orderNo();
            $orderId = Database::insert(
                'INSERT INTO admin_exchange_orders
                 (platform_id, admin_id, product_id, order_no, idempotency_key, product_code,
                  product_name, product_type, unit_price_integral, quantity, total_integral,
                  grant_json, before_entitlement_json, after_entitlement_json, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $platformId, $adminId, (int) $product['id'], $orderNo, $idempotencyKey,
                    $product['product_code'], $product['name'], $product['product_type'],
                    (int) $product['price_integral'], $quantity, (int) $quote['total_balance'],
                    json_encode($quote['total_grant'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'completed',
                ]
            );
            self::saveEntitlement($adminId, $platformId, $after, $platformId);
            Database::execute(
                'UPDATE platform_exchange_products
                 SET stock = CASE WHEN stock IS NULL THEN NULL ELSE stock - ? END,
                     sold_count = sold_count + ?, updated_at = NOW()
                 WHERE id = ?',
                [$quantity, $quantity, (int) $product['id']]
            );
            self::recordIntegralLog(
                $platformId,
                $adminId,
                -(int) $quote['total_balance'],
                (int) $before['integral'],
                (int) $after['integral'],
                'point_exchange',
                'admin_exchange_order',
                $orderId,
                '兑换：' . (string) $product['name'],
                $platformId
            );
            Database::execute(
                'INSERT INTO admin_entitlement_logs
                 (platform_id, admin_id, actor_platform_id, change_type, before_json, change_json,
                  after_json, remark, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $platformId, $adminId, $platformId, 'point_exchange',
                    json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode([
                        'order_id' => $orderId,
                        'product_id' => (int) $product['id'],
                        'quantity' => $quantity,
                        'cost_balance' => (int) $quote['total_balance'],
                        'grant' => $quote['total_grant'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    '平台余额自动兑换',
                ]
            );
            PlatformService::increment($platformId, 'point_exchange_count');
            PlatformService::increment($platformId, 'point_exchange_integral', (int) $quote['total_balance']);
            $order = Database::one('SELECT * FROM admin_exchange_orders WHERE id = ?', [$orderId]);
            return ['order' => $order, 'idempotent' => false];
        });

        $context = AdminAccessService::context((int) $admin['id']);
        return [
            'order' => self::orderData((array) $result['order']),
            'entitlement' => self::entitlementData($context),
            'idempotent' => (bool) $result['idempotent'],
        ];
    }

    public static function refund(array $actor, int $orderId, string $reason): array
    {
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') {
            throw new HttpException('refund_reason 不能为空', 0, 422);
        }
        $result = Database::transaction(static function () use ($actor, $orderId, $reason): array {
            $sql = 'SELECT * FROM admin_exchange_orders WHERE id = ?';
            $params = [$orderId];
            if ((int) $actor['level'] === 2) {
                $sql .= ' AND platform_id = ?';
                $params[] = (int) $actor['id'];
            } else {
                $sql .= ' AND (platform_id = ? OR platform_id IN
                        (SELECT id FROM platform_accounts WHERE parent_id = ? AND level = 2 AND deleted_at IS NULL))';
                $params[] = (int) $actor['id'];
                $params[] = (int) $actor['id'];
            }
            $candidate = Database::one($sql, $params);
            if ($candidate === null) {
                throw new HttpException('兑换订单不存在或不在当前平台范围内', 404, 404);
            }
            $entitlement = Database::one(
                'SELECT * FROM admin_entitlements WHERE admin_id = ? AND platform_id = ? FOR UPDATE',
                [(int) $candidate['admin_id'], (int) $candidate['platform_id']]
            );
            $order = Database::one($sql . ' FOR UPDATE', $params);
            if ($order === null) {
                throw new HttpException('兑换订单已被删除', 0, 409);
            }
            if ((string) $order['status'] !== 'completed') {
                throw new HttpException('只有 completed 兑换订单可以退款', 0, 409);
            }
            $product = Database::one(
                'SELECT * FROM platform_exchange_products WHERE id = ? AND platform_id = ? FOR UPDATE',
                [(int) $order['product_id'], (int) $order['platform_id']]
            );
            if ($entitlement === null || $product === null) {
                throw new HttpException('退款所需的权益或商品记录不存在', 0, 409);
            }
            $grant = self::decodeArray($order['grant_json']);
            $beforeExchange = self::decodeArray($order['before_entitlement_json']);
            $afterExchange = self::decodeArray($order['after_entitlement_json']);
            $beforeRefund = self::entitlementSnapshot($entitlement);
            $afterRefund = self::reverseGrant(
                $beforeRefund,
                $grant,
                $beforeExchange,
                $afterExchange,
                (int) $order['admin_id']
            );
            if ((int) $beforeRefund['integral'] > PHP_INT_MAX - (int) $order['total_integral']) {
                throw new HttpException('退款后余额超过系统整数上限', 0, 409);
            }
            $afterRefund['integral'] = (int) $beforeRefund['integral'] + (int) $order['total_integral'];
            self::saveEntitlement(
                (int) $order['admin_id'],
                (int) $order['platform_id'],
                $afterRefund,
                (int) $actor['id']
            );
            Database::execute(
                'UPDATE admin_exchange_orders SET status = ?, refunded_by_platform_id = ?,
                 refund_reason = ?, refunded_at = NOW(), updated_at = NOW() WHERE id = ?',
                ['refunded', (int) $actor['id'], $reason, $orderId]
            );
            Database::execute(
                'UPDATE platform_exchange_products
                 SET stock = CASE WHEN stock IS NULL THEN NULL ELSE stock + ? END,
                     sold_count = GREATEST(0, sold_count - ?), updated_at = NOW()
                 WHERE id = ?',
                [(int) $order['quantity'], (int) $order['quantity'], (int) $product['id']]
            );
            self::recordIntegralLog(
                (int) $order['platform_id'],
                (int) $order['admin_id'],
                (int) $order['total_integral'],
                (int) $beforeRefund['integral'],
                (int) $afterRefund['integral'],
                'point_exchange_refund',
                'admin_exchange_order',
                $orderId,
                $reason,
                (int) $actor['id']
            );
            Database::execute(
                'INSERT INTO admin_entitlement_logs
                 (platform_id, admin_id, actor_platform_id, change_type, before_json, change_json,
                  after_json, remark, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    (int) $order['platform_id'], (int) $order['admin_id'], (int) $actor['id'],
                    'point_exchange_refund',
                    json_encode($beforeRefund, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode(['order_id' => $orderId, 'reversed_grant' => $grant], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($afterRefund, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $reason,
                ]
            );
            PlatformService::increment((int) $order['platform_id'], 'point_refund_count');
            PlatformService::increment((int) $order['platform_id'], 'point_refund_integral', (int) $order['total_integral']);
            return ['order_id' => $orderId, 'admin_id' => (int) $order['admin_id'], 'after' => $afterRefund];
        });

        if (strtotime((string) $result['after']['membership_expired_at']) <= time()
            || (string) $result['after']['membership_status'] !== 'active') {
            Database::execute('UPDATE user_tokens SET revoked_at = NOW() WHERE admin_id = ? AND revoked_at IS NULL', [(int) $result['admin_id']]);
            Database::execute('UPDATE user_refresh_tokens SET revoked_at = NOW() WHERE admin_id = ? AND revoked_at IS NULL', [(int) $result['admin_id']]);
        }
        $order = Database::one('SELECT * FROM admin_exchange_orders WHERE id = ?', [$orderId]);
        return [
            'order' => self::orderData((array) $order),
            'entitlement' => self::entitlementData(AdminAccessService::context((int) $result['admin_id'])),
        ];
    }

    public static function recordIntegralLog(
        int $platformId,
        int $adminId,
        int $change,
        int $before,
        int $after,
        string $scene,
        string $refType = '',
        ?int $refId = null,
        string $remark = '',
        ?int $actorPlatformId = null
    ): void {
        Database::execute(
            'INSERT INTO admin_integral_logs
             (platform_id, admin_id, change_value, before_value, after_value, scene,
              ref_type, ref_id, remark, actor_platform_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $platformId, $adminId, $change, $before, $after, mb_substr($scene, 0, 50),
                mb_substr($refType, 0, 50), $refId, mb_substr($remark, 0, 500), $actorPlatformId,
            ]
        );
    }

    public static function productData(array $product): array
    {
        return [
            'id' => (int) $product['id'],
            'platform_id' => (int) $product['platform_id'],
            'product_code' => $product['product_code'],
            'name' => $product['name'],
            'description' => $product['description'],
            'product_type' => $product['product_type'],
            'grant' => self::decodeArray($product['grant_json']),
            'price_balance' => (int) $product['price_integral'],
            'stock' => $product['stock'] === null ? null : (int) $product['stock'],
            'sold_count' => (int) $product['sold_count'],
            'per_admin_limit' => (int) $product['per_admin_limit'],
            'per_admin_daily_limit' => (int) $product['per_admin_daily_limit'],
            'status' => (int) $product['status'],
            'sort_order' => (int) $product['sort_order'],
            'start_at' => $product['start_at'],
            'end_at' => $product['end_at'],
            'created_at' => $product['created_at'],
            'updated_at' => $product['updated_at'],
        ];
    }

    public static function orderData(array $order): array
    {
        foreach (['id', 'platform_id', 'admin_id', 'product_id', 'unit_price_integral', 'quantity', 'total_integral'] as $key) {
            if (array_key_exists($key, $order)) {
                $order[$key] = (int) $order[$key];
            }
        }
        if (array_key_exists('unit_price_integral', $order)) {
            $order['unit_price_balance'] = $order['unit_price_integral'];
            unset($order['unit_price_integral']);
        }
        if (array_key_exists('total_integral', $order)) {
            $order['total_balance'] = $order['total_integral'];
            unset($order['total_integral']);
        }
        $order['grant'] = self::decodeArray($order['grant_json'] ?? null);
        $order['before_entitlement'] = self::decodeArray($order['before_entitlement_json'] ?? null);
        $order['after_entitlement'] = self::decodeArray($order['after_entitlement_json'] ?? null);
        unset($order['grant_json'], $order['before_entitlement_json'], $order['after_entitlement_json']);
        return $order;
    }

    public static function entitlementData(array $admin): array
    {
        return [
            'membership_level' => $admin['membership_level'],
            'membership_status' => $admin['membership_status'],
            'membership_started_at' => $admin['membership_started_at'],
            'membership_expired_at' => $admin['membership_expired_at'],
            'app_quota' => (int) $admin['app_quota'],
            'remote_document_quota' => (int) $admin['remote_document_quota'],
            'balance' => (int) ($admin['admin_integral'] ?? $admin['integral']),
            'access_start_time' => $admin['access_start_time'],
            'access_end_time' => $admin['access_end_time'],
            'allowed_weekdays' => $admin['allowed_weekdays'],
        ];
    }

    private static function buildQuote(
        array $product,
        int $adminId,
        int $balance,
        int $quantity,
        string $membershipStatus
    ): array
    {
        self::validateQuantity($quantity);
        $unitPrice = (int) $product['price_integral'];
        if ($unitPrice > 0 && $quantity > intdiv(PHP_INT_MAX, $unitPrice)) {
            throw new HttpException('兑换总余额超过系统整数上限', 0, 422);
        }
        $total = $unitPrice * $quantity;
        $unitGrant = self::validateGrant(
            self::decodeArray($product['grant_json']),
            (string) $product['product_type']
        );
        $grant = self::multiplyGrant($unitGrant, $quantity);
        $usage = self::usage($adminId, (int) $product['id']);
        $platformId = (int) $product['platform_id'];
        $exchangeEnabled = (bool) PlatformService::setting($platformId, 'balance_exchange_enabled', true);
        $maxQuantity = max(1, min(1000, (int) PlatformService::setting(
            $platformId,
            'balance_exchange_max_quantity_per_order',
            100
        )));
        $dailyIntegralLimit = max(0, (int) PlatformService::setting(
            $platformId,
            'balance_exchange_admin_daily_limit',
            0
        ));
        $todayIntegral = (int) (Database::one(
            "SELECT COALESCE(SUM(total_integral), 0) AS total
             FROM admin_exchange_orders
             WHERE admin_id = ? AND platform_id = ? AND status = 'completed' AND created_at >= CURDATE()",
            [$adminId, $platformId]
        )['total'] ?? 0);
        $reasons = [];
        if ($membershipStatus === 'frozen') {
            $reasons[] = 'membership_frozen';
        }
        if (!$exchangeEnabled) {
            $reasons[] = 'platform_exchange_disabled';
        }
        if ($quantity > $maxQuantity) {
            $reasons[] = 'order_quantity_limit_exceeded';
        }
        if ($product['stock'] !== null && $quantity > (int) $product['stock']) {
            $reasons[] = 'stock_insufficient';
        }
        if ((int) $product['per_admin_limit'] > 0
            && $usage['lifetime_quantity'] + $quantity > (int) $product['per_admin_limit']) {
            $reasons[] = 'lifetime_limit_exceeded';
        }
        if ((int) $product['per_admin_daily_limit'] > 0
            && $usage['today_quantity'] + $quantity > (int) $product['per_admin_daily_limit']) {
            $reasons[] = 'daily_limit_exceeded';
        }
        if ($balance < $total) {
            $reasons[] = 'balance_insufficient';
        }
        if ($dailyIntegralLimit > 0 && $todayIntegral + $total > $dailyIntegralLimit) {
            $reasons[] = 'daily_balance_limit_exceeded';
        }
        return [
            'product' => self::productData($product),
            'quantity' => $quantity,
            'unit_price_balance' => $unitPrice,
            'total_balance' => $total,
            'balance_before' => $balance,
            'balance_after' => $balance - $total,
            'total_grant' => $grant,
            'usage' => $usage,
            'platform_rules' => [
                'exchange_enabled' => $exchangeEnabled,
                'max_quantity_per_order' => $maxQuantity,
                'admin_daily_balance_limit' => $dailyIntegralLimit,
                'admin_today_balance' => $todayIntegral,
            ],
            'can_exchange' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    private static function usage(int $adminId, int $productId): array
    {
        $row = Database::one(
            "SELECT
               COALESCE(SUM(CASE WHEN status = 'completed' THEN quantity ELSE 0 END), 0) AS lifetime_quantity,
               COALESCE(SUM(CASE WHEN status = 'completed' AND created_at >= CURDATE() THEN quantity ELSE 0 END), 0) AS today_quantity
             FROM admin_exchange_orders WHERE admin_id = ? AND product_id = ?",
            [$adminId, $productId]
        ) ?? [];
        return [
            'lifetime_quantity' => (int) ($row['lifetime_quantity'] ?? 0),
            'today_quantity' => (int) ($row['today_quantity'] ?? 0),
        ];
    }

    private static function applyGrant(array $before, array $grant): array
    {
        $after = $before;
        $after['app_quota'] = self::safeAdd((int) $before['app_quota'], (int) ($grant['app_quota'] ?? 0), 'app_quota');
        $after['remote_document_quota'] = self::safeAdd(
            (int) $before['remote_document_quota'],
            (int) ($grant['remote_document_quota'] ?? 0),
            'remote_document_quota'
        );
        $vipDays = (int) ($grant['vip_days'] ?? 0);
        if ($vipDays > 0) {
            $currentExpiry = strtotime((string) $before['membership_expired_at']);
            $base = max(time(), $currentExpiry === false ? 0 : $currentExpiry);
            $seconds = $vipDays * 86400;
            if ($base > PHP_INT_MAX - $seconds) {
                throw new HttpException('会员到期时间超过系统上限', 0, 422);
            }
            $after['membership_expired_at'] = date('Y-m-d H:i:s', $base + $seconds);
            $after['membership_status'] = 'active';
            $after['membership_level'] = self::upgradeMembershipLevel(
                (string) $before['membership_level'],
                (string) ($grant['membership_level'] ?? 'vip')
            );
        }
        return $after;
    }

    private static function reverseGrant(
        array $current,
        array $grant,
        array $beforeExchange,
        array $afterExchange,
        int $adminId
    ): array {
        $after = $current;
        $appGrant = (int) ($grant['app_quota'] ?? 0);
        $remoteGrant = (int) ($grant['remote_document_quota'] ?? 0);
        $newAppQuota = (int) $current['app_quota'] - $appGrant;
        $newRemoteQuota = (int) $current['remote_document_quota'] - $remoteGrant;
        $appUsed = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM apps WHERE admin_id = ? AND deleted_at IS NULL',
            [$adminId]
        )['total'] ?? 0);
        $remoteUsed = (int) (Database::one(
            "SELECT COUNT(*) AS total
             FROM remote_files f
             INNER JOIN apps a ON a.id = f.app_id AND a.admin_id = f.admin_id
             WHERE f.admin_id = ? AND f.file_type = 'file' AND f.deleted_at IS NULL
               AND a.deleted_at IS NULL",
            [$adminId]
        )['total'] ?? 0);
        if ($newAppQuota < $appUsed) {
            throw new HttpException('兑换得到的 App 名额已被使用，删除超出退款后额度的 App 后才能退款', 0, 409, [
                'used' => $appUsed,
                'quota_after_refund' => $newAppQuota,
            ]);
        }
        if ($newRemoteQuota < $remoteUsed) {
            throw new HttpException('兑换得到的远程文档名额已被使用，删除超出退款后额度的文件后才能退款', 0, 409, [
                'used' => $remoteUsed,
                'quota_after_refund' => $newRemoteQuota,
            ]);
        }
        if ($newAppQuota < 0 || $newRemoteQuota < 0) {
            throw new HttpException('当前权益已低于兑换前水平，无法安全退款', 0, 409);
        }
        $after['app_quota'] = $newAppQuota;
        $after['remote_document_quota'] = $newRemoteQuota;

        $vipDays = (int) ($grant['vip_days'] ?? 0);
        if ($vipDays > 0) {
            $currentExpiry = strtotime((string) $current['membership_expired_at']);
            $baseline = strtotime((string) ($beforeExchange['membership_expired_at'] ?? ''));
            if ($currentExpiry === false || $baseline === false) {
                throw new HttpException('会员权益快照不完整，无法自动退款', 0, 409);
            }
            $candidate = $currentExpiry - $vipDays * 86400;
            if ($candidate < $baseline) {
                throw new HttpException('会员权益在兑换后被缩短，无法安全自动退款', 0, 409);
            }
            $after['membership_expired_at'] = date('Y-m-d H:i:s', $candidate);
            $after['membership_status'] = $candidate <= time() ? 'expired' : (string) $current['membership_status'];
            if ((string) $current['membership_level'] === (string) ($afterExchange['membership_level'] ?? '')
                && array_key_exists('membership_level', $beforeExchange)) {
                $after['membership_level'] = (string) $beforeExchange['membership_level'];
            }
        }
        return $after;
    }

    private static function saveEntitlement(int $adminId, int $platformId, array $data, int $granterPlatformId): void
    {
        Database::execute(
            'UPDATE admin_entitlements SET membership_level = ?, membership_status = ?,
             membership_expired_at = ?, app_quota = ?, remote_document_quota = ?, integral = ?,
             last_granted_by_platform_id = ?, updated_at = NOW()
             WHERE admin_id = ? AND platform_id = ?',
            [
                $data['membership_level'], $data['membership_status'], $data['membership_expired_at'],
                (int) $data['app_quota'], (int) $data['remote_document_quota'], (int) $data['integral'],
                $granterPlatformId, $adminId, $platformId,
            ]
        );
    }

    private static function entitlementSnapshot(array $row): array
    {
        return [
            'membership_level' => (string) $row['membership_level'],
            'membership_status' => (string) $row['membership_status'],
            'membership_started_at' => $row['membership_started_at'],
            'membership_expired_at' => $row['membership_expired_at'],
            'app_quota' => (int) $row['app_quota'],
            'remote_document_quota' => (int) $row['remote_document_quota'],
            'integral' => (int) $row['integral'],
            'access_start_time' => $row['access_start_time'],
            'access_end_time' => $row['access_end_time'],
            'allowed_weekdays' => $row['allowed_weekdays'],
        ];
    }

    private static function validateGrant(array $grant, string $type): array
    {
        $allowed = ['vip_days', 'membership_level', 'app_quota', 'remote_document_quota'];
        foreach (array_keys($grant) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                throw new HttpException('grant 包含不支持的字段：' . (string) $key, 0, 422);
            }
        }
        $result = [];
        foreach (['vip_days' => 36500, 'app_quota' => 1000000, 'remote_document_quota' => 1000000] as $key => $max) {
            $value = self::nonNegativeInt($grant[$key] ?? 0, 'grant.' . $key);
            if ($value > $max) {
                throw new HttpException('grant.' . $key . ' 超出单件商品上限', 0, 422, ['max' => $max]);
            }
            if ($value > 0) {
                $result[$key] = $value;
            }
        }
        if (isset($grant['membership_level'])) {
            $level = mb_substr(trim((string) $grant['membership_level']), 0, 40);
            if ($level === '') {
                throw new HttpException('grant.membership_level 不能为空', 0, 422);
            }
            $result['membership_level'] = $level;
        }
        if (($result['vip_days'] ?? 0) + ($result['app_quota'] ?? 0) + ($result['remote_document_quota'] ?? 0) <= 0) {
            throw new HttpException('grant 至少包含一种大于 0 的权益', 0, 422);
        }
        if ($type === 'membership_days' && !isset($result['vip_days'])) {
            throw new HttpException('membership_days 商品必须包含 vip_days', 0, 422);
        }
        if ($type === 'app_quota' && !isset($result['app_quota'])) {
            throw new HttpException('app_quota 商品必须包含 app_quota', 0, 422);
        }
        if ($type === 'remote_document_quota' && !isset($result['remote_document_quota'])) {
            throw new HttpException('remote_document_quota 商品必须包含 remote_document_quota', 0, 422);
        }
        return $result;
    }

    private static function multiplyGrant(array $grant, int $quantity): array
    {
        $result = $grant;
        foreach (['vip_days', 'app_quota', 'remote_document_quota'] as $key) {
            if (!isset($grant[$key])) {
                continue;
            }
            $value = (int) $grant[$key];
            if ($value > 0 && $quantity > intdiv(PHP_INT_MAX, $value)) {
                throw new HttpException('兑换权益总量超过系统整数上限', 0, 422);
            }
            $result[$key] = $value * $quantity;
        }
        return $result;
    }

    private static function validateQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > 1000) {
            throw new HttpException('quantity 必须在 1-1000 之间', 0, 422);
        }
    }

    private static function nonNegativeInt($value, string $field, bool $allowLarge = false): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new HttpException($field . ' 必须是非负整数', 0, 422);
        }
        $number = (int) $value;
        if (!$allowLarge && $number > 1000000000) {
            throw new HttpException($field . ' 不能大于 1000000000', 0, 422);
        }
        return $number;
    }

    private static function dateTime($value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            throw new HttpException($field . ' 格式错误', 0, 422);
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private static function safeAdd(int $left, int $right, string $field): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new HttpException($field . ' 超过系统整数上限', 0, 422);
        }
        return $left + $right;
    }

    private static function upgradeMembershipLevel(string $current, string $candidate): string
    {
        $rank = ['trial' => 0, 'vip' => 10, 'svip' => 20, 'owner' => 100];
        if (isset($rank[$current], $rank[$candidate])) {
            return $rank[$candidate] > $rank[$current] ? $candidate : $current;
        }
        return in_array($current, ['', 'trial', 'expired'], true) ? $candidate : $current;
    }

    private static function orderNo(): string
    {
        do {
            $orderNo = 'EX' . date('YmdHis') . strtoupper(bin2hex(random_bytes(6)));
        } while (Database::one('SELECT id FROM admin_exchange_orders WHERE order_no = ?', [$orderNo]));
        return $orderNo;
    }

    private static function decodeArray($json): array
    {
        if (is_array($json)) {
            return $json;
        }
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
