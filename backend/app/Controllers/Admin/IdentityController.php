<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\IdentityService;

final class IdentityController
{
    public static function index(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $status = trim((string) $request->input('status', 'pending'));
        return Response::success(['items' => IdentityService::requestsForReviewer('admin', $admin, $status)]);
    }

    public static function review(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $action = trim((string) $request->input('action', ''));
        if (!in_array($action, ['approve', 'reject'], true)) throw new HttpException('action 只支持 approve 或 reject', 0, 422);
        $item = IdentityService::review('admin', $admin, (int) $params['request_id'], $action === 'approve', (string) $request->input('remark', ''));
        return Response::success(['request' => $item], $action === 'approve' ? '已批准解绑' : '已拒绝解绑');
    }

    public static function mine(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        return Response::success(['items' => IdentityService::requestsForSubject('admin', (int) $admin['id'])]);
    }

    public static function requestMine(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $item = IdentityService::requestUnbind('admin', $admin, trim((string) $request->input('identity_type', '')), (string) $request->input('reason', ''));
        return Response::success(['request' => $item], '解绑申请已提交给所属平台审核', 201);
    }
}
