<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use DateTimeImmutable;
use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class ChatRecordService
{
    private const FILTERS = [
        'all', 'date', 'media', 'image_video', 'image', 'video', 'sticker', 'file', 'tag',
        'document', 'online_document', 'link', 'audio', 'favorite', 'moment_share', 'transfer', 'red_packet', 'gift', 'card', 'location',
    ];

    public static function records(array $user, string $scope, int $targetId, array $filters = [], int $limit = 500): array
    {
        $scope = strtolower(trim($scope));
        if (!in_array($scope, ['private', 'group', 'service'], true)) {
            throw new HttpException('scope_type 仅支持 private、group 或 service', 0, 422);
        }
        $filters = self::normalizeFilters($filters);
        $limit = max(1, min(5000, $limit));
        return match ($scope) {
            'private' => self::privateRecords($user, $targetId, $filters, $limit),
            'group' => self::groupRecords($user, $targetId, $filters, $limit),
            default => self::serviceRecords($user, $targetId, $filters, $limit),
        };
    }

    public static function cleanup(array $user, string $scope, int $targetId, array $filters): array
    {
        $items = self::records($user, $scope, $targetId, $filters, 5000);
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(array $item): int => (int) ($item['id'] ?? 0),
            $items
        ))));
        if ($ids === []) return ['matched_count' => 0, 'hidden_count' => 0, 'scope_type' => $scope, 'target_id' => $targetId];
        $hidden = Database::transaction(static function () use ($user, $scope, $targetId, $ids): int {
            $count = 0;
            foreach ($ids as $messageId) {
                if ($scope === 'private') {
                    Database::execute(
                        'INSERT INTO message_user_states (message_id, user_id, is_deleted, is_favorite, created_at, updated_at)
                         VALUES (?, ?, 1, 0, NOW(), NOW())
                         ON DUPLICATE KEY UPDATE is_deleted = 1, updated_at = NOW()',
                        [$messageId, (int) $user['id']]
                    );
                } else {
                    Database::execute(
                        'INSERT INTO communication_message_states
                         (admin_id, app_id, user_id, scope_type, target_id, message_id, is_deleted, is_favorite, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW())
                         ON DUPLICATE KEY UPDATE is_deleted = 1, updated_at = NOW()',
                        [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $scope, $targetId, $messageId]
                    );
                }
                $count++;
            }
            return $count;
        });
        return [
            'matched_count' => count($ids), 'hidden_count' => $hidden,
            'scope_type' => $scope, 'target_id' => $targetId,
            'operation' => '仅从当前账号显示中清理，未撤回也未删除服务器原消息',
        ];
    }

    public static function normalizeFilters(array $filters): array
    {
        $contentFilter = strtolower(trim((string) ($filters['content_filter'] ?? 'all')));
        if (!in_array($contentFilter, self::FILTERS, true)) {
            throw new HttpException('content_filter 不受支持', 0, 422, ['allowed' => self::FILTERS]);
        }
        if ($contentFilter === 'image_video') $contentFilter = 'media';
        if ($contentFilter === 'online_document') $contentFilter = 'document';
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if (mb_strlen($keyword) > 100) throw new HttpException('搜索关键词不能超过 100 个字符', 0, 422);
        $messageIds = $filters['message_ids'] ?? [];
        if (is_string($messageIds)) $messageIds = array_filter(array_map('trim', explode(',', $messageIds)));
        if (!is_array($messageIds)) throw new HttpException('message_ids 必须是数组或逗号分隔编号', 0, 422);
        $messageIds = array_values(array_unique(array_filter(array_map('intval', $messageIds), static fn(int $id): bool => $id > 0)));
        if (count($messageIds) > 5000) throw new HttpException('单次最多选择 5000 条聊天记录', 0, 422);
        return [
            'keyword' => $keyword,
            'content_filter' => $contentFilter,
            'date_from' => self::date((string) ($filters['date_from'] ?? ''), false),
            'date_to' => self::date((string) ($filters['date_to'] ?? ''), true),
            'sender_id' => max(0, (int) ($filters['sender_id'] ?? 0)),
            'message_ids' => $messageIds,
        ];
    }

    private static function privateRecords(array $user, int $conversationId, array $filters, int $limit): array
    {
        $conversation = Database::one(
            'SELECT * FROM conversations WHERE id = ? AND admin_id = ? AND app_id = ? AND (user_a_id = ? OR user_b_id = ?)',
            [$conversationId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], (int) $user['id']]
        );
        if ($conversation === null) throw new HttpException('会话不存在或无权查看', 404, 404);
        [$where, $query] = self::conditions($filters, 'm', 'private_message', "CASE WHEN m.sender_type = 'user' THEN m.sender_id ELSE 0 END", '(m.content LIKE ? OR u.account LIKE ? OR p.nickname LIKE ?)');
        array_unshift($where, 'm.conversation_id = ?', 'm.status = 1', 'COALESCE(s.is_deleted, 0) = 0');
        array_unshift($query, $conversationId);
        $items = Database::all(
            "SELECT m.id, m.sender_type, m.sender_id, m.receiver_user_id, m.title, m.content_type, m.tags_json,
                    CASE WHEN recall.id IS NULL THEN m.content ELSE COALESCE(NULLIF(recall.notice_text, ''), '[消息已撤回]') END AS content,
                    m.created_at, COALESCE(s.is_favorite, 0) AS is_favorite,
                    (recall.id IS NOT NULL) AS recalled, recall.reason AS recall_reason, recall.notice_text AS recall_notice,
                    COALESCE(NULLIF(p.nickname, ''), u.account,
                      CASE m.sender_type WHEN 'admin' THEN '管理员' WHEN 'platform' THEN '平台' ELSE '用户' END) AS sender_name,
                    COALESCE(p.avatar, '') AS sender_avatar
             FROM messages m
             LEFT JOIN message_user_states s ON s.message_id = m.id AND s.user_id = ?
             LEFT JOIN message_recalls recall ON recall.message_id = m.id
             LEFT JOIN users u ON u.id = CASE WHEN m.sender_type = 'user' THEN m.sender_id ELSE NULL END
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE " . implode(' AND ', $where) . " ORDER BY m.id ASC LIMIT {$limit}",
            array_merge([(int) $user['id']], $query)
        );
        $items = ContentTagService::hydrate($items);
        $items = MessageMediaService::hydrate($items, 'private_message', (int) $user['app_id']);
        $items = MessageForwardService::hydrate($items, 'private_message', (int) $user['app_id']);
        return MessagePresentationService::hydrate($items, 'private');
    }

    private static function groupRecords(array $user, int $roomId, array $filters, int $limit): array
    {
        $room = ChatRoomService::userRoom($user, $roomId, true);
        ChatRoomService::requireMember($user, $room);
        [$where, $query] = self::conditions($filters, 'm', 'group_message', 'COALESCE(m.user_id, m.sender_admin_id, 0)', '(m.content LIKE ? OR u.account LIKE ? OR p.nickname LIKE ?)');
        array_unshift($where, 'm.room_id = ?', 'm.status = 1', 'COALESCE(s.is_deleted, 0) = 0');
        array_unshift($query, $roomId);
        $items = Database::all(
            "SELECT m.id, m.user_id, m.sender_type, m.sender_admin_id,
                    COALESCE(m.user_id, m.sender_admin_id, 0) AS sender_id,
                    m.content_type, m.content, m.tags_json, m.reply_to_message_id, m.created_at,
                    COALESCE(NULLIF(p.nickname, ''), u.account,
                      CASE m.sender_type WHEN 'admin' THEN '管理员' WHEN 'platform' THEN '平台' ELSE '群成员' END) AS sender_name,
                    COALESCE(p.avatar, '') AS sender_avatar, COALESCE(s.is_favorite, 0) AS is_favorite,
                    member.role
             FROM chat_room_messages m
             LEFT JOIN users u ON u.id = m.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN chat_room_members member ON member.room_id = m.room_id AND member.user_id = m.user_id
             LEFT JOIN communication_message_states s ON s.scope_type = 'group' AND s.message_id = m.id AND s.user_id = ?
             WHERE " . implode(' AND ', $where) . " ORDER BY m.id ASC LIMIT {$limit}",
            array_merge([(int) $user['id']], $query)
        );
        $items = ContentTagService::hydrate($items);
        $items = MessageMediaService::hydrate($items, 'group_message', (int) $user['app_id']);
        $items = MessageForwardService::hydrate($items, 'group_message', (int) $user['app_id']);
        return MessagePresentationService::hydrate($items, 'group');
    }

    private static function serviceRecords(array $user, int $sessionId, array $filters, int $limit): array
    {
        $session = $sessionId > 0
            ? Database::one('SELECT * FROM service_sessions WHERE id = ? AND app_id = ? AND user_id = ?', [$sessionId, (int) $user['app_id'], (int) $user['id']])
            : Database::one('SELECT * FROM service_sessions WHERE app_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1', [(int) $user['app_id'], (int) $user['id']]);
        if ($session === null) return [];
        $sessionId = (int) $session['id'];
        [$where, $query] = self::conditions($filters, 'm', 'service_message', "CASE WHEN m.sender_type = 'user' THEN m.sender_id ELSE 0 END", '(m.content LIKE ? OR sender_user.account LIKE ? OR profile.nickname LIKE ?)', false);
        array_unshift($where, 'm.session_id = ?', 'COALESCE(s.is_deleted, 0) = 0');
        array_unshift($query, $sessionId);
        $items = Database::all(
            "SELECT m.*, COALESCE(s.is_favorite, 0) AS is_favorite,
                    CASE WHEN m.sender_type = 'user' THEN COALESCE(NULLIF(profile.nickname, ''), sender_user.account, '用户')
                         WHEN m.sender_type = 'admin' THEN COALESCE(NULLIF(sender_admin.nickname, ''), sender_admin.account, '客服')
                         ELSE '在线客服' END AS sender_name,
                    CASE WHEN m.sender_type = 'user' THEN COALESCE(profile.avatar, '')
                         WHEN m.sender_type = 'admin' THEN COALESCE(sender_admin.avatar, '') ELSE '' END AS sender_avatar
             FROM service_messages m
             LEFT JOIN communication_message_states s ON s.scope_type = 'service' AND s.message_id = m.id AND s.user_id = ?
             LEFT JOIN users sender_user ON m.sender_type = 'user' AND sender_user.id = m.sender_id
             LEFT JOIN user_profiles profile ON profile.user_id = sender_user.id
             LEFT JOIN admins sender_admin ON m.sender_type = 'admin' AND sender_admin.id = m.sender_id
             WHERE " . implode(' AND ', $where) . " ORDER BY m.id ASC LIMIT {$limit}",
            array_merge([(int) $user['id']], $query)
        );
        $items = MessageMediaService::hydrate($items, 'service_message', (int) $user['app_id']);
        $items = MessageForwardService::hydrate($items, 'service_message', (int) $user['app_id']);
        return MessagePresentationService::hydrate($items, 'service');
    }

    private static function conditions(array $filters, string $alias, string $targetType, string $senderExpression, string $keywordSql, bool $hasContentType = true): array
    {
        $where = [];
        $query = [];
        if ($filters['keyword'] !== '') {
            $like = '%' . $filters['keyword'] . '%';
            $keywordParts = [$keywordSql];
            array_push($query, $like, $like, $like);
            if ($hasContentType) {
                $keywordParts[] = "{$alias}.tags_json LIKE ?";
                $query[] = $like;
            }
            $keywordParts[] = "EXISTS (SELECT 1 FROM media_attachments search_media
                               WHERE search_media.app_id = {$alias}.app_id
                                 AND search_media.target_type = '{$targetType}'
                                 AND search_media.target_id = {$alias}.id
                                 AND (search_media.file_name LIKE ? OR search_media.mime_type LIKE ?
                                      OR search_media.media_type LIKE ? OR search_media.metadata_json LIKE ?))";
            array_push($query, $like, $like, $like, $like);
            $keywordParts[] = "EXISTS (SELECT 1 FROM message_forward_links search_link
                               INNER JOIN message_forward_bundles search_bundle ON search_bundle.id = search_link.bundle_id
                               WHERE search_link.app_id = {$alias}.app_id
                                 AND search_link.target_type = '{$targetType}'
                                 AND search_link.target_id = {$alias}.id
                                 AND search_bundle.snapshot_json LIKE ?)";
            $query[] = $like;
            $where[] = '(' . implode(' OR ', $keywordParts) . ')';
        }
        if ($filters['date_from'] !== null) { $where[] = "{$alias}.created_at >= ?"; $query[] = $filters['date_from']; }
        if ($filters['date_to'] !== null) { $where[] = "{$alias}.created_at <= ?"; $query[] = $filters['date_to']; }
        if ($filters['sender_id'] > 0) { $where[] = "{$senderExpression} = ?"; $query[] = $filters['sender_id']; }
        if ($filters['message_ids'] !== []) {
            $where[] = "{$alias}.id IN (" . implode(',', array_fill(0, count($filters['message_ids']), '?')) . ')';
            array_push($query, ...$filters['message_ids']);
        }
        $attachment = "EXISTS (SELECT 1 FROM media_attachments media WHERE media.app_id = {$alias}.app_id AND media.target_type = '{$targetType}' AND media.target_id = {$alias}.id";
        $forwardSnapshot = "EXISTS (SELECT 1 FROM message_forward_links filter_link
                            INNER JOIN message_forward_bundles filter_bundle ON filter_bundle.id = filter_link.bundle_id
                            WHERE filter_link.app_id = {$alias}.app_id
                              AND filter_link.target_type = '{$targetType}'
                              AND filter_link.target_id = {$alias}.id";
        $contentType = $hasContentType ? "{$alias}.content_type" : "''";
        $where[] = match ($filters['content_filter']) {
            'media' => $attachment . " AND media.media_type IN ('image','video'))",
            'image' => $attachment . " AND media.media_type = 'image')",
            'video' => $attachment . " AND media.media_type = 'video')",
            'sticker' => "({$contentType} = 'sticker' OR " . $attachment . ' AND (media.media_type = \'sticker\' OR media.sticker_id IS NOT NULL)))',
            'file' => '(' . $attachment . " AND media.media_type = 'file') OR "
                . $forwardSnapshot . " AND filter_bundle.snapshot_json LIKE '%\"file_name\"%'))",
            'tag' => $hasContentType
                ? "(({$alias}.tags_json IS NOT NULL AND {$alias}.tags_json <> '' AND {$alias}.tags_json <> '[]') OR "
                    . $forwardSnapshot . " AND filter_bundle.snapshot_json LIKE '%\"tags\"%'))"
                : '(' . $attachment . " AND media.metadata_json LIKE '%\"tags\"%') OR "
                    . $forwardSnapshot . " AND filter_bundle.snapshot_json LIKE '%\"tags\"%'))",
            'audio' => $attachment . " AND media.media_type = 'audio')",
            'document' => "{$contentType} IN ('document','online_document')",
            'link' => "({$alias}.content LIKE '%http://%' OR {$alias}.content LIKE '%https://%')",
            'favorite' => 'COALESCE(s.is_favorite, 0) = 1',
            'moment_share' => $attachment . " AND media.media_type = 'moment_share')",
            'transfer' => $attachment . " AND media.media_type = 'transfer')",
            'red_packet' => $attachment . " AND media.media_type = 'red_packet')",
            'gift' => $attachment . " AND media.media_type = 'gift')",
            'card' => $attachment . " AND media.media_type = 'contact_card')",
            'location' => $attachment . " AND media.media_type = 'location')",
            default => '1 = 1',
        };
        return [$where, $query];
    }

    private static function date(string $value, bool $endOfDay): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Throwable) {
            throw new HttpException('日期格式无效，请使用 YYYY-MM-DD 或 YYYY-MM-DD HH:mm:ss', 0, 422);
        }
        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) $date = $date->setTime(23, 59, 59);
        return $date->format('Y-m-d H:i:s');
    }
}
