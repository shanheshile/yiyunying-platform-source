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
use Yiyunying\Services\ContentTagService;
use Yiyunying\Services\ForumExperienceService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\MessageMediaService;
use Yiyunying\Services\NotificationService;
use Yiyunying\Services\ProfileAvatarService;
use Yiyunying\Services\RewardRuleService;

final class ForumController
{
    public static function plates(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        return Response::success(['items' => Database::all(
            'SELECT * FROM forum_plates WHERE admin_id = ? AND app_id = ? ORDER BY sort_order DESC, id',
            [(int) $admin['id'], $appId]
        )]);
    }

    public static function createPlate(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $icon = mb_substr(trim((string) $request->input('icon', '')), 0, 500);
        if ($icon !== '') {
            AppService::requireFeature($appId, 'forum_plate_avatar_upload');
        }
        $id = Database::insert(
            'INSERT INTO forum_plates
             (admin_id, app_id, name, icon, description, sort_order, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [
                (int) $admin['id'], $appId, Validator::string($request->input('name', ''), 'name', 1, 100),
                $icon,
                mb_substr((string) $request->input('description', ''), 0, 1000), (int) $request->input('sort_order', 0),
            ]
        );
        return Response::success(['plate_id' => $id], '论坛板块创建成功', 201);
    }

    public static function updatePlate(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['plate_id'];
        $row = Database::one('SELECT * FROM forum_plates WHERE id = ? AND admin_id = ? AND app_id = ?', [$id, (int) $admin['id'], $appId]);
        if ($row === null) {
            throw new HttpException('论坛板块不存在', 404, 404);
        }
        if (array_key_exists('icon', $request->all())) {
            AppService::requireFeature($appId, 'forum_plate_avatar_upload');
        }
        Database::execute(
            'UPDATE forum_plates SET name = ?, icon = ?, description = ?, sort_order = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [
                mb_substr((string) $request->input('name', $row['name']), 0, 100),
                mb_substr((string) $request->input('icon', $row['icon']), 0, 500),
                mb_substr((string) $request->input('description', $row['description']), 0, 1000),
                (int) $request->input('sort_order', $row['sort_order']), (int) $request->input('status', $row['status']), $id,
            ]
        );
        return Response::success([], '论坛板块修改成功');
    }

    public static function plateAvatar(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        AppService::requireFeature($appId, 'forum_plate_avatar_upload');
        $plateId = (int) ($params['plate_id'] ?? 0);
        $plate = Database::one(
            'SELECT id FROM forum_plates WHERE id = ? AND admin_id = ? AND app_id = ?',
            [$plateId, (int) $admin['id'], $appId]
        );
        if ($plate === null) throw new HttpException('论坛板块不存在', 404, 404);
        $result = ProfileAvatarService::upload('forum_plate', $plateId);
        Database::execute(
            'UPDATE forum_plates SET icon = ?, updated_at = NOW() WHERE id = ? AND admin_id = ? AND app_id = ?',
            [(string) $result['avatar'], $plateId, (int) $admin['id'], $appId]
        );
        LogService::adminOperation(
            $request,
            (int) $admin['id'],
            $appId,
            'forum_plate',
            'avatar_update',
            $plateId
        );
        return Response::success(
            $result + ['icon' => (string) $result['avatar'], 'plate_id' => $plateId],
            '板块头像上传成功',
            201
        );
    }

