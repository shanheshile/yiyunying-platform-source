<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;

final class AiKnowledgeManagementService
{
    private const SCOPES = ['global', 'platform', 'admin', 'app'];

    public static function platformIndex(array $actor, Request $request): array
    {
        PlatformService::requireCapability($actor, 'ai.manage');
        $where = [];
        $query = [];
        if ((int) $actor['level'] === 1) {
            $where[] = 'k.root_platform_id = ?';
            $query[] = (int) $actor['id'];
        } else {
            $where[] = 'k.root_platform_id = ?';
            $query[] = (int) $actor['parent_id'];
            $where[] = "(k.scope_type = 'global' OR k.platform_id = ?)";
            $query[] = (int) $actor['id'];
        }
        self::appendFilters($request, $where, $query);
        return self::page($request, $where, $query, $actor);
    }

    public static function platformShow(array $actor, int $documentId): array
    {
        PlatformService::requireCapability($actor, 'ai.manage');
        $document = self::document($documentId);
        self::assertPlatformVisible($actor, $document);
        return self::decorate($document, $actor);
    }

    public static function platformCreate(array $actor, array $data): array
    {
        PlatformService::requireCapability($actor, 'ai.manage');
        $scope = self::platformScope($actor, $data);
        $values = self::values($data);
        $id = self::insert($scope, $values, 'platform', (int) $actor['id']);
        return self::document($id);
    }

    public static function platformUpdate(array $actor, int $documentId, array $data): array
    {
        PlatformService::requireCapability($actor, 'ai.manage');
        $before = self::document($documentId);
        self::assertPlatformManageable($actor, $before);
        $merged = array_merge($before, $data);
        $scope = self::platformScope($actor, $merged);
        $values = self::values($merged);
        self::update($documentId, $scope, $values);
        return self::document($documentId);
    }

    public static function platformDelete(array $actor, int $documentId): array
    {
        PlatformService::requireCapability($actor, 'ai.manage');
        $document = self::document($documentId);
        self::assertPlatformManageable($actor, $document);
        Database::execute('DELETE FROM ai_knowledge_documents WHERE id = ?', [$documentId]);
        return $document;
    }

    public static function adminIndex(array $admin, int $appId, Request $request): array
    {
        AppService::owned((int) $admin['id'], $appId);
        $where = ["k.scope_type = 'app'", 'k.admin_id = ?', 'k.app_id = ?'];
        $query = [(int) $admin['id'], $appId];
        self::appendFilters($request, $where, $query, false);
        return self::page($request, $where, $query);
    }

    public static function adminShow(array $admin, int $appId, int $documentId): array
    {
        AppService::owned((int) $admin['id'], $appId);
        return self::adminDocument($admin, $appId, $documentId);
    }

    public static function adminCreate(array $admin, int $appId, array $data): array
    {
        AppService::owned((int) $admin['id'], $appId);
        $context = GovernanceService::appContext($appId);
        $scope = [
            'root_platform_id' => (int) $context['root_platform_id'],
            'scope_type' => 'app',
            'platform_id' => (int) $context['platform_id'],
            'admin_id' => (int) $admin['id'],
            'app_id' => $appId,
        ];
        $id = self::insert($scope, self::values($data), 'admin', (int) $admin['id']);
        return self::document($id);
    }

    public static function adminUpdate(array $admin, int $appId, int $documentId, array $data): array
    {
        AppService::owned((int) $admin['id'], $appId);
        $before = self::adminDocument($admin, $appId, $documentId);
        $context = GovernanceService::appContext($appId);
        $scope = [
            'root_platform_id' => (int) $context['root_platform_id'],
            'scope_type' => 'app',
            'platform_id' => (int) $context['platform_id'],
            'admin_id' => (int) $admin['id'],
            'app_id' => $appId,
        ];
        self::update($documentId, $scope, self::values(array_merge($before, $data)));
        return self::document($documentId);
    }

    public static function adminDelete(array $admin, int $appId, int $documentId): array
    {
        AppService::owned((int) $admin['id'], $appId);
        $document = self::adminDocument($admin, $appId, $documentId);
        Database::execute('DELETE FROM ai_knowledge_documents WHERE id = ?', [$documentId]);
        return $document;
    }

