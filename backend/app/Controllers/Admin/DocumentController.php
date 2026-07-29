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

final class DocumentController
{
    public static function index(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['d.admin_id = ?', 'd.app_id = ?'];
        $queryParams = [(int) $admin['id'], $appId];
        if ($request->input('status') !== null && $request->input('status') !== '') {
            $where[] = 'd.status = ?';
            $queryParams[] = (int) $request->input('status');
        }
        if ($request->input('user_id') !== null && $request->input('user_id') !== '') {
            $where[] = 'd.user_id = ?';
            $queryParams[] = (int) $request->input('user_id');
        }
        if ($request->input('owner_type') !== null && $request->input('owner_type') !== '') {
            $ownerType = strtolower(trim((string) $request->input('owner_type')));
            if (!in_array($ownerType, ['admin', 'user'], true)) {
                throw new HttpException('owner_type 仅支持 admin 或 user', 0, 422);
            }
            $where[] = 'd.owner_type = ?';
            $queryParams[] = $ownerType;
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(d.title LIKE ? OR COALESCE(u.account, ?) LIKE ?)';
            $queryParams[] = '%' . $keyword . '%';
            $queryParams[] = (string) $admin['account'];
            $queryParams[] = '%' . $keyword . '%';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM documents d LEFT JOIN users u ON u.id = d.user_id WHERE {$whereSql}",
            $queryParams
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT d.id, d.user_id, d.owner_type,
                    COALESCE(u.account, ?) AS account,
                    COALESCE(NULLIF(p.nickname, ''), ?) AS nickname,
                    d.title, d.content_type, d.word_count,
                    d.is_public, d.status, d.version_no, d.created_at, d.updated_at, d.deleted_at
             FROM documents d
             LEFT JOIN users u ON u.id = d.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE {$whereSql}
             ORDER BY d.id DESC LIMIT {$limit} OFFSET {$offset}",
            array_merge([(string) $admin['account'], (string) ($admin['nickname'] ?? '管理员')], $queryParams)
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function create(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $title = Validator::string($request->input('title', ''), 'title', 1, 200);
        $content = (string) $request->input('content', '');
        self::validateContent($content);
        $contentType = self::contentType($request->input('content_type', 'text'));
        $isPublic = Validator::boolean($request->input('is_public', false), 'is_public') ? 1 : 0;
        $wordCount = self::wordCount($content);
        $documentId = Database::transaction(static function () use ($admin, $appId, $title, $content, $contentType, $isPublic, $wordCount): int {
            $id = Database::insert(
                'INSERT INTO documents
                 (admin_id, app_id, user_id, owner_type, title, content_type, content, word_count, is_public, status, version_no, created_at, updated_at)
                 VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, 1, 1, NOW(), NOW())',
                [(int) $admin['id'], $appId, 'admin', $title, $contentType, $content, $wordCount, $isPublic]
            );
            Database::execute(
                'INSERT INTO document_versions
                 (admin_id, app_id, document_id, user_id, owner_type, title, content_type, content, word_count, version_no, created_at)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, 1, NOW())',
                [(int) $admin['id'], $appId, $id, 'admin', $title, $contentType, $content, $wordCount]
            );
            return $id;
        });
        $document = self::find((int) $admin['id'], $appId, $documentId);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'document', 'create', $documentId, null, $document);
        LogService::increment((int) $admin['id'], $appId, 'document_created');
        return Response::success(['document' => $document], '管理员文档创建成功', 201);
    }

    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $document = self::find((int) $admin['id'], $appId, (int) $params['document_id']);
        $document['versions'] = Database::all(
            'SELECT id, version_no, title, word_count, created_at FROM document_versions
             WHERE admin_id = ? AND app_id = ? AND document_id = ? ORDER BY version_no DESC',
            [(int) $admin['id'], $appId, (int) $document['id']]
        );
        return Response::success(['document' => $document]);
    }

    public static function update(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $documentId = (int) $params['document_id'];
        $before = self::find((int) $admin['id'], $appId, $documentId);
        if (!array_key_exists('title', $request->all()) && !array_key_exists('content', $request->all())
            && !array_key_exists('content_type', $request->all()) && !array_key_exists('is_public', $request->all())) {
            throw new HttpException('没有可修改的文档字段', 0, 422);
        }
        $title = array_key_exists('title', $request->all())
            ? Validator::string($request->input('title'), 'title', 1, 200) : (string) $before['title'];
        $content = array_key_exists('content', $request->all()) ? (string) $request->input('content') : (string) $before['content'];
        self::validateContent($content);
        $contentType = array_key_exists('content_type', $request->all())
            ? self::contentType($request->input('content_type')) : (string) $before['content_type'];
        $isPublic = array_key_exists('is_public', $request->all())
            ? (Validator::boolean($request->input('is_public'), 'is_public') ? 1 : 0) : (int) $before['is_public'];
        $wordCount = self::wordCount($content);
        $nextVersion = (int) $before['version_no'] + 1;
        Database::transaction(static function () use ($admin, $appId, $documentId, $before, $title, $content, $contentType, $isPublic, $wordCount, $nextVersion): void {
            $locked = Database::one(
                'SELECT id FROM documents WHERE id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [$documentId, (int) $admin['id'], $appId]
            );
            if ($locked === null) throw new HttpException('文档不存在', 404, 404);
            Database::execute(
                'UPDATE documents SET title = ?, content = ?, content_type = ?, word_count = ?, is_public = ?,
                 version_no = ?, status = 1, deleted_at = NULL, updated_at = NOW() WHERE id = ?',
                [$title, $content, $contentType, $wordCount, $isPublic, $nextVersion, $documentId]
            );
            Database::execute(
                'INSERT INTO document_versions
                 (admin_id, app_id, document_id, user_id, owner_type, title, content_type, content, word_count, version_no, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    (int) $admin['id'], $appId, $documentId,
                    $before['user_id'] === null ? null : (int) $before['user_id'],
                    (string) ($before['owner_type'] ?? 'user'), $title, $contentType, $content, $wordCount, $nextVersion,
                ]
            );
        });
        $after = self::find((int) $admin['id'], $appId, $documentId);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'document', 'update', $documentId, $before, $after);
        LogService::increment((int) $admin['id'], $appId, 'document_updated');
        return Response::success(['document' => $after], '文档修改成功');
    }

    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $document = self::find((int) $admin['id'], $appId, (int) $params['document_id']);
        Database::execute(
            'UPDATE documents SET status = -1, deleted_at = NOW(), updated_at = NOW()
             WHERE id = ? AND admin_id = ? AND app_id = ?',
            [(int) $document['id'], (int) $admin['id'], $appId]
        );
        LogService::adminOperation(
            $request,
            (int) $admin['id'],
            $appId,
            'document',
            'delete',
            (int) $document['id'],
            $document,
            ['reason' => mb_substr((string) $request->input('reason', ''), 0, 255)]
        );
        LogService::increment((int) $admin['id'], $appId, 'document_deleted');
        return Response::success([], '文档已删除');
    }

    public static function restore(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $document = self::find((int) $admin['id'], $appId, (int) $params['document_id']);
        Database::execute(
            'UPDATE documents SET status = 1, deleted_at = NULL, updated_at = NOW()
             WHERE id = ? AND admin_id = ? AND app_id = ?',
            [(int) $document['id'], (int) $admin['id'], $appId]
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'document', 'restore', (int) $document['id']);
        return Response::success([], '文档已恢复');
    }

    public static function shares(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM document_shares WHERE admin_id = ? AND app_id = ?',
            [(int) $admin['id'], $appId]
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT s.id, s.document_id, s.user_id, s.share_code, s.expired_at, s.view_count,
                    s.status, s.created_at, d.title, u.account
             FROM document_shares s INNER JOIN documents d ON d.id = s.document_id
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.admin_id = ? AND s.app_id = ? ORDER BY s.id DESC LIMIT {$limit} OFFSET {$offset}",
            [(int) $admin['id'], $appId]
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function rules(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $map = [
            'default_credit' => 'initial_document_credit',
            'create_cost' => 'document_create_cost',
            'share_enabled' => 'document_share_enabled',
            'max_count' => 'document_max_count',
        ];
        $settings = [];
        foreach ($map as $input => $key) {
            if (array_key_exists($input, $request->all())) {
                $settings[$key] = $input === 'share_enabled'
                    ? Validator::boolean($request->input($input), $input)
                    : max(0, (int) $request->input($input));
            }
        }
        if ($settings === []) {
            throw new HttpException('没有可保存的文档规则', 0, 422);
        }
        $after = AppService::saveSettings((int) $admin['id'], $appId, $settings);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'document', 'rules_update', $appId, null, $settings);
        return Response::success(['settings' => $after], '文档规则保存成功');
    }

    private static function find(int $adminId, int $appId, int $documentId): array
    {
        $document = Database::one(
            'SELECT d.*, COALESCE(u.account, a.account) AS account,
                    COALESCE(p.nickname, a.nickname, a.account) AS nickname
             FROM documents d
             INNER JOIN admins a ON a.id = d.admin_id
             LEFT JOIN users u ON u.id = d.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE d.id = ? AND d.admin_id = ? AND d.app_id = ?',
            [$documentId, $adminId, $appId]
        );
        if ($document === null) {
            throw new HttpException('文档不存在', 404, 404);
        }
        return $document;
    }

    private static function contentType($value): string
    {
        $type = strtolower(trim((string) $value));
        if (!in_array($type, ['text', 'markdown', 'html'], true)) {
            throw new HttpException('content_type 仅支持 text、markdown、html', 0, 422);
        }
        return $type;
    }

    private static function validateContent(string $content): void
    {
        if (strlen($content) > 2 * 1024 * 1024) {
            throw new HttpException('单篇文档不能超过 2 MB', 0, 422);
        }
    }

    private static function wordCount(string $content): int
    {
        return mb_strlen(trim(strip_tags($content)));
    }
}
