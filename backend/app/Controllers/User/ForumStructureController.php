<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\User;

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

final class ForumStructureController
{
    public static function categories(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $plateId = Validator::integer($request->input('plate_id', 0), 'plate_id', 1, PHP_INT_MAX);
        $keyword = trim((string) $request->input('keyword', ''));
        $where = ['c.app_id = ?', 'c.plate_id = ?', 'c.status = 1'];
        $query = [(int) $user['app_id'], $plateId];
        if ($keyword !== '') {
            $where[] = '(c.name LIKE ? OR c.description LIKE ?)';
            array_push($query, '%' . $keyword . '%', '%' . $keyword . '%');
        }
        return Response::success(['items' => Database::all(
            'SELECT c.id, c.plate_id, c.name, c.description, c.icon, c.sort_order,
                    COUNT(DISTINCT p.id) AS post_count, COUNT(DISTINCT t.id) AS tag_count
             FROM forum_categories c
             LEFT JOIN forum_posts p ON p.category_id = c.id AND p.status = 1 AND p.deleted_at IS NULL
             LEFT JOIN forum_tags t ON t.category_id = c.id AND t.status = 1
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY c.id ORDER BY c.sort_order DESC, c.id ASC',
            $query
        )]);
    }

    public static function tags(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $plateId = Validator::integer($request->input('plate_id', 0), 'plate_id', 1, PHP_INT_MAX);
        $categoryId = (int) $request->input('category_id', 0);
        $keyword = trim((string) $request->input('keyword', ''));
        $where = ['t.app_id = ?', 't.plate_id = ?', 't.status = 1'];
        $query = [(int) $user['app_id'], $plateId];
        if ($categoryId > 0) {
            $where[] = '(t.category_id IS NULL OR t.category_id = ?)';
            $query[] = $categoryId;
        }
        if ($keyword !== '') {
            $where[] = '(t.name LIKE ? OR t.aliases_json LIKE ? OR t.description LIKE ?)';
            array_push($query, '%' . $keyword . '%', '%' . $keyword . '%', '%' . $keyword . '%');
        }
        $items = Database::all(
            'SELECT t.id, t.plate_id, t.category_id, t.name, t.aliases_json, t.description, t.sort_order,
                    c.name AS category_name
             FROM forum_tags t LEFT JOIN forum_categories c ON c.id = t.category_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY t.sort_order DESC, t.id ASC',
            $query
        );
        foreach ($items as &$item) {
            $item['aliases'] = json_decode((string) ($item['aliases_json'] ?? '[]'), true) ?: [];
            unset($item['aliases_json']);
        }
        return Response::success(['items' => $items]);
    }

    public static function requests(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $query = [(int) $user['app_id'], (int) $user['id']];
        $where = ['r.app_id = ?', 'r.user_id = ?'];
        $status = trim((string) $request->input('status', ''));
        if ($status !== '') {
            $where[] = 'r.status = ?';
            $query[] = $status;
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one('SELECT COUNT(*) total FROM forum_structure_requests r WHERE ' . $whereSql, $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT r.*, p.name AS plate_name, c.name AS category_name
             FROM forum_structure_requests r
             LEFT JOIN forum_plates p ON p.id = r.plate_id
             LEFT JOIN forum_categories c ON c.id = r.category_id
             WHERE {$whereSql} ORDER BY r.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        foreach ($items as &$item) {
            $item['aliases'] = json_decode((string) ($item['aliases_json'] ?? '[]'), true) ?: [];
            unset($item['aliases_json']);
        }
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function createRequest(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $type = trim((string) $request->input('request_type', ''));
        if (!in_array($type, ['plate', 'category', 'tag', 'moderator'], true)) {
            throw new HttpException('request_type 仅支持 plate、category、tag、moderator', 0, 422);
        }
        $name = $type === 'moderator'
            ? mb_substr(trim((string) $request->input('name', '版主申请')) ?: '版主申请', 0, 100)
            : Validator::string($request->input('name', ''), 'name', 1, 100);
        $plateId = (int) $request->input('plate_id', 0);
        $categoryId = (int) $request->input('category_id', 0);
        if ($type !== 'plate') {
            $plate = Database::one('SELECT id FROM forum_plates WHERE id = ? AND app_id = ? AND status = 1', [$plateId, (int) $user['app_id']]);
            if ($plate === null) throw new HttpException('申请二级分类、标签或版主时必须选择有效板块', 0, 422);
        }
        if ($type === 'moderator' && ForumModeratorService::canManage($user, $plateId)) {
            throw new HttpException('你已经是该板块版主，无需重复申请', 0, 409);
        }
        if ($type === 'tag' && $categoryId > 0) {
            $category = Database::one('SELECT id FROM forum_categories WHERE id = ? AND app_id = ? AND plate_id = ? AND status = 1', [$categoryId, (int) $user['app_id'], $plateId]);
            if ($category === null) throw new HttpException('所选二级分类无效', 0, 422);
        }
        $duplicate = Database::one(
            'SELECT id FROM forum_structure_requests
             WHERE app_id = ? AND user_id = ? AND request_type = ? AND name = ? AND status = ?
               AND COALESCE(plate_id, 0) = ? AND COALESCE(category_id, 0) = ?',
            [(int) $user['app_id'], (int) $user['id'], $type, $name, 'pending', $plateId, $categoryId]
        );
        if ($duplicate !== null) throw new HttpException('相同申请正在审核中，请勿重复提交', 0, 409);
        $id = Database::insert(
            'INSERT INTO forum_structure_requests
             (admin_id, app_id, user_id, request_type, plate_id, category_id, name, aliases_json,
              description, reason, status, review_comment, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $type,
                $plateId > 0 ? $plateId : null, $categoryId > 0 ? $categoryId : null, $name,
                ForumTaxonomyService::encodeAliases($request->input('aliases', [])),
                mb_substr(trim((string) $request->input('description', '')), 0, 1000),
                mb_substr(trim((string) $request->input('reason', '')), 0, 1000), 'pending', '',
            ]
        );
        return Response::success(['request_id' => $id, 'status' => 'pending'], '申请已提交，等待管理员审核', 201);
    }

    private static function user(Request $request): array
    {
        $user = AuthService::user($request);
        AppService::requireFeature((int) $user['app_id'], 'forum');
        return $user;
    }
}
