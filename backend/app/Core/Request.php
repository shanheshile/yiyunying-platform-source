<?php
declare(strict_types=1);

namespace Yiyunying\Core;

final class Request
{
    private string $method;
    private string $path;
    private array $query;
    private array $body;
    private array $headers;
    private array $attributes = [];

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $rawPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $routeOverride = trim((string) ($_GET['__route'] ?? ''));
        if (($rawPath === '/' || $rawPath === '/index.php')
            && $routeOverride !== ''
            && $routeOverride[0] === '/'
            && strpos($routeOverride, '..') === false) {
            $rawPath = (string) parse_url($routeOverride, PHP_URL_PATH);
        }
        $basePath = (string) config('app.base_path', '');
        if ($basePath !== '' && strncmp($rawPath, $basePath, strlen($basePath)) === 0) {
            $rawPath = substr($rawPath, strlen($basePath)) ?: '/';
        }
        $this->path = '/' . ltrim(rawurldecode($rawPath), '/');
        $this->query = $_GET;
        unset($this->query['__route']);
        $this->headers = $this->readHeaders();
        $this->body = $this->readBody();
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function input(string $key, $default = null)
    {
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }
        return $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $authorization = trim((string) $this->header('authorization', ''));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) !== 1) {
            return null;
        }
        return trim($matches[1]);
    }

    public function clientIp(): string
    {
        $forwarded = $this->header('x-forwarded-for');
        if ($forwarded !== null && $forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr((string) $this->header('user-agent', ''), 0, 500);
    }

    public function setAttribute(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, $default = null)
    {
        return $this->attributes[$key] ?? $default;
    }

    public function page(): int
    {
        return max(1, (int) $this->input('page', 1));
    }

    public function limit(): int
    {
        $default = (int) config('pagination.default_limit', 20);
        $max = (int) config('pagination.max_limit', 100);
        return min($max, max(1, (int) $this->input('limit', $default)));
    }

    private function readBody(): array
    {
        $contentType = strtolower((string) $this->header('content-type', ''));
        if (strpos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            if ($raw === false || trim($raw) === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw new HttpException('JSON 请求体格式错误', 0, 400);
            }
            return $decoded;
        }
        return $_POST;
    }

    private function readHeaders(): array
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                $headers[strtolower((string) $name)] = (string) $value;
            }
        }
        foreach ($_SERVER as $name => $value) {
            if (strncmp($name, 'HTTP_', 5) === 0) {
                $header = strtolower(str_replace('_', '-', substr($name, 5)));
                $headers[$header] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return $headers;
    }
}
