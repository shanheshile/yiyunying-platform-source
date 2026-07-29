<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Validator;

final class HierarchyActivityService
{
    private const TYPES = ['red_packet', 'lottery', 'bounty'];

    public static function feed(Request $request, array $actor): array
    {
        self::requireEnabled($actor);
        $where = ['root_platform_id = ?'];
        $params = [(int) $actor['root_platform_id']];
        $type = trim((string) $request->input('activity_type', ''));
        if ($type !== '') { self::requireType($type); $where[] = 'activity_type = ?'; $params[] = $type; }
        $status = trim((string) $request->input('status', ''));
        if ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') { $where[] = '(title LIKE ? OR description LIKE ?)'; array_push($params, '%' . $keyword . '%', '%' . $keyword . '%'); }
        $rows = Database::all(
            'SELECT * FROM hierarchy_activities WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 5000',
            $params
        );
        $mine = self::boolValue($request->input('mine', false));
        $visible = [];
        foreach ($rows as $row) {
            if ($mine && !self::isOwner($row, $actor)) continue;
            if (!$mine && !self::isVisible($row, $actor)) continue;
            $visible[] = self::publicActivity($row, $actor, false);
        }
        $page = $request->page();
        $limit = $request->limit();
        $total = count($visible);
        $items = array_slice($visible, ($page - 1) * $limit, $limit);
        return Pagination::data($items, $total, $page, $limit);
    }

    public static function show(array $actor, int $activityId): array
    {
        self::requireEnabled($actor);
        $row = self::row($activityId);
        if (!self::isVisible($row, $actor)) throw new HttpException('活动不存在或不在可见范围内', 404, 404);
        return self::publicActivity($row, $actor, true);
    }

