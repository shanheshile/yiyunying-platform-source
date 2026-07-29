<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Password;
use Yiyunying\Core\Request;
use Yiyunying\Core\Token;

final class LoginCardService
{
    private const LOGIN_CARD_TYPES = ['login', 'login_card'];

    public static function firstLogin(Request $request, array $data): array
    {
        $app = AppService::byKey(trim((string) ($data['app_key'] ?? '')));
        self::bindAppContext($request, $app);
        AppService::requireFeature((int) $app['id'], 'user_account');
        if (!AppService::setting((int) $app['id'], 'card_login_enabled', true)) {
            throw new HttpException('当前应用已关闭登录卡密', 403, 403);
        }
        $cardCode = strtoupper(trim((string) ($data['card_code'] ?? '')));
        $deviceId = trim((string) ($data['device_id'] ?? ''));
        if ($cardCode === '' || $deviceId === '') {
            throw new HttpException('卡密和设备标识不能为空', 0, 422);
        }
        if (strlen($deviceId) > 200) {
            throw new HttpException('设备标识不能超过 200 个字节', 0, 422);
        }
        $deviceHash = self::deviceHash((int) $app['id'], $deviceId);
        $deviceSecret = Token::issue();
        $deviceLabel = mb_substr(trim((string) ($data['device_label'] ?? $data['device'] ?? '')), 0, 100);

        $result = Database::transaction(static function () use (
            $request, $app, $cardCode, $deviceHash, $deviceSecret, $deviceLabel
        ): array {
            $card = Database::one(
                'SELECT c.*, b.status AS batch_status
                 FROM cards c INNER JOIN card_batches b ON b.id = c.batch_id
                 WHERE c.admin_id = ? AND c.app_id = ? AND c.card_code = ? FOR UPDATE',
                [(int) $app['admin_id'], (int) $app['id'], $cardCode]
            );
            if ($card === null) {
                throw new HttpException('登录卡密不存在或不属于当前应用', 404, 404);
            }
            if (!in_array((string) $card['card_type'], self::LOGIN_CARD_TYPES, true)) {
                throw new HttpException('该卡密不是登录卡密，请在资产页面兑换', 0, 422);
            }
            if ((int) $card['status'] !== 1 || (int) $card['batch_status'] !== 1) {
                throw new HttpException('登录卡密已停用或已绑定', 0, 422);
            }
            if ($card['expired_at'] !== null && strtotime((string) $card['expired_at']) <= time()) {
                throw new HttpException('登录卡密已过期', 0, 422);
            }
            if (Database::one('SELECT id FROM card_login_bindings WHERE card_id = ? LIMIT 1 FOR UPDATE', [(int) $card['id']])) {
                throw new HttpException('该登录卡密已经绑定，请使用设备自动登录', 0, 409);
            }
            if (Database::one(
                'SELECT id FROM card_login_bindings WHERE app_id = ? AND device_hash = ? LIMIT 1 FOR UPDATE',
                [(int) $app['id'], $deviceHash]
            )) {
                throw new HttpException('当前设备已经绑定登录卡密，请使用设备自动登录', 0, 409);
            }

            $uid = IdentityService::generateUid();
            $account = self::cardAccount((int) $app['id'], $cardCode);
            $initialCredit = max(0, (int) AppService::setting((int) $app['id'], 'initial_document_credit', 20));
            $initialBalance = max(0, (float) AppService::setting((int) $app['id'], 'user_initial_balance', 0));
            $initialActivityCredit = max(0, (int) AppService::setting((int) $app['id'], 'user_initial_activity_credit', 0));
            $freeVipDays = max(0, (int) AppService::setting((int) $app['id'], 'user_free_vip_days', 0));
            $vipExpiredAt = $freeVipDays > 0 ? date('Y-m-d H:i:s', time() + $freeVipDays * 86400) : null;
            $publicProfile = AppService::setting((int) $app['id'], 'profile_public_default', true) ? 1 : 0;
            $userId = Database::insert(
                'INSERT INTO users
                 (uid, admin_id, app_id, account, password_hash, status, register_ip, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?, NOW(), NOW())',
                [
                    $uid, (int) $app['admin_id'], (int) $app['id'], $account,
                    Password::hash(Token::issue()), $request->clientIp(),
                ]
            );
            Database::execute(
                'INSERT INTO user_profiles
                 (admin_id, app_id, user_id, nickname, qq, avatar, background, signature, gender, title, public_profile, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    (int) $app['admin_id'], (int) $app['id'], $userId, '卡密用户' . substr($uid, -6),
                    '', '', '', '', '', '', $publicProfile,
                ]
            );
            Database::execute(
                'INSERT INTO user_wallets
                 (admin_id, app_id, user_id, integral, experience, balance, document_credit, vip_expired_at, level_code, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, NOW(), NOW())',
                [
                    (int) $app['admin_id'], (int) $app['id'], $userId,
                    $initialActivityCredit, $initialBalance, $initialCredit, $vipExpiredAt, 'normal',
                ]
            );
            Database::execute(
                'INSERT INTO user_message_preferences
                 (admin_id, app_id, user_id, accept_stranger_messages, system_notification_enabled,
                  private_notification_enabled, group_notification_enabled, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, 1, 1, NOW(), NOW())',
                [
                    (int) $app['admin_id'], (int) $app['id'], $userId,
                    AppService::setting((int) $app['id'], 'accept_stranger_messages_default', true) ? 1 : 0,
                ]
            );
            $rewards = json_decode((string) $card['value_json'], true);
            if (!is_array($rewards)) {
                throw new HttpException('登录卡密奖励配置损坏', -1, 500);
            }
            if ($rewards !== []) {
                WalletService::applyRewards([
                    'id' => $userId,
                    'admin_id' => (int) $app['admin_id'],
                    'app_id' => (int) $app['id'],
                ], $rewards, 'login_card_bind', 'card', (int) $card['id']);
            }
            $wallet = Database::one('SELECT vip_expired_at FROM user_wallets WHERE user_id = ? FOR UPDATE', [$userId]);
            if (AppService::setting((int) $app['id'], 'user_login_vip_only', false)
                && ($wallet === null || $wallet['vip_expired_at'] === null
                    || strtotime((string) $wallet['vip_expired_at']) <= time())) {
                throw new HttpException('当前应用仅允许有效会员登录，该登录卡密未包含有效会员时长', 403, 403);
            }
            $bindingId = Database::insert(
                'INSERT INTO card_login_bindings
                 (admin_id, app_id, card_id, user_id, device_hash, device_secret_hash, device_label,
                  status, bound_at, last_login_at, expired_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), ?, NOW(), NOW())',
                [
                    (int) $app['admin_id'], (int) $app['id'], (int) $card['id'], $userId,
                    $deviceHash, Token::hash($deviceSecret), $deviceLabel, $card['expired_at'],
                ]
            );
            Database::execute(
                'UPDATE cards SET used_count = max_use, status = 2, updated_at = NOW() WHERE id = ?',
                [(int) $card['id']]
            );
            Database::execute('UPDATE card_batches SET used_count = used_count + 1 WHERE id = ?', [(int) $card['batch_id']]);
            Database::execute(
                'INSERT INTO card_redeem_logs
                 (admin_id, app_id, card_id, user_id, reward_json, ip, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [
                    (int) $app['admin_id'], (int) $app['id'], (int) $card['id'], $userId,
                    json_encode($rewards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $request->clientIp(),
                ]
            );
            Database::execute('UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = ?', [$userId]);
            $session = UserSessionService::issue($request, $app, $userId, $deviceLabel === '' ? '登录卡密' : $deviceLabel);
            return array_merge($session, [
                'binding_id' => $bindingId,
                'device_secret' => $deviceSecret,
                'rewards' => $rewards,
            ]);
        });

        $user = UserSessionService::userData((int) $app['admin_id'], (int) $app['id'], (int) $result['user_id']);
        $request->setAttribute('actor_type', 'user');
        $request->setAttribute('actor_id', (int) $user['id']);
        LogService::userOperation($request, $user, 'card', 'login_bind', (int) $result['binding_id']);
        LogService::increment((int) $app['admin_id'], (int) $app['id'], 'new_users');
        LogService::increment((int) $app['admin_id'], (int) $app['id'], 'card_redeemed');
        $result['app_key'] = (string) $app['app_key'];
        $result['user'] = $user;
        return $result;
    }

    public static function autoLogin(Request $request, array $data): array
    {
        $app = AppService::byKey(trim((string) ($data['app_key'] ?? '')));
        self::bindAppContext($request, $app);
        if (!AppService::setting((int) $app['id'], 'card_login_enabled', true)) {
            throw new HttpException('当前应用已关闭登录卡密', 403, 403);
        }
        $deviceId = trim((string) ($data['device_id'] ?? ''));
        $deviceSecret = trim((string) ($data['device_secret'] ?? ''));
        if ($deviceId === '' || $deviceSecret === '') {
            throw new HttpException('设备标识和设备密钥不能为空', 0, 422);
        }
        $binding = Database::one(
            'SELECT b.*, c.status AS card_status, c.expired_at AS card_expired_at,
                    cb.status AS batch_status, u.status AS user_status
             FROM card_login_bindings b
             INNER JOIN cards c ON c.id = b.card_id AND c.app_id = b.app_id AND c.admin_id = b.admin_id
             INNER JOIN card_batches cb ON cb.id = c.batch_id
             INNER JOIN users u ON u.id = b.user_id AND u.app_id = b.app_id AND u.admin_id = b.admin_id
             WHERE b.app_id = ? AND b.device_hash = ? LIMIT 1',
            [(int) $app['id'], self::deviceHash((int) $app['id'], $deviceId)]
        );
        if ($binding === null || !hash_equals((string) $binding['device_secret_hash'], Token::hash($deviceSecret))) {
            throw new HttpException('设备未绑定或设备密钥错误', 401, 401);
        }
        if ((int) $binding['status'] !== 1 || (int) $binding['card_status'] === 0
            || (int) $binding['batch_status'] !== 1 || (int) $binding['user_status'] !== 1) {
            throw new HttpException('登录卡密、绑定设备或用户账号已被停用', 403, 403);
        }
        $expiredAt = $binding['expired_at'] ?? $binding['card_expired_at'];
        if ($expiredAt !== null && strtotime((string) $expiredAt) <= time()) {
            throw new HttpException('登录卡密已过期', 403, 403);
        }
        if (AppService::setting((int) $app['id'], 'user_login_vip_only', false)) {
            $wallet = Database::one('SELECT vip_expired_at FROM user_wallets WHERE user_id = ?', [(int) $binding['user_id']]);
            if ($wallet === null || $wallet['vip_expired_at'] === null
                || strtotime((string) $wallet['vip_expired_at']) <= time()) {
                throw new HttpException('当前应用仅允许有效会员登录，请先续费会员', 403, 403);
            }
        }
        Database::execute(
            'UPDATE card_login_bindings SET last_login_at = NOW(), updated_at = NOW() WHERE id = ?',
            [(int) $binding['id']]
        );
        Database::execute('UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = ?', [(int) $binding['user_id']]);
        $session = UserSessionService::issue(
            $request,
            $app,
            (int) $binding['user_id'],
            (string) ($binding['device_label'] ?: '登录卡密')
        );
        $session['app_key'] = (string) $app['app_key'];
        $session['binding_id'] = (int) $binding['id'];
        $session['user'] = UserSessionService::userData(
            (int) $app['admin_id'], (int) $app['id'], (int) $binding['user_id']
        );
        return $session;
    }

    private static function cardAccount(int $appId, string $cardCode): string
    {
        $base = 'card_' . substr(hash('sha256', $appId . ':' . $cardCode), 0, 20);
        $account = $base;
        $suffix = 0;
        while (Database::one('SELECT id FROM users WHERE app_id = ? AND account = ? LIMIT 1', [$appId, $account])) {
            $suffix++;
            $account = $base . '_' . $suffix;
        }
        return $account;
    }

    private static function deviceHash(int $appId, string $deviceId): string
    {
        return hash('sha256', $appId . "\0" . $deviceId);
    }

    private static function bindAppContext(Request $request, array $app): void
    {
        $request->setAttribute('admin_id', (int) $app['admin_id']);
        $request->setAttribute('app_id', (int) $app['id']);
    }
}
