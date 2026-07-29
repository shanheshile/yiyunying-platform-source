<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class MessageEditService
{
    public static function hydrate(array $items, string $channelType, int $appId): array
    {
        if (!in_array($channelType, ['private', 'group'], true) || $items === []) return $items;
        $ids = [];
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id > 0) $ids[$id] = true;
        }
        if ($ids === []) return $items;

        $values = array_keys($ids);
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $rows = Database::all(
            "SELECT message_id, COUNT(*) AS edit_count, MAX(created_at) AS edited_at
             FROM message_edit_histories
             WHERE app_id = ? AND channel_type = ? AND message_id IN ({$placeholders})
             GROUP BY message_id",
            array_merge([$appId, $channelType], $values)
        );
        $edits = [];
        foreach ($rows as $row) {
            $edits[(int) $row['message_id']] = [
                'edit_count' => (int) $row['edit_count'],
                'edited_at' => (string) $row['edited_at'],
            ];
        }
        foreach ($items as &$item) {
            $edit = $edits[(int) ($item['id'] ?? 0)] ?? null;
            $item['edited'] = $edit !== null;
            $item['edit_count'] = (int) ($edit['edit_count'] ?? 0);
            $item['edited_at'] = $edit['edited_at'] ?? null;
        }
        unset($item);
        return $items;
    }

    public static function editPrivate(array $user, int $messageId, string $content): array
    {
        $content = self::content($content);
        return Database::transaction(static function () use ($user, $messageId, $content): array {
            $message = Database::one(
                "SELECT m.*, c.user_a_id, c.user_b_id
                 FROM messages m INNER JOIN conversations c ON c.id = m.conversation_id
                 LEFT JOIN message_recalls recall ON recall.message_id = m.id
                 WHERE m.id = ? AND m.app_id = ? AND m.admin_id = ? AND m.status = 1
                   AND m.sender_type = 'user' AND m.sender_id = ?
                   AND (c.user_a_id = ? OR c.user_b_id = ?) AND recall.id IS NULL
                 FOR UPDATE",
                [$messageId, (int) $user['app_id'], (int) $user['admin_id'], (int) $user['id'],
                 (int) $user['id'], (int) $user['id']]
            );
            self::assertEditable($message);
            return self::save($user, 'private', (int) $message['conversation_id'], $message, $content, 'messages');
        });
    }

    public static function editGroup(array $user, int $roomId, int $messageId, string $content): array
    {
        $content = self::content($content);
        return Database::transaction(static function () use ($user, $roomId, $messageId, $content): array {
            $member = Database::one(
                'SELECT id FROM chat_room_members WHERE room_id = ? AND user_id = ? AND app_id = ?',
                [$roomId, (int) $user['id'], (int) $user['app_id']]
            );
            if ($member === null) throw new HttpException('你不是当前群聊成员', 403, 403);
            $message = Database::one(
                "SELECT * FROM chat_room_messages
                 WHERE id = ? AND room_id = ? AND app_id = ? AND admin_id = ? AND status = 1
                   AND sender_type = 'user' AND user_id = ? FOR UPDATE",
                [$messageId, $roomId, (int) $user['app_id'], (int) $user['admin_id'], (int) $user['id']]
            );
            self::assertEditable($message);
            return self::save($user, 'group', $roomId, $message, $content, 'chat_room_messages');
        });
    }

    public static function privateHistory(array $user, int $messageId): array
    {
        $message = Database::one(
            'SELECT m.id, m.content, m.conversation_id FROM messages m
             INNER JOIN conversations c ON c.id = m.conversation_id
             WHERE m.id = ? AND m.app_id = ? AND (c.user_a_id = ? OR c.user_b_id = ?)',
            [$messageId, (int) $user['app_id'], (int) $user['id'], (int) $user['id']]
        );
        if ($message === null) throw new HttpException('消息不存在或无权查看编辑记录', 404, 404);
        return self::history($user, 'private', $messageId, (string) $message['content']);
    }

    public static function groupHistory(array $user, int $roomId, int $messageId): array
    {
        $message = Database::one(
            'SELECT m.id, m.content FROM chat_room_messages m
             INNER JOIN chat_room_members member ON member.room_id = m.room_id AND member.user_id = ?
             WHERE m.id = ? AND m.room_id = ? AND m.app_id = ?',
            [(int) $user['id'], $messageId, $roomId, (int) $user['app_id']]
        );
        if ($message === null) throw new HttpException('群消息不存在或无权查看编辑记录', 404, 404);
        return self::history($user, 'group', $messageId, (string) $message['content']);
    }

    private static function save(array $user, string $channelType, int $channelId, array $message, string $content, string $table): array
    {
        $old = (string) ($message['content'] ?? '');
        if ($old === $content) throw new HttpException('内容没有发生变化', 0, 422);
        Database::insert(
            'INSERT INTO message_edit_histories
             (admin_id, app_id, channel_type, channel_id, message_id, editor_user_id,
              old_content, new_content, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [(int) $user['admin_id'], (int) $user['app_id'], $channelType, $channelId,
             (int) $message['id'], (int) $user['id'], $old, $content]
        );
        Database::execute("UPDATE {$table} SET content = ? WHERE id = ?", [$content, (int) $message['id']]);
        $count = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM message_edit_histories
             WHERE app_id = ? AND channel_type = ? AND message_id = ?',
            [(int) $user['app_id'], $channelType, (int) $message['id']]
        )['total'] ?? 0);
        return [
            'message_id' => (int) $message['id'],
            'channel_type' => $channelType,
            'content' => $content,
            'edited' => true,
            'edit_count' => $count,
            'edited_at' => date('Y-m-d H:i:s'),
        ];
    }

    private static function history(array $user, string $channelType, int $messageId, string $current): array
    {
        $items = Database::all(
            'SELECT h.id, h.old_content, h.new_content, h.created_at,
                    u.account AS editor_account, COALESCE(NULLIF(p.nickname, \'\'), u.account) AS editor_name
             FROM message_edit_histories h
             INNER JOIN users u ON u.id = h.editor_user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE h.app_id = ? AND h.channel_type = ? AND h.message_id = ?
             ORDER BY h.id ASC',
            [(int) $user['app_id'], $channelType, $messageId]
        );
        foreach ($items as &$item) $item['id'] = (int) $item['id'];
        unset($item);
        return [
            'message_id' => $messageId,
            'channel_type' => $channelType,
            'current_content' => $current,
            'edit_count' => count($items),
            'items' => $items,
        ];
    }

    private static function assertEditable(?array $message): void
    {
        if ($message === null) throw new HttpException('消息不存在、已撤回或不是你发送的消息', 404, 404);
        if ((string) ($message['content_type'] ?? '') !== 'text') {
            throw new HttpException('只有纯文字消息可以重新编辑', 0, 422);
        }
    }

    private static function content(string $content): string
    {
        $content = trim($content);
        if ($content === '') throw new HttpException('消息内容不能为空', 0, 422);
        if (mb_strlen($content) > 10000) throw new HttpException('消息正文不能超过 10000 个字符', 0, 422);
        return $content;
    }
}
