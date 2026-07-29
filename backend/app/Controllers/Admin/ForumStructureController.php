<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Validator;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\ForumModeratorService;
use Yiyunying\Services\ForumTaxonomyService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\NotificationService;
use Yiyunying\Services\RewardRuleService;

final class ForumStructureController
{
    public static function categories(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $plateId = (int) $request->input('plate_id', 0);
        $where = ['c.admin_id = ?', 'c.app_id = ?'];
        $query = [(int) $admin['id'], $appId];
        if ($plateId > 0) { $where[] = 'c.plate_id = ?'; $query[] = $plateId; }
        return Response::success(['items' => Database::all(
            'SELECT c.*, p.name AS plate_name, COUNT(DISTINCT fp.id) AS post_count, COUNT(DISTINCT t.id) AS tag_count
             FROM forum_categories c INNER JOIN forum_plates p ON p.id = c.plate_id
             LEFT JOIN forum_posts fp ON fp.category_id = c.id AND fp.deleted_at IS NULL
             LEFT JOIN forum_tags t ON t.category_id = c.id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY c.id ORDER BY c.sort_order DESC, c.id ASC',
            $query
        )]);
    }

    public static function createCategory(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $plateId = self::plateId((int) $admin['id'], $appId, $request->input('plate_id'));
        $name = Validator::string($request->input('name', ''), 'name', 1, 100);
        self::ensureUnique('forum_categories', $appId, $plateId, $name);
        $id = Database::insert(
            'INSERT INTO forum_categories
             (admin_id, app_id, plate_id, name, description, icon, sort_order, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [(int) $admin['id'], $appId, $plateId, $name, mb_substr((string) $request->input('description', ''), 0, 500), mb_substr((string) $request->input('icon', ''), 0, 500), (int) $request->input('sort_order', 0)]
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'forum_category', 'create', $id);
        return Response::success(['category_id' => $id], '二级分类创建成功', 201);
    }

    public static function updateCategory(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['category_id'];
        $row = self::row('forum_categories', $id, (int) $admin['id'], $appId, '二级分类不存在');
        $plateId = array_key_exists('plate_id', $request->all()) ? self::plateId((int) $admin['id'], $appId, $request->input('plate_id')) : (int) $row['plate_id'];
        $name = Validator::string($request->input('name', $row['name']), 'name', 1, 100);
        self::ensureUnique('forum_categories', $appId, $plateId, $name, $id);
        Database::execute(
            'UPDATE forum_categories SET plate_id = ?, name = ?, description = ?, icon = ?, sort_order = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [$plateId, $name, mb_substr((string) $request->input('description', $row['description']), 0, 500), mb_substr((string) $request->input('icon', $row['icon']), 0, 500), (int) $request->input('sort_order', $row['sort_order']), Validator::integer($request->input('status', $row['status']), 'status', 0, 1), $id]
        );
        return Response::success(['category_id' => $id], '二级分类修改成功');
    }

    public static function deleteCategory(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['category_id'];
        $changed = Database::execute('DELETE FROM forum_categories WHERE id = ? AND admin_id = ? AND app_id = ?', [$id, (int) $admin['id'], $appId]);
        if ($changed === 0) throw new HttpException('二级分类不存在', 404, 404);
        return Response::success([], '二级分类已删除，原帖保留并转为未分类');
    }

    public static function tags(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $where = ['t.admin_id = ?', 't.app_id = ?'];
        $query = [(int) $admin['id'], $appId];
        foreach (['plate_id', 'category_id'] as $field) {
            if ((int) $request->input($field, 0) > 0) { $where[] = 't.' . $field . ' = ?'; $query[] = (int) $request->input($field); }
        }
        $items = Database::all(
            'SELECT t.*, p.name AS plate_name, c.name AS category_name
             FROM forum_tags t INNER JOIN forum_plates p ON p.id = t.plate_id
             LEFT JOIN forum_categories c ON c.id = t.category_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY t.sort_order DESC, t.id ASC',
            $query
        );
        foreach ($items as &$item) { $item['aliases'] = json_decode((string) ($item['aliases_json'] ?? '[]'), true) ?: []; unset($item['aliases_json']); }
        return Response::success(['items' => $items]);
    }

    public static function createTag(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $plateId = self::plateId((int) $admin['id'], $appId, $request->input('plate_id'));
        $categoryId = ForumTaxonomyService::categoryId((int) $admin['id'], $appId, $plateId, $request->input('category_id'));
        $name = Validator::string($request->input('name', ''), 'name', 1, 80);
        self::ensureUnique('forum_tags', $appId, $plateId, $name);
        $id = Database::insert(
            'INSERT INTO forum_tags
             (admin_id, app_id, plate_id, category_id, name, aliases_json, description, sort_order, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [(int) $admin['id'], $appId, $plateId, $categoryId, $name, ForumTaxonomyService::encodeAliases($request->input('aliases', [])), mb_substr((string) $request->input('description', ''), 0, 500), (int) $request->input('sort_order', 0)]
        );
        return Response::success(['tag_id' => $id], '标签创建成功', 201);
    }

    public static function updateTag(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['tag_id'];
        $row = self::row('forum_tags', $id, (int) $admin['id'], $appId, '标签不存在');
        $plateId = array_key_exists('plate_id', $request->all()) ? self::plateId((int) $admin['id'], $appId, $request->input('plate_id')) : (int) $row['plate_id'];
        $categoryId = array_key_exists('category_id', $request->all()) ? ForumTaxonomyService::categoryId((int) $admin['id'], $appId, $plateId, $request->input('category_id')) : ($row['category_id'] === null ? null : (int) $row['category_id']);
        $name = Validator::string($request->input('name', $row['name']), 'name', 1, 80);
        self::ensureUnique('forum_tags', $appId, $plateId, $name, $id);
        Database::execute(
            'UPDATE forum_tags SET plate_id = ?, category_id = ?, name = ?, aliases_json = ?, description = ?, sort_order = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [$plateId, $categoryId, $name, array_key_exists('aliases', $request->all()) ? ForumTaxonomyService::encodeAliases($request->input('aliases')) : (string) $row['aliases_json'], mb_substr((string) $request->input('description', $row['description']), 0, 500), (int) $request->input('sort_order', $row['sort_order']), Validator::integer($request->input('status', $row['status']), 'status', 0, 1), $id]
        );
        return Response::success(['tag_id' => $id], '标签修改成功');
    }

    public static function deleteTag(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $changed = Database::execute('DELETE FROM forum_tags WHERE id = ? AND admin_id = ? AND app_id = ?', [(int) $params['tag_id'], (int) $admin['id'], $appId]);
        if ($changed === 0) throw new HttpException('标签不存在', 404, 404);
        return Response::success([], '标签已删除');
    }

    public static function moderators(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $where = ['m.admin_id = ?', 'm.app_id = ?'];
        $query = [(int) $admin['id'], $appId];
        $plateId = (int) $request->input('plate_id', 0);
        if ($plateId > 0) { $where[] = 'm.plate_id = ?'; $query[] = $plateId; }
        if ($request->input('status') !== null) { $where[] = 'm.status = ?'; $query[] = Validator::integer($request->input('status'), 'status', 0, 1); }
        $items = Database::all(
            'SELECT m.*, fp.name AS plate_name, u.uid, u.account, up.nickname, up.avatar
             FROM forum_moderators m
             INNER JOIN forum_plates fp ON fp.id = m.plate_id
             INNER JOIN users u ON u.id = m.user_id
             LEFT JOIN user_profiles up ON up.user_id = m.user_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY m.status DESC, m.id DESC',
            $query
        );
        foreach ($items as &$item) {
            $item['permissions'] = ForumModeratorService::normalizePermissions(
                json_decode((string) ($item['permissions_json'] ?? '{}'), true) ?: []
            );
            unset($item['permissions_json']);
        }
        return Response::success(['items' => $items, 'permission_defaults' => ForumModeratorService::permissions()]);
    }

    public static function updateModerator(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['moderator_id'];
        $row = Database::one(
            'SELECT * FROM forum_moderators WHERE id = ? AND admin_id = ? AND app_id = ?',
            [$id, (int) $admin['id'], $appId]
        );
        if ($row === null) throw new HttpException('版主记录不存在', 404, 404);
        $permissions = array_key_exists('permissions', $request->all())
            ? ForumModeratorService::normalizePermissions((array) $request->input('permissions', []))
            : ForumModeratorService::normalizePermissions(json_decode((string) ($row['permissions_json'] ?? '{}'), true) ?: []);
        $status = Validator::integer($request->input('status', $row['status']), 'status', 0, 1);
        Database::execute(
            'UPDATE forum_moderators SET permissions_json = ?, status = ?, granted_by_admin_id = ?, updated_at = NOW() WHERE id = ?',
            [json_encode($permissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, (int) $admin['id'], $id]
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'forum_moderator', $status === 1 ? 'update' : 'revoke', $id);
        return Response::success(['moderator_id' => $id, 'status' => $status, 'permissions' => $permissions], $status === 1 ? '版主权限已更新' : '版主权限已撤销');
    }

    public static function requests(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $page = $request->page(); $limit = $request->limit(); $offset = ($page - 1) * $limit;
        $where = ['r.admin_id = ?', 'r.app_id = ?']; $query = [(int) $admin['id'], $appId];
        foreach (['status', 'request_type'] as $field) {
            $value = trim((string) $request->input($field, ''));
            if ($value !== '') { $where[] = 'r.' . $field . ' = ?'; $query[] = $value; }
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one('SELECT COUNT(*) total FROM forum_structure_requests r WHERE ' . $whereSql, $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT r.*, u.uid, u.account, up.nickname, p.name AS plate_name, c.name AS category_name
             FROM forum_structure_requests r INNER JOIN users u ON u.id = r.user_id
             LEFT JOIN user_profiles up ON up.user_id = r.user_id
             LEFT JOIN forum_plates p ON p.id = r.plate_id LEFT JOIN forum_categories c ON c.id = r.category_id
             WHERE {$whereSql} ORDER BY CASE r.status WHEN 'pending' THEN 0 ELSE 1 END, r.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        foreach ($items as &$item) { $item['aliases'] = json_decode((string) ($item['aliases_json'] ?? '[]'), true) ?: []; unset($item['aliases_json']); }
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function reviewRequest(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['request_id'];
        $decision = trim((string) $request->input('decision', ''));
        if (!in_array($decision, ['approve', 'reject'], true)) throw new HttpException('decision 仅支持 approve 或 reject', 0, 422);
        $row = Database::one('SELECT * FROM forum_structure_requests WHERE id = ? AND admin_id = ? AND app_id = ?', [$id, (int) $admin['id'], $appId]);
        if ($row === null) throw new HttpException('申请不存在', 404, 404);
        if ((string) $row['status'] !== 'pending') throw new HttpException('该申请已经审核，不能重复处理', 0, 409);
        $createdId = null;
        Database::transaction(static function () use ($request, $admin, $appId, $id, $row, $decision, &$createdId): void {
            if ($decision === 'approve') $createdId = self::approve($admin, $appId, $row);
            Database::execute(
                'UPDATE forum_structure_requests SET status = ?, reviewer_admin_id = ?, review_comment = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?',
                [$decision === 'approve' ? 'approved' : 'rejected', (int) $admin['id'], mb_substr(trim((string) $request->input('review_comment', '')), 0, 1000), $id]
            );
        });
        $user = NotificationService::user((int) $admin['id'], $appId, (int) $row['user_id']);
        if ($user !== null) NotificationService::send(
            $user, 'forum_structure_review', (string) $row['request_type'] === 'moderator' ? '论坛版主申请审核结果' : '论坛分类申请审核结果',
            $decision === 'approve' ? '你申请的“' . (string) $row['name'] . '”已创建' : '你申请的“' . (string) $row['name'] . '”未通过审核',
            ['request_id' => $id, 'decision' => $decision, 'created_id' => $createdId]
        );
        $rewardResult = null;
        if (
            $user !== null
            && $decision === 'approve'
            && in_array((string) $row['request_type'], ['plate', 'category'], true)
        ) {
            $rewardResult = RewardRuleService::trigger(
                $user,
                'forum_plate_create',
                'forum_structure_request',
                $id,
                [
                    'approved' => true,
                    'status' => 'approved',
                    'content' => trim((string) $row['name'] . "\n" . (string) $row['description']),
                    'request_type' => (string) $row['request_type'],
                    'created_id' => $createdId,
                ],
                'admin',
                (int) $admin['id']
            );
        }
        return Response::success([
            'request_id' => $id,
            'created_id' => $createdId,
            'status' => $decision === 'approve' ? 'approved' : 'rejected',
            'reward_result' => $rewardResult,
        ], '审核完成');
    }

    private static function approve(array $admin, int $appId, array $row): int
    {
        $type = (string) $row['request_type']; $name = (string) $row['name'];
        if ($type === 'plate') {
            $existing = Database::one('SELECT id FROM forum_plates WHERE app_id = ? AND name = ?', [$appId, $name]);
            if ($existing !== null) return (int) $existing['id'];
            return Database::insert('INSERT INTO forum_plates (admin_id, app_id, name, icon, description, sort_order, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, 1, NOW(), NOW())', [(int) $admin['id'], $appId, $name, '', (string) $row['description']]);
        }
        $plateId = self::plateId((int) $admin['id'], $appId, $row['plate_id']);
        if ($type === 'moderator') {
            return ForumModeratorService::grant((int) $admin['id'], $appId, $plateId, (int) $row['user_id']);
        }
        if ($type === 'category') {
            $existing = Database::one('SELECT id FROM forum_categories WHERE app_id = ? AND plate_id = ? AND name = ?', [$appId, $plateId, $name]);
            if ($existing !== null) return (int) $existing['id'];
            return Database::insert('INSERT INTO forum_categories (admin_id, app_id, plate_id, name, description, icon, sort_order, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, 1, NOW(), NOW())', [(int) $admin['id'], $appId, $plateId, $name, (string) $row['description'], '']);
        }
        $categoryId = ForumTaxonomyService::categoryId((int) $admin['id'], $appId, $plateId, $row['category_id']);
        $existing = Database::one('SELECT id FROM forum_tags WHERE app_id = ? AND plate_id = ? AND name = ?', [$appId, $plateId, $name]);
        if ($existing !== null) return (int) $existing['id'];
        return Database::insert('INSERT INTO forum_tags (admin_id, app_id, plate_id, category_id, name, aliases_json, description, sort_order, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1, NOW(), NOW())', [(int) $admin['id'], $appId, $plateId, $categoryId, $name, (string) $row['aliases_json'], (string) $row['description']]);
    }

    private static function plateId(int $adminId, int $appId, $value): int
    {
        $id = Validator::integer($value, 'plate_id', 1, PHP_INT_MAX);
        if (Database::one('SELECT id FROM forum_plates WHERE id = ? AND admin_id = ? AND app_id = ?', [$id, $adminId, $appId]) === null) throw new HttpException('论坛板块不存在', 404, 404);
        return $id;
    }

    private static function ensureUnique(string $table, int $appId, int $plateId, string $name, int $excludeId = 0): void
    {
        $sql = "SELECT id FROM {$table} WHERE app_id = ? AND plate_id = ? AND name = ?";
        $query = [$appId, $plateId, $name];
        if ($excludeId > 0) { $sql .= ' AND id <> ?'; $query[] = $excludeId; }
        if (Database::one($sql, $query) !== null) throw new HttpException('同名内容已经存在', 0, 409);
    }

    private static function row(string $table, int $id, int $adminId, int $appId, string $message): array
    {
        $row = Database::one("SELECT * FROM {$table} WHERE id = ? AND admin_id = ? AND app_id = ?", [$id, $adminId, $appId]);
        if ($row === null) throw new HttpException($message, 404, 404);
        return $row;
    }

    private static function context(Request $request, array $params): array
    {
        $admin = AuthService::admin($request); $appId = (int) $params['app_id']; AppService::owned((int) $admin['id'], $appId); return [$admin, $appId];
    }
}
