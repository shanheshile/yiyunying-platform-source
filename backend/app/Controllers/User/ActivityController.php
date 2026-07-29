<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\User;

use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\HierarchyActivityService;
use Yiyunying\Services\HierarchyActorService;

final class ActivityController
{
    public static function index(Request $request): \Yiyunying\Core\ApiResponse { return Response::success(HierarchyActivityService::feed($request, self::actor($request))); }
    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse { return Response::success(['activity' => HierarchyActivityService::show(self::actor($request), (int) $params['activity_id'])]); }
    public static function claim(Request $request, array $params): \Yiyunying\Core\ApiResponse { return Response::success(HierarchyActivityService::claim(self::actor($request), (int) $params['activity_id']), '红包领取成功'); }
    public static function draw(Request $request, array $params): \Yiyunying\Core\ApiResponse { return Response::success(HierarchyActivityService::draw(self::actor($request), (int) $params['activity_id']), '抽奖完成'); }
    public static function submit(Request $request, array $params): \Yiyunying\Core\ApiResponse { return Response::success(HierarchyActivityService::submit(self::actor($request), (int) $params['activity_id'], $request->all()), '悬赏投稿成功', 201); }
    public static function balance(Request $request): \Yiyunying\Core\ApiResponse { return Response::success(HierarchyActivityService::balance(self::actor($request))); }
    private static function actor(Request $request): array { return HierarchyActorService::user(AuthService::user($request)); }
}
