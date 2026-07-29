<?php
declare(strict_types=1);

use Yiyunying\Core\HttpException;
use Yiyunying\Services\AdminAccessService;
use Yiyunying\Services\ExchangeService;

require dirname(__DIR__) . '/bootstrap.php';

if ($argc !== 5) {
    fwrite(STDERR, "Usage: php tools/exchange-concurrency-worker.php <admin_id> <product_id> <quantity> <idempotency_key>\n");
    exit(2);
}

try {
    $admin = AdminAccessService::context((int) $argv[1]);
    AdminAccessService::assertDirectAccess($admin, '/api/admin/exchanges');
    $result = ExchangeService::exchange($admin, (int) $argv[2], (int) $argv[3], (string) $argv[4]);
    echo json_encode([
        'code' => 1,
        'data' => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (HttpException $exception) {
    echo json_encode([
        'code' => $exception->apiCode,
        'http_status' => $exception->httpStatus,
        'msg' => $exception->getMessage(),
        'data' => $exception->data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode([
        'code' => -1,
        'msg' => $exception->getMessage(),
        'exception' => get_class($exception),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(1);
}
