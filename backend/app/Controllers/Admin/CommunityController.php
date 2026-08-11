<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LevelForumService;

final class CommunityController
{
    private static function actor(Request $request): array { return LevelForumService::adminActor(AuthService::admin($request)); }
    public static function posts(Request $request): \Yiyunying\Core\ApiResponse { return Response::success(LevelForumService::feed($request, self::actor($request))); }
    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse { return Response::success(['post' => LevelForumService::show(self::actor($request), (int) $params['post_id'])]); }
    public static function create(Request $request): \Yiyunying\Core\ApiResponse { $id = LevelForumService::create(self::actor($request), $request->all()); return Response::success(['post_id' => $id], '交流帖子已发布', 201); }
    public static function comment(Request $request, array $params): \Yiyunying\Core\ApiResponse { $id = LevelForumService::comment(self::actor($request), (int) $params['post_id'], $request->all()); return Response::success(['comment_id' => $id], '评论成功', 201); }
    public static function reaction(Request $request, array $params): \Yiyunying\Core\ApiResponse { $type = trim((string) $request->input('reaction_type', 'like')); $active = LevelForumService::reaction(self::actor($request), (int) $params['post_id'], $type); return Response::success(['reaction_type' => $type, 'active' => $active], $active ? '操作成功' : '已取消'); }
    public static function pin(Request $request, array $params): \Yiyunying\Core\ApiResponse { $pinned = LevelForumService::pin(self::actor($request), (int) $params['post_id'], $request->input('pinned', true)); return Response::success(['pinned' => $pinned], $pinned ? '帖子已置顶' : '已取消置顶'); }
    public static function report(Request $request, array $params): \Yiyunying\Core\ApiResponse { $id = LevelForumService::report(self::actor($request), (int) $params['post_id'], $request->all()); return Response::success(['report_id' => $id], '举报已提交', 201); }
    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse { LevelForumService::delete(self::actor($request), (int) $params['post_id']); return Response::success([], '帖子已删除'); }
}
