<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class CloudSyncService
{
    private const TYPES = [
        'chat' => ['label' => '聊天记录', 'prefix' => 'cloud_chat_backup'],
        'stickers' => ['label' => '表情包', 'prefix' => 'cloud_sticker_sync'],
        'favorites' => ['label' => '收藏', 'prefix' => 'cloud_favorite_sync'],
    ];

    public static function policy(array $user): array
    {
        $wallet = Database::one(
            'SELECT balance, vip_expired_at FROM user_wallets WHERE admin_id = ? AND app_id = ? AND user_id = ?',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        ) ?? ['balance' => 0, 'vip_expired_at' => null];
        $vip = $wallet['vip_expired_at'] !== null && strtotime((string) $wallet['vip_expired_at']) > time();
        $items = [];
        foreach (self::TYPES as $type => $definition) {
            $prefix = $definition['prefix'];
            $enabled = (bool) AppService::setting((int) $user['app_id'], $prefix . '_enabled', true);
            $vipRequired = (bool) AppService::setting((int) $user['app_id'], $prefix . '_vip_required', false);
            $price = max(0.0, (float) AppService::setting((int) $user['app_id'], $prefix . '_price', 0));
            $items[$type] = [
                'data_type' => $type, 'label' => $definition['label'], 'enabled' => $enabled,
                'vip_required' => $vipRequired, 'price_balance' => $price,
                'available' => $enabled && (!$vipRequired || $vip) && (float) $wallet['balance'] >= $price,
                'unavailable_reason' => !$enabled ? '管理员已关闭此云同步功能'
                    : ($vipRequired && !$vip ? '需要有效会员'
                        : ((float) $wallet['balance'] < $price ? '余额不足' : '')),
            ];
        }
        $allCacheCategories = ['chat_record', 'profile', 'image', 'video', 'voice', 'audio', 'document', 'file', 'sticker'];
        $configuredCategories = AppService::setting((int) $user['app_id'], 'auto_cache_allowed_categories', $allCacheCategories);
        $cacheCategories = is_array($configuredCategories)
            ? array_values(array_intersect($allCacheCategories, array_map('strval', $configuredCategories)))
            : $allCacheCategories;
        if ($cacheCategories === []) $cacheCategories = $allCacheCategories;
        $cacheNetwork = strtolower(trim((string) AppService::setting((int) $user['app_id'], 'auto_cache_network', 'wifi_mobile')));
        if (!in_array($cacheNetwork, ['wifi', 'wifi_mobile', 'never'], true)) $cacheNetwork = 'wifi_mobile';
        $forceWifi = (bool) AppService::setting((int) $user['app_id'], 'auto_cache_force_wifi_only', false);
        if ($forceWifi && $cacheNetwork !== 'never') $cacheNetwork = 'wifi';
        $videoNetwork = strtolower(trim((string) AppService::setting((int) $user['app_id'], 'video_autoplay_network', 'wifi_mobile')));
        if (!in_array($videoNetwork, ['wifi', 'wifi_mobile', 'never'], true)) $videoNetwork = 'wifi_mobile';
        $videoDefaultNetwork = strtolower(trim((string) AppService::setting((int) $user['app_id'], 'video_autoplay_default_network', 'wifi')));
        if (!in_array($videoDefaultNetwork, ['wifi', 'wifi_mobile', 'never'], true)) $videoDefaultNetwork = 'wifi';
        return [
            'items' => $items, 'is_vip' => $vip, 'vip_expired_at' => $wallet['vip_expired_at'],
            'balance' => (float) $wallet['balance'],
            'max_items_per_snapshot' => max(1, (int) AppService::setting((int) $user['app_id'], 'cloud_backup_max_items', 5000)),
            'retention_days' => max(0, (int) AppService::setting((int) $user['app_id'], 'cloud_backup_retention_days', 3650)),
            'local_cache_days' => max(0, (int) AppService::setting((int) $user['app_id'], 'chat_local_cache_days', 90)),
            'media_cache_max_bytes' => max(0, (int) AppService::setting((int) $user['app_id'], 'media_cache_max_bytes', 536870912)),
            'auto_cache_policy' => [
                'enabled' => (bool) AppService::setting((int) $user['app_id'], 'auto_download_cache_enabled', true),
                'allowed_categories' => $cacheCategories,
                'default_max_bytes' => max(67108864, (int) AppService::setting((int) $user['app_id'], 'auto_cache_default_max_bytes', 536870912)),
                'max_bytes_limit' => max(67108864, (int) AppService::setting((int) $user['app_id'], 'auto_cache_max_bytes_limit', 2147483648)),
                'retention_days' => max(0, (int) AppService::setting((int) $user['app_id'], 'auto_cache_retention_days', 90)),
                'network' => $cacheNetwork,
                'force_wifi_only' => $forceWifi,
                'policy_version' => (string) AppService::setting((int) $user['app_id'], 'auto_cache_policy_version', '2026.08.01'),
            ],
            'video_autoplay_policy' => [
                'enabled' => (bool) AppService::setting((int) $user['app_id'], 'video_autoplay_enabled', true),
                'network' => $videoNetwork,
                'default_network' => $videoDefaultNetwork,
            ],
        ];
    }

    public static function create(array $user, array $data): array
    {
        $type = self::type((string) ($data['data_type'] ?? 'chat'));
        $access = self::requireAccess($user, $type);
        $maxItems = max(1, min(5000, (int) AppService::setting((int) $user['app_id'], 'cloud_backup_max_items', 5000)));
        $scope = strtolower(trim((string) ($data['scope_type'] ?? '')));
        $targetId = max(0, (int) ($data['target_id'] ?? 0));
        $filters = is_array($data['filters'] ?? null) ? $data['filters'] : $data;
        if ($type === 'chat') {
            if ($scope === '' || $targetId <= 0) throw new HttpException('聊天备份必须选择会话类型和会话编号', 0, 422);
            $items = ChatRecordService::records($user, $scope, $targetId, $filters, $maxItems);
            $payload = ['schema_version' => 1, 'data_type' => 'chat', 'scope_type' => $scope, 'target_id' => $targetId, 'items' => $items];
            $defaultTitle = self::TYPES[$type]['label'] . '备份 ' . date('Y-m-d H:i');
        } elseif ($type === 'stickers') {
            $items = self::stickerItems($user, $maxItems);
            $payload = ['schema_version' => 1, 'data_type' => 'stickers', 'packs' => $items];
            $defaultTitle = '表情包备份 ' . date('Y-m-d H:i');
        } else {
            $items = self::favoriteItems($user, $maxItems);
            $payload = ['schema_version' => 1, 'data_type' => 'favorites', 'items' => $items];
            $defaultTitle = '收藏备份 ' . date('Y-m-d H:i');
        }
        $itemCount = $type === 'stickers'
            ? array_sum(array_map(static fn(array $pack): int => count($pack['stickers'] ?? []), $items))
            : count($items);
        if ($itemCount === 0) throw new HttpException('当前条件下没有可备份的内容', 0, 422);
        $snapshot = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($snapshot)) throw new HttpException('生成云备份数据失败', -1, 500);
        $title = mb_substr(trim((string) ($data['title'] ?? '')) ?: $defaultTitle, 0, 255);
        $normalizedFilters = $type === 'chat' ? ChatRecordService::normalizeFilters($filters) : [];
        $filterJson = json_encode($normalizedFilters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $id = Database::transaction(static function () use (
            $user, $type, $scope, $targetId, $title, $itemCount, $normalizedFilters, $filterJson, $snapshot, $access
        ): int {
            if ($access['price'] > 0) {
                WalletService::adjust($user, 'balance', -$access['price'], 'cloud_sync_snapshot', 'cloud_sync', null, self::TYPES[$type]['label'] . '云同步');
            }
            return Database::insert(
                'INSERT INTO cloud_sync_snapshots
                 (admin_id, app_id, user_id, data_type, scope_type, target_id, title, item_count,
                  date_from, date_to, filter_json, snapshot_json, size_bytes, charged_balance, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $type,
                    $scope, $targetId, $title, $itemCount, $normalizedFilters['date_from'] ?? null,
                    $normalizedFilters['date_to'] ?? null, $filterJson, $snapshot, strlen($snapshot), $access['price'],
                ]
            );
        });
        return [
            'snapshot_id' => $id, 'data_type' => $type, 'title' => $title, 'item_count' => $itemCount,
            'size_bytes' => strlen($snapshot), 'charged_balance' => $access['price'], 'read_only' => true,
        ];
    }

    public static function listing(array $user, string $type = ''): array
    {
        $where = ['admin_id = ?', 'app_id = ?', 'user_id = ?', 'status = 1'];
        $query = [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']];
        if (trim($type) !== '') { $where[] = 'data_type = ?'; $query[] = self::type($type); }
        $retention = max(0, (int) AppService::setting((int) $user['app_id'], 'cloud_backup_retention_days', 3650));
        if ($retention > 0) { $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)'; $query[] = $retention; }
        return Database::all(
            'SELECT id, data_type, scope_type, target_id, title, item_count, date_from, date_to,
                    size_bytes, charged_balance, created_at, updated_at
             FROM cloud_sync_snapshots WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 200',
            $query
        );
    }

    public static function show(array $user, int $snapshotId): array
    {
        $row = self::owned($user, $snapshotId);
        $payload = json_decode((string) $row['snapshot_json'], true);
        if (!is_array($payload)) throw new HttpException('云备份内容损坏，请删除后重新备份', -1, 500);
        unset($row['snapshot_json']);
        $row['snapshot_id'] = (int) $row['id'];
        unset($row['id']);
        return ['meta' => $row, 'snapshot' => $payload, 'read_only' => true, 'can_search_and_copy' => true];
    }

    public static function restore(array $user, int $snapshotId): array
    {
        $shown = self::show($user, $snapshotId);
        $payload = $shown['snapshot'];
        $type = self::type((string) ($payload['data_type'] ?? 'chat'));
        self::requireAccess($user, $type, false);
        if ($type === 'chat') {
            return $shown + ['restored_count' => 0, 'mode' => '只读拉取'];
        }
        $restored = Database::transaction(static function () use ($user, $type, $payload): int {
            return $type === 'stickers'
                ? self::restoreStickers($user, $payload['packs'] ?? [])
                : self::restoreFavorites($user, $payload['items'] ?? []);
        });
        return ['snapshot_id' => $snapshotId, 'data_type' => $type, 'restored_count' => $restored, 'mode' => '合并恢复'];
    }

    public static function delete(array $user, int $snapshotId): void
    {
        $row = self::owned($user, $snapshotId);
        Database::execute('UPDATE cloud_sync_snapshots SET status = 0, updated_at = NOW() WHERE id = ?', [(int) $row['id']]);
    }

    private static function stickerItems(array $user, int $limit): array
    {
        $packs = Database::all(
            'SELECT id, name, cover_url, sort_order FROM sticker_packs
             WHERE admin_id = ? AND app_id = ? AND user_id = ? AND status = 1 ORDER BY sort_order DESC, id',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        $remaining = $limit;
        foreach ($packs as &$pack) {
            $pack['stickers'] = $remaining > 0 ? Database::all(
                "SELECT name, image_url, thumbnail_url, width, height, sort_order FROM stickers
                 WHERE pack_id = ? AND user_id = ? AND status = 1 ORDER BY sort_order DESC, id LIMIT {$remaining}",
                [(int) $pack['id'], (int) $user['id']]
            ) : [];
            $remaining -= count($pack['stickers']);
        }
        unset($pack);
        return $packs;
    }

    private static function favoriteItems(array $user, int $limit): array
    {
        $items = [];
        foreach (Database::all(
            "SELECT 'content' AS favorite_kind, content_type, content_id, created_at
             FROM content_favorites WHERE admin_id = ? AND app_id = ? AND user_id = ? ORDER BY id DESC LIMIT {$limit}",
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        ) as $item) $items[] = $item;
        $remaining = $limit - count($items);
        if ($remaining > 0) foreach (Database::all(
            "SELECT 'private_message' AS favorite_kind, message_id AS content_id, updated_at AS created_at
             FROM message_user_states WHERE user_id = ? AND is_favorite = 1 ORDER BY id DESC LIMIT {$remaining}",
            [(int) $user['id']]
        ) as $item) $items[] = $item;
        $remaining = $limit - count($items);
        if ($remaining > 0) foreach (Database::all(
            "SELECT scope_type AS favorite_kind, message_id AS content_id, target_id, updated_at AS created_at
             FROM communication_message_states WHERE app_id = ? AND user_id = ? AND is_favorite = 1 ORDER BY id DESC LIMIT {$remaining}",
            [(int) $user['app_id'], (int) $user['id']]
        ) as $item) $items[] = $item;
        return $items;
    }

    private static function restoreStickers(array $user, array $packs): int
    {
        $count = 0;
        foreach ($packs as $pack) {
            if (!is_array($pack)) continue;
            $name = mb_substr(trim((string) ($pack['name'] ?? '云端表情')), 0, 100);
            if ($name === '') $name = '云端表情';
            $existing = Database::one('SELECT id FROM sticker_packs WHERE app_id = ? AND user_id = ? AND name = ?', [(int) $user['app_id'], (int) $user['id'], $name]);
            $packId = $existing === null ? Database::insert(
                'INSERT INTO sticker_packs (admin_id, app_id, user_id, name, cover_url, sticker_count, sort_order, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?, 1, NOW(), NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $name, mb_substr((string) ($pack['cover_url'] ?? ''), 0, 1000), (int) ($pack['sort_order'] ?? 0)]
            ) : (int) $existing['id'];
            foreach (($pack['stickers'] ?? []) as $sticker) {
                if (!is_array($sticker)) continue;
                $url = mb_substr(trim((string) ($sticker['image_url'] ?? '')), 0, 1000);
                if ($url === '') continue;
                $exists = Database::one('SELECT id FROM stickers WHERE pack_id = ? AND image_url = ?', [$packId, $url]);
                if ($exists !== null) continue;
                Database::execute(
                    'INSERT INTO stickers (admin_id, app_id, pack_id, user_id, upload_id, name, image_url, thumbnail_url, width, height, sort_order, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
                    [(int) $user['admin_id'], (int) $user['app_id'], $packId, (int) $user['id'], mb_substr((string) ($sticker['name'] ?? ''), 0, 100), $url, mb_substr((string) ($sticker['thumbnail_url'] ?? ''), 0, 1000), max(0, (int) ($sticker['width'] ?? 0)), max(0, (int) ($sticker['height'] ?? 0)), (int) ($sticker['sort_order'] ?? 0)]
                );
                $count++;
            }
            Database::execute('UPDATE sticker_packs SET sticker_count = (SELECT COUNT(*) FROM stickers WHERE pack_id = ?), updated_at = NOW() WHERE id = ?', [$packId, $packId]);
        }
        return $count;
    }

    private static function restoreFavorites(array $user, array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $kind = (string) ($item['favorite_kind'] ?? '');
            $contentId = max(0, (int) ($item['content_id'] ?? 0));
            if ($contentId <= 0) continue;
            if ($kind === 'content') {
                $type = mb_substr((string) ($item['content_type'] ?? ''), 0, 30);
                if ($type === '') continue;
                Database::execute(
                    'INSERT IGNORE INTO content_favorites (admin_id, app_id, user_id, content_type, content_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
                    [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $type, $contentId]
                );
            } elseif ($kind === 'private_message') {
                Database::execute(
                    'INSERT INTO message_user_states (message_id, user_id, is_deleted, is_favorite, created_at, updated_at)
                     VALUES (?, ?, 0, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE is_favorite = 1, updated_at = NOW()',
                    [$contentId, (int) $user['id']]
                );
            } elseif (in_array($kind, ['group', 'service'], true)) {
                Database::execute(
                    'INSERT INTO communication_message_states (admin_id, app_id, user_id, scope_type, target_id, message_id, is_deleted, is_favorite, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, 0, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE is_favorite = 1, updated_at = NOW()',
                    [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $kind, max(0, (int) ($item['target_id'] ?? 0)), $contentId]
                );
            } else continue;
            $count++;
        }
        return $count;
    }

    private static function requireAccess(array $user, string $type, bool $chargeCheck = true): array
    {
        $policy = self::policy($user)['items'][$type];
        if (!$policy['enabled']) throw new HttpException((string) $policy['unavailable_reason'], 403, 403);
        if ($policy['vip_required'] && !$policy['available'] && $policy['unavailable_reason'] === '需要有效会员') {
            throw new HttpException('此云同步功能仅限有效会员使用', 403, 403);
        }
        if ($chargeCheck && !$policy['available']) throw new HttpException((string) $policy['unavailable_reason'], 403, 403);
        return ['price' => $chargeCheck ? (float) $policy['price_balance'] : 0.0];
    }

    private static function owned(array $user, int $snapshotId): array
    {
        $row = Database::one(
            'SELECT * FROM cloud_sync_snapshots WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ? AND status = 1',
            [$snapshotId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        if ($row === null) throw new HttpException('云备份不存在或无权访问', 404, 404);
        return $row;
    }

    private static function type(string $type): string
    {
        $type = strtolower(trim($type));
        $type = match ($type) { 'sticker' => 'stickers', 'favorite' => 'favorites', default => $type };
        if (!isset(self::TYPES[$type])) throw new HttpException('data_type 仅支持 chat、stickers 或 favorites', 0, 422);
        return $type;
    }
}
