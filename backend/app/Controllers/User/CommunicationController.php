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
use Yiyunying\Services\ChatRoomService;
use Yiyunying\Services\ChatSearchService;
use Yiyunying\Services\ContentTagService;
use Yiyunying\Services\ForumTaxonomyService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\MessageForwardService;
use Yiyunying\Services\MessageEditService;
use Yiyunying\Services\MessageMediaService;
use Yiyunying\Services\MessagePresentationService;
use Yiyunying\Services\MomentVisibilityService;
use Yiyunying\Services\NotificationService;
use Yiyunying\Services\FriendQrService;
use Yiyunying\Services\IdentityService;

final class CommunicationController
{
    public static function unread(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $messages = Database::one(
            "SELECT
                SUM(CASE WHEN conversation_id IS NOT NULL AND is_read = 0 THEN 1 ELSE 0 END) AS private_unread
             FROM messages WHERE admin_id = ? AND app_id = ? AND receiver_user_id = ? AND status = 1",
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        ) ?? [];
        $service = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM service_messages sm
             INNER JOIN service_sessions s ON s.id = sm.session_id
             WHERE s.app_id = ? AND s.user_id = ? AND sm.sender_type = 'admin' AND sm.is_read = 0",
            [(int) $user['app_id'], (int) $user['id']]
        )['total'] ?? 0);
        $private = (int) ($messages['private_unread'] ?? 0);
        $group = (int) (Database::one(
            "SELECT COUNT(*) AS total
             FROM chat_room_messages gm
             INNER JOIN chat_room_members member ON member.room_id = gm.room_id AND member.user_id = ?
             LEFT JOIN chat_room_reads read_state ON read_state.room_id = gm.room_id AND read_state.user_id = ?
             WHERE gm.app_id = ? AND gm.status = 1 AND gm.id > COALESCE(read_state.last_read_message_id, 0)
               AND (gm.user_id IS NULL OR gm.user_id <> ?)",
            [(int) $user['id'], (int) $user['id'], (int) $user['app_id'], (int) $user['id']]
        )['total'] ?? 0);
        $businessNotifications = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM user_notifications WHERE app_id = ? AND user_id = ? AND is_read = 0',
            [(int) $user['app_id'], (int) $user['id']]
        )['total'] ?? 0);
        $systemNotifications = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM messages
             WHERE app_id = ? AND receiver_user_id = ? AND conversation_id IS NULL
               AND sender_type IN ('system','admin','platform') AND is_read = 0 AND status = 1",
            [(int) $user['app_id'], (int) $user['id']]
        )['total'] ?? 0);
        return Response::success([
            'total' => $private + $group + $service,
            'chat_total' => $private + $group + $service,
            'private' => $private,
            'group' => $group,
            'service' => $service,
            'notification_total' => $businessNotifications + $systemNotifications,
            'business_notification' => $businessNotifications,
            'system_notification' => $systemNotifications,
        ]);
    }

    public static function messageCenter(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $userId = (int) $user['id'];
        $appId = (int) $user['app_id'];
        $limit = min(200, max(1, $request->limit()));

        $privateRows = Database::all(
            "SELECT c.id AS target_id,
                    CASE WHEN c.user_a_id = ? THEN c.user_b_id ELSE c.user_a_id END AS peer_user_id,
                    u.uid AS peer_uid, COALESCE(u.account, '') AS peer_account,
                    p.nickname AS peer_name, p.avatar AS peer_avatar,
                    CASE WHEN lm.content <> '' THEN lm.content
                         WHEN lm.content_type = 'image' THEN '[图片]'
                         WHEN lm.content_type = 'sticker' THEN '[表情包]'
                         WHEN lm.content_type = 'audio' THEN '[语音]'
                         WHEN lm.content_type = 'video' THEN '[视频]'
                         WHEN lm.content_type = 'file' THEN '[文件]'
                         WHEN lm.content_type = 'favorite' THEN '[收藏]'
                         WHEN lm.content_type = 'moment_share' THEN '[动态]'
                         WHEN lm.content_type = 'red_packet' THEN '[红包]'
                         WHEN lm.content_type = 'transfer' THEN '[转账]'
                         WHEN lm.content_type = 'contact_card' THEN '[名片]'
                         WHEN lm.content_type = 'gift' THEN '[礼物]'
                         WHEN lm.content_type = 'location' THEN '[位置]'
                         WHEN lm.content_type = 'mixed' THEN '[多媒体消息]'
                         ELSE '' END AS last_message,
                    COALESCE(lm.content_type, '') AS last_message_type,
                    COALESCE(c.last_message_at, c.created_at) AS last_message_at,
                    (SELECT COUNT(*) FROM messages um
                     WHERE um.conversation_id = c.id AND um.receiver_user_id = ?
                       AND um.is_read = 0 AND um.status = 1) AS unread_count,
                    EXISTS(SELECT 1 FROM friends f WHERE f.app_id = c.app_id AND f.user_id = ?
                           AND f.friend_user_id = CASE WHEN c.user_a_id = ? THEN c.user_b_id ELSE c.user_a_id END
                           AND f.status = 1) AS is_friend
             FROM conversations c
             INNER JOIN users u ON u.id = CASE WHEN c.user_a_id = ? THEN c.user_b_id ELSE c.user_a_id END
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN messages lm ON lm.id = c.last_message_id
             WHERE c.app_id = ? AND (c.user_a_id = ? OR c.user_b_id = ?)
             ORDER BY COALESCE(c.last_message_at, c.created_at) DESC LIMIT {$limit}",
            [$userId, $userId, $userId, $userId, $userId, $appId, $userId, $userId]
        );

        $groupRows = Database::all(
            "SELECT r.id AS target_id, r.name, r.icon, member.role,
                    CASE WHEN lm.content <> '' THEN lm.content
                         WHEN lm.content_type = 'image' THEN '[图片]'
                         WHEN lm.content_type = 'sticker' THEN '[表情包]'
                         WHEN lm.content_type = 'audio' THEN '[语音]'
                         WHEN lm.content_type = 'video' THEN '[视频]'
                         WHEN lm.content_type = 'file' THEN '[文件]'
                         WHEN lm.content_type = 'favorite' THEN '[收藏]'
                         WHEN lm.content_type = 'moment_share' THEN '[动态]'
                         WHEN lm.content_type = 'red_packet' THEN '[红包]'
                         WHEN lm.content_type = 'transfer' THEN '[转账]'
                         WHEN lm.content_type = 'contact_card' THEN '[名片]'
                         WHEN lm.content_type = 'gift' THEN '[礼物]'
                         WHEN lm.content_type = 'location' THEN '[位置]'
                         WHEN lm.content_type = 'mixed' THEN '[多媒体消息]'
                         ELSE '' END AS last_message,
                    COALESCE(lm.content_type, '') AS last_message_type,
                    COALESCE(lm.created_at, r.created_at) AS last_message_at,
                    (SELECT COUNT(*) FROM chat_room_messages unread
                     WHERE unread.room_id = r.id AND unread.status = 1
                       AND unread.id > COALESCE(read_state.last_read_message_id, 0)
                       AND (unread.user_id IS NULL OR unread.user_id <> ?)) AS unread_count
             FROM chat_room_members member
             INNER JOIN chat_rooms r ON r.id = member.room_id AND r.status = 1
             LEFT JOIN chat_room_reads read_state ON read_state.room_id = r.id AND read_state.user_id = member.user_id
             LEFT JOIN chat_room_messages lm ON lm.id = (
                 SELECT MAX(latest.id) FROM chat_room_messages latest
                 WHERE latest.room_id = r.id AND latest.status = 1
             )
             WHERE member.user_id = ? AND r.app_id = ?
             ORDER BY COALESCE(lm.created_at, r.created_at) DESC LIMIT {$limit}",
            [$userId, $userId, $appId]
        );

        $serviceRows = Database::all(
            "SELECT session.id AS target_id, session.subject AS title,
                    COALESCE(latest.content, '在线客服随时为你服务') AS last_message,
                    COALESCE(latest.created_at, session.created_at) AS last_message_at,
                    (SELECT COUNT(*) FROM service_messages unread
                     WHERE unread.session_id = session.id AND unread.sender_type = 'admin' AND unread.is_read = 0) AS unread_count
             FROM service_sessions session
             LEFT JOIN service_messages latest ON latest.id = (
                 SELECT MAX(last_message.id) FROM service_messages last_message WHERE last_message.session_id = session.id
             )
             WHERE session.app_id = ? AND session.user_id = ?
             ORDER BY COALESCE(latest.id, 0) DESC LIMIT 20",
            [$appId, $userId]
        );

        // Return the actual unread message lines used by Android MessagingStyle. A conversation
        // remains one notification group, while every known unread message is still visible.
        $notificationMessages = [];
        $appendNotification = static function (string $key, array $row) use (&$notificationMessages): void {
            if (count($notificationMessages[$key] ?? []) >= 20) return;
            $type = strtolower(trim((string) ($row['content_type'] ?? 'text')));
            $content = trim((string) ($row['content'] ?? ''));
            if ($type !== 'text' || $content === '') {
                $content = match ($type) {
                    'image' => '[图片]', 'sticker' => '[表情包]', 'audio' => '[语音]',
                    'video' => '[视频]', 'file' => '[文件]', 'favorite' => '[收藏]', 'moment_share' => '[动态]',
                    'red_packet' => '[红包]', 'transfer' => '[转账]',
                    'contact_card' => '[名片]', 'gift' => '[礼物]', 'location' => '[位置]', 'mixed' => '[多媒体消息]',
                    default => $content === '' ? '[新消息]' : $content,
                };
            }
            $entry = [
                'id' => (int) ($row['id'] ?? 0),
                'sender_name' => trim((string) ($row['sender_name'] ?? '')) ?: '好友',
                'content' => mb_substr($content, 0, 1000),
                'content_type' => $type,
                'created_at' => $row['created_at'] ?? null,
            ];
            if (!isset($notificationMessages[$key])) $notificationMessages[$key] = [];
            array_unshift($notificationMessages[$key], $entry);
        };
        foreach (Database::all(
            "SELECT m.id, m.conversation_id AS target_id, m.content_type, m.content, m.created_at,
                    COALESCE(NULLIF(p.nickname, ''), NULLIF(sender.account, ''), '好友') AS sender_name
             FROM messages m
             LEFT JOIN users sender ON sender.id = m.sender_id AND sender.app_id = m.app_id
             LEFT JOIN user_profiles p ON p.user_id = sender.id
             WHERE m.app_id = ? AND m.receiver_user_id = ? AND m.conversation_id IS NOT NULL
               AND m.is_read = 0 AND m.status = 1
             ORDER BY m.id DESC LIMIT 500",
            [$appId, $userId]
        ) as $row) $appendNotification('private:' . (int) $row['target_id'], $row);
        foreach (Database::all(
            "SELECT message.id, message.room_id AS target_id, message.content_type, message.content,
                    message.created_at,
                    CASE WHEN message.sender_type IN ('system','admin','platform') THEN '系统消息'
                         ELSE COALESCE(NULLIF(profile.nickname, ''), NULLIF(sender.account, ''), '群成员') END AS sender_name
             FROM chat_room_messages message
             INNER JOIN chat_room_members member ON member.room_id = message.room_id AND member.user_id = ?
             LEFT JOIN chat_room_reads read_state ON read_state.room_id = message.room_id AND read_state.user_id = member.user_id
             LEFT JOIN users sender ON sender.id = message.user_id AND sender.app_id = message.app_id
             LEFT JOIN user_profiles profile ON profile.user_id = sender.id
             WHERE message.app_id = ? AND message.status = 1
               AND message.id > COALESCE(read_state.last_read_message_id, 0)
               AND (message.user_id IS NULL OR message.user_id <> ?)
             ORDER BY message.id DESC LIMIT 500",
            [$userId, $appId, $userId]
        ) as $row) $appendNotification('group:' . (int) $row['target_id'], $row);
        foreach (Database::all(
            "SELECT message.id, message.session_id AS target_id, 'text' AS content_type,
                    message.content, message.created_at, '在线客服' AS sender_name
             FROM service_messages message
             INNER JOIN service_sessions session ON session.id = message.session_id
             WHERE session.app_id = ? AND session.user_id = ?
               AND message.sender_type = 'admin' AND message.is_read = 0
             ORDER BY message.id DESC LIMIT 200",
            [$appId, $userId]
        ) as $row) $appendNotification('service:' . (int) $row['target_id'], $row);

        $items = [];
        $unread = 0;
        foreach ($privateRows as $row) {
            $count = (int) ($row['unread_count'] ?? 0);
            $unread += $count;
            $isFriend = (bool) ($row['is_friend'] ?? false);
            $selfChat = (int) $row['peer_user_id'] === $userId;
            $fallbackName = trim((string) ($row['peer_account'] ?? ''));
            if ($fallbackName === '' || strtolower($fallbackName) === 'null') {
                $fallbackName = '用户 ' . (trim((string) ($row['peer_uid'] ?? '')) ?: (string) $row['peer_user_id']);
            }
            $title = $selfChat ? '我的聊天' : (trim((string) ($row['peer_name'] ?? '')) ?: $fallbackName);
            $items[] = [
                'type' => 'private', 'target_id' => (int) $row['target_id'],
                'peer_user_id' => (int) $row['peer_user_id'], 'title' => $title,
                'account' => $fallbackName, 'uid' => (string) ($row['peer_uid'] ?? ''),
                'avatar' => (string) ($row['peer_avatar'] ?? ''),
                'last_message' => (string) ($row['last_message'] ?? ''),
                'last_message_type' => (string) ($row['last_message_type'] ?? ''),
                'last_message_at' => $row['last_message_at'], 'unread_count' => $count,
                'is_friend' => $isFriend, 'is_stranger' => !$isFriend,
                'is_self' => $selfChat,
            ];
        }
        foreach ($groupRows as $row) {
            $count = (int) ($row['unread_count'] ?? 0);
            $unread += $count;
            $items[] = [
                'type' => 'group', 'target_id' => (int) $row['target_id'],
                'title' => (string) $row['name'], 'avatar' => (string) ($row['icon'] ?? ''),
                'last_message' => (string) ($row['last_message'] ?? ''),
                'last_message_type' => (string) ($row['last_message_type'] ?? ''),
                'last_message_at' => $row['last_message_at'], 'unread_count' => $count,
                'role' => $row['role'], 'is_friend' => false, 'is_stranger' => false,
            ];
        }
        foreach ($serviceRows as $row) {
            $count = (int) ($row['unread_count'] ?? 0);
            $unread += $count;
            $items[] = [
                'type' => 'service', 'target_id' => (int) $row['target_id'],
                'title' => trim((string) ($row['title'] ?? '')) ?: '在线客服',
                'last_message' => (string) ($row['last_message'] ?? ''),
                'last_message_type' => 'text',
                'last_message_at' => $row['last_message_at'], 'unread_count' => $count,
                'is_friend' => false, 'is_stranger' => false,
            ];
        }
        if ($serviceRows === []) {
            $items[] = [
                'type' => 'service', 'target_id' => 0, 'title' => '在线客服', 'avatar' => '',
                'last_message' => '有问题可以随时联系我们', 'last_message_at' => null, 'unread_count' => 0,
                'last_message_type' => 'text',
                'is_friend' => false, 'is_stranger' => false,
            ];
        }
        $items[] = [
            'type' => 'bot', 'target_id' => 0, 'title' => '智能机器人', 'avatar' => '',
            'last_message' => '智能问答与常见问题助手', 'last_message_at' => null, 'unread_count' => 0,
            'last_message_type' => 'text',
            'is_friend' => false, 'is_stranger' => false,
        ];
        $draftRows = Database::all(
            "SELECT target_type, target_id, content, updated_at,
                    UNIX_TIMESTAMP(updated_at) * 1000 AS updated_at_epoch
             FROM composer_drafts
             WHERE app_id = ? AND user_id = ?
               AND (TRIM(content) <> ''
                    OR COALESCE(attachments_json, '[]') NOT IN ('', '[]')
                    OR COALESCE(tags_json, '[]') NOT IN ('', '[]'))",
            [$appId, $userId]
        );
        $draftMap = [];
        foreach ($draftRows as $draft) {
            $draftMap[(string) $draft['target_type'] . ':' . (int) $draft['target_id']] = $draft;
        }
        $preferenceRows = Database::all(
            'SELECT target_type, target_id, is_pinned, is_bottomed, is_hidden, is_muted, updated_at
             FROM conversation_preferences WHERE app_id = ? AND user_id = ?',
            [$appId, $userId]
        );
        $preferenceMap = [];
        foreach ($preferenceRows as $preference) {
            $preferenceMap[(string) $preference['target_type'] . ':' . (int) $preference['target_id']] = $preference;
        }
        $includeHidden = filter_var($request->input('include_hidden', false), FILTER_VALIDATE_BOOLEAN);
        $hiddenCount = 0;
        foreach ($items as &$item) {
            $item['notification_messages'] = $notificationMessages[
                (string) $item['type'] . ':' . (int) $item['target_id']
            ] ?? [];
            $preference = $preferenceMap[(string) $item['type'] . ':' . (int) $item['target_id']] ?? [];
            $draftKey = (string) $item['type'] . ':' . (int) $item['target_id'];
            $draft = $draftMap[$draftKey] ?? ((string) $item['type'] === 'service'
                ? ($draftMap['service:0'] ?? null) : null);
            $item['is_pinned'] = (bool) ($preference['is_pinned'] ?? false);
            $item['is_bottomed'] = (bool) ($preference['is_bottomed'] ?? false);
            $item['is_hidden'] = (bool) ($preference['is_hidden'] ?? false);
            $item['is_muted'] = (bool) ($preference['is_muted'] ?? false);
            $item['preference_updated_at'] = $preference['updated_at'] ?? null;
            $item['has_draft'] = $draft !== null;
            $item['draft_content'] = $draft === null ? '' : (string) ($draft['content'] ?? '');
            $item['draft_updated_at'] = $draft['updated_at'] ?? null;
            $item['draft_updated_at_epoch'] = $draft === null ? 0 : (int) ($draft['updated_at_epoch'] ?? 0);
            if ($item['is_hidden']) $hiddenCount++;
        }
        unset($item);
        if (!$includeHidden) {
            $items = array_values(array_filter($items, static fn(array $item): bool => !(bool) $item['is_hidden']));
        }
        usort($items, static function (array $left, array $right): int {
            $pinned = ((int) ($right['is_pinned'] ?? 0)) <=> ((int) ($left['is_pinned'] ?? 0));
            if ($pinned !== 0) return $pinned;
            $bottomed = ((int) ($left['is_bottomed'] ?? 0)) <=> ((int) ($right['is_bottomed'] ?? 0));
            if ($bottomed !== 0) return $bottomed;
            $draft = ((int) ($right['has_draft'] ?? 0)) <=> ((int) ($left['has_draft'] ?? 0));
            if ($draft !== 0) return $draft;
            if ((bool) ($left['has_draft'] ?? false) && (bool) ($right['has_draft'] ?? false)) {
                $draftTime = ((int) ($right['draft_updated_at_epoch'] ?? 0))
                    <=> ((int) ($left['draft_updated_at_epoch'] ?? 0));
                if ($draftTime !== 0) return $draftTime;
            }
            return strcmp((string) ($right['last_message_at'] ?? ''), (string) ($left['last_message_at'] ?? ''));
        });
        $businessNotificationUnread = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM user_notifications WHERE app_id = ? AND user_id = ? AND is_read = 0',
            [$appId, $userId]
        )['total'] ?? 0);
        $systemNotificationUnread = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM messages
             WHERE app_id = ? AND receiver_user_id = ? AND conversation_id IS NULL
               AND sender_type IN ('system','admin','platform') AND is_read = 0 AND status = 1",
            [$appId, $userId]
        )['total'] ?? 0);

        return Response::success([
            'items' => array_slice($items, 0, $limit),
            'unread_count' => $unread,
            'content_scope' => 'chat_only',
            'notification_unread_count' => $businessNotificationUnread + $systemNotificationUnread,
            'pinned_count' => count(array_filter($items, static fn(array $item): bool => (bool) ($item['is_pinned'] ?? false))),
            'bottomed_count' => count(array_filter($items, static fn(array $item): bool => (bool) ($item['is_bottomed'] ?? false))),
            'hidden_count' => $hiddenCount,
            'relationship_summary' => self::relationshipNoticeSummary($user),
            'settings' => self::messagePreferenceData($user),
            'poll_interval_ms' => (int) AppService::chatPollingPolicy($appId)['effective_interval_ms'],
            'message_recall_policy' => AppService::messageRecallPolicy($appId),
        ]);
    }

    public static function messageSettings(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        return Response::success(['settings' => self::messagePreferenceData($user)]);
    }

    public static function saveMessageSettings(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $current = self::messagePreferenceData($user);
        $values = [];
        $booleanFields = [
            'accept_stranger_messages', 'allow_friend_requests', 'system_notification_enabled',
            'private_notification_enabled', 'group_notification_enabled',
            'profile_notes_visible', 'profile_forum_visible', 'profile_bounties_visible',
            'profile_following_visible', 'profile_followers_visible',
            'allow_card_add', 'allow_qr_add', 'allow_uid_search', 'allow_phone_search',
            'allow_email_search', 'allow_group_member_add', 'allow_group_invitations',
            'show_online_status', 'read_receipts_enabled',
            'room_notification_enabled', 'forum_notification_enabled',
            'bounty_notification_enabled', 'mention_notification_enabled',
            'notification_preview_enabled', 'notification_sound_enabled',
            'notification_vibration_enabled', 'remote_login_protection', 'dynamic_enabled', 'dynamic_visible_to_friends',
            'dynamic_visible_to_followers', 'dynamic_visible_to_strangers',
            'dynamic_visible_to_hidden_contacts', 'dynamic_visible_to_special_care',
        ];
        foreach ($booleanFields as $field) {
            $values[$field] = array_key_exists($field, $request->all())
                ? Validator::boolean($request->input($field), $field)
                : (bool) $current[$field];
        }
        $values['dynamic_visible_days'] = array_key_exists('dynamic_visible_days', $request->all())
            ? MomentVisibilityService::normalizeDays($request->input('dynamic_visible_days'))
            : (int) $current['dynamic_visible_days'];
        $values['dynamic_visibility_mode'] = array_key_exists('dynamic_visibility_mode', $request->all())
            ? MomentVisibilityService::normalizeMode((string) $request->input('dynamic_visibility_mode'), false)
            : (string) $current['dynamic_visibility_mode'];
        $values['dynamic_allow_user_ids'] = array_key_exists('dynamic_allow_user_ids', $request->all())
            ? MomentVisibilityService::normalizeUserIds($request->input('dynamic_allow_user_ids'), (int) $user['app_id'], (int) $user['id'])
            : (array) $current['dynamic_allow_user_ids'];
        $values['dynamic_deny_user_ids'] = array_key_exists('dynamic_deny_user_ids', $request->all())
            ? MomentVisibilityService::normalizeUserIds($request->input('dynamic_deny_user_ids'), (int) $user['app_id'], (int) $user['id'])
            : (array) $current['dynamic_deny_user_ids'];

        $databaseValues = [
            'admin_id' => (int) $user['admin_id'],
            'app_id' => (int) $user['app_id'],
            'user_id' => (int) $user['id'],
        ];
        foreach ($booleanFields as $field) $databaseValues[$field] = $values[$field] ? 1 : 0;
        $databaseValues['dynamic_visible_days'] = $values['dynamic_visible_days'];
        $databaseValues['dynamic_visibility_mode'] = $values['dynamic_visibility_mode'];
        $databaseValues['dynamic_allow_user_ids_json'] = MomentVisibilityService::encodeIds($values['dynamic_allow_user_ids']);
        $databaseValues['dynamic_deny_user_ids_json'] = MomentVisibilityService::encodeIds($values['dynamic_deny_user_ids']);
        $columns = array_keys($databaseValues);
        $updates = array_values(array_filter($columns, static fn(string $column): bool => !in_array($column, ['admin_id', 'app_id', 'user_id'], true)));
        Database::execute(
            'INSERT INTO user_message_preferences (' . implode(', ', $columns) . ', created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ', NOW(), NOW()) ON DUPLICATE KEY UPDATE '
            . implode(', ', array_map(static fn(string $column): string => "{$column} = VALUES({$column})", $updates))
            . ', updated_at = NOW()',
            array_values($databaseValues)
        );
        return Response::success(['settings' => $values], '消息与通知设置已保存');
    }

    public static function saveConversationPreference(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $targetType = strtolower(trim((string) ($params['target_type'] ?? '')));
        $targetId = max(0, (int) ($params['target_id'] ?? 0));
        self::assertConversationTarget($user, $targetType, $targetId);
        $current = Database::one(
            'SELECT * FROM conversation_preferences WHERE user_id = ? AND target_type = ? AND target_id = ?',
            [(int) $user['id'], $targetType, $targetId]
        ) ?? [];
        $pinned = array_key_exists('is_pinned', $request->all())
            ? Validator::boolean($request->input('is_pinned'), 'is_pinned') : (bool) ($current['is_pinned'] ?? false);
        $bottomed = array_key_exists('is_bottomed', $request->all())
            ? Validator::boolean($request->input('is_bottomed'), 'is_bottomed') : (bool) ($current['is_bottomed'] ?? false);
        if ($pinned && $bottomed) {
            if (array_key_exists('is_bottomed', $request->all())) $pinned = false;
            else $bottomed = false;
        }
        $hidden = array_key_exists('is_hidden', $request->all())
            ? Validator::boolean($request->input('is_hidden'), 'is_hidden') : (bool) ($current['is_hidden'] ?? false);
        $muted = array_key_exists('is_muted', $request->all())
            ? Validator::boolean($request->input('is_muted'), 'is_muted') : (bool) ($current['is_muted'] ?? false);
        Database::execute(
            'INSERT INTO conversation_preferences
             (admin_id, app_id, user_id, target_type, target_id, is_pinned, is_bottomed, is_hidden, is_muted, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE is_pinned = VALUES(is_pinned), is_bottomed = VALUES(is_bottomed), is_hidden = VALUES(is_hidden),
               is_muted = VALUES(is_muted), updated_at = NOW()',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $targetType, $targetId,
             $pinned ? 1 : 0, $bottomed ? 1 : 0, $hidden ? 1 : 0, $muted ? 1 : 0]
        );
        return Response::success([
            'target_type' => $targetType, 'target_id' => $targetId,
            'is_pinned' => $pinned, 'is_bottomed' => $bottomed, 'is_hidden' => $hidden, 'is_muted' => $muted,
        ], '会话设置已保存');
    }

    public static function draft(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        [$targetType, $targetId] = self::draftTarget($params);
        $draft = Database::one(
            'SELECT target_type, target_id, content, attachments_json, tags_json, updated_at
             FROM composer_drafts WHERE user_id = ? AND target_type = ? AND target_id = ?',
            [(int) $user['id'], $targetType, $targetId]
        );
        if ($draft !== null) {
            $draft['attachments'] = json_decode((string) ($draft['attachments_json'] ?? '[]'), true) ?: [];
            $draft['tags'] = json_decode((string) ($draft['tags_json'] ?? '[]'), true) ?: [];
            unset($draft['attachments_json'], $draft['tags_json']);
        }
        return Response::success(['draft' => $draft]);
    }

    public static function saveDraft(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        [$targetType, $targetId] = self::draftTarget($params);
        $content = mb_substr((string) $request->input('content', ''), 0, 20000);
        $attachments = self::jsonList($request->input('attachments', []), 'attachments', 200);
        $tags = self::jsonList($request->input('tags', []), 'tags', 50);
        if (trim($content) === '' && $attachments === [] && $tags === []) {
            Database::execute(
                'DELETE FROM composer_drafts WHERE user_id = ? AND target_type = ? AND target_id = ?',
                [(int) $user['id'], $targetType, $targetId]
            );
            return Response::success([
                'target_type' => $targetType, 'target_id' => $targetId, 'deleted' => true,
            ], '空草稿已清除');
        }
        Database::execute(
            'INSERT INTO composer_drafts
             (admin_id, app_id, user_id, target_type, target_id, content, attachments_json, tags_json, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE content = VALUES(content), attachments_json = VALUES(attachments_json),
               tags_json = VALUES(tags_json), updated_at = NOW()',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $targetType, $targetId,
             $content, json_encode($attachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
             json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
        return Response::success(['target_type' => $targetType, 'target_id' => $targetId], '草稿已保存');
    }

    public static function deleteDraft(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        [$targetType, $targetId] = self::draftTarget($params);
        Database::execute('DELETE FROM composer_drafts WHERE user_id = ? AND target_type = ? AND target_id = ?', [
            (int) $user['id'], $targetType, $targetId,
        ]);
        return Response::success(['target_type' => $targetType, 'target_id' => $targetId], '草稿已清除');
    }

    public static function conversations(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM conversations WHERE app_id = ? AND (user_a_id = ? OR user_b_id = ?)',
            [(int) $user['app_id'], (int) $user['id'], (int) $user['id']]
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT c.*,
                    CASE WHEN c.user_a_id = ? THEN c.user_b_id ELSE c.user_a_id END AS peer_user_id,
                    u.account AS peer_account, p.nickname AS peer_name, p.avatar AS peer_avatar,
                    lm.content AS last_message, lm.sender_id AS last_sender_id,
                    (SELECT COUNT(*) FROM messages um
                     WHERE um.conversation_id = c.id AND um.receiver_user_id = ? AND um.is_read = 0 AND um.status = 1) AS unread_count
             FROM conversations c
             INNER JOIN users u ON u.id = CASE WHEN c.user_a_id = ? THEN c.user_b_id ELSE c.user_a_id END
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN messages lm ON lm.id = c.last_message_id
             WHERE c.app_id = ? AND (c.user_a_id = ? OR c.user_b_id = ?)
             ORDER BY COALESCE(c.last_message_at, c.created_at) DESC LIMIT {$limit} OFFSET {$offset}",
            [(int) $user['id'], (int) $user['id'], (int) $user['id'], (int) $user['app_id'], (int) $user['id'], (int) $user['id']]
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function conversationMessages(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $conversation = self::conversation($user, (int) $params['conversation_id']);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['m.conversation_id = ?', 'm.status = 1', 'COALESCE(s.is_deleted, 0) = 0'];
        $query = [(int) $conversation['id']];
        $sinceId = (int) $request->input('since_id', 0);
        if ($sinceId > 0) {
            $where[] = 'm.id > ?';
            $query[] = $sinceId;
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(m.content LIKE ? OR u.account LIKE ? OR p.nickname LIKE ? OR viewer_friend.remark LIKE ?)';
            $like = '%' . $keyword . '%';
            array_push($query, $like, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM messages m
             LEFT JOIN message_user_states s ON s.message_id = m.id AND s.user_id = ?
             LEFT JOIN users u ON u.id = CASE WHEN m.sender_type = 'user' OR m.content_type = 'recall' THEN m.sender_id ELSE NULL END
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN friends viewer_friend ON viewer_friend.app_id = m.app_id AND viewer_friend.user_id = ?
               AND viewer_friend.friend_user_id = u.id AND viewer_friend.status = 1
             WHERE {$whereSql}",
            array_merge([(int) $user['id'], (int) $user['id']], $query)
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT m.id, m.sender_type, m.sender_id, m.receiver_user_id, m.title, m.content_type, m.tags_json,
                    m.reply_to_message_id,
                    CASE WHEN r.id IS NULL THEN m.content
                         ELSE COALESCE(NULLIF(r.notice_text, ''), '[消息已撤回]') END AS content,
                    m.is_read, m.read_at, m.created_at, COALESCE(s.is_favorite, 0) AS is_favorite,
                    (r.id IS NOT NULL) AS recalled, r.reason AS recall_reason, r.notice_text AS recall_notice,
                    r.created_at AS recalled_at, u.account AS sender_account,
                    p.nickname AS sender_nickname, COALESCE(viewer_friend.remark, '') AS sender_remark,
                    COALESCE(NULLIF(viewer_friend.remark, ''), NULLIF(p.nickname, ''), u.account,
                      CASE m.sender_type WHEN 'admin' THEN '管理员' WHEN 'platform' THEN '平台'
                           WHEN 'system' THEN '系统' ELSE '用户' END) AS sender_name,
                    COALESCE(p.avatar, '') AS sender_avatar,
                    vc.id AS call_id, vc.call_type, vc.status AS call_status,
                    vc.duration_seconds AS call_duration_seconds,
                    vc.caller_user_id AS call_caller_user_id,
                    vc.callee_user_id AS call_callee_user_id,
                    vc.context_type AS call_context_type, vc.context_id AS call_context_id,
                    COALESCE(NULLIF(call_caller_profile.nickname, ''), call_caller.account, '') AS call_caller_name,
                    COALESCE(NULLIF(call_callee_profile.nickname, ''), call_callee.account, '') AS call_callee_name,
                    COALESCE(call_caller_profile.avatar, '') AS call_caller_avatar,
                    COALESCE(call_callee_profile.avatar, '') AS call_callee_avatar
             FROM messages m LEFT JOIN message_user_states s ON s.message_id = m.id AND s.user_id = ?
             LEFT JOIN message_recalls r ON r.message_id = m.id
             LEFT JOIN users u ON u.id = CASE WHEN m.sender_type = 'user' OR m.content_type = 'recall' THEN m.sender_id ELSE NULL END
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN friends viewer_friend ON viewer_friend.app_id = m.app_id AND viewer_friend.user_id = ?
               AND viewer_friend.friend_user_id = u.id AND viewer_friend.status = 1
             LEFT JOIN voice_calls vc ON vc.private_message_id = m.id
             LEFT JOIN users call_caller ON call_caller.id = vc.caller_user_id
             LEFT JOIN user_profiles call_caller_profile ON call_caller_profile.user_id = call_caller.id
             LEFT JOIN users call_callee ON call_callee.id = vc.callee_user_id
             LEFT JOIN user_profiles call_callee_profile ON call_callee_profile.user_id = call_callee.id
             WHERE {$whereSql} ORDER BY m.id " . ($sinceId > 0 ? 'ASC' : 'DESC') . " LIMIT {$limit} OFFSET {$offset}",
            array_merge([(int) $user['id'], (int) $user['id']], $query)
        );
        if ($sinceId <= 0) $items = array_reverse($items);
        $items = ContentTagService::hydrate($items);
        $items = MessageMediaService::hydrate($items, 'private_message', (int) $user['app_id']);
        $items = MessageForwardService::hydrate($items, 'private_message', (int) $user['app_id']);
        $items = MessagePresentationService::hydrate($items, 'private', (int) $user['id']);
        $recallPolicy = AppService::messageRecallPolicy((int) $user['app_id']);
        $recallSeconds = (int) $recallPolicy['effective_seconds'];
        foreach ($items as &$item) {
            $item['recalled_message_id'] = (string) ($item['content_type'] ?? '') === 'recall'
                ? (int) ($item['title'] ?? 0)
                : null;
            if ((bool) ($item['recalled'] ?? false)) {
                $item['attachments'] = [];
                $item['attachment_count'] = 0;
                $item['has_media'] = false;
                $item['media_summary'] = '';
                $item['attachments_hidden_by_recall'] = true;
            }
            $item['can_recall'] = self::canRecallPrivateMessage($item, $user, $recallSeconds);
        }
        unset($item);
        Database::execute(
            'UPDATE messages SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE conversation_id = ? AND receiver_user_id = ? AND is_read = 0',
            [(int) $conversation['id'], (int) $user['id']]
        );
        return Response::success(array_merge(Pagination::data($items, $total, $page, $limit), [
            'conversation_id' => (int) $conversation['id'],
            'message_recall_policy' => $recallPolicy,
        ]));
    }

    public static function privateMessage(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        AuthService::ensureNotBanned($user, ['all', 'message']);
        if (!AppService::setting((int) $user['app_id'], 'private_message_enabled', true)) {
            throw new HttpException('当前应用已关闭私信功能', 403, 403);
        }
        $toUserId = IdentityService::resolveUserReference((int) $user['app_id'], $request->input('to_uid', $request->input('to_user_id')));
        $selfChat = $toUserId === (int) $user['id'];
        $receiver = Database::one(
            'SELECT id FROM users WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1 AND deleted_at IS NULL',
            [$toUserId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        if ($receiver === null) {
            throw new HttpException('接收用户不存在或不可用', 404, 404);
        }
        if (!$selfChat) {
            SocialController::assertNotBlocked($user, $toUserId);
            if (!self::isFriend($user, $toUserId) && !self::acceptsStrangerMessages($receiver, (int) $user['app_id'])) {
                throw new HttpException('对方已关闭陌生人消息，请先发送好友申请', 403, 403);
            }
        }
        $payload = MessageMediaService::userPayload($user, $request->all());
        MessageMediaService::assertChatFeatures((int) $user['app_id'], $payload);
        $tagsJson = ContentTagService::encode($request->input('tags', []));
        $replyRequestId = max(0, (int) $request->input('reply_to_message_id', 0));
        [$a, $b] = [(int) $user['id'], $toUserId];
        if ($a > $b) {
            [$a, $b] = [$b, $a];
        }
        $result = Database::transaction(static function () use ($user, $toUserId, $payload, $tagsJson, $a, $b, $selfChat, $replyRequestId): array {
            Database::execute(
                'INSERT INTO conversations
                 (admin_id, app_id, type, user_a_id, user_b_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE updated_at = NOW()',
                [(int) $user['admin_id'], (int) $user['app_id'], 'private', $a, $b]
            );
            $conversation = Database::one(
                'SELECT * FROM conversations WHERE app_id = ? AND type = ? AND user_a_id = ? AND user_b_id = ? FOR UPDATE',
                [(int) $user['app_id'], 'private', $a, $b]
            );
            if ($conversation === null) {
                throw new HttpException('创建会话失败', -1, 500);
            }
            $replyId = null;
            if ($replyRequestId > 0) {
                $reply = Database::one(
                    'SELECT id FROM messages WHERE id = ? AND conversation_id = ? AND status = 1 LIMIT 1',
                    [$replyRequestId, (int) $conversation['id']]
                );
                if ($reply === null) throw new HttpException('被引用的私聊消息不存在', 0, 404);
                $replyId = (int) $reply['id'];
            }
            $messageId = Database::insert(
                'INSERT INTO messages
                 (admin_id, app_id, conversation_id, sender_type, sender_id, receiver_user_id,
                   title, content_type, content, tags_json, reply_to_message_id, is_read, status, created_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $conversation['id'],
                    'user', (int) $user['id'], $toUserId, '', (string) $payload['content_type'], (string) $payload['content'], $tagsJson,
                    $replyId, $selfChat ? 1 : 0,
                ]
            );
            MessageMediaService::save('private_message', $messageId, $payload);
            Database::execute(
                'UPDATE conversations SET last_message_id = ?, last_message_at = NOW(), updated_at = NOW() WHERE id = ?',
                [$messageId, (int) $conversation['id']]
            );
            return [
                'conversation_id' => (int) $conversation['id'],
                'message_id' => $messageId,
                'reply_to_message_id' => $replyId,
            ];
        });
        $mentions = $request->input('mentions', []);
        if (!is_array($mentions)) $mentions = [];
        $mentionIds = array_values(array_unique(array_filter(array_map('intval', $mentions), static fn (int $id): bool => $id > 0)));
        if (!$selfChat && in_array($toUserId, $mentionIds, true)) {
            $mentionedUser = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], $toUserId);
            if ($mentionedUser !== null) {
                $senderName = trim((string) ($user['nickname'] ?? $user['account'] ?? '好友'));
                NotificationService::send(
                    $mentionedUser,
                    'chat_mention',
                    '私聊中有人提到你',
                    ($senderName === '' ? '好友' : $senderName) . ' 在私聊中提到了你',
                    [
                        'conversation_id' => (int) $result['conversation_id'],
                        'message_id' => (int) $result['message_id'],
                        'sender_user_id' => (int) $user['id'],
                        'sender_name' => $senderName === '' ? '好友' : $senderName,
                    ]
                );
            }
        }
        LogService::userOperation($request, $user, 'message', 'private_send', $result['message_id'], ['to_user_id' => $toUserId]);
        $message = self::sentPrivateMessage(
            $user,
            (int) $result['message_id'],
            $toUserId,
            $payload,
            $tagsJson,
            $result['reply_to_message_id'] === null ? null : (int) $result['reply_to_message_id'],
            $selfChat
        );
        unset($result['reply_to_message_id']);
        return Response::success(
            $result + ['is_self_chat' => $selfChat, 'message' => $message],
            $selfChat ? '已发送到我的聊天' : '私信发送成功',
            201
        );
    }

    private static function sentPrivateMessage(
        array $user,
        int $messageId,
        int $receiverUserId,
        array $payload,
        string $tagsJson,
        ?int $replyToMessageId,
        bool $isSelfChat
    ): array {
        $stored = Database::one('SELECT created_at FROM messages WHERE id = ? LIMIT 1', [$messageId]);
        $displayName = trim((string) ($user['nickname'] ?? $user['account'] ?? '用户'));
        if ($displayName === '') $displayName = '用户';
        $items = [[
            'id' => $messageId,
            'sender_type' => 'user',
            'sender_id' => (int) $user['id'],
            'receiver_user_id' => $receiverUserId,
            'title' => '',
            'content_type' => (string) $payload['content_type'],
            'content' => (string) $payload['content'],
            'tags_json' => $tagsJson,
            'reply_to_message_id' => $replyToMessageId,
            'is_read' => $isSelfChat ? 1 : 0,
            'read_at' => null,
            'created_at' => (string) ($stored['created_at'] ?? date('Y-m-d H:i:s')),
            'is_favorite' => 0,
            'recalled' => false,
            'sender_account' => (string) ($user['account'] ?? ''),
            'sender_name' => $displayName,
            'sender_avatar' => (string) ($user['avatar'] ?? ''),
            'can_recall' => true,
        ]];
        $items = ContentTagService::hydrate($items);
        $items = MessageMediaService::hydrate($items, 'private_message', (int) $user['app_id']);
        $items = MessageForwardService::hydrate($items, 'private_message', (int) $user['app_id']);
        $items = MessagePresentationService::hydrate($items, 'private', (int) $user['id']);
        return $items[0] ?? [];
    }

    public static function forwardMessages(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        AuthService::ensureNotBanned($user, ['all', 'message']);
        $sourceType = trim((string) $request->input('source_type', ''));
        $sourceId = Validator::integer($request->input('source_id'), 'source_id', 1, PHP_INT_MAX);
        $targetType = trim((string) $request->input('target_type', ''));
        $targetId = max(0, (int) $request->input('target_id', 0));
        if (!in_array($sourceType, ['private', 'group', 'service'], true)) {
            throw new HttpException('source_type 仅支持 private、group 或 service', 0, 422);
        }
        if (!in_array($targetType, ['private', 'group', 'chat_room', 'forum', 'forum_post', 'service'], true)) {
            throw new HttpException('不能转发给机器人，目标仅支持私聊、群聊、聊天室、论坛新帖、已有帖子评论或客服', 0, 422);
        }
        $messageIds = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('message_ids', [])), static fn(int $id): bool => $id > 0)));
        if ($messageIds === [] || count($messageIds) > 50) throw new HttpException('请选择 1-50 条消息', 0, 422);
        $anonymityMode = strtolower(trim((string) $request->input('anonymity_mode', 'none')));
        if (!in_array($anonymityMode, ['none', 'selected', 'full'], true)) {
            throw new HttpException('anonymity_mode 仅支持 none、selected 或 full', 0, 422);
        }
        $anonymousSenderKeys = [];
        foreach ((array) $request->input('anonymous_sender_keys', []) as $value) {
            $key = trim((string) $value);
            if ($key !== '' && mb_strlen($key) <= 160) $anonymousSenderKeys[$key] = true;
        }
        $anonymousSenderKeys = array_keys($anonymousSenderKeys);
        if ($anonymityMode === 'selected' && $anonymousSenderKeys === []) {
            throw new HttpException('选择部分匿名时至少要选择一位发送者', 0, 422);
        }
        if (($sourceType === 'service' || $targetType === 'service') && $anonymityMode !== 'none') {
            throw new HttpException('客服聊天依法保留真实身份，不能匿名转发，也不能隐藏会话来源', 0, 422);
        }
        $rows = self::forwardSourceRows($user, $sourceType, $sourceId, $messageIds);
        if (count($rows) !== count($messageIds)) throw new HttpException('部分消息不存在、已撤回或无权转发', 0, 422);
        if ($targetType === 'service' && MessageForwardService::containsAnonymousSnapshot($rows)) {
            throw new HttpException('匿名聊天快照不能转发给客服，请选择不含匿名内容的消息', 0, 422);
        }
        $content = self::forwardText($rows, $anonymityMode);
        $tagsJson = ContentTagService::encode($request->input('tags', ['聊天记录']));
        $forumTitle = $targetType === 'forum_post'
            ? Validator::string($request->input('forum_title', ''), 'forum_title', 1, 200)
            : '';
        $forumIntro = $targetType === 'forum_post'
            ? mb_substr(trim((string) $request->input('forum_content', '')), 0, 10000)
            : '';
        $forumCategoryId = $request->input('forum_category_id');
        $forumTags = $request->input('forum_tags', ['聊天记录']);
        $result = Database::transaction(static function () use (
            $user, $sourceType, $sourceId, $rows, $targetType, $targetId, $content, $tagsJson,
            $anonymityMode, $anonymousSenderKeys, $forumTitle, $forumIntro, $forumCategoryId, $forumTags
        ): array {
            $bundle = MessageForwardService::create($user, $sourceType, $sourceId, $rows, [
                'anonymity_mode' => $anonymityMode,
                'anonymous_sender_keys' => $anonymousSenderKeys,
            ]);
            if ($targetType === 'private') $target = self::forwardToPrivate($user, $targetId, $content, $tagsJson);
            elseif ($targetType === 'group' || $targetType === 'chat_room') $target = self::forwardToGroup(
                $user, $targetId, $content, $tagsJson, $targetType
            );
            elseif ($targetType === 'forum') $target = self::forwardToForum($user, $targetId, $content);
            elseif ($targetType === 'forum_post') $target = self::forwardToForumPost(
                $user, $targetId, $forumTitle, $forumIntro, $content, $forumCategoryId, $forumTags
            );
            else $target = self::forwardToService($user, $content);
            $linkType = match ((string) $target['target_type']) {
                'private' => 'private_message', 'group', 'chat_room' => 'group_message',
                'forum' => 'forum_comment', 'forum_post' => 'forum_post', default => 'service_message',
            };
            $linkId = (int) ($target['message_id'] ?? $target['comment_id'] ?? $target['post_id'] ?? 0);
            MessageForwardService::link($user, (int) $bundle['id'], $linkType, $linkId);
            return $target + ['forward_bundle_id' => (int) $bundle['id'], 'forward_title' => (string) $bundle['title']];
        });
        LogService::userOperation($request, $user, 'message', 'forward', (int) ($result['message_id'] ?? $result['comment_id'] ?? $result['post_id'] ?? 0), [
            'source_type' => $sourceType, 'source_id' => $sourceId, 'target_type' => $targetType,
            'target_id' => $targetId, 'message_count' => count($rows), 'anonymity_mode' => $anonymityMode,
        ]);
        return Response::success($result + [
            'forwarded_count' => count($rows),
            'anonymity_mode' => $anonymityMode,
        ], $targetType === 'forum_post' ? '聊天记录已发布为论坛新帖' : '聊天记录已转发', 201);
    }

    public static function forwardBundle(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        return Response::success([
            'forward' => MessageForwardService::showForUser($user, (int) $params['forward_id']),
        ]);
    }

    public static function searchMessages(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        return Response::success(ChatSearchService::search(
            $user,
            (string) $request->input('scope_type', ''),
            max(0, (int) $request->input('target_id', 0)),
            (string) $request->input('keyword', ''),
            (int) $request->input('context_size', 3),
            (int) $request->input('limit', 30),
            $request->all()
        ));
    }

    public static function searchHistory(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        return Response::success(['items' => ChatSearchService::history(
            $user,
            trim((string) $request->input('scope_type', '')),
            max(0, (int) $request->input('target_id', 0))
        )]);
    }

    public static function clearSearchHistory(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $deleted = ChatSearchService::clearHistory(
            $user,
            trim((string) $request->input('scope_type', '')),
            max(0, (int) $request->input('target_id', 0)),
            trim((string) $request->input('keyword', ''))
        );
        return Response::success(['deleted_count' => $deleted], '搜索历史已清理');
    }

    public static function messageState(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages'); $message = self::ownedMessage($user, (int) $params['message_id']);
        $action = trim((string) $request->input('action', 'favorite'));
        if (!in_array($action, ['favorite', 'delete'], true)) throw new HttpException('action 仅支持 favorite 或 delete', 0, 422);
        $state = Database::one('SELECT * FROM message_user_states WHERE message_id = ? AND user_id = ?', [(int) $message['id'], (int) $user['id']]);
        $favorite = (int) ($state['is_favorite'] ?? 0); $deleted = (int) ($state['is_deleted'] ?? 0);
        if ($action === 'favorite') $favorite = $favorite === 1 ? 0 : 1; else $deleted = 1;
        Database::execute(
            'INSERT INTO message_user_states (message_id, user_id, is_deleted, is_favorite, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE is_deleted = VALUES(is_deleted), is_favorite = VALUES(is_favorite), updated_at = NOW()',
            [(int) $message['id'], (int) $user['id'], $deleted, $favorite]
        );
        return Response::success(['message_id' => (int) $message['id'], 'is_deleted' => (bool) $deleted, 'is_favorite' => (bool) $favorite], $action === 'delete' ? '消息已从当前账号删除' : ($favorite ? '消息已收藏' : '已取消收藏'));
    }

    public static function communicationMessageState(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $scopeType = strtolower(trim((string) ($params['scope_type'] ?? '')));
        $targetId = Validator::integer($params['target_id'] ?? 0, 'target_id', 1, PHP_INT_MAX);
        $messageId = Validator::integer($params['message_id'] ?? 0, 'message_id', 1, PHP_INT_MAX);
        self::assertCommunicationMessage($user, $scopeType, $targetId, $messageId);
        $action = strtolower(trim((string) $request->input('action', 'favorite')));
        if (!in_array($action, ['favorite', 'unfavorite', 'delete', 'restore'], true)) {
            throw new HttpException('action 仅支持 favorite、unfavorite、delete 或 restore', 0, 422);
        }
        $current = Database::one(
            'SELECT is_deleted, is_favorite FROM communication_message_states
             WHERE user_id = ? AND scope_type = ? AND message_id = ?',
            [(int) $user['id'], $scopeType, $messageId]
        ) ?? [];
        $deleted = (bool) ($current['is_deleted'] ?? false);
        $favorite = (bool) ($current['is_favorite'] ?? false);
        if ($action === 'favorite') $favorite = true;
        if ($action === 'unfavorite') $favorite = false;
        if ($action === 'delete') $deleted = true;
        if ($action === 'restore') $deleted = false;
        Database::execute(
            'INSERT INTO communication_message_states
             (admin_id, app_id, user_id, scope_type, target_id, message_id, is_deleted, is_favorite, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE target_id = VALUES(target_id), is_deleted = VALUES(is_deleted),
               is_favorite = VALUES(is_favorite), updated_at = NOW()',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $scopeType,
             $targetId, $messageId, $deleted ? 1 : 0, $favorite ? 1 : 0]
        );
        $message = $action === 'delete' ? '消息已仅从当前账号删除'
            : ($action === 'restore' ? '消息已恢复显示' : ($favorite ? '消息已收藏' : '已取消收藏'));
        return Response::success([
            'scope_type' => $scopeType, 'target_id' => $targetId, 'message_id' => $messageId,
            'is_deleted' => $deleted, 'is_favorite' => $favorite,
        ], $message);
    }

    public static function recallMessage(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages'); $message = self::ownedMessage($user, (int) $params['message_id']);
        if ((string) $message['sender_type'] !== 'user' || (int) $message['sender_id'] !== (int) $user['id']) throw new HttpException('只能撤回自己发送的消息', 403, 403);
        $policy = AppService::messageRecallPolicy((int) $user['app_id']);
        $seconds = (int) $policy['effective_seconds'];
        if ($seconds <= 0) throw new HttpException('当前应用已关闭消息撤回', 403, 403, ['message_recall_policy' => $policy]);
        if (time() - strtotime((string) $message['created_at']) > $seconds) {
            throw new HttpException('消息已超过可撤回时间', 0, 410, ['message_recall_policy' => $policy]);
        }
        $noticeText = mb_substr(trim((string) $request->input('notice_text', '')), 0, 200);
        if ($noticeText === '') $noticeText = '你撤回了一条消息';
        $editableContent = (string) ($message['content'] ?? '');
        $editableAttachments = MessageMediaService::attachments('private_message', (int) $message['id'], (int) $user['app_id']);
        try {
            $eventId = Database::transaction(static function () use ($message, $user, $request, $noticeText): int {
                MessageMediaService::recordRecall(
                    $message, 'private', (int) $message['conversation_id'], 'private_message',
                    'user', (int) $user['id'], (string) $request->input('reason', '')
                );
                Database::execute(
                    'INSERT INTO message_recalls (message_id, actor_type, actor_id, reason, notice_text, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW())',
                    [(int) $message['id'], 'user', (int) $user['id'],
                     mb_substr((string) $request->input('reason', ''), 0, 500), $noticeText]
                );
                $eventId = Database::insert(
                    'INSERT INTO messages
                     (admin_id, app_id, conversation_id, sender_type, sender_id, receiver_user_id,
                      title, content_type, content, is_read, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, NOW())',
                    [
                        (int) $message['admin_id'], (int) $message['app_id'], (int) $message['conversation_id'],
                        'system', (int) $user['id'], (int) $message['receiver_user_id'], (string) $message['id'],
                        'recall', $noticeText,
                    ]
                );
                Database::execute(
                    'UPDATE conversations SET last_message_id = ?, last_message_at = NOW(), updated_at = NOW() WHERE id = ?',
                    [$eventId, (int) $message['conversation_id']]
                );
                return $eventId;
            });
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') throw new HttpException('消息已经撤回', 0, 409);
            throw $e;
        }
        LogService::userOperation($request, $user, 'message', 'recall', (int) $message['id'], ['recall_event_id' => $eventId]);
        return Response::success([
            'message_id' => (int) $message['id'], 'recall_event_id' => $eventId,
            'recalled' => true, 'message_recall_policy' => $policy,
            'notice_text' => $noticeText, 'editable_content' => $editableContent,
            'editable_attachments' => $editableAttachments,
        ], '消息已撤回');
    }

    public static function editMessage(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $data = MessageEditService::editPrivate(
            $user,
            Validator::integer($params['message_id'] ?? 0, 'message_id', 1, PHP_INT_MAX),
            (string) $request->input('content', '')
        );
        LogService::userOperation($request, $user, 'message', 'edit', (int) $data['message_id'], [
            'edit_count' => (int) $data['edit_count'],
        ]);
        return Response::success($data, '消息已重新编辑');
    }

    public static function messageEditHistory(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        return Response::success(MessageEditService::privateHistory(
            $user,
            Validator::integer($params['message_id'] ?? 0, 'message_id', 1, PHP_INT_MAX)
        ));
    }

    public static function favoriteMessages(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $items = Database::all(
            "SELECT m.id, m.conversation_id, m.sender_type, m.sender_id, m.receiver_user_id, m.content_type,
                     CASE WHEN r.id IS NULL THEN m.content ELSE '[消息已撤回]' END AS content,
                     m.created_at, s.updated_at AS favorited_at, (r.id IS NOT NULL) AS recalled,
                     'private' AS scope_type, m.conversation_id AS target_id,
                     u.account AS sender_account, p.nickname AS sender_nickname,
                     COALESCE(viewer_friend.remark, '') AS sender_remark,
                     COALESCE(NULLIF(viewer_friend.remark, ''), NULLIF(p.nickname, ''), u.account, '用户') AS sender_name,
                     COALESCE(p.avatar, '') AS sender_avatar
             FROM message_user_states s INNER JOIN messages m ON m.id = s.message_id
             LEFT JOIN message_recalls r ON r.message_id = m.id
             LEFT JOIN users u ON u.id = CASE WHEN m.sender_type = 'user' OR m.content_type = 'recall' THEN m.sender_id ELSE NULL END
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN friends viewer_friend ON viewer_friend.app_id = m.app_id AND viewer_friend.user_id = s.user_id
               AND viewer_friend.friend_user_id = u.id AND viewer_friend.status = 1
             WHERE s.user_id = ? AND s.is_favorite = 1 AND s.is_deleted = 0 AND m.app_id = ? ORDER BY s.updated_at DESC",
            [(int) $user['id'], (int) $user['app_id']]
        );
        $items = MessagePresentationService::hydrate($items, 'private', (int) $user['id']);
        $otherStates = Database::all(
            'SELECT scope_type, target_id, message_id, updated_at AS favorited_at
             FROM communication_message_states
             WHERE user_id = ? AND app_id = ? AND is_favorite = 1 AND is_deleted = 0 ORDER BY updated_at DESC',
            [(int) $user['id'], (int) $user['app_id']]
        );
        foreach ($otherStates as $state) {
            $scope = (string) $state['scope_type'];
            if ($scope === 'group') {
                $row = Database::one(
                    "SELECT message.id, message.room_id AS target_id, message.sender_type,
                            COALESCE(message.user_id, message.sender_admin_id, 0) AS sender_id,
                            message.content_type, message.content, message.created_at, 'group' AS scope_type,
                            sender.account AS sender_account, profile.nickname AS sender_nickname,
                            COALESCE(viewer_friend.remark, '') AS sender_remark,
                            COALESCE(NULLIF(viewer_friend.remark, ''), NULLIF(profile.nickname, ''), sender.account, '群成员') AS sender_name
                     FROM chat_room_messages message
                     LEFT JOIN users sender ON sender.id = message.user_id
                     LEFT JOIN user_profiles profile ON profile.user_id = sender.id
                     LEFT JOIN friends viewer_friend ON viewer_friend.app_id = message.app_id AND viewer_friend.user_id = ?
                       AND viewer_friend.friend_user_id = sender.id AND viewer_friend.status = 1
                     WHERE message.id = ? AND message.room_id = ?",
                    [(int) $user['id'], (int) $state['message_id'], (int) $state['target_id']]
                );
            } else {
                $row = Database::one(
                    "SELECT message.id, message.session_id AS target_id, message.sender_type, message.sender_id,
                            'text' AS content_type, message.content, message.created_at, 'service' AS scope_type,
                            CASE WHEN message.sender_type = 'user' THEN COALESCE(NULLIF(profile.nickname, ''), sender_user.account, '用户')
                                 WHEN message.sender_type = 'admin' THEN COALESCE(NULLIF(sender_admin.nickname, ''), sender_admin.account, '客服')
                                 ELSE '在线客服' END AS sender_name
                     FROM service_messages message
                     LEFT JOIN users sender_user ON message.sender_type = 'user' AND sender_user.id = message.sender_id
                     LEFT JOIN user_profiles profile ON profile.user_id = sender_user.id
                     LEFT JOIN admins sender_admin ON message.sender_type = 'admin' AND sender_admin.id = message.sender_id
                     WHERE message.id = ? AND message.session_id = ?",
                    [(int) $state['message_id'], (int) $state['target_id']]
                );
            }
            if ($row !== null) {
                $row['favorited_at'] = $state['favorited_at'];
                $row['recalled'] = (string) ($row['content_type'] ?? '') === 'recall';
                $row = MessagePresentationService::hydrate([$row], $scope, (int) $user['id'])[0];
                $items[] = $row;
            }
        }
        usort($items, static fn(array $left, array $right): int => strcmp(
            (string) ($right['favorited_at'] ?? ''), (string) ($left['favorited_at'] ?? '')
        ));
        return Response::success(['items' => $items]);
    }

    public static function relationshipNotices(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $category = strtolower(trim((string) $request->input('category', 'friend_incoming')));
        $allowed = [
            'friend_incoming', 'friend_outgoing', 'friend_filtered',
            'group_join', 'group_invitation', 'group_filtered',
        ];
        if (!in_array($category, $allowed, true)) {
            throw new HttpException('关系通知分类无效', 0, 422);
        }
        $appId = (int) $user['app_id'];
        $userId = (int) $user['id'];
        $limit = min(200, max(1, $request->limit()));
        $items = [];

        if (str_starts_with($category, 'friend_')) {
            $where = $category === 'friend_outgoing'
                ? 'fr.from_user_id = ?'
                : 'fr.to_user_id = ?';
            if ($category === 'friend_filtered') $where .= " AND fr.status = 'ignored'";
            elseif ($category === 'friend_incoming') $where .= " AND fr.status <> 'ignored'";
            $peer = $category === 'friend_outgoing' ? 'fr.to_user_id' : 'fr.from_user_id';
            $rows = Database::all(
                "SELECT 'friend_request' AS notice_type, fr.*, u.id AS subject_user_id,
                        u.uid, COALESCE(u.account, '') AS account,
                        COALESCE(NULLIF(p.nickname, ''), NULLIF(u.account, ''), CONCAT('用户 ', u.uid)) AS display_name,
                        COALESCE(p.avatar, '') AS avatar
                 FROM friend_requests fr
                 INNER JOIN users u ON u.id = {$peer}
                 LEFT JOIN user_profiles p ON p.user_id = u.id
                 WHERE fr.app_id = ? AND {$where}
                 ORDER BY fr.id DESC LIMIT {$limit}",
                [$appId, $userId]
            );
            foreach ($rows as $row) {
                $outgoing = $category === 'friend_outgoing';
                $row['title'] = $outgoing
                    ? '已申请添加 ' . (string) $row['display_name']
                    : (string) $row['display_name'] . ' 请求添加你为好友';
                $row['subtitle'] = '账号：' . ((string) $row['account'] !== '' ? (string) $row['account'] : '未设置')
                    . ' · UID：' . (string) $row['uid'];
                $row = self::relationshipNoticeState($row, !$outgoing);
                if ($outgoing && (string) $row['status'] === 'ignored') {
                    $row['status'] = 'pending';
                    $row['status_text'] = $row['is_expired'] ? '已过期' : '等待对方处理';
                    $row['decision_reason'] = '';
                    $row['ignore_reason'] = '';
                    $row['ignored_at'] = null;
                }
                $row['direction'] = $outgoing ? 'outgoing' : 'incoming';
                $items[] = $row;
            }
        } elseif ($category === 'group_invitation') {
            $rows = Database::all(
                "SELECT 'group_invitation' AS notice_type, i.*, r.name AS room_name, r.icon AS room_icon,
                        u.id AS subject_user_id, u.uid, COALESCE(u.account, '') AS account,
                        COALESCE(NULLIF(p.nickname, ''), NULLIF(u.account, ''), CONCAT('用户 ', u.uid)) AS display_name,
                        COALESCE(p.avatar, '') AS avatar
                 FROM chat_room_invitations i
                 INNER JOIN chat_rooms r ON r.id = i.room_id
                 INNER JOIN users u ON u.id = i.inviter_user_id
                 LEFT JOIN user_profiles p ON p.user_id = u.id
                 WHERE i.app_id = ? AND i.invitee_user_id = ? AND i.status <> 'ignored'
                 ORDER BY i.id DESC LIMIT {$limit}",
                [$appId, $userId]
            );
            foreach ($rows as $row) $items[] = self::groupInvitationNotice($row);
        } elseif ($category === 'group_join') {
            $rows = self::managedJoinRequestRows($user, false, $limit);
            foreach ($rows as $row) $items[] = self::groupJoinNotice($row);
        } else {
            $invites = Database::all(
                "SELECT 'group_invitation' AS notice_type, i.*, r.name AS room_name, r.icon AS room_icon,
                        u.id AS subject_user_id, u.uid, COALESCE(u.account, '') AS account,
                        COALESCE(NULLIF(p.nickname, ''), NULLIF(u.account, ''), CONCAT('用户 ', u.uid)) AS display_name,
                        COALESCE(p.avatar, '') AS avatar
                 FROM chat_room_invitations i
                 INNER JOIN chat_rooms r ON r.id = i.room_id
                 INNER JOIN users u ON u.id = i.inviter_user_id
                 LEFT JOIN user_profiles p ON p.user_id = u.id
                 WHERE i.app_id = ? AND i.invitee_user_id = ? AND i.status = 'ignored'
                 ORDER BY i.id DESC LIMIT {$limit}",
                [$appId, $userId]
            );
            foreach ($invites as $row) $items[] = self::groupInvitationNotice($row);
            foreach (self::managedJoinRequestRows($user, true, $limit) as $row) {
                $items[] = self::groupJoinNotice($row);
            }
            usort($items, static fn(array $left, array $right): int => strcmp(
                (string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? '')
            ));
            $items = array_slice($items, 0, $limit);
        }

        return Response::success([
            'category' => $category,
            'items' => array_values($items),
            'summary' => self::relationshipNoticeSummary($user),
            'relationship_policy' => AppService::relationshipRequestPolicy($appId),
        ]);
    }

    public static function friendRequests(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $items = Database::all(
            "SELECT fr.*,
                    CASE WHEN fr.from_user_id = ? THEN 'outgoing' ELSE 'incoming' END AS direction,
                    u.uid, COALESCE(u.account, '') AS account,
                    COALESCE(NULLIF(p.nickname, ''), NULLIF(u.account, ''), CONCAT('用户 ', u.uid)) AS nickname,
                    COALESCE(p.avatar, '') AS avatar
             FROM friend_requests fr
             INNER JOIN users u ON u.id = CASE WHEN fr.from_user_id = ? THEN fr.to_user_id ELSE fr.from_user_id END
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE fr.app_id = ? AND (fr.from_user_id = ? OR fr.to_user_id = ?)
             ORDER BY fr.id DESC LIMIT 500",
            [(int) $user['id'], (int) $user['id'], (int) $user['app_id'], (int) $user['id'], (int) $user['id']]
        );
        return Response::success(['items' => $items]);
    }

    public static function sendFriendRequest(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        AuthService::ensureNotBanned($user, ['all', 'message']);
        $toUserId = IdentityService::resolveUserReference((int) $user['app_id'], $request->input('to_uid', $request->input('to_user_id')));
        return self::createFriendRequest($request, $user, $toUserId);
    }

    public static function friendQrCode(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        return Response::success([
            'uid' => (string) $user['uid'],
            'qr_payload' => FriendQrService::encode($user),
            'display_text' => '扫描二维码添加好友',
        ]);
    }

    public static function scanFriendQr(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        AuthService::ensureNotBanned($user, ['all', 'message']);
        $uid = FriendQrService::decode((string) $request->input('qr_payload', ''), (int) $user['app_id']);
        $toUserId = IdentityService::resolveUserReference((int) $user['app_id'], $uid);
        return self::createFriendRequest($request, $user, $toUserId);
    }

    private static function createFriendRequest(Request $request, array $user, int $toUserId): \Yiyunying\Core\ApiResponse
    {
        if ($toUserId === (int) $user['id']) {
            throw new HttpException('不能添加自己为好友', 0, 422);
        }
        if (Database::one('SELECT id FROM friends WHERE app_id = ? AND user_id = ? AND friend_user_id = ? AND status = 1', [
            (int) $user['app_id'], (int) $user['id'], $toUserId,
        ])) {
            throw new HttpException('对方已经是好友', 0, 409);
        }
        $target = Database::one('SELECT id, account, uid FROM users WHERE id = ? AND app_id = ? AND status = 1 AND deleted_at IS NULL', [
            $toUserId, (int) $user['app_id'],
        ]);
        if ($target === null) {
            throw new HttpException('目标用户不存在', 404, 404);
        }
        if (!self::allowsFriendRequests($target, (int) $user['app_id'])) {
            throw new HttpException('对方已关闭好友申请', 0, 403, ['friend_request_enabled' => false]);
        }
        if (Database::one(
            'SELECT id FROM user_blacklist WHERE app_id = ? AND ((user_id = ? AND blocked_user_id = ?) OR (user_id = ? AND blocked_user_id = ?)) LIMIT 1',
            [(int) $user['app_id'], (int) $user['id'], $toUserId, $toUserId, (int) $user['id']]
        )) {
            throw new HttpException('当前关系状态不允许发送好友申请', 0, 403);
        }
        $existing = Database::one(
            "SELECT id FROM friend_requests
             WHERE app_id = ? AND from_user_id = ? AND to_user_id = ?
               AND status IN ('pending','ignored') AND expired_at > NOW()",
            [(int) $user['app_id'], (int) $user['id'], $toUserId]
        );
        if ($existing !== null) {
            throw new HttpException('好友申请已经发送', 0, 409);
        }
        $message = trim((string) $request->input('message', ''));
        if ($message === '') {
            $message = '我是【' . (string) ($user['account'] ?? $user['uid'] ?? '新朋友') . '】';
        }
        $remark = mb_substr(trim((string) $request->input('requester_remark', '')), 0, 100);
        $groupId = (int) $request->input('requester_group_id', 0);
        if ($groupId > 0 && Database::one(
            'SELECT id FROM friend_groups WHERE id = ? AND app_id = ? AND user_id = ?',
            [$groupId, (int) $user['app_id'], (int) $user['id']]
        ) === null) {
            throw new HttpException('所选好友分组不存在', 0, 422);
        }
        $hideMyDynamic = array_key_exists('hide_my_dynamic', $request->all())
            ? Validator::boolean($request->input('hide_my_dynamic'), 'hide_my_dynamic') : false;
        $hideTheirDynamic = array_key_exists('hide_their_dynamic', $request->all())
            ? Validator::boolean($request->input('hide_their_dynamic'), 'hide_their_dynamic') : false;
        $validDays = (int) AppService::relationshipRequestPolicy((int) $user['app_id'])['effective_days'];
        $expiredAt = date('Y-m-d H:i:s', time() + ($validDays * 86400));
        $id = Database::insert(
            'INSERT INTO friend_requests
             (admin_id, app_id, from_user_id, to_user_id, message, requester_remark, requester_group_id,
              hide_my_dynamic, hide_their_dynamic, status, decision_reason, ignore_reason, expired_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $toUserId,
                mb_substr($message, 0, 500), $remark, $groupId > 0 ? $groupId : null,
                $hideMyDynamic ? 1 : 0, $hideTheirDynamic ? 1 : 0, 'pending', '', '', $expiredAt,
            ]
        );
        $receiver = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], $toUserId);
        if ($receiver !== null) NotificationService::send(
            $receiver, 'friend_request', '收到好友申请',
            mb_substr($message, 0, 120),
            ['request_id' => $id, 'user_id' => (int) $user['id']]
        );
        return Response::success([
            'request_id' => $id, 'to_user_id' => $toUserId, 'to_uid' => (string) $target['uid'],
            'message' => $message, 'requester_remark' => $remark,
            'requester_group_id' => $groupId > 0 ? $groupId : null,
            'hide_my_dynamic' => $hideMyDynamic, 'hide_their_dynamic' => $hideTheirDynamic,
            'expired_at' => $expiredAt, 'valid_days' => $validDays,
        ], '好友申请已发送', 201);
    }

    public static function acceptFriendRequest(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::handleFriendRequest($request, (int) $params['request_id'], 'accepted');
    }

    public static function rejectFriendRequest(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::handleFriendRequest($request, (int) $params['request_id'], 'rejected');
    }

    public static function ignoreFriendRequest(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::handleFriendRequest($request, (int) $params['request_id'], 'ignored');
    }

    public static function friends(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $where = ['f.app_id = ?', 'f.user_id = ?', 'f.status = 1', 'u.status = 1', 'u.deleted_at IS NULL'];
        $query = [(int) $user['app_id'], (int) $user['id']];
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(u.uid LIKE ? OR u.account LIKE ? OR p.nickname LIKE ? OR f.remark LIKE ?)';
            foreach (range(1, 4) as $_) $query[] = '%' . $keyword . '%';
        }
        $groupId = (int) $request->input('group_id', -1);
        if ($groupId > 0) { $where[] = 'fgm.group_id = ?'; $query[] = $groupId; }
        elseif ($groupId === 0) $where[] = 'fgm.group_id IS NULL';
        $items = Database::all(
            'SELECT f.friend_user_id AS user_id, u.uid, u.uid AS public_no,
                    f.remark, f.special_care, f.relationship_label, f.clue_note,
                    f.only_chat, f.hide_my_notes, f.hide_their_notes, f.created_at,
                    EXISTS(SELECT 1 FROM user_blacklist bl
                           WHERE bl.app_id = f.app_id AND bl.user_id = f.user_id
                             AND bl.blocked_user_id = f.friend_user_id) AS is_blacklisted,
                    u.account, fgm.group_id, fg.name AS group_name,
                    p.nickname, p.avatar, p.signature, p.title
             FROM friends f INNER JOIN users u ON u.id = f.friend_user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN friend_group_members fgm ON fgm.app_id = f.app_id AND fgm.user_id = f.user_id AND fgm.friend_user_id = f.friend_user_id
             LEFT JOIN friend_groups fg ON fg.id = fgm.group_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY fg.sort_order DESC, fg.id, p.nickname, u.id',
            $query
        );
        foreach ($items as &$item) {
            $item['clue_note'] = self::generatedFriendClue($item);
        }
        unset($item);
        return Response::success(['items' => $items]);
    }

    public static function friendGroups(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $items = Database::all(
            'SELECT g.*, COUNT(m.id) AS friend_count FROM friend_groups g
             LEFT JOIN friend_group_members m ON m.group_id = g.id
             WHERE g.app_id = ? AND g.user_id = ? GROUP BY g.id ORDER BY g.sort_order DESC, g.id',
            [(int) $user['app_id'], (int) $user['id']]
        );
        $ungrouped = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM friends f LEFT JOIN friend_group_members m
             ON m.app_id = f.app_id AND m.user_id = f.user_id AND m.friend_user_id = f.friend_user_id
             WHERE f.app_id = ? AND f.user_id = ? AND f.status = 1 AND m.id IS NULL',
            [(int) $user['app_id'], (int) $user['id']]
        )['total'] ?? 0);
        return Response::success(['items' => $items, 'ungrouped_count' => $ungrouped]);
    }

    public static function createFriendGroup(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $id = Database::insert(
            'INSERT INTO friend_groups (admin_id, app_id, user_id, name, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], Validator::string($request->input('name', ''), 'name', 1, 60), (int) $request->input('sort_order', 0)]
        );
        return Response::success(['group_id' => $id], '好友分组已创建', 201);
    }

    public static function updateFriendGroup(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $group = self::ownedFriendGroup($user, (int) $params['group_id']);
        Database::execute('UPDATE friend_groups SET name = ?, sort_order = ?, updated_at = NOW() WHERE id = ?', [
            Validator::string($request->input('name', $group['name']), 'name', 1, 60),
            (int) $request->input('sort_order', $group['sort_order']), (int) $group['id'],
        ]);
        return Response::success([], '好友分组已更新');
    }

    public static function deleteFriendGroup(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $group = self::ownedFriendGroup($user, (int) $params['group_id']);
        Database::execute('DELETE FROM friend_groups WHERE id = ?', [(int) $group['id']]);
        return Response::success([], '好友分组已删除，好友已移至未分组');
    }

    public static function updateFriend(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $friendId = IdentityService::resolveUserReference((int) $user['app_id'], $params['friend_user_id']);
        $friend = Database::one('SELECT * FROM friends WHERE app_id = ? AND user_id = ? AND friend_user_id = ? AND status = 1', [(int) $user['app_id'], (int) $user['id'], $friendId]);
        if ($friend === null) throw new HttpException('好友不存在', 404, 404);
        $remark = mb_substr(trim((string) $request->input('remark', $friend['remark'])), 0, 100);
        $relationshipLabel = mb_substr(trim((string) $request->input('relationship_label', $friend['relationship_label'] ?? '')), 0, 60);
        $specialCare = filter_var($request->input('special_care', (bool) ($friend['special_care'] ?? false)), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $onlyChat = filter_var($request->input('only_chat', (bool) ($friend['only_chat'] ?? false)), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $hideMyNotes = filter_var($request->input('hide_my_notes', (bool) ($friend['hide_my_notes'] ?? false)), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $hideTheirNotes = filter_var($request->input('hide_their_notes', (bool) ($friend['hide_their_notes'] ?? false)), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        Database::transaction(static function () use (
            $request, $user, $friendId, $friend, $remark, $relationshipLabel,
            $specialCare, $onlyChat, $hideMyNotes, $hideTheirNotes
        ): void {
            Database::execute(
                'UPDATE friends SET remark = ?, special_care = ?, relationship_label = ?, clue_note = \'\',
                    only_chat = ?, hide_my_notes = ?, hide_their_notes = ?, updated_at = NOW() WHERE id = ?',
                [$remark, $specialCare, $relationshipLabel, $onlyChat, $hideMyNotes, $hideTheirNotes, (int) $friend['id']]
            );
            if (array_key_exists('group_id', $request->all())) {
                $groupId = (int) $request->input('group_id', 0);
                Database::execute('DELETE FROM friend_group_members WHERE app_id = ? AND user_id = ? AND friend_user_id = ?', [(int) $user['app_id'], (int) $user['id'], $friendId]);
                if ($groupId > 0) {
                    self::ownedFriendGroup($user, $groupId);
                    Database::execute(
                        'INSERT INTO friend_group_members (admin_id, app_id, user_id, friend_user_id, group_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
                        [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $friendId, $groupId]
                    );
                }
            }
        });
        $clueSource = Database::one(
            'SELECT f.created_at, f.special_care, f.only_chat, fg.name AS group_name
             FROM friends f
             LEFT JOIN friend_group_members fgm ON fgm.app_id = f.app_id AND fgm.user_id = f.user_id
                AND fgm.friend_user_id = f.friend_user_id
             LEFT JOIN friend_groups fg ON fg.id = fgm.group_id
             WHERE f.id = ?',
            [(int) $friend['id']]
        ) ?? [];
        return Response::success([
            'friend_user_id' => $friendId,
            'remark' => $remark,
            'special_care' => (bool) $specialCare,
            'relationship_label' => $relationshipLabel,
            'clue_note' => self::generatedFriendClue($clueSource),
            'only_chat' => (bool) $onlyChat,
            'hide_my_notes' => (bool) $hideMyNotes,
            'hide_their_notes' => (bool) $hideTheirNotes,
        ], '好友资料与权限已更新');
    }

    public static function deleteFriend(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $friendId = (int) $params['friend_user_id'];
        Database::transaction(static function () use ($user, $friendId): void {
            Database::execute(
                'DELETE FROM friend_group_members WHERE app_id = ? AND ((user_id = ? AND friend_user_id = ?) OR (user_id = ? AND friend_user_id = ?))',
                [(int) $user['app_id'], (int) $user['id'], $friendId, $friendId, (int) $user['id']]
            );
            Database::execute(
                'DELETE FROM friends WHERE app_id = ? AND ((user_id = ? AND friend_user_id = ?) OR (user_id = ? AND friend_user_id = ?))',
                [(int) $user['app_id'], (int) $user['id'], $friendId, $friendId, (int) $user['id']]
            );
        });
        return Response::success(['friend_user_id' => $friendId], '好友已删除');
    }

    private static function ownedFriendGroup(array $user, int $groupId): array
    {
        $group = Database::one('SELECT * FROM friend_groups WHERE id = ? AND app_id = ? AND user_id = ?', [$groupId, (int) $user['app_id'], (int) $user['id']]);
        if ($group === null) throw new HttpException('好友分组不存在', 404, 404);
        return $group;
    }

    public static function chatRooms(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'chat_rooms');
        return Response::success(['items' => Database::all(
            'SELECT r.*,
                    EXISTS(SELECT 1 FROM chat_room_members m WHERE m.room_id = r.id AND m.user_id = ?) AS joined,
                    (SELECT COUNT(*) FROM chat_room_members m WHERE m.room_id = r.id) AS member_count
             FROM chat_rooms r WHERE r.app_id = ? AND r.status = 1
               AND (r.is_public = 1 OR EXISTS(
                   SELECT 1 FROM chat_room_members m2 WHERE m2.room_id = r.id AND m2.user_id = ?
               )) ORDER BY r.id DESC',
            [(int) $user['id'], (int) $user['app_id'], (int) $user['id']]
        )]);
    }

    public static function chatRoomMessages(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'chat_rooms');
        $room = self::room($user, (int) $params['room_id']);
        $limit = $request->limit();
        $where = ['m.room_id = ?', 'm.status = 1'];
        $query = [(int) $room['id']];
        if ((int) $request->input('since_id', 0) > 0) {
            $where[] = 'm.id > ?';
            $query[] = (int) $request->input('since_id');
        }
        $items = Database::all(
            'SELECT m.id, m.user_id, m.sender_type, m.content_type, m.content, m.created_at,
                    u.account, p.nickname, p.avatar, cm.role,
                    vc.id AS call_id, vc.call_type, vc.status AS call_status,
                    vc.duration_seconds AS call_duration_seconds,
                    vc.caller_user_id AS call_caller_user_id,
                    vc.callee_user_id AS call_callee_user_id,
                    vc.context_type AS call_context_type, vc.context_id AS call_context_id,
                    COALESCE(NULLIF(call_caller_profile.nickname, \'\'), call_caller.account, \'\') AS call_caller_name,
                    COALESCE(NULLIF(call_callee_profile.nickname, \'\'), call_callee.account, \'\') AS call_callee_name,
                    COALESCE(call_caller_profile.avatar, \'\') AS call_caller_avatar,
                    COALESCE(call_callee_profile.avatar, \'\') AS call_callee_avatar
             FROM chat_room_messages m INNER JOIN users u ON u.id = m.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN chat_room_members cm ON cm.room_id = m.room_id AND cm.user_id = m.user_id
             LEFT JOIN voice_calls vc ON vc.room_message_id = m.id
             LEFT JOIN users call_caller ON call_caller.id = vc.caller_user_id
             LEFT JOIN user_profiles call_caller_profile ON call_caller_profile.user_id = call_caller.id
             LEFT JOIN users call_callee ON call_callee.id = vc.callee_user_id
             LEFT JOIN user_profiles call_callee_profile ON call_callee_profile.user_id = call_callee.id
             WHERE ' . implode(' AND ', $where) . " ORDER BY m.id DESC LIMIT {$limit}",
            $query
        );
        $items = MessageMediaService::hydrate($items, 'group_message', (int) $user['app_id']);
        $items = MessageForwardService::hydrate($items, 'group_message', (int) $user['app_id']);
        $items = MessagePresentationService::hydrate($items, 'group');
        return Response::success(['room' => $room, 'items' => array_reverse($items)]);
    }

    public static function sendChatRoomMessage(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'chat_rooms');
        AuthService::ensureNotBanned($user, ['all', 'message', 'chat']);
        $room = self::room($user, (int) $params['room_id']);
        $payload = MessageMediaService::userPayload($user, $request->all());
        MessageMediaService::assertChatFeatures((int) $user['app_id'], $payload);
        $messageId = Database::transaction(static function () use ($user, $room, $payload): int {
            Database::execute(
                'INSERT INTO chat_room_members
                 (admin_id, app_id, room_id, user_id, role, joined_at)
                 VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)',
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $room['id'], (int) $user['id'], 'member']
            );
            $member = Database::one(
                'SELECT * FROM chat_room_members WHERE room_id = ? AND user_id = ? FOR UPDATE',
                [(int) $room['id'], (int) $user['id']]
            );
            if ($member !== null && $member['mute_until'] !== null && strtotime((string) $member['mute_until']) > time()) {
                throw new HttpException('你已被禁言', 403, 403, ['mute_until' => $member['mute_until']]);
            }
            $messageId = Database::insert(
                'INSERT INTO chat_room_messages
                 (admin_id, app_id, room_id, user_id, content_type, content, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $room['id'], (int) $user['id'],
                    (string) $payload['content_type'], (string) $payload['content'],
                ]
            );
            MessageMediaService::save('group_message', $messageId, $payload);
            return $messageId;
        });
        return Response::success(['message_id' => $messageId], '消息发送成功', 201);
    }

    public static function serviceSession(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'service');
        $session = Database::one(
            "SELECT * FROM service_sessions WHERE app_id = ? AND user_id = ?
             ORDER BY (status = 'open') DESC, id DESC LIMIT 1",
            [(int) $user['app_id'], (int) $user['id']]
        );
        return Response::success(['session' => $session]);
    }

    public static function serviceMessages(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'service');
        $session = Database::one(
            'SELECT * FROM service_sessions WHERE app_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1',
            [(int) $user['app_id'], (int) $user['id']]
        );
        if ($session === null) {
            return Response::success(['session' => null, 'items' => [], 'message_recall_allowed' => false]);
        }
        $limit = $request->limit();
        $where = ['sm.session_id = ?', 'COALESCE(state.is_deleted, 0) = 0'];
        $query = [(int) $session['id']];
        if ((int) $request->input('since_id', 0) > 0) {
            $where[] = 'sm.id > ?';
            $query[] = (int) $request->input('since_id');
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = 'sm.content LIKE ?';
            $query[] = '%' . $keyword . '%';
        }
        $items = Database::all(
            "SELECT sm.*, COALESCE(state.is_favorite, 0) AS is_favorite,
                    CASE WHEN sm.sender_type = 'user' THEN COALESCE(NULLIF(profile.nickname, ''), sender_user.account, '用户')
                         WHEN sm.sender_type = 'admin' THEN COALESCE(NULLIF(sender_admin.nickname, ''), sender_admin.account, '客服')
                         ELSE '在线客服' END AS sender_name,
                    CASE WHEN sm.sender_type = 'user' THEN COALESCE(profile.avatar, '')
                         WHEN sm.sender_type = 'admin' THEN COALESCE(sender_admin.avatar, '') ELSE '' END AS sender_avatar,
                    sender_user.account AS sender_account
             FROM service_messages sm
             LEFT JOIN communication_message_states state ON state.scope_type = 'service'
               AND state.message_id = sm.id AND state.user_id = ?
             LEFT JOIN users sender_user ON sm.sender_type = 'user' AND sender_user.id = sm.sender_id
             LEFT JOIN user_profiles profile ON profile.user_id = sender_user.id
             LEFT JOIN admins sender_admin ON sm.sender_type = 'admin' AND sender_admin.id = sm.sender_id
             WHERE " . implode(' AND ', $where) . " ORDER BY sm.id DESC LIMIT {$limit}",
            array_merge([(int) $user['id']], $query)
        );
        $items = MessageMediaService::hydrate($items, 'service_message', (int) $user['app_id']);
        $items = MessageForwardService::hydrate($items, 'service_message', (int) $user['app_id']);
        $items = MessagePresentationService::hydrate($items, 'service');
        Database::execute(
            "UPDATE service_messages SET is_read = 1 WHERE session_id = ? AND sender_type = 'admin'",
            [(int) $session['id']]
        );
        foreach ($items as &$item) $item['can_recall'] = false;
        unset($item);
        return Response::success([
            'session' => $session, 'items' => array_reverse($items), 'message_recall_allowed' => false,
        ]);
    }

    public static function sendServiceMessage(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'service');
        AuthService::ensureNotBanned($user, ['all', 'message', 'service']);
        $payload = MessageMediaService::userPayload($user, $request->all());
        MessageMediaService::assertChatFeatures((int) $user['app_id'], $payload);
        $subject = mb_substr(trim((string) $request->input('subject', '在线客服')), 0, 200);
        $replyRequestId = max(0, (int) $request->input('reply_to_message_id', 0));
        $result = Database::transaction(static function () use ($user, $payload, $subject, $replyRequestId): array {
            $session = Database::one(
                "SELECT * FROM service_sessions WHERE app_id = ? AND user_id = ? AND status = 'open'
                 ORDER BY id DESC LIMIT 1 FOR UPDATE",
                [(int) $user['app_id'], (int) $user['id']]
            );
            if ($session === null) {
                $sessionId = Database::insert(
                    'INSERT INTO service_sessions
                     (admin_id, app_id, user_id, subject, status, last_message_at, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())',
                    [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $subject, 'open']
                );
            } else {
                $sessionId = (int) $session['id'];
            }
            $replyId = null;
            if ($replyRequestId > 0) {
                $reply = Database::one('SELECT id FROM service_messages WHERE id = ? AND session_id = ? LIMIT 1', [$replyRequestId, $sessionId]);
                if ($reply === null) throw new HttpException('被引用的客服消息不存在', 0, 404);
                $replyId = (int) $reply['id'];
            }
            $messageId = Database::insert(
                'INSERT INTO service_messages
                 (admin_id, app_id, session_id, sender_type, sender_id, reply_to_message_id, content, is_read, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], $sessionId, 'user', (int) $user['id'], $replyId, (string) $payload['content']]
            );
            MessageMediaService::save('service_message', $messageId, $payload);
            Database::execute(
                'UPDATE service_sessions SET last_message_at = NOW(), updated_at = NOW() WHERE id = ?',
                [$sessionId]
            );
            return ['session_id' => $sessionId, 'message_id' => $messageId];
        });
        return Response::success($result, '客服消息发送成功', 201);
    }

    public static function systemMessages(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $query = [(int) $user['app_id'], (int) $user['id'], 'system'];
        $total = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM messages WHERE app_id = ? AND receiver_user_id = ? AND sender_type = ?',
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT id, title, content, is_read, read_at, created_at FROM messages
             WHERE app_id = ? AND receiver_user_id = ? AND sender_type = ? AND status = 1
             ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        Database::execute(
            "UPDATE messages SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE app_id = ? AND receiver_user_id = ? AND sender_type = 'system' AND is_read = 0",
            [(int) $user['app_id'], (int) $user['id']]
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    private static function forwardSourceRows(array $user, string $type, int $sourceId, array $ids): array
    {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $order = implode(',', $ids);
        if ($type === 'private') {
            self::conversation($user, $sourceId);
            $rows = Database::all(
                "SELECT m.id, m.sender_type, m.sender_id, m.content_type, m.content, m.tags_json, m.created_at,
                         u.account AS sender_account, p.nickname AS sender_nickname,
                         COALESCE(viewer_friend.remark, '') AS sender_remark,
                         COALESCE(NULLIF(viewer_friend.remark, ''), NULLIF(p.nickname, ''), u.account, '用户') AS sender_name,
                         COALESCE(p.avatar, '') AS sender_avatar
                 FROM messages m LEFT JOIN message_recalls recall ON recall.message_id = m.id
                 LEFT JOIN users u ON u.id = CASE WHEN m.sender_type = 'user' THEN m.sender_id ELSE NULL END
                 LEFT JOIN user_profiles p ON p.user_id = u.id
                 LEFT JOIN friends viewer_friend ON viewer_friend.app_id = m.app_id AND viewer_friend.user_id = ?
                   AND viewer_friend.friend_user_id = u.id AND viewer_friend.status = 1
                 WHERE m.conversation_id = ? AND m.status = 1 AND recall.id IS NULL AND m.id IN ({$placeholders})
                 ORDER BY FIELD(m.id, {$order})",
                array_merge([(int) $user['id'], $sourceId], $ids)
            );
            $rows = ContentTagService::hydrate($rows);
            $rows = MessageMediaService::hydrate($rows, 'private_message', (int) $user['app_id']);
            $rows = MessageForwardService::hydrate($rows, 'private_message', (int) $user['app_id']);
            return MessagePresentationService::hydrate($rows, 'private', (int) $user['id']);
        }
        if ($type === 'group') {
            $room = ChatRoomService::userRoom($user, $sourceId, true);
            ChatRoomService::requireMember($user, $room);
            $rows = Database::all(
                "SELECT m.id, m.sender_type, COALESCE(m.user_id, m.sender_admin_id, 0) AS sender_id,
                        m.content_type, m.content, m.tags_json, m.reply_to_message_id, m.created_at,
                         u.account AS sender_account, p.nickname AS sender_nickname,
                         COALESCE(viewer_friend.remark, '') AS sender_remark,
                         COALESCE(NULLIF(viewer_friend.remark, ''), NULLIF(p.nickname, ''), u.account,
                           CASE m.sender_type WHEN 'admin' THEN '管理员' WHEN 'platform' THEN '平台' ELSE '群成员' END) AS sender_name,
                        COALESCE(p.avatar, '') AS sender_avatar, member.role
                 FROM chat_room_messages m LEFT JOIN users u ON u.id = m.user_id
                 LEFT JOIN user_profiles p ON p.user_id = u.id
                 LEFT JOIN friends viewer_friend ON viewer_friend.app_id = m.app_id AND viewer_friend.user_id = ?
                   AND viewer_friend.friend_user_id = u.id AND viewer_friend.status = 1
                 LEFT JOIN chat_room_members member ON member.room_id = m.room_id AND member.user_id = m.user_id
                 WHERE m.room_id = ? AND m.status = 1 AND m.content_type <> 'recall' AND m.id IN ({$placeholders})
                 ORDER BY FIELD(m.id, {$order})",
                array_merge([(int) $user['id'], $sourceId], $ids)
            );
            $rows = ContentTagService::hydrate($rows);
            $rows = MessageMediaService::hydrate($rows, 'group_message', (int) $user['app_id']);
            $rows = MessageForwardService::hydrate($rows, 'group_message', (int) $user['app_id']);
            return MessagePresentationService::hydrate($rows, 'group', (int) $user['id']);
        }
        $session = Database::one(
            'SELECT id FROM service_sessions WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ?',
            [$sourceId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        if ($session === null) throw new HttpException('客服会话不存在', 404, 404);
        $rows = Database::all(
            "SELECT message.id, message.sender_type, message.sender_id, 'text' AS content_type,
                    message.content, message.created_at,
                    CASE WHEN message.sender_type = 'user' THEN COALESCE(NULLIF(profile.nickname, ''), sender_user.account, '用户')
                         WHEN message.sender_type = 'admin' THEN COALESCE(NULLIF(sender_admin.nickname, ''), sender_admin.account, '客服')
                         ELSE '在线客服' END AS sender_name,
                    CASE WHEN message.sender_type = 'user' THEN COALESCE(profile.avatar, '')
                         WHEN message.sender_type = 'admin' THEN COALESCE(sender_admin.avatar, '') ELSE '' END AS sender_avatar
             FROM service_messages message
             LEFT JOIN users sender_user ON message.sender_type = 'user' AND sender_user.id = message.sender_id
             LEFT JOIN user_profiles profile ON profile.user_id = sender_user.id
             LEFT JOIN admins sender_admin ON message.sender_type = 'admin' AND sender_admin.id = message.sender_id
             WHERE message.session_id = ? AND message.id IN ({$placeholders}) ORDER BY FIELD(message.id, {$order})",
            array_merge([$sourceId], $ids)
        );
        $rows = MessageMediaService::hydrate($rows, 'service_message', (int) $user['app_id']);
        $rows = MessageForwardService::hydrate($rows, 'service_message', (int) $user['app_id']);
        return MessagePresentationService::hydrate($rows, 'service');
    }

    private static function forwardText(array $rows, string $anonymityMode = 'none'): string
    {
        if ($anonymityMode !== 'none') {
            return '【匿名合并转发 · ' . count($rows) . " 条聊天记录】\n点击查看只读聊天快照";
        }
        $lines = ['【合并转发 · ' . count($rows) . ' 条聊天记录】'];
        foreach ($rows as $row) {
            $sender = trim((string) ($row['sender_name'] ?? ''));
            if ($sender === '') $sender = trim((string) ($row['nickname'] ?? ''));
            if ($sender === '') $sender = trim((string) ($row['account'] ?? ''));
            if ($sender === '') {
                $type = (string) ($row['sender_type'] ?? 'user');
                $sender = $type === 'admin' ? '管理员' : ($type === 'platform' ? '平台' : ($type === 'system' ? '系统' : '用户'));
            }
            $badge = trim((string) ($row['sender_badge'] ?? ''));
            if ($badge !== '') $sender .= ' [' . $badge . ']';
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') $content = '[' . (string) ($row['content_type'] ?? '附件') . ']';
            $lines[] = $sender . '：' . preg_replace('/\s+/u', ' ', mb_substr($content, 0, 500));
        }
        return mb_substr(implode("\n", $lines), 0, 10000);
    }

    private static function forwardToPrivate(array $user, int $targetUserId, string $content, string $tagsJson): array
    {
        if ($targetUserId <= 0) throw new HttpException('转发目标用户不正确', 0, 422);
        $selfChat = $targetUserId === (int) $user['id'];
        $receiver = Database::one(
            'SELECT id FROM users WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1 AND deleted_at IS NULL',
            [$targetUserId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        if ($receiver === null) throw new HttpException('转发目标用户不存在', 404, 404);
        if (!$selfChat) {
            SocialController::assertNotBlocked($user, $targetUserId);
            if (!self::isFriend($user, $targetUserId) && !self::acceptsStrangerMessages($receiver, (int) $user['app_id'])) {
                throw new HttpException('对方已关闭陌生人消息', 403, 403);
            }
        }
        [$a, $b] = [(int) $user['id'], $targetUserId];
        if ($a > $b) [$a, $b] = [$b, $a];
        Database::execute(
            'INSERT INTO conversations (admin_id, app_id, type, user_a_id, user_b_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE updated_at = NOW()',
            [(int) $user['admin_id'], (int) $user['app_id'], 'private', $a, $b]
        );
        $conversation = Database::one('SELECT id FROM conversations WHERE app_id = ? AND type = ? AND user_a_id = ? AND user_b_id = ? FOR UPDATE', [(int) $user['app_id'], 'private', $a, $b]);
        if ($conversation === null) throw new HttpException('创建转发会话失败', -1, 500);
        $messageId = Database::insert(
            'INSERT INTO messages
             (admin_id, app_id, conversation_id, sender_type, sender_id, receiver_user_id, title, content_type, content, tags_json, is_read, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $conversation['id'], 'user', (int) $user['id'], $targetUserId, '', 'forward', $content, $tagsJson, $selfChat ? 1 : 0]
        );
        Database::execute('UPDATE conversations SET last_message_id = ?, last_message_at = NOW(), updated_at = NOW() WHERE id = ?', [$messageId, (int) $conversation['id']]);
        return ['conversation_id' => (int) $conversation['id'], 'message_id' => $messageId, 'target_type' => 'private', 'is_self_chat' => $selfChat];
    }

    private static function forwardToGroup(
        array $user,
        int $roomId,
        string $content,
        string $tagsJson,
        string $expectedType
    ): array
    {
        $room = ChatRoomService::userRoom($user, $roomId, true);
        $actualType = ChatRoomService::roomKind($room['room_kind'] ?? ChatRoomService::ROOM_GROUP);
        if ($actualType !== $expectedType) {
            $expectedName = $expectedType === ChatRoomService::ROOM_CHATROOM ? '聊天室' : '群聊';
            throw new HttpException("所选目标不是{$expectedName}，请重新选择", 0, 422);
        }
        $member = ChatRoomService::requireMember($user, $room);
        $policy = ChatRoomService::policy($room);
        if ((bool) $policy['mute_all'] && !in_array((string) $member['role'], ['owner', 'admin'], true)) throw new HttpException('当前群聊已开启全员禁言', 403, 403);
        if ($member['mute_until'] !== null && strtotime((string) $member['mute_until']) > time()) throw new HttpException('你已被禁言', 403, 403);
        $messageId = Database::insert(
            'INSERT INTO chat_room_messages
             (admin_id, app_id, room_id, user_id, sender_type, sender_admin_id, content_type, content, tags_json, status, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, 1, NOW())',
            [(int) $user['admin_id'], (int) $user['app_id'], $roomId, (int) $user['id'], 'user', 'forward', $content, $tagsJson]
        );
        return ['room_id' => $roomId, 'message_id' => $messageId, 'target_type' => $actualType];
    }

    private static function forwardToForum(array $user, int $postId, string $content): array
    {
        $post = Database::one(
            "SELECT id, is_locked FROM forum_posts
             WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1 AND deleted_at IS NULL
               AND (audit_status = 'approved' OR user_id = ?)",
            [$postId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        if ($post === null) throw new HttpException('目标帖子不存在', 404, 404);
        if ((int) $post['is_locked'] === 1) throw new HttpException('目标帖子已锁定', 403, 403);
        $commentId = Database::insert(
            'INSERT INTO forum_comments (admin_id, app_id, post_id, user_id, content, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [(int) $user['admin_id'], (int) $user['app_id'], $postId, (int) $user['id'], $content]
        );
        Database::execute('UPDATE forum_posts SET comment_count = comment_count + 1 WHERE id = ?', [$postId]);
        return ['post_id' => $postId, 'comment_id' => $commentId, 'target_type' => 'forum'];
    }

    private static function forwardToForumPost(
        array $user,
        int $plateId,
        string $title,
        string $intro,
        string $content,
        $rawCategoryId,
        $rawTags
    ): array {
        $plate = Database::one(
            'SELECT id FROM forum_plates WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1',
            [$plateId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        if ($plate === null) throw new HttpException('目标论坛板块不存在或无权发布', 404, 404);
        $categoryId = ForumTaxonomyService::categoryId(
            (int) $user['admin_id'], (int) $user['app_id'], $plateId, $rawCategoryId
        );
        $tagsJson = ContentTagService::encode(ForumTaxonomyService::normalizeTags(
            (int) $user['app_id'], $plateId, $categoryId, $rawTags
        ));
        $postContent = $intro === '' ? $content : $intro . "\n\n" . $content;
        $audit = AppService::setting((int) $user['app_id'], 'forum_post_audit', false) ? 'pending' : 'approved';
        $postId = Database::insert(
            'INSERT INTO forum_posts
             (admin_id, app_id, plate_id, category_id, user_id, title, content, images_json, tags_json, audit_status, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [
                (int) $user['admin_id'], (int) $user['app_id'], $plateId, $categoryId, (int) $user['id'],
                $title, $postContent, '[]', $tagsJson, $audit,
            ]
        );
        return ['post_id' => $postId, 'target_type' => 'forum_post', 'audit_status' => $audit];
    }

    private static function forwardToService(array $user, string $content): array
    {
        $session = Database::one("SELECT id FROM service_sessions WHERE app_id = ? AND user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1 FOR UPDATE", [(int) $user['app_id'], (int) $user['id']]);
        $sessionId = $session === null
            ? Database::insert('INSERT INTO service_sessions (admin_id, app_id, user_id, subject, status, last_message_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())', [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], '聊天记录转发', 'open'])
            : (int) $session['id'];
        $messageId = Database::insert(
            'INSERT INTO service_messages (admin_id, app_id, session_id, sender_type, sender_id, content, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, NOW())',
            [(int) $user['admin_id'], (int) $user['app_id'], $sessionId, 'user', (int) $user['id'], $content]
        );
        Database::execute('UPDATE service_sessions SET last_message_at = NOW(), updated_at = NOW() WHERE id = ?', [$sessionId]);
        return ['session_id' => $sessionId, 'message_id' => $messageId, 'target_type' => 'service'];
    }

    private static function handleFriendRequest(Request $request, int $requestId, string $action): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'messages');
        if (!in_array($action, ['accepted', 'rejected', 'ignored'], true)) {
            throw new HttpException('好友申请处理动作无效', 0, 422);
        }
        $reasonMap = [
            'accepted' => '接收方同意好友申请',
            'rejected' => '接收方明确拒绝好友申请',
            'ignored' => '接收方选择忽略好友申请，未通知申请人',
        ];
        if ($action === 'ignored') {
            $customReason = mb_substr(trim((string) $request->input('reason', '')), 0, 255);
            if ($customReason !== '') $reasonMap['ignored'] = $customReason;
        }
        $friendRequest = Database::transaction(static function () use ($user, $requestId, $action, $reasonMap): array {
            $friendRequest = Database::one(
                "SELECT * FROM friend_requests WHERE id = ? AND app_id = ? AND to_user_id = ?
                 AND status IN ('pending','ignored') FOR UPDATE",
                [$requestId, (int) $user['app_id'], (int) $user['id']]
            );
            if ($friendRequest === null) throw new HttpException('好友申请不存在或已处理', 404, 404);
            if (strtotime((string) $friendRequest['expired_at']) <= time()) {
                throw new HttpException('好友申请已过期，只能查看，不能继续处理', 0, 410, [
                    'expired_at' => $friendRequest['expired_at'], 'status_text' => '已过期',
                ]);
            }
            if ($action === 'ignored') {
                Database::execute(
                    'UPDATE friend_requests SET status = ?, decision_reason = ?, ignore_reason = ?,
                       ignored_at = NOW(), handled_at = NULL WHERE id = ?',
                    [$action, $reasonMap[$action], $reasonMap[$action], (int) $friendRequest['id']]
                );
            } else {
                Database::execute(
                    'UPDATE friend_requests SET status = ?, decision_reason = ?, handled_at = NOW() WHERE id = ?',
                    [$action, $reasonMap[$action], (int) $friendRequest['id']]
                );
            }
            if ($action !== 'accepted') return $friendRequest;
            foreach ([
                [
                    (int) $friendRequest['from_user_id'], (int) $friendRequest['to_user_id'],
                    (string) ($friendRequest['requester_remark'] ?? ''),
                    (int) ($friendRequest['hide_my_dynamic'] ?? 0), (int) ($friendRequest['hide_their_dynamic'] ?? 0),
                ],
                [(int) $friendRequest['to_user_id'], (int) $friendRequest['from_user_id'], '', 0, 0],
            ] as [$ownerId, $friendId, $remark, $hideMyDynamic, $hideTheirDynamic]) {
                Database::execute(
                    'INSERT INTO friends
                     (admin_id, app_id, user_id, friend_user_id, remark, hide_my_notes, hide_their_notes, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
                     ON DUPLICATE KEY UPDATE remark = VALUES(remark), hide_my_notes = VALUES(hide_my_notes),
                       hide_their_notes = VALUES(hide_their_notes), status = 1',
                    [(int) $user['admin_id'], (int) $user['app_id'], $ownerId, $friendId,
                     $remark, $hideMyDynamic, $hideTheirDynamic]
                );
            }
            $groupId = (int) ($friendRequest['requester_group_id'] ?? 0);
            if ($groupId > 0 && Database::one(
                'SELECT id FROM friend_groups WHERE id = ? AND app_id = ? AND user_id = ?',
                [$groupId, (int) $user['app_id'], (int) $friendRequest['from_user_id']]
            ) !== null) {
                Database::execute(
                    'INSERT INTO friend_group_members (admin_id, app_id, user_id, friend_user_id, group_id, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE group_id = VALUES(group_id)',
                    [(int) $user['admin_id'], (int) $user['app_id'], (int) $friendRequest['from_user_id'],
                     (int) $friendRequest['to_user_id'], $groupId]
                );
            }
            return $friendRequest;
        });
        LogService::userOperation($request, $user, 'friend_request', $action, (int) $friendRequest['id'], [
            'decision_reason' => $reasonMap[$action],
        ]);
        if ($action !== 'ignored') {
            $sender = NotificationService::user(
                (int) $user['admin_id'], (int) $user['app_id'], (int) $friendRequest['from_user_id']
            );
            if ($sender !== null) NotificationService::send(
                $sender, $action === 'accepted' ? 'friend_request_accepted' : 'friend_request_rejected',
                $action === 'accepted' ? '好友申请已通过' : '好友申请未通过',
                $action === 'accepted' ? '对方已同意你的好友申请，现在可以开始聊天' : '对方拒绝了你的好友申请',
                ['request_id' => (int) $friendRequest['id'], 'user_id' => (int) $user['id']]
            );
        }
        $messageMap = ['accepted' => '已同意好友申请', 'rejected' => '已拒绝好友申请', 'ignored' => '已忽略好友申请'];
        return Response::success([
            'request_id' => (int) $friendRequest['id'],
            'status' => $action, 'decision_reason' => $reasonMap[$action],
            'sender_notified' => $action !== 'ignored',
        ], $messageMap[$action]);
    }

    private static function conversation(array $user, int $conversationId): array
    {
        $conversation = Database::one(
            'SELECT * FROM conversations WHERE id = ? AND admin_id = ? AND app_id = ? AND (user_a_id = ? OR user_b_id = ?)',
            [$conversationId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], (int) $user['id']]
        );
        if ($conversation === null) {
            throw new HttpException('会话不存在', 404, 404);
        }
        return $conversation;
    }

    private static function assertConversationTarget(array $user, string $targetType, int $targetId): void
    {
        if ($targetType === 'private') {
            self::conversation($user, $targetId);
            return;
        }
        if ($targetType === 'group') {
            $room = ChatRoomService::userRoom($user, $targetId, true);
            ChatRoomService::requireMember($user, $room);
            return;
        }
        if ($targetType === 'service') {
            if ($targetId === 0) return;
            if (!Database::one('SELECT id FROM service_sessions WHERE id = ? AND app_id = ? AND user_id = ?', [
                $targetId, (int) $user['app_id'], (int) $user['id'],
            ])) throw new HttpException('客服会话不存在', 404, 404);
            return;
        }
        if ($targetType === 'bot' && $targetId === 0) return;
        throw new HttpException('会话目标不存在或无权设置', 404, 404);
    }

    private static function assertCommunicationMessage(array $user, string $scopeType, int $targetId, int $messageId): void
    {
        if ($scopeType === 'group') {
            $room = ChatRoomService::userRoom($user, $targetId, true);
            ChatRoomService::requireMember($user, $room);
            if (Database::one('SELECT id FROM chat_room_messages WHERE id = ? AND room_id = ?', [$messageId, $targetId])) return;
        } elseif ($scopeType === 'service') {
            if (Database::one(
                'SELECT sm.id FROM service_messages sm INNER JOIN service_sessions session ON session.id = sm.session_id
                 WHERE sm.id = ? AND sm.session_id = ? AND session.app_id = ? AND session.user_id = ?',
                [$messageId, $targetId, (int) $user['app_id'], (int) $user['id']]
            )) return;
        } else {
            throw new HttpException('scope_type 仅支持 group 或 service；私聊请使用私聊消息状态接口', 0, 422);
        }
        throw new HttpException('消息不存在或无权操作', 404, 404);
    }

    private static function draftTarget(array $params): array
    {
        $type = strtolower(trim((string) ($params['target_type'] ?? '')));
        if (!in_array($type, ['private', 'group', 'service', 'forum', 'note'], true)) {
            throw new HttpException('草稿类型仅支持 private、group、service、forum 或 note', 0, 422);
        }
        return [$type, max(0, (int) ($params['target_id'] ?? 0))];
    }

    private static function jsonList($value, string $field, int $max): array
    {
        if (is_string($value)) $value = json_decode($value, true);
        if (!is_array($value) || count($value) > $max) {
            throw new HttpException($field . ' 必须是最多 ' . $max . ' 项的数组', 0, 422);
        }
        return array_values($value);
    }

    private static function ownedMessage(array $user, int $messageId): array
    {
        $message = Database::one(
            'SELECT m.*, c.user_a_id, c.user_b_id FROM messages m INNER JOIN conversations c ON c.id = m.conversation_id
             WHERE m.id = ? AND m.app_id = ? AND m.status = 1 AND (c.user_a_id = ? OR c.user_b_id = ?)',
            [$messageId, (int) $user['app_id'], (int) $user['id'], (int) $user['id']]
        );
        if ($message === null) throw new HttpException('消息不存在或不属于当前用户', 404, 404); return $message;
    }

    private static function canRecallPrivateMessage(array $message, array $user, int $seconds): bool
    {
        if ($seconds <= 0 || (bool) ($message['recalled'] ?? false)) return false;
        if ((string) ($message['content_type'] ?? '') === 'recall') return false;
        if ((string) ($message['sender_type'] ?? '') !== 'user'
            || (int) ($message['sender_id'] ?? 0) !== (int) $user['id']) return false;
        $createdAt = strtotime((string) ($message['created_at'] ?? ''));
        return $createdAt !== false && time() - $createdAt <= $seconds;
    }

    private static function messagePreferenceData(array $user): array
    {
        $row = Database::one(
            'SELECT accept_stranger_messages, allow_friend_requests, system_notification_enabled,
                    private_notification_enabled, group_notification_enabled,
                    profile_notes_visible, profile_forum_visible, profile_bounties_visible,
                    profile_following_visible, profile_followers_visible,
                    allow_card_add, allow_qr_add, allow_uid_search, allow_phone_search,
                    allow_email_search, allow_group_member_add, allow_group_invitations,
                    show_online_status, read_receipts_enabled, room_notification_enabled,
                    forum_notification_enabled, bounty_notification_enabled,
                    mention_notification_enabled, notification_preview_enabled,
                    notification_sound_enabled, notification_vibration_enabled,
                    remote_login_protection, dynamic_enabled, dynamic_visible_days, dynamic_visibility_mode,
                    dynamic_allow_user_ids_json, dynamic_deny_user_ids_json,
                    dynamic_visible_to_friends, dynamic_visible_to_followers,
                    dynamic_visible_to_strangers, dynamic_visible_to_hidden_contacts,
                    dynamic_visible_to_special_care
             FROM user_message_preferences WHERE user_id = ?',
            [(int) $user['id']]
        );
        $defaultStranger = (bool) AppService::setting((int) $user['app_id'], 'accept_stranger_messages_default', true);
        return [
            'accept_stranger_messages' => $row === null ? $defaultStranger : (bool) $row['accept_stranger_messages'],
            'allow_friend_requests' => $row === null ? true : (bool) $row['allow_friend_requests'],
            'system_notification_enabled' => $row === null ? true : (bool) $row['system_notification_enabled'],
            'private_notification_enabled' => $row === null ? true : (bool) $row['private_notification_enabled'],
            'group_notification_enabled' => $row === null ? true : (bool) $row['group_notification_enabled'],
            'profile_notes_visible' => $row === null ? true : (bool) $row['profile_notes_visible'],
            'profile_forum_visible' => $row === null ? true : (bool) $row['profile_forum_visible'],
            'profile_bounties_visible' => $row === null ? true : (bool) $row['profile_bounties_visible'],
            'profile_following_visible' => $row === null ? true : (bool) $row['profile_following_visible'],
            'profile_followers_visible' => $row === null ? true : (bool) $row['profile_followers_visible'],
            'allow_card_add' => $row === null ? true : (bool) $row['allow_card_add'],
            'allow_qr_add' => $row === null ? true : (bool) $row['allow_qr_add'],
            'allow_uid_search' => $row === null ? true : (bool) $row['allow_uid_search'],
            'allow_phone_search' => $row === null ? false : (bool) $row['allow_phone_search'],
            'allow_email_search' => $row === null ? false : (bool) $row['allow_email_search'],
            'allow_group_member_add' => $row === null ? true : (bool) $row['allow_group_member_add'],
            'allow_group_invitations' => $row === null ? true : (bool) $row['allow_group_invitations'],
            'show_online_status' => $row === null ? true : (bool) $row['show_online_status'],
            'read_receipts_enabled' => $row === null ? true : (bool) $row['read_receipts_enabled'],
            'room_notification_enabled' => $row === null ? true : (bool) $row['room_notification_enabled'],
            'forum_notification_enabled' => $row === null ? true : (bool) $row['forum_notification_enabled'],
            'bounty_notification_enabled' => $row === null ? true : (bool) $row['bounty_notification_enabled'],
            'mention_notification_enabled' => $row === null ? true : (bool) $row['mention_notification_enabled'],
            'notification_preview_enabled' => $row === null ? true : (bool) $row['notification_preview_enabled'],
            'notification_sound_enabled' => $row === null ? true : (bool) $row['notification_sound_enabled'],
            'notification_vibration_enabled' => $row === null ? true : (bool) $row['notification_vibration_enabled'],
            'remote_login_protection' => $row === null ? true : (bool) $row['remote_login_protection'],
            'dynamic_enabled' => $row === null ? true : (bool) $row['dynamic_enabled'],
            'dynamic_visible_days' => MomentVisibilityService::normalizeDays($row['dynamic_visible_days'] ?? 0),
            'dynamic_visibility_mode' => MomentVisibilityService::normalizeMode((string) ($row['dynamic_visibility_mode'] ?? 'public'), false),
            'dynamic_allow_user_ids' => MomentVisibilityService::decodeIds($row['dynamic_allow_user_ids_json'] ?? null),
            'dynamic_deny_user_ids' => MomentVisibilityService::decodeIds($row['dynamic_deny_user_ids_json'] ?? null),
            'dynamic_visible_to_friends' => $row === null ? true : (bool) $row['dynamic_visible_to_friends'],
            'dynamic_visible_to_followers' => $row === null ? true : (bool) $row['dynamic_visible_to_followers'],
            'dynamic_visible_to_strangers' => $row === null ? true : (bool) $row['dynamic_visible_to_strangers'],
            'dynamic_visible_to_hidden_contacts' => $row === null ? true : (bool) $row['dynamic_visible_to_hidden_contacts'],
            'dynamic_visible_to_special_care' => $row === null ? true : (bool) $row['dynamic_visible_to_special_care'],
        ];
    }

    private static function allowsFriendRequests(array $receiver, int $appId): bool
    {
        $row = Database::one(
            'SELECT allow_friend_requests FROM user_message_preferences WHERE user_id = ? AND app_id = ?',
            [(int) $receiver['id'], $appId]
        );
        return $row === null || (bool) $row['allow_friend_requests'];
    }

    private static function relationshipNoticeSummary(array $user): array
    {
        $appId = (int) $user['app_id'];
        $userId = (int) $user['id'];
        $friendCounts = Database::one(
            "SELECT
                SUM(CASE WHEN to_user_id = ? AND status = 'pending' AND expired_at > NOW() THEN 1 ELSE 0 END) AS incoming_count,
                SUM(CASE WHEN from_user_id = ? AND status IN ('pending','ignored') AND expired_at > NOW() THEN 1 ELSE 0 END) AS outgoing_count,
                SUM(CASE WHEN to_user_id = ? AND status = 'ignored' AND expired_at > NOW() THEN 1 ELSE 0 END) AS filtered_count
             FROM friend_requests WHERE app_id = ? AND (from_user_id = ? OR to_user_id = ?)",
            [$userId, $userId, $userId, $appId, $userId, $userId]
        ) ?? [];
        $latestFriend = Database::one(
            "SELECT fr.created_at, fr.status,
                    CASE WHEN fr.from_user_id = ? THEN 'outgoing' ELSE 'incoming' END AS direction,
                    COALESCE(NULLIF(p.nickname, ''), NULLIF(u.account, ''), CONCAT('用户 ', u.uid)) AS display_name
             FROM friend_requests fr
             INNER JOIN users u ON u.id = CASE WHEN fr.from_user_id = ? THEN fr.to_user_id ELSE fr.from_user_id END
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE fr.app_id = ? AND (fr.from_user_id = ? OR fr.to_user_id = ?)
             ORDER BY fr.id DESC LIMIT 1",
            [$userId, $userId, $appId, $userId, $userId]
        );
        $invitationCounts = Database::one(
            "SELECT
                SUM(CASE WHEN status = 'pending' AND (expired_at IS NULL OR expired_at > NOW()) THEN 1 ELSE 0 END) AS invitation_count,
                SUM(CASE WHEN status = 'ignored' AND (expired_at IS NULL OR expired_at > NOW()) THEN 1 ELSE 0 END) AS filtered_count
             FROM chat_room_invitations WHERE app_id = ? AND invitee_user_id = ?",
            [$appId, $userId]
        ) ?? [];
        $joinCounts = Database::one(
            "SELECT
                SUM(CASE WHEN jr.status = 'pending' AND jr.expired_at > NOW() THEN 1 ELSE 0 END) AS join_count,
                SUM(CASE WHEN jr.status = 'ignored' AND jr.expired_at > NOW() THEN 1 ELSE 0 END) AS filtered_count
             FROM chat_room_join_requests jr
             INNER JOIN chat_room_members manager ON manager.room_id = jr.room_id
                AND manager.user_id = ? AND manager.role IN ('owner','admin')
             WHERE jr.app_id = ?",
            [$userId, $appId]
        ) ?? [];
        $latestGroups = [];
        $latestInvitation = Database::one(
            'SELECT i.created_at, i.status, r.name AS room_name, \'邀请加入\' AS notice_name
             FROM chat_room_invitations i INNER JOIN chat_rooms r ON r.id = i.room_id
             WHERE i.app_id = ? AND i.invitee_user_id = ? ORDER BY i.id DESC LIMIT 1',
            [$appId, $userId]
        );
        if ($latestInvitation !== null) $latestGroups[] = $latestInvitation;
        $latestJoin = Database::one(
            "SELECT jr.created_at, jr.status, r.name AS room_name, '群聊申请' AS notice_name
             FROM chat_room_join_requests jr
             INNER JOIN chat_rooms r ON r.id = jr.room_id
             INNER JOIN chat_room_members manager ON manager.room_id = jr.room_id
                AND manager.user_id = ? AND manager.role IN ('owner','admin')
             WHERE jr.app_id = ? ORDER BY jr.id DESC LIMIT 1",
            [$userId, $appId]
        );
        if ($latestJoin !== null) $latestGroups[] = $latestJoin;
        usort($latestGroups, static fn(array $left, array $right): int => strcmp(
            (string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? '')
        ));
        $latestGroup = $latestGroups[0] ?? null;
        $friendIncoming = (int) ($friendCounts['incoming_count'] ?? 0);
        $groupPending = (int) ($invitationCounts['invitation_count'] ?? 0) + (int) ($joinCounts['join_count'] ?? 0);
        return [
            'friend' => [
                'incoming_count' => $friendIncoming,
                'outgoing_count' => (int) ($friendCounts['outgoing_count'] ?? 0),
                'filtered_count' => (int) ($friendCounts['filtered_count'] ?? 0),
                'latest_text' => $latestFriend === null ? '暂无好友通知'
                    : ((string) $latestFriend['direction'] === 'outgoing' ? '已申请添加 ' : '')
                        . (string) $latestFriend['display_name']
                        . ((string) $latestFriend['direction'] === 'incoming' ? ' 请求添加你为好友' : ''),
                'latest_at' => $latestFriend['created_at'] ?? null,
            ],
            'group' => [
                'join_count' => (int) ($joinCounts['join_count'] ?? 0),
                'invitation_count' => (int) ($invitationCounts['invitation_count'] ?? 0),
                'filtered_count' => (int) ($invitationCounts['filtered_count'] ?? 0) + (int) ($joinCounts['filtered_count'] ?? 0),
                'latest_text' => $latestGroup === null ? '暂无群聊通知'
                    : (string) $latestGroup['notice_name'] . ' · ' . (string) $latestGroup['room_name'],
                'latest_at' => $latestGroup['created_at'] ?? null,
            ],
            'badge_count' => $friendIncoming + $groupPending,
        ];
    }

    private static function managedJoinRequestRows(array $user, bool $filtered, int $limit): array
    {
        $statusSql = $filtered ? "jr.status = 'ignored'" : "jr.status <> 'ignored'";
        return Database::all(
            "SELECT 'group_join_request' AS notice_type, jr.*, r.name AS room_name, r.icon AS room_icon,
                    u.id AS subject_user_id, u.uid, COALESCE(u.account, '') AS account,
                    COALESCE(NULLIF(p.nickname, ''), NULLIF(u.account, ''), CONCAT('用户 ', u.uid)) AS display_name,
                    COALESCE(p.avatar, '') AS avatar
             FROM chat_room_join_requests jr
             INNER JOIN chat_rooms r ON r.id = jr.room_id
             INNER JOIN chat_room_members manager ON manager.room_id = jr.room_id
                AND manager.user_id = ? AND manager.role IN ('owner','admin')
             INNER JOIN users u ON u.id = jr.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE jr.app_id = ? AND {$statusSql}
             ORDER BY jr.id DESC LIMIT {$limit}",
            [(int) $user['id'], (int) $user['app_id']]
        );
    }

    private static function groupInvitationNotice(array $row): array
    {
        $row['title'] = (string) $row['display_name'] . ' 邀请你加入群聊';
        $row['subtitle'] = (string) $row['room_name'];
        $row = self::relationshipNoticeState($row, true);
        $row['direction'] = 'incoming';
        $row['subject_type'] = 'user';
        return $row;
    }

    private static function groupJoinNotice(array $row): array
    {
        $row['title'] = (string) $row['display_name'] . ' 申请加入群聊';
        $row['subtitle'] = (string) $row['room_name'];
        $row = self::relationshipNoticeState($row, true);
        $row['direction'] = 'incoming';
        $row['subject_type'] = 'user';
        return $row;
    }

    private static function relationshipNoticeState(array $row, bool $mayDecide): array
    {
        $status = strtolower(trim((string) ($row['status'] ?? 'pending')));
        $active = in_array($status, ['pending', 'ignored'], true);
        $expiredAt = trim((string) ($row['expired_at'] ?? ''));
        $expired = $active && $expiredAt !== '' && strtotime($expiredAt) <= time();
        $row['is_expired'] = $expired;
        $row['is_dimmed'] = $expired;
        $row['can_decide'] = $mayDecide && $active && !$expired;
        $row['status_text'] = $expired ? '已过期' : match ($status) {
            'pending' => '待处理',
            'ignored' => '已忽略，可继续处理',
            'accepted', 'approved' => '已同意',
            'rejected' => '已拒绝',
            default => '已处理',
        };
        $row['expired_text'] = $expired ? '申请或邀请已过期，仅可查看' : '';
        return $row;
    }

    private static function acceptsStrangerMessages(array $receiver, int $appId): bool
    {
        $row = Database::one(
            'SELECT accept_stranger_messages FROM user_message_preferences WHERE user_id = ?',
            [(int) $receiver['id']]
        );
        return $row === null
            ? (bool) AppService::setting($appId, 'accept_stranger_messages_default', true)
            : (bool) $row['accept_stranger_messages'];
    }

    private static function generatedFriendClue(array $friend): string
    {
        $clues = [];
        $createdAt = trim((string) ($friend['created_at'] ?? ''));
        if ($createdAt !== '') $clues[] = '成为好友：' . mb_substr($createdAt, 0, 10);
        $groupName = trim((string) ($friend['group_name'] ?? ''));
        if ($groupName !== '') $clues[] = '好友分组：' . $groupName;
        if ((bool) ($friend['special_care'] ?? false)) $clues[] = '已设为特别关心';
        if ((bool) ($friend['only_chat'] ?? false)) $clues[] = '当前仅开放聊天权限';
        return $clues === [] ? '暂无更多互动线索' : implode(' · ', $clues);
    }

    private static function isFriend(array $user, int $targetUserId): bool
    {
        return Database::one(
            'SELECT id FROM friends WHERE app_id = ? AND user_id = ? AND friend_user_id = ? AND status = 1',
            [(int) $user['app_id'], (int) $user['id'], $targetUserId]
        ) !== null;
    }

    private static function room(array $user, int $roomId): array
    {
        $room = Database::one(
            'SELECT * FROM chat_rooms WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1',
            [$roomId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        if ($room === null) {
            throw new HttpException('聊天室不存在或已关闭', 404, 404);
        }
        if (!(bool) $room['is_public'] && !Database::one(
            'SELECT id FROM chat_room_members WHERE room_id = ? AND user_id = ?',
            [$roomId, (int) $user['id']]
        )) {
            throw new HttpException('你无权访问该聊天室', 403, 403);
        }
        return $room;
    }

    private static function user(Request $request, string $feature): array
    {
        return AuthService::user($request, $feature);
    }
}
