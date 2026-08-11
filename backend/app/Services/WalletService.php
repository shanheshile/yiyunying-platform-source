<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class WalletService
{
    private const ASSETS = ['integral', 'experience', 'balance', 'document_credit'];
    private const MAX_BALANCE_CENTS = 100000000000; // 1,000,000,000.00
    private const MAX_INTEGRAL_ASSET = 1000000000;

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
            RolePermissionService::requireUserFeature($user, 'balance_document_purchase');
            if (!AppService::setting($appId, 'balance_document_purchase_enabled', false)) {
                throw new HttpException('当前应用未开放余额购买笔记额度', 403, 403);
            }
            $unitPriceRaw = AppService::setting($appId, 'document_credit_balance_price', 1);
            $grantAsset = 'document_credit';
            $grantAmount = $quantity;
            $remark = '余额购买笔记额度';
        } elseif ($productType === 'vip_days') {
            RolePermissionService::requireUserFeature($user, 'balance_membership_purchase');
            if (!AppService::setting($appId, 'balance_membership_purchase_enabled', false)) {
                throw new HttpException('当前应用未开放余额购买会员', 403, 403);
            }
            $unitPriceRaw = AppService::setting($appId, 'vip_day_balance_price', 1);
            $grantAsset = 'vip_days';
            $grantAmount = $quantity;
            $remark = '余额购买会员';
        } else {
            throw new HttpException('不支持的购买类型', 0, 422);
        }
        $payAsset = self::primaryAsset($appId);
        try {
            $unitPriceUnits = self::changeUnits($payAsset, $unitPriceRaw);
        } catch (HttpException $exception) {
            throw new HttpException('管理员尚未配置有效售价：' . $exception->getMessage(), 0, 422);
        }
        if ($unitPriceUnits <= 0) {
            throw new HttpException('管理员尚未配置有效售价', 0, 422);
        }
        $maximumUnits = $payAsset === 'balance' ? self::MAX_BALANCE_CENTS : self::MAX_INTEGRAL_ASSET;
        if ($unitPriceUnits > intdiv($maximumUnits, $quantity)) {
            throw new HttpException('本次购买总额超过安全业务上限，请减少数量', 0, 422);
        }
        $totalUnits = $unitPriceUnits * $quantity;
        $unitPrice = $payAsset === 'balance' ? self::formatBalanceMinorUnits($unitPriceUnits) : $unitPriceUnits;
        $total = $payAsset === 'balance' ? self::formatBalanceMinorUnits($totalUnits) : $totalUnits;

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
            self::adjust(
                $user,
                $payAsset,
                self::negativeAmount($payAsset, $total),
                'asset_purchase_pay',
                'asset_purchase',
                $purchaseId,
                $remark
            );
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
        mixed $change,
        string $scene,
        string $refType = '',
        ?int $refId = null,
        string $remark = '',
        bool $allowNegative = false
    ): array {
        $changeUnits = self::changeUnits($asset, $change);

        $wallet = Database::one(
            'SELECT * FROM user_wallets WHERE admin_id = ? AND app_id = ? AND user_id = ? FOR UPDATE',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        if ($wallet === null) {
            throw new HttpException('用户资产账户不存在', -1, 500);
        }
        $adjustment = self::adjustmentFromUnits(
            $asset,
            $wallet[$asset] ?? ($asset === 'balance' ? '0.00' : 0),
            $changeUnits,
            $allowNegative
        );
        $before = $adjustment['before'];
        $changeValue = $adjustment['change'];
        $after = $adjustment['after'];
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
                $changeValue, $before, $after, $scene, $refType, $refId, mb_substr($remark, 0, 255),
            ]
        );
        $wallet[$asset] = $after;
        return $wallet;
    }

    /**
     * Pure amount calculation used by regression tests and the persisted ledger path.
     * No database access is performed here.
     */
    private static function adjustmentValues(
        string $asset,
        mixed $beforeValue,
        mixed $change,
        bool $allowNegative = false
    ): array {
        return self::adjustmentFromUnits(
            $asset,
            $beforeValue,
            self::changeUnits($asset, $change),
            $allowNegative
        );
    }

    private static function adjustmentFromUnits(
        string $asset,
        mixed $beforeValue,
        int $changeUnits,
        bool $allowNegative
    ): array {
        if ($asset === 'balance') {
            $beforeUnits = self::balanceMinorUnits($beforeValue);
            $afterUnits = $beforeUnits + $changeUnits;
            if (abs($beforeUnits) > self::MAX_BALANCE_CENTS
                || abs($changeUnits) > self::MAX_BALANCE_CENTS
                || abs($afterUnits) > self::MAX_BALANCE_CENTS) {
                throw new HttpException('余额超过安全业务上限，请先由财务人员核对账户', 0, 422);
            }
            $before = self::formatBalanceMinorUnits($beforeUnits);
            $changeValue = self::formatBalanceMinorUnits($changeUnits);
            $after = self::formatBalanceMinorUnits($afterUnits);
        } else {
            $beforeUnits = self::integerAssetUnits($beforeValue);
            $afterUnits = $beforeUnits + $changeUnits;
            if (abs($beforeUnits) > self::MAX_INTEGRAL_ASSET
                || abs($changeUnits) > self::MAX_INTEGRAL_ASSET
                || abs($afterUnits) > self::MAX_INTEGRAL_ASSET) {
                throw new HttpException('资产数值超过安全业务上限', 0, 422);
            }
            $before = $beforeUnits;
            $changeValue = $changeUnits;
            $after = $afterUnits;
        }
        if (!$allowNegative && $afterUnits < 0) {
            throw new HttpException('资产余额不足：' . $asset, 0, 422, [
                'current' => $before,
                'required' => $asset === 'balance'
                    ? self::formatBalanceMinorUnits(abs($changeUnits))
                    : abs($changeUnits),
            ]);
        }
        return ['before' => $before, 'change' => $changeValue, 'after' => $after];
    }

    public static function amountUnits(string $asset, mixed $value, bool $allowZero = false): int
    {
        if (!in_array($asset, array_merge(self::ASSETS, ['vip_days']), true)) {
            throw new HttpException('不支持的资产类型：' . $asset, 0, 422);
        }
        return self::parseAmountUnits($asset, $value, $allowZero);
    }

    public static function canonicalAmount(string $asset, mixed $value, bool $allowZero = false): string|int
    {
        $units = self::amountUnits($asset, $value, $allowZero);
        return $asset === 'balance' ? self::formatBalanceMinorUnits($units) : $units;
    }

    public static function negativeAmount(string $asset, mixed $positiveValue): string|int
    {
        $units = self::amountUnits($asset, $positiveValue);
        if ($units <= 0) throw new HttpException('扣款金额必须大于 0', 0, 422);
        return $asset === 'balance' ? self::formatBalanceMinorUnits(-$units) : -$units;
    }

    private static function changeUnits(string $asset, mixed $change): int
    {
        if (!in_array($asset, self::ASSETS, true)) {
            throw new HttpException('不支持的资产类型：' . $asset, 0, 422);
        }
        return self::parseAmountUnits($asset, $change, false);
    }

    private static function parseAmountUnits(string $asset, mixed $change, bool $allowZero): int
    {
        if (!is_int($change) && !is_float($change) && !is_string($change)) {
            throw new HttpException('资产变动值必须为有限数字', 0, 422);
        }
        if (is_float($change) && !is_finite($change)) {
            throw new HttpException('资产变动值必须为有限数字', 0, 422);
        }

        $raw = trim((string) $change);
        if ($asset === 'balance') {
            if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $raw, $matches) !== 1) {
                throw new HttpException('余额变动最多保留两位小数', 0, 422);
            }
            $whole = ltrim((string) $matches[2], '0');
            $whole = $whole === '' ? '0' : $whole;
            if (strlen($whole) > 10) {
                throw new HttpException('余额超过安全业务上限，请先由财务人员核对账户', 0, 422);
            }
            $units = ((int) $whole) * 100
                + (int) str_pad((string) ($matches[3] ?? ''), 2, '0');
            if (($matches[1] ?? '') === '-') $units = -$units;
            if (!$allowZero && $units === 0) throw new HttpException('资产变动值不能为 0', 0, 422);
            if (abs($units) > self::MAX_BALANCE_CENTS) {
                throw new HttpException('余额超过安全业务上限，请先由财务人员核对账户', 0, 422);
            }
            return $units;
        }

        if (preg_match('/^(-?)(\d+)(?:\.0+)?$/', $raw, $matches) !== 1) {
            throw new HttpException('该资产仅支持整数变动', 0, 422);
        }
        $whole = ltrim((string) $matches[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) > 10) {
            throw new HttpException('资产数值超过安全业务上限', 0, 422);
        }
        $units = (int) $whole;
        if (($matches[1] ?? '') === '-') $units = -$units;
        if (!$allowZero && $units === 0) throw new HttpException('资产变动值不能为 0', 0, 422);
        if (abs($units) > self::MAX_INTEGRAL_ASSET) {
            throw new HttpException('资产数值超过安全业务上限', 0, 422);
        }
        return $units;
    }

    private static function integerAssetUnits(mixed $value): int
    {
        if (is_int($value)) return $value;
        $raw = trim((string) $value);
        if (preg_match('/^-?\d+$/', $raw) !== 1 || strlen(ltrim($raw, '-0')) > 10) {
            throw new HttpException('资产数据格式异常，请先由财务人员核对账户', -1, 500);
        }
        return (int) $raw;
    }

    private static function balanceMinorUnits(mixed $value): int
    {
        $raw = trim((string) $value);
        if (preg_match('/^(-?)(\d{1,10})(?:\.(\d{1,2}))?$/', $raw, $matches) !== 1) {
            throw new HttpException('余额数据格式异常，请先由财务人员核对账户', -1, 500);
        }
        $minor = ((int) $matches[2]) * 100 + (int) str_pad((string) ($matches[3] ?? ''), 2, '0');
        return ($matches[1] ?? '') === '-' ? -$minor : $minor;
    }

    private static function formatBalanceMinorUnits(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);
        return $sign . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
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
            if (isset($rewards[$asset]) && !self::isExplicitZero($rewards[$asset])) {
                $last = self::adjust($user, $asset, $rewards[$asset], $scene, $refType, $refId);
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

    private static function isExplicitZero(mixed $value): bool
    {
        if (is_int($value)) return $value === 0;
        if (is_float($value)) return is_finite($value) && $value == 0.0;
        if (!is_string($value)) return false;
        return preg_match('/^[+-]?0+(?:\.0+)?$/', trim($value)) === 1;
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
