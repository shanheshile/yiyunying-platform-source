<?php
declare(strict_types=1);

use Yiyunying\Services\StorageMaintenanceService;

require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "该维护工具只能从命令行运行。\n");
    exit(2);
}

$options = getopt('', ['execute', 'dry-run', 'json']);
$execute = isset($options['execute']) && !isset($options['dry-run']);

try {
    $result = StorageMaintenanceService::run($execute);
    if (isset($options['json'])) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    printf("易运盈后台存储维护（%s）\n", $execute ? '正式执行' : '仅预览');
    printf("单批上限：%d 行\n", $result['batch_size']);
    foreach ($result['items'] as $item) {
        printf("%-28s %8d  %s\n", $item['table'], $item['rows'], $item['description']);
    }
    printf("数据库候选/已清理：%d 行\n", $result['database_rows']);
    printf("天气缓存候选/已清理：%d 个文件，%d 字节\n", $result['weather_cache_files'], $result['weather_cache_bytes']);
    if (!$execute) {
        echo "当前是安全预览；确认结果后使用 php tools/maintenance.php --execute 正式执行。\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, '维护失败：' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
