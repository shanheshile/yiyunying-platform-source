<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/MomentVisibilityService.php';

use Yiyunying\Services\MomentVisibilityService;

$pinned = ['is_pinned' => 1];
$regular = ['is_pinned' => 0];
$cases = [
    'public pinned moments still use the time window' => [true, MomentVisibilityService::appliesVisibleDays($pinned, false)],
    'profile pinned moments bypass only the time window' => [false, MomentVisibilityService::appliesVisibleDays($pinned, true)],
    'profile regular moments still use the time window' => [true, MomentVisibilityService::appliesVisibleDays($regular, true)],
    'public regular moments use the time window' => [true, MomentVisibilityService::appliesVisibleDays($regular, false)],
    'owners always see their own pinned moments' => [true, MomentVisibilityService::canView([
        'id' => 9,
        'user_id' => 17,
        'app_id' => 3,
        'admin_id' => 1,
        'is_pinned' => 1,
        'visibility_mode' => 'private',
        'visible_days' => 3,
        'created_at' => '2020-01-01 00:00:00',
    ], [
        'id' => 17,
        'app_id' => 3,
        'admin_id' => 1,
    ], true)],
];

foreach ($cases as $name => [$expected, $actual]) {
    if ($expected !== $actual) {
        fwrite(STDERR, $name . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

echo "Moment pinned visibility policy: passed\n";
