<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\PublicApi;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AppService;
use Yiyunying\Services\MessageMediaService;
use Yiyunying\Services\SubmissionInspectionService;

final class ResourceController
{
    public static function categories(Request $request): \Yiyunying\Core\ApiResponse
    {
        $app = self::app($request);
        $resourceType = SubmissionInspectionService::normalizeResourceType(
            (string) $request->input('resource_type', 'app_store')
        );
        SubmissionInspectionService::seedResourceCategories(
            (int) $app['admin_id'],
            (int) $app['id'],
            $resourceType
        );
        return Response::success(['items' => Database::all(
            'SELECT id, resource_type, name, icon, description
             FROM resource_categories
             WHERE app_id = ? AND resource_type = ? AND status = 1
             ORDER BY sort_order DESC, id',
            [(int) $app['id'], $resourceType]
        )]);
    }

    public static function resources(Request $request): \Yiyunying\Core\ApiResponse
    {
        $app = self::app($request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['r.app_id = ?', 'r.audit_status = ?', 'r.status = 1', 'r.deleted_at IS NULL'];
        $query = [(int) $app['id'], 'approved'];
        $resourceType = trim((string) $request->input('resource_type', ''));
        if ($resourceType !== '') {
            $where[] = 'r.resource_type = ?';
            $query[] = SubmissionInspectionService::normalizeResourceType($resourceType);
        }
        if ($request->input('category_id') !== null && $request->input('category_id') !== '') {
            $where[] = 'r.category_id = ?';
            $query[] = (int) $request->input('category_id');
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(r.title LIKE ? OR r.description LIKE ?)';
            $query[] = '%' . $keyword . '%';
            $query[] = '%' . $keyword . '%';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM resources r WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT r.id, r.admin_id, r.app_id, r.user_id, r.resource_type, r.category_id,
                    r.title, r.description, r.cover_url, r.cover_upload_id,
                    r.size_bytes, r.file_sha256, r.risk_level, r.risk_reason, r.metadata_json,
                    r.audit_status, r.price_integral, r.view_count, r.download_count,
                    r.created_at, c.name AS category_name,
                    (SELECT AVG(score) FROM resource_ratings rr WHERE rr.resource_id = r.id) AS rating
             FROM resources r INNER JOIN resource_categories c ON c.id = r.category_id
             WHERE {$whereSql} ORDER BY r.is_top DESC, r.is_recommended DESC, r.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = MessageMediaService::hydrate($items, 'resource', (int) $app['id']);
        foreach ($items as &$item) {
            $item['price_balance'] = (int) ($item['price_integral'] ?? 0);
            unset($item['price_integral']);
            $item = SubmissionInspectionService::present($item);
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $app = self::app($request);
        $resource = Database::one(
            'SELECT r.id, r.admin_id, r.app_id, r.user_id, r.resource_type, r.category_id,
                    r.title, r.description, r.cover_url, r.cover_upload_id,
                    r.size_bytes, r.file_sha256, r.risk_level, r.risk_reason, r.metadata_json,
                    r.audit_status, r.price_integral, r.view_count, r.download_count,
                    r.created_at, c.name AS category_name
             FROM resources r INNER JOIN resource_categories c ON c.id = r.category_id
             WHERE r.id = ? AND r.app_id = ? AND r.audit_status = ? AND r.status = 1 AND r.deleted_at IS NULL',
            [(int) $params['resource_id'], (int) $app['id'], 'approved']
        );
        if ($resource === null) {
            throw new HttpException('资源不存在', 404, 404);
        }
        Database::execute('UPDATE resources SET view_count = view_count + 1 WHERE id = ?', [(int) $resource['id']]);
        $resource['comments'] = Database::all(
            'SELECT c.id, c.content, c.created_at, p.nickname, p.avatar
             FROM resource_comments c LEFT JOIN user_profiles p ON p.user_id = c.user_id
             WHERE c.resource_id = ? AND c.status = 1 ORDER BY c.id ASC LIMIT 100',
            [(int) $resource['id']]
        );
        $resource = MessageMediaService::hydrate([$resource], 'resource', (int) $app['id'])[0];
        $resource['comments'] = MessageMediaService::hydrate(
            $resource['comments'],
            'resource_comment',
            (int) $app['id']
        );
        $resource['price_balance'] = (int) ($resource['price_integral'] ?? 0);
        unset($resource['price_integral']);
        $resource = SubmissionInspectionService::present($resource);
        return Response::success(['resource' => $resource]);
    }

    private static function app(Request $request): array
    {
        $appKey = trim((string) ($request->header('x-app-key') ?? $request->input('app_key', '')));
        $app = AppService::byKey($appKey);
        AppService::requireFeature((int) $app['id'], 'resources');
        SubmissionInspectionService::requireCatalogMigrationReady((int) $app['id']);
        $request->setAttribute('admin_id', (int) $app['admin_id']);
        $request->setAttribute('app_id', (int) $app['id']);
        return $app;
    }
}
