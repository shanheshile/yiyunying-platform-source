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
use Yiyunying\Services\LoginCardService;

final class CardController
{
    public static function login(Request $request): \Yiyunying\Core\ApiResponse
    {
        $data = $request->all();
        Validator::required($data, ['app_key', 'card_code', 'device_id']);
        return Response::success(LoginCardService::firstLogin($request, $data), '登录卡密绑定并登录成功');
    }

    public static function autoLogin(Request $request): \Yiyunying\Core\ApiResponse
    {
        $data = $request->all();
        Validator::required($data, ['app_key', 'device_id', 'device_secret']);
        return Response::success(LoginCardService::autoLogin($request, $data), '设备自动登录成功');
    }

    public static function redeem(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        if (!AppService::setting((int) $user['app_id'], 'card_redeem_enabled', true)) {
            throw new HttpException('当前应用已关闭卡密兑换', 403, 403);
        }
        $data = $request->all();
        Validator::required($data, ['card_code']);
        $cardCode = strtoupper(trim((string) $data['card_code']));
        $adminId = (int) $user['admin_id'];
        $appId = (int) $user['app_id'];
        $userId = (int) $user['id'];
        $ip = $request->clientIp();

        $result = Database::transaction(static function () use ($adminId, $appId, $userId, $cardCode, $ip): array {
            $card = Database::one(
                'SELECT c.*, b.status AS batch_status
                 FROM cards c INNER JOIN card_batches b ON b.id = c.batch_id
                 WHERE c.admin_id = ? AND c.app_id = ? AND c.card_code = ? FOR UPDATE',
                [$adminId, $appId, $cardCode]
            );
            if ($card === null) {
                throw new HttpException('卡密不存在或不属于当前应用', 404, 404);
            }
            if (in_array((string) $card['card_type'], ['login', 'login_card'], true)) {
                throw new HttpException('登录卡密不能兑换，请在登录页面使用', 0, 422);
            }
            if ((int) $card['status'] !== 1 || (int) $card['batch_status'] !== 1) {
                throw new HttpException('卡密已停用或已耗尽', 0, 422);
            }
            if ($card['expired_at'] !== null && strtotime((string) $card['expired_at']) < time()) {
                throw new HttpException('卡密已过期', 0, 422);
            }
            if ((int) $card['used_count'] >= (int) $card['max_use']) {
                throw new HttpException('卡密使用次数已耗尽', 0, 422);
            }
            if (Database::one('SELECT id FROM card_redeem_logs WHERE card_id = ? AND user_id = ?', [(int) $card['id'], $userId])) {
                throw new HttpException('当前用户已经兑换过该卡密', 0, 409);
            }
            $rewards = json_decode((string) $card['value_json'], true);
            if (!is_array($rewards) || $rewards === []) {
                throw new HttpException('卡密奖励配置损坏', -1, 500);
            }
            $wallet = Database::one(
                'SELECT * FROM user_wallets WHERE admin_id = ? AND app_id = ? AND user_id = ? FOR UPDATE',
                [$adminId, $appId, $userId]
            );
            if ($wallet === null) {
                throw new HttpException('用户资产账户不存在', -1, 500);
            }

            $before = [
                'integral' => (int) $wallet['integral'],
                'experience' => (int) $wallet['experience'],
                'balance' => (float) $wallet['balance'],
                'document_credit' => (int) $wallet['document_credit'],
                'vip_expired_at' => $wallet['vip_expired_at'],
            ];
            $after = $before;
            foreach (['integral', 'experience', 'document_credit'] as $asset) {
                if (isset($rewards[$asset])) {
                    $after[$asset] += max(0, (int) $rewards[$asset]);
                }
            }
            if (isset($rewards['balance'])) {
                $after['balance'] = round($after['balance'] + max(0, (float) $rewards['balance']), 2);
            }
            if (isset($rewards['vip_days'])) {
                $base = $before['vip_expired_at'] === null ? time() : max(time(), strtotime((string) $before['vip_expired_at']));
                $after['vip_expired_at'] = date('Y-m-d H:i:s', $base + max(0, (int) $rewards['vip_days']) * 86400);
            }

            Database::execute(
                'UPDATE user_wallets SET integral = ?, experience = ?, balance = ?, document_credit = ?,
                 vip_expired_at = ?, updated_at = NOW()
                 WHERE admin_id = ? AND app_id = ? AND user_id = ?',
                [
                    $after['integral'], $after['experience'], $after['balance'], $after['document_credit'],
                    $after['vip_expired_at'], $adminId, $appId, $userId,
                ]
            );
            foreach (['integral', 'experience', 'balance', 'document_credit'] as $asset) {
                if (!isset($rewards[$asset])) {
                    continue;
                }
                Database::execute(
                    'INSERT INTO user_wallet_logs
                     (admin_id, app_id, user_id, asset_type, change_value, before_value, after_value,
                      scene, ref_type, ref_id, remark, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [
                        $adminId, $appId, $userId, $asset, $rewards[$asset], $before[$asset], $after[$asset],
                        'card_redeem', 'card', (int) $card['id'], '卡密兑换',
                    ]
                );
            }
            if (isset($rewards['vip_days'])) {
                Database::execute(
                    'INSERT INTO user_wallet_logs
                     (admin_id, app_id, user_id, asset_type, change_value, before_value, after_value,
                      scene, ref_type, ref_id, remark, created_at)
                     VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, NOW())',
                    [
                        $adminId, $appId, $userId, 'vip_days', (int) $rewards['vip_days'],
                        (int) $rewards['vip_days'], 'card_redeem', 'card', (int) $card['id'],
                        '会员到期：' . $after['vip_expired_at'],
                    ]
                );
            }

            $newUsedCount = (int) $card['used_count'] + 1;
            $newStatus = $newUsedCount >= (int) $card['max_use'] ? 2 : 1;
            Database::execute(
                'UPDATE cards SET used_count = ?, status = ?, updated_at = NOW() WHERE id = ?',
                [$newUsedCount, $newStatus, (int) $card['id']]
            );
            Database::execute('UPDATE card_batches SET used_count = used_count + 1 WHERE id = ?', [(int) $card['batch_id']]);
            $redeemId = Database::insert(
                'INSERT INTO card_redeem_logs
                 (admin_id, app_id, card_id, user_id, reward_json, ip, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [
                    $adminId, $appId, (int) $card['id'], $userId,
                    json_encode($rewards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $ip,
                ]
            );
            return [
                'redeem_id' => $redeemId,
                'card_id' => (int) $card['id'],
                'rewards' => $rewards,
                'wallet' => $after,
            ];
        });

        LogService::userOperation($request, $user, 'card', 'redeem', (int) $result['card_id'], [
            'rewards' => $result['rewards'],
        ]);
        LogService::increment($adminId, $appId, 'card_redeemed');
        unset($result['card_id']);
        return Response::success($result, '卡密兑换成功');
    }

    public static function logs(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $queryParams = [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']];
        $total = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM card_redeem_logs WHERE admin_id = ? AND app_id = ? AND user_id = ?',
            $queryParams
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT l.id, l.card_id, c.card_code, l.reward_json, l.created_at
             FROM card_redeem_logs l INNER JOIN cards c ON c.id = l.card_id
             WHERE l.admin_id = ? AND l.app_id = ? AND l.user_id = ?
             ORDER BY l.id DESC LIMIT {$limit} OFFSET {$offset}",
            $queryParams
        );
        foreach ($items as &$item) {
            $decoded = json_decode((string) $item['reward_json'], true);
            $item['rewards'] = is_array($decoded) ? $decoded : [];
            unset($item['reward_json']);
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }
}
