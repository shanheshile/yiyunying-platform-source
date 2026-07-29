<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Password;
use Yiyunying\Core\Request;
use Yiyunying\Core\Validator;

final class AdminProvisionService
{
    public static function publicProvision(array $platform, array $data, Request $request): array
    {
        Validator::required($data, ['password_confirmation']);
        if (!hash_equals((string) ($data['password'] ?? ''), (string) $data['password_confirmation'])) {
            throw new HttpException('两次输入的密码不一致', 0, 422);
        }
        return Database::transaction(static function () use ($platform, $data, $request): array {
            $locked = self::lockPlatform((int) $platform['id']);
            PlatformService::assertActive($locked);
            self::assertPublicRegistrationAllowed($locked, $request);
            $admin = self::provision($locked, $data, $request);
            self::writeRegistrationLog(
                $locked,
                $request,
                (string) $admin['account'],
                true,
                '注册成功',
                (int) $admin['id'],
                (array) ($admin['registration_gift'] ?? [])
            );
            return $admin;
        });
    }

    public static function managedProvision(
        array $platform,
        array $data,
        Request $request,
        array $grant,
        string $reason = '平台创建'
    ): array {
        return Database::transaction(static function () use ($platform, $data, $request, $grant, $reason): array {
            $locked = self::lockPlatform((int) $platform['id']);
            PlatformService::assertActive($locked);
            PlatformService::requireAdminQuota($locked);
            $admin = self::provision($locked, $data, $request, $grant);
            self::writeRegistrationLog(
                $locked,
                $request,
                (string) $admin['account'],
                true,
                $reason,
                (int) $admin['id'],
                $grant
            );
            return $admin;
        });
    }

    public static function assertPublicRegistrationAllowed(array $platform, Request $request): void
    {
        $platformId = (int) $platform['id'];
        if (!PlatformService::setting($platformId, 'admin_registration_enabled', true)) {
            throw new HttpException('当前平台已关闭 admin 注册', 403, 403);
        }
        PlatformService::requireAdminQuota($platform);
        $dailyLimit = max(0, (int) PlatformService::setting($platformId, 'admin_daily_register_limit', 100));
        $ipDailyLimit = max(0, (int) PlatformService::setting($platformId, 'admin_ip_daily_register_limit', 3));
        $ipTotalLimit = max(0, (int) PlatformService::setting($platformId, 'admin_ip_total_register_limit', 10));
        $daily = self::registrationCount($platformId, null, true);
        $ipDaily = self::registrationCount($platformId, $request->clientIp(), true);
        $ipTotal = self::registrationCount($platformId, $request->clientIp(), false);
        if (($dailyLimit > 0 && $daily >= $dailyLimit)
            || ($ipDailyLimit > 0 && $ipDaily >= $ipDailyLimit)
            || ($ipTotalLimit > 0 && $ipTotal >= $ipTotalLimit)) {
            throw new HttpException('admin 注册数量已达到平台限制', 429, 429, [
                'daily' => ['used' => $daily, 'limit' => $dailyLimit],
                'ip_daily' => ['used' => $ipDaily, 'limit' => $ipDailyLimit],
                'ip_total' => ['used' => $ipTotal, 'limit' => $ipTotalLimit],
            ]);
        }
    }

