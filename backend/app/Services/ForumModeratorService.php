<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;

final class ForumModeratorService
{
    private const DEFAULT_PERMISSIONS = [
        'manage_posts' => true,
        'edit_posts' => true,
        'delete_posts' => true,
        'pin_posts' => true,
        'lock_posts' => true,
        'review_posts' => true,
        'manage_comments' => true,
    ];

    public static function canManage(array $user, int $plateId, string $permission = 'manage_posts'): bool
    {
        if ($plateId <= 0) return false;
        $row = Database::one(
            'SELECT permissions_json FROM forum_moderators
             WHERE app_id = ? AND plate_id = ? AND user_id = ? AND status = 1',
            [(int) $user['app_id'], $plateId, (int) $user['id']]
        );
        if ($row === null) return false;
        $permissions = json_decode((string) ($row['permissions_json'] ?? '{}'), true);
        if (!is_array($permissions) || $permissions === []) $permissions = self::DEFAULT_PERMISSIONS;
        return !empty($permissions['manage_posts']) || !empty($permissions[$permission]);
    }

    public static function grant(int $adminId, int $appId, int $plateId, int $userId, array $permissions = []): int
    {
        $permissions = self::normalizePermissions($permissions);
        $existing = Database::one(
            'SELECT id FROM forum_moderators WHERE app_id = ? AND plate_id = ? AND user_id = ?',
            [$appId, $plateId, $userId]
        );
        if ($existing !== null) {
            Database::execute(
                'UPDATE forum_moderators SET permissions_json = ?, status = 1, granted_by_admin_id = ?, updated_at = NOW() WHERE id = ?',
                [self::json($permissions), $adminId, (int) $existing['id']]
            );
            return (int) $existing['id'];
        }
        return Database::insert(
            'INSERT INTO forum_moderators
             (admin_id, app_id, plate_id, user_id, permissions_json, status, granted_by_admin_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, ?, NOW(), NOW())',
            [$adminId, $appId, $plateId, $userId, self::json($permissions), $adminId]
        );
    }

    public static function normalizePermissions(array $permissions): array
    {
        $result = self::DEFAULT_PERMISSIONS;
        foreach ($result as $name => $enabled) {
            if (array_key_exists($name, $permissions)) $result[$name] = (bool) $permissions[$name];
        }
        return $result;
    }

    public static function permissions(): array
    {
        return self::DEFAULT_PERMISSIONS;
    }

    private static function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
