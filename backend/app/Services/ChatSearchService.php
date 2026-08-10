<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class ChatSearchService
{
    public static function search(
        array $user,
        string $scope,
        int $targetId,
        string $keyword,
        int $contextSize,
        int $limit,
        array $filters = []
    ): array
    {
        $scope = strtolower(trim($scope));
        if (!in_array($scope, ['private', 'group', 'service'], true)) {
            throw new HttpException('scope_type 仅支持 private、group 或 service', 0, 422);
        }
        $normalized = ChatRecordService::normalizeFilters($filters + ['keyword' => $keyword]);
        $keyword = (string) $normalized['keyword'];
        $hasAdvancedFilter = $normalized['content_filter'] !== 'all'
            || $normalized['date_from'] !== null || $normalized['date_to'] !== null
            || $normalized['sender_id'] > 0 || $normalized['message_ids'] !== [];
        if ($keyword === '' && !$hasAdvancedFilter) {
            throw new HttpException('请输入关键词，或至少选择日期、媒体类型、发言人等一项条件', 0, 422);
        }
        $contextSize = max(0, min(10, $contextSize));
        $limit = max(1, min(50, $limit));
        if ($hasAdvancedFilter) {
            $items = ChatRecordService::records($user, $scope, $targetId, $normalized, $limit);
            foreach ($items as &$item) {
                $item['is_search_match'] = true;
                $item['search_context'] = false;
            }
            unset($item);
            self::remember($user, $scope, $targetId, $normalized);
            return [
                'target_id' => $targetId, 'match_count' => count($items), 'items' => $items,
                'scope_type' => $scope, 'keyword' => $keyword, 'content_filter' => $normalized['content_filter'],
                'filters' => $normalized, 'context_size' => 0, 'read_only' => true,
            ];
        }
        $result = match ($scope) {
            'private' => self::privateSearch($user, $targetId, $keyword, $contextSize, $limit),
            'group' => self::groupSearch($user, $targetId, $keyword, $contextSize, $limit),
            default => self::serviceSearch($user, $targetId, $keyword, $contextSize, $limit),
        };
        self::remember($user, $scope, $result['target_id'], $normalized);
        return $result + [
            'scope_type' => $scope, 'keyword' => $keyword, 'context_size' => $contextSize,
            'content_filter' => 'all', 'filters' => $normalized,
            'read_only' => true,
        ];
    }

    public static function history(array $user, string $scope = '', int $targetId = 0): array
    {
        $where = ['admin_id = ?', 'app_id = ?', 'user_id = ?'];
        $query = [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']];
        if ($scope !== '') { $where[] = 'scope_type = ?'; $query[] = $scope; }
        if ($targetId > 0) { $where[] = 'target_id = ?'; $query[] = $targetId; }
        $items = Database::all(
            'SELECT id, scope_type, target_id, keyword, content_filter, filter_json, search_count, last_searched_at
             FROM chat_search_histories WHERE ' . implode(' AND ', $where) . '
             ORDER BY last_searched_at DESC, id DESC LIMIT 30',
            $query
        );
        foreach ($items as &$item) {
            $item['filters'] = json_decode((string) ($item['filter_json'] ?? '{}'), true) ?: [];
            unset($item['filter_json']);
        }
        unset($item);
        return $items;
    }

    public static function clearHistory(array $user, string $scope = '', int $targetId = 0, string $keyword = ''): int
    {
        $where = ['admin_id = ?', 'app_id = ?', 'user_id = ?'];
        $query = [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']];
        if ($scope !== '') { $where[] = 'scope_type = ?'; $query[] = $scope; }
        if ($targetId > 0) { $where[] = 'target_id = ?'; $query[] = $targetId; }
        if ($keyword !== '') { $where[] = 'keyword = ?'; $query[] = $keyword; }
        return Database::execute('DELETE FROM chat_search_histories WHERE ' . implode(' AND ', $where), $query);
    }

    private static function privateSearch(array $user, int $conversationId, string $keyword, int $contextSize, int $limit): array
    {
        $conversation = Database::one(
            'SELECT * FROM conversations WHERE id = ? AND admin_id = ? AND app_id = ? AND (user_a_id = ? OR user_b_id = ?)',
            [$conversationId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], (int) $user['id']]
        );
        if ($conversation === null) throw new HttpException('会话不存在或无权查看', 404, 404);
        $like = '%' . $keyword . '%';
        $matches = Database::all(
            "SELECT message.id FROM messages message
             LEFT JOIN message_user_states state ON state.message_id = message.id AND state.user_id = ?
             LEFT JOIN users sender ON sender.id = CASE WHEN message.sender_type = 'user' THEN message.sender_id ELSE NULL END
             LEFT JOIN user_profiles profile ON profile.user_id = sender.id
             LEFT JOIN friends viewer_friend ON viewer_friend.app_id = message.app_id AND viewer_friend.user_id = ?
               AND viewer_friend.friend_user_id = sender.id AND viewer_friend.status = 1
             WHERE message.conversation_id = ? AND message.status = 1 AND COALESCE(state.is_deleted, 0) = 0
               AND (message.content LIKE ? OR message.tags_json LIKE ? OR sender.account LIKE ? OR profile.nickname LIKE ? OR viewer_friend.remark LIKE ?
                    OR EXISTS (SELECT 1 FROM media_attachments media
                               WHERE media.app_id = ? AND media.target_type = ? AND media.target_id = message.id
                                 AND (media.file_name LIKE ? OR media.mime_type LIKE ? OR media.media_type LIKE ?))
                    OR EXISTS (SELECT 1 FROM message_forward_links forward_link
                               INNER JOIN message_forward_bundles forward_bundle ON forward_bundle.id = forward_link.bundle_id
                               WHERE forward_link.app_id = ? AND forward_link.target_type = ?
                                 AND forward_link.target_id = message.id AND forward_bundle.snapshot_json LIKE ?))
             ORDER BY message.id DESC LIMIT {$limit}",
            [
                (int) $user['id'], (int) $user['id'], $conversationId, $like, $like, $like, $like, $like,
                (int) $user['app_id'], 'private_message', $like, $like, $like,
                (int) $user['app_id'], 'private_message', $like,
            ]
        );
        $matchIds = array_map(static fn(array $row): int => (int) $row['id'], $matches);
        $ids = self::contextIds('messages', 'conversation_id', $conversationId, $matchIds, $contextSize,
            'status = 1 AND NOT EXISTS (SELECT 1 FROM message_user_states local_state WHERE local_state.message_id = messages.id AND local_state.user_id = ' . (int) $user['id'] . ' AND local_state.is_deleted = 1)');
        if ($ids === []) return ['target_id' => $conversationId, 'match_count' => 0, 'items' => []];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $items = Database::all(
            "SELECT message.id, message.sender_type, message.sender_id, message.receiver_user_id, message.title,
                    message.content_type, message.tags_json, message.content, message.created_at,
                    COALESCE(state.is_favorite, 0) AS is_favorite,
                    sender.account AS sender_account, profile.nickname AS sender_nickname,
                    COALESCE(viewer_friend.remark, '') AS sender_remark,
                    COALESCE(NULLIF(viewer_friend.remark, ''), NULLIF(profile.nickname, ''), sender.account,
                      CASE message.sender_type WHEN 'admin' THEN '管理员' WHEN 'platform' THEN '平台' ELSE '用户' END) AS sender_name,
                    COALESCE(profile.avatar, '') AS sender_avatar
             FROM messages message
             LEFT JOIN message_user_states state ON state.message_id = message.id AND state.user_id = ?
             LEFT JOIN users sender ON sender.id = CASE WHEN message.sender_type = 'user' THEN message.sender_id ELSE NULL END
             LEFT JOIN user_profiles profile ON profile.user_id = sender.id
             LEFT JOIN friends viewer_friend ON viewer_friend.app_id = message.app_id AND viewer_friend.user_id = ?
               AND viewer_friend.friend_user_id = sender.id AND viewer_friend.status = 1
             WHERE message.id IN ({$placeholders}) ORDER BY message.id ASC",
            array_merge([(int) $user['id'], (int) $user['id']], $ids)
        );
        $items = ContentTagService::hydrate($items);
        $items = MessageMediaService::hydrate($items, 'private_message', (int) $user['app_id']);
        $items = MessageForwardService::hydrate($items, 'private_message', (int) $user['app_id']);
        $items = MessagePresentationService::hydrate($items, 'private', (int) $user['id']);
        return ['target_id' => $conversationId, 'match_count' => count($matchIds), 'items' => self::markMatches($items, $matchIds, $keyword)];
    }

    private static function groupSearch(array $user, int $roomId, string $keyword, int $contextSize, int $limit): array
    {
        $room = ChatRoomService::userRoom($user, $roomId, true);
        ChatRoomService::requireMember($user, $room);
        $like = '%' . $keyword . '%';
        $matches = Database::all(
            "SELECT message.id FROM chat_room_messages message
             LEFT JOIN users sender ON sender.id = message.user_id
             LEFT JOIN user_profiles profile ON profile.user_id = sender.id
             LEFT JOIN friends viewer_friend ON viewer_friend.app_id = message.app_id AND viewer_friend.user_id = ?
               AND viewer_friend.friend_user_id = sender.id AND viewer_friend.status = 1
             LEFT JOIN communication_message_states state ON state.scope_type = 'group'
               AND state.message_id = message.id AND state.user_id = ?
             WHERE message.room_id = ? AND message.status = 1 AND COALESCE(state.is_deleted, 0) = 0
               AND (message.content LIKE ? OR message.tags_json LIKE ? OR sender.account LIKE ? OR profile.nickname LIKE ? OR viewer_friend.remark LIKE ?
                    OR EXISTS (SELECT 1 FROM media_attachments media
                               WHERE media.app_id = ? AND media.target_type = ? AND media.target_id = message.id
                                 AND (media.file_name LIKE ? OR media.mime_type LIKE ? OR media.media_type LIKE ?))
                    OR EXISTS (SELECT 1 FROM message_forward_links forward_link
                               INNER JOIN message_forward_bundles forward_bundle ON forward_bundle.id = forward_link.bundle_id
                               WHERE forward_link.app_id = ? AND forward_link.target_type = ?
                                 AND forward_link.target_id = message.id AND forward_bundle.snapshot_json LIKE ?))
             ORDER BY message.id DESC LIMIT {$limit}",
            [
                (int) $user['id'], (int) $user['id'], $roomId, $like, $like, $like, $like, $like,
                (int) $user['app_id'], 'group_message', $like, $like, $like,
                (int) $user['app_id'], 'group_message', $like,
            ]
        );
        $matchIds = array_map(static fn(array $row): int => (int) $row['id'], $matches);
        $ids = self::contextIds('chat_room_messages', 'room_id', $roomId, $matchIds, $contextSize,
            "status = 1 AND NOT EXISTS (SELECT 1 FROM communication_message_states local_state WHERE local_state.scope_type = 'group' AND local_state.message_id = chat_room_messages.id AND local_state.user_id = " . (int) $user['id'] . ' AND local_state.is_deleted = 1)');
        if ($ids === []) return ['target_id' => $roomId, 'match_count' => 0, 'items' => []];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $items = Database::all(
            "SELECT message.id, message.user_id, message.sender_type, message.sender_admin_id,
                    COALESCE(message.user_id, message.sender_admin_id, 0) AS sender_id,
                    message.content_type, message.content, message.tags_json, message.reply_to_message_id, message.created_at,
                    sender.account AS sender_account, profile.nickname AS sender_nickname,
                    COALESCE(viewer_friend.remark, '') AS sender_remark,
                    COALESCE(NULLIF(viewer_friend.remark, ''), NULLIF(profile.nickname, ''), sender.account,
                      CASE message.sender_type WHEN 'admin' THEN '管理员' WHEN 'platform' THEN '平台' ELSE '群成员' END) AS sender_name,
                    COALESCE(profile.avatar, '') AS sender_avatar, COALESCE(state.is_favorite, 0) AS is_favorite,
                    member.role
             FROM chat_room_messages message
             LEFT JOIN users sender ON sender.id = message.user_id
             LEFT JOIN user_profiles profile ON profile.user_id = sender.id
             LEFT JOIN friends viewer_friend ON viewer_friend.app_id = message.app_id AND viewer_friend.user_id = ?
               AND viewer_friend.friend_user_id = sender.id AND viewer_friend.status = 1
             LEFT JOIN chat_room_members member ON member.room_id = message.room_id AND member.user_id = message.user_id
             LEFT JOIN communication_message_states state ON state.scope_type = 'group'
               AND state.message_id = message.id AND state.user_id = ?
             WHERE message.id IN ({$placeholders}) ORDER BY message.id ASC",
            array_merge([(int) $user['id'], (int) $user['id']], $ids)
        );
        $items = ContentTagService::hydrate($items);
        $items = MessageMediaService::hydrate($items, 'group_message', (int) $user['app_id']);
        $items = MessageForwardService::hydrate($items, 'group_message', (int) $user['app_id']);
        $items = MessagePresentationService::hydrate($items, 'group', (int) $user['id']);
        return ['target_id' => $roomId, 'match_count' => count($matchIds), 'items' => self::markMatches($items, $matchIds, $keyword)];
    }

    private static function serviceSearch(array $user, int $sessionId, string $keyword, int $contextSize, int $limit): array
    {
        $session = $sessionId > 0
            ? Database::one('SELECT * FROM service_sessions WHERE id = ? AND app_id = ? AND user_id = ?', [$sessionId, (int) $user['app_id'], (int) $user['id']])
            : Database::one('SELECT * FROM service_sessions WHERE app_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1', [(int) $user['app_id'], (int) $user['id']]);
        if ($session === null) return ['target_id' => 0, 'match_count' => 0, 'items' => []];
        $sessionId = (int) $session['id'];
        $matches = Database::all(
            "SELECT message.id FROM service_messages message
             LEFT JOIN communication_message_states state ON state.scope_type = 'service'
               AND state.message_id = message.id AND state.user_id = ?
             WHERE message.session_id = ? AND COALESCE(state.is_deleted, 0) = 0
               AND (message.content LIKE ? OR EXISTS (SELECT 1 FROM media_attachments media
                    WHERE media.app_id = ? AND media.target_type = ? AND media.target_id = message.id
                      AND (media.file_name LIKE ? OR media.mime_type LIKE ? OR media.media_type LIKE ?))
                    OR EXISTS (SELECT 1 FROM message_forward_links forward_link
                               INNER JOIN message_forward_bundles forward_bundle ON forward_bundle.id = forward_link.bundle_id
                               WHERE forward_link.app_id = ? AND forward_link.target_type = ?
                                 AND forward_link.target_id = message.id AND forward_bundle.snapshot_json LIKE ?))
             ORDER BY message.id DESC LIMIT {$limit}",
            [
                (int) $user['id'], $sessionId, '%' . $keyword . '%', (int) $user['app_id'], 'service_message',
                '%' . $keyword . '%', '%' . $keyword . '%', '%' . $keyword . '%',
                (int) $user['app_id'], 'service_message', '%' . $keyword . '%',
            ]
        );
        $matchIds = array_map(static fn(array $row): int => (int) $row['id'], $matches);
        $ids = self::contextIds('service_messages', 'session_id', $sessionId, $matchIds, $contextSize,
            "NOT EXISTS (SELECT 1 FROM communication_message_states local_state WHERE local_state.scope_type = 'service' AND local_state.message_id = service_messages.id AND local_state.user_id = " . (int) $user['id'] . ' AND local_state.is_deleted = 1)');
        if ($ids === []) return ['target_id' => $sessionId, 'match_count' => 0, 'items' => []];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $items = Database::all(
            "SELECT message.*, COALESCE(state.is_favorite, 0) AS is_favorite,
                    CASE WHEN message.sender_type = 'user' THEN COALESCE(NULLIF(profile.nickname, ''), sender_user.account, '用户')
                         WHEN message.sender_type = 'admin' THEN COALESCE(NULLIF(sender_admin.nickname, ''), sender_admin.account, '客服')
                         ELSE '在线客服' END AS sender_name,
                    CASE WHEN message.sender_type = 'user' THEN COALESCE(profile.avatar, '')
                         WHEN message.sender_type = 'admin' THEN COALESCE(sender_admin.avatar, '') ELSE '' END AS sender_avatar
             FROM service_messages message
             LEFT JOIN communication_message_states state ON state.scope_type = 'service'
               AND state.message_id = message.id AND state.user_id = ?
             LEFT JOIN users sender_user ON message.sender_type = 'user' AND sender_user.id = message.sender_id
             LEFT JOIN user_profiles profile ON profile.user_id = sender_user.id
             LEFT JOIN admins sender_admin ON message.sender_type = 'admin' AND sender_admin.id = message.sender_id
             WHERE message.id IN ({$placeholders}) ORDER BY message.id ASC",
            array_merge([(int) $user['id']], $ids)
        );
        $items = MessageMediaService::hydrate($items, 'service_message', (int) $user['app_id']);
        $items = MessageForwardService::hydrate($items, 'service_message', (int) $user['app_id']);
        $items = MessagePresentationService::hydrate($items, 'service');
        return ['target_id' => $sessionId, 'match_count' => count($matchIds), 'items' => self::markMatches($items, $matchIds, $keyword)];
    }

    private static function contextIds(string $table, string $scopeColumn, int $scopeId, array $matchIds, int $size, string $extra): array
    {
        $ids = [];
        foreach ($matchIds as $matchId) {
            $before = Database::all(
                "SELECT id FROM {$table} WHERE {$scopeColumn} = ? AND {$extra} AND id <= ? ORDER BY id DESC LIMIT " . ($size + 1),
                [$scopeId, $matchId]
            );
            $after = Database::all(
                "SELECT id FROM {$table} WHERE {$scopeColumn} = ? AND {$extra} AND id > ? ORDER BY id ASC LIMIT {$size}",
                [$scopeId, $matchId]
            );
            foreach (array_merge($before, $after) as $row) $ids[(int) $row['id']] = true;
        }
        $result = array_keys($ids);
        sort($result, SORT_NUMERIC);
        return $result;
    }

    private static function markMatches(array $items, array $matchIds, string $keyword = ''): array
    {
        $matches = array_fill_keys($matchIds, true);
        foreach ($items as &$item) {
            $item['is_search_match'] = isset($matches[(int) ($item['id'] ?? 0)]);
            $item['search_context'] = !$item['is_search_match'];
            $fields = [];
            if ($item['is_search_match'] && $keyword !== '') {
                if (mb_stripos((string) ($item['content'] ?? ''), $keyword) !== false) $fields[] = '正文';
                foreach ((array) ($item['tags'] ?? []) as $tag) {
                    if (mb_stripos((string) $tag, $keyword) !== false) { $fields[] = '标签'; break; }
                }
                foreach ((array) ($item['attachments'] ?? []) as $attachment) {
                    if (!is_array($attachment)) continue;
                    $fileText = implode(' ', [
                        (string) ($attachment['file_name'] ?? ''), (string) ($attachment['mime_type'] ?? ''),
                        (string) ($attachment['media_type'] ?? ''),
                    ]);
                    if (mb_stripos($fileText, $keyword) !== false) { $fields[] = '文件'; break; }
                }
                if ($fields === [] && (int) ($item['forward_bundle_id'] ?? 0) > 0) $fields[] = '聊天快照';
            }
            $item['search_match_fields'] = array_values(array_unique($fields));
        }
        unset($item);
        return $items;
    }

    private static function remember(array $user, string $scope, int $targetId, array $filters): void
    {
        $keyword = (string) ($filters['keyword'] ?? '');
        $contentFilter = (string) ($filters['content_filter'] ?? 'all');
        $filterJson = json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $filterHash = hash('sha256', (string) $filterJson);
        Database::execute(
            'INSERT INTO chat_search_histories
             (admin_id, app_id, user_id, scope_type, target_id, keyword, content_filter, filter_json,
              filter_hash, search_count, created_at, last_searched_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE keyword = VALUES(keyword), content_filter = VALUES(content_filter),
               filter_json = VALUES(filter_json), search_count = search_count + 1, last_searched_at = NOW()',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $scope, $targetId,
                $keyword, $contentFilter, $filterJson, $filterHash,
            ]
        );
    }
}
