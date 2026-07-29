<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class GovernanceService
{
    private const TARGET_TYPES = ['global', 'level', 'platform', 'admin', 'app', 'user'];
    private const EFFECTS = ['allow', 'deny', 'config'];

    public static function appContext(int $appId): array
    {
        $context = Database::one(
            'SELECT ap.id AS app_id, ap.admin_id, a.platform_id, p.level AS platform_level,
                    CASE WHEN p.level = 1 THEN p.id ELSE p.parent_id END AS root_platform_id
             FROM apps ap
             INNER JOIN admins a ON a.id = ap.admin_id
             INNER JOIN platform_accounts p ON p.id = a.platform_id
             WHERE ap.id = ? AND ap.deleted_at IS NULL AND a.status <> -1 AND p.deleted_at IS NULL',
            [$appId]
        );
        if ($context === null) {
            throw new HttpException('应用治理上下文不存在', 404, 404);
        }
        return array_map(static fn($value) => is_numeric($value) ? (int) $value : $value, $context);
    }

    public static function effectiveFeatureForApp(
        int $appId,
        string $featureCode,
        ?bool $configured = null,
        ?int $userId = null
    ): array {
        $context = self::appContext($appId);
        if ($configured === null) {
            $flag = Database::one(
                'SELECT enabled FROM app_feature_flags WHERE app_id = ? AND feature_code = ?',
                [$appId, $featureCode]
            );
            $configured = $flag === null ? true : (bool) $flag['enabled'];
        }

        $issuerIds = [(int) $context['root_platform_id']];
        if ((int) $context['platform_id'] !== (int) $context['root_platform_id']) {
            $issuerIds[] = (int) $context['platform_id'];
        }
        $issuerPlaceholders = implode(',', array_fill(0, count($issuerIds), '?'));
        $params = array_merge([$featureCode], $issuerIds, [
            (int) $context['platform_id'],
            (int) $context['admin_id'],
            $appId,
            $userId ?? 0,
        ]);
        $rules = Database::all(
            "SELECT r.*, p.nickname AS issuer_name, p.platform_key AS issuer_platform_key
             FROM governance_rules r
             INNER JOIN platform_accounts p ON p.id = r.issuer_platform_id
             WHERE r.feature_code = ? AND r.issuer_platform_id IN ({$issuerPlaceholders})
               AND r.status = 1
               AND (r.starts_at IS NULL OR r.starts_at <= NOW())
               AND (r.ends_at IS NULL OR r.ends_at > NOW())
               AND (
                    r.target_type = 'global'
                    OR (r.target_type = 'level' AND r.target_level = 4)
                    OR (r.target_type = 'platform' AND r.target_id = ?)
                    OR (r.target_type = 'admin' AND r.target_id = ?)
                    OR (r.target_type = 'app' AND r.target_id = ?)
                    OR (r.target_type = 'user' AND r.target_id = ?)
               )
             ORDER BY r.issuer_level ASC, r.forced DESC, r.priority DESC,
               FIELD(r.target_type, 'user', 'app', 'admin', 'platform', 'level', 'global'), r.id DESC",
            $params
        );

        $effective = $configured;
        $locked = false;
        $source = 'admin_app';
        $sourceRule = null;
        $config = null;
        foreach ($rules as $rule) {
            if ($config === null && (string) $rule['effect'] === 'config') {
                $decoded = json_decode((string) ($rule['value_json'] ?? ''), true);
                $config = is_array($decoded) ? $decoded : null;
            }
            if ((int) $rule['forced'] !== 1 || !in_array((string) $rule['effect'], ['allow', 'deny'], true)) {
                continue;
            }
            $effective = (string) $rule['effect'] === 'allow';
            $locked = true;
            $source = 'platform_force';
            $sourceRule = $rule;
            break;
        }

        return [
            'feature_code' => $featureCode,
            'configured_enabled' => $configured,
            'effective_enabled' => $effective,
            'locked' => $locked,
            'can_admin_modify' => !$locked,
            'source' => $source,
            'source_rule_id' => $sourceRule === null ? null : (int) $sourceRule['id'],
            'forced_by_platform_id' => $sourceRule === null ? null : (int) $sourceRule['issuer_platform_id'],
            'forced_by_level' => $sourceRule === null ? null : (int) $sourceRule['issuer_level'],
            'forced_by_platform_key' => $sourceRule['issuer_platform_key'] ?? null,
            'config' => $config,
            'matched_rule_count' => count($rules),
        ];
    }

    public static function effectiveFeatures(int $appId): array
    {
        $flags = AppService::features($appId);
        $codes = array_keys($flags);
        $ruleCodes = Database::all(
            'SELECT DISTINCT feature_code FROM governance_rules WHERE status = 1 ORDER BY feature_code'
        );
        foreach ($ruleCodes as $row) {
            $codes[] = (string) $row['feature_code'];
        }
        $codes = array_values(array_unique($codes));
        sort($codes);
        $result = [];
        foreach ($codes as $code) {
            $configured = isset($flags[$code]) ? (bool) $flags[$code]['enabled'] : true;
            $result[$code] = self::effectiveFeatureForApp($appId, $code, $configured);
        }
        return $result;
    }

    public static function assertFeatureMutable(int $appId, string $featureCode): void
    {
        $policy = self::effectiveFeatureForApp($appId, $featureCode);
        if ((bool) $policy['locked']) {
            throw new HttpException('该功能已被上级平台强制锁定', 403, 403, $policy);
        }
    }

    public static function createRule(array $actor, array $data): int
    {
        PlatformService::requireCapability($actor, 'governance.manage');
        $targetType = trim((string) ($data['target_type'] ?? ''));
        $effect = trim((string) ($data['effect'] ?? ''));
        $featureCode = trim((string) ($data['feature_code'] ?? ''));
        if (!in_array($targetType, self::TARGET_TYPES, true)) {
            throw new HttpException('target_type 不支持', 0, 422);
        }
        if (!in_array($effect, self::EFFECTS, true)) {
            throw new HttpException('effect 仅支持 allow、deny、config', 0, 422);
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{1,99}$/', $featureCode) !== 1) {
            throw new HttpException('feature_code 格式错误', 0, 422);
        }
        $targetId = isset($data['target_id']) && $data['target_id'] !== '' ? (int) $data['target_id'] : null;
        $targetLevel = isset($data['target_level']) && $data['target_level'] !== '' ? (int) $data['target_level'] : null;
        self::assertTarget($actor, $targetType, $targetId, $targetLevel);
        $value = $data['value'] ?? $data['value_json'] ?? null;
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }
        if ($effect === 'config' && !is_array($value)) {
            throw new HttpException('config 规则必须提供 JSON 对象 value', 0, 422);
        }
        return Database::insert(
            'INSERT INTO governance_rules
             (issuer_platform_id, issuer_level, target_type, target_id, target_level, feature_code,
              effect, value_json, forced, priority, status, starts_at, ends_at, remark, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, NOW(), NOW())',
            [
                (int) $actor['id'], (int) $actor['level'], $targetType, $targetId, $targetLevel,
                $featureCode, $effect,
                $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                self::boolValue($data['forced'] ?? true) ? 1 : 0,
                (int) ($data['priority'] ?? 0),
                self::dateValue($data['starts_at'] ?? null),
                self::dateValue($data['ends_at'] ?? null),
                mb_substr((string) ($data['remark'] ?? ''), 0, 500),
            ]
        );
    }

    public static function manageableRule(array $actor, int $ruleId): array
    {
        $rule = Database::one(
            'SELECT r.*, p.parent_id AS issuer_parent_id FROM governance_rules r
             INNER JOIN platform_accounts p ON p.id = r.issuer_platform_id WHERE r.id = ?',
            [$ruleId]
        );
        if ($rule === null) {
            throw new HttpException('治理规则不存在', 404, 404);
        }
        $manageable = (int) $rule['issuer_platform_id'] === (int) $actor['id'];
        if ((int) $actor['level'] === 1 && (int) ($rule['issuer_parent_id'] ?? 0) === (int) $actor['id']) {
            $manageable = true;
        }
        if (!$manageable) {
            throw new HttpException('无权管理该治理规则', 403, 403);
        }
        return $rule;
    }

    public static function assertTarget(array $actor, string $type, ?int $id, ?int $level): void
    {
        if ($type === 'global') {
            PlatformService::requireLevelOne($actor);
            return;
        }
        if ($type === 'level') {
            $allowed = (int) $actor['level'] === 1 ? [2, 3, 4] : [3, 4];
            if ($level === null || !in_array($level, $allowed, true)) {
                throw new HttpException('不能对该等级下发规则', 0, 422);
            }
            return;
        }
        if ($id === null || $id <= 0) {
            throw new HttpException('该目标类型必须提供 target_id', 0, 422);
        }
        if ($type === 'platform') {
            if ((int) $actor['level'] === 1) {
                if ($id !== (int) $actor['id']) {
                    PlatformService::ownedOperator($actor, $id);
                }
            } elseif ($id !== (int) $actor['id']) {
                throw new HttpException('2 级平台只能设置自己的分支', 403, 403);
            }
            return;
        }
        if ($type === 'admin') {
            PlatformService::ownedAdmin($actor, $id);
            return;
        }
        if ($type === 'app') {
            PlatformService::ownedApp($actor, $id);
            return;
        }
        $user = Database::one(
            'SELECT u.id, u.app_id FROM users u INNER JOIN apps ap ON ap.id = u.app_id WHERE u.id = ? AND u.deleted_at IS NULL',
            [$id]
        );
        if ($user === null) {
            throw new HttpException('目标用户不存在', 404, 404);
        }
        PlatformService::ownedApp($actor, (int) $user['app_id']);
    }

    public static function visibleWhere(array $actor, string $alias = 'r'): array
    {
        if ((int) $actor['level'] === 1) {
            return [
                "({$alias}.issuer_platform_id = ? OR {$alias}.issuer_platform_id IN
                  (SELECT id FROM platform_accounts WHERE parent_id = ? AND level = 2))",
                [(int) $actor['id'], (int) $actor['id']],
            ];
        }
        return [
            "{$alias}.issuer_platform_id IN (?, ?)",
            [(int) $actor['parent_id'], (int) $actor['id']],
        ];
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