    public static function posts(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['p.admin_id = ?', 'p.app_id = ?'];
        $query = [(int) $admin['id'], $appId];
        foreach (['plate_id', 'category_id', 'status'] as $field) {
            if ($request->input($field) !== null && $request->input($field) !== '') {
                $where[] = "p.{$field} = ?";
                $query[] = (int) $request->input($field);
            }
        }
        if (trim((string) $request->input('audit_status', '')) !== '') {
            $where[] = 'p.audit_status = ?';
            $query[] = trim((string) $request->input('audit_status'));
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(p.title LIKE ? OR p.content LIKE ? OR p.tags_json LIKE ? OR CAST(p.id AS CHAR) LIKE ?)';
            foreach (range(1, 4) as $_) $query[] = '%' . $keyword . '%';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM forum_posts p WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT p.*, fp.name AS plate_name, fc.name AS category_name, u.account, up.nickname
             FROM forum_posts p INNER JOIN forum_plates fp ON fp.id = p.plate_id
             LEFT JOIN forum_categories fc ON fc.id = p.category_id
             INNER JOIN users u ON u.id = p.user_id LEFT JOIN user_profiles up ON up.user_id = p.user_id
             WHERE {$whereSql}
             ORDER BY p.is_top DESC, p.is_essence DESC, p.is_locked DESC,
                      CASE WHEN p.hot_label <> '' THEN 0 ELSE 1 END, p.heat_score DESC, p.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = ContentTagService::hydrate($items);
        $items = MessageMediaService::hydrate($items, 'forum_post', $appId);
        foreach ($items as &$item) {
            $item['audit_status_name'] = self::auditStatusName((string) ($item['audit_status'] ?? 'pending'));
            $item['status_name'] = (int) ($item['status'] ?? 0) === 1 ? '正常' : '已删除';
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function showPost(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $post = Database::one(
            'SELECT p.*, fp.name AS plate_name, u.uid, u.account, up.nickname, up.avatar
             FROM forum_posts p INNER JOIN forum_plates fp ON fp.id = p.plate_id
             INNER JOIN users u ON u.id = p.user_id LEFT JOIN user_profiles up ON up.user_id = p.user_id
             WHERE p.id = ? AND p.admin_id = ? AND p.app_id = ?',
            [(int) $params['post_id'], (int) $admin['id'], $appId]
        );
        if ($post === null) throw new HttpException('帖子不存在', 404, 404);
        $post['images'] = json_decode((string) ($post['images_json'] ?? '[]'), true) ?: [];
        unset($post['images_json']);
        $post['tags'] = ContentTagService::decode($post['tags_json'] ?? null);
        unset($post['tags_json']);
        $post = MessageMediaService::hydrate([$post], 'forum_post', $appId)[0];
        $post['sections'] = ForumExperienceService::sections($post, null, true);
        $post['has_sections'] = $post['sections'] !== [];
        $post['comments'] = Database::all(
            'SELECT c.*, u.uid, u.account, up.nickname, up.avatar
             FROM forum_comments c INNER JOIN users u ON u.id = c.user_id
             LEFT JOIN user_profiles up ON up.user_id = c.user_id
             WHERE c.post_id = ? AND c.app_id = ? ORDER BY c.is_pinned DESC, c.pin_order DESC, c.id ASC LIMIT 2000',
            [(int) $post['id'], $appId]
        );
        $post['comments'] = ContentTagService::hydrate($post['comments']);
        $post['comments'] = MessageMediaService::hydrate($post['comments'], 'forum_comment', $appId);
        $post['paid_rule'] = Database::one(
            'SELECT price_integral AS price_balance, asset_type, preview_content, status, created_at, updated_at
             FROM forum_paid_contents WHERE post_id = ?',
            [(int) $post['id']]
        );
        return Response::success(['post' => $post]);
    }

    public static function updatePost(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $postId = (int) $params['post_id'];
        $post = Database::one(
            'SELECT * FROM forum_posts WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
            [$postId, (int) $admin['id'], $appId]
        );
        if ($post === null) throw new HttpException('帖子不存在', 404, 404);

        $all = $request->all();
        $updates = [];
        $query = [];
        $rightsSensitiveUpdate = array_key_exists('content', $all);
        if (array_key_exists('title', $all)) {
            $updates[] = 'title = ?';
            $query[] = Validator::string($request->input('title'), 'title', 1, 200);
        }
        if (array_key_exists('content', $all)) {
            $updates[] = 'content = ?';
            $query[] = Validator::string($request->input('content'), 'content', 1, 100000);
        }
        if (array_key_exists('plate_id', $all)) {
            $plateId = Validator::integer($request->input('plate_id'), 'plate_id', 1, PHP_INT_MAX);
            if (Database::one('SELECT id FROM forum_plates WHERE id = ? AND admin_id = ? AND app_id = ?', [$plateId, (int) $admin['id'], $appId]) === null) {
                throw new HttpException('论坛板块不存在', 404, 404);
            }
            $updates[] = 'plate_id = ?';
            $query[] = $plateId;
        }
        if (array_key_exists('category_id', $all)) {
            $categoryId = (int) $request->input('category_id', 0);
            if ($categoryId > 0 && Database::one(
                'SELECT id FROM forum_categories WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$categoryId, (int) $admin['id'], $appId]
            ) === null) throw new HttpException('论坛二级分类不存在', 404, 404);
            $updates[] = 'category_id = ?';
            $query[] = $categoryId > 0 ? $categoryId : null;
        }
        if (array_key_exists('tags', $all)) {
            $updates[] = 'tags_json = ?';
            $query[] = ContentTagService::encode($request->input('tags', []));
        }
        if (array_key_exists('status', $all)) {
            $status = Validator::integer($request->input('status'), 'status', 0, 1);
            $updates[] = 'status = ?';
            $query[] = $status;
            $rightsSensitiveUpdate = $rightsSensitiveUpdate || $status !== 1;
        }
        if ($updates === []) throw new HttpException('没有可修改的帖子字段', 0, 422);
        $query[] = $postId;
        $after = Database::transaction(static function () use (
            $admin, $appId, $postId, $updates, $query, $rightsSensitiveUpdate
        ): array {
            $lockedPost = Database::one(
                'SELECT id FROM forum_posts
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL FOR UPDATE',
                [$postId, (int) $admin['id'], $appId]
            );
            if ($lockedPost === null) throw new HttpException('帖子不存在', 404, 404);
            if ($rightsSensitiveUpdate) {
                ForumExperienceService::assertPostPurchaseSafeMutation($postId);
            }
            Database::execute(
                'UPDATE forum_posts SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE id = ?',
                $query
            );
            return Database::one('SELECT * FROM forum_posts WHERE id = ?', [$postId]) ?? [];
        });
        ForumExperienceService::refreshHeat($postId, $appId);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'forum_post', 'update', $postId, $post, $after);
        return Response::success(['post_id' => $postId], '帖子已修改');
    }

    public static function top(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::toggle($request, $params, 'is_top', 'top');
    }

    public static function essence(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::toggle($request, $params, 'is_essence', 'essence');
    }

    public static function lock(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::toggle($request, $params, 'is_locked', 'lock');
    }

    public static function audit(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $status = trim((string) $request->input('audit_status', ''));
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            throw new HttpException('audit_status 格式错误', 0, 422);
        }
        $post = Database::one(
            'SELECT id, user_id, title, content, plate_id, category_id, audit_status
             FROM forum_posts WHERE id = ? AND admin_id = ? AND app_id = ?',
            [(int) $params['post_id'], (int) $admin['id'], $appId]
        );
        if ($post === null) throw new HttpException('帖子不存在', 404, 404);
        $reason = mb_substr(trim((string) $request->input('reason', '')), 0, 500);
        Database::execute(
            'UPDATE forum_posts SET audit_status = ?, audit_reason = ?, audited_by = ?,
             audited_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$status, $reason, (int) $admin['id'], (int) $post['id']]
        );
        $author = NotificationService::user((int) $admin['id'], $appId, (int) $post['user_id']);
        if ($author !== null && $status !== 'pending') {
            NotificationService::send(
                $author,
                'forum_post_audit',
                $status === 'approved' ? '帖子审核通过' : '帖子审核未通过',
                '《' . (string) $post['title'] . '》' . ($reason === '' ? '' : '：' . $reason),
                ['post_id' => (int) $post['id'], 'audit_status' => $status]
            );
        }
        $rewardResult = null;
        if ($author !== null && $status === 'approved' && (string) $post['audit_status'] !== 'approved') {
            $rewardResult = RewardRuleService::trigger(
                $author,
                'forum_post_create',
                'forum_post',
                (int) $post['id'],
                [
                    'approved' => true,
                    'status' => 'approved',
                    'content' => trim((string) $post['title'] . "\n" . (string) $post['content']),
                    'plate_id' => (int) $post['plate_id'],
                    'category_id' => $post['category_id'] === null ? null : (int) $post['category_id'],
                ],
                'admin',
                (int) $admin['id']
            );
        }
        return Response::success(['reward_result' => $rewardResult], '帖子审核状态已更新');
    }

