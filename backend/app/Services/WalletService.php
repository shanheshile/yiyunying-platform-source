<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class WalletService
{
    private const ASSETS = ['integral', 'experience', 'balance', 'document_credit'];

    public static function primaryAsset(int $appId): string
    {
        $asset = (string) AppService::setting($appId, 'economy_primary_asset', 'balance');
        return in_array($asset, ['balance', 'integral'], true) ? $asset : 'balance';
    }

    public static function requireActivityEnabled(int $appId): string
    {
        if (!AppService::setting($appId, 'balance_activity_enabled', true)) {
            throw new HttpException('当前应用已关闭余额消费和互动活动', 403, 403);
        }
        return self::primaryAsset($appId);
    }

    public static function publicWallet(array $wallet, int $appId): array
    {
        $primaryAsset = self::primaryAsset($appId);
        $wallet['balance'] = (float) ($wallet[$primaryAsset] ?? 0);
        $wallet['activity_credit'] = (int) ($wallet['integral'] ?? 0);
        $wallet['experience'] = (int) ($wallet['experience'] ?? 0);
        $wallet['document_credit'] = (int) ($wallet['document_credit'] ?? 0);
        $wallet['primary_asset'] = 'balance';
        unset($wallet['integral']);
        return $wallet;
    }

    public static function purchase(array $user, string $productType, int $quantity): array
    {
        $appId = (int) $user['app_id'];
        $quantity = max(1, $quantity);
        if ($quantity > 10000) {
            throw new HttpException('单次购买数量不能超过 10000', 0, 422);
        }

        if ($productType === 'document_credit') {
            AppService::requireFeature($appId, 'balance_document_purchase');
            if (!AppService::setting($appId, 'balance_document_purchase_enabled', false)) {
                throw new HttpException('当前应用未开放余额购买笔记额度', 403, 403);
            }
            $unitPrice = (float) AppService::setting($appId, 'document_credit_balance_price', 1);
            $grantAsset = 'document_credit';
            $grantAmount = $quantity;
            $remark = '余额购买笔记额度';
        } elseif ($productType === 'vip_days') {
            AppService::requireFeature($appId, 'balance_membership_purchase');
            if (!AppService::setting($appId, 'balance_membership_purchase_enabled', false)) {
                throw new HttpException('当前应用未开放余额购买会员', 403, 403);
            }
            $unitPrice = (float) AppService::setting($appId, 'vip_day_balance_price', 1);
            $grantAsset = 'vip_days';
            $grantAmount = $quantity;
            $remark = '余额购买会员';
        } else {
            throw new HttpException('不支持的购买类型', 0, 422);
        }
        if ($unitPrice <= 0) {
            throw new HttpException('管理员尚未配置有效售价', 0, 422);
        }

        $payAsset = self::primaryAsset($appId);
        $total = round($unitPrice * $quantity, 2);
        if ($payAsset === 'integral' && floor($total) !== $total) {
            throw new HttpException('活动币模式下售价必须为整数', 0, 422);
        }

        return Database::transaction(static function () use (
            $user,
            $productType,
            $quantity,
            $unitPrice,
            $total,
            $payAsset,
            $grantAsset,
            $grantAmount,
            $remark
        ): array {
            $orderNo = 'UYB' . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
            $purchaseId = Database::insert(
                'INSERT INTO user_asset_purchases
                 (admin_id, app_id, user_id, order_no, product_type, quantity, unit_price,
                  total_amount, pay_asset, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
                    $orderNo, $productType, $quantity, $unitPrice, $total, $payAsset, 'pending',
                ]
            );
            self::adjust($user, $payAsset, -$total, 'asset_purchase_pay', 'asset_purchase', $purchaseId, $remark);
            if ($grantAsset === 'vip_days') {
                self::addVipDays($user, $grantAmount, 'asset_purchase_grant', 'asset_purchase', $purchaseId);
            } else {
                self::adjust($user, $grantAsset, $grantAmount, 'asset_purchase_grant', 'asset_purchase', $purchaseId, $remark);
            }
            Database::execute(
                "UPDATE user_asset_purchases SET status = 'completed', completed_at = NOW(), updated_at = NOW() WHERE id = ?",
                [$purchaseId]
            );
            $wallet = Database::one(
                'SELECT integral, experience, balance, document_credit, vip_expired_at, level_code, updated_at
                 FROM user_wallets WHERE user_id = ? AND app_id = ? AND admin_id = ?',
                [(int) $user['id'], (int) $user['app_id'], (int) $user['admin_id']]
            ) ?? [];
            return [
                'purchase_id' => $purchaseId,
                'order_no' => $orderNo,
                'product_type' => $productType,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_balance' => $total,
                'wallet' => self::publicWallet($wallet, (int) $user['app_id']),
            ];
        });
    }

    public static function adjust(
        array $user,
        string $asset,
        float $change,
        string $scene,
        string $refType = '',
        ?int $refId = null,
        string $remark = '',
        bool $allowNegative = false
    ): array {
        if (!in_array($asset, self::ASSETS, true)) {
            throw new HttpException('不支持的资产类型：' . $asset, 0, 422);
        }
        if ($change == 0.0) {
            throw new HttpException('资产变动值不能为 0', 0, 422);
        }

        $wallet = Database::one(
            'SELECT * FROM user_wallets WHERE admin_id = ? AND app_id = ? AND user_id = ? FOR UPDATE',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        if ($wallet === null) {
            throw new HttpException('用户资产账户不存在', -1, 500);
        }
        $before = (float) $wallet[$asset];
        $after = $asset === 'balance' ? round($before + $change, 2) : (int) ($before + $change);
        if (!$allowNegative && $after < 0) {
            throw new HttpException('资产余额不足：' . $asset, 0, 422, [
                'current' => $before,
                'required' => abs($change),
            ]);
        }
        Database::execute(
            "UPDATE user_wallets SET {$asset} = ?, updated_at = NOW()
             WHERE admin_id = ? AND app_id = ? AND user_id = ?",
            [$after, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        Database::execute(
            'INSERT INTO user_wallet_logs
             (admin_id, app_id, user_id, asset_type, change_value, before_value, after_value,
              scene, ref_type, ref_id, remark, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $asset,
                $change, $before, $after, $scene, $refType, $refId, mb_substr($remark, 0, 255),
            ]
        );
        $wallet[$asset] = $after;
        return $wallet;
    }

    public static function applyRewards(
        array $user,
        array $rewards,
        string $scene,
        string $refType = '',
        ?int $refId = null
    ): array {
        $last = null;
        foreach (self::ASSETS as $asset) {
            if (isset($rewards[$asset]) && (float) $rewards[$asset] != 0.0) {
                $last = self::adjust($user, $asset, (float) $rewards[$asset], $scene, $refType, $refId);
            }
        }
        if (isset($rewards['vip_days']) && (int) $rewards['vip_days'] > 0) {
            self::addVipDays($user, (int) $rewards['vip_days'], $scene, $refType, $refId);
        }
        return $last ?? (Database::one(
            'SELECT * FROM user_wallets WHERE admin_id = ? AND app_id = ? AND user_id = ?',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        ) ?? []);
    }

    public static function addVipDays(
        array $user,
        int $days,
        string $scene,
        string $refType = '',
        ?int $refId = null
    ): string {
        $wallet = Database::one(
            'SELECT * FROM user_wallets WHERE admin_id = ? AND app_id = ? AND user_id = ? FOR UPDATE',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        if ($wallet === null) {
            throw new HttpException('用户资产账户不存在', -1, 500);
        }
        $base = $wallet['vip_expired_at'] === null
            ? time()
            : max(time(), strtotime((string) $wallet['vip_expired_at']));
        $expiredAt = date('Y-m-d H:i:s', $base + $days * 86400);
        Database::execute(
            'UPDATE user_wallets SET vip_expired_at = ?, updated_at = NOW()
             WHERE admin_id = ? AND app_id = ? AND user_id = ?',
            [$expiredAt, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        Database::execute(
            'INSERT INTO user_wallet_logs
             (admin_id, app_id, user_id, asset_type, change_value, before_value, after_value,
              scene, ref_type, ref_id, remark, created_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, NOW())',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], 'vip_days', $days,
                $days, $scene, $refType, $refId, '会员到期：' . $expiredAt,
            ]
        );
        return $expiredAt;
    }
}
