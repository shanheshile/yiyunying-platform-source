<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

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
use Yiyunying\Services\LogService;
use Yiyunying\Services\MessageForwardService;
use Yiyunying\Services\MessageMediaService;
use Yiyunying\Services\MessagePresentationService;

final class GroupController
{
    public static function index(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['admin_id = ?', 'app_id = ?'];
        $query = [(int) $admin['id'], $appId];
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(name LIKE ? OR description LIKE ? OR tags_json LIKE ? OR CAST(id AS CHAR) LIKE ?)';
            foreach (range(1, 4) as $_) $query[] = '%' . $keyword . '%';
        }
        if ($request->input('status') !== null && $request->input('status') !== '') {
            $where[] = 'status = ?';
            $query[] = (int) $request->input('status');
        }
        $requestedKind = trim((string) $request->input('room_kind', ''));
        if ($requestedKind !== '') {
            $where[] = 'room_kind = ?';
            $query[] = ChatRoomService::roomKind($requestedKind);
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM chat_rooms WHERE {$whereSql}", $query)['total'] ?? 0);
        $rooms = Database::all(
            "SELECT * FROM chat_rooms WHERE {$whereSql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = array_map(static fn(array $room): array => ChatRoomService::detail($room), $rooms);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function create(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $roomKind = ChatRoomService::roomKind($request->input('room_kind', ChatRoomService::ROOM_GROUP));
        $isChatroom = $roomKind === ChatRoomService::ROOM_CHATROOM;
        $entity = $isChatroom ? '聊天室' : '群聊';
        $name = Validator::string($request->input('name', ''), 'name', 1, 100);
        $defaultJoinMode = $isChatroom ? ChatRoomService::JOIN_OPEN : ChatRoomService::JOIN_APPROVAL;
        $joinMode = ChatRoomService::joinMode($request->input('join_mode', $defaultJoinMode));
        $memberLimitKey = $isChatroom ? 'chatroom_default_max_members' : 'group_default_max_members';
        $defaultMemberLimit = max(2, (int) AppService::setting($appId, $memberLimitKey, 500));
        $roomId = Database::transaction(static function () use (
            $request, $admin, $appId, $name, $joinMode, $roomKind, $defaultMemberLimit
        ): int {
            $id = Database::insert(
                'INSERT INTO chat_rooms
                 (admin_id, app_id, name, icon, description, tags_json, room_kind, is_public, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
                [
                    (int) $admin['id'], $appId, $name,
                    mb_substr((string) $request->input('icon', ''), 0, 500),
                    mb_substr((string) $request->input('description', ''), 0, 1000),
                    ContentTagService::encode($request->input('tags', [])),
                    $roomKind,
                    $joinMode === ChatRoomService::JOIN_INVITE ? 0 : 1,
                ]
            );
            $room = ChatRoomService::adminRoom((int) $admin['id'], $appId, $id);
            ChatRoomService::savePolicy($room, [
                'join_mode' => $joinMode,
                'max_members' => (int) $request->input('max_members', $defaultMemberLimit),
                'allow_member_invite' => Validator::boolean($request->input('allow_member_invite', true), 'allow_member_invite'),
                'mute_all' => Validator::boolean($request->input('mute_all', false), 'mute_all'),
                'announcement' => (string) $request->input('announcement', ''),
            ]);
            return $id;
        });
        $room = ChatRoomService::adminRoom((int) $admin['id'], $appId, $roomId);
        LogService::adminOperation($request, (int) $admin['id'], $appId, $roomKind, 'create', $roomId, null, $room);
        return Response::success(['room' => ChatRoomService::detail($room)], $entity . '创建成功', 201);
    }
    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $room = ChatRoomService::adminRoom((int) $admin['id'], $appId, (int) $params['room_id']);
        return Response::success(['room' => ChatRoomService::detail($room)]);
    }

    public static function update(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $room = ChatRoomService::adminRoom((int) $admin['id'], $appId, (int) $params['room_id']);
        $before = ChatRoomService::detail($room);
        $name = array_key_exists('name', $request->all())
            ? Validator::string($request->input('name'), 'name', 1, 100) : (string) $room['name'];
        $status = array_key_exists('status', $request->all())
            ? (Validator::boolean($request->input('status'), 'status') ? 1 : 0) : (int) $room['status'];
        Database::execute(
            'UPDATE chat_rooms SET name = ?, icon = ?, description = ?, tags_json = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [
                $name,
                mb_substr((string) $request->input('icon', $room['icon']), 0, 500),
                mb_substr((string) $request->input('description', $room['description']), 0, 1000),
                ContentTagService::encode($request->input('tags', ContentTagService::decode($room['tags_json'] ?? null))),
                $status,
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
        $updated = ChatRoomService::adminRoom((int) $admin['id'], $appId, (int) $room['id']);
        if ($policyValues !== []) ChatRoomService::savePolicy($updated, $policyValues);
        $after = ChatRoomService::detail($updated);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'group', 'update', (int) $room['id'], $before, $after);
        return Response::success(['room' => $after], '群聊已更新');
    }

    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $room = ChatRoomService::adminRoom((int) $admin['id'], $appId, (int) $params['room_id']);
        Database::execute('UPDATE chat_rooms SET status = 0, updated_at = NOW() WHERE id = ?', [(int) $room['id']]);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'group', 'dissolve', (int) $room['id'], $room, null);
        return Response::success(['room_id' => (int) $room['id']], '群聊已解散');
    }

    public static function members(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $room = ChatRoomService::adminRoom((int) $admin['id'], $appId, (int) $params['room_id']);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one('SELECT COUNT(*) AS total FROM chat_room_members WHERE room_id = ?', [(int) $room['id']])['total'] ?? 0);
        $items = Database::all(
            "SELECT m.id, m.user_id, m.role, m.mute_until, m.joined_at,
                    u.account, p.nickname, p.avatar
             FROM chat_room_members m INNER JOIN users u ON u.id = m.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE m.room_id = ? ORDER BY FIELD(m.role, 'owner', 'admin', 'member'), m.id
             LIMIT {$limit} OFFSET {$offset}",
            [(int) $room['id']]
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function addMember(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $room = ChatRoomService::adminRoom((int) $admin['id'], $appId, (int) $params['room_id']);
        $userId = Validator::integer($request->input('user_id'), 'user_id', 1, PHP_INT_MAX);
        $role = ChatRoomService::memberRole($request->input('role', 'member'));
        $member = Database::transaction(static function () use ($room, $userId, $role): array {
            if ($role === 'owner') {
                Database::execute("UPDATE chat_room_members SET role = 'member' WHERE room_id = ? AND role = 'owner'", [(int) $room['id']]);
            }
            $member = ChatRoomService::addMember($room, $userId, $role);
            if ($role === 'owner') ChatRoomService::savePolicy($room, ['owner_user_id' => $userId]);
            return $member;
        });
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'group_member', 'add', $userId, null, $member);
        return Response::success(['member' => $member], '成员已加入群聊', 201);
    }

    public static function updateMember(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $room = ChatRoomService::adminRoom((int) $admin['id'], $appId, (int) $params['room_id']);
        $userId = (int) $params['user_id'];
        $member = ChatRoomService::member((int) $room['id'], $userId);
        if ($member === null) throw new HttpException('群成员不存在', 404, 404);
        $role = ChatRoomService::memberRole($request->input('role', $member['role']));
        Database::transaction(static function () use ($room, $userId, $role): void {
            if ($role === 'owner') {
                Database::execute("UPDATE chat_room_members SET role = 'member' WHERE room_id = ? AND role = 'owner'", [(int) $room['id']]);
                ChatRoomService::savePolicy($room, ['owner_user_id' => $userId]);
            } elseif ((string) (ChatRoomService::member((int) $room['id'], $userId)['role'] ?? '') === 'owner') {
                ChatRoomService::savePolicy($room, ['owner_user_id' => null]);
            }
            Database::execute('UPDATE chat_room_members SET role = ? WHERE room_id = ? AND user_id = ?', [$role, (int) $room['id'], $userId]);
        });
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'group_member', 'role_update', $userId, $member, ['role' => $role]);
        return Response::success(['user_id' => $userId, 'role' => $role], '成员角色已更新');
    }

    public static function muteMember(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $room = ChatRoomService::adminRoom((int) $admin['id'], $appId, (int) $params['room_id']);
        $userId = (int) $params['user_id'];
        if (ChatRoomService::member((int) $room['id'], $userId) === null) throw new HttpException('群成员不存在', 404, 404);
        $muteUntil = Validator::nullableDateTime($request->input('mute_until'), 'mute_until');
        Database::execute('UPDATE chat_room_members SET mute_until = ? WHERE room_id = ? AND user_id = ?', [$muteUntil, (int) $room['id'], $userId]);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'group_member', 'mute', $userId, null, ['mute_until' => $muteUntil]);
        return Response::success(['user_id' => $userId, 'mute_until' => $muteUntil], '禁言状态已更新');
    }

    public static function removeMember(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $room = ChatRoomService::adminRoom((int) $admin['id'], $appId, (int) $params['room_id']);
        $userId = (int) $params['user_id'];
        $member = ChatRoomService::member((int) $room['id'], $userId);
        if ($member === null) throw new HttpException('群成员不存在', 404, 404);
        if ((string) $member['role'] === 'owner') ChatRoomService::savePolicy($room, ['owner_user_id' => null]);
        ChatRoomService::removeMember($room, $userId);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'group_member', 'remove', $userId, $member, null);
        return Response::success(['user_id' => $userId], '成员已移出群聊');
    }

    public static function messages(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $room = ChatRoomService::adminRoom((int) $admin['id'], $appId, (int) $params['room_id']);
        return Response::success(['room' => ChatRoomService::detail($room), 'items' => self::messageItems($request, (int) $room['id'], $appId)]);
    }

    public static function sendMessage(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $room = ChatRoomService::adminRoom((int) $admin['id'], $appId, (int) $params['room_id'], true);
        $payload = MessageMediaService::adminPayload($admin, $appId, $request->all());
        $reply = self::replyId($room, (int) $request->input('reply_to_message_id', 0));
        $id = Database::transaction(static function () use ($admin, $appId, $room, $payload, $reply): int {
            $id = Database::insert(
                'INSERT INTO chat_room_messages
                 (admin_id, app_id, room_id, user_id, sender_type, sender_admin_id, content_type, content, reply_to_message_id, status, created_at)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, 1, NOW())',
                [
                    (int) $admin['id'], $appId, (int) $room['id'], 'system', (int) $admin['id'],
                    (string) $payload['content_type'], (string) $payload['content'], $reply,
                ]
            );
            MessageMediaService::save('group_message', $id, $payload);
            return $id;
        });
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'group_message', 'send', $id);
        return Response::success(['message_id' => $id], '群消息发送成功', 201);
    }

    public static function deleteMessage(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $room = ChatRoomService::adminRoom((int) $admin['id'], $appId, (int) $params['room_id']);
        $eventId = Database::transaction(static function () use ($params, $room, $admin, $appId): int {
            $message = Database::one(
                'SELECT * FROM chat_room_messages WHERE id = ? AND room_id = ? AND admin_id = ? AND app_id = ? AND status = 1 FOR UPDATE',
                [(int) $params['message_id'], (int) $room['id'], (int) $admin['id'], $appId]
            );
            if ($message === null) throw new HttpException('群消息不存在或已撤回', 404, 404);
            MessageMediaService::recordRecall(
                $message, 'group', (int) $room['id'], 'group_message', 'admin', (int) $admin['id'], '管理员撤回'
            );
            $affected = Database::execute(
                'UPDATE chat_room_messages SET status = 0
                 WHERE id = ? AND room_id = ? AND admin_id = ? AND app_id = ? AND status = 1',
                [(int) $params['message_id'], (int) $room['id'], (int) $admin['id'], $appId]
            );
            if ($affected === 0) throw new HttpException('群消息不存在或已撤回', 404, 404);
            return Database::insert(
                'INSERT INTO chat_room_messages
                 (admin_id, app_id, room_id, user_id, sender_type, sender_admin_id,
                  content_type, content, reply_to_message_id, status, created_at)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, 1, NOW())',
                [(int) $admin['id'], $appId, (int) $room['id'], 'system', (int) $admin['id'],
                    'recall', '管理员撤回了一条群消息', (int) $params['message_id']]
            );
        });
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'group_message', 'delete', (int) $params['message_id']);
        return Response::success([
            'message_id' => (int) $params['message_id'], 'recall_event_id' => $eventId, 'recalled' => true,
        ], '群消息已撤回');
    }

    public static function deleteAsset(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $room = ChatRoomService::adminRoom((int) $admin['id'], $appId, (int) $params['room_id']);
        $assetType = trim((string) $params['asset_type']);
        $assetId = (int) $params['asset_id'];
        $map = [
            'file' => ['chat_room_files', 'id', '群文件'],
            'album' => ['chat_room_albums', 'id', '群相册'],
            'photo' => ['chat_room_album_photos', 'id', '群相册照片'],
            'vote' => ['chat_room_votes', 'id', '群投票'],
        ];
        if (!isset($map[$assetType])) throw new HttpException('asset_type 仅支持 file、album、photo 或 vote', 0, 422);
        [$table, $idColumn, $label] = $map[$assetType];
        if ($assetType === 'photo') {
            $asset = Database::one('SELECT photo.id FROM chat_room_album_photos photo INNER JOIN chat_room_albums album ON album.id = photo.album_id WHERE photo.id = ? AND album.room_id = ? AND photo.status = 1', [$assetId, (int) $room['id']]);
        } else {
            $asset = Database::one("SELECT {$idColumn} AS id FROM {$table} WHERE {$idColumn} = ? AND room_id = ? AND status " . ($assetType === 'vote' ? "<> 'deleted'" : '= 1'), [$assetId, (int) $room['id']]);
        }
        if ($asset === null) throw new HttpException($label . '不存在', 404, 404);
        Database::execute('UPDATE ' . $table . ' SET status = ? WHERE ' . $idColumn . ' = ?', [$assetType === 'vote' ? 'deleted' : 0, $assetId]);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'group_asset', 'delete', $assetId, ['type' => $assetType], null);
        return Response::success(['asset_type' => $assetType, 'asset_id' => $assetId], $label . '已删除');
    }

    public static function joinRequests(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $room = ChatRoomService::adminRoom((int) $admin['id'], $appId, (int) $params['room_id']);
        $items = Database::all(
            'SELECT jr.*, u.account, p.nickname, p.avatar FROM chat_room_join_requests jr
             INNER JOIN users u ON u.id = jr.user_id LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE jr.room_id = ? ORDER BY (jr.status = ?) DESC, jr.id DESC',
            [(int) $room['id'], 'pending']
        );
        foreach ($items as &$item) {
            $active = in_array((string) $item['status'], ['pending', 'ignored'], true);
            $expired = $active && trim((string) ($item['expired_at'] ?? '')) !== ''
                && strtotime((string) $item['expired_at']) <= time();
            $item['is_expired'] = $expired;
            $item['is_dimmed'] = $expired;
            $item['can_decide'] = $active && !$expired;
            $item['status_text'] = $expired ? '已过期' : match ((string) $item['status']) {
                'pending' => '待处理', 'ignored' => '已忽略，可继续处理',
                'approved' => '已同意', 'rejected' => '已拒绝', default => '已处理',
            };
        }
        unset($item);
        return Response::success(['items' => $items]);
    }

    public static function decideJoinRequest(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $room = ChatRoomService::adminRoom((int) $admin['id'], $appId, (int) $params['room_id']);
        $action = strtolower(trim((string) $request->input('action', '')));
        if ($action === '') {
            $action = Validator::boolean($request->input('approve', true), 'approve') ? 'approved' : 'rejected';
        }
        if ($action === 'approve') $action = 'approved';
        if ($action === 'reject') $action = 'rejected';
        if ($action === 'ignore') $action = 'ignored';
        if (!in_array($action, ['approved', 'rejected', 'ignored'], true)) {
            throw new HttpException('处理动作仅支持同意、忽略或拒绝', 0, 422);
        }
        $reasonMap = [
            'approved' => '应用管理员同意入群申请',
            'rejected' => '应用管理员明确拒绝入群申请',
            'ignored' => '应用管理员忽略入群申请，申请人未收到通知',
        ];
        $join = Database::transaction(static function () use ($room, $params, $admin, $action, $reasonMap): array {
            $join = Database::one(
                "SELECT * FROM chat_room_join_requests WHERE id = ? AND room_id = ?
                 AND status IN ('pending','ignored') FOR UPDATE",
                [(int) $params['request_id'], (int) $room['id']]
            );
            if ($join === null) throw new HttpException('待处理的入群申请不存在', 404, 404);
            if (strtotime((string) $join['expired_at']) <= time()) {
                throw new HttpException('入群申请已过期，只能查看，不能继续处理', 0, 410);
            }
            if ($action === 'approved') ChatRoomService::addMember($room, (int) $join['user_id']);
            if ($action === 'ignored') {
                Database::execute(
                    'UPDATE chat_room_join_requests SET status = ?, decision_reason = ?, ignore_reason = ?,
                       ignored_at = NOW(), handled_by_admin_id = ?, handled_at = NULL, updated_at = NOW() WHERE id = ?',
                    [$action, $reasonMap[$action], $reasonMap[$action], (int) $admin['id'], (int) $join['id']]
                );
            } else {
                Database::execute(
                    'UPDATE chat_room_join_requests SET status = ?, decision_reason = ?, handled_by_admin_id = ?, handled_at = NOW(), updated_at = NOW() WHERE id = ?',
                    [$action, $reasonMap[$action], (int) $admin['id'], (int) $join['id']]
                );
            }
            return $join;
        });
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'group_join_request', $action, (int) $join['id'], [
            'decision_reason' => $reasonMap[$action],
        ]);
        $messageMap = ['approved' => '已同意入群', 'rejected' => '已拒绝入群', 'ignored' => '已忽略，稍后仍可同意或拒绝'];
        return Response::success([
            'request_id' => (int) $join['id'], 'status' => $action,
            'decision_reason' => $reasonMap[$action], 'applicant_notified' => $action !== 'ignored',
        ], $messageMap[$action]);
    }

    private static function context(Request $request, array $params): array
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        return [$admin, $appId];
    }

    private static function messageItems(Request $request, int $roomId, int $appId): array
    {
        $limit = $request->limit();
        $where = ['m.room_id = ?', 'm.status = 1'];
        $query = [$roomId];
        if ((int) $request->input('since_id', 0) > 0) {
            $where[] = 'm.id > ?';
            $query[] = (int) $request->input('since_id');
        }
        $items = Database::all(
            'SELECT m.id, m.user_id, m.sender_type, m.sender_admin_id, m.content_type, m.content, m.tags_json,
                    m.reply_to_message_id, m.created_at, u.account, p.nickname, p.avatar, cm.role,
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
             LEFT JOIN chat_room_members cm ON cm.room_id = m.room_id AND cm.user_id = m.user_id
             LEFT JOIN voice_calls vc ON vc.room_message_id = m.id
             LEFT JOIN users call_caller ON call_caller.id = vc.caller_user_id
             LEFT JOIN user_profiles call_caller_profile ON call_caller_profile.user_id = call_caller.id
             LEFT JOIN users call_callee ON call_callee.id = vc.callee_user_id
             LEFT JOIN user_profiles call_callee_profile ON call_callee_profile.user_id = call_callee.id
             WHERE ' . implode(' AND ', $where) . " ORDER BY m.id DESC LIMIT {$limit}",
            $query
        );
        $items = ContentTagService::hydrate($items);
        $items = MessageMediaService::hydrate($items, 'group_message', null);
        $items = MessageForwardService::hydrate($items, 'group_message', $appId);
        $items = MessagePresentationService::hydrate($items, 'group');
        foreach ($items as &$item) {
            $item['recalled_message_id'] = (string) ($item['content_type'] ?? '') === 'recall'
                ? (int) ($item['reply_to_message_id'] ?? 0)
                : null;
            $item['can_recall'] = false;
        }
        unset($item);
        return array_reverse($items);
    }

    private static function replyId(array $room, int $messageId): ?int
    {
        if ($messageId <= 0) return null;
        $message = Database::one(
            'SELECT id FROM chat_room_messages WHERE id = ? AND room_id = ? AND status = 1',
            [$messageId, (int) $room['id']]
        );
        if ($message === null) throw new HttpException('被回复的群消息不存在', 404, 404);
        return (int) $message['id'];
    }
}
