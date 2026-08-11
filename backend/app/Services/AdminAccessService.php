<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class AdminAccessService
{
    private const BILLING_PATHS = [
        '#^/api/admin/me/?$#',
        '#^/api/admin/logout/?$#',
        '#^/api/admin/entitlement/?$#',
        '#^/api/admin/purchase-orders(?:/[^/]+)?/?$#',
        '#^/api/admin/platform-feedbacks(?:/[^/]+)?/?$#',
        '#^/api/admin/exchange-products(?:/[^/]+)?/?$#',
        '#^/api/admin/exchanges(?:/[^/]+)?/?$#',
        '#^/api/admin/balance-logs/?$#',
    ];

    public static function contextSql(): string
    {
        return 'SELECT a.*,
                       e.membership_level, e.membership_status, e.membership_started_at,
                       e.membership_expired_at, e.app_quota, e.remote_document_quota,
                       e.integral AS admin_integral, e.access_start_time, e.access_end_time,
                       e.allowed_weekdays,
                       p.level AS platform_level, p.platform_key, p.status AS platform_status,
                       p.disabled_reason AS platform_disabled_reason,
                       p.membership_expired_at AS platform_membership_expired_at,
                       p.deleted_at AS platform_deleted_at
                FROM admins a
                INNER JOIN admin_entitlements e ON e.admin_id = a.id AND e.platform_id = a.platform_id
                INNER JOIN platform_accounts p ON p.id = a.platform_id';
    }

    public static function context(int $adminId): array
    {
        $admin = Database::one(self::contextSql() . ' WHERE a.id = ?', [$adminId]);
        if ($admin === null) {
            throw new HttpException('admin 账号或权益记录不存在', 404, 404);
        }
        return $admin;
    }

    public static function accessState(array $admin, bool $checkSchedule = true): array
    {
        if ($admin['platform_deleted_at'] !== null || (int) $admin['platform_status'] !== 1) {
            return ['mode' => 'blocked', 'reason_code' => 'platform_disabled', 'reason' => '所属平台已被停用或删除'];
        }
        if ((int) $admin['platform_level'] === 2 && $admin['platform_membership_expired_at'] !== null
            && strtotime((string) $admin['platform_membership_expired_at']) <= time()) {
            return ['mode' => 'blocked', 'reason_code' => 'platform_expired', 'reason' => '所属 2 级平台会员已到期'];
        }
        if ((int) $admin['status'] !== 1) {
            return ['mode' => 'blocked', 'reason_code' => 'admin_disabled', 'reason' => 'admin 账号已被封禁或停用'];
        }
        $membershipRequired = (bool) PlatformService::setting((int) $admin['platform_id'], 'admin_membership_required', true);
        if ($membershipRequired) {
            if ((string) $admin['membership_status'] !== 'active') {
                return ['mode' => 'billing_only', 'reason_code' => 'membership_inactive', 'reason' => 'admin 会员状态不可用'];
            }
            if ($admin['membership_expired_at'] === null || strtotime((string) $admin['membership_expired_at']) <= time()) {
                return ['mode' => 'billing_only', 'reason_code' => 'membership_expired', 'reason' => 'admin 会员已到期'];
            }
        }
        if ((bool) PlatformService::setting((int) $admin['platform_id'], 'admin_vip_only', false)
            && !in_array(strtolower((string) $admin['membership_level']), ['vip', 'svip', 'premium', 'enterprise'], true)) {
            return ['mode' => 'billing_only', 'reason_code' => 'vip_required', 'reason' => '所属平台当前仅允许 VIP admin 使用'];
        }
        if ($checkSchedule && !self::withinSchedule($admin)) {
            return ['mode' => 'billing_only', 'reason_code' => 'outside_access_window', 'reason' => '当前时间不在允许使用时段内'];
        }
        return ['mode' => 'full', 'reason_code' => 'ok', 'reason' => '可正常使用'];
    }

    public static function assertLoginAllowed(array $admin): array
    {
        if (!PlatformService::setting((int) $admin['platform_id'], 'admin_login_enabled', true)) {
            throw new HttpException('所属平台已关闭 admin 登录', 403, 403);
        }
        $state = self::accessState($admin);
        if ($state['mode'] === 'blocked') {
            throw new HttpException($state['reason'], 403, 403, $state);
        }
        return $state;
    }

    public static function assertDirectAccess(array $admin, string $path): array
    {
        $state = self::accessState($admin);
        if ($state['mode'] === 'blocked') {
            throw new HttpException($state['reason'], 403, 403, $state);
        }
        if ($state['mode'] === 'billing_only' && !self::isBillingPath($path)) {
            throw new HttpException('当前 admin 只能访问续费与权益页面：' . $state['reason'], 403, 403, $state);
        }
        if ($state['mode'] === 'full') {
            self::requirePathPermission((int) $admin['id'], $path);
        }
        return $state;
    }

    public static function assertDownstreamAccess(array $admin): void
    {
        $state = self::accessState($admin);
        if ($state['mode'] !== 'full') {
            throw new HttpException('所属 admin 当前不可用：' . $state['reason'], 403, 403, $state);
        }
        if (!PlatformService::setting((int) $admin['platform_id'], 'downstream_user_enabled', true)) {
            throw new HttpException('所属平台已暂停全部下游 user', 403, 403);
        }
        if (!self::permissionAllowed((int) $admin['id'], 'downstream_users.access', true)) {
            throw new HttpException('上级平台已暂停该 admin 的全部 user', 403, 403);
        }
    }

    public static function permissionAllowed(int $adminId, string $code, bool $default = true): bool
    {
        $row = Database::one(
            'SELECT allowed FROM admin_permissions WHERE admin_id = ? AND permission_code = ?',
            [$adminId, $code]
        );
        return $row === null ? $default : (bool) $row['allowed'];
    }

    public static function requireAppQuota(array $admin, bool $lockEntitlement = false): void
    {
        $quota = (int) $admin['app_quota'];
        if ($lockEntitlement) {
            $entitlement = Database::one(
                'SELECT app_quota FROM admin_entitlements WHERE admin_id = ? FOR UPDATE',
                [(int) $admin['id']]
            );
            if ($entitlement === null) {
                throw new HttpException('admin 权益记录不存在', 404, 404);
            }
            $quota = (int) $entitlement['app_quota'];
        }
        $used = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM apps WHERE admin_id = ? AND deleted_at IS NULL',
            [(int) $admin['id']]
        )['total'] ?? 0);
        if ($used >= $quota) {
            throw new HttpException('应用数量已达到 admin 权益上限，请购买应用名额', 0, 422, [
                'used' => $used,
                'quota' => $quota,
            ]);
        }
    }

    public static function requireRemoteDocumentQuota(array $admin, bool $lockEntitlement = false): void
    {
        $quota = (int) $admin['remote_document_quota'];
        if ($lockEntitlement) {
            $entitlement = Database::one(
                'SELECT remote_document_quota FROM admin_entitlements WHERE admin_id = ? FOR UPDATE',
                [(int) $admin['id']]
            );
            if ($entitlement === null) {
                throw new HttpException('admin 权益记录不存在', 404, 404);
            }
            $quota = (int) $entitlement['remote_document_quota'];
        }
        $used = (int) (Database::one(
            "SELECT COUNT(*) AS total
             FROM remote_files f
             INNER JOIN apps a ON a.id = f.app_id AND a.admin_id = f.admin_id
             WHERE f.admin_id = ? AND f.file_type = 'file' AND f.deleted_at IS NULL
               AND a.deleted_at IS NULL",
            [(int) $admin['id']]
        )['total'] ?? 0);
        if ($used >= $quota) {
            throw new HttpException('远程文档数量已达到 admin 权益上限，请购买文档名额', 0, 422, [
                'used' => $used,
                'quota' => $quota,
            ]);
        }
    }

    public static function validateChatPollInterval(int $platformId, int $interval, bool $allowForced = false): int
    {
        $policy = PlatformService::chatPollingPolicy($platformId, $interval);
        if (!$allowForced && (bool) $policy['locked']) {
            throw new HttpException('上级平台已强制聊天轮询间隔，3 级 admin 不能修改', 403, 403, [
                'chat_polling_policy' => $policy,
            ]);
        }
        $min = (int) $policy['minimum_interval_ms'];
        $max = (int) $policy['maximum_interval_ms'];
        if ($interval < $min || $interval > $max) {
            throw new HttpException('chat_poll_interval_ms 超出平台允许范围', 0, 422, [
                'min' => $min,
                'max' => $max,
                'chat_polling_policy' => $policy,
            ]);
        }
        return $interval;
    }

    public static function validateMessageRecallPolicy(
        int $platformId,
        int $seconds,
        bool $inherit,
        bool $allowForced = false
    ): array {
        if ($seconds < 0 || $seconds > 31536000) {
            throw new HttpException('消息撤回时限必须在 0-31536000 秒之间，0 表示关闭撤回', 0, 422);
        }
        $policy = PlatformService::messageRecallPolicy($platformId, $seconds, $inherit);
        if (!$allowForced && !(bool) $policy['can_admin_modify']) {
            throw new HttpException('上级平台已锁定消息撤回规则，3 级管理员不能修改', 403, 403, [
                'message_recall_policy' => $policy,
            ]);
        }
        return $policy;
    }

    private static function requirePathPermission(int $adminId, string $path): void
    {
        $code = self::permissionForPath($path);
        if ($code !== null && !self::permissionAllowed($adminId, $code, true)) {
            throw new HttpException('上级平台未授权 admin 权限：' . $code, 403, 403, ['permission_code' => $code]);
        }
    }

    private static function permissionForPath(string $path): ?string
    {
        $rules = [
            '#/users(?:/|$)|/user-tags(?:/|$)#' => 'users.manage',
            '#/documents(?:/|$)|/document-(?:shares|rules)#' => 'documents.manage',
            '#/(?:notices|versions|banners|remote-configs)(?:/|$)#' => 'content.manage',
            '#/(?:resource-categories|resource-comments|resources|store-categories|store-apps)(?:/|$)#' => 'resources.manage',
            '#/(?:forum-plates|forum-categories|forum-tags|forum-structure-requests|forum-moderators|forum-posts|forum-comments|moments|moment-comments|short-videos|short-video-comments|reports|forum-report-tags)(?:/|$)#' => 'forum.manage',
            '#^/api/admin/community(?:/|$)#' => 'forum.manage',
            '#/(?:system-messages|messages|service-sessions|chat-rooms|chat-room-messages)(?:/|$)#' => 'communication.manage',
            '#/(?:card-batches|cards|card-redeem-logs)(?:/|$)#' => 'cards.manage',
            '#/(?:orders|payments|payment-channels|shop-goods-comments|shop-goods|red-packets|lottery-prizes|votes)(?:/|$)#' => 'commerce.manage',
            '#^/api/admin/activities(?:/|$)#' => 'activities.manage',
            '#/(?:bounties|bounty-categories|bounty-category-requests|polls|poll-categories)(?:/|$)#' => 'activities.manage',
            '#/(?:remote-files|uploads|feedbacks|bot-qa)(?:/|$)#' => 'files.manage',
            '#/(?:statistics|api-logs|operation-logs)(?:/|$)#' => 'statistics.view',
            '#^/api/admin/apps(?:/|$)#' => 'apps.manage',
        ];
        foreach ($rules as $pattern => $code) {
            if (preg_match($pattern, $path) === 1) {
                return $code;
            }
        }
        return null;
    }

    private static function isBillingPath(string $path): bool
    {
        foreach (self::BILLING_PATHS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }
        return false;
    }

    private static function withinSchedule(array $admin): bool
    {
        $weekdays = array_map('intval', explode(',', (string) ($admin['allowed_weekdays'] ?? '1,2,3,4,5,6,7')));
        if (!in_array((int) date('N'), $weekdays, true)) {
            return false;
        }
        $start = $admin['access_start_time'];
        $end = $admin['access_end_time'];
        if ($start === null || $end === null) {
            return true;
        }
        $now = date('H:i:s');
        $start = (string) $start;
        $end = (string) $end;
        return $start <= $end
            ? $now >= $start && $now <= $end
            : $now >= $start || $now <= $end;
    }
}
