<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;

final class CommunicationTakeoverService
{
    private const BOOLEAN_FIELDS = [
        'platform_view_enabled', 'platform_send_enabled',
        'platform_private_enabled', 'platform_group_enabled', 'platform_service_enabled',
        'admin_view_enabled', 'admin_send_enabled',
        'admin_private_enabled', 'admin_group_enabled', 'admin_service_enabled',
    ];

    public static function policy(int $adminId, int $appId): array
    {
        Database::execute(
            'INSERT IGNORE INTO communication_takeover_policies
             (admin_id, app_id, platform_view_enabled, platform_send_enabled,
              platform_private_enabled, platform_group_enabled, platform_service_enabled,
              admin_view_enabled, admin_send_enabled, admin_private_enabled, admin_group_enabled,
              admin_service_enabled, system_display_name, policy_locked_for_level,
              locked_by_platform_id, updated_by_type, updated_by_id, created_at, updated_at)
             VALUES (?, ?, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, ?, 0, NULL, ?, 0, NOW(), NOW())',
            [$adminId, $appId, '系统消息', 'system']
        );
        $policy = Database::one(
            'SELECT * FROM communication_takeover_policies WHERE admin_id = ? AND app_id = ?',
            [$adminId, $appId]
        );
        if ($policy === null) {
            throw new HttpException('通信接管策略初始化失败', -1, 500);
        }
        return self::normalizePolicy($policy);
    }

    public static function forPlatform(array $actor, array $app): array
    {
        $policy = self::policy((int) $app['admin_id'], (int) $app['id']);
        $level = (int) $actor['level'];
        $locked = $level === 2 && (int) $policy['policy_locked_for_level'] === 2
            && (int) ($policy['locked_by_platform_id'] ?? 0) !== (int) $actor['id'];
        return self::view($policy, 'platform', $level, !$locked, $level === 1);
    }

    public static function forAdmin(array $admin, int $appId): array
    {
        $policy = self::policy((int) $admin['id'], $appId);
        $locked = in_array((int) $policy['policy_locked_for_level'], [2, 3], true);
        return self::view($policy, 'admin', 3, !$locked, false);
    }

    public static function saveForPlatform(Request $request, array $actor, array $app): array
    {
        $policy = self::policy((int) $app['admin_id'], (int) $app['id']);
        $level = (int) $actor['level'];
        if ($level === 2 && (int) $policy['policy_locked_for_level'] === 2
            && (int) ($policy['locked_by_platform_id'] ?? 0) !== (int) $actor['id']) {
            throw new HttpException('1 级平台已强制锁定通信接管策略，2 级平台不能修改', 403, 403);
        }
        $values = self::inputValues($request, $policy, self::BOOLEAN_FIELDS);
        $name = self::displayName($request->input('system_display_name', $policy['system_display_name']));
        $force = self::boolean($request->input('force_descendants', false));
        $lockLevel = $force ? ($level === 1 ? 2 : 3) : 0;
        Database::execute(
            'UPDATE communication_takeover_policies SET
               platform_view_enabled = ?, platform_send_enabled = ?,
               platform_private_enabled = ?, platform_group_enabled = ?, platform_service_enabled = ?,
               admin_view_enabled = ?, admin_send_enabled = ?, admin_private_enabled = ?,
               admin_group_enabled = ?, admin_service_enabled = ?, system_display_name = ?,
               policy_locked_for_level = ?, locked_by_platform_id = ?, updated_by_type = ?,
               updated_by_id = ?, updated_at = NOW()
             WHERE admin_id = ? AND app_id = ?',
            [
                $values['platform_view_enabled'], $values['platform_send_enabled'],
                $values['platform_private_enabled'], $values['platform_group_enabled'], $values['platform_service_enabled'],
                $values['admin_view_enabled'], $values['admin_send_enabled'], $values['admin_private_enabled'],
                $values['admin_group_enabled'], $values['admin_service_enabled'], $name,
                $lockLevel, $force ? (int) $actor['id'] : null, 'platform', (int) $actor['id'],
                (int) $app['admin_id'], (int) $app['id'],
            ]
        );
        self::audit($request, (int) $app['admin_id'], (int) $app['id'], 'platform', (int) $actor['id'], $level,
            'policy_update', '', 0, null, null, ['force_descendants' => $force, 'lock_level' => $lockLevel]);
        return self::forPlatform($actor, $app);
    }

