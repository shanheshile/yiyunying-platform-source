<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\PublicApi;

use Yiyunying\Core\Database;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AppService;
use Yiyunying\Services\ContentTagService;
use Yiyunying\Services\ForumExperienceService;
use Yiyunying\Services\ForumVisibilityService;
use Yiyunying\Services\MessageMediaService;

final class ForumController
{
    public static function plates(Request $request): \Yiyunying\Core\ApiResponse
    {
        $app = self::app($request);
        return Response::success(['items' => Database::all(
            'SELECT id, name, icon, description FROM forum_plates WHERE app_id = ? AND status = 1 ORDER BY sort_order DESC, id',
            [(int) $app['id']]
        )]);
    }

    public static function posts(Request $request): \Yiyunying\Core\ApiResponse
    {
        $app = self::app($request);
        return Response::success(\Yiyunying\Controllers\User\ForumController::postList($request, (int) $app['id']));
    }

    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $app = self::app($request);
        $post = \Yiyunying\Controllers\User\ForumController::post((int) $app['id'], (int) $params['post_id']);
        $view = ForumExperienceService::recordView($request, $post);
        foreach (['view_count', 'unique_view_count', 'heat_score', 'hot_label'] as $field) {
            if (array_key_exists($field, $view)) $post[$field] = $view[$field];
        }
        $post = ForumVisibilityService::hydratePosts([$post], (int) $app['id'], null)[0];
        $post['sections'] = ForumExperienceService::sections($post, null);
        $post['has_sections'] = $post['sections'] !== [];
        $post['comments'] = Database::all(
            "SELECT c.id, c.parent_id,
                    COALESCE(c.root_comment_id, CASE WHEN c.parent_id IS NULL THEN c.id ELSE c.parent_id END) AS root_comment_id,
                    c.user_id, c.content, c.tags_json, c.is_pinned, c.pin_order,
                    c.like_count, c.favorite_count, c.created_at, c.updated_at, u.uid, p.nickname, p.avatar
             FROM forum_comments c INNER JOIN users u ON u.id = c.user_id
             LEFT JOIN user_profiles p ON p.user_id = c.user_id
             WHERE c.post_id = ? AND c.status = 1 AND c.audit_status = 'approved'
             ORDER BY c.is_pinned DESC, c.pin_order DESC, c.id ASC LIMIT 500",
            [(int) $post['id']]
        );
        $post['comments'] = ContentTagService::hydrate($post['comments']);
        $post['comments'] = MessageMediaService::hydrate($post['comments'], 'forum_comment', (int) $app['id']);
        return Response::success(['post' => $post]);
    }

    private static function app(Request $request): array
    {
        $key = trim((string) ($request->header('x-app-key') ?? $request->input('app_key', '')));
        $app = AppService::byKey($key);
        AppService::requireFeature((int) $app['id'], 'forum');
        $request->setAttribute('admin_id', (int) $app['admin_id']);
        $request->setAttribute('app_id', (int) $app['id']);
        return $app;
    }
}
