<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class MessageForwardService
{
    public static function create(
        array $user,
        string $sourceType,
        int $sourceId,
        array $rows,
        array $options = []
    ): array
    {
        $anonymityMode = self::anonymityMode((string) ($options['anonymity_mode'] ?? 'none'));
        $selectedKeys = [];
        foreach ((array) ($options['anonymous_sender_keys'] ?? []) as $value) {
            $key = trim((string) $value);
            if ($key !== '') $selectedKeys[$key] = true;
        }
        $aliases = [];
        $visitedBundles = [];
        $auditVisitedBundles = [];
        $items = [];
        $auditItems = [];
        foreach ($rows as $index => $row) {
            $rawRow = $row;
            MessagePresentationService::decorate($row, $sourceType);
            $senderName = trim((string) ($row['sender_name'] ?? $row['nickname'] ?? $row['account'] ?? ''));
            if ($senderName === '') $senderName = self::senderLabel((string) ($row['sender_type'] ?? 'user'));
            $tags = $row['tags'] ?? [];
            if (!is_array($tags)) $tags = self::decodeArray($row['tags_json'] ?? null);
            $item = [
                'id' => (int) ($row['id'] ?? 0),
                'source_message_id' => (int) ($row['id'] ?? 0),
                'snapshot_sequence' => $index + 1,
                'snapshot_version' => 2,
                'sender_type' => (string) ($row['sender_type'] ?? 'user'),
                'sender_id' => (int) ($row['sender_id'] ?? $row['user_id'] ?? 0),
                'sender_name' => $senderName,
                'sender_display_name' => (string) ($row['sender_display_name'] ?? $senderName),
                'sender_badge' => (string) ($row['sender_badge'] ?? ''),
                'sender_badge_tone' => (string) ($row['sender_badge_tone'] ?? 'neutral'),
                'sender_role' => (string) ($row['sender_role'] ?? $row['role'] ?? ''),
                'sender_avatar' => (string) ($row['sender_avatar'] ?? $row['avatar'] ?? ''),
                'content_type' => (string) ($row['content_type'] ?? 'text'),
                'content' => (string) ($row['content'] ?? ''),
                'attachments' => array_values(is_array($row['attachments'] ?? null) ? $row['attachments'] : []),
                'tags' => array_values($tags),
                'reply_to_message_id' => (int) ($row['reply_to_message_id'] ?? 0),
                'forward_bundle_id' => (int) ($row['forward_bundle_id'] ?? 0),
                'forward_bundle' => self::embeddedForward(
                    (int) $user['admin_id'],
                    (int) $user['app_id'],
                    (int) ($row['forward_bundle_id'] ?? 0),
                    is_array($row['forward_bundle'] ?? null) ? $row['forward_bundle'] : [],
                    $visitedBundles,
                    0
                ),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'snapshot_mine' => (string) ($row['sender_type'] ?? 'user') === 'user'
                    && (int) ($row['sender_id'] ?? $row['user_id'] ?? 0) === (int) $user['id'],
                'snapshot_read_only' => true,
            ];
            $auditItem = $item;
            self::applyAuditIdentity(
                $auditItem,
                $rawRow,
                (int) $user['admin_id'],
                (int) $user['app_id'],
                $sourceType
            );
            $auditItem['forward_bundle'] = self::embeddedForward(
                (int) $user['admin_id'],
                (int) $user['app_id'],
                (int) ($row['forward_bundle_id'] ?? 0),
                is_array($row['forward_bundle'] ?? null) ? $row['forward_bundle'] : [],
                $auditVisitedBundles,
                0,
                true
            );
            $auditItems[] = $auditItem;
            self::applyAnonymity($item, $anonymityMode, $selectedKeys, $aliases);
            $items[] = $item;
        }
        $title = self::title($items, $anonymityMode);
        $sourceContext = self::sourceContext(
            (int) $user['admin_id'],
            (int) $user['app_id'],
            $sourceType,
            $sourceId
        );
        $id = Database::insert(
            'INSERT INTO message_forward_bundles
             (admin_id, app_id, creator_user_id, source_type, source_id, title, item_count,
              anonymity_mode, anonymity_map_json, snapshot_json, audit_snapshot_json,
              source_context_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $sourceType, $sourceId,
                $title, count($items), $anonymityMode,
                json_encode($aliases, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($auditItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($sourceContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
        return [
            'id' => $id,
            'title' => $title,
            'item_count' => count($items),
            'anonymity_mode' => $anonymityMode,
            'anonymity_scope' => 'current_bundle',
            'items' => $items,
        ];
    }

    public static function link(array $user, int $bundleId, string $targetType, int $targetId): void
    {
        Database::insert(
            'INSERT INTO message_forward_links
             (admin_id, app_id, bundle_id, target_type, target_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [(int) $user['admin_id'], (int) $user['app_id'], $bundleId, $targetType, $targetId]
        );
    }

    public static function hydrate(array $items, string $targetType, int $appId): array
    {
        $ids = [];
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) > 0) $ids[] = (int) $item['id'];
        }
        if ($ids === []) return $items;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::all(
            "SELECT link.target_id, bundle.id, bundle.title, bundle.item_count,
                    bundle.anonymity_mode, bundle.created_at
             FROM message_forward_links link INNER JOIN message_forward_bundles bundle ON bundle.id = link.bundle_id
             WHERE link.app_id = ? AND link.target_type = ? AND link.target_id IN ({$placeholders})",
            array_merge([$appId, $targetType], $ids)
        );
        $byTarget = [];
        foreach ($rows as $row) {
            $byTarget[(int) $row['target_id']] = [
                'id' => (int) $row['id'], 'title' => (string) $row['title'],
                'item_count' => (int) $row['item_count'], 'created_at' => (string) $row['created_at'],
                'anonymity_mode' => self::anonymityMode((string) ($row['anonymity_mode'] ?? 'none')),
                'anonymity_scope' => 'current_bundle',
                'read_only' => true,
            ];
        }
        foreach ($items as &$item) {
            $bundle = $byTarget[(int) ($item['id'] ?? 0)] ?? null;
            if ($bundle !== null) {
                $item['forward_bundle_id'] = $bundle['id'];
                $item['forward_bundle'] = $bundle;
            }
        }
        unset($item);
        return $items;
    }

    public static function showForUser(array $user, int $bundleId): array
    {
        $bundle = Database::one(
            'SELECT * FROM message_forward_bundles WHERE id = ? AND admin_id = ? AND app_id = ?',
            [$bundleId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        if ($bundle === null || !self::canView($user, $bundle)) {
            throw new HttpException('转发的聊天记录不存在或无权查看', 404, 404);
        }
        return self::formatBundle($bundle, false);
    }

    public static function showForManager(int $adminId, int $appId, int $bundleId): array
    {
        $bundle = Database::one(
            'SELECT * FROM message_forward_bundles WHERE id = ? AND admin_id = ? AND app_id = ?',
            [$bundleId, $adminId, $appId]
        );
        if ($bundle === null) throw new HttpException('聊天记录快照不存在或不在当前管理范围', 404, 404);
        return self::formatBundle($bundle, true);
    }

    private static function formatBundle(array $bundle, bool $auditView): array
    {
        $anonymityMode = self::anonymityMode((string) ($bundle['anonymity_mode'] ?? 'none'));
        $snapshot = $auditView && trim((string) ($bundle['audit_snapshot_json'] ?? '')) !== ''
            ? (string) $bundle['audit_snapshot_json']
            : (string) $bundle['snapshot_json'];
        $items = json_decode($snapshot, true);
        if (!is_array($items)) $items = [];
        foreach ($items as $index => &$item) {
            if (!is_array($item)) { $item = []; continue; }
            if ($auditView) {
                self::recoverAuditIdentity($item, $bundle);
            } else {
                MessagePresentationService::decorate($item, (string) $bundle['source_type']);
            }
            $item['id'] = (int) ($item['id'] ?? $item['source_message_id'] ?? ($index + 1));
            $item['snapshot_sequence'] = (int) ($item['snapshot_sequence'] ?? ($index + 1));
            $item['snapshot_version'] = (int) ($item['snapshot_version'] ?? 1);
            $item['snapshot_read_only'] = true;
            $item['attachments'] = array_values(is_array($item['attachments'] ?? null) ? $item['attachments'] : []);
            if (!is_array($item['tags'] ?? null)) $item['tags'] = self::decodeArray($item['tags_json'] ?? null);
            if (!is_array($item['forward_bundle'] ?? null)) $item['forward_bundle'] = [];
            if (!$auditView && $anonymityMode !== 'none') self::hideSourceContext($item, false);
        }
        unset($item);
        $sourceContext = json_decode((string) ($bundle['source_context_json'] ?? ''), true);
        if (!is_array($sourceContext)) {
            $sourceContext = self::sourceContext(
                (int) $bundle['admin_id'], (int) $bundle['app_id'],
                (string) $bundle['source_type'], (int) $bundle['source_id']
            );
        }
        $hideContext = !$auditView && $anonymityMode !== 'none';
        if ($hideContext) $sourceContext = ['hidden' => true, 'display_name' => '匿名会话'];
        $forwarder = $hideContext
            ? ['user_id' => 0, 'name' => '匿名转发者', 'hidden' => true]
            : self::forwarder((int) $bundle['app_id'], (int) $bundle['creator_user_id']);
        return [
            'id' => (int) $bundle['id'],
            'title' => $auditView ? self::title($items, 'none') : (string) $bundle['title'],
            'item_count' => (int) $bundle['item_count'],
            'source_type' => $hideContext ? 'anonymous' : (string) $bundle['source_type'],
            'source_id' => $hideContext ? 0 : (int) $bundle['source_id'],
            'source_context' => $sourceContext,
            'source_context_hidden' => $hideContext,
            'created_at' => (string) $bundle['created_at'],
            'creator_user_id' => (int) $bundle['creator_user_id'],
            'forwarded_by' => $forwarder,
            'anonymity_mode' => $anonymityMode,
            'anonymity_scope' => 'current_bundle',
            'identity_view' => $auditView ? 'real' : ($anonymityMode === 'none' ? 'real' : 'anonymous'),
            'audit_identity_visible' => $auditView,
            'read_only' => true, 'permissions' => ['search' => true, 'copy' => true, 'create' => false, 'update' => false, 'delete' => false],
            'items' => array_values($items),
        ];
    }

    private static function canView(array $user, array $bundle): bool
    {
        if ((int) $bundle['creator_user_id'] === (int) $user['id']) return true;
        $links = Database::all('SELECT target_type, target_id FROM message_forward_links WHERE bundle_id = ?', [(int) $bundle['id']]);
        foreach ($links as $link) {
            $targetId = (int) $link['target_id'];
            if ($link['target_type'] === 'private_message' && Database::one(
                'SELECT m.id FROM messages m INNER JOIN conversations c ON c.id = m.conversation_id
                 WHERE m.id = ? AND m.app_id = ? AND (c.user_a_id = ? OR c.user_b_id = ?)',
                [$targetId, (int) $user['app_id'], (int) $user['id'], (int) $user['id']]
            )) return true;
            if ($link['target_type'] === 'group_message' && Database::one(
                'SELECT message.id FROM chat_room_messages message INNER JOIN chat_room_members member ON member.room_id = message.room_id
                 WHERE message.id = ? AND message.app_id = ? AND member.user_id = ?',
                [$targetId, (int) $user['app_id'], (int) $user['id']]
            )) return true;
            if ($link['target_type'] === 'service_message' && Database::one(
                'SELECT message.id FROM service_messages message INNER JOIN service_sessions session ON session.id = message.session_id
                 WHERE message.id = ? AND message.app_id = ? AND session.user_id = ?',
                [$targetId, (int) $user['app_id'], (int) $user['id']]
            )) return true;
            if ($link['target_type'] === 'forum_comment' && Database::one(
                'SELECT id FROM forum_comments WHERE id = ? AND app_id = ? AND status = 1',
                [$targetId, (int) $user['app_id']]
            )) return true;
            if ($link['target_type'] === 'forum_post' && Database::one(
                'SELECT id FROM forum_posts WHERE id = ? AND app_id = ? AND status = 1 AND deleted_at IS NULL',
                [$targetId, (int) $user['app_id']]
            )) return true;
        }
        return false;
    }

    private static function title(array $items, string $anonymityMode = 'none'): string
    {
        if ($anonymityMode !== 'none') return '匿名聊天记录 · ' . count($items) . ' 条';
        $names = [];
        foreach ($items as $item) {
            $name = trim((string) ($item['sender_name'] ?? ''));
            if ($name !== '' && !in_array($name, $names, true)) $names[] = $name;
            if (count($names) >= 2) break;
        }
        return ($names === [] ? '聊天记录' : implode('、', $names) . '的聊天记录') . ' · ' . count($items) . ' 条';
    }

    private static function senderLabel(string $type): string
    {
        return match ($type) {
            'admin' => '管理员', 'platform' => '平台', 'system' => '系统', default => '用户',
        };
    }

    private static function anonymityMode(string $value): string
    {
        $mode = strtolower(trim($value));
        return in_array($mode, ['none', 'selected', 'full'], true) ? $mode : 'none';
    }

    private static function senderKey(array $item): string
    {
        $type = trim((string) ($item['sender_type'] ?? 'user')) ?: 'user';
        $id = (int) ($item['sender_id'] ?? $item['user_id'] ?? 0);
        if ($id > 0) return $type . ':' . $id;
        $name = trim((string) ($item['sender_name'] ?? $item['sender_display_name'] ?? ''));
        return $type . ':name:' . mb_strtolower($name === '' ? 'unknown' : $name);
    }

    private static function applyAnonymity(
        array &$item,
        string $mode,
        array $selectedKeys,
        array &$aliases
    ): void {
        if ($mode !== 'none') self::hideSourceContext($item, false);
        $senderType = (string) ($item['sender_type'] ?? 'user');
        $key = self::senderKey($item);
        $anonymous = $senderType === 'user'
            && ($mode === 'full' || ($mode === 'selected' && isset($selectedKeys[$key])));
        if ($anonymous) {
            if (!isset($aliases[$key])) $aliases[$key] = '默认用户' . (count($aliases) + 1);
            $alias = (string) $aliases[$key];
            $item['sender_id'] = 0;
            $item['sender_name'] = $alias;
            $item['sender_display_name'] = $alias;
            $item['sender_avatar'] = '';
            $item['sender_avatar_text'] = '默';
            $item['sender_badge'] = '匿名';
            $item['sender_badge_tone'] = 'neutral';
            $item['sender_role'] = 'anonymous';
            $item['anonymous'] = true;
            $item['anonymous_alias'] = $alias;
            $item['snapshot_mine'] = false;
        }
        // Anonymity applies only to items selected in this forwarding layer. Nested forwarded
        // snapshots keep the independent mode stored when they were created, including voice,
        // image, video, file and other media payloads.
    }

    public static function containsAnonymousSnapshot(array $rows): bool
    {
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $forward = $row['forward_bundle'] ?? null;
            if (!is_array($forward)) continue;
            if (self::anonymityMode((string) ($forward['anonymity_mode'] ?? 'none')) !== 'none') return true;
            if (is_array($forward['items'] ?? null) && self::containsAnonymousItem($forward['items'])) return true;
        }
        return false;
    }

    private static function containsAnonymousItem(array $items): bool
    {
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            if (($item['anonymous'] ?? false) === true) return true;
            $nested = $item['forward_bundle']['items'] ?? null;
            if (is_array($nested) && self::containsAnonymousItem($nested)) return true;
        }
        return false;
    }

    private static function embeddedForward(
        int $adminId,
        int $appId,
        int $bundleId,
        array $summary,
        array &$visited,
        int $depth,
        bool $auditView = false
    ): array {
        if ($bundleId <= 0) return $summary;
        if ($depth >= 8 || isset($visited[$bundleId])) {
            $summary['id'] = $bundleId;
            $summary['nested_unavailable'] = true;
            return $summary;
        }
        $bundle = Database::one(
            'SELECT id, admin_id, app_id, creator_user_id, title, item_count, source_type, source_id,
                    anonymity_mode, snapshot_json, audit_snapshot_json, source_context_json, created_at
             FROM message_forward_bundles WHERE id = ? AND admin_id = ? AND app_id = ?',
            [$bundleId, $adminId, $appId]
        );
        if ($bundle === null) return $summary;
        $visited[$bundleId] = true;
        $snapshot = $auditView && trim((string) ($bundle['audit_snapshot_json'] ?? '')) !== ''
            ? (string) $bundle['audit_snapshot_json']
            : (string) $bundle['snapshot_json'];
        $nestedItems = json_decode($snapshot, true);
        if (!is_array($nestedItems)) $nestedItems = [];
        foreach ($nestedItems as &$nested) {
            if (!is_array($nested)) { $nested = []; continue; }
            $nestedId = (int) ($nested['forward_bundle_id'] ?? 0);
            $nested['forward_bundle'] = self::embeddedForward(
                $adminId,
                $appId,
                $nestedId,
                is_array($nested['forward_bundle'] ?? null) ? $nested['forward_bundle'] : [],
                $visited,
                $depth + 1,
                $auditView
            );
            if ($auditView) self::recoverAuditIdentity($nested, $bundle);
            $nested['snapshot_read_only'] = true;
        }
        unset($nested);
        unset($visited[$bundleId]);
        $anonymityMode = self::anonymityMode((string) ($bundle['anonymity_mode'] ?? 'none'));
        if (!$auditView && $anonymityMode !== 'none') {
            foreach ($nestedItems as &$nested) {
                if (is_array($nested)) self::hideSourceContext($nested, false);
            }
            unset($nested);
        }
        $sourceContext = json_decode((string) ($bundle['source_context_json'] ?? ''), true);
        if (!is_array($sourceContext)) $sourceContext = [];
        $hideContext = !$auditView && $anonymityMode !== 'none';
        return [
            'id' => (int) $bundle['id'],
            'title' => $auditView ? self::title($nestedItems, 'none') : (string) $bundle['title'],
            'item_count' => (int) $bundle['item_count'],
            'source_type' => $hideContext ? 'anonymous' : (string) $bundle['source_type'],
            'source_id' => $hideContext ? 0 : (int) $bundle['source_id'],
            'source_context' => $hideContext ? ['hidden' => true, 'display_name' => '匿名会话'] : $sourceContext,
            'source_context_hidden' => $hideContext,
            'anonymity_mode' => $anonymityMode,
            'anonymity_scope' => 'current_bundle',
            'identity_view' => $auditView ? 'real' : ($anonymityMode === 'none' ? 'real' : 'anonymous'),
            'audit_identity_visible' => $auditView,
            'forwarded_by' => $hideContext
                ? ['user_id' => 0, 'name' => '匿名转发者', 'hidden' => true]
                : self::forwarder((int) $bundle['app_id'], (int) $bundle['creator_user_id']),
            'created_at' => (string) $bundle['created_at'],
            'embedded' => true,
            'read_only' => true,
            'items' => array_values($nestedItems),
        ];
    }

    private static function recoverAuditIdentity(array &$item, array $bundle): void
    {
        if (is_array($item['audit_actor'] ?? null)) {
            unset($item['anonymous'], $item['anonymous_alias'], $item['sender_avatar_text']);
            $item['audit_identity_visible'] = true;
            $item['audit_identity_complete'] = true;
            return;
        }
        $messageId = (int) ($item['source_message_id'] ?? $item['id'] ?? 0);
        $raw = self::sourceSenderRow(
            (int) $bundle['admin_id'],
            (int) $bundle['app_id'],
            (string) $bundle['source_type'],
            (int) $bundle['source_id'],
            $messageId
        );
        if ($raw !== null) {
            self::applyAuditIdentity(
                $item,
                $raw,
                (int) $bundle['admin_id'],
                (int) $bundle['app_id'],
                (string) $bundle['source_type']
            );
            return;
        }
        $item['audit_identity_visible'] = true;
        $item['audit_identity_complete'] = !((bool) ($item['anonymous'] ?? false));
    }

    private static function applyAuditIdentity(
        array &$item,
        array $raw,
        int $adminId,
        int $appId,
        string $sourceType
    ): void {
        unset($item['anonymous'], $item['anonymous_alias'], $item['sender_avatar_text']);
        $senderType = strtolower(trim((string) ($raw['sender_type'] ?? 'user'))) ?: 'user';
        $senderId = (int) ($raw['sender_id'] ?? $raw['user_id'] ?? 0);
        $actor = null;
        if ($senderType === 'user' && $senderId > 0) {
            $actor = self::actorIdentity('user', $senderId, $appId);
        } elseif (in_array($senderType, ['admin', 'platform'], true) && $senderId > 0) {
            $actor = self::actorIdentity($senderType, $senderId, $appId);
        } elseif ($senderType === 'system') {
            $actor = self::takeoverActor($adminId, $appId, $sourceType, (int) ($raw['id'] ?? $item['source_message_id'] ?? 0));
            if ($actor === null && $senderId > 0) $actor = self::actorIdentity('admin', $senderId, $appId);
        }

        if ($actor === null && $senderType === 'user') {
            $name = trim((string) ($raw['sender_name'] ?? $raw['nickname'] ?? $raw['account'] ?? '')) ?: '用户';
            $actor = [
                'actor_type' => 'user', 'actor_id' => $senderId, 'actor_level' => 4,
                'uid' => (string) ($raw['uid'] ?? ''), 'account' => (string) ($raw['account'] ?? ''),
                'name' => $name, 'avatar' => (string) ($raw['sender_avatar'] ?? $raw['avatar'] ?? ''),
                'role_label' => '用户',
            ];
        }
        if ($actor !== null) {
            $role = strtolower(trim((string) ($raw['role'] ?? '')));
            $badge = (string) ($actor['role_label'] ?? '实名');
            if (($actor['actor_type'] ?? '') === 'user' && $sourceType === 'group') {
                if ($role === 'owner') $badge = '群主';
                elseif ($role === 'admin') $badge = '版主';
                else $badge = '群成员';
            }
            $item['sender_type'] = (string) $actor['actor_type'];
            $item['sender_id'] = (int) $actor['actor_id'];
            $item['sender_name'] = (string) $actor['name'];
            $item['sender_display_name'] = (string) $actor['name'];
            $item['sender_avatar'] = (string) ($actor['avatar'] ?? '');
            $item['sender_badge'] = $badge;
            $item['sender_badge_tone'] = ($actor['actor_type'] ?? '') === 'user' ? 'neutral' : 'primary';
            $item['sender_role'] = (string) ($actor['actor_type'] ?? 'user');
            $item['audit_actor'] = $actor;
            $item['audit_identity_complete'] = true;
            if ($senderType === 'system' && ($actor['actor_type'] ?? '') !== 'system') {
                $item['public_sender_name'] = '系统消息';
                $item['actor_hidden_from_members'] = true;
            }
        } else {
            $item['sender_type'] = $senderType;
            $item['sender_id'] = $senderId;
            $item['sender_name'] = trim((string) ($raw['sender_name'] ?? $item['sender_name'] ?? '')) ?: self::senderLabel($senderType);
            $item['sender_display_name'] = (string) $item['sender_name'];
            $item['audit_identity_complete'] = $senderType === 'system';
        }
        $item['audit_identity_visible'] = true;
    }

    private static function takeoverActor(
        int $adminId,
        int $appId,
        string $sourceType,
        int $messageId
    ): ?array {
        if ($messageId <= 0) return null;
        $audit = Database::one(
            "SELECT actor_type, actor_id, actor_level
             FROM communication_takeover_audits
             WHERE admin_id = ? AND app_id = ? AND action = 'send'
               AND channel_type = ? AND message_id = ?
             ORDER BY id DESC LIMIT 1",
            [$adminId, $appId, $sourceType, $messageId]
        );
        if ($audit === null) return null;
        $actor = self::actorIdentity((string) $audit['actor_type'], (int) $audit['actor_id'], $appId);
        if ($actor !== null) $actor['actor_level'] = (int) $audit['actor_level'];
        return $actor;
    }

    private static function actorIdentity(string $type, int $id, int $appId): ?array
    {
        if ($id <= 0) return null;
        if ($type === 'user') {
            $row = Database::one(
                "SELECT u.id AS actor_id, u.uid, u.account,
                        COALESCE(NULLIF(p.nickname, ''), u.account, '用户') AS name,
                        COALESCE(p.avatar, '') AS avatar
                 FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
                 WHERE u.id = ? AND u.app_id = ?",
                [$id, $appId]
            );
            if ($row === null) return null;
            return $row + ['actor_type' => 'user', 'actor_level' => 4, 'role_label' => '用户'];
        }
        if ($type === 'platform') {
            $row = Database::one(
                "SELECT id AS actor_id, level AS actor_level, account,
                        COALESCE(NULLIF(nickname, ''), account, '平台') AS name,
                        COALESCE(avatar, '') AS avatar
                 FROM platform_accounts WHERE id = ? AND deleted_at IS NULL",
                [$id]
            );
            if ($row === null) return null;
            return $row + [
                'actor_type' => 'platform',
                'role_label' => ((int) $row['actor_level'] === 1 ? '1级总控平台' : '2级授权平台'),
            ];
        }
        if ($type === 'admin') {
            $row = Database::one(
                "SELECT a.id AS actor_id, a.account,
                        COALESCE(NULLIF(a.nickname, ''), a.account, '管理员') AS name,
                        COALESCE(a.avatar, '') AS avatar
                 FROM admins a INNER JOIN apps app ON app.admin_id = a.id
                 WHERE a.id = ? AND app.id = ?",
                [$id, $appId]
            );
            if ($row === null) return null;
            return $row + ['actor_type' => 'admin', 'actor_level' => 3, 'role_label' => '3级管理员'];
        }
        return null;
    }

    private static function sourceSenderRow(
        int $adminId,
        int $appId,
        string $sourceType,
        int $sourceId,
        int $messageId
    ): ?array {
        if ($messageId <= 0) return null;
        if ($sourceType === 'private') {
            return Database::one(
                "SELECT m.id, m.sender_type, m.sender_id, u.uid, u.account,
                        COALESCE(NULLIF(p.nickname, ''), u.account, '用户') AS sender_name,
                        COALESCE(p.avatar, '') AS sender_avatar
                 FROM messages m
                 LEFT JOIN users u ON m.sender_type = 'user' AND u.id = m.sender_id
                 LEFT JOIN user_profiles p ON p.user_id = u.id
                 WHERE m.id = ? AND m.conversation_id = ? AND m.admin_id = ? AND m.app_id = ?",
                [$messageId, $sourceId, $adminId, $appId]
            );
        }
        if ($sourceType === 'group') {
            return Database::one(
                "SELECT m.id, m.sender_type, COALESCE(m.user_id, m.sender_admin_id, 0) AS sender_id,
                        u.uid, u.account, COALESCE(NULLIF(p.nickname, ''), u.account, '群成员') AS sender_name,
                        COALESCE(p.avatar, '') AS sender_avatar, member.role
                 FROM chat_room_messages m
                 LEFT JOIN users u ON u.id = m.user_id
                 LEFT JOIN user_profiles p ON p.user_id = u.id
                 LEFT JOIN chat_room_members member ON member.room_id = m.room_id AND member.user_id = m.user_id
                 WHERE m.id = ? AND m.room_id = ? AND m.admin_id = ? AND m.app_id = ?",
                [$messageId, $sourceId, $adminId, $appId]
            );
        }
        if ($sourceType === 'service') {
            return Database::one(
                "SELECT m.id, m.sender_type, m.sender_id, u.uid, u.account,
                        CASE WHEN m.sender_type = 'user' THEN COALESCE(NULLIF(p.nickname, ''), u.account, '用户')
                             WHEN m.sender_type = 'admin' THEN COALESCE(NULLIF(a.nickname, ''), a.account, '客服')
                             ELSE '系统消息' END AS sender_name,
                        CASE WHEN m.sender_type = 'user' THEN COALESCE(p.avatar, '')
                             WHEN m.sender_type = 'admin' THEN COALESCE(a.avatar, '') ELSE '' END AS sender_avatar
                 FROM service_messages m
                 LEFT JOIN users u ON m.sender_type = 'user' AND u.id = m.sender_id
                 LEFT JOIN user_profiles p ON p.user_id = u.id
                 LEFT JOIN admins a ON m.sender_type = 'admin' AND a.id = m.sender_id
                 WHERE m.id = ? AND m.session_id = ? AND m.admin_id = ? AND m.app_id = ?",
                [$messageId, $sourceId, $adminId, $appId]
            );
        }
        return null;
    }

    private static function sourceContext(int $adminId, int $appId, string $sourceType, int $sourceId): array
    {
        if ($sourceType === 'group') {
            $room = Database::one(
                'SELECT id, name, icon FROM chat_rooms WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$sourceId, $adminId, $appId]
            );
            return [
                'type' => 'group', 'id' => $sourceId,
                'display_name' => (string) ($room['name'] ?? '群聊或聊天室'),
                'icon' => (string) ($room['icon'] ?? ''),
            ];
        }
        if ($sourceType === 'private') {
            $conversation = Database::one(
                'SELECT c.id, c.user_a_id, c.user_b_id,
                        ua.account AS account_a, ub.account AS account_b,
                        COALESCE(NULLIF(pa.nickname, \'\'), ua.account, \'用户A\') AS name_a,
                        COALESCE(NULLIF(pb.nickname, \'\'), ub.account, \'用户B\') AS name_b
                 FROM conversations c
                 INNER JOIN users ua ON ua.id = c.user_a_id
                 INNER JOIN users ub ON ub.id = c.user_b_id
                 LEFT JOIN user_profiles pa ON pa.user_id = ua.id
                 LEFT JOIN user_profiles pb ON pb.user_id = ub.id
                 WHERE c.id = ? AND c.admin_id = ? AND c.app_id = ?',
                [$sourceId, $adminId, $appId]
            );
            if ($conversation !== null) {
                return [
                    'type' => 'private', 'id' => $sourceId,
                    'display_name' => (string) $conversation['name_a'] . ' 与 ' . (string) $conversation['name_b'] . ' 的私聊',
                    'participants' => [
                        ['user_id' => (int) $conversation['user_a_id'], 'account' => (string) $conversation['account_a'], 'name' => (string) $conversation['name_a']],
                        ['user_id' => (int) $conversation['user_b_id'], 'account' => (string) $conversation['account_b'], 'name' => (string) $conversation['name_b']],
                    ],
                ];
            }
        }
        return [
            'type' => $sourceType,
            'id' => $sourceId,
            'display_name' => $sourceType === 'service' ? '在线客服会话' : '聊天会话',
        ];
    }

    private static function forwarder(int $appId, int $userId): array
    {
        $row = Database::one(
            'SELECT u.id AS user_id, u.uid, u.account,
                    COALESCE(NULLIF(p.nickname, \'\'), u.account, \'用户\') AS name,
                    COALESCE(p.avatar, \'\') AS avatar
             FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE u.id = ? AND u.app_id = ?',
            [$userId, $appId]
        );
        return $row === null ? ['user_id' => $userId, 'name' => '未知用户'] : $row;
    }

    private static function hideSourceContext(array &$value, bool $includeNestedForward = true): void
    {
        foreach ([
            'source_name', 'source_title', 'conversation_name', 'conversation_title',
            'group_name', 'room_name', 'chat_room_name', 'friend_name', 'peer_name',
            'target_name', 'session_name',
        ] as $field) {
            unset($value[$field]);
        }
        if (isset($value['source_type'])) $value['source_type'] = 'anonymous';
        if (isset($value['source_id'])) $value['source_id'] = 0;
        if (array_key_exists('source_context', $value)) {
            $value['source_context'] = ['hidden' => true, 'display_name' => '匿名会话'];
        }
        if (array_key_exists('forwarded_by', $value)) {
            $value['forwarded_by'] = ['user_id' => 0, 'name' => '匿名转发者', 'hidden' => true];
        }
        if (array_key_exists('creator_user_id', $value)) $value['creator_user_id'] = 0;
        if (array_key_exists('title', $value) && array_key_exists('item_count', $value)) {
            $value['title'] = '匿名聊天记录 · ' . max(0, (int) $value['item_count']) . ' 条';
        }
        $value['source_context_hidden'] = true;
        if ($includeNestedForward && is_array($value['forward_bundle'] ?? null)) {
            self::hideSourceContext($value['forward_bundle'], true);
        }
        if ($includeNestedForward && is_array($value['items'] ?? null)) {
            foreach ($value['items'] as &$item) {
                if (is_array($item)) self::hideSourceContext($item, true);
            }
            unset($item);
        }
    }

    private static function decodeArray($value): array
    {
        if (is_array($value)) return array_values($value);
        if (!is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}
