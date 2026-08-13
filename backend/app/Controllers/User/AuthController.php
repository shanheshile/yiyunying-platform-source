<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\User;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Password;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Token;
use Yiyunying\Core\Validator;
use Yiyunying\Services\AdminAccessService;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\NotificationService;
use Yiyunying\Services\ProfileAvatarService;
use Yiyunying\Services\RewardRuleService;
use Yiyunying\Services\RolePermissionService;
use Yiyunying\Services\WalletLedgerService;
use Yiyunying\Services\WalletService;
use Yiyunying\Services\ContactVerificationService;
use Yiyunying\Services\IdentityService;
use Yiyunying\Services\LoginAttemptService;
use Yiyunying\Services\UserSessionService;

final class AuthController
{
    public static function register(Request $request): \Yiyunying\Core\ApiResponse
    {
        $data = $request->all();
        Validator::required($data, ['app_key', 'account', 'password', 'password_confirmation']);
        $app = AppService::byKey(trim((string) $data['app_key']));
        self::bindAppContext($request, $app);
        AppService::requireFeature((int) $app['id'], 'user_account');
        if (!AppService::setting((int) $app['id'], 'registration_enabled', true)) {
            throw new HttpException('当前应用已关闭用户注册', 403, 403);
        }

        $minAccount = min(64, max(1, (int) AppService::setting((int) $app['id'], 'account_min_length', 3)));
        $maxAccount = min(64, max($minAccount, (int) AppService::setting((int) $app['id'], 'account_max_length', 32)));
        $account = Validator::string($data['account'], 'account', $minAccount, $maxAccount);
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $account) !== 1) {
            throw new HttpException('account 只能包含字母、数字、下划线、点和短横线', 0, 422);
        }
        $password = self::validatePassword((string) $data['password']);
        if (!hash_equals($password, (string) $data['password_confirmation'])) {
            throw new HttpException('两次输入的密码不一致', 0, 422);
        }
        $contacts = IdentityService::validateRegistrationContacts((int) $app['id'], $data);
        $nickname = mb_substr($contacts['nickname'] === '' ? $account : $contacts['nickname'], 0, 80);
        $email = (string) $contacts['email'];
        $phone = (string) $contacts['phone'];
        $emailVerification = $email === '' ? null : ContactVerificationService::assertEmailCode(
            (int) $app['id'], 'register', $email, (string) ($data['email_code'] ?? '')
        );
        $uid = IdentityService::generateUid();
        $platformId = (int) (Database::one('SELECT platform_id FROM admins WHERE id = ?', [(int) $app['admin_id']])['platform_id'] ?? 0);
        if (Database::one('SELECT id FROM users WHERE app_id = ? AND account = ?', [(int) $app['id'], $account])) {
            throw new HttpException('该应用下账号已存在', 0, 409);
        }
        $dailyLimit = max(0, (int) AppService::setting((int) $app['id'], 'daily_register_limit', 1000));
        $ipLimit = max(0, (int) AppService::setting((int) $app['id'], 'register_ip_daily_limit', 10));
        $todayCount = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM users WHERE app_id = ? AND created_at >= CURDATE()',
            [(int) $app['id']]
        )['total'] ?? 0);
        $ipCount = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM users WHERE app_id = ? AND register_ip = ? AND created_at >= CURDATE()',
            [(int) $app['id'], $request->clientIp()]
        )['total'] ?? 0);
        if (($dailyLimit > 0 && $todayCount >= $dailyLimit) || ($ipLimit > 0 && $ipCount >= $ipLimit)) {
            throw new HttpException('今日注册数量已达到应用限制', 429, 429);
        }
        $initialCredit = max(0, (int) AppService::setting((int) $app['id'], 'initial_document_credit', 20));
        $initialBalance = max(0, (float) AppService::setting((int) $app['id'], 'user_initial_balance', 0));
        $initialActivityCredit = max(0, (int) AppService::setting((int) $app['id'], 'user_initial_activity_credit', 0));
        $freeVipDays = max(0, (int) AppService::setting((int) $app['id'], 'user_free_vip_days', 0));
        $vipOnly = (bool) AppService::setting((int) $app['id'], 'user_login_vip_only', false);
        $vipExpiredAt = $freeVipDays > 0 ? date('Y-m-d H:i:s', time() + $freeVipDays * 86400) : null;
        $publicProfile = AppService::setting((int) $app['id'], 'profile_public_default', true) ? 1 : 0;

        $result = Database::transaction(static function () use (
            $request,
            $app,
            $platformId,
            $uid,
            $account,
            $password,
            $nickname,
            $email,
            $phone,
            $initialCredit,
            $initialBalance,
            $initialActivityCredit,
            $freeVipDays,
            $vipOnly,
            $vipExpiredAt,
            $publicProfile,
            $emailVerification,
            $data
        ): array {
            $userId = Database::insert(
                'INSERT INTO users
                 (uid, admin_id, app_id, account, password_hash, email, phone, status, register_ip, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())',
                [
                    $uid,
                    (int) $app['admin_id'],
                    (int) $app['id'],
                    $account,
                    Password::hash($password),
                    $email === '' ? null : $email,
                    $phone === '' ? null : $phone,
                    $request->clientIp(),
                ]
            );
            IdentityService::bind('user', $userId, 'email', $email, $platformId, (int) $app['admin_id'], (int) $app['id'], $email !== '');
            IdentityService::bind('user', $userId, 'phone', $phone, $platformId, (int) $app['admin_id'], (int) $app['id'], false);
            if ($emailVerification !== null) ContactVerificationService::consume($emailVerification);
            Database::execute(
                'INSERT INTO user_profiles
                 (admin_id, app_id, user_id, nickname, qq, avatar, background, signature, gender, title, public_profile, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    (int) $app['admin_id'], (int) $app['id'], $userId, $nickname,
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
            $token = ['user_id' => $userId, 'access_token' => null, 'refresh_token' => null, 'expires_at' => null, 'refresh_expires_at' => null, 'invite_reward_context' => null];
            if (!$vipOnly || $freeVipDays > 0) {
                $token = self::issueUserToken(
                    $request,
                    $app,
                    $userId,
                    mb_substr((string) ($data['device'] ?? ''), 0, 100)
                );
            }
            if (trim((string) ($data['invite_code'] ?? '')) !== '') {
                $token['invite_reward_context'] = self::applyInvite([
                    'id' => $userId,
                    'admin_id' => (int) $app['admin_id'],
                    'app_id' => (int) $app['id'],
                ], trim((string) $data['invite_code']));
            }
            return $token;
        });

        $user = self::userData((int) $app['admin_id'], (int) $app['id'], (int) $result['user_id']);
        $registerReward = RewardRuleService::trigger(
            $user,
            'register',
            'user',
            (int) $user['id'],
            ['event_key' => 'register:' . (int) $user['id']]
        );
        $inviteReward = null;
        $inviteContext = $result['invite_reward_context'] ?? null;
        if (is_array($inviteContext) && (int) ($inviteContext['inviter_user_id'] ?? 0) > 0) {
            $inviter = Database::one(
                'SELECT id, admin_id, app_id FROM users
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1 AND deleted_at IS NULL',
                [
                    (int) $inviteContext['inviter_user_id'],
                    (int) $app['admin_id'],
                    (int) $app['id'],
                ]
            );
            if ($inviter !== null) {
                $inviteReward = RewardRuleService::trigger(
                    $inviter,
                    'invite_success',
                    'invite_relation',
                    (int) ($inviteContext['invite_relation_id'] ?? 0),
                    [
                        'event_key' => 'invite:' . (int) ($inviteContext['invite_relation_id'] ?? 0),
                        'invited_user_id' => (int) $user['id'],
                        'invite_code_id' => (int) ($inviteContext['invite_code_id'] ?? 0),
                    ]
                );
            }
        }
        $user = self::userData((int) $app['admin_id'], (int) $app['id'], (int) $result['user_id']);
        $request->setAttribute('actor_type', 'user');
        $request->setAttribute('actor_id', (int) $user['id']);
        LogService::userOperation($request, $user, 'auth', 'register');
        LogService::increment((int) $app['admin_id'], (int) $app['id'], 'new_users');
        return Response::success([
            'token_type' => 'Bearer',
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'expires_at' => $result['expires_at'],
            'refresh_expires_at' => $result['refresh_expires_at'],
            'app_key' => $app['app_key'],
            'uid' => $user['uid'],
            'requires_vip_activation' => $vipOnly && $freeVipDays === 0,
            'reward_result' => $registerReward,
            'invite_reward_result' => $inviteReward,
            'user' => $user,
        ], $vipOnly && $freeVipDays === 0 ? '注册成功，请开通会员后登录' : '注册成功', 201);
    }

    public static function login(Request $request): \Yiyunying\Core\ApiResponse
    {
        $data = $request->all();
        Validator::required($data, ['app_key', 'account', 'password']);
        $app = AppService::byKey(trim((string) $data['app_key']));
        self::bindAppContext($request, $app);
        AppService::requireFeature((int) $app['id'], 'user_account');
        if (!AppService::setting((int) $app['id'], 'login_enabled', true)) {
            throw new HttpException('当前应用已关闭用户登录', 403, 403);
        }

        $account = trim((string) $data['account']);
        $user = Database::one(
            'SELECT * FROM users WHERE admin_id = ? AND app_id = ? AND account = ? AND deleted_at IS NULL LIMIT 1',
            [(int) $app['admin_id'], (int) $app['id'], $account]
        );
        LoginAttemptService::assertUserAllowed(
            (int) $app['id'],
            $user === null ? null : (int) $user['id'],
            $request->clientIp()
        );
        if ($user === null || !Password::verify((string) $data['password'], (string) $user['password_hash'])) {
            self::writeLoginLog($app, $user, $request, false, '账号或密码错误');
            throw new HttpException('账号或密码错误', 401, 401);
        }
        if ((int) $user['status'] !== 1) {
            self::writeLoginLog($app, $user, $request, false, '用户已停用');
            throw new HttpException('用户账号已停用', 403, 403);
        }
        RolePermissionService::requireUserFeature($user, 'user_account');
        if (AppService::setting((int) $app['id'], 'user_login_vip_only', false)) {
            $wallet = Database::one('SELECT vip_expired_at FROM user_wallets WHERE user_id = ? AND app_id = ?', [
                (int) $user['id'], (int) $app['id'],
            ]);
            if ($wallet === null || $wallet['vip_expired_at'] === null
                || strtotime((string) $wallet['vip_expired_at']) <= time()) {
                self::writeLoginLog($app, $user, $request, false, '当前应用仅允许有效会员登录');
                throw new HttpException('当前应用仅允许有效会员登录，请先开通或续费会员', 403, 403, [
                    'reason_code' => 'vip_required',
                    'vip_expired_at' => $wallet['vip_expired_at'] ?? null,
                ]);
            }
        }

        if (Password::needsRehash((string) $user['password_hash'])) {
            Database::execute('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?', [
                Password::hash((string) $data['password']),
                (int) $user['id'],
            ]);
        }
        $device = mb_substr(trim((string) ($data['device'] ?? '')), 0, 100);
        $securityAlert = self::loginSecurityAlert($user, $request, $device);
        $token = self::issueUserToken(
            $request,
            $app,
            (int) $user['id'],
            $device
        );
        Database::execute(
            'UPDATE users SET last_login_ip = ?, last_login_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$request->clientIp(), (int) $user['id']]
        );
        $loginLogId = self::writeLoginLog($app, $user, $request, true, '登录成功');
        if ((bool) ($securityAlert['triggered'] ?? false)) {
            NotificationService::send(
                $user,
                'security_login',
                '检测到新设备登录',
                '登录设备：' . ($device === '' ? '未知设备' : $device)
                    . '；登录 IP：' . $request->clientIp()
                    . '。如果不是本人操作，请立即修改密码并检查账号安全设置。',
                [
                    'target_type' => 'security_login',
                    'target_id' => $loginLogId,
                    'reason' => (string) ($securityAlert['reason'] ?? 'new_device'),
                    'device' => $device,
                    'ip' => $request->clientIp(),
                ]
            );
        }
        $loginReward = RewardRuleService::trigger(
            $user,
            'login',
            'user_login_log',
            $loginLogId,
            ['event_key' => 'login:' . $loginLogId]
        );
        $user = self::userData((int) $app['admin_id'], (int) $app['id'], (int) $user['id']);
        $request->setAttribute('actor_type', 'user');
        $request->setAttribute('actor_id', (int) $user['id']);
        LogService::userOperation($request, $user, 'auth', 'login');
        LogService::increment((int) $app['admin_id'], (int) $app['id'], 'user_logins');

        return Response::success([
            'token_type' => 'Bearer',
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'expires_at' => $token['expires_at'],
            'refresh_expires_at' => $token['refresh_expires_at'],
            'app_key' => $app['app_key'],
            'reward_result' => $loginReward,
            'security_alert' => $securityAlert,
            'user' => $user,
        ], '登录成功');
    }

    public static function logout(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        AuthService::revokeCurrentUserToken($request);
        Database::execute(
            "UPDATE user_presence SET status = 'offline', online_until = NOW(), updated_at = NOW()
             WHERE user_id = ? AND app_id = ?",
            [(int) $user['id'], (int) $user['app_id']]
        );
        LogService::userOperation($request, $user, 'auth', 'logout');
        return Response::success([], '已退出登录');
    }

    public static function me(Request $request): \Yiyunying\Core\ApiResponse
    {
        $authUser = AuthService::user($request);
        return Response::success([
            'user' => self::userData((int) $authUser['admin_id'], (int) $authUser['app_id'], (int) $authUser['id']),
        ]);
    }

    public static function permissions(Request $request): \Yiyunying\Core\ApiResponse
    {
        $authUser = AuthService::user($request);
        $user = Database::one(
            'SELECT u.id, u.uid, u.admin_id, u.app_id, u.account, u.status, p.nickname
             FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id AND p.app_id = u.app_id
             WHERE u.id = ? AND u.admin_id = ? AND u.app_id = ? AND u.deleted_at IS NULL
             LIMIT 1',
            [(int) $authUser['id'], (int) $authUser['admin_id'], (int) $authUser['app_id']]
        );
        if ($user === null) {
            throw new HttpException('用户不存在或已被删除', 404, 404);
        }
        return Response::success(
            RolePermissionService::userPayload($user, 4),
            '权限与限制读取成功'
        );
    }

    public static function features(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        return Response::success([
            'features' => AppService::effectiveFeaturesForUser($user),
        ], '用户有效功能读取成功');
    }

    public static function heartbeat(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $onlineSeconds = min(3600, max(30, (int) AppService::setting(
            (int) $user['app_id'], 'heartbeat_online_seconds', 180
        )));
        $onlineUntil = date('Y-m-d H:i:s', time() + $onlineSeconds);
        $device = mb_substr(trim((string) $request->input('device', '')), 0, 100);
        $ipHash = hash('sha256', (int) $user['app_id'] . "\0" . $request->clientIp());
        Database::execute(
            "INSERT INTO user_presence
             (admin_id, app_id, user_id, status, device, last_ip_hash, last_heartbeat_at,
              online_until, created_at, updated_at)
             VALUES (?, ?, ?, 'online', ?, ?, NOW(), ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE status = 'online', device = VALUES(device),
               last_ip_hash = VALUES(last_ip_hash), last_heartbeat_at = NOW(),
               online_until = VALUES(online_until), updated_at = NOW()",
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
                $device, $ipHash, $onlineUntil,
            ]
        );
        Database::execute(
            'INSERT INTO statistics_daily
             (admin_id, app_id, stat_date, heartbeat_count, created_at, updated_at)
             VALUES (?, ?, CURDATE(), 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE heartbeat_count = heartbeat_count + 1, updated_at = NOW()',
            [(int) $user['admin_id'], (int) $user['app_id']]
        );
        return Response::success([
            'status' => 'online',
            'status_name' => '在线',
            'heartbeat_interval_seconds' => max(10, (int) floor($onlineSeconds / 2)),
            'online_until' => $onlineUntil,
            'server_time' => date('Y-m-d H:i:s'),
        ], '在线状态已更新');
    }

    public static function wallet(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $wallet = Database::one(
            'SELECT integral, experience, balance, document_credit, vip_expired_at, level_code, updated_at
             FROM user_wallets WHERE admin_id = ? AND app_id = ? AND user_id = ?',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        $purchaseFeatures = RolePermissionService::effectiveUserFeatures($user, [
            'balance_document_purchase', 'balance_membership_purchase',
        ]);
        return Response::success([
            'wallet' => WalletService::publicWallet($wallet ?? [], (int) $user['app_id']),
            'purchase_rules' => [
                'document_credit_enabled' => (bool) ($purchaseFeatures['balance_document_purchase']['effective_enabled'] ?? false)
                    && AppService::setting((int) $user['app_id'], 'balance_document_purchase_enabled', false),
                'document_credit_unit_price' => (float) AppService::setting((int) $user['app_id'], 'document_credit_balance_price', 1),
                'membership_enabled' => (bool) ($purchaseFeatures['balance_membership_purchase']['effective_enabled'] ?? false)
                    && AppService::setting((int) $user['app_id'], 'balance_membership_purchase_enabled', false),
                'membership_day_unit_price' => (float) AppService::setting((int) $user['app_id'], 'vip_day_balance_price', 1),
            ],
        ]);
    }

    public static function walletLogs(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        return Response::success(WalletLedgerService::paginate($request, $user));
    }

    public static function purchase(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $productType = trim((string) $request->input('product_type', ''));
        $quantity = Validator::integer($request->input('quantity', 1), 'quantity', 1, 10000);
        $result = WalletService::purchase($user, $productType, $quantity);
        LogService::userOperation($request, $user, 'wallet', 'purchase', (int) $result['purchase_id'], [
            'product_type' => $productType,
            'quantity' => $quantity,
            'total_balance' => $result['total_balance'],
        ]);
        return Response::success($result, '购买成功', 201);
    }

    public static function purchases(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM user_asset_purchases WHERE user_id = ? AND app_id = ?',
            [(int) $user['id'], (int) $user['app_id']]
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT id, order_no, product_type, quantity, unit_price, total_amount AS total_balance,
                    status, completed_at, created_at
             FROM user_asset_purchases WHERE user_id = ? AND app_id = ? ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            [(int) $user['id'], (int) $user['app_id']]
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function refresh(Request $request): \Yiyunying\Core\ApiResponse
    {
        $data = $request->all();
        Validator::required($data, ['refresh_token']);
        $tokenHash = Token::hash((string) $data['refresh_token']);
        $result = Database::transaction(static function () use ($tokenHash, $request): array {
            $row = Database::one(
                'SELECT r.*, u.status AS user_status, u.deleted_at AS user_deleted_at,
                        a.id AS resolved_app_id, a.admin_id AS resolved_admin_id, a.app_key,
                        a.status AS app_status, a.deleted_at AS app_deleted_at,
                        ad.status AS admin_status,
                        p.id AS resolved_platform_id, p.status AS platform_status,
                        p.deleted_at AS platform_deleted_at
                 FROM user_refresh_tokens r
                 INNER JOIN users u ON u.id = r.user_id AND u.app_id = r.app_id AND u.admin_id = r.admin_id
                 INNER JOIN apps a ON a.id = r.app_id AND a.admin_id = r.admin_id
                 INNER JOIN admins ad ON ad.id = r.admin_id
                 INNER JOIN platform_accounts p ON p.id = ad.platform_id
                 WHERE r.token_hash = ? AND r.revoked_at IS NULL AND r.expired_at > NOW()
                 LIMIT 1 FOR UPDATE',
                [$tokenHash]
            );
            if ($row === null
                || (int) $row['user_status'] !== 1 || $row['user_deleted_at'] !== null
                || (int) $row['app_status'] !== 1 || $row['app_deleted_at'] !== null
                || (int) $row['admin_status'] !== 1
                || (int) $row['platform_status'] !== 1 || $row['platform_deleted_at'] !== null) {
                throw new HttpException('刷新令牌无效或已过期', 401, 401);
            }
            $providedAppKey = trim((string) ($request->header('x-app-key') ?? $request->input('app_key', $row['app_key'])));
            if (!hash_equals((string) $row['app_key'], $providedAppKey)) {
                throw new HttpException('刷新令牌与应用不匹配', 403, 403);
            }
            $admin = AdminAccessService::context((int) $row['resolved_admin_id']);
            AdminAccessService::assertDownstreamAccess($admin);
            Database::execute(
                'UPDATE user_refresh_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL',
                [(int) $row['id']]
            );
            if ($row['user_token_id'] !== null) {
                Database::execute(
                    'UPDATE user_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL',
                    [(int) $row['user_token_id']]
                );
            }
            $app = [
                'id' => (int) $row['resolved_app_id'],
                'admin_id' => (int) $row['resolved_admin_id'],
                'app_key' => $row['app_key'],
            ];
            return [
                'row' => $row,
                'token' => self::issueUserToken($request, $app, (int) $row['user_id'], 'refresh'),
            ];
        });
        $row = $result['row'];
        $newToken = $result['token'];
        $request->setAttribute('actor_type', 'user');
        $request->setAttribute('actor_id', (int) $row['user_id']);
        $request->setAttribute('admin_id', (int) $row['admin_id']);
        $request->setAttribute('app_id', (int) $row['app_id']);
        $request->setAttribute('platform_id', (int) $row['resolved_platform_id']);
        return Response::success([
            'token_type' => 'Bearer',
            'access_token' => $newToken['access_token'],
            'refresh_token' => $newToken['refresh_token'],
            'expires_at' => $newToken['expires_at'],
            'refresh_expires_at' => $newToken['refresh_expires_at'],
            'app_key' => $row['app_key'],
        ], '令牌刷新成功');
    }

    public static function profile(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        AppService::requireFeature((int) $user['app_id'], 'user_profile');
        if (!AppService::setting((int) $user['app_id'], 'profile_edit_enabled', true)) {
            throw new HttpException('当前应用已关闭资料修改', 403, 403);
        }
        $data = $request->all();
        $profileFields = [];
        $profileValues = [];
        foreach (['nickname' => 80, 'qq' => 30, 'signature' => 500, 'avatar' => 500, 'background' => 500, 'gender' => 20] as $field => $max) {
            if (array_key_exists($field, $data)) {
                $profileFields[] = "{$field} = ?";
                $profileValues[] = mb_substr(trim((string) $data[$field]), 0, $max);
            }
        }
        if (array_key_exists('public_profile', $data)) {
            $profileFields[] = 'public_profile = ?';
            $profileValues[] = Validator::boolean($data['public_profile'], 'public_profile') ? 1 : 0;
        }
        $accountFields = [];
        $accountValues = [];
        $identityUpdates = [];
        foreach (['email' => 190, 'phone' => 40] as $field => $max) {
            if (array_key_exists($field, $data)) {
                $value = mb_substr(IdentityService::normalize($field, (string) $data[$field]), 0, $max);
                $current = IdentityService::normalize($field, (string) ($user[$field] ?? ''));
                if ($value === $current) continue;
                if ($current !== '') {
                    throw new HttpException('已绑定的' . ($field === 'email' ? '邮箱' : '手机号') . '不能直接替换，请先提交解绑申请并等待管理员审核', 0, 409);
                }
                if ($value === '') continue;
                IdentityService::assertAvailable($field, $value, 'user', (int) $user['id']);
                $verification = $field === 'email'
                    ? ContactVerificationService::assertEmailCode((int) $user['app_id'], 'profile_email', $value, (string) ($data['email_code'] ?? ''))
                    : null;
                $accountFields[] = "{$field} = ?";
                $accountValues[] = $value;
                $identityUpdates[] = ['type' => $field, 'value' => $value, 'verification' => $verification];
            }
        }
        if ($profileFields === [] && $accountFields === []) {
            throw new HttpException('没有可修改的字段', 0, 422);
        }
        $platformId = (int) (Database::one('SELECT platform_id FROM admins WHERE id = ?', [(int) $user['admin_id']])['platform_id'] ?? 0);
        Database::transaction(static function () use ($user, $platformId, $profileFields, $profileValues, $accountFields, $accountValues, $identityUpdates): void {
            if ($profileFields !== []) {
                Database::execute(
                    'UPDATE user_profiles SET ' . implode(', ', $profileFields) . ', updated_at = NOW()
                     WHERE user_id = ? AND admin_id = ? AND app_id = ?',
                    array_merge($profileValues, [(int) $user['id'], (int) $user['admin_id'], (int) $user['app_id']])
                );
            }
            if ($accountFields !== []) {
                Database::execute(
                    'UPDATE users SET ' . implode(', ', $accountFields) . ', updated_at = NOW()
                     WHERE id = ? AND admin_id = ? AND app_id = ?',
                    array_merge($accountValues, [(int) $user['id'], (int) $user['admin_id'], (int) $user['app_id']])
                );
            }
            foreach ($identityUpdates as $identity) {
                IdentityService::bind('user', (int) $user['id'], (string) $identity['type'], (string) $identity['value'],
                    $platformId, (int) $user['admin_id'], (int) $user['app_id'], $identity['type'] === 'email');
                if ($identity['verification'] !== null) ContactVerificationService::consume($identity['verification']);
            }
        });
        LogService::userOperation($request, $user, 'profile', 'update');
        return Response::success(['user' => self::userData((int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'])], '资料修改成功');
    }

    public static function password(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $data = $request->all();
        Validator::required($data, ['old_password', 'new_password']);
        if (!Password::verify((string) $data['old_password'], (string) $user['password_hash'])) {
            throw new HttpException('原密码错误', 0, 422);
        }
        $newPassword = self::validatePassword((string) $data['new_password']);
        Database::transaction(static function () use ($user, $newPassword): void {
            Database::execute('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?', [
                Password::hash($newPassword), (int) $user['id'],
            ]);
            Database::execute('UPDATE user_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [(int) $user['id']]);
            Database::execute('UPDATE user_refresh_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [(int) $user['id']]);
        });
        LogService::userOperation($request, $user, 'profile', 'password_change');
        return Response::success([], '密码修改成功，请重新登录');
    }

    public static function avatar(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        AppService::requireFeature((int) $user['app_id'], 'user_profile');
        if (!AppService::setting((int) $user['app_id'], 'profile_edit_enabled', true)) {
            throw new HttpException('当前应用已关闭资料修改', 403, 403);
        }
        $result = ProfileAvatarService::upload('user', (int) $user['id']);
        Database::transaction(static function () use ($user, $result): void {
            Database::execute('UPDATE user_avatar_history SET is_current = 0 WHERE user_id = ?', [(int) $user['id']]);
            Database::execute(
                'INSERT INTO user_avatar_history
                 (admin_id, app_id, user_id, avatar_url, sha256, is_current, created_at)
                 VALUES (?, ?, ?, ?, ?, 1, NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
                 (string) $result['avatar'], (string) $result['sha256']]
            );
            Database::execute('UPDATE user_profiles SET avatar = ?, updated_at = NOW() WHERE user_id = ? AND app_id = ?', [
                $result['avatar'], (int) $user['id'], (int) $user['app_id'],
            ]);
        });
        LogService::userOperation($request, $user, 'profile', 'avatar_update');
        return Response::success($result + ['history' => self::avatarHistoryItems($user)], '用户头像上传成功', 201);
    }

    public static function avatarHistory(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        AppService::requireFeature((int) $user['app_id'], 'user_profile');
        return Response::success(['items' => self::avatarHistoryItems($user)]);
    }

    public static function switchAvatar(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        AppService::requireFeature((int) $user['app_id'], 'user_profile');
        if (!AppService::setting((int) $user['app_id'], 'profile_edit_enabled', true)) {
            throw new HttpException('当前应用已关闭资料修改', 403, 403);
        }
        $history = Database::one(
            'SELECT * FROM user_avatar_history WHERE id = ? AND user_id = ? AND app_id = ?',
            [(int) ($params['history_id'] ?? 0), (int) $user['id'], (int) $user['app_id']]
        );
        if ($history === null) throw new HttpException('历史头像不存在', 404, 404);
        Database::transaction(static function () use ($user, $history): void {
            Database::execute('UPDATE user_avatar_history SET is_current = 0 WHERE user_id = ?', [(int) $user['id']]);
            Database::execute('UPDATE user_avatar_history SET is_current = 1 WHERE id = ?', [(int) $history['id']]);
            Database::execute('UPDATE user_profiles SET avatar = ?, updated_at = NOW() WHERE user_id = ? AND app_id = ?', [
                (string) $history['avatar_url'], (int) $user['id'], (int) $user['app_id'],
            ]);
        });
        LogService::userOperation($request, $user, 'profile', 'avatar_switch', (int) $history['id']);
        return Response::success([
            'avatar' => (string) $history['avatar_url'], 'history_id' => (int) $history['id'],
            'cache_version' => time(), 'history' => self::avatarHistoryItems($user),
        ], '已切换历史头像');
    }

    public static function resetPassword(Request $request): \Yiyunying\Core\ApiResponse
    {
        $data = $request->all();
        Validator::required($data, ['app_key', 'account', 'email_or_phone', 'code', 'new_password']);
        $app = AppService::byKey(trim((string) $data['app_key']));
        self::bindAppContext($request, $app);
        if (!AppService::setting((int) $app['id'], 'password_reset_enabled', true)) {
            throw new HttpException('当前应用已关闭找回密码', 403, 403);
        }
        $account = trim((string) $data['account']);
        $user = Database::one(
            'SELECT * FROM users WHERE admin_id = ? AND app_id = ? AND account = ? AND deleted_at IS NULL',
            [(int) $app['admin_id'], (int) $app['id'], $account]
        );
        if ($user === null) {
            throw new HttpException('用户不存在', 404, 404);
        }
        $contact = trim((string) $data['email_or_phone']);
        if (($user['email'] === null || !hash_equals((string) $user['email'], $contact))
            && ($user['phone'] === null || !hash_equals((string) $user['phone'], $contact))) {
            throw new HttpException('邮箱或手机号与账号不匹配', 0, 422);
        }
        $code = Database::one(
            'SELECT * FROM verification_codes WHERE admin_id = ? AND app_id = ? AND scene = ? AND target = ?
             AND used_at IS NULL AND expired_at > NOW() ORDER BY id DESC LIMIT 1',
            [(int) $app['admin_id'], (int) $app['id'], 'password_reset', $account]
        );
        if ($code === null || (int) $code['attempts'] >= 5
            || !hash_equals((string) $code['code_hash'], hash('sha256', (string) $data['code']))) {
            if ($code !== null) {
                Database::execute('UPDATE verification_codes SET attempts = attempts + 1 WHERE id = ?', [(int) $code['id']]);
            }
            throw new HttpException('验证码错误或已过期', 0, 422);
        }
        if (isset($data['new_password_confirmation'])
            && !hash_equals((string) $data['new_password'], (string) $data['new_password_confirmation'])) {
            throw new HttpException('两次输入的新密码不一致', 0, 422);
        }
        $newPassword = self::validatePassword((string) $data['new_password']);
        Database::transaction(static function () use ($user, $newPassword, $code): void {
            Database::execute('UPDATE verification_codes SET used_at = NOW() WHERE id = ?', [(int) $code['id']]);
            Database::execute('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?', [Password::hash($newPassword), (int) $user['id']]);
            Database::execute('UPDATE user_tokens SET revoked_at = NOW() WHERE user_id = ?', [(int) $user['id']]);
            Database::execute('UPDATE user_refresh_tokens SET revoked_at = NOW() WHERE user_id = ?', [(int) $user['id']]);
        });
        return Response::success([], '密码重置成功');
    }

    public static function issuePasswordResetCode(Request $request): \Yiyunying\Core\ApiResponse
    {
        $data = $request->all();
        Validator::required($data, ['app_key', 'account', 'email_or_phone']);
        $app = AppService::byKey(trim((string) $data['app_key']));
        self::bindAppContext($request, $app);
        if (!AppService::setting((int) $app['id'], 'password_reset_enabled', true)) {
            throw new HttpException('当前应用已关闭找回密码', 403, 403);
        }
        $result = ContactVerificationService::issuePasswordReset(
            $app, (string) $data['account'], (string) $data['email_or_phone'], $request
        );
        return Response::success($result, ContactVerificationService::deliveryResponseMessage($result), 202);
    }

    public static function sign(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        AppService::requireFeature((int) $user['app_id'], 'sign_invite');
        if (!AppService::setting((int) $user['app_id'], 'sign_enabled', true)) {
            throw new HttpException('当前应用已关闭签到', 403, 403);
        }
        if (Database::one('SELECT id FROM user_sign_logs WHERE app_id = ? AND user_id = ? AND sign_date = CURDATE()', [
            (int) $user['app_id'], (int) $user['id'],
        ])) {
            throw new HttpException('今天已经签到', 0, 409);
        }
        $last = Database::one(
            'SELECT sign_date, continuous_days FROM user_sign_logs WHERE app_id = ? AND user_id = ? ORDER BY sign_date DESC LIMIT 1',
            [(int) $user['app_id'], (int) $user['id']]
        );
        $continuous = $last !== null && $last['sign_date'] === date('Y-m-d', strtotime('-1 day'))
            ? (int) $last['continuous_days'] + 1
            : 1;
        $rewardBalance = max(0, (int) AppService::setting((int) $user['app_id'], 'sign_reward_balance', 10));
        $primaryAsset = WalletService::primaryAsset((int) $user['app_id']);
        $legacyRewards = [
            $primaryAsset => $rewardBalance,
            'experience' => max(0, (int) AppService::setting((int) $user['app_id'], 'sign_reward_experience', 5)),
            'document_credit' => max(0, (int) AppService::setting((int) $user['app_id'], 'sign_reward_credit', 0)),
        ];
        $managedByRule = RewardRuleService::enabled(
            (int) $user['admin_id'],
            (int) $user['app_id'],
            'daily_sign'
        );
        $signResult = Database::transaction(static function () use (
            $user,
            $continuous,
            $legacyRewards,
            $rewardBalance,
            $managedByRule
        ): array {
            $logId = Database::insert(
                'INSERT INTO user_sign_logs
                 (admin_id, app_id, user_id, sign_date, reward_integral, reward_experience, reward_credit, continuous_days, created_at)
                 VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, NOW())',
                [
                    (int) $user['admin_id'],
                    (int) $user['app_id'],
                    (int) $user['id'],
                    $managedByRule ? 0 : $rewardBalance,
                    $managedByRule ? 0 : (int) $legacyRewards['experience'],
                    $managedByRule ? 0 : (int) $legacyRewards['document_credit'],
                    $continuous,
                ]
            );
            $wallet = $managedByRule
                ? WalletService::applyRewards($user, [], 'daily_sign', 'sign', $logId)
                : WalletService::applyRewards($user, $legacyRewards, 'daily_sign', 'sign', $logId);
            return ['log_id' => $logId, 'wallet' => $wallet];
        });

        $rewardResult = [
            'granted' => false,
            'status' => 'legacy',
            'message' => '已按应用原签到配置发放',
        ];
        if ($managedByRule) {
            $rewardResult = RewardRuleService::trigger(
                $user,
                'daily_sign',
                'sign',
                (int) $signResult['log_id'],
                [
                    'event_key' => 'sign:' . (int) $signResult['log_id'],
                    'continuous_days' => $continuous,
                ]
            );
        }

        if ($managedByRule) {
            $ruleReward = is_array($rewardResult['reward'] ?? null) ? $rewardResult['reward'] : [];
            $publicRewards = [
                'balance' => (float) ($ruleReward[$primaryAsset] ?? 0),
                'activity_credit' => (int) ($ruleReward['integral'] ?? 0),
                'experience' => (int) ($ruleReward['experience'] ?? 0),
                'document_credit' => (int) ($ruleReward['document_credit'] ?? 0),
                'vip_days' => (int) ($ruleReward['vip_days'] ?? 0),
            ];
            $publicWallet = is_array($rewardResult['wallet'] ?? null)
                ? $rewardResult['wallet']
                : WalletService::publicWallet((array) $signResult['wallet'], (int) $user['app_id']);
            $appliedRewards = $ruleReward;
        } else {
            $publicRewards = $legacyRewards;
            $publicRewards['balance'] = $rewardBalance;
            unset($publicRewards['integral']);
            $publicWallet = WalletService::publicWallet((array) $signResult['wallet'], (int) $user['app_id']);
            $appliedRewards = $legacyRewards;
        }

        LogService::userOperation(
            $request,
            $user,
            'sign',
            'daily',
            (int) $signResult['log_id'],
            [
                'continuous_days' => $continuous,
                'rewards' => $appliedRewards,
                'reward_engine' => $managedByRule ? 'rule' : 'legacy',
            ]
        );
        return Response::success([
            'continuous_days' => $continuous,
            'rewards' => $publicRewards,
            'reward_result' => $rewardResult,
            'wallet' => $publicWallet,
        ], '签到成功');
    }

    public static function logs(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one('SELECT COUNT(*) AS total FROM user_operation_logs WHERE user_id = ?', [(int) $user['id']])['total'] ?? 0);
        $items = Database::all(
            "SELECT id, module, action, target_id, detail_json, ip, created_at
             FROM user_operation_logs WHERE user_id = ? ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            [(int) $user['id']]
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function inviteCode(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        AppService::requireFeature((int) $user['app_id'], 'sign_invite');
        if (!AppService::setting((int) $user['app_id'], 'invite_enabled', true)) {
            throw new HttpException('当前应用已关闭邀请功能', 403, 403);
        }
        $existing = Database::one(
            'SELECT * FROM invite_codes WHERE app_id = ? AND user_id = ? AND status = 1 ORDER BY id DESC LIMIT 1',
            [(int) $user['app_id'], (int) $user['id']]
        );
        if ($existing !== null) {
            return Response::success(['invite' => $existing]);
        }
        $code = strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
        $id = Database::insert(
            'INSERT INTO invite_codes
             (admin_id, app_id, user_id, invite_code, max_use, used_count, reward_json, status, created_at)
             VALUES (?, ?, ?, ?, 0, 0, ?, 1, NOW())',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $code,
                json_encode([
                    WalletService::primaryAsset((int) $user['app_id'])
                        => (int) AppService::setting((int) $user['app_id'], 'invite_reward_balance', 20),
                ]),
            ]
        );
        return Response::success(['invite' => [
            'id' => $id,
            'invite_code' => $code,
            'max_use' => 0,
            'used_count' => 0,
            'status' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]], '邀请码生成成功', 201);
    }

    public static function invites(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $items = Database::all(
            'SELECT r.id, r.invited_user_id, u.account, p.nickname, r.reward_status, r.created_at
             FROM invite_relations r INNER JOIN users u ON u.id = r.invited_user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE r.app_id = ? AND r.inviter_user_id = ? ORDER BY r.id DESC',
            [(int) $user['app_id'], (int) $user['id']]
        );
        return Response::success(['items' => $items]);
    }

    public static function rankings(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $asset = trim((string) $request->input('asset', 'balance'));
        if (!in_array($asset, ['balance', 'experience', 'document_credit', 'activity_credit'], true)) {
            throw new HttpException('不支持的排行榜类型', 0, 422);
        }
        $databaseAsset = $asset === 'activity_credit' ? 'integral' : $asset;
        $limit = min(100, max(1, (int) $request->input('limit', 20)));
        $items = Database::all(
            "SELECT u.id AS user_id, u.account, p.nickname, p.avatar, w.{$databaseAsset} AS score
             FROM user_wallets w INNER JOIN users u ON u.id = w.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE w.app_id = ? AND u.status = 1 AND u.deleted_at IS NULL
             ORDER BY w.{$databaseAsset} DESC, u.id ASC LIMIT {$limit}",
            [(int) $user['app_id']]
        );
        foreach ($items as $index => &$item) {
            $item['rank'] = $index + 1;
        }
        unset($item);
        return Response::success(['asset' => $asset, 'items' => $items]);
    }

    private static function validatePassword(string $password): string
    {
        return Password::assertAcceptable($password);
    }

    private static function issueUserToken(Request $request, array $app, int $userId, string $device): array
    {
        return UserSessionService::issue($request, $app, $userId, $device);
    }

    private static function loginSecurityAlert(array $user, Request $request, string $device): array
    {
        $preference = Database::one(
            'SELECT remote_login_protection FROM user_message_preferences WHERE user_id = ? AND app_id = ? LIMIT 1',
            [(int) $user['id'], (int) $user['app_id']]
        );
        $enabled = $preference === null || (bool) $preference['remote_login_protection'];
        if (!$enabled) {
            return ['enabled' => false, 'triggered' => false, 'reason' => 'disabled'];
        }

        $previous = Database::one(
            'SELECT device, ip, created_at FROM user_tokens
             WHERE user_id = ? AND app_id = ? AND revoked_at IS NULL AND expired_at > NOW()
             ORDER BY id DESC LIMIT 1',
            [(int) $user['id'], (int) $user['app_id']]
        );
        if ($previous === null) {
            return ['enabled' => true, 'triggered' => false, 'reason' => 'first_device'];
        }

        $previousDevice = trim((string) ($previous['device'] ?? ''));
        $previousIp = trim((string) ($previous['ip'] ?? ''));
        $currentIp = trim($request->clientIp());
        $deviceChanged = $device !== '' && $previousDevice !== '' && !hash_equals($previousDevice, $device);
        $networkChangedWithoutDevice = ($device === '' || $previousDevice === '')
            && $currentIp !== '' && $previousIp !== '' && !hash_equals($previousIp, $currentIp);
        $triggered = $deviceChanged || $networkChangedWithoutDevice;
        return [
            'enabled' => true,
            'triggered' => $triggered,
            'reason' => $deviceChanged ? 'new_device' : ($networkChangedWithoutDevice ? 'new_network' : 'trusted_device'),
            'previous_device' => $previousDevice,
            'current_device' => $device,
            'previous_login_at' => $previous['created_at'] ?? null,
        ];
    }

    private static function applyInvite(array $invitedUser, string $inviteCode): array
    {
        $invite = Database::one(
            'SELECT * FROM invite_codes WHERE app_id = ? AND invite_code = ? AND status = 1 FOR UPDATE',
            [(int) $invitedUser['app_id'], $inviteCode]
        );
        if ($invite === null
            || ((int) $invite['max_use'] > 0 && (int) $invite['used_count'] >= (int) $invite['max_use'])
            || ($invite['expired_at'] !== null && strtotime((string) $invite['expired_at']) < time())) {
            throw new HttpException('邀请码无效或已过期', 0, 422);
        }
        if ((int) $invite['user_id'] === (int) $invitedUser['id']) {
            throw new HttpException('不能使用自己的邀请码', 0, 422);
        }
        $relationId = Database::insert(
            'INSERT INTO invite_relations
             (admin_id, app_id, invite_code, inviter_user_id, invited_user_id, reward_status, created_at)
             VALUES (?, ?, ?, ?, ?, 0, NOW())',
            [
                (int) $invitedUser['admin_id'],
                (int) $invitedUser['app_id'],
                $inviteCode,
                (int) $invite['user_id'],
                (int) $invitedUser['id'],
            ]
        );
        Database::execute('UPDATE invite_codes SET used_count = used_count + 1 WHERE id = ?', [(int) $invite['id']]);
        $rewards = json_decode((string) $invite['reward_json'], true);
        if (is_array($rewards) && $rewards !== []) {
            $inviter = [
                'id' => (int) $invite['user_id'],
                'admin_id' => (int) $invitedUser['admin_id'],
                'app_id' => (int) $invitedUser['app_id'],
            ];
            WalletService::applyRewards($inviter, $rewards, 'invite_reward', 'invite_relation', $relationId);
        }
        Database::execute(
            'UPDATE invite_relations SET reward_status = 1 WHERE id = ?',
            [$relationId]
        );
        return [
            'inviter_user_id' => (int) $invite['user_id'],
            'invite_relation_id' => $relationId,
            'invite_code_id' => (int) $invite['id'],
        ];
    }
    private static function userData(int $adminId, int $appId, int $userId): array
    {
        return UserSessionService::userData($adminId, $appId, $userId);
    }

    private static function avatarHistoryItems(array $user): array
    {
        return Database::all(
            'SELECT id, avatar_url AS avatar, sha256, is_current, created_at
             FROM user_avatar_history WHERE user_id = ? AND app_id = ? ORDER BY is_current DESC, id DESC LIMIT 100',
            [(int) $user['id'], (int) $user['app_id']]
        );
    }

    private static function bindAppContext(Request $request, array $app): void
    {
        $request->setAttribute('admin_id', (int) $app['admin_id']);
        $request->setAttribute('app_id', (int) $app['id']);
    }

    private static function writeLoginLog(
        array $app,
        ?array $user,
        Request $request,
        bool $success,
        string $reason
    ): int {
        return Database::insert(
            'INSERT INTO user_login_logs
             (admin_id, app_id, user_id, ip, user_agent, result, reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                (int) $app['admin_id'],
                (int) $app['id'],
                $user === null ? null : (int) $user['id'],
                $request->clientIp(),
                $request->userAgent(),
                $success ? 1 : 0,
                $reason,
            ]
        );
    }
}
