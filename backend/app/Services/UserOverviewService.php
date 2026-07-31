<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;

final class UserOverviewService
{
    public static function list(int $adminId, int $appId, Request $request): array
    {
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['u.admin_id = ?', 'u.app_id = ?', 'u.deleted_at IS NULL'];
        $query = [$adminId, $appId];
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(u.uid LIKE ? OR u.account LIKE ? OR p.nickname LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
            foreach (range(1, 5) as $_) $query[] = '%' . $keyword . '%';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT u.id, u.uid, u.account, u.email, u.phone, u.status, u.register_ip, u.last_login_ip,
                    u.last_login_at, u.created_at, p.nickname, p.avatar, p.title, p.signature,
                    w.balance, w.integral AS activity_credit, w.experience, w.document_credit,
                    w.vip_expired_at, w.level_code
             FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN user_wallets w ON w.user_id = u.id
             WHERE {$whereSql} ORDER BY u.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        return Pagination::data($items, $total, $page, $limit);
    }

    public static function overview(int $adminId, int $appId, int $userId): array
    {
        $user = self::user($adminId, $appId, $userId);
        $counts = Database::one(
            'SELECT
              (SELECT COUNT(*) FROM friends WHERE app_id = ? AND user_id = ? AND status = 1) AS friends,
              (SELECT COUNT(*) FROM conversations WHERE app_id = ? AND (user_a_id = ? OR user_b_id = ?)) AS conversations,
              (SELECT COUNT(*) FROM chat_room_members WHERE app_id = ? AND user_id = ?) AS groups,
              (SELECT COUNT(*) FROM forum_posts WHERE app_id = ? AND user_id = ? AND deleted_at IS NULL) AS forum_posts,
              (SELECT COUNT(*) FROM forum_comments WHERE app_id = ? AND user_id = ? AND status = 1) AS forum_comments,
              (SELECT COUNT(*) FROM forum_favorites WHERE app_id = ? AND user_id = ?) AS forum_favorites,
              (SELECT COUNT(*) FROM user_follows WHERE app_id = ? AND follower_user_id = ?) AS following,
              (SELECT COUNT(*) FROM user_follows WHERE app_id = ? AND followed_user_id = ?) AS followers,
              (SELECT COUNT(*) FROM documents WHERE app_id = ? AND user_id = ? AND deleted_at IS NULL) AS notes,
              (SELECT COUNT(*) FROM bounties WHERE app_id = ? AND creator_user_id = ? AND deleted_at IS NULL) AS bounties,
              (SELECT COUNT(*) FROM uploads WHERE app_id = ? AND user_id = ?) AS uploads,
              (SELECT COUNT(*) FROM message_recall_audits WHERE app_id = ? AND sender_type = ? AND sender_id = ?) AS recalled_messages',
            [
                $appId, $userId, $appId, $userId, $userId, $appId, $userId,
                $appId, $userId, $appId, $userId, $appId, $userId,
                $appId, $userId, $appId, $userId, $appId, $userId,
                $appId, $userId, $appId, $userId, $appId, 'user', $userId,
            ]
        ) ?? [];

        $conversations = Database::all(
            'SELECT c.id, c.last_message_at,
                    CASE WHEN c.user_a_id = ? THEN c.user_b_id ELSE c.user_a_id END AS peer_user_id,
                    peer.account AS peer_account, profile.nickname AS peer_name, profile.avatar AS peer_avatar,
                    last.content AS last_message, last.content_type AS last_content_type,
                    (recall.id IS NOT NULL) AS last_message_recalled
             FROM conversations c
             INNER JOIN users peer ON peer.id = CASE WHEN c.user_a_id = ? THEN c.user_b_id ELSE c.user_a_id END
             LEFT JOIN user_profiles profile ON profile.user_id = peer.id
             LEFT JOIN messages last ON last.id = c.last_message_id
             LEFT JOIN message_recalls recall ON recall.message_id = last.id
             WHERE c.app_id = ? AND (c.user_a_id = ? OR c.user_b_id = ?)
             ORDER BY COALESCE(c.last_message_at, c.created_at) DESC LIMIT 100',
            [$userId, $userId, $appId, $userId, $userId]
        );
        $groups = Database::all(
            'SELECT r.id, r.name, r.icon, r.description, r.tags_json, member.role, member.joined_at, member.mute_until,
                    policy.owner_user_id,
                    r.room_kind,
                    (SELECT COUNT(*) FROM chat_room_messages m WHERE m.room_id = r.id AND m.status = 1) AS message_count
             FROM chat_room_members member INNER JOIN chat_rooms r ON r.id = member.room_id
             LEFT JOIN chat_room_policies policy ON policy.room_id = r.id
             WHERE member.app_id = ? AND member.user_id = ? ORDER BY member.joined_at DESC LIMIT 100',
            [$appId, $userId]
        );
        $groups = ContentTagService::hydrate($groups);
        $groupChats = [];
        $chatRooms = [];
        foreach ($groups as $room) {
            if (($room['room_kind'] ?? 'group') === 'chat_room') $chatRooms[] = $room;
            else $groupChats[] = $room;
        }
        $serviceSessions = Database::all(
            'SELECT id, subject, status, assigned_admin_id, last_message_at, closed_at, created_at
             FROM service_sessions WHERE app_id = ? AND user_id = ? ORDER BY id DESC LIMIT 50',
            [$appId, $userId]
        );
        $recalls = Database::all(
            'SELECT id, channel_type, channel_id, message_id, original_content_type,
                    original_content, original_attachments_json, recalled_by_type, recalled_by_id, reason, recalled_at
             FROM message_recall_audits WHERE admin_id = ? AND app_id = ? AND sender_type = ? AND sender_id = ?
             ORDER BY id DESC LIMIT 100',
            [$adminId, $appId, 'user', $userId]
        );
        foreach ($recalls as &$recall) {
            $recall['original_attachments'] = self::decodeArray($recall['original_attachments_json'] ?? null);
            unset($recall['original_attachments_json']);
            $recall['audit_notice'] = '该消息已撤回，仅管理审计可见原文';
        }
        unset($recall);

        $favoritePosts = MessageMediaService::hydrate(Database::all(
            'SELECT p.id, p.title, p.user_id AS author_user_id, favorite.created_at AS favorited_at
             FROM forum_favorites favorite INNER JOIN forum_posts p ON p.id = favorite.post_id
             WHERE favorite.app_id = ? AND favorite.user_id = ? ORDER BY favorite.id DESC LIMIT 100',
            [$appId, $userId]
        ), 'forum_post', $appId);
        $following = Database::all(
            'SELECT followed.id AS user_id, followed.uid, followed.account,
                    profile.nickname, profile.avatar, profile.signature, profile.title, relation.created_at
             FROM user_follows relation
             INNER JOIN users followed ON followed.id = relation.followed_user_id
             LEFT JOIN user_profiles profile ON profile.user_id = followed.id
             WHERE relation.app_id = ? AND relation.follower_user_id = ?
             ORDER BY relation.id DESC LIMIT 500',
            [$appId, $userId]
        );
        $followers = Database::all(
            'SELECT follower.id AS user_id, follower.uid, follower.account,
                    profile.nickname, profile.avatar, profile.signature, profile.title, relation.created_at
             FROM user_follows relation
             INNER JOIN users follower ON follower.id = relation.follower_user_id
             LEFT JOIN user_profiles profile ON profile.user_id = follower.id
             WHERE relation.app_id = ? AND relation.followed_user_id = ?
             ORDER BY relation.id DESC LIMIT 500',
            [$appId, $userId]
        );
        $bounties = MessageMediaService::hydrate(ContentTagService::hydrate(Database::all(
            'SELECT id, title, description, requirements_json AS tags_json,
                    reward_integral AS reward_balance, status, audit_status, audit_reason, submission_count,
                    like_count, favorite_count, deadline_at, created_at, updated_at
             FROM bounties
             WHERE admin_id = ? AND app_id = ? AND creator_user_id = ? AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 200',
            [$adminId, $appId, $userId]
        )), 'bounty', $appId);
        $submittedResources = MessageMediaService::hydrate(Database::all(
            'SELECT id, category_id, title, description, cover_url, download_url,
                    audit_status, is_top, is_recommended, status, created_at
             FROM resources WHERE admin_id = ? AND app_id = ? AND user_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 100',
            [$adminId, $appId, $userId]
        ), 'resource', $appId);
        $submittedStoreApps = MessageMediaService::hydrate(Database::all(
            'SELECT id, category_id, name, package_name, version_name, version_code,
                    icon_url, apk_url, description, status, created_at
             FROM store_apps WHERE admin_id = ? AND app_id = ? AND user_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 100',
            [$adminId, $appId, $userId]
        ), 'store_app', $appId);
        $behaviorLogs = Database::all(
            'SELECT id, module, action, target_id, detail_json, ip, created_at
             FROM user_operation_logs WHERE admin_id = ? AND app_id = ? AND user_id = ? ORDER BY id DESC LIMIT 200',
            [$adminId, $appId, $userId]
        );
        foreach ($behaviorLogs as &$behaviorLog) {
            $behaviorLog['detail'] = self::decodeArray($behaviorLog['detail_json'] ?? null);
            unset($behaviorLog['detail_json']);
        }
        unset($behaviorLog);

        return [
            '资料与资产' => [
                '用户资料' => $user,
                '关联统计' => $counts,
                '资产流水' => Database::all(
                    'SELECT asset_type, change_value, before_value, after_value, scene, ref_type, ref_id, remark, created_at
                     FROM user_wallet_logs WHERE admin_id = ? AND app_id = ? AND user_id = ? ORDER BY id DESC LIMIT 100',
                    [$adminId, $appId, $userId]
                ),
                '订单' => Database::all(
                    'SELECT id, order_no, order_type, title, quantity, amount, pay_amount, pay_channel, status, created_at
                     FROM orders WHERE admin_id = ? AND app_id = ? AND user_id = ? ORDER BY id DESC LIMIT 100',
                    [$adminId, $appId, $userId]
                ),
            ],
            '消息类' => [
                '私聊会话' => $conversations,
                '群聊' => $groupChats,
                '聊天室' => $chatRooms,
                '客服会话' => $serviceSessions,
                '撤回审计' => $recalls,
            ],
            '社交类' => [
                '好友' => Database::all(
                    'SELECT f.friend_user_id AS user_id, u.uid, f.remark, f.created_at, u.account,
                            p.nickname, p.avatar, p.signature, p.title
                     FROM friends f INNER JOIN users u ON u.id = f.friend_user_id
                     LEFT JOIN user_profiles p ON p.user_id = u.id
                     WHERE f.app_id = ? AND f.user_id = ? AND f.status = 1 ORDER BY f.id DESC LIMIT 500',
                    [$appId, $userId]
                ),
                '关注的人' => $following,
                '粉丝' => $followers,
                '好友申请' => Database::all(
                    'SELECT id, from_user_id, to_user_id, message, requester_remark, requester_group_id,
                            hide_my_dynamic, hide_their_dynamic, status, decision_reason, ignore_reason,
                            ignored_at, expired_at, handled_at, created_at
                     FROM friend_requests WHERE app_id = ? AND (from_user_id = ? OR to_user_id = ?)
                     ORDER BY id DESC LIMIT 200',
                    [$appId, $userId, $userId]
                ),
                '群聊邀请' => Database::all(
                    'SELECT invitation.id, invitation.room_id, room.name AS room_name,
                            invitation.inviter_user_id, invitation.invitee_user_id, invitation.message,
                            invitation.status, invitation.decision_reason, invitation.ignore_reason,
                            invitation.ignored_at, invitation.expired_at, invitation.responded_at, invitation.created_at
                     FROM chat_room_invitations invitation
                     INNER JOIN chat_rooms room ON room.id = invitation.room_id
                     WHERE invitation.app_id = ? AND (invitation.inviter_user_id = ? OR invitation.invitee_user_id = ?)
                     ORDER BY invitation.id DESC LIMIT 200',
                    [$appId, $userId, $userId]
                ),
                '入群申请' => Database::all(
                    'SELECT request.id, request.room_id, room.name AS room_name, request.user_id,
                            request.message, request.status, request.decision_reason, request.ignore_reason,
                            request.ignored_at, request.expired_at,
                            request.handled_by_user_id, request.handled_by_admin_id, request.handled_at, request.created_at
                     FROM chat_room_join_requests request
                     INNER JOIN chat_rooms room ON room.id = request.room_id
                     WHERE request.app_id = ? AND request.user_id = ?
                     ORDER BY request.id DESC LIMIT 200',
                    [$appId, $userId]
                ),
            ],
            '内容类' => [
                '笔记' => Database::all(
                    'SELECT id, title, content_type, word_count, is_public, status, version_no,
                            DATE_FORMAT(created_at, \'%Y-%m-%d\') AS note_date,
                            YEAR(created_at) AS year, MONTH(created_at) AS month, DAY(created_at) AS day,
                            DATE_FORMAT(created_at, \'%Y年%c月%e日\') AS date_label,
                            created_at, updated_at
                     FROM documents WHERE admin_id = ? AND app_id = ? AND user_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 100',
                    [$adminId, $appId, $userId]
                ),
                '悬赏' => $bounties,
                '论坛帖子' => MessageMediaService::hydrate(ContentTagService::hydrate(Database::all(
                    'SELECT id, plate_id, title, content, tags_json, is_top, is_essence, is_locked,
                            audit_status, view_count, like_count, comment_count, status, created_at
                     FROM forum_posts WHERE admin_id = ? AND app_id = ? AND user_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 100',
                    [$adminId, $appId, $userId]
                )), 'forum_post', $appId),
                '论坛评论' => MessageMediaService::hydrate(Database::all(
                    'SELECT id, post_id, parent_id, content, status, created_at
                     FROM forum_comments WHERE admin_id = ? AND app_id = ? AND user_id = ? ORDER BY id DESC LIMIT 100',
                    [$adminId, $appId, $userId]
                ), 'forum_comment', $appId),
                '收藏的帖子' => $favoritePosts,
                '资源投稿' => $submittedResources,
                '应用商店投稿' => $submittedStoreApps,
            ],
            '其他' => [
                '上传文件' => Database::all(
                    'SELECT id, scene, original_name, file_url, mime_type, size_bytes, status, created_at
                     FROM uploads WHERE admin_id = ? AND app_id = ? AND user_id = ? ORDER BY id DESC LIMIT 100',
                    [$adminId, $appId, $userId]
                ),
                '反馈' => Database::all(
                    'SELECT id, type, title, content, status, reply_content, replied_at, created_at
                     FROM feedbacks WHERE admin_id = ? AND app_id = ? AND user_id = ? ORDER BY id DESC LIMIT 100',
                    [$adminId, $appId, $userId]
                ),
                '行为日志' => $behaviorLogs,
            ],
        ];
    }

    public static function communications(
        int $adminId,
        int $appId,
        int $userId,
        string $channelType,
        int $channelId,
        Request $request
    ): array {
        $subjectUser = self::user($adminId, $appId, $userId);
        $subjectSummary = self::communicationUserSummary($subjectUser);
        $viewContext = [];
        if (in_array($channelType, ['room', 'chat_room', 'chatroom'], true)) $channelType = 'group';
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        if ($channelType === 'private') {
            $channel = Database::one(
                'SELECT * FROM conversations WHERE id = ? AND admin_id = ? AND app_id = ? AND (user_a_id = ? OR user_b_id = ?)',
                [$channelId, $adminId, $appId, $userId, $userId]
            );
            if ($channel === null) throw new HttpException('私聊会话不存在或不属于该用户', 404, 404);
            $peerId = (int) $channel['user_a_id'] === $userId
                ? (int) $channel['user_b_id']
                : (int) $channel['user_a_id'];
            $peer = Database::one(
                'SELECT u.id, u.uid, u.account, u.status, p.nickname, p.avatar, p.signature, p.title
                 FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
                 WHERE u.id = ? AND u.admin_id = ? AND u.app_id = ?',
                [$peerId, $adminId, $appId]
            );
            if ($peer === null) throw new HttpException('私聊对方已不在当前应用范围', 404, 404);
            $peerSummary = self::communicationUserSummary($peer);
            $viewContext = [
                'label' => '管理员视角',
                'summary' => $subjectSummary['display_name'] . ' 与 ' . $peerSummary['display_name'],
                'description' => '正在查看这两位用户之间的私聊，消息左右位置按被监管用户视角显示',
                'subject_user' => $subjectSummary,
                'counterpart_user' => $peerSummary,
                'channel_kind' => 'private',
                'channel_id' => $channelId,
            ];
            [$filterSql, $filterParams] = self::communicationSearchFilter('m', $appId, 'private_message', $request, true);
            $total = (int) (Database::one(
                'SELECT COUNT(*) AS total FROM messages m WHERE m.conversation_id = ?' . $filterSql,
                array_merge([$channelId], $filterParams)
            )['total'] ?? 0);
            $items = Database::all(
                "SELECT m.*, (r.id IS NOT NULL) AS recalled, r.created_at AS recalled_at,
                        audit.recalled_by_type, audit.recalled_by_id, audit.reason AS recall_reason,
                        COALESCE(NULLIF(profile.nickname, ''), sender.account, '') AS sender_name,
                        COALESCE(profile.avatar, '') AS sender_avatar
                 FROM messages m LEFT JOIN message_recalls r ON r.message_id = m.id
                 LEFT JOIN message_recall_audits audit ON audit.app_id = m.app_id AND audit.channel_type = 'private' AND audit.message_id = m.id
                 LEFT JOIN users sender ON sender.id = CASE WHEN m.sender_type = 'user' THEN m.sender_id ELSE NULL END
                 LEFT JOIN user_profiles profile ON profile.user_id = sender.id
                 WHERE m.conversation_id = ?{$filterSql} ORDER BY m.id DESC LIMIT {$limit} OFFSET {$offset}",
                array_merge([$channelId], $filterParams)
            );
            $items = ContentTagService::hydrate(array_reverse($items));
            $items = MessageMediaService::hydrate($items, 'private_message', $appId);
            $items = MessageForwardService::hydrate($items, 'private_message', $appId);
            $items = MessagePresentationService::hydrate($items, 'private');
        } elseif ($channelType === 'group') {
            $channel = Database::one(
                'SELECT r.*, member.role AS perspective_member_role,
                        member.joined_at AS perspective_joined_at,
                        (SELECT COUNT(*) FROM chat_room_members members WHERE members.room_id = r.id) AS member_count
                 FROM chat_rooms r INNER JOIN chat_room_members member ON member.room_id = r.id
                 WHERE r.id = ? AND r.admin_id = ? AND r.app_id = ? AND member.user_id = ?',
                [$channelId, $adminId, $appId, $userId]
            );
            if ($channel === null) throw new HttpException('群聊不存在或该用户不是群成员', 404, 404);
            $roomName = trim((string) ($channel['name'] ?? ''));
            if ($roomName === '') $roomName = '群聊 #' . $channelId;
            $viewContext = [
                'label' => '管理员视角',
                'summary' => $subjectSummary['display_name'] . ' 进入 ' . $roomName,
                'description' => '正在按被监管用户的成员身份查看该群聊或聊天室，管理操作会写入审计日志',
                'subject_user' => $subjectSummary,
                'room' => [
                    'id' => $channelId,
                    'name' => $roomName,
                    'icon' => (string) ($channel['icon'] ?? ''),
                    'member_role' => (string) ($channel['perspective_member_role'] ?? 'member'),
                    'member_count' => (int) ($channel['member_count'] ?? 0),
                    'joined_at' => $channel['perspective_joined_at'] ?? null,
                ],
                'channel_kind' => 'group',
                'channel_id' => $channelId,
            ];
            [$filterSql, $filterParams] = self::communicationSearchFilter('m', $appId, 'group_message', $request, true);
            $total = (int) (Database::one(
                'SELECT COUNT(*) AS total FROM chat_room_messages m WHERE m.room_id = ?' . $filterSql,
                array_merge([$channelId], $filterParams)
            )['total'] ?? 0);
            $items = Database::all(
                "SELECT m.*, audit.recalled_at, audit.recalled_by_type, audit.recalled_by_id,
                        audit.reason AS recall_reason, (audit.id IS NOT NULL) AS recalled,
                        COALESCE(NULLIF(profile.nickname, ''), sender.account, '') AS sender_name,
                        COALESCE(profile.avatar, '') AS sender_avatar, member.role
                 FROM chat_room_messages m
                 LEFT JOIN message_recall_audits audit ON audit.app_id = m.app_id AND audit.channel_type = 'group' AND audit.message_id = m.id
                 LEFT JOIN users sender ON sender.id = m.user_id
                 LEFT JOIN user_profiles profile ON profile.user_id = sender.id
                 LEFT JOIN chat_room_members member ON member.room_id = m.room_id AND member.user_id = m.user_id
                 WHERE m.room_id = ?{$filterSql} ORDER BY m.id DESC LIMIT {$limit} OFFSET {$offset}",
                array_merge([$channelId], $filterParams)
            );
            $items = ContentTagService::hydrate(array_reverse($items));
            $items = MessageMediaService::hydrate($items, 'group_message', $appId);
            $items = MessageForwardService::hydrate($items, 'group_message', $appId);
            $items = MessagePresentationService::hydrate($items, 'group');
        } elseif ($channelType === 'service') {
            $channel = Database::one(
                'SELECT * FROM service_sessions WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ?',
                [$channelId, $adminId, $appId, $userId]
            );
            if ($channel === null) throw new HttpException('客服会话不存在或不属于该用户', 404, 404);
            $subject = trim((string) ($channel['subject'] ?? ''));
            if ($subject === '') $subject = '客服会话 #' . $channelId;
            $viewContext = [
                'label' => '管理员视角',
                'summary' => $subjectSummary['display_name'] . ' 的' . $subject,
                'description' => '正在查看该用户与系统客服之间的完整会话',
                'subject_user' => $subjectSummary,
                'service_session' => [
                    'id' => $channelId,
                    'subject' => $subject,
                    'status' => (string) ($channel['status'] ?? ''),
                ],
                'channel_kind' => 'service',
                'channel_id' => $channelId,
            ];
            [$filterSql, $filterParams] = self::communicationSearchFilter('m', $appId, 'service_message', $request, false);
            $total = (int) (Database::one(
                'SELECT COUNT(*) AS total FROM service_messages m WHERE m.session_id = ?' . $filterSql,
                array_merge([$channelId], $filterParams)
            )['total'] ?? 0);
            $items = Database::all(
                "SELECT m.*, 0 AS recalled,
                        CASE WHEN m.sender_type = 'user' THEN COALESCE(NULLIF(profile.nickname, ''), sender_user.account, '')
                             WHEN m.sender_type = 'admin' THEN COALESCE(NULLIF(sender_admin.nickname, ''), sender_admin.account, '')
                             ELSE '' END AS sender_name,
                        CASE WHEN m.sender_type = 'user' THEN COALESCE(profile.avatar, '')
                             WHEN m.sender_type = 'admin' THEN COALESCE(sender_admin.avatar, '') ELSE '' END AS sender_avatar
                 FROM service_messages m
                 LEFT JOIN users sender_user ON m.sender_type = 'user' AND sender_user.id = m.sender_id
                 LEFT JOIN user_profiles profile ON profile.user_id = sender_user.id
                 LEFT JOIN admins sender_admin ON m.sender_type = 'admin' AND sender_admin.id = m.sender_id
                 WHERE m.session_id = ?{$filterSql} ORDER BY m.id DESC LIMIT {$limit} OFFSET {$offset}",
                array_merge([$channelId], $filterParams)
            );
            $items = MessageMediaService::hydrate(array_reverse($items), 'service_message', $appId);
            $items = MessageForwardService::hydrate($items, 'service_message', $appId);
            $items = MessagePresentationService::hydrate($items, 'service');
        } else {
            throw new HttpException('channel_type 仅支持 private、group 或 service', 0, 422);
        }
        $items = self::markCommunicationMatches($items, $request);
        foreach ($items as &$item) {
            $item['original_visible_to_manager'] = true;
            if ((bool) ($item['recalled'] ?? false)) $item['recall_notice'] = '该消息已撤回，仅管理审计可见原文';
        }
        unset($item);
        return array_merge(Pagination::data($items, $total, $page, $limit), [
            'channel_type' => $channelType, 'channel' => $channel,
            'view_context' => $viewContext,
            'perspective_user_id' => $userId,
            'perspective_mode' => 'subject_user',
            'manager_capabilities' => ['view', 'send_system', 'update', 'delete'],
            'audit_mode' => true, 'audit_notice' => '管理审计页面会显示撤回消息原文，请依法依规使用',
            'search_capabilities' => [
                ['value' => 'all', 'label' => '全部'],
                ['value' => 'file', 'label' => '文件与媒体'],
                ['value' => 'tag', 'label' => '标签'],
                ['value' => 'snapshot', 'label' => '聊天快照（随关键词检索）'],
            ],
        ]);
    }

    private static function communicationUserSummary(array $user): array
    {
        $nickname = trim((string) ($user['nickname'] ?? ''));
        $account = trim((string) ($user['account'] ?? ''));
        return [
            'id' => (int) ($user['id'] ?? 0),
            'uid' => (string) ($user['uid'] ?? ''),
            'account' => $account,
            'nickname' => $nickname,
            'display_name' => $nickname !== '' ? $nickname : ($account !== '' ? $account : '用户 #' . (int) ($user['id'] ?? 0)),
            'avatar' => (string) ($user['avatar'] ?? ''),
            'status' => (int) ($user['status'] ?? 0),
            'title' => (string) ($user['title'] ?? ''),
        ];
    }

    private static function communicationSearchFilter(
        string $alias,
        int $appId,
        string $targetType,
        Request $request,
        bool $supportsTags
    ): array {
        $keyword = trim((string) $request->input('keyword', ''));
        $filter = strtolower(trim((string) $request->input('content_filter', 'all')));
        if (!in_array($filter, ['all', 'file', 'tag', 'snapshot'], true)) {
            throw new HttpException('content_filter 仅支持 all、file、tag 或 snapshot', 0, 422);
        }
        $like = '%' . $keyword . '%';
        if ($filter === 'file') {
            $mediaSql = "EXISTS (SELECT 1 FROM media_attachments media
                         WHERE media.app_id = ? AND media.target_type = ? AND media.target_id = {$alias}.id";
            $params = [$appId, $targetType];
            if ($keyword !== '') {
                $mediaSql .= ' AND (media.file_name LIKE ? OR media.mime_type LIKE ? OR media.media_type LIKE ?)';
                array_push($params, $like, $like, $like);
            }
            $snapshotNeedle = $keyword === '' ? '%"file_name"%' : $like;
            $snapshotSql = "EXISTS (SELECT 1 FROM message_forward_links forward_link
                            INNER JOIN message_forward_bundles forward_bundle ON forward_bundle.id = forward_link.bundle_id
                            WHERE forward_link.app_id = ? AND forward_link.target_type = ?
                              AND forward_link.target_id = {$alias}.id AND forward_bundle.snapshot_json LIKE ?)";
            array_push($params, $appId, $targetType, $snapshotNeedle);
            return [' AND (' . $mediaSql . ') OR ' . $snapshotSql . ')', $params];
        }
        if ($filter === 'tag') {
            if (!$supportsTags) {
                $needle = $keyword === '' ? '%"tags"%' : $like;
                return [" AND (EXISTS (SELECT 1 FROM media_attachments media
                         WHERE media.app_id = ? AND media.target_type = ? AND media.target_id = {$alias}.id
                           AND media.metadata_json LIKE ?)
                       OR EXISTS (SELECT 1 FROM message_forward_links forward_link
                         INNER JOIN message_forward_bundles forward_bundle ON forward_bundle.id = forward_link.bundle_id
                         WHERE forward_link.app_id = ? AND forward_link.target_type = ?
                           AND forward_link.target_id = {$alias}.id AND forward_bundle.snapshot_json LIKE ?))",
                    [$appId, $targetType, $needle, $appId, $targetType, $needle]];
            }
            if ($keyword === '') {
                return [" AND (({$alias}.tags_json IS NOT NULL AND {$alias}.tags_json <> '' AND {$alias}.tags_json <> '[]')
                         OR EXISTS (SELECT 1 FROM message_forward_links forward_link
                           INNER JOIN message_forward_bundles forward_bundle ON forward_bundle.id = forward_link.bundle_id
                           WHERE forward_link.app_id = ? AND forward_link.target_type = ?
                             AND forward_link.target_id = {$alias}.id AND forward_bundle.snapshot_json LIKE '%\"tags\"%'))",
                    [$appId, $targetType]];
            }
            return [" AND ({$alias}.tags_json LIKE ? OR EXISTS (SELECT 1 FROM message_forward_links forward_link
                       INNER JOIN message_forward_bundles forward_bundle ON forward_bundle.id = forward_link.bundle_id
                       WHERE forward_link.app_id = ? AND forward_link.target_type = ?
                         AND forward_link.target_id = {$alias}.id AND forward_bundle.snapshot_json LIKE ?))",
                [$like, $appId, $targetType, $like]];
        }
        if ($filter === 'snapshot') {
            $sql = " AND EXISTS (SELECT 1 FROM message_forward_links forward_link
                    INNER JOIN message_forward_bundles forward_bundle ON forward_bundle.id = forward_link.bundle_id
                    WHERE forward_link.app_id = ? AND forward_link.target_type = ?
                      AND forward_link.target_id = {$alias}.id";
            $params = [$appId, $targetType];
            if ($keyword !== '') { $sql .= ' AND forward_bundle.snapshot_json LIKE ?'; $params[] = $like; }
            return [$sql . ')', $params];
        }
        if ($keyword === '') return ['', []];
        $conditions = ["{$alias}.content LIKE ?"];
        $params = [$like];
        if ($supportsTags) { $conditions[] = "{$alias}.tags_json LIKE ?"; $params[] = $like; }
        $conditions[] = "EXISTS (SELECT 1 FROM media_attachments media
                       WHERE media.app_id = ? AND media.target_type = ? AND media.target_id = {$alias}.id
                         AND (media.file_name LIKE ? OR media.mime_type LIKE ? OR media.media_type LIKE ?))";
        array_push($params, $appId, $targetType, $like, $like, $like);
        $conditions[] = "EXISTS (SELECT 1 FROM message_forward_links forward_link
                       INNER JOIN message_forward_bundles forward_bundle ON forward_bundle.id = forward_link.bundle_id
                       WHERE forward_link.app_id = ? AND forward_link.target_type = ?
                         AND forward_link.target_id = {$alias}.id AND forward_bundle.snapshot_json LIKE ?)";
        array_push($params, $appId, $targetType, $like);
        return [' AND (' . implode(' OR ', $conditions) . ')', $params];
    }

    private static function markCommunicationMatches(array $items, Request $request): array
    {
        $keyword = trim((string) $request->input('keyword', ''));
        $filter = strtolower(trim((string) $request->input('content_filter', 'all')));
        foreach ($items as &$item) {
            $fields = [];
            if ($keyword !== '' && mb_stripos((string) ($item['content'] ?? ''), $keyword) !== false) $fields[] = '正文';
            foreach ((array) ($item['tags'] ?? []) as $tag) {
                if ($filter === 'tag' || ($keyword !== '' && mb_stripos((string) $tag, $keyword) !== false)) {
                    $fields[] = '标签';
                    break;
                }
            }
            foreach ((array) ($item['attachments'] ?? []) as $attachment) {
                if (!is_array($attachment)) continue;
                $haystack = implode(' ', [
                    (string) ($attachment['file_name'] ?? ''), (string) ($attachment['mime_type'] ?? ''),
                    (string) ($attachment['media_type'] ?? ''),
                ]);
                if ($filter === 'file' || ($keyword !== '' && mb_stripos($haystack, $keyword) !== false)) {
                    $fields[] = '文件';
                    break;
                }
            }
            if ($fields === [] && (int) ($item['forward_bundle_id'] ?? 0) > 0) $fields[] = '聊天快照';
            $item['search_match_fields'] = array_values(array_unique($fields));
            $item['is_search_match'] = $keyword !== '' || $filter !== 'all';
        }
        unset($item);
        return $items;
    }

    private static function user(int $adminId, int $appId, int $userId): array
    {
        $user = Database::one(
            'SELECT u.id, u.uid, u.account, u.email, u.phone, u.status, u.register_ip, u.last_login_ip,
                    u.last_login_at, u.created_at, u.updated_at, p.nickname, p.qq, p.avatar, p.background,
                    p.signature, p.gender, p.birthday, p.title, p.public_profile,
                    wallet.integral AS activity_credit, wallet.experience, wallet.balance,
                    wallet.document_credit, wallet.vip_expired_at, wallet.level_code
             FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN user_wallets wallet ON wallet.user_id = u.id
             WHERE u.id = ? AND u.admin_id = ? AND u.app_id = ? AND u.deleted_at IS NULL',
            [$userId, $adminId, $appId]
        );
        if ($user === null) throw new HttpException('用户不存在或不在当前管理范围', 404, 404);
        return $user;
    }

    private static function decodeArray($value): array
    {
        if (!is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
