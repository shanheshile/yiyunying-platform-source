<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\User;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Validator;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\ChatRoomService;
use Yiyunying\Services\ContentTagService;
use Yiyunying\Services\GroupQrService;
use Yiyunying\Services\IdentityService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\MessageForwardService;
use Yiyunying\Services\MessageEditService;
use Yiyunying\Services\MessageMediaService;
use Yiyunying\Services\MessagePresentationService;
use Yiyunying\Services\NotificationService;
use Yiyunying\Services\ProfileAvatarService;

final class GroupController
{
    public static function index(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = [
            'r.admin_id = ?', 'r.app_id = ?', 'r.status = 1',
            "(COALESCE(cp.join_mode, IF(r.is_public = 1, 'open', 'invite')) <> 'invite'
              OR mine.id IS NOT NULL OR invitation.id IS NOT NULL)",
        ];
        $query = [(int) $user['admin_id'], (int) $user['app_id']];
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(r.name LIKE ? OR r.description LIKE ? OR r.tags_json LIKE ? OR rus.remark LIKE ? OR CAST(20000000000 + r.id AS CHAR) LIKE ?)';
            foreach (range(1, 5) as $_) $query[] = '%' . $keyword . '%';
        }
        $groupId = (int) $request->input('group_id', -1);
        if ($groupId > 0) { $where[] = 'rus.group_id = ?'; $query[] = $groupId; }
        elseif ($groupId === 0) $where[] = 'rus.group_id IS NULL';
        $whereSql = implode(' AND ', $where);
        $joins = " LEFT JOIN chat_room_policies cp ON cp.room_id = r.id
                   LEFT JOIN chat_room_members mine ON mine.room_id = r.id AND mine.user_id = " . (int) $user['id'] . "
                   LEFT JOIN chat_room_invitations invitation ON invitation.room_id = r.id
                     AND invitation.invitee_user_id = " . (int) $user['id'] . " AND invitation.status = 'pending'
                     AND (invitation.expired_at IS NULL OR invitation.expired_at > NOW())
                   LEFT JOIN chat_room_user_settings rus ON rus.room_id = r.id AND rus.user_id = " . (int) $user['id'] . "
                   LEFT JOIN chat_room_user_groups rug ON rug.id = rus.group_id";
        $total = (int) (Database::one(
            "SELECT COUNT(DISTINCT r.id) AS total FROM chat_rooms r {$joins} WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $rooms = Database::all(
            "SELECT DISTINCT r.*, rus.remark AS user_remark, rus.group_id AS user_group_id, rug.name AS user_group_name
             FROM chat_rooms r {$joins} WHERE {$whereSql}
             ORDER BY (mine.id IS NOT NULL) DESC, r.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = array_map(static fn(array $room): array => ChatRoomService::detail($room, (int) $user['id']), $rooms);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function userGroups(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $items = Database::all(
            'SELECT g.*, COUNT(s.id) AS room_count FROM chat_room_user_groups g
             LEFT JOIN chat_room_user_settings s ON s.group_id = g.id
             WHERE g.app_id = ? AND g.user_id = ? GROUP BY g.id ORDER BY g.sort_order DESC, g.id',
            [(int) $user['app_id'], (int) $user['id']]
        );
        return Response::success(['items' => $items]);
    }

    public static function createUserGroup(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $id = Database::insert(
            'INSERT INTO chat_room_user_groups (admin_id, app_id, user_id, name, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], Validator::string($request->input('name', ''), 'name', 1, 60), (int) $request->input('sort_order', 0)]
        );
        return Response::success(['group_id' => $id], '群聊分组已创建', 201);
    }

    public static function updateUserGroup(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $group = self::ownedUserGroup($user, (int) $params['group_id']);
        Database::execute('UPDATE chat_room_user_groups SET name = ?, sort_order = ?, updated_at = NOW() WHERE id = ?', [
            Validator::string($request->input('name', $group['name']), 'name', 1, 60),
            (int) $request->input('sort_order', $group['sort_order']), (int) $group['id'],
        ]);
        return Response::success([], '群聊分组已更新');
    }

    public static function deleteUserGroup(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $group = self::ownedUserGroup($user, (int) $params['group_id']);
        Database::execute('DELETE FROM chat_room_user_groups WHERE id = ?', [(int) $group['id']]);
        return Response::success([], '群聊分组已删除，群聊已移至未分组');
    }

    public static function saveUserSettings(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        AppService::requireFeature((int) $user['app_id'], 'chat_rooms');
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        ChatRoomService::requireMember($user, $room);
        $groupId = (int) $request->input('group_id', 0);
        if ($groupId > 0) self::ownedUserGroup($user, $groupId);
        $remark = mb_substr(trim((string) $request->input('remark', '')), 0, 100);
        Database::execute(
            'INSERT INTO chat_room_user_settings (admin_id, app_id, user_id, room_id, group_id, remark, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE group_id = VALUES(group_id), remark = VALUES(remark), updated_at = NOW()',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], (int) $room['id'], $groupId > 0 ? $groupId : null, $remark]
        );
        return Response::success(['room_id' => (int) $room['id'], 'group_id' => $groupId, 'remark' => $remark], '群聊备注与分组已更新');
    }

    private static function ownedUserGroup(array $user, int $groupId): array
    {
        $group = Database::one('SELECT * FROM chat_room_user_groups WHERE id = ? AND app_id = ? AND user_id = ?', [$groupId, (int) $user['app_id'], (int) $user['id']]);
        if ($group === null) throw new HttpException('群聊分组不存在', 404, 404);
        return $group;
    }

    public static function create(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        AuthService::ensureNotBanned($user, ['all', 'message', 'chat']);
        $roomKind = ChatRoomService::roomKind($request->input('room_kind', ChatRoomService::ROOM_GROUP));
        $isChatroom = $roomKind === ChatRoomService::ROOM_CHATROOM;
        $entity = $isChatroom ? '聊天室' : '群聊';
        $enabledKey = $isChatroom ? 'user_chatroom_create_enabled' : 'user_group_create_enabled';
        $limitKey = $isChatroom ? 'user_chatroom_max_owned' : 'user_group_max_owned';
        $memberLimitKey = $isChatroom ? 'chatroom_default_max_members' : 'group_default_max_members';
        if (!AppService::setting((int) $user['app_id'], $enabledKey, true)) {
            throw new HttpException('管理员已关闭用户创建' . $entity . '功能', 403, 403);
        }
        $ownedLimit = max(0, (int) AppService::setting((int) $user['app_id'], $limitKey, 10));
        $defaultMemberLimit = max(2, (int) AppService::setting((int) $user['app_id'], $memberLimitKey, 500));
        $name = Validator::string($request->input('name', ''), 'name', 1, 100);
        $icon = mb_substr(trim((string) $request->input('icon', '')), 0, 500);
        if ($icon !== '') {
            AppService::requireFeature(
                (int) $user['app_id'],
                $isChatroom ? 'chatroom_avatar_upload' : 'group_avatar_upload'
            );
        }
        $defaultJoinMode = $isChatroom ? ChatRoomService::JOIN_OPEN : ChatRoomService::JOIN_APPROVAL;
        $joinMode = ChatRoomService::joinMode($request->input('join_mode', $defaultJoinMode));
        $initialMemberIds = self::initialFriendIds($request, $user);
        $maxMembers = max(2, min(5000, (int) $request->input('max_members', $defaultMemberLimit)));
        if (count($initialMemberIds) + 1 > $maxMembers) {
            throw new HttpException('首批成员数量超过群人数上限', 0, 422, [
                'selected' => count($initialMemberIds),
                'max_members' => $maxMembers,
            ]);
        }
        $roomId = Database::transaction(static function () use (
            $request, $user, $name, $icon, $joinMode, $ownedLimit, $maxMembers, $roomKind, $entity, $initialMemberIds
        ): int {
            Database::one('SELECT id FROM users WHERE id = ? FOR UPDATE', [(int) $user['id']]);
            $owned = (int) (Database::one(
                'SELECT COUNT(*) AS total FROM chat_room_policies cp INNER JOIN chat_rooms r ON r.id = cp.room_id
                 WHERE cp.app_id = ? AND cp.owner_user_id = ? AND r.room_kind = ? AND r.status = 1',
                [(int) $user['app_id'], (int) $user['id'], $roomKind]
            )['total'] ?? 0);
            if ($ownedLimit === 0 || $owned >= $ownedLimit) {
                throw new HttpException('你创建的' . $entity . '数量已达到上限', 0, 409, [
                    'limit' => $ownedLimit, 'used' => $owned, 'room_kind' => $roomKind,
                ]);
            }
            $id = Database::insert(
                'INSERT INTO chat_rooms
                 (admin_id, app_id, name, icon, description, tags_json, room_kind, is_public, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], $name,
                    $icon,
                    mb_substr((string) $request->input('description', ''), 0, 1000),
                    ContentTagService::encode($request->input('tags', [])),
                    $roomKind,
                    $joinMode === ChatRoomService::JOIN_INVITE ? 0 : 1,
                ]
            );
            $room = ChatRoomService::adminRoom((int) $user['admin_id'], (int) $user['app_id'], $id);
            ChatRoomService::savePolicy($room, [
                'owner_user_id' => (int) $user['id'],
                'join_mode' => $joinMode,
                'max_members' => $maxMembers,
                'allow_member_invite' => Validator::boolean($request->input('allow_member_invite', true), 'allow_member_invite'),
                'mute_all' => false,
                'announcement' => (string) $request->input('announcement', ''),
            ]);
            ChatRoomService::addMember($room, (int) $user['id'], 'owner');
            foreach ($initialMemberIds as $memberId) {
                ChatRoomService::addMember($room, $memberId, 'member');
            }
            return $id;
        });
        $room = ChatRoomService::adminRoom((int) $user['admin_id'], (int) $user['app_id'], $roomId);
        LogService::userOperation($request, $user, $roomKind, 'create', $roomId, [
            'name' => $name, 'room_kind' => $roomKind,
        ]);
        return Response::success([
            'room' => ChatRoomService::detail($room, (int) $user['id']),
            'initial_member_ids' => $initialMemberIds,
        ], $entity . '创建成功', 201);
    }

    private static function initialFriendIds(Request $request, array $user): array
    {
        $raw = $request->input('initial_member_ids', []);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            throw new HttpException('initial_member_ids 必须是用户编号数组', 0, 422);
        }
        $ids = [];
        foreach ($raw as $value) {
            if (!is_scalar($value)) continue;
            $id = (int) $value;
            if ($id > 0 && $id !== (int) $user['id']) $ids[$id] = $id;
        }
        $ids = array_values($ids);
        if (count($ids) > 99) {
            throw new HttpException('一次最多选择 99 位好友', 0, 422);
        }
        if ($ids === []) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = [(int) $user['app_id'], (int) $user['id']];
        array_push($params, ...$ids);
        $rows = Database::all(
            "SELECT friend_user_id FROM friends
             WHERE app_id = ? AND user_id = ? AND status = 1
               AND friend_user_id IN ({$placeholders})",
            $params
        );
        $allowed = [];
        foreach ($rows as $row) $allowed[(int) $row['friend_user_id']] = true;
        $invalid = array_values(array_filter($ids, static fn (int $id): bool => !isset($allowed[$id])));
        if ($invalid !== []) {
            throw new HttpException('只能邀请当前账号的好友', 0, 422, ['invalid_user_ids' => $invalid]);
        }
        return $ids;
    }

    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $room = ChatRoomService::userRoom($user, (int) $params['room_id']);
        return Response::success(['room' => ChatRoomService::detail($room, (int) $user['id'])]);
    }

    public static function update(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        AppService::requireFeature((int) $user['app_id'], 'chat_rooms');
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        ChatRoomService::requireManager($user, $room);
        if (array_key_exists('icon', $request->all())) {
            $roomKind = ChatRoomService::roomKind($room['room_kind'] ?? ChatRoomService::ROOM_GROUP);
            AppService::requireFeature(
                (int) $user['app_id'],
                $roomKind === ChatRoomService::ROOM_CHATROOM
                    ? 'chatroom_avatar_upload'
                    : 'group_avatar_upload'
            );
        }
        $before = ChatRoomService::detail($room, (int) $user['id']);
        $name = array_key_exists('name', $request->all())
            ? Validator::string($request->input('name'), 'name', 1, 100) : (string) $room['name'];
        Database::execute(
            'UPDATE chat_rooms SET name = ?, icon = ?, description = ?, tags_json = ?, updated_at = NOW() WHERE id = ?',
            [
                $name,
                mb_substr((string) $request->input('icon', $room['icon']), 0, 500),
                mb_substr((string) $request->input('description', $room['description']), 0, 1000),
                ContentTagService::encode($request->input('tags', ContentTagService::decode($room['tags_json'] ?? null))),
                (int) $room['id'],
            ]
        );
        $policyValues = [];
        foreach (['join_mode', 'max_members', 'announcement'] as $field) {
            if (array_key_exists($field, $request->all())) $policyValues[$field] = $request->input($field);
        }
        foreach (['allow_member_invite', 'mute_all'] as $field) {
            if (array_key_exists($field, $request->all())) {
                $policyValues[$field] = Validator::boolean($request->input($field), $field);
            }
        }
        if ($policyValues !== []) ChatRoomService::savePolicy($room, $policyValues);
        $afterRoom = ChatRoomService::adminRoom((int) $user['admin_id'], (int) $user['app_id'], (int) $room['id']);
        $after = ChatRoomService::detail($afterRoom, (int) $user['id']);
        LogService::userOperation($request, $user, 'group', 'update', (int) $room['id'], ['before' => $before, 'after' => $after]);
        return Response::success(['room' => $after], '群资料已更新');
    }

    public static function avatar(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        AppService::requireFeature((int) $user['app_id'], 'chat_rooms');
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        ChatRoomService::requireManager($user, $room);
        $roomKind = ChatRoomService::roomKind($room['room_kind'] ?? ChatRoomService::ROOM_GROUP);
        AppService::requireFeature(
            (int) $user['app_id'],
            $roomKind === ChatRoomService::ROOM_CHATROOM
                ? 'chatroom_avatar_upload'
                : 'group_avatar_upload'
        );
        $result = ProfileAvatarService::upload($roomKind, (int) $room['id']);
        Database::execute(
            'UPDATE chat_rooms SET icon = ?, updated_at = NOW() WHERE id = ? AND admin_id = ? AND app_id = ?',
            [(string) $result['avatar'], (int) $room['id'], (int) $user['admin_id'], (int) $user['app_id']]
        );
        $updated = ChatRoomService::adminRoom(
            (int) $user['admin_id'],
            (int) $user['app_id'],
            (int) $room['id']
        );
        LogService::userOperation($request, $user, $roomKind, 'avatar_update', (int) $room['id']);
        return Response::success(
            $result + [
                'icon' => (string) $result['avatar'],
                'room' => ChatRoomService::detail($updated, (int) $user['id']),
            ],
            $roomKind === ChatRoomService::ROOM_CHATROOM ? '聊天室头像上传成功' : '群聊头像上传成功',
            201
        );
    }

    public static function dissolve(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        ChatRoomService::requireOwner($user, $room);
        $roomKind = ChatRoomService::roomKind($room['room_kind'] ?? ChatRoomService::ROOM_GROUP);
        $entity = $roomKind === ChatRoomService::ROOM_CHATROOM ? '聊天室' : '群聊';
        $restoreDays = min(365, max(0, (int) AppService::setting((int) $user['app_id'], 'group_restore_days', 7)));
        $restoreUntil = $restoreDays > 0 ? date('Y-m-d H:i:s', time() + $restoreDays * 86400) : null;
        self::roomNotice($room, $user, '创建者解散了' . $entity);
        Database::execute(
            'UPDATE chat_rooms SET status = 0, dissolved_at = NOW(), restore_until = ?, updated_at = NOW() WHERE id = ?',
            [$restoreUntil, (int) $room['id']]
        );
        LogService::userOperation($request, $user, $roomKind, 'dissolve', (int) $room['id']);
        return Response::success([
            'room_id' => (int) $room['id'],
            'room_kind' => $roomKind,
            'restore_available' => $restoreUntil !== null,
            'restore_until' => $restoreUntil,
            'restore_days' => $restoreDays,
            'unit' => '天',
        ], $restoreUntil === null ? $entity . '已永久解散' : $entity . '已解散，可在恢复期限内找回');
    }

    public static function dissolved(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $query = [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']];
        $total = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM chat_rooms r
             INNER JOIN chat_room_policies p ON p.room_id = r.id
             WHERE r.admin_id = ? AND r.app_id = ? AND p.owner_user_id = ? AND r.status = 0',
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT r.id, r.name, r.icon, r.description, r.room_kind, r.dissolved_at, r.restore_until,
                    CASE WHEN r.restore_until IS NOT NULL AND r.restore_until > NOW() THEN 1 ELSE 0 END AS can_restore
             FROM chat_rooms r INNER JOIN chat_room_policies p ON p.room_id = r.id
             WHERE r.admin_id = ? AND r.app_id = ? AND p.owner_user_id = ? AND r.status = 0
             ORDER BY r.dissolved_at DESC, r.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        foreach ($items as &$item) {
            $item['can_restore'] = (bool) $item['can_restore'];
            $item['room_kind'] = ChatRoomService::roomKind($item['room_kind'] ?? ChatRoomService::ROOM_GROUP);
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function restore(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $room = Database::one(
            'SELECT r.*, p.owner_user_id FROM chat_rooms r
             INNER JOIN chat_room_policies p ON p.room_id = r.id
             WHERE r.id = ? AND r.admin_id = ? AND r.app_id = ? AND r.status = 0',
            [(int) $params['room_id'], (int) $user['admin_id'], (int) $user['app_id']]
        );
        if ($room === null || (int) $room['owner_user_id'] !== (int) $user['id']) {
            throw new HttpException('已解散的群聊或聊天室不存在，或你不是创建者', 404, 404);
        }
        $roomKind = ChatRoomService::roomKind($room['room_kind'] ?? ChatRoomService::ROOM_GROUP);
        $isChatroom = $roomKind === ChatRoomService::ROOM_CHATROOM;
        $entity = $isChatroom ? '聊天室' : '群聊';
        if ($room['restore_until'] === null || strtotime((string) $room['restore_until']) <= time()) {
            throw new HttpException($entity . '恢复期限已过，无法恢复', 0, 410);
        }
        $limitKey = $isChatroom ? 'user_chatroom_max_owned' : 'user_group_max_owned';
        $ownedLimit = max(0, (int) AppService::setting((int) $user['app_id'], $limitKey, 10));
        $owned = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM chat_room_policies p INNER JOIN chat_rooms r ON r.id = p.room_id
             WHERE p.app_id = ? AND p.owner_user_id = ? AND r.room_kind = ? AND r.status = 1',
            [(int) $user['app_id'], (int) $user['id'], $roomKind]
        )['total'] ?? 0);
        if ($ownedLimit === 0 || $owned >= $ownedLimit) {
            throw new HttpException('当前有效' . $entity . '数量已达到上限，暂时不能恢复', 0, 409, [
                'limit' => $ownedLimit, 'used' => $owned, 'room_kind' => $roomKind,
            ]);
        }
        Database::execute(
            'UPDATE chat_rooms SET status = 1, dissolved_at = NULL, restore_until = NULL, updated_at = NOW() WHERE id = ?',
            [(int) $room['id']]
        );
        $restored = ChatRoomService::adminRoom((int) $user['admin_id'], (int) $user['app_id'], (int) $room['id'], true);
        self::roomNotice($restored, $user, '创建者恢复了' . $entity);
        LogService::userOperation($request, $user, $roomKind, 'restore', (int) $room['id']);
        return Response::success([
            'room' => ChatRoomService::detail($restored, (int) $user['id']),
        ], $entity . '恢复成功');
    }
    public static function qrCode(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        $entity = ChatRoomService::roomKind($room['room_kind'] ?? ChatRoomService::ROOM_GROUP) === ChatRoomService::ROOM_CHATROOM ? '聊天室' : '群聊';
        if (!ChatRoomService::mayInvite($user, $room)) {
            throw new HttpException('当前' . $entity . '不允许你生成邀请二维码', 0, 403);
        }
        $payload = GroupQrService::encode($room, (int) $user['id']);
        $decoded = ['signed' => true, 'issuer_user_id' => (int) $user['id']];
        return Response::success([
            'qr_code' => $payload,
            'room' => self::qrRoomPreview($user, $room, $decoded),
        ], $entity . '二维码已生成');
    }

    public static function scanQr(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        [$decoded, $room] = self::decodedQrRoom($request, $user);
        $entity = ChatRoomService::roomKind($room['room_kind'] ?? ChatRoomService::ROOM_GROUP) === ChatRoomService::ROOM_CHATROOM ? '聊天室' : '群聊';
        return Response::success([
            'room' => self::qrRoomPreview($user, $room, $decoded),
        ], '已识别' . $entity . '二维码');
    }

    public static function joinQr(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        AuthService::ensureNotBanned($user, ['all', 'message', 'chat']);
        [$decoded, $room] = self::decodedQrRoom($request, $user);
        $entity = ChatRoomService::roomKind($room['room_kind'] ?? ChatRoomService::ROOM_GROUP) === ChatRoomService::ROOM_CHATROOM ? '聊天室' : '群聊';
        if (ChatRoomService::member((int) $room['id'], (int) $user['id']) !== null) {
            return Response::success([
                'joined' => true,
                'pending' => false,
                'room' => ChatRoomService::detail($room, (int) $user['id']),
            ], '你已在' . $entity . '中');
        }

        $policy = ChatRoomService::policy($room);
        if ($policy['join_mode'] === ChatRoomService::JOIN_APPROVAL) {
            return self::submitQrJoinRequest($request, $user, $room);
        }
        if ($policy['join_mode'] === ChatRoomService::JOIN_INVITE
            && self::pendingInvitation($room, $user) === null
            && !self::signedQrMayInvite($decoded, $room)) {
            throw new HttpException('该' . $entity . '仅限受邀成员加入，此二维码不能直接加入', 0, 403);
        }

        ChatRoomService::addMember($room, (int) $user['id']);
        LogService::userOperation($request, $user, ChatRoomService::roomKind($room['room_kind'] ?? ChatRoomService::ROOM_GROUP), 'qr_join', (int) $room['id'], [
            'signed_qr' => (bool) ($decoded['signed'] ?? false),
        ]);
        self::roomNotice($room, $user, '通过' . $entity . '二维码加入了' . $entity);
        return Response::success([
            'joined' => true,
            'pending' => false,
            'room' => ChatRoomService::detail($room, (int) $user['id']),
        ], '已加入' . $entity);
    }
    public static function join(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        AuthService::ensureNotBanned($user, ['all', 'message', 'chat']);
        $room = ChatRoomService::userRoom($user, (int) $params['room_id']);
        $roomKind = ChatRoomService::roomKind($room['room_kind'] ?? ChatRoomService::ROOM_GROUP);
        $entity = self::roomEntity($room);
        if (ChatRoomService::member((int) $room['id'], (int) $user['id']) !== null) {
            return Response::success(['joined' => true, 'room' => ChatRoomService::detail($room, (int) $user['id'])], '你已在' . $entity . '中');
        }
        $policy = ChatRoomService::policy($room);
        if ($policy['join_mode'] === ChatRoomService::JOIN_OPEN) {
            ChatRoomService::addMember($room, (int) $user['id']);
            LogService::userOperation($request, $user, $roomKind, 'join', (int) $room['id']);
            return Response::success(['joined' => true, 'room' => ChatRoomService::detail($room, (int) $user['id'])], '已加入' . $entity);
        }
        if ($policy['join_mode'] === ChatRoomService::JOIN_INVITE) {
            $invitation = Database::one(
                "SELECT * FROM chat_room_invitations WHERE room_id = ? AND invitee_user_id = ? AND status = 'pending'
                 AND (expired_at IS NULL OR expired_at > NOW())",
                [(int) $room['id'], (int) $user['id']]
            );
            if ($invitation === null) throw new HttpException('该' . $entity . '只能通过邀请加入', 403, 403);
            ChatRoomService::addMember($room, (int) $user['id']);
            return Response::success(['joined' => true, 'room' => ChatRoomService::detail($room, (int) $user['id'])], '已接受邀请并加入' . $entity);
        }
        $message = mb_substr((string) $request->input('message', ''), 0, 500);
        $validDays = (int) AppService::relationshipRequestPolicy((int) $user['app_id'])['effective_days'];
        $expiredAt = date('Y-m-d H:i:s', time() + ($validDays * 86400));
        Database::execute(
            'INSERT INTO chat_room_join_requests
             (admin_id, app_id, room_id, user_id, message, status, decision_reason, ignore_reason,
              ignored_at, expired_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, \'\', \'\', NULL, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE message = VALUES(message), status = ?, decision_reason = \'\',
               ignore_reason = \'\', ignored_at = NULL, expired_at = VALUES(expired_at), handled_by_user_id = NULL,
               handled_by_admin_id = NULL, handled_at = NULL, updated_at = NOW()',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $room['id'], (int) $user['id'],
                $message, 'pending', $expiredAt, 'pending',
            ]
        );
        LogService::userOperation($request, $user, $roomKind, 'join_request', (int) $room['id']);
        return Response::success([
            'joined' => false, 'pending' => true, 'expired_at' => $expiredAt, 'valid_days' => $validDays,
        ], '加入' . $entity . '的申请已提交', 202);
    }

    public static function leave(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        $roomKind = ChatRoomService::roomKind($room['room_kind'] ?? ChatRoomService::ROOM_GROUP);
        $entity = self::roomEntity($room);
        $member = ChatRoomService::requireMember($user, $room);
        if ((string) $member['role'] === 'owner') {
            throw new HttpException('创建者必须先转让或解散' . $entity, 0, 409);
        }
        ChatRoomService::removeMember($room, (int) $user['id']);
        LogService::userOperation($request, $user, $roomKind, 'leave', (int) $room['id']);
        return Response::success(['room_id' => (int) $room['id']], '已退出' . $entity);
    }

    public static function members(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        $member = ChatRoomService::requireMember($user, $room);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one('SELECT COUNT(*) AS total FROM chat_room_members WHERE room_id = ?', [(int) $room['id']])['total'] ?? 0);
        $items = Database::all(
            "SELECT m.user_id, u.uid, m.role, m.mute_until, m.joined_at, m.history_visible_from,
                    u.account, p.nickname, p.avatar, p.signature
             FROM chat_room_members m INNER JOIN users u ON u.id = m.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE m.room_id = ? ORDER BY FIELD(m.role, 'owner', 'admin', 'member'), m.id
             LIMIT {$limit} OFFSET {$offset}",
            [(int) $room['id']]
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function updateMember(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        ChatRoomService::requireOwner($user, $room);
        $targetId = (int) $params['user_id'];
        $target = ChatRoomService::member((int) $room['id'], $targetId);
        if ($target === null) throw new HttpException('群成员不存在', 404, 404);
        if ((string) $target['role'] === 'owner') throw new HttpException('请使用转让群主接口', 0, 409);
        $role = trim((string) $request->input('role', 'member'));
        if (!in_array($role, ['admin', 'member'], true)) throw new HttpException('role 仅支持 admin 或 member', 0, 422);
        Database::execute('UPDATE chat_room_members SET role = ? WHERE room_id = ? AND user_id = ?', [$role, (int) $room['id'], $targetId]);
        LogService::userOperation($request, $user, 'group_member', 'role_update', $targetId, ['role' => $role]);
        return Response::success(['user_id' => $targetId, 'role' => $role], '成员角色已更新');
    }

    public static function removeMember(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        $actor = ChatRoomService::requireManager($user, $room);
        $targetId = (int) $params['user_id'];
        $target = ChatRoomService::member((int) $room['id'], $targetId);
        if ($target === null) throw new HttpException('群成员不存在', 404, 404);
        if ((string) $target['role'] === 'owner' || ((string) $actor['role'] !== 'owner' && (string) $target['role'] === 'admin')) {
            throw new HttpException('你无权移除该成员', 403, 403);
        }
        ChatRoomService::removeMember($room, $targetId);
        LogService::userOperation($request, $user, 'group_member', 'remove', $targetId);
        return Response::success(['user_id' => $targetId], '成员已移出群聊');
    }

    public static function muteMember(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        $actor = ChatRoomService::requireManager($user, $room);
        $targetId = (int) $params['user_id'];
        $target = ChatRoomService::member((int) $room['id'], $targetId);
        if ($target === null) throw new HttpException('群成员不存在', 404, 404);
        if ((string) $target['role'] === 'owner' || ((string) $actor['role'] !== 'owner' && (string) $target['role'] === 'admin')) {
            throw new HttpException('你无权禁言该成员', 403, 403);
        }
        $muteUntil = Validator::nullableDateTime($request->input('mute_until'), 'mute_until');
        Database::execute('UPDATE chat_room_members SET mute_until = ? WHERE room_id = ? AND user_id = ?', [$muteUntil, (int) $room['id'], $targetId]);
        LogService::userOperation($request, $user, 'group_member', 'mute', $targetId, ['mute_until' => $muteUntil]);
        return Response::success(['user_id' => $targetId, 'mute_until' => $muteUntil], '禁言状态已更新');
    }

    public static function transfer(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        ChatRoomService::requireOwner($user, $room);
        $targetId = Validator::integer($request->input('new_owner_user_id'), 'new_owner_user_id', 1, PHP_INT_MAX);
        $target = ChatRoomService::member((int) $room['id'], $targetId);
        if ($target === null) throw new HttpException('新群主必须已经是群成员', 0, 422);
        if ($targetId === (int) $user['id']) throw new HttpException('你已经是群主', 0, 409);
        Database::transaction(static function () use ($room, $user, $targetId): void {
            Database::execute('UPDATE chat_room_members SET role = ? WHERE room_id = ? AND user_id = ?', ['member', (int) $room['id'], (int) $user['id']]);
            Database::execute('UPDATE chat_room_members SET role = ? WHERE room_id = ? AND user_id = ?', ['owner', (int) $room['id'], $targetId]);
            ChatRoomService::savePolicy($room, ['owner_user_id' => $targetId]);
        });
        LogService::userOperation($request, $user, 'group', 'transfer', (int) $room['id'], ['new_owner_user_id' => $targetId]);
        return Response::success(['room_id' => (int) $room['id'], 'owner_user_id' => $targetId], '群主已转让');
    }

    public static function invite(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        $entity = self::roomEntity($room);
        if (!ChatRoomService::mayInvite($user, $room)) throw new HttpException('当前' . $entity . '不允许普通成员邀请', 403, 403);
        $targetId = IdentityService::resolveUserReference(
            (int) $user['app_id'],
            $request->input('user_uid', $request->input('user_id'))
        );
        if ($targetId === (int) $user['id']) throw new HttpException('不能邀请自己', 0, 422);
        ChatRoomService::tenantUser($room, $targetId);
        if (ChatRoomService::member((int) $room['id'], $targetId) !== null) throw new HttpException('该用户已经是群成员', 0, 409);
        $validDays = (int) AppService::relationshipRequestPolicy((int) $user['app_id'])['effective_days'];
        $expiredAt = date('Y-m-d H:i:s', time() + ($validDays * 86400));
        $shareHistory = self::boolValue($request->input('share_history', true));
        Database::execute(
            'INSERT INTO chat_room_invitations
             (admin_id, app_id, room_id, inviter_user_id, invitee_user_id, message, share_history, status, expired_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE inviter_user_id = VALUES(inviter_user_id), message = VALUES(message),
               share_history = VALUES(share_history), status = ?, decision_reason = \'\', ignore_reason = \'\', ignored_at = NULL,
               expired_at = VALUES(expired_at), responded_at = NULL, updated_at = NOW()',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $room['id'], (int) $user['id'], $targetId,
                mb_substr((string) $request->input('message', ''), 0, 500), $shareHistory ? 1 : 0,
                'pending', $expiredAt, 'pending',
            ]
        );
        $invitation = Database::one('SELECT * FROM chat_room_invitations WHERE room_id = ? AND invitee_user_id = ?', [(int) $room['id'], $targetId]);
        LogService::userOperation($request, $user, 'group_invitation', 'create', (int) ($invitation['id'] ?? 0), ['room_id' => (int) $room['id'], 'invitee_user_id' => $targetId]);
        $invitee = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], $targetId);
        if ($invitee !== null) NotificationService::send(
            $invitee, 'group_invitation', '收到' . $entity . '邀请', '你被邀请加入' . $entity . '“' . (string) $room['name'] . '”',
            ['invitation_id' => (int) ($invitation['id'] ?? 0), 'room_id' => (int) $room['id'], 'inviter_user_id' => (int) $user['id']]
        );
        return Response::success([
            'invitation' => $invitation, 'expired_at' => $expiredAt, 'valid_days' => $validDays,
        ], $entity . '邀请已发送', 201);
    }

    public static function invitations(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $items = Database::all(
            'SELECT i.*, r.name AS room_name, r.icon AS room_icon, u.account AS inviter_account, p.nickname AS inviter_nickname
             FROM chat_room_invitations i INNER JOIN chat_rooms r ON r.id = i.room_id
             INNER JOIN users u ON u.id = i.inviter_user_id LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE i.admin_id = ? AND i.app_id = ? AND i.invitee_user_id = ?
             ORDER BY (i.status = ?) DESC, i.id DESC',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], 'pending']
        );
        foreach ($items as &$item) $item = self::requestState($item);
        unset($item);
        return Response::success(['items' => $items]);
    }

    public static function acceptInvitation(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::respondInvitation($request, (int) $params['invitation_id'], 'accepted');
    }

    public static function rejectInvitation(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::respondInvitation($request, (int) $params['invitation_id'], 'rejected');
    }

    public static function ignoreInvitation(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::respondInvitation($request, (int) $params['invitation_id'], 'ignored');
    }

    public static function joinRequests(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        ChatRoomService::requireManager($user, $room);
        $items = Database::all(
            'SELECT jr.*, u.account, p.nickname, p.avatar FROM chat_room_join_requests jr
             INNER JOIN users u ON u.id = jr.user_id LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE jr.room_id = ? ORDER BY (jr.status = ?) DESC, jr.id DESC',
            [(int) $room['id'], 'pending']
        );
        foreach ($items as &$item) $item = self::requestState($item);
        unset($item);
        return Response::success(['items' => $items]);
    }

    public static function approveJoinRequest(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::respondJoinRequest($request, $params, 'approved');
    }

    public static function rejectJoinRequest(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::respondJoinRequest($request, $params, 'rejected');
    }

    public static function ignoreJoinRequest(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::respondJoinRequest($request, $params, 'ignored');
    }

    public static function messages(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        $member = ChatRoomService::requireMember($user, $room);
        $canModerate = in_array((string) $member['role'], ['owner', 'admin'], true);
        $items = self::messageItems(
            $request,
            (int) $room['id'],
            (int) $user['id'],
            (int) $user['app_id'],
            $member['history_visible_from'] ?? null
        );
        $recallPolicy = AppService::messageRecallPolicy((int) $user['app_id']);
        $recallSeconds = (int) $recallPolicy['effective_seconds'];
        foreach ($items as &$item) {
            $item['recalled_message_id'] = (string) ($item['content_type'] ?? '') === 'recall'
                ? (int) ($item['reply_to_message_id'] ?? 0)
                : null;
            $item['can_recall'] = self::canRecallGroupMessage($item, $user, $recallSeconds, $canModerate);
        }
        unset($item);
        if ($items !== []) {
            $lastItem = end($items);
            $last = is_array($lastItem) ? (int) ($lastItem['id'] ?? 0) : 0;
            if ($last > 0) self::saveRead($room, $user, $last);
        }
        return Response::success([
            'room' => ChatRoomService::detail($room, (int) $user['id']),
            'items' => $items,
            'message_recall_policy' => $recallPolicy,
        ]);
    }

    public static function sendMessage(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        AuthService::ensureNotBanned($user, ['all', 'message', 'chat']);
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        $member = ChatRoomService::requireMember($user, $room);
        $policy = ChatRoomService::policy($room);
        $manager = in_array((string) $member['role'], ['owner', 'admin'], true);
        if ((bool) $policy['mute_all'] && !$manager) throw new HttpException('当前群聊已开启全员禁言', 403, 403);
        if ($member['mute_until'] !== null && strtotime((string) $member['mute_until']) > time()) {
            throw new HttpException('你已被禁言', 403, 403, ['mute_until' => $member['mute_until']]);
        }
        $payload = MessageMediaService::userPayload($user, $request->all());
        MessageMediaService::assertChatFeatures((int) $user['app_id'], $payload);
        $tagsJson = ContentTagService::encode($request->input('tags', []));
        $reply = self::replyId($room, (int) $request->input('reply_to_message_id', 0));
        $id = Database::transaction(static function () use ($user, $room, $payload, $tagsJson, $reply): int {
            $id = Database::insert(
                'INSERT INTO chat_room_messages
                 (admin_id, app_id, room_id, user_id, sender_type, sender_admin_id, content_type, content, tags_json, reply_to_message_id, status, created_at)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, 1, NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $room['id'], (int) $user['id'],
                    'user', (string) $payload['content_type'], (string) $payload['content'], $tagsJson, $reply,
                ]
            );
            MessageMediaService::save('group_message', $id, $payload);
            return $id;
        });
        self::saveRead($room, $user, $id);
        $mentions = $request->input('mentions', []);
        if (!is_array($mentions)) $mentions = [];
        $mentionIds = array_values(array_unique(array_filter(array_map('intval', $mentions),
            static fn (int $userId): bool => $userId > 0 && $userId !== (int) $user['id'])));
        if ($mentionIds !== []) {
            $placeholders = implode(',', array_fill(0, count($mentionIds), '?'));
            $members = Database::all(
                "SELECT user_id FROM chat_room_members WHERE room_id = ? AND user_id IN ({$placeholders})",
                array_merge([(int) $room['id']], $mentionIds)
            );
            $senderName = trim((string) ($user['nickname'] ?? $user['account'] ?? '群成员'));
            foreach ($members as $mentionedMember) {
                $mentioned = NotificationService::user(
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $mentionedMember['user_id']
                );
                if ($mentioned === null) continue;
                NotificationService::send(
                    $mentioned,
                    'chat_mention',
                    '群聊中有人提到你',
                    ($senderName === '' ? '群成员' : $senderName) . ' 在“' . (string) $room['name'] . '”中提到了你',
                    [
                        'room_id' => (int) $room['id'],
                        'room_name' => (string) $room['name'],
                        'message_id' => $id,
                        'sender_user_id' => (int) $user['id'],
                        'sender_name' => $senderName === '' ? '群成员' : $senderName,
                    ]
                );
            }
        }
        LogService::userOperation($request, $user, 'group_message', 'send', $id, [
            'room_id' => (int) $room['id'], 'content_type' => (string) $payload['content_type'],
            'attachment_count' => count($payload['attachments']),
        ]);
        return Response::success([
            'message_id' => $id,
            'message' => self::sentGroupMessage($user, $member, $id, $payload, $tagsJson, $reply),
        ], '消息发送成功', 201);
    }

    private static function sentGroupMessage(
        array $user,
        array $member,
        int $messageId,
        array $payload,
        string $tagsJson,
        ?int $replyToMessageId
    ): array {
        $stored = Database::one('SELECT created_at FROM chat_room_messages WHERE id = ? LIMIT 1', [$messageId]);
        $nickname = trim((string) ($user['nickname'] ?? $user['account'] ?? '群成员'));
        if ($nickname === '') $nickname = '群成员';
        $items = [[
            'id' => $messageId,
            'user_id' => (int) $user['id'],
            'sender_type' => 'user',
            'sender_admin_id' => null,
            'content_type' => (string) $payload['content_type'],
            'content' => (string) $payload['content'],
            'tags_json' => $tagsJson,
            'reply_to_message_id' => $replyToMessageId,
            'created_at' => (string) ($stored['created_at'] ?? date('Y-m-d H:i:s')),
            'account' => (string) ($user['account'] ?? ''),
            'nickname' => $nickname,
            'avatar' => (string) ($user['avatar'] ?? ''),
            'role' => (string) ($member['role'] ?? 'member'),
            'is_favorite' => 0,
            'can_recall' => true,
        ]];
        $items = ContentTagService::hydrate($items);
        $items = MessageMediaService::hydrate($items, 'group_message', (int) $user['app_id']);
        $items = MessageForwardService::hydrate($items, 'group_message', (int) $user['app_id']);
        $items = MessagePresentationService::hydrate($items, 'group', (int) $user['id']);
        return $items[0] ?? [];
    }

    public static function editMessage(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        AuthService::ensureNotBanned($user, ['all', 'message', 'chat']);
        $roomId = Validator::integer($params['room_id'] ?? 0, 'room_id', 1, PHP_INT_MAX);
        ChatRoomService::userRoom($user, $roomId, true);
        $data = MessageEditService::editGroup(
            $user,
            $roomId,
            Validator::integer($params['message_id'] ?? 0, 'message_id', 1, PHP_INT_MAX),
            (string) $request->input('content', '')
        );
        LogService::userOperation($request, $user, 'group_message', 'edit', (int) $data['message_id'], [
            'room_id' => $roomId,
            'edit_count' => (int) $data['edit_count'],
        ]);
        return Response::success($data, '群消息已重新编辑');
    }

    public static function messageEditHistory(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        return Response::success(MessageEditService::groupHistory(
            $user,
            Validator::integer($params['room_id'] ?? 0, 'room_id', 1, PHP_INT_MAX),
            Validator::integer($params['message_id'] ?? 0, 'message_id', 1, PHP_INT_MAX)
        ));
    }

    public static function deleteMessage(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        $member = ChatRoomService::requireMember($user, $room);
        $message = Database::one(
            'SELECT * FROM chat_room_messages WHERE id = ? AND room_id = ? AND status = 1',
            [(int) $params['message_id'], (int) $room['id']]
        );
        if ($message === null) throw new HttpException('群消息不存在', 404, 404);
        $manager = in_array((string) $member['role'], ['owner', 'admin'], true);
        $ownMessage = (string) $message['sender_type'] === 'user'
            && (int) ($message['user_id'] ?? 0) === (int) $user['id'];
        if (!$ownMessage && !$manager) throw new HttpException('只能撤回自己发送的消息', 403, 403);
        $policy = AppService::messageRecallPolicy((int) $user['app_id']);
        if ($ownMessage) {
            $seconds = (int) $policy['effective_seconds'];
            if ($seconds <= 0) throw new HttpException('当前应用已关闭消息撤回', 403, 403, ['message_recall_policy' => $policy]);
            if (time() - strtotime((string) $message['created_at']) > $seconds) {
                throw new HttpException('群消息已超过可撤回时间', 0, 410, ['message_recall_policy' => $policy]);
            }
        }
        $noticeText = mb_substr(trim((string) $request->input('notice_text', '')), 0, 200);
        if ($noticeText === '') $noticeText = $ownMessage ? '你撤回了一条群消息' : '群管理员撤回了一条消息';
        $editableContent = (string) ($message['content'] ?? '');
        $editableAttachments = MessageMediaService::attachments('group_message', (int) $message['id'], (int) $user['app_id']);
        $eventId = Database::transaction(static function () use ($message, $room, $user, $ownMessage, $noticeText): int {
            MessageMediaService::recordRecall(
                $message, 'group', (int) $room['id'], 'group_message', 'user', (int) $user['id'],
                $ownMessage ? '' : '群管理员撤回'
            );
            $affected = Database::execute(
                'UPDATE chat_room_messages SET status = 0 WHERE id = ? AND room_id = ? AND status = 1',
                [(int) $message['id'], (int) $room['id']]
            );
            if ($affected === 0) throw new HttpException('群消息已经撤回', 0, 409);
            return Database::insert(
                'INSERT INTO chat_room_messages
                 (admin_id, app_id, room_id, user_id, sender_type, sender_admin_id,
                  content_type, content, reply_to_message_id, status, created_at)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, 1, NOW())',
                [
                    (int) $room['admin_id'], (int) $room['app_id'], (int) $room['id'], (int) $user['id'],
                    'system', 'recall', $noticeText,
                    (int) $message['id'],
                ]
            );
        });
        self::saveRead($room, $user, $eventId);
        LogService::userOperation($request, $user, 'group_message', $ownMessage ? 'recall' : 'moderate_recall', (int) $message['id'], [
            'room_id' => (int) $room['id'], 'recall_event_id' => $eventId,
        ]);
        return Response::success([
            'message_id' => (int) $message['id'], 'recall_event_id' => $eventId,
            'recalled' => true, 'message_recall_policy' => $policy,
            'notice_text' => $noticeText, 'editable_content' => $editableContent,
            'editable_attachments' => $editableAttachments,
        ], $ownMessage ? '群消息已撤回' : '群消息已由管理员撤回');
    }

    public static function markRead(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        ChatRoomService::requireMember($user, $room);
        $messageId = Validator::integer($request->input('message_id'), 'message_id', 1, PHP_INT_MAX);
        if (Database::one('SELECT id FROM chat_room_messages WHERE id = ? AND room_id = ?', [$messageId, (int) $room['id']]) === null) {
            throw new HttpException('群消息不存在', 404, 404);
        }
        self::saveRead($room, $user, $messageId);
        return Response::success(['room_id' => (int) $room['id'], 'last_read_message_id' => $messageId], '已更新群消息已读位置');
    }

    public static function files(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room, $member] = self::extensionRoom($request, (int) $params['room_id']);
        $parentId = max(0, (int) $request->input('parent_id', 0));
        $parent = null;
        if ($parentId > 0) {
            $parent = Database::one(
                'SELECT * FROM chat_room_files WHERE id = ? AND room_id = ? AND is_folder = 1 AND status = 1',
                [$parentId, (int) $room['id']]
            );
            if ($parent === null) throw new HttpException('群文件夹不存在', 404, 404);
        }
        $items = Database::all(
            'SELECT f.*, u.account, p.nickname FROM chat_room_files f LEFT JOIN users u ON u.id = f.uploader_user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE f.room_id = ? AND f.status = 1 AND ' . ($parentId > 0 ? 'f.parent_id = ?' : 'f.parent_id IS NULL') . '
             ORDER BY f.is_folder DESC, f.id DESC',
            $parentId > 0 ? [(int) $room['id'], $parentId] : [(int) $room['id']]
        );
        foreach ($items as &$item) {
            $item['is_folder'] = (bool) ((int) ($item['is_folder'] ?? 0));
            $item['can_delete'] = (int) $item['uploader_user_id'] === (int) $user['id'] || in_array((string) $member['role'], ['owner', 'admin'], true);
            if ($item['is_folder']) {
                $item['child_count'] = (int) (Database::one(
                    'SELECT COUNT(*) AS total FROM chat_room_files WHERE room_id = ? AND parent_id = ? AND status = 1',
                    [(int) $room['id'], (int) $item['id']]
                )['total'] ?? 0);
            }
        }
        unset($item);
        return Response::success([
            'items' => $items,
            'parent' => $parent,
            'parent_id' => $parentId,
        ]);
    }

    public static function addFile(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room] = self::extensionRoom($request, (int) $params['room_id']);
        $isFolder = self::boolValue($request->input('is_folder', false));
        $parentId = max(0, (int) $request->input('parent_id', 0));
        if ($parentId > 0 && Database::one(
            'SELECT id FROM chat_room_files WHERE id = ? AND room_id = ? AND is_folder = 1 AND status = 1',
            [$parentId, (int) $room['id']]
        ) === null) throw new HttpException('上级群文件夹不存在', 404, 404);
        $name = Validator::string($request->input('name', ''), 'name', 1, 255);
        $fileUrl = $isFolder ? '' : Validator::string($request->input('file_url', ''), 'file_url', 1, 1000);
        $id = Database::insert(
            'INSERT INTO chat_room_files (room_id, uploader_user_id, parent_id, is_folder, name, file_url, mime_type, size_bytes, download_count, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1, NOW())',
            [
                (int) $room['id'], (int) $user['id'], $parentId > 0 ? $parentId : null, $isFolder ? 1 : 0, $name, $fileUrl,
                $isFolder ? 'inode/directory' : mb_substr((string) $request->input('mime_type', 'application/octet-stream'), 0, 100),
                $isFolder ? 0 : max(0, (int) $request->input('size_bytes', 0)),
            ]
        );
        self::roomNotice($room, $user, ($isFolder ? '新建了群文件夹：' : '上传了群文件：') . $name);
        return Response::success(['file_id' => $id, 'is_folder' => $isFolder], $isFolder ? '群文件夹已创建' : '群文件已添加', 201);
    }

    public static function recordFileDownload(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [, $room] = self::extensionRoom($request, (int) $params['room_id']);
        $file = Database::one(
            'SELECT * FROM chat_room_files WHERE id = ? AND room_id = ? AND is_folder = 0 AND status = 1',
            [(int) $params['file_id'], (int) $room['id']]
        );
        if ($file === null) throw new HttpException('群文件不存在', 404, 404);
        Database::execute('UPDATE chat_room_files SET download_count = download_count + 1 WHERE id = ?', [(int) $file['id']]);
        return Response::success([
            'file_id' => (int) $file['id'],
            'file_url' => (string) $file['file_url'],
            'download_count' => (int) ($file['download_count'] ?? 0) + 1,
        ], '已记录群文件下载');
    }

    public static function deleteFile(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room, $member] = self::extensionRoom($request, (int) $params['room_id']);
        $file = Database::one('SELECT * FROM chat_room_files WHERE id = ? AND room_id = ? AND status = 1', [(int) $params['file_id'], (int) $room['id']]);
        if ($file === null) throw new HttpException('群文件不存在', 404, 404);
        if ((int) ($file['uploader_user_id'] ?? 0) !== (int) $user['id'] && !in_array((string) $member['role'], ['owner', 'admin'], true)) throw new HttpException('无权删除该群文件', 403, 403);
        $ids = [(int) $file['id']];
        if ((int) ($file['is_folder'] ?? 0) === 1) {
            $all = Database::all('SELECT id, parent_id FROM chat_room_files WHERE room_id = ? AND status = 1', [(int) $room['id']]);
            for ($index = 0; $index < count($ids); $index++) {
                $parentId = $ids[$index];
                foreach ($all as $candidate) {
                    if ((int) ($candidate['parent_id'] ?? 0) === $parentId && !in_array((int) $candidate['id'], $ids, true)) {
                        $ids[] = (int) $candidate['id'];
                    }
                }
            }
        }
        Database::transaction(static function () use ($ids): void {
            foreach ($ids as $id) Database::execute('UPDATE chat_room_files SET status = 0 WHERE id = ?', [$id]);
        });
        self::roomNotice($room, $user, ((int) ($file['is_folder'] ?? 0) === 1 ? '删除了群文件夹：' : '删除了群文件：') . (string) $file['name']);
        return Response::success(['deleted_count' => count($ids)], (int) ($file['is_folder'] ?? 0) === 1 ? '群文件夹及其内容已删除' : '群文件已删除');
    }

    public static function albums(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room, $member] = self::extensionRoom($request, (int) $params['room_id']);
        $items = Database::all(
            'SELECT a.*, cu.account AS creator_account, cp.nickname AS creator_nickname,
                    (SELECT COUNT(*) FROM chat_room_album_photos p WHERE p.album_id = a.id AND p.status = 1) AS photo_count
             FROM chat_room_albums a
             LEFT JOIN users cu ON cu.id = a.creator_user_id
             LEFT JOIN user_profiles cp ON cp.user_id = cu.id
             WHERE a.room_id = ? AND a.status = 1 ORDER BY a.id DESC', [(int) $room['id']]
        );
        foreach ($items as &$item) {
            $item['can_delete'] = (int) $item['creator_user_id'] === (int) $user['id'] || in_array((string) $member['role'], ['owner', 'admin'], true);
            $item['photos'] = Database::all(
                'SELECT ap.*, u.account AS uploader_account, p.nickname AS uploader_nickname, p.avatar AS uploader_avatar
                 FROM chat_room_album_photos ap
                 LEFT JOIN users u ON u.id = ap.uploader_user_id
                 LEFT JOIN user_profiles p ON p.user_id = u.id
                 WHERE ap.album_id = ? AND ap.status = 1 ORDER BY ap.id DESC LIMIT 500',
                [(int) $item['id']]
            );
            foreach ($item['photos'] as &$photo) $photo['can_delete'] = (int) $photo['uploader_user_id'] === (int) $user['id'] || in_array((string) $member['role'], ['owner', 'admin'], true);
            unset($photo);
        }
        unset($item); return Response::success(['items' => $items]);
    }

    public static function createAlbum(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room] = self::extensionRoom($request, (int) $params['room_id']);
        $id = Database::insert('INSERT INTO chat_room_albums (room_id, creator_user_id, name, description, status, created_at) VALUES (?, ?, ?, ?, 1, NOW())', [(int) $room['id'], (int) $user['id'], Validator::string($request->input('name', ''), 'name', 1, 120), mb_substr((string) $request->input('description', ''), 0, 500)]);
        self::roomNotice($room, $user, '创建了群相册：' . Validator::string($request->input('name', ''), 'name', 1, 120));
        return Response::success(['album_id' => $id], '群相册已创建', 201);
    }

    public static function deleteAlbum(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room, $member] = self::extensionRoom($request, (int) $params['room_id']);
        $album = Database::one('SELECT * FROM chat_room_albums WHERE id = ? AND room_id = ? AND status = 1', [(int) $params['album_id'], (int) $room['id']]);
        if ($album === null) throw new HttpException('群相册不存在', 404, 404);
        if ((int) $album['creator_user_id'] !== (int) $user['id'] && !in_array((string) $member['role'], ['owner', 'admin'], true)) throw new HttpException('无权删除该群相册', 403, 403);
        Database::execute('UPDATE chat_room_albums SET status = 0 WHERE id = ?', [(int) $album['id']]);
        self::roomNotice($room, $user, '删除了群相册：' . (string) $album['name']);
        return Response::success([], '群相册已删除');
    }

    public static function addPhoto(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room] = self::extensionRoom($request, (int) $params['room_id']);
        $album = Database::one('SELECT id FROM chat_room_albums WHERE id = ? AND room_id = ? AND status = 1', [(int) $params['album_id'], (int) $room['id']]); if ($album === null) throw new HttpException('群相册不存在', 404, 404);
        $mediaType = strtolower(trim((string) $request->input('media_type', 'image')));
        if (!in_array($mediaType, ['image', 'video'], true)) {
            throw new HttpException('群相册仅支持图片或视频', 422, 422);
        }
        $mediaUrl = (string) $request->input('image_url', $request->input('media_url', ''));
        $id = Database::insert(
            'INSERT INTO chat_room_album_photos
             (album_id, uploader_user_id, image_url, media_type, mime_type, size_bytes, thumbnail_url, caption, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())',
            [
                (int) $album['id'],
                (int) $user['id'],
                Validator::string($mediaUrl, 'media_url', 1, 1000),
                $mediaType,
                mb_substr((string) $request->input('mime_type', $mediaType === 'video' ? 'video/mp4' : 'image/jpeg'), 0, 120),
                max(0, (int) $request->input('size_bytes', 0)),
                mb_substr((string) $request->input('thumbnail_url', ''), 0, 1000),
                mb_substr((string) $request->input('caption', ''), 0, 500),
            ]
        );
        $label = $mediaType === 'video' ? '视频' : '图片';
        self::roomNotice($room, $user, '向群相册上传了' . $label);
        return Response::success(['photo_id' => $id, 'media_type' => $mediaType], $label . '已加入群相册', 201);
    }

    public static function deletePhoto(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room, $member] = self::extensionRoom($request, (int) $params['room_id']);
        $photo = Database::one('SELECT p.* FROM chat_room_album_photos p INNER JOIN chat_room_albums a ON a.id = p.album_id WHERE p.id = ? AND a.id = ? AND a.room_id = ? AND p.status = 1', [(int) $params['photo_id'], (int) $params['album_id'], (int) $room['id']]); if ($photo === null) throw new HttpException('群相册照片不存在', 404, 404);
        if ((int) ($photo['uploader_user_id'] ?? 0) !== (int) $user['id'] && !in_array((string) $member['role'], ['owner', 'admin'], true)) throw new HttpException('无权删除该照片', 403, 403);
        Database::execute('UPDATE chat_room_album_photos SET status = 0 WHERE id = ?', [(int) $photo['id']]);
        self::roomNotice($room, $user, '删除了一张群相册照片');
        return Response::success([], '照片已删除');
    }

    public static function groupVotes(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room, $member] = self::extensionRoom($request, (int) $params['room_id']);
        $items = Database::all(
            'SELECT v.*, EXISTS(SELECT 1 FROM chat_room_vote_records r WHERE r.vote_id = v.id AND r.user_id = ?) AS voted
             FROM chat_room_votes v WHERE v.room_id = ? ORDER BY v.id DESC', [(int) $user['id'], (int) $room['id']]
        );
        foreach ($items as &$item) {
            $item = self::decorateVoteMultipleChoice($item);
            $item['can_delete'] = (int) $item['creator_user_id'] === (int) $user['id'] || in_array((string) $member['role'], ['owner', 'admin'], true);
        }
        unset($item);
        return Response::success(['items' => $items]);
    }

    public static function createGroupVote(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room] = self::extensionRoom($request, (int) $params['room_id']); $options = (array) $request->input('options', []);
        if (count($options) < 2 || count($options) > 100) throw new HttpException('群投票选项数量必须为 2-100', 0, 422);
        $multiple = self::boolValue($request->input(
            'multiple_choice',
            $request->input('multi_select', $request->input('allow_multiple', false))
        ));
        $min = max(1, (int) $request->input('min_select', 1));
        $max = $multiple ? max($min, (int) $request->input('max_select', count($options))) : 1;
        if ($max > count($options)) throw new HttpException('max_select 不能超过选项数量', 0, 422);
        $id = Database::transaction(static function () use ($request, $user, $room, $options, $multiple, $min, $max): int {
            $id = Database::insert('INSERT INTO chat_room_votes (room_id, creator_user_id, title, description, multiple_choice, min_select, max_select, allow_change, anonymous, status, ends_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())', [(int) $room['id'], (int) $user['id'], Validator::string($request->input('title', ''), 'title', 1, 200), (string) $request->input('description', ''), $multiple ? 1 : 0, $min, $max, self::boolValue($request->input('allow_change', false)) ? 1 : 0, self::boolValue($request->input('anonymous', false)) ? 1 : 0, 'active', Validator::nullableDateTime($request->input('ends_at'), 'ends_at')]);
            foreach (array_values($options) as $index => $option) {
                $optionText = Validator::string(is_array($option) ? ($option['option_text'] ?? '') : $option, 'option_text', 1, 300);
                $imageUrl = is_array($option) ? mb_substr(trim((string) ($option['image_url'] ?? '')), 0, 1000) : '';
                Database::execute(
                    'INSERT INTO chat_room_vote_options (vote_id, option_text, image_url, sort_order) VALUES (?, ?, ?, ?)',
                    [$id, $optionText, $imageUrl, $index]
                );
            }
            return $id;
        });
        self::roomNotice($room, $user, '发起了群投票：' . Validator::string($request->input('title', ''), 'title', 1, 200));
        return Response::success(['vote_id' => $id], '群投票已创建', 201);
    }

    public static function showGroupVote(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room] = self::extensionRoom($request, (int) $params['room_id']);
        $vote = self::decorateVoteMultipleChoice(self::roomVote($room, (int) $params['vote_id']));
        $vote['options'] = Database::all('SELECT * FROM chat_room_vote_options WHERE vote_id = ? ORDER BY sort_order, id', [(int) $vote['id']]);
        $vote['selected_option_ids'] = array_map('intval', array_column(Database::all('SELECT option_id FROM chat_room_vote_records WHERE vote_id = ? AND user_id = ? ORDER BY id', [(int) $vote['id'], (int) $user['id']]), 'option_id'));
        return Response::success(['vote' => $vote]);
    }

    public static function submitGroupVote(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room] = self::extensionRoom($request, (int) $params['room_id']); $voteId = (int) $params['vote_id']; $ids = array_values(array_unique(array_map('intval', (array) $request->input('option_ids', []))));
        $result = Database::transaction(static function () use ($user, $room, $voteId, $ids): array {
            $vote = Database::one("SELECT * FROM chat_room_votes WHERE id = ? AND room_id = ? AND status = 'active' FOR UPDATE", [$voteId, (int) $room['id']]); if ($vote === null) throw new HttpException('群投票不存在或已关闭', 404, 404);
            if ($vote['ends_at'] !== null && strtotime((string) $vote['ends_at']) <= time()) throw new HttpException('群投票已结束', 0, 410);
            if (count($ids) < (int) $vote['min_select'] || count($ids) > (int) $vote['max_select']) throw new HttpException('选择数量不符合规则', 0, 422);
            $valid = $ids === [] ? [] : Database::all('SELECT id FROM chat_room_vote_options WHERE vote_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([$voteId], $ids)); if (count($valid) !== count($ids)) throw new HttpException('包含无效选项', 0, 422);
            $old = Database::all('SELECT id, option_id FROM chat_room_vote_records WHERE vote_id = ? AND user_id = ? FOR UPDATE', [$voteId, (int) $user['id']]); if ($old !== [] && (int) $vote['allow_change'] !== 1) throw new HttpException('该群投票不允许改票', 0, 409);
            foreach ($old as $row) { Database::execute('UPDATE chat_room_vote_options SET vote_count = GREATEST(0, vote_count - 1) WHERE id = ?', [(int) $row['option_id']]); Database::execute('DELETE FROM chat_room_vote_records WHERE id = ?', [(int) $row['id']]); }
            foreach ($ids as $optionId) { Database::execute('INSERT INTO chat_room_vote_records (vote_id, option_id, user_id, created_at) VALUES (?, ?, ?, NOW())', [$voteId, $optionId, (int) $user['id']]); Database::execute('UPDATE chat_room_vote_options SET vote_count = vote_count + 1 WHERE id = ?', [$optionId]); }
            return ['selected_option_ids' => $ids, 'changed' => $old !== []];
        });
        self::roomNotice($room, $user, '参与了群投票');
        return Response::success($result, '群投票提交成功');
    }

    public static function closeGroupVote(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room, $member] = self::extensionRoom($request, (int) $params['room_id']); $vote = self::roomVote($room, (int) $params['vote_id']);
        if ((int) ($vote['creator_user_id'] ?? 0) !== (int) $user['id'] && !in_array((string) $member['role'], ['owner', 'admin'], true)) throw new HttpException('无权关闭群投票', 403, 403);
        Database::execute("UPDATE chat_room_votes SET status = 'closed' WHERE id = ?", [(int) $vote['id']]);
        self::roomNotice($room, $user, '关闭了群投票：' . (string) $vote['title']);
        return Response::success([], '群投票已关闭');
    }

    public static function deleteGroupVote(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room, $member] = self::extensionRoom($request, (int) $params['room_id']);
        $vote = self::roomVote($room, (int) $params['vote_id']);
        if ((int) $vote['creator_user_id'] !== (int) $user['id'] && !in_array((string) $member['role'], ['owner', 'admin'], true)) throw new HttpException('无权删除该群投票', 403, 403);
        Database::execute("UPDATE chat_room_votes SET status = 'deleted' WHERE id = ?", [(int) $vote['id']]);
        self::roomNotice($room, $user, '删除了群投票：' . (string) $vote['title']);
        return Response::success([], '群投票已删除');
    }

    public static function solitaires(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room, $member] = self::extensionRoom($request, (int) $params['room_id']);
        $items = Database::all(
            "SELECT s.*, (SELECT COUNT(*) FROM chat_room_solitaire_entries e WHERE e.solitaire_id = s.id) AS entry_count
             FROM chat_room_solitaire s WHERE s.room_id = ? AND s.status <> 'deleted' ORDER BY s.id DESC",
            [(int) $room['id']]
        );
        foreach ($items as &$item) {
            $item['can_delete'] = (int) $item['creator_user_id'] === (int) $user['id']
                || in_array((string) $member['role'], ['owner', 'admin'], true);
        }
        unset($item);
        return Response::success(['items' => $items]);
    }

    public static function createSolitaire(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room] = self::extensionRoom($request, (int) $params['room_id']);
        $title = Validator::string($request->input('title', ''), 'title', 1, 200);
        $id = Database::insert(
            'INSERT INTO chat_room_solitaire (room_id, creator_user_id, title, description, status, ends_at, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [(int) $room['id'], (int) $user['id'], $title, (string) $request->input('description', ''), 'active', Validator::nullableDateTime($request->input('ends_at'), 'ends_at')]
        );
        self::roomNotice($room, $user, '发起了群接龙：' . $title);
        return Response::success(['solitaire_id' => $id], '群接龙已创建', 201);
    }

    public static function showSolitaire(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room] = self::extensionRoom($request, (int) $params['room_id']); $item = self::roomSolitaire($room, (int) $params['solitaire_id']); $item['entries'] = Database::all('SELECT e.*, u.account, p.nickname, p.avatar FROM chat_room_solitaire_entries e INNER JOIN users u ON u.id = e.user_id LEFT JOIN user_profiles p ON p.user_id = u.id WHERE e.solitaire_id = ? ORDER BY e.id', [(int) $item['id']]); return Response::success(['solitaire' => $item]);
    }

    public static function joinSolitaire(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room] = self::extensionRoom($request, (int) $params['room_id']); $item = self::roomSolitaire($room, (int) $params['solitaire_id']); if ((string) $item['status'] !== 'active' || ($item['ends_at'] !== null && strtotime((string) $item['ends_at']) <= time())) throw new HttpException('群接龙已结束', 0, 410);
        $content = Validator::string($request->input('content', ''), 'content', 1, 1000);
        Database::execute('INSERT INTO chat_room_solitaire_entries (solitaire_id, user_id, content, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE content = VALUES(content), created_at = NOW()', [(int) $item['id'], (int) $user['id'], $content]);
        self::roomNotice($room, $user, '参与了群接龙：' . (string) $item['title']);
        return Response::success(['solitaire_id' => (int) $item['id'], 'content' => $content], '接龙已提交');
    }

    public static function closeSolitaire(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room, $member] = self::extensionRoom($request, (int) $params['room_id']); $item = self::roomSolitaire($room, (int) $params['solitaire_id']); if ((int) ($item['creator_user_id'] ?? 0) !== (int) $user['id'] && !in_array((string) $member['role'], ['owner', 'admin'], true)) throw new HttpException('无权关闭群接龙', 403, 403); Database::execute("UPDATE chat_room_solitaire SET status = 'closed' WHERE id = ?", [(int) $item['id']]); self::roomNotice($room, $user, '关闭了群接龙：' . (string) $item['title']); return Response::success([], '群接龙已关闭');
    }

    public static function deleteSolitaire(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$user, $room, $member] = self::extensionRoom($request, (int) $params['room_id']);
        $item = self::roomSolitaire($room, (int) $params['solitaire_id']);
        if ((int) ($item['creator_user_id'] ?? 0) !== (int) $user['id']
            && !in_array((string) $member['role'], ['owner', 'admin'], true)) {
            throw new HttpException('无权删除该群接龙', 403, 403);
        }
        Database::execute("UPDATE chat_room_solitaire SET status = 'deleted' WHERE id = ?", [(int) $item['id']]);
        self::roomNotice($room, $user, '删除了群接龙：' . (string) $item['title']);
        return Response::success([], '群接龙已删除');
    }

    private static function respondInvitation(Request $request, int $invitationId, string $action): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        if (!in_array($action, ['accepted', 'rejected', 'ignored'], true)) {
            throw new HttpException('群聊邀请处理动作无效', 0, 422);
        }
        $reasonMap = [
            'accepted' => '接收方同意群聊邀请',
            'rejected' => '接收方明确拒绝群聊邀请',
            'ignored' => '接收方选择忽略群聊邀请，未通知邀请人',
        ];
        if ($action === 'ignored') {
            $customReason = mb_substr(trim((string) $request->input('reason', '')), 0, 255);
            if ($customReason !== '') $reasonMap['ignored'] = $customReason;
        }
        $outcome = Database::transaction(static function () use ($user, $invitationId, $action, $reasonMap): array {
            $invitation = Database::one(
                "SELECT * FROM chat_room_invitations WHERE id = ? AND admin_id = ? AND app_id = ?
                 AND invitee_user_id = ? AND status IN ('pending','ignored') FOR UPDATE",
                [$invitationId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
            );
            if ($invitation === null) throw new HttpException('待处理的群邀请不存在', 404, 404);
            if ($invitation['expired_at'] !== null && strtotime((string) $invitation['expired_at']) <= time()) {
                return ['expired' => true, 'invitation' => $invitation, 'room' => null];
            }
            $room = ChatRoomService::adminRoom(
                (int) $user['admin_id'], (int) $user['app_id'], (int) $invitation['room_id'], true
            );
            if ($action === 'accepted') {
                $historyVisibleFrom = (bool) ($invitation['share_history'] ?? true)
                    ? null
                    : date('Y-m-d H:i:s');
                ChatRoomService::addMember($room, (int) $user['id'], 'member', $historyVisibleFrom);
            }
            if ($action === 'ignored') {
                Database::execute(
                    'UPDATE chat_room_invitations SET status = ?, decision_reason = ?, ignore_reason = ?,
                       ignored_at = NOW(), responded_at = NULL, updated_at = NOW() WHERE id = ?',
                    [$action, $reasonMap[$action], $reasonMap[$action], $invitationId]
                );
            } else {
                Database::execute(
                    'UPDATE chat_room_invitations SET status = ?, decision_reason = ?, responded_at = NOW(), updated_at = NOW() WHERE id = ?',
                    [$action, $reasonMap[$action], $invitationId]
                );
            }
            return ['expired' => false, 'invitation' => $invitation, 'room' => $room];
        });
        if ((bool) ($outcome['expired'] ?? false)) throw new HttpException('群邀请已过期', 0, 410);
        LogService::userOperation($request, $user, 'group_invitation', $action, $invitationId, [
            'decision_reason' => $reasonMap[$action],
        ]);
        if ($action !== 'ignored') {
            $inviter = NotificationService::user(
                (int) $user['admin_id'], (int) $user['app_id'], (int) $outcome['invitation']['inviter_user_id']
            );
            if ($inviter !== null) NotificationService::send(
                $inviter, $action === 'accepted' ? 'group_invitation_accepted' : 'group_invitation_rejected',
                $action === 'accepted' ? '群聊邀请已接受' : '群聊邀请被拒绝',
                $action === 'accepted' ? '对方已加入群聊“' . (string) $outcome['room']['name'] . '”' : '对方拒绝了群聊邀请',
                ['invitation_id' => $invitationId, 'room_id' => (int) $outcome['invitation']['room_id'], 'user_id' => (int) $user['id']]
            );
        }
        $messageMap = ['accepted' => '已加入群聊', 'rejected' => '已拒绝群邀请', 'ignored' => '已忽略群邀请'];
        return Response::success([
            'invitation_id' => $invitationId, 'status' => $action,
            'decision_reason' => $reasonMap[$action], 'inviter_notified' => $action !== 'ignored',
        ], $messageMap[$action]);
    }

    private static function respondJoinRequest(Request $request, array $params, string $action): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        if (!in_array($action, ['approved', 'rejected', 'ignored'], true)) {
            throw new HttpException('入群申请处理动作无效', 0, 422);
        }
        $reasonMap = [
            'approved' => '群管理员同意入群申请',
            'rejected' => '群管理员明确拒绝入群申请',
            'ignored' => '群管理员选择忽略入群申请，未通知申请人',
        ];
        if ($action === 'ignored') {
            $customReason = mb_substr(trim((string) $request->input('reason', '')), 0, 255);
            if ($customReason !== '') $reasonMap['ignored'] = $customReason;
        }
        $room = ChatRoomService::userRoom($user, (int) $params['room_id'], true);
        ChatRoomService::requireManager($user, $room);
        $join = Database::transaction(static function () use ($room, $user, $params, $action, $reasonMap): array {
            $join = Database::one(
                "SELECT * FROM chat_room_join_requests WHERE id = ? AND room_id = ?
                 AND status IN ('pending','ignored') FOR UPDATE",
                [(int) $params['request_id'], (int) $room['id']]
            );
            if ($join === null) throw new HttpException('待处理的入群申请不存在', 404, 404);
            if (strtotime((string) $join['expired_at']) <= time()) {
                throw new HttpException('入群申请已过期，只能查看，不能继续处理', 0, 410, [
                    'expired_at' => $join['expired_at'], 'status_text' => '已过期',
                ]);
            }
            if ($action === 'approved') ChatRoomService::addMember($room, (int) $join['user_id']);
            if ($action === 'ignored') {
                Database::execute(
                    'UPDATE chat_room_join_requests SET status = ?, decision_reason = ?, ignore_reason = ?,
                       ignored_at = NOW(), handled_by_user_id = ?, handled_at = NULL, updated_at = NOW() WHERE id = ?',
                    [$action, $reasonMap[$action], $reasonMap[$action], (int) $user['id'], (int) $join['id']]
                );
            } else {
                Database::execute(
                    'UPDATE chat_room_join_requests SET status = ?, decision_reason = ?, handled_by_user_id = ?, handled_at = NOW(), updated_at = NOW() WHERE id = ?',
                    [$action, $reasonMap[$action], (int) $user['id'], (int) $join['id']]
                );
            }
            return $join;
        });
        LogService::userOperation($request, $user, 'group_join_request', $action, (int) $join['id'], [
            'decision_reason' => $reasonMap[$action],
        ]);
        if ($action !== 'ignored') {
            $applicant = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], (int) $join['user_id']);
            if ($applicant !== null) NotificationService::send(
                $applicant, $action === 'approved' ? 'group_join_approved' : 'group_join_rejected',
                $action === 'approved' ? '入群申请已通过' : '入群申请未通过',
                $action === 'approved' ? '你已加入群聊“' . (string) $room['name'] . '”' : '群管理员拒绝了你的入群申请',
                ['request_id' => (int) $join['id'], 'room_id' => (int) $room['id']]
            );
        }
        $messageMap = ['approved' => '已同意入群', 'rejected' => '已拒绝入群', 'ignored' => '已忽略入群申请'];
        return Response::success([
            'request_id' => (int) $join['id'], 'status' => $action,
            'decision_reason' => $reasonMap[$action], 'applicant_notified' => $action !== 'ignored',
        ], $messageMap[$action]);
    }

    private static function requestState(array $item): array
    {
        $status = strtolower(trim((string) ($item['status'] ?? 'pending')));
        $active = in_array($status, ['pending', 'ignored'], true);
        $expiredAt = trim((string) ($item['expired_at'] ?? ''));
        $expired = $active && $expiredAt !== '' && strtotime($expiredAt) <= time();
        $item['is_expired'] = $expired;
        $item['is_dimmed'] = $expired;
        $item['can_decide'] = $active && !$expired;
        $item['status_text'] = $expired ? '已过期' : match ($status) {
            'pending' => '待处理', 'ignored' => '已忽略，可继续处理',
            'accepted', 'approved' => '已同意', 'rejected' => '已拒绝', default => '已处理',
        };
        return $item;
    }

    private static function messageItems(
        Request $request,
        int $roomId,
        int $userId,
        int $appId,
        ?string $historyVisibleFrom = null
    ): array
    {
        $limit = $request->limit();
        $where = ['m.room_id = ?', 'm.status = 1', 'COALESCE(state.is_deleted, 0) = 0'];
        $query = [$roomId];
        if ($historyVisibleFrom !== null && trim($historyVisibleFrom) !== '') {
            $where[] = 'm.created_at >= ?';
            $query[] = $historyVisibleFrom;
        }
        if ((int) $request->input('since_id', 0) > 0) {
            $where[] = 'm.id > ?';
            $query[] = (int) $request->input('since_id');
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(m.content LIKE ? OR u.account LIKE ? OR p.nickname LIKE ? OR viewer_friend.remark LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($query, $like, $like, $like, $like);
        }
        $items = Database::all(
            'SELECT m.id, m.user_id, m.sender_type, m.sender_admin_id, m.content_type, m.content, m.tags_json,
                    m.reply_to_message_id, m.created_at, u.account AS sender_account,
                    p.nickname AS sender_nickname, COALESCE(viewer_friend.remark, \'\') AS sender_remark,
                    COALESCE(NULLIF(viewer_friend.remark, \'\'), NULLIF(p.nickname, \'\'), u.account,
                      CASE m.sender_type WHEN \'admin\' THEN \'管理员\' WHEN \'platform\' THEN \'平台\'
                           WHEN \'system\' THEN \'系统\' ELSE \'群成员\' END) AS nickname,
                    COALESCE(p.avatar, \'\') AS avatar, cm.role, COALESCE(state.is_favorite, 0) AS is_favorite,
                    vc.id AS call_id, vc.call_type, vc.status AS call_status,
                    vc.duration_seconds AS call_duration_seconds,
                    vc.caller_user_id AS call_caller_user_id,
                    vc.callee_user_id AS call_callee_user_id,
                    vc.context_type AS call_context_type, vc.context_id AS call_context_id,
                    COALESCE(NULLIF(call_caller_profile.nickname, \'\'), call_caller.account, \'\') AS call_caller_name,
                    COALESCE(NULLIF(call_callee_profile.nickname, \'\'), call_callee.account, \'\') AS call_callee_name,
                    COALESCE(call_caller_profile.avatar, \'\') AS call_caller_avatar,
                    COALESCE(call_callee_profile.avatar, \'\') AS call_callee_avatar
             FROM chat_room_messages m LEFT JOIN users u ON u.id = m.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN friends viewer_friend ON viewer_friend.app_id = m.app_id AND viewer_friend.user_id = ?
               AND viewer_friend.friend_user_id = u.id AND viewer_friend.status = 1
             LEFT JOIN chat_room_members cm ON cm.room_id = m.room_id AND cm.user_id = m.user_id
             LEFT JOIN voice_calls vc ON vc.room_message_id = m.id
             LEFT JOIN users call_caller ON call_caller.id = vc.caller_user_id
             LEFT JOIN user_profiles call_caller_profile ON call_caller_profile.user_id = call_caller.id
             LEFT JOIN users call_callee ON call_callee.id = vc.callee_user_id
             LEFT JOIN user_profiles call_callee_profile ON call_callee_profile.user_id = call_callee.id
             LEFT JOIN communication_message_states state ON state.scope_type = \'group\'
               AND state.message_id = m.id AND state.user_id = ?
             WHERE ' . implode(' AND ', $where) . " ORDER BY m.id DESC LIMIT {$limit}",
            array_merge([$userId, $userId], $query)
        );
        $items = ContentTagService::hydrate($items);
        $items = MessageMediaService::hydrate($items, 'group_message', $appId);
        $items = MessageForwardService::hydrate($items, 'group_message', $appId);
        $items = MessagePresentationService::hydrate($items, 'group', $userId);
        return array_reverse($items);
    }

    private static function canRecallGroupMessage(array $message, array $user, int $seconds, bool $canModerate): bool
    {
        if ((string) ($message['content_type'] ?? '') === 'recall'
            || (string) ($message['sender_type'] ?? '') === 'system') return false;
        if ($canModerate) return true;
        if ($seconds <= 0) return false;
        if ((string) ($message['sender_type'] ?? '') !== 'user'
            || (int) ($message['user_id'] ?? 0) !== (int) $user['id']) return false;
        $createdAt = strtotime((string) ($message['created_at'] ?? ''));
        return $createdAt !== false && time() - $createdAt <= $seconds;
    }

    private static function replyId(array $room, int $messageId): ?int
    {
        if ($messageId <= 0) return null;
        $message = Database::one('SELECT id FROM chat_room_messages WHERE id = ? AND room_id = ? AND status = 1', [$messageId, (int) $room['id']]);
        if ($message === null) throw new HttpException('被回复的群消息不存在', 404, 404);
        return (int) $message['id'];
    }

    private static function saveRead(array $room, array $user, int $messageId): void
    {
        Database::execute(
            'INSERT INTO chat_room_reads (admin_id, app_id, room_id, user_id, last_read_message_id, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(COALESCE(last_read_message_id, 0), VALUES(last_read_message_id)), updated_at = NOW()',
            [(int) $room['admin_id'], (int) $room['app_id'], (int) $room['id'], (int) $user['id'], $messageId]
        );
    }

    private static function roomNotice(array $room, array $user, string $content): int
    {
        return Database::insert(
            'INSERT INTO chat_room_messages
             (admin_id, app_id, room_id, user_id, sender_type, sender_admin_id, content_type, content, tags_json, status, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, 1, NOW())',
            [(int) $room['admin_id'], (int) $room['app_id'], (int) $room['id'], (int) $user['id'], 'system', 'system', mb_substr($content, 0, 1000), '[]']
        );
    }

    private static function extensionRoom(Request $request, int $roomId): array
    {
        $user = self::user($request); AppService::requireFeature((int) $user['app_id'], 'chat_extensions');
        $room = ChatRoomService::userRoom($user, $roomId, true); $member = ChatRoomService::requireMember($user, $room); return [$user, $room, $member];
    }

    private static function roomVote(array $room, int $voteId): array
    {
        $vote = Database::one('SELECT * FROM chat_room_votes WHERE id = ? AND room_id = ?', [$voteId, (int) $room['id']]); if ($vote === null) throw new HttpException('群投票不存在', 404, 404); return $vote;
    }

    private static function decorateVoteMultipleChoice(array $vote): array
    {
        $multiple = self::boolValue($vote['multiple_choice'] ?? false);
        $vote['multiple_choice'] = $multiple;
        $vote['multi_select'] = $multiple;
        $vote['allow_multiple'] = $multiple;
        return $vote;
    }

    private static function roomSolitaire(array $room, int $id): array
    {
        $item = Database::one('SELECT * FROM chat_room_solitaire WHERE id = ? AND room_id = ?', [$id, (int) $room['id']]); if ($item === null) throw new HttpException('群接龙不存在', 404, 404); return $item;
    }

    private static function boolValue($value): bool
    {
        if (is_bool($value)) return $value; return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function decodedQrRoom(Request $request, array $user): array
    {
        $value = trim((string) $request->input(
            'qr_code',
            $request->input('code', $request->input('payload', ''))
        ));
        if ($value === '') throw new HttpException('请提供群聊或聊天室二维码内容', 0, 422);
        $decoded = GroupQrService::decode($value, (int) $user['admin_id'], (int) $user['app_id']);
        $room = ChatRoomService::adminRoom(
            (int) $user['admin_id'],
            (int) $user['app_id'],
            (int) $decoded['room_id'],
            true
        );
        return [$decoded, $room];
    }

    private static function qrRoomPreview(array $user, array $room, array $decoded): array
    {
        $detail = ChatRoomService::detail($room, (int) $user['id']);
        $roomKind = ChatRoomService::roomKind($room['room_kind'] ?? ChatRoomService::ROOM_GROUP);
        $entity = $roomKind === ChatRoomService::ROOM_CHATROOM ? '聊天室' : '群聊';
        $policy = ChatRoomService::policy($room);
        $joined = ChatRoomService::member((int) $room['id'], (int) $user['id']) !== null;
        $invited = self::pendingInvitation($room, $user) !== null;
        $pending = Database::one(
            "SELECT id FROM chat_room_join_requests
             WHERE room_id = ? AND user_id = ? AND status = 'pending'
               AND (expired_at IS NULL OR expired_at > NOW())",
            [(int) $room['id'], (int) $user['id']]
        ) !== null;

        $action = 'unavailable';
        $label = '仅限受邀成员';
        if ($joined) {
            $action = 'enter';
            $label = '进入' . $entity;
        } elseif ($policy['join_mode'] === ChatRoomService::JOIN_OPEN) {
            $action = 'join';
            $label = '加入' . $entity;
        } elseif ($policy['join_mode'] === ChatRoomService::JOIN_APPROVAL) {
            $action = $pending ? 'pending' : 'apply';
            $label = $pending ? '申请审核中' : '申请加入';
        } elseif ($invited || self::signedQrMayInvite($decoded, $room)) {
            $action = 'join';
            $label = '加入' . $entity;
        }

        $modeText = [
            ChatRoomService::JOIN_OPEN => '公开加入',
            ChatRoomService::JOIN_APPROVAL => '需要审核',
            ChatRoomService::JOIN_INVITE => '仅限邀请',
        ][$policy['join_mode']] ?? '仅限邀请';

        return [
            'id' => (int) $room['id'],
            'room_kind' => $roomKind,
            'room_kind_name' => $entity,
            'name' => (string) $room['name'],
            'description' => (string) ($room['description'] ?? ''),
            'avatar' => (string) ($room['icon'] ?? ''),
            'icon' => (string) ($room['icon'] ?? ''),
            'group_number' => (string) (20000000000 + (int) $room['id']),
            'member_count' => (int) ($detail['member_count'] ?? 0),
            'max_members' => (int) ($policy['max_members'] ?? 0),
            'join_mode' => (string) $policy['join_mode'],
            'join_mode_text' => $modeText,
            'announcement' => (string) ($policy['announcement'] ?? ''),
            'tags' => $detail['tags'] ?? [],
            'created_at' => $room['created_at'] ?? null,
            'joined' => $joined,
            'pending' => $pending,
            'current_role' => $detail['current_role'] ?? null,
            'join_action' => $action,
            'join_label' => $label,
            'qr_signed' => (bool) ($decoded['signed'] ?? false),
        ];
    }

    private static function pendingInvitation(array $room, array $user): ?array
    {
        return Database::one(
            "SELECT * FROM chat_room_invitations
             WHERE room_id = ? AND invitee_user_id = ? AND status = 'pending'
               AND (expired_at IS NULL OR expired_at > NOW())",
            [(int) $room['id'], (int) $user['id']]
        );
    }

    private static function signedQrMayInvite(array $decoded, array $room): bool
    {
        if (!(bool) ($decoded['signed'] ?? false)) return false;
        $issuerUserId = (int) ($decoded['issuer_user_id'] ?? 0);
        if ($issuerUserId <= 0) return false;
        $issuer = Database::one(
            'SELECT u.id, m.role
             FROM users u INNER JOIN chat_room_members m ON m.user_id = u.id AND m.room_id = ?
             WHERE u.id = ? AND u.admin_id = ? AND u.app_id = ? AND u.status = 1 AND u.deleted_at IS NULL',
            [(int) $room['id'], $issuerUserId, (int) $room['admin_id'], (int) $room['app_id']]
        );
        if ($issuer === null) return false;
        return in_array((string) $issuer['role'], ['owner', 'admin'], true)
            || (bool) ChatRoomService::policy($room)['allow_member_invite'];
    }

    private static function submitQrJoinRequest(
        Request $request,
        array $user,
        array $room
    ): \Yiyunying\Core\ApiResponse {
        $roomKind = ChatRoomService::roomKind($room['room_kind'] ?? ChatRoomService::ROOM_GROUP);
        $entity = self::roomEntity($room);
        $message = mb_substr((string) $request->input('message', ''), 0, 500);
        $validDays = (int) AppService::relationshipRequestPolicy((int) $user['app_id'])['effective_days'];
        $expiredAt = date('Y-m-d H:i:s', time() + ($validDays * 86400));
        Database::execute(
            'INSERT INTO chat_room_join_requests
             (admin_id, app_id, room_id, user_id, message, status, decision_reason, ignore_reason,
              ignored_at, expired_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, \'\', \'\', NULL, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE message = VALUES(message), status = ?, decision_reason = \'\',
               ignore_reason = \'\', ignored_at = NULL, expired_at = VALUES(expired_at), handled_by_user_id = NULL,
               handled_by_admin_id = NULL, handled_at = NULL, updated_at = NOW()',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $room['id'], (int) $user['id'],
                $message, 'pending', $expiredAt, 'pending',
            ]
        );
        LogService::userOperation($request, $user, $roomKind, 'qr_join_request', (int) $room['id']);
        return Response::success([
            'joined' => false,
            'pending' => true,
            'expired_at' => $expiredAt,
            'valid_days' => $validDays,
            'room' => self::qrRoomPreview($user, $room, [
                'signed' => false,
                'issuer_user_id' => 0,
            ]),
        ], '加入' . $entity . '的申请已提交', 202);
    }

    private static function roomEntity(array $room): string
    {
        return ChatRoomService::roomKind($room['room_kind'] ?? ChatRoomService::ROOM_GROUP)
            === ChatRoomService::ROOM_CHATROOM ? '聊天室' : '群聊';
    }

    private static function user(Request $request): array
    {
        $user = AuthService::user($request);
        AppService::requireFeature((int) $user['app_id'], 'chat_rooms');
        return $user;
    }
}
