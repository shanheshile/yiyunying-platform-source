<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\User;

use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\RewardRuleService;

final class RewardController
{
    public static function rules(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        return Response::success([
            'items' => RewardRuleService::listRules((int) $user['admin_id'], (int) $user['app_id'], true),
        ]);
    }

    public static function events(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        return Response::success(RewardRuleService::events(
            (int) $user['admin_id'],
            (int) $user['app_id'],
            $request->page(),
            $request->limit(),
            (int) $user['id'],
            trim((string) $request->input('scene_code', '')),
            trim((string) $request->input('status', ''))
        ));
    }
}
