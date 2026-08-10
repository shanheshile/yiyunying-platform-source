<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\User;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\ChatRoomService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\MessageMediaService;
use Yiyunying\Services\MomentVisibilityService;
use Yiyunying\Services\NotificationService;

final class MomentController
{
    private const EDIT_WINDOW_SECONDS = 120;
    private const MAX_MEDIA_COUNT = 9;

    public static function index(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        self::purgeExpired((int) $user['app_id']);
        $page = $request->page();
        $limit = $request->limit();
        $where = [
            'm.admin_id = ?', 'm.app_id = ?', 'm.status = 1', 'm.deleted_at IS NULL',
            "(m.audit_status = 'approved' OR m.user_id = ?)",
        ];
        $params = [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']];
        $targetUserId = max(0, (int) $request->input('user_id', 0));
        if ((int) $request->input('mine', 0) === 1) $targetUserId = (int) $user['id'];
        if ($targetUserId > 0) {
            $where[] = 'm.user_id = ?';
            $params[] = $targetUserId;
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(m.content LIKE ? OR m.location_name LIKE ? OR u.account LIKE ? OR p.nickname LIKE ?)';
            foreach (range(1, 4) as $_) $params[] = '%' . $keyword . '%';
        }
        $orderBy = $targetUserId > 0
            ? 'm.is_pinned DESC, m.pin_order ASC, m.created_at DESC, m.id DESC'
            : 'm.created_at DESC, m.id DESC';
        $rows = Database::all(
            'SELECT m.*, u.uid, u.account, p.nickname, p.avatar, p.title AS user_title
             FROM user_moments m
             INNER JOIN users u ON u.id = m.user_id
             LEFT JOIN user_profiles p ON p.user_id = m.user_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ' . $orderBy,
            $params
        );
        $visible = [];
        foreach ($rows as $row) {
            if (MomentVisibilityService::canView($row, $user, $targetUserId > 0)) $visible[] = $row;
        }
        $total = count($visible);
        $items = array_slice($visible, ($page - 1) * $limit, $limit);
        $items = MessageMediaService::hydrate($items, 'moment', (int) $user['app_id']);
        foreach ($items as &$item) self::decorate($item, $user);
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        return Response::success(['moment' => self::find($user, (int) $params['moment_id'], false)]);
    }

    public static function create(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $data = $request->all();
        $content = trim((string) ($data['content'] ?? ''));
        if (mb_strlen($content) > 5000) throw new HttpException('动态文字不能超过 5000 个字符', 0, 422);
        $payload = self::mediaPayload($user, $content, $data['attachments'] ?? []);
        if ($content === '' && $payload['attachments'] === []) throw new HttpException('动态文字和媒体不能同时为空', 0, 422);
        [$locationName, $latitude, $longitude] = self::location($user, $data);
        $visibilityMode = MomentVisibilityService::normalizeMode((string) ($data['visibility_mode'] ?? 'inherit'), true);
        $visibleDays = self::requestedVisibleDays($data, null);
        $visibilityUserIds = MomentVisibilityService::normalizeUserIds(
            $data['visibility_user_ids'] ?? [],
            (int) $user['app_id'],
            (int) $user['id']
        );
        $auditStatus = AppService::setting((int) $user['app_id'], 'moment_post_audit', false)
            ? 'pending' : 'approved';
        $momentId = Database::transaction(static function () use (
            $user, $content, $locationName, $latitude, $longitude, $payload,
            $visibilityMode, $visibleDays, $visibilityUserIds, $auditStatus
        ): int {
            $id = Database::insert(
                'INSERT INTO user_moments
                 (admin_id, app_id, user_id, content, location_name, latitude, longitude,
                  visibility_mode, visible_days, visibility_user_ids_json, audit_status, audit_reason,
                  status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'\', 1, NOW(), NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $content,
                    $locationName, $latitude, $longitude, $visibilityMode, $visibleDays,
                    MomentVisibilityService::encodeIds($visibilityUserIds), $auditStatus,
                ]
            );
            MessageMediaService::save('moment', $id, $payload);
            return $id;
        });
        LogService::userOperation($request, $user, 'moment', 'create', $momentId, [
            'media_count' => count($payload['attachments']),
            'visibility_mode' => $visibilityMode,
            'visible_days' => $visibleDays,
            'audit_status' => $auditStatus,
        ]);
        return Response::success(
            ['moment' => self::find($user, $momentId, false), 'audit_status' => $auditStatus],
            $auditStatus === 'pending' ? '动态已提交审核，通过后对其他用户展示' : '动态发布成功',
            201
        );
    }

    public static function update(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $momentId = (int) $params['moment_id'];
        $before = self::find($user, $momentId, true);
        self::ensureOwnerAndWindow($before, $user);
        $data = $request->all();
        $content = array_key_exists('content', $data) ? trim((string) $data['content']) : (string) $before['content'];
        if (mb_strlen($content) > 5000) throw new HttpException('动态文字不能超过 5000 个字符', 0, 422);
        $payload = array_key_exists('attachments', $data)
            ? self::mediaPayload($user, $content, $data['attachments'])
            : null;
        $attachmentCount = $payload === null ? (int) ($before['attachment_count'] ?? 0) : count($payload['attachments']);
        if ($content === '' && $attachmentCount === 0) throw new HttpException('动态文字和媒体不能同时为空', 0, 422);
        [$locationName, $latitude, $longitude] = array_key_exists('location_name', $data)
            || array_key_exists('latitude', $data) || array_key_exists('longitude', $data)
            ? self::location($user, $data)
            : [(string) $before['location_name'], $before['latitude'], $before['longitude']];
        $visibilityMode = array_key_exists('visibility_mode', $data)
            ? MomentVisibilityService::normalizeMode((string) $data['visibility_mode'], true)
            : (string) $before['visibility_mode'];
        $visibleDays = self::requestedVisibleDays($data, $before['visible_days'] === null ? null : (int) $before['visible_days']);
        $visibilityUserIds = array_key_exists('visibility_user_ids', $data)
            ? MomentVisibilityService::normalizeUserIds($data['visibility_user_ids'], (int) $user['app_id'], (int) $user['id'])
            : MomentVisibilityService::decodeIds($before['visibility_user_ids'] ?? []);
        $auditStatus = AppService::setting((int) $user['app_id'], 'moment_post_audit', false)
            ? 'pending' : 'approved';
        Database::transaction(static function () use (
            $user, $momentId, $content, $locationName, $latitude, $longitude, $payload,
            $visibilityMode, $visibleDays, $visibilityUserIds, $auditStatus
        ): void {
            $affected = Database::execute(
                "UPDATE user_moments
                 SET content = ?, location_name = ?, latitude = ?, longitude = ?, visibility_mode = ?,
                     visible_days = ?, visibility_user_ids_json = ?, audit_status = ?, audit_reason = '',
                     audited_by = NULL, audited_at = NULL, edited_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ? AND deleted_at IS NULL
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 120 SECOND)",
                [
                    $content, $locationName, $latitude, $longitude, $visibilityMode, $visibleDays,
                    MomentVisibilityService::encodeIds($visibilityUserIds), $auditStatus, $momentId,
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
                ]
            );
            if ($affected !== 1) throw new HttpException('动态已超过 2 分钟，不能再修改', 0, 422);
            if ($payload !== null) MessageMediaService::replace('moment', $momentId, $payload);
        });
        LogService::userOperation($request, $user, 'moment', 'update', $momentId);
        return Response::success(
            ['moment' => self::find($user, $momentId, false), 'audit_status' => $auditStatus],
            $auditStatus === 'pending' ? '动态已更新并重新提交审核' : '动态已更新'
        );
    }

