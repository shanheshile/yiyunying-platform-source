<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\MessageMediaService;
use Yiyunying\Services\NotificationService;

final class ContentModerationController
{
    private const AUDIT_STATUSES = ['pending', 'approved', 'rejected'];

    public static function moments(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['m.admin_id = ?', 'm.app_id = ?'];
        $query = [(int) $admin['id'], $appId];
        self::auditFilter($request, 'm.audit_status', $where, $query);
        if ($request->input('status') !== null && $request->input('status') !== '') {
            $where[] = 'm.status = ?';
            $query[] = (int) $request->input('status');
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(m.content LIKE ? OR m.location_name LIKE ? OR u.account LIKE ? OR u.uid LIKE ? OR p.nickname LIKE ?)';
            foreach (range(1, 5) as $_) $query[] = '%' . $keyword . '%';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM user_moments m
             INNER JOIN users u ON u.id = m.user_id
             LEFT JOIN user_profiles p ON p.user_id = m.user_id
             WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT m.*, u.uid, u.account, p.nickname, p.avatar, reviewer.nickname AS reviewer_name,
                    (SELECT COUNT(*) FROM moment_comments c
                     WHERE c.moment_id = m.id AND c.status = 1 AND c.audit_status = 'approved') AS comment_count,
                    (SELECT COUNT(*) FROM moment_comments c
                     WHERE c.moment_id = m.id AND c.status = 1 AND c.audit_status = 'pending') AS pending_comment_count
             FROM user_moments m
             INNER JOIN users u ON u.id = m.user_id
             LEFT JOIN user_profiles p ON p.user_id = m.user_id
             LEFT JOIN admins reviewer ON reviewer.id = m.audited_by
             WHERE {$whereSql}
             ORDER BY CASE m.audit_status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END,
                      m.created_at DESC, m.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = MessageMediaService::hydrate($items, 'moment', $appId);
        foreach ($items as &$item) self::decorate($item);
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function showMoment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $moment = self::moment((int) $admin['id'], $appId, (int) $params['moment_id']);
        $comments = Database::all(
            "SELECT c.*, u.uid, u.account, p.nickname, p.avatar,
                    parent.content AS parent_content, parent_user.account AS parent_account,
                    parent_profile.nickname AS parent_nickname, reviewer.nickname AS reviewer_name
             FROM moment_comments c
             INNER JOIN users u ON u.id = c.user_id
             LEFT JOIN user_profiles p ON p.user_id = c.user_id
             LEFT JOIN moment_comments parent ON parent.id = c.parent_id
             LEFT JOIN users parent_user ON parent_user.id = parent.user_id
             LEFT JOIN user_profiles parent_profile ON parent_profile.user_id = parent.user_id
             LEFT JOIN admins reviewer ON reviewer.id = c.audited_by
             WHERE c.moment_id = ? AND c.admin_id = ? AND c.app_id = ?
             ORDER BY c.id ASC LIMIT 2000",
            [(int) $moment['id'], (int) $admin['id'], $appId]
        );
        $comments = MessageMediaService::hydrate($comments, 'moment_comment', $appId);
        foreach ($comments as &$comment) self::decorate($comment);
        unset($comment);
        $moment['comments'] = $comments;
        $moment['comment_total'] = count($comments);
        return Response::success(['moment' => $moment]);
    }

    public static function auditMoment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $momentId = (int) $params['moment_id'];
        [$status, $reason] = self::decision($request);
        [$before, $after] = Database::transaction(static function () use (
            $request, $admin, $appId, $momentId, $status, $reason
        ): array {
            $before = Database::one(
                'SELECT * FROM user_moments WHERE id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [$momentId, (int) $admin['id'], $appId]
            );
            if ($before === null) throw new HttpException('动态不存在', 404, 404);
            if ((string) $before['audit_status'] === $status) {
                throw new HttpException('该动态已是当前审核状态，请刷新列表', 0, 409, ['audit_status' => $status]);
            }
            if ($status === 'approved' && ((int) $before['status'] !== 1 || $before['deleted_at'] !== null)) {
                throw new HttpException('已停用或已删除的动态不能审核通过', 0, 409);
            }
            Database::execute(
                'UPDATE user_moments SET audit_status = ?, audit_reason = ?, audited_by = ?, audited_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$status, $reason, (int) $admin['id'], $momentId, (int) $admin['id'], $appId]
            );
            if ($status === 'rejected') {
                Database::execute(
                    "UPDATE moment_comments
                     SET audit_status = 'rejected', audit_reason = ?, audited_by = ?, audited_at = NOW(), updated_at = NOW()
                     WHERE moment_id = ? AND admin_id = ? AND app_id = ? AND status = 1
                       AND audit_status <> 'rejected'",
                    [
                        mb_substr('上级动态未通过审核：' . $reason, 0, 500),
                        (int) $admin['id'], $momentId, (int) $admin['id'], $appId,
                    ]
                );
            }
            $after = Database::one(
                'SELECT * FROM user_moments WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$momentId, (int) $admin['id'], $appId]
            ) ?? [];
            LogService::adminOperation(
                $request, (int) $admin['id'], $appId, 'moment_moderation',
                $status === 'approved' ? 'approve' : 'reject', $momentId, $before, $after
            );
            self::notifyAuditResult(
                (int) $admin['id'], $appId, (int) $after['user_id'], 'moment_audit',
                $status === 'approved' ? '动态审核通过' : '动态审核未通过',
                $status === 'approved' ? '你发布的动态已公开展示' : '你发布的动态未通过审核：' . $reason,
                ['moment_id' => $momentId, 'audit_status' => $status, 'audit_reason' => $reason]
            );
            return [$before, $after];
        });
        return Response::success(
            ['moment' => self::moment((int) $admin['id'], $appId, $momentId)],
            $status === 'approved' ? '动态已审核通过' : '动态已拒绝并记录原因'
        );
    }

