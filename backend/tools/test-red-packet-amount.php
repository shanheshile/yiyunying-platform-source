<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Yiyunying\Services\RedPacketAmountService;

function assertMoney(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

assertMoney(RedPacketAmountService::normalize('1') === '1.00', '整数金额应规范为两位小数');
assertMoney(RedPacketAmountService::normalize('0.01') === '0.01', '最小金额应为 0.01');
assertMoney(RedPacketAmountService::compare('0.10', '0.09') > 0, '金额比较应按分进行');
assertMoney(RedPacketAmountService::normalizeStored('0') === '0.00', '已抢完红包应可按零余额安全结算');
assertMoney(RedPacketAmountService::normalizeStored('0.10') === '0.10', '到期退款金额应精确保留到分');

foreach (['0', '-1', '0.001', 'abc'] as $invalid) {
    try {
        RedPacketAmountService::normalize($invalid);
        assertMoney(false, "非法金额未被拒绝: {$invalid}");
    } catch (InvalidArgumentException) {
    }
}

$seenFirstClaims = [];
for ($trial = 0; $trial < 80; $trial++) {
    $remainingCents = 100;
    $count = 5;
    $sum = 0;
    while ($count > 0) {
        $claim = RedPacketAmountService::allocate(
            RedPacketAmountService::formatCents($remainingCents),
            $count,
            'random'
        );
        $claimCents = RedPacketAmountService::parseCents($claim);
        assertMoney($claimCents >= 1, '每份红包不得小于 0.01');
        assertMoney($claimCents <= $remainingCents - ($count - 1), '随机拆分必须为未领取者保留 0.01');
        if ($count === 5) $seenFirstClaims[$claim] = true;
        $remainingCents -= $claimCents;
        $sum += $claimCents;
        $count--;
    }
    assertMoney($sum === 100 && $remainingCents === 0, '1.00 余额拆分给 5 人后必须精确结算');
}

assertMoney(count($seenFirstClaims) > 1, '拼手气红包的金额不应每次固定');

$equal = [];
$remainingCents = 100;
for ($count = 5; $count > 0; $count--) {
    $claim = RedPacketAmountService::allocate(RedPacketAmountService::formatCents($remainingCents), $count, 'equal');
    $claimCents = RedPacketAmountService::parseCents($claim);
    $equal[] = $claimCents;
    $remainingCents -= $claimCents;
}
assertMoney(array_sum($equal) === 100, '等额红包也必须精确结算');

echo "红包金额与随机拆分测试通过\n";
