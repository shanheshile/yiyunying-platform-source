<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Token;

final class PlatformService
{
    private const MAX_MESSAGE_RECALL_SECONDS = 31536000;
    private const MAX_RELATIONSHIP_REQUEST_VALID_DAYS = 3650;
    private const STAT_COLUMNS = [
        'admin_registered', 'admin_login_success', 'admin_login_failed',
        'admin_active', 'purchase_created', 'purchase_fulfilled',
        'point_exchange_count', 'point_exchange_integral',
        'point_refund_count', 'point_refund_integral',
    ];

    public static function auth(Request $request): array
    {
        $plainToken = $request->bearerToken();
        if ($plainToken === null || $plainToken === '') {
            throw new HttpException('请先登录平台账号', 401, 401);
        }
        $platform = Database::one(
            'SELECT p.*, t.id AS token_id
             FROM platform_tokens t INNER JOIN platform_accounts p ON p.id = t.platform_id
             WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expired_at > NOW()
             LIMIT 1',
            [Token::hash($plainToken)]
        );
        if ($platform === null) {
            throw new HttpException('平台令牌无效或已过期', 401, 401);
        }
        self::assertActive($platform);
        Database::execute('UPDATE platform_tokens SET last_used_at = NOW() WHERE id = ?', [(int) $platform['token_id']]);
        $request->setAttribute('actor_type', 'platform');
        $request->setAttribute('actor_id', (int) $platform['id']);
        $request->setAttribute('platform_id', (int) $platform['id']);
        $request->setAttribute('platform_level', (int) $platform['level']);
        return $platform;
    }

    public static function byKey(?string $platformKey): array
    {
        $key = trim((string) $platformKey);
        if ($key === '') {
            $key = 'yiyunying-root';
        }
        $platform = Database::one(
            'SELECT * FROM platform_accounts WHERE platform_key = ? AND deleted_at IS NULL LIMIT 1',
            [$key]
        );
        if ($platform === null) {
            throw new HttpException('平台注册入口不存在', 404, 404);
        }
        self::assertActive($platform);
        return $platform;
    }

    public static function byId(int $platformId): array
    {
        $platform = Database::one(
            'SELECT * FROM platform_accounts WHERE id = ? AND deleted_at IS NULL',
            [$platformId]
        );
        if ($platform === null) {
            throw new HttpException('平台账号不存在', 404, 404);
        }
        self::assertActive($platform);
        return $platform;
    }

    public static function assertActive(array $platform): void
    {
        if ($platform['deleted_at'] !== null || (int) $platform['status'] !== 1) {
            throw new HttpException('平台账号已被停用或删除', 403, 403, ['reason' => $platform['disabled_reason'] ?? '']);
        }
        $level = (int) $platform['level'];
        if (!in_array($level, [1, 2], true)) {
            throw new HttpException('平台账号等级无效', 403, 403);
        }
        if ($level === 2) {
            $parent = Database::one('SELECT id, status, deleted_at FROM platform_accounts WHERE id = ?', [(int) $platform['parent_id']]);
            if ($parent === null || (int) $parent['status'] !== 1 || $parent['deleted_at'] !== null) {
                throw new HttpException('上级平台不可用', 403, 403);
            }
            $membershipRequired = (bool) self::setting((int) $parent['id'], 'authorized_platform_membership_required', true);
            if ($membershipRequired && ($platform['membership_expired_at'] === null
                || strtotime((string) $platform['membership_expired_at']) <= time())) {
                throw new HttpException('授权平台会员已到期，请联系 1 级平台续期', 403, 403, [
                    'membership_expired_at' => $platform['membership_expired_at'],
                ]);
            }
            if ((bool) self::setting((int) $parent['id'], 'authorized_platform_vip_only', false)
                && !self::isVipLevel((string) $platform['membership_level'])) {
                throw new HttpException('1 级平台当前仅允许 VIP 授权平台使用', 403, 403, [
                    'membership_level' => $platform['membership_level'],
                ]);
            }
            if (!self::withinAccessSchedule($platform)) {
                throw new HttpException('当前时间不在 1 级平台允许的使用时段内', 403, 403, [
                    'access_start_time' => $platform['access_start_time'] ?? null,
                    'access_end_time' => $platform['access_end_time'] ?? null,
                    'allowed_weekdays' => $platform['allowed_weekdays'] ?? null,
                ]);
            }
        }
    }

