<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Validator;

final class LevelForumService
{
    private const CATEGORIES = ['general', 'technology', 'help', 'share', 'communication'];
    public static function platformActor(array $platform): array
    {
        return [
            'type' => 'platform',
            'id' => (int) $platform['id'],
            'level' => (int) $platform['level'],
            'name' => (string) ($platform['nickname'] ?: $platform['account']),
            'root_platform_id' => (int) $platform['level'] === 1 ? (int) $platform['id'] : (int) $platform['parent_id'],
            'scope_platform_id' => (int) $platform['id'],
        ];
    }

    public static function adminActor(array $admin): array
    {
        $platform = Database::one('SELECT id, parent_id, level FROM platform_accounts WHERE id = ?', [(int) $admin['platform_id']]);
        if ($platform === null) throw new HttpException('管理员所属平台不存在', 404, 404);
        return [
            'type' => 'admin',
            'id' => (int) $admin['id'],
            'level' => 3,
            'name' => (string) ($admin['nickname'] ?: $admin['account']),
            'root_platform_id' => (int) $platform['level'] === 1 ? (int) $platform['id'] : (int) $platform['parent_id'],
            'scope_platform_id' => (int) $platform['id'],
        ];
    }

    public static function feed(Request $request, array $actor): array
    {
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['p.root_platform_id = ?', 'p.status = 1', 'p.deleted_at IS NULL'];
        $query = [(int) $actor['root_platform_id']];
        $moderation = self::boolValue($request->input('moderation', false));
        if (!$moderation || (int) $actor['level'] === 3) {
            $where[] = '(p.target_level = 0 OR p.target_level = ?)';
            $query[] = (int) $actor['level'];
            $where[] = '(p.scope_platform_id IS NULL OR p.scope_platform_id = ?)';
            $query[] = (int) $actor['scope_platform_id'];
        } elseif ((int) $actor['level'] === 2) {
            $where[] = '(p.target_level = 2 OR (p.target_level IN (3, 4) AND p.scope_platform_id = ?))';
            $query[] = (int) $actor['scope_platform_id'];
        }
        if (trim((string) $request->input('keyword', '')) !== '') {
            $where[] = '(p.title LIKE ? OR p.content LIKE ? OR p.author_name LIKE ?)';
            $keyword = '%' . trim((string) $request->input('keyword')) . '%';
            array_push($query, $keyword, $keyword, $keyword);
        }
        if ($request->input('target_level') !== null && $request->input('target_level') !== '') {
            $where[] = 'p.target_level = ?';
            $query[] = (int) $request->input('target_level');
        }
        $category = trim((string) $request->input('category_code', ''));
        if ($category !== '') {
            if (!in_array($category, self::CATEGORIES, true)) throw new HttpException('交流分类不支持', 0, 422);
            $where[] = 'p.category_code = ?';
            $query[] = $category;
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM level_forum_posts p WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT p.*,
                    EXISTS(SELECT 1 FROM level_forum_reactions r WHERE r.post_id = p.id AND r.actor_type = ? AND r.actor_id = ? AND r.reaction_type = 'like') AS liked,
                    EXISTS(SELECT 1 FROM level_forum_reactions r WHERE r.post_id = p.id AND r.actor_type = ? AND r.actor_id = ? AND r.reaction_type = 'favorite') AS favorited
             FROM level_forum_posts p WHERE {$whereSql}
             ORDER BY p.is_top DESC, p.id DESC LIMIT {$limit} OFFSET {$offset}",
            array_merge([(string) $actor['type'], (int) $actor['id'], (string) $actor['type'], (int) $actor['id']], $query)
        );
        foreach ($items as &$item) $item['category_name'] = self::categoryName((string) ($item['category_code'] ?? 'general'));
        unset($item);
        return Pagination::data($items, $total, $page, $limit);
    }

    public static function show(array $actor, int $postId): array
    {
        $post = self::visiblePost($actor, $postId);
        $post['category_name'] = self::categoryName((string) ($post['category_code'] ?? 'general'));
        $post['attachments'] = json_decode((string) ($post['attachments_json'] ?? ''), true) ?: [];
        unset($post['attachments_json']);
        $post['comments'] = Database::all(
            'SELECT * FROM level_forum_comments WHERE post_id = ? AND status = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1000',
            [$postId]
        );
        foreach (['like', 'favorite'] as $reaction) {
            $post[$reaction === 'like' ? 'liked' : 'favorited'] = Database::one(
                'SELECT id FROM level_forum_reactions WHERE post_id = ? AND actor_type = ? AND actor_id = ? AND reaction_type = ?',
                [$postId, (string) $actor['type'], (int) $actor['id'], $reaction]
            ) !== null;
        }
        return $post;
    }