    public static function provision(
        array $platform,
        array $data,
        Request $request,
        ?array $customGrant = null
    ): array {
        $platformId = (int) $platform['id'];
        $min = max(1, (int) PlatformService::setting($platformId, 'admin_account_min_length', 3));
        $max = min(64, max($min, (int) PlatformService::setting($platformId, 'admin_account_max_length', 32)));
        Validator::required($data, ['account', 'password']);
        $account = Validator::string($data['account'], 'account', $min, $max);
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $account) !== 1) {
            throw new HttpException('account 只能包含字母、数字、下划线、点和短横线', 0, 422);
        }
        if (Database::one('SELECT id FROM admins WHERE platform_id = ? AND account = ?', [$platformId, $account])) {
            throw new HttpException('当前平台下 admin 账号已存在', 0, 409);
        }
        $password = (string) $data['password'];
        if (strlen($password) < 6 || strlen($password) > 72) {
            throw new HttpException('password 长度必须在 6-72 个字节之间', 0, 422);
        }
        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException('email 格式错误', 0, 422);
        }
        $phone = IdentityService::normalize('phone', (string) ($data['phone'] ?? ''));
        if ($email !== '') IdentityService::assertAvailable('email', $email);
        if ($phone !== '') IdentityService::assertAvailable('phone', $phone);
        $gift = $customGrant ?? self::defaultGrant($platformId);
        $gift['vip_days'] = max(1, (int) ($gift['vip_days'] ?? 3));
        $gift['app_quota'] = max(0, (int) ($gift['app_quota'] ?? 1));
        $gift['remote_document_quota'] = max(0, (int) ($gift['remote_document_quota'] ?? 3));
        $gift['integral'] = max(0, (int) ($gift['integral'] ?? 15));
        $trialDays = max(1, (int) ($gift['vip_days'] ?? 3));
        $expiredAt = date('Y-m-d H:i:s', time() + $trialDays * 86400);
        $result = Database::transaction(static function () use (
            $platformId, $platform, $data, $request, $account, $password, $email, $phone, $gift, $expiredAt
        ): array {
            $adminId = Database::insert(
                'INSERT INTO admins
                 (platform_id, account, password_hash, nickname, avatar, email, phone, status,
                  register_ip, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())',
                [
                    $platformId, $account, Password::hash($password),
                    mb_substr(trim((string) ($data['nickname'] ?? $account)), 0, 100),
                    mb_substr(trim((string) ($data['avatar'] ?? '')), 0, 500),
                    $email === '' ? null : $email,
                    $phone === '' ? null : mb_substr($phone, 0, 40),
                    $request->clientIp(),
                ]
            );
            IdentityService::bind('admin', $adminId, 'email', $email, $platformId, $adminId, null, false);
            IdentityService::bind('admin', $adminId, 'phone', $phone, $platformId, $adminId, null, false);
            Database::execute(
                'INSERT INTO admin_entitlements
                 (platform_id, admin_id, membership_level, membership_status, membership_started_at,
                  membership_expired_at, app_quota, remote_document_quota, integral, allowed_weekdays,
                  last_granted_by_platform_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $platformId, $adminId, (string) ($gift['membership_level'] ?? 'trial'), 'active', $expiredAt,
                    max(0, (int) ($gift['app_quota'] ?? 1)),
                    max(0, (int) ($gift['remote_document_quota'] ?? 3)),
                    (int) ($gift['integral'] ?? 15), '1,2,3,4,5,6,7', $platformId,
                ]
            );
            Database::execute(
                'INSERT INTO admin_entitlement_logs
                 (platform_id, admin_id, actor_platform_id, change_type, change_json, remark, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [
                    $platformId, $adminId, $platformId, 'registration_gift',
                    json_encode($gift, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'admin 注册免费权益',
                ]
            );
            $giftIntegral = max(0, (int) ($gift['integral'] ?? 15));
            ExchangeService::recordIntegralLog(
                $platformId,
                $adminId,
                $giftIntegral,
                0,
                $giftIntegral,
                'registration_gift',
                'admin',
                $adminId,
                'admin 注册赠送平台余额',
                $platformId
            );
            return ['admin_id' => $adminId, 'gift' => $gift, 'membership_expired_at' => $expiredAt];
        });
        $publicGift = $result['gift'];
        $publicGift['balance'] = (int) ($publicGift['integral'] ?? 0);
        unset($publicGift['integral']);
        return array_merge(AdminAccessService::context((int) $result['admin_id']), [
            'registration_gift' => $publicGift,
        ]);
    }

    public static function adjustEntitlement(
        array $actor,
        array $admin,
        array $changes,
        string $remark = ''
    ): array {
        $adminId = (int) $admin['id'];
        $result = Database::transaction(static function () use ($actor, $admin, $adminId, $changes, $remark): array {
            $before = Database::one('SELECT * FROM admin_entitlements WHERE admin_id = ? FOR UPDATE', [$adminId]);
            if ($before === null) {
                throw new HttpException('admin 权益记录不存在', 404, 404);
            }
            $after = $before;
            foreach (['membership_level', 'membership_status', 'access_start_time', 'access_end_time', 'allowed_weekdays'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $after[$field] = $changes[$field] === '' ? null : $changes[$field];
                }
            }
            $after['access_start_time'] = self::normalizeTime($after['access_start_time'], 'access_start_time');
            $after['access_end_time'] = self::normalizeTime($after['access_end_time'], 'access_end_time');
            if (($after['access_start_time'] === null) !== ($after['access_end_time'] === null)) {
                throw new HttpException('access_start_time 与 access_end_time 必须同时设置或同时为空', 0, 422);
            }
            $after['allowed_weekdays'] = self::normalizeWeekdays((string) ($after['allowed_weekdays'] ?? ''));
            if (array_key_exists('membership_expired_at', $changes)) {
                $timestamp = strtotime((string) $changes['membership_expired_at']);
                if ($timestamp === false) {
                    throw new HttpException('membership_expired_at 格式错误', 0, 422);
                }
                $after['membership_expired_at'] = date('Y-m-d H:i:s', $timestamp);
            }
            if ((int) ($changes['add_vip_days'] ?? 0) !== 0) {
                $base = max(time(), strtotime((string) $after['membership_expired_at']));
                $after['membership_expired_at'] = date('Y-m-d H:i:s', $base + (int) $changes['add_vip_days'] * 86400);
                $after['membership_status'] = 'active';
            }
            if (array_key_exists('membership_duration_value', $changes)) {
                $after['membership_expired_at'] = EntitlementDurationService::apply(
                    $after['membership_expired_at'] === null ? null : (string) $after['membership_expired_at'],
                    (string) ($changes['membership_operation'] ?? 'increase'),
                    (int) $changes['membership_duration_value'],
                    (string) ($changes['membership_duration_unit'] ?? 'day')
                );
                $after['membership_status'] = strtotime((string) $after['membership_expired_at']) > time()
                    ? 'active' : 'expired';
            }
            foreach (['app_quota', 'remote_document_quota', 'integral'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $after[$field] = (int) $changes[$field];
                }
                $changeKey = $field . '_change';
                if (array_key_exists($changeKey, $changes)) {
                    $after[$field] = (int) $after[$field] + (int) $changes[$changeKey];
                }
                if ((int) $after[$field] < 0) {
                    throw new HttpException($field . ' 不能小于 0', 0, 422);
                }
            }
            if (!in_array((string) $after['membership_status'], ['active', 'frozen', 'expired'], true)) {
                throw new HttpException('membership_status 不正确', 0, 422);
            }
            Database::execute(
                'UPDATE admin_entitlements SET membership_level = ?, membership_status = ?, membership_expired_at = ?,
                 app_quota = ?, remote_document_quota = ?, integral = ?, access_start_time = ?, access_end_time = ?,
                 allowed_weekdays = ?, last_granted_by_platform_id = ?, updated_at = NOW() WHERE admin_id = ?',
                [
                    mb_substr((string) $after['membership_level'], 0, 40), $after['membership_status'],
                    $after['membership_expired_at'], (int) $after['app_quota'], (int) $after['remote_document_quota'],
                    (int) $after['integral'], $after['access_start_time'], $after['access_end_time'],
                    mb_substr((string) $after['allowed_weekdays'], 0, 30), (int) $actor['id'], $adminId,
                ]
            );
            Database::execute(
                'INSERT INTO admin_entitlement_logs
                 (platform_id, admin_id, actor_platform_id, change_type, before_json, change_json, after_json, remark, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    (int) $admin['platform_id'], $adminId, (int) $actor['id'], 'platform_adjust',
                    json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    mb_substr($remark, 0, 500),
                ]
            );
            if ((int) $after['integral'] !== (int) $before['integral']) {
                ExchangeService::recordIntegralLog(
                    (int) $admin['platform_id'],
                    $adminId,
                    (int) $after['integral'] - (int) $before['integral'],
                    (int) $before['integral'],
                    (int) $after['integral'],
                    'platform_adjustment',
                    'admin_entitlement_log',
                    null,
                    $remark,
                    (int) $actor['id']
                );
            }
            return $after;
        });
        if ((string) $result['membership_status'] !== 'active') {
            Database::execute('UPDATE admin_tokens SET revoked_at = NOW() WHERE admin_id = ? AND revoked_at IS NULL', [$adminId]);
        }
        return AdminAccessService::context($adminId);
    }

    public static function writeRegistrationLog(
        array $platform,
        Request $request,
        string $account,
        bool $success,
        string $reason,
        ?int $adminId = null,
        ?array $gift = null
    ): void {
        Database::execute(
            'INSERT INTO admin_registration_logs
             (platform_id, admin_id, account, ip, user_agent, result, reason, gift_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                (int) $platform['id'], $adminId, mb_substr($account, 0, 64), $request->clientIp(),
                $request->userAgent(), $success ? 1 : 0, mb_substr($reason, 0, 255),
                $gift === null ? null : json_encode($gift, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
        if ($success) {
            PlatformService::increment((int) $platform['id'], 'admin_registered');
        }
    }

    public static function defaultGrant(int $platformId): array
    {
        return [
            'membership_level' => 'trial',
            'vip_days' => max(1, (int) PlatformService::setting($platformId, 'admin_free_trial_days', 3)),
            'app_quota' => max(0, (int) PlatformService::setting($platformId, 'admin_free_app_quota', 1)),
            'remote_document_quota' => max(0, (int) PlatformService::setting($platformId, 'admin_free_remote_document_quota', 3)),
            'integral' => max(0, (int) PlatformService::setting($platformId, 'admin_free_balance', 15)),
        ];
    }

    private static function registrationCount(int $platformId, ?string $ip, bool $today): int
    {
        $sql = 'SELECT COUNT(*) AS total FROM admin_registration_logs WHERE platform_id = ? AND result = 1';
        $params = [$platformId];
        if ($ip !== null) {
            $sql .= ' AND ip = ?';
            $params[] = $ip;
        }
        if ($today) {
            $sql .= ' AND created_at >= CURDATE()';
        }
        return (int) (Database::one($sql, $params)['total'] ?? 0);
    }

    private static function lockPlatform(int $platformId): array
    {
        $platform = Database::one(
            'SELECT * FROM platform_accounts WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
            [$platformId]
        );
        if ($platform === null) {
            throw new HttpException('平台账号不存在', 404, 404);
        }
        return $platform;
    }

    private static function normalizeTime($value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim((string) $value);
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value) !== 1) {
            throw new HttpException($field . ' 必须是 HH:MM 或 HH:MM:SS', 0, 422);
        }
        return strlen($value) === 5 ? $value . ':00' : $value;
    }

    private static function normalizeWeekdays(string $value): string
    {
        $days = array_values(array_unique(array_filter(
            array_map('intval', explode(',', $value)),
            static fn(int $day): bool => $day >= 1 && $day <= 7
        )));
        sort($days);
        if ($days === []) {
            throw new HttpException('allowed_weekdays 至少包含一个 1-7 的星期值', 0, 422);
        }
        return implode(',', $days);
    }
}
