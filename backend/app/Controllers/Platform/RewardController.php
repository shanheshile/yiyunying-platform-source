<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Platform;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\PlatformService;
use Yiyunying\Services\RewardRuleService;

final class RewardController
{
    public static function rules(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, $params);
        return Response::success([
            'scene_definitions' => RewardRuleService::definitions(),
            'items' => RewardRuleService::listRules((int) $app['admin_id'], (int) $app['id']),
            'management_level' => (int) $actor['level'],
        ]);
    }

    public static function updateRule(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, $params);
        $rule = RewardRuleService::saveRule(
            (int) $app['admin_id'],
            (int) $app['id'],
            trim((string) $params['scene_code']),
            $request->all(),
            'platform',
            (int) $actor['id'],
            (int) $actor['level']
        );
        PlatformService::log($request, $actor, 'reward', 'rule_update', 'app_reward_rule', (int) $rule['id'], null, $rule);
        return Response::success(['rule' => $rule], '奖励规则已保存');
    }

    public static function events(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [, $app] = self::context($request, $params);
        $userId = max(0, (int) $request->input('user_id', 0));
        return Response::success(RewardRuleService::events(
            (int) $app['admin_id'],
            (int) $app['id'],
            $request->page(),
            $request->limit(),
            $userId > 0 ? $userId : null,
            trim((string) $request->input('scene_code', '')),
            trim((string) $request->input('status', ''))
        ));
    }

    public static function review(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, $params);
        $approved = self::boolean($request->input('approved'));
        $result = RewardRuleService::reviewEvent(
            (int) $app['admin_id'],
            (int) $app['id'],
            (int) $params['event_id'],
            $approved,
            trim((string) $request->input('reason', '')),
            'platform',
            (int) $actor['id']
        );
        PlatformService::log($request, $actor, 'reward', $approved ? 'event_approve' : 'event_reject', 'app_reward_event', (int) $params['event_id'], null, $result);
        return Response::success(['result' => $result], (string) ($result['message'] ?? '审核完成'));
    }

    public static function manualGrant(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$actor, $app] = self::context($request, $params);
        $userId = max(1, (int) $request->input('user_id', 0));
        $user = Database::one(
            'SELECT * FROM users WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
            [$userId, (int) $app['admin_id'], (int) $app['id']]
        );
        if ($user === null) {
            throw new HttpException('用户不存在或不属于当前应用', 404, 404);
        }
        $result = RewardRuleService::grant(
            $user,
            trim((string) $request->input('scene_code', '')),
            trim((string) $request->input('reference_type', 'manual')),
            max(0, (int) $request->input('reference_id', 0)),
            [
                'manual_grant' => true,
                'force_grant' => true,
                'event_key' => trim((string) $request->input('event_key', 'manual:' . bin2hex(random_bytes(8)))),
                'reason' => mb_substr(trim((string) $request->input('reason', '平台人工发放')), 0, 500),
            ],
            'platform',
            (int) $actor['id']
        );
        PlatformService::log($request, $actor, 'reward', 'manual_grant', 'user', $userId, null, $result);
        return Response::success(['result' => $result], (string) ($result['message'] ?? '奖励处理完成'));
    }

    private static function context(Request $request, array $params): array
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'reward_management');
        $app = PlatformService::ownedApp($actor, (int) $params['app_id']);
        return [$actor, $app];
    }

    private static function boolean($value): bool
    {
        if (is_bool($value)) return $value;
        if (in_array($value, [1, '1', 'true'], true)) return true;
        if (in_array($value, [0, '0', 'false'], true)) return false;
        throw new HttpException('审核结果必须为通过或不通过', 0, 422);
    }
}
