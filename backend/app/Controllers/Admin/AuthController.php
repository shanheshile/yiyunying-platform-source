<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Password;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Token;
use Yiyunying\Core\Validator;
use Yiyunying\Services\AdminAccessService;
use Yiyunying\Services\AdminProvisionService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\ProfileAvatarService;
use Yiyunying\Services\PlatformService;
use Yiyunying\Services\RolePermissionService;

final class AuthController
{
    public static function register(Request $request): \Yiyunying\Core\ApiResponse
    {
        $data = $request->all();
        $platform = PlatformService::byKey((string) ($data['platform_key'] ?? ''));
        $account = trim((string) ($data['account'] ?? ''));
        $request->setAttribute('platform_id', (int) $platform['id']);
        try {
            $admin = AdminProvisionService::publicProvision($platform, $data, $request);
        } catch (\Throwable $exception) {
            AdminProvisionService::writeRegistrationLog($platform, $request, $account, false, $exception->getMessage());
            throw $exception;
        }
        return Response::success([
            'admin' => self::publicAdmin($admin),
            'platform' => [
                'id' => (int) $platform['id'],
                'level' => (int) $platform['level'],
                'platform_key' => $platform['platform_key'],
                'nickname' => $platform['nickname'],
            ],
            'registration_gift' => $admin['registration_gift'],
        ], 'admin 注册成功', 201);
    }

    public static function login(Request $request): \Yiyunying\Core\ApiResponse
    {
        $data = $request->all();
        Validator::required($data, ['account', 'password']);
        $platform = PlatformService::byKey((string) ($data['platform_key'] ?? ''));
        $account = Validator::string($data['account'], 'account', 3, 64);
        $password = (string) $data['password'];
        $rawAdmin = Database::one(
            'SELECT * FROM admins WHERE platform_id = ? AND account = ? LIMIT 1',
            [(int) $platform['id'], $account]
        );

        if ($rawAdmin === null || !Password::verify($password, (string) $rawAdmin['password_hash'])) {
            self::writeLoginLog((int) $platform['id'], $rawAdmin === null ? null : (int) $rawAdmin['id'], $account, $request, false, '账号或密码错误');
            PlatformService::increment((int) $platform['id'], 'admin_login_failed');
            throw new HttpException('账号或密码错误', 401, 401);
        }
        $admin = AdminAccessService::context((int) $rawAdmin['id']);
        try {
            $accessState = AdminAccessService::assertLoginAllowed($admin);
        } catch (\Throwable $exception) {
            self::writeLoginLog((int) $platform['id'], (int) $admin['id'], $account, $request, false, $exception->getMessage());
            PlatformService::increment((int) $platform['id'], 'admin_login_failed');
            throw $exception;
        }

        if (Password::needsRehash((string) $admin['password_hash'])) {
            Database::execute('UPDATE admins SET password_hash = ?, updated_at = NOW() WHERE id = ?', [
                Password::hash($password),
                (int) $admin['id'],
            ]);
        }

        $plainToken = Token::issue();
        $ttl = (int) config('security.admin_token_ttl', 86400);
        $expiredAt = date('Y-m-d H:i:s', time() + $ttl);
        Database::execute(
            'INSERT INTO admin_tokens
             (admin_id, issued_by_platform_id, token_type, token_hash, device, ip, user_agent, expired_at, created_at, last_used_at)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (int) $admin['id'],
                'direct',
                Token::hash($plainToken),
                mb_substr((string) ($data['device'] ?? ''), 0, 100),
                $request->clientIp(),
                $request->userAgent(),
                $expiredAt,
            ]
        );
        Database::execute(
            'UPDATE admins SET last_login_ip = ?, last_login_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$request->clientIp(), (int) $admin['id']]
        );
        self::writeLoginLog((int) $platform['id'], (int) $admin['id'], $account, $request, true, '登录成功');
        PlatformService::increment((int) $platform['id'], 'admin_login_success');

        $request->setAttribute('actor_type', 'admin');
        $request->setAttribute('actor_id', (int) $admin['id']);
        $request->setAttribute('admin_id', (int) $admin['id']);
        $request->setAttribute('platform_id', (int) $platform['id']);
        $request->setAttribute('access_mode', $accessState['mode']);

