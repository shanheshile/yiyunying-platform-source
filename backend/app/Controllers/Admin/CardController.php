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

final class CardController
{
    private const REWARD_KEYS = ['integral', 'experience', 'balance', 'document_credit', 'vip_days'];

    public static function batches(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM card_batches WHERE admin_id = ? AND app_id = ?',
            [(int) $admin['id'], $appId]
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT * FROM card_batches WHERE admin_id = ? AND app_id = ? ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            [(int) $admin['id'], $appId]
        );
        foreach ($items as &$item) {
            $item['value_json'] = self::decodeRewards((string) $item['value_json']);
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function createBatch(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $data = $request->all();
        Validator::required($data, ['name', 'total_count', 'value_json']);
        $name = Validator::string($data['name'], 'name', 2, 100);
        $total = Validator::integer($data['total_count'], 'total_count', 1, 1000);
        $maxUse = Validator::integer($data['max_use'] ?? 1, 'max_use', 1, 10000);
        $expiredAt = Validator::nullableDateTime($data['expired_at'] ?? null, 'expired_at');
        $rewards = is_string($data['value_json']) ? json_decode($data['value_json'], true) : $data['value_json'];
        $rewards = self::validateRewards($rewards);
        $cardType = mb_substr(trim((string) ($data['card_type'] ?? 'mixed')), 0, 30);
        if (!in_array($cardType, ['mixed', 'direct', 'login', 'login_card'], true)) {
            throw new HttpException('card_type 仅支持 mixed、direct 或 login', 0, 422);
        }
        if (in_array($cardType, ['login', 'login_card'], true)) {
            $maxUse = 1;
        }
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($data['prefix'] ?? 'YY')) ?: 'YY');
        $prefix = substr($prefix, 0, 8);

        $result = Database::transaction(static function () use (
            $admin,
            $appId,
            $name,
            $cardType,
            $rewards,
            $total,
            $maxUse,
            $expiredAt,
            $prefix
        ): array {
            $rewardJson = json_encode($rewards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $batchId = Database::insert(
                'INSERT INTO card_batches
                 (admin_id, app_id, name, card_type, value_json, total_count, used_count, max_use, status, expired_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 0, ?, 1, ?, NOW())',
                [(int) $admin['id'], $appId, $name, $cardType, $rewardJson, $total, $maxUse, $expiredAt]
            );
            $codes = [];
            for ($index = 0; $index < $total; $index++) {
                $code = self::generateCode($prefix);
                Database::execute(
                    'INSERT INTO cards
                     (admin_id, app_id, batch_id, card_code, card_type, value_json, max_use, used_count, status, expired_at, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1, ?, NOW())',
                    [(int) $admin['id'], $appId, $batchId, $code, $cardType, $rewardJson, $maxUse, $expiredAt]
                );
                $codes[] = $code;
            }
            return ['batch_id' => $batchId, 'codes' => $codes];
        });

        LogService::adminOperation(
            $request,
            (int) $admin['id'],
            $appId,
            'card',
            'create_batch',
            (int) $result['batch_id'],
            null,
            ['name' => $name, 'total_count' => $total, 'rewards' => $rewards]
        );
        return Response::success($result, '卡密生成成功', 201);
    }

