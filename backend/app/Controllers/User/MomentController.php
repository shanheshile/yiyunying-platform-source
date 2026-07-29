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
        $where = ['m.admin_id = ?', 'm.app_id = ?', 'm.status = 1', 'm.deleted_at IS NULL'];
        $params = [(int) $user['admin_id'], (int) $user['app_id']];
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
            if (MomentVisibilityService::canView($row, $user)) $visible[] = $row;
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
        $momentId = Database::transaction(static function () use (
            $user, $content, $locationName, $latitude, $longitude, $payload,
            $visibilityMode, $visibleDays, $visibilityUserIds
        ): int {
            $id = Database::insert(
                'INSERT INTO user_moments
                 (admin_id, app_id, user_id, content, location_name, latitude, longitude,
                  visibility_mode, visible_days, visibility_user_ids_json, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $content,
                    $locationName, $latitude, $longitude, $visibilityMode, $visibleDays,
                    MomentVisibilityService::encodeIds($visibilityUserIds),
                ]
            );
            MessageMediaService::save('moment', $id, $payload);
            return $id;
        });
        LogService::userOperation($request, $user, 'moment', 'create', $momentId, [
            'media_count' => count($payload['attachments']),
            'visibility_mode' => $visibilityMode,
            'visible_days' => $visibleDays,
        ]);
        return Response::success(['moment' => self::find($user, $momentId, false)], '动态发布成功', 201);
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
            : MomentVisibilityService::decodeIds($before['visibility_user_ids_json'] ?? null);
        Database::transaction(static function () use (
            $user, $momentId, $content, $locationName, $latitude, $longitude, $payload,
            $visibilityMode, $visibleDays, $visibilityUserIds
        ): void {
            $affected = Database::execute(
                'UPDATE user_moments
                 SET content = ?, location_name = ?, latitude = ?, longitude = ?, visibility_mode = ?,
                     visible_days = ?, visibility_user_ids_json = ?, edited_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ? AND deleted_at IS NULL
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 120 SECOND)',
                [
                    $content, $locationName, $latitude, $longitude, $visibilityMode, $visibleDays,
                    MomentVisibilityService::encodeIds($visibilityUserIds), $momentId,
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
                ]
            );
            if ($affected !== 1) throw new HttpException('动态已超过 2 分钟，不能再修改', 0, 422);
            if ($payload !== null) MessageMediaService::replace('moment', $momentId, $payload);
        });
        LogService::userOperation($request, $user, 'moment', 'update', $momentId);
        return Response::success(['moment' => self::find($user, $momentId, false)], '动态已更新');
    }

    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $momentId = (int) $params['moment_id'];
        $moment = self::find($user, $momentId, true);
        self::ensureOwnerAndWindow($moment, $user);
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
            self::notifyOwner($moment, $user, 'moment_like', '动态收到点赞', self::displayName($user) . '赞了你的动态');
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
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM moment_comments WHERE moment_id = ? AND status = 1',
            [(int) $moment['id']]
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT c.*, u.uid, u.account, p.nickname, p.avatar,
                    pu.uid AS parent_uid, pu.account AS parent_account, pp.nickname AS parent_nickname
             FROM moment_comments c
             INNER JOIN users u ON u.id = c.user_id
             LEFT JOIN user_profiles p ON p.user_id = c.user_id
             LEFT JOIN moment_comments pc ON pc.id = c.parent_id
             LEFT JOIN users pu ON pu.id = pc.user_id
             LEFT JOIN user_profiles pp ON pp.user_id = pc.user_id
             WHERE c.moment_id = ? AND c.status = 1
             ORDER BY c.id ASC LIMIT {$limit} OFFSET {$offset}",
            [(int) $moment['id']]
        );
        foreach ($items as &$item) self::decorateComment($item, $moment, (int) $user['id']);
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function createComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $moment = self::find($user, (int) $params['moment_id'], false);
        $content = trim((string) $request->input('content', ''));
        if ($content === '' || mb_strlen($content) > 2000) throw new HttpException('评论内容应为 1 到 2000 个字符', 0, 422);
        $parentId = max(0, (int) $request->input('parent_id', 0));
        $parent = null;
        if ($parentId > 0) {
            $parent = Database::one('SELECT * FROM moment_comments WHERE id = ? AND moment_id = ? AND status = 1', [$parentId, (int) $moment['id']]);
            if ($parent === null) throw new HttpException('回复的评论不存在', 404, 404);
        }
        $commentId = Database::insert(
            'INSERT INTO moment_comments (admin_id, app_id, moment_id, user_id, parent_id, content, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $moment['id'], (int) $user['id'], $parentId > 0 ? $parentId : null, $content]
        );
        self::notifyOwner($moment, $user, 'moment_comment', '动态收到评论', self::displayName($user) . '评论了你的动态');
        if ($parent !== null && (int) $parent['user_id'] !== (int) $moment['user_id'] && (int) $parent['user_id'] !== (int) $user['id']) {
            self::notifyUser((int) $parent['user_id'], $user, (int) $moment['id'], 'moment_reply', '评论收到回复', self::displayName($user) . '回复了你的评论');
        }
        $item = Database::one(
            'SELECT c.*, u.uid, u.account, p.nickname, p.avatar FROM moment_comments c
             INNER JOIN users u ON u.id = c.user_id LEFT JOIN user_profiles p ON p.user_id = c.user_id WHERE c.id = ?',
            [$commentId]
        );
        self::decorateComment($item, $moment, (int) $user['id']);
        return Response::success(['comment' => $item], '评论成功', 201);
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
        $targetType = strtolower(trim((string) $request->input('target_type', 'external')));
        if ($targetType === 'room') $targetType = 'group';
        if (!in_array($targetType, ['private', 'group', 'service', 'forum', 'bounty', 'external'], true)) {
            throw new HttpException('转发目标类型不正确', 0, 422);
        }
        $targetId = max(0, (int) $request->input('target_id', 0));
        if (in_array($targetType, ['private', 'group'], true) && $targetId <= 0) {
            throw new HttpException('请选择有效的转发目标', 0, 422);
        }
        $payload = in_array($targetType, ['private', 'group', 'service'], true)
            ? self::forwardPayload($user, $moment)
            : null;
        $result = Database::transaction(static function () use ($user, $moment, $targetType, $targetId, $payload): array {
            $delivery = match ($targetType) {
                'private' => self::forwardToPrivate($user, $targetId, $payload),
                'group' => self::forwardToGroup($user, $targetId, $payload),
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
        ]), in_array($targetType, ['private', 'group', 'service'], true) ? '动态已发送' : '转发记录已创建', 201);
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

    private static function forwardToGroup(array $user, int $roomId, array $payload): array
    {
        $room = ChatRoomService::userRoom($user, $roomId, true);
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
        return ['target_type' => 'group', 'room_id' => $roomId, 'message_id' => $messageId];
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
             WHERE m.id = ? AND m.admin_id = ? AND m.app_id = ? AND m.status = 1{$deleted}",
            [$momentId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        if ($row === null || !MomentVisibilityService::canView($row, $user)) throw new HttpException('动态不存在或你无权查看', 404, 404);
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
        if ((int) $moment['user_id'] !== (int) $user['id']) throw new HttpException('只能修改或删除自己的动态', 0, 403);
        $createdAt = strtotime((string) $moment['created_at']);
        if ($createdAt === false || time() - $createdAt > self::EDIT_WINDOW_SECONDS) throw new HttpException('动态已超过 2 分钟，不能再修改或删除', 0, 422);
    }

    private static function decorate(array &$item, array $viewer): void
    {
        foreach (['id', 'admin_id', 'app_id', 'user_id'] as $key) $item[$key] = (int) $item[$key];
        $viewerId = (int) $viewer['id'];
        $created = new \DateTimeImmutable((string) $item['created_at']);
        $owner = (int) $item['user_id'] === $viewerId;
        $withinWindow = time() - $created->getTimestamp() <= self::EDIT_WINDOW_SECONDS;
        $item['display_name'] = trim((string) ($item['nickname'] ?? '')) !== '' ? (string) $item['nickname'] : (string) $item['account'];
        $item['is_edited'] = !empty($item['edited_at']);
        $item['can_edit'] = $owner && $withinWindow && empty($item['deleted_at']);
        $item['can_delete'] = $item['can_edit'];
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
        $item['comment_count'] = self::count('moment_comments', (int) $item['id'], ' AND status = 1');
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
        $item['display_name'] = trim((string) ($item['nickname'] ?? '')) !== '' ? (string) $item['nickname'] : (string) $item['account'];
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

    private static function notifyOwner(array $moment, array $actor, string $type, string $title, string $content): void
    {
        if ((int) $moment['user_id'] === (int) $actor['id']) return;
        self::notifyUser((int) $moment['user_id'], $actor, (int) $moment['id'], $type, $title, $content);
    }

    private static function notifyUser(int $userId, array $actor, int $momentId, string $type, string $title, string $content): void
    {
        $receiver = NotificationService::user((int) $actor['admin_id'], (int) $actor['app_id'], $userId);
        if ($receiver === null) return;
        NotificationService::send($receiver, $type, $title, $content, [
            'moment_id' => $momentId,
            'actor_user_id' => (int) $actor['id'],
            'route' => 'moment_detail',
        ]);
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
