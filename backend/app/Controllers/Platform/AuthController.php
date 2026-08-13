<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Platform;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Password;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Token;
use Yiyunying\Core\Validator;
use Yiyunying\Services\PlatformService;
use Yiyunying\Services\ProfileAvatarService;
use Yiyunying\Services\RolePermissionService;
use Yiyunying\Services\LoginAttemptService;

final class AuthController
{
    public static function login(Request $request): \Yiyunying\Core\ApiResponse
    {
        $data = $request->all();
        Validator::required($data, ['platform_key', 'account', 'password']);
        $account = Validator::string($data['account'], 'account', 3, 64);
        $platformKey = trim((string) $data['platform_key']);
        $platform = Database::one(
            'SELECT * FROM platform_accounts WHERE account = ? AND deleted_at IS NULL LIMIT 1',
            [$account]
        );
        LoginAttemptService::assertPlatformAllowed($account, $request->clientIp());
        if ($platform === null
            || !hash_equals((string) $platform['platform_key'], $platformKey)
            || !Password::verify((string) $data['password'], (string) $platform['password_hash'])) {
            self::writeLoginLog($platform, $account, $request, false, '账号或密码错误');
            throw new HttpException('平台标识、账号或密码错误', 401, 401);
        }
        try {
            PlatformService::assertActive($platform);
        } catch (\Throwable $exception) {
            self::writeLoginLog($platform, $account, $request, false, $exception->getMessage());
            throw $exception;
        }
        if (Password::needsRehash((string) $platform['password_hash'])) {
            Database::execute('UPDATE platform_accounts SET password_hash = ?, updated_at = NOW() WHERE id = ?', [
                Password::hash((string) $data['password']), (int) $platform['id'],
            ]);
        }
        $plain = Token::issue();
        $expiredAt = date('Y-m-d H:i:s', time() + (int) config('security.platform_token_ttl', 86400));
        Database::execute(
            'INSERT INTO platform_tokens
             (platform_id, token_hash, device, ip, user_agent, expired_at, created_at, last_used_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (int) $platform['id'], Token::hash($plain),
                mb_substr((string) ($data['device'] ?? ''), 0, 100),
                $request->clientIp(), $request->userAgent(), $expiredAt,
            ]
        );
        Database::execute(
            'UPDATE platform_accounts SET last_login_ip = ?, last_login_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$request->clientIp(), (int) $platform['id']]
        );
        self::writeLoginLog($platform, $account, $request, true, '登录成功');
        return Response::success([
            'token_type' => 'Bearer',
            'access_token' => $plain,
            'expires_at' => $expiredAt,
            'platform' => PlatformService::publicData($platform),
        ], '平台登录成功');
    }

    public static function logout(Request $request): \Yiyunying\Core\ApiResponse
    {
        $platform = PlatformService::auth($request);
        $token = $request->bearerToken();
        if ($token !== null) {
            Database::execute('UPDATE platform_tokens SET revoked_at = NOW() WHERE token_hash = ?', [Token::hash($token)]);
        }
        PlatformService::log($request, $platform, 'auth', 'logout');
        return Response::success([], '已退出平台');
    }

    public static function me(Request $request): \Yiyunying\Core\ApiResponse
    {
        $platform = PlatformService::auth($request);
        return Response::success([
            'platform' => PlatformService::publicData($platform),
            'settings' => PlatformService::settings((int) $platform['id']),
            'chat_polling_policy' => PlatformService::chatPollingPolicy((int) $platform['id']),
            'message_recall_policy' => PlatformService::messageRecallPolicy((int) $platform['id']),
        ]);
    }

    public static function permissions(Request $request): \Yiyunying\Core\ApiResponse
    {
        $platform = PlatformService::auth($request);
        $level = (int) ($platform['level'] ?? 2);
        $payload = $level === 1
            ? RolePermissionService::ownerPayload($platform)
            : RolePermissionService::platformPayload($platform, $level, false);
        return Response::success($payload);
    }

    public static function profile(Request $request): \Yiyunying\Core\ApiResponse
    {
        $platform = PlatformService::auth($request);
        $data = $request->all();
        $fields = [];
        $values = [];
        foreach (['nickname' => 100, 'avatar' => 500, 'phone' => 40] as $field => $max) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = mb_substr(trim((string) $data[$field]), 0, $max);
            }
        }
        if (array_key_exists('email', $data)) {
            $email = trim((string) $data['email']);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new HttpException('email 格式错误', 0, 422);
            }
            $fields[] = 'email = ?';
            $values[] = $email === '' ? null : $email;
        }
        if ($fields === []) {
            throw new HttpException('没有可修改字段', 0, 422);
        }
        $values[] = (int) $platform['id'];
        Database::execute('UPDATE platform_accounts SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ?', $values);
        $after = Database::one('SELECT * FROM platform_accounts WHERE id = ?', [(int) $platform['id']]) ?? $platform;
        PlatformService::log($request, $platform, 'platform', 'profile_update', 'platform', (int) $platform['id']);
        return Response::success(['platform' => PlatformService::publicData($after)], '平台资料已更新');
    }

    public static function password(Request $request): \Yiyunying\Core\ApiResponse
    {
        $platform = PlatformService::auth($request);
        $data = $request->all();
        Validator::required($data, ['old_password', 'new_password']);
        if (!Password::verify((string) $data['old_password'], (string) $platform['password_hash'])) {
            throw new HttpException('原密码错误', 0, 422);
        }
        $new = Password::assertAcceptable((string) $data['new_password'], 'new_password');
        Database::transaction(static function () use ($platform, $new): void {
            Database::execute('UPDATE platform_accounts SET password_hash = ?, updated_at = NOW() WHERE id = ?', [
                Password::hash($new), (int) $platform['id'],
            ]);
            Database::execute('UPDATE platform_tokens SET revoked_at = NOW() WHERE platform_id = ?', [(int) $platform['id']]);
        });
        return Response::success([], '平台密码已修改，请重新登录');
    }

    public static function avatar(Request $request): \Yiyunying\Core\ApiResponse
    {
        $platform = PlatformService::auth($request);
        $result = ProfileAvatarService::upload('platform', (int) $platform['id']);
        Database::execute('UPDATE platform_accounts SET avatar = ?, updated_at = NOW() WHERE id = ?', [$result['avatar'], (int) $platform['id']]);
        PlatformService::log($request, $platform, 'platform', 'avatar_update', 'platform', (int) $platform['id']);
        return Response::success($result, '平台头像上传成功', 201);
    }

    public static function loginLogs(Request $request): \Yiyunying\Core\ApiResponse
    {
        $platform = PlatformService::auth($request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one('SELECT COUNT(*) AS total FROM platform_login_logs WHERE platform_id = ?', [
            (int) $platform['id'],
        ])['total'] ?? 0);
        $items = Database::all(
            "SELECT id, account, ip, user_agent, result, reason, created_at FROM platform_login_logs
             WHERE platform_id = ? ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            [(int) $platform['id']]
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    private static function writeLoginLog(?array $platform, string $account, Request $request, bool $success, string $reason): void
    {
        Database::execute(
            'INSERT INTO platform_login_logs
             (platform_id, account, ip, user_agent, result, reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [
                $platform === null ? null : (int) $platform['id'], $account, $request->clientIp(),
                $request->userAgent(), $success ? 1 : 0, mb_substr($reason, 0, 255),
            ]
        );
    }
}