    public static function comments(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['c.admin_id = ?', 'c.app_id = ?'];
        $query = [(int) $admin['id'], $appId];
        foreach (['post_id', 'user_id', 'status'] as $field) {
            if ($request->input($field) !== null && $request->input($field) !== '') {
                $where[] = "c.{$field} = ?";
                $query[] = (int) $request->input($field);
            }
        }
        $auditStatus = trim((string) $request->input('audit_status', ''));
        if ($auditStatus !== '') {
            $where[] = 'c.audit_status = ?';
            $query[] = $auditStatus;
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(c.content LIKE ? OR u.account LIKE ? OR u.uid LIKE ? OR p.nickname LIKE ?)';
            array_push($query, '%' . $keyword . '%', '%' . $keyword . '%', '%' . $keyword . '%', '%' . $keyword . '%');
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM forum_comments c
             INNER JOIN users u ON u.id = c.user_id LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT c.*, fp.title AS post_title, u.uid, u.account, p.nickname, p.avatar
             FROM forum_comments c INNER JOIN forum_posts fp ON fp.id = c.post_id
             INNER JOIN users u ON u.id = c.user_id LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE {$whereSql} ORDER BY c.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = MessageMediaService::hydrate($items, 'forum_comment', $appId);
        foreach ($items as &$item) {
            $item['audit_status_name'] = self::auditStatusName((string) ($item['audit_status'] ?? 'approved'));
            $item['status_name'] = (int) ($item['status'] ?? 0) === 1 ? '正常' : '已删除';
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function auditComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $status = trim((string) $request->input('audit_status', ''));
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            throw new HttpException('audit_status 仅支持 pending、approved 或 rejected', 0, 422);
        }
        $comment = Database::one(
            'SELECT c.*, p.title AS post_title FROM forum_comments c
             INNER JOIN forum_posts p ON p.id = c.post_id
             WHERE c.id = ? AND c.admin_id = ? AND c.app_id = ?',
            [(int) $params['comment_id'], (int) $admin['id'], $appId]
        );
        if ($comment === null) throw new HttpException('评论不存在', 404, 404);
        $reason = mb_substr(trim((string) $request->input('reason', '')), 0, 500);
        Database::transaction(static function () use ($comment, $status, $reason, $admin): void {
            Database::execute(
                'UPDATE forum_comments SET audit_status = ?, audit_reason = ?, audited_by = ?,
                 audited_at = NOW(), updated_at = NOW() WHERE id = ?',
                [$status, $reason, (int) $admin['id'], (int) $comment['id']]
            );
            $wasApproved = (string) $comment['audit_status'] === 'approved' && (int) $comment['status'] === 1;
            $isApproved = $status === 'approved' && (int) $comment['status'] === 1;
            if (!$wasApproved && $isApproved) {
                Database::execute('UPDATE forum_posts SET comment_count = comment_count + 1 WHERE id = ?', [(int) $comment['post_id']]);
            } elseif ($wasApproved && !$isApproved) {
                Database::execute('UPDATE forum_posts SET comment_count = GREATEST(0, comment_count - 1) WHERE id = ?', [(int) $comment['post_id']]);
            }
        });
        $author = NotificationService::user((int) $admin['id'], $appId, (int) $comment['user_id']);
        if ($author !== null && $status !== 'pending') {
            NotificationService::send(
                $author,
                'forum_comment_audit',
                $status === 'approved' ? '评论审核通过' : '评论审核未通过',
                '你在《' . (string) $comment['post_title'] . '》下的评论' . ($reason === '' ? '' : '：' . $reason),
                ['post_id' => (int) $comment['post_id'], 'comment_id' => (int) $comment['id'], 'audit_status' => $status]
            );
        }
        $rewardResult = null;
        if ($author !== null && $status === 'approved' && (string) $comment['audit_status'] !== 'approved') {
            $rewardResult = RewardRuleService::trigger(
                $author,
                (int) ($comment['parent_id'] ?? 0) > 0 ? 'reply_create' : 'comment_create',
                'forum_comment',
                (int) $comment['id'],
                [
                    'approved' => true,
                    'status' => 'approved',
                    'content' => (string) $comment['content'],
                    'post_id' => (int) $comment['post_id'],
                    'parent_id' => (int) ($comment['parent_id'] ?? 0),
                ],
                'admin',
                (int) $admin['id']
            );
        }
        return Response::success(['audit_status' => $status, 'reward_result' => $rewardResult], '评论审核状态已更新');
    }

    public static function deletePost(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $postId = (int) $params['post_id'];
        Database::transaction(static function () use ($admin, $appId, $postId): void {
            $post = Database::one(
                'SELECT id FROM forum_posts
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL FOR UPDATE',
                [$postId, (int) $admin['id'], $appId]
            );
            if ($post === null) throw new HttpException('帖子不存在', 404, 404);
            ForumExperienceService::assertPostPurchaseSafeMutation($postId);
            Database::execute(
                'UPDATE forum_posts SET status = -1, deleted_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$postId, (int) $admin['id'], $appId]
            );
        });
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'forum', 'delete_post', $postId, null, ['reason' => $request->input('reason', '')]);
        return Response::success([], '帖子已删除');
    }

