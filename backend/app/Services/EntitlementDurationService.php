<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use DateTimeImmutable;
use Yiyunying\Core\HttpException;

final class EntitlementDurationService
{
    private const UNITS = [
        'second' => 'seconds', 'seconds' => 'seconds', '秒' => 'seconds',
        'minute' => 'minutes', 'minutes' => 'minutes', '分' => 'minutes', '分钟' => 'minutes',
        'hour' => 'hours', 'hours' => 'hours', '时' => 'hours', '小时' => 'hours',
        'day' => 'days', 'days' => 'days', '天' => 'days',
        'week' => 'weeks', 'weeks' => 'weeks', '周' => 'weeks',
        'month' => 'months', 'months' => 'months', '月' => 'months',
        'quarter' => 'months', 'quarters' => 'months', '季' => 'months', '季度' => 'months',
        'year' => 'years', 'years' => 'years', '年' => 'years',
    ];

    public static function apply(?string $current, string $operation, int $amount, string $unit): string
    {
        if ($amount < 0) {
            throw new HttpException('权益数量不能小于 0，请通过“减少”操作扣减', 0, 422);
        }
        if ($amount === 0) {
            throw new HttpException('权益数量必须大于 0', 0, 422);
        }
        $operation = self::operation($operation);
        $normalizedUnit = self::unit($unit);
        if (in_array($unit, ['quarter', 'quarters', '季', '季度'], true)) {
            $amount *= 3;
        }
        $now = new DateTimeImmutable('now');
        $existing = self::date($current);
        if ($operation === 'set') {
            $base = $now;
            $sign = '+';
        } elseif ($operation === 'increase') {
            $base = $existing !== null && $existing > $now ? $existing : $now;
            $sign = '+';
        } else {
            $base = $existing ?? $now;
            $sign = '-';
        }
        $changed = $base->modify($sign . $amount . ' ' . $normalizedUnit);
        if ($changed === false) {
            throw new HttpException('会员时间计算失败', -1, 500);
        }
        return $changed->format('Y-m-d H:i:s');
    }

    public static function operation(string $value): string
    {
        $value = strtolower(trim($value));
        return match ($value) {
            'increase', 'add', '增加', '赠送' => 'increase',
            'decrease', 'subtract', '减少', '扣减' => 'decrease',
            'set', 'replace', '设为', '设置' => 'set',
            default => throw new HttpException('操作方式仅支持增加、减少或设为', 0, 422),
        };
    }

    public static function numericChange(string $operation, float $amount, float $current): float
    {
        if ($amount < 0) {
            throw new HttpException('权益数量不能小于 0，请通过“减少”操作扣减', 0, 422);
        }
        return match (self::operation($operation)) {
            'increase' => $amount,
            'decrease' => -$amount,
            'set' => $amount - $current,
        };
    }

    private static function unit(string $value): string
    {
        $value = strtolower(trim($value));
        if (!isset(self::UNITS[$value])) {
            throw new HttpException('时间单位仅支持秒、分、时、天、周、月、季或年', 0, 422);
        }
        return self::UNITS[$value];
    }

    private static function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') return null;
        try { return new DateTimeImmutable($value); }
        catch (\Throwable $exception) { throw new HttpException('当前会员到期时间格式错误', -1, 500); }
    }
}
