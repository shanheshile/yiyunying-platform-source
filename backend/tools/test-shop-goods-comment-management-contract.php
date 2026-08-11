<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'routes' => $root . '/routes/api.php',
    'admin' => $root . '/app/Controllers/Admin/CommerceController.php',
    'user' => $root . '/app/Controllers/User/ShopController.php',
    'access' => $root . '/app/Services/AdminAccessService.php',
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
$adminPrefix = '/api/admin/apps/{app_id}/shop-goods-comments';

$checks = [
    'admin routes expose list detail hide restore and soft delete' =>
        str_contains($source['routes'], "get('{$adminPrefix}', [AdminCommerce::class, 'shopGoodsComments'])")
        && str_contains($source['routes'], "get('{$adminPrefix}/{comment_id}', [AdminCommerce::class, 'showShopGoodsComment'])")
        && str_contains($source['routes'], "put('{$adminPrefix}/{comment_id}/hide', [AdminCommerce::class, 'hideShopGoodsComment'])")
        && str_contains($source['routes'], "put('{$adminPrefix}/{comment_id}/restore', [AdminCommerce::class, 'restoreShopGoodsComment'])")
        && str_contains($source['routes'], "delete('{$adminPrefix}/{comment_id}', [AdminCommerce::class, 'deleteShopGoodsComment'])"),
    'list and detail include goods author parent rating reply and media context' =>
        str_contains($source['admin'], 'public static function shopGoodsComments(')
        && str_contains($source['admin'], 'public static function showShopGoodsComment(')
        && str_contains($source['admin'], 'goods.name AS goods_name')
        && str_contains($source['admin'], 'parent.content AS parent_content')
        && str_contains($source['admin'], 'AS reply_count')
        && str_contains($source['admin'], "MessageMediaService::hydrate(\$items, 'shop_goods_comment'")
        && str_contains($source['admin'], "'score_label'"),
    'all joined records remain inside the selected tenant and goods boundary' =>
        str_contains($source['admin'], 'goods.id = comment.goods_id')
        && str_contains($source['admin'], 'goods.admin_id = comment.admin_id AND goods.app_id = comment.app_id')
        && str_contains($source['admin'], 'author.id = comment.user_id')
        && str_contains($source['admin'], 'author.admin_id = comment.admin_id AND author.app_id = comment.app_id')
        && str_contains($source['admin'], 'parent.id = comment.parent_id AND parent.goods_id = comment.goods_id')
        && str_contains($source['admin'], 'comment.id = ? AND comment.admin_id = ? AND comment.app_id = ?'),
    'legacy user deletes cannot be confused with administrator hidden comments' =>
        str_contains($source['user'], 'UPDATE shop_goods_comments SET status = -1, updated_at = NOW()')
        && str_contains($source['admin'], "2 => ['hidden', '已隐藏']")
        && str_contains($source['admin'], "0 => ['legacy_deleted', '历史已删除']")
        && str_contains($source['admin'], "-1 => ['deleted', '已删除']")
        && str_contains($source['admin'], "'can_restore'] = \$status === 2"),
    'hide and delete traverse reply subtrees while restore requires visible parents' =>
        str_contains($source['admin'], 'private static function transitionShopGoodsComment(')
        && str_contains($source['admin'], 'private static function shopGoodsCommentSubtreeIds(')
        && str_contains($source['admin'], 'private static function assertRestorableShopGoodsCommentParentChain(')
        && str_contains($source['admin'], 'SELECT id, parent_id, status FROM shop_goods_comments')
        && str_contains($source['admin'], 'FOR UPDATE')
        && str_contains($source['admin'], "array_chunk(\$changedIds, 500)")
        && str_contains($source['admin'], 'WHERE id IN ({$placeholders}) AND goods_id = ? AND admin_id = ? AND app_id = ?'),
    'moderation uses audited soft status changes without hard deleting comments' =>
        str_contains($source['admin'], "'shop_goods_comment_moderation'")
        && str_contains($source['admin'], "'hide'")
        && str_contains($source['admin'], "'restore'")
        && str_contains($source['admin'], "'delete'")
        && !preg_match('/DELETE\s+FROM\s+shop_goods_comments/i', $source['admin']),
    'normal users only read active comments and replies' =>
        substr_count($source['user'], 'comment.status = 1') >= 3
        && str_contains($source['user'], 'child.status = 1')
        && str_contains($source['user'], "MessageMediaService::hydrate(\$items, 'shop_goods_comment'"),
    'comment routes inherit commerce management permission' =>
        preg_match('/shop-goods-comments\|shop-goods/', $source['access']) === 1
        && str_contains($source['access'], "=> 'commerce.manage'"),
    'Android admin module is Chinese and exposes all governance actions' =>
        str_contains($source['modules'], 'ModuleSpec.builder("shop_goods_comments", "商品评论管理"')
        && str_contains($source['modules'], '.primary("content", "id")')
        && str_contains($source['modules'], '"goods_name", "parent_content", "score_label", "status_label"')
        && str_contains($source['modules'], 'itemAction("查看评论详情", "GET"')
        && str_contains($source['modules'], 'itemAction("隐藏评论", "PUT"')
        && str_contains($source['modules'], 'itemAction("恢复评论", "PUT"')
        && str_contains($source['modules'], 'itemAction("删除评论", "DELETE"'),
    'generated Android catalog contains every comment governance route' =>
        isset($catalogRoutes['GET ' . $adminPrefix])
        && isset($catalogRoutes['GET ' . $adminPrefix . '/{comment_id}'])
        && isset($catalogRoutes['PUT ' . $adminPrefix . '/{comment_id}/hide'])
        && isset($catalogRoutes['PUT ' . $adminPrefix . '/{comment_id}/restore'])
        && isset($catalogRoutes['DELETE ' . $adminPrefix . '/{comment_id}']),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Shop goods comment management contract failed: {$name}\n");
        exit(1);
    }
}

