<?php
declare(strict_types=1);

namespace Yiyunying\Core;

use Yiyunying\Services\MaintenanceWriteGuard;

final class Router
{
    private array $routes = [];

    /**
     * Returns the registered route metadata for documentation and diagnostics.
     */
    public function routes(): array
    {
        return $this->routes;
    }

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    public function dispatch(Request $request): ApiResponse
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path(), $matches) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method()) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            $request->setAttribute('route_params', $params);
            if (array_key_exists('app_id', $params)) {
                $rawAppId = (string) $params['app_id'];
                if (!ctype_digit($rawAppId) || (int) $rawAppId <= 0) {
                    throw new HttpException('应用标识格式错误', 0, 422);
                }
                $request->setAttribute('requested_app_id', (int) $rawAppId);
            }
            MaintenanceWriteGuard::enforce($request, $params);
            $result = call_user_func($route['handler'], $request, $params);
            if (!$result instanceof ApiResponse) {
                throw new \LogicException('路由处理器必须返回 ApiResponse');
            }
            return $result;
        }

        if ($pathMatched) {
            throw new HttpException('请求方法不允许', 0, 405);
        }
        throw new HttpException('接口不存在', 404, 404);
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn(array $matches): string => '(?P<' . $matches[1] . '>[^/]+)',
            $path
        );
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'regex' => '#^' . $regex . '/?$#',
            'handler' => $handler,
        ];
    }
}
