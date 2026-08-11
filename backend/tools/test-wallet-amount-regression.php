<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Core/HttpException.php';
require_once $root . '/app/Services/WalletService.php';
require_once $root . '/app/Services/RewardRuleService.php';

use Yiyunying\Core\HttpException;
use Yiyunying\Services\WalletService;

function failWalletTest(string $message): never
{
    fwrite(STDERR, "Wallet amount regression failed: {$message}\n");
    exit(1);
}

function assertSameWallet(mixed $expected, mixed $actual, string $message): void
{
    if ($actual !== $expected) {
        failWalletTest($message . '; expected=' . var_export($expected, true)
            . ', actual=' . var_export($actual, true));
    }
}

function expectWalletReject(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (HttpException $exception) {
        if ($exception->httpStatus !== 422) {
            failWalletTest($message . '; unexpected HTTP status ' . $exception->httpStatus);
        }
        return;
    } catch (Throwable $exception) {
        failWalletTest($message . '; unexpected ' . $exception::class . ': ' . $exception->getMessage());
    }
    failWalletTest($message . '; value was accepted');
}

function balanceCents(string $value): int
{
    if (preg_match('/^(-?)(\d+)\.(\d{2})$/', $value, $matches) !== 1) {
        failWalletTest('unexpected balance ledger format ' . $value);
    }
    $minor = ((int) $matches[2]) * 100 + (int) $matches[3];
    return $matches[1] === '-' ? -$minor : $minor;
}

$calculate = new ReflectionMethod(WalletService::class, 'adjustmentValues');
$calculate->setAccessible(true);
$adjust = static fn(string $asset, mixed $before, mixed $change, bool $allowNegative = false): array =>
    $calculate->invoke(null, $asset, $before, $change, $allowNegative);

$cent = $adjust('balance', '10.00', '0.01');
assertSameWallet(
    ['before' => '10.00', 'change' => '0.01', 'after' => '10.01'],
    $cent,
    'two-decimal balance adjustment must be exact'
);
$decimal = $adjust('balance', '10.00', '123.45');
assertSameWallet('133.45', $decimal['after'], 'two decimal places must be retained');

foreach (['0.001', '1.000', 1.001] as $invalid) {
    expectWalletReject(
        static fn(): array => $adjust('balance', '10.00', $invalid),
        'balance change with more than two decimal places must be rejected: ' . var_export($invalid, true)
    );
}

$balanceLimit = $adjust('balance', '0.00', '1000000000.00');
assertSameWallet('1000000000.00', $balanceLimit['after'], 'one-billion balance limit must be accepted exactly');
assertSameWallet(
    '1000000000.00',
    $adjust('balance', '999999999.99', '0.01')['after'],
    'addition up to the one-billion balance limit must be exact'
);
expectWalletReject(
    static fn(): array => $adjust('balance', '0.00', '1000000000.01'),
    'balance change above the one-billion business limit must be rejected'
);
expectWalletReject(
    static fn(): array => $adjust('balance', '999999999.99', '0.02'),
    'balance result above the one-billion business limit must be rejected'
);

foreach ([1.5, '1.01', '-0.5'] as $invalid) {
    expectWalletReject(
        static fn(): array => $adjust('integral', 10, $invalid),
        'non-integer integral change must be rejected: ' . var_export($invalid, true)
    );
}
assertSameWallet(
    1000000000,
    $adjust('integral', 0, '1000000000')['after'],
    'one-billion integral limit must be accepted exactly'
);
expectWalletReject(
    static fn(): array => $adjust('integral', 0, '1000000001'),
    'integral change above the one-billion business limit must be rejected'
);

$pointDebit = $adjust('integral', 10, -1);
$pointCredit = $adjust('integral', $pointDebit['after'], 1);
assertSameWallet(['before' => 10, 'change' => -1, 'after' => 9], $pointDebit, 'one-point debit ledger must be exact');
assertSameWallet(['before' => 9, 'change' => 1, 'after' => 10], $pointCredit, 'one-point credit ledger must be exact');
assertSameWallet(
    $pointDebit['after'],
    $pointDebit['before'] + $pointDebit['change'],
    'point debit before plus change must equal after'
);
assertSameWallet(
    $pointCredit['after'],
    $pointCredit['before'] + $pointCredit['change'],
    'point credit before plus change must equal after'
);

$balanceDebit = $adjust('balance', '10.00', '-1.00');
$balanceCredit = $adjust('balance', $balanceDebit['after'], '1.00');
assertSameWallet('9.00', $balanceDebit['after'], 'one-unit balance debit must be exact');
assertSameWallet('10.00', $balanceCredit['after'], 'one-unit balance credit must restore the balance');
assertSameWallet(
    balanceCents($balanceDebit['after']),
    balanceCents($balanceDebit['before']) + balanceCents($balanceDebit['change']),
    'balance debit ledger must reconcile in cents'
);
assertSameWallet(
    balanceCents($balanceCredit['after']),
    balanceCents($balanceCredit['before']) + balanceCents($balanceCredit['change']),
    'balance credit ledger must reconcile in cents'
);

