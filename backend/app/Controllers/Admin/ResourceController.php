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
use Yiyunying\Services\LogService;
use Yiyunying\Services\MessageMediaService;
use Yiyunying\Services\SubmissionInspectionService;

final class ResourceController
{
    public static function categories(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $resourceType = SubmissionInspectionService::normalizeResourceType(
            (string) $request->input('resource_type', 'app_store')
        );
        SubmissionInspectionService::seedResourceCategories((int) $admin['id'], $appId, $resourceType);
        return Response::success(['items' => Database::all(
            'SELECT * FROM resource_categories
             WHERE admin_id = ? AND app_id = ? AND resource_type = ?
             ORDER BY sort_order DESC, id ASC',
            [(int) $admin['id'], $appId, $resourceType]
        )]);
    }

    public static function createCategory(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $name = Validator::string($request->input('name', ''), 'name', 1, 100);
        $resourceType = SubmissionInspectionService::normalizeResourceType(
            (string) $request->input('resource_type', 'app_store')
        );
        $id = Database::insert(
            'INSERT INTO resource_categories
             (admin_id, app_id, resource_type, name, icon, description, sort_order, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [
                (int) $admin['id'], $appId, $resourceType, $name,
                mb_substr((string) $request->input('icon', ''), 0, 500),
                mb_substr((string) $request->input('description', ''), 0, 500), (int) $request->input('sort_order', 0),
            ]
        );
        return Response::success([
            'category_id' => $id,
            'resource_type' => $resourceType,
            'resource_type_label' => $resourceType === 'source_market' ? '源码商城' : '应用商店',
        ], '资源分类创建成功', 201);
    }

    public static function updateCategory(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['category_id'];
        $row = Database::one('SELECT * FROM resource_categories WHERE id = ? AND admin_id = ? AND app_id = ?', [$id, (int) $admin['id'], $appId]);
        if ($row === null) {
            throw new HttpException('资源分类不存在', 404, 404);
        }
        $resourceType = SubmissionInspectionService::normalizeResourceType(
            (string) $request->input('resource_type', $row['resource_type'] ?? 'app_store')
        );
        Database::execute(
            'UPDATE resource_categories
             SET resource_type = ?, name = ?, icon = ?, description = ?, sort_order = ?, status = ?, updated_at = NOW()
             WHERE id = ?',
            [
                $resourceType,
                mb_substr((string) $request->input('name', $row['name']), 0, 100),
                mb_substr((string) $request->input('icon', $row['icon']), 0, 500),
                mb_substr((string) $request->input('description', $row['description']), 0, 500),
                (int) $request->input('sort_order', $row['sort_order']),
                (int) $request->input('status', $row['status']), $id,
            ]
        );
        return Response::success([], '资源分类修改成功');
    }

    public static function deleteCategory(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['category_id'];
        if (Database::one('SELECT id FROM resources WHERE category_id = ? AND deleted_at IS NULL LIMIT 1', [$id])) {
            throw new HttpException('分类下仍有资源，不能删除', 0, 422);
        }
        Database::execute('DELETE FROM resource_categories WHERE id = ? AND admin_id = ? AND app_id = ?', [$id, (int) $admin['id'], $appId]);
        return Response::success([], '资源分类已删除');
    }

    public static function resources(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['r.admin_id = ?', 'r.app_id = ?', 'r.deleted_at IS NULL'];
        $query = [(int) $admin['id'], $appId];
        foreach (['category_id', 'status'] as $field) {
            if ($request->input($field) !== null && $request->input($field) !== '') {
                $where[] = "r.{$field} = ?";
                $query[] = (int) $request->input($field);
            }
        }
        if (trim((string) $request->input('audit_status', '')) !== '') {
            $where[] = 'r.audit_status = ?';
            $query[] = trim((string) $request->input('audit_status'));
        }
        if (trim((string) $request->input('resource_type', '')) !== '') {
            $where[] = 'r.resource_type = ?';
            $query[] = SubmissionInspectionService::normalizeResourceType(
                (string) $request->input('resource_type')
            );
        }
        $riskLevel = trim((string) $request->input('risk_level', ''));
        if ($riskLevel !== '') {
            if (!in_array($riskLevel, ['low', 'review', 'high'], true)) {
                throw new HttpException('风险等级格式错误', 0, 422);
            }
            $where[] = 'r.risk_level = ?';
            $query[] = $riskLevel;
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(r.title LIKE ? OR r.description LIKE ? OR u.account LIKE ? OR p.nickname LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($query, $like, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total
             FROM resources r
             LEFT JOIN users u ON u.id = r.user_id
             LEFT JOIN user_profiles p ON p.user_id = r.user_id
             WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT r.*, c.name AS category_name, u.account, p.nickname,
                    (SELECT AVG(score) FROM resource_ratings rr WHERE rr.resource_id = r.id) AS rating
             FROM resources r LEFT JOIN resource_categories c ON c.id = r.category_id
             LEFT JOIN users u ON u.id = r.user_id LEFT JOIN user_profiles p ON p.user_id = r.user_id
             WHERE {$whereSql} ORDER BY r.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = MessageMediaService::hydrate($items, 'resource', $appId);
        $items = array_map(static function (array $item): array {
            $item['price_balance'] = max(0, (int) ($item['price_integral'] ?? 0));
            return SubmissionInspectionService::present($item);
        }, $items);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function audit(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['resource_id'];
        $status = trim((string) $request->input('audit_status', ''));
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            throw new HttpException('audit_status 格式错误', 0, 422);
        }
        $row = Database::one(
            'SELECT * FROM resources
             WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
            [$id, (int) $admin['id'], $appId]
        );
        if ($row === null) {
            throw new HttpException('资源不存在', 404, 404);
        }
        $overrideRisk = $request->input('override_risk') === null
            ? false
            : Validator::boolean($request->input('override_risk'), 'override_risk');
        if ($status === 'approved' && (string) ($row['risk_level'] ?? 'review') === 'high' && !$overrideRisk) {
            throw new HttpException('该文件被标记为高风险，需确认“覆盖风险”后才能通过审核', 0, 422);
        }
        $reason = mb_substr((string) $request->input('reason', ''), 0, 500);
        $publicStatus = $status === 'approved' ? 1 : 0;
        Database::execute(
            'UPDATE resources
             SET audit_status = ?, audit_reason = ?, status = ?, updated_at = NOW()
             WHERE id = ?',
            [$status, $reason, $publicStatus, $id]
        );
        $updated = Database::one('SELECT * FROM resources WHERE id = ?', [$id]) ?? $row;
        LogService::adminOperation(
            $request,
            (int) $admin['id'],
            $appId,
            'resource',
            'audit',
            $id,
            $row,
            [
                'audit_status' => $status,
                'audit_reason' => $reason,
                'risk_level' => (string) ($row['risk_level'] ?? 'review'),
                'override_risk' => $overrideRisk,
                'public_status' => $publicStatus,
            ]
        );
        return Response::success(
            ['item' => SubmissionInspectionService::present($updated)],
            $status === 'approved' ? '资源审核通过并已上架' : '资源已下架，审核状态已更新'
        );
    }

    public static function updateResource(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['resource_id'];
        $row = Database::one('SELECT * FROM resources WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL', [$id, (int) $admin['id'], $appId]);
        if ($row === null) {
            throw new HttpException('资源不存在', 404, 404);
        }
        $data = $request->all();
        $resourceType = SubmissionInspectionService::normalizeResourceType(
            (string) $request->input('resource_type', $row['resource_type'] ?? 'app_store')
        );
        if (!array_key_exists('category_id', $data)) {
            $data['category_id'] = (int) ($row['category_id'] ?? 0);
        }
        $category = SubmissionInspectionService::resolveResourceCategory(
            (int) $admin['id'],
            $appId,
            $resourceType,
            $data
        );
        $payload = null;
        if ($request->input('attachments') !== null) {
            $media = $data;
            $media['content'] = (string) $request->input('description', $row['description']);
            $payload = MessageMediaService::adminPayload($admin, $appId, $media);
        }

        $sourceChanged = self::hasMeaningfulInput($data, [
            'source_upload_id', 'upload_id', 'file_upload_id', 'apk_upload_id', 'download_url', 'apk_url',
        ]);
        $coverChanged = self::hasMeaningfulInput($data, [
            'cover_upload_id', 'icon_upload_id', 'cover_url', 'icon_url',
        ]);
        $metadataChanged = self::hasAnyInput($data, [
            'metadata', 'metadata_json', 'file_name', 'original_name', 'mime_type', 'size_bytes', 'size',
            'file_sha256', 'sha256', 'package_name', 'version_name', 'version_code', 'platform',
            'language', 'min_sdk', 'target_sdk', 'permissions', 'framework', 'license',
        ]);
        if ($sourceChanged || $coverChanged || $metadataChanged) {
            $inspectionData = $data;
            if (!$sourceChanged) {
                if ((int) ($row['source_upload_id'] ?? 0) > 0) {
                    $inspectionData['source_upload_id'] = (int) $row['source_upload_id'];
                } else {
                    $inspectionData['download_url'] = (string) ($row['download_url'] ?? '');
                }
            }
            if (!$coverChanged) {
                if ((int) ($row['cover_upload_id'] ?? 0) > 0) {
                    $inspectionData['cover_upload_id'] = (int) $row['cover_upload_id'];
                } else {
                    $inspectionData['cover_url'] = (string) ($row['cover_url'] ?? '');
                }
            }
            if (!array_key_exists('metadata', $inspectionData) && !array_key_exists('metadata_json', $inspectionData)) {
                $inspectionData['metadata_json'] = (string) ($row['metadata_json'] ?? '{}');
            }
            $inspection = SubmissionInspectionService::inspectAdminUpload(
                $admin,
                $appId,
                $inspectionData,
                $resourceType
            );
        } else {
            $inspection = [
                'source_url' => (string) ($row['download_url'] ?? ''),
                'cover_url' => (string) ($row['cover_url'] ?? ''),
                'size_bytes' => (int) ($row['size_bytes'] ?? 0),
                'file_sha256' => (string) ($row['file_sha256'] ?? ''),
                'risk_level' => (string) ($row['risk_level'] ?? 'review'),
                'risk_reason' => (string) ($row['risk_reason'] ?? ''),
                'source_upload_id' => empty($row['source_upload_id']) ? null : (int) $row['source_upload_id'],
                'cover_upload_id' => empty($row['cover_upload_id']) ? null : (int) $row['cover_upload_id'],
                'metadata_json' => (string) ($row['metadata_json'] ?? '{}'),
                'force_audit' => (string) ($row['risk_level'] ?? 'review') !== 'low',
            ];
        }

        $auditStatus = (string) ($row['audit_status'] ?? 'pending');
        $auditReason = (string) ($row['audit_reason'] ?? '');
        if ($sourceChanged) {
            $auditStatus = $inspection['force_audit'] ? 'pending' : 'approved';
            $auditReason = $inspection['force_audit']
                ? '文件已变更，等待重新审核'
                : '';
        }
        $requestedStatus = (int) $request->input('status', $row['status']);
        $publicStatus = $auditStatus === 'approved' ? $requestedStatus : 0;
        $priceBalance = array_key_exists('price_balance', $data)
            ? max(0, (int) $data['price_balance'])
            : max(0, (int) ($row['price_integral'] ?? 0));

        Database::transaction(static function () use (
            $request,
            $row,
            $id,
            $payload,
            $resourceType,
            $category,
            $inspection,
            $auditStatus,
            $auditReason,
            $publicStatus,
            $priceBalance
        ): void {
            Database::execute(
                'UPDATE resources
                 SET resource_type = ?, category_id = ?, title = ?, description = ?,
                     cover_url = ?, download_url = ?, size_bytes = ?, file_sha256 = ?,
                     risk_level = ?, risk_reason = ?, source_upload_id = ?, cover_upload_id = ?,
                     metadata_json = ?, price_integral = ?, is_top = ?, is_recommended = ?,
                     audit_status = ?, audit_reason = ?, status = ?, updated_at = NOW()
                 WHERE id = ?',
                [
                    $resourceType,
                    (int) $category['id'],
                    mb_substr((string) $request->input('title', $row['title']), 0, 200),
                    $payload === null ? (string) $request->input('description', $row['description']) : (string) $payload['content'],
                    $inspection['cover_url'],
                    $inspection['source_url'],
                    $inspection['size_bytes'],
                    $inspection['file_sha256'],
                    $inspection['risk_level'],
                    $inspection['risk_reason'],
                    $inspection['source_upload_id'],
                    $inspection['cover_upload_id'],
                    $inspection['metadata_json'],
                    $priceBalance,
                    Validator::boolean($request->input('is_top', (bool) $row['is_top']), 'is_top') ? 1 : 0,
                    Validator::boolean($request->input('is_recommended', (bool) $row['is_recommended']), 'is_recommended') ? 1 : 0,
                    $auditStatus,
                    $auditReason,
                    $publicStatus,
                    $id,
                ]
            );
            if ($payload !== null) MessageMediaService::replace('resource', $id, $payload);
        });
        $updated = Database::one('SELECT * FROM resources WHERE id = ?', [$id]) ?? $row;
        LogService::adminOperation(
            $request,
            (int) $admin['id'],
            $appId,
            'resource',
            'update',
            $id,
            $row,
            [
                'resource_type' => $resourceType,
                'category_id' => (int) $category['id'],
                'risk_level' => $inspection['risk_level'],
                'audit_status' => $auditStatus,
                'status' => $publicStatus,
            ]
        );
        return Response::success(
            ['item' => SubmissionInspectionService::present($updated)],
            $auditStatus === 'approved' ? '资源修改成功' : '资源修改成功，文件等待审核'
        );
    }

    public static function deleteResource(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        Database::execute(
            'UPDATE resources SET status = -1, deleted_at = NOW(), updated_at = NOW()
             WHERE id = ? AND admin_id = ? AND app_id = ?',
            [(int) $params['resource_id'], (int) $admin['id'], $appId]
        );
        return Response::success([], '资源已删除');
    }

    public static function storeCategories(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        SubmissionInspectionService::seedStoreCategories((int) $admin['id'], $appId);
        return Response::success(['items' => Database::all(
            'SELECT * FROM store_categories WHERE admin_id = ? AND app_id = ? ORDER BY sort_order DESC, id',
            [(int) $admin['id'], $appId]
        )]);
    }

    public static function createStoreCategory(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = Database::insert(
            'INSERT INTO store_categories (admin_id, app_id, name, icon, sort_order, status, created_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW())',
            [
                (int) $admin['id'], $appId, Validator::string($request->input('name', ''), 'name', 1, 100),
                mb_substr((string) $request->input('icon', ''), 0, 500), (int) $request->input('sort_order', 0),
            ]
        );
        return Response::success(['category_id' => $id], '应用分类创建成功', 201);
    }

    public static function storeApps(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        SubmissionInspectionService::seedStoreCategories((int) $admin['id'], $appId);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['s.admin_id = ?', 's.app_id = ?', 's.deleted_at IS NULL'];
        $query = [(int) $admin['id'], $appId];
        foreach (['category_id', 'status'] as $field) {
            if ($request->input($field) !== null && $request->input($field) !== '') {
                $where[] = "s.{$field} = ?";
                $query[] = (int) $request->input($field);
            }
        }
        if ($request->input('uploader_id') !== null && $request->input('uploader_id') !== '') {
            $where[] = 's.user_id = ?';
            $query[] = (int) $request->input('uploader_id');
        }
        $auditStatus = trim((string) $request->input('audit_status', ''));
        if ($auditStatus !== '') {
            if (!in_array($auditStatus, ['pending', 'approved', 'rejected'], true)) {
                throw new HttpException('审核状态格式错误', 0, 422);
            }
            $where[] = 's.audit_status = ?';
            $query[] = $auditStatus;
        }
        $riskLevel = trim((string) $request->input('risk_level', ''));
        if ($riskLevel !== '') {
            if (!in_array($riskLevel, ['low', 'review', 'high'], true)) {
                throw new HttpException('风险等级格式错误', 0, 422);
            }
            $where[] = 's.risk_level = ?';
            $query[] = $riskLevel;
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(s.name LIKE ? OR s.package_name LIKE ? OR s.version_name LIKE ? OR u.account LIKE ? OR p.nickname LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($query, $like, $like, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total
             FROM store_apps s
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN user_profiles p ON p.user_id = s.user_id
             WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT s.*, c.name AS category_name, u.account, p.nickname
             FROM store_apps s
             LEFT JOIN store_categories c ON c.id = s.category_id
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN user_profiles p ON p.user_id = s.user_id
             WHERE {$whereSql} ORDER BY s.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = MessageMediaService::hydrate($items, 'store_app', $appId);
        $items = array_map(static function (array $item): array {
            $item['price_balance'] = max(0, (int) ($item['price_integral'] ?? 0));
            unset($item['price_integral']);
            return SubmissionInspectionService::present($item);
        }, $items);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function createStoreApp(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $data = $request->all();
        Validator::required($data, ['name', 'package_name', 'version_name']);
        $category = SubmissionInspectionService::resolveStoreCategory(
            (int) $admin['id'],
            $appId,
            $data
        );
        $inspection = SubmissionInspectionService::inspectAdminUpload(
            $admin,
            $appId,
            $data,
            'app_store'
        );
        $packageName = trim((string) $data['package_name']);
        if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{1,188}$/', $packageName)) {
            throw new HttpException('应用包名格式错误', 0, 422);
        }
        $versionCode = max(1, (int) ($data['version_code'] ?? 1));
        if (Database::one(
            'SELECT id FROM store_apps
             WHERE admin_id = ? AND app_id = ? AND package_name = ? AND version_code = ? AND deleted_at IS NULL
             LIMIT 1',
            [(int) $admin['id'], $appId, $packageName, $versionCode]
        ) !== null) {
            throw new HttpException('该包名和版本号已经投稿，请勿重复上传', 0, 409);
        }
        $images = $data['images'] ?? [];
        if (is_string($images)) $images = json_decode($images, true) ?: [];
        $imageUrls = [];
        foreach ((array) $images as $image) {
            $url = is_array($image)
                ? (string) ($image['url'] ?? $image['image_url'] ?? '')
                : (string) $image;
            if (trim($url) !== '') {
                $imageUrls[] = mb_substr(trim($url), 0, 1000);
            }
        }
        if (!isset($data['attachments']) && $imageUrls !== []) {
            $data['attachments'] = array_map(
                static fn(string $url): array => ['media_type' => 'image', 'url' => $url],
                $imageUrls
            );
        }
        $payload = null;
        if (self::hasMeaningfulInput($data, ['attachments', 'description'])) {
            $media = $data;
            $media['content'] = (string) ($data['description'] ?? '');
            $payload = MessageMediaService::adminPayload($admin, $appId, $media);
        }
        $auditStatus = $inspection['force_audit'] ? 'pending' : 'approved';
        $auditReason = $inspection['force_audit'] ? '安装包需要人工复核，审核通过后公开' : '';
        $requestedStatus = (int) ($data['status'] ?? 1);
        $publicStatus = $auditStatus === 'approved' ? $requestedStatus : 0;
        $priceBalance = max(0, (int) ($data['price_balance'] ?? $data['price_integral'] ?? 0));
        $id = Database::transaction(static function () use (
            $admin,
            $appId,
            $data,
            $category,
            $inspection,
            $packageName,
            $versionCode,
            $imageUrls,
            $payload,
            $auditStatus,
            $auditReason,
            $publicStatus,
            $priceBalance
        ): int {
            $id = Database::insert(
                'INSERT INTO store_apps
                 (admin_id, app_id, category_id, user_id, name, package_name, version_name, version_code,
                  icon_url, apk_url, size_bytes, description, metadata_json, file_sha256,
                  risk_level, risk_reason, source_upload_id, icon_upload_id, audit_status, audit_reason,
                  price_integral, status, created_at, updated_at)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    (int) $admin['id'],
                    $appId,
                    (int) $category['id'],
                    mb_substr((string) $data['name'], 0, 150),
                    mb_substr($packageName, 0, 190),
                    mb_substr((string) $data['version_name'], 0, 40),
                    $versionCode,
                    mb_substr((string) $inspection['cover_url'], 0, 500),
                    mb_substr((string) $inspection['source_url'], 0, 1000),
                    (int) $inspection['size_bytes'],
                    $payload === null ? (string) ($data['description'] ?? '') : (string) $payload['content'],
                    (string) $inspection['metadata_json'],
                    (string) $inspection['file_sha256'],
                    (string) $inspection['risk_level'],
                    mb_substr((string) $inspection['risk_reason'], 0, 1000),
                    $inspection['source_upload_id'],
                    $inspection['cover_upload_id'],
                    $auditStatus,
                    $auditReason,
                    $priceBalance,
                    $publicStatus,
                ]
            );
            foreach ($imageUrls as $index => $url) {
                Database::execute(
                    'INSERT INTO store_app_images (admin_id, app_id, store_app_id, image_url, sort_order, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW())',
                    [(int) $admin['id'], $appId, $id, $url, $index]
                );
            }
            if ($payload !== null) MessageMediaService::save('store_app', $id, $payload);
            return $id;
        });
        $item = Database::one(
            'SELECT s.*, c.name AS category_name
             FROM store_apps s LEFT JOIN store_categories c ON c.id = s.category_id
             WHERE s.id = ?',
            [$id]
        ) ?? ['id' => $id];
        $item['price_balance'] = $priceBalance;
        unset($item['price_integral']);
        LogService::adminOperation(
            $request,
            (int) $admin['id'],
            $appId,
            'store_app',
            'create',
            $id,
            null,
            [
                'package_name' => $packageName,
                'version_code' => $versionCode,
                'category_id' => (int) $category['id'],
                'risk_level' => $inspection['risk_level'],
                'audit_status' => $auditStatus,
                'status' => $publicStatus,
            ]
        );
        return Response::success(
            ['store_app_id' => $id, 'item' => SubmissionInspectionService::present($item)],
            $auditStatus === 'approved' ? '商店应用创建成功' : '应用已提交，等待安全审核',
            201
        );
    }

    public static function auditStoreApp(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['store_app_id'];
        $status = trim((string) $request->input('audit_status', ''));
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            throw new HttpException('审核状态格式错误', 0, 422);
        }
        $row = Database::one(
            'SELECT * FROM store_apps
             WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
            [$id, (int) $admin['id'], $appId]
        );
        if ($row === null) {
            throw new HttpException('商店应用不存在', 404, 404);
        }
        $overrideRisk = $request->input('override_risk') === null
            ? false
            : Validator::boolean($request->input('override_risk'), 'override_risk');
        if ($status === 'approved' && (string) ($row['risk_level'] ?? 'review') === 'high' && !$overrideRisk) {
            throw new HttpException('安装包被标记为高风险，需确认“覆盖风险”后才能通过审核', 0, 422);
        }
        $reason = mb_substr((string) $request->input('reason', ''), 0, 500);
        $publicStatus = $status === 'approved' ? 1 : 0;
        Database::execute(
            'UPDATE store_apps
             SET audit_status = ?, audit_reason = ?, status = ?, updated_at = NOW()
             WHERE id = ?',
            [$status, $reason, $publicStatus, $id]
        );
        $item = Database::one(
            'SELECT s.*, c.name AS category_name, u.account, p.nickname
             FROM store_apps s
             LEFT JOIN store_categories c ON c.id = s.category_id
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN user_profiles p ON p.user_id = s.user_id
             WHERE s.id = ?',
            [$id]
        ) ?? $row;
        $item['price_balance'] = max(0, (int) ($item['price_integral'] ?? 0));
        unset($item['price_integral']);
        LogService::adminOperation(
            $request,
            (int) $admin['id'],
            $appId,
            'store_app',
            'audit',
            $id,
            $row,
            [
                'audit_status' => $status,
                'audit_reason' => $reason,
                'risk_level' => (string) ($row['risk_level'] ?? 'review'),
                'override_risk' => $overrideRisk,
                'status' => $publicStatus,
            ]
        );
        return Response::success(
            ['item' => SubmissionInspectionService::present($item)],
            $status === 'approved' ? '应用审核通过并已上架' : '应用审核状态已更新'
        );
    }

    private static function hasAnyInput(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }
        return false;
    }

    private static function hasMeaningfulInput(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (is_array($value) && $value !== []) {
                return true;
            }
            if (!is_array($value) && trim((string) $value) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function context(Request $request, array $params): array
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        return [$admin, $appId];
    }
}
