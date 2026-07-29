<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class HierarchyActorService
{
    public static function platform(array $platform): array
    {
        $level = (int) $platform['level'];
        return [
            'actor_type' => 'platform',
            'actor_id' => (int) $platform['id'],
            'actor_level' => $level,
            'platform_id' => (int) $platform['id'],
            'root_platform_id' => $level === 1 ? (int) $platform['id'] : (int) $platform['parent_id'],
            'admin_id' => null,
            'app_id' => null,
            'name' => (string) ($platform['nickname'] ?: $platform['account']),
        ];
    }

    public static function admin(array $admin): array
    {
        $platform = self::platformRow((int) $admin['platform_id']);
        return [
            'actor_type' => 'admin',
            'actor_id' => (int) $admin['id'],
            'actor_level' => 3,
            'platform_id' => (int) $platform['id'],
            'root_platform_id' => (int) $platform['level'] === 1 ? (int) $platform['id'] : (int) $platform['parent_id'],
            'admin_id' => (int) $admin['id'],
            'app_id' => null,
            'name' => (string) ($admin['nickname'] ?: $admin['account']),
        ];
    }

    public static function user(array $user): array
    {
        $row = Database::one(
            'SELECT u.id, u.admin_id, u.app_id, u.account, p.nickname, a.platform_id,
                    pa.level AS platform_level, pa.parent_id
             FROM users u INNER JOIN admins a ON a.id = u.admin_id
             INNER JOIN platform_accounts pa ON pa.id = a.platform_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE u.id = ? AND u.admin_id = ? AND u.app_id = ? AND u.deleted_at IS NULL',
            [(int) $user['id'], (int) $user['admin_id'], (int) $user['app_id']]
        );
        if ($row === null) throw new HttpException('用户层级身份不存在', 404, 404);
        return [
            'actor_type' => 'user',
            'actor_id' => (int) $row['id'],
            'actor_level' => 4,
            'platform_id' => (int) $row['platform_id'],
            'root_platform_id' => (int) $row['platform_level'] === 1 ? (int) $row['platform_id'] : (int) $row['parent_id'],
            'admin_id' => (int) $row['admin_id'],
            'app_id' => (int) $row['app_id'],
            'name' => (string) (($row['nickname'] ?? '') ?: $row['account']),
        ];
    }

    public static function load(string $actorType, int $actorId): array
    {
        if ($actorType === 'platform') {
            $row = Database::one('SELECT * FROM platform_accounts WHERE id = ? AND deleted_at IS NULL', [$actorId]);
            if ($row === null) throw new HttpException('活动参与平台不存在', 404, 404);
            return self::platform($row);
        }
        if ($actorType === 'admin') {
            return self::admin(AdminAccessService::context($actorId));
        }
        if ($actorType === 'user') {
            $row = Database::one('SELECT id, admin_id, app_id FROM users WHERE id = ? AND deleted_at IS NULL', [$actorId]);
            if ($row === null) throw new HttpException('活动参与用户不存在', 404, 404);
            return self::user($row);
        }
        throw new HttpException('活动参与者类型无效', 0, 422);
    }

    public static function balance(array $actor, bool $lock = false): float
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        if ($actor['actor_type'] === 'platform') {
            $row = Database::one('SELECT integral AS balance FROM platform_accounts WHERE id = ?' . $suffix, [(int) $actor['actor_id']]);
        } elseif ($actor['actor_type'] === 'admin') {
            $row = Database::one('SELECT integral AS balance FROM admin_entitlements WHERE admin_id = ?' . $suffix, [(int) $actor['actor_id']]);
        } else {
            $row = Database::one('SELECT balance FROM user_wallets WHERE user_id = ?' . $suffix, [(int) $actor['actor_id']]);
        }
        if ($row === null) throw new HttpException('参与者余额账户不存在', -1, 500);
        return (float) $row['balance'];
    }

    public static function adjust(
        array $actor,
        float $change,
        string $scene,
        string $refType,
        ?int $refId,
        array $operator,
        string $remark = ''
    ): float {
        // L1 is the system owner and has an unlimited issuing balance. Activity
        // publication and grants must never consume or cap the owner's account.
        if ($actor['actor_type'] === 'platform' && (int) $actor['actor_level'] === 1) {
            return self::balance($actor, true);
        }
        if ($change == 0.0) return self::balance($actor, true);
        $before = self::balance($actor, true);
        $after = round($before + $change, $actor['actor_type'] === 'user' ? 2 : 0);
        if ($after < 0) {
            throw new HttpException('余额不足', 0, 422, ['balance' => $before, 'required' => abs($change)]);
        }
        if ($actor['actor_type'] === 'platform') {
            Database::execute('UPDATE platform_accounts SET integral = ?, updated_at = NOW() WHERE id = ?', [$after, (int) $actor['actor_id']]);
        } elseif ($actor['actor_type'] === 'admin') {
            Database::execute('UPDATE admin_entitlements SET integral = ?, updated_at = NOW() WHERE admin_id = ?', [$after, (int) $actor['actor_id']]);
        } else {
            Database::execute('UPDATE user_wallets SET balance = ?, updated_at = NOW() WHERE user_id = ?', [$after, (int) $actor['actor_id']]);
            Database::execute(
                'INSERT INTO user_wallet_logs
                 (admin_id, app_id, user_id, asset_type, change_value, before_value, after_value,
                  scene, ref_type, ref_id, remark, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    (int) $actor['admin_id'], (int) $actor['app_id'], (int) $actor['actor_id'], 'balance',
                    $change, $before, $after, $scene, $refType, $refId, mb_substr($remark, 0, 255),
                ]
            );
        }
        Database::execute(
            'INSERT INTO hierarchy_balance_logs
             (root_platform_id, actor_type, actor_id, actor_level, change_value, before_value,
              after_value, scene, ref_type, ref_id, operator_type, operator_id, remark, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                (int) $actor['root_platform_id'], $actor['actor_type'], (int) $actor['actor_id'],
                (int) $actor['actor_level'], $change, $before, $after, $scene, $refType, $refId,
                (string) $operator['actor_type'], (int) $operator['actor_id'], mb_substr($remark, 0, 255),
            ]
        );
        return $after;
    }

    public static function logs(array $actor, int $limit = 100): array
    {
        $limit = min(500, max(1, $limit));
        return Database::all(
            "SELECT id, change_value, before_value, after_value, scene, ref_type, ref_id,
                    operator_type, operator_id, remark, created_at
             FROM hierarchy_balance_logs WHERE actor_type = ? AND actor_id = ? ORDER BY id DESC LIMIT {$limit}",
            [$actor['actor_type'], (int) $actor['actor_id']]
        );
    }

    private static function platformRow(int $platformId): array
    {
        $row = Database::one('SELECT id, parent_id, level FROM platform_accounts WHERE id = ? AND deleted_at IS NULL', [$platformId]);
        if ($row === null) throw new HttpException('所属平台不存在', 404, 404);
        return $row;
    }
}
