<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Password;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Validator;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\TransferPolicyService;
use Yiyunying\Services\WalletService;
use Yiyunying\Services\UserOverviewService;
use Yiyunying\Services\EntitlementDurationService;
use Yiyunying\Services\IdentityService;
use Yiyunying\Services\CommunicationTakeoverService;
use Yiyunying\Services\MessageForwardService;
use Yiyunying\Services\RolePermissionService;

final class UserController
{
    public static function index(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['u.admin_id = ?', 'u.app_id = ?', 'u.deleted_at IS NULL'];
        $queryParams = [(int) $admin['id'], $appId];
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(u.uid LIKE ? OR u.account LIKE ? OR u.email LIKE ? OR p.nickname LIKE ?)';
            $queryParams[] = '%' . $keyword . '%';
            $queryParams[] = '%' . $keyword . '%';
            $queryParams[] = '%' . $keyword . '%';
            $queryParams[] = '%' . $keyword . '%';
        }
        if ($request->input('status') !== null && $request->input('status') !== '') {
            $where[] = 'u.status = ?';
            $queryParams[] = (int) $request->input('status');
        }
        if ($request->input('tag_id') !== null && $request->input('tag_id') !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM user_tag_relations utr WHERE utr.user_id = u.id AND utr.tag_id = ?)';
            $queryParams[] = (int) $request->input('tag_id');
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id WHERE {$whereSql}",
            $queryParams
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT u.id, u.uid, u.account, u.email, u.phone, u.status, u.register_ip, u.last_login_ip,
                    u.last_login_at, u.created_at, p.nickname, p.avatar, p.signature,
                    w.integral, w.experience, w.balance, w.document_credit, w.vip_expired_at, w.level_code
             FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN user_wallets w ON w.user_id = u.id
             WHERE {$whereSql}
             ORDER BY u.id DESC LIMIT {$limit} OFFSET {$offset}",
            $queryParams
        );
        foreach ($items as &$item) {
            $item['id'] = (int) $item['id'];
            $item['status'] = (int) $item['status'];
            $item['activity_credit'] = (int) $item['integral'];
            unset($item['integral']);
            $item['balance'] = (float) $item['balance'];
            $item['experience'] = (int) $item['experience'];
            $item['document_credit'] = (int) $item['document_credit'];
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $sections = UserOverviewService::overview((int) $admin['id'], $appId, (int) $params['user_id']);
        return Response::success([
            'user' => $sections['资料与资产']['用户资料'] ?? [],
            'sections' => $sections,
        ]);
    }

    public static function permissions(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $user = self::ownedUser((int) $admin['id'], $appId, (int) $params['user_id']);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user_permissions', 'view', (int) $user['id']);
        return Response::success(RolePermissionService::userPayload($user, 3));
    }

    public static function savePermissions(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $user = self::ownedUser((int) $admin['id'], $appId, (int) $params['user_id']);
        $input = $request->input('permissions', []);
        if (!is_array($input)) {
            throw new HttpException('权限配置必须是对象', 0, 422);
        }
        $permissions = RolePermissionService::normalizeUserInput($input);
        $before = RolePermissionService::userPayload($user, 3);
        Database::transaction(static function () use ($admin, $appId, $user, $permissions): void {
            foreach ($permissions as $code => $value) {
                RolePermissionService::assertUserPermissionMutable($appId, (int) $user['id'], (string) $code, 3);
                Database::execute(
                    'INSERT INTO user_feature_permissions
                     (admin_id, app_id, user_id, feature_code, enabled, config_json, updated_by_type, updated_by_id, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), config_json = VALUES(config_json),
                       updated_by_type = VALUES(updated_by_type), updated_by_id = VALUES(updated_by_id), updated_at = NOW()',
                    [
                        (int) $admin['id'], $appId, (int) $user['id'], (string) $code,
                        (bool) $value['allowed'] ? 1 : 0,
                        is_array($value['config']) ? json_encode($value['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                        'admin', (int) $admin['id'],
                    ]
                );
            }
        });
        $after = RolePermissionService::userPayload($user, 3);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user_permissions', 'update', (int) $user['id'], $before, $after);
        return Response::success($after, '用户权限已保存');
    }
    public static function communications(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $channelType = trim((string) $request->input('channel_type', ''));
        $channelId = (int) $request->input('channel_id', 0);
        if ($channelId <= 0) throw new HttpException('channel_id 必须大于 0', 0, 422);
        $takeoverPolicy = CommunicationTakeoverService::assertAdmin($admin, $appId, 'view', $channelType);
        $data = UserOverviewService::communications(
            (int) $admin['id'], $appId, (int) $params['user_id'], $channelType, $channelId, $request
        );
        $data['takeover_policy'] = $takeoverPolicy;
        CommunicationTakeoverService::recordView(
            $request, (int) $admin['id'], $appId, 'admin', (int) $admin['id'], 3,
            $channelType, $channelId, (int) $params['user_id']
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user_audit', 'communications', (int) $params['user_id'], null, [
            'channel_type' => $channelType, 'channel_id' => $channelId,
        ]);
        return Response::success($data);
    }

    public static function participate(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $userId = (int) $params['user_id'];
        UserOverviewService::overview((int) $admin['id'], $appId, $userId);
        $channelType = trim((string) $request->input('channel_type', ''));
        $channelId = Validator::integer($request->input('channel_id'), 'channel_id', 1, PHP_INT_MAX);
        $content = Validator::string($request->input('content', ''), 'content', 1, 10000);
        CommunicationTakeoverService::assertAdmin($admin, $appId, 'send', $channelType);
        $result = CommunicationTakeoverService::sendSystemMessage(
            $request, (int) $admin['id'], $appId, $userId, $channelType, $channelId, $content,
            'admin', (int) $admin['id'], 3
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user_audit', 'participate', $userId, null, [
            'channel_type' => $channelType, 'channel_id' => $channelId, 'message_id' => $result['message_id'],
        ]);
        return Response::success($result, '系统接管消息已发送', 201);
    }

    public static function updateCommunication(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $userId = (int) $params['user_id'];
        $messageId = (int) $params['message_id'];
        UserOverviewService::overview((int) $admin['id'], $appId, $userId);
        $channelType = trim((string) $request->input('channel_type', ''));
        $channelId = Validator::integer($request->input('channel_id'), 'channel_id', 1, PHP_INT_MAX);
        $content = Validator::string($request->input('content', ''), 'content', 1, 10000);
        CommunicationTakeoverService::assertAdmin($admin, $appId, 'update', $channelType);
        $result = CommunicationTakeoverService::updateManagedMessage(
            $request, (int) $admin['id'], $appId, $userId, $channelType, $channelId,
            $messageId, $content, 'admin', (int) $admin['id'], 3
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user_audit', 'communication_update', $userId, null, [
            'channel_type' => $channelType, 'channel_id' => $channelId, 'message_id' => $messageId,
        ]);
        return Response::success($result, '聊天内容已修改');
    }

    public static function deleteCommunication(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $userId = (int) $params['user_id'];
        $messageId = (int) $params['message_id'];
        UserOverviewService::overview((int) $admin['id'], $appId, $userId);
        $channelType = trim((string) $request->input('channel_type', ''));
        $channelId = Validator::integer($request->input('channel_id'), 'channel_id', 1, PHP_INT_MAX);
        CommunicationTakeoverService::assertAdmin($admin, $appId, 'delete', $channelType);
        $result = CommunicationTakeoverService::deleteManagedMessage(
            $request, (int) $admin['id'], $appId, $userId, $channelType, $channelId,
            $messageId, 'admin', (int) $admin['id'], 3
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user_audit', 'communication_delete', $userId, null, [
            'channel_type' => $channelType, 'channel_id' => $channelId, 'message_id' => $messageId,
        ]);
        return Response::success($result, '聊天内容已删除');
    }

    public static function takeoverPolicy(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        return Response::success(CommunicationTakeoverService::forAdmin($admin, $appId));
    }

    public static function saveTakeoverPolicy(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        return Response::success(
            CommunicationTakeoverService::saveForAdmin($request, $admin, $appId),
            '通信接管策略已保存'
        );
    }

    public static function takeoverAudits(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        return Response::success(CommunicationTakeoverService::audits(
            $request, (int) $admin['id'], $appId
        ));
    }

    public static function forwardBundle(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        return Response::success([
            'forward' => MessageForwardService::showForManager(
                (int) $admin['id'], $appId, (int) $params['forward_id']
            ),
        ]);
    }

    public static function create(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $app = AppService::owned((int) $admin['id'], $appId);
        $data = $request->all();
        Validator::required($data, ['account', 'password']);
        $account = Validator::string($data['account'], 'account', 3, 32);
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $account) !== 1) {
            throw new HttpException('account 格式错误', 0, 422);
        }
        if (Database::one('SELECT id FROM users WHERE app_id = ? AND account = ?', [$appId, $account])) {
            throw new HttpException('该应用下账号已存在', 0, 409);
        }
        $password = Password::assertAcceptable((string) $data['password']);
        $nickname = mb_substr(trim((string) ($data['nickname'] ?? $account)), 0, 80);
        $email = IdentityService::normalize('email', (string) ($data['email'] ?? ''));
        $phone = mb_substr(IdentityService::normalize('phone', (string) ($data['phone'] ?? '')), 0, 40);
        if ($email !== '') IdentityService::assertAvailable('email', $email);
        if ($phone !== '') IdentityService::assertAvailable('phone', $phone);
        $uid = IdentityService::generateUid();
        $initialCredit = max(0, (int) AppService::setting($appId, 'initial_document_credit', 20));
        $initialBalance = max(0, (float) ($data['balance'] ?? AppService::setting($appId, 'user_initial_balance', 0)));
        $initialActivityCredit = max(0, (int) ($data['activity_credit'] ?? AppService::setting($appId, 'user_initial_activity_credit', 0)));
        $vipDays = max(0, (int) ($data['vip_days'] ?? AppService::setting($appId, 'user_free_vip_days', 0)));
        $vipExpiredAt = $vipDays > 0 ? date('Y-m-d H:i:s', time() + $vipDays * 86400) : null;

        $userId = Database::transaction(static function () use (
            $admin, $app, $appId, $uid, $account, $password, $nickname, $email, $phone, $initialCredit,
            $initialBalance, $initialActivityCredit, $vipExpiredAt, $request
        ): int {
            $id = Database::insert(
                'INSERT INTO users
                 (uid, admin_id, app_id, account, password_hash, email, phone, status, register_ip, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())',
                [
                    $uid, (int) $admin['id'], $appId, $account, Password::hash($password),
                    $email === '' ? null : $email, $phone === '' ? null : $phone, $request->clientIp(),
                ]
            );
            IdentityService::bind('user', $id, 'email', $email, (int) $admin['platform_id'], (int) $admin['id'], $appId, false);
            IdentityService::bind('user', $id, 'phone', $phone, (int) $admin['platform_id'], (int) $admin['id'], $appId, false);
            Database::execute(
                'INSERT INTO user_profiles
                 (admin_id, app_id, user_id, nickname, public_profile, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                [(int) $admin['id'], $appId, $id, $nickname, AppService::setting($appId, 'profile_public_default', true) ? 1 : 0]
            );
            Database::execute(
                'INSERT INTO user_wallets
                 (admin_id, app_id, user_id, integral, balance, document_credit, vip_expired_at, level_code, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [(int) $admin['id'], $appId, $id, $initialActivityCredit, $initialBalance, $initialCredit, $vipExpiredAt, 'normal']
            );
            return $id;
        });
        $user = self::ownedUser((int) $admin['id'], $appId, $userId);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user', 'create', $userId, null, ['account' => $account]);
        return Response::success(['user' => self::publicUser($user)], '用户创建成功', 201);
    }

    public static function update(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $userId = (int) $params['user_id'];
        $before = self::ownedUser((int) $admin['id'], $appId, $userId);
        $data = $request->all();
        $accountFields = [];
        $accountValues = [];
        $disableRequested = false;
        foreach (['email' => 190, 'phone' => 40] as $field => $max) {
            if (array_key_exists($field, $data)) {
                $value = mb_substr(trim((string) $data[$field]), 0, $max);
                if ($field === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    throw new HttpException('email 格式错误', 0, 422);
                }
                $accountFields[] = "{$field} = ?";
                $accountValues[] = $value === '' ? null : $value;
            }
        }
        if (array_key_exists('status', $data)) {
            $status = Validator::integer($data['status'], 'status', 0, 1);
            $accountFields[] = 'status = ?';
            $accountValues[] = $status;
            $disableRequested = $status === 0;
        }
        $profileFields = [];
        $profileValues = [];
        foreach (['nickname' => 80, 'qq' => 30, 'signature' => 500, 'avatar' => 500, 'background' => 500, 'gender' => 20, 'title' => 100] as $field => $max) {
            if (array_key_exists($field, $data)) {
                $profileFields[] = "{$field} = ?";
                $profileValues[] = mb_substr(trim((string) $data[$field]), 0, $max);
            }
        }
        if (array_key_exists('public_profile', $data)) {
            $profileFields[] = 'public_profile = ?';
            $profileValues[] = Validator::boolean($data['public_profile'], 'public_profile') ? 1 : 0;
        }
        if ($accountFields === [] && $profileFields === []) {
            throw new HttpException('没有可修改的字段', 0, 422);
        }
        Database::transaction(static function () use (
            $accountFields, $accountValues, $profileFields, $profileValues, $admin, $appId, $userId,
            $disableRequested
        ): void {
            if ($accountFields !== []) {
                $values = array_merge($accountValues, [$userId, (int) $admin['id'], $appId]);
                Database::execute(
                    'UPDATE users SET ' . implode(', ', $accountFields) . ', updated_at = NOW()
                     WHERE id = ? AND admin_id = ? AND app_id = ?',
                    $values
                );
            }
            if ($profileFields !== []) {
                $values = array_merge($profileValues, [$userId, (int) $admin['id'], $appId]);
                Database::execute(
                    'UPDATE user_profiles SET ' . implode(', ', $profileFields) . ', updated_at = NOW()
                     WHERE user_id = ? AND admin_id = ? AND app_id = ?',
                    $values
                );
            }
            if ($disableRequested) {
                self::revokeUserSessions($userId, $appId);
            }
        });
        $after = self::ownedUser((int) $admin['id'], $appId, $userId);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user', 'update', $userId, $before, $after);
        return Response::success(['user' => self::publicUser($after)], '用户资料修改成功');
    }

    public static function resetPassword(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $userId = (int) $params['user_id'];
        self::ownedUser((int) $admin['id'], $appId, $userId);
        $password = Password::assertAcceptable((string) $request->input('new_password', ''), 'new_password');
        Database::transaction(static function () use ($admin, $appId, $userId, $password): void {
            Database::execute(
                'UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ? AND admin_id = ? AND app_id = ?',
                [Password::hash($password), $userId, (int) $admin['id'], $appId]
            );
            self::revokeUserSessions($userId, $appId);
        });
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user', 'password_reset', $userId);
        return Response::success([], '用户密码已重置');
    }

    public static function ban(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $userId = (int) $params['user_id'];
        self::ownedUser((int) $admin['id'], $appId, $userId);
        $banType = mb_substr(trim((string) $request->input('ban_type', 'all')), 0, 40);
        $reason = mb_substr(trim((string) $request->input('reason', '')), 0, 500);
        $endAt = Validator::nullableDateTime($request->input('end_at'), 'end_at');
        Database::transaction(static function () use ($admin, $appId, $userId, $banType, $reason, $endAt): void {
            Database::execute(
                'INSERT INTO user_bans
                 (admin_id, app_id, user_id, ban_type, reason, start_at, end_at, operator_admin_id, status, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, 1, NOW())',
                [(int) $admin['id'], $appId, $userId, $banType, $reason, $endAt, (int) $admin['id']]
            );
            if (in_array($banType, ['all', 'login'], true)) {
                Database::execute('UPDATE users SET status = 0, updated_at = NOW() WHERE id = ? AND app_id = ?', [$userId, $appId]);
                self::revokeUserSessions($userId, $appId);
            }
            if (in_array($banType, ['all', 'resource'], true)) {
                self::freezeCatalogSubmissions((int) $admin['id'], $appId, $userId, 'resource', '发布者账号已被封禁，资源暂定并停止新购买');
            }
            if (in_array($banType, ['all', 'store', 'shop'], true)) {
                self::freezeCatalogSubmissions((int) $admin['id'], $appId, $userId, 'store', '发布者账号已被封禁，应用暂定并停止新购买');
            }
        });
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user', 'ban', $userId, null, ['type' => $banType, 'reason' => $reason]);
        return Response::success([], '用户已封禁');
    }

    public static function unban(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $userId = (int) $params['user_id'];
        self::ownedUser((int) $admin['id'], $appId, $userId);
        Database::transaction(static function () use ($admin, $appId, $userId): void {
            Database::execute(
                'UPDATE user_bans SET status = 0 WHERE admin_id = ? AND app_id = ? AND user_id = ? AND status = 1',
                [(int) $admin['id'], $appId, $userId]
            );
            Database::execute(
                'UPDATE users SET status = 1, updated_at = NOW() WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$userId, (int) $admin['id'], $appId]
            );
        });
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user', 'unban', $userId);
        return Response::success([], '用户已解封');
    }

    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $userId = (int) $params['user_id'];
        $user = self::ownedUser((int) $admin['id'], $appId, $userId);
        if ((string) $request->input('confirm', '') !== 'DELETE') {
            throw new HttpException('请传 confirm=DELETE 确认删除', 0, 422);
        }
        Database::transaction(static function () use ($admin, $appId, $userId): void {
            Database::execute(
                'UPDATE users SET status = -1, deleted_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$userId, (int) $admin['id'], $appId]
            );
            self::revokeUserSessions($userId, $appId);
            self::freezeCatalogSubmissions(
                (int) $admin['id'], $appId, $userId, 'resource', '发布者账号已删除，资源暂定并停止新购买'
            );
            self::freezeCatalogSubmissions(
                (int) $admin['id'], $appId, $userId, 'store', '发布者账号已删除，应用暂定并停止新购买'
            );
        });
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user', 'delete', $userId, $user);
        return Response::success([], '用户已删除');
    }

    private static function freezeCatalogSubmissions(
        int $adminId,
        int $appId,
        int $userId,
        string $kind,
        string $reason
    ): void {
        $table = $kind === 'store' ? 'store_apps' : 'resources';
        Database::execute(
            "UPDATE {$table} SET audit_status = 'on_hold', audit_reason = ?, status = 0,
             audited_by = NULL, audited_at = NULL, updated_at = NOW()
             WHERE admin_id = ? AND app_id = ? AND user_id = ? AND deleted_at IS NULL",
            [mb_substr($reason, 0, 500), $adminId, $appId, $userId]
        );
    }

    public static function import(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $users = $request->input('users', $request->input('users_json'));
        if (is_string($users)) {
            $users = json_decode($users, true);
        }
        if (!is_array($users) || $users === [] || count($users) > 100) {
            throw new HttpException('users 必须是 1-100 条用户数组', 0, 422);
        }
        $created = [];
        $failed = [];
        foreach ($users as $index => $item) {
            if (!is_array($item)) {
                $failed[] = ['index' => $index, 'reason' => '格式错误'];
                continue;
            }
            $account = trim((string) ($item['account'] ?? ''));
            $password = (string) ($item['password'] ?? '');
            if (preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $account) !== 1
                || !Password::isAcceptable($password)) {
                $failed[] = [
                    'index' => $index, 'account' => $account,
                    'reason' => '账号格式错误，或密码未显式提供 6-72 字节且不能使用已知默认密码',
                ];
                continue;
            }
            if (Database::one('SELECT id FROM users WHERE app_id = ? AND account = ? AND deleted_at IS NULL', [$appId, $account])) {
                $failed[] = ['index' => $index, 'account' => $account, 'reason' => '账号已存在'];
                continue;
            }
            $userId = Database::transaction(static function () use ($admin, $appId, $account, $password, $item, $request): int {
                $uid = IdentityService::generateUid();
                $email = IdentityService::normalize('email', (string) ($item['email'] ?? ''));
                $phone = IdentityService::normalize('phone', (string) ($item['phone'] ?? ''));
                if ($email !== '') IdentityService::assertAvailable('email', $email);
                if ($phone !== '') IdentityService::assertAvailable('phone', $phone);
                $id = Database::insert(
                    'INSERT INTO users
                     (uid, admin_id, app_id, account, password_hash, email, phone, status, register_ip, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())',
                    [
                        $uid, (int) $admin['id'], $appId, $account, Password::hash($password),
                        $email === '' ? null : $email, $phone === '' ? null : $phone, $request->clientIp(),
                    ]
                );
                IdentityService::bind('user', $id, 'email', $email, (int) $admin['platform_id'], (int) $admin['id'], $appId, false);
                IdentityService::bind('user', $id, 'phone', $phone, (int) $admin['platform_id'], (int) $admin['id'], $appId, false);
                Database::execute(
                    'INSERT INTO user_profiles (admin_id, app_id, user_id, nickname, public_profile, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 1, NOW(), NOW())',
                    [(int) $admin['id'], $appId, $id, mb_substr((string) ($item['nickname'] ?? $account), 0, 80)]
                );
                Database::execute(
                    'INSERT INTO user_wallets (admin_id, app_id, user_id, document_credit, level_code, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                    [(int) $admin['id'], $appId, $id, max(0, (int) AppService::setting($appId, 'initial_document_credit', 20)), 'normal']
                );
                return $id;
            });
            $created[] = ['id' => $userId, 'account' => $account];
        }
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user', 'import', null, null, ['created' => count($created), 'failed' => count($failed)]);
        return Response::success(['created' => $created, 'failed' => $failed], '批量导入完成');
    }

    public static function wallet(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $userId = (int) $params['user_id'];
        $user = self::ownedUser((int) $admin['id'], $appId, $userId);
        $asset = trim((string) $request->input('asset_type', ''));
        if ($asset === 'activity_credit') $asset = 'integral';
        $change = $request->input('change_value', null);
        $wallet = Database::transaction(static fn() => WalletService::adjust(
            $user,
            $asset,
            $change,
            'admin_adjust',
            'admin',
            (int) $admin['id'],
            (string) $request->input('remark', '')
        ));
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user_wallet', 'adjust', $userId, null, ['asset' => $asset, 'change' => $change]);
        return Response::success(['wallet' => WalletService::publicWallet($wallet, $appId)], '用户资产调整成功');
    }

    public static function vip(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $userId = (int) $params['user_id'];
        self::ownedUser((int) $admin['id'], $appId, $userId);
        $expiredAt = Validator::nullableDateTime($request->input('vip_expired_at'), 'vip_expired_at');
        Database::execute(
            'UPDATE user_wallets SET vip_expired_at = ?, updated_at = NOW()
             WHERE admin_id = ? AND app_id = ? AND user_id = ?',
            [$expiredAt, (int) $admin['id'], $appId, $userId]
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user_wallet', 'vip_set', $userId, null, ['vip_expired_at' => $expiredAt]);
        return Response::success(['vip_expired_at' => $expiredAt], '会员状态已更新');
    }

    public static function entitlement(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $user = self::ownedUser((int) $admin['id'], $appId, (int) $params['user_id']);
        $result = self::applyEntitlement($user, $request->all(), (int) $admin['id']);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user_entitlement', 'adjust', (int) $user['id'], null, [
            'entitlement_type' => $request->input('entitlement_type'), 'operation' => $request->input('operation'),
        ]);
        return Response::success($result, '用户权益已调整');
    }

    public static function batchEntitlement(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $ids = self::targetIds($request->input('target_ids', []));
        $users = [];
        foreach ($ids as $id) $users[] = self::ownedUser((int) $admin['id'], $appId, $id);
        $updated = [];
        foreach ($users as $user) {
            self::applyEntitlement($user, $request->all(), (int) $admin['id']);
            $updated[] = ['id' => (int) $user['id'], 'account' => (string) $user['account']];
        }
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user_entitlement', 'batch_adjust', null, null, [
            'target_ids' => $ids, 'count' => count($updated),
        ]);
        return Response::success(['updated' => $updated, 'count' => count($updated)], '已批量调整用户权益');
    }

    public static function logs(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $userId = (int) $params['user_id'];
        self::ownedUser((int) $admin['id'], $appId, $userId);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['admin_id = ?', 'app_id = ?', 'user_id = ?'];
        $query = [(int) $admin['id'], $appId, $userId];
        if (trim((string) $request->input('module', '')) !== '') {
            $where[] = 'module = ?';
            $query[] = trim((string) $request->input('module'));
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM user_operation_logs WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT * FROM user_operation_logs WHERE {$whereSql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function tags(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        return Response::success(['items' => Database::all(
            'SELECT id, name, color, sort_order, created_at FROM user_tags
             WHERE admin_id = ? AND app_id = ? ORDER BY sort_order DESC, id ASC',
            [(int) $admin['id'], $appId]
        )]);
    }

    public static function createTag(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $name = Validator::string($request->input('name', ''), 'name', 1, 50);
        $id = Database::insert(
            'INSERT INTO user_tags (admin_id, app_id, name, color, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [(int) $admin['id'], $appId, $name, mb_substr((string) $request->input('color', '#64748b'), 0, 20), (int) $request->input('sort_order', 0)]
        );
        return Response::success(['tag_id' => $id], '标签创建成功', 201);
    }

    public static function updateTag(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $tagId = (int) $params['tag_id'];
        $tag = Database::one('SELECT * FROM user_tags WHERE id = ? AND admin_id = ? AND app_id = ?', [$tagId, (int) $admin['id'], $appId]);
        if ($tag === null) {
            throw new HttpException('标签不存在', 404, 404);
        }
        Database::execute(
            'UPDATE user_tags SET name = ?, color = ?, sort_order = ?, updated_at = NOW()
             WHERE id = ? AND admin_id = ? AND app_id = ?',
            [
                mb_substr((string) $request->input('name', $tag['name']), 0, 50),
                mb_substr((string) $request->input('color', $tag['color']), 0, 20),
                (int) $request->input('sort_order', $tag['sort_order']),
                $tagId, (int) $admin['id'], $appId,
            ]
        );
        return Response::success([], '标签修改成功');
    }

    public static function deleteTag(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        Database::execute('DELETE FROM user_tags WHERE id = ? AND admin_id = ? AND app_id = ?', [
            (int) $params['tag_id'], (int) $admin['id'], $appId,
        ]);
        return Response::success([], '标签已删除');
    }

    public static function setTags(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $userId = (int) $params['user_id'];
        self::ownedUser((int) $admin['id'], $appId, $userId);
        $tagIds = $request->input('tag_ids', []);
        if (!is_array($tagIds)) {
            throw new HttpException('tag_ids 必须是数组', 0, 422);
        }
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
        Database::transaction(static function () use ($admin, $appId, $userId, $tagIds): void {
            Database::execute('DELETE FROM user_tag_relations WHERE admin_id = ? AND app_id = ? AND user_id = ?', [
                (int) $admin['id'], $appId, $userId,
            ]);
            foreach ($tagIds as $tagId) {
                if (Database::one('SELECT id FROM user_tags WHERE id = ? AND admin_id = ? AND app_id = ?', [$tagId, (int) $admin['id'], $appId]) === null) {
                    throw new HttpException('标签不存在：' . $tagId, 404, 404);
                }
                Database::execute(
                    'INSERT INTO user_tag_relations (admin_id, app_id, user_id, tag_id, created_at)
                     VALUES (?, ?, ?, ?, NOW())',
                    [(int) $admin['id'], $appId, $userId, $tagId]
                );
            }
        });
        return Response::success(['tag_ids' => $tagIds], '用户标签设置成功');
    }

    public static function transferPolicy(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $userId = (int) $params['user_id'];
        self::ownedUser((int) $admin['id'], $appId, $userId);
        return Response::success(['policy' => TransferPolicyService::get((int) $admin['id'], $appId, $userId)]);
    }

    public static function saveTransferPolicy(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $userId = (int) $params['user_id'];
        self::ownedUser((int) $admin['id'], $appId, $userId);
        $policy = TransferPolicyService::save((int) $admin['id'], $appId, $userId, $request->all());
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'user', 'transfer_policy', $userId, null, $policy);
        return Response::success(['policy' => $policy], '用户转账策略已保存');
    }

    private static function applyEntitlement(array $user, array $data, int $operatorAdminId): array
    {
        $type = trim((string) ($data['entitlement_type'] ?? ''));
        $operation = EntitlementDurationService::operation((string) ($data['operation'] ?? 'increase'));
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount < 0) throw new HttpException('权益数量不能小于 0，请选择减少操作', 0, 422);
        if ($type === 'vip') {
            $expiredAt = EntitlementDurationService::apply(
                $user['vip_expired_at'] === null ? null : (string) $user['vip_expired_at'],
                $operation, (int) $amount, (string) ($data['duration_unit'] ?? 'day')
            );
            Database::execute(
                'UPDATE user_wallets SET vip_expired_at = ?, level_code = ?, updated_at = NOW()
                 WHERE admin_id = ? AND app_id = ? AND user_id = ?',
                [
                    $expiredAt, mb_substr((string) ($data['membership_level'] ?? 'vip'), 0, 40),
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
                ]
            );
            return ['user' => self::publicUser(self::ownedUser((int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']))];
        }
        $asset = match ($type) {
            'balance' => 'balance',
            'document_credit' => 'document_credit',
            'activity_credit' => 'integral',
            'experience' => 'experience',
            default => throw new HttpException('用户不支持该权益类型', 0, 422),
        };
        $current = (float) $user[$asset];
        $change = EntitlementDurationService::numericChange($operation, $amount, $current);
        if ($change == 0.0) {
            return ['user' => self::publicUser($user), 'changed' => false];
        }
        $wallet = Database::transaction(static fn(): array => WalletService::adjust(
            $user, $asset, $change, 'admin_entitlement_adjust', 'admin', $operatorAdminId,
            (string) ($data['remark'] ?? '')
        ));
        return ['user' => self::publicUser(self::ownedUser((int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'])),
            'wallet' => WalletService::publicWallet($wallet, (int) $user['app_id']), 'changed' => true];
    }

    private static function targetIds($value): array
    {
        if (!is_array($value)) throw new HttpException('请选择要调整的用户', 0, 422);
        $ids = array_values(array_unique(array_filter(array_map('intval', $value), static fn(int $id): bool => $id > 0)));
        if ($ids === [] || count($ids) > 500) throw new HttpException('每次请选择 1-500 个用户', 0, 422);
        return $ids;
    }

    private static function ownedUser(int $adminId, int $appId, int $userId): array
    {
        $user = Database::one(
            'SELECT u.id, u.uid, u.admin_id, u.app_id, u.account, u.email, u.phone, u.status,
                    u.register_ip, u.last_login_ip, u.last_login_at, u.created_at, u.updated_at,
                    p.nickname, p.qq, p.avatar, p.background, p.signature, p.gender, p.birthday,
                    p.title, p.public_profile, w.integral, w.experience, w.balance,
                    w.document_credit, w.vip_expired_at, w.level_code
             FROM users u
             INNER JOIN user_profiles p ON p.user_id = u.id
             INNER JOIN user_wallets w ON w.user_id = u.id
             WHERE u.id = ? AND u.admin_id = ? AND u.app_id = ? AND u.deleted_at IS NULL',
            [$userId, $adminId, $appId]
        );
        if ($user === null) {
            throw new HttpException('用户不存在', 404, 404);
        }
        return $user;
    }

    private static function revokeUserSessions(int $userId, int $appId): void
    {
        Database::execute(
            'UPDATE user_tokens SET revoked_at = NOW() WHERE user_id = ? AND app_id = ? AND revoked_at IS NULL',
            [$userId, $appId]
        );
        Database::execute(
            'UPDATE user_refresh_tokens SET revoked_at = NOW() WHERE user_id = ? AND app_id = ? AND revoked_at IS NULL',
            [$userId, $appId]
        );
    }

    private static function publicUser(array $user): array
    {
        if (array_key_exists('integral', $user)) {
            $user['activity_credit'] = (int) $user['integral'];
            unset($user['integral']);
        }
        if (array_key_exists('balance', $user)) $user['balance'] = (float) $user['balance'];
        if (array_key_exists('experience', $user)) $user['experience'] = (int) $user['experience'];
        if (array_key_exists('document_credit', $user)) $user['document_credit'] = (int) $user['document_credit'];
        return $user;
    }
}
