<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'install' => $root . '/database/install.sql',
    'migration' => $root . '/database/migrations/upgrade_20260801_forum_comment_threads.sql',
    'user_controller' => $root . '/app/Controllers/User/ForumController.php',
    'public_controller' => $root . '/app/Controllers/PublicApi/ForumController.php',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
}

$install = file_get_contents($files['install']);
$migration = file_get_contents($files['migration']);
$userController = file_get_contents($files['user_controller']);
$publicController = file_get_contents($files['public_controller']);

$method = static function (string $source, string $name, string $nextName): string {
    $start = strpos($source, 'function ' . $name . '(');
    $end = strpos($source, 'function ' . $nextName . '(', $start === false ? 0 : $start + 1);
    if ($start === false || $end === false || $end <= $start) return '';
    return substr($source, $start, $end - $start);
};

$showPost = $method($userController, 'showPost', 'updatePost');
$comments = $method($userController, 'comments', 'likes');
$postList = $method($userController, 'postList', 'post');
$previewIndex = $method($userController, 'fetchReplyPreviewIndexRows', 'attachReplyPreviews');
$preview = $method($userController, 'attachReplyPreviews', 'filterVisibleReplyChains');
$chainFilter = $method($userController, 'filterVisibleReplyChains', 'resolveVisibleThreadRoot');
$rootResolver = $method($userController, 'resolveVisibleThreadRoot', 'commentIndex');
$sortNormalizer = $method($userController, 'normalizeForumSort', 'forumCommentOrder');
$commentOrder = $method($userController, 'forumCommentOrder', 'forumPostOrder');
$postOrderStart = strpos($userController, 'function forumPostOrder(');
$postOrder = $postOrderStart === false ? '' : substr($userController, $postOrderStart, 900);
$commentOrderBranches = [];
if (preg_match_all("/'(hot|latest|earliest)' => '([^']+)'/", $commentOrder, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) $commentOrderBranches[$match[1]] = $match[2];
}
if (preg_match("/default => '([^']+)'/", $commentOrder, $defaultMatch)) {
    $commentOrderBranches['comprehensive'] = $defaultMatch[1];
}
$checks = [
    'forum comment query only selects columns owned by forum_comments' =>
        !str_contains($userController, 'c.content_kind'),
    'install column' => str_contains($install, '`root_comment_id` BIGINT UNSIGNED DEFAULT NULL'),
    'install index' => str_contains($install, 'idx_forum_comments_root'),
    'portable migration column' => str_contains($migration, 'root_comment_id'),
    'portable migration index' => str_contains($migration, 'idx_forum_comments_root'),
    'migration has no delimiter' => !preg_match('/\bDELIMITER\b|CREATE\s+PROCEDURE/i', $migration),
    'reply insert stores root' => str_contains($userController, 'parent_id, root_comment_id, user_id'),
    'reply root resolver' => str_contains($userController, 'resolveStoredCommentRoot'),
    'legacy root hydration' => str_contains($userController, 'hydrateCommentRoots'),
    'public API exposes root' => str_contains($publicController, 'AS root_comment_id'),
    'show post returns root page' => str_contains($showPost, 'loadRootComments')
        && str_contains($showPost, "['comment_scope'] = 'roots'"),
    'show post advertises two previews' => str_contains($showPost, "['comment_preview_limit'] = 2"),
    'comment scopes are closed whitelist' => str_contains($comments, "in_array(\$scopeInput, ['roots', 'thread'], true)")
        && str_contains($comments, "? \$scopeInput : 'roots'"),
    'legacy parent deep link maps to thread' => str_contains($comments, "\$legacyParentId > 0")
        && str_contains($comments, "\$commentId = \$legacyParentId"),
    'thread resolves comment inside tenant' => str_contains($comments, 'resolveVisibleThreadRoot')
        && str_contains($rootResolver, 'admin_id = ?')
        && str_contains($rootResolver, 'app_id = ?')
        && str_contains($rootResolver, "audit_status = 'approved' OR user_id = ?"),
    'thread is strictly root limited' => str_contains($comments, 'c.root_comment_id = ?')
        && str_contains($comments, 'parent_comment.id IS NOT NULL'),
    'thread returns resolved root' => str_contains($comments, "['resolved_root_comment_id'] = \$resolvedRootId"),
    'deep link automatically returns the page containing the target reply' =>
        str_contains($comments, '$focusedReplyPage = intdiv($focusedReplyIndex, $limit) + 1')
        && str_contains($comments, "['focused_reply_page'] = \$focusedReplyPage"),
    'thread always includes hydrated root first' => str_contains($comments, 'array_merge([$resolvedRootId], $pageIds)')
        && str_contains($comments, '$items = [$rootDetail]')
        && str_contains($comments, "\$rootDetail['reply_count'] = \$replyTotal"),
    'thread root does not consume reply page limit' => str_contains($comments, 'array_slice($threadRows, $offset, $limit)')
        && str_contains($comments, "['reply_total'] = \$replyTotal")
        && str_contains($comments, "['thread_total'] = 1 + (int) \$replyTotal")
        && str_contains($comments, "['pagination_scope'] = 'replies'"),
    'root count cannot duplicate replies' => str_contains($comments, 'SELECT COUNT(*) AS total FROM forum_comments c')
        && str_contains($comments, '(c.parent_id IS NULL OR c.parent_id = 0)')
        && !str_contains($comments, 'COUNT(DISTINCT c.id)'),
    'approved or owner visibility retained' => substr_count($comments, "audit_status = 'approved' OR c.user_id = ?") >= 1
        && str_contains($userController, "AND (c.audit_status = 'approved' OR c.user_id = ?)"),
    'reply preview is batch loaded once' => substr_count($preview, 'fetchCommentRows(') === 1
        && !str_contains($preview, 'Database::'),
    'reply preview first scans only a tenant-scoped lightweight visible graph' =>
        str_contains($preview, 'fetchReplyPreviewIndexRows($user, $postId, $rootIds)')
        && str_contains($previewIndex, 'SELECT c.id, c.parent_id, c.root_comment_id, c.is_pinned, c.pin_order')
        && str_contains($previewIndex, 'c.admin_id = ? AND c.app_id = ? AND c.post_id = ?')
        && str_contains($previewIndex, "c.audit_status = 'approved' OR c.user_id = ?")
        && str_contains($previewIndex, "parent_comment.audit_status = 'approved' OR parent_comment.user_id = ?")
        && !str_contains($previewIndex, 'c.content')
        && !str_contains($previewIndex, 'tags_json')
        && !str_contains($previewIndex, 'mentions_json')
        && !str_contains($previewIndex, 'hydrateForumCommentRows'),
    'reply count is exact after fail-closed full-chain filtering' =>
        str_contains($preview, '$replyIndexRows = self::filterVisibleReplyChains($replyIndexRows, $roots)')
        && str_contains($preview, 'foreach ($replyIndexRows as $reply)')
        && str_contains($preview, "\$roots[\$index]['reply_count']++"),
    'reply preview contains count and at most two' => str_contains($preview, "['reply_count']")
        && str_contains($preview, "['reply_preview']")
        && str_contains($preview, 'count($previewIds[$index]) < 2'),
    'only selected preview ids load full rows and receive hydration' =>
        str_contains($preview, '$selectedPreviewIds = array_keys($selectedIndexById)')
        && str_contains($preview, 'c.id IN ({$selectedPlaceholders})')
        && strpos($preview, '$selectedPreviewIds = array_keys($selectedIndexById)')
            < strpos($preview, 'self::fetchCommentRows(')
        && str_contains($preview, 'self::hydrateForumCommentRows($previewRows')
        && !str_contains($preview, 'self::hydrateForumCommentRows($replyIndexRows')
        && !str_contains($preview, '$previewRows[] = $reply'),
    'preview detail read fails closed when selected relationships change' =>
        str_contains($preview, '$selectedIndexById[$replyId]')
        && str_contains($preview, "(int) (\$row['parent_id'] ?? 0) === (int) \$expected['parent_id']")
        && str_contains($preview, "(int) (\$row['root_comment_id'] ?? 0) === (int) \$expected['root_comment_id']"),
    'broken parent chains fail closed without queries' => str_contains($chainFilter, '!isset($byId[$parentId])')
        && str_contains($chainFilter, '$parentRootId !== $rootId')
        && !str_contains($chainFilter, 'Database::'),
    'reply target display name preserved' => str_contains($userController, 'AS reply_to_name'),
    'sort whitelist defaults comprehensive' => str_contains($sortNormalizer, "['comprehensive', 'hot', 'latest', 'earliest']")
        && str_contains($sortNormalizer, ": 'comprehensive'"),
    'comment sort uses server constants' => str_contains($commentOrder, "'hot' =>")
        && str_contains($commentOrder, "'latest' =>")
        && str_contains($commentOrder, "'earliest' =>"),
    'four comment orders are distinct' => count($commentOrderBranches) === 4
        && count(array_unique($commentOrderBranches)) === 4,
    'comprehensive combines engagement and freshness' => str_contains(
        (string) ($commentOrderBranches['comprehensive'] ?? ''),
        'c.like_count DESC, c.favorite_count DESC, c.created_at DESC'
    ),
    'hot is weighted interaction without time' => str_contains(
        (string) ($commentOrderBranches['hot'] ?? ''),
        '(c.like_count * 2 + c.favorite_count * 3) DESC'
    ) && !str_contains((string) ($commentOrderBranches['hot'] ?? ''), 'created_at'),
    'post sort uses server constants' => str_contains($postOrder, "'hot' =>")
        && str_contains($postOrder, "'latest' =>")
        && str_contains($postOrder, "'earliest' =>")
        && str_contains($postList, '$sortOrder = self::forumPostOrder($sort)'),
    'post personal and official positions stay ahead of chosen sort' => str_contains(
        $postList,
        "WHEN 'bottom' THEN 1 ELSE 0 END,\n                       p.is_top DESC"
    ) && strpos($postList, 'personal_sort_order DESC') < strpos($postList, '{$sortOrder}'),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Forum comment thread contract failed: {$name}\n");
        exit(1);
    }
}

echo "Forum comment thread contract: passed\n";