    private static function page(Request $request, array $where, array $query, ?array $actor = null): array
    {
        $whereSql = implode(' AND ', $where);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM ai_knowledge_documents k WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT k.* FROM ai_knowledge_documents k WHERE {$whereSql}
             ORDER BY k.priority DESC, k.updated_at DESC, k.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        if ($actor !== null) {
            $items = array_map(static fn(array $item): array => self::decorate($item, $actor), $items);
        }
        return Pagination::data($items, $total, $page, $limit);
    }

    private static function appendFilters(Request $request, array &$where, array &$query, bool $allowScope = true): void
    {
        $keyword = trim((string) $request->input('q', ''));
        if ($keyword !== '') {
            $where[] = '(k.title LIKE ? OR k.keywords LIKE ? OR k.content LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($keyword, 0, 100)) . '%';
            array_push($query, $like, $like, $like);
        }
        if ($allowScope) {
            $scope = trim((string) $request->input('scope_type', ''));
            if ($scope !== '') {
                if (!in_array($scope, self::SCOPES, true)) throw new HttpException('知识范围不受支持', 0, 422);
                $where[] = 'k.scope_type = ?';
                $query[] = $scope;
            }
        }
        $status = $request->input('status');
        if ($status !== null && $status !== '') {
            $status = (int) $status;
            if (!in_array($status, [0, 1], true)) throw new HttpException('status 仅支持 0 或 1', 0, 422);
            $where[] = 'k.status = ?';
            $query[] = $status;
        }
    }

    private static function platformScope(array $actor, array $data): array
    {
        $scopeType = trim((string) ($data['scope_type'] ?? 'platform'));
        if (!in_array($scopeType, self::SCOPES, true)) throw new HttpException('知识范围不受支持', 0, 422);
        $rootId = (int) ((int) $actor['level'] === 1 ? $actor['id'] : $actor['parent_id']);
        if ($scopeType === 'global') {
            PlatformService::requireLevelOne($actor);
            return self::scope($rootId, 'global');
        }
        if ($scopeType === 'platform') {
            $platformId = (int) ($data['platform_id'] ?? $actor['id']);
            if ((int) $actor['level'] === 1 && $platformId !== (int) $actor['id']) {
                PlatformService::ownedOperator($actor, $platformId);
            } elseif ((int) $actor['level'] === 2 && $platformId !== (int) $actor['id']) {
                throw new HttpException('授权平台只能维护自己的平台知识', 403, 403);
            }
            return self::scope($rootId, 'platform', $platformId);
        }
        if ($scopeType === 'admin') {
            $adminId = (int) ($data['admin_id'] ?? 0);
            if ($adminId <= 0) throw new HttpException('管理员范围必须选择 admin_id', 0, 422);
            $admin = PlatformService::ownedAdmin($actor, $adminId);
            return self::scope($rootId, 'admin', (int) $admin['platform_id'], $adminId);
        }
        $appId = (int) ($data['app_id'] ?? 0);
        if ($appId <= 0) throw new HttpException('应用范围必须选择 app_id', 0, 422);
        $app = PlatformService::ownedApp($actor, $appId);
        return self::scope($rootId, 'app', (int) $app['platform_id'], (int) $app['admin_id'], $appId);
    }

    private static function scope(int $rootId, string $scopeType, ?int $platformId = null, ?int $adminId = null, ?int $appId = null): array
    {
        return [
            'root_platform_id' => $rootId,
            'scope_type' => $scopeType,
            'platform_id' => $platformId,
            'admin_id' => $adminId,
            'app_id' => $appId,
        ];
    }

