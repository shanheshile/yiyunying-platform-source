<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;

final class OrderTrackingService
{
    public static function record(
        array $order,
        string $source,
        string $eventCode,
        string $title,
        string $detail = '',
        string $actorType = 'system',
        int $actorId = 0,
        array $metadata = []
    ): int {
        return Database::insert(
            'INSERT INTO shop_order_events
             (admin_id, app_id, user_id, order_source, order_id, order_no, event_code,
              title, detail, actor_type, actor_id, metadata_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                (int) $order['admin_id'],
                (int) $order['app_id'],
                (int) $order['user_id'],
                $source,
                (int) $order['id'],
                (string) $order['order_no'],
                mb_substr($eventCode, 0, 40),
                mb_substr($title, 0, 150),
                mb_substr($detail, 0, 500),
                mb_substr($actorType, 0, 20),
                max(0, $actorId),
                $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    public static function events(int $appId, string $orderNo): array
    {
        $items = Database::all(
            'SELECT id, order_source, order_id, order_no, event_code, title, detail,
                    actor_type, actor_id, metadata_json, created_at
             FROM shop_order_events WHERE app_id = ? AND order_no = ? ORDER BY id ASC',
            [$appId, $orderNo]
        );
        foreach ($items as &$item) {
            $metadata = json_decode((string) ($item['metadata_json'] ?? ''), true);
            $item['metadata'] = is_array($metadata) ? $metadata : [];
            unset($item['metadata_json']);
        }
        unset($item);
        return $items;
    }
}
