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
use Yiyunying\Services\CatalogDownloadService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\MessageMediaService;
use Yiyunying\Services\NotificationService;
use Yiyunying\Services\SubmissionInspectionService;

final class ResourceController
{
    public static function categories(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $resourceType = SubmissionInspectionService::normalizeResourceType(
            (string) $request->input('resource_type', 'source_market')
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
            (string) $request->input('resource_type', 'source_market')
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
        $row = Database::transaction(static function () use ($id, $admin, $appId): array {
            $current = Database::one(
                'SELECT * FROM resource_categories
                 WHERE id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [$id, (int) $admin['id'], $appId]
            );
            if ($current === null) throw new HttpException('资源分类不存在', 404, 404);
            if (Database::one(
                'SELECT id FROM resources
                 WHERE category_id = ? AND admin_id = ? AND app_id = ? LIMIT 1 FOR UPDATE',
                [$id, (int) $admin['id'], $appId]
            ) !== null) {
                throw new HttpException('分类下存在资源历史（含已删除资源），不能删除；可停用分类但必须保留历史归属', 0, 409);
            }
            Database::execute(
                'DELETE FROM resource_categories WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$id, (int) $admin['id'], $appId]
            );
            return $current;
        });
        LogService::adminOperation(
            $request, (int) $admin['id'], $appId, 'resource_category', 'delete', $id, $row, ['deleted' => true]
        );
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
            $auditStatus = trim((string) $request->input('audit_status'));
            if (!in_array($auditStatus, ['pending', 'approved', 'rejected', 'on_hold'], true)) {
                throw new HttpException('audit_status 格式错误', 0, 422);
            }
            $where[] = 'r.audit_status = ?';
            $query[] = $auditStatus;
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
                    reviewer.account AS reviewer_account, reviewer.nickname AS reviewer_name,
                    (SELECT AVG(score) FROM resource_ratings rr WHERE rr.resource_id = r.id) AS rating
             FROM resources r LEFT JOIN resource_categories c ON c.id = r.category_id
             LEFT JOIN users u ON u.id = r.user_id LEFT JOIN user_profiles p ON p.user_id = r.user_id
             LEFT JOIN admins reviewer ON reviewer.id = r.audited_by
             WHERE {$whereSql}
             ORDER BY CASE r.audit_status WHEN 'pending' THEN 0 WHEN 'on_hold' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END,
                      r.updated_at DESC, r.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = MessageMediaService::hydrate($items, 'resource', $appId);
        $items = array_map(static function (array $item) use ($appId): array {
            $item['price_balance'] = max(0, (int) ($item['price_integral'] ?? 0));
            return self::presentResource($item, $appId);
        }, $items);
        $data = Pagination::data($items, $total, $page, $limit);
        $data['audit_summary'] = self::auditSummary('resources', (int) $admin['id'], $appId);
        return Response::success($data);
    }

    public static function showResource(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['resource_id'];
        $item = Database::one(
            'SELECT r.*, c.name AS category_name, u.account, u.uid, p.nickname, p.avatar,
                    reviewer.account AS reviewer_account, reviewer.nickname AS reviewer_name,
                    (SELECT AVG(score) FROM resource_ratings rr WHERE rr.resource_id = r.id) AS rating,
                    (SELECT COUNT(*) FROM resource_comments rc WHERE rc.resource_id = r.id AND rc.status = 1) AS comment_count,
                    (SELECT COUNT(*) FROM resource_purchases rp WHERE rp.resource_id = r.id) AS purchase_count
             FROM resources r
             LEFT JOIN resource_categories c ON c.id = r.category_id
             LEFT JOIN users u ON u.id = r.user_id
             LEFT JOIN user_profiles p ON p.user_id = r.user_id
             LEFT JOIN admins reviewer ON reviewer.id = r.audited_by
             WHERE r.id = ? AND r.admin_id = ? AND r.app_id = ? AND r.deleted_at IS NULL',
            [$id, (int) $admin['id'], $appId]
        );
        if ($item === null) throw new HttpException('资源不存在', 404, 404);
        $item = MessageMediaService::hydrate([$item], 'resource', $appId)[0];
        $item['price_balance'] = max(0, (int) ($item['price_integral'] ?? 0));
        $downloadUrl = CatalogDownloadService::adminUrl('resource', $item);
        $presented = self::presentResource($item, $appId);
        if ($downloadUrl !== '') $presented['download_url'] = $downloadUrl;
        return Response::success(['resource' => $presented]);
    }

    public static function resourceComments(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $adminId = (int) $admin['id'];
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['c.admin_id = ?', 'c.app_id = ?'];
        $query = [$adminId, $appId];

        $status = self::resourceCommentStatusFilter($request);
        if ($status !== null) {
            $where[] = 'c.status = ?';
            $query[] = $status;
        }
        foreach (['resource_id', 'user_id'] as $field) {
            if ($request->input($field) !== null && $request->input($field) !== '') {
                $where[] = "c.{$field} = ?";
                $query[] = (int) $request->input($field);
            }
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(c.content LIKE ? OR r.title LIKE ? OR u.account LIKE ? OR u.uid LIKE ? OR p.nickname LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($query, $like, $like, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total
             FROM resource_comments c
             INNER JOIN resources r
                     ON r.id = c.resource_id AND r.admin_id = c.admin_id AND r.app_id = c.app_id
             INNER JOIN users u
                     ON u.id = c.user_id AND u.admin_id = c.admin_id AND u.app_id = c.app_id
             LEFT JOIN user_profiles p
                    ON p.user_id = u.id AND p.admin_id = c.admin_id AND p.app_id = c.app_id
             WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            self::resourceCommentSelect() . " WHERE {$whereSql}
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = MessageMediaService::hydrate($items, 'resource_comment', $appId);
        $items = array_map(
            static fn(array $item): array => self::decorateResourceComment($item),
            $items
        );
        $data = Pagination::data($items, $total, $page, $limit);
        $data['status_summary'] = self::resourceCommentStatusSummary($adminId, $appId);
        return Response::success($data);
    }

    public static function showResourceComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $adminId = (int) $admin['id'];
        $commentId = (int) $params['comment_id'];
        $comment = self::resourceCommentRow($adminId, $appId, $commentId);
        if ($comment === null) throw new HttpException('资源评论不存在', 404, 404);

        $replies = Database::all(
            self::resourceCommentSelect() .
            ' WHERE c.parent_id = ? AND c.admin_id = ? AND c.app_id = ?
              ORDER BY c.created_at ASC, c.id ASC LIMIT 100',
            [$commentId, $adminId, $appId]
        );
        $comment = MessageMediaService::hydrate([$comment], 'resource_comment', $appId)[0];
        $replies = MessageMediaService::hydrate($replies, 'resource_comment', $appId);
        return Response::success([
            'comment' => self::decorateResourceComment($comment),
            'replies' => array_map(
                static fn(array $item): array => self::decorateResourceComment($item),
                $replies
            ),
            'replies_truncated' => (int) ($comment['reply_count'] ?? 0) > count($replies),
        ]);
    }

    public static function hideResourceComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::transitionResourceComment($request, $params, 0, 'hide', '评论及其回复已隐藏');
    }

    public static function restoreResourceComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::transitionResourceComment($request, $params, 1, 'restore', '评论已恢复，其回复仍保持原状态');
    }

    public static function deleteResourceComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::transitionResourceComment($request, $params, -1, 'delete', '评论及其回复已删除');
    }

