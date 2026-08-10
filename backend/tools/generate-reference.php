<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$root = dirname(__DIR__);
$sql = file_get_contents($root . '/database/install.sql');
if ($sql === false) {
    throw new RuntimeException('Cannot read database/install.sql');
}

preg_match_all(
    '/CREATE TABLE IF NOT EXISTS `(?<name>[^`]+)`\s*\((?<body>.*?)\) ENGINE=/s',
    $sql,
    $tableMatches,
    PREG_SET_ORDER
);

$schema = [
    '# 易运盈后台数据库结构参考',
    '',
    '> 本文件由 `php tools/generate-reference.php` 从 `database/install.sql` 生成。',
    '',
    '- 数据表：' . count($tableMatches),
    '- 字符集：`utf8mb4`',
    '- 租户边界：平台层按 `platform_id`，后台层按 `admin_id`，应用业务层按 `admin_id + app_id` 隔离',
    '',
];

foreach ($tableMatches as $table) {
    $columns = [];
    $constraints = [];
    // The Unicode modifier prevents UTF-8 continuation bytes such as 0x85 from
    // being mistaken for a standalone NEL line break inside Chinese text.
    foreach (preg_split('/\R/u', (string) $table['body']) as $line) {
        $line = rtrim(trim($line), ',');
        if ($line === '') {
            continue;
        }
        if (preg_match('/^`([^`]+)`\s+(.+)$/', $line, $column) === 1) {
            $columns[] = [$column[1], $column[2]];
        } else {
            $constraints[] = $line;
        }
    }
    $schema[] = '## `' . $table['name'] . '`';
    $schema[] = '';
    $schema[] = '| 字段 | SQL 定义 |';
    $schema[] = '| --- | --- |';
    foreach ($columns as [$name, $definition]) {
        $schema[] = '| `' . $name . '` | `' . str_replace('`', '\\`', $definition) . '` |';
    }
    $schema[] = '';
    $schema[] = '**索引与约束**';
    $schema[] = '';
    foreach ($constraints as $constraint) {
        $schema[] = '- `' . str_replace('`', '\\`', $constraint) . '`';
    }
    $schema[] = '';
}

file_put_contents($root . '/docs/SCHEMA.md', rtrim(implode("\n", $schema)) . "\n");

/** @var Yiyunying\Core\Router $router */
$router = require $root . '/routes/api.php';
$routes = $router->routes();

$routeGroups = ['platform' => [], 'public' => [], 'admin' => [], 'user' => [], 'system' => []];
foreach ($routes as $route) {
    $path = (string) $route['path'];
    if (str_starts_with($path, '/api/platform/')) {
        $group = 'platform';
    } elseif (str_starts_with($path, '/api/admin/')) {
        $group = 'admin';
    } elseif (str_starts_with($path, '/api/user/')) {
        $group = 'user';
    } elseif (str_starts_with($path, '/api/public/')) {
        $group = 'public';
    } else {
        $group = 'system';
    }
    $handler = $route['handler'];
    $routeGroups[$group][] = [
        'method' => $route['method'],
        'path' => $path,
        'handler' => is_array($handler) ? $handler[0] . '::' . $handler[1] : 'callable',
    ];
}

$routeDoc = [
    '# 易运盈后台实际路由参考',
    '',
    '> 本文件由 `php tools/generate-reference.php` 从 `routes/api.php` 生成。功能说明与参数见 `API_FULL.md`。',
    '',
    '- 注册路由：' . count($routes),
    '- 四级角色：1 级平台所有者、2 级授权平台、3 级 admin、4 级 user',
    '',
];
$titles = [
    'platform' => '平台治理接口',
    'admin' => '管理员接口',
    'user' => '用户接口',
    'public' => '公开接口',
    'system' => '系统接口',
];
foreach (['platform', 'admin', 'user', 'public', 'system'] as $group) {
    $routeDoc[] = '## ' . $titles[$group] . '（' . count($routeGroups[$group]) . '）';
    $routeDoc[] = '';
    $routeDoc[] = '| 方法 | 路径 | 处理器 |';
    $routeDoc[] = '| --- | --- | --- |';
    foreach ($routeGroups[$group] as $route) {
        $routeDoc[] = '| `' . $route['method'] . '` | `' . $route['path'] . '` | `' . $route['handler'] . '` |';
    }
    $routeDoc[] = '';
}
file_put_contents($root . '/docs/ROUTES.md', rtrim(implode("\n", $routeDoc)) . "\n");

echo 'Generated docs/SCHEMA.md (' . count($tableMatches) . " tables)\n";
echo 'Generated docs/ROUTES.md (' . count($routes) . " routes)\n";
