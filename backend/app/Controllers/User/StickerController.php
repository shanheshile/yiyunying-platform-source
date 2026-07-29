<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\User;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Validator;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;

final class StickerController
{
    public static function index(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $packs = Database::all(
            'SELECT * FROM sticker_packs WHERE admin_id = ? AND app_id = ? AND user_id = ? AND status = 1
             ORDER BY sort_order DESC, id ASC',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        $packIds = array_map(static fn(array $pack): int => (int) $pack['id'], $packs);
        $stickers = [];
        if ($packIds !== []) {
            $placeholders = implode(',', array_fill(0, count($packIds), '?'));
            $stickers = Database::all(
                "SELECT id, pack_id, upload_id, name, image_url, thumbnail_url, width, height, sort_order
                 FROM stickers WHERE pack_id IN ({$placeholders}) AND status = 1 ORDER BY sort_order DESC, id ASC",
                $packIds
            );
        }
        $grouped = [];
        foreach ($stickers as $sticker) {
            foreach (['id', 'pack_id', 'upload_id', 'width', 'height', 'sort_order'] as $key) {
                if ($sticker[$key] !== null) $sticker[$key] = (int) $sticker[$key];
            }
            $grouped[(int) $sticker['pack_id']][] = $sticker;
        }
        foreach ($packs as &$pack) {
            $pack['id'] = (int) $pack['id'];
            $pack['sticker_count'] = (int) $pack['sticker_count'];
            $pack['stickers'] = $grouped[(int) $pack['id']] ?? [];
        }
        unset($pack);
        return Response::success(['items' => $packs]);
    }

    public static function createPack(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $name = Validator::string($request->input('name', ''), 'name', 1, 100);
        $id = Database::insert(
            'INSERT INTO sticker_packs
             (admin_id, app_id, user_id, name, cover_url, sticker_count, sort_order, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, 1, NOW(), NOW())',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $name,
                mb_substr(trim((string) $request->input('cover_url', '')), 0, 1000),
                (int) $request->input('sort_order', 0),
            ]
        );
        LogService::userOperation($request, $user, 'sticker_pack', 'create', $id);
        return Response::success(['pack_id' => $id], '表情包分组已创建', 201);
    }

    public static function updatePack(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $pack = self::pack($user, (int) $params['pack_id']);
        Database::execute(
            'UPDATE sticker_packs SET name = ?, cover_url = ?, sort_order = ?, status = ?, updated_at = NOW() WHERE id = ?',
            [
                mb_substr(trim((string) $request->input('name', $pack['name'])), 0, 100),
                mb_substr(trim((string) $request->input('cover_url', $pack['cover_url'])), 0, 1000),
                (int) $request->input('sort_order', $pack['sort_order']),
                Validator::integer($request->input('status', $pack['status']), 'status', 0, 1),
                (int) $pack['id'],
            ]
        );
        return Response::success(['pack_id' => (int) $pack['id']], '表情包分组已更新');
    }

    public static function deletePack(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $pack = self::pack($user, (int) $params['pack_id']);
        Database::execute('DELETE FROM sticker_packs WHERE id = ?', [(int) $pack['id']]);
        return Response::success([], '表情包分组已删除');
    }