    public static function downloadResource(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        return CatalogDownloadService::downloadForAdmin(
            $admin, $appId, 'resource', (int) $params['resource_id']
        );
    }

    public static function audit(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['resource_id'];
        [$status, $reason] = self::reviewDecision($request);
        $overrideRisk = $request->input('override_risk') === null
            ? false
            : Validator::boolean($request->input('override_risk'), 'override_risk');
        $readyUploadId = null;
        if ($status === 'approved') {
            $ready = Database::one(
                'SELECT * FROM resources WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
                [$id, (int) $admin['id'], $appId]
            );
            if ($ready === null) throw new HttpException('资源不存在', 404, 404);
            CatalogDownloadService::assertReady('resource', $ready);
            $readyUploadId = (int) ($ready['source_upload_id'] ?? 0);
        }
        $result = SubmissionInspectionService::catalogWriteTransaction($appId, static function () use (
            $request, $admin, $appId, $id, $status, $reason, $overrideRisk, $readyUploadId
        ): array {
            $row = Database::one(
                'SELECT * FROM resources
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL FOR UPDATE',
                [$id, (int) $admin['id'], $appId]
            );
            if ($row === null) throw new HttpException('资源不存在', 404, 404);
            $reviewable = MessageMediaService::hydrate([$row], 'resource', $appId)[0];
            self::assertExpectedSnapshot($request, $reviewable);
            if ($status === 'approved' && (int) ($row['source_upload_id'] ?? 0) !== $readyUploadId) {
                throw new HttpException('资源文件已在审核期间变化，请刷新后重试', 0, 409);
            }
            if ($status === 'approved' && (string) ($row['risk_level'] ?? 'review') === 'high' && !$overrideRisk) {
                throw new HttpException('该文件被标记为高风险，需确认“覆盖风险”后才能通过审核', 0, 422);
            }
            $sameDecision = (string) $row['audit_status'] === $status
                && (string) ($row['audit_reason'] ?? '') === $reason;
            if ($sameDecision) return ['before' => $row, 'after' => $row, 'changed' => false];

            $publicStatus = $status === 'approved' ? 1 : 0;
            Database::execute(
                'UPDATE resources
                 SET audit_status = ?, audit_reason = ?, audited_by = ?, audited_at = NOW(),
                     status = ?, updated_at = NOW()
                 WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$status, $reason, (int) $admin['id'], $publicStatus, $id, (int) $admin['id'], $appId]
            );
            $updated = Database::one(
                'SELECT * FROM resources WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$id, (int) $admin['id'], $appId]
            ) ?? $row;
            LogService::adminOperation(
                $request, (int) $admin['id'], $appId, 'resource_moderation',
                self::auditAction($status), $id, $row, $updated
            );
            self::notifyReview(
                (int) $admin['id'], $appId, (int) ($row['user_id'] ?? 0),
                'resource_audit', '资源', (string) $row['title'], 'resource_id', $id, $status, $reason
            );
            return ['before' => $row, 'after' => $updated, 'changed' => true];
        });
        $updated = $result['after'];
        $updated['price_balance'] = max(0, (int) ($updated['price_integral'] ?? 0));
        return Response::success(
            [
                'item' => self::presentResource($updated, $appId),
                'already_reviewed' => !$result['changed'],
            ],
            !$result['changed'] ? '审核结果未变化，无需重复处理' : self::reviewMessage('资源', $status)
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

        $sourceChanged = self::fileReferenceChanged(
            $data,
            ['source_upload_id', 'upload_id', 'file_upload_id', 'apk_upload_id'],
            (int) ($row['source_upload_id'] ?? 0),
            ['download_url', 'apk_url'],
            (string) ($row['download_url'] ?? '')
        );
        if ($sourceChanged && self::resourceHasPurchases($id)) {
            throw new HttpException('该资源已有购买记录，不能替换源文件；请新建资源版本', 0, 409);
        }
        $coverChanged = self::fileReferenceChanged(
            $data,
            ['cover_upload_id', 'icon_upload_id'],
            (int) ($row['cover_upload_id'] ?? 0),
            ['cover_url', 'icon_url'],
            (string) ($row['cover_url'] ?? '')
        );
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
            $auditStatus = 'pending';
            $auditReason = '文件已变更，等待重新审核';
        }
        $requestedStatus = (int) $request->input('status', $row['status']);
        $publicStatus = $auditStatus === 'approved' ? $requestedStatus : 0;
        $priceBalance = array_key_exists('price_balance', $data)
            ? SubmissionInspectionService::catalogPrice($data['price_balance'])
            : SubmissionInspectionService::catalogPrice($row['price_integral'] ?? 0);