foreach ([INF, -INF, NAN, '1e309', 'INF', 'NAN'] as $invalid) {
    expectWalletReject(
        static fn(): array => $adjust('balance', '10.00', $invalid),
        'non-finite balance input must be rejected: ' . var_export($invalid, true)
    );
    expectWalletReject(
        static fn(): array => $adjust('integral', 10, $invalid),
        'non-finite integral input must be rejected: ' . var_export($invalid, true)
    );
}

assertSameWallet(
    '-1.23',
    WalletService::negativeAmount('balance', '1.23'),
    'balance debit must be canonical without unary float coercion'
);
assertSameWallet(
    -7,
    WalletService::negativeAmount('integral', '7'),
    'integer debit must remain an integer'
);
assertSameWallet(
    '1.20',
    WalletService::canonicalAmount('balance', '1.20'),
    'canonical balance amount must retain two decimals'
);

$normalizeReward = new ReflectionMethod(\Yiyunying\Services\RewardRuleService::class, 'normalizeReward');
$normalizeReward->setAccessible(true);
$validReward = $normalizeReward->invoke(null, ['balance' => '1.20', 'integral' => '2']);
assertSameWallet('1.20', $validReward['balance'], 'reward balance must remain fixed-point');
assertSameWallet(2, $validReward['integral'], 'integer reward must remain exact');
foreach ([
    ['integral' => '1.9'],
    ['experience' => '2.01'],
    ['balance' => '1.234'],
] as $invalidReward) {
    expectWalletReject(
        static fn(): array => $normalizeReward->invoke(null, $invalidReward),
        'reward normalization must reject truncation or excess precision'
    );
}

$walletSource = (string) file_get_contents($root . '/app/Services/WalletService.php');
$adminSource = (string) file_get_contents($root . '/app/Controllers/Admin/UserController.php');
$forumSource = (string) file_get_contents($root . '/app/Services/ForumExperienceService.php');
$rewardSource = (string) file_get_contents($root . '/app/Services/RewardRuleService.php');
$walletStart = strpos($adminSource, 'public static function wallet(');
$walletEnd = $walletStart === false ? false : strpos($adminSource, 'public static function vip(', $walletStart);
if ($walletStart === false || $walletEnd === false) {
    failWalletTest('admin wallet endpoint method boundaries are missing');
}
$adminWallet = substr($adminSource, $walletStart, $walletEnd - $walletStart);
if (!str_contains($adminWallet, "\$change = \$request->input('change_value', null);")
    || str_contains($adminWallet, '(float) $request->input')
    || !str_contains($adminWallet, 'WalletService::adjust(')) {
    failWalletTest('admin wallet endpoint must forward the original amount without float coercion');
}
if (!str_contains($walletSource, "['before' => \$before, 'change' => \$changeValue, 'after' => \$after]")
    || !str_contains($walletSource, '$changeValue, $before, $after, $scene')) {
    failWalletTest('persisted wallet and ledger values must share the pure adjustment result');
}
if (str_contains($walletSource, 'self::adjust($user, $asset, (float) $rewards[$asset]')) {
    failWalletTest('reward values must reach fixed-point adjustment without float coercion');
}
if (str_contains($walletSource, '(float) AppService::setting')
    || str_contains($walletSource, 'round($unitPrice * $quantity')
    || str_contains($walletSource, 'self::adjust($user, $payAsset, -$total')
    || !str_contains($walletSource, '$totalUnits = $unitPriceUnits * $quantity')
    || !str_contains($walletSource, 'isExplicitZero($rewards[$asset])')) {
    failWalletTest('configured purchase prices and rewards must avoid float pre-calculation');
}
$buyStart = strpos($forumSource, 'public static function buySection(');
$buyEnd = $buyStart === false ? false : strpos($forumSource, 'public static function toggleLike(', $buyStart);
if ($buyStart === false || $buyEnd === false) failWalletTest('forum purchase method boundaries are missing');
$forumPurchase = substr($forumSource, $buyStart, $buyEnd - $buyStart);
if (str_contains($forumPurchase, '(float) $section[\'price_balance\']')
    || !str_contains($forumPurchase, "WalletService::canonicalAmount('balance'")
    || !str_contains($forumPurchase, 'WalletService::negativeAmount(')) {
    failWalletTest('forum paid-section purchase must use canonical fixed-point amounts');
}
$rewardStart = strpos($rewardSource, 'private static function normalizeReward(');
$rewardEnd = $rewardStart === false ? false : strpos($rewardSource, 'private static function emptyReward(', $rewardStart);
if ($rewardStart === false || $rewardEnd === false) failWalletTest('reward normalizer method boundaries are missing');
$rewardNormalizer = substr($rewardSource, $rewardStart, $rewardEnd - $rewardStart);
if (str_contains($rewardNormalizer, '(float)') || str_contains($rewardNormalizer, '(int) $amount')
    || !str_contains($rewardNormalizer, 'WalletService::canonicalAmount(')) {
    failWalletTest('reward normalization must reject lossy float or integer coercion');
}

echo "Wallet amount regression: passed\n";
