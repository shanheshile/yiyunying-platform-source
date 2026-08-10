<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;

final class ForumCommentNotificationService
{
    public static function notifyParticipants(int $adminId, int $appId, array $comment): void
    {
        $commentId = (int) ($comment['id'] ?? 0);
        $postId = (int) ($comment['post_id'] ?? 0);
        $authorId = (int) ($comment['user_id'] ?? 0);
        if ($commentId <= 0 || $postId <= 0 || $authorId <= 0) return;

        $post = Database::one(
            'SELECT id, user_id, title FROM forum_posts WHERE id = ? AND admin_id = ? AND app_id = ?',
            [$postId, $adminId, $appId]
        );
        if ($post === null) return;

        $author = Database::one(
            'SELECT u.account, p.nickname FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE u.id = ? AND u.admin_id = ? AND u.app_id = ?',
            [$authorId, $adminId, $appId]
        );
        $senderName = trim((string) ($author['nickname'] ?? ''));
        if ($senderName === '') $senderName = trim((string) ($author['account'] ?? ''));
        if ($senderName === '') $senderName = '用户';

        $commentSummary = trim((string) ($comment['content'] ?? ''));
        if ($commentSummary === '') {
            $commentSummary = MessageMediaService::summary(
                MessageMediaService::attachments('forum_comment', $commentId, $appId)
            );
        }
        if ($commentSummary === '') $commentSummary = '[评论]';
        $commentSummary = mb_substr($commentSummary, 0, 180);

        $parentId = max(0, (int) ($comment['parent_id'] ?? 0));
        $receiverId = (int) $post['user_id'];
        $parentSummary = '';
        if ($parentId > 0) {
            $parent = Database::one(
                'SELECT id, user_id, content FROM forum_comments
                 WHERE id = ? AND post_id = ? AND admin_id = ? AND app_id = ?',
                [$parentId, $postId, $adminId, $appId]
            );
            if ($parent !== null) {
                $receiverId = (int) $parent['user_id'];
                $parentSummary = trim((string) ($parent['content'] ?? ''));
                if ($parentSummary === '') {
                    $parentSummary = MessageMediaService::summary(
                        MessageMediaService::attachments('forum_comment', $parentId, $appId)
                    );
                }
                $parentSummary = mb_substr($parentSummary, 0, 180);
            }
        }

        $payload = [
            'post_id' => $postId,
            'post_title' => (string) $post['title'],
            'comment_id' => $commentId,
            'comment_content' => $commentSummary,
            'parent_comment_id' => $parentId > 0 ? $parentId : null,
            'parent_comment_content' => $parentSummary,
            'actor_user_id' => $authorId,
            'actor_name' => $senderName,
            'focus' => 'comment',
            'location_hint' => '《' . (string) $post['title'] . '》评论区 · '
                . ($parentId > 0 ? '这条回复' : '这条评论'),
        ];

        if ($receiverId > 0 && $receiverId !== $authorId) {
            $receiver = NotificationService::user($adminId, $appId, $receiverId);
            if ($receiver !== null) {
                NotificationService::send(
                    $receiver,
                    $parentId > 0 ? 'forum_reply' : 'forum_comment',
                    $parentId > 0 ? '论坛评论收到回复' : '帖子收到新评论',
                    $senderName . ($parentId > 0 ? ' 回复你：' : ' 评论：') . $commentSummary,
                    $payload
                );
            }
        }

        foreach (self::mentionIds($comment['mentions_json'] ?? null) as $mentionedId) {
            if ($mentionedId === $authorId || $mentionedId === $receiverId) continue;
            $mentioned = NotificationService::user($adminId, $appId, $mentionedId);
            if ($mentioned === null) continue;
            NotificationService::send(
                $mentioned,
                'forum_mention',
                '论坛中有人提到你',
                $senderName . ' 在《' . (string) $post['title'] . '》中提到了你',
                $payload + [
                    'sender_user_id' => $authorId,
                    'sender_name' => $senderName,
                ]
            );
        }
    }

    public static function encodeMentions($value, int $authorId): string
    {
        if (!is_array($value)) return '[]';
        $ids = [];
        foreach ($value as $candidate) {
            $id = (int) $candidate;
            if ($id > 0 && $id !== $authorId) $ids[$id] = $id;
        }
        return json_encode(array_values($ids), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private static function mentionIds($value): array
    {
        if (is_string($value)) $value = json_decode($value, true);
        if (!is_array($value)) return [];
        $ids = [];
        foreach ($value as $candidate) {
            $id = (int) $candidate;
            if ($id > 0) $ids[$id] = $id;
        }
        return array_values($ids);
    }
}
