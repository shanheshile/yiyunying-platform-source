<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\User;

use Yiyunying\Core\Database;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;

final class NoticeController
{
    public static function index(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request); AppService::requireFeature((int) $user['app_id'], 'notices');
        $wallet = Database::one('SELECT level_code, vip_expired_at FROM user_wallets WHERE user_id = ?', [(int) $user['id']]) ?? [];
        $tags = array_merge(
            array_map('strval', array_column(Database::all('SELECT t.id FROM user_tag_relations r INNER JOIN user_tags t ON t.id = r.tag_id WHERE r.user_id = ?', [(int) $user['id']]), 'id')),
            array_map('strval', array_column(Database::all('SELECT t.name FROM user_tag_relations r INNER JOIN user_tags t ON t.id = r.tag_id WHERE r.user_id = ?', [(int) $user['id']]), 'name'))
        );
        $rows = Database::all(
            'SELECT id, title, content, type, is_popup, display_enabled, popup_frequency, audience_type,
                    audience_json, start_at, end_at, created_at, updated_at
             FROM notices WHERE admin_id = ? AND app_id = ? AND status = 1 AND display_enabled = 1
               AND deleted_at IS NULL AND (start_at IS NULL OR start_at <= NOW()) AND (end_at IS NULL OR end_at >= NOW())
             ORDER BY id DESC LIMIT 100',
            [(int) $user['admin_id'], (int) $user['app_id']]
        );
        $items = [];
        foreach ($rows as $row) {
            $audience = json_decode((string) ($row['audience_json'] ?? ''), true);
            $audience = is_array($audience) ? array_map('strval', $audience) : [];
            $visible = match ((string) $row['audience_type']) {
                'vip' => ($wallet['vip_expired_at'] ?? null) !== null && strtotime((string) $wallet['vip_expired_at']) > time(),
                'normal' => ($wallet['vip_expired_at'] ?? null) === null || strtotime((string) $wallet['vip_expired_at']) <= time(),
                'user_ids' => in_array((string) $user['id'], $audience, true),
                'levels' => in_array((string) ($wallet['level_code'] ?? 'normal'), $audience, true),
                'tags' => array_intersect($tags, $audience) !== [],
                default => true,
            };
            unset($row['audience_json']);
            if ($visible) $items[] = $row;
        }
        return Response::success(['items' => $items]);
    }
}
