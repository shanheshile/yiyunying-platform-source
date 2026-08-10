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

    public static function file(string $path, string $mimeType = 'application/octet-stream'): ApiResponse
    {
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false) throw new HttpException('媒体文件不存在', 404, 404);
        $offset = 0;
        $length = (int) $size;
        $status = 200;
        $range = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) === 1) {
            if ($matches[1] === '' && $matches[2] === '') throw new HttpException('媒体范围无效', 416, 416);
            if ($matches[1] === '') {
                $suffix = max(1, (int) $matches[2]);
                $offset = max(0, (int) $size - $suffix);
                $end = (int) $size - 1;
            } else {
                $offset = (int) $matches[1];
                $end = $matches[2] === '' ? (int) $size - 1 : min((int) $matches[2], (int) $size - 1);
            }
            if ($offset < 0 || $offset >= (int) $size || $end < $offset) {
                throw new HttpException('媒体范围超出文件大小', 416, 416);
            }
            $length = $end - $offset + 1;
            $status = 206;
        }
        $safeMime = preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mimeType) === 1
            ? $mimeType : 'application/octet-stream';
        $response = new ApiResponse([], $status);
        $response->filePath = $path;
        $response->fileOffset = $offset;
        $response->fileLength = $length;
        $response->headers = [
            'Content-Type' => $safeMime,
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=300, no-transform',
            'Content-Disposition' => 'inline',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($status === 206) {
            $response->headers['Content-Range'] = 'bytes ' . $offset . '-' . ($offset + $length - 1) . '/' . $size;
        }
        return $response;
    }

    public static function emit(ApiResponse $response): void
    {
        http_response_code($response->httpStatus);
        if ($response->filePath !== null) {
            foreach ($response->headers as $name => $value) header($name . ': ' . $value);
            $stream = fopen($response->filePath, 'rb');
            if ($stream === false) return;
            try {
                if ($response->fileOffset > 0) fseek($stream, $response->fileOffset);
                $remaining = $response->fileLength;
                while ($remaining > 0 && !feof($stream)) {
                    $chunk = fread($stream, min(1048576, $remaining));
                    if ($chunk === false || $chunk === '') break;
                    echo $chunk;
                    $remaining -= strlen($chunk);
                }
            } finally {
                fclose($stream);
            }
            return;
        }
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
