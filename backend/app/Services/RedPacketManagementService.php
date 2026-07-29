<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class RedPacketManagementService
{
    public static function forceRefund(int $adminId, int $appId, int $packetId): array
    {
        $result = Database::transaction(static function () use ($adminId, $appId, $packetId): array {
            $packet = Database::one(
                'SELECT * FROM red_packets WHERE id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [$packetId, $adminId, $appId]
            );
            if ($packet === null) {
                throw new HttpException('红包不存在', 404, 404);
            }

            $refund = RedPacketAmountService::normalizeStored($packet['remain_amount']);
            $refundCents = RedPacketAmountService::parseStoredCents($refund);
            if ((int) $packet['status'] !== 1 || $refundCents <= 0 || (int) $packet['remain_count'] <= 0) {
                throw new HttpException('红包已结束或没有可退回金额', 0, 409);
            }

            $sender = Database::one(
                'SELECT * FROM users WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL FOR UPDATE',
                [(int) $packet['user_id'], $adminId, $appId]
            );
            if ($sender === null) {
                throw new HttpException('红包发送者不存在，无法完成退款', -1, 500);
            }

            Database::execute(
                'UPDATE red_packets SET status = 2, remain_amount = 0, remain_count = 0
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1',
                [$packetId, $adminId, $appId]
            );
            $asset = WalletService::requireActivityEnabled($appId);
            WalletService::adjust(
                $sender,
                $asset,
                (float) $refund,
                'red_packet_manager_refund',
                'red_packet',
                $packetId,
                '管理方强制结束红包并退回剩余金额'
            );

            return [
                'packet' => $packet,
                'sender' => $sender,
                'refund_amount' => $refund,
                'asset_type' => $asset,
            ];
        });

        NotificationService::send(
            $result['sender'],
            'red_packet_manager_refund',
            '红包已由管理方结束',
            '红包剩余金额已退回你的账户',
            [
                'packet_id' => $packetId,
                'refund_amount' => $result['refund_amount'],
                'delivery_scope' => (string) ($result['packet']['delivery_scope'] ?? 'private'),
            ]
        );

        return $result;
    }
}
