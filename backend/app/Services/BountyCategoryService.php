<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Validator;

final class BountyCategoryService
{
    public static function categories(int $adminId, int $appId, bool $activeOnly = true): array
    {
        $status = $activeOnly ? ' AND c.status = 1' : '';
        return Database::all(
            'SELECT c.*, COUNT(b.id) AS bounty_count
             FROM bounty_categories c
             LEFT JOIN bounties b ON b.category_id = c.id AND b.deleted_at IS NULL
             WHERE c.admin_id = ? AND c.app_id = ?' . $status . '
             GROUP BY c.id ORDER BY c.sort_order DESC, c.id ASC',
            [$adminId, $appId]
        );
    }

    public static function categoryId(int $adminId, int $appId, $value): ?int
    {
        $id = (int) $value;
        if ($id <= 0) return null;
        if (Database::one(
            'SELECT id FROM bounty_categories WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1',
            [$id, $adminId, $appId]
        ) === null) throw new HttpException('所选悬赏分类不存在或已停用', 0, 422);
        return $id;
    }

    public static function create(int $adminId, int $appId, array $data): int
    {
        $name = Validator::string($data['name'] ?? '', 'name', 1, 100);
        self::ensureUnique($appId, $name);
        return Database::insert(
            'INSERT INTO bounty_categories
             (admin_id, app_id, name, description, icon, sort_order, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [$adminId, $appId, $name, mb_substr(trim((string) ($data['description'] ?? '')), 0, 1000), mb_substr(trim((string) ($data['icon'] ?? '')), 0, 500), (int) ($data['sort_order'] ?? 0)]
        );
    }

    public static function update(int $adminId, int $appId, int $id, array $data): array
    {
        $row = Database::one('SELECT * FROM bounty_categories WHERE id = ? AND admin_id = ? AND app_id = ?', [$id, $adminId, $appId]);
        if ($row === null) throw new HttpException('悬赏分类不存在', 404, 404);
        $name = Validator::string($data['name'] ?? $row['name'], 'name', 1, 100);
        self::ensureUnique($appId, $name, $id);
        Database::execute(
            'UPDATE bounty_categories SET name = ?, description = ?, icon = ?, sort_order = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [$name, mb_substr(trim((string) ($data['description'] ?? $row['description'])), 0, 1000), mb_substr(trim((string) ($data['icon'] ?? $row['icon'])), 0, 500), (int) ($data['sort_order'] ?? $row['sort_order']), Validator::integer($data['status'] ?? $row['status'], 'status', 0, 1), $id]
        );
        return Database::one('SELECT * FROM bounty_categories WHERE id = ?', [$id]) ?? [];
    }

    public static function delete(int $adminId, int $appId, int $id): void
    {
        $changed = Database::execute('DELETE FROM bounty_categories WHERE id = ? AND admin_id = ? AND app_id = ?', [$id, $adminId, $appId]);
        if ($changed === 0) throw new HttpException('悬赏分类不存在', 404, 404);
    }

    public static function userRequests(Request $request, array $user): array
    {
        return self::requestList($request, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']);
    }

    public static function adminRequests(Request $request, int $adminId, int $appId): array
    {
        return self::requestList($request, $adminId, $appId, null);
    }

    public static function createRequest(array $user, array $data): int
    {
        $name = Validator::string($data['name'] ?? '', 'name', 1, 100);
        if (Database::one('SELECT id FROM bounty_categories WHERE app_id = ? AND name = ?', [(int) $user['app_id'], $name]) !== null) {
            throw new HttpException('该悬赏分类已经存在', 0, 409);
        }
        if (Database::one(
            "SELECT id FROM bounty_category_requests WHERE app_id = ? AND user_id = ? AND name = ? AND status = 'pending'",
            [(int) $user['app_id'], (int) $user['id'], $name]
        ) !== null) throw new HttpException('相同分类申请正在审核中', 0, 409);
        return Database::insert(
            'INSERT INTO bounty_category_requests
             (admin_id, app_id, user_id, name, description, reason, status, review_comment, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $name, mb_substr(trim((string) ($data['description'] ?? '')), 0, 1000), mb_substr(trim((string) ($data['reason'] ?? '')), 0, 1000), 'pending', '']
        );
    }

    public static function reviewRequest(int $adminId, int $appId, int $id, string $decision, string $comment): array
    {
        if (!in_array($decision, ['approve', 'reject'], true)) throw new HttpException('decision 仅支持 approve 或 reject', 0, 422);
        $request = Database::one('SELECT * FROM bounty_category_requests WHERE id = ? AND admin_id = ? AND app_id = ?', [$id, $adminId, $appId]);
        if ($request === null) throw new HttpException('悬赏分类申请不存在', 404, 404);
        if ((string) $request['status'] !== 'pending') throw new HttpException('该申请已经审核', 0, 409);
        $categoryId = null;
        Database::transaction(static function () use ($adminId, $appId, $id, $request, $decision, $comment, &$categoryId): void {
            if ($decision === 'approve') {
                $existing = Database::one('SELECT id FROM bounty_categories WHERE app_id = ? AND name = ?', [$appId, (string) $request['name']]);
                $categoryId = $existing === null ? Database::insert(
                    'INSERT INTO bounty_categories (admin_id, app_id, name, description, icon, sort_order, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 0, 1, NOW(), NOW())',
                    [$adminId, $appId, (string) $request['name'], (string) $request['description'], '']
                ) : (int) $existing['id'];
            }
            Database::execute(
                'UPDATE bounty_category_requests SET status = ?, reviewer_admin_id = ?, review_comment = ?, created_category_id = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?',
                [$decision === 'approve' ? 'approved' : 'rejected', $adminId, mb_substr(trim($comment), 0, 1000), $categoryId, $id]
            );
        });
        $user = NotificationService::user($adminId, $appId, (int) $request['user_id']);
        if ($user !== null) NotificationService::send(
            $user,
            'bounty_category_review',
            '悬赏分类申请审核结果',
            $decision === 'approve' ? '你申请的“' . (string) $request['name'] . '”已创建' : '你申请的“' . (string) $request['name'] . '”未通过审核',
            ['request_id' => $id, 'decision' => $decision, 'category_id' => $categoryId, 'review_comment' => $comment]
        );
        return ['request_id' => $id, 'status' => $decision === 'approve' ? 'approved' : 'rejected', 'category_id' => $categoryId];
    }

    private static function requestList(Request $request, int $adminId, int $appId, ?int $userId): array
    {
        $page = $request->page(); $limit = $request->limit(); $offset = ($page - 1) * $limit;
        $where = ['r.admin_id = ?', 'r.app_id = ?']; $query = [$adminId, $appId];
        if ($userId !== null) { $where[] = 'r.user_id = ?'; $query[] = $userId; }
        $status = trim((string) $request->input('status', ''));
        if ($status !== '') { $where[] = 'r.status = ?'; $query[] = $status; }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one('SELECT COUNT(*) total FROM bounty_category_requests r WHERE ' . $whereSql, $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT r.*, u.uid, u.account, up.nickname, up.avatar
             FROM bounty_category_requests r INNER JOIN users u ON u.id = r.user_id
             LEFT JOIN user_profiles up ON up.user_id = r.user_id
             WHERE {$whereSql} ORDER BY CASE r.status WHEN 'pending' THEN 0 ELSE 1 END, r.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        return Pagination::data($items, $total, $page, $limit);
    }

    private static function ensureUnique(int $appId, string $name, int $excludeId = 0): void
    {
        $sql = 'SELECT id FROM bounty_categories WHERE app_id = ? AND name = ?'; $query = [$appId, $name];
        if ($excludeId > 0) { $sql .= ' AND id <> ?'; $query[] = $excludeId; }
        if (Database::one($sql, $query) !== null) throw new HttpException('同名悬赏分类已经存在', 0, 409);
    }
}
