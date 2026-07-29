<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;

final class NotificationService
{
    public static function send(array $user, string $type, string $title, string $content, ?array $data = null): int
    {
        return Database::insert(
            'INSERT INTO user_notifications
             (admin_id, app_id, user_id, notification_type, title, content, data_json, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
                mb_substr($type, 0, 40), mb_substr($title, 0, 200), $content,
                $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    public static function user(int $adminId, int $appId, int $userId): ?array
    {
        return Database::one(
            'SELECT * FROM users WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
            [$userId, $adminId, $appId]
        );
    }
}
