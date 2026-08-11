<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'routes' => $root . '/routes/api.php',
    'admin' => $root . '/app/Controllers/Admin/ResourceController.php',
    'access' => $root . '/app/Services/AdminAccessService.php',
    'user' => $root . '/app/Controllers/User/ResourceController.php',
    'public' => $root . '/app/Controllers/PublicApi/ResourceController.php',
    'modules' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/domain/module/ModuleRegistry.java',
    'catalog' => dirname($root) . '/android/app/src/main/assets/api_catalog.json',
];

$source = [];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $source[$name] = (string) file_get_contents($path);
}

$catalog = json_decode($source['catalog'], true, 512, JSON_THROW_ON_ERROR);
$catalogRoutes = [];
foreach ($catalog as $route) {
    $catalogRoutes[(string) $route['method'] . ' ' . (string) $route['path']] = true;
}
$adminPrefix = '/api/admin/apps/{app_id}/resource-comments';

$checks = [
    'admin routes expose list detail hide restore and delete' =>
        str_contains($source['routes'], "get('{$adminPrefix}', [AdminResource::class, 'resourceComments'])")
        && str_contains($source['routes'], "get('{$adminPrefix}/{comment_id}', [AdminResource::class, 'showResourceComment'])")
        && str_contains($source['routes'], "put('{$adminPrefix}/{comment_id}/hide', [AdminResource::class, 'hideResourceComment'])")
        && str_contains($source['routes'], "put('{$adminPrefix}/{comment_id}/restore', [AdminResource::class, 'restoreResourceComment'])")
        && str_contains($source['routes'], "delete('{$adminPrefix}/{comment_id}', [AdminResource::class, 'deleteResourceComment'])"),
    'list and detail expose resource author parent and reply context' =>
        str_contains($source['admin'], 'public static function resourceComments(')
        && str_contains($source['admin'], 'public static function showResourceComment(')
        && str_contains($source['admin'], 'r.title AS resource_title')
        && str_contains($source['admin'], 'parent.content AS parent_content')
        && str_contains($source['admin'], 'AS reply_count')
        && substr_count($source['admin'], "MessageMediaService::hydrate(") >= 6,
    'every resource comment query is constrained to the selected tenant and matching parent resource' =>
        str_contains($source['admin'], 'r.id = c.resource_id AND r.admin_id = c.admin_id AND r.app_id = c.app_id')
        && str_contains($source['admin'], 'u.id = c.user_id AND u.admin_id = c.admin_id AND u.app_id = c.app_id')
        && str_contains($source['admin'], 'p.user_id = u.id AND p.admin_id = c.admin_id AND p.app_id = c.app_id')
        && str_contains($source['admin'], 'parent.id = c.parent_id AND parent.resource_id = c.resource_id')
        && str_contains($source['admin'], 'WHERE c.id = ? AND c.admin_id = ? AND c.app_id = ?'),
    'hide and delete use row locks and whole subtrees while restore requires visible parents' =>
        str_contains($source['admin'], 'private static function transitionResourceComment(')
        && str_contains($source['admin'], 'private static function resourceCommentSubtreeIds(')
        && str_contains($source['admin'], 'private static function assertRestorableResourceCommentParentChain(')
        && str_contains($source['admin'], "SELECT id, parent_id, status FROM resource_comments")
        && str_contains($source['admin'], 'FOR UPDATE')
        && str_contains($source['admin'], "array_chunk(\$changedIds, 500)")
        && str_contains($source['admin'], 'WHERE id IN ({$placeholders}) AND resource_id = ? AND admin_id = ? AND app_id = ?'),
    'delete is an audited soft delete and cannot bypass storage or history' =>
        str_contains($source['admin'], "'resource_comment_moderation'")
        && str_contains($source['admin'], "'hide'")
        && str_contains($source['admin'], "'restore'")
        && str_contains($source['admin'], "'delete'")
        && !preg_match('/DELETE\s+FROM\s+resource_comments/i', $source['admin'])
        && str_contains($source['admin'], "-1 => ['deleted', '已删除']"),
    'hidden and deleted comments stay out of public and normal user detail' =>
        str_contains($source['user'], 'WHERE c.resource_id = ? AND c.status = 1')
        && str_contains($source['public'], 'WHERE c.resource_id = ? AND c.status = 1'),
    'resource comment routes inherit the resource management permission' =>
        preg_match('/resource-categories\|resource-comments\|resources/', $source['access']) === 1
        && str_contains($source['access'], "=> 'resources.manage'"),
    'Android admin module is Chinese and exposes contextual moderation actions' =>
        str_contains($source['modules'], 'ModuleSpec.builder("resource_comments", "资源评论管理"')
        && str_contains($source['modules'], '.primary("content", "id")')
        && str_contains($source['modules'], '"resource_title", "parent_content", "status_label"')
        && str_contains($source['modules'], 'itemAction("查看评论详情", "GET"')
        && str_contains($source['modules'], 'itemAction("隐藏评论", "PUT"')
        && str_contains($source['modules'], 'itemAction("恢复评论", "PUT"')
        && str_contains($source['modules'], 'itemAction("删除评论", "DELETE"'),
    'generated Android catalog contains every comment management route' =>
        isset($catalogRoutes['GET ' . $adminPrefix])
        && isset($catalogRoutes['GET ' . $adminPrefix . '/{comment_id}'])
        && isset($catalogRoutes['PUT ' . $adminPrefix . '/{comment_id}/hide'])
        && isset($catalogRoutes['PUT ' . $adminPrefix . '/{comment_id}/restore'])
        && isset($catalogRoutes['DELETE ' . $adminPrefix . '/{comment_id}']),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Resource comment management contract failed: {$name}\n");
        exit(1);
    }
}