    public static function comments(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['c.admin_id = ?', 'c.app_id = ?'];
        $query = [(int) $admin['id'], $appId];
        self::auditFilter($request, 'c.audit_status', $where, $query);
        foreach (['moment_id', 'user_id', 'status'] as $field) {
            if ($request->input($field) !== null && $request->input($field) !== '') {
                $where[] = "c.{$field} = ?";
                $query[] = (int) $request->input($field);
            }
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(c.content LIKE ? OR m.content LIKE ? OR u.account LIKE ? OR u.uid LIKE ? OR p.nickname LIKE ?)';
            foreach (range(1, 5) as $_) $query[] = '%' . $keyword . '%';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM moment_comments c
             INNER JOIN user_moments m ON m.id = c.moment_id
             INNER JOIN users u ON u.id = c.user_id LEFT JOIN user_profiles p ON p.user_id = c.user_id
             WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT c.*, LEFT(m.content, 180) AS moment_excerpt, m.audit_status AS moment_audit_status,
                    u.uid, u.account, p.nickname, p.avatar, reviewer.nickname AS reviewer_name,
                    parent.content AS parent_content
             FROM moment_comments c
             INNER JOIN user_moments m ON m.id = c.moment_id
             INNER JOIN users u ON u.id = c.user_id
             LEFT JOIN user_profiles p ON p.user_id = c.user_id
             LEFT JOIN admins reviewer ON reviewer.id = c.audited_by
             LEFT JOIN moment_comments parent ON parent.id = c.parent_id
             WHERE {$whereSql}
             ORDER BY CASE c.audit_status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END,
                      c.created_at DESC, c.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = MessageMediaService::hydrate($items, 'moment_comment', $appId);
        foreach ($items as &$item) self::decorate($item);
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function showComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        return Response::success([
            'comment' => self::comment((int) $admin['id'], $appId, (int) $params['comment_id']),
        ]);
    }

    public static function auditComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $commentId = (int) $params['comment_id'];
        [$status, $reason] = self::decision($request);
        $locator = Database::one(
            'SELECT moment_id FROM moment_comments WHERE id = ? AND admin_id = ? AND app_id = ?',
            [$commentId, (int) $admin['id'], $appId]
        );
        if ($locator === null) throw new HttpException('动态评论不存在', 404, 404);
        $momentId = (int) $locator['moment_id'];
        [$before, $after] = Database::transaction(static function () use (
            $request, $admin, $appId, $commentId, $momentId, $status, $reason
        ): array {
            $moment = Database::one(
                'SELECT id, user_id, audit_status, status, deleted_at FROM user_moments
                 WHERE id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [$momentId, (int) $admin['id'], $appId]
            );
            if ($moment === null) throw new HttpException('评论所属动态不存在', 404, 404);
            $before = Database::one(
                'SELECT * FROM moment_comments
                 WHERE id = ? AND moment_id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [$commentId, $momentId, (int) $admin['id'], $appId]
            );
            if ($before === null) throw new HttpException('动态评论不存在', 404, 404);
            if ((string) $before['audit_status'] === $status) {
                throw new HttpException('该评论已是当前审核状态，请刷新列表', 0, 409, ['audit_status' => $status]);
            }
            if ($status === 'approved' && (int) $before['status'] !== 1) {
                throw new HttpException('已删除的动态评论不能审核通过', 0, 409);
            }
            if ($status === 'approved') {
                if ((int) $moment['status'] !== 1 || $moment['deleted_at'] !== null
                    || (string) $moment['audit_status'] !== 'approved') {
                    throw new HttpException('请先通过该评论所属动态的审核', 0, 409);
                }
                self::assertApprovedMomentParentChain(
                    (int) ($before['parent_id'] ?? 0), $momentId, (int) $admin['id'], $appId
                );
            }
            Database::execute(
                'UPDATE moment_comments SET audit_status = ?, audit_reason = ?, audited_by = ?, audited_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$status, $reason, (int) $admin['id'], $commentId, (int) $admin['id'], $appId]
            );
            if ($status === 'rejected') {
                self::rejectMomentCommentDescendants(
                    $commentId, $momentId, (int) $admin['id'], $appId, $reason
                );
            }
            $after = Database::one(
                'SELECT * FROM moment_comments WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$commentId, (int) $admin['id'], $appId]
            ) ?? [];
            LogService::adminOperation(
                $request, (int) $admin['id'], $appId, 'moment_comment_moderation',
                $status === 'approved' ? 'approve' : 'reject', $commentId, $before, $after
            );
            self::notifyAuditResult(
                (int) $admin['id'], $appId, (int) $after['user_id'], 'moment_comment_audit',
                $status === 'approved' ? '评论审核通过' : '评论审核未通过',
                $status === 'approved' ? '你的动态评论已公开展示' : '你的动态评论未通过审核：' . $reason,
                ['moment_id' => (int) $after['moment_id'], 'comment_id' => $commentId,
                 'audit_status' => $status, 'audit_reason' => $reason]
            );
            if ($status === 'approved' && (string) $before['audit_status'] !== 'approved') {
                self::notifyApprovedCommentParticipants((int) $admin['id'], $appId, $after);
            }
            return [$before, $after];
        });
        return Response::success(
            ['comment' => self::comment((int) $admin['id'], $appId, $commentId)],
            $status === 'approved' ? '评论已审核通过' : '评论已拒绝并记录原因'
        );
    }

