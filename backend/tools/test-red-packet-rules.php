<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Yiyunying\Services\RedPacketAmountService;
use Yiyunying\Services\RedPacketRuleService;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

assertSameValue(
    RedPacketRuleService::DISTRIBUTION_COUNT_SPLIT,
    RedPacketRuleService::distributionMode(''),
    '旧客户端默认使用按份数发'
);
assertSameValue(
    RedPacketRuleService::ELIGIBILITY_SELECTED,
    RedPacketRuleService::eligibilityMode('', true),
    '旧客户端传接收人时默认使用指定范围'
);
assertSameValue(
    RedPacketRuleService::ELIGIBILITY_CONTEXT_ALL,
    RedPacketRuleService::eligibilityMode('', false),
    '未指定接收人时默认使用当前场景全部参与人'
);
assertSameValue(3, RedPacketRuleService::totalCount('count_split', 3, 5), '按份数发保留填写份数');
assertSameValue(
    RedPacketRuleService::DISTRIBUTION_RANDOM_GRAB,
    RedPacketRuleService::distributionMode('single_race'),
    '旧单份随机抢兼容为金额池随机抢'
);
assertSameValue(50, RedPacketRuleService::totalCount('single_race', 5, 50), '金额池随机抢允许范围内所有人参与');
$grab = RedPacketAmountService::randomGrab('1.00', 50);
$grabCents = RedPacketAmountService::parseCents($grab);
assertSameValue(true, $grabCents >= 1 && $grabCents <= 100, '金额池每次应随机领取剩余余额的一部分');
assertSameValue('金额池随机抢', RedPacketRuleService::distributionLabel('single_race'), '旧值应显示现行中文标签');

$invalidCountRejected = false;
try {
    RedPacketRuleService::totalCount('count_split', 6, 5);
} catch (InvalidArgumentException) {
    $invalidCountRejected = true;
}
assertSameValue(true, $invalidCountRejected, '红包份数不能超过可领取人数');

$rule = RedPacketRuleService::claimRule('single_race', 'selected', 8);
assertSameValue(
    true,
    str_contains($rule, '共8人可抢')
        && str_contains($rule, '每人最多领取一次')
        && str_contains($rule, '余额抢完即止'),
    '金额池随机抢规则说明'
);

fwrite(STDOUT, "Red packet rule checks: passed\n");
