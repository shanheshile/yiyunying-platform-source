<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use InvalidArgumentException;

final class RedPacketAmountService
{
    private const MAX_CENTS = 100000000000;

    public static function normalize(mixed $value): string
    {
        return self::formatCents(self::parseCents($value));
    }

    public static function normalizeStored(mixed $value): string
    {
        return self::formatCents(self::parseStoredCents($value));
    }

    public static function parseCents(mixed $value): int
    {
        $cents = self::parseStoredCents($value);
        if ($cents <= 0) {
            throw new InvalidArgumentException('金额必须在 0.01 至 1000000000.00 之间');
        }
        return $cents;
    }

    public static function parseStoredCents(mixed $value): int
    {
        $raw = trim((string) $value);
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', $raw, $matches)) {
            throw new InvalidArgumentException('金额必须是非负数且最多保留两位小数');
        }
        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) > 10) {
            throw new InvalidArgumentException('金额超出允许范围');
        }
        $fraction = str_pad($matches[2] ?? '', 2, '0');
        $cents = ((int) $whole * 100) + (int) $fraction;
        if ($cents > self::MAX_CENTS) {
            throw new InvalidArgumentException('金额超出允许范围');
        }
        return $cents;
    }

    public static function formatCents(int $cents): string
    {
        if ($cents < 0) {
            throw new InvalidArgumentException('金额不能小于 0');
        }
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function allocate(mixed $remainingAmount, int $remainingCount, string $packetType = 'random'): string
    {
        $remainingCents = self::parseCents($remainingAmount);
        if ($remainingCount <= 0 || $remainingCents < $remainingCount) {
            throw new InvalidArgumentException('红包剩余金额不足以保证每份至少 0.01 余额');
        }
        if ($remainingCount === 1) {
            return self::formatCents($remainingCents);
        }
        if ($packetType === 'equal') {
            return self::formatCents(intdiv($remainingCents, $remainingCount));
        }

        $reservedCents = $remainingCount - 1;
        $doubleMean = intdiv($remainingCents * 2, $remainingCount);
        $maximum = max(1, min($remainingCents - $reservedCents, $doubleMean));
        return self::formatCents(random_int(1, $maximum));
    }

    public static function randomGrab(mixed $remainingAmount, int $remainingEligibleCount): string
    {
        $remainingCents = self::parseCents($remainingAmount);
        if ($remainingEligibleCount <= 0) {
            throw new InvalidArgumentException('红包已无可领取人员');
        }
        if ($remainingEligibleCount === 1) {
            return self::formatCents($remainingCents);
        }

        // The pool can be exhausted before every eligible participant receives money.
        return self::formatCents(random_int(1, $remainingCents));
    }

    public static function compare(mixed $left, mixed $right): int
    {
        return self::parseStoredCents($left) <=> self::parseStoredCents($right);
    }
}