    public static function addSticker(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $pack = self::pack($user, (int) $params['pack_id']);
        $uploadId = max(0, (int) $request->input('upload_id', 0));
        $upload = null;
        if ($uploadId > 0) {
            $upload = Database::one(
                'SELECT * FROM uploads WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ? AND status = 1',
                [$uploadId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
            );
            if ($upload === null) throw new HttpException('上传图片不存在或无权使用', 404, 404);
            if (!str_starts_with(strtolower((string) $upload['mime_type']), 'image/')) {
                throw new HttpException('表情包只能使用图片文件', 0, 422);
            }
        }
        $url = trim((string) ($upload['file_url'] ?? $request->input('image_url', '')));
        if ($url === '' || preg_match('#^(https?://|/)#i', $url) !== 1) throw new HttpException('请上传图片或填写正确的图片地址', 0, 422);
        $id = Database::transaction(static function () use ($user, $pack, $request, $uploadId, $url): int {
            $id = Database::insert(
                'INSERT INTO stickers
                 (admin_id, app_id, pack_id, user_id, upload_id, name, image_url, thumbnail_url,
                  width, height, sort_order, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $pack['id'], (int) $user['id'],
                    $uploadId > 0 ? $uploadId : null, mb_substr(trim((string) $request->input('name', '')), 0, 100),
                    mb_substr($url, 0, 1000), mb_substr(trim((string) $request->input('thumbnail_url', '')), 0, 1000),
                    max(0, (int) $request->input('width', 0)), max(0, (int) $request->input('height', 0)),
                    (int) $request->input('sort_order', 0),
                ]
            );
            Database::execute(
                'UPDATE sticker_packs SET sticker_count = sticker_count + 1,
                 cover_url = IF(cover_url = ?, ?, cover_url), updated_at = NOW() WHERE id = ?',
                ['', mb_substr($url, 0, 1000), (int) $pack['id']]
            );
            return $id;
        });
        LogService::userOperation($request, $user, 'sticker', 'create', $id, ['pack_id' => (int) $pack['id']]);
        return Response::success(['sticker_id' => $id, 'image_url' => $url], '表情已添加', 201);
    }

    public static function deleteSticker(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $pack = self::pack($user, (int) $params['pack_id']);
        $sticker = Database::one('SELECT id FROM stickers WHERE id = ? AND pack_id = ? AND user_id = ?', [
            (int) $params['sticker_id'], (int) $pack['id'], (int) $user['id'],
        ]);
        if ($sticker === null) throw new HttpException('表情不存在', 404, 404);
        Database::transaction(static function () use ($sticker, $pack): void {
            Database::execute('DELETE FROM stickers WHERE id = ?', [(int) $sticker['id']]);
            Database::execute('UPDATE sticker_packs SET sticker_count = GREATEST(0, sticker_count - 1), updated_at = NOW() WHERE id = ?', [(int) $pack['id']]);
        });
        return Response::success([], '表情已删除');
    }

    public static function batchAdd(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $pack = self::pack($user, (int) $params['pack_id']);
        $items = $request->input('items', []);
        if (!is_array($items) || $items === [] || count($items) > 100) {
            throw new HttpException('items 必须是 1-100 项的表情数组', 0, 422);
        }
        $result = Database::transaction(static function () use ($user, $pack, $items): array {
            $created = [];
            $skipped = [];
            foreach (array_values($items) as $index => $item) {
                if (!is_array($item)) throw new HttpException('第 ' . ($index + 1) . ' 项表情格式无效', 0, 422);
                $payload = self::stickerPayload($user, $item);
                $existing = Database::one('SELECT id FROM stickers WHERE pack_id = ? AND image_url = ?', [(int) $pack['id'], $payload['url']]);
                if ($existing !== null) {
                    $skipped[] = ['index' => $index, 'reason' => '相同表情已存在', 'sticker_id' => (int) $existing['id']];
                    continue;
                }
                $created[] = self::insertSticker($user, $pack, $payload);
            }
            self::refreshPack((int) $pack['id']);
            return ['created' => $created, 'skipped' => $skipped];
        });
        LogService::userOperation($request, $user, 'sticker', 'batch_create', null, [
            'pack_id' => (int) $pack['id'], 'created_count' => count($result['created']), 'skipped_count' => count($result['skipped']),
        ]);
        return Response::success($result + [
            'created_count' => count($result['created']), 'skipped_count' => count($result['skipped']),
        ], '表情已批量添加', 201);
    }

    public static function batchDelete(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $pack = self::pack($user, (int) $params['pack_id']);
        $ids = $request->input('sticker_ids', []);
        if (is_string($ids)) $ids = array_filter(array_map('trim', explode(',', $ids)));
        if (!is_array($ids)) throw new HttpException('sticker_ids 必须是数组或逗号分隔编号', 0, 422);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === [] || count($ids) > 200) throw new HttpException('单次必须选择 1-200 个表情', 0, 422);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $deleted = Database::transaction(static function () use ($pack, $user, $ids, $placeholders): int {
            $count = Database::execute(
                "DELETE FROM stickers WHERE pack_id = ? AND user_id = ? AND id IN ({$placeholders})",
                array_merge([(int) $pack['id'], (int) $user['id']], $ids)
            );
            self::refreshPack((int) $pack['id']);
            return $count;
        });
        LogService::userOperation($request, $user, 'sticker', 'batch_delete', null, [
            'pack_id' => (int) $pack['id'], 'requested_count' => count($ids), 'deleted_count' => $deleted,
        ]);
        return Response::success(['deleted_count' => $deleted], '所选表情已删除');
    }

