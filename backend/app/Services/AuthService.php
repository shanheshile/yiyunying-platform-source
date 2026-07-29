<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Token;

final class AuthService
{
    public static function admin(Request $request): array
    {
        $plainToken = $request->bearerToken();
        if ($plainToken === null || $plainToken === '') {
            throw new HttpException('请先登录管理员账号', 401, 401);
        }

        $token = Database::one(
            'SELECT * FROM admin_tokens
             WHERE token_hash = ? AND revoked_at IS NULL AND expired_at > NOW()
             LIMIT 1',
            [Token::hash($plainToken)]
        );
        if ($token === null) {
            throw new HttpException('管理员令牌无效或已过期', 401, 401);
        }
        $admin = AdminAccessService::context((int) $token['admin_id']);
        $issuerPlatformId = $token['issued_by_platform_id'] === null ? null : (int) $token['issued_by_platform_id'];
        if ($issuerPlatformId !== null) {
            $issuer = PlatformService::byId($issuerPlatformId);
            PlatformService::ownedAdmin($issuer, (int) $admin['id']);
            $request->setAttribute('actor_type', 'platform_impersonation');
            $request->setAttribute('actor_id', $issuerPlatformId);
            $request->setAttribute('platform_id', $issuerPlatformId);
            $request->setAttribute('impersonated_admin_id', (int) $admin['id']);
            $request->setAttribute('access_mode', 'platform_override');
        } else {
            $state = AdminAccessService::assertDirectAccess($admin, $request->path());
            $request->setAttribute('actor_type', 'admin');
            $request->setAttribute('actor_id', (int) $admin['id']);
            $request->setAttribute('platform_id', (int) $admin['platform_id']);
            $request->setAttribute('access_mode', $state['mode']);
        }

        Database::execute('UPDATE admin_tokens SET last_used_at = NOW() WHERE id = ?', [(int) $token['id']]);
        $request->setAttribute('admin_id', (int) $admin['id']);
        $admin['token_id'] = (int) $token['id'];
        $admin['issued_by_platform_id'] = $issuerPlatformId;

        $requestedAppId = $request->attribute('requested_app_id');
        if ($requestedAppId !== null) {
            $ownedApp = Database::one(
                'SELECT id FROM apps WHERE id = ? AND admin_id = ? AND deleted_at IS NULL',
                [(int) $requestedAppId, (int) $admin['id']]
            );
            if ($ownedApp === null) {
                throw new HttpException('应用不存在或不属于当前管理员', 403, 403);
            }
            $request->setAttribute('app_id', (int) $requestedAppId);
        }
        return $admin;
    }

    public static function user(Request $request): array
    {
        $plainToken = $request->bearerToken();
        if ($plainToken === null || $plainToken === '') {
            throw new HttpException('请先登录用户账号', 401, 401);
        }

        $appKey = trim((string) ($request->header('x-app-key') ?? $request->input('app_key', '')));
        if ($appKey === '') {
            throw new HttpException('缺少 X-App-Key 请求头', 0, 400);
        }

        $user = Database::one(
            'SELECT u.*, a.app_key, a.status AS app_status, t.id AS token_id
             FROM user_tokens t
             INNER JOIN users u ON u.id = t.user_id AND u.app_id = t.app_id AND u.admin_id = t.admin_id
             INNER JOIN apps a ON a.id = u.app_id AND a.admin_id = u.admin_id
             WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expired_at > NOW()
               AND u.deleted_at IS NULL AND u.status = 1 AND a.deleted_at IS NULL AND a.status = 1
             LIMIT 1',
            [Token::hash($plainToken)]
        );
        if ($user === null) {
            throw new HttpException('用户令牌无效、账号停用或应用已停用', 401, 401);
        }
        if (!hash_equals((string) $user['app_key'], $appKey)) {
            throw new HttpException('用户令牌与应用不匹配', 403, 403);
        }
        $admin = AdminAccessService::context((int) $user['admin_id']);
        AdminAccessService::assertDownstreamAccess($admin);
        if (AppService::setting((int) $user['app_id'], 'user_login_vip_only', false)) {
            $wallet = Database::one('SELECT vip_expired_at FROM user_wallets WHERE user_id = ? AND app_id = ?', [
                (int) $user['id'], (int) $user['app_id'],
            ]);
            if ($wallet === null || $wallet['vip_expired_at'] === null
                || strtotime((string) $wallet['vip_expired_at']) <= time()) {
                throw new HttpException('当前应用仅允许有效会员使用，请先开通或续费会员', 403, 403, [
                    'reason_code' => 'vip_required',
                    'vip_expired_at' => $wallet['vip_expired_at'] ?? null,
                ]);
            }
        }

        Database::execute('UPDATE user_tokens SET last_used_at = NOW() WHERE id = ?', [(int) $user['token_id']]);
        $request->setAttribute('actor_type', 'user');
        $request->setAttribute('actor_id', (int) $user['id']);
        $request->setAttribute('admin_id', (int) $user['admin_id']);
        $request->setAttribute('app_id', (int) $user['app_id']);
        $request->setAttribute('platform_id', (int) $admin['platform_id']);
        return $user;
    }

    public static function revokeCurrentAdminToken(Request $request): void
    {
        $token = $request->bearerToken();
        if ($token !== null) {
            Database::execute('UPDATE admin_tokens SET revoked_at = NOW() WHERE token_hash = ?', [Token::hash($token)]);
        }
    }

    public static function revokeCurrentUserToken(Request $request): void
    {
        $token = $request->bearerToken();
        if ($token !== null) {
            $row = Database::one('SELECT id FROM user_tokens WHERE token_hash = ?', [Token::hash($token)]);
            Database::execute('UPDATE user_tokens SET revoked_at = NOW() WHERE token_hash = ?', [Token::hash($token)]);
            if ($row !== null) {
                Database::execute('UPDATE user_refresh_tokens SET revoked_at = NOW() WHERE user_token_id = ?', [(int) $row['id']]);
            }
        }
    }

    public static function ensureNotBanned(array $user, array $types): void
    {
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $params = [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']];
        foreach ($types as $type) {
            $params[] = $type;
        }
        $ban = Database::one(
            "SELECT id, ban_type, reason, end_at FROM user_bans
             WHERE admin_id = ? AND app_id = ? AND user_id = ? AND status = 1
               AND ban_type IN ({$placeholders}) AND (end_at IS NULL OR end_at > NOW())
             ORDER BY id DESC LIMIT 1",
            $params
        );
        if ($ban !== null) {
            throw new HttpException('当前功能已被限制：' . (string) $ban['reason'], 403, 403, [
                'ban_type' => $ban['ban_type'],
                'end_at' => $ban['end_at'],
            ]);
        }
    }
}