    private static function context(Request $request, array $params): array
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        return [$admin, $appId];
    }

    private static function auditFilter(Request $request, string $column, array &$where, array &$query): void
    {
        $status = trim((string) $request->input('audit_status', ''));
        if ($status === '') return;
        if (!in_array($status, self::AUDIT_STATUSES, true)) {
            throw new HttpException('audit_status 仅支持 pending、approved 或 rejected', 0, 422);
        }
        $where[] = "{$column} = ?";
        $query[] = $status;
    }

    private static function decision(Request $request): array
    {
        $status = trim((string) $request->input('audit_status', ''));
        if (!in_array($status, ['approved', 'rejected'], true)) {
            throw new HttpException('audit_status 仅支持 approved 或 rejected', 0, 422);
        }
        $reason = trim((string) $request->input('reason', ''));
        if ($status === 'rejected' && $reason === '') throw new HttpException('拒绝审核时必须填写原因', 0, 422);
        if (mb_strlen($reason) > 500) throw new HttpException('审核说明不能超过 500 个字符', 0, 422);
        return [$status, $reason];
    }

    private static function moment(int $adminId, int $appId, int $momentId): array
    {
        $row = Database::one(
            'SELECT m.*, u.uid, u.account, p.nickname, p.avatar, reviewer.nickname AS reviewer_name
             FROM user_moments m INNER JOIN users u ON u.id = m.user_id
             LEFT JOIN user_profiles p ON p.user_id = m.user_id
             LEFT JOIN admins reviewer ON reviewer.id = m.audited_by
             WHERE m.id = ? AND m.admin_id = ? AND m.app_id = ?',
            [$momentId, $adminId, $appId]
        );
        if ($row === null) throw new HttpException('动态不存在', 404, 404);
        $row = MessageMediaService::hydrate([$row], 'moment', $appId)[0];
        self::decorate($row);
        return $row;
    }

    private static function comment(int $adminId, int $appId, int $commentId): array
    {
        $row = Database::one(
            'SELECT c.*, LEFT(m.content, 500) AS moment_excerpt, m.audit_status AS moment_audit_status,
                    u.uid, u.account, p.nickname, p.avatar, reviewer.nickname AS reviewer_name,
                    parent.content AS parent_content, parent_user.account AS parent_account,
                    parent_profile.nickname AS parent_nickname
             FROM moment_comments c INNER JOIN user_moments m ON m.id = c.moment_id
             INNER JOIN users u ON u.id = c.user_id LEFT JOIN user_profiles p ON p.user_id = c.user_id
             LEFT JOIN admins reviewer ON reviewer.id = c.audited_by
             LEFT JOIN moment_comments parent ON parent.id = c.parent_id
             LEFT JOIN users parent_user ON parent_user.id = parent.user_id
             LEFT JOIN user_profiles parent_profile ON parent_profile.user_id = parent.user_id
             WHERE c.id = ? AND c.admin_id = ? AND c.app_id = ?',
            [$commentId, $adminId, $appId]
        );
        if ($row === null) throw new HttpException('动态评论不存在', 404, 404);
        $row = MessageMediaService::hydrate([$row], 'moment_comment', $appId)[0];
        self::decorate($row);
        return $row;
    }

    private static function decorate(array &$item): void
    {
        $item['audit_status_name'] = [
            'pending' => '待审核', 'approved' => '审核通过', 'rejected' => '审核未通过',
        ][(string) ($item['audit_status'] ?? 'pending')] ?? '待审核';
        $item['display_name'] = trim((string) ($item['nickname'] ?? '')) !== ''
            ? (string) $item['nickname'] : (string) ($item['account'] ?? '');
        $item['status_name'] = (int) ($item['status'] ?? 0) === 1 ? '正常' : '已停用';
    }

    private static function notifyAuditResult(
        int $adminId, int $appId, int $userId, string $type, string $title, string $content, array $data
    ): void {
        $user = NotificationService::user($adminId, $appId, $userId);
        if ($user !== null) NotificationService::send($user, $type, $title, $content, $data);
    }

    private static function notifyApprovedCommentParticipants(int $adminId, int $appId, array $comment): void
    {
        $moment = Database::one(
            'SELECT user_id FROM user_moments WHERE id = ? AND admin_id = ? AND app_id = ?',
            [(int) $comment['moment_id'], $adminId, $appId]
        );
        $author = Database::one(
            'SELECT u.account, p.nickname FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE u.id = ? AND u.admin_id = ? AND u.app_id = ?',
            [(int) $comment['user_id'], $adminId, $appId]
        );
        $name = trim((string) ($author['nickname'] ?? '')) !== ''
            ? (string) $author['nickname'] : (string) ($author['account'] ?? '用户');
        if ($moment !== null && (int) $moment['user_id'] !== (int) $comment['user_id']) {
            self::notifyAuditResult(
                $adminId, $appId, (int) $moment['user_id'], 'moment_comment', '动态收到评论',
                $name . '评论了你的动态',
                ['moment_id' => (int) $comment['moment_id'], 'comment_id' => (int) $comment['id'], 'focus' => 'comments']
            );
        }
        if ((int) ($comment['parent_id'] ?? 0) > 0) {
            $parent = Database::one(
                'SELECT user_id FROM moment_comments
                 WHERE id = ? AND moment_id = ? AND admin_id = ? AND app_id = ?',
                [(int) $comment['parent_id'], (int) $comment['moment_id'], $adminId, $appId]
            );
            $parentUserId = (int) ($parent['user_id'] ?? 0);
            if ($parentUserId > 0 && $parentUserId !== (int) $comment['user_id']
                && ($moment === null || $parentUserId !== (int) $moment['user_id'])) {
                self::notifyAuditResult(
                    $adminId, $appId, $parentUserId, 'moment_reply', '评论收到回复',
                    $name . '回复了你的评论',
                    ['moment_id' => (int) $comment['moment_id'], 'comment_id' => (int) $comment['id'],
                     'parent_comment_id' => (int) $comment['parent_id'], 'focus' => 'comments']
                );
            }
        }
    }

    private static function assertApprovedMomentParentChain(
        int $parentId, int $momentId, int $adminId, int $appId
    ): void {
        $visited = [];
        for ($depth = 0; $parentId > 0 && $depth < 64; $depth++) {
            if (isset($visited[$parentId])) {
                throw new HttpException('评论回复关系异常，不能审核通过', 0, 409);
            }
            $visited[$parentId] = true;
            $parent = Database::one(
                'SELECT id, parent_id, audit_status, status FROM moment_comments
                 WHERE id = ? AND moment_id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [$parentId, $momentId, $adminId, $appId]
            );
            if ($parent === null || (int) $parent['status'] !== 1
                || (string) $parent['audit_status'] !== 'approved') {
                throw new HttpException('请先通过该回复所属全部上级评论的审核', 0, 409);
            }
            $parentId = (int) ($parent['parent_id'] ?? 0);
        }
        if ($parentId > 0) throw new HttpException('评论回复层级过深，不能审核通过', 0, 409);
    }

    private static function rejectMomentCommentDescendants(
        int $commentId, int $momentId, int $adminId, int $appId, string $reason
    ): void {
        $frontier = [$commentId];
        $visited = [$commentId => true];
        $descendantIds = [];
        for ($depth = 0; $frontier !== [] && $depth < 64; $depth++) {
            $children = [];
            foreach (array_chunk($frontier, 500) as $parentChunk) {
                $placeholders = implode(',', array_fill(0, count($parentChunk), '?'));
                $children = array_merge($children, Database::all(
                    "SELECT id FROM moment_comments
                     WHERE parent_id IN ({$placeholders}) AND moment_id = ? AND admin_id = ? AND app_id = ?
                     ORDER BY id FOR UPDATE",
                    array_merge($parentChunk, [$momentId, $adminId, $appId])
                ));
            }
            $frontier = [];
            foreach ($children as $child) {
                $id = (int) $child['id'];
                if ($id <= 0 || isset($visited[$id])) continue;
                $visited[$id] = true;
                $descendantIds[] = $id;
                $frontier[] = $id;
            }
        }
        if ($frontier !== []) throw new HttpException('评论回复层级过深，拒绝操作已取消', 0, 409);
        foreach (array_chunk($descendantIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            Database::execute(
                "UPDATE moment_comments
                 SET audit_status = 'rejected', audit_reason = ?, audited_by = ?, audited_at = NOW(), updated_at = NOW()
                 WHERE id IN ({$placeholders}) AND moment_id = ? AND admin_id = ? AND app_id = ? AND status = 1",
                array_merge(
                    [mb_substr('上级评论未通过审核：' . $reason, 0, 500), $adminId],
                    $chunk,
                    [$momentId, $adminId, $appId]
                )
            );
        }
    }
}