    public static function requireLevelOne(array $platform): void
    {
        if ((int) $platform['level'] !== 1) {
            throw new HttpException('只有 1 级平台所有者可以执行该操作', 403, 403);
        }
    }

    public static function requireCapability(array $platform, string $capability): void
    {
        if ((int) $platform['level'] === 1) {
            return;
        }
        $permissions = json_decode((string) ($platform['permissions_json'] ?? ''), true);
        if (is_array($permissions) && array_key_exists($capability, $permissions)) {
            $configured = $permissions[$capability];
            $allowed = is_array($configured)
                ? (bool) ($configured['allowed'] ?? true)
                : (bool) $configured;
            if (!$allowed) {
                throw new HttpException('上级平台未授权该能力：' . $capability, 403, 403);
            }
        }
    }

    public static function ownedOperator(array $actor, int $operatorId): array
    {
        self::requireLevelOne($actor);
        $operator = Database::one(
            'SELECT * FROM platform_accounts WHERE id = ? AND level = 2 AND parent_id = ? AND deleted_at IS NULL',
            [$operatorId, (int) $actor['id']]
        );
        if ($operator === null) {
            throw new HttpException('授权平台不存在或不属于当前 1 级平台', 404, 404);
        }
        return $operator;
    }

    public static function ownedAdmin(array $actor, int $adminId): array
    {
        $sql = AdminAccessService::contextSql() . ' WHERE a.id = ?';
        $params = [$adminId];
        if ((int) $actor['level'] === 2) {
            $sql .= ' AND a.platform_id = ?';
            $params[] = (int) $actor['id'];
        } else {
            $sql .= ' AND (a.platform_id = ? OR p.parent_id = ?)';
            $params[] = (int) $actor['id'];
            $params[] = (int) $actor['id'];
        }
        $admin = Database::one($sql, $params);
        if ($admin === null) {
            throw new HttpException('admin 不存在或不在当前平台管理范围内', 404, 404);
        }
        return $admin;
    }

    public static function ownedApp(array $actor, int $appId): array
    {
        $sql = 'SELECT ap.*, a.platform_id, a.account AS admin_account
                FROM apps ap INNER JOIN admins a ON a.id = ap.admin_id
                INNER JOIN platform_accounts p ON p.id = a.platform_id
                WHERE ap.id = ? AND ap.deleted_at IS NULL';
        $params = [$appId];
        if ((int) $actor['level'] === 2) {
            $sql .= ' AND a.platform_id = ?';
            $params[] = (int) $actor['id'];
        } else {
            $sql .= ' AND (a.platform_id = ? OR p.parent_id = ?)';
            $params[] = (int) $actor['id'];
            $params[] = (int) $actor['id'];
        }
        $app = Database::one($sql, $params);
        if ($app === null) {
            throw new HttpException('应用不存在或不在当前平台管理范围内', 404, 404);
        }
        return $app;
    }

    public static function settings(int $platformId): array
    {
        $rows = Database::all(
            'SELECT setting_key, setting_value, value_type FROM platform_settings WHERE platform_id = ? ORDER BY setting_key',
            [$platformId]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[$row['setting_key']] = self::decode((string) ($row['setting_value'] ?? ''), (string) $row['value_type']);
        }
        return $result;
    }

    public static function setting(int $platformId, string $key, $default = null)
    {
        $row = Database::one(
            'SELECT setting_value, value_type FROM platform_settings WHERE platform_id = ? AND setting_key = ?',
            [$platformId, $key]
        );
        return $row === null ? $default : self::decode((string) ($row['setting_value'] ?? ''), (string) $row['value_type']);
    }

