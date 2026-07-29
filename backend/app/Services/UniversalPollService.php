<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Validator;

final class UniversalPollService
{
    public static function platformActor(array $platform): array
    {
        return LevelForumService::platformActor($platform);
    }

    public static function adminActor(array $admin): array
    {
        return LevelForumService::adminActor($admin);
    }

    public static function userActor(array $user): array
    {
        $platform = Database::one(
            'SELECT p.id, p.parent_id, p.level FROM admins a
             INNER JOIN platform_accounts p ON p.id = a.platform_id WHERE a.id = ?',
            [(int) $user['admin_id']]
        );
        if ($platform === null) throw new HttpException('用户所属平台不存在', 404, 404);
        $profile = Database::one('SELECT nickname FROM user_profiles WHERE user_id = ?', [(int) $user['id']]);
        return [
            'type' => 'user', 'id' => (int) $user['id'], 'level' => 4,
            'name' => (string) (($profile['nickname'] ?? '') ?: $user['account']),
            'root_platform_id' => (int) $platform['level'] === 1 ? (int) $platform['id'] : (int) $platform['parent_id'],
            'scope_platform_id' => (int) $platform['id'], 'app_id' => (int) $user['app_id'],
            'admin_id' => (int) $user['admin_id'],
        ];
    }

    public static function categories(Request $request, array $actor): array
    {
        [$where, $query] = self::visibility(
            $actor,
            'c',
            self::boolValue($request->input('manage', (string) $actor['type'] !== 'user'))
        );
        $where[] = 'c.status = 1';
        $items = Database::all(
            'SELECT c.*, (SELECT COUNT(*) FROM universal_poll_category_links l WHERE l.category_id = c.id) AS poll_count
             FROM poll_categories c WHERE ' . implode(' AND ', $where) . ' ORDER BY c.sort_order DESC, c.id DESC',
            $query
        );
        return $items;
    }

    public static function createCategory(array $actor, array $data): int
    {
        [$targetLevel, $scopeId, $appId] = self::target($actor, $data);
        $name = Validator::string($data['name'] ?? '', 'name', 1, 100);
        return Database::insert(
            'INSERT INTO poll_categories
             (root_platform_id, scope_platform_id, app_id, owner_type, owner_id, target_level,
              name, icon, color, sort_order, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [
                (int) $actor['root_platform_id'], $scopeId, $appId, (string) $actor['type'], (int) $actor['id'],
                $targetLevel, $name, mb_substr((string) ($data['icon'] ?? ''), 0, 500),
                mb_substr((string) ($data['color'] ?? ''), 0, 20), (int) ($data['sort_order'] ?? 0),
            ]
        );
    }

    public static function updateCategory(array $actor, int $id, array $data): array
    {
        $category = self::manageableCategory($actor, $id);
        Database::execute(
            'UPDATE poll_categories SET name = ?, icon = ?, color = ?, sort_order = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [
                Validator::string($data['name'] ?? $category['name'], 'name', 1, 100),
                mb_substr((string) ($data['icon'] ?? $category['icon']), 0, 500),
                mb_substr((string) ($data['color'] ?? $category['color']), 0, 20),
                (int) ($data['sort_order'] ?? $category['sort_order']),
                self::boolValue($data['enabled'] ?? ((int) $category['status'] === 1)) ? 1 : 0, $id,
            ]
        );
        return Database::one('SELECT * FROM poll_categories WHERE id = ?', [$id]) ?? [];
    }

    public static function deleteCategory(array $actor, int $id): void
    {
        self::manageableCategory($actor, $id);
        Database::execute('UPDATE poll_categories SET status = -1, updated_at = NOW() WHERE id = ?', [$id]);
    }

