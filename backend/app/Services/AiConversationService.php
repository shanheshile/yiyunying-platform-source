<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;

final class AiConversationService
{
    public static function index(array $user, Request $request): array
    {
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $query = trim((string) $request->input('q', ''));
        $where = ['c.admin_id = ?', 'c.app_id = ?', 'c.user_id = ?'];
        $params = [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']];
        if ($query !== '') {
            $where[] = '(c.title LIKE ? OR EXISTS (SELECT 1 FROM ai_messages search_message WHERE search_message.conversation_id = c.id AND search_message.content LIKE ?))';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($query, 0, 100)) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM ai_conversations c WHERE {$whereSql}",
            $params
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT c.id, c.title, c.created_at, c.updated_at,
                    (SELECT COUNT(*) FROM ai_messages message_count WHERE message_count.conversation_id = c.id) AS message_count,
                    (SELECT content FROM ai_messages last_message WHERE last_message.conversation_id = c.id ORDER BY last_message.id DESC LIMIT 1) AS last_message
             FROM ai_conversations c WHERE {$whereSql}
             ORDER BY c.updated_at DESC, c.id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        foreach ($items as &$item) {
            $item['message_count'] = (int) ($item['message_count'] ?? 0);
            $item['last_message'] = mb_substr((string) ($item['last_message'] ?? ''), 0, 200);
        }
        unset($item);
        return Pagination::data($items, $total, $page, $limit);
    }

    public static function messages(array $user, int $conversationId, Request $request): array
    {
        $conversation = self::owned($user, $conversationId);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM ai_messages WHERE conversation_id = ?',
            [$conversationId]
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT id, role, content, provider, model, metadata_json, created_at
             FROM ai_messages WHERE conversation_id = ? ORDER BY id ASC LIMIT {$limit} OFFSET {$offset}",
            [$conversationId]
        );
        foreach ($items as &$item) {
            $metadata = json_decode((string) ($item['metadata_json'] ?? ''), true);
            $item['metadata'] = is_array($metadata) ? $metadata : [];
            unset($item['metadata_json']);
        }
        unset($item);
        return array_merge(['conversation' => $conversation], Pagination::data($items, $total, $page, $limit));
    }

    public static function delete(array $user, int $conversationId): array
    {
        $conversation = self::owned($user, $conversationId);
        Database::execute('DELETE FROM ai_conversations WHERE id = ?', [$conversationId]);
        return $conversation;
    }

    private static function owned(array $user, int $conversationId): array
    {
        $conversation = Database::one(
            'SELECT id, title, created_at, updated_at FROM ai_conversations
             WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ?',
            [$conversationId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        if ($conversation === null) throw new HttpException('AI 会话不存在或不属于当前账号', 404, 404);
        return $conversation;
    }
}
