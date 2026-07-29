<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class TransferPolicyService
{
    public static function get(int $adminId, int $appId, int $userId): array
    {
        $row = Database::one(
            'SELECT * FROM user_transfer_policies WHERE admin_id = ? AND app_id = ? AND user_id = ?',
            [$adminId, $appId, $userId]
        );
        return self::publicPolicy($row ?? [
            'user_id' => $userId,
            'can_send' => 1,
            'can_receive' => 1,
            'single_limit' => 0,
            'daily_send_limit' => 0,
            'daily_receive_limit' => 0,
            'daily_pair_limit' => 0,
            'blocked_send_to_json' => null,
            'blocked_receive_from_json' => null,
        ]);
    }

    public static function save(int $adminId, int $appId, int $userId, array $data): array
    {
        $current = self::get($adminId, $appId, $userId);
        $canSend = self::boolValue($data['can_send'] ?? $current['can_send']);
        $canReceive = self::boolValue($data['can_receive'] ?? $current['can_receive']);
        $singleLimit = self::amount($data['single_limit'] ?? $current['single_limit'], 'single_limit');
        $dailySendLimit = self::amount($data['daily_send_limit'] ?? $current['daily_send_limit'], 'daily_send_limit');
        $dailyReceiveLimit = self::amount($data['daily_receive_limit'] ?? $current['daily_receive_limit'], 'daily_receive_limit');
        $dailyPairLimit = self::amount($data['daily_pair_limit'] ?? $current['daily_pair_limit'], 'daily_pair_limit');
        $blockedSendTo = self::ids($data['blocked_send_to_user_ids'] ?? $current['blocked_send_to_user_ids']);
        $blockedReceiveFrom = self::ids($data['blocked_receive_from_user_ids'] ?? $current['blocked_receive_from_user_ids']);
        Database::execute(
            'INSERT INTO user_transfer_policies
             (admin_id, app_id, user_id, can_send, can_receive, single_limit, daily_send_limit,
              daily_receive_limit, daily_pair_limit, blocked_send_to_json, blocked_receive_from_json,
              created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE can_send = VALUES(can_send), can_receive = VALUES(can_receive),
             single_limit = VALUES(single_limit), daily_send_limit = VALUES(daily_send_limit),
             daily_receive_limit = VALUES(daily_receive_limit), daily_pair_limit = VALUES(daily_pair_limit),
             blocked_send_to_json = VALUES(blocked_send_to_json),
             blocked_receive_from_json = VALUES(blocked_receive_from_json), updated_at = NOW()',
            [
                $adminId, $appId, $userId, $canSend ? 1 : 0, $canReceive ? 1 : 0,
                $singleLimit, $dailySendLimit, $dailyReceiveLimit, $dailyPairLimit,
                json_encode($blockedSendTo), json_encode($blockedReceiveFrom),
            ]
        );
        return self::get($adminId, $appId, $userId);
    }

    public static function assertAllowed(array $sender, array $receiver, float $amount): void
    {
        $adminId = (int) $sender['admin_id'];
        $appId = (int) $sender['app_id'];
        $senderId = (int) $sender['id'];
        $receiverId = (int) $receiver['id'];
        $senderPolicy = self::get($adminId, $appId, $senderId);
        $receiverPolicy = self::get($adminId, $appId, $receiverId);
        if (!$senderPolicy['can_send']) throw new HttpException('管理员已禁止当前账号转出余额', 403, 403);
        if (!$receiverPolicy['can_receive']) throw new HttpException('收款账号已被管理员禁止接收余额', 403, 403);
        if (in_array($receiverId, $senderPolicy['blocked_send_to_user_ids'], true)) {
            throw new HttpException('管理员禁止当前账号向该用户转账', 403, 403);
        }
        if (in_array($senderId, $receiverPolicy['blocked_receive_from_user_ids'], true)) {
            throw new HttpException('收款账号不能接收当前账号的转账', 403, 403);
        }
        if ($senderPolicy['single_limit'] > 0 && $amount > $senderPolicy['single_limit']) {
            throw new HttpException('转账金额超过该账号单笔限额', 0, 422, ['single_limit' => $senderPolicy['single_limit']]);
        }
        $sentToday = self::sum($senderId, 'wallet_transfer_out');
        $receivedToday = self::sum($receiverId, 'wallet_transfer_in');
        $pairToday = self::sum($senderId, 'wallet_transfer_out', $receiverId);
        self::assertDaily($sentToday, $amount, $senderPolicy['daily_send_limit'], '该账号今日转出额度不足');
        self::assertDaily($receivedToday, $amount, $receiverPolicy['daily_receive_limit'], '收款账号今日接收额度不足');
        self::assertDaily($pairToday, $amount, $senderPolicy['daily_pair_limit'], '今日向该用户转账额度不足');
    }

    private static function sum(int $userId, string $scene, ?int $refId = null): float
    {
        $sql = 'SELECT COALESCE(SUM(ABS(change_value)), 0) AS total FROM user_wallet_logs
                WHERE user_id = ? AND scene = ? AND created_at >= CURDATE()';
        $params = [$userId, $scene];
        if ($refId !== null) { $sql .= ' AND ref_type = ? AND ref_id = ?'; array_push($params, 'user', $refId); }
        return (float) (Database::one($sql, $params)['total'] ?? 0);
    }

    private static function assertDaily(float $used, float $amount, float $limit, string $message): void
    {
        if ($limit > 0 && $used + $amount > $limit) {
            throw new HttpException($message, 0, 422, ['used' => $used, 'amount' => $amount, 'limit' => $limit]);
        }
    }

    private static function publicPolicy(array $row): array
    {
        return [
            'user_id' => (int) $row['user_id'],
            'can_send' => (bool) $row['can_send'],
            'can_receive' => (bool) $row['can_receive'],
            'single_limit' => (float) $row['single_limit'],
            'daily_send_limit' => (float) $row['daily_send_limit'],
            'daily_receive_limit' => (float) $row['daily_receive_limit'],
            'daily_pair_limit' => (float) $row['daily_pair_limit'],
            'blocked_send_to_user_ids' => self::ids(json_decode((string) ($row['blocked_send_to_json'] ?? '[]'), true)),
            'blocked_receive_from_user_ids' => self::ids(json_decode((string) ($row['blocked_receive_from_json'] ?? '[]'), true)),
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private static function ids($value): array
    {
        if (!is_array($value)) return [];
        return array_values(array_unique(array_filter(array_map('intval', $value), static fn (int $id): bool => $id > 0)));
    }

    private static function amount($value, string $field): float
    {
        if (!is_numeric($value) || (float) $value < 0) throw new HttpException($field . ' 不能小于 0', 0, 422);
        return round((float) $value, 2);
    }

    private static function boolValue($value): bool
    {
        if (is_bool($value)) return $value;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