require_once $root . '/bootstrap.php';

$controller = Yiyunying\Controllers\Admin\CommerceController::class;
$subtreeMethod = new ReflectionMethod($controller, 'shopGoodsCommentSubtreeIds');
$subtreeMethod->setAccessible(true);
$threadRows = [
    ['id' => 1, 'parent_id' => 0, 'status' => 1],
    ['id' => 2, 'parent_id' => 1, 'status' => 1],
    ['id' => 3, 'parent_id' => 2, 'status' => 2],
    ['id' => 4, 'parent_id' => 0, 'status' => 1],
];
if ($subtreeMethod->invoke(null, $threadRows, 1) !== [1, 2, 3]) {
    fwrite(STDERR, "Shop goods comment management contract failed: subtree traversal\n");
    exit(1);
}

$decorateMethod = new ReflectionMethod($controller, 'decorateShopGoodsComment');
$decorateMethod->setAccessible(true);
$hidden = $decorateMethod->invoke(null, ['id' => 2, 'status' => 2, 'score' => 5, 'content' => '待恢复']);
$legacyDeleted = $decorateMethod->invoke(null, ['id' => 3, 'status' => 0, 'content' => '历史删除']);
if (($hidden['status_label'] ?? '') !== '已隐藏' || ($hidden['can_restore'] ?? false) !== true
    || ($legacyDeleted['status_label'] ?? '') !== '历史已删除' || ($legacyDeleted['can_restore'] ?? true) !== false) {
    fwrite(STDERR, "Shop goods comment management contract failed: Chinese status presentation\n");
    exit(1);
}

$parentMethod = new ReflectionMethod($controller, 'assertRestorableShopGoodsCommentParentChain');
$parentMethod->setAccessible(true);
$parentMethod->invoke(null, $threadRows, ['id' => 2, 'parent_id' => 1, 'status' => 2]);
$hiddenParentRows = $threadRows;
$hiddenParentRows[1]['status'] = 2;
try {
    $parentMethod->invoke(null, $hiddenParentRows, ['id' => 3, 'parent_id' => 2, 'status' => 2]);
    fwrite(STDERR, "Shop goods comment management contract failed: hidden parent restore guard\n");
    exit(1);
} catch (Throwable $exception) {
    $cause = $exception->getPrevious() ?? $exception;
    if (!$cause instanceof Yiyunying\Core\HttpException || $cause->httpStatus !== 409) {
        throw $exception;
    }
}

echo "Shop goods comment management contract: passed\n";
