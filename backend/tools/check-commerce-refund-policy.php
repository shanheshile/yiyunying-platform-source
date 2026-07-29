<?php
declare(strict_types=1);

$path = dirname(__DIR__) . '/app/Controllers/User/CommerceController.php';
$source = file_get_contents($path);
if ($source === false) exit(2);

$required = [
    "(int) \$item['to_user_id'] === (int) \$user['id']",
    '付款人不能自行收回转账',
    '只有收款人可以退回该转账',
    '赠送人不能自行收回礼物',
    '只有收礼人可以退回该礼物',
    '拼手气红包',
    '运气王',
];

foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "资金规则缺失: {$needle}\n");
        exit(1);
    }
}

if (str_contains($source, "in_array((int) \$user['id'], [(int) \$item['from_user_id'], (int) \$item['to_user_id']], true)")) {
    fwrite(STDERR, "转账或礼物仍允许付款人自行退回\n");
    exit(1);
}

echo "转账、礼物与红包资金权限检查通过\n";