    public static function create(array $actor, array $data): array
    {
        self::requirePublisher($actor);
        $type = trim((string) ($data['activity_type'] ?? ''));
        self::requireType($type);
        $title = Validator::string($data['title'] ?? '', 'title', 1, 200);
        $description = mb_substr((string) ($data['description'] ?? ''), 0, 100000);
        $funding = trim((string) ($data['funding_mode'] ?? 'balance'));
        if (!in_array($funding, ['balance', 'issued'], true)) throw new HttpException('funding_mode 仅支持 balance 或 issued', 0, 422);
        if ($funding === 'issued' && (int) $actor['actor_level'] !== 1) {
            throw new HttpException('只有 1 级平台可以发布官方发放型活动，其他角色必须使用自身余额', 403, 403);
        }
        [$targets, $audienceSync] = self::normalizeAudience($actor, $data);
        $rules = (array) ($data['rules'] ?? []);
        $rules['audience_sync'] = $audienceSync;
        $startsAt = self::dateValue($data['starts_at'] ?? null, 'starts_at');
        $endsAt = self::dateValue($data['ends_at'] ?? null, 'ends_at');
        if ($startsAt !== null && $endsAt !== null && strtotime($endsAt) <= strtotime($startsAt)) {
            throw new HttpException('活动结束时间必须晚于开始时间', 0, 422);
        }
        $status = trim((string) ($data['status'] ?? 'active'));
        if (!in_array($status, ['draft', 'active'], true)) throw new HttpException('新活动状态仅支持 draft 或 active', 0, 422);

        $packetMode = null;
        $prizes = [];
        if ($type === 'red_packet') {
            $budget = Validator::integer($data['total_balance'] ?? null, 'total_balance', 1, PHP_INT_MAX);
            $slots = Validator::integer($data['total_count'] ?? null, 'total_count', 1, 100000);
            if ($budget < $slots) throw new HttpException('红包总余额不能小于红包份数', 0, 422);
            $packetMode = trim((string) ($data['packet_mode'] ?? 'random'));
            if (!in_array($packetMode, ['equal', 'random'], true)) throw new HttpException('packet_mode 仅支持 equal 或 random', 0, 422);
        } elseif ($type === 'bounty') {
            $budget = Validator::integer($data['reward_balance'] ?? null, 'reward_balance', 1, PHP_INT_MAX);
            $slots = 1;
        } else {
            $prizes = self::normalizePrizes($data['prizes'] ?? []);
            $budget = 0;
            $slots = 0;
            foreach ($prizes as $prize) {
                $budget += $prize['reward_balance'] * $prize['stock'];
                $slots += $prize['stock'];
            }
        }
        $maxBudget = max(1, (int) PlatformService::setting((int) $actor['platform_id'], 'hierarchical_activity_max_budget', 1000000000));
        if ($budget > $maxBudget) throw new HttpException('活动预算超过所属平台上限', 0, 422, ['max_budget' => $maxBudget]);
        $perActorLimit = $type === 'bounty' ? 1 : Validator::integer($data['per_actor_limit'] ?? 1, 'per_actor_limit', 1, min(1000, $slots));

        $activityId = Database::transaction(static function () use (
            $actor, $data, $type, $funding, $title, $description, $packetMode, $budget,
            $slots, $perActorLimit, $status, $startsAt, $endsAt, $targets, $prizes, $rules
        ): int {
            $id = Database::insert(
                'INSERT INTO hierarchy_activities
                 (root_platform_id, owner_type, owner_id, owner_level, owner_platform_id, owner_admin_id,
                  activity_type, funding_mode, title, description, packet_mode, total_balance,
                  remaining_balance, total_slots, remaining_slots, per_actor_limit, rules_json,
                  status, starts_at, ends_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    (int) $actor['root_platform_id'], $actor['actor_type'], (int) $actor['actor_id'],
                    (int) $actor['actor_level'], (int) $actor['platform_id'], $actor['admin_id'],
                    $type, $funding, $title, $description, $packetMode, $budget, $budget,
                    $slots, $slots, $perActorLimit,
                    json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $status, $startsAt, $endsAt,
                ]
            );
            foreach ($targets as $target) {
                Database::execute(
                    'INSERT INTO hierarchy_activity_targets
                     (activity_id, target_scope, target_type, target_level, target_id, actor_type, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())',
                    [$id, $target['target_scope'], $target['target_type'], $target['target_level'], $target['target_id'], $target['actor_type']]
                );
            }
            foreach ($prizes as $prize) {
                Database::execute(
                    'INSERT INTO hierarchy_activity_prizes
                     (activity_id, name, reward_balance, weight, stock, remaining_stock, sort_order, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                    [$id, $prize['name'], $prize['reward_balance'], $prize['weight'], $prize['stock'], $prize['stock'], $prize['sort_order']]
                );
            }
            if ($funding === 'balance' && $budget > 0) {
                HierarchyActorService::adjust($actor, -$budget, 'hierarchy_activity_escrow', 'hierarchy_activity', $id, $actor, '发布活动冻结余额');
            }
            return $id;
        });
        return self::show($actor, $activityId);
    }

    public static function claim(array $actor, int $activityId): array
    {
        return Database::transaction(static function () use ($actor, $activityId): array {
            $activity = self::actionRow($actor, $activityId, 'red_packet');
            self::assertNotOwner($activity, $actor);
            self::assertEntryLimit($activity, $actor, 'claim');
            $remainBalance = (int) $activity['remaining_balance'];
            $remainSlots = (int) $activity['remaining_slots'];
            if ($remainBalance <= 0 || $remainSlots <= 0) throw new HttpException('红包已经领完', 0, 409);
            if ($remainSlots === 1) {
                $reward = $remainBalance;
            } elseif ($activity['packet_mode'] === 'equal') {
                $reward = max(1, intdiv($remainBalance, $remainSlots));
            } else {
                $max = max(1, min($remainBalance - ($remainSlots - 1), intdiv($remainBalance * 2, $remainSlots)));
                $reward = random_int(1, $max);
            }
            $entryId = self::entry($activityId, $actor, 'claim', null, $reward);
            HierarchyActorService::adjust($actor, $reward, 'hierarchy_red_packet_claim', 'hierarchy_activity', $activityId, $actor, '领取层级红包');
            self::consume($activity, $reward, 1);
            return ['entry_id' => $entryId, 'activity_id' => $activityId, 'reward_balance' => $reward, 'balance' => HierarchyActorService::balance($actor)];
        });
    }

    public static function draw(array $actor, int $activityId): array
    {
        return Database::transaction(static function () use ($actor, $activityId): array {
            $activity = self::actionRow($actor, $activityId, 'lottery');
            self::assertNotOwner($activity, $actor);
            self::assertEntryLimit($activity, $actor, 'draw');
            $prizes = Database::all('SELECT * FROM hierarchy_activity_prizes WHERE activity_id = ? AND remaining_stock > 0 FOR UPDATE', [$activityId]);
            if ($prizes === []) throw new HttpException('奖品已抽完', 0, 409);
            $totalWeight = array_sum(array_map(static fn (array $item): int => max(1, (int) $item['weight']), $prizes));
            $hit = random_int(1, $totalWeight);
            $prize = $prizes[0];
            foreach ($prizes as $candidate) {
                $hit -= max(1, (int) $candidate['weight']);
                if ($hit <= 0) { $prize = $candidate; break; }
            }
            $reward = (int) $prize['reward_balance'];
            if ($reward > (int) $activity['remaining_balance']) throw new HttpException('活动奖池余额异常，请联系发布者', -1, 500);
            Database::execute('UPDATE hierarchy_activity_prizes SET remaining_stock = remaining_stock - 1 WHERE id = ?', [(int) $prize['id']]);
            $entryId = self::entry($activityId, $actor, 'draw', (int) $prize['id'], $reward);
            if ($reward > 0) HierarchyActorService::adjust($actor, $reward, 'hierarchy_lottery_draw', 'hierarchy_activity', $activityId, $actor, '层级抽奖奖励');
            self::consume($activity, $reward, 1);
            return [
                'entry_id' => $entryId,
                'activity_id' => $activityId,
                'prize' => ['id' => (int) $prize['id'], 'name' => $prize['name'], 'reward_balance' => $reward],
                'balance' => HierarchyActorService::balance($actor),
            ];
        });
    }

    public static function submit(array $actor, int $activityId, array $data): array
    {
        $content = Validator::string($data['content'] ?? '', 'content', 1, 100000);
        return Database::transaction(static function () use ($actor, $activityId, $data, $content): array {
            $activity = self::actionRow($actor, $activityId, 'bounty');
            self::assertNotOwner($activity, $actor);
            try {
                $id = Database::insert(
                    'INSERT INTO hierarchy_activity_submissions
                     (activity_id, actor_type, actor_id, actor_level, platform_id, admin_id, app_id,
                      content, attachments_json, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    [
                        $activityId, $actor['actor_type'], (int) $actor['actor_id'], (int) $actor['actor_level'],
                        (int) $actor['platform_id'], $actor['admin_id'], $actor['app_id'], $content,
                        json_encode((array) ($data['attachments'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'submitted',
                    ]
                );
            } catch (\PDOException $e) {
                if ((string) $e->getCode() === '23000') throw new HttpException('你已经提交过该悬赏', 0, 409);
                throw $e;
            }
            return ['submission_id' => $id, 'activity_id' => $activityId, 'status' => 'submitted'];
        });
    }

    public static function award(array $actor, int $activityId, int $submissionId): array
    {
        return Database::transaction(static function () use ($actor, $activityId, $submissionId): array {
            $activity = self::row($activityId, true);
            self::assertManage($activity, $actor);
            if ($activity['activity_type'] !== 'bounty' || $activity['status'] !== 'active') throw new HttpException('悬赏不存在或不可结算', 0, 409);
            $submission = Database::one(
                "SELECT * FROM hierarchy_activity_submissions WHERE id = ? AND activity_id = ? AND status = 'submitted' FOR UPDATE",
                [$submissionId, $activityId]
            );
            if ($submission === null) throw new HttpException('投稿不存在或不可选中', 404, 404);
            $winner = HierarchyActorService::load((string) $submission['actor_type'], (int) $submission['actor_id']);
            $reward = (int) $activity['remaining_balance'];
            if ($reward <= 0) throw new HttpException('悬赏奖池余额不足', 0, 409);
            HierarchyActorService::adjust($winner, $reward, 'hierarchy_bounty_award', 'hierarchy_activity', $activityId, $actor, '层级悬赏奖励');
            $entryId = self::entry($activityId, $winner, 'award', null, $reward);
            Database::execute("UPDATE hierarchy_activity_submissions SET status = CASE WHEN id = ? THEN 'accepted' ELSE 'rejected' END, updated_at = NOW() WHERE activity_id = ?", [$submissionId, $activityId]);
            Database::execute("UPDATE hierarchy_activities SET remaining_balance = 0, remaining_slots = 0, status = 'completed', updated_at = NOW() WHERE id = ?", [$activityId]);
            return [
                'entry_id' => $entryId,
                'submission_id' => $submissionId,
                'winner' => ['actor_type' => $winner['actor_type'], 'actor_id' => $winner['actor_id'], 'name' => $winner['name']],
                'reward_balance' => $reward,
            ];
        });
    }

    public static function finish(array $actor, int $activityId, bool $cancel): array
    {
        return Database::transaction(static function () use ($actor, $activityId, $cancel): array {
            $activity = self::row($activityId, true);
            self::assertManage($activity, $actor);
            if (!in_array($activity['status'], ['draft', 'active'], true)) throw new HttpException('活动已经结束', 0, 409);
            $refund = (int) $activity['remaining_balance'];
            if ($activity['funding_mode'] === 'balance' && $refund > 0) {
                $owner = HierarchyActorService::load((string) $activity['owner_type'], (int) $activity['owner_id']);
                HierarchyActorService::adjust($owner, $refund, 'hierarchy_activity_refund', 'hierarchy_activity', $activityId, $actor, $cancel ? '取消活动退回余额' : '结束活动退回余额');
            }
            $status = $cancel ? 'cancelled' : 'closed';
            Database::execute('UPDATE hierarchy_activities SET remaining_balance = 0, remaining_slots = 0, status = ?, updated_at = NOW() WHERE id = ?', [$status, $activityId]);
            Database::execute("UPDATE hierarchy_activity_submissions SET status = 'cancelled', updated_at = NOW() WHERE activity_id = ? AND status = 'submitted'", [$activityId]);
            return ['activity_id' => $activityId, 'status' => $status, 'refunded_balance' => $activity['funding_mode'] === 'balance' ? $refund : 0];
        });
    }

    public static function balance(array $actor): array
    {
        return ['balance' => HierarchyActorService::balance($actor), 'logs' => HierarchyActorService::logs($actor, 100)];
    }

    private static function publicActivity(array $row, array $actor, bool $details): array
    {
        $rules = json_decode((string) ($row['rules_json'] ?? ''), true) ?: [];
        $participationAllowed = !self::isOwner($row, $actor) && self::isParticipationTarget($row, $actor);
        $currentlyAvailable = $row['status'] === 'active'
            && ($row['starts_at'] === null || strtotime((string) $row['starts_at']) <= time())
            && ($row['ends_at'] === null || strtotime((string) $row['ends_at']) > time());
        $item = [
            'id' => (int) $row['id'],
            'activity_type' => $row['activity_type'],
            'funding_mode' => $row['funding_mode'],
            'title' => $row['title'],
            'description' => $row['description'],
            'packet_mode' => $row['packet_mode'],
            'total_balance' => (int) $row['total_balance'],
            'remaining_balance' => (int) $row['remaining_balance'],
            'total_slots' => (int) $row['total_slots'],
            'remaining_slots' => (int) $row['remaining_slots'],
            'per_actor_limit' => (int) $row['per_actor_limit'],
            'status' => $row['status'],
            'starts_at' => $row['starts_at'],
            'ends_at' => $row['ends_at'],
            'created_at' => $row['created_at'],
            'owner' => [
                'actor_type' => $row['owner_type'],
                'actor_id' => (int) $row['owner_id'],
                'actor_level' => (int) $row['owner_level'],
            ],
            'is_owner' => self::isOwner($row, $actor),
            'can_manage' => self::canManage($row, $actor),
            'audience_sync' => (bool) ($rules['audience_sync'] ?? true),
            'participation_allowed' => $participationAllowed,
            'can_participate' => $participationAllowed && $currentlyAvailable,
            'my_entry_count' => (int) (Database::one(
                'SELECT COUNT(*) AS total FROM hierarchy_activity_entries WHERE activity_id = ? AND actor_type = ? AND actor_id = ?',
                [(int) $row['id'], $actor['actor_type'], (int) $actor['actor_id']]
            )['total'] ?? 0),
        ];
        if ($details) {
            $item['rules'] = $rules;
            $targets = self::audienceTargets((int) $row['id']);
            $item['visibility_targets'] = array_values(array_filter(
                $targets,
                static fn (array $target): bool => in_array($target['target_scope'], ['both', 'visibility'], true)
            ));
            $item['participation_targets'] = array_values(array_filter(
                $targets,
                static fn (array $target): bool => in_array($target['target_scope'], ['both', 'participation'], true)
            ));
            $item['prizes'] = Database::all(
                'SELECT id, name, reward_balance, weight, stock, remaining_stock, sort_order FROM hierarchy_activity_prizes WHERE activity_id = ? ORDER BY sort_order, id',
                [(int) $row['id']]
            );
            $submissionWhere = self::canManage($row, $actor)
                ? 'activity_id = ?'
                : 'activity_id = ? AND actor_type = ? AND actor_id = ?';
            $submissionParams = self::canManage($row, $actor)
                ? [(int) $row['id']]
                : [(int) $row['id'], $actor['actor_type'], (int) $actor['actor_id']];
            $item['submissions'] = Database::all(
                "SELECT id, actor_type, actor_id, actor_level, content, attachments_json, status, created_at, updated_at
                 FROM hierarchy_activity_submissions WHERE {$submissionWhere} ORDER BY id DESC",
                $submissionParams
            );
        }
        return $item;
    }

    private static function normalizeAudience(array $actor, array $data): array
    {
        $sync = !array_key_exists('audience_sync', $data) || self::boolValue($data['audience_sync']);
        if ($sync) {
            $targets = self::normalizeTargets($actor, $data['targets'] ?? [], true, 'targets');
            foreach ($targets as &$target) $target['target_scope'] = 'both';
            unset($target);
            return [$targets, true];
        }

        $visibility = self::normalizeTargets($actor, $data['visibility_targets'] ?? [], false, 'visibility_targets');
        $participation = self::normalizeTargets($actor, $data['participation_targets'] ?? [], true, 'participation_targets');
        $merged = [];
        foreach ($visibility as $target) {
            $target['target_scope'] = 'visibility';
            $merged[self::targetKey($target)] = $target;
        }
        foreach ($participation as $target) {
            $target['target_scope'] = 'both';
            $merged[self::targetKey($target)] = $target;
        }
        return [array_values($merged), false];
    }

    private static function normalizeTargets(array $actor, $value, bool $required = true, string $field = 'targets'): array
    {
        if (is_string($value)) $value = json_decode($value, true);
        if (!is_array($value)) throw new HttpException($field . ' 必须是 JSON 数组', 0, 422);
        if ($required && $value === []) throw new HttpException($field . ' 必须至少指定一个同级或下级范围', 0, 422);
        $targets = [];
        $seen = [];
        foreach ($value as $input) {
            if (!is_array($input)) throw new HttpException($field . ' 每一项必须是对象', 0, 422);
            $type = trim((string) ($input['type'] ?? $input['target_type'] ?? ''));
            $target = ['target_type' => $type, 'target_level' => null, 'target_id' => null, 'actor_type' => null];
            if ($type === 'level') {
                $level = (int) ($input['level'] ?? $input['target_level'] ?? 0);
                if ($level < (int) $actor['actor_level'] || $level > 4) throw new HttpException('只能向当前角色的同级或下级发布活动', 0, 422);
                $target['target_level'] = $level;
            } elseif (in_array($type, ['platform', 'admin', 'app'], true)) {
                $target['target_id'] = Validator::integer($input['id'] ?? $input['target_id'] ?? null, 'target_id', 1, PHP_INT_MAX);
                self::validateSpecificTarget($actor, $type, (int) $target['target_id']);
            } elseif ($type === 'actor') {
                $target['actor_type'] = trim((string) ($input['actor_type'] ?? ''));
                $target['target_id'] = Validator::integer($input['id'] ?? $input['target_id'] ?? null, 'target_id', 1, PHP_INT_MAX);
                $specific = HierarchyActorService::load((string) $target['actor_type'], (int) $target['target_id']);
                self::validateActorReach($actor, $specific);
            } else {
                throw new HttpException('target_type 仅支持 level、platform、admin、app、actor', 0, 422);
            }
            $key = implode(':', [$target['target_type'], $target['target_level'] ?? '', $target['target_id'] ?? '', $target['actor_type'] ?? '']);
            if (!isset($seen[$key])) { $targets[] = $target; $seen[$key] = true; }
        }
        return $targets;
    }

    private static function targetKey(array $target): string
    {
        return implode(':', [$target['target_type'], $target['target_level'] ?? '', $target['target_id'] ?? '', $target['actor_type'] ?? '']);
    }

    private static function normalizePrizes($value): array
    {
        if (is_string($value)) $value = json_decode($value, true);
        if (!is_array($value) || $value === []) throw new HttpException('抽奖活动至少需要一个奖项', 0, 422);
        if (count($value) > 200) throw new HttpException('单个抽奖活动最多 200 个奖项', 0, 422);
        $result = [];
        foreach ($value as $index => $item) {
            if (!is_array($item)) throw new HttpException('prizes 每一项必须是对象', 0, 422);
            $result[] = [
                'name' => Validator::string($item['name'] ?? '', 'prize.name', 1, 120),
                'reward_balance' => Validator::integer($item['reward_balance'] ?? 0, 'prize.reward_balance', 0, PHP_INT_MAX),
                'weight' => Validator::integer($item['weight'] ?? 1, 'prize.weight', 1, 1000000000),
                'stock' => Validator::integer($item['stock'] ?? 1, 'prize.stock', 1, 100000),
                'sort_order' => (int) ($item['sort_order'] ?? $index),
            ];
        }
        return $result;
    }

    private static function validateSpecificTarget(array $actor, string $type, int $targetId): void
    {
        if ($type === 'platform') {
            $row = Database::one('SELECT * FROM platform_accounts WHERE id = ? AND deleted_at IS NULL', [$targetId]);
            if ($row === null) throw new HttpException('目标平台不存在', 404, 404);
            self::validateActorReach($actor, HierarchyActorService::platform($row));
            return;
        }
        if ($type === 'admin') {
            self::validateActorReach($actor, HierarchyActorService::admin(AdminAccessService::context($targetId)));
            return;
        }
        $row = Database::one(
            'SELECT ap.id, ap.admin_id, a.platform_id, p.level AS platform_level, p.parent_id
             FROM apps ap INNER JOIN admins a ON a.id = ap.admin_id
             INNER JOIN platform_accounts p ON p.id = a.platform_id
             WHERE ap.id = ? AND ap.deleted_at IS NULL',
            [$targetId]
        );
        if ($row === null) throw new HttpException('目标应用不存在', 404, 404);
        $specific = [
            'actor_type' => 'user', 'actor_id' => 0, 'actor_level' => 4,
            'platform_id' => (int) $row['platform_id'],
            'root_platform_id' => (int) $row['platform_level'] === 1 ? (int) $row['platform_id'] : (int) $row['parent_id'],
            'admin_id' => (int) $row['admin_id'], 'app_id' => (int) $row['id'], 'name' => '',
        ];
        self::validateActorReach($actor, $specific);
    }

    private static function validateActorReach(array $owner, array $target): void
    {
        if ((int) $owner['root_platform_id'] !== (int) $target['root_platform_id']) throw new HttpException('不能跨 1 级平台发布活动', 403, 403);
        if ((int) $target['actor_level'] < (int) $owner['actor_level']) throw new HttpException('不能向上级发布活动', 403, 403);
        if ((int) $owner['actor_level'] === 2 && (int) $target['actor_level'] > 2
            && (int) $target['platform_id'] !== (int) $owner['platform_id']) {
            throw new HttpException('2 级平台只能向自己的 admin 和 user 发布下级活动', 403, 403);
        }
        if ((int) $owner['actor_level'] === 3) {
            if ((int) $target['actor_level'] === 3 && (int) $target['platform_id'] !== (int) $owner['platform_id']) {
                throw new HttpException('admin 只能向同一授权平台下的平级 admin 发布活动', 403, 403);
            }
            if ((int) $target['actor_level'] === 4 && (int) $target['admin_id'] !== (int) $owner['admin_id']) {
                throw new HttpException('admin 只能向自己应用下的 user 发布活动', 403, 403);
            }
        }
    }

    private static function isVisible(array $activity, array $actor): bool
    {
        if ((int) $activity['root_platform_id'] !== (int) $actor['root_platform_id']) return false;
        if (self::canManage($activity, $actor)) return true;
        if (!self::withinOwnerReach($activity, $actor)) return false;
        return self::matchesAudience($activity, $actor, ['both', 'visibility']);
    }

    private static function isParticipationTarget(array $activity, array $actor): bool
    {
        if ((int) $activity['root_platform_id'] !== (int) $actor['root_platform_id']) return false;
        if (!self::withinOwnerReach($activity, $actor)) return false;
        return self::matchesAudience($activity, $actor, ['both', 'participation']);
    }

    private static function matchesAudience(array $activity, array $actor, array $scopes): bool
    {
        foreach (self::audienceTargets((int) $activity['id']) as $target) {
            if (!in_array($target['target_scope'], $scopes, true)) continue;
            if ($target['target_type'] === 'level' && (int) $target['target_level'] === (int) $actor['actor_level']) return true;
            if ($target['target_type'] === 'platform' && (int) $target['target_id'] === (int) $actor['platform_id']) return true;
            if ($target['target_type'] === 'admin' && $actor['admin_id'] !== null && (int) $target['target_id'] === (int) $actor['admin_id']) return true;
            if ($target['target_type'] === 'app' && $actor['app_id'] !== null && (int) $target['target_id'] === (int) $actor['app_id']) return true;
            if ($target['target_type'] === 'actor' && $target['actor_type'] === $actor['actor_type'] && (int) $target['target_id'] === (int) $actor['actor_id']) return true;
        }
        return false;
    }

    private static function audienceTargets(int $activityId): array
    {
        return Database::all(
            'SELECT id, target_scope, target_type, target_level, target_id, actor_type
             FROM hierarchy_activity_targets WHERE activity_id = ? ORDER BY id',
            [$activityId]
        );
    }

    private static function withinOwnerReach(array $activity, array $actor): bool
    {
        $ownerLevel = (int) $activity['owner_level'];
        if ($ownerLevel === 1) return true;
        if ($ownerLevel === 2) {
            return (int) $actor['actor_level'] === 2
                || (int) $actor['platform_id'] === (int) $activity['owner_platform_id'];
        }
        if ((int) $actor['actor_level'] === 3) return (int) $actor['platform_id'] === (int) $activity['owner_platform_id'];
        return (int) ($actor['admin_id'] ?? 0) === (int) $activity['owner_admin_id'];
    }

    private static function actionRow(array $actor, int $activityId, string $type): array
    {
        self::requireEnabled($actor);
        $row = self::row($activityId, true);
        if ($row['activity_type'] !== $type || !self::isVisible($row, $actor)) throw new HttpException('活动不存在或类型不匹配', 404, 404);
        if (!self::isOwner($row, $actor) && !self::isParticipationTarget($row, $actor)) {
            throw new HttpException('该活动仅对当前账号可见，发布者未授予领取或参与权限', 403, 403);
        }
        if ($row['status'] !== 'active') throw new HttpException('活动当前不可参与', 0, 409, ['status' => $row['status']]);
        if ($row['starts_at'] !== null && strtotime((string) $row['starts_at']) > time()) throw new HttpException('活动尚未开始', 0, 409);
        if ($row['ends_at'] !== null && strtotime((string) $row['ends_at']) <= time()) throw new HttpException('活动已经结束', 0, 410);
        return $row;
    }

    private static function assertEntryLimit(array $activity, array $actor, string $entryType): void
    {
        $count = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM hierarchy_activity_entries WHERE activity_id = ? AND actor_type = ? AND actor_id = ? AND entry_type = ?',
            [(int) $activity['id'], $actor['actor_type'], (int) $actor['actor_id'], $entryType]
        )['total'] ?? 0);
        if ($count >= (int) $activity['per_actor_limit']) throw new HttpException('当前账号参与次数已达到活动上限', 0, 409, ['limit' => (int) $activity['per_actor_limit']]);
    }

    private static function entry(int $activityId, array $actor, string $type, ?int $prizeId, int $reward): int
    {
        return Database::insert(
            'INSERT INTO hierarchy_activity_entries
             (activity_id, actor_type, actor_id, actor_level, platform_id, admin_id, app_id,
              entry_type, prize_id, reward_balance, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $activityId, $actor['actor_type'], (int) $actor['actor_id'], (int) $actor['actor_level'],
                (int) $actor['platform_id'], $actor['admin_id'], $actor['app_id'], $type, $prizeId, $reward,
            ]
        );
    }

    private static function consume(array $activity, int $reward, int $slots): void
    {
        $newBalance = max(0, (int) $activity['remaining_balance'] - $reward);
        $newSlots = max(0, (int) $activity['remaining_slots'] - $slots);
        $status = $newSlots === 0 ? 'completed' : 'active';
        Database::execute(
            'UPDATE hierarchy_activities SET remaining_balance = ?, remaining_slots = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [$newBalance, $newSlots, $status, (int) $activity['id']]
        );
    }

    private static function row(int $activityId, bool $lock = false): array
    {
        $row = Database::one('SELECT * FROM hierarchy_activities WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$activityId]);
        if ($row === null) throw new HttpException('活动不存在', 404, 404);
        return $row;
    }

    private static function isOwner(array $activity, array $actor): bool
    {
        return $activity['owner_type'] === $actor['actor_type'] && (int) $activity['owner_id'] === (int) $actor['actor_id'];
    }

    private static function canManage(array $activity, array $actor): bool
    {
        return self::isOwner($activity, $actor)
            || ((int) $actor['actor_level'] === 1 && (int) $actor['root_platform_id'] === (int) $activity['root_platform_id']);
    }

    private static function assertManage(array $activity, array $actor): void
    {
        if (!self::canManage($activity, $actor)) throw new HttpException('只有活动发布者或 1 级平台可以管理该活动', 403, 403);
    }

    private static function assertNotOwner(array $activity, array $actor): void
    {
        if (self::isOwner($activity, $actor)) throw new HttpException('活动发布者不能参与自己发布的活动', 0, 422);
    }

    private static function requirePublisher(array $actor): void
    {
        self::requireEnabled($actor);
        if ((int) $actor['actor_level'] > 3) throw new HttpException('user 只能参与活动，不能发布层级活动', 403, 403);
        if ($actor['actor_type'] === 'platform') {
            $platform = Database::one('SELECT * FROM platform_accounts WHERE id = ? AND deleted_at IS NULL', [(int) $actor['actor_id']]);
            if ($platform === null) throw new HttpException('平台账号不存在', 404, 404);
            PlatformService::requireCapability($platform, 'activities.manage');
        }
    }

    private static function requireEnabled(array $actor): void
    {
        if (!PlatformService::setting((int) $actor['platform_id'], 'hierarchical_activities_enabled', true)) {
            throw new HttpException('所属平台已关闭层级活动中心', 403, 403);
        }
        if ($actor['actor_type'] === 'user' && $actor['app_id'] !== null) {
            AppService::requireFeature((int) $actor['app_id'], 'hierarchical_activities');
        }
    }

    private static function requireType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) throw new HttpException('activity_type 仅支持 red_packet、lottery、bounty', 0, 422);
    }

    private static function dateValue($value, string $field): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $time = strtotime($value);
        if ($time === false) throw new HttpException($field . ' 时间格式错误', 0, 422);
        return date('Y-m-d H:i:s', $time);
    }

    private static function boolValue($value): bool
    {
        if (is_bool($value)) return $value;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
