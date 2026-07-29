<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\UniversalPollService;

final class PollController
{
    private static function actor(Request $request): array { return UniversalPollService::adminActor(AuthService::admin($request)); }
    public static function categories(Request $request): \Yiyunying\Core\ApiResponse { return Response::success(['items' => UniversalPollService::categories($request, self::actor($request))]); }
    public static function createCategory(Request $request): \Yiyunying\Core\ApiResponse { $id = UniversalPollService::createCategory(self::actor($request), $request->all()); return Response::success(['category_id' => $id], '投票分类已创建', 201); }
    public static function updateCategory(Request $request, array $params): \Yiyunying\Core\ApiResponse { return Response::success(['category' => UniversalPollService::updateCategory(self::actor($request), (int) $params['category_id'], $request->all())], '投票分类已更新'); }
    public static function deleteCategory(Request $request, array $params): \Yiyunying\Core\ApiResponse { UniversalPollService::deleteCategory(self::actor($request), (int) $params['category_id']); return Response::success([], '投票分类已删除'); }
    public static function polls(Request $request): \Yiyunying\Core\ApiResponse { return Response::success(UniversalPollService::feed($request, self::actor($request))); }
    public static function createPoll(Request $request): \Yiyunying\Core\ApiResponse { $id = UniversalPollService::create(self::actor($request), $request->all()); return Response::success(['poll_id' => $id], '投票活动已创建', 201); }
    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse { return Response::success(['poll' => UniversalPollService::show(self::actor($request), (int) $params['poll_id'])]); }
    public static function vote(Request $request, array $params): \Yiyunying\Core\ApiResponse { return Response::success(UniversalPollService::vote(self::actor($request), (int) $params['poll_id'], (array) $request->input('option_ids', [])), '投票成功'); }
    public static function close(Request $request, array $params): \Yiyunying\Core\ApiResponse { UniversalPollService::close(self::actor($request), (int) $params['poll_id']); return Response::success([], '投票已关闭'); }
    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse { UniversalPollService::delete(self::actor($request), (int) $params['poll_id']); return Response::success([], '投票已删除'); }
}
