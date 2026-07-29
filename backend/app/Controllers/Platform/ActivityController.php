<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Platform;

use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Validator;
use Yiyunying\Services\HierarchyActivityService;
use Yiyunying\Services\HierarchyActorService;
use Yiyunying\Services\PlatformService;

final class ActivityController
{
    public static function index(Request $request): \Yiyunying\Core\ApiResponse
    {
        return Response::success(HierarchyActivityService::feed($request, self::actor($request)));
    }

    public static function create(Request $request): \Yiyunying\Core\ApiResponse
    {
        return Response::success(['activity' => HierarchyActivityService::create(self::actor($request), $request->all())], '活动发布成功', 201);
    }

    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return Response::success(['activity' => HierarchyActivityService::show(self::actor($request), (int) $params['activity_id'])]);
    }

    public static function claim(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return Response::success(HierarchyActivityService::claim(self::actor($request), (int) $params['activity_id']), '红包领取成功');
    }

    public static function draw(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return Response::success(HierarchyActivityService::draw(self::actor($request), (int) $params['activity_id']), '抽奖完成');
    }

    public static function submit(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return Response::success(HierarchyActivityService::submit(self::actor($request), (int) $params['activity_id'], $request->all()), '悬赏投稿成功', 201);
    }

    public static function award(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $submissionId = Validator::integer($request->input('submission_id'), 'submission_id', 1, PHP_INT_MAX);
        return Response::success(HierarchyActivityService::award(self::actor($request), (int) $params['activity_id'], $submissionId), '悬赏结算成功');
    }

    public static function close(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return Response::success(HierarchyActivityService::finish(self::actor($request), (int) $params['activity_id'], false), '活动已结束');
    }

    public static function cancel(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return Response::success(HierarchyActivityService::finish(self::actor($request), (int) $params['activity_id'], true), '活动已取消');
    }

    public static function balance(Request $request): \Yiyunying\Core\ApiResponse
    {
        return Response::success(HierarchyActivityService::balance(self::actor($request)));
    }

    private static function actor(Request $request): array
    {
        return HierarchyActorService::platform(PlatformService::auth($request));
    }
}
