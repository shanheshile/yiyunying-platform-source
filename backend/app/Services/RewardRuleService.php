<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Throwable;

final class RewardRuleService
{
    private const ASSETS = ['balance', 'experience', 'integral', 'document_credit', 'vip_days'];
    private const GRANT_MODES = ['automatic', 'after_review', 'manual'];
    private const CYCLES = ['once', 'daily', 'weekly', 'monthly', 'unlimited'];

    private const SCENES = [
        'register' => ['name' => '注册奖励', 'description' => '用户首次完成注册后发放'],
        'login' => ['name' => '登录奖励', 'description' => '用户成功登录后按配置周期发放'],
        'daily_sign' => ['name' => '签到奖励', 'description' => '用户每日首次签到后发放'],
        'invite_success' => ['name' => '邀请奖励', 'description' => '受邀用户完成注册后向邀请人发放'],
        'forum_post_create' => ['name' => '发帖奖励', 'description' => '帖子发布并满足规则后发放'],
        'forum_plate_create' => ['name' => '建设论坛奖励', 'description' => '板块或二级分类申请通过后发放'],
        'valid_report' => ['name' => '有效举报奖励', 'description' => '举报被审核认定有效后发放'],
        'valid_feedback' => ['name' => '有效反馈奖励', 'description' => '反馈被审核认定有效后发放'],
        'comment_create' => ['name' => '有效评论奖励', 'description' => '有效评论发布或审核通过后发放'],
        'reply_create' => ['name' => '有效回复奖励', 'description' => '有效回复发布或审核通过后发放'],
    ];

    public static function definitions(): array
    {
        $items = [];
        foreach (self::SCENES as $code => $definition) {
            $items[] = ['scene_code' => $code] + $definition;
        }
        return $items;
    }