    public static function saveForAdmin(Request $request, array $admin, int $appId): array
    {
        $policy = self::policy((int) $admin['id'], $appId);
        if (in_array((int) $policy['policy_locked_for_level'], [2, 3], true)) {
            throw new HttpException('上级平台已强制锁定通信接管策略，管理员不能修改', 403, 403, [
                'locked_by_platform_id' => $policy['locked_by_platform_id'],
            ]);
        }
        $fields = [
            'admin_view_enabled', 'admin_send_enabled', 'admin_private_enabled',
            'admin_group_enabled', 'admin_service_enabled',
        ];
        $values = self::inputValues($request, $policy, $fields);
        $name = self::displayName($request->input('system_display_name', $policy['system_display_name']));
        Database::execute(
            'UPDATE communication_takeover_policies SET admin_view_enabled = ?, admin_send_enabled = ?,
               admin_private_enabled = ?, admin_group_enabled = ?, admin_service_enabled = ?,
               system_display_name = ?, updated_by_type = ?, updated_by_id = ?, updated_at = NOW()
             WHERE admin_id = ? AND app_id = ?',
            [
                $values['admin_view_enabled'], $values['admin_send_enabled'], $values['admin_private_enabled'],
                $values['admin_group_enabled'], $values['admin_service_enabled'], $name,
                'admin', (int) $admin['id'], (int) $admin['id'], $appId,
            ]
        );
        self::audit($request, (int) $admin['id'], $appId, 'admin', (int) $admin['id'], 3,
            'policy_update', '', 0, null, null, ['self_managed' => true]);
        return self::forAdmin($admin, $appId);
    }

    public static function assertPlatform(array $actor, array $app, string $action, string $channelType): array
    {
        $view = self::forPlatform($actor, $app);
        self::assertAllowed($view, $action, $channelType);
        return $view;
    }

    public static function assertAdmin(array $admin, int $appId, string $action, string $channelType): array
    {
        $view = self::forAdmin($admin, $appId);
        self::assertAllowed($view, $action, $channelType);
        return $view;
    }