require_once $root . '/bootstrap.php';

$controller = Yiyunying\Controllers\Admin\ResourceController::class;
$subtreeMethod = new ReflectionMethod($controller, 'resourceCommentSubtreeIds');
$subtreeMethod->setAccessible(true);
$threadRows = [
    ['id' => 1, 'parent_id' => null, 'status' => 1],
    ['id' => 2, 'parent_id' => 1, 'status' => 1],
    ['id' => 3, 'parent_id' => 2, 'status' => 0],
    ['id' => 4, 'parent_id' => null, 'status' => 1],
];
if ($subtreeMethod->invoke(null, $threadRows, 1) !== [1, 2, 3]) {
    fwrite(STDERR, "Resource comment management contract failed: subtree traversal\n");
    exit(1);
}

$decorateMethod = new ReflectionMethod($controller, 'decorateResourceComment');
$decorateMethod->setAccessible(true);
$deleted = $decorateMethod->invoke(null, ['id' => 3, 'status' => -1, 'content' => '已删除内容']);
if (($deleted['status_label'] ?? '') !== '已删除' || ($deleted['can_restore'] ?? true) !== false) {
    fwrite(STDERR, "Resource comment management contract failed: Chinese deleted-state presentation\n");
    exit(1);
}

$parentMethod = new ReflectionMethod($controller, 'assertRestorableResourceCommentParentChain');
$parentMethod->setAccessible(true);
$parentMethod->invoke(null, $threadRows, ['id' => 2, 'parent_id' => 1, 'status' => 0]);
$hiddenParentRows = $threadRows;
$hiddenParentRows[1]['status'] = 0;
try {
    $parentMethod->invoke(null, $hiddenParentRows, ['id' => 3, 'parent_id' => 2, 'status' => 0]);
    fwrite(STDERR, "Resource comment management contract failed: hidden parent restore guard\n");
    exit(1);
} catch (ReflectionException $exception) {
    throw $exception;
} catch (Throwable $exception) {
    $cause = $exception instanceof ReflectionException ? $exception : ($exception->getPrevious() ?? $exception);
    if (!$cause instanceof Yiyunying\Core\HttpException || $cause->httpStatus !== 409) {
        throw $exception;
    }
}

echo "Resource comment management contract: passed\n";