    public static function updateVisibility(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $momentId = (int) $params['moment_id'];
        $before = self::find($user, $momentId, false);
        self::ensureOwner($before, $user);
        $data = $request->all();
        $visibilityMode = array_key_exists('visibility_mode', $data)
            ? MomentVisibilityService::normalizeMode((string) $data['visibility_mode'], true)
            : (string) $before['visibility_mode'];
        $visibleDays = self::requestedVisibleDays(
            $data,
            $before['visible_days'] === null ? null : (int) $before['visible_days']
        );
        $visibilityUserIds = array_key_exists('visibility_user_ids', $data)
            ? MomentVisibilityService::normalizeUserIds(
                $data['visibility_user_ids'],
                (int) $user['app_id'],
                (int) $user['id']
            )
            : MomentVisibilityService::decodeIds($before['visibility_user_ids'] ?? []);
        Database::execute(
            'UPDATE user_moments
             SET visibility_mode = ?, visible_days = ?, visibility_user_ids_json = ?, updated_at = NOW()
             WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ? AND deleted_at IS NULL',
            [
                $visibilityMode,
                $visibleDays,
                MomentVisibilityService::encodeIds($visibilityUserIds),
                $momentId,
                (int) $user['admin_id'],
                (int) $user['app_id'],
                (int) $user['id'],
            ]
        );
        LogService::userOperation($request, $user, 'moment', 'update_visibility', $momentId, [
            'visibility_mode' => $visibilityMode,
            'visible_days' => $visibleDays,
            'visibility_user_ids' => $visibilityUserIds,
        ]);
        return Response::success(['moment' => self::find($user, $momentId, false)], '可见范围已更新');
    }

    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $momentId = (int) $params['moment_id'];
        $moment = self::find($user, $momentId, true);
        self::ensureOwner($moment, $user);
        $affected = Database::execute(
            'UPDATE user_moments SET deleted_at = NOW(), delete_expires_at = DATE_ADD(NOW(), INTERVAL 120 SECOND), updated_at = NOW()
             WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ? AND deleted_at IS NULL',
            [$momentId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        if ($affected !== 1) throw new HttpException('动态不存在或已删除', 404, 404);
        LogService::userOperation($request, $user, 'moment', 'delete', $momentId);
        return Response::success(['moment_id' => $momentId, 'undo_seconds' => self::EDIT_WINDOW_SECONDS], '动态已删除，2 分钟内可以恢复');
    }

    public static function restore(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $momentId = (int) $params['moment_id'];
        $affected = Database::execute(
            'UPDATE user_moments SET deleted_at = NULL, delete_expires_at = NULL, updated_at = NOW()
             WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ?
               AND deleted_at IS NOT NULL AND delete_expires_at >= NOW()',
            [$momentId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        if ($affected !== 1) throw new HttpException('恢复时间已超过 2 分钟，动态已永久删除', 0, 410);
        LogService::userOperation($request, $user, 'moment', 'restore', $momentId);
        return Response::success(['moment' => self::find($user, $momentId, false)], '动态已恢复');
    }

    public static function setPin(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $momentId = (int) $params['moment_id'];
        $moment = self::find($user, $momentId, false);
        if ((int) $moment['user_id'] !== (int) $user['id']) {
            throw new HttpException('只能管理自己动态的置顶状态', 0, 403);
        }
        $pinned = filter_var($request->input('pinned', true), FILTER_VALIDATE_BOOLEAN);
        $pinOrder = max(0, (int) $request->input('pin_order', 0));
        if ($pinned && $pinOrder === 0) {
            $maximum = Database::one(
                'SELECT COALESCE(MAX(pin_order), 0) AS maximum FROM user_moments
                 WHERE admin_id = ? AND app_id = ? AND user_id = ? AND is_pinned = 1 AND deleted_at IS NULL',
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
            );
            $pinOrder = (int) ($maximum['maximum'] ?? 0) + 1;
        }
        Database::execute(
            'UPDATE user_moments SET is_pinned = ?, pin_order = ?, updated_at = NOW()
             WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ? AND deleted_at IS NULL',
            [$pinned ? 1 : 0, $pinned ? $pinOrder : 0, $momentId,
             (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        LogService::userOperation($request, $user, 'moment', $pinned ? 'pin' : 'unpin', $momentId, [
            'pin_order' => $pinned ? $pinOrder : 0,
        ]);
        return Response::success([
            'moment' => self::find($user, $momentId, false),
        ], $pinned ? '动态已置顶' : '已取消置顶');
    }

    public static function toggleLike(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $moment = self::find($user, (int) $params['moment_id'], false);
        self::ensureApprovedForInteraction($moment);
        $existing = Database::one('SELECT id FROM moment_likes WHERE moment_id = ? AND user_id = ?', [(int) $moment['id'], (int) $user['id']]);
        if ($existing !== null) {
            Database::execute('DELETE FROM moment_likes WHERE id = ?', [(int) $existing['id']]);
            $liked = false;
        } else {
            Database::execute(
                'INSERT IGNORE INTO moment_likes (admin_id, app_id, moment_id, user_id, created_at) VALUES (?, ?, ?, ?, NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $moment['id'], (int) $user['id']]
            );
            $liked = true;
            self::notifyOwner($moment, $user, 'moment_like', '动态收到点赞', self::displayName($user) . '赞了你的动态', [
                'focus' => 'likes',
                'location_hint' => '将打开这条动态并定位到点赞区域',
            ]);
        }
        $presentation = self::likePresentation($moment, $user, 12, 0);
        return Response::success([
            'liked' => $liked,
            'like_count' => (int) $presentation['visibility']['total_count'],
            'visible_likers' => $presentation['items'],
            'like_visibility' => $presentation['visibility'],
        ], $liked ? '已点赞' : '已取消点赞');
    }

    public static function likes(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $moment = self::find($user, (int) $params['moment_id'], false);
        self::ensureApprovedForInteraction($moment);
        $page = $request->page();
        $limit = $request->limit();
        $presentation = self::likePresentation($moment, $user, $limit, ($page - 1) * $limit);
        return Response::success(array_merge(
            Pagination::data(
                $presentation['items'],
                (int) $presentation['visibility']['visible_count'],
                $page,
                $limit
            ),
            ['like_visibility' => $presentation['visibility']]
        ));
    }

    public static function comments(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $moment = self::find($user, (int) $params['moment_id'], false);
        self::ensureApprovedForInteraction($moment);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM moment_comments
             WHERE moment_id = ? AND status = 1 AND (audit_status = 'approved' OR user_id = ?)",
            [(int) $moment['id'], (int) $user['id']]
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT c.*, u.uid, u.account, p.nickname, p.avatar,
                    pu.uid AS parent_uid, pu.account AS parent_account, pp.nickname AS parent_nickname,
                    pc.content AS parent_content,
                    s.name AS sticker_name, s.image_url AS sticker_url, s.thumbnail_url AS sticker_thumbnail_url,
                    (SELECT COUNT(*) FROM moment_comment_likes cl WHERE cl.comment_id = c.id) AS like_count,
                    EXISTS(SELECT 1 FROM moment_comment_likes viewer_like WHERE viewer_like.comment_id = c.id AND viewer_like.user_id = ?) AS is_liked
             FROM moment_comments c
             INNER JOIN users u ON u.id = c.user_id
             LEFT JOIN user_profiles p ON p.user_id = c.user_id
             LEFT JOIN moment_comments pc ON pc.id = c.parent_id AND pc.moment_id = c.moment_id
               AND pc.status = 1 AND (pc.audit_status = 'approved' OR pc.user_id = ?)
             LEFT JOIN users pu ON pu.id = pc.user_id
             LEFT JOIN user_profiles pp ON pp.user_id = pc.user_id
             LEFT JOIN stickers s ON s.id = c.sticker_id AND s.status = 1
             WHERE c.moment_id = ? AND c.status = 1
               AND (c.audit_status = 'approved' OR c.user_id = ?)
             ORDER BY c.id ASC LIMIT {$limit} OFFSET {$offset}",
            [(int) $user['id'], (int) $user['id'], (int) $moment['id'], (int) $user['id']]
        );
        foreach ($items as &$item) self::decorateComment($item, $moment, (int) $user['id']);
        unset($item);
        $items = MessageMediaService::hydrate($items, 'moment_comment', (int) $user['app_id']);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function createComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $moment = self::find($user, (int) $params['moment_id'], false);
        self::ensureApprovedForInteraction($moment);
        $content = trim((string) $request->input('content', ''));
        $stickerId = max(0, (int) $request->input('sticker_id', 0));
        $rawAttachments = $request->input('attachments', []);
        if (is_string($rawAttachments)) $rawAttachments = json_decode($rawAttachments, true);
        if (!is_array($rawAttachments)) throw new HttpException('语音评论格式错误', 0, 422);
        if (count($rawAttachments) > 1) throw new HttpException('每条动态评论最多发送一段语音', 0, 422);
        $mediaPayload = null;
        if ($rawAttachments !== []) {
            $mediaPayload = MessageMediaService::userPayload($user, [
                'content' => $content,
                'attachments' => $rawAttachments,
            ]);
            $attachment = $mediaPayload['attachments'][0] ?? null;
            if (!is_array($attachment) || (string) ($attachment['media_type'] ?? '') !== 'audio') {
                throw new HttpException('动态评论只支持录制语音附件', 0, 422);
            }
            $durationMs = (int) ($attachment['duration_ms'] ?? 0);
            if ($durationMs < 650 || $durationMs > 60000) {
                throw new HttpException('语音评论时长应在 1 至 60 秒之间', 0, 422);
            }
            $mediaPayload['attachments'][0]['metadata']['audio_kind'] = 'voice';
        }
        if (($content === '' && $stickerId <= 0 && $mediaPayload === null) || mb_strlen($content) > 2000) {
            throw new HttpException('请输入评论内容、选择表情包或录制语音，文字最多 2000 个字符', 0, 422);
        }
        $sticker = null;
        if ($stickerId > 0) {
            $sticker = Database::one(
                'SELECT id, name, image_url FROM stickers WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ? AND status = 1',
                [$stickerId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
            );
            if ($sticker === null) throw new HttpException('选择的表情包不存在或无权使用', 0, 422);
        }
        $parentId = max(0, (int) $request->input('parent_id', 0));
        $auditStatus = AppService::setting((int) $user['app_id'], 'moment_comment_audit', false)
            ? 'pending' : 'approved';
        [$commentId, $parent] = Database::transaction(static function () use (
            $user, $moment, $parentId, $stickerId, $content, $mediaPayload, $auditStatus
        ): array {
            $lockedMoment = Database::one(
                "SELECT id, audit_status FROM user_moments
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1
                   AND deleted_at IS NULL FOR UPDATE",
                [(int) $moment['id'], (int) $user['admin_id'], (int) $user['app_id']]
            );
            if ($lockedMoment === null || (string) $lockedMoment['audit_status'] !== 'approved') {
                throw new HttpException('动态尚未审核通过，暂不能评论', 403, 403);
            }
            $parent = null;
            if ($parentId > 0) {
                $parent = Database::one(
                    "SELECT * FROM moment_comments
                     WHERE id = ? AND moment_id = ? AND admin_id = ? AND app_id = ? AND status = 1
                       AND (audit_status = 'approved' OR user_id = ?) FOR UPDATE",
                    [
                        $parentId, (int) $moment['id'], (int) $user['admin_id'],
                        (int) $user['app_id'], (int) $user['id'],
                    ]
                );
                if ($parent === null) throw new HttpException('回复的评论不存在', 404, 404);
                if ($auditStatus === 'approved') {
                    self::assertApprovedMomentParentChain(
                        (int) $moment['id'], $parent, (int) $user['admin_id'], (int) $user['app_id']
                    );
                }
            }
            $id = Database::insert(
                'INSERT INTO moment_comments
                 (admin_id, app_id, moment_id, user_id, parent_id, sticker_id, content,
                  audit_status, audit_reason, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'\', 1, NOW(), NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $moment['id'], (int) $user['id'], $parentId > 0 ? $parentId : null, $stickerId > 0 ? $stickerId : null, $content, $auditStatus]
            );
            if ($mediaPayload !== null) MessageMediaService::save('moment_comment', $id, $mediaPayload);
            return [$id, $parent];
        });
        $visibleComment = $content !== '' ? $content : ($stickerId > 0 ? '[表情包]' : '[语音]');
        $notificationData = [
            'focus' => 'comments',
            'comment_id' => $commentId,
            'comment_content' => mb_substr($visibleComment, 0, 160),
            'location_hint' => '将打开这条动态并定位到对应评论',
        ];
        if ($auditStatus === 'approved') self::notifyOwner($moment, $user, 'moment_comment', '动态收到评论', self::displayName($user) . '评论了你的动态', $notificationData);
        if ($auditStatus === 'approved' && $parent !== null && (int) $parent['user_id'] !== (int) $moment['user_id'] && (int) $parent['user_id'] !== (int) $user['id']) {
            self::notifyUser((int) $parent['user_id'], $user, $moment, 'moment_reply', '评论收到回复', self::displayName($user) . '回复了你的评论', array_merge($notificationData, [
                'reply_content' => mb_substr($visibleComment, 0, 160),
                'parent_comment_id' => (int) $parent['id'],
                'parent_comment_content' => mb_substr((string) ($parent['content'] ?? ''), 0, 160),
            ]));
        }
        $item = Database::one(
            "SELECT c.*, u.uid, u.account, p.nickname, p.avatar,
                    pu.uid AS parent_uid, pu.account AS parent_account, pp.nickname AS parent_nickname,
                    pc.content AS parent_content,
                    s.name AS sticker_name, s.image_url AS sticker_url, s.thumbnail_url AS sticker_thumbnail_url,
                    0 AS like_count, 0 AS is_liked
             FROM moment_comments c
             INNER JOIN users u ON u.id = c.user_id
             LEFT JOIN user_profiles p ON p.user_id = c.user_id
             LEFT JOIN moment_comments pc ON pc.id = c.parent_id AND pc.moment_id = c.moment_id
               AND pc.status = 1 AND (pc.audit_status = 'approved' OR pc.user_id = ?)
             LEFT JOIN users pu ON pu.id = pc.user_id
             LEFT JOIN user_profiles pp ON pp.user_id = pc.user_id
             LEFT JOIN stickers s ON s.id = c.sticker_id AND s.status = 1
             WHERE c.id = ?",
            [(int) $user['id'], $commentId]
        );
        self::decorateComment($item, $moment, (int) $user['id']);
        $item = MessageMediaService::hydrate([$item], 'moment_comment', (int) $user['app_id'])[0];
        return Response::success(
            ['comment' => $item, 'audit_status' => $auditStatus],
            $auditStatus === 'pending' ? '评论已提交审核，通过后对其他用户展示' : '评论成功',
            201
        );
    }

    public static function toggleCommentLike(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $moment = self::find($user, (int) $params['moment_id'], false);
        self::ensureApprovedForInteraction($moment);
        $commentId = (int) $params['comment_id'];
        $comment = Database::one(
            "SELECT c.*, u.account, p.nickname FROM moment_comments c
             INNER JOIN users u ON u.id = c.user_id
             LEFT JOIN user_profiles p ON p.user_id = c.user_id
             WHERE c.id = ? AND c.moment_id = ? AND c.status = 1 AND c.audit_status = 'approved'",
            [$commentId, (int) $moment['id']]
        );
        if ($comment === null) throw new HttpException('评论不存在', 404, 404);
        $existing = Database::one(
            'SELECT id FROM moment_comment_likes WHERE comment_id = ? AND user_id = ?',
            [$commentId, (int) $user['id']]
        );
        if ($existing !== null) {
            Database::execute('DELETE FROM moment_comment_likes WHERE id = ?', [(int) $existing['id']]);
            $liked = false;
        } else {
            Database::execute(
                'INSERT IGNORE INTO moment_comment_likes (admin_id, app_id, comment_id, user_id, created_at) VALUES (?, ?, ?, ?, NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], $commentId, (int) $user['id']]
            );
            $liked = true;
            if ((int) $comment['user_id'] !== (int) $user['id']) {
                self::notifyUser((int) $comment['user_id'], $user, $moment, 'moment_comment_like', '评论收到点赞', self::displayName($user) . '赞了你的评论', [
                    'focus' => 'comments',
                    'comment_id' => $commentId,
                    'comment_content' => mb_substr((string) ($comment['content'] ?? '[表情包]'), 0, 160),
                    'location_hint' => '将打开这条动态并定位到被点赞的评论',
                ]);
            }
        }
        $count = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM moment_comment_likes WHERE comment_id = ?', [$commentId]
        )['total'] ?? 0);
        return Response::success([
            'comment_id' => $commentId,
            'liked' => $liked,
            'like_count' => $count,
        ], $liked ? '已点赞评论' : '已取消点赞');
    }

    public static function deleteComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $moment = self::find($user, (int) $params['moment_id'], false);
        $commentId = (int) $params['comment_id'];
        $comment = Database::one('SELECT * FROM moment_comments WHERE id = ? AND moment_id = ? AND status = 1', [$commentId, (int) $moment['id']]);
        if ($comment === null) throw new HttpException('评论不存在', 404, 404);
        if ((int) $comment['user_id'] !== (int) $user['id'] && (int) $moment['user_id'] !== (int) $user['id']) {
            throw new HttpException('只能删除自己的评论或自己动态下的评论', 0, 403);
        }
        Database::execute('UPDATE moment_comments SET status = 0, updated_at = NOW() WHERE id = ?', [$commentId]);
        return Response::success(['comment_id' => $commentId], '评论已删除');
    }

    public static function toggleFavorite(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $moment = self::find($user, (int) $params['moment_id'], false);
        self::ensureApprovedForInteraction($moment);
        $existing = Database::one('SELECT id FROM moment_favorites WHERE moment_id = ? AND user_id = ?', [(int) $moment['id'], (int) $user['id']]);
        if ($existing !== null) {
            Database::execute('DELETE FROM moment_favorites WHERE id = ?', [(int) $existing['id']]);
            $favorited = false;
        } else {
            Database::execute(
                'INSERT IGNORE INTO moment_favorites (admin_id, app_id, moment_id, user_id, created_at) VALUES (?, ?, ?, ?, NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $moment['id'], (int) $user['id']]
            );
            $favorited = true;
        }
        return Response::success([
            'favorited' => $favorited,
            'favorite_count' => self::count('moment_favorites', (int) $moment['id']),
        ], $favorited ? '已收藏' : '已取消收藏');
    }

    public static function forward(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $moment = self::find($user, (int) $params['moment_id'], false);
        self::ensureApprovedForInteraction($moment);
        $targetType = strtolower(trim((string) $request->input('target_type', 'external')));
        if ($targetType === 'room') $targetType = 'group';
        if (!in_array($targetType, ['private', 'group', 'chat_room', 'service', 'forum', 'bounty', 'external'], true)) {
            throw new HttpException('转发目标类型不正确', 0, 422);
        }
        $targetId = max(0, (int) $request->input('target_id', 0));
        if (in_array($targetType, ['private', 'group', 'chat_room'], true) && $targetId <= 0) {
            throw new HttpException('请选择有效的转发目标', 0, 422);
        }
        $payload = in_array($targetType, ['private', 'group', 'chat_room', 'service'], true)
            ? self::forwardPayload($user, $moment)
            : null;
        $result = Database::transaction(static function () use ($user, $moment, $targetType, $targetId, $payload): array {
            $delivery = match ($targetType) {
                'private' => self::forwardToPrivate($user, $targetId, $payload),
                'group', 'chat_room' => self::forwardToGroup($user, $targetId, $payload, $targetType),
                'service' => self::forwardToService($user, $payload),
                default => [],
            };
            $forwardId = Database::insert(
                'INSERT INTO moment_forwards (admin_id, app_id, moment_id, user_id, target_type, target_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $moment['id'], (int) $user['id'], $targetType, $targetId]
            );
            return ['forward_id' => $forwardId] + $delivery;
        });
        return Response::success(array_merge($result, [
            'forward_count' => self::count('moment_forwards', (int) $moment['id']),
        ]), in_array($targetType, ['private', 'group', 'chat_room', 'service'], true) ? '动态已发送' : '转发记录已创建', 201);
    }

    private static function forwardPayload(array $user, array $moment): array
    {
        $attachments = is_array($moment['attachments'] ?? null) ? $moment['attachments'] : [];
        $previewUrl = '';
        $previewType = '';
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) continue;
            $previewUrl = trim((string) ($attachment['thumbnail_url'] ?? $attachment['url'] ?? ''));
            $previewType = trim((string) ($attachment['media_type'] ?? ''));
            if ($previewUrl !== '') break;
        }
        $summary = trim((string) ($moment['content'] ?? ''));
        if ($summary === '') $summary = trim((string) ($moment['media_summary'] ?? ''));
        if ($summary === '') $summary = '分享了一条动态';
        $momentId = (int) $moment['id'];
        return MessageMediaService::userPayload($user, [
            'content' => '',
            'attachments' => [[
                'media_type' => 'moment_share',
                'url' => "/api/user/moments/{$momentId}",
                'thumbnail_url' => $previewUrl,
                'file_name' => '动态分享',
                'mime_type' => 'application/vnd.yiyunying.moment+json',
                'metadata' => [
                    'content_kind' => 'moment',
                    'target_id' => $momentId,
                    'moment_id' => $momentId,
                    'author_user_id' => (int) $moment['user_id'],
                    'author_name' => (string) ($moment['display_name'] ?? $moment['account'] ?? '用户'),
                    'author_account' => (string) ($moment['account'] ?? ''),
                    'author_avatar' => (string) ($moment['avatar'] ?? ''),
                    'content' => (string) ($moment['content'] ?? ''),
                    'location_name' => (string) ($moment['location_name'] ?? ''),
                    'created_at' => (string) ($moment['created_at'] ?? ''),
                    'attachment_count' => count($attachments),
                    'media_summary' => (string) ($moment['media_summary'] ?? ''),
                    'summary' => mb_substr($summary, 0, 160),
                    'preview_url' => $previewUrl,
                    'preview_type' => $previewType,
                ],
            ]],
        ]);
    }

    private static function forwardToPrivate(array $user, int $targetUserId, array $payload): array
    {
        $selfChat = $targetUserId === (int) $user['id'];
        $receiver = Database::one(
            'SELECT id FROM users WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1 AND deleted_at IS NULL',
            [$targetUserId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        if ($receiver === null) throw new HttpException('转发目标用户不存在或不可用', 404, 404);
        if (!$selfChat) {
            SocialController::assertNotBlocked($user, $targetUserId);
            $friend = Database::one(
                'SELECT id FROM friends WHERE app_id = ? AND user_id = ? AND friend_user_id = ? AND status = 1 LIMIT 1',
                [(int) $user['app_id'], (int) $user['id'], $targetUserId]
            );
            if ($friend === null) throw new HttpException('动态只能直接发送给好友', 403, 403);
        }
        [$a, $b] = [(int) $user['id'], $targetUserId];
        if ($a > $b) [$a, $b] = [$b, $a];
        Database::execute(
            'INSERT INTO conversations (admin_id, app_id, type, user_a_id, user_b_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE updated_at = NOW()',
            [(int) $user['admin_id'], (int) $user['app_id'], 'private', $a, $b]
        );
        $conversation = Database::one(
            'SELECT id FROM conversations WHERE app_id = ? AND type = ? AND user_a_id = ? AND user_b_id = ? FOR UPDATE',
            [(int) $user['app_id'], 'private', $a, $b]
        );
        if ($conversation === null) throw new HttpException('创建转发会话失败', -1, 500);
        $messageId = Database::insert(
            'INSERT INTO messages
             (admin_id, app_id, conversation_id, sender_type, sender_id, receiver_user_id,
              title, content_type, content, tags_json, is_read, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $conversation['id'],
                'user', (int) $user['id'], $targetUserId, '', (string) $payload['content_type'],
                (string) $payload['content'], '[]', $selfChat ? 1 : 0,
            ]
        );
        MessageMediaService::save('private_message', $messageId, $payload);
        Database::execute(
            'UPDATE conversations SET last_message_id = ?, last_message_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$messageId, (int) $conversation['id']]
        );
        return [
            'target_type' => 'private',
            'conversation_id' => (int) $conversation['id'],
            'message_id' => $messageId,
            'is_self_chat' => $selfChat,
        ];
    }

    private static function forwardToGroup(array $user, int $roomId, array $payload, string $expectedType): array
    {
        $room = ChatRoomService::userRoom($user, $roomId, true);
        $actualType = ChatRoomService::roomKind($room['room_kind'] ?? ChatRoomService::ROOM_GROUP);
        if ($actualType !== $expectedType) {
            $expectedName = $expectedType === ChatRoomService::ROOM_CHATROOM ? '聊天室' : '群聊';
            throw new HttpException("所选目标不是{$expectedName}，请重新选择", 0, 422);
        }
        $member = ChatRoomService::requireMember($user, $room);
        $policy = ChatRoomService::policy($room);
        if ((bool) $policy['mute_all'] && !in_array((string) $member['role'], ['owner', 'admin'], true)) {
            throw new HttpException('当前群聊已开启全员禁言', 403, 403);
        }
        if ($member['mute_until'] !== null && strtotime((string) $member['mute_until']) > time()) {
            throw new HttpException('你已被禁言', 403, 403);
        }
        $messageId = Database::insert(
            'INSERT INTO chat_room_messages
             (admin_id, app_id, room_id, user_id, sender_type, sender_admin_id, content_type, content, tags_json, status, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, 1, NOW())',
            [
                (int) $user['admin_id'], (int) $user['app_id'], $roomId, (int) $user['id'], 'user',
                (string) $payload['content_type'], (string) $payload['content'], '[]',
            ]
        );
        MessageMediaService::save('group_message', $messageId, $payload);
        return ['target_type' => $actualType, 'room_id' => $roomId, 'message_id' => $messageId];
    }

    private static function forwardToService(array $user, array $payload): array
    {
        $session = Database::one(
            "SELECT id FROM service_sessions WHERE app_id = ? AND user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1 FOR UPDATE",
            [(int) $user['app_id'], (int) $user['id']]
        );
        $sessionId = $session === null
            ? Database::insert(
                'INSERT INTO service_sessions
                 (admin_id, app_id, user_id, subject, status, last_message_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], '动态分享', 'open']
            )
            : (int) $session['id'];
        $messageId = Database::insert(
            'INSERT INTO service_messages (admin_id, app_id, session_id, sender_type, sender_id, content, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, NOW())',
            [(int) $user['admin_id'], (int) $user['app_id'], $sessionId, 'user', (int) $user['id'], '[动态]']
        );
        MessageMediaService::save('service_message', $messageId, $payload);
        Database::execute('UPDATE service_sessions SET last_message_at = NOW(), updated_at = NOW() WHERE id = ?', [$sessionId]);
        return ['target_type' => 'service', 'session_id' => $sessionId, 'message_id' => $messageId];
    }

    private static function find(array $user, int $momentId, bool $includeDeleted): array
    {
        $deleted = $includeDeleted ? '' : ' AND m.deleted_at IS NULL';
        $row = Database::one(
            "SELECT m.*, u.uid, u.account, p.nickname, p.avatar, p.title AS user_title
             FROM user_moments m INNER JOIN users u ON u.id = m.user_id
             LEFT JOIN user_profiles p ON p.user_id = m.user_id
             WHERE m.id = ? AND m.admin_id = ? AND m.app_id = ? AND m.status = 1
               AND (m.audit_status = 'approved' OR m.user_id = ?){$deleted}",
            [$momentId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        if ($row === null || !MomentVisibilityService::canView($row, $user, true)) throw new HttpException('动态不存在或你无权查看', 404, 404);
        $row = MessageMediaService::hydrate([$row], 'moment', (int) $user['app_id'])[0];
        self::decorate($row, $user);
        return $row;
    }

    private static function mediaPayload(array $user, string $content, $attachments): array
    {
        if (!is_array($attachments)) throw new HttpException('动态媒体格式错误', 0, 422);
        if (count($attachments) > self::MAX_MEDIA_COUNT) throw new HttpException('一条动态最多发布 9 张图片或视频', 0, 422);
        foreach ($attachments as $attachment) {
            $type = is_array($attachment) ? strtolower((string) ($attachment['media_type'] ?? '')) : '';
            if (!in_array($type, ['image', 'video'], true)) throw new HttpException('动态附件只支持图片或视频', 0, 422);
        }
        return MessageMediaService::userPayload($user, ['content' => $content, 'attachments' => $attachments]);
    }

    private static function requestedVisibleDays(array $data, ?int $fallback): ?int
    {
        if (!array_key_exists('visible_days', $data)) return $fallback;
        $value = $data['visible_days'];
        if ($value === null || $value === '' || strtolower((string) $value) === 'inherit') return null;
        return MomentVisibilityService::normalizeDays($value);
    }

    private static function location(array $user, array $data): array
    {
        $name = trim((string) ($data['location_name'] ?? ''));
        if ($name === '') return ['', null, null];
        $latitude = self::coordinate($data['latitude'] ?? null, -90, 90, '纬度');
        $longitude = self::coordinate($data['longitude'] ?? null, -180, 180, '经度');
        $currentLatitude = self::coordinate($data['current_latitude'] ?? null, -90, 90, '当前位置纬度');
        $currentLongitude = self::coordinate($data['current_longitude'] ?? null, -180, 180, '当前位置经度');
        $radius = max(0.5, min(30.0, (float) AppService::setting((int) $user['app_id'], 'moment_location_radius_km', 5)));
        $distance = self::distanceKm($latitude, $longitude, $currentLatitude, $currentLongitude);
        if ($distance > $radius) throw new HttpException('只能选择当前位置附近 ' . self::number($radius) . ' 公里内的地点', 0, 422, ['distance_km' => round($distance, 2)]);
        return [mb_substr($name, 0, 200), $latitude, $longitude];
    }

    private static function coordinate($value, float $min, float $max, string $label): float
    {
        if ($value === null || $value === '' || !is_numeric($value)) throw new HttpException('选择位置需要提供' . $label, 0, 422);
        $number = (float) $value;
        if ($number < $min || $number > $max) throw new HttpException($label . '超出有效范围', 0, 422);
        return $number;
    }

    private static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earth * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    private static function ensureOwnerAndWindow(array $moment, array $user): void
    {
        self::ensureOwner($moment, $user);
        $createdAt = strtotime((string) $moment['created_at']);
        if ($createdAt === false || time() - $createdAt > self::EDIT_WINDOW_SECONDS) {
            throw new HttpException('动态已超过 2 分钟，不能再修改内容', 0, 422);
        }
    }

    private static function ensureOwner(array $moment, array $user): void
    {
        if ((int) $moment['user_id'] !== (int) $user['id']) {
            throw new HttpException('只能管理自己的动态', 0, 403);
        }
    }

    private static function ensureApprovedForInteraction(array $moment): void
    {
        if ((string) ($moment['audit_status'] ?? 'approved') !== 'approved') {
            throw new HttpException('动态尚未审核通过，暂不能互动或转发', 403, 403, [
                'audit_status' => (string) ($moment['audit_status'] ?? 'pending'),
                'audit_reason' => (string) ($moment['audit_reason'] ?? ''),
            ]);
        }
    }

    private static function assertApprovedMomentParentChain(
        int $momentId, array $parent, int $adminId, int $appId
    ): void {
        $current = $parent;
        $visited = [];
        for ($depth = 0; $depth < 64; $depth++) {
            $currentId = (int) ($current['id'] ?? 0);
            if ($currentId <= 0 || isset($visited[$currentId])) {
                throw new HttpException('评论回复关系异常，暂不能公开回复', 0, 409);
            }
            $visited[$currentId] = true;
            if ((int) ($current['status'] ?? 0) !== 1
                || (string) ($current['audit_status'] ?? '') !== 'approved') {
                throw new HttpException('上级评论尚未审核通过，暂不能公开回复', 0, 409);
            }
            $nextId = (int) ($current['parent_id'] ?? 0);
            if ($nextId <= 0) return;
            $current = Database::one(
                'SELECT id, parent_id, audit_status, status FROM moment_comments
                 WHERE id = ? AND moment_id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [$nextId, $momentId, $adminId, $appId]
            );
            if ($current === null) {
                throw new HttpException('上级评论不存在，暂不能公开回复', 0, 409);
            }
        }
        throw new HttpException('评论回复层级过深，暂不能公开回复', 0, 409);
    }

    private static function decorate(array &$item, array $viewer): void
    {
        foreach (['id', 'admin_id', 'app_id', 'user_id'] as $key) $item[$key] = (int) $item[$key];
        $viewerId = (int) $viewer['id'];
        $created = new \DateTimeImmutable((string) $item['created_at']);
        $owner = (int) $item['user_id'] === $viewerId;
        $withinWindow = time() - $created->getTimestamp() <= self::EDIT_WINDOW_SECONDS;
        $item['display_name'] = trim((string) ($item['nickname'] ?? '')) !== '' ? (string) $item['nickname'] : (string) $item['account'];
        $item['audit_status_name'] = self::auditStatusName((string) ($item['audit_status'] ?? 'approved'));
        $item['is_edited'] = !empty($item['edited_at']);
        $item['can_edit'] = $owner && $withinWindow && empty($item['deleted_at']);
        $item['can_edit_visibility'] = $owner && empty($item['deleted_at']);
        $item['can_delete'] = $owner && empty($item['deleted_at']);
        $item['is_pinned'] = (int) ($item['is_pinned'] ?? 0) === 1;
        $item['pin_order'] = (int) ($item['pin_order'] ?? 0);
        $item['can_pin'] = $owner && empty($item['deleted_at']);
        $item['year'] = (int) $created->format('Y');
        $item['month'] = (int) $created->format('m');
        $item['day'] = (int) $created->format('d');
        $item['time_label'] = $created->format('H:i');
        $item['date_label'] = $created->format('Y-m-d');
        $item['latitude'] = $item['latitude'] === null ? null : (float) $item['latitude'];
        $item['longitude'] = $item['longitude'] === null ? null : (float) $item['longitude'];
        $item['visible_days'] = $item['visible_days'] === null ? null : (int) $item['visible_days'];
        $item['visibility_user_ids'] = MomentVisibilityService::decodeIds($item['visibility_user_ids_json'] ?? null);
        unset($item['visibility_user_ids_json']);
        $likePresentation = self::likePresentation($item, $viewer, 12, 0);
        $item['like_count'] = (int) $likePresentation['visibility']['total_count'];
        $item['visible_likers'] = $likePresentation['items'];
        $item['like_visibility'] = $likePresentation['visibility'];
        $item['comment_count'] = self::count(
            'moment_comments', (int) $item['id'], " AND status = 1 AND audit_status = 'approved'"
        );
        $item['favorite_count'] = self::count('moment_favorites', (int) $item['id']);
        $item['forward_count'] = self::count('moment_forwards', (int) $item['id']);
        $item['is_liked'] = Database::one('SELECT id FROM moment_likes WHERE moment_id = ? AND user_id = ? LIMIT 1', [(int) $item['id'], $viewerId]) !== null;
        $item['is_favorited'] = Database::one('SELECT id FROM moment_favorites WHERE moment_id = ? AND user_id = ? LIMIT 1', [(int) $item['id'], $viewerId]) !== null;
    }

    /**
     * The public count and the visible identities are intentionally separate:
     * privacy filtering must not alter the engagement count.
     */
    private static function likePresentation(array $moment, array $viewer, int $limit, int $offset): array
    {
        $momentId = (int) $moment['id'];
        $appId = (int) $moment['app_id'];
        $authorId = (int) $moment['user_id'];
        $viewerId = (int) $viewer['id'];
        $owner = $authorId === $viewerId;
        $nonFriendVisible = (bool) AppService::setting($appId, 'moment_like_non_friend_visible', false);
        $total = self::count('moment_likes', $momentId);

        $privacySql = '';
        $privacyParams = [];
        if (!$owner && !$nonFriendVisible) {
            $privacySql =
                ' AND (ml.user_id = ? OR (' .
                'EXISTS (SELECT 1 FROM friends viewer_friend ' .
                'WHERE viewer_friend.app_id = ml.app_id AND viewer_friend.user_id = ? ' .
                'AND viewer_friend.friend_user_id = ml.user_id AND viewer_friend.status = 1) ' .
                'AND (ml.user_id = ? OR EXISTS (SELECT 1 FROM friends author_friend ' .
                'WHERE author_friend.app_id = ml.app_id AND author_friend.user_id = ? ' .
                'AND author_friend.friend_user_id = ml.user_id AND author_friend.status = 1))))';
            $privacyParams = [$viewerId, $viewerId, $authorId, $authorId];
        }

        $visibleTotal = $owner || $nonFriendVisible
            ? $total
            : (int) (Database::one(
                'SELECT COUNT(*) AS total FROM moment_likes ml WHERE ml.moment_id = ? AND ml.app_id = ?' . $privacySql,
                array_merge([$momentId, $appId], $privacyParams)
            )['total'] ?? 0);

        $safeLimit = max(1, min(100, $limit));
        $safeOffset = max(0, $offset);
        $items = Database::all(
            'SELECT ml.user_id, ml.created_at, u.uid, u.account, p.nickname, p.avatar, ' .
            'EXISTS (SELECT 1 FROM friends viewer_relation WHERE viewer_relation.app_id = ml.app_id ' .
            'AND viewer_relation.user_id = ? AND viewer_relation.friend_user_id = ml.user_id ' .
            'AND viewer_relation.status = 1) AS is_friend, ' .
            'EXISTS (SELECT 1 FROM friends author_relation WHERE author_relation.app_id = ml.app_id ' .
            'AND author_relation.user_id = ? AND author_relation.friend_user_id = ml.user_id ' .
            'AND author_relation.status = 1) AS is_author_friend ' .
            'FROM moment_likes ml INNER JOIN users u ON u.id = ml.user_id ' .
            'LEFT JOIN user_profiles p ON p.user_id = ml.user_id ' .
            'WHERE ml.moment_id = ? AND ml.app_id = ?' . $privacySql .
            " ORDER BY ml.id DESC LIMIT {$safeLimit} OFFSET {$safeOffset}",
            array_merge([$viewerId, $authorId, $momentId, $appId], $privacyParams)
        );
        foreach ($items as &$item) {
            $item['user_id'] = (int) $item['user_id'];
            $item['display_name'] = trim((string) ($item['nickname'] ?? '')) !== ''
                ? (string) $item['nickname']
                : (string) $item['account'];
            $item['is_self'] = (int) $item['user_id'] === $viewerId;
            $item['is_friend'] = (bool) $item['is_friend'];
            $item['is_common_friend'] = $item['is_friend'] && (bool) $item['is_author_friend'];
            unset($item['is_author_friend']);
        }
        unset($item);

        $mode = $owner ? 'owner' : ($nonFriendVisible ? 'all' : 'mutual_friends');
        $hidden = max(0, $total - $visibleTotal);
        if ($owner) {
            $label = '动态作者可查看全部点赞者';
        } elseif ($nonFriendVisible) {
            $label = '当前应用允许查看全部点赞者';
        } elseif ($hidden > 0) {
            $label = '仅显示共同好友，另有 ' . $hidden . ' 位点赞者身份已隐藏';
        } else {
            $label = '点赞者身份仅共同好友可见';
        }

        return [
            'items' => $items,
            'visibility' => [
                'mode' => $mode,
                'non_friend_visible' => $nonFriendVisible,
                'total_count' => $total,
                'visible_count' => $visibleTotal,
                'returned_count' => count($items),
                'hidden_count' => $hidden,
                'label' => $label,
            ],
        ];
    }

    private static function decorateComment(array &$item, array $moment, int $viewerId): void
    {
        foreach (['id', 'admin_id', 'app_id', 'moment_id', 'user_id'] as $key) $item[$key] = (int) $item[$key];
        $item['parent_id'] = $item['parent_id'] === null ? null : (int) $item['parent_id'];
        $item['sticker_id'] = ($item['sticker_id'] ?? null) === null ? null : (int) $item['sticker_id'];
        $item['like_count'] = (int) ($item['like_count'] ?? 0);
        $item['is_liked'] = (bool) ($item['is_liked'] ?? false);
        $item['display_name'] = trim((string) ($item['nickname'] ?? '')) !== '' ? (string) $item['nickname'] : (string) $item['account'];
        $item['audit_status_name'] = self::auditStatusName((string) ($item['audit_status'] ?? 'approved'));
        $item['parent_display_name'] = trim((string) ($item['parent_nickname'] ?? '')) !== ''
            ? (string) $item['parent_nickname']
            : (string) ($item['parent_account'] ?? '');
        $item['can_delete'] = (int) $item['user_id'] === $viewerId || (int) $moment['user_id'] === $viewerId;
    }

    private static function count(string $table, int $momentId, string $extra = ''): int
    {
        $allowed = ['moment_likes', 'moment_comments', 'moment_favorites', 'moment_forwards'];
        if (!in_array($table, $allowed, true)) return 0;
        return (int) (Database::one("SELECT COUNT(*) AS total FROM {$table} WHERE moment_id = ?{$extra}", [$momentId])['total'] ?? 0);
    }

    private static function auditStatusName(string $status): string
    {
        return [
            'pending' => '待审核',
            'approved' => '审核通过',
            'rejected' => '审核未通过',
        ][$status] ?? '待审核';
    }

    private static function notifyOwner(array $moment, array $actor, string $type, string $title, string $content, array $extra = []): void
    {
        if ((int) $moment['user_id'] === (int) $actor['id']) return;
        self::notifyUser((int) $moment['user_id'], $actor, $moment, $type, $title, $content, $extra);
    }

    private static function notifyUser(int $userId, array $actor, array $moment, string $type, string $title, string $content, array $extra = []): void
    {
        $receiver = NotificationService::user((int) $actor['admin_id'], (int) $actor['app_id'], $userId);
        if ($receiver === null) return;
        $profile = Database::one('SELECT nickname, avatar FROM user_profiles WHERE user_id = ?', [(int) $actor['id']]);
        $payload = array_merge([
            'moment_id' => (int) $moment['id'],
            'actor_user_id' => (int) $actor['id'],
            'actor_name' => self::displayName($actor),
            'actor_avatar' => (string) ($profile['avatar'] ?? ''),
            'target_type' => 'moment',
            'moment_excerpt' => self::momentExcerpt($moment),
            'route' => 'moment_detail',
        ], $extra);
        NotificationService::send($receiver, $type, $title, $content, $payload);
    }

    private static function momentExcerpt(array $moment): string
    {
        $content = trim((string) ($moment['content'] ?? ''));
        if ($content !== '') return mb_substr($content, 0, 160);
        return ((int) ($moment['media_count'] ?? 0)) > 0 ? '[媒体动态]' : '这条动态';
    }

    private static function displayName(array $user): string
    {
        $profile = Database::one('SELECT nickname FROM user_profiles WHERE user_id = ?', [(int) $user['id']]);
        $nickname = trim((string) ($profile['nickname'] ?? ''));
        return $nickname !== '' ? $nickname : (string) ($user['account'] ?? ('用户 ' . $user['uid']));
    }

    private static function purgeExpired(int $appId): void
    {
        $rows = Database::all('SELECT id FROM user_moments WHERE app_id = ? AND deleted_at IS NOT NULL AND delete_expires_at < NOW() LIMIT 100', [$appId]);
        foreach ($rows as $row) {
            Database::execute('DELETE FROM media_attachments WHERE app_id = ? AND target_type = ? AND target_id = ?', [$appId, 'moment', (int) $row['id']]);
            Database::execute('DELETE FROM user_moments WHERE id = ? AND app_id = ?', [(int) $row['id'], $appId]);
        }
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