    public static function saveSettings(int $platformId, array $settings, bool $allowParentOverride = false): array
    {
        $settings = self::validateSettings($platformId, $settings, $allowParentOverride);
        foreach ($settings as $key => $value) {
            if (preg_match('/^[a-z][a-z0-9_.-]{1,79}$/', (string) $key) !== 1) {
                throw new HttpException('平台配置键格式错误：' . (string) $key, 0, 422);
            }
            [$encoded, $type] = self::encode($value);
            Database::execute(
                'INSERT INTO platform_settings
                 (platform_id, setting_key, setting_value, value_type, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_at = NOW()',
                [$platformId, $key, $encoded, $type]
            );
        }
        return self::settings($platformId);
    }

    public static function validateSettings(int $platformId, array $settings, bool $allowParentOverride = false): array
    {
        $merged = array_merge(self::settings($platformId), $settings);
        $platform = Database::one(
            'SELECT id, parent_id, level FROM platform_accounts WHERE id = ? AND deleted_at IS NULL',
            [$platformId]
        );
        if ($platform === null) {
            throw new HttpException('平台账号不存在', 404, 404);
        }
        $recallKeys = [
            'default_message_recall_seconds', 'force_message_recall_seconds',
            'allow_child_message_recall_override', 'message_recall_inherit',
        ];
        $changesRecallPolicy = array_intersect($recallKeys, array_keys($settings)) !== [];
        if (array_key_exists('default_message_recall_seconds', $settings)) {
            $seconds = $settings['default_message_recall_seconds'];
            if (!is_numeric($seconds) || (int) $seconds < 0 || (int) $seconds > self::MAX_MESSAGE_RECALL_SECONDS) {
                throw new HttpException('默认消息撤回时限必须在 0-31536000 秒之间，0 表示关闭撤回', 0, 422);
            }
            $settings['default_message_recall_seconds'] = (int) $seconds;
        }
        if ((int) $platform['level'] === 2 && $changesRecallPolicy && !$allowParentOverride) {
            $parentSettings = self::settings((int) $platform['parent_id']);
            if ((bool) ($parentSettings['force_message_recall_seconds'] ?? false)) {
                throw new HttpException('1 级总控已强制同步消息撤回时限，授权平台不能修改', 403, 403, [
                    'message_recall_policy' => self::messageRecallPolicy($platformId),
                ]);
            }
            if (!(bool) ($parentSettings['allow_child_message_recall_override'] ?? true)) {
                throw new HttpException('1 级总控未开放消息撤回规则修改权限', 403, 403, [
                    'message_recall_policy' => self::messageRecallPolicy($platformId),
                ]);
            }
        }
        $relationshipKeys = [
            'default_relationship_request_valid_days', 'force_relationship_request_valid_days',
            'allow_child_relationship_request_valid_days_override', 'relationship_request_valid_days_inherit',
        ];
        $changesRelationshipPolicy = array_intersect($relationshipKeys, array_keys($settings)) !== [];
        if (array_key_exists('default_relationship_request_valid_days', $settings)) {
            $days = $settings['default_relationship_request_valid_days'];
            if (!is_numeric($days) || (int) $days < 1 || (int) $days > self::MAX_RELATIONSHIP_REQUEST_VALID_DAYS) {
                throw new HttpException('好友申请和群聊邀请有效期必须在 1-3650 天之间', 0, 422);
            }
            $settings['default_relationship_request_valid_days'] = (int) $days;
        }
        if ((int) $platform['level'] === 2 && $changesRelationshipPolicy && !$allowParentOverride) {
            $parentSettings = self::settings((int) $platform['parent_id']);
            if ((bool) ($parentSettings['force_relationship_request_valid_days'] ?? false)) {
                throw new HttpException('1 级总控已强制同步申请和邀请有效期，授权平台不能修改', 403, 403, [
                    'relationship_request_policy' => self::relationshipRequestPolicy($platformId),
                ]);
            }
            if (!(bool) ($parentSettings['allow_child_relationship_request_valid_days_override'] ?? true)) {
                throw new HttpException('1 级总控未开放申请和邀请有效期修改权限', 403, 403, [
                    'relationship_request_policy' => self::relationshipRequestPolicy($platformId),
                ]);
            }
        }
        $min = max(250, (int) ($merged['min_chat_poll_interval_ms'] ?? 1000));
        $max = max($min, (int) ($merged['max_chat_poll_interval_ms'] ?? 60000));
        if ((int) $platform['level'] === 2 && $platform['parent_id'] !== null) {
            $parentSettings = self::settings((int) $platform['parent_id']);
            $parentMin = max(250, (int) ($parentSettings['min_chat_poll_interval_ms'] ?? 1000));
            $parentMax = max($parentMin, (int) ($parentSettings['max_chat_poll_interval_ms'] ?? 60000));
            $min = max($min, $parentMin);
            $max = min($max, $parentMax);
            if ($max < $min) {
                throw new HttpException('2 级平台的聊天轮询范围不能超出 1 级平台范围', 0, 422, [
                    'level_1_min' => $parentMin,
                    'level_1_max' => $parentMax,
                ]);
            }
        }
        $default = (int) ($merged['default_chat_poll_interval_ms'] ?? 5000);
        if ($default < $min || $default > $max) {
            throw new HttpException('默认聊天轮询间隔必须在平台最小值和最大值之间', 0, 422, [
                'min' => $min,
                'max' => $max,
            ]);
        }
        foreach ([
            'admin_daily_register_limit', 'admin_ip_daily_register_limit', 'admin_ip_total_register_limit',
            'admin_free_trial_days', 'admin_free_app_quota', 'admin_free_remote_document_quota',
            'admin_free_balance', 'balance_exchange_max_quantity_per_order',
            'balance_exchange_admin_daily_limit', 'operator_free_trial_days',
            'operator_free_admin_quota', 'operator_free_balance',
        ] as $key) {
            if (array_key_exists($key, $settings) && (int) $settings[$key] < 0) {
                throw new HttpException($key . ' 不能小于 0', 0, 422);
            }
        }
        return $settings;
    }

    public static function chatPollingPolicy(int $platformId, ?int $configuredInterval = null): array
    {
        $owner = Database::one(
            'SELECT id, parent_id, level, platform_key, nickname
             FROM platform_accounts WHERE id = ? AND deleted_at IS NULL',
            [$platformId]
        );
        if ($owner === null) {
            throw new HttpException('应用所属平台不存在', 404, 404);
        }

        $ownerSettings = self::settings($platformId);
        $root = $owner;
        $rootSettings = $ownerSettings;
        if ((int) $owner['level'] === 2) {
            $root = Database::one(
                'SELECT id, parent_id, level, platform_key, nickname
                 FROM platform_accounts WHERE id = ? AND level = 1 AND deleted_at IS NULL',
                [(int) $owner['parent_id']]
            );
            if ($root === null) {
                throw new HttpException('2 级平台所属的 1 级平台不存在', 403, 403);
            }
            $rootSettings = self::settings((int) $root['id']);
        }

        $rootMin = max(250, (int) ($rootSettings['min_chat_poll_interval_ms'] ?? 1000));
        $rootMax = max($rootMin, (int) ($rootSettings['max_chat_poll_interval_ms'] ?? 60000));
        $ownerMin = max(250, (int) ($ownerSettings['min_chat_poll_interval_ms'] ?? 1000));
        $ownerMax = max($ownerMin, (int) ($ownerSettings['max_chat_poll_interval_ms'] ?? 60000));
        $min = (int) $owner['level'] === 2 ? max($rootMin, $ownerMin) : $ownerMin;
        $max = (int) $owner['level'] === 2 ? min($rootMax, $ownerMax) : $ownerMax;
        if ($max < $min) {
            $max = $min;
        }

        $forcedBy = null;
        $forcedSettings = null;
        if ((bool) ($rootSettings['force_chat_poll_interval'] ?? false)) {
            $forcedBy = $root;
            $forcedSettings = $rootSettings;
            $min = $rootMin;
            $max = $rootMax;
        } elseif ((int) $owner['level'] === 2 && (bool) ($ownerSettings['force_chat_poll_interval'] ?? false)) {
            $forcedBy = $owner;
            $forcedSettings = $ownerSettings;
        }

        $ownerDefault = (int) ($ownerSettings['default_chat_poll_interval_ms'] ?? 5000);
        $configured = $configuredInterval ?? $ownerDefault;
        $effective = $forcedSettings === null
            ? $configured
            : (int) ($forcedSettings['default_chat_poll_interval_ms'] ?? 5000);
        $effective = min($max, max($min, $effective));

        return [
            'setting_key' => 'chat_poll_interval_ms',
            'configured_interval_ms' => $configured,
            'effective_interval_ms' => $effective,
            'minimum_interval_ms' => $min,
            'maximum_interval_ms' => $max,
            'locked' => $forcedBy !== null,
            'can_admin_modify' => $forcedBy === null,
            'source' => $forcedBy === null ? 'admin_app' : 'platform_force',
            'forced_by_platform_id' => $forcedBy === null ? null : (int) $forcedBy['id'],
            'forced_by_level' => $forcedBy === null ? null : (int) $forcedBy['level'],
            'forced_by_platform_key' => $forcedBy['platform_key'] ?? null,
            'owner_platform_id' => (int) $owner['id'],
            'owner_platform_level' => (int) $owner['level'],
            'level_1_force_enabled' => (bool) ($rootSettings['force_chat_poll_interval'] ?? false),
            'level_2_force_enabled' => (int) $owner['level'] === 2
                ? (bool) ($ownerSettings['force_chat_poll_interval'] ?? false)
                : null,
        ];
    }

    public static function messageRecallPolicy(
        int $platformId,
        ?int $configuredSeconds = null,
        ?bool $appInherit = null
    ): array {
        $owner = Database::one(
            'SELECT id, parent_id, level, platform_key, nickname
             FROM platform_accounts WHERE id = ? AND deleted_at IS NULL',
            [$platformId]
        );
        if ($owner === null) throw new HttpException('应用所属平台不存在', 404, 404);

        $ownerSettings = self::settings($platformId);
        $root = $owner;
        $rootSettings = $ownerSettings;
        if ((int) $owner['level'] === 2) {
            $root = Database::one(
                'SELECT id, parent_id, level, platform_key, nickname
                 FROM platform_accounts WHERE id = ? AND level = 1 AND deleted_at IS NULL',
                [(int) $owner['parent_id']]
            );
            if ($root === null) throw new HttpException('2 级平台所属的 1 级平台不存在', 403, 403);
            $rootSettings = self::settings((int) $root['id']);
        }

        $rootDefault = self::normalizeMessageRecallSeconds($rootSettings['default_message_recall_seconds'] ?? 120);
        $rootForced = (bool) ($rootSettings['force_message_recall_seconds'] ?? false);
        $rootAllowsOverride = (bool) ($rootSettings['allow_child_message_recall_override'] ?? true);
        $ownerConfigured = self::normalizeMessageRecallSeconds(
            $ownerSettings['default_message_recall_seconds'] ?? $rootDefault
        );
        $ownerInherit = (int) $owner['level'] === 2
            ? (bool) ($ownerSettings['message_recall_inherit'] ?? true)
            : false;
        $ownerDefault = $ownerInherit ? $rootDefault : $ownerConfigured;
        $ownerForced = (int) $owner['level'] === 2
            && (bool) ($ownerSettings['force_message_recall_seconds'] ?? false);
        $ownerAllowsOverride = (bool) ($ownerSettings['allow_child_message_recall_override'] ?? true);
        $canPlatformModify = (int) $owner['level'] === 1 || (!$rootForced && $rootAllowsOverride);

        $forcedBy = null;
        $effective = null;
        if ($rootForced) {
            $forcedBy = $root;
            $effective = $rootDefault;
        } elseif ($ownerForced) {
            $forcedBy = $owner;
            $effective = $ownerDefault;
        }

        $configured = self::normalizeMessageRecallSeconds($configuredSeconds ?? $ownerDefault);
        $inherit = $appInherit ?? true;
        $canAdminModify = $forcedBy === null
            && $rootAllowsOverride
            && ((int) $owner['level'] === 1 || $ownerAllowsOverride);
        if ($effective === null) $effective = $inherit ? $ownerDefault : $configured;
        $source = $forcedBy !== null
            ? ((int) $forcedBy['level'] === 1 ? 'level_1_force' : 'level_2_force')
            : ($inherit ? ((int) $owner['level'] === 2 && !$ownerInherit ? 'level_2_default' : 'level_1_default') : 'admin_app_override');

        return [
            'setting_key' => 'message_recall_seconds',
            'configured_seconds' => $configured,
            'effective_seconds' => $effective,
            'platform_default_seconds' => $ownerDefault,
            'inherit_from_parent' => $inherit,
            'platform_inherit_from_level_1' => $ownerInherit,
            'enabled' => $effective > 0,
            'locked' => !$canAdminModify,
            'can_admin_modify' => $canAdminModify,
            'can_platform_modify' => $canPlatformModify,
            'source' => $source,
            'forced_by_platform_id' => $forcedBy === null ? null : (int) $forcedBy['id'],
            'forced_by_level' => $forcedBy === null ? null : (int) $forcedBy['level'],
            'forced_by_platform_key' => $forcedBy['platform_key'] ?? null,
            'owner_platform_id' => (int) $owner['id'],
            'owner_platform_level' => (int) $owner['level'],
            'level_1_default_seconds' => $rootDefault,
            'level_1_force_enabled' => $rootForced,
            'level_1_allows_override' => $rootAllowsOverride,
            'level_2_default_seconds' => (int) $owner['level'] === 2 ? $ownerDefault : null,
            'level_2_force_enabled' => (int) $owner['level'] === 2 ? $ownerForced : null,
            'level_2_allows_override' => (int) $owner['level'] === 2 ? $ownerAllowsOverride : null,
            'maximum_seconds' => self::MAX_MESSAGE_RECALL_SECONDS,
        ];
    }

    public static function relationshipRequestPolicy(
        int $platformId,
        ?int $configuredDays = null,
        ?bool $appInherit = null
    ): array {
        $owner = Database::one(
            'SELECT id, parent_id, level, platform_key, nickname
             FROM platform_accounts WHERE id = ? AND deleted_at IS NULL',
            [$platformId]
        );
        if ($owner === null) throw new HttpException('应用所属平台不存在', 404, 404);

        $ownerSettings = self::settings($platformId);
        $root = $owner;
        $rootSettings = $ownerSettings;
        if ((int) $owner['level'] === 2) {
            $root = Database::one(
                'SELECT id, parent_id, level, platform_key, nickname
                 FROM platform_accounts WHERE id = ? AND level = 1 AND deleted_at IS NULL',
                [(int) $owner['parent_id']]
            );
            if ($root === null) throw new HttpException('2 级平台所属的 1 级平台不存在', 403, 403);
            $rootSettings = self::settings((int) $root['id']);
        }

        $normalize = static fn($value): int => min(
            self::MAX_RELATIONSHIP_REQUEST_VALID_DAYS,
            max(1, (int) $value)
        );
        $rootDefault = $normalize($rootSettings['default_relationship_request_valid_days'] ?? 30);
        $rootForced = (bool) ($rootSettings['force_relationship_request_valid_days'] ?? false);
        $rootAllowsOverride = (bool) ($rootSettings['allow_child_relationship_request_valid_days_override'] ?? true);
        $ownerConfigured = $normalize($ownerSettings['default_relationship_request_valid_days'] ?? $rootDefault);
        $ownerInherit = (int) $owner['level'] === 2
            ? (bool) ($ownerSettings['relationship_request_valid_days_inherit'] ?? true)
            : false;
        $ownerDefault = $ownerInherit ? $rootDefault : $ownerConfigured;
        $ownerForced = (int) $owner['level'] === 2
            && (bool) ($ownerSettings['force_relationship_request_valid_days'] ?? false);
        $ownerAllowsOverride = (bool) ($ownerSettings['allow_child_relationship_request_valid_days_override'] ?? true);
        $canPlatformModify = (int) $owner['level'] === 1 || (!$rootForced && $rootAllowsOverride);

        $forcedBy = null;
        $effective = null;
        if ($rootForced) {
            $forcedBy = $root;
            $effective = $rootDefault;
        } elseif ($ownerForced) {
            $forcedBy = $owner;
            $effective = $ownerDefault;
        }

        $configured = $normalize($configuredDays ?? $ownerDefault);
        $inherit = $appInherit ?? true;
        $canAdminModify = $forcedBy === null
            && $rootAllowsOverride
            && ((int) $owner['level'] === 1 || $ownerAllowsOverride);
        if ($effective === null) $effective = $inherit ? $ownerDefault : $configured;

        return [
            'setting_key' => 'relationship_request_valid_days',
            'configured_days' => $configured,
            'effective_days' => $effective,
            'platform_default_days' => $ownerDefault,
            'inherit_from_parent' => $inherit,
            'platform_inherit_from_level_1' => $ownerInherit,
            'locked' => !$canAdminModify,
            'can_admin_modify' => $canAdminModify,
            'can_platform_modify' => $canPlatformModify,
            'source' => $forcedBy !== null
                ? ((int) $forcedBy['level'] === 1 ? 'level_1_force' : 'level_2_force')
                : ($inherit ? 'platform_default' : 'admin_app_override'),
            'forced_by_platform_id' => $forcedBy === null ? null : (int) $forcedBy['id'],
            'forced_by_level' => $forcedBy === null ? null : (int) $forcedBy['level'],
            'owner_platform_id' => (int) $owner['id'],
            'owner_platform_level' => (int) $owner['level'],
            'level_1_default_days' => $rootDefault,
            'level_1_force_enabled' => $rootForced,
            'level_1_allows_override' => $rootAllowsOverride,
            'level_2_default_days' => (int) $owner['level'] === 2 ? $ownerDefault : null,
            'level_2_force_enabled' => (int) $owner['level'] === 2 ? $ownerForced : null,
            'level_2_allows_override' => (int) $owner['level'] === 2 ? $ownerAllowsOverride : null,
            'maximum_days' => self::MAX_RELATIONSHIP_REQUEST_VALID_DAYS,
        ];
    }

    public static function seedDefaults(int $platformId): void
    {
        self::saveSettings($platformId, [
            'admin_registration_enabled' => true,
            'admin_login_enabled' => true,
            'downstream_user_enabled' => true,
            'admin_daily_register_limit' => 100,
            'admin_ip_daily_register_limit' => 3,
            'admin_ip_total_register_limit' => 10,
            'admin_account_min_length' => 3,
            'admin_account_max_length' => 32,
            'admin_free_trial_days' => 3,
            'admin_free_app_quota' => 1,
            'admin_free_remote_document_quota' => 3,
            'admin_free_balance' => 15,
            'operator_free_trial_days' => 3,
            'operator_free_admin_quota' => 10,
            'operator_free_balance' => 15,
            'default_chat_poll_interval_ms' => 5000,
            'force_chat_poll_interval' => false,
            'min_chat_poll_interval_ms' => 1000,
            'max_chat_poll_interval_ms' => 60000,
            'default_message_recall_seconds' => 120,
            'force_message_recall_seconds' => false,
            'allow_child_message_recall_override' => true,
            'message_recall_inherit' => true,
            'default_relationship_request_valid_days' => 30,
            'force_relationship_request_valid_days' => false,
            'allow_child_relationship_request_valid_days_override' => true,
            'relationship_request_valid_days_inherit' => true,
            'balance_exchange_enabled' => true,
            'balance_exchange_max_quantity_per_order' => 100,
            'balance_exchange_admin_daily_limit' => 0,
            'data_console_enabled' => true,
            'balance_display_name' => '余额',
            'authorized_platform_membership_required' => true,
            'authorized_platform_vip_only' => false,
            'admin_membership_required' => true,
            'admin_vip_only' => false,
            'admin_balance_purchase_enabled' => true,
            'admin_document_purchase_enabled' => true,
            'admin_membership_purchase_enabled' => true,
            'hierarchical_activities_enabled' => true,
            'hierarchical_activity_max_budget' => 1000000000,
        ], true);
        ExchangeService::seedDefaultProducts($platformId);
    }

    private static function normalizeMessageRecallSeconds($value): int
    {
        return min(self::MAX_MESSAGE_RECALL_SECONDS, max(0, (int) $value));
    }

    public static function requireAdminQuota(array $platform): void
    {
        if ((int) $platform['level'] === 1 || (int) $platform['admin_quota'] === 0) {
            return;
        }
        $count = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM admins WHERE platform_id = ? AND status <> -1',
            [(int) $platform['id']]
        )['total'] ?? 0);
        if ($count >= (int) $platform['admin_quota']) {
            throw new HttpException('授权平台的 admin 数量已达到上级设置的额度', 0, 422, [
                'used' => $count,
                'quota' => (int) $platform['admin_quota'],
            ]);
        }
    }

    public static function increment(int $platformId, string $column, int $amount = 1): void
    {
        if (!in_array($column, self::STAT_COLUMNS, true)) {
            throw new \InvalidArgumentException('不允许的平台统计字段');
        }
        Database::execute(
            "INSERT INTO platform_daily_statistics (platform_id, stat_date, {$column}, created_at, updated_at)
             VALUES (?, CURDATE(), ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE {$column} = {$column} + VALUES({$column}), updated_at = NOW()",
            [$platformId, $amount]
        );
    }

    public static function log(
        Request $request,
        array $actor,
        string $module,
        string $action,
        string $targetType = '',
        ?int $targetId = null,
        ?array $before = null,
        ?array $after = null
    ): void {
        Database::execute(
            'INSERT INTO platform_operation_logs
             (platform_id, actor_level, module, action, target_type, target_id, before_json, after_json, ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                (int) $actor['id'], (int) $actor['level'], $module, $action, $targetType, $targetId,
                $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $request->clientIp(),
            ]
        );
    }

    public static function publicData(array $platform): array
    {
        $unlimited = (int) $platform['level'] === 1;
        return [
            'id' => (int) $platform['id'],
            'parent_id' => $platform['parent_id'] === null ? null : (int) $platform['parent_id'],
            'level' => (int) $platform['level'],
            'platform_key' => $platform['platform_key'],
            'account' => $platform['account'],
            'nickname' => $platform['nickname'],
            'avatar' => $platform['avatar'],
            'email' => $platform['email'],
            'phone' => $platform['phone'],
            'status' => (int) $platform['status'],
            'membership_level' => $platform['membership_level'],
            'membership_expired_at' => $unlimited ? null : $platform['membership_expired_at'],
            'admin_quota' => $unlimited ? null : (int) $platform['admin_quota'],
            'balance' => $unlimited ? null : (int) $platform['integral'],
            'unlimited' => $unlimited,
            'membership_unlimited' => $unlimited,
            'balance_unlimited' => $unlimited,
            'app_quota_unlimited' => $unlimited,
            'document_quota_unlimited' => $unlimited,
            'access_start_time' => $platform['access_start_time'] ?? null,
            'access_end_time' => $platform['access_end_time'] ?? null,
            'allowed_weekdays' => $platform['allowed_weekdays'] ?? '1,2,3,4,5,6,7',
            'last_login_at' => $platform['last_login_at'],
            'created_at' => $platform['created_at'],
        ];
    }

    private static function encode($value): array
    {
        if (is_bool($value)) {
            return [$value ? '1' : '0', 'bool'];
        }
        if (is_int($value)) {
            return [(string) $value, 'int'];
        }
        if (is_float($value)) {
            return [(string) $value, 'float'];
        }
        if (is_array($value) || is_object($value)) {
            return [json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'json'];
        }
        return [(string) $value, 'string'];
    }

    private static function decode(string $value, string $type)
    {
        return match ($type) {
            'bool' => in_array(strtolower($value), ['1', 'true'], true),
            'int' => (int) $value,
            'float' => (float) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    private static function isVipLevel(string $level): bool
    {
        return in_array(strtolower(trim($level)), ['vip', 'svip', 'premium', 'authorized', 'enterprise'], true);
    }

    private static function withinAccessSchedule(array $platform): bool
    {
        $weekdays = array_map('intval', explode(',', (string) ($platform['allowed_weekdays'] ?? '1,2,3,4,5,6,7')));
        if (!in_array((int) date('N'), $weekdays, true)) return false;
        $start = $platform['access_start_time'] ?? null;
        $end = $platform['access_end_time'] ?? null;
        if ($start === null || $end === null || $start === '' || $end === '') return true;
        $now = date('H:i:s');
        if ((string) $start <= (string) $end) return $now >= $start && $now <= $end;
        return $now >= $start || $now <= $end;
    }
}
