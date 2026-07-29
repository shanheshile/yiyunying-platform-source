<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Platform;

use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\IdentityService;
use Yiyunying\Services\PlatformService;

final class IdentityController
{
    public static function index(Request $request): \Yiyunying\Core\ApiResponse
    {
        $platform = PlatformService::auth($request);
        $status = trim((string) $request->input('status', 'pending'));
        $includeAll = (string) $request->input('scope', 'direct') === 'all';
        if ($includeAll && (int) ($platform['level'] ?? 0) !== 1) {
            throw new HttpException('只有一级总控可以查看全局解绑申请', 0, 403);
        }
        return Response::success([
            'scope' => $includeAll ? 'all' : 'direct',
            'items' => IdentityService::requestsForReviewer('platform', $platform, $status, $includeAll),
        ]);
    }

    public static function review(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $platform = PlatformService::auth($request);
        $action = trim((string) $request->input('action', ''));
        if (!in_array($action, ['approve', 'reject'], true)) throw new HttpException('action 只支持 approve 或 reject', 0, 422);
        $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);
        if ($force && (int) ($platform['level'] ?? 0) !== 1) {
            throw new HttpException('只有一级总控可以强制处理非直属解绑申请', 0, 403);
        }
        $item = IdentityService::review(
            'platform',
            $platform,
            (int) $params['request_id'],
            $action === 'approve',
            (string) $request->input('remark', ''),
            $force
        );
        return Response::success(['request' => $item], $action === 'approve' ? '已批准解绑' : '已拒绝解绑');
    }

    public static function mine(Request $request): \Yiyunying\Core\ApiResponse
    {
        $platform = PlatformService::auth($request);
        return Response::success(['items' => IdentityService::requestsForSubject('platform', (int) $platform['id'])]);
    }

    public static function requestMine(Request $request): \Yiyunying\Core\ApiResponse
    {
        $platform = PlatformService::auth($request);
        $item = IdentityService::requestUnbind('platform', $platform, trim((string) $request->input('identity_type', '')), (string) $request->input('reason', ''));
        return Response::success(['request' => $item], '解绑申请已提交给上级平台审核', 201);
    }
}
