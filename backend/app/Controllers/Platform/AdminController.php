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
use Yiyunying\Services\AdminAccessService;
use Yiyunying\Services\AdminProvisionService;
use Yiyunying\Services\PlatformService;
use Yiyunying\Services\RolePermissionService;
use Yiyunying\Services\EntitlementDurationService;

final class AdminController
{
    private const PERMISSIONS = [
        'apps.manage', 'users.manage', 'documents.manage', 'content.manage',
        'resources.manage', 'forum.manage', 'communication.manage', 'cards.manage',
        'commerce.manage', 'files.manage', 'statistics.view', 'downstream_users.access',
        'activities.manage',
    ];

    public static function index(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'admins.manage');
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        [$scope, $query] = self::adminScope($actor, $request);
        $where = [$scope, 'a.status <> -1'];
        if (trim((string) $request->input('keyword', '')) !== '') {
            $where[] = '(a.account LIKE ? OR a.nickname LIKE ? OR a.email LIKE ? OR a.phone LIKE ?)';
            $keyword = '%' . trim((string) $request->input('keyword')) . '%';
            array_push($query, $keyword, $keyword, $keyword, $keyword);
        }
        if ($request->input('status') !== null && $request->input('status') !== '') {
            $where[] = 'a.status = ?';
            $query[] = (int) $request->input('status');
        }
        if (trim((string) $request->input('membership_status', '')) !== '') {
            $where[] = 'e.membership_status = ?';
            $query[] = trim((string) $request->input('membership_status'));
        }
        if (trim((string) $request->input('ip', '')) !== '') {
            $where[] = 'a.register_ip = ?';
            $query[] = trim((string) $request->input('ip'));
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM admins a INNER JOIN admin_entitlements e ON e.admin_id = a.id WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT a.id, a.platform_id, a.account, a.nickname, a.avatar, a.email, a.phone, a.status,
                    a.register_ip, a.last_login_ip, a.last_login_at, a.created_at,
                    e.membership_level, e.membership_status, e.membership_expired_at,
                    e.app_quota, e.remote_document_quota, e.integral AS balance, e.access_start_time,
                    e.access_end_time, e.allowed_weekdays, p.nickname AS platform_name, p.platform_key,
                    (SELECT COUNT(*) FROM apps ap WHERE ap.admin_id = a.id AND ap.deleted_at IS NULL) AS app_count,
                    (SELECT COUNT(*) FROM users u WHERE u.admin_id = a.id AND u.deleted_at IS NULL) AS user_count
             FROM admins a INNER JOIN admin_entitlements e ON e.admin_id = a.id
             INNER JOIN platform_accounts p ON p.id = a.platform_id
             WHERE {$whereSql} ORDER BY a.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        foreach ($items as &$item) {
            $context = AdminAccessService::context((int) $item['id']);
            $item['access'] = AdminAccessService::accessState($context);
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function create(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'admins.manage');
        $target = self::targetPlatform($actor, (int) $request->input('platform_id', 0));
        PlatformService::assertActive($target);
        $defaults = AdminProvisionService::defaultGrant((int) $target['id']);
        $custom = array_merge($defaults, array_filter([
            'membership_level' => $request->input('membership_level'),
            'vip_days' => $request->input('vip_days', $request->input('membership_days')),
            'app_quota' => $request->input('app_quota'),
            'remote_document_quota' => $request->input('remote_document_quota'),
            'integral' => $request->input('balance'),
        ], static fn ($value): bool => $value !== null && $value !== ''));
        $admin = AdminProvisionService::managedProvision(
            $target,
            $request->all(),
            $request,
            $custom,
            '平台创建',
            static function (array $created) use ($request, $actor): void {
                PlatformService::log(
                    $request,
                    $actor,
                    'admin',
                    'create',
                    'admin',
                    (int) $created['id'],
                    null,
                    self::publicAdmin($created)
                );
            }
        );
        return Response::success([
            'admin' => self::publicAdmin($admin),
            'registration_gift' => $admin['registration_gift'],
            'initial_app' => $admin['initial_app'],
            'app_secret' => $admin['initial_app_secret'],
            'secret_notice' => '首个应用 app_secret 只在创建成功时返回一次，请立即交付给管理员保存到服务端。',
        ], 'admin 创建成功', 201);
    }

    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $admin = PlatformService::ownedAdmin($actor, (int) $params['admin_id']);
        $data = self::publicAdmin($admin);
        $data['access'] = AdminAccessService::accessState($admin);
        $data['permissions'] = self::permissions((int) $admin['id']);
        $data['counts'] = self::counts((int) $admin['id']);
        return Response::success(['admin' => $data]);
    }

