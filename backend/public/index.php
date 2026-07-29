<?php
declare(strict_types=1);

use Yiyunying\Core\ApiResponse;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\LogService;

require dirname(__DIR__) . '/bootstrap.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

$origins = (array) config('app.cors_origins', ['*']);
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array('*', $origins, true)) {
    header('Access-Control-Allow-Origin: *');
} elseif ($requestOrigin !== '' && in_array($requestOrigin, $origins, true)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-App-Key, X-Requested-With, Idempotency-Key');
header('Access-Control-Max-Age: 86400');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$startedAt = microtime(true);
$request = null;
$response = null;

try {
    $request = new Request();
    /** @var \Yiyunying\Core\Router $router */
    $router = require dirname(__DIR__) . '/routes/api.php';
    $response = $router->dispatch($request);
} catch (HttpException $exception) {
    $response = Response::fromException($exception);
} catch (Throwable $exception) {
    if ($request instanceof Request) {
        LogService::error($request, $exception);
    }
    $data = (bool) config('app.debug', false)
        ? ['exception' => get_class($exception), 'message' => $exception->getMessage()]
        : [];
    $response = Response::failure('服务器内部错误', -1, 500, $data);
}

if (!$response instanceof ApiResponse) {
    $response = Response::failure('服务器内部错误', -1, 500);
}
if ($request instanceof Request) {
    LogService::api($request, $response, $startedAt);
}
Response::emit($response);