        return Response::success([
            'token_type' => 'Bearer',
            'access_token' => $plainToken,
            'expires_at' => $expiredAt,
            'admin' => self::publicAdmin($admin),
            'access' => $accessState,
        ], $accessState['mode'] === 'full' ? '登录成功' : '登录成功，当前仅可续费或查看权益');
    }

    public static function logout(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        AuthService::revokeCurrentAdminToken($request);
        LogService::adminOperation($request, (int) $admin['id'], null, 'auth', 'logout');
        return Response::success([], '已退出登录');
    }

    public static function me(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        return Response::success(['admin' => self::publicAdmin($admin)]);
    }

    public static function permissions(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        return Response::success(RolePermissionService::adminPayload($admin, 3, false));
    }

    public static function profile(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
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
            throw new HttpException('没有可修改的字段', 0, 422);
        }
        $values[] = (int) $admin['id'];
        Database::execute('UPDATE admins SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ?', $values);
        $after = AdminAccessService::context((int) $admin['id']);
        LogService::adminOperation($request, (int) $admin['id'], null, 'admin', 'profile_update', (int) $admin['id']);
        return Response::success(['admin' => self::publicAdmin($after ?? $admin)], '资料修改成功');
    }

    public static function password(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $data = $request->all();
        Validator::required($data, ['old_password', 'new_password']);
        if (!Password::verify((string) $data['old_password'], (string) $admin['password_hash'])) {
            throw new HttpException('原密码错误', 0, 422);
        }
        $newPassword = (string) $data['new_password'];
        $min = (int) config('security.password_min_length', 6);
        if (strlen($newPassword) < $min || strlen($newPassword) > 72) {
            throw new HttpException("新密码长度必须在 {$min}-72 个字节之间", 0, 422);
        }
        Database::transaction(static function () use ($admin, $newPassword): void {
            Database::execute('UPDATE admins SET password_hash = ?, updated_at = NOW() WHERE id = ?', [
                Password::hash($newPassword),
                (int) $admin['id'],
            ]);
            Database::execute('UPDATE admin_tokens SET revoked_at = NOW() WHERE admin_id = ? AND revoked_at IS NULL', [
                (int) $admin['id'],
            ]);
        });
        LogService::adminOperation($request, (int) $admin['id'], null, 'admin', 'password_change', (int) $admin['id']);
        return Response::success([], '密码修改成功，请重新登录');
    }

    public static function avatar(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $result = ProfileAvatarService::upload('admin', (int) $admin['id']);
        Database::execute('UPDATE admins SET avatar = ?, updated_at = NOW() WHERE id = ?', [$result['avatar'], (int) $admin['id']]);
        LogService::adminOperation($request, (int) $admin['id'], null, 'admin', 'avatar_update', (int) $admin['id']);
        return Response::success($result, '管理员头像上传成功', 201);
    }

    public static function loginLogs(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one('SELECT COUNT(*) AS total FROM admin_login_logs WHERE admin_id = ?', [
            (int) $admin['id'],
        ])['total'] ?? 0);
        $items = Database::all(
            "SELECT id, ip, user_agent, result, reason, created_at FROM admin_login_logs
             WHERE admin_id = ? ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            [(int) $admin['id']]
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    private static function publicAdmin(array $admin): array
    {
        return [
            'id' => (int) $admin['id'],
            'account' => $admin['account'],
            'nickname' => $admin['nickname'],
            'avatar' => $admin['avatar'],
            'email' => $admin['email'],
            'phone' => $admin['phone'],
            'status' => (int) $admin['status'],
            'last_login_ip' => $admin['last_login_ip'],
            'last_login_at' => $admin['last_login_at'],
            'created_at' => $admin['created_at'],
            'platform_id' => (int) ($admin['platform_id'] ?? 0),
            'platform_key' => $admin['platform_key'] ?? null,
            'membership_level' => $admin['membership_level'] ?? null,
            'membership_status' => $admin['membership_status'] ?? null,
            'membership_expired_at' => $admin['membership_expired_at'] ?? null,
            'app_quota' => isset($admin['app_quota']) ? (int) $admin['app_quota'] : null,
            'remote_document_quota' => isset($admin['remote_document_quota']) ? (int) $admin['remote_document_quota'] : null,
            'balance' => isset($admin['admin_integral']) ? (int) $admin['admin_integral'] : null,
            'access' => isset($admin['membership_expired_at']) ? AdminAccessService::accessState($admin) : null,
        ];
    }

    private static function writeLoginLog(
        int $platformId,
        ?int $adminId,
        string $account,
        Request $request,
        bool $success,
        string $reason
    ): void
    {
        Database::execute(
            'INSERT INTO admin_login_logs (platform_id, admin_id, account, ip, user_agent, result, reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [$platformId, $adminId, $account, $request->clientIp(), $request->userAgent(), $success ? 1 : 0, $reason]
        );
    }
}