        SubmissionInspectionService::catalogWriteTransaction($appId, static function () use (
            $request,
            $admin,
            $appId,
            $row,
            $id,
            $payload,
            $resourceType,
            $category,
            $inspection,
            $auditStatus,
            $auditReason,
            $sourceChanged,
            $publicStatus,
            $priceBalance
        ): void {
            SubmissionInspectionService::lockCatalogUploadReference(
                (int) $inspection['source_upload_id'],
                (int) $admin['id'],
                $appId,
                null,
                SubmissionInspectionService::catalogScene($resourceType),
                (string) $inspection['file_sha256']
            );
            if ((int) ($inspection['cover_upload_id'] ?? 0) > 0) {
                SubmissionInspectionService::lockCatalogCoverReference(
                    (int) $inspection['cover_upload_id'],
                    (int) $admin['id'],
                    $appId,
                    null,
                    SubmissionInspectionService::catalogCoverScene($resourceType),
                    (string) ($inspection['cover_sha256'] ?? '')
                );
            }
            $current = Database::one(
                'SELECT * FROM resources
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL FOR UPDATE',
                [$id, (int) $admin['id'], $appId]
            );
            if ($current === null) throw new HttpException('资源不存在', 404, 404);
            self::assertReviewSnapshot($row, $current);
            if ($sourceChanged && Database::one(
                'SELECT id FROM resource_purchases WHERE resource_id = ? LIMIT 1 FOR UPDATE',
                [$id]
            ) !== null) {
                throw new HttpException('该资源已有购买记录，不能替换源文件；请新建资源版本', 0, 409);
            }
            Database::execute(
                'UPDATE resources
                 SET resource_type = ?, category_id = ?, title = ?, description = ?,
                     cover_url = ?, download_url = ?, size_bytes = ?, file_sha256 = ?,
                     risk_level = ?, risk_reason = ?, source_upload_id = ?, cover_upload_id = ?,
                     metadata_json = ?, price_integral = ?, is_top = ?, is_recommended = ?,
                     audit_status = ?, audit_reason = ?, audited_by = ?, audited_at = ?,
                     status = ?, updated_at = NOW()
                 WHERE id = ? AND admin_id = ? AND app_id = ?',
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
                    $sourceChanged ? null : $row['audited_by'],
                    $sourceChanged ? null : $row['audited_at'],
                    $publicStatus,
                    $id,
                    (int) $admin['id'],
                    $appId,
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
            ['item' => self::presentResource($updated, $appId)],
            $auditStatus === 'approved' ? '资源修改成功' : '资源修改成功，文件等待审核'
        );
    }

    public static function deleteResource(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['resource_id'];
        $row = SubmissionInspectionService::catalogWriteTransaction($appId, static function () use ($id, $admin, $appId): array {
            $current = Database::one(
                'SELECT * FROM resources
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL FOR UPDATE',
                [$id, (int) $admin['id'], $appId]
            );
            if ($current === null) throw new HttpException('资源不存在', 404, 404);
            if (Database::one(
                'SELECT id FROM resource_purchases WHERE resource_id = ? LIMIT 1 FOR UPDATE',
                [$id]
            ) !== null) {
                throw new HttpException('该资源已有购买记录，不能删除；可停止公开但必须保留买家下载凭据', 0, 409);
            }
            Database::execute(
                'UPDATE resources SET status = -1, deleted_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$id, (int) $admin['id'], $appId]
            );
            return $current;
        });
        LogService::adminOperation(
            $request, (int) $admin['id'], $appId, 'resource', 'delete', $id, $row, ['deleted' => true]
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

    public static function updateStoreCategory(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['category_id'];
        $row = Database::one(
            'SELECT * FROM store_categories WHERE id = ? AND admin_id = ? AND app_id = ?',
            [$id, (int) $admin['id'], $appId]
        );
        if ($row === null) throw new HttpException('应用分类不存在', 404, 404);
        Database::execute(
            'UPDATE store_categories SET name = ?, icon = ?, sort_order = ?, status = ?
             WHERE id = ? AND admin_id = ? AND app_id = ?',
            [
                mb_substr(trim((string) $request->input('name', $row['name'])), 0, 100),
                mb_substr(trim((string) $request->input('icon', $row['icon'])), 0, 500),
                (int) $request->input('sort_order', $row['sort_order']),
                (int) $request->input('status', $row['status']),
                $id, (int) $admin['id'], $appId,
            ]
        );
        return Response::success([], '应用分类修改成功');
    }

    public static function deleteStoreCategory(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['category_id'];
        $row = Database::transaction(static function () use ($id, $admin, $appId): array {
            $current = Database::one(
                'SELECT * FROM store_categories
                 WHERE id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [$id, (int) $admin['id'], $appId]
            );
            if ($current === null) throw new HttpException('应用分类不存在', 404, 404);
            if (Database::one(
                'SELECT id FROM store_apps
                 WHERE category_id = ? AND admin_id = ? AND app_id = ? LIMIT 1 FOR UPDATE',
                [$id, (int) $admin['id'], $appId]
            ) !== null) {
                throw new HttpException('分类下存在应用历史（含已删除应用），不能删除；可停用分类但必须保留历史归属', 0, 409);
            }
            Database::execute(
                'DELETE FROM store_categories WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$id, (int) $admin['id'], $appId]
            );
            return $current;
        });
        LogService::adminOperation(
            $request, (int) $admin['id'], $appId, 'store_category', 'delete', $id, $row, ['deleted' => true]
        );
        return Response::success([], '应用分类已删除');
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
            if (!in_array($auditStatus, ['pending', 'approved', 'rejected', 'on_hold'], true)) {
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
            "SELECT s.*, c.name AS category_name, u.account, p.nickname,
                    reviewer.account AS reviewer_account, reviewer.nickname AS reviewer_name
             FROM store_apps s
             LEFT JOIN store_categories c ON c.id = s.category_id
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN user_profiles p ON p.user_id = s.user_id
             LEFT JOIN admins reviewer ON reviewer.id = s.audited_by
             WHERE {$whereSql}
             ORDER BY CASE s.audit_status WHEN 'pending' THEN 0 WHEN 'on_hold' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END,
                      s.updated_at DESC, s.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = MessageMediaService::hydrate($items, 'store_app', $appId);
        $items = array_map(
            static fn (array $item): array => self::presentStoreApp($item, $appId),
            $items
        );
        $data = Pagination::data($items, $total, $page, $limit);
        $data['audit_summary'] = self::auditSummary('store_apps', (int) $admin['id'], $appId);
        return Response::success($data);
    }

    public static function showStoreApp(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['store_app_id'];
        $item = Database::one(
            'SELECT s.*, c.name AS category_name, u.account, u.uid, p.nickname, p.avatar,
                    reviewer.account AS reviewer_account, reviewer.nickname AS reviewer_name,
                    (SELECT COUNT(*) FROM store_app_reactions sr WHERE sr.store_app_id = s.id AND sr.reaction_type = \'like\') AS like_count,
                    (SELECT COUNT(*) FROM store_app_reactions sr WHERE sr.store_app_id = s.id AND sr.reaction_type = \'favorite\') AS favorite_count
             FROM store_apps s
             LEFT JOIN store_categories c ON c.id = s.category_id
             LEFT JOIN users u ON u.id = s.user_id
             LEFT JOIN user_profiles p ON p.user_id = s.user_id
             LEFT JOIN admins reviewer ON reviewer.id = s.audited_by
             WHERE s.id = ? AND s.admin_id = ? AND s.app_id = ? AND s.deleted_at IS NULL',
            [$id, (int) $admin['id'], $appId]
        );
        if ($item === null) throw new HttpException('商店应用不存在', 404, 404);
        $item['images'] = Database::all(
            'SELECT image_url, sort_order FROM store_app_images WHERE store_app_id = ? ORDER BY sort_order, id',
            [$id]
        );
        $item = MessageMediaService::hydrate([$item], 'store_app', $appId)[0];
        $downloadUrl = CatalogDownloadService::adminUrl('store_app', $item);
        $presented = self::presentStoreApp($item, $appId);
        if ($downloadUrl !== '') $presented['apk_url'] = $downloadUrl;
        return Response::success(['store_app' => $presented]);
    }

    public static function downloadStoreApp(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        return CatalogDownloadService::downloadForAdmin(
            $admin, $appId, 'store_app', (int) $params['store_app_id']
        );
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
        $priceBalance = SubmissionInspectionService::catalogPrice(
            $data['price_balance'] ?? $data['price_integral'] ?? 0
        );
        $id = SubmissionInspectionService::catalogWriteTransaction($appId, static function () use (
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
            SubmissionInspectionService::lockCatalogUploadReference(
                (int) $inspection['source_upload_id'],
                (int) $admin['id'],
                $appId,
                null,
                'store_app_package',
                (string) $inspection['file_sha256']
            );
            if ((int) ($inspection['cover_upload_id'] ?? 0) > 0) {
                SubmissionInspectionService::lockCatalogCoverReference(
                    (int) $inspection['cover_upload_id'],
                    (int) $admin['id'],
                    $appId,
                    null,
                    'store_app_icon',
                    (string) ($inspection['cover_sha256'] ?? '')
                );
            }
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
            ['store_app_id' => $id, 'item' => self::presentStoreApp($item, $appId)],
            $auditStatus === 'approved' ? '商店应用创建成功' : '应用已提交，等待安全审核',
            201
        );
    }

    public static function updateStoreApp(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['store_app_id'];
        $row = Database::one(
            'SELECT * FROM store_apps WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
            [$id, (int) $admin['id'], $appId]
        );
        if ($row === null) throw new HttpException('商店应用不存在', 404, 404);
        $data = $request->all();
        if (!array_key_exists('category_id', $data)) $data['category_id'] = (int) ($row['category_id'] ?? 0);
        $category = SubmissionInspectionService::resolveStoreCategory((int) $admin['id'], $appId, $data);

        $packageName = trim((string) $request->input('package_name', $row['package_name']));
        if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{1,188}$/', $packageName)) {
            throw new HttpException('应用包名格式错误', 0, 422);
        }
        $versionCode = max(1, (int) $request->input('version_code', $row['version_code']));
        $versionName = mb_substr((string) $request->input('version_name', $row['version_name']), 0, 40);
        if (Database::one(
            'SELECT id FROM store_apps
             WHERE admin_id = ? AND app_id = ? AND package_name = ? AND version_code = ?
               AND id <> ? AND deleted_at IS NULL LIMIT 1',
            [(int) $admin['id'], $appId, $packageName, $versionCode, $id]
        ) !== null) {
            throw new HttpException('该包名和版本号已经存在', 0, 409);
        }

        $sourceChanged = self::fileReferenceChanged(
            $data,
            ['source_upload_id', 'upload_id', 'file_upload_id', 'apk_upload_id'],
            (int) ($row['source_upload_id'] ?? 0),
            ['apk_url'],
            (string) ($row['apk_url'] ?? '')
        );
        $identityChanged = $sourceChanged
            || $packageName !== (string) $row['package_name']
            || $versionCode !== (int) $row['version_code']
            || $versionName !== (string) $row['version_name'];
        if ($identityChanged && self::storeAppHasPurchases($id)) {
            throw new HttpException('该应用版本已有购买记录，不能替换包名、版本或安装包；请新建版本', 0, 409);
        }
        $iconChanged = self::fileReferenceChanged(
            $data,
            ['icon_upload_id', 'cover_upload_id'],
            (int) ($row['icon_upload_id'] ?? 0),
            ['icon_url', 'cover_url'],
            (string) ($row['icon_url'] ?? '')
        );
        if ($sourceChanged || $iconChanged) {
            $inspectionData = $data;
            if (!$sourceChanged) {
                if ((int) ($row['source_upload_id'] ?? 0) > 0) {
                    $inspectionData['source_upload_id'] = (int) $row['source_upload_id'];
                } else {
                    $inspectionData['apk_url'] = (string) $row['apk_url'];
                }
            }
            if (!$iconChanged) {
                if ((int) ($row['icon_upload_id'] ?? 0) > 0) {
                    $inspectionData['icon_upload_id'] = (int) $row['icon_upload_id'];
                } else {
                    $inspectionData['icon_url'] = (string) $row['icon_url'];
                }
            }
            if (!array_key_exists('metadata', $inspectionData) && !array_key_exists('metadata_json', $inspectionData)) {
                $inspectionData['metadata_json'] = (string) ($row['metadata_json'] ?? '{}');
            }
            $inspection = SubmissionInspectionService::inspectAdminUpload($admin, $appId, $inspectionData, 'app_store');
        } else {
            $inspection = [
                'source_url' => (string) $row['apk_url'],
                'cover_url' => (string) $row['icon_url'],
                'size_bytes' => (int) $row['size_bytes'],
                'file_sha256' => (string) $row['file_sha256'],
                'risk_level' => (string) $row['risk_level'],
                'risk_reason' => (string) $row['risk_reason'],
                'source_upload_id' => $row['source_upload_id'],
                'cover_upload_id' => $row['icon_upload_id'],
                'metadata_json' => (string) ($row['metadata_json'] ?? '{}'),
            ];
        }

        $auditStatus = $sourceChanged ? 'pending' : (string) $row['audit_status'];
        $auditReason = $sourceChanged ? '安装包已变更，等待重新审核' : (string) $row['audit_reason'];
        $requestedStatus = (int) $request->input('status', $row['status']);
        $publicStatus = $auditStatus === 'approved' ? $requestedStatus : 0;
        $priceBalance = SubmissionInspectionService::catalogPrice(
            $request->input('price_balance', $row['price_integral'])
        );
        $updated = SubmissionInspectionService::catalogWriteTransaction($appId, static function () use (
            $request,
            $admin,
            $appId,
            $id,
            $row,
            $category,
            $packageName,
            $versionCode,
            $versionName,
            $inspection,
            $auditStatus,
            $auditReason,
            $sourceChanged,
            $identityChanged,
            $priceBalance,
            $publicStatus
        ): array {
            SubmissionInspectionService::lockCatalogUploadReference(
                (int) $inspection['source_upload_id'],
                (int) $admin['id'],
                $appId,
                null,
                'store_app_package',
                (string) $inspection['file_sha256']
            );
            if ((int) ($inspection['cover_upload_id'] ?? 0) > 0) {
                SubmissionInspectionService::lockCatalogCoverReference(
                    (int) $inspection['cover_upload_id'],
                    (int) $admin['id'],
                    $appId,
                    null,
                    'store_app_icon',
                    (string) ($inspection['cover_sha256'] ?? '')
                );
            }
            $current = Database::one(
                'SELECT * FROM store_apps
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL FOR UPDATE',
                [$id, (int) $admin['id'], $appId]
            );
            if ($current === null) throw new HttpException('商店应用不存在', 404, 404);
            self::assertReviewSnapshot($row, $current);
            if ($identityChanged && Database::one(
                'SELECT id FROM store_app_purchases WHERE store_app_id = ? LIMIT 1 FOR UPDATE',
                [$id]
            ) !== null) {
                throw new HttpException('该应用版本已有购买记录，不能替换包名、版本或安装包；请新建版本', 0, 409);
            }
            Database::execute(
                'UPDATE store_apps
                 SET category_id = ?, name = ?, package_name = ?, version_name = ?, version_code = ?,
                     icon_url = ?, apk_url = ?, size_bytes = ?, description = ?, metadata_json = ?,
                     file_sha256 = ?, risk_level = ?, risk_reason = ?, source_upload_id = ?, icon_upload_id = ?,
                     audit_status = ?, audit_reason = ?, audited_by = ?, audited_at = ?,
                     price_integral = ?, status = ?, updated_at = NOW()
                 WHERE id = ? AND admin_id = ? AND app_id = ?',
                [
                    (int) $category['id'],
                    mb_substr((string) $request->input('name', $row['name']), 0, 150),
                    mb_substr($packageName, 0, 190),
                    $versionName,
                    $versionCode,
                    $inspection['cover_url'], $inspection['source_url'], (int) $inspection['size_bytes'],
                    (string) $request->input('description', $row['description']), $inspection['metadata_json'],
                    $inspection['file_sha256'], $inspection['risk_level'], $inspection['risk_reason'],
                    $inspection['source_upload_id'], $inspection['cover_upload_id'],
                    $auditStatus, $auditReason,
                    $sourceChanged ? null : $row['audited_by'], $sourceChanged ? null : $row['audited_at'],
                    $priceBalance, $publicStatus, $id, (int) $admin['id'], $appId,
                ]
            );
            return Database::one(
                'SELECT * FROM store_apps WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$id, (int) $admin['id'], $appId]
            ) ?? $row;
        });
        LogService::adminOperation(
            $request, (int) $admin['id'], $appId, 'store_app', 'update', $id, $row, $updated
        );
        return Response::success(
            ['item' => self::presentStoreApp($updated, $appId)],
            $sourceChanged ? '应用已更新，安装包等待重新审核' : '应用修改成功'
        );
    }

    public static function deleteStoreApp(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['store_app_id'];
        $row = SubmissionInspectionService::catalogWriteTransaction($appId, static function () use ($id, $admin, $appId): array {
            $current = Database::one(
                'SELECT * FROM store_apps
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL FOR UPDATE',
                [$id, (int) $admin['id'], $appId]
            );
            if ($current === null) throw new HttpException('商店应用不存在', 404, 404);
            if (Database::one(
                'SELECT id FROM store_app_purchases WHERE store_app_id = ? LIMIT 1 FOR UPDATE',
                [$id]
            ) !== null) {
                throw new HttpException('该应用版本已有购买记录，不能删除；可停止公开但必须保留买家下载凭据', 0, 409);
            }
            Database::execute(
                'UPDATE store_apps SET status = -1, deleted_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$id, (int) $admin['id'], $appId]
            );
            return $current;
        });
        LogService::adminOperation(
            $request, (int) $admin['id'], $appId, 'store_app', 'delete', $id, $row, ['deleted' => true]
        );
        return Response::success([], '应用已删除');
    }

    public static function auditStoreApp(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['store_app_id'];
        [$status, $reason] = self::reviewDecision($request);
        $overrideRisk = $request->input('override_risk') === null
            ? false
            : Validator::boolean($request->input('override_risk'), 'override_risk');
        $readyUploadId = null;
        if ($status === 'approved') {
            $ready = Database::one(
                'SELECT * FROM store_apps WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
                [$id, (int) $admin['id'], $appId]
            );
            if ($ready === null) throw new HttpException('商店应用不存在', 404, 404);
            CatalogDownloadService::assertReady('store_app', $ready);
            $readyUploadId = (int) ($ready['source_upload_id'] ?? 0);
        }
        $result = SubmissionInspectionService::catalogWriteTransaction($appId, static function () use (
            $request, $admin, $appId, $id, $status, $reason, $overrideRisk, $readyUploadId
        ): array {
            $row = Database::one(
                'SELECT * FROM store_apps
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL FOR UPDATE',
                [$id, (int) $admin['id'], $appId]
            );
            if ($row === null) throw new HttpException('商店应用不存在', 404, 404);
            $reviewable = MessageMediaService::hydrate([$row], 'store_app', $appId)[0];
            self::assertExpectedSnapshot($request, $reviewable);
            if ($status === 'approved' && (int) ($row['source_upload_id'] ?? 0) !== $readyUploadId) {
                throw new HttpException('安装包已在审核期间变化，请刷新后重试', 0, 409);
            }
            if ($status === 'approved' && (string) ($row['risk_level'] ?? 'review') === 'high' && !$overrideRisk) {
                throw new HttpException('安装包被标记为高风险，需确认“覆盖风险”后才能通过审核', 0, 422);
            }
            $sameDecision = (string) $row['audit_status'] === $status
                && (string) ($row['audit_reason'] ?? '') === $reason;
            if ($sameDecision) return ['before' => $row, 'after' => $row, 'changed' => false];

            $publicStatus = $status === 'approved' ? 1 : 0;
            Database::execute(
                'UPDATE store_apps
                 SET audit_status = ?, audit_reason = ?, audited_by = ?, audited_at = NOW(),
                     status = ?, updated_at = NOW()
                 WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$status, $reason, (int) $admin['id'], $publicStatus, $id, (int) $admin['id'], $appId]
            );
            $updated = Database::one(
                'SELECT * FROM store_apps WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$id, (int) $admin['id'], $appId]
            ) ?? $row;
            LogService::adminOperation(
                $request, (int) $admin['id'], $appId, 'store_app_moderation',
                self::auditAction($status), $id, $row, $updated
            );
            self::notifyReview(
                (int) $admin['id'], $appId, (int) ($row['user_id'] ?? 0),
                'store_app_audit', '应用', (string) $row['name'], 'store_app_id', $id, $status, $reason
            );
            return ['before' => $row, 'after' => $updated, 'changed' => true];
        });
        $item = $result['after'];
        return Response::success(
            [
                'item' => self::presentStoreApp($item, $appId),
                'already_reviewed' => !$result['changed'],
            ],
            !$result['changed'] ? '审核结果未变化，无需重复处理' : self::reviewMessage('应用', $status)
        );
    }

    private static function reviewDecision(Request $request): array
    {
        $status = trim((string) $request->input('audit_status', ''));
        if (!in_array($status, ['approved', 'rejected', 'on_hold'], true)) {
            throw new HttpException('audit_status 仅支持 approved、rejected 或 on_hold', 0, 422);
        }
        $reason = trim(mb_substr((string) $request->input('reason', ''), 0, 500));
        if (in_array($status, ['rejected', 'on_hold'], true) && $reason === '') {
            throw new HttpException($status === 'on_hold' ? '暂定时必须填写原因与后续要求' : '不通过时必须填写原因', 0, 422);
        }
        return [$status, $reason];
    }

    private static function presentStoreApp(array $item, int $appId): array
    {
        if (!array_key_exists('attachments', $item)) {
            $item = MessageMediaService::hydrate([$item], 'store_app', $appId)[0];
        }
        $item['price_balance'] = max(0, (int) ($item['price_integral'] ?? 0));
        $presented = SubmissionInspectionService::present($item);
        unset($presented['price_integral']);
        return $presented;
    }

    private static function presentResource(array $item, int $appId): array
    {
        if (!array_key_exists('attachments', $item)) {
            $item = MessageMediaService::hydrate([$item], 'resource', $appId)[0];
        }
        $item['price_balance'] = max(0, (int) ($item['price_integral'] ?? 0));
        return SubmissionInspectionService::present($item);
    }

    private static function assertExpectedSnapshot(Request $request, array $actual): void
    {
        $actualStatus = (string) ($actual['audit_status'] ?? '');
        $expected = trim((string) $request->input('expected_audit_status', ''));
        if (!in_array($expected, ['pending', 'approved', 'rejected', 'on_hold'], true)) {
            throw new HttpException('expected_audit_status 为必填项，请刷新审核列表后重试', 0, 422);
        }
        if ($expected !== $actualStatus) {
            throw new HttpException('审核状态已被其他操作更新，请刷新列表后重试', 0, 409, [
                'expected_audit_status' => $expected,
                'actual_audit_status' => $actualStatus,
            ]);
        }
        $expectedRevision = strtolower(trim((string) $request->input('expected_review_revision', '')));
        if (preg_match('/^[a-f0-9]{64}$/', $expectedRevision) !== 1) {
            throw new HttpException('expected_review_revision 为必填项，请重新打开审核详情', 0, 422);
        }
        $actualRevision = SubmissionInspectionService::reviewRevision($actual);
        if (!hash_equals($actualRevision, $expectedRevision)) {
            throw new HttpException('待审核内容已被编辑，请重新查看完整内容后再作决定', 0, 409, [
                'expected_review_revision' => $expectedRevision,
                'actual_review_revision' => $actualRevision,
            ]);
        }
    }

    private static function notifyReview(
        int $adminId,
        int $appId,
        int $userId,
        string $type,
        string $entityLabel,
        string $title,
        string $idKey,
        int $id,
        string $status,
        string $reason
    ): void {
        if ($userId <= 0) return;
        $author = NotificationService::user($adminId, $appId, $userId);
        if ($author === null) return;
        $statusLabel = [
            'approved' => '通过',
            'rejected' => '不通过',
            'on_hold' => '暂定',
        ][$status];
        $content = '《' . $title . '》审核结果：' . $statusLabel;
        if ($reason !== '') $content .= '。' . $reason;
        NotificationService::send(
            $author,
            $type,
            $entityLabel . '审核' . $statusLabel,
            $content,
            [$idKey => $id, 'audit_status' => $status, 'audit_reason' => $reason]
        );
    }

    private static function auditAction(string $status): string
    {
        return ['approved' => 'approve', 'rejected' => 'reject', 'on_hold' => 'hold'][$status];
    }

    private static function reviewMessage(string $entityLabel, string $status): string
    {
        return [
            'approved' => $entityLabel . '已通过审核并公开',
            'rejected' => $entityLabel . '已标记为不通过并停止公开',
            'on_hold' => $entityLabel . '已暂定并停止公开',
        ][$status];
    }

    private static function auditSummary(string $table, int $adminId, int $appId): array
    {
        if (!in_array($table, ['resources', 'store_apps'], true)) {
            throw new \InvalidArgumentException('Unsupported audit summary table');
        }
        $summary = ['pending' => 0, 'on_hold' => 0, 'approved' => 0, 'rejected' => 0];
        foreach (Database::all(
            "SELECT audit_status, COUNT(*) AS total FROM {$table}
             WHERE admin_id = ? AND app_id = ? AND deleted_at IS NULL
             GROUP BY audit_status",
            [$adminId, $appId]
        ) as $row) {
            $status = (string) ($row['audit_status'] ?? '');
            if (array_key_exists($status, $summary)) $summary[$status] = (int) $row['total'];
        }
        $summary['total'] = array_sum($summary);
        return $summary;
    }

    private static function resourceCommentSelect(): string
    {
        return 'SELECT c.*, r.title AS resource_title, r.resource_type,
                       CASE WHEN r.deleted_at IS NULL THEN 0 ELSE 1 END AS resource_deleted,
                       u.uid, u.account, p.nickname, p.avatar,
                       parent.content AS parent_content, parent.status AS parent_status,
                       parent_user.account AS parent_account, parent_profile.nickname AS parent_nickname,
                       (SELECT COUNT(*) FROM resource_comments child
                        WHERE child.parent_id = c.id
                          AND child.admin_id = c.admin_id AND child.app_id = c.app_id
                          AND child.resource_id = c.resource_id AND child.status <> -1) AS reply_count
                FROM resource_comments c
                INNER JOIN resources r
                        ON r.id = c.resource_id AND r.admin_id = c.admin_id AND r.app_id = c.app_id
                INNER JOIN users u
                        ON u.id = c.user_id AND u.admin_id = c.admin_id AND u.app_id = c.app_id
                LEFT JOIN user_profiles p
                       ON p.user_id = u.id AND p.admin_id = c.admin_id AND p.app_id = c.app_id
                LEFT JOIN resource_comments parent
                       ON parent.id = c.parent_id AND parent.resource_id = c.resource_id
                      AND parent.admin_id = c.admin_id AND parent.app_id = c.app_id
                LEFT JOIN users parent_user
                       ON parent_user.id = parent.user_id
                      AND parent_user.admin_id = c.admin_id AND parent_user.app_id = c.app_id
                LEFT JOIN user_profiles parent_profile
                       ON parent_profile.user_id = parent_user.id
                      AND parent_profile.admin_id = c.admin_id AND parent_profile.app_id = c.app_id';
    }

    private static function resourceCommentRow(int $adminId, int $appId, int $commentId): ?array
    {
        return Database::one(
            self::resourceCommentSelect() . ' WHERE c.id = ? AND c.admin_id = ? AND c.app_id = ?',
            [$commentId, $adminId, $appId]
        );
    }

    private static function resourceCommentStatusFilter(Request $request): ?int
    {
        $raw = $request->input('status');
        if ($raw === null || trim((string) $raw) === '') return null;
        $key = strtolower(trim((string) $raw));
        $statuses = [
            '1' => 1, 'visible' => 1,
            '0' => 0, 'hidden' => 0,
            '-1' => -1, 'deleted' => -1,
        ];
        if (!array_key_exists($key, $statuses)) {
            throw new HttpException('评论状态仅支持 visible、hidden、deleted 或 1、0、-1', 0, 422);
        }
        return $statuses[$key];
    }

    private static function resourceCommentStatusSummary(int $adminId, int $appId): array
    {
        $summary = ['visible' => 0, 'hidden' => 0, 'deleted' => 0];
        foreach (Database::all(
            'SELECT status, COUNT(*) AS total FROM resource_comments
             WHERE admin_id = ? AND app_id = ? GROUP BY status',
            [$adminId, $appId]
        ) as $row) {
            $key = [1 => 'visible', 0 => 'hidden', -1 => 'deleted'][(int) $row['status']] ?? null;
            if ($key !== null) $summary[$key] = (int) $row['total'];
        }
        $summary['total'] = array_sum($summary);
        return $summary;
    }

    private static function decorateResourceComment(array $comment): array
    {
        $status = (int) ($comment['status'] ?? 0);
        $state = [
            1 => ['visible', '正常显示'],
            0 => ['hidden', '已隐藏'],
            -1 => ['deleted', '已删除'],
        ][$status] ?? ['unknown', '未知状态'];
        $comment['visibility_status'] = $state[0];
        $comment['status_label'] = $state[1];
        $comment['can_hide'] = $status === 1;
        $comment['can_restore'] = $status === 0;
        $comment['can_delete'] = $status !== -1;
        $comment['content_excerpt'] = mb_substr((string) ($comment['content'] ?? ''), 0, 180);
        return $comment;
    }

    private static function transitionResourceComment(
        Request $request,
        array $params,
        int $targetStatus,
        string $action,
        string $successMessage
    ): \Yiyunying\Core\ApiResponse {
        [$admin, $appId] = self::context($request, $params);
        $adminId = (int) $admin['id'];
        $commentId = (int) $params['comment_id'];
        $reason = trim(mb_substr((string) $request->input('reason', ''), 0, 500));
        $result = SubmissionInspectionService::catalogWriteTransaction($appId, static function () use (
            $request, $adminId, $appId, $commentId, $targetStatus, $action, $reason
        ): array {
            $before = Database::one(
                'SELECT c.* FROM resource_comments c
                 INNER JOIN resources r
                         ON r.id = c.resource_id AND r.admin_id = c.admin_id AND r.app_id = c.app_id
                 WHERE c.id = ? AND c.admin_id = ? AND c.app_id = ? FOR UPDATE',
                [$commentId, $adminId, $appId]
            );
            if ($before === null) throw new HttpException('资源评论不存在', 404, 404);
            $currentStatus = (int) $before['status'];
            if ($currentStatus === -1 && $targetStatus !== -1) {
                throw new HttpException('已删除的评论不能隐藏或恢复', 0, 409);
            }
            if ($currentStatus === $targetStatus) {
                return ['changed' => false, 'affected_count' => 0];
            }

            $resourceId = (int) $before['resource_id'];
            $rows = Database::all(
                'SELECT id, parent_id, status FROM resource_comments
                 WHERE resource_id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [$resourceId, $adminId, $appId]
            );
            if ($targetStatus === 1) {
                self::assertRestorableResourceCommentParentChain($rows, $before);
            }
            $subtreeIds = $targetStatus === 1
                ? [$commentId]
                : self::resourceCommentSubtreeIds($rows, $commentId);
            $subtreeLookup = array_fill_keys($subtreeIds, true);
            $changedIds = [];
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if (!isset($subtreeLookup[$id])) continue;
                $status = (int) $row['status'];
                $eligible = $targetStatus === 1 ? $status === 0
                    : ($targetStatus === 0 ? $status === 1 : $status !== -1);
                if ($eligible) $changedIds[] = $id;
            }
            foreach (array_chunk($changedIds, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                Database::execute(
                    "UPDATE resource_comments SET status = ?
                     WHERE id IN ({$placeholders}) AND resource_id = ? AND admin_id = ? AND app_id = ?",
                    array_merge([$targetStatus], $chunk, [$resourceId, $adminId, $appId])
                );
            }
            $after = Database::one(
                'SELECT * FROM resource_comments WHERE id = ? AND resource_id = ? AND admin_id = ? AND app_id = ?',
                [$commentId, $resourceId, $adminId, $appId]
            ) ?? $before;
            $auditIds = array_slice($changedIds, 0, 100);
            $beforeLog = $before;
            $beforeLog['affected_comment_ids'] = $auditIds;
            $beforeLog['affected_count'] = count($changedIds);
            $afterLog = $after;
            $afterLog['affected_comment_ids'] = $auditIds;
            $afterLog['affected_count'] = count($changedIds);
            $afterLog['affected_ids_truncated'] = count($changedIds) > count($auditIds);
            $afterLog['reason'] = $reason;
            LogService::adminOperation(
                $request, $adminId, $appId, 'resource_comment_moderation',
                $action, $commentId, $beforeLog, $afterLog
            );
            return ['changed' => true, 'affected_count' => count($changedIds)];
        });

        $comment = self::resourceCommentRow($adminId, $appId, $commentId);
        if ($comment !== null) {
            $comment = MessageMediaService::hydrate([$comment], 'resource_comment', $appId)[0];
            $comment = self::decorateResourceComment($comment);
        }
        return Response::success(
            [
                'comment' => $comment,
                'changed' => (bool) $result['changed'],
                'affected_count' => (int) $result['affected_count'],
            ],
            $result['changed'] ? $successMessage : '评论状态未变化，无需重复处理'
        );
    }

    private static function resourceCommentSubtreeIds(array $rows, int $rootId): array
    {
        $children = [];
        foreach ($rows as $row) {
            $parentId = (int) ($row['parent_id'] ?? 0);
            $children[$parentId][] = (int) $row['id'];
        }
        $result = [];
        $visited = [];
        $queue = [$rootId];
        $cursor = 0;
        while ($cursor < count($queue)) {
            $id = $queue[$cursor++];
            if (isset($visited[$id])) continue;
            $visited[$id] = true;
            $result[] = $id;
            foreach ($children[$id] ?? [] as $childId) $queue[] = $childId;
        }
        return $result;
    }

    private static function assertRestorableResourceCommentParentChain(array $rows, array $comment): void
    {
        $byId = [];
        foreach ($rows as $row) $byId[(int) $row['id']] = $row;
        $parentId = (int) ($comment['parent_id'] ?? 0);
        $visited = [];
        while ($parentId > 0) {
            if (isset($visited[$parentId])) {
                throw new HttpException('评论回复关系异常，暂时不能恢复', 0, 409);
            }
            $visited[$parentId] = true;
            $parent = $byId[$parentId] ?? null;
            if ($parent === null) {
                throw new HttpException('上级评论不存在，暂时不能恢复该回复', 0, 409);
            }
            $status = (int) $parent['status'];
            if ($status === -1) {
                throw new HttpException('上级评论已删除，不能恢复该回复', 0, 409);
            }
            if ($status !== 1) {
                throw new HttpException('请先恢复上级评论，再恢复该回复', 0, 409);
            }
            $parentId = (int) ($parent['parent_id'] ?? 0);
        }
    }

    private static function fileReferenceChanged(
        array $data,
        array $uploadKeys,
        int $currentUploadId,
        array $urlKeys,
        string $currentUrl
    ): bool {
        foreach ($uploadKeys as $key) {
            if (!array_key_exists($key, $data)) continue;
            $value = trim((string) $data[$key]);
            if ($value !== '' && $value !== (string) $currentUploadId) return true;
        }
        $currentUrl = trim($currentUrl);
        foreach ($urlKeys as $key) {
            if (!array_key_exists($key, $data)) continue;
            $value = trim((string) $data[$key]);
            if ($value !== '' && $value !== $currentUrl) return true;
        }
        return false;
    }

    private static function assertReviewSnapshot(array $expected, array $actual): void
    {
        foreach (['audit_status', 'audit_reason', 'audited_by', 'audited_at', 'status', 'updated_at'] as $key) {
            if ((string) ($expected[$key] ?? '') !== (string) ($actual[$key] ?? '')) {
                throw new HttpException('记录已被其他审核或编辑操作更新，请刷新详情后重试', 0, 409, [
                    'actual_audit_status' => (string) ($actual['audit_status'] ?? ''),
                ]);
            }
        }
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

    private static function resourceHasPurchases(int $resourceId): bool
    {
        return Database::one(
            'SELECT id FROM resource_purchases WHERE resource_id = ? LIMIT 1',
            [$resourceId]
        ) !== null;
    }

    private static function storeAppHasPurchases(int $storeAppId): bool
    {
        return Database::one(
            'SELECT id FROM store_app_purchases WHERE store_app_id = ? LIMIT 1',
            [$storeAppId]
        ) !== null;
    }

    private static function context(Request $request, array $params): array
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        SubmissionInspectionService::requireCatalogMigrationReady($appId);
        return [$admin, $appId];
    }
}