    public static function create(array $actor, array $data): int
    {
        $targetLevel = isset($data['target_level']) ? (int) $data['target_level'] : (int) $actor['level'];
        $scopePlatformId = null;
        $appId = isset($data['app_id']) && $data['app_id'] !== '' ? (int) $data['app_id'] : null;
        if ((int) $actor['level'] === 1) {
            if (!in_array($targetLevel, [0, 1, 2, 3, 4], true)) throw new HttpException('target_level 不支持', 0, 422);
            if (isset($data['scope_platform_id']) && $data['scope_platform_id'] !== '') {
                $scopePlatformId = (int) $data['scope_platform_id'];
                if ($scopePlatformId !== (int) $actor['id']) PlatformService::ownedOperator(self::platformRow($actor), $scopePlatformId);
            }
        } elseif ((int) $actor['level'] === 2) {
            if (!in_array($targetLevel, [2, 3, 4], true)) throw new HttpException('2 级平台只能向 2/3/4 级发布', 403, 403);
            $scopePlatformId = $targetLevel === 2 ? null : (int) $actor['scope_platform_id'];
        } else {
            if ($targetLevel !== 3) throw new HttpException('admin 只能在 3 级交流区发布', 403, 403);
            $scopePlatformId = (int) $actor['scope_platform_id'];
        }
        if ($appId !== null) {
            if ((int) $actor['level'] === 3) {
                if (Database::one('SELECT id FROM apps WHERE id = ? AND admin_id = ? AND deleted_at IS NULL', [$appId, (int) $actor['id']]) === null) {
                    throw new HttpException('应用不属于当前 admin', 403, 403);
                }
            } else {
                PlatformService::ownedApp(self::platformRow($actor), $appId);
            }
        }
        $title = Validator::string($data['title'] ?? '', 'title', 1, 200);
        $content = Validator::string($data['content'] ?? '', 'content', 1, 50000);
        $category = trim((string) ($data['category_code'] ?? 'general'));
        if (!in_array($category, self::CATEGORIES, true)) throw new HttpException('交流分类不支持', 0, 422);
        return Database::insert(
            'INSERT INTO level_forum_posts
             (root_platform_id, scope_platform_id, app_id, target_level, author_type, author_id, author_name,
              category_code, title, content, attachments_json, is_top, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, NOW(), NOW())',
            [
                (int) $actor['root_platform_id'], $scopePlatformId, $appId, $targetLevel,
                (string) $actor['type'], (int) $actor['id'], (string) $actor['name'], $category, $title, $content,
                json_encode((array) ($data['attachments'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    public static function comment(array $actor, int $postId, array $data): int
    {
        self::visiblePost($actor, $postId);
        $content = Validator::string($data['content'] ?? '', 'content', 1, 5000);
        $parentId = isset($data['parent_id']) && (int) $data['parent_id'] > 0 ? (int) $data['parent_id'] : null;
        if ($parentId !== null && Database::one('SELECT id FROM level_forum_comments WHERE id = ? AND post_id = ? AND status = 1', [$parentId, $postId]) === null) {
            throw new HttpException('被回复的评论不存在', 404, 404);
        }
        return Database::transaction(static function () use ($actor, $postId, $parentId, $content): int {
            $id = Database::insert(
                'INSERT INTO level_forum_comments (post_id, parent_id, author_type, author_id, author_name, content, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, NOW())',
                [$postId, $parentId, (string) $actor['type'], (int) $actor['id'], (string) $actor['name'], $content]
            );
            Database::execute('UPDATE level_forum_posts SET comment_count = comment_count + 1 WHERE id = ?', [$postId]);
            return $id;
        });
    }

    public static function reaction(array $actor, int $postId, string $reaction): bool
    {
        self::visiblePost($actor, $postId);
        if (!in_array($reaction, ['like', 'favorite'], true)) throw new HttpException('reaction_type 不支持', 0, 422);
        return Database::transaction(static function () use ($actor, $postId, $reaction): bool {
            $existing = Database::one(
                'SELECT id FROM level_forum_reactions WHERE post_id = ? AND actor_type = ? AND actor_id = ? AND reaction_type = ?',
                [$postId, (string) $actor['type'], (int) $actor['id'], $reaction]
            );
            $column = $reaction === 'like' ? 'like_count' : 'favorite_count';
            if ($existing !== null) {
                Database::execute('DELETE FROM level_forum_reactions WHERE id = ?', [(int) $existing['id']]);
                Database::execute("UPDATE level_forum_posts SET {$column} = GREATEST(0, {$column} - 1) WHERE id = ?", [$postId]);
                return false;
            }
            Database::execute(
                'INSERT INTO level_forum_reactions (post_id, actor_type, actor_id, reaction_type, created_at) VALUES (?, ?, ?, ?, NOW())',
                [$postId, (string) $actor['type'], (int) $actor['id'], $reaction]
            );
            Database::execute("UPDATE level_forum_posts SET {$column} = {$column} + 1 WHERE id = ?", [$postId]);
            return true;
        });
    }

    public static function pin(array $actor, int $postId, $pinned): bool
    {
        $post = self::visiblePost($actor, $postId, true);
        if ((string) $actor['type'] !== 'admin' || (int) $actor['level'] !== 3
            || (int) ($post['scope_platform_id'] ?? 0) !== (int) $actor['scope_platform_id']) {
            throw new HttpException('无权置顶该交流帖子', 403, 403);
        }
        $value = self::boolValue($pinned);
        Database::execute('UPDATE level_forum_posts SET is_top = ?, updated_at = NOW() WHERE id = ?', [$value ? 1 : 0, $postId]);
        return $value;
    }

    public static function report(array $actor, int $postId, array $data): int
    {
        self::visiblePost($actor, $postId);
        $reason = Validator::string($data['reason'] ?? '', 'reason', 2, 500);
        $existing = Database::one(
            'SELECT id FROM level_forum_reports WHERE post_id = ? AND reporter_type = ? AND reporter_id = ? AND status IN (?, ?) LIMIT 1',
            [$postId, (string) $actor['type'], (int) $actor['id'], 'pending', 'processing']
        );
        if ($existing !== null) return (int) $existing['id'];
        return Database::insert(
            'INSERT INTO level_forum_reports
             (post_id, reporter_type, reporter_id, reason, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [$postId, (string) $actor['type'], (int) $actor['id'], $reason, 'pending']
        );
    }

    public static function delete(array $actor, int $postId): void
    {
        $post = self::visiblePost($actor, $postId, true);
        $allowed = (string) $post['author_type'] === (string) $actor['type'] && (int) $post['author_id'] === (int) $actor['id'];
        if ((int) $actor['level'] === 1) $allowed = true;
        if ((int) $actor['level'] === 2 && (int) ($post['scope_platform_id'] ?? 0) === (int) $actor['scope_platform_id']) $allowed = true;
        if (!$allowed) throw new HttpException('无权删除该交流帖子', 403, 403);
        Database::execute('UPDATE level_forum_posts SET status = -1, deleted_at = NOW(), updated_at = NOW() WHERE id = ?', [$postId]);
    }

    private static function visiblePost(array $actor, int $postId, bool $moderation = false): array
    {
        $post = Database::one('SELECT * FROM level_forum_posts WHERE id = ? AND root_platform_id = ? AND status = 1 AND deleted_at IS NULL', [
            $postId, (int) $actor['root_platform_id'],
        ]);
        if ($post === null) throw new HttpException('交流帖子不存在', 404, 404);
        if ($moderation && (int) $actor['level'] === 1) return $post;
        if ((int) $actor['level'] === 2 && $moderation
            && ((int) $post['target_level'] === 2 || (int) ($post['scope_platform_id'] ?? 0) === (int) $actor['scope_platform_id'])) return $post;
        if (!in_array((int) $post['target_level'], [0, (int) $actor['level']], true)
            || ($post['scope_platform_id'] !== null && (int) $post['scope_platform_id'] !== (int) $actor['scope_platform_id'])) {
            throw new HttpException('该帖子不在当前等级或分支的可见范围', 403, 403);
        }
        return $post;
    }

    private static function platformRow(array $actor): array
    {
        return Database::one('SELECT * FROM platform_accounts WHERE id = ?', [(int) $actor['scope_platform_id']]) ?? $actor;
    }

    private static function boolValue($value): bool
    {
        if (is_bool($value)) return $value;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function categoryName(string $code): string
    {
        return [
            'general' => '综合类', 'technology' => '技术类', 'help' => '求助类',
            'share' => '分享类', 'communication' => '交流类',
        ][$code] ?? '综合类';
    }
}