    public static function feed(Request $request, array $actor): array
    {
        [$where, $query] = self::visibility(
            $actor,
            'p',
            self::boolValue($request->input('manage', (string) $actor['type'] !== 'user'))
        );
        $where[] = 'p.deleted_at IS NULL';
        if (trim((string) $request->input('status', '')) !== '') { $where[] = 'p.status = ?'; $query[] = trim((string) $request->input('status')); }
        else $where[] = "p.status <> 'deleted'";
        if ((int) $request->input('category_id', 0) > 0) {
            $where[] = 'EXISTS(SELECT 1 FROM universal_poll_category_links pcl WHERE pcl.poll_id = p.id AND pcl.category_id = ?)';
            $query[] = (int) $request->input('category_id');
        }
        if (trim((string) $request->input('keyword', '')) !== '') {
            $where[] = '(p.title LIKE ? OR p.description LIKE ?)';
            $keyword = '%' . trim((string) $request->input('keyword')) . '%'; array_push($query, $keyword, $keyword);
        }
        $page = $request->page(); $limit = $request->limit(); $offset = ($page - 1) * $limit;
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM universal_polls p WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT p.*,
                    EXISTS(SELECT 1 FROM universal_poll_ballots b WHERE b.poll_id = p.id AND b.actor_type = ? AND b.actor_id = ?) AS voted,
                    (SELECT GROUP_CONCAT(c.name ORDER BY c.sort_order DESC, c.id SEPARATOR ', ')
                     FROM universal_poll_category_links l INNER JOIN poll_categories c ON c.id = l.category_id WHERE l.poll_id = p.id) AS category_names
             FROM universal_polls p WHERE {$whereSql} ORDER BY (p.status = 'active') DESC, p.id DESC LIMIT {$limit} OFFSET {$offset}",
            array_merge([(string) $actor['type'], (int) $actor['id']], $query)
        );
        $items = array_map([self::class, 'decorateMultipleChoice'], $items);
        return Pagination::data($items, $total, $page, $limit);
    }

    public static function create(array $actor, array $data): int
    {
        [$targetLevel, $scopeId, $appId] = self::target($actor, $data);
        $options = self::options($data['options'] ?? []);
        $multiple = self::boolValue(
            $data['multiple_choice'] ?? $data['multi_select'] ?? $data['allow_multiple'] ?? false
        );
        $min = max(1, (int) ($data['min_select'] ?? 1));
        $max = $multiple ? max($min, (int) ($data['max_select'] ?? count($options))) : 1;
        if ($max > count($options)) throw new HttpException('max_select 不能大于选项数量', 0, 422);
        if (!$multiple && $min !== 1) throw new HttpException('单选投票的 min_select 必须为 1', 0, 422);
        $visibility = trim((string) ($data['result_visibility'] ?? 'after_vote'));
        if (!in_array($visibility, ['always', 'after_vote', 'after_end', 'creator_only'], true)) throw new HttpException('result_visibility 不支持', 0, 422);
        $starts = self::dateValue($data['starts_at'] ?? null); $ends = self::dateValue($data['ends_at'] ?? null);
        if ($starts !== null && $ends !== null && strtotime($ends) <= strtotime($starts)) throw new HttpException('结束时间必须晚于开始时间', 0, 422);
        return Database::transaction(static function () use ($actor, $data, $targetLevel, $scopeId, $appId, $options, $multiple, $min, $max, $visibility, $starts, $ends): int {
            $id = Database::insert(
                'INSERT INTO universal_polls
                 (root_platform_id, scope_platform_id, app_id, creator_type, creator_id, creator_name, target_level,
                  title, description, multiple_choice, min_select, max_select, anonymous, allow_change,
                  result_visibility, status, starts_at, ends_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    (int) $actor['root_platform_id'], $scopeId, $appId, (string) $actor['type'], (int) $actor['id'],
                    (string) $actor['name'], $targetLevel, Validator::string($data['title'] ?? '', 'title', 1, 200),
                    (string) ($data['description'] ?? ''), $multiple ? 1 : 0, $min, $max,
                    self::boolValue($data['anonymous'] ?? false) ? 1 : 0,
                    self::boolValue($data['allow_change'] ?? false) ? 1 : 0, $visibility, 'active', $starts, $ends,
                ]
            );
            self::saveOptions($id, $options);
            self::saveCategoryLinks($actor, $id, $targetLevel, $appId, (array) ($data['category_ids'] ?? []));
            return $id;
        });
    }

    public static function show(array $actor, int $pollId): array
    {
        $poll = self::visiblePoll($actor, $pollId);
        $ballot = Database::one('SELECT id FROM universal_poll_ballots WHERE poll_id = ? AND actor_type = ? AND actor_id = ?', [$pollId, (string) $actor['type'], (int) $actor['id']]);
        $poll['voted'] = $ballot !== null;
        $canSeeResults = self::canSeeResults($actor, $poll, $ballot !== null);
        $poll['categories'] = Database::all(
            'SELECT c.* FROM poll_categories c INNER JOIN universal_poll_category_links l ON l.category_id = c.id WHERE l.poll_id = ? ORDER BY c.sort_order DESC, c.id', [$pollId]
        );
        $poll['options'] = Database::all(
            'SELECT id, option_text, image_url, ' . ($canSeeResults ? 'vote_count' : '0 AS vote_count') . ', sort_order FROM universal_poll_options WHERE poll_id = ? ORDER BY sort_order, id', [$pollId]
        );
        $poll['selected_option_ids'] = $ballot === null ? [] : array_map('intval', array_column(Database::all('SELECT option_id FROM universal_poll_choices WHERE ballot_id = ? ORDER BY id', [(int) $ballot['id']]), 'option_id'));
        $poll['results_visible'] = $canSeeResults;
        return self::decorateMultipleChoice($poll);
    }

    public static function vote(array $actor, int $pollId, array $optionIds): array
    {
        $optionIds = array_values(array_unique(array_map('intval', $optionIds)));
        return Database::transaction(static function () use ($actor, $pollId, $optionIds): array {
            $poll = Database::one('SELECT * FROM universal_polls WHERE id = ? AND status = ? AND deleted_at IS NULL FOR UPDATE', [$pollId, 'active']);
            if ($poll === null) throw new HttpException('投票不存在或已关闭', 404, 404);
            self::assertVisible($actor, $poll);
            if ($poll['starts_at'] !== null && strtotime((string) $poll['starts_at']) > time()) throw new HttpException('投票尚未开始', 0, 409);
            if ($poll['ends_at'] !== null && strtotime((string) $poll['ends_at']) <= time()) throw new HttpException('投票已经结束', 0, 410);
            $count = count($optionIds);
            if ($count < (int) $poll['min_select'] || $count > (int) $poll['max_select']) throw new HttpException('选择数量不符合投票规则', 0, 422, ['min' => (int) $poll['min_select'], 'max' => (int) $poll['max_select']]);
            if ($count === 0) throw new HttpException('至少选择一个选项', 0, 422);
            $placeholders = implode(',', array_fill(0, $count, '?'));
            $valid = Database::all("SELECT id FROM universal_poll_options WHERE poll_id = ? AND id IN ({$placeholders})", array_merge([$pollId], $optionIds));
            if (count($valid) !== $count) throw new HttpException('包含无效选项', 0, 422);
            $ballot = Database::one('SELECT * FROM universal_poll_ballots WHERE poll_id = ? AND actor_type = ? AND actor_id = ? FOR UPDATE', [$pollId, (string) $actor['type'], (int) $actor['id']]);
            if ($ballot !== null && (int) $poll['allow_change'] !== 1) throw new HttpException('该投票不允许改票', 0, 409);
            if ($ballot === null) {
                $ballotId = Database::insert('INSERT INTO universal_poll_ballots (poll_id, actor_type, actor_id, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())', [$pollId, (string) $actor['type'], (int) $actor['id']]);
                Database::execute('UPDATE universal_polls SET ballot_count = ballot_count + 1 WHERE id = ?', [$pollId]);
            } else {
                $ballotId = (int) $ballot['id'];
                $old = Database::all('SELECT option_id FROM universal_poll_choices WHERE ballot_id = ?', [$ballotId]);
                foreach ($old as $choice) Database::execute('UPDATE universal_poll_options SET vote_count = GREATEST(0, vote_count - 1) WHERE id = ?', [(int) $choice['option_id']]);
                Database::execute('DELETE FROM universal_poll_choices WHERE ballot_id = ?', [$ballotId]);
                Database::execute('UPDATE universal_poll_ballots SET updated_at = NOW() WHERE id = ?', [$ballotId]);
            }
            foreach ($optionIds as $optionId) {
                Database::execute('INSERT INTO universal_poll_choices (ballot_id, option_id, created_at) VALUES (?, ?, NOW())', [$ballotId, $optionId]);
                Database::execute('UPDATE universal_poll_options SET vote_count = vote_count + 1 WHERE id = ?', [$optionId]);
            }
            return ['ballot_id' => $ballotId, 'selected_option_ids' => $optionIds, 'changed' => $ballot !== null];
        });
    }

    public static function close(array $actor, int $pollId): void
    {
        $poll = self::manageablePoll($actor, $pollId);
        Database::execute("UPDATE universal_polls SET status = 'closed', updated_at = NOW() WHERE id = ?", [(int) $poll['id']]);
    }

    public static function delete(array $actor, int $pollId): void
    {
        $poll = self::manageablePoll($actor, $pollId);
        Database::execute("UPDATE universal_polls SET status = 'deleted', deleted_at = NOW(), updated_at = NOW() WHERE id = ?", [(int) $poll['id']]);
    }

    private static function visibility(array $actor, string $alias, bool $manage): array
    {
        $where = ["{$alias}.root_platform_id = ?"]; $query = [(int) $actor['root_platform_id']];
        if ($manage && (int) $actor['level'] === 1) return [$where, $query];
        if ($manage && (int) $actor['level'] === 2) {
            $where[] = "({$alias}.target_level = 2 OR {$alias}.scope_platform_id = ? OR ({$alias}.owner_type = 'platform' AND {$alias}.owner_id = ?))";
            array_push($query, (int) $actor['scope_platform_id'], (int) $actor['id']); return [$where, $query];
        }
        if ($manage && (int) $actor['level'] === 3) {
            $where[] = "(({$alias}.target_level = 3 AND ({$alias}.scope_platform_id IS NULL OR {$alias}.scope_platform_id = ?)) OR ({$alias}.owner_type = 'admin' AND {$alias}.owner_id = ?))";
            array_push($query, (int) $actor['scope_platform_id'], (int) $actor['id']); return [$where, $query];
        }
        $where[] = "({$alias}.target_level = 0 OR {$alias}.target_level = ?)"; $query[] = (int) $actor['level'];
        $where[] = "({$alias}.scope_platform_id IS NULL OR {$alias}.scope_platform_id = ?)"; $query[] = (int) $actor['scope_platform_id'];
        if ((int) $actor['level'] === 4) { $where[] = "({$alias}.app_id IS NULL OR {$alias}.app_id = ?)"; $query[] = (int) $actor['app_id']; }
        return [$where, $query];
    }

    private static function target(array $actor, array $data): array
    {
        $level = isset($data['target_level']) ? (int) $data['target_level'] : (int) $actor['level'];
        $scope = null; $appId = isset($data['app_id']) && $data['app_id'] !== '' ? (int) $data['app_id'] : null;
        if ((int) $actor['level'] === 1) {
            if (!in_array($level, [0, 1, 2, 3, 4], true)) throw new HttpException('target_level 不支持', 0, 422);
            $scope = isset($data['scope_platform_id']) && $data['scope_platform_id'] !== '' ? (int) $data['scope_platform_id'] : null;
            if ($scope !== null && $scope !== (int) $actor['id']) PlatformService::ownedOperator(self::platformRow($actor), $scope);
            if ($appId !== null) PlatformService::ownedApp(self::platformRow($actor), $appId);
        } elseif ((int) $actor['level'] === 2) {
            if (!in_array($level, [2, 3, 4], true)) throw new HttpException('2 级平台只能创建 2/3/4 级投票', 403, 403);
            $scope = $level === 2 ? null : (int) $actor['scope_platform_id'];
            if ($appId !== null) PlatformService::ownedApp(self::platformRow($actor), $appId);
        } elseif ((int) $actor['level'] === 3) {
            if (!in_array($level, [3, 4], true)) throw new HttpException('admin 只能创建 3 级或自己 App 的 4 级投票', 403, 403);
            $scope = (int) $actor['scope_platform_id'];
            if ($level === 4) {
                if ($appId === null || Database::one('SELECT id FROM apps WHERE id = ? AND admin_id = ? AND deleted_at IS NULL', [$appId, (int) $actor['id']]) === null) throw new HttpException('4 级投票必须选择自己的 App', 0, 422);
            } else $appId = null;
        } else {
            if (!AppService::setting((int) $actor['app_id'], 'user_poll_create_enabled', true)) throw new HttpException('管理员已关闭用户创建投票', 403, 403);
            $level = 4; $scope = (int) $actor['scope_platform_id']; $appId = (int) $actor['app_id'];
        }
        return [$level, $scope, $appId];
    }

    private static function options($items): array
    {
        if (!is_array($items) || count($items) < 2 || count($items) > 500) throw new HttpException('options 数量必须为 2-500', 0, 422);
        $result = [];
        foreach (array_values($items) as $index => $item) {
            if (is_string($item)) $item = ['option_text' => $item];
            if (!is_array($item)) throw new HttpException('投票选项格式错误', 0, 422);
            $result[] = ['option_text' => Validator::string($item['option_text'] ?? '', 'option_text', 1, 500), 'image_url' => mb_substr((string) ($item['image_url'] ?? ''), 0, 1000), 'sort_order' => (int) ($item['sort_order'] ?? $index)];
        }
        return $result;
    }

    private static function saveOptions(int $pollId, array $options): void
    {
        foreach ($options as $item) Database::execute('INSERT INTO universal_poll_options (poll_id, option_text, image_url, sort_order, created_at) VALUES (?, ?, ?, ?, NOW())', [$pollId, $item['option_text'], $item['image_url'], $item['sort_order']]);
    }

    private static function saveCategoryLinks(array $actor, int $pollId, int $targetLevel, ?int $appId, array $categoryIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds), static fn(int $id): bool => $id > 0)));
        if (count($ids) > 50) throw new HttpException('一个投票最多关联 50 个分类', 0, 422);
        foreach ($ids as $id) {
            $category = Database::one('SELECT * FROM poll_categories WHERE id = ? AND root_platform_id = ? AND status = 1', [$id, (int) $actor['root_platform_id']]);
            if ($category === null || !in_array((int) $category['target_level'], [0, $targetLevel], true)) throw new HttpException('投票分类不可用：' . $id, 0, 422);
            if ($category['app_id'] !== null && (int) $category['app_id'] !== (int) ($appId ?? 0)) throw new HttpException('投票分类不属于目标 App', 0, 422);
            Database::execute('INSERT INTO universal_poll_category_links (poll_id, category_id, created_at) VALUES (?, ?, NOW())', [$pollId, $id]);
        }
    }

    private static function visiblePoll(array $actor, int $pollId): array
    {
        $poll = Database::one("SELECT * FROM universal_polls WHERE id = ? AND status <> 'deleted' AND deleted_at IS NULL", [$pollId]);
        if ($poll === null) throw new HttpException('投票不存在', 404, 404);
        self::assertVisible($actor, $poll); return $poll;
    }

    private static function assertVisible(array $actor, array $poll): void
    {
        if ((int) $poll['root_platform_id'] !== (int) $actor['root_platform_id']) throw new HttpException('投票不在当前平台范围', 403, 403);
        $creator = (string) $poll['creator_type'] === (string) $actor['type'] && (int) $poll['creator_id'] === (int) $actor['id'];
        if (!$creator && !in_array((int) $poll['target_level'], [0, (int) $actor['level']], true)) throw new HttpException('投票不对当前级别开放', 403, 403);
        if (!$creator && $poll['scope_platform_id'] !== null && (int) $poll['scope_platform_id'] !== (int) $actor['scope_platform_id']) throw new HttpException('投票不在当前分支', 403, 403);
        if ((int) $actor['level'] === 4 && $poll['app_id'] !== null && (int) $poll['app_id'] !== (int) $actor['app_id']) throw new HttpException('投票不属于当前 App', 403, 403);
    }

    private static function manageablePoll(array $actor, int $pollId): array
    {
        $poll = Database::one('SELECT * FROM universal_polls WHERE id = ? AND deleted_at IS NULL', [$pollId]);
        if ($poll === null) throw new HttpException('投票不存在', 404, 404);
        $allowed = (string) $poll['creator_type'] === (string) $actor['type'] && (int) $poll['creator_id'] === (int) $actor['id'];
        if ((int) $actor['level'] === 1 && (int) $poll['root_platform_id'] === (int) $actor['root_platform_id']) $allowed = true;
        if ((int) $actor['level'] === 2 && (int) ($poll['scope_platform_id'] ?? 0) === (int) $actor['scope_platform_id']) $allowed = true;
        if ((int) $actor['level'] === 3 && $poll['app_id'] !== null && Database::one('SELECT id FROM apps WHERE id = ? AND admin_id = ?', [(int) $poll['app_id'], (int) $actor['id']])) $allowed = true;
        if (!$allowed) throw new HttpException('无权管理该投票', 403, 403); return $poll;
    }

    private static function manageableCategory(array $actor, int $id): array
    {
        $category = Database::one('SELECT * FROM poll_categories WHERE id = ? AND status <> -1', [$id]);
        if ($category === null) throw new HttpException('投票分类不存在', 404, 404);
        $allowed = (string) $category['owner_type'] === (string) $actor['type'] && (int) $category['owner_id'] === (int) $actor['id'];
        if ((int) $actor['level'] === 1 && (int) $category['root_platform_id'] === (int) $actor['root_platform_id']) $allowed = true;
        if ((int) $actor['level'] === 2 && (int) ($category['scope_platform_id'] ?? 0) === (int) $actor['scope_platform_id']) $allowed = true;
        if (!$allowed) throw new HttpException('无权管理该投票分类', 403, 403); return $category;
    }

    private static function canSeeResults(array $actor, array $poll, bool $voted): bool
    {
        $creator = (string) $poll['creator_type'] === (string) $actor['type'] && (int) $poll['creator_id'] === (int) $actor['id'];
        if ($creator || (int) $actor['level'] === 1) return true;
        return match ((string) $poll['result_visibility']) {
            'always' => true, 'after_vote' => $voted,
            'after_end' => (string) $poll['status'] === 'closed' || ($poll['ends_at'] !== null && strtotime((string) $poll['ends_at']) <= time()),
            default => false,
        };
    }

    private static function decorateMultipleChoice(array $poll): array
    {
        $multiple = self::boolValue($poll['multiple_choice'] ?? false);
        $poll['multiple_choice'] = $multiple;
        $poll['multi_select'] = $multiple;
        $poll['allow_multiple'] = $multiple;
        return $poll;
    }

    private static function platformRow(array $actor): array { return Database::one('SELECT * FROM platform_accounts WHERE id = ?', [(int) $actor['scope_platform_id']]) ?? $actor; }
    private static function boolValue($value): bool { if (is_bool($value)) return $value; return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true); }
    private static function dateValue($value): ?string { $value = trim((string) $value); if ($value === '') return null; $time = strtotime($value); if ($time === false) throw new HttpException('时间格式错误', 0, 422); return date('Y-m-d H:i:s', $time); }
}