    public static function listRules(int $adminId, int $appId, bool $enabledOnly = false): array
    {
        $sql = 'SELECT * FROM app_reward_rules WHERE admin_id = ? AND app_id = ? AND status = 1';
        $params = [$adminId, $appId];
        if ($enabledOnly) {
            $sql .= ' AND enabled = 1';
        }
        $sql .= ' ORDER BY id ASC';
        $rows = Database::all($sql, $params);
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['scene_code']] = self::presentRule($row);
        }

        $items = [];
        foreach (self::SCENES as $code => $definition) {
            if (isset($indexed[$code])) {
                $items[] = $indexed[$code];
            } elseif (!$enabledOnly) {
                $items[] = [
                    'id' => 0,
                    'scene_code' => $code,
                    'scene_name' => $definition['name'],
                    'description' => $definition['description'],
                    'enabled' => false,
                    'grant_mode' => 'automatic',
                    'grant_mode_name' => '自动发放',
                    'cycle_type' => 'unlimited',
                    'cycle_type_name' => '不限周期',
                    'cycle_limit' => 0,
                    'user_total_limit' => 0,
                    'reward' => self::emptyReward(),
                    'conditions' => [],
                    'audience' => [],
                    'manager_level' => 3,
                    'force_sync' => false,
                    'configured' => false,
                ];
            }
        }
        return $items;
    }

    public static function saveRule(
        int $adminId,
        int $appId,
        string $sceneCode,
        array $data,
        string $actorType,
        int $actorId,
        int $managerLevel = 3
    ): array {
        self::assertScene($sceneCode);
        $app = AppService::owned($adminId, $appId);
        if ((int) $app['admin_id'] !== $adminId) {
            throw new HttpException('应用不属于当前管理员', 403, 403);
        }

        $existingRule = Database::one(
            'SELECT manager_level, force_sync, created_by_type, created_by_id
             FROM app_reward_rules
             WHERE admin_id = ? AND app_id = ? AND scene_code = ? AND status = 1',
            [$adminId, $appId, $sceneCode]
        );
        if ($existingRule !== null
            && (int) $existingRule['force_sync'] === 1
            && (int) $existingRule['manager_level'] < $managerLevel) {
            throw new HttpException('上级已强制同步该奖励规则，当前级别不能修改', 403, 403, [
                'forced_by_level' => (int) $existingRule['manager_level'],
                'forced_by_type' => (string) $existingRule['created_by_type'],
                'forced_by_id' => (int) $existingRule['created_by_id'],
            ]);
        }

        $definition = self::SCENES[$sceneCode];
        $enabled = self::boolValue($data['enabled'] ?? false, 'enabled');
        $grantMode = trim((string) ($data['grant_mode'] ?? 'automatic'));
        if (!in_array($grantMode, self::GRANT_MODES, true)) {
            throw new HttpException('发放方式不正确', 0, 422);
        }
        $cycleType = trim((string) ($data['cycle_type'] ?? 'unlimited'));
        if (!in_array($cycleType, self::CYCLES, true)) {
            throw new HttpException('奖励周期不正确', 0, 422);
        }
        $cycleLimit = self::unsignedInt($data['cycle_limit'] ?? 0, '周期内次数上限', 1000000);
        $userTotalLimit = self::unsignedInt($data['user_total_limit'] ?? 0, '用户累计次数上限', 100000000);
        $reward = self::normalizeReward($data['reward'] ?? $data['reward_json'] ?? []);
        $conditions = self::objectValue($data['conditions'] ?? $data['conditions_json'] ?? [], '发放条件');
        $audience = self::objectValue($data['audience'] ?? $data['audience_json'] ?? [], '适用用户');
        $forceSync = self::boolValue($data['force_sync'] ?? false, '强制同步');
        $sceneName = mb_substr(trim((string) ($data['scene_name'] ?? $definition['name'])), 0, 100);
        $description = mb_substr(trim((string) ($data['description'] ?? $definition['description'])), 0, 500);

        Database::execute(
            'INSERT INTO app_reward_rules
             (admin_id, app_id, scene_code, scene_name, description, enabled, reward_json,
              grant_mode, cycle_type, cycle_limit, user_total_limit, conditions_json, audience_json,
              manager_level, force_sync, created_by_type, created_by_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE scene_name = VALUES(scene_name), description = VALUES(description),
               enabled = VALUES(enabled), reward_json = VALUES(reward_json), grant_mode = VALUES(grant_mode),
               cycle_type = VALUES(cycle_type), cycle_limit = VALUES(cycle_limit),
               user_total_limit = VALUES(user_total_limit), conditions_json = VALUES(conditions_json),
               audience_json = VALUES(audience_json), manager_level = VALUES(manager_level),
               force_sync = VALUES(force_sync), created_by_type = VALUES(created_by_type),
               created_by_id = VALUES(created_by_id), status = 1, updated_at = NOW()',
            [
                $adminId, $appId, $sceneCode, $sceneName, $description, $enabled ? 1 : 0,
                self::json($reward), $grantMode, $cycleType, $cycleLimit, $userTotalLimit,
                self::json($conditions), self::json($audience), max(1, min(3, $managerLevel)),
                $forceSync ? 1 : 0, mb_substr($actorType, 0, 20), max(0, $actorId),
            ]
        );
        return self::rule($adminId, $appId, $sceneCode);
    }

    public static function rule(int $adminId, int $appId, string $sceneCode): array
    {
        self::assertScene($sceneCode);
        $row = Database::one(
            'SELECT * FROM app_reward_rules WHERE admin_id = ? AND app_id = ? AND scene_code = ? AND status = 1',
            [$adminId, $appId, $sceneCode]
        );
        if ($row === null) {
            throw new HttpException('奖励规则不存在', 404, 404);
        }
        return self::presentRule($row);
    }

    public static function enabled(int $adminId, int $appId, string $sceneCode): bool
    {
        self::assertScene($sceneCode);
        try {
            $row = Database::one(
                'SELECT enabled FROM app_reward_rules
                 WHERE admin_id = ? AND app_id = ? AND scene_code = ? AND status = 1',
                [$adminId, $appId, $sceneCode]
            );
            return $row !== null && (int) $row['enabled'] === 1;
        } catch (Throwable $exception) {
            return false;
        }
    }

    public static function reviewEvent(
        int $adminId,
        int $appId,
        int $eventId,
        bool $approved,
        string $reason,
        string $actorType,
        int $actorId
    ): array {
        $event = Database::one(
            'SELECT e.*, u.admin_id AS user_admin_id, u.app_id AS user_app_id
             FROM app_reward_events e
             INNER JOIN users u ON u.id = e.user_id
             WHERE e.id = ? AND e.admin_id = ? AND e.app_id = ?',
            [$eventId, $adminId, $appId]
        );
        if ($event === null) {
            throw new HttpException('奖励申请不存在', 404, 404);
        }
        if ((string) $event['status'] === 'granted') {
            throw new HttpException('该奖励已经发放', 0, 409);
        }
        if ((string) $event['status'] !== 'pending') {
            throw new HttpException('当前状态不能重复审核', 0, 409);
        }
        if (!$approved) {
            Database::execute(
                "UPDATE app_reward_events
                 SET status = 'rejected', reason = ?, actor_type = ?, actor_id = ?, updated_at = NOW()
                 WHERE id = ? AND status = 'pending'",
                [mb_substr(trim($reason), 0, 500), mb_substr($actorType, 0, 20), $actorId, $eventId]
            );
            return ['event_id' => $eventId, 'status' => 'rejected', 'status_name' => '已拒绝', 'message' => '奖励申请已拒绝'];
        }

        $user = Database::one('SELECT * FROM users WHERE id = ? AND admin_id = ? AND app_id = ?', [
            (int) $event['user_id'], $adminId, $appId,
        ]);
        if ($user === null) {
            throw new HttpException('奖励用户不存在', 404, 404);
        }
        $context = self::decodeObject($event['context_json'] ?? '{}');
        $context['approved'] = true;
        $context['review_reason'] = mb_substr(trim($reason), 0, 500);
        $context['dedupe_key_override'] = (string) $event['dedupe_key'];
        return self::grant(
            $user,
            (string) $event['scene_code'],
            (string) $event['ref_type'],
            (int) $event['ref_id'],
            $context,
            $actorType,
            $actorId
        );
    }

    public static function grant(
        array $user,
        string $sceneCode,
        string $refType = '',
        int $refId = 0,
        array $context = [],
        string $actorType = 'system',
        int $actorId = 0
    ): array {
        self::assertScene($sceneCode);
        $adminId = (int) $user['admin_id'];
        $appId = (int) $user['app_id'];
        $userId = (int) $user['id'];

        return Database::transaction(static function () use (
            $user,
            $sceneCode,
            $refType,
            $refId,
            $context,
            $actorType,
            $actorId,
            $adminId,
            $appId,
            $userId
        ): array {
            $rule = Database::one(
                'SELECT * FROM app_reward_rules
                 WHERE admin_id = ? AND app_id = ? AND scene_code = ? AND status = 1 FOR UPDATE',
                [$adminId, $appId, $sceneCode]
            );
            if ($rule === null || (int) $rule['enabled'] !== 1) {
                return ['granted' => false, 'status' => 'disabled', 'message' => '当前奖励规则未启用'];
            }
            self::assertAudience($rule, $user, $context);
            self::assertConditions($rule, $context, $refId);
            $reward = self::normalizeReward(self::decodeObject($rule['reward_json'] ?? '{}'));
            if (!self::hasReward($reward)) {
                return ['granted' => false, 'status' => 'empty', 'message' => '奖励规则尚未配置奖励内容'];
            }

            $periodKey = self::periodKey((string) $rule['cycle_type']);
            $dedupeKey = self::dedupeKey($sceneCode, $userId, $refType, $refId, $periodKey, $context);
            $storedContext = $context;
            unset($storedContext['dedupe_key_override'], $storedContext['force_grant'], $storedContext['manual_grant']);
            $existing = Database::one(
                'SELECT * FROM app_reward_events WHERE app_id = ? AND dedupe_key = ? FOR UPDATE',
                [$appId, $dedupeKey]
            );
            $canGrant = self::canGrantNow((string) $rule['grant_mode'], $context);
            if ($existing !== null && (string) $existing['status'] === 'granted') {
                return [
                    'granted' => false,
                    'status' => 'duplicate',
                    'message' => '该奖励已经发放，请勿重复领取',
                    'event_id' => (int) $existing['id'],
                ];
            }

            self::assertLimits($rule, $userId, $periodKey, $existing === null ? 0 : (int) $existing['id']);
            if (!$canGrant) {
                if ($existing === null) {
                    $eventId = self::insertEvent(
                        $rule,
                        $userId,
                        $refType,
                        $refId,
                        $periodKey,
                        $dedupeKey,
                        $reward,
                        $storedContext,
                        'pending',
                        '等待有权限的上级审核',
                        $actorType,
                        $actorId
                    );
                } else {
                    $eventId = (int) $existing['id'];
                }
                return [
                    'granted' => false,
                    'status' => 'pending',
                    'message' => '奖励申请已提交，等待审核',
                    'event_id' => $eventId,
                ];
            }

            if ($existing === null) {
                $eventId = self::insertEvent(
                    $rule,
                    $userId,
                    $refType,
                    $refId,
                    $periodKey,
                    $dedupeKey,
                    $reward,
                    $storedContext,
                    'granted',
                    '',
                    $actorType,
                    $actorId
                );
            } else {
                $eventId = (int) $existing['id'];
                Database::execute(
                    "UPDATE app_reward_events SET status = 'granted', reason = '', reward_json = ?,
                     context_json = ?, actor_type = ?, actor_id = ?, granted_at = NOW(), updated_at = NOW()
                     WHERE id = ?",
                    [self::json($reward), self::json($storedContext), $actorType, $actorId, $eventId]
                );
            }

            $wallet = WalletService::applyRewards(
                $user,
                $reward,
                'reward_' . $sceneCode,
                $refType !== '' ? $refType : 'reward_event',
                $refId > 0 ? $refId : $eventId
            );
            return [
                'granted' => true,
                'status' => 'granted',
                'message' => '奖励已发放',
                'event_id' => $eventId,
                'scene_code' => $sceneCode,
                'scene_name' => (string) $rule['scene_name'],
                'reward' => $reward,
                'wallet' => WalletService::publicWallet($wallet, $appId),
            ];
        });
    }

    /**
     * 触发非阻断型奖励。奖励规则、适用范围或周期限制不应影响注册、登录等主营流程。
     */
    public static function trigger(
        array $user,
        string $sceneCode,
        string $refType = '',
        int $refId = 0,
        array $context = [],
        string $actorType = 'system',
        int $actorId = 0
    ): array {
        try {
            return self::grant($user, $sceneCode, $refType, $refId, $context, $actorType, $actorId);
        } catch (HttpException $exception) {
            $status = match ($exception->httpStatus) {
                403 => 'not_eligible',
                409 => 'limit_reached',
                422 => 'condition_not_met',
                default => 'skipped',
            };
            return [
                'granted' => false,
                'status' => $status,
                'message' => $exception->getMessage(),
                'api_code' => $exception->apiCode,
                'details' => $exception->data,
            ];
        } catch (Throwable $exception) {
            return [
                'granted' => false,
                'status' => 'error',
                'message' => '奖励暂未发放，不影响当前操作',
            ];
        }
    }

    public static function events(
        int $adminId,
        int $appId,
        int $page,
        int $limit,
        ?int $userId = null,
        string $sceneCode = '',
        string $status = ''
    ): array {
        $where = ['e.admin_id = ?', 'e.app_id = ?'];
        $params = [$adminId, $appId];
        if ($userId !== null && $userId > 0) {
            $where[] = 'e.user_id = ?';
            $params[] = $userId;
        }
        if ($sceneCode !== '') {
            self::assertScene($sceneCode);
            $where[] = 'e.scene_code = ?';
            $params[] = $sceneCode;
        }
        if ($status !== '') {
            if (!in_array($status, ['pending', 'granted', 'rejected', 'reversed'], true)) {
                throw new HttpException('奖励流水状态不正确', 0, 422);
            }
            $where[] = 'e.status = ?';
            $params[] = $status;
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM app_reward_events e WHERE {$whereSql}",
            $params
        )['total'] ?? 0);
        $offset = ($page - 1) * $limit;
        $items = Database::all(
            "SELECT e.*, u.account, p.nickname, p.avatar, r.scene_name
             FROM app_reward_events e
             INNER JOIN users u ON u.id = e.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             INNER JOIN app_reward_rules r ON r.id = e.rule_id
             WHERE {$whereSql} ORDER BY e.id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        foreach ($items as &$item) {
            $item = self::presentEvent($item);
        }
        unset($item);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    private static function insertEvent(
        array $rule,
        int $userId,
        string $refType,
        int $refId,
        string $periodKey,
        string $dedupeKey,
        array $reward,
        array $context,
        string $status,
        string $reason,
        string $actorType,
        int $actorId
    ): int {
        return Database::insert(
            'INSERT INTO app_reward_events
             (admin_id, app_id, user_id, rule_id, scene_code, ref_type, ref_id, period_key,
              dedupe_key, reward_json, context_json, status, reason, actor_type, actor_id,
              granted_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (int) $rule['admin_id'], (int) $rule['app_id'], $userId, (int) $rule['id'],
                (string) $rule['scene_code'], mb_substr($refType, 0, 40), max(0, $refId), $periodKey,
                $dedupeKey, self::json($reward), self::json($context), $status, mb_substr($reason, 0, 500),
                mb_substr($actorType, 0, 20), max(0, $actorId), $status === 'granted' ? date('Y-m-d H:i:s') : null,
            ]
        );
    }

    private static function assertLimits(array $rule, int $userId, string $periodKey, int $excludeEventId): void
    {
        $params = [(int) $rule['app_id'], (int) $rule['id'], $userId];
        $exclude = '';
        if ($excludeEventId > 0) {
            $exclude = ' AND id <> ?';
            $params[] = $excludeEventId;
        }
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM app_reward_events
             WHERE app_id = ? AND rule_id = ? AND user_id = ? AND status = 'granted'{$exclude}",
            $params
        )['total'] ?? 0);
        $userTotalLimit = (int) $rule['user_total_limit'];
        if ($userTotalLimit > 0 && $total >= $userTotalLimit) {
            throw new HttpException('已达到该奖励的用户累计领取上限', 0, 409);
        }
        $cycleLimit = (int) $rule['cycle_limit'];
        if ($cycleLimit <= 0 || $periodKey === '') {
            return;
        }
        $periodParams = [(int) $rule['app_id'], (int) $rule['id'], $userId, $periodKey];
        $periodExclude = '';
        if ($excludeEventId > 0) {
            $periodExclude = ' AND id <> ?';
            $periodParams[] = $excludeEventId;
        }
        $periodTotal = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM app_reward_events
             WHERE app_id = ? AND rule_id = ? AND user_id = ? AND period_key = ?
               AND status = 'granted'{$periodExclude}",
            $periodParams
        )['total'] ?? 0);
        if ($periodTotal >= $cycleLimit) {
            throw new HttpException('已达到当前周期的奖励领取上限', 0, 409);
        }
    }

    private static function assertAudience(array $rule, array $user, array $context): void
    {
        $audience = self::decodeObject($rule['audience_json'] ?? '{}');
        if ($audience === []) {
            return;
        }
        if (isset($audience['user_ids']) && is_array($audience['user_ids'])) {
            $ids = array_map('intval', $audience['user_ids']);
            if (!in_array((int) $user['id'], $ids, true)) {
                throw new HttpException('当前用户不在该奖励的适用范围内', 403, 403);
            }
        }
        if (isset($audience['excluded_user_ids']) && is_array($audience['excluded_user_ids'])) {
            $ids = array_map('intval', $audience['excluded_user_ids']);
            if (in_array((int) $user['id'], $ids, true)) {
                throw new HttpException('当前用户已被排除在该奖励范围外', 403, 403);
            }
        }
        if (!empty($audience['vip_only']) && empty($context['is_vip'])) {
            $wallet = Database::one(
                'SELECT vip_expired_at FROM user_wallets WHERE app_id = ? AND user_id = ?',
                [(int) $user['app_id'], (int) $user['id']]
            );
            if ($wallet === null || $wallet['vip_expired_at'] === null
                || strtotime((string) $wallet['vip_expired_at']) <= time()) {
                throw new HttpException('该奖励仅限有效会员领取', 403, 403);
            }
        }
    }

    private static function assertConditions(array $rule, array $context, int $refId): void
    {
        $conditions = self::decodeObject($rule['conditions_json'] ?? '{}');
        if (!empty($conditions['reference_required']) && $refId <= 0) {
            throw new HttpException('该奖励必须关联有效业务记录', 0, 422);
        }
        $minimumLength = max(0, (int) ($conditions['minimum_content_length'] ?? 0));
        if ($minimumLength > 0 && mb_strlen(trim((string) ($context['content'] ?? ''))) < $minimumLength) {
            throw new HttpException('内容长度未达到奖励条件', 0, 422);
        }
        if (isset($conditions['required_status'])
            && (string) ($context['status'] ?? '') !== (string) $conditions['required_status']) {
            throw new HttpException('当前业务状态尚未满足奖励条件', 0, 422);
        }
    }

    private static function canGrantNow(string $grantMode, array $context): bool
    {
        if ($grantMode === 'automatic') {
            return true;
        }
        if ($grantMode === 'after_review') {
            return !empty($context['approved']) || !empty($context['force_grant']);
        }
        return !empty($context['manual_grant']) || !empty($context['force_grant']);
    }

    private static function dedupeKey(
        string $sceneCode,
        int $userId,
        string $refType,
        int $refId,
        string $periodKey,
        array $context
    ): string {
        $override = strtolower(trim((string) ($context['dedupe_key_override'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $override) === 1) {
            return $override;
        }
        $eventKey = trim((string) ($context['event_key'] ?? ''));
        if ($refId <= 0 && $eventKey === '' && $periodKey === '') {
            $eventKey = bin2hex(random_bytes(12));
        }
        return hash('sha256', implode('|', [
            $sceneCode,
            (string) $userId,
            $refType,
            (string) max(0, $refId),
            $periodKey,
            $eventKey,
        ]));
    }

    private static function periodKey(string $cycle): string
    {
        if ($cycle === 'once') {
            return 'once';
        }
        if ($cycle === 'daily') {
            return date('Y-m-d');
        }
        if ($cycle === 'weekly') {
            return date('o-\WW');
        }
        if ($cycle === 'monthly') {
            return date('Y-m');
        }
        return '';
    }

    private static function presentRule(array $row): array
    {
        $grantNames = ['automatic' => '自动发放', 'after_review' => '审核通过后发放', 'manual' => '人工发放'];
        $cycleNames = ['once' => '仅一次', 'daily' => '每天', 'weekly' => '每周', 'monthly' => '每月', 'unlimited' => '不限周期'];
        return [
            'id' => (int) $row['id'],
            'scene_code' => (string) $row['scene_code'],
            'scene_name' => (string) $row['scene_name'],
            'description' => (string) $row['description'],
            'enabled' => (int) $row['enabled'] === 1,
            'grant_mode' => (string) $row['grant_mode'],
            'grant_mode_name' => $grantNames[(string) $row['grant_mode']] ?? '未知方式',
            'cycle_type' => (string) $row['cycle_type'],
            'cycle_type_name' => $cycleNames[(string) $row['cycle_type']] ?? '未知周期',
            'cycle_limit' => (int) $row['cycle_limit'],
            'user_total_limit' => (int) $row['user_total_limit'],
            'reward' => self::normalizeReward(self::decodeObject($row['reward_json'] ?? '{}')),
            'conditions' => self::decodeObject($row['conditions_json'] ?? '{}'),
            'audience' => self::decodeObject($row['audience_json'] ?? '{}'),
            'manager_level' => (int) $row['manager_level'],
            'force_sync' => (int) $row['force_sync'] === 1,
            'configured' => true,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private static function presentEvent(array $row): array
    {
        $statusNames = ['pending' => '等待审核', 'granted' => '已发放', 'rejected' => '已拒绝', 'reversed' => '已撤销'];
        return [
            'id' => (int) $row['id'],
            'user' => [
                'id' => (int) $row['user_id'],
                'account' => (string) ($row['account'] ?? ''),
                'nickname' => (string) ($row['nickname'] ?? ''),
                'avatar' => (string) ($row['avatar'] ?? ''),
            ],
            'scene_code' => (string) $row['scene_code'],
            'scene_name' => (string) ($row['scene_name'] ?? (self::SCENES[(string) $row['scene_code']]['name'] ?? '奖励')),
            'reference' => ['type' => (string) $row['ref_type'], 'id' => (int) $row['ref_id']],
            'period_key' => (string) $row['period_key'],
            'reward' => self::normalizeReward(self::decodeObject($row['reward_json'] ?? '{}')),
            'context' => self::decodeObject($row['context_json'] ?? '{}'),
            'status' => (string) $row['status'],
            'status_name' => $statusNames[(string) $row['status']] ?? '未知状态',
            'reason' => (string) $row['reason'],
            'actor' => ['type' => (string) $row['actor_type'], 'id' => (int) $row['actor_id']],
            'granted_at' => $row['granted_at'],
            'created_at' => $row['created_at'],
        ];
    }

    private static function normalizeReward($value): array
    {
        $value = self::objectValue($value, '奖励内容');
        $reward = self::emptyReward();
        foreach (self::ASSETS as $asset) {
            if (!array_key_exists($asset, $value)) {
                continue;
            }
            try {
                $units = WalletService::amountUnits($asset, $value[$asset], true);
                $amount = WalletService::canonicalAmount($asset, $value[$asset], true);
            } catch (HttpException) {
                throw new HttpException(
                    $asset === 'balance'
                        ? '余额奖励最多保留两位小数'
                        : '该奖励必须填写非负整数：' . $asset,
                    0,
                    422
                );
            }
            if ($units < 0) {
                throw new HttpException('奖励数值超出允许范围：' . $asset, 0, 422);
            }
            $reward[$asset] = $amount;
        }
        return $reward;
    }

    private static function emptyReward(): array
    {
        return ['balance' => '0.00', 'experience' => 0, 'integral' => 0, 'document_credit' => 0, 'vip_days' => 0];
    }

    private static function hasReward(array $reward): bool
    {
        foreach ($reward as $asset => $value) {
            if (WalletService::amountUnits((string) $asset, $value, true) > 0) {
                return true;
            }
        }
        return false;
    }

    private static function objectValue($value, string $label): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw new HttpException($label . '格式不正确', 0, 422);
            }
            return $decoded;
        }
        if (!is_array($value)) {
            throw new HttpException($label . '必须是对象', 0, 422);
        }
        return $value;
    }

    private static function decodeObject($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function boolValue($value, string $label): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [1, '1', 'true'], true)) {
            return true;
        }
        if (in_array($value, [0, '0', 'false'], true)) {
            return false;
        }
        throw new HttpException($label . '必须为开启或关闭', 0, 422);
    }

    private static function unsignedInt($value, string $label, int $max): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new HttpException($label . '必须是整数', 0, 422);
        }
        $value = (int) $value;
        if ($value < 0 || $value > $max) {
            throw new HttpException($label . '超出允许范围', 0, 422);
        }
        return $value;
    }

    private static function assertScene(string $sceneCode): void
    {
        if (!isset(self::SCENES[$sceneCode])) {
            throw new HttpException('不支持的奖励场景', 0, 422);
        }
    }

    private static function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
