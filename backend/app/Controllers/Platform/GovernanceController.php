<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Platform;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\GovernanceService;
use Yiyunying\Services\PlatformService;

final class GovernanceController
{
    public static function index(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        [$visible, $query] = GovernanceService::visibleWhere($actor);
        $where = [$visible];
        foreach (['feature_code', 'target_type', 'effect'] as $field) {
            $value = trim((string) $request->input($field, ''));
            if ($value !== '') {
                $where[] = "r.{$field} = ?";
                $query[] = $value;
            }
        }
        if ($request->input('status') !== null && $request->input('status') !== '') {
            $where[] = 'r.status = ?';
            $query[] = (int) $request->input('status');
        }
        $whereSql = implode(' AND ', $where);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM governance_rules r WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT r.*, p.nickname AS issuer_name, p.platform_key AS issuer_platform_key
             FROM governance_rules r INNER JOIN platform_accounts p ON p.id = r.issuer_platform_id
             WHERE {$whereSql} ORDER BY r.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function create(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $id = GovernanceService::createRule($actor, $request->all());
        PlatformService::log($request, $actor, 'governance', 'create', 'rule', $id, null, $request->all());
        return Response::success(['rule_id' => $id], '治理规则已创建', 201);
    }

    public static function update(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'governance.manage');
        $rule = GovernanceService::manageableRule($actor, (int) $params['rule_id']);
        $data = array_merge($rule, $request->all());
        GovernanceService::assertTarget(
            $actor,
            (string) $data['target_type'],
            $data['target_id'] === null ? null : (int) $data['target_id'],
            $data['target_level'] === null ? null : (int) $data['target_level']
        );
        $effect = trim((string) ($data['effect'] ?? ''));
        if (!in_array($effect, ['allow', 'deny', 'config'], true)) throw new HttpException('effect 不支持', 0, 422);
        $value = $request->input('value', $request->input('value_json', $rule['value_json']));
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) $value = $decoded;
        }
        $forced = self::boolValue($request->input('forced', (int) $rule['forced'] === 1));
        $status = (int) $request->input('status', $rule['status']);
        if (!in_array($status, [0, 1], true)) throw new HttpException('status 仅支持 0 或 1', 0, 422);
        Database::execute(
            'UPDATE governance_rules SET target_type = ?, target_id = ?, target_level = ?, feature_code = ?,
             effect = ?, value_json = ?, forced = ?, priority = ?, status = ?, starts_at = ?, ends_at = ?,
             remark = ?, updated_at = NOW() WHERE id = ?',
            [
                (string) $data['target_type'], $data['target_id'] === null ? null : (int) $data['target_id'],
                $data['target_level'] === null ? null : (int) $data['target_level'],
                trim((string) $data['feature_code']), $effect,
                $value === null || $value === '' ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $forced ? 1 : 0, (int) ($data['priority'] ?? 0), $status,
                self::dateValue($data['starts_at'] ?? null), self::dateValue($data['ends_at'] ?? null),
                mb_substr((string) ($data['remark'] ?? ''), 0, 500), (int) $rule['id'],
            ]
        );
        $after = Database::one('SELECT * FROM governance_rules WHERE id = ?', [(int) $rule['id']]);
        PlatformService::log($request, $actor, 'governance', 'update', 'rule', (int) $rule['id'], $rule, $after);
        return Response::success(['rule' => $after], '治理规则已更新');
    }

    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'governance.manage');
        $rule = GovernanceService::manageableRule($actor, (int) $params['rule_id']);
        Database::execute('DELETE FROM governance_rules WHERE id = ?', [(int) $rule['id']]);
        PlatformService::log($request, $actor, 'governance', 'delete', 'rule', (int) $rule['id'], $rule, null);
        return Response::success([], '治理规则已删除');
    }

    public static function batch(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $targetType = trim((string) $request->input('target_type', ''));
        $targetIds = $request->input('target_ids', []);
        $featureCodes = $request->input('feature_codes', []);
        if (!is_array($targetIds) || !is_array($featureCodes) || $featureCodes === []) {
            throw new HttpException('target_ids 与 feature_codes 必须是数组', 0, 422);
        }
        if (in_array($targetType, ['global', 'level'], true) && $targetIds === []) $targetIds = [null];
        $targetIds = array_values(array_unique(array_slice($targetIds, 0, 1000)));
        $featureCodes = array_values(array_unique(array_slice($featureCodes, 0, 100)));
        if ($targetIds === []) throw new HttpException('至少选择一个目标', 0, 422);
        $created = Database::transaction(static function () use ($actor, $request, $targetType, $targetIds, $featureCodes): array {
            $ids = [];
            foreach ($targetIds as $targetId) {
                foreach ($featureCodes as $featureCode) {
                    $data = $request->all();
                    $data['target_type'] = $targetType;
                    $data['target_id'] = $targetId;
                    $data['feature_code'] = $featureCode;
                    $ids[] = GovernanceService::createRule($actor, $data);
                }
            }
            return $ids;
        });
        PlatformService::log($request, $actor, 'governance', 'batch_create', 'rule', null, null, ['rule_ids' => $created]);
        return Response::success(['created_count' => count($created), 'rule_ids' => $created], '批量治理规则已创建', 201);
    }

    public static function effective(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $appId = (int) $request->input('app_id', 0);
        PlatformService::ownedApp($actor, $appId);
        return Response::success(['app_id' => $appId, 'features' => GovernanceService::effectiveFeatures($appId)]);
    }

    private static function boolValue($value): bool
    {
        if (is_bool($value)) return $value;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function dateValue($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $time = strtotime($value);
        if ($time === false) throw new HttpException('时间格式错误', 0, 422);
        return date('Y-m-d H:i:s', $time);
    }
}
