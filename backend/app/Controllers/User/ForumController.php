<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\User;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Validator;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\ContentTagService;
use Yiyunying\Services\ForumExperienceService;
use Yiyunying\Services\ForumModeratorService;
use Yiyunying\Services\ForumTaxonomyService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\MessageMediaService;
use Yiyunying\Services\MessageForwardService;
use Yiyunying\Services\NotificationService;
use Yiyunying\Services\RewardRuleService;
use Yiyunying\Services\WalletService;

final class ForumController
{
    public static function plates(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $where = ['app_id = ?', 'status = 1'];
        $query = [(int) $user['app_id']];
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(name LIKE ? OR description LIKE ? OR CAST(id AS CHAR) LIKE ?)';
            foreach (range(1, 3) as $_) $query[] = '%' . $keyword . '%';
        }
        array_unshift($query, (int) $user['id'], (int) $user['id']);
        return Response::success(['items' => Database::all(
            "SELECT plate.id, plate.name, plate.icon, plate.description,
                    (SELECT COUNT(*) FROM forum_categories category
                     WHERE category.plate_id = plate.id AND category.status = 1) AS category_count,
                    (SELECT COUNT(*) FROM forum_posts post
                     WHERE post.plate_id = plate.id AND post.status = 1 AND post.deleted_at IS NULL) AS post_count,
                    COALESCE(personal.position, 'normal') AS personal_position,
                    COALESCE(personal.sort_order, 0) AS personal_sort_order,
                    CASE WHEN moderator.id IS NULL THEN 0 ELSE 1 END AS is_moderator
             FROM forum_plates plate
             LEFT JOIN forum_personal_positions personal
               ON personal.user_id = ? AND personal.target_type = 'plate' AND personal.target_id = plate.id
             LEFT JOIN forum_moderators moderator
               ON moderator.user_id = ? AND moderator.plate_id = plate.id AND moderator.status = 1
             WHERE " . str_replace(['app_id', 'status', 'name', 'description', 'CAST(id AS CHAR)'],
                 ['plate.app_id', 'plate.status', 'plate.name', 'plate.description', 'CAST(plate.id AS CHAR)'], implode(' AND ', $where)) . "
             ORDER BY CASE COALESCE(personal.position, 'normal') WHEN 'bottom' THEN 1 ELSE 0 END,
                      CASE COALESCE(personal.position, 'normal') WHEN 'top' THEN 0 ELSE 1 END,
                      personal.sort_order DESC, plate.sort_order DESC, plate.id",
            $query
        )]);
    }

    public static function posts(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        return Response::success(self::postList($request, (int) $user['app_id'], (int) $user['id']));
    }

    public static function createPost(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        AuthService::ensureNotBanned($user, ['all', 'forum', 'post']);
        $data = $request->all();
        Validator::required($data, ['plate_id', 'title']);
        if (Database::one('SELECT id FROM forum_plates WHERE id = ? AND app_id = ? AND status = 1', [(int) $data['plate_id'], (int) $user['app_id']]) === null) {
            throw new HttpException('论坛板块不存在', 404, 404);
        }
        $audit = AppService::setting((int) $user['app_id'], 'forum_post_audit', false) ? 'pending' : 'approved';
        $mediaData = self::withLegacyImages($data);
        $sections = $data['sections'] ?? [];
        if (is_string($sections)) $sections = json_decode($sections, true);
        if (!is_array($sections)) throw new HttpException('sections 必须是数组', 0, 422);
        if ($sections !== [] && trim((string) ($mediaData['content'] ?? '')) === '' && empty($mediaData['attachments'])) {
            $mediaData['content'] = '本帖包含分节内容，请按顺序阅读。';
        }
        $payload = MessageMediaService::userPayload($user, $mediaData);
        $images = $data['images'] ?? [];
        $categoryId = ForumTaxonomyService::categoryId(
            (int) $user['admin_id'], (int) $user['app_id'], (int) $data['plate_id'], $data['category_id'] ?? null
        );
        $tagsJson = ContentTagService::encode(ForumTaxonomyService::normalizeTags(
            (int) $user['app_id'], (int) $data['plate_id'], $categoryId, $data['tags'] ?? []
        ));
        $result = Database::transaction(static function () use ($user, $data, $categoryId, $payload, $images, $tagsJson, $audit, $sections): array {
            $id = Database::insert(
                'INSERT INTO forum_posts
                 (admin_id, app_id, plate_id, category_id, user_id, title, content, images_json, tags_json, audit_status, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $data['plate_id'], $categoryId, (int) $user['id'],
                    Validator::string($data['title'], 'title', 1, 120), (string) $payload['content'],
                    json_encode((array) $images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $tagsJson, $audit,
                ]
            );
            MessageMediaService::save('forum_post', $id, $payload);
            $sectionIds = ForumExperienceService::createSections($user, $id, $sections);
            return ['post_id' => $id, 'section_ids' => $sectionIds];
        });
        $id = (int) $result['post_id'];
        LogService::userOperation($request, $user, 'forum', 'post_create', $id);
        $rewardResult = null;
        if ($audit === 'approved') {
            $rewardResult = RewardRuleService::trigger(
                $user,
                'forum_post_create',
                'forum_post',
                $id,
                [
                    'approved' => true,
                    'status' => 'approved',
                    'content' => trim((string) $data['title'] . "\n" . (string) $payload['content']),
                    'plate_id' => (int) $data['plate_id'],
                    'category_id' => $categoryId,
                ],
                'user',
                (int) $user['id']
            );
        }
        return Response::success($result + ['audit_status' => $audit, 'reward_result' => $rewardResult], '帖子发布成功', 201);
    }

    public static function showPost(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $post = self::post((int) $user['app_id'], (int) $params['post_id']);
        $view = ForumExperienceService::recordView($request, $post, $user);
        foreach (['view_count', 'unique_view_count', 'heat_score', 'hot_label'] as $field) {
            if (array_key_exists($field, $view)) $post[$field] = $view[$field];
        }
        $paid = Database::one('SELECT * FROM forum_paid_contents WHERE post_id = ? AND status = 1', [(int) $post['id']]);
        if ($paid !== null) {
            $purchased = (int) $post['user_id'] === (int) $user['id'] || Database::one('SELECT id FROM forum_post_purchases WHERE post_id = ? AND buyer_user_id = ?', [(int) $post['id'], (int) $user['id']]) !== null;
            $post['paid_content'] = true;
            $post['paid_price_balance'] = (int) $paid['price_integral'];
            $post['purchased'] = $purchased;
            if (!$purchased) $post['content'] = (string) $paid['preview_content'];
        } else {
            $post['paid_content'] = false;
            $post['purchased'] = true;
        }
        if ((bool) $post['purchased']) {
            $post = MessageMediaService::hydrate([$post], 'forum_post', (int) $user['app_id'])[0];
            $post = MessageForwardService::hydrate([$post], 'forum_post', (int) $user['app_id'])[0];
        } else {
            $post['attachments'] = [];
            $post['attachment_count'] = 0;
            $post['has_media'] = false;
            $post['attachments_locked'] = true;
        }
        $post['sections'] = ForumExperienceService::sections($post, $user);
        $post['has_sections'] = $post['sections'] !== [];
        $post['comments'] = Database::all(
            "SELECT c.id, c.parent_id, c.user_id, c.content, c.tags_json, c.audit_status, c.audit_reason,
                    c.is_pinned, c.pin_order, c.like_count, c.favorite_count, c.created_at, c.updated_at,
                    u.uid, p.nickname, p.avatar,
                    CASE WHEN liked.id IS NULL THEN 0 ELSE 1 END AS liked,
                    CASE WHEN favorite.id IS NULL THEN 0 ELSE 1 END AS favorited
             FROM forum_comments c INNER JOIN users u ON u.id = c.user_id
             LEFT JOIN user_profiles p ON p.user_id = c.user_id
             LEFT JOIN forum_likes liked ON liked.app_id = c.app_id AND liked.user_id = ?
               AND liked.target_type = 'comment' AND liked.target_id = c.id
             LEFT JOIN forum_content_favorites favorite ON favorite.app_id = c.app_id AND favorite.user_id = ?
               AND favorite.target_type = 'comment' AND favorite.target_id = c.id
             WHERE c.post_id = ? AND c.status = 1 AND (c.audit_status = ? OR c.user_id = ?)
             ORDER BY c.is_pinned DESC, c.pin_order DESC, c.id ASC LIMIT 500",
            [(int) $user['id'], (int) $user['id'], (int) $post['id'], 'approved', (int) $user['id']]
        );
        $post['comments'] = ContentTagService::hydrate($post['comments']);
        $post['comments'] = MessageMediaService::hydrate($post['comments'], 'forum_comment', (int) $user['app_id']);
        $post['comments'] = MessageForwardService::hydrate($post['comments'], 'forum_comment', (int) $user['app_id']);
        $post['liked'] = Database::one(
            'SELECT id FROM forum_likes WHERE app_id = ? AND user_id = ? AND target_type = ? AND target_id = ?',
            [(int) $user['app_id'], (int) $user['id'], 'post', (int) $post['id']]
        ) !== null;
        $post['favorited'] = Database::one('SELECT id FROM forum_favorites WHERE post_id = ? AND user_id = ?', [(int) $post['id'], (int) $user['id']]) !== null;
        return Response::success(['post' => $post]);
    }

    public static function updatePost(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $postId = (int) $params['post_id'];
        $post = Database::one(
            'SELECT * FROM forum_posts WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
            [$postId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        $isOwner = $post !== null && (int) $post['user_id'] === (int) $user['id'];
        if ($post === null || (!$isOwner && !ForumModeratorService::canManage($user, (int) $post['plate_id'], 'edit_posts'))) {
            throw new HttpException('帖子不存在或无权修改', 404, 404);
        }
        $images = $request->input('images', json_decode((string) $post['images_json'], true) ?: []);
        $all = $request->all();
        $categoryId = array_key_exists('category_id', $all)
            ? ForumTaxonomyService::categoryId((int) $user['admin_id'], (int) $user['app_id'], (int) $post['plate_id'], $request->input('category_id'))
            : ($post['category_id'] === null ? null : (int) $post['category_id']);
        $tagsJson = array_key_exists('tags', $all)
            ? ContentTagService::encode(ForumTaxonomyService::normalizeTags(
                (int) $user['app_id'], (int) $post['plate_id'], $categoryId, $request->input('tags')
            ))
            : (string) ($post['tags_json'] ?? '[]');
        $hasMediaChange = $request->input('attachments') !== null || $request->input('images') !== null;
        $payload = null;
        if ($hasMediaChange) {
            $mediaData = self::withLegacyImages($request->all());
            $mediaData['content'] = (string) $request->input('content', $post['content']);
            $payload = MessageMediaService::userPayload($user, $mediaData);
        }
        $audit = $isOwner && AppService::setting((int) $user['app_id'], 'forum_post_audit', false) ? 'pending' : 'approved';
        Database::transaction(static function () use ($request, $post, $postId, $categoryId, $images, $tagsJson, $payload, $audit): void {
            Database::execute(
                'UPDATE forum_posts SET category_id = ?, title = ?, content = ?, images_json = ?, tags_json = ?,
                 audit_status = ?, audit_reason = ?, audited_by = NULL, audited_at = NULL, updated_at = NOW() WHERE id = ?',
                [
                    $categoryId,
                    Validator::string($request->input('title', $post['title']), 'title', 1, 120),
                    $payload === null ? (string) $request->input('content', $post['content']) : (string) $payload['content'],
                    json_encode((array) $images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $tagsJson, $audit, '', $postId,
                ]
            );
            if ($payload !== null) MessageMediaService::replace('forum_post', $postId, $payload);
        });
        LogService::userOperation($request, $user, 'forum', $isOwner ? 'post_update' : 'moderator_post_update', $postId, ['plate_id' => (int) $post['plate_id']]);
        return Response::success(['audit_status' => $audit], $audit === 'pending' ? '帖子修改成功，等待审核' : '帖子修改成功');
    }

    public static function deletePost(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $postId = (int) $params['post_id'];
        $post = Database::one(
            'SELECT id, user_id, plate_id FROM forum_posts WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
            [$postId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        $isOwner = $post !== null && (int) $post['user_id'] === (int) $user['id'];
        if ($post === null || (!$isOwner && !ForumModeratorService::canManage($user, (int) $post['plate_id'], 'delete_posts'))) {
            throw new HttpException('帖子不存在或无权删除', 404, 404);
        }
        Database::execute(
            'UPDATE forum_posts SET status = -1, deleted_at = NOW(), updated_at = NOW() WHERE id = ? AND deleted_at IS NULL',
            [$postId]
        );
        LogService::userOperation($request, $user, 'forum', $isOwner ? 'post_delete' : 'moderator_post_delete', $postId, ['plate_id' => (int) $post['plate_id']]);
        return Response::success([], '帖子已删除');
    }

    public static function comment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        AuthService::ensureNotBanned($user, ['all', 'forum', 'comment']);
        $post = self::post((int) $user['app_id'], (int) $params['post_id']);
        if ((int) $post['is_locked'] === 1) {
            throw new HttpException('帖子已锁定，不能评论', 403, 403);
        }
        $payload = MessageMediaService::userPayload($user, $request->all());
        if (mb_strlen((string) $payload['content']) > 5000) throw new HttpException('评论正文不能超过 5000 个字符', 0, 422);
        $tagsJson = ContentTagService::encode($request->input('tags', []));
        $parentId = (int) $request->input('parent_id', 0);
        $audit = AppService::setting((int) $user['app_id'], 'forum_comment_audit', false) ? 'pending' : 'approved';
        $receiverId = (int) ($post['user_id'] ?? 0);
        if ($parentId > 0) {
            $parent = Database::one(
                'SELECT user_id FROM forum_comments
                 WHERE id = ? AND post_id = ? AND status = 1 AND (audit_status = ? OR user_id = ?)',
                [$parentId, (int) $post['id'], 'approved', (int) $user['id']]
            );
            if ($parent === null) throw new HttpException('回复的评论不存在', 404, 404);
            $receiverId = (int) $parent['user_id'];
        }
        $id = Database::transaction(static function () use ($user, $post, $payload, $parentId, $audit, $tagsJson): int {
            $id = Database::insert(
                'INSERT INTO forum_comments
                 (admin_id, app_id, post_id, parent_id, user_id, content, tags_json, audit_status, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $post['id'],
                    $parentId > 0 ? $parentId : null, (int) $user['id'], (string) $payload['content'], $tagsJson, $audit,
                ]
            );
            MessageMediaService::save('forum_comment', $id, $payload);
            if ($audit === 'approved') {
                Database::execute(
                    'UPDATE forum_posts SET comment_count = comment_count + 1, last_activity_at = NOW(), updated_at = NOW() WHERE id = ?',
                    [(int) $post['id']]
                );
            }
            return $id;
        });
        ForumExperienceService::refreshHeat((int) $post['id'], (int) $user['app_id']);
        if ($audit === 'approved' && $receiverId > 0 && $receiverId !== (int) $user['id']) {
            $receiver = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], $receiverId);
            if ($receiver !== null) NotificationService::send(
                $receiver, $parentId > 0 ? 'forum_reply' : 'forum_comment',
                $parentId > 0 ? '论坛评论收到回复' : '帖子收到新评论',
                '《' . (string) $post['title'] . '》有了新的互动',
                ['post_id' => (int) $post['id'], 'comment_id' => $id, 'user_id' => (int) $user['id']]
            );
        }
        if ($audit === 'approved') {
            $mentions = $request->input('mentions', []);
            if (!is_array($mentions)) $mentions = [];
            $mentionIds = array_values(array_unique(array_filter(array_map('intval', $mentions),
                static fn (int $mentionedId): bool => $mentionedId > 0
                    && $mentionedId !== (int) $user['id'] && $mentionedId !== $receiverId)));
            $senderName = trim((string) ($user['nickname'] ?? $user['account'] ?? '用户'));
            foreach ($mentionIds as $mentionedId) {
                $mentioned = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], $mentionedId);
                if ($mentioned === null) continue;
                NotificationService::send(
                    $mentioned,
                    'forum_mention',
                    '论坛中有人提到你',
                    ($senderName === '' ? '用户' : $senderName) . ' 在《' . (string) $post['title'] . '》中提到了你',
                    [
                        'post_id' => (int) $post['id'],
                        'post_title' => (string) $post['title'],
                        'comment_id' => $id,
                        'sender_user_id' => (int) $user['id'],
                        'sender_name' => $senderName === '' ? '用户' : $senderName,
                    ]
                );
            }
        }
        $rewardResult = null;
        if ($audit === 'approved') {
            $rewardResult = RewardRuleService::trigger(
                $user,
                $parentId > 0 ? 'reply_create' : 'comment_create',
                'forum_comment',
                $id,
                [
                    'approved' => true,
                    'status' => 'approved',
                    'content' => (string) $payload['content'],
                    'post_id' => (int) $post['id'],
                    'parent_id' => $parentId,
                ],
                'user',
                (int) $user['id']
            );
        }
        return Response::success(
            ['comment_id' => $id, 'audit_status' => $audit, 'reward_result' => $rewardResult],
            $audit === 'pending' ? '评论已提交，等待审核' : '评论成功',
            201
        );
    }

    public static function like(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $post = self::post((int) $user['app_id'], (int) $params['post_id']);
        $liked = ForumExperienceService::toggleLike($user, 'post', (int) $post['id']);
        if ($liked && (int) ($post['user_id'] ?? 0) > 0 && (int) $post['user_id'] !== (int) $user['id']) {
            $receiver = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], (int) $post['user_id']);
            if ($receiver !== null) NotificationService::send(
                $receiver, 'forum_post_like', '帖子收到点赞', '《' . (string) $post['title'] . '》收到一个新点赞',
                ['post_id' => (int) $post['id'], 'user_id' => (int) $user['id']]
            );
        }
        return Response::success(['liked' => $liked], $liked ? '点赞成功' : '已取消点赞');
    }

    public static function favorite(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $post = self::post((int) $user['app_id'], (int) $params['post_id']);
        $favorited = ForumExperienceService::toggleFavorite($user, 'post', (int) $post['id']);
        if ($favorited && (int) ($post['user_id'] ?? 0) > 0 && (int) $post['user_id'] !== (int) $user['id']) {
            $receiver = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], (int) $post['user_id']);
            if ($receiver !== null) NotificationService::send(
                $receiver, 'forum_post_favorite', '帖子被收藏', '《' . (string) $post['title'] . '》被用户收藏',
                ['post_id' => (int) $post['id'], 'user_id' => (int) $user['id']]
            );
        }
        return Response::success(['favorited' => $favorited], $favorited ? '收藏成功' : '已取消收藏');
    }

    public static function favorites(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $items = Database::all(
            'SELECT p.*, f.created_at AS favorited_at, up.nickname, up.avatar
             FROM forum_favorites f INNER JOIN forum_posts p ON p.id = f.post_id
             LEFT JOIN user_profiles up ON up.user_id = p.user_id
             WHERE f.app_id = ? AND f.user_id = ? AND p.status = 1 AND p.deleted_at IS NULL ORDER BY f.id DESC',
            [(int) $user['app_id'], (int) $user['id']]
        );
        return Response::success(['items' => MessageMediaService::hydrate($items, 'forum_post', (int) $user['app_id'])]);
    }

    public static function comments(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $post = self::post((int) $user['app_id'], (int) $params['post_id']);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['c.post_id = ?', 'c.status = 1', '(c.audit_status = ? OR c.user_id = ?)'];
        $query = [(int) $post['id'], 'approved', (int) $user['id']];
        if ((int) $request->input('parent_id', 0) > 0) {
            $where[] = 'c.parent_id = ?';
            $query[] = (int) $request->input('parent_id');
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM forum_comments c WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT c.id, c.parent_id, c.user_id, c.content, c.tags_json, c.audit_status, c.audit_reason,
                    c.is_pinned, c.pin_order, c.like_count, c.favorite_count,
                    c.created_at, c.updated_at, u.uid, p.nickname, p.avatar,
                    CASE WHEN liked.id IS NULL THEN 0 ELSE 1 END AS liked,
                    CASE WHEN favorite.id IS NULL THEN 0 ELSE 1 END AS favorited
             FROM forum_comments c INNER JOIN users u ON u.id = c.user_id
             LEFT JOIN user_profiles p ON p.user_id = c.user_id
             LEFT JOIN forum_likes liked ON liked.app_id = c.app_id AND liked.user_id = ?
               AND liked.target_type = 'comment' AND liked.target_id = c.id
             LEFT JOIN forum_content_favorites favorite ON favorite.app_id = c.app_id AND favorite.user_id = ?
               AND favorite.target_type = 'comment' AND favorite.target_id = c.id
             WHERE {$whereSql} ORDER BY c.is_pinned DESC, c.pin_order DESC, c.id ASC LIMIT {$limit} OFFSET {$offset}",
            array_merge([(int) $user['id'], (int) $user['id']], $query)
        );
        $items = ContentTagService::hydrate($items);
        $items = MessageMediaService::hydrate($items, 'forum_comment', (int) $user['app_id']);
        $items = MessageForwardService::hydrate($items, 'forum_comment', (int) $user['app_id']);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function likes(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $post = self::post((int) $user['app_id'], (int) $params['post_id']);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $query = [(int) $user['app_id'], 'post', (int) $post['id']];
        $total = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM forum_likes WHERE app_id = ? AND target_type = ? AND target_id = ?',
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT l.id, l.user_id, l.created_at, u.uid, u.account, p.nickname, p.avatar, p.signature
             FROM forum_likes l INNER JOIN users u ON u.id = l.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE l.app_id = ? AND l.target_type = ? AND l.target_id = ?
             ORDER BY l.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function myPosts(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        return Response::success(self::postCollection(
            $request, $user, '', 'p.user_id = ?', [(int) $user['id']], false, true
        ));
    }

    public static function purchasedPosts(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        return Response::success(self::postCollection(
            $request,
            $user,
            'INNER JOIN forum_post_purchases collection ON collection.post_id = p.id',
            'collection.buyer_user_id = ?',
            [(int) $user['id']],
            true,
            true
        ));
    }

    public static function followingPosts(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        return Response::success(self::postCollection(
            $request,
            $user,
            'INNER JOIN user_follows collection ON collection.followed_user_id = p.user_id AND collection.app_id = p.app_id',
            'collection.follower_user_id = ?',
            [(int) $user['id']],
            true,
            false
        ));
    }

    public static function likedPosts(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        return Response::success(self::postCollection(
            $request,
            $user,
            "INNER JOIN forum_likes collection ON collection.target_id = p.id AND collection.target_type = 'post' AND collection.app_id = p.app_id",
            'collection.user_id = ?',
            [(int) $user['id']],
            true,
            false
        ));
    }

    public static function reportTags(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        return Response::success(['items' => Database::all(
            'SELECT id, name, description, sort_order FROM forum_report_tags
             WHERE admin_id = ? AND app_id = ? AND status = 1 ORDER BY sort_order DESC, id ASC',
            [(int) $user['admin_id'], (int) $user['app_id']]
        )]);
    }

    public static function myReports(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $query = [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']];
        $total = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM forum_reports WHERE admin_id = ? AND app_id = ? AND user_id = ?',
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT r.id, r.target_type, r.target_id, r.reason, r.status, r.handled_at, r.created_at,
                    t.name AS report_tag_name,
                    CASE WHEN r.target_type = 'post' THEN p.title ELSE LEFT(c.content, 120) END AS target_summary
             FROM forum_reports r
             LEFT JOIN forum_report_tags t ON t.id = r.report_tag_id AND t.app_id = r.app_id
             LEFT JOIN forum_posts p ON r.target_type = 'post' AND p.id = r.target_id
             LEFT JOIN forum_comments c ON r.target_type = 'comment' AND c.id = r.target_id
             WHERE r.admin_id = ? AND r.app_id = ? AND r.user_id = ?
             ORDER BY r.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $statusNames = ['pending' => '待处理', 'approved' => '已确认', 'rejected' => '已驳回', 'handled' => '已处理'];
        foreach ($items as &$item) {
            $item['target_type_name'] = $item['target_type'] === 'post' ? '帖子' : '评论';
            $item['status_name'] = $statusNames[$item['status']] ?? '处理中';
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function history(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $items = Database::all(
            'SELECT p.*, h.view_count AS my_view_count, h.last_viewed_at, up.nickname, up.avatar
             FROM forum_view_history h INNER JOIN forum_posts p ON p.id = h.post_id
             LEFT JOIN user_profiles up ON up.user_id = p.user_id
             WHERE h.user_id = ? AND p.app_id = ? AND p.status = 1 AND p.deleted_at IS NULL ORDER BY h.last_viewed_at DESC LIMIT 1000',
            [(int) $user['id'], (int) $user['app_id']]
        );
        return Response::success(['items' => MessageMediaService::hydrate($items, 'forum_post', (int) $user['app_id'])]);
    }

    public static function reward(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        if (!AppService::setting((int) $user['app_id'], 'forum_reward_enabled', true)) throw new HttpException('管理员已关闭论坛打赏', 403, 403);
        $post = self::post((int) $user['app_id'], (int) $params['post_id']);
        $amount = Validator::integer($request->input('balance'), 'balance', 1, 1000000000);
        $targetType = trim((string) $request->input('target_type', 'post')); $targetId = (int) $request->input('target_id', $post['id']); $toUserId = (int) $post['user_id'];
        if ($targetType === 'comment') {
            $comment = Database::one('SELECT user_id FROM forum_comments WHERE id = ? AND post_id = ? AND status = 1', [$targetId, (int) $post['id']]);
            if ($comment === null) throw new HttpException('评论不存在', 404, 404); $toUserId = (int) $comment['user_id'];
        } elseif ($targetType !== 'post') throw new HttpException('target_type 仅支持 post 或 comment', 0, 422);
        if ($toUserId === (int) $user['id']) throw new HttpException('不能打赏自己', 0, 422);
        $receiver = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], $toUserId); if ($receiver === null) throw new HttpException('收款用户不存在', 404, 404);
        $id = Database::transaction(static function () use ($user, $receiver, $amount, $targetType, $targetId): int {
            $asset = WalletService::requireActivityEnabled((int) $user['app_id']);
            WalletService::adjust($user, $asset, -$amount, 'forum_reward_send', $targetType, $targetId, '论坛打赏');
            WalletService::adjust($receiver, $asset, $amount, 'forum_reward_receive', $targetType, $targetId, '收到论坛打赏');
            return Database::insert('INSERT INTO forum_rewards (app_id, from_user_id, to_user_id, target_type, target_id, integral, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())', [(int) $user['app_id'], (int) $user['id'], (int) $receiver['id'], $targetType, $targetId, $amount]);
        });
        NotificationService::send($receiver, 'forum_reward', '收到论坛打赏', '你收到了 ' . $amount . ' 余额打赏', [
            'reward_id' => $id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'post_id' => (int) $post['id'],
            'comment_id' => $targetType === 'comment' ? $targetId : 0,
        ]);
        return Response::success(['reward_id' => $id, 'balance' => $amount], '打赏成功', 201);
    }

    public static function setPaidContent(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        if (!AppService::setting((int) $user['app_id'], 'forum_paid_content_enabled', true)) throw new HttpException('管理员已关闭付费内容', 403, 403);
        $postId = (int) $params['post_id'];
        if (Database::one('SELECT id FROM forum_posts WHERE id = ? AND app_id = ? AND user_id = ? AND status = 1 AND deleted_at IS NULL', [$postId, (int) $user['app_id'], (int) $user['id']]) === null) throw new HttpException('帖子不存在或无权设置', 404, 404);
        $price = Validator::integer($request->input('price_balance'), 'price_balance', 1, 1000000000); $preview = Validator::string($request->input('preview_content', ''), 'preview_content', 1, 5000);
        Database::execute('INSERT INTO forum_paid_contents (post_id, price_integral, preview_content, status, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE price_integral = VALUES(price_integral), preview_content = VALUES(preview_content), status = 1, updated_at = NOW()', [$postId, $price, $preview]);
        return Response::success(['post_id' => $postId, 'price_balance' => $price], '付费内容规则已保存');
    }

    public static function buyPaidContent(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request); $postId = (int) $params['post_id'];
        $result = Database::transaction(static function () use ($user, $postId): array {
            $paid = Database::one('SELECT pc.*, p.user_id AS seller_user_id FROM forum_paid_contents pc INNER JOIN forum_posts p ON p.id = pc.post_id WHERE pc.post_id = ? AND p.app_id = ? AND pc.status = 1 AND p.status = 1 FOR UPDATE', [$postId, (int) $user['app_id']]);
            if ($paid === null) throw new HttpException('付费内容不存在', 404, 404);
            if ((int) $paid['seller_user_id'] === (int) $user['id']) return ['already_owned' => true, 'price_balance' => 0];
            if (Database::one('SELECT id FROM forum_post_purchases WHERE post_id = ? AND buyer_user_id = ?', [$postId, (int) $user['id']])) return ['already_owned' => true, 'price_balance' => (int) $paid['price_integral']];
            $seller = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], (int) $paid['seller_user_id']); if ($seller === null) throw new HttpException('内容作者不存在', 404, 404);
            $price = (int) $paid['price_integral']; $asset = WalletService::requireActivityEnabled((int) $user['app_id']); WalletService::adjust($user, $asset, -$price, 'forum_paid_buy', 'post', $postId, '购买论坛付费内容'); WalletService::adjust($seller, $asset, $price, 'forum_paid_sale', 'post', $postId, '论坛付费内容收入');
            Database::execute('INSERT INTO forum_post_purchases (post_id, buyer_user_id, seller_user_id, price_integral, created_at) VALUES (?, ?, ?, ?, NOW())', [$postId, (int) $user['id'], (int) $seller['id'], $price]); NotificationService::send($seller, 'forum_paid_sale', '付费内容售出', '你的付费内容已售出', ['post_id' => $postId, 'balance' => $price]);
            return ['already_owned' => false, 'price_balance' => $price];
        });
        return Response::success($result, $result['already_owned'] ? '已拥有该内容' : '购买成功');
    }

    public static function createSection(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $sectionId = ForumExperienceService::createSection($user, (int) $params['post_id'], $request->all());
        return Response::success(['section_id' => $sectionId], '内容节创建成功', 201);
    }

    public static function updateSection(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        ForumExperienceService::updateSection($user, (int) $params['post_id'], (int) $params['section_id'], $request->all());
        return Response::success(['section_id' => (int) $params['section_id']], '内容节修改成功');
    }

    public static function deleteSection(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        ForumExperienceService::deleteSection($user, (int) $params['post_id'], (int) $params['section_id']);
        return Response::success([], '内容节已删除');
    }

    public static function reorderSections(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $ids = ForumExperienceService::reorderSections($user, (int) $params['post_id'], $request->input('section_ids', []));
        return Response::success(['section_ids' => $ids], '内容节顺序已同步');
    }

    public static function buySection(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $result = ForumExperienceService::buySection($user, (int) $params['post_id'], (int) $params['section_id']);
        return Response::success($result, $result['already_owned'] ? '已经拥有该内容节' : '购买成功');
    }

    public static function contentLike(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $liked = ForumExperienceService::toggleLike($user, (string) $params['target_type'], (int) $params['target_id']);
        return Response::success(['liked' => $liked], $liked ? '点赞成功' : '已取消点赞');
    }

    public static function contentFavorite(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $favorited = ForumExperienceService::toggleFavorite($user, (string) $params['target_type'], (int) $params['target_id']);
        return Response::success(['favorited' => $favorited], $favorited ? '收藏成功' : '已取消收藏');
    }

    public static function pinComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $enabled = Validator::boolean($request->input('enabled', true), 'enabled');
        $result = ForumExperienceService::setCommentPin(
            $user, (int) $params['post_id'], (int) $params['comment_id'], $enabled,
            (int) $request->input('sort_order', 0)
        );
        return Response::success($result, $enabled ? '评论已置顶' : '评论已取消置顶');
    }

    public static function personalPosition(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $result = ForumExperienceService::setPersonalPosition(
            $user, (string) $params['target_type'], (int) $params['target_id'],
            trim((string) $request->input('position', 'normal')), (int) $request->input('sort_order', 0)
        );
        return Response::success($result, '个人排序已保存');
    }

    public static function forwardContent(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $id = ForumExperienceService::recordForward(
            $user, (string) $params['target_type'], (int) $params['target_id'],
            trim((string) $request->input('destination_type', '')), (int) $request->input('destination_id', 0)
        );
        return Response::success(['forward_id' => $id], '转发记录已保存', 201);
    }

    public static function report(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $data = $request->all();
        Validator::required($data, ['target_type', 'target_id']);
        $targetType = trim((string) $data['target_type']);
        if (!in_array($targetType, ['post', 'comment'], true)) {
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') throw new HttpException('请填写举报原因', 0, 422);
            $id = Database::insert(
                'INSERT INTO content_reports
                 (admin_id, app_id, user_id, target_type, target_id, reason, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $targetType, (int) $data['target_id'], mb_substr($reason, 0, 1000), 'pending']
            );
        } else {
            $targetId = (int) $data['target_id'];
            $targetExists = $targetType === 'post'
                ? Database::one('SELECT id FROM forum_posts WHERE id = ? AND app_id = ? AND status = 1 AND deleted_at IS NULL', [$targetId, (int) $user['app_id']])
                : Database::one('SELECT id FROM forum_comments WHERE id = ? AND app_id = ? AND status = 1', [$targetId, (int) $user['app_id']]);
            if ($targetExists === null) throw new HttpException('举报目标不存在', 404, 404);
            $tagId = max(0, (int) ($data['report_tag_id'] ?? 0));
            $tag = $tagId > 0 ? Database::one(
                'SELECT id, name FROM forum_report_tags WHERE id = ? AND app_id = ? AND status = 1',
                [$tagId, (int) $user['app_id']]
            ) : null;
            if ($tagId > 0 && $tag === null) throw new HttpException('举报标签不存在或已停用', 404, 404);
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') $reason = (string) ($tag['name'] ?? '');
            if ($reason === '') throw new HttpException('请选择举报标签或填写举报原因', 0, 422);
            if (Database::one(
                "SELECT id FROM forum_reports WHERE app_id = ? AND user_id = ? AND target_type = ?
                 AND target_id = ? AND status = 'pending' LIMIT 1",
                [(int) $user['app_id'], (int) $user['id'], $targetType, $targetId]
            )) {
                throw new HttpException('你已经举报过该内容，请等待管理员处理', 0, 409);
            }
            $id = Database::insert(
                'INSERT INTO forum_reports
                 (admin_id, app_id, target_type, target_id, user_id, report_tag_id, reason, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], $targetType, $targetId,
                    (int) $user['id'], $tagId > 0 ? $tagId : null, mb_substr($reason, 0, 1000), 'pending',
                ]
            );
        }
        return Response::success(['report_id' => $id], '举报已提交', 201);
    }

    private static function postCollection(
        Request $request,
        array $user,
        string $join,
        string $condition,
        array $conditionParams,
        bool $approvedOnly,
        bool $hydrateMedia
    ): array {
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['p.app_id = ?', 'p.status = 1', 'p.deleted_at IS NULL', $condition];
        $query = array_merge([(int) $user['app_id']], $conditionParams);
        if ($approvedOnly) {
            $where[] = "p.audit_status = 'approved'";
        }
        if ((int) $request->input('plate_id', 0) > 0) {
            $where[] = 'p.plate_id = ?';
            $query[] = (int) $request->input('plate_id');
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(p.title LIKE ? OR p.content LIKE ? OR p.tags_json LIKE ?)';
            array_push($query, '%' . $keyword . '%', '%' . $keyword . '%', '%' . $keyword . '%');
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $field => $operator) {
            $date = trim((string) $request->input($field, ''));
            if ($date === '') continue;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new HttpException($field . ' 必须是 YYYY-MM-DD 日期', 0, 422);
            }
            $where[] = 'p.created_at ' . $operator . ' ?';
            $query[] = $field === 'date_to' ? $date . ' 23:59:59' : $date . ' 00:00:00';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(DISTINCT p.id) AS total FROM forum_posts p {$join} WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT DISTINCT p.id, p.plate_id, p.user_id, p.title, p.content, p.images_json,
                    p.tags_json, p.is_top, p.is_essence, p.is_locked, p.audit_status,
                    p.audit_reason, p.view_count, p.unique_view_count, p.like_count, p.comment_count,
                    p.heat_score, p.hot_label, p.last_activity_at, p.created_at,
                    p.updated_at, fp.name AS plate_name, up.nickname, up.avatar
             FROM forum_posts p {$join}
             INNER JOIN forum_plates fp ON fp.id = p.plate_id
             LEFT JOIN user_profiles up ON up.user_id = p.user_id
             WHERE {$whereSql}
             ORDER BY p.is_top DESC, p.is_essence DESC, p.is_locked DESC,
                      CASE WHEN p.hot_label <> '' THEN 0 ELSE 1 END, p.heat_score DESC, p.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = ContentTagService::hydrate($items);
        if ($hydrateMedia) {
            $items = MessageMediaService::hydrate($items, 'forum_post', (int) $user['app_id']);
        }
        return Pagination::data($items, $total, $page, $limit);
    }

    public static function postList(Request $request, int $appId, ?int $userId = null): array
    {
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['p.app_id = ?', 'p.audit_status = ?', 'p.status = 1', 'p.deleted_at IS NULL'];
        $query = [$appId, 'approved'];
        if ($request->input('plate_id') !== null && $request->input('plate_id') !== '') {
            $where[] = 'p.plate_id = ?';
            $query[] = (int) $request->input('plate_id');
        }
        if ($request->input('category_id') !== null && $request->input('category_id') !== '') {
            $where[] = 'p.category_id = ?';
            $query[] = (int) $request->input('category_id');
        }
        if (trim((string) $request->input('tag', '')) !== '') {
            $where[] = 'p.tags_json LIKE ?';
            $query[] = '%"' . trim((string) $request->input('tag')) . '"%';
        }
        if (trim((string) $request->input('keyword', '')) !== '') {
            $where[] = '(p.title LIKE ? OR p.content LIKE ? OR p.tags_json LIKE ? OR CAST(p.id AS CHAR) LIKE ?)';
            foreach (range(1, 4) as $_) $query[] = '%' . trim((string) $request->input('keyword')) . '%';
        }
        $dateFrom = trim((string) $request->input('date_from', ''));
        if ($dateFrom !== '') {
            $where[] = 'p.created_at >= ?';
            $query[] = Validator::nullableDateTime($dateFrom . ' 00:00:00', 'date_from');
        }
        $dateTo = trim((string) $request->input('date_to', ''));
        if ($dateTo !== '') {
            $where[] = 'p.created_at <= ?';
            $query[] = Validator::nullableDateTime($dateTo . ' 23:59:59', 'date_to');
        }
        $whereSql = implode(' AND ', $where);
        $personalJoin = '';
        $personalSelect = "'normal' AS personal_position, 0 AS personal_sort_order";
        $queryWithJoin = $query;
        if ($userId !== null && $userId > 0) {
            $personalJoin = "LEFT JOIN forum_personal_positions personal
               ON personal.user_id = ? AND personal.target_type = 'post' AND personal.target_id = p.id";
            $personalSelect = "COALESCE(personal.position, 'normal') AS personal_position,
                    COALESCE(personal.sort_order, 0) AS personal_sort_order";
            $queryWithJoin = array_merge([$userId], $query);
        }
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM forum_posts p {$personalJoin} WHERE {$whereSql}",
            $queryWithJoin
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT p.id, p.plate_id, p.category_id, p.user_id, p.title, p.content, p.images_json, p.tags_json, p.is_top,
                    p.is_essence, p.is_locked, p.view_count, p.unique_view_count, p.like_count, p.comment_count,
                    p.heat_score, p.hot_label, p.last_activity_at, p.created_at,
                    fp.name AS plate_name, fc.name AS category_name, up.nickname, up.avatar,
                    EXISTS(SELECT 1 FROM forum_paid_contents paid WHERE paid.post_id = p.id AND paid.status = 1) AS paid_content,
                    (SELECT COUNT(*) FROM forum_post_sections section WHERE section.post_id = p.id AND section.status = 1) AS section_count,
                    {$personalSelect}
             FROM forum_posts p {$personalJoin}
             INNER JOIN forum_plates fp ON fp.id = p.plate_id
             LEFT JOIN forum_categories fc ON fc.id = p.category_id
             LEFT JOIN user_profiles up ON up.user_id = p.user_id
             WHERE {$whereSql}
             ORDER BY CASE COALESCE(personal_position, 'normal') WHEN 'bottom' THEN 1 ELSE 0 END,
                      p.is_top DESC, p.is_essence DESC, p.is_locked DESC,
                      CASE COALESCE(personal_position, 'normal') WHEN 'top' THEN 0 ELSE 1 END,
                      personal_sort_order DESC,
                      CASE WHEN p.hot_label <> '' THEN 0 ELSE 1 END, p.heat_score DESC, p.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $queryWithJoin
        );
        $items = ContentTagService::hydrate($items);
        $items = MessageMediaService::hydrate($items, 'forum_post', $appId);
        return Pagination::data($items, $total, $page, $limit);
    }

    public static function post(int $appId, int $postId): array
    {
        $post = Database::one(
            'SELECT p.*, fp.name AS plate_name, fc.name AS category_name, up.nickname, up.avatar
             FROM forum_posts p INNER JOIN forum_plates fp ON fp.id = p.plate_id
             LEFT JOIN forum_categories fc ON fc.id = p.category_id
             LEFT JOIN user_profiles up ON up.user_id = p.user_id
             WHERE p.id = ? AND p.app_id = ? AND p.audit_status = ? AND p.status = 1 AND p.deleted_at IS NULL',
            [$postId, $appId, 'approved']
        );
        if ($post === null) {
            throw new HttpException('帖子不存在', 404, 404);
        }
        $post['images'] = json_decode((string) $post['images_json'], true) ?: [];
        unset($post['images_json']);
        $post['tags'] = ContentTagService::decode($post['tags_json'] ?? null);
        unset($post['tags_json']);
        return $post;
    }

    private static function user(Request $request): array
    {
        $user = AuthService::user($request);
        AppService::requireFeature((int) $user['app_id'], 'forum');
        return $user;
    }

    private static function withLegacyImages(array $data): array
    {
        if (isset($data['attachments'])) return $data;
        $images = $data['images'] ?? [];
        if (is_string($images)) $images = json_decode($images, true);
        if (!is_array($images)) $images = [];
        $data['attachments'] = array_map(static fn($url): array => [
            'media_type' => 'image', 'url' => (string) $url,
        ], $images);
        return $data;
    }
}