    private static function user(Request $request): array
    {
        $user = AuthService::user($request);
        AppService::requireFeature((int) $user['app_id'], 'messages');
        AuthService::ensureNotBanned($user, ['all', 'message', 'upload']);
        return $user;
    }

    private static function pack(array $user, int $packId): array
    {
        $pack = Database::one(
            'SELECT * FROM sticker_packs WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ?',
            [$packId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        if ($pack === null) throw new HttpException('表情包分组不存在', 404, 404);
        return $pack;
    }

    private static function stickerPayload(array $user, array $item): array
    {
        $uploadId = max(0, (int) ($item['upload_id'] ?? 0));
        $upload = null;
        if ($uploadId > 0) {
            $upload = Database::one(
                'SELECT * FROM uploads WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ? AND status = 1',
                [$uploadId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
            );
            if ($upload === null) throw new HttpException('上传图片不存在或无权使用', 404, 404);
            if (!str_starts_with(strtolower((string) $upload['mime_type']), 'image/')) throw new HttpException('表情包只能使用图片文件', 0, 422);
        }
        $url = trim((string) ($upload['file_url'] ?? ($item['image_url'] ?? '')));
        if ($url === '' || preg_match('#^(https?://|/)#i', $url) !== 1) throw new HttpException('请上传图片或填写正确的图片地址', 0, 422);
        return [
            'upload_id' => $uploadId > 0 ? $uploadId : null,
            'url' => mb_substr($url, 0, 1000),
            'name' => mb_substr(trim((string) ($item['name'] ?? '')), 0, 100),
            'thumbnail_url' => mb_substr(trim((string) ($item['thumbnail_url'] ?? ($upload['thumbnail_url'] ?? ''))), 0, 1000),
            'width' => max(0, (int) ($item['width'] ?? 0)), 'height' => max(0, (int) ($item['height'] ?? 0)),
            'sort_order' => (int) ($item['sort_order'] ?? 0),
        ];
    }

    private static function insertSticker(array $user, array $pack, array $payload): array
    {
        $id = Database::insert(
            'INSERT INTO stickers
             (admin_id, app_id, pack_id, user_id, upload_id, name, image_url, thumbnail_url,
              width, height, sort_order, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $pack['id'], (int) $user['id'],
                $payload['upload_id'], $payload['name'], $payload['url'], $payload['thumbnail_url'],
                $payload['width'], $payload['height'], $payload['sort_order'],
            ]
        );
        return ['sticker_id' => $id, 'image_url' => $payload['url']];
    }

    private static function refreshPack(int $packId): void
    {
        $cover = Database::one('SELECT image_url FROM stickers WHERE pack_id = ? AND status = 1 ORDER BY sort_order DESC, id LIMIT 1', [$packId]);
        Database::execute(
            'UPDATE sticker_packs SET sticker_count = (SELECT COUNT(*) FROM stickers WHERE pack_id = ? AND status = 1),
             cover_url = ?, updated_at = NOW() WHERE id = ?',
            [$packId, (string) ($cover['image_url'] ?? ''), $packId]
        );
    }
}
