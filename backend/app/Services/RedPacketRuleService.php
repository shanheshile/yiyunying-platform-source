<?php

declare(strict_types=1);

namespace Yiyunying\Services;

use InvalidArgumentException;

final class RedPacketRuleService
{
    public const DISTRIBUTION_COUNT_SPLIT = 'count_split';
    public const DISTRIBUTION_RANDOM_GRAB = 'random_grab';
    // Legacy clients still send this value; normalize it at the API boundary.
    public const DISTRIBUTION_SINGLE_RACE = 'single_race';
    public const ELIGIBILITY_CONTEXT_ALL = 'context_all';
    public const ELIGIBILITY_SELECTED = 'selected';

    public static function distributionMode(mixed $value): string
    {
        $mode = strtolower(trim((string) $value));
        if ($mode === '') {
            return self::DISTRIBUTION_COUNT_SPLIT;
        }

        if ($mode === self::DISTRIBUTION_SINGLE_RACE) {
            return self::DISTRIBUTION_RANDOM_GRAB;
        }

        if (!in_array($mode, [self::DISTRIBUTION_COUNT_SPLIT, self::DISTRIBUTION_RANDOM_GRAB], true)) {
            throw new InvalidArgumentException('红包发放方式无效');
        }

        return $mode;
    }

    public static function eligibilityMode(mixed $value, bool $hasSelectedRecipients): string
    {
        $mode = strtolower(trim((string) $value));
        if ($mode === '') {
            return $hasSelectedRecipients ? self::ELIGIBILITY_SELECTED : self::ELIGIBILITY_CONTEXT_ALL;
        }

        if (!in_array($mode, [self::ELIGIBILITY_CONTEXT_ALL, self::ELIGIBILITY_SELECTED], true)) {
            throw new InvalidArgumentException('红包领取范围无效');
        }

        return $mode;
    }

    public static function totalCount(string $distributionMode, mixed $requestedCount, int $eligibleCount): int
    {
        if ($eligibleCount < 1) {
            throw new InvalidArgumentException('红包领取范围内至少需要一人');
        }

        if (self::distributionMode($distributionMode) === self::DISTRIBUTION_RANDOM_GRAB) {
            return $eligibleCount;
        }

        $count = (int) $requestedCount;
        if ($count < 1) {
            $count = $eligibleCount;
        }
        if ($count > $eligibleCount) {
            throw new InvalidArgumentException('红包份数不能超过可领取人数');
        }

        return $count;
    }

    public static function distributionLabel(string $mode): string
    {
        return self::distributionMode($mode) === self::DISTRIBUTION_RANDOM_GRAB ? '金额池随机抢' : '按份数发';
    }

    public static function eligibilityLabel(string $mode): string
    {
        return $mode === self::ELIGIBILITY_SELECTED ? '仅指定人员' : '当前会话所有人';
    }

    public static function claimRule(string $distributionMode, string $eligibilityMode, int $eligibleCount): string
    {
        $scope = self::eligibilityLabel($eligibilityMode);
        if (self::distributionMode($distributionMode) === self::DISTRIBUTION_RANDOM_GRAB) {
            return sprintf(
                '%s可参与，共%d人可抢；每人最多领取一次，每次从剩余金额池随机领取，余额抢完即止，未抢到则没有',
                $scope,
                $eligibleCount
            );
        }

        return sprintf('%s可参与，按设置份数发放；总份数固定，份数抢完即止', $scope);
    }
}
