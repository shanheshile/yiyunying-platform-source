<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class ChatRoomService
{
    public const ROOM_GROUP = 'group';
    public const ROOM_CHATROOM = 'chat_room';
    public const JOIN_OPEN = 'open';
    public const JOIN_APPROVAL = 'approval';
    public const JOIN_INVITE = 'invite';

    public static function adminRoom(int $adminId, int $appId, int $roomId, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM chat_rooms WHERE id = ? AND admin_id = ? AND app_id = ?';
        if ($activeOnly) {
            $sql .= ' AND status = 1';
        }
        $room = Database::one($sql, [$roomId, $adminId, $appId]);
        if ($room === null) {
            throw new HttpException('群聊不存在', 404, 404);
        }
        return $room;
    }

    public static function userRoom(array $user, int $roomId, bool $requireMember = false): array
    {
        $room = self::adminRoom((int) $user['admin_id'], (int) $user['app_id'], $roomId, true);
        $policy = self::policy($room);
        $member = self::member($roomId, (int) $user['id']);
        if ($requireMember && $member === null) {
            throw new HttpException('请先加入该群聊', 403, 403, ['join_mode' => $policy['join_mode']]);
        }
        if ($member === null && $policy['join_mode'] === self::JOIN_INVITE) {
            $invitation = Database::one(
                "SELECT id FROM chat_room_invitations
                 WHERE room_id = ? AND invitee_user_id = ? AND status = 'pending'
                   AND (expired_at IS NULL OR expired_at > NOW())",
                [$roomId, (int) $user['id']]
            );
            if ($invitation === null) {
                throw new HttpException('该群聊仅受邀成员可见', 403, 403);
            }
        }
        return $room;
    }

    public static function policy(array $room): array
    {
        $policy = Database::one('SELECT * FROM chat_room_policies WHERE room_id = ?', [(int) $room['id']]);
        if ($policy !== null) {
            return self::normalizePolicy($policy);
        }
        return [
            'owner_user_id' => null,
            'join_mode' => (bool) $room['is_public'] ? self::JOIN_OPEN : self::JOIN_INVITE,
            'max_members' => 500,
            'allow_member_invite' => true,
            'mute_all' => false,
            'announcement' => '',
        ];
    }

    public static function savePolicy(array $room, array $values): array
    {
        $current = self::policy($room);
        $owner = array_key_exists('owner_user_id', $values) ? $values['owner_user_id'] : $current['owner_user_id'];
        $joinMode = self::joinMode($values['join_mode'] ?? $current['join_mode']);
        $maxMembers = max(2, min(5000, (int) ($values['max_members'] ?? $current['max_members'])));
        $allowInvite = array_key_exists('allow_member_invite', $values)
            ? (bool) $values['allow_member_invite'] : (bool) $current['allow_member_invite'];
        $muteAll = array_key_exists('mute_all', $values) ? (bool) $values['mute_all'] : (bool) $current['mute_all'];
        $announcement = mb_substr((string) ($values['announcement'] ?? $current['announcement']), 0, 2000);
        Database::execute(
            'INSERT INTO chat_room_policies
             (admin_id, app_id, room_id, owner_user_id, join_mode, max_members, allow_member_invite, mute_all, announcement, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE owner_user_id = VALUES(owner_user_id), join_mode = VALUES(join_mode),
               max_members = VALUES(max_members), allow_member_invite = VALUES(allow_member_invite),
               mute_all = VALUES(mute_all), announcement = VALUES(announcement), updated_at = NOW()',
            [
                (int) $room['admin_id'], (int) $room['app_id'], (int) $room['id'],
                $owner === null ? null : (int) $owner, $joinMode, $maxMembers,
                $allowInvite ? 1 : 0, $muteAll ? 1 : 0, $announcement,
            ]
        );
        Database::execute(
            'UPDATE chat_rooms SET is_public = ?, updated_at = NOW() WHERE id = ? AND admin_id = ? AND app_id = ?',
            [
                $joinMode === self::JOIN_INVITE ? 0 : 1,
                (int) $room['id'], (int) $room['admin_id'], (int) $room['app_id'],
            ]
        );
        return self::policy($room);
    }

    public static function detail(array $room, ?int $currentUserId = null): array
    {
        $result = $room;
        $result['room_kind'] = self::roomKind($room['room_kind'] ?? self::ROOM_GROUP);
        $policy = self::policy($room);
        foreach ($policy as $key => $value) {
            $result[$key] = $value;
        }
        $counts = Database::one(
            'SELECT
               (SELECT COUNT(*) FROM chat_room_members WHERE room_id = ?) AS member_count,
               (SELECT COUNT(*) FROM chat_room_messages WHERE room_id = ? AND status = 1) AS message_count,
               (SELECT COUNT(*) FROM chat_room_join_requests WHERE room_id = ? AND status = ?) AS pending_request_count',
            [(int) $room['id'], (int) $room['id'], (int) $room['id'], 'pending']
        ) ?? [];
        $result['id'] = (int) $room['id'];
        $result['admin_id'] = (int) $room['admin_id'];
        $result['app_id'] = (int) $room['app_id'];
        $result['is_public'] = (bool) $room['is_public'];
        $result['status'] = (int) $room['status'];
        $result['tags'] = ContentTagService::decode($room['tags_json'] ?? null);
        unset($result['tags_json']);
        $result['member_count'] = (int) ($counts['member_count'] ?? 0);
        $result['message_count'] = (int) ($counts['message_count'] ?? 0);
        $result['pending_request_count'] = (int) ($counts['pending_request_count'] ?? 0);
        if ($currentUserId !== null) {
            $member = self::member((int) $room['id'], $currentUserId);
            $result['joined'] = $member !== null;
            $result['current_role'] = $member['role'] ?? null;
            $result['mute_until'] = $member['mute_until'] ?? null;
            $result['history_visible_from'] = $member['history_visible_from'] ?? null;
            $read = Database::one(
                'SELECT last_read_message_id FROM chat_room_reads WHERE room_id = ? AND user_id = ?',
                [(int) $room['id'], $currentUserId]
            );
            $lastRead = (int) ($read['last_read_message_id'] ?? 0);
            $result['last_read_message_id'] = $lastRead;
            $result['unread_count'] = (int) (Database::one(
                "SELECT COUNT(*) AS total FROM chat_room_messages
                 WHERE room_id = ? AND status = 1 AND id > ?
                   AND NOT (sender_type = 'user' AND user_id = ?)",
                [(int) $room['id'], $lastRead, $currentUserId]
            )['total'] ?? 0);
            $latest = Database::one(
                'SELECT id, content, content_type, created_at FROM chat_room_messages
                 WHERE room_id = ? AND status = 1 ORDER BY id DESC LIMIT 1',
                [(int) $room['id']]
            );
            $result['last_message_id'] = $latest === null ? null : (int) $latest['id'];
            $result['last_message'] = $latest['content'] ?? '';
            $result['last_message_at'] = $latest['created_at'] ?? null;
        }
        return $result;
    }

    public static function member(int $roomId, int $userId): ?array
    {
        return Database::one(
            'SELECT * FROM chat_room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, $userId]
        );
    }

    public static function requireMember(array $user, array $room): array
    {
        $member = self::member((int) $room['id'], (int) $user['id']);
        if ($member === null) {
            throw new HttpException('你不是该群聊成员', 403, 403);
        }
        return $member;
    }

    public static function requireManager(array $user, array $room): array
    {
        $member = self::requireMember($user, $room);
        if (!in_array((string) $member['role'], ['owner', 'admin'], true)) {
            throw new HttpException('只有群主或群管理员可以执行此操作', 403, 403);
        }
        return $member;
    }

    public static function requireOwner(array $user, array $room): array
    {
        $member = self::requireMember($user, $room);
        $policy = self::policy($room);
        if ((string) $member['role'] !== 'owner' || (int) ($policy['owner_user_id'] ?? 0) !== (int) $user['id']) {
            throw new HttpException('只有群主可以执行此操作', 403, 403);
        }
        return $member;
    }

    public static function tenantUser(array $room, int $userId): array
    {
        $user = Database::one(
            'SELECT id, admin_id, app_id, account, status, deleted_at FROM users
             WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1 AND deleted_at IS NULL',
            [$userId, (int) $room['admin_id'], (int) $room['app_id']]
        );
        if ($user === null) {
            throw new HttpException('用户不存在或不属于当前应用', 404, 404);
        }
        return $user;
    }

    public static function addMember(
        array $room,
        int $userId,
        string $role = 'member',
        ?string $historyVisibleFrom = null
    ): array
    {
        return Database::transaction(static function () use ($room, $userId, $role, $historyVisibleFrom): array {
            self::tenantUser($room, $userId);
            $role = self::memberRole($role);
            $lockedRoom = Database::one(
                'SELECT id FROM chat_rooms WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1 FOR UPDATE',
                [(int) $room['id'], (int) $room['admin_id'], (int) $room['app_id']]
            );
            if ($lockedRoom === null) throw new HttpException('群聊不存在或已解散', 404, 404);
            $existing = self::member((int) $room['id'], $userId);
            if ($existing === null) {
                $policy = self::policy($room);
                $count = (int) (Database::one(
                    'SELECT COUNT(*) AS total FROM chat_room_members WHERE room_id = ?',
                    [(int) $room['id']]
                )['total'] ?? 0);
                if ($count >= (int) $policy['max_members']) {
                    throw new HttpException('群聊人数已达到上限', 0, 409, ['max_members' => (int) $policy['max_members']]);
                }
                Database::execute(
                    'INSERT INTO chat_room_members
                     (admin_id, app_id, room_id, user_id, role, mute_until, joined_at, history_visible_from)
                     VALUES (?, ?, ?, ?, ?, NULL, NOW(), ?)',
                    [
                        (int) $room['admin_id'], (int) $room['app_id'], (int) $room['id'],
                        $userId, $role, $historyVisibleFrom,
                    ]
                );
            } else {
                Database::execute(
                    'UPDATE chat_room_members
                     SET role = ?, mute_until = NULL, history_visible_from = ? WHERE id = ?',
                    [$role, $historyVisibleFrom, (int) $existing['id']]
                );
            }
            Database::execute(
                "UPDATE chat_room_invitations SET status = 'accepted', responded_at = NOW(), updated_at = NOW()
                 WHERE room_id = ? AND invitee_user_id = ? AND status IN ('pending','ignored')",
                [(int) $room['id'], $userId]
            );
            Database::execute(
                "UPDATE chat_room_join_requests SET status = 'approved', handled_at = NOW(), updated_at = NOW()
                 WHERE room_id = ? AND user_id = ? AND status IN ('pending','ignored')",
                [(int) $room['id'], $userId]
            );
            return self::member((int) $room['id'], $userId) ?? [];
        });
    }

    public static function removeMember(array $room, int $userId): void
    {
        Database::execute(
            'DELETE FROM chat_room_members WHERE room_id = ? AND user_id = ?',
            [(int) $room['id'], $userId]
        );
        Database::execute(
            'DELETE FROM chat_room_reads WHERE room_id = ? AND user_id = ?',
            [(int) $room['id'], $userId]
        );
    }

    public static function mayInvite(array $user, array $room): bool
    {
        $member = self::requireMember($user, $room);
        return in_array((string) $member['role'], ['owner', 'admin'], true)
            || (bool) self::policy($room)['allow_member_invite'];
    }

    public static function joinMode($value): string
    {
        $mode = trim((string) $value);
        if (!in_array($mode, [self::JOIN_OPEN, self::JOIN_APPROVAL, self::JOIN_INVITE], true)) {
            throw new HttpException('join_mode 仅支持 open、approval、invite', 0, 422);
        }
        return $mode;
    }

    public static function roomKind($value): string
    {
        $kind = trim((string) $value);
        if (!in_array($kind, [self::ROOM_GROUP, self::ROOM_CHATROOM], true)) {
            throw new HttpException('room_kind 仅支持 group 或 chat_room', 0, 422);
        }
        return $kind;
    }

    public static function memberRole($value): string
    {
        $role = trim((string) $value);
        if (!in_array($role, ['owner', 'admin', 'member'], true)) {
            throw new HttpException('成员角色仅支持 owner、admin、member', 0, 422);
        }
        return $role;
    }

    public static function contentType($value): string
    {
        $type = trim((string) $value);
        if (!in_array($type, ['text', 'image', 'file'], true)) {
            throw new HttpException('content_type 仅支持 text、image、file', 0, 422);
        }
        return $type;
    }

    private static function normalizePolicy(array $policy): array
    {
        return [
            'owner_user_id' => $policy['owner_user_id'] === null ? null : (int) $policy['owner_user_id'],
            'join_mode' => (string) $policy['join_mode'],
            'max_members' => (int) $policy['max_members'],
            'allow_member_invite' => (bool) $policy['allow_member_invite'],
            'mute_all' => (bool) $policy['mute_all'],
            'announcement' => (string) $policy['announcement'],
        ];
    }
}
