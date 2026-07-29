<?php
declare(strict_types=1);

namespace Yiyunying\Core;

final class Response
{
    private const PUBLIC_KEY_MAP = [
        'price_integral' => 'price_balance',
        'unit_price_integral' => 'unit_price_balance',
        'total_integral' => 'total_balance',
        'amount_integral' => 'amount_balance',
        'reward_integral' => 'reward_balance',
        'integral_change' => 'balance_change',
        'admin_integral' => 'admin_balance',
        'received_integral' => 'received_balance',
        'spent_integral' => 'spent_balance',
        'point_exchange' => 'balance_exchange',
        'point_exchange_count' => 'balance_exchange_count',
        'point_exchange_orders' => 'balance_exchange_orders',
        'point_exchange_integral' => 'balance_exchange_amount',
        'point_refund_count' => 'balance_refund_count',
        'point_refund_integral' => 'balance_refund_amount',
    ];

    public static function success(array $data = [], string $message = '操作成功', int $httpStatus = 200): ApiResponse
    {
        return new ApiResponse([
            'code' => 1,
            'msg' => $message,
            'data' => self::publicData($data),
            'trace_id' => Trace::id(),
        ], $httpStatus);
    }

    public static function failure(string $message, int $code, int $httpStatus, array $data = []): ApiResponse
    {
        return new ApiResponse([
            'code' => $code,
            'msg' => $message,
            'data' => self::publicData($data),
            'trace_id' => Trace::id(),
        ], $httpStatus);
    }

    public static function fromException(HttpException $exception): ApiResponse
    {
        return self::failure(
            $exception->getMessage(),
            $exception->apiCode,
            $exception->httpStatus,
            $exception->data
        );
    }

    public static function emit(ApiResponse $response): void
    {
        http_response_code($response->httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $response->body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    public static function publicData(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $publicKey = is_string($key) ? (self::PUBLIC_KEY_MAP[$key] ?? $key) : $key;
            $publicValue = is_array($value) ? self::publicData($value) : $value;
            if (!array_key_exists($publicKey, $result) || $publicKey === $key) {
                $result[$publicKey] = $publicValue;
            }
        }
        return $result;
    }
}
