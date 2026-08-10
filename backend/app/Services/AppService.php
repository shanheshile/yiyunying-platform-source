<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class AppService
{
    public static function byKey(string $appKey, bool $requireEnabled = true): array
    {
        $sql = 'SELECT * FROM apps WHERE app_key = ? AND deleted_at IS NULL';
        $params = [$appKey];
        if ($requireEnabled) {
            $sql .= ' AND status = 1';
        }
        $app = Database::one($sql, $params);
        if ($app === null) {
            throw new HttpException('应用不存在或已停用', 404, 404);
        }
        $owner = AdminAccessService::context((int) $app['admin_id']);
        AdminAccessService::assertDownstreamAccess($owner);
        return $app;
    }

    public static function owned(int $adminId, int $appId, bool $includeDisabled = true): array
    {
        $sql = 'SELECT * FROM apps WHERE id = ? AND admin_id = ? AND deleted_at IS NULL';
        $params = [$appId, $adminId];
        if (!$includeDisabled) {
            $sql .= ' AND status = 1';
        }
        $app = Database::one($sql, $params);
        if ($app === null) {
            throw new HttpException('应用不存在或不属于当前管理员', 403, 403);
        }
        return $app;
    }

    public static function settings(int $appId): array
    {
        $rows = Database::all(
            'SELECT setting_key, setting_value, value_type FROM app_settings WHERE app_id = ? ORDER BY setting_key',
            [$appId]
        );
        $settings = [];
        foreach ($rows as $row) {
            $value = self::decodeValue($row['setting_value'], $row['value_type']);
            if ($row['setting_key'] === 'economy_primary_asset' && $value === 'integral') $value = 'activity_credit';
            $settings[$row['setting_key']] = $value;
        }
        return $settings;
    }

    public static function effectiveSettings(int $appId): array
    {
        $settings = self::settings($appId);
        $policy = self::chatPollingPolicy($appId, $settings);
        $settings['chat_poll_interval_ms'] = (int) $policy['effective_interval_ms'];
        $recallPolicy = self::messageRecallPolicy($appId, $settings);
        $settings['message_recall_seconds'] = (int) $recallPolicy['effective_seconds'];
        $relationshipPolicy = self::relationshipRequestPolicy($appId, $settings);
        $settings['relationship_request_valid_days'] = (int) $relationshipPolicy['effective_days'];
        return $settings;
    }

    public static function chatPollingPolicy(int $appId, ?array $settings = null): array
    {
        $app = Database::one(
            'SELECT ap.id, ap.admin_id, a.platform_id
             FROM apps ap INNER JOIN admins a ON a.id = ap.admin_id
             WHERE ap.id = ? AND ap.deleted_at IS NULL',
            [$appId]
        );
        if ($app === null) {
            throw new HttpException('应用不存在', 404, 404);
        }
        $settings ??= self::settings($appId);
        $configured = array_key_exists('chat_poll_interval_ms', $settings)
            ? (int) $settings['chat_poll_interval_ms']
            : null;
        return PlatformService::chatPollingPolicy((int) $app['platform_id'], $configured);
    }

    public static function messageRecallPolicy(int $appId, ?array $settings = null): array
    {
        $app = Database::one(
            'SELECT ap.id, ap.admin_id, a.platform_id
             FROM apps ap INNER JOIN admins a ON a.id = ap.admin_id
             WHERE ap.id = ? AND ap.deleted_at IS NULL',
            [$appId]
        );
        if ($app === null) throw new HttpException('应用不存在', 404, 404);
        $settings ??= self::settings($appId);
        $configured = array_key_exists('message_recall_seconds', $settings)
            ? (int) $settings['message_recall_seconds']
            : null;
        $inherit = array_key_exists('message_recall_inherit', $settings)
            ? (bool) $settings['message_recall_inherit']
            : true;
        return PlatformService::messageRecallPolicy((int) $app['platform_id'], $configured, $inherit);
    }

    public static function relationshipRequestPolicy(int $appId, ?array $settings = null): array
    {
        $app = Database::one(
            'SELECT ap.id, a.platform_id
             FROM apps ap INNER JOIN admins a ON a.id = ap.admin_id
             WHERE ap.id = ? AND ap.deleted_at IS NULL',
            [$appId]
        );
        if ($app === null) throw new HttpException('应用不存在', 404, 404);
        $settings ??= self::settings($appId);
        $configured = array_key_exists('relationship_request_valid_days', $settings)
            ? (int) $settings['relationship_request_valid_days']
            : null;
        $inherit = array_key_exists('relationship_request_valid_days_inherit', $settings)
            ? (bool) $settings['relationship_request_valid_days_inherit']
            : true;
        return PlatformService::relationshipRequestPolicy((int) $app['platform_id'], $configured, $inherit);
    }

    public static function setting(int $appId, string $key, $default = null)
    {
        $row = Database::one(
            'SELECT setting_value, value_type FROM app_settings WHERE app_id = ? AND setting_key = ?',
            [$appId, $key]
        );
        return $row === null ? $default : self::decodeValue($row['setting_value'], $row['value_type']);
    }

    public static function saveSettings(int $adminId, int $appId, array $settings): array
    {
        $settings = self::validateSettings($appId, $settings);
        foreach ($settings as $key => $value) {
            if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', (string) $key) !== 1) {
                throw new HttpException('配置键格式错误：' . (string) $key, 0, 422);
            }
            [$encoded, $type] = self::encodeValue($value);
            Database::execute(
                'INSERT INTO app_settings (admin_id, app_id, setting_key, setting_value, value_type, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_at = NOW()',
                [$adminId, $appId, $key, $encoded, $type]
            );
        }
        return self::settings($appId);
    }

    public static function validateSettings(int $appId, array $settings): array
    {
        $merged = array_merge(self::settings($appId), $settings);
        if (($settings['economy_primary_asset'] ?? null) === 'activity_credit') {
            $settings['economy_primary_asset'] = 'integral';
        }
        if (array_key_exists('economy_primary_asset', $settings)
            && !in_array((string) $settings['economy_primary_asset'], ['balance', 'integral'], true)) {
            throw new HttpException('economy_primary_asset 仅支持 balance 或 activity_credit', 0, 422);
        }
        foreach ([
            'initial_document_credit', 'document_create_cost', 'document_max_count',
            'account_min_length', 'account_max_length', 'daily_register_limit', 'register_ip_daily_limit',
            'sign_reward_balance', 'sign_reward_experience', 'sign_reward_credit', 'invite_reward_balance',
            'lottery_daily_limit', 'wallet_transfer_max', 'user_group_max_owned', 'group_default_max_members',
            'user_chatroom_max_owned', 'chatroom_default_max_members',
            'bounty_min_reward_balance', 'bounty_max_reward_balance', 'withdrawal_min_amount',
            'withdrawal_max_amount', 'message_recall_seconds', 'user_initial_balance',
            'user_initial_activity_credit', 'user_free_vip_days', 'document_credit_balance_price',
            'vip_day_balance_price',
            'profile_like_per_action_limit', 'profile_like_daily_limit',
            'upload_max_bytes', 'upload_image_max_bytes', 'upload_video_max_bytes',
            'upload_audio_max_bytes', 'upload_file_max_bytes', 'sticker_target_max_bytes',
            'cloud_chat_backup_price', 'cloud_sticker_sync_price', 'cloud_favorite_sync_price',
            'cloud_backup_max_items', 'cloud_backup_retention_days', 'chat_local_cache_days',
            'media_cache_max_bytes', 'auto_cache_default_max_bytes', 'auto_cache_max_bytes_limit',
            'auto_cache_retention_days',
            'heartbeat_online_seconds', 'group_restore_days',
            'relationship_request_valid_days',
        ] as $key) {
            if (array_key_exists($key, $settings) && (!is_numeric($settings[$key]) || (float) $settings[$key] < 0)) {
                throw new HttpException($key . ' 不能小于 0', 0, 422);
            }
        }
        foreach (['auto_cache_network', 'video_autoplay_network', 'video_autoplay_default_network'] as $key) {
            if (array_key_exists($key, $settings)
                && !in_array(strtolower(trim((string) $settings[$key])), ['wifi', 'wifi_mobile', 'never'], true)) {
                throw new HttpException($key . ' 仅支持 wifi、wifi_mobile 或 never', 0, 422);
            }
        }
        if (array_key_exists('auto_cache_allowed_categories', $settings)) {
            if (!is_array($settings['auto_cache_allowed_categories'])) {
                throw new HttpException('auto_cache_allowed_categories 必须是缓存类别数组', 0, 422);
            }
            $allowedCategories = ['chat_record', 'profile', 'image', 'video', 'voice', 'audio', 'document', 'file', 'sticker'];
            foreach ($settings['auto_cache_allowed_categories'] as $category) {
                if (!in_array((string) $category, $allowedCategories, true)) {
                    throw new HttpException('不支持的自动缓存类别：' . (string) $category, 0, 422);
                }
            }
        }
        $cacheDefaultBytes = (int) ($merged['auto_cache_default_max_bytes'] ?? 536870912);
        $cacheLimitBytes = (int) ($merged['auto_cache_max_bytes_limit'] ?? 2147483648);
        if ($cacheDefaultBytes > $cacheLimitBytes) {
            throw new HttpException('自动缓存默认容量不能大于管理员容量上限', 0, 422);
        }
        if (array_key_exists('message_recall_seconds', $settings)
            && (int) $settings['message_recall_seconds'] > 31536000) {
            throw new HttpException('消息撤回时限不能超过 31536000 秒', 0, 422);
        }
        if (array_key_exists('relationship_request_valid_days', $settings)) {
            $days = (int) $settings['relationship_request_valid_days'];
            if ($days < 1 || $days > 3650) {
                throw new HttpException('好友申请和群聊邀请有效期必须在 1-3650 天之间', 0, 422);
            }
        }
        if (array_intersect(
            ['relationship_request_valid_days', 'relationship_request_valid_days_inherit'],
            array_keys($settings)
        ) !== []) {
            $policy = self::relationshipRequestPolicy($appId);
            if (!(bool) $policy['can_admin_modify']) {
                throw new HttpException('上级平台已强制同步申请和邀请有效期，当前管理员不能修改', 403, 403, [
                    'relationship_request_policy' => $policy,
                ]);
            }
        }
        $minLength = max(1, (int) ($merged['account_min_length'] ?? 3));
        $maxLength = min(64, (int) ($merged['account_max_length'] ?? 32));
        if ($minLength > $maxLength) throw new HttpException('账号最小长度不能大于最大长度', 0, 422);
        $bountyMin = (int) ($merged['bounty_min_reward_balance'] ?? 1);
        $bountyMax = (int) ($merged['bounty_max_reward_balance'] ?? 1000000);
        if ($bountyMin > $bountyMax) throw new HttpException('悬赏最小余额不能大于最大余额', 0, 422);
        $withdrawalMin = (float) ($merged['withdrawal_min_amount'] ?? 1);
        $withdrawalMax = (float) ($merged['withdrawal_max_amount'] ?? 100000);
        if ($withdrawalMin > $withdrawalMax) throw new HttpException('提现最小金额不能大于最大金额', 0, 422);
        if ((bool) ($merged['balance_document_purchase_enabled'] ?? false)
            && (float) ($merged['document_credit_balance_price'] ?? 0) <= 0) {
            throw new HttpException('开启余额购买笔记额度时，单价必须大于 0', 0, 422);
        }
        if ((bool) ($merged['balance_membership_purchase_enabled'] ?? false)
            && (float) ($merged['vip_day_balance_price'] ?? 0) <= 0) {
            throw new HttpException('开启余额购买会员时，每日单价必须大于 0', 0, 422);
        }
        if ((bool) ($merged['registration_email_required'] ?? false)
            && !(bool) ($merged['registration_email_enabled'] ?? false)) {
            throw new HttpException('邮箱必填时必须同时启用邮箱注册字段', 0, 422);
        }
        if ((bool) ($merged['registration_phone_required'] ?? false)
            && !(bool) ($merged['registration_phone_enabled'] ?? false)) {
            throw new HttpException('手机号必填时必须同时启用手机号注册字段', 0, 422);
        }
        if ((bool) ($merged['registration_nickname_required'] ?? false)
            && !(bool) ($merged['registration_nickname_enabled'] ?? false)) {
            throw new HttpException('昵称必填时必须同时启用昵称注册字段', 0, 422);
        }
        return $settings;
    }

    public static function seedDefaults(int $adminId, int $appId): void
    {
        $admin = AdminAccessService::context($adminId);
        $chatPollInterval = max(250, (int) PlatformService::setting(
            (int) $admin['platform_id'],
            'default_chat_poll_interval_ms',
            5000
        ));
        self::saveSettings($adminId, $appId, [
            'registration_enabled' => true,
            'registration_nickname_enabled' => true,
            'registration_nickname_required' => true,
            'registration_email_enabled' => false,
            'registration_email_required' => false,
            'registration_phone_enabled' => false,
            'registration_phone_required' => false,
            'identity_unbind_enabled' => true,
            'login_enabled' => true,
            'document_enabled' => true,
            'card_redeem_enabled' => true,
            'card_login_enabled' => true,
            'public_app_statistics_enabled' => true,
            'heartbeat_online_seconds' => 180,
            'initial_document_credit' => 20,
            'document_create_cost' => 1,
            'document_max_count' => 1000,
            'document_share_enabled' => true,
            'account_min_length' => 3,
            'account_max_length' => 32,
            'daily_register_limit' => 1000,
            'register_ip_daily_limit' => 10,
            'password_reset_enabled' => true,
            'profile_edit_enabled' => true,
            'profile_public_default' => true,
            'moment_like_non_friend_visible' => false,
            'moment_post_audit' => false,
            'moment_comment_audit' => false,
            'profile_like_per_action_limit' => 10,
            'profile_like_daily_limit' => 50,
            'sign_enabled' => true,
            'sign_reward_balance' => 10,
            'sign_reward_experience' => 5,
            'sign_reward_credit' => 0,
            'invite_enabled' => true,
            'invite_reward_balance' => 20,
            'private_message_enabled' => true,
            'accept_stranger_messages_default' => true,
            'forum_post_audit' => false,
            'forum_comment_audit' => false,
            'resource_user_submit_enabled' => true,
            'resource_submit_audit' => true,
            'upload_max_bytes' => 104857600,
            'upload_image_max_bytes' => 104857600,
            'upload_video_max_bytes' => 1073741824,
            'upload_audio_max_bytes' => 104857600,
            'upload_file_max_bytes' => 536870912,
            'media_optimize_by_default' => true,
            'media_original_upload_enabled' => true,
            'sticker_optimize_enabled' => true,
            'sticker_target_max_bytes' => 524288,
            'cloud_chat_backup_enabled' => true,
            'cloud_chat_backup_vip_required' => false,
            'cloud_chat_backup_price' => 0.0,
            'cloud_sticker_sync_enabled' => true,
            'cloud_sticker_sync_vip_required' => false,
            'cloud_sticker_sync_price' => 0.0,
            'cloud_favorite_sync_enabled' => true,
            'cloud_favorite_sync_vip_required' => false,
            'cloud_favorite_sync_price' => 0.0,
            'cloud_backup_max_items' => 5000,
            'cloud_backup_retention_days' => 3650,
            'chat_local_cache_days' => 90,
            'media_cache_max_bytes' => 536870912,
            'auto_download_cache_enabled' => true,
            'auto_cache_allowed_categories' => ['chat_record', 'profile', 'image', 'video', 'voice', 'audio', 'document', 'file', 'sticker'],
            'auto_cache_default_max_bytes' => 536870912,
            'auto_cache_max_bytes_limit' => 2147483648,
            'auto_cache_retention_days' => 90,
            'auto_cache_network' => 'wifi_mobile',
            'auto_cache_force_wifi_only' => false,
            'auto_cache_policy_version' => '2026.08.01',
            'video_autoplay_enabled' => true,
            'video_autoplay_network' => 'wifi_mobile',
            'video_autoplay_default_network' => 'wifi',
            'lottery_daily_limit' => 3,
            'wallet_transfer_enabled' => true,
            'wallet_transfer_max' => 1000000,
            'chat_poll_interval_ms' => $chatPollInterval,
            'user_group_create_enabled' => true,
            'user_group_max_owned' => 10,
            'group_default_max_members' => 500,
            'user_chatroom_create_enabled' => true,
            'user_chatroom_max_owned' => 10,
            'chatroom_default_max_members' => 500,
            'group_restore_days' => 7,
            'bounty_min_reward_balance' => 1,
            'bounty_max_reward_balance' => 1000000,
            'withdrawal_enabled' => true,
            'withdrawal_min_amount' => 1,
            'withdrawal_max_amount' => 100000,
            'message_recall_seconds' => 120,
            'message_recall_inherit' => true,
            'relationship_request_valid_days' => 30,
            'relationship_request_valid_days_inherit' => true,
            'forum_reward_enabled' => true,
            'forum_paid_content_enabled' => true,
            'forum_unlock_max_price_balance' => 1000000000.0,
            'forum_unlock_max_future_days' => 3650,
            'forum_paid_section_max_count' => 30,
            'user_poll_create_enabled' => true,
            'economy_primary_asset' => 'balance',
            'user_initial_balance' => 0,
            'user_initial_activity_credit' => 0,
            'user_free_vip_days' => 0,
            'user_login_vip_only' => false,
            'document_credit_separate' => true,
            'balance_document_purchase_enabled' => false,
            'document_credit_balance_price' => 1,
            'balance_membership_purchase_enabled' => false,
            'vip_day_balance_price' => 1,
            'balance_activity_enabled' => true,
        ]);

        foreach ([
            'user_account', 'user_profile', 'sign_invite', 'documents', 'notices',
            'resources', 'store', 'forum', 'messages', 'chat_rooms', 'service',
            'cards', 'payments', 'remote_files', 'shop', 'red_packets',
            'lottery', 'votes', 'feedback', 'bot',
            'bounties', 'level_forum', 'social', 'notifications',
            'withdrawals', 'chat_extensions',
            'chat_camera', 'chat_album', 'chat_contact_card', 'chat_call_record_label',
            'group_avatar_upload', 'chatroom_avatar_upload', 'forum_plate_avatar_upload',
            'forum_chapters', 'forum_paid_unlock', 'forum_scheduled_unlock',
            'forum_attachment_unlock', 'forum_media_filename_privacy',
            'balance_document_purchase', 'balance_membership_purchase', 'hierarchical_activities',
        ] as $featureCode) {
            self::saveFeature($adminId, $appId, $featureCode, true, null);
        }

        foreach ([
            ['广告营销', '广告、引流或营销内容', 40],
            ['违法违规', '涉嫌违法或违反平台规则', 30],
            ['人身攻击', '侮辱、骚扰或人身攻击', 20],
            ['其他问题', '不属于以上分类的问题', 10],
        ] as [$name, $description, $sortOrder]) {
            Database::execute(
                'INSERT INTO forum_report_tags
                 (admin_id, app_id, name, description, sort_order, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE description = VALUES(description), sort_order = VALUES(sort_order), updated_at = NOW()',
                [$adminId, $appId, $name, $description, $sortOrder]
            );
        }
    }

    public static function features(int $appId): array
    {
        $rows = Database::all(
            'SELECT feature_code, enabled, config_json FROM app_feature_flags WHERE app_id = ? ORDER BY feature_code',
            [$appId]
        );
        $features = [];
        foreach ($rows as $row) {
            $featureCode = (string) $row['feature_code'];
            $config = $row['config_json'] === null ? null : json_decode((string) $row['config_json'], true);
            $features[$featureCode] = [
                // Exposing original media names is a data-leak risk, not a product option.
                'enabled' => $featureCode === 'forum_media_filename_privacy' ? true : (bool) $row['enabled'],
                'config' => is_array($config) ? $config : null,
            ];
        }
        return $features;
    }

    public static function featureEnabled(int $appId, string $featureCode, bool $default = true): bool
    {
        if ($featureCode === 'forum_media_filename_privacy') {
            return true;
        }
        $row = Database::one(
            'SELECT enabled FROM app_feature_flags WHERE app_id = ? AND feature_code = ?',
            [$appId, $featureCode]
        );
        $configured = $row === null ? $default : (bool) $row['enabled'];
        return (bool) GovernanceService::effectiveFeatureForApp($appId, $featureCode, $configured)['effective_enabled'];
    }

    public static function requireFeature(int $appId, string $featureCode): void
    {
        $policy = GovernanceService::effectiveFeatureForApp($appId, $featureCode);
        if (!(bool) $policy['effective_enabled']) {
            throw new HttpException('当前应用或上级平台已关闭该功能：' . $featureCode, 403, 403, $policy);
        }
    }

    public static function saveFeature(
        int $adminId,
        int $appId,
        string $featureCode,
        bool $enabled,
        ?array $config,
        bool $updateConfig = true
    ): void {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $featureCode) !== 1) {
            throw new HttpException('feature_code 格式错误', 0, 422);
        }
        if ($featureCode === 'forum_media_filename_privacy') {
            $enabled = true;
        }
        $updateClause = $updateConfig
            ? 'enabled = VALUES(enabled), config_json = VALUES(config_json), updated_at = NOW()'
            : 'enabled = VALUES(enabled), updated_at = NOW()';
        Database::execute(
            'INSERT INTO app_feature_flags
             (admin_id, app_id, feature_code, enabled, config_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE ' . $updateClause,
            [
                $adminId,
                $appId,
                $featureCode,
                $enabled ? 1 : 0,
                $config === null ? null : json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private static function encodeValue($value): array
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

    private static function decodeValue(string $value, string $type)
    {
        switch ($type) {
            case 'bool':
                return $value === '1' || strtolower($value) === 'true';
            case 'int':
                return (int) $value;
            case 'float':
                return (float) $value;
            case 'json':
                $decoded = json_decode($value, true);
                return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            default:
                return $value;
        }
    }
}
