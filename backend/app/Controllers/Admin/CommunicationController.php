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
use Yiyunying\Services\LogService;
use Yiyunying\Services\MessageForwardService;
use Yiyunying\Services\MessageMediaService;
use Yiyunying\Services\MessagePresentationService;

final class CommunicationController
{
    public static function systemMessage(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $data = $request->all();
        Validator::required($data, ['target_type', 'title', 'content']);
        $targetType = trim((string) $data['target_type']);
        if (!in_array($targetType, ['all', 'users', 'tag'], true)) {
            throw new HttpException('target_type 仅支持 all、users 或 tag', 0, 422);
        }
        $title = Validator::string($data['title'], 'title', 1, 200);
        $content = Validator::string($data['content'], 'content', 1, 20000);
        $where = ['u.admin_id = ?', 'u.app_id = ?', 'u.status = 1', 'u.deleted_at IS NULL'];
        $query = [(int) $admin['id'], $appId];
        $join = '';
        if ($targetType === 'users') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($data['target_user_ids'] ?? [])))));
            if ($ids === [] || count($ids) > 1000) {
                throw new HttpException('target_user_ids 必须包含 1-1000 个用户 ID', 0, 422);
            }
            $where[] = 'u.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $query = array_merge($query, $ids);
        } elseif ($targetType === 'tag') {
            $tagId = (int) ($data['tag_id'] ?? 0);
            if ($tagId <= 0) {
                throw new HttpException('tag 模式必须提供 tag_id', 0, 422);
            }
            $join = ' INNER JOIN user_tag_relations utr ON utr.user_id = u.id AND utr.app_id = u.app_id';
            $where[] = 'utr.tag_id = ?';
            $query[] = $tagId;
        }
        $users = Database::all(
            'SELECT DISTINCT u.id FROM users u' . $join . ' WHERE ' . implode(' AND ', $where),
            $query
        );
        if ($users === []) {
            throw new HttpException('没有符合条件的接收用户', 0, 422);
        }
        Database::transaction(static function () use ($admin, $appId, $users, $title, $content): void {
            foreach ($users as $user) {
                Database::execute(
                    'INSERT INTO messages
                     (admin_id, app_id, conversation_id, sender_type, sender_id, receiver_user_id,
                      title, content_type, content, is_read, status, created_at)
                     VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, 0, 1, NOW())',
                    [(int) $admin['id'], $appId, 'system', (int) $admin['id'], (int) $user['id'], $title, 'text', $content]
                );
            }
        });
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'message', 'system_send', null, null, [
            'target_type' => $targetType,
            'recipient_count' => count($users),
            'title' => $title,
        ]);
        return Response::success(['recipient_count' => count($users)], '系统通知发送成功', 201);
    }

    public static function messages(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['m.admin_id = ?', 'm.app_id = ?'];
        $query = [(int) $admin['id'], $appId];
        if ((int) $request->input('user_id', 0) > 0) {
            $where[] = '(m.receiver_user_id = ? OR (m.sender_type = ? AND m.sender_id = ?))';
            $query[] = (int) $request->input('user_id');
            $query[] = 'user';
            $query[] = (int) $request->input('user_id');
        }
        $type = trim((string) $request->input('type', ''));
        if ($type !== '') {
            $where[] = 'm.sender_type = ?';
            $query[] = $type;
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM messages m WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT m.*, u.account AS receiver_account, p.nickname AS receiver_name,
                    (recall.id IS NOT NULL) AS recalled, recall.created_at AS recalled_at,
                    audit.recalled_by_type, audit.recalled_by_id, audit.reason AS recall_reason
             FROM messages m INNER JOIN users u ON u.id = m.receiver_user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN message_recalls recall ON recall.message_id = m.id
             LEFT JOIN message_recall_audits audit
               ON audit.app_id = m.app_id AND audit.channel_type = 'private' AND audit.message_id = m.id
             WHERE {$whereSql} ORDER BY m.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = MessageMediaService::hydrate($items, 'private_message', $appId);
        $items = MessageForwardService::hydrate($items, 'private_message', $appId);
        $items = MessagePresentationService::hydrate($items, 'private');
        foreach ($items as &$item) {
            $item['original_visible_to_manager'] = true;
            if ((bool) ($item['recalled'] ?? false)) $item['recall_notice'] = '该消息已撤回，仅管理审计可见原文';
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function serviceSessions(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['s.admin_id = ?', 's.app_id = ?'];
        $query = [(int) $admin['id'], $appId];
        if (trim((string) $request->input('status', '')) !== '') {
            $where[] = 's.status = ?';
            $query[] = trim((string) $request->input('status'));
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM service_sessions s WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT s.*, u.account, p.nickname, p.avatar,
                    (SELECT COUNT(*) FROM service_messages sm
                     WHERE sm.session_id = s.id AND sm.sender_type = 'user' AND sm.is_read = 0) AS unread_count,
                    (SELECT content FROM service_messages sm
                     WHERE sm.session_id = s.id ORDER BY sm.id DESC LIMIT 1) AS last_message
             FROM service_sessions s INNER JOIN users u ON u.id = s.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE {$whereSql} ORDER BY COALESCE(s.last_message_at, s.created_at) DESC
             LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function serviceMessages(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $session = self::session((int) $params['session_id'], (int) $admin['id'], $appId);
        Database::execute(
            "UPDATE service_messages SET is_read = 1 WHERE session_id = ? AND sender_type = 'user'",
            [(int) $session['id']]
        );
        $items = Database::all(
            'SELECT * FROM service_messages WHERE session_id = ? ORDER BY id ASC LIMIT 1000',
            [(int) $session['id']]
        );
        $items = MessageMediaService::hydrate($items, 'service_message', $appId);
        $items = MessageForwardService::hydrate($items, 'service_message', $appId);
        $items = MessagePresentationService::hydrate($items, 'service');
        foreach ($items as &$item) $item['can_recall'] = false;
        unset($item);
        return Response::success(['session' => $session, 'items' => $items, 'message_recall_allowed' => false]);
    }

    public static function serviceReply(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $session = self::session((int) $params['session_id'], (int) $admin['id'], $appId);
        if ((string) $session['status'] === 'closed') {
            throw new HttpException('客服会话已关闭', 0, 409);
        }
        $payload = MessageMediaService::adminPayload($admin, $appId, $request->all());
        $replyRequestId = max(0, (int) $request->input('reply_to_message_id', 0));
        $messageId = Database::transaction(static function () use ($admin, $appId, $session, $payload, $replyRequestId): int {
            $replyId = null;
            if ($replyRequestId > 0) {
                $reply = Database::one('SELECT id FROM service_messages WHERE id = ? AND session_id = ? LIMIT 1', [$replyRequestId, (int) $session['id']]);
                if ($reply === null) throw new HttpException('被引用的客服消息不存在', 0, 404);
                $replyId = (int) $reply['id'];
            }
            $id = Database::insert(
                'INSERT INTO service_messages
                 (admin_id, app_id, session_id, sender_type, sender_id, reply_to_message_id, content, is_read, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())',
                [(int) $admin['id'], $appId, (int) $session['id'], 'admin', (int) $admin['id'], $replyId, (string) $payload['content']]
            );
            MessageMediaService::save('service_message', $id, $payload);
            Database::execute(
                'UPDATE service_sessions SET assigned_admin_id = ?, status = ?, last_message_at = NOW(), updated_at = NOW() WHERE id = ?',
                [(int) $admin['id'], 'open', (int) $session['id']]
            );
            Database::execute(
                "UPDATE service_messages SET is_read = 1 WHERE session_id = ? AND sender_type = 'user'",
                [(int) $session['id']]
            );
            return $id;
        });
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'service', 'reply', (int) $session['id']);
        return Response::success(['message_id' => $messageId], '客服回复成功', 201);
    }

    public static function closeServiceSession(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $session = self::session((int) $params['session_id'], (int) $admin['id'], $appId);
        Database::execute(
            'UPDATE service_sessions SET status = ?, closed_at = NOW(), updated_at = NOW() WHERE id = ?',
            ['closed', (int) $session['id']]
        );
        return Response::success(['session_id' => (int) $session['id'], 'status' => 'closed'], '客服会话已关闭');
    }

    public static function chatRooms(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        return Response::success(['items' => Database::all(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM chat_room_members m WHERE m.room_id = r.id) AS member_count,
                    (SELECT COUNT(*) FROM chat_room_messages cm WHERE cm.room_id = r.id AND cm.status = 1) AS message_count
             FROM chat_rooms r WHERE r.admin_id = ? AND r.app_id = ? ORDER BY r.id DESC',
            [(int) $admin['id'], $appId]
        )]);
    }

    public static function createChatRoom(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $name = Validator::string($request->input('name', ''), 'name', 1, 100);
        $id = Database::insert(
            'INSERT INTO chat_rooms
             (admin_id, app_id, name, icon, description, room_kind, is_public, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (int) $admin['id'], $appId, $name,
                mb_substr((string) $request->input('icon', ''), 0, 500),
                mb_substr((string) $request->input('description', ''), 0, 1000),
                ChatRoomService::ROOM_CHATROOM,
                Validator::boolean($request->input('is_public', true), 'is_public') ? 1 : 0,
                Validator::boolean($request->input('status', true), 'status') ? 1 : 0,
            ]
        );
        return Response::success(['room_id' => $id], '聊天室创建成功', 201);
    }

    public static function updateChatRoom(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $room = Database::one('SELECT * FROM chat_rooms WHERE id = ? AND admin_id = ? AND app_id = ?', [
            (int) $params['room_id'], (int) $admin['id'], $appId,
        ]);
        if ($room === null) {
            throw new HttpException('聊天室不存在', 404, 404);
        }
        Database::execute(
            'UPDATE chat_rooms SET name = ?, icon = ?, description = ?, is_public = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [
                mb_substr((string) $request->input('name', $room['name']), 0, 100),
                mb_substr((string) $request->input('icon', $room['icon']), 0, 500),
                mb_substr((string) $request->input('description', $room['description']), 0, 1000),
                Validator::boolean($request->input('is_public', (bool) $room['is_public']), 'is_public') ? 1 : 0,
                Validator::boolean($request->input('status', (bool) $room['status']), 'status') ? 1 : 0,
                (int) $room['id'],
            ]
        );
        return Response::success(['room_id' => (int) $room['id']], '聊天室已更新');
    }

    public static function muteRoomMember(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $member = Database::one(
            'SELECT m.* FROM chat_room_members m INNER JOIN chat_rooms r ON r.id = m.room_id
             WHERE m.room_id = ? AND m.user_id = ? AND r.admin_id = ? AND r.app_id = ?',
            [(int) $params['room_id'], (int) $params['user_id'], (int) $admin['id'], $appId]
        );
        if ($member === null) {
            throw new HttpException('聊天室成员不存在', 404, 404);
        }
        $muteUntil = Validator::nullableDateTime($request->input('mute_until'), 'mute_until');
        Database::execute('UPDATE chat_room_members SET mute_until = ? WHERE id = ?', [$muteUntil, (int) $member['id']]);
        return Response::success(['user_id' => (int) $member['user_id'], 'mute_until' => $muteUntil], '成员禁言状态已更新');
    }

    public static function deleteRoomMessage(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $affected = Database::execute(
            'UPDATE chat_room_messages SET status = 0 WHERE id = ? AND admin_id = ? AND app_id = ?',
            [(int) $params['message_id'], (int) $admin['id'], $appId]
        );
        if ($affected === 0) {
            throw new HttpException('聊天室消息不存在', 404, 404);
        }
        return Response::success(['message_id' => (int) $params['message_id']], '消息已删除');
    }

    private static function context(Request $request, array $params): array
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        return [$admin, $appId];
    }

    private static function session(int $sessionId, int $adminId, int $appId): array
    {
        $session = Database::one(
            'SELECT s.*, u.account, p.nickname FROM service_sessions s
             INNER JOIN users u ON u.id = s.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE s.id = ? AND s.admin_id = ? AND s.app_id = ?',
            [$sessionId, $adminId, $appId]
        );
        if ($session === null) {
            throw new HttpException('客服会话不存在', 404, 404);
        }
        return $session;
    }
}
