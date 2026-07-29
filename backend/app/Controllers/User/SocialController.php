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
use Yiyunying\Services\NotificationService;
use Yiyunying\Services\WalletService;
use Yiyunying\Services\ContentTagService;
use Yiyunying\Services\IdentityService;
use Yiyunying\Services\MessageMediaService;

final class SocialController
{
    public static function profile(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'social'); $targetId = IdentityService::resolveUserReference((int) $user['app_id'], $params['user_id']);
        $profile = Database::one(
            'SELECT u.id, u.uid, u.uid AS public_no, u.account, u.email, u.phone, u.created_at,
                    p.nickname, p.qq, p.avatar, p.background, p.signature, p.gender, p.birthday, p.title, p.public_profile,
                    COALESCE(mp.allow_friend_requests, 1) AS allow_friend_requests,
                    COALESCE(mp.accept_stranger_messages, 1) AS accept_stranger_messages,
                    w.level_code, w.experience, w.vip_expired_at,
                    (SELECT COUNT(*) FROM user_follows f WHERE f.app_id = u.app_id AND f.followed_user_id = u.id) AS follower_count,
                    (SELECT COUNT(*) FROM user_follows f WHERE f.app_id = u.app_id AND f.follower_user_id = u.id) AS following_count,
                    (SELECT COALESCE(SUM(profile_like.like_count), 0) FROM user_profile_likes profile_like
                     WHERE profile_like.app_id = u.app_id AND profile_like.target_user_id = u.id) AS like_count
             FROM users u INNER JOIN user_profiles p ON p.user_id = u.id INNER JOIN user_wallets w ON w.user_id = u.id
             LEFT JOIN user_message_preferences mp ON mp.user_id = u.id
             WHERE u.id = ? AND u.admin_id = ? AND u.app_id = ? AND u.status = 1 AND u.deleted_at IS NULL',
            [$targetId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        if ($profile === null) throw new HttpException('用户不存在', 404, 404);
        $canViewDetails = $targetId === (int) $user['id'] || (int) $profile['public_profile'] === 1;
        if (!$canViewDetails) {
            foreach (['email', 'phone', 'qq', 'background', 'signature', 'gender', 'birthday', 'level_code', 'experience', 'vip_expired_at'] as $field) {
                unset($profile[$field]);
            }
        }
        $profile['profile_visibility'] = $canViewDetails ? 'full' : 'basic';
        $profile['details_hidden'] = !$canViewDetails;
        $profile['visibility_notice'] = $canViewDetails
            ? '该用户资料可见'
            : '该用户隐藏了详细资料，仅展示基础公开信息';
        $profile['followed'] = Database::one('SELECT id FROM user_follows WHERE app_id = ? AND follower_user_id = ? AND followed_user_id = ?', [(int) $user['app_id'], (int) $user['id'], $targetId]) !== null;
        $profile['blocked'] = Database::one('SELECT id FROM user_blacklist WHERE app_id = ? AND user_id = ? AND blocked_user_id = ?', [(int) $user['app_id'], (int) $user['id'], $targetId]) !== null;
        $profile['blocked_by'] = Database::one('SELECT id FROM user_blacklist WHERE app_id = ? AND user_id = ? AND blocked_user_id = ?', [(int) $user['app_id'], $targetId, (int) $user['id']]) !== null;
        $profile['is_self'] = $targetId === (int) $user['id'];
        $profile['is_friend'] = Database::one(
            'SELECT id FROM friends WHERE app_id = ? AND user_id = ? AND friend_user_id = ? AND status = 1',
            [(int) $user['app_id'], (int) $user['id'], $targetId]
        ) !== null;
        $profile['outgoing_friend_request_pending'] = Database::one(
            "SELECT id FROM friend_requests WHERE app_id = ? AND from_user_id = ? AND to_user_id = ?
             AND status IN ('pending','ignored') AND expired_at > NOW()",
            [(int) $user['app_id'], (int) $user['id'], $targetId]
        ) !== null;
        $profile['incoming_friend_request_pending'] = Database::one(
            "SELECT id FROM friend_requests WHERE app_id = ? AND from_user_id = ? AND to_user_id = ?
             AND status IN ('pending','ignored') AND expired_at > NOW()",
            [(int) $user['app_id'], $targetId, (int) $user['id']]
        ) !== null;
        $profile['friend_request_enabled'] = (bool) $profile['allow_friend_requests'];
        $profile['can_send_friend_request'] = !$profile['is_self'] && !$profile['is_friend']
            && !$profile['blocked'] && !$profile['blocked_by']
            && !$profile['outgoing_friend_request_pending'] && $profile['friend_request_enabled'];
        $profile['can_send_message'] = !$profile['is_self'] && !$profile['blocked'] && !$profile['blocked_by']
            && ($profile['is_friend'] || (bool) $profile['accept_stranger_messages']);
        $profile['message_permission_notice'] = $profile['can_send_message']
            ? ($profile['is_friend'] ? '可以直接发送好友消息' : '对方允许接收陌生人消息')
            : ($profile['blocked'] || $profile['blocked_by'] ? '当前关系无法发送消息' : '对方已关闭陌生人消息');
        $profile['my_like_count_today'] = (int) (Database::one(
            'SELECT like_count FROM user_profile_likes WHERE app_id = ? AND liker_user_id = ? AND target_user_id = ? AND like_date = CURDATE()',
            [(int) $user['app_id'], (int) $user['id'], $targetId]
        )['like_count'] ?? 0);
        $profile['like_policy'] = [
            'per_action_limit' => (int) AppService::setting((int) $user['app_id'], 'profile_like_per_action_limit', 10),
            'daily_limit' => (int) AppService::setting((int) $user['app_id'], 'profile_like_daily_limit', 50),
            'unit' => '次',
        ];
        $visibilitySql = $targetId === (int) $user['id'] ? '' : ' AND is_public = 1';
        $profile['notes'] = ContentTagService::hydrate(MessageMediaService::hydrate(Database::all(
            'SELECT id, title, content, content_type, word_count, tags_json, is_public, created_at, updated_at
             FROM documents WHERE app_id = ? AND user_id = ? AND deleted_at IS NULL AND status = 1' . $visibilitySql . ' ORDER BY id DESC LIMIT 30',
            [(int) $user['app_id'], $targetId]
        ), 'note', (int) $user['app_id']));
        $profile['posts'] = ContentTagService::hydrate(Database::all(
            "SELECT id, plate_id, title, content, tags_json, like_count, comment_count, created_at
             FROM forum_posts WHERE app_id = ? AND user_id = ? AND deleted_at IS NULL AND status = 1 AND audit_status = 'approved'
             ORDER BY id DESC LIMIT 30",
            [(int) $user['app_id'], $targetId]
        ));
        return Response::success(['profile' => $profile]);
    }

    public static function likeProfile(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'social');
        $targetId = IdentityService::resolveUserReference((int) $user['app_id'], $params['user_id']);
        self::target($user, $targetId);
        if ($targetId === (int) $user['id']) throw new HttpException('不能给自己的主页点赞', 0, 422);
        $perAction = max(1, (int) AppService::setting((int) $user['app_id'], 'profile_like_per_action_limit', 10));
        $dailyLimit = max($perAction, (int) AppService::setting((int) $user['app_id'], 'profile_like_daily_limit', 50));
        $count = Validator::integer($request->input('count', 1), 'count', 1, $perAction);
        $current = (int) (Database::one(
            'SELECT like_count FROM user_profile_likes WHERE app_id = ? AND liker_user_id = ? AND target_user_id = ? AND like_date = CURDATE()',
            [(int) $user['app_id'], (int) $user['id'], $targetId]
        )['like_count'] ?? 0);
        if ($current + $count > $dailyLimit) throw new HttpException('今日给该用户的点赞次数已达到上限', 0, 422, [
            'daily_limit' => $dailyLimit, 'used' => $current, 'remaining' => max(0, $dailyLimit - $current), 'unit' => '次',
        ]);
        Database::execute(
            'INSERT INTO user_profile_likes (admin_id, app_id, liker_user_id, target_user_id, like_date, like_count, created_at, updated_at)
             VALUES (?, ?, ?, ?, CURDATE(), ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE like_count = like_count + VALUES(like_count), updated_at = NOW()',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $targetId, $count]
        );
        $target = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], $targetId);
        if ($target !== null) NotificationService::send($target, 'profile_like', '主页收到点赞', '有用户给你的主页点了 ' . $count . ' 个赞', ['user_id' => (int) $user['id'], 'count' => $count]);
        return Response::success(['added' => $count, 'today_count' => $current + $count, 'daily_limit' => $dailyLimit, 'unit' => '次'], '点赞成功');
    }

    public static function unlikeProfile(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'social');
        $targetId = IdentityService::resolveUserReference((int) $user['app_id'], $params['user_id']);
        $row = Database::one(
            'SELECT * FROM user_profile_likes WHERE app_id = ? AND liker_user_id = ? AND target_user_id = ? AND like_date = CURDATE()',
            [(int) $user['app_id'], (int) $user['id'], $targetId]
        );
        if ($row === null) throw new HttpException('今天还没有给该用户点赞', 404, 404);
        $remove = max(1, min((int) $row['like_count'], (int) $request->input('count', $row['like_count'])));
        if ($remove >= (int) $row['like_count']) Database::execute('DELETE FROM user_profile_likes WHERE id = ?', [(int) $row['id']]);
        else Database::execute('UPDATE user_profile_likes SET like_count = like_count - ?, updated_at = NOW() WHERE id = ?', [$remove, (int) $row['id']]);
        return Response::success(['removed' => $remove, 'today_count' => max(0, (int) $row['like_count'] - $remove), 'unit' => '次'], '已撤回今天的点赞');
    }

    public static function follow(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'social'); $targetId = IdentityService::resolveUserReference((int) $user['app_id'], $params['user_id']); self::target($user, $targetId);
        if ($targetId === (int) $user['id']) throw new HttpException('不能关注自己', 0, 422);
        self::assertNotBlocked($user, $targetId);
        $active = Database::transaction(static function () use ($user, $targetId): bool {
            $existing = Database::one('SELECT id FROM user_follows WHERE app_id = ? AND follower_user_id = ? AND followed_user_id = ?', [(int) $user['app_id'], (int) $user['id'], $targetId]);
            if ($existing !== null) { Database::execute('DELETE FROM user_follows WHERE id = ?', [(int) $existing['id']]); return false; }
            Database::execute('INSERT INTO user_follows (admin_id, app_id, follower_user_id, followed_user_id, created_at) VALUES (?, ?, ?, ?, NOW())', [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $targetId]);
            $target = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], $targetId);
            if ($target !== null) NotificationService::send($target, 'new_follower', '收到新关注', '有用户关注了你', ['user_id' => (int) $user['id']]);
            return true;
        });
        return Response::success(['followed' => $active], $active ? '关注成功' : '已取消关注');
    }

    public static function followStatus(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'social');
        $targetId = IdentityService::resolveUserReference((int) $user['app_id'], $params['user_id']);
        self::target($user, $targetId);
        $relations = Database::all(
            'SELECT follower_user_id, followed_user_id FROM user_follows
             WHERE app_id = ? AND ((follower_user_id = ? AND followed_user_id = ?)
                OR (follower_user_id = ? AND followed_user_id = ?))',
            [(int) $user['app_id'], (int) $user['id'], $targetId, $targetId, (int) $user['id']]
        );
        $following = false;
        $followedBy = false;
        foreach ($relations as $relation) {
            if ((int) $relation['follower_user_id'] === (int) $user['id']) $following = true;
            if ((int) $relation['follower_user_id'] === $targetId) $followedBy = true;
        }
        $blocked = Database::one(
            'SELECT id FROM user_blacklist WHERE app_id = ? AND user_id = ? AND blocked_user_id = ?',
            [(int) $user['app_id'], (int) $user['id'], $targetId]
        ) !== null;
        $blockedBy = Database::one(
            'SELECT id FROM user_blacklist WHERE app_id = ? AND user_id = ? AND blocked_user_id = ?',
            [(int) $user['app_id'], $targetId, (int) $user['id']]
        ) !== null;
        return Response::success([
            'user_id' => $targetId,
            'following' => $following,
            'followed_by' => $followedBy,
            'mutual_follow' => $following && $followedBy,
            'blocked' => $blocked,
            'blocked_by' => $blockedBy,
            'can_interact' => !$blocked && !$blockedBy,
        ]);
    }

    public static function searchUsers(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'social');
        $keyword = Validator::string($request->input('keyword', ''), '搜索关键词', 1, 80);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $appId = (int) $user['app_id'];
        $adminId = (int) $user['admin_id'];
        $currentUserId = (int) $user['id'];

        $matchSql = "(u.uid = ? OR u.account = ? OR LOCATE(?, u.account) > 0 OR LOCATE(?, COALESCE(p.nickname, '')) > 0)";
        $matchParams = [$keyword, $keyword, $keyword, $keyword];
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total
             FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE u.app_id = ? AND u.admin_id = ? AND u.status = 1 AND u.deleted_at IS NULL AND {$matchSql}",
            array_merge([$appId, $adminId], $matchParams)
        )['total'] ?? 0);

        $items = Database::all(
            "SELECT u.id AS user_id, u.uid, u.uid AS to_uid, u.account,
                    COALESCE(NULLIF(p.nickname, ''), u.account) AS nickname,
                    COALESCE(p.avatar, '') AS avatar, COALESCE(p.signature, '') AS signature,
                    COALESCE(p.title, '') AS title, COALESCE(p.public_profile, 1) AS public_profile,
                    COALESCE(mp.allow_friend_requests, 1) AS allow_friend_requests,
                    w.level_code, w.vip_expired_at,
                    CASE WHEN u.id = ? THEN 1 ELSE 0 END AS is_self,
                    EXISTS(SELECT 1 FROM friends fr
                           WHERE fr.app_id = u.app_id AND fr.user_id = ? AND fr.friend_user_id = u.id AND fr.status = 1) AS is_friend,
                    EXISTS(SELECT 1 FROM user_follows ff
                           WHERE ff.app_id = u.app_id AND ff.follower_user_id = ? AND ff.followed_user_id = u.id) AS following,
                    EXISTS(SELECT 1 FROM user_follows fb
                           WHERE fb.app_id = u.app_id AND fb.follower_user_id = u.id AND fb.followed_user_id = ?) AS followed_by,
                    EXISTS(SELECT 1 FROM user_blacklist bl
                           WHERE bl.app_id = u.app_id AND bl.user_id = ? AND bl.blocked_user_id = u.id) AS blocked,
                    EXISTS(SELECT 1 FROM user_blacklist bb
                           WHERE bb.app_id = u.app_id AND bb.user_id = u.id AND bb.blocked_user_id = ?) AS blocked_by,
                    (SELECT 'pending' FROM friend_requests frq
                     WHERE frq.app_id = u.app_id AND frq.from_user_id = ? AND frq.to_user_id = u.id
                        AND frq.status IN ('pending','ignored') AND frq.expired_at > NOW()
                      ORDER BY frq.id DESC LIMIT 1) AS outgoing_request_status,
                    (SELECT 'pending' FROM friend_requests frq
                     WHERE frq.app_id = u.app_id AND frq.from_user_id = u.id AND frq.to_user_id = ?
                        AND frq.status IN ('pending','ignored') AND frq.expired_at > NOW()
                      ORDER BY frq.id DESC LIMIT 1) AS incoming_request_status
             FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN user_wallets w ON w.user_id = u.id
             LEFT JOIN user_message_preferences mp ON mp.user_id = u.id
             WHERE u.app_id = ? AND u.admin_id = ? AND u.status = 1 AND u.deleted_at IS NULL AND {$matchSql}
             ORDER BY CASE
                        WHEN u.uid = ? THEN 0
                        WHEN u.account = ? THEN 1
                        WHEN COALESCE(p.nickname, '') = ? THEN 2
                        WHEN u.id = ? THEN 4
                        ELSE 3
                      END, u.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            array_merge(
                [$currentUserId, $currentUserId, $currentUserId, $currentUserId,
                 $currentUserId, $currentUserId, $currentUserId, $currentUserId,
                 $appId, $adminId],
                $matchParams,
                [$keyword, $keyword, $keyword, $currentUserId]
            )
        );

        foreach ($items as &$item) {
            foreach (['is_self', 'is_friend', 'following', 'followed_by', 'blocked', 'blocked_by', 'allow_friend_requests'] as $field) {
                $item[$field] = (bool) $item[$field];
            }
            $item['mutual_follow'] = $item['following'] && $item['followed_by'];
            $item['can_interact'] = !$item['blocked'] && !$item['blocked_by'];
            $canViewDetails = $item['is_self'] || (int) $item['public_profile'] === 1;
            $item['profile_visibility'] = $canViewDetails ? 'full' : 'basic';
            $item['profile_visibility_name'] = $canViewDetails ? '资料公开' : '仅显示基础资料';
            $item['details_hidden'] = !$canViewDetails;
            $item['friend_request_enabled'] = $item['allow_friend_requests'];
            $item['can_send_friend_request'] = !$item['is_self'] && !$item['is_friend']
                && !$item['blocked'] && !$item['blocked_by']
                && $item['outgoing_request_status'] !== 'pending' && $item['allow_friend_requests'];
            if (!$canViewDetails) {
                foreach (['signature', 'title', 'level_code', 'vip_expired_at'] as $field) unset($item[$field]);
            }
            if ($item['is_self']) $relationName = '这是你自己';
            elseif ($item['blocked']) $relationName = '已加入黑名单';
            elseif ($item['blocked_by']) $relationName = '对方已将你加入黑名单';
            elseif ($item['is_friend']) $relationName = '好友';
            elseif ($item['mutual_follow']) $relationName = '互相关注';
            elseif ($item['outgoing_request_status'] === 'pending') $relationName = '好友申请已发送';
            elseif ($item['incoming_request_status'] === 'pending') $relationName = '有待处理的好友申请';
            elseif ($item['following']) $relationName = '已关注';
            elseif ($item['followed_by']) $relationName = '对方关注了你';
            else $relationName = '陌生人';
            $item['relation_name'] = $relationName;
        }
        unset($item);

        $data = Pagination::data($items, $total, $page, $limit);
        $data['keyword'] = $keyword;
        $data['search_scope'] = 'current_app_only';
        $data['search_scope_name'] = '仅搜索当前应用内的用户';
        return Response::success($data);
    }

    public static function following(Request $request): \Yiyunying\Core\ApiResponse { return self::followList($request, true); }
    public static function followers(Request $request): \Yiyunying\Core\ApiResponse { return self::followList($request, false); }

    public static function blacklist(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'social');
        $items = Database::all(
            'SELECT b.blocked_user_id AS user_id, b.created_at, u.account, p.nickname, p.avatar
             FROM user_blacklist b INNER JOIN users u ON u.id = b.blocked_user_id LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE b.app_id = ? AND b.user_id = ? ORDER BY b.id DESC', [(int) $user['app_id'], (int) $user['id']]
        );
        return Response::success(['items' => $items]);
    }

    public static function toggleBlacklist(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'social'); $targetId = IdentityService::resolveUserReference((int) $user['app_id'], $params['user_id']); self::target($user, $targetId);
        if ($targetId === (int) $user['id']) throw new HttpException('不能拉黑自己', 0, 422);
        $blocked = Database::transaction(static function () use ($user, $targetId): bool {
            $existing = Database::one('SELECT id FROM user_blacklist WHERE app_id = ? AND user_id = ? AND blocked_user_id = ?', [(int) $user['app_id'], (int) $user['id'], $targetId]);
            if ($existing !== null) { Database::execute('DELETE FROM user_blacklist WHERE id = ?', [(int) $existing['id']]); return false; }
            Database::execute('INSERT INTO user_blacklist (admin_id, app_id, user_id, blocked_user_id, created_at) VALUES (?, ?, ?, ?, NOW())', [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $targetId]);
            Database::execute('DELETE FROM user_follows WHERE app_id = ? AND ((follower_user_id = ? AND followed_user_id = ?) OR (follower_user_id = ? AND followed_user_id = ?))', [(int) $user['app_id'], (int) $user['id'], $targetId, $targetId, (int) $user['id']]);
            Database::execute('UPDATE friends SET status = 0, updated_at = NOW() WHERE app_id = ? AND ((user_id = ? AND friend_user_id = ?) OR (user_id = ? AND friend_user_id = ?))', [(int) $user['app_id'], (int) $user['id'], $targetId, $targetId, (int) $user['id']]);
            return true;
        });
        return Response::success(['blocked' => $blocked], $blocked ? '已加入黑名单' : '已移出黑名单');
    }

    public static function notifications(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'notifications');
        $page = $request->page();
        $limit = min(500, max(1, (int) $request->input('limit', 50)));
        $offset = ($page - 1) * $limit;
        $unionSql = self::notificationUnionSql();
        $baseParams = [(int) $user['app_id'], (int) $user['id'], (int) $user['app_id'], (int) $user['id']];
        $filters = [];
        $filterParams = [];
        if ($request->input('is_read') !== null && $request->input('is_read') !== '') {
            $filters[] = 'inbox.is_read = ?';
            $filterParams[] = self::boolValue($request->input('is_read')) ? 1 : 0;
        }
        $groupKey = trim((string) $request->input('group', ''));
        if ($groupKey !== '') {
            self::assertNotificationGroup($groupKey);
            $filters[] = 'inbox.group_key = ?';
            $filterParams[] = $groupKey;
        }
        $centerKey = trim((string) $request->input('center', ''));
        if ($centerKey !== '') {
            self::assertNotificationCenter($centerKey);
            $filters[] = 'inbox.center_key = ?';
            $filterParams[] = $centerKey;
        }
        $whereSql = $filters === [] ? '' : ' WHERE ' . implode(' AND ', $filters);
        $queryParams = array_merge($baseParams, $filterParams);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM ({$unionSql}) inbox{$whereSql}",
            $queryParams
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT inbox.* FROM ({$unionSql}) inbox{$whereSql}
             ORDER BY inbox.created_at DESC, inbox.source_id DESC LIMIT {$limit} OFFSET {$offset}",
            $queryParams
        );
        foreach ($items as &$item) {
            $item['id'] = (int) $item['source_id'];
            $item['source_id'] = (int) $item['source_id'];
            $item['is_read'] = (bool) $item['is_read'];
            $item['group_name'] = self::notificationGroupDefinitions()[(string) $item['group_key']]['name'];
            $item['center_name'] = self::notificationCenterDefinitions()[(string) $item['center_key']]['name'];
        }
        unset($item);

        $groupWhereSql = $centerKey === '' ? '' : ' WHERE inbox.center_key = ?';
        $groupParams = $centerKey === '' ? $baseParams : array_merge($baseParams, [$centerKey]);
        $groupRows = Database::all(
            "SELECT inbox.center_key, inbox.group_key, COUNT(*) AS total_count,
                    SUM(CASE WHEN inbox.is_read = 0 THEN 1 ELSE 0 END) AS unread_count,
                    MAX(inbox.created_at) AS latest_at
             FROM ({$unionSql}) inbox{$groupWhereSql} GROUP BY inbox.center_key, inbox.group_key",
            $groupParams
        );
        $groups = [];
        $definitions = self::notificationGroupDefinitions();
        foreach ($groupRows as $row) {
            $key = (string) $row['group_key'];
            if (!isset($definitions[$key])) $key = 'other';
            $unread = (int) ($row['unread_count'] ?? 0);
            $groups[] = [
                'key' => $key,
                'center_key' => (string) $row['center_key'],
                'name' => $definitions[$key]['name'],
                'description' => $definitions[$key]['description'],
                'order' => $definitions[$key]['order'],
                'total_count' => (int) ($row['total_count'] ?? 0),
                'unread_count' => $unread,
                'latest_at' => $row['latest_at'] ?? null,
                'collapsed' => true,
            ];
        }
        usort($groups, static fn(array $left, array $right): int => ((int) $left['order']) <=> ((int) $right['order']));
        $centerRows = Database::all(
            "SELECT inbox.center_key, COUNT(*) AS total_count,
                    SUM(CASE WHEN inbox.is_read = 0 THEN 1 ELSE 0 END) AS unread_count,
                    MAX(inbox.created_at) AS latest_at
             FROM ({$unionSql}) inbox GROUP BY inbox.center_key",
            $baseParams
        );
        $centerStats = [];
        $unreadCount = 0;
        foreach ($centerRows as $row) {
            $key = (string) $row['center_key'];
            if (!isset(self::notificationCenterDefinitions()[$key])) $key = 'system';
            $centerStats[$key] = $row;
            $unreadCount += (int) ($row['unread_count'] ?? 0);
        }
        $centers = [];
        foreach (self::notificationCenterDefinitions() as $key => $definition) {
            $row = $centerStats[$key] ?? [];
            $centers[] = [
                'key' => $key,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'order' => $definition['order'],
                'total_count' => (int) ($row['total_count'] ?? 0),
                'unread_count' => (int) ($row['unread_count'] ?? 0),
                'latest_at' => $row['latest_at'] ?? null,
            ];
        }
        $data = Pagination::data($items, $total, $page, $limit);
        $data['groups'] = $groups;
        $data['centers'] = $centers;
        $data['selected_center'] = $centerKey;
        $data['unread_count'] = $unreadCount;
        $data['content_scope'] = 'notification_only';
        return Response::success($data);
    }

    public static function readNotification(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'notifications');
        $sourceType = strtolower(trim((string) $request->input('source_type', 'business')));
        if ($sourceType === 'system') {
            $changed = Database::execute(
                "UPDATE messages SET is_read = 1, read_at = COALESCE(read_at, NOW())
                 WHERE id = ? AND app_id = ? AND receiver_user_id = ? AND conversation_id IS NULL
                   AND sender_type IN ('system','admin','platform') AND status = 1",
                [(int) $params['notification_id'], (int) $user['app_id'], (int) $user['id']]
            );
        } else {
            $changed = Database::execute(
                'UPDATE user_notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = ? AND app_id = ? AND user_id = ?',
                [(int) $params['notification_id'], (int) $user['app_id'], (int) $user['id']]
            );
        }
        if ($changed === 0) throw new HttpException('通知不存在', 404, 404); return Response::success([], '通知已读');
    }

    public static function readNotificationGroup(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'notifications');
        $groupKey = trim((string) $params['group_key']);
        self::assertNotificationGroup($groupKey);
        $groupSql = self::notificationGroupSql('notification_type');
        $businessCount = Database::execute(
            "UPDATE user_notifications SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE app_id = ? AND user_id = ? AND is_read = 0 AND ({$groupSql}) = ?",
            [(int) $user['app_id'], (int) $user['id'], $groupKey]
        );
        $systemCount = 0;
        if ($groupKey === 'system') {
            $systemCount = Database::execute(
                "UPDATE messages SET is_read = 1, read_at = COALESCE(read_at, NOW())
                 WHERE app_id = ? AND receiver_user_id = ? AND conversation_id IS NULL
                   AND sender_type IN ('system','admin','platform') AND is_read = 0 AND status = 1",
                [(int) $user['app_id'], (int) $user['id']]
            );
        }
        return Response::success(
            ['group_key' => $groupKey, 'updated_count' => $businessCount + $systemCount],
            self::notificationGroupDefinitions()[$groupKey]['name'] . '已全部标记为已读'
        );
    }

    public static function readAll(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'notifications');
        $centerKey = trim((string) $request->input('center', ''));
        if ($centerKey !== '') self::assertNotificationCenter($centerKey);
        $centerSql = self::notificationCenterSql('notification_type');
        $businessSql = 'UPDATE user_notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE app_id = ? AND user_id = ? AND is_read = 0';
        $businessParams = [(int) $user['app_id'], (int) $user['id']];
        if ($centerKey !== '') {
            $businessSql .= " AND ({$centerSql}) = ?";
            $businessParams[] = $centerKey;
        }
        $businessCount = Database::execute($businessSql, $businessParams);
        $systemCount = 0;
        if ($centerKey === '' || $centerKey === 'system') {
            $systemCount = Database::execute(
                "UPDATE messages SET is_read = 1, read_at = COALESCE(read_at, NOW())
                 WHERE app_id = ? AND receiver_user_id = ? AND conversation_id IS NULL
                   AND sender_type IN ('system','admin','platform') AND is_read = 0 AND status = 1",
                [(int) $user['app_id'], (int) $user['id']]
            );
        }
        $label = $centerKey === '' ? '全部通知' : self::notificationCenterDefinitions()[$centerKey]['name'];
        return Response::success([
            'center_key' => $centerKey,
            'updated_count' => $businessCount + $systemCount,
        ], $label . '已全部标记为已读');
    }

    public static function withdrawals(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'withdrawals'); return Response::success(['items' => Database::all('SELECT * FROM user_withdrawals WHERE app_id = ? AND user_id = ? ORDER BY id DESC LIMIT 500', [(int) $user['app_id'], (int) $user['id']])]);
    }

    public static function createWithdrawal(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'withdrawals');
        if (!AppService::setting((int) $user['app_id'], 'withdrawal_enabled', true)) throw new HttpException('管理员已关闭提现', 403, 403);
        $amount = round((float) $request->input('amount', 0), 2); $min = (float) AppService::setting((int) $user['app_id'], 'withdrawal_min_amount', 1); $max = (float) AppService::setting((int) $user['app_id'], 'withdrawal_max_amount', 100000);
        if ($amount < $min || $amount > $max) throw new HttpException('提现金额超出范围', 0, 422, ['min' => $min, 'max' => $max]);
        $channel = Validator::string($request->input('channel', ''), 'channel', 1, 40); $name = Validator::string($request->input('account_name', ''), 'account_name', 1, 100); $no = Validator::string($request->input('account_no', ''), 'account_no', 1, 200);
        $id = Database::transaction(static function () use ($user, $amount, $channel, $name, $no): int {
            $id = Database::insert('INSERT INTO user_withdrawals (admin_id, app_id, user_id, amount, channel, account_name, account_no, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())', [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $amount, $channel, $name, $no, 'pending']);
            WalletService::adjust($user, 'balance', -$amount, 'withdrawal_hold', 'withdrawal', $id, '提现冻结余额'); return $id;
        });
        return Response::success(['withdrawal_id' => $id], '提现申请已提交', 201);
    }

    public static function cancelWithdrawal(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'withdrawals'); $id = (int) $params['withdrawal_id'];
        $amount = Database::transaction(static function () use ($user, $id): float {
            $row = Database::one("SELECT * FROM user_withdrawals WHERE id = ? AND app_id = ? AND user_id = ? AND status = 'pending' FOR UPDATE", [$id, (int) $user['app_id'], (int) $user['id']]);
            if ($row === null) throw new HttpException('待处理提现不存在', 404, 404);
            Database::execute("UPDATE user_withdrawals SET status = 'cancelled', updated_at = NOW() WHERE id = ?", [$id]); WalletService::adjust($user, 'balance', (float) $row['amount'], 'withdrawal_refund', 'withdrawal', $id, '取消提现退回余额'); return (float) $row['amount'];
        });
        return Response::success(['refunded_amount' => $amount], '提现已取消，余额已退回');
    }

    public static function assertNotBlocked(array $user, int $targetId): void
    {
        if (Database::one('SELECT id FROM user_blacklist WHERE app_id = ? AND ((user_id = ? AND blocked_user_id = ?) OR (user_id = ? AND blocked_user_id = ?)) LIMIT 1', [(int) $user['app_id'], (int) $user['id'], $targetId, $targetId, (int) $user['id']]) !== null) throw new HttpException('双方存在黑名单关系，不能执行该操作', 403, 403);
    }

    private static function followList(Request $request, bool $following): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'social'); $field = $following ? 'f.followed_user_id' : 'f.follower_user_id'; $ownerField = $following ? 'f.follower_user_id' : 'f.followed_user_id';
        $items = Database::all("SELECT {$field} AS user_id, f.created_at, u.account, p.nickname, p.avatar, p.signature FROM user_follows f INNER JOIN users u ON u.id = {$field} LEFT JOIN user_profiles p ON p.user_id = u.id WHERE f.app_id = ? AND {$ownerField} = ? ORDER BY f.id DESC", [(int) $user['app_id'], (int) $user['id']]);
        return Response::success(['items' => $items]);
    }

    private static function target(array $user, int $targetId): array { $target = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], $targetId); if ($target === null || (int) $target['status'] !== 1) throw new HttpException('目标用户不存在', 404, 404); return $target; }
    private static function notificationUnionSql(): string
    {
        $groupSql = self::notificationGroupSql('notification_type');
        $centerSql = self::notificationCenterSql('notification_type');
        return "SELECT id AS source_id, 'business' AS source_type, notification_type,
                       ({$centerSql}) AS center_key, ({$groupSql}) AS group_key,
                       title, content, data_json, is_read, read_at, created_at
                FROM user_notifications WHERE app_id = ? AND user_id = ?
                UNION ALL
                SELECT id AS source_id, 'system' AS source_type, CONCAT('system_', sender_type) AS notification_type,
                       'system' AS center_key, 'system' AS group_key,
                       title, content, NULL AS data_json, is_read, read_at, created_at
                FROM messages WHERE app_id = ? AND receiver_user_id = ? AND conversation_id IS NULL
                  AND sender_type IN ('system','admin','platform') AND status = 1";
    }

    private static function notificationGroupSql(string $column): string
    {
        $value = "LOWER({$column})";
        return "CASE
            WHEN {$value} LIKE '%system%' OR {$value} LIKE '%notice%' OR {$value} LIKE '%announcement%'
              OR {$value} LIKE '%update%' OR {$value} LIKE '%maintenance%' THEN 'system'
            WHEN {$value} LIKE '%like%' OR {$value} LIKE '%reaction%' OR {$value} LIKE '%praise%'
              OR {$value} LIKE '%rating%' OR {$value} LIKE '%favorite%' THEN 'likes'
            WHEN {$value} LIKE '%comment%' OR {$value} LIKE '%reply%' OR {$value} LIKE '%mention%' THEN 'comments'
            WHEN {$value} LIKE '%bounty%' THEN 'bounties'
            WHEN {$value} LIKE '%group%' THEN 'groups'
            WHEN {$value} LIKE '%room%' OR {$value} LIKE '%chatroom%' THEN 'rooms'
            WHEN {$value} LIKE '%forum%' THEN 'forums'
            WHEN {$value} LIKE '%resource%' THEN 'resources'
            WHEN {$value} LIKE '%follow%' OR {$value} LIKE '%friend%' OR {$value} LIKE '%invite%' OR {$value} LIKE '%request%' THEN 'social'
            WHEN {$value} LIKE '%lottery%' OR {$value} LIKE '%draw%' THEN 'lottery'
            WHEN {$value} LIKE '%order%' OR {$value} LIKE '%purchase%' OR {$value} LIKE '%sale%' OR {$value} LIKE '%product%' OR {$value} LIKE '%store%' THEN 'orders'
            WHEN {$value} LIKE '%wallet%' OR {$value} LIKE '%balance%' OR {$value} LIKE '%withdrawal%' OR {$value} LIKE '%transfer%' OR {$value} LIKE '%payment%' OR {$value} LIKE '%recharge%' OR {$value} LIKE '%refund%' OR {$value} LIKE '%reward%' OR {$value} LIKE '%card%' OR {$value} LIKE '%vip%' THEN 'wallet'
            WHEN {$value} LIKE '%activity%' OR {$value} LIKE '%poll%' OR {$value} LIKE '%vote%' OR {$value} LIKE '%red_packet%' OR {$value} LIKE '%gift%' THEN 'activities'
            WHEN {$value} LIKE '%post%' OR {$value} LIKE '%resource%' OR {$value} LIKE '%document%' OR {$value} LIKE '%note%' OR {$value} LIKE '%file%' OR {$value} LIKE '%upload%' THEN 'content'
            ELSE 'other' END";
    }

    private static function notificationCenterSql(string $column): string
    {
        $value = "LOWER({$column})";
        return "CASE
            WHEN {$value} LIKE '%system%' OR {$value} LIKE '%notice%' OR {$value} LIKE '%announcement%'
              OR {$value} LIKE '%update%' OR {$value} LIKE '%maintenance%' THEN 'system'
            WHEN {$value} LIKE '%lottery%' OR {$value} LIKE '%draw%' OR {$value} LIKE '%activity%'
              OR {$value} LIKE '%red_packet%' OR {$value} LIKE '%poll%' OR {$value} LIKE '%vote%'
              OR {$value} LIKE '%order%' OR {$value} LIKE '%purchase%' OR {$value} LIKE '%sale%'
              OR {$value} LIKE '%product%' OR {$value} LIKE '%store%' OR {$value} LIKE '%wallet%'
              OR {$value} LIKE '%balance%' OR {$value} LIKE '%withdrawal%' OR {$value} LIKE '%transfer%'
              OR {$value} LIKE '%payment%' OR {$value} LIKE '%recharge%' OR {$value} LIKE '%refund%'
              OR {$value} LIKE '%reward%' OR {$value} LIKE '%card%' OR {$value} LIKE '%vip%'
              OR {$value} LIKE '%gift%' OR {$value} LIKE '%exchange%' OR {$value} LIKE '%coupon%' THEN 'activity'
            WHEN {$value} LIKE '%friend%' OR {$value} LIKE '%follow%' OR {$value} LIKE '%invite%'
              OR {$value} LIKE '%request%' OR {$value} LIKE '%group%' OR {$value} LIKE '%room%'
              OR {$value} LIKE '%chatroom%' OR {$value} LIKE '%chat_%' OR {$value} LIKE '%forum%'
              OR {$value} LIKE '%bounty%' OR {$value} LIKE '%resource%' OR {$value} LIKE '%post%'
              OR {$value} LIKE '%comment%' OR {$value} LIKE '%reply%' OR {$value} LIKE '%mention%'
              OR {$value} LIKE '%like%' OR {$value} LIKE '%reaction%' OR {$value} LIKE '%praise%'
              OR {$value} LIKE '%rating%' OR {$value} LIKE '%favorite%' THEN 'social'
            ELSE 'system' END";
    }

    private static function notificationGroupDefinitions(): array
    {
        return [
            'likes' => ['name' => '点赞与喜欢', 'description' => '主页、帖子、资源和其他内容收到的点赞', 'order' => 10],
            'comments' => ['name' => '评论与回复', 'description' => '评论、回复、提及和互动提醒', 'order' => 20],
            'social' => ['name' => '关注与好友', 'description' => '关注、好友申请、邀请和社交关系变化', 'order' => 30],
            'groups' => ['name' => '群聊通知', 'description' => '群聊邀请、入群申请和群内管理提醒', 'order' => 40],
            'rooms' => ['name' => '聊天室通知', 'description' => '聊天室互动、管理和成员变化提醒', 'order' => 50],
            'forums' => ['name' => '论坛通知', 'description' => '论坛、帖子、评论和版块相关提醒', 'order' => 60],
            'bounties' => ['name' => '悬赏通知', 'description' => '悬赏投稿、选中、取消和状态变化', 'order' => 70],
            'resources' => ['name' => '资源通知', 'description' => '资源投稿、互动、购买和状态变化', 'order' => 80],
            'lottery' => ['name' => '抽奖通知', 'description' => '抽奖参与、开奖和获奖结果', 'order' => 90],
            'orders' => ['name' => '订单与购买', 'description' => '商城、付费内容和订单状态变化', 'order' => 100],
            'wallet' => ['name' => '余额与权益', 'description' => '余额、转账、提现、会员和卡密权益变化', 'order' => 110],
            'activities' => ['name' => '活动通知', 'description' => '红包、投票、礼物和其他活动结果', 'order' => 120],
            'content' => ['name' => '内容与文件', 'description' => '笔记、文档和文件状态变化', 'order' => 130],
            'system' => ['name' => '系统与公告', 'description' => '平台公告、维护、更新和系统通知', 'order' => 140],
            'other' => ['name' => '其他通知', 'description' => '未归入以上分类的业务提醒', 'order' => 150],
        ];
    }

    private static function notificationCenterDefinitions(): array
    {
        return [
            'social' => ['name' => '动态通知', 'description' => '关注、点赞、收藏、评论、回复及社区互动', 'order' => 10],
            'activity' => ['name' => '活动通知', 'description' => '抽奖、红包、投票、商城、订单和余额权益', 'order' => 20],
            'system' => ['name' => '系统通知', 'description' => '公告、系统消息、维护、更新、审核和平台提醒', 'order' => 30],
        ];
    }

    private static function assertNotificationGroup(string $groupKey): void
    {
        if (!isset(self::notificationGroupDefinitions()[$groupKey])) throw new HttpException('通知分类不存在', 404, 404);
    }
    private static function assertNotificationCenter(string $centerKey): void
    {
        if (!isset(self::notificationCenterDefinitions()[$centerKey])) throw new HttpException('通知中心不存在', 404, 404);
    }
    private static function user(Request $request, string $feature): array { $user = AuthService::user($request); AppService::requireFeature((int) $user['app_id'], $feature); return $user; }
    private static function boolValue($value): bool { if (is_bool($value)) return $value; return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true); }
}