    public static function cards(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['c.admin_id = ?', 'c.app_id = ?'];
        $queryParams = [(int) $admin['id'], $appId];
        if ($request->input('batch_id') !== null && $request->input('batch_id') !== '') {
            $where[] = 'c.batch_id = ?';
            $queryParams[] = (int) $request->input('batch_id');
        }
        if ($request->input('status') !== null && $request->input('status') !== '') {
            $where[] = 'c.status = ?';
            $queryParams[] = (int) $request->input('status');
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM cards c WHERE {$whereSql}", $queryParams)['total'] ?? 0);
        $items = Database::all(
            "SELECT c.*, b.name AS batch_name FROM cards c
             INNER JOIN card_batches b ON b.id = c.batch_id
             WHERE {$whereSql} ORDER BY c.id DESC LIMIT {$limit} OFFSET {$offset}",
            $queryParams
        );
        foreach ($items as &$item) {
            $item['value_json'] = self::decodeRewards((string) $item['value_json']);
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function updateCard(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $status = Validator::integer($request->input('status'), 'status', 0, 1);
        $card = Database::one(
            'SELECT * FROM cards WHERE id = ? AND admin_id = ? AND app_id = ?',
            [(int) $params['card_id'], (int) $admin['id'], $appId]
        );
        if ($card === null) {
            throw new HttpException('卡密不存在', 404, 404);
        }
        if ($status === 1 && (int) $card['used_count'] >= (int) $card['max_use']) {
            throw new HttpException('卡密使用次数已耗尽，不能重新启用', 0, 422);
        }
        Database::execute(
            'UPDATE cards SET status = ?, updated_at = NOW() WHERE id = ? AND admin_id = ? AND app_id = ?',
            [$status, (int) $card['id'], (int) $admin['id'], $appId]
        );
        LogService::adminOperation(
            $request,
            (int) $admin['id'],
            $appId,
            'card',
            $status === 1 ? 'enable' : 'disable',
            (int) $card['id'],
            ['status' => (int) $card['status']],
            ['status' => $status]
        );
        return Response::success(['card_id' => (int) $card['id'], 'status' => $status], '卡密状态已更新');
    }

    public static function redeemLogs(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['l.admin_id = ?', 'l.app_id = ?'];
        $query = [(int) $admin['id'], $appId];
        if ((int) $request->input('user_id', 0) > 0) {
            $where[] = 'l.user_id = ?';
            $query[] = (int) $request->input('user_id');
        }
        if ((int) $request->input('batch_id', 0) > 0) {
            $where[] = 'c.batch_id = ?';
            $query[] = (int) $request->input('batch_id');
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM card_redeem_logs l INNER JOIN cards c ON c.id = l.card_id WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT l.*, c.card_code, c.batch_id, u.account, p.nickname
             FROM card_redeem_logs l INNER JOIN cards c ON c.id = l.card_id
             INNER JOIN users u ON u.id = l.user_id LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE {$whereSql} ORDER BY l.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        foreach ($items as &$item) {
            $item['reward_json'] = self::decodeRewards((string) $item['reward_json']);
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function loginBindings(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['b.admin_id = ?', 'b.app_id = ?'];
        $query = [(int) $admin['id'], $appId];
        if ($request->input('status') !== null && $request->input('status') !== '') {
            $where[] = 'b.status = ?';
            $query[] = (int) $request->input('status');
        }
        if (trim((string) $request->input('keyword', '')) !== '') {
            $where[] = '(u.account LIKE ? OR u.uid LIKE ? OR p.nickname LIKE ? OR c.card_code LIKE ? OR b.device_label LIKE ?)';
            $keyword = '%' . trim((string) $request->input('keyword')) . '%';
            array_push($query, $keyword, $keyword, $keyword, $keyword, $keyword);
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM card_login_bindings b
             INNER JOIN cards c ON c.id = b.card_id
             INNER JOIN users u ON u.id = b.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT b.id, b.card_id, b.user_id, b.device_label, b.status, b.bound_at,
                    b.last_login_at, b.expired_at, c.card_code, u.uid, u.account, u.status AS user_status,
                    p.nickname, p.avatar
             FROM card_login_bindings b
             INNER JOIN cards c ON c.id = b.card_id
             INNER JOIN users u ON u.id = b.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE {$whereSql} ORDER BY b.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        foreach ($items as &$item) {
            $item['status_name'] = (int) $item['status'] === 1 ? '绑定有效' : '绑定停用';
            $item['user_status_name'] = (int) $item['user_status'] === 1 ? '用户正常' : '用户不可用';
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function updateBatch(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $batch = Database::one('SELECT * FROM card_batches WHERE id = ? AND admin_id = ? AND app_id = ?', [
            (int) $params['batch_id'], (int) $admin['id'], $appId,
        ]);
        if ($batch === null) {
            throw new HttpException('卡密批次不存在', 404, 404);
        }
        $status = Validator::integer($request->input('status'), 'status', 0, 1);
        Database::transaction(static function () use ($batch, $status): void {
            Database::execute('UPDATE card_batches SET status = ?, updated_at = NOW() WHERE id = ?', [$status, (int) $batch['id']]);
            if ($status === 0) {
                Database::execute('UPDATE cards SET status = 0, updated_at = NOW() WHERE batch_id = ?', [(int) $batch['id']]);
            }
        });
        return Response::success(['batch_id' => (int) $batch['id'], 'status' => $status], '卡密批次状态已更新');
    }

    private static function validateRewards($rewards): array
    {
        if (!is_array($rewards) || $rewards === []) {
            throw new HttpException('value_json 必须是非空奖励对象', 0, 422);
        }
        $clean = [];
        foreach ($rewards as $key => $value) {
            if (!in_array($key, self::REWARD_KEYS, true)) {
                throw new HttpException('不支持的奖励字段：' . (string) $key, 0, 422);
            }
            if (!is_numeric($value) || (float) $value <= 0) {
                throw new HttpException("奖励 {$key} 必须大于 0", 0, 422);
            }
            $clean[$key] = $key === 'balance' ? round((float) $value, 2) : (int) $value;
        }
        return $clean;
    }

    private static function decodeRewards(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function generateCode(string $prefix): string
    {
        $raw = strtoupper(bin2hex(random_bytes(8)));
        return $prefix . '-' . substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4) . '-' . substr($raw, 12, 4);
    }
}
