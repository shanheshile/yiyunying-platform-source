<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class MomentVisibilityService
{
    public const MODES = ['inherit', 'public', 'friends', 'followers', 'selected', 'exclude', 'private'];
    public const GLOBAL_MODES = ['public', 'friends', 'followers', 'selected', 'exclude', 'private'];
    public const DAYS = [0, 3, 30, 180, 365];

    public static function canView(array $moment, array $viewer): bool
    {
        $ownerId = (int) ($moment['user_id'] ?? 0);
        $viewerId = (int) ($viewer['id'] ?? 0);
        $appId = (int) ($viewer['app_id'] ?? 0);
        if ($ownerId <= 0 || $viewerId <= 0 || $appId <= 0) return false;
        if ((int) ($moment['app_id'] ?? 0) !== $appId
            || (int) ($moment['admin_id'] ?? 0) !== (int) ($viewer['admin_id'] ?? 0)) return false;
        if ($ownerId === $viewerId) return true;

        $preferences = self::preferences($ownerId);
        if (!$preferences['dynamic_enabled']) return false;
        if (self::blocked($appId, $ownerId, $viewerId)) return false;

        $visibleDays = $moment['visible_days'] === null
            ? $preferences['dynamic_visible_days']
            : self::normalizeDays($moment['visible_days']);
        if ($visibleDays > 0) {
            $createdAt = strtotime((string) ($moment['created_at'] ?? ''));
            if ($createdAt === false || $createdAt < time() - ($visibleDays * 86400)) return false;
        }

        $momentMode = self::normalizeMode((string) ($moment['visibility_mode'] ?? 'inherit'), true);
        $mode = $momentMode === 'inherit' ? $preferences['dynamic_visibility_mode'] : $momentMode;
        $ids = $momentMode === 'inherit'
            ? ($mode === 'selected' ? $preferences['dynamic_allow_user_ids'] : $preferences['dynamic_deny_user_ids'])
            : self::decodeIds($moment['visibility_user_ids_json'] ?? null);

        if (in_array($viewerId, $preferences['dynamic_deny_user_ids'], true)) return false;
        if ($mode === 'private') return false;
        if ($mode === 'selected' && !in_array($viewerId, $ids, true)) return false;
        if ($mode === 'exclude' && in_array($viewerId, $ids, true)) return false;

        $relationship = self::relationship($appId, $ownerId, $viewerId);
        if ($mode === 'friends' && !$relationship['friend']) return false;
        if ($mode === 'followers' && !$relationship['follower']) return false;

        if ($relationship['friend']) {
            if (!$preferences['dynamic_visible_to_friends']) return false;
            if ($relationship['hidden'] && !$preferences['dynamic_visible_to_hidden_contacts']) return false;
            if ($relationship['special_care'] && !$preferences['dynamic_visible_to_special_care']) return false;
            return true;
        }
        if ($relationship['follower']) return $preferences['dynamic_visible_to_followers'];
        return $preferences['dynamic_visible_to_strangers'];
    }

    public static function preferences(int $ownerId): array
    {
        $row = Database::one(
            'SELECT dynamic_enabled, dynamic_visible_days, dynamic_visibility_mode,
                    dynamic_allow_user_ids_json, dynamic_deny_user_ids_json,
                    dynamic_visible_to_friends, dynamic_visible_to_followers,
                    dynamic_visible_to_strangers, dynamic_visible_to_hidden_contacts,
                    dynamic_visible_to_special_care
             FROM user_message_preferences WHERE user_id = ?',
            [$ownerId]
        );
        return [
            'dynamic_enabled' => $row === null ? true : (bool) $row['dynamic_enabled'],
            'dynamic_visible_days' => self::normalizeDays($row['dynamic_visible_days'] ?? 0),
            'dynamic_visibility_mode' => self::normalizeMode((string) ($row['dynamic_visibility_mode'] ?? 'public'), false),
            'dynamic_allow_user_ids' => self::decodeIds($row['dynamic_allow_user_ids_json'] ?? null),
            'dynamic_deny_user_ids' => self::decodeIds($row['dynamic_deny_user_ids_json'] ?? null),
            'dynamic_visible_to_friends' => $row === null ? true : (bool) $row['dynamic_visible_to_friends'],
            'dynamic_visible_to_followers' => $row === null ? true : (bool) $row['dynamic_visible_to_followers'],
            'dynamic_visible_to_strangers' => $row === null ? true : (bool) $row['dynamic_visible_to_strangers'],
            'dynamic_visible_to_hidden_contacts' => $row === null ? true : (bool) $row['dynamic_visible_to_hidden_contacts'],
            'dynamic_visible_to_special_care' => $row === null ? true : (bool) $row['dynamic_visible_to_special_care'],
        ];
    }

    public static function normalizeMode(string $mode, bool $allowInherit): string
    {
        $mode = strtolower(trim($mode));
        $allowed = $allowInherit ? self::MODES : self::GLOBAL_MODES;
        if (!in_array($mode, $allowed, true)) {
            throw new HttpException('动态可见范围不正确', 0, 422, ['allowed' => $allowed]);
        }
        return $mode;
    }

    public static function normalizeDays($days): int
    {
        $days = (int) $days;
        if (!in_array($days, self::DAYS, true)) {
            throw new HttpException('动态可见时间不正确', 0, 422, ['allowed_days' => self::DAYS]);
        }
        return $days;
    }

    public static function normalizeUserIds($value, int $appId, int $ownerId, int $limit = 200): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[\s,，;；]+/u', trim($value));
        }
        if (!is_array($value)) return [];
        $tokens = [];
        foreach ($value as $entry) {
            if (is_array($entry)) $entry = $entry['id'] ?? $entry['user_id'] ?? $entry['uid'] ?? $entry['account'] ?? '';
            $token = trim((string) $entry);
            if ($token !== '') $tokens[$token] = $token;
        }
        if (count($tokens) > $limit) throw new HttpException('指定用户数量不能超过 ' . $limit . ' 人', 0, 422);

        $valid = [];
        foreach ($tokens as $token) {
            $row = ctype_digit($token)
                ? Database::one(
                    'SELECT id FROM users WHERE app_id = ? AND deleted_at IS NULL AND (id = ? OR uid = ?) ORDER BY id = ? DESC LIMIT 1',
                    [$appId, (int) $token, $token, (int) $token]
                )
                : Database::one(
                    'SELECT id FROM users WHERE app_id = ? AND deleted_at IS NULL AND (account = ? OR uid = ?) LIMIT 1',
                    [$appId, $token, $token]
                );
            if ($row !== null && (int) $row['id'] !== $ownerId) $valid[(int) $row['id']] = (int) $row['id'];
        }
        $valid = array_values($valid);
        sort($valid);
        return $valid;
    }

    public static function encodeIds(array $ids): ?string
    {
        if ($ids === []) return null;
        return json_encode(array_values($ids), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function decodeIds($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) return [];
        $ids = [];
        foreach ($value as $entry) {
            $id = is_array($entry) ? (int) ($entry['id'] ?? $entry['user_id'] ?? 0) : (int) $entry;
            if ($id > 0) $ids[$id] = $id;
        }
        $ids = array_values($ids);
        sort($ids);
        return $ids;
    }

    private static function blocked(int $appId, int $ownerId, int $viewerId): bool
    {
        return Database::one(
            'SELECT id FROM user_blacklist
             WHERE app_id = ? AND ((user_id = ? AND blocked_user_id = ?) OR (user_id = ? AND blocked_user_id = ?)) LIMIT 1',
            [$appId, $ownerId, $viewerId, $viewerId, $ownerId]
        ) !== null;
    }

    private static function relationship(int $appId, int $ownerId, int $viewerId): array
    {
        $friend = Database::one(
            'SELECT special_care, hide_my_notes FROM friends
             WHERE app_id = ? AND user_id = ? AND friend_user_id = ? AND status = 1 LIMIT 1',
            [$appId, $ownerId, $viewerId]
        );
        $follower = Database::one(
            'SELECT id FROM user_follows WHERE app_id = ? AND follower_user_id = ? AND followed_user_id = ? LIMIT 1',
            [$appId, $viewerId, $ownerId]
        ) !== null;
        $conversationHidden = Database::one(
            "SELECT id FROM conversation_preferences
             WHERE app_id = ? AND user_id = ? AND target_type = 'private' AND target_id = ? AND is_hidden = 1 LIMIT 1",
            [$appId, $ownerId, $viewerId]
        ) !== null;
        return [
            'friend' => $friend !== null,
            'follower' => $follower,
            'special_care' => $friend !== null && (bool) $friend['special_care'],
            'hidden' => $conversationHidden || ($friend !== null && (bool) $friend['hide_my_notes']),
        ];
    }
}