    public static function update(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'admins.manage');
        $admin = PlatformService::ownedAdmin($actor, (int) $params['admin_id']);
        $data = $request->all();
        $account = trim((string) ($data['account'] ?? $admin['account']));
        if ($account !== (string) $admin['account'] && Database::one(
            'SELECT id FROM admins WHERE platform_id = ? AND account = ?',
            [(int) $admin['platform_id'], $account]
        )) {
            throw new HttpException('当前平台下 admin 账号已存在', 0, 409);
        }
        $status = array_key_exists('status', $data)
            ? Validator::integer($data['status'], 'status', 0, 1)
            : (int) $admin['status'];
        Database::execute(
            'UPDATE admins SET account = ?, nickname = ?, avatar = ?, email = ?, phone = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [
                $account, mb_substr((string) ($data['nickname'] ?? $admin['nickname']), 0, 100),
                mb_substr((string) ($data['avatar'] ?? $admin['avatar']), 0, 500),
                trim((string) ($data['email'] ?? $admin['email'])) ?: null,
                mb_substr(trim((string) ($data['phone'] ?? $admin['phone'])), 0, 40) ?: null,
                $status, (int) $admin['id'],
            ]
        );
        if ($status !== 1) {
            self::revoke((int) $admin['id']);
        }
        $after = AdminAccessService::context((int) $admin['id']);
        PlatformService::log($request, $actor, 'admin', 'update', 'admin', (int) $admin['id'], self::publicAdmin($admin), self::publicAdmin($after));
        return Response::success(['admin' => self::publicAdmin($after)], 'admin 信息已更新');
    }

    public static function resetPassword(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $admin = PlatformService::ownedAdmin($actor, (int) $params['admin_id']);
        $password = (string) $request->input('new_password', '');
        if (strlen($password) < 6 || strlen($password) > 72) {
            throw new HttpException('new_password 长度必须在 6-72 个字节之间', 0, 422);
        }
        Database::execute('UPDATE admins SET password_hash = ?, updated_at = NOW() WHERE id = ?', [
            Password::hash($password), (int) $admin['id'],
        ]);
        self::revoke((int) $admin['id']);
        PlatformService::log($request, $actor, 'admin', 'password_reset', 'admin', (int) $admin['id']);
        return Response::success([], 'admin 密码已重置');
    }

    public static function ban(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::status($request, $params, false);
    }

    public static function unban(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::status($request, $params, true);
    }

    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'admins.manage');
        $admin = PlatformService::ownedAdmin($actor, (int) $params['admin_id']);
        if ((string) $request->input('confirm', '') !== 'DELETE') {
            throw new HttpException('请传 confirm=DELETE 确认连带删除 admin、应用、user 和全部业务数据', 0, 422);
        }
        $counts = self::counts((int) $admin['id']);
        PlatformService::log($request, $actor, 'admin', 'hard_delete', 'admin', (int) $admin['id'], [
            'admin' => self::publicAdmin($admin), 'counts' => $counts,
        ]);
        Database::execute('DELETE FROM admins WHERE id = ?', [(int) $admin['id']]);
        return Response::success(['deleted_counts' => $counts], 'admin 及全部附属数据已连带删除');
    }

    public static function entitlement(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $admin = PlatformService::ownedAdmin($actor, (int) $params['admin_id']);
        $entitlementData = self::entitlementInput($request->all());
        if (array_key_exists('balance', $entitlementData)) $entitlementData['integral'] = $entitlementData['balance'];
        if (array_key_exists('balance_change', $entitlementData)) $entitlementData['integral_change'] = $entitlementData['balance_change'];
        unset($entitlementData['balance']);
        unset($entitlementData['balance_change']);
        $after = AdminProvisionService::adjustEntitlement(
            $actor,
            $admin,
            $entitlementData,
            (string) $request->input('remark', '')
        );
        PlatformService::log($request, $actor, 'admin', 'entitlement_adjust', 'admin', (int) $admin['id'], null, self::publicAdmin($after));
        return Response::success(['admin' => self::publicAdmin($after)], 'admin 权益已调整');
    }

    public static function batchEntitlement(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'admins.manage');
        $ids = self::targetIds($request->input('target_ids', []));
        $admins = [];
        foreach ($ids as $id) $admins[] = PlatformService::ownedAdmin($actor, $id);
        $changes = self::entitlementInput($request->all());
        $updated = [];
        foreach ($admins as $admin) {
            $after = AdminProvisionService::adjustEntitlement($actor, $admin, $changes, (string) $request->input('remark', ''));
            $updated[] = ['id' => (int) $after['id'], 'account' => (string) $after['account']];
        }
        PlatformService::log($request, $actor, 'admin', 'batch_entitlement_adjust', 'admin', null, null, [
            'target_ids' => $ids, 'count' => count($updated),
        ]);
        return Response::success(['updated' => $updated, 'count' => count($updated)], '已批量调整管理员权益');
    }

    public static function permissionList(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $admin = PlatformService::ownedAdmin($actor, (int) $params['admin_id']);
        return Response::success(RolePermissionService::adminPayload($admin, (int) $actor['level']));
    }

    public static function savePermissions(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'admins.permissions');
        $admin = PlatformService::ownedAdmin($actor, (int) $params['admin_id']);
        $input = $request->input('permissions', []);
        if (!is_array($input)) {
            throw new HttpException('权限配置必须是对象', 0, 422);
        }
        $permissions = RolePermissionService::normalizeAdminInput($input);
        $before = RolePermissionService::adminPayload($admin, (int) $actor['level']);
        Database::transaction(static function () use ($admin, $permissions): void {
            foreach ($permissions as $code => $value) {
                Database::execute(
                    'INSERT INTO admin_permissions
                     (platform_id, admin_id, permission_code, allowed, config_json, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE allowed = VALUES(allowed), config_json = VALUES(config_json), updated_at = NOW()',
                    [
                        (int) $admin['platform_id'], (int) $admin['id'], (string) $code,
                        (bool) $value['allowed'] ? 1 : 0,
                        is_array($value['config']) ? json_encode($value['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    ]
                );
            }
        });
        $after = RolePermissionService::adminPayload($admin, (int) $actor['level']);
        PlatformService::log($request, $actor, 'admin', 'permissions_update', 'admin', (int) $admin['id'], $before, $after);
        return Response::success($after, '管理员权限已保存');
    }

    public static function impersonate(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'admins.impersonate');
        $admin = PlatformService::ownedAdmin($actor, (int) $params['admin_id']);
        $plain = Token::issue();
        $expiredAt = date('Y-m-d H:i:s', time() + min(86400, max(300, (int) $request->input('ttl', 3600))));
        Database::execute(
            'INSERT INTO admin_tokens
             (admin_id, issued_by_platform_id, token_type, token_hash, device, ip, user_agent, expired_at, created_at, last_used_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (int) $admin['id'], (int) $actor['id'], 'platform_impersonation', Token::hash($plain),
                'platform-impersonation', $request->clientIp(), $request->userAgent(), $expiredAt,
            ]
        );
        PlatformService::log($request, $actor, 'admin', 'impersonate', 'admin', (int) $admin['id']);
        return Response::success([
            'token_type' => 'Bearer',
            'access_token' => $plain,
            'expires_at' => $expiredAt,
            'admin' => self::publicAdmin($admin),
            'apps' => Database::all('SELECT id, app_key, name, status FROM apps WHERE admin_id = ? AND deleted_at IS NULL', [(int) $admin['id']]),
            'audit_notice' => '该令牌由平台代管签发，所有操作仍会记录 target admin 与平台签发者。',
        ], '已签发受审计的 admin 代管令牌');
    }

    public static function apps(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $admin = PlatformService::ownedAdmin($actor, (int) $params['admin_id']);
        return Response::success(['items' => Database::all(
            'SELECT id, app_key, name, logo, description, status, disabled_reason, version, created_at, updated_at
             FROM apps WHERE admin_id = ? AND deleted_at IS NULL ORDER BY id DESC',
            [(int) $admin['id']]
        )]);
    }

    private static function status(Request $request, array $params, bool $enable): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $admin = PlatformService::ownedAdmin($actor, (int) $params['admin_id']);
        Database::execute('UPDATE admins SET status = ?, updated_at = NOW() WHERE id = ?', [$enable ? 1 : 0, (int) $admin['id']]);
        if (!$enable) {
            self::revoke((int) $admin['id']);
        }
        PlatformService::log($request, $actor, 'admin', $enable ? 'unban' : 'ban', 'admin', (int) $admin['id'], null, [
            'reason' => (string) $request->input('reason', ''),
        ]);
        return Response::success(['admin_id' => (int) $admin['id'], 'status' => $enable ? 1 : 0], $enable ? 'admin 已解封' : 'admin 已封禁，下游 user 同步停用');
    }

    private static function revoke(int $adminId): void
    {
        Database::execute('UPDATE admin_tokens SET revoked_at = NOW() WHERE admin_id = ? AND revoked_at IS NULL', [$adminId]);
        Database::execute('UPDATE user_tokens SET revoked_at = NOW() WHERE admin_id = ? AND revoked_at IS NULL', [$adminId]);
        Database::execute('UPDATE user_refresh_tokens SET revoked_at = NOW() WHERE admin_id = ? AND revoked_at IS NULL', [$adminId]);
    }

    private static function targetPlatform(array $actor, int $requested): array
    {
        if ((int) $actor['level'] === 2) {
            return $actor;
        }
        if ($requested <= 0 || $requested === (int) $actor['id']) {
            return $actor;
        }
        return PlatformService::ownedOperator($actor, $requested);
    }

    private static function adminScope(array $actor, Request $request): array
    {
        if ((int) $actor['level'] === 2) {
            return ['a.platform_id = ?', [(int) $actor['id']]];
        }
        $platformId = (int) $request->input('platform_id', 0);
        if ($platformId > 0) {
            if ($platformId !== (int) $actor['id']) {
                PlatformService::ownedOperator($actor, $platformId);
            }
            return ['a.platform_id = ?', [$platformId]];
        }
        return [
            '(a.platform_id = ? OR a.platform_id IN
              (SELECT id FROM platform_accounts WHERE parent_id = ? AND level = 2 AND deleted_at IS NULL))',
            [(int) $actor['id'], (int) $actor['id']],
        ];
    }

    private static function permissions(int $adminId): array
    {
        $rows = Database::all('SELECT permission_code, allowed, config_json FROM admin_permissions WHERE admin_id = ?', [$adminId]);
        $stored = [];
        foreach ($rows as $row) {
            $stored[$row['permission_code']] = [
                'allowed' => (bool) $row['allowed'],
                'config' => json_decode((string) ($row['config_json'] ?? ''), true),
            ];
        }
        $result = [];
        foreach (self::PERMISSIONS as $code) {
            $result[$code] = $stored[$code] ?? ['allowed' => true, 'config' => null];
        }
        return $result;
    }

    private static function counts(int $adminId): array
    {
        return Database::one(
            'SELECT
               (SELECT COUNT(*) FROM apps WHERE admin_id = ? AND deleted_at IS NULL) AS apps,
               (SELECT COUNT(*) FROM users WHERE admin_id = ? AND deleted_at IS NULL) AS users,
               (SELECT COUNT(*) FROM documents WHERE admin_id = ? AND deleted_at IS NULL) AS documents,
               (SELECT COUNT(*) FROM forum_posts WHERE admin_id = ? AND deleted_at IS NULL) AS posts,
               (SELECT COUNT(*) FROM messages WHERE admin_id = ?) AS messages,
               (SELECT COUNT(*) FROM chat_rooms WHERE admin_id = ?) AS chat_rooms,
               (SELECT COUNT(*) FROM remote_files WHERE admin_id = ? AND deleted_at IS NULL) AS remote_files',
            [$adminId, $adminId, $adminId, $adminId, $adminId, $adminId, $adminId]
        ) ?? [];
    }

    private static function publicAdmin(array $admin): array
    {
        return [
            'id' => (int) $admin['id'], 'platform_id' => (int) $admin['platform_id'],
            'platform_key' => $admin['platform_key'] ?? null, 'account' => $admin['account'],
            'nickname' => $admin['nickname'], 'avatar' => $admin['avatar'], 'email' => $admin['email'],
            'phone' => $admin['phone'], 'status' => (int) $admin['status'], 'register_ip' => $admin['register_ip'],
            'last_login_ip' => $admin['last_login_ip'], 'last_login_at' => $admin['last_login_at'],
            'membership_level' => $admin['membership_level'], 'membership_status' => $admin['membership_status'],
            'membership_expired_at' => $admin['membership_expired_at'], 'app_quota' => (int) $admin['app_quota'],
            'remote_document_quota' => (int) $admin['remote_document_quota'], 'balance' => (int) $admin['admin_integral'],
            'access_start_time' => $admin['access_start_time'], 'access_end_time' => $admin['access_end_time'],
            'allowed_weekdays' => $admin['allowed_weekdays'], 'created_at' => $admin['created_at'],
        ];
    }

    private static function entitlementInput(array $data): array
    {
        $type = trim((string) ($data['entitlement_type'] ?? ''));
        if ($type === '') return $data;
        $amount = (int) ($data['amount'] ?? 0);
        if ($amount < 0) throw new HttpException('权益数量不能小于 0，请选择减少操作', 0, 422);
        $operation = EntitlementDurationService::operation((string) ($data['operation'] ?? 'increase'));
        if ($type === 'vip') {
            return [
                'membership_duration_value' => $amount,
                'membership_duration_unit' => (string) ($data['duration_unit'] ?? 'day'),
                'membership_operation' => $operation,
                'membership_level' => (string) ($data['membership_level'] ?? 'vip'),
            ];
        }
        $field = match ($type) {
            'balance' => 'integral',
            'document_quota' => 'remote_document_quota',
            'app_quota' => 'app_quota',
            default => throw new HttpException('管理员不支持该权益类型', 0, 422),
        };
        if ($operation === 'set') return [$field => $amount];
        return [$field . '_change' => $operation === 'increase' ? $amount : -$amount];
    }

    private static function targetIds($value): array
    {
        if (!is_array($value)) throw new HttpException('请选择要调整的管理员', 0, 422);
        $ids = array_values(array_unique(array_filter(array_map('intval', $value), static fn(int $id): bool => $id > 0)));
        if ($ids === [] || count($ids) > 500) throw new HttpException('每次请选择 1-500 个管理员', 0, 422);
        return $ids;
    }
}
