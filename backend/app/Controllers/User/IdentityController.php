<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\User;

use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\IdentityService;

final class IdentityController
{
    public static function index(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        return Response::success(['items' => IdentityService::requestsForSubject('user', (int) $user['id'])]);
    }

    public static function create(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $item = IdentityService::requestUnbind(
            'user',
            $user,
            trim((string) $request->input('identity_type', '')),
            (string) $request->input('reason', '')
        );
        return Response::success(['request' => $item], '解绑申请已提交，请等待管理员审核', 201);
    }
}
