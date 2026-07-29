<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\RewardRuleService;

final class RewardController
{
    public static function rules(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        return Response::success([
            'scene_definitions' => RewardRuleService::definitions(),
            'items' => RewardRuleService::listRules((int) $admin['id'], $appId),
        ]);
    }

    public static function updateRule(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $sceneCode = trim((string) $params['scene_code']);
        $rule = RewardRuleService::saveRule(
            (int) $admin['id'],
            $appId,
            $sceneCode,
            $request->all(),
            'admin',
            (int) $admin['id'],
            3
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'reward', 'rule_update', (int) $rule['id'], null, $rule);
        return Response::success(['rule' => $rule], '奖励规则已保存');
    }

    public static function events(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $userId = max(0, (int) $request->input('user_id', 0));
        return Response::success(RewardRuleService::events(
            (int) $admin['id'],
            $appId,
            $request->page(),
            $request->limit(),
            $userId > 0 ? $userId : null,
            trim((string) $request->input('scene_code', '')),
            trim((string) $request->input('status', ''))
        ));
    }

    public static function review(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $approved = self::boolean($request->input('approved'), '审核结果');
        $result = RewardRuleService::reviewEvent(
            (int) $admin['id'],
            $appId,
            (int) $params['event_id'],
            $approved,
            trim((string) $request->input('reason', '')),
            'admin',
            (int) $admin['id']
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'reward', $approved ? 'event_approve' : 'event_reject', (int) $params['event_id'], null, $result);
        return Response::success(['result' => $result], (string) ($result['message'] ?? '审核完成'));
    }

    public static function manualGrant(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $userId = max(1, (int) $request->input('user_id', 0));
        $user = Database::one(
            'SELECT * FROM users WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
            [$userId, (int) $admin['id'], $appId]
        );
        if ($user === null) {
            throw new HttpException('用户不存在或不属于当前应用', 404, 404);
        }
        $sceneCode = trim((string) $request->input('scene_code', ''));
        $referenceType = trim((string) $request->input('reference_type', 'manual'));
        $referenceId = max(0, (int) $request->input('reference_id', 0));
        $result = RewardRuleService::grant($user, $sceneCode, $referenceType, $referenceId, [
            'manual_grant' => true,
            'force_grant' => true,
            'event_key' => trim((string) $request->input('event_key', 'manual:' . bin2hex(random_bytes(8)))),
            'reason' => mb_substr(trim((string) $request->input('reason', '管理员人工发放')), 0, 500),
        ], 'admin', (int) $admin['id']);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'reward', 'manual_grant', $userId, null, $result);
        return Response::success(['result' => $result], (string) ($result['message'] ?? '奖励处理完成'));
    }

    private static function context(Request $request, array $params): array
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        return [$admin, $appId];
    }

    private static function boolean($value, string $label): bool
    {
        if (is_bool($value)) return $value;
        if (in_array($value, [1, '1', 'true'], true)) return true;
        if (in_array($value, [0, '0', 'false'], true)) return false;
        throw new HttpException($label . '必须为通过或不通过', 0, 422);
    }
}
