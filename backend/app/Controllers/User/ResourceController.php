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
use Yiyunying\Services\LogService;
use Yiyunying\Services\MessageMediaService;
use Yiyunying\Services\NotificationService;
use Yiyunying\Services\SubmissionInspectionService;
use Yiyunying\Services\WalletService;

final class ResourceController
{
    public static function categories(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'resources');
        $type = SubmissionInspectionService::normalizeResourceType(
            (string) $request->input('resource_type', 'app_store')
        );
        SubmissionInspectionService::seedResourceCategories(
            (int) $user['admin_id'],
            (int) $user['app_id'],
            $type
        );
        return Response::success(['items' => Database::all(
            'SELECT id, resource_type, name, icon, description
             FROM resource_categories
             WHERE app_id = ? AND resource_type = ? AND status = 1
             ORDER BY sort_order DESC, id',
            [(int) $user['app_id'], $type]
        )]);
    }

    public static function submissionPolicy(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'resources');
        return Response::success([
            'enabled' => (bool) AppService::setting((int) $user['app_id'], 'resource_user_submit_enabled', true),
            'audit_required' => (bool) AppService::setting((int) $user['app_id'], 'resource_submit_audit', true),
        ]);
    }

    public static function resources(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'resources');
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['r.app_id = ?', 'r.audit_status = ?', 'r.status = 1', 'r.deleted_at IS NULL'];
        $query = [(int) $user['app_id'], 'approved'];
        $resourceType = trim((string) $request->input('resource_type', ''));
        if ($resourceType !== '') {
            $where[] = 'r.resource_type = ?';
            $query[] = SubmissionInspectionService::normalizeResourceType($resourceType);
        }
        if ($request->input('category_id') !== null && $request->input('category_id') !== '') {
            $where[] = 'r.category_id = ?';
            $query[] = (int) $request->input('category_id');
        }
        if (trim((string) $request->input('keyword', '')) !== '') {
            $where[] = '(r.title LIKE ? OR r.description LIKE ?)';
            $query[] = '%' . trim((string) $request->input('keyword')) . '%';
            $query[] = '%' . trim((string) $request->input('keyword')) . '%';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM resources r WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT r.id, r.resource_type, r.category_id, r.user_id, r.title, r.description,
                    r.cover_url, r.size_bytes, r.file_sha256, r.risk_level,
                    r.risk_reason, r.metadata_json, r.audit_status, r.price_integral,
                    r.is_top, r.is_recommended, r.view_count, r.download_count, r.created_at,
                    c.name AS category_name, p.nickname,
                    (SELECT AVG(score) FROM resource_ratings rr WHERE rr.resource_id = r.id) AS rating
             FROM resources r INNER JOIN resource_categories c ON c.id = r.category_id
             LEFT JOIN user_profiles p ON p.user_id = r.user_id
             WHERE {$whereSql} ORDER BY r.is_top DESC, r.is_recommended DESC, r.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        foreach ($items as &$item) {
            $item['price_balance'] = (int) $item['price_integral'];
            unset($item['price_integral']);
            $item = SubmissionInspectionService::present($item);
        }
        unset($item);
        $items = MessageMediaService::hydrate($items, 'resource', (int) $user['app_id']);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function showResource(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'resources');
        $resource = self::resource((int) $user['app_id'], (int) $params['resource_id']);
        Database::execute('UPDATE resources SET view_count = view_count + 1 WHERE id = ?', [(int) $resource['id']]);
        $purchased = (int) $resource['price_integral'] === 0
            || (int) ($resource['user_id'] ?? 0) === (int) $user['id']
            || Database::one('SELECT id FROM resource_purchases WHERE resource_id = ? AND buyer_user_id = ?', [(int) $resource['id'], (int) $user['id']]) !== null;
        if (!$purchased) {
            unset($resource['download_url']);
        }
        $resource['purchased'] = $purchased;
        $resource['liked'] = Database::one('SELECT id FROM resource_reactions WHERE resource_id = ? AND user_id = ? AND reaction_type = ?', [(int) $resource['id'], (int) $user['id'], 'like']) !== null;
        $resource['favorited'] = Database::one('SELECT id FROM resource_reactions WHERE resource_id = ? AND user_id = ? AND reaction_type = ?', [(int) $resource['id'], (int) $user['id'], 'favorite']) !== null;
        $resource['comments'] = Database::all(
            'SELECT c.id, c.user_id, c.parent_id, c.content, c.created_at, p.nickname, p.avatar
             FROM resource_comments c LEFT JOIN user_profiles p ON p.user_id = c.user_id
             WHERE c.resource_id = ? AND c.status = 1 ORDER BY c.id ASC LIMIT 100',
            [(int) $resource['id']]
        );
        $resource = MessageMediaService::hydrate([$resource], 'resource', (int) $user['app_id'])[0];
        $resource['comments'] = MessageMediaService::hydrate($resource['comments'], 'resource_comment', (int) $user['app_id']);
        $resource['price_balance'] = (int) $resource['price_integral'];
        unset($resource['price_integral']);
        $resource = SubmissionInspectionService::present($resource);
        return Response::success(['resource' => $resource]);
    }

    public static function submit(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'resources');
        if (!(bool) AppService::setting((int) $user['app_id'], 'resource_user_submit_enabled', true)) {
            throw new HttpException('当前应用未开放用户资源投稿', 403, 403);
        }
        AuthService::ensureNotBanned($user, ['all', 'resource']);
        $data = $request->all();
        Validator::required($data, ['title', 'description']);
        $resourceType = SubmissionInspectionService::normalizeResourceType(
            (string) ($data['resource_type'] ?? 'app_store')
        );
        $category = SubmissionInspectionService::resolveResourceCategory(
            (int) $user['admin_id'],
            (int) $user['app_id'],
            $resourceType,
            $data
        );
        $inspection = SubmissionInspectionService::inspectUserUpload($user, $data, $resourceType);
        $auditRequired = (bool) AppService::setting((int) $user['app_id'], 'resource_submit_audit', true)
            || (bool) $inspection['force_audit'];
        $audit = $auditRequired ? 'pending' : 'approved';
        $status = $audit === 'approved' ? 1 : 0;
        $mediaData = $data;
        $mediaData['content'] = (string) $data['description'];
        $payload = MessageMediaService::userPayload($user, $mediaData);
        $id = Database::transaction(static function () use (
            $user,
            $data,
            $payload,
            $audit,
            $status,
            $resourceType,
            $category,
            $inspection
        ): int {
            $id = Database::insert(
                'INSERT INTO resources
                 (admin_id, app_id, resource_type, category_id, user_id, title, description,
                  cover_url, download_url, size_bytes, file_sha256, risk_level, risk_reason,
                  source_upload_id, cover_upload_id, metadata_json, price_integral,
                  audit_status, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], $resourceType,
                    (int) $category['id'], (int) $user['id'],
                    mb_substr((string) $data['title'], 0, 200), (string) $payload['content'],
                    $inspection['cover_url'], $inspection['source_url'], (int) $inspection['size_bytes'],
                    $inspection['file_sha256'], $inspection['risk_level'], $inspection['risk_reason'],
                    $inspection['source_upload_id'], $inspection['cover_upload_id'],
                    $inspection['metadata_json'], max(0, (int) ($data['price_balance'] ?? 0)),
                    $audit, $status,
                ]
            );
            MessageMediaService::save('resource', $id, $payload);
            return $id;
        });
        LogService::userOperation($request, $user, 'resource', 'submit', $id, ['audit_status' => $audit]);
        return Response::success([
            'resource_id' => $id,
            'resource_type' => $resourceType,
            'category_id' => (int) $category['id'],
            'category_name' => (string) $category['name'],
            'audit_status' => $audit,
            'audit_status_label' => $audit === 'approved' ? '已通过' : '待审核',
            'risk_level' => $inspection['risk_level'],
        ], $audit === 'approved' ? '资源投稿成功' : '资源已提交审核', 201);
    }

    public static function buy(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'resources');
        $resourceId = (int) $params['resource_id'];
        $result = Database::transaction(static function () use ($user, $resourceId): array {
            $resource = Database::one(
                'SELECT * FROM resources WHERE id = ? AND admin_id = ? AND app_id = ?
                 AND audit_status = ? AND status = 1 AND deleted_at IS NULL FOR UPDATE',
                [$resourceId, (int) $user['admin_id'], (int) $user['app_id'], 'approved']
            );
            if ($resource === null) {
                throw new HttpException('资源不存在或不可购买', 404, 404);
            }
            if ((int) ($resource['user_id'] ?? 0) === (int) $user['id']) {
                return ['resource' => $resource, 'already_owned' => true];
            }
            if (Database::one('SELECT id FROM resource_purchases WHERE resource_id = ? AND buyer_user_id = ?', [$resourceId, (int) $user['id']])) {
                return ['resource' => $resource, 'already_owned' => true];
            }
            $price = (int) $resource['price_integral'];
            if ($price > 0) {
                $asset = WalletService::requireActivityEnabled((int) $user['app_id']);
                WalletService::adjust($user, $asset, -$price, 'resource_buy', 'resource', $resourceId, '余额购买资源');
                if ($resource['user_id'] !== null) {
                    WalletService::adjust([
                        'id' => (int) $resource['user_id'],
                        'admin_id' => (int) $user['admin_id'],
                        'app_id' => (int) $user['app_id'],
                    ], $asset, $price, 'resource_sale', 'resource', $resourceId, '资源销售收入');
                }
            }
            Database::execute(
                'INSERT INTO resource_purchases
                 (admin_id, app_id, resource_id, buyer_user_id, seller_user_id, price_integral, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], $resourceId, (int) $user['id'],
                    $resource['user_id'] === null ? null : (int) $resource['user_id'], $price,
                ]
            );
            Database::execute('UPDATE resources SET download_count = download_count + 1 WHERE id = ?', [$resourceId]);
            return ['resource' => $resource, 'already_owned' => false];
        });
        if (!$result['already_owned'] && (int) ($result['resource']['user_id'] ?? 0) > 0
            && (int) $result['resource']['user_id'] !== (int) $user['id']) {
            $seller = NotificationService::user(
                (int) $user['admin_id'],
                (int) $user['app_id'],
                (int) $result['resource']['user_id']
            );
            if ($seller !== null) {
                NotificationService::send(
                    $seller,
                    'resource_sale',
                    '资源被购买',
                    '《' . (string) $result['resource']['title'] . '》产生了一笔新购买',
                    [
                        'resource_id' => $resourceId,
                        'buyer_user_id' => (int) $user['id'],
                        'balance' => (int) $result['resource']['price_integral'],
                    ]
                );
            }
        }
        LogService::userOperation($request, $user, 'resource', 'buy', $resourceId);
        return Response::success([
            'resource_id' => $resourceId,
            'download_url' => $result['resource']['download_url'],
            'already_owned' => $result['already_owned'],
            'cost_balance' => $result['already_owned'] ? 0 : (int) $result['resource']['price_integral'],
        ], $result['already_owned'] ? '资源已经拥有' : '资源购买成功');
    }

    public static function comment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'resources');
        AuthService::ensureNotBanned($user, ['all', 'comment', 'resource']);
        $resource = self::resource((int) $user['app_id'], (int) $params['resource_id']);
        $payload = MessageMediaService::userPayload($user, $request->all());
        if (mb_strlen((string) $payload['content']) > 2000) throw new HttpException('评论正文不能超过 2000 个字符', 0, 422);
        $id = Database::transaction(static function () use ($user, $resource, $request, $payload): int {
            $id = Database::insert(
                'INSERT INTO resource_comments
                 (admin_id, app_id, resource_id, user_id, parent_id, content, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $resource['id'], (int) $user['id'],
                    (int) $request->input('parent_id', 0) ?: null, (string) $payload['content'],
                ]
            );
            MessageMediaService::save('resource_comment', $id, $payload);
            return $id;
        });
        $receiverId = (int) ($resource['user_id'] ?? 0);
        $parentId = (int) $request->input('parent_id', 0);
        if ($parentId > 0) {
            $parent = Database::one('SELECT user_id FROM resource_comments WHERE id = ? AND resource_id = ? AND status = 1', [$parentId, (int) $resource['id']]);
            if ($parent !== null) $receiverId = (int) $parent['user_id'];
        }
        if ($receiverId > 0 && $receiverId !== (int) $user['id']) {
            $receiver = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], $receiverId);
            if ($receiver !== null) NotificationService::send(
                $receiver, $parentId > 0 ? 'resource_reply' : 'resource_comment',
                $parentId > 0 ? '资源评论收到回复' : '资源收到新评论',
                '《' . (string) $resource['title'] . '》有了新的互动',
                ['resource_id' => (int) $resource['id'], 'comment_id' => $id, 'user_id' => (int) $user['id']]
            );
        }
        return Response::success(['comment_id' => $id], '评论成功', 201);
    }

    public static function rating(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'resources');
        $resource = self::resource((int) $user['app_id'], (int) $params['resource_id']);
        $score = Validator::integer($request->input('score'), 'score', 1, 5);
        Database::execute(
            'INSERT INTO resource_ratings (admin_id, app_id, resource_id, user_id, score, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = NOW()',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $resource['id'], (int) $user['id'], $score]
        );
        if ((int) ($resource['user_id'] ?? 0) > 0 && (int) $resource['user_id'] !== (int) $user['id']) {
            $receiver = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], (int) $resource['user_id']);
            if ($receiver !== null) NotificationService::send(
                $receiver, 'resource_rating', '资源收到评分', '《' . (string) $resource['title'] . '》收到 ' . $score . ' 星评分',
                ['resource_id' => (int) $resource['id'], 'score' => $score, 'user_id' => (int) $user['id']]
            );
        }
        return Response::success(['score' => $score], '评分成功');
    }

    public static function resourceReaction(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'resources');
        $resource = self::resource((int) $user['app_id'], (int) $params['resource_id']);
        $type = trim((string) $request->input('reaction_type', 'like'));
        $active = self::toggleReaction('resource_reactions', 'resource_id', (int) $resource['id'], (int) $user['id'], $type);
        if ($active && (int) ($resource['user_id'] ?? 0) > 0 && (int) $resource['user_id'] !== (int) $user['id']) {
            $receiver = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], (int) $resource['user_id']);
            if ($receiver !== null) NotificationService::send(
                $receiver, 'resource_' . $type, $type === 'favorite' ? '资源被收藏' : '资源收到点赞',
                '《' . (string) $resource['title'] . '》收到新的互动',
                ['resource_id' => (int) $resource['id'], 'reaction_type' => $type, 'user_id' => (int) $user['id']]
            );
        }
        return Response::success(['reaction_type' => $type, 'active' => $active], $active ? '操作成功' : '已取消');
    }

    public static function favoriteResources(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'resources');
        $items = Database::all(
            "SELECT r.*, c.name AS category_name, rr.created_at AS favorited_at
             FROM resource_reactions rr INNER JOIN resources r ON r.id = rr.resource_id
             LEFT JOIN resource_categories c ON c.id = r.category_id
             WHERE rr.user_id = ? AND rr.reaction_type = 'favorite' AND r.app_id = ?
               AND r.audit_status = 'approved' AND r.status = 1 AND r.deleted_at IS NULL
             ORDER BY rr.id DESC",
            [(int) $user['id'], (int) $user['app_id']]
        );
        $items = MessageMediaService::hydrate($items, 'resource', (int) $user['app_id']);
        foreach ($items as &$item) {
            unset($item['download_url']);
            $item['price_balance'] = (int) ($item['price_integral'] ?? 0);
            unset($item['price_integral']);
            $item = SubmissionInspectionService::present($item);
        }
        unset($item);
        return Response::success(['items' => $items]);
    }

    public static function storeCategories(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'store');
        SubmissionInspectionService::seedStoreCategories((int) $user['admin_id'], (int) $user['app_id']);
        return Response::success(['items' => Database::all(
            'SELECT id, name, icon FROM store_categories WHERE app_id = ? AND status = 1 ORDER BY sort_order DESC, id',
            [(int) $user['app_id']]
        )]);
    }

    public static function storeSubmissionPolicy(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'store');
        return Response::success([
            'enabled' => (bool) AppService::setting((int) $user['app_id'], 'store_user_submit_enabled', true),
            'audit_required' => (bool) AppService::setting((int) $user['app_id'], 'store_submit_audit', true),
        ]);
    }

    public static function submitStoreApp(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'store');
        if (!(bool) AppService::setting((int) $user['app_id'], 'store_user_submit_enabled', true)) {
            throw new HttpException('当前应用未开放应用投稿', 403, 403);
        }
        AuthService::ensureNotBanned($user, ['all', 'resource']);
        $data = $request->all();
        Validator::required($data, ['name', 'package_name', 'version_name']);
        $category = SubmissionInspectionService::resolveStoreCategory(
            (int) $user['admin_id'],
            (int) $user['app_id'],
            $data
        );
        $inspection = SubmissionInspectionService::inspectUserUpload($user, $data, 'app_store');
        $packageName = trim((string) $data['package_name']);
        if ($packageName === '' || mb_strlen($packageName) > 190) {
            throw new HttpException('请填写正确的应用包名', 0, 422);
        }
        $versionCode = max(1, (int) ($data['version_code'] ?? 1));
        if (Database::one(
            'SELECT id FROM store_apps WHERE app_id = ? AND package_name = ? AND version_code = ? AND deleted_at IS NULL',
            [(int) $user['app_id'], $packageName, $versionCode]
        ) !== null) {
            throw new HttpException('该包名和版本号已经投稿，请提高版本号后重试', 0, 409);
        }
        $auditRequired = (bool) AppService::setting((int) $user['app_id'], 'store_submit_audit', true)
            || (bool) $inspection['force_audit'];
        $audit = $auditRequired ? 'pending' : 'approved';
        $status = $audit === 'approved' ? 1 : 0;
        $media = $data;
        $media['content'] = (string) ($data['description'] ?? '');
        $payload = MessageMediaService::userPayload($user, $media);
        $id = Database::transaction(static function () use (
            $user,
            $data,
            $payload,
            $packageName,
            $versionCode,
            $status,
            $audit,
            $category,
            $inspection
        ): int {
            $id = Database::insert(
                'INSERT INTO store_apps
                 (admin_id, app_id, category_id, user_id, name, package_name, version_name, version_code,
                  icon_url, apk_url, size_bytes, description, metadata_json, file_sha256, risk_level,
                  risk_reason, source_upload_id, icon_upload_id, audit_status, audit_reason,
                  price_integral, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $category['id'], (int) $user['id'],
                    mb_substr((string) $data['name'], 0, 150), mb_substr($packageName, 0, 190),
                    mb_substr((string) $data['version_name'], 0, 40), $versionCode,
                    $inspection['cover_url'], $inspection['source_url'], (int) $inspection['size_bytes'],
                    (string) $payload['content'], $inspection['metadata_json'],
                    $inspection['file_sha256'], $inspection['risk_level'], $inspection['risk_reason'],
                    $inspection['source_upload_id'], $inspection['cover_upload_id'],
                    $audit, '', max(0, (int) ($data['price_balance'] ?? 0)), $status,
                ]
            );
            MessageMediaService::save('store_app', $id, $payload);
            return $id;
        });
        LogService::userOperation($request, $user, 'store_app', 'submit', $id, [
            'audit_status' => $audit,
            'risk_level' => $inspection['risk_level'],
        ]);
        return Response::success([
            'store_app_id' => $id,
            'category_id' => (int) $category['id'],
            'category_name' => (string) $category['name'],
            'audit_status' => $audit,
            'audit_status_label' => $audit === 'approved' ? '已通过' : '待审核',
            'risk_level' => $inspection['risk_level'],
        ], $audit === 'approved' ? '应用投稿成功' : '应用已提交审核', 201);
    }

    public static function storeApps(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'store');
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['s.app_id = ?', 's.audit_status = ?', 's.status = 1', 's.deleted_at IS NULL'];
        $query = [(int) $user['app_id'], 'approved'];
        if ($request->input('category_id') !== null && $request->input('category_id') !== '') {
            $where[] = 's.category_id = ?';
            $query[] = (int) $request->input('category_id');
        }
        if (trim((string) $request->input('keyword', '')) !== '') {
            $where[] = '(s.name LIKE ? OR s.description LIKE ?)';
            $query[] = '%' . trim((string) $request->input('keyword')) . '%';
            $query[] = '%' . trim((string) $request->input('keyword')) . '%';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM store_apps s WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT s.id, s.category_id, s.name, s.package_name, s.version_name, s.version_code,
                    s.icon_url, s.size_bytes, s.description, s.metadata_json, s.file_sha256,
                    s.risk_level, s.risk_reason, s.audit_status, s.price_integral, s.download_count,
                    c.name AS category_name
             FROM store_apps s LEFT JOIN store_categories c ON c.id = s.category_id
             WHERE {$whereSql} ORDER BY s.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = MessageMediaService::hydrate($items, 'store_app', (int) $user['app_id']);
        foreach ($items as &$item) {
            $item['price_balance'] = (int) ($item['price_integral'] ?? 0);
            unset($item['price_integral']);
            $item = SubmissionInspectionService::present($item);
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function showStoreApp(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'store');
        $app = Database::one(
            'SELECT * FROM store_apps
             WHERE id = ? AND app_id = ? AND audit_status = ? AND status = 1 AND deleted_at IS NULL',
            [(int) $params['store_app_id'], (int) $user['app_id'], 'approved']
        );
        if ($app === null) {
            throw new HttpException('商店应用不存在', 404, 404);
        }
        $canDownload = (int) ($app['price_integral'] ?? 0) === 0
            || (int) ($app['user_id'] ?? 0) === (int) $user['id'];
        if (!$canDownload) {
            unset($app['apk_url']);
        }
        $app['can_download'] = $canDownload;
        $app['price_balance'] = (int) ($app['price_integral'] ?? 0);
        unset($app['price_integral']);
        $app['images'] = Database::all('SELECT image_url, sort_order FROM store_app_images WHERE store_app_id = ? ORDER BY sort_order', [(int) $app['id']]);
        $app = MessageMediaService::hydrate([$app], 'store_app', (int) $user['app_id'])[0];
        $app['liked'] = Database::one('SELECT id FROM store_app_reactions WHERE store_app_id = ? AND user_id = ? AND reaction_type = ?', [(int) $app['id'], (int) $user['id'], 'like']) !== null;
        $app['favorited'] = Database::one('SELECT id FROM store_app_reactions WHERE store_app_id = ? AND user_id = ? AND reaction_type = ?', [(int) $app['id'], (int) $user['id'], 'favorite']) !== null;
        $app = SubmissionInspectionService::present($app);
        return Response::success(['store_app' => $app]);
    }

    public static function storeReaction(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'store');
        $storeAppId = (int) $params['store_app_id'];
        if (Database::one(
            'SELECT id FROM store_apps
             WHERE id = ? AND app_id = ? AND audit_status = ? AND status = 1 AND deleted_at IS NULL',
            [$storeAppId, (int) $user['app_id'], 'approved']
        ) === null) throw new HttpException('商店应用不存在', 404, 404);
        $type = trim((string) $request->input('reaction_type', 'like'));
        $active = self::toggleReaction('store_app_reactions', 'store_app_id', $storeAppId, (int) $user['id'], $type);
        return Response::success(['reaction_type' => $type, 'active' => $active], $active ? '操作成功' : '已取消');
    }

    public static function favoriteStoreApps(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'store');
        $items = Database::all(
            "SELECT s.*, r.created_at AS favorited_at FROM store_app_reactions r
             INNER JOIN store_apps s ON s.id = r.store_app_id
             WHERE r.user_id = ? AND r.reaction_type = 'favorite' AND s.app_id = ?
               AND s.audit_status = 'approved' AND s.status = 1 AND s.deleted_at IS NULL
             ORDER BY r.id DESC",
            [(int) $user['id'], (int) $user['app_id']]
        );
        $items = MessageMediaService::hydrate($items, 'store_app', (int) $user['app_id']);
        foreach ($items as &$item) {
            unset($item['apk_url']);
            $item['price_balance'] = (int) ($item['price_integral'] ?? 0);
            unset($item['price_integral']);
            $item = SubmissionInspectionService::present($item);
        }
        unset($item);
        return Response::success(['items' => $items]);
    }

    private static function user(Request $request, string $feature): array
    {
        $user = AuthService::user($request);
        AppService::requireFeature((int) $user['app_id'], $feature);
        return $user;
    }

    private static function resource(int $appId, int $resourceId): array
    {
        $resource = Database::one(
            'SELECT r.*, c.name AS category_name, p.nickname
             FROM resources r INNER JOIN resource_categories c ON c.id = r.category_id
             LEFT JOIN user_profiles p ON p.user_id = r.user_id
             WHERE r.id = ? AND r.app_id = ? AND r.audit_status = ? AND r.status = 1 AND r.deleted_at IS NULL',
            [$resourceId, $appId, 'approved']
        );
        if ($resource === null) {
            throw new HttpException('资源不存在', 404, 404);
        }
        return $resource;
    }

    private static function toggleReaction(string $table, string $targetColumn, int $targetId, int $userId, string $type): bool
    {
        if (!in_array($type, ['like', 'favorite'], true)) throw new HttpException('reaction_type 仅支持 like 或 favorite', 0, 422);
        return Database::transaction(static function () use ($table, $targetColumn, $targetId, $userId, $type): bool {
            $existing = Database::one("SELECT id FROM {$table} WHERE {$targetColumn} = ? AND user_id = ? AND reaction_type = ?", [$targetId, $userId, $type]);
            if ($existing !== null) { Database::execute("DELETE FROM {$table} WHERE id = ?", [(int) $existing['id']]); return false; }
            Database::execute("INSERT INTO {$table} ({$targetColumn}, user_id, reaction_type, created_at) VALUES (?, ?, ?, NOW())", [$targetId, $userId, $type]); return true;
        });
    }
}