    private static function values(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $content = trim((string) ($data['content'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) throw new HttpException('知识标题必须为 1-200 个字符', 0, 422);
        if ($content === '' || mb_strlen($content) > 200000) throw new HttpException('知识正文必须为 1-200000 个字符', 0, 422);
        $keywords = $data['keywords'] ?? '';
        if (is_array($keywords)) $keywords = implode(',', array_map('strval', array_slice($keywords, 0, 100)));
        $keywords = mb_substr(trim((string) $keywords), 0, 1000);
        $sourceUrl = trim((string) ($data['source_url'] ?? ''));
        if ($sourceUrl !== '' && (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            throw new HttpException('知识来源链接必须是有效的 HTTP 或 HTTPS 地址', 0, 422);
        }
        $status = (int) ($data['status'] ?? 1);
        if (!in_array($status, [0, 1], true)) throw new HttpException('status 仅支持 0 或 1', 0, 422);
        return [
            'title' => $title,
            'content' => $content,
            'keywords' => $keywords,
            'source_url' => mb_substr($sourceUrl, 0, 1000),
            'priority' => max(-100000, min(100000, (int) ($data['priority'] ?? 0))),
            'status' => $status,
        ];
    }

    private static function insert(array $scope, array $values, string $creatorType, int $creatorId): int
    {
        return Database::insert(
            'INSERT INTO ai_knowledge_documents
             (root_platform_id, scope_type, platform_id, admin_id, app_id, title, content, keywords,
              source_url, priority, status, created_by_type, created_by_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $scope['root_platform_id'], $scope['scope_type'], $scope['platform_id'], $scope['admin_id'], $scope['app_id'],
                $values['title'], $values['content'], $values['keywords'], $values['source_url'], $values['priority'],
                $values['status'], $creatorType, $creatorId,
            ]
        );
    }

    private static function update(int $documentId, array $scope, array $values): void
    {
        Database::execute(
            'UPDATE ai_knowledge_documents SET root_platform_id = ?, scope_type = ?, platform_id = ?, admin_id = ?,
             app_id = ?, title = ?, content = ?, keywords = ?, source_url = ?, priority = ?, status = ?, updated_at = NOW()
             WHERE id = ?',
            [
                $scope['root_platform_id'], $scope['scope_type'], $scope['platform_id'], $scope['admin_id'], $scope['app_id'],
                $values['title'], $values['content'], $values['keywords'], $values['source_url'], $values['priority'],
                $values['status'], $documentId,
            ]
        );
    }

    private static function document(int $documentId): array
    {
        $document = Database::one('SELECT * FROM ai_knowledge_documents WHERE id = ?', [$documentId]);
        if ($document === null) throw new HttpException('知识条目不存在', 404, 404);
        return $document;
    }

    private static function adminDocument(array $admin, int $appId, int $documentId): array
    {
        $document = Database::one(
            "SELECT * FROM ai_knowledge_documents WHERE id = ? AND scope_type = 'app' AND admin_id = ? AND app_id = ?",
            [$documentId, (int) $admin['id'], $appId]
        );
        if ($document === null) throw new HttpException('知识条目不存在或不属于当前应用', 404, 404);
        return $document;
    }

    private static function assertPlatformVisible(array $actor, array $document): void
    {
        $rootId = (int) ((int) $actor['level'] === 1 ? $actor['id'] : $actor['parent_id']);
        $visible = (int) ($document['root_platform_id'] ?? 0) === $rootId;
        if ((int) $actor['level'] === 2) {
            $visible = $visible && ((string) $document['scope_type'] === 'global'
                || (int) ($document['platform_id'] ?? 0) === (int) $actor['id']);
        }
        if (!$visible) throw new HttpException('知识条目不在当前平台管理范围内', 403, 403);
    }

    private static function assertPlatformManageable(array $actor, array $document): void
    {
        self::assertPlatformVisible($actor, $document);
        if ((int) $actor['level'] === 2 && ((string) $document['scope_type'] === 'global'
            || (int) ($document['platform_id'] ?? 0) !== (int) $actor['id'])) {
            throw new HttpException('上级平台下发的知识只能查看，不能修改', 403, 403);
        }
    }

    private static function decorate(array $document, array $actor): array
    {
        $document['inherited'] = (int) $actor['level'] === 2 && (string) $document['scope_type'] === 'global';
        $document['can_manage'] = (int) $actor['level'] === 1 || !$document['inherited'];
        return $document;
    }
}