    public static function sendSystemMessage(
        Request $request,
        int $adminId,
        int $appId,
        int $subjectUserId,
        string $channelType,
        int $channelId,
        string $content,
        string $actorType,
        int $actorId,
        int $actorLevel
    ): array {
        $channelType = self::channelType($channelType);
        $content = trim($content);
        if ($channelId <= 0) throw new HttpException('channel_id 必须大于 0', 0, 422);
        if ($content === '' || mb_strlen($content) > 10000) {
            throw new HttpException('消息内容长度必须在 1-10000 个字符之间', 0, 422);
        }
        $user = Database::one(
            'SELECT id FROM users WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
            [$subjectUserId, $adminId, $appId]
        );
        if ($user === null) throw new HttpException('用户不存在或不在当前管理范围', 404, 404);
        $messageId = Database::transaction(static function () use (
            $adminId, $appId, $subjectUserId, $channelType, $channelId, $content
        ): int {
            if ($channelType === 'private') {
                $channel = Database::one(
                    'SELECT id FROM conversations WHERE id = ? AND admin_id = ? AND app_id = ? AND (user_a_id = ? OR user_b_id = ?)',
                    [$channelId, $adminId, $appId, $subjectUserId, $subjectUserId]
                );
                if ($channel === null) throw new HttpException('私聊会话不存在或不属于该用户', 404, 404);
                $id = Database::insert(
                    'INSERT INTO messages
                     (admin_id, app_id, conversation_id, sender_type, sender_id, receiver_user_id,
                      title, content_type, content, tags_json, is_read, status, created_at)
                     VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 0, 1, NOW())',
                    [$adminId, $appId, $channelId, 'system', $subjectUserId, '系统接管', 'text', $content, '["系统接管"]']
                );
                Database::execute(
                    'UPDATE conversations SET last_message_id = ?, last_message_at = NOW(), updated_at = NOW() WHERE id = ?',
                    [$id, $channelId]
                );
                return $id;
            }
            if ($channelType === 'group') {
                $channel = Database::one(
                    'SELECT room.id FROM chat_rooms room INNER JOIN chat_room_members member ON member.room_id = room.id
                     WHERE room.id = ? AND room.admin_id = ? AND room.app_id = ? AND member.user_id = ? AND room.status = 1',
                    [$channelId, $adminId, $appId, $subjectUserId]
                );
                if ($channel === null) throw new HttpException('群聊或聊天室不存在，或该用户不在其中', 404, 404);
                return Database::insert(
                    'INSERT INTO chat_room_messages
                     (admin_id, app_id, room_id, user_id, sender_type, sender_admin_id,
                      content_type, content, tags_json, status, created_at)
                     VALUES (?, ?, ?, NULL, ?, NULL, ?, ?, ?, 1, NOW())',
                    [$adminId, $appId, $channelId, 'system', 'text', $content, '["系统接管"]']
                );
            }
            $channel = Database::one(
                'SELECT id FROM service_sessions WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ?',
                [$channelId, $adminId, $appId, $subjectUserId]
            );
            if ($channel === null) throw new HttpException('客服会话不存在或不属于该用户', 404, 404);
            $id = Database::insert(
                'INSERT INTO service_messages (admin_id, app_id, session_id, sender_type, sender_id, content, is_read, created_at)
                 VALUES (?, ?, ?, ?, 0, ?, 0, NOW())',
                [$adminId, $appId, $channelId, 'system', $content]
            );
            Database::execute('UPDATE service_sessions SET last_message_at = NOW(), updated_at = NOW() WHERE id = ?', [$channelId]);
            return $id;
        });
        self::audit($request, $adminId, $appId, $actorType, $actorId, $actorLevel, 'send',
            $channelType, $channelId, $subjectUserId, $messageId, [
                'public_sender_name' => '系统消息',
                'public_sender_badge' => '系统',
                'actor_visible_to_users' => false,
                'content_sha256' => hash('sha256', $content),
                'content_preview' => mb_substr($content, 0, 120),
            ]);
        return [
            'message_id' => $messageId,
            'channel_type' => $channelType,
            'channel_id' => $channelId,
            'public_sender' => ['name' => '系统消息', 'badge' => '系统'],
            'actor_hidden_from_members' => true,
        ];
    }

    public static function updateManagedMessage(
        Request $request,
        int $adminId,
        int $appId,
        int $subjectUserId,
        string $channelType,
        int $channelId,
        int $messageId,
        string $content,
        string $actorType,
        int $actorId,
        int $actorLevel
    ): array {
        $channelType = self::channelType($channelType);
        $content = trim($content);
        if ($channelId <= 0 || $messageId <= 0) {
            throw new HttpException('channel_id 和 message_id 必须大于 0', 0, 422);
        }
        if ($content === '' || mb_strlen($content) > 10000) {
            throw new HttpException('消息内容长度必须在 1-10000 个字符之间', 0, 422);
        }
        $message = self::managedMessage(
            $adminId, $appId, $subjectUserId, $channelType, $channelId, $messageId
        );
        if (in_array($channelType, ['private', 'group'], true) && (int) ($message['status'] ?? 1) !== 1) {
            throw new HttpException('已删除的消息不能再修改', 0, 409);
        }
        $table = self::messageTable($channelType);
        Database::execute("UPDATE {$table} SET content = ? WHERE id = ?", [$content, $messageId]);
        self::audit(
            $request, $adminId, $appId, $actorType, $actorId, $actorLevel, 'update',
            $channelType, $channelId, $subjectUserId, $messageId,
            [
                'old_content' => (string) $message['content'],
                'new_content' => $content,
                'old_content_sha256' => hash('sha256', (string) $message['content']),
                'new_content_sha256' => hash('sha256', $content),
                'actor_visible_to_users' => false,
            ]
        );
        return [
            'message_id' => $messageId,
            'channel_type' => $channelType,
            'channel_id' => $channelId,
            'content' => $content,
            'updated_by_management' => true,
        ];
    }

    public static function deleteManagedMessage(
        Request $request,
        int $adminId,
        int $appId,
        int $subjectUserId,
        string $channelType,
        int $channelId,
        int $messageId,
        string $actorType,
        int $actorId,
        int $actorLevel
    ): array {
        $channelType = self::channelType($channelType);
        if ($channelId <= 0 || $messageId <= 0) {
            throw new HttpException('channel_id 和 message_id 必须大于 0', 0, 422);
        }
        $message = self::managedMessage(
            $adminId, $appId, $subjectUserId, $channelType, $channelId, $messageId
        );
        if ($channelType === 'service') {
            Database::execute(
                "UPDATE service_messages SET content = '[该消息已由管理人员删除]' WHERE id = ?",
                [$messageId]
            );
        } else {
            Database::execute(
                'UPDATE ' . self::messageTable($channelType) . ' SET status = -1 WHERE id = ?',
                [$messageId]
            );
        }
        self::audit(
            $request, $adminId, $appId, $actorType, $actorId, $actorLevel, 'delete',
            $channelType, $channelId, $subjectUserId, $messageId,
            [
                'old_content' => (string) $message['content'],
                'old_content_sha256' => hash('sha256', (string) $message['content']),
                'old_content_type' => (string) ($message['content_type'] ?? 'text'),
                'actor_visible_to_users' => false,
                'delete_mode' => $channelType === 'service' ? 'redacted' : 'soft_delete',
            ]
        );
        return [
            'message_id' => $messageId,
            'channel_type' => $channelType,
            'channel_id' => $channelId,
            'deleted_by_management' => true,
        ];
    }

    public static function recordView(
        Request $request,
        int $adminId,
        int $appId,
        string $actorType,
        int $actorId,
        int $actorLevel,
        string $channelType,
        int $channelId,
        int $subjectUserId
    ): void {
        self::audit($request, $adminId, $appId, $actorType, $actorId, $actorLevel, 'view',
            self::channelType($channelType), $channelId, $subjectUserId, null, ['audit_mode' => true]);
    }

    public static function audits(Request $request, int $adminId, int $appId): array
    {
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['admin_id = ?', 'app_id = ?'];
        $query = [$adminId, $appId];
        foreach (['actor_type', 'action', 'channel_type'] as $field) {
            $value = trim((string) $request->input($field, ''));
            if ($value !== '') { $where[] = $field . ' = ?'; $query[] = $value; }
        }
        if ((int) $request->input('actor_id', 0) > 0) {
            $where[] = 'actor_id = ?';
            $query[] = (int) $request->input('actor_id');
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM communication_takeover_audits WHERE {$whereSql}", $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT * FROM communication_takeover_audits WHERE {$whereSql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        foreach ($items as &$item) {
            $item['detail'] = json_decode((string) ($item['detail_json'] ?? ''), true) ?: [];
            unset($item['detail_json']);
        }
        unset($item);
        return Pagination::data($items, $total, $page, $limit);
    }

    private static function view(array $policy, string $actorType, int $actorLevel, bool $editable, bool $unlimited): array
    {
        $prefix = $actorType === 'platform' ? 'platform' : 'admin';
        $effective = [
            'can_view' => $unlimited || (bool) $policy[$prefix . '_view_enabled'],
            'can_send' => $unlimited || (bool) $policy[$prefix . '_send_enabled'],
            'private_enabled' => $unlimited || (bool) $policy[$prefix . '_private_enabled'],
            'group_enabled' => $unlimited || (bool) $policy[$prefix . '_group_enabled'],
            'service_enabled' => $unlimited || (bool) $policy[$prefix . '_service_enabled'],
        ];
        return [
            'policy' => $policy,
            'effective' => $effective,
            'actor_type' => $actorType,
            'actor_level' => $actorLevel,
            'editable' => $editable || $unlimited,
            'unlimited' => $unlimited,
            'member_visibility' => 'hidden',
            'public_sender' => [
                'name' => (string) $policy['system_display_name'],
                'badge' => '系统',
                'description' => '接管者不加入成员列表，真实身份仅写入管理审计',
            ],
            'channel_labels' => [
                'private' => '私聊', 'group' => '群聊与聊天室', 'service' => '客服会话',
            ],
        ];
    }

    private static function assertAllowed(array $view, string $action, string $channelType): void
    {
        $channelType = self::channelType($channelType);
        $effective = $view['effective'];
        if (!(bool) $effective['can_view']) {
            throw new HttpException('当前层级未启用下级通信查看权限', 403, 403, ['takeover_policy' => $view]);
        }
        if (in_array($action, ['send', 'update', 'delete'], true) && !(bool) $effective['can_send']) {
            throw new HttpException('当前层级未启用系统消息接管发言权限', 403, 403, ['takeover_policy' => $view]);
        }
        if (!(bool) $effective[$channelType . '_enabled']) {
            throw new HttpException('当前接管策略未启用该通信类型：' . $view['channel_labels'][$channelType], 403, 403, [
                'takeover_policy' => $view,
            ]);
        }
    }

    private static function audit(
        Request $request,
        int $adminId,
        int $appId,
        string $actorType,
        int $actorId,
        int $actorLevel,
        string $action,
        string $channelType,
        int $channelId,
        ?int $subjectUserId,
        ?int $messageId,
        array $detail
    ): void {
        Database::execute(
            'INSERT INTO communication_takeover_audits
             (admin_id, app_id, actor_type, actor_id, actor_level, action, channel_type,
              channel_id, subject_user_id, message_id, detail_json, ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $adminId, $appId, $actorType, $actorId, $actorLevel, $action, $channelType,
                $channelId, $subjectUserId, $messageId,
                json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $request->clientIp(),
            ]
        );
    }

    private static function normalizePolicy(array $policy): array
    {
        $policy['id'] = (int) $policy['id'];
        $policy['admin_id'] = (int) $policy['admin_id'];
        $policy['app_id'] = (int) $policy['app_id'];
        foreach (self::BOOLEAN_FIELDS as $field) $policy[$field] = (bool) $policy[$field];
        $policy['policy_locked_for_level'] = (int) $policy['policy_locked_for_level'];
        $policy['locked_by_platform_id'] = $policy['locked_by_platform_id'] === null
            ? null : (int) $policy['locked_by_platform_id'];
        $policy['updated_by_id'] = (int) $policy['updated_by_id'];
        return $policy;
    }

    private static function inputValues(Request $request, array $policy, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = self::boolean($request->input($field, $policy[$field])) ? 1 : 0;
        }
        return $values;
    }

    private static function boolean($value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value) || is_float($value)) return (int) $value === 1;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function displayName($value): string
    {
        $name = trim((string) $value);
        if ($name === '') $name = '系统消息';
        if (mb_strlen($name) > 40) throw new HttpException('系统消息显示名称不能超过 40 个字符', 0, 422);
        return $name;
    }

    private static function managedMessage(
        int $adminId,
        int $appId,
        int $subjectUserId,
        string $channelType,
        int $channelId,
        int $messageId
    ): array {
        $user = Database::one(
            'SELECT id FROM users WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
            [$subjectUserId, $adminId, $appId]
        );
        if ($user === null) throw new HttpException('用户不存在或不在当前管理范围', 404, 404);
        if ($channelType === 'private') {
            $message = Database::one(
                'SELECT message.id, message.content, message.content_type, message.status
                 FROM messages message
                 INNER JOIN conversations conversation ON conversation.id = message.conversation_id
                 WHERE message.id = ? AND conversation.id = ? AND conversation.admin_id = ?
                   AND conversation.app_id = ? AND (conversation.user_a_id = ? OR conversation.user_b_id = ?)',
                [$messageId, $channelId, $adminId, $appId, $subjectUserId, $subjectUserId]
            );
        } elseif ($channelType === 'group') {
            $message = Database::one(
                'SELECT message.id, message.content, message.content_type, message.status
                 FROM chat_room_messages message
                 INNER JOIN chat_rooms room ON room.id = message.room_id
                 INNER JOIN chat_room_members member ON member.room_id = room.id AND member.user_id = ?
                 WHERE message.id = ? AND room.id = ? AND room.admin_id = ? AND room.app_id = ?',
                [$subjectUserId, $messageId, $channelId, $adminId, $appId]
            );
        } else {
            $message = Database::one(
                "SELECT message.id, message.content, 'text' AS content_type, 1 AS status
                 FROM service_messages message
                 INNER JOIN service_sessions session ON session.id = message.session_id
                 WHERE message.id = ? AND session.id = ? AND session.admin_id = ?
                   AND session.app_id = ? AND session.user_id = ?",
                [$messageId, $channelId, $adminId, $appId, $subjectUserId]
            );
        }
        if ($message === null) {
            throw new HttpException('消息不存在，或不属于该用户的当前会话', 404, 404);
        }
        return $message;
    }

    private static function messageTable(string $channelType): string
    {
        if ($channelType === 'private') return 'messages';
        if ($channelType === 'group') return 'chat_room_messages';
        return 'service_messages';
    }

    private static function channelType(string $value): string
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['room', 'chat_room', 'chatroom'], true)) $value = 'group';
        if (!in_array($value, ['private', 'group', 'service'], true)) {
            throw new HttpException('channel_type 仅支持 private、group、chat_room 或 service', 0, 422);
        }
        return $value;
    }
}