    public static function deleteComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $commentId = (int) $params['comment_id'];
        $comment = Database::one('SELECT * FROM forum_comments WHERE id = ? AND admin_id = ? AND app_id = ?', [$commentId, (int) $admin['id'], $appId]);
        if ($comment === null) {
            throw new HttpException('评论不存在', 404, 404);
        }
        Database::transaction(static function () use ($commentId, $comment): void {
            Database::execute('UPDATE forum_comments SET status = -1, updated_at = NOW() WHERE id = ?', [$commentId]);
            if ((string) ($comment['audit_status'] ?? 'approved') === 'approved' && (int) $comment['status'] === 1) {
                Database::execute('UPDATE forum_posts SET comment_count = GREATEST(0, comment_count - 1) WHERE id = ?', [(int) $comment['post_id']]);
            }
        });
        return Response::success([], '评论已删除');
    }

    public static function reports(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['r.admin_id = ?', 'r.app_id = ?'];
        $query = [(int) $admin['id'], $appId];
        if (trim((string) $request->input('status', '')) !== '') {
            $where[] = 'r.status = ?';
            $query[] = trim((string) $request->input('status'));
        }
        if (trim((string) $request->input('target_type', '')) !== '') {
            $where[] = 'r.target_type = ?';
            $query[] = trim((string) $request->input('target_type'));
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM forum_reports r WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT r.*, u.uid, u.account AS reporter_account, p.nickname AS reporter_nickname,
                    t.name AS report_tag_name,
                    CASE WHEN r.target_type = 'post' THEN post.title ELSE LEFT(comment.content, 120) END AS target_summary
             FROM forum_reports r INNER JOIN users u ON u.id = r.user_id
             LEFT JOIN user_profiles p ON p.user_id = r.user_id
             LEFT JOIN forum_report_tags t ON t.id = r.report_tag_id
             LEFT JOIN forum_posts post ON r.target_type = 'post' AND post.id = r.target_id
             LEFT JOIN forum_comments comment ON r.target_type = 'comment' AND comment.id = r.target_id
             WHERE {$whereSql} ORDER BY r.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $reportStatusNames = [
            'pending' => '待处理', 'handled' => '已处理', 'approved' => '举报成立', 'rejected' => '举报驳回',
        ];
        foreach ($items as &$item) {
            $item['target_type_name'] = $item['target_type'] === 'post' ? '帖子' : '评论';
            $item['status_name'] = $reportStatusNames[$item['status']] ?? '处理中';
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function handleReport(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $status = trim((string) $request->input('status', 'handled'));
        if (!in_array($status, ['pending', 'handled', 'approved', 'rejected'], true)) {
            throw new HttpException('status 仅支持 pending、handled、approved 或 rejected', 0, 422);
        }
        $report = Database::one(
            'SELECT id, user_id, status, reason, target_type, target_id
             FROM forum_reports WHERE id = ? AND admin_id = ? AND app_id = ?',
            [(int) $params['report_id'], (int) $admin['id'], $appId]
        );
        if ($report === null) throw new HttpException('举报记录不存在', 404, 404);
        Database::execute(
            'UPDATE forum_reports SET status = ?, handled_by = ?, handled_at = NOW()
             WHERE id = ?',
            [$status, (int) $admin['id'], (int) $report['id']]
        );
        $rewardResult = null;
        if ($status === 'approved' && (string) $report['status'] !== 'approved') {
            $reporter = NotificationService::user((int) $admin['id'], $appId, (int) $report['user_id']);
            if ($reporter !== null) {
                $rewardResult = RewardRuleService::trigger(
                    $reporter,
                    'valid_report',
                    'forum_report',
                    (int) $report['id'],
                    [
                        'approved' => true,
                        'status' => 'approved',
                        'content' => (string) $report['reason'],
                        'target_type' => (string) $report['target_type'],
                        'target_id' => (int) $report['target_id'],
                    ],
                    'admin',
                    (int) $admin['id']
                );
            }
        }
        return Response::success(['reward_result' => $rewardResult], '举报处理完成');
    }

    public static function reportTags(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        return Response::success(['items' => Database::all(
            'SELECT id, name, description, sort_order, status, created_at, updated_at
             FROM forum_report_tags WHERE admin_id = ? AND app_id = ? ORDER BY sort_order DESC, id ASC',
            [(int) $admin['id'], $appId]
        )]);
    }

    public static function createReportTag(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $name = Validator::string($request->input('name', ''), 'name', 1, 80);
        if (Database::one('SELECT id FROM forum_report_tags WHERE app_id = ? AND name = ?', [$appId, $name])) {
            throw new HttpException('同名举报标签已经存在', 0, 409);
        }
        $id = Database::insert(
            'INSERT INTO forum_report_tags
             (admin_id, app_id, name, description, sort_order, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [
                (int) $admin['id'], $appId, $name,
                mb_substr(trim((string) $request->input('description', '')), 0, 500),
                (int) $request->input('sort_order', 0),
            ]
        );
        return Response::success(['report_tag_id' => $id], '举报标签创建成功', 201);
    }

    public static function updateReportTag(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['tag_id'];
        $tag = Database::one(
            'SELECT * FROM forum_report_tags WHERE id = ? AND admin_id = ? AND app_id = ?',
            [$id, (int) $admin['id'], $appId]
        );
        if ($tag === null) throw new HttpException('举报标签不存在', 404, 404);
        $name = Validator::string($request->input('name', $tag['name']), 'name', 1, 80);
        if (Database::one('SELECT id FROM forum_report_tags WHERE app_id = ? AND name = ? AND id <> ?', [$appId, $name, $id])) {
            throw new HttpException('同名举报标签已经存在', 0, 409);
        }
        $status = Validator::integer($request->input('status', $tag['status']), 'status', 0, 1);
        Database::execute(
            'UPDATE forum_report_tags SET name = ?, description = ?, sort_order = ?, status = ?, updated_at = NOW()
             WHERE id = ?',
            [
                $name, mb_substr(trim((string) $request->input('description', $tag['description'])), 0, 500),
                (int) $request->input('sort_order', $tag['sort_order']), $status, $id,
            ]
        );
        return Response::success(['report_tag_id' => $id, 'status' => $status], '举报标签修改成功');
    }

    public static function deleteReportTag(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['tag_id'];
        $changed = Database::execute(
            'DELETE FROM forum_report_tags WHERE id = ? AND admin_id = ? AND app_id = ?',
            [$id, (int) $admin['id'], $appId]
        );
        if ($changed === 0) throw new HttpException('举报标签不存在', 404, 404);
        return Response::success([], '举报标签已删除');
    }

    private static function toggle(Request $request, array $params, string $column, string $action): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $enabled = Validator::boolean($request->input('enabled', true), 'enabled') ? 1 : 0;
        Database::execute(
            "UPDATE forum_posts SET {$column} = ?, updated_at = NOW() WHERE id = ? AND admin_id = ? AND app_id = ?",
            [$enabled, (int) $params['post_id'], (int) $admin['id'], $appId]
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'forum', $action, (int) $params['post_id'], null, ['enabled' => $enabled]);
        return Response::success(['enabled' => (bool) $enabled], '帖子状态已更新');
    }

    private static function context(Request $request, array $params): array
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        return [$admin, $appId];
    }

    private static function auditStatusName(string $status): string
    {
        return [
            'pending' => '待审核',
            'approved' => '审核通过',
            'rejected' => '审核未通过',
        ][$status] ?? '待审核';
    }
}
