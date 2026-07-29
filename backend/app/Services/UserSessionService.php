<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Token;

final class UserSessionService
{
    public static function issue(Request $request, array $app, int $userId, string $device): array
    {
        $plainToken = Token::issue();
        $refreshToken = Token::issue();
        $expiredAt = date('Y-m-d H:i:s', time() + (int) config('security.user_token_ttl', 2592000));
        $tokenId = Database::insert(
            'INSERT INTO user_tokens
             (admin_id, app_id, user_id, token_hash, device, ip, user_agent, expired_at, created_at, last_used_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (int) $app['admin_id'],
                (int) $app['id'],
                $userId,
                Token::hash($plainToken),
                mb_substr(trim($device), 0, 100),
                $request->clientIp(),
                $request->userAgent(),
                $expiredAt,
            ]
        );
        $refreshExpiredAt = date('Y-m-d H:i:s', time() + (int) config('security.user_refresh_token_ttl', 7776000));
        Database::execute(
            'INSERT INTO user_refresh_tokens
             (admin_id, app_id, user_id, user_token_id, token_hash, expired_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [
                (int) $app['admin_id'], (int) $app['id'], $userId, $tokenId,
                Token::hash($refreshToken), $refreshExpiredAt,
            ]
        );

        return [
            'token_type' => 'Bearer',
            'user_id' => $userId,
            'access_token' => $plainToken,
            'refresh_token' => $refreshToken,
            'expires_at' => $expiredAt,
            'refresh_expires_at' => $refreshExpiredAt,
        ];
    }

    public static function userData(int $adminId, int $appId, int $userId): array
    {
        $user = Database::one(
            'SELECT u.id, u.uid, u.admin_id, u.app_id, u.account, u.email, u.phone, u.status, u.last_login_at, u.created_at,
                    p.nickname, p.qq, p.avatar, p.background, p.signature, p.gender, p.birthday,
                    p.title, p.public_profile, w.integral, w.experience, w.balance,
                    w.document_credit, w.vip_expired_at, w.level_code
             FROM users u
             INNER JOIN user_profiles p ON p.user_id = u.id AND p.app_id = u.app_id AND p.admin_id = u.admin_id
             INNER JOIN user_wallets w ON w.user_id = u.id AND w.app_id = u.app_id AND w.admin_id = u.admin_id
             WHERE u.id = ? AND u.admin_id = ? AND u.app_id = ? AND u.deleted_at IS NULL',
            [$userId, $adminId, $appId]
        );
        if ($user === null) {
            throw new HttpException('用户不存在', 404, 404);
        }
        $user['id'] = (int) $user['id'];
        $user['admin_id'] = (int) $user['admin_id'];
        $user['app_id'] = (int) $user['app_id'];
        $user['status'] = (int) $user['status'];
        $user['activity_credit'] = (int) $user['integral'];
        unset($user['integral']);
        $user['balance'] = (float) $user['balance'];
        $user['primary_asset'] = 'balance';
        $user['experience'] = (int) $user['experience'];
        $user['document_credit'] = (int) $user['document_credit'];
        $user['public_profile'] = (bool) $user['public_profile'];
        return $user;
    }
}
