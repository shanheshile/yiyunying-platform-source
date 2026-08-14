<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class MessageMediaService
{
    private const MEDIA_TYPES = [
        'image', 'sticker', 'audio', 'video', 'file', 'favorite', 'moment_share',
        'red_packet', 'transfer', 'contact_card', 'gift', 'location',
    ];
    private const PUBLIC_MEDIA_TARGET_TYPES = [
        'forum_post', 'forum_comment', 'forum_section', 'moment', 'moment_comment',
        'resource', 'resource_comment', 'store_app', 'shop_goods_comment',
    ];
    private const MAX_ATTACHMENTS = 200;

    public static function userPayload(array $user, array $data): array
    {
        return self::payload(
            (int) $user['admin_id'],
            (int) $user['app_id'],
            (int) $user['id'],
            null,
            $data
        );
    }

    /**
     * Enforce app-level chat composer controls after attachment normalization.
     * Camera/album provenance comes only from the authenticated, persisted upload
     * record. Client attachment metadata is presentation data and is never an
     * authorization source.
     */
    public static function assertChatFeatures(int $appId, array $payload): void
    {
        $attachments = is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [];
        if ($attachments === []) return;
        $contactCardEnabled = AppService::featureEnabled($appId, 'chat_contact_card', true);
        $cameraEnabled = AppService::featureEnabled($appId, 'chat_camera', true);
        $albumEnabled = AppService::featureEnabled($appId, 'chat_album', true);
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) continue;
            $mediaType = strtolower(trim((string) ($attachment['media_type'] ?? '')));
            if ($mediaType === 'contact_card' && !$contactCardEnabled) {
                throw new HttpException('管理员已关闭聊天名片功能', 403, 403);
            }
        }
        if ($cameraEnabled && $albumEnabled) return;

        $uploadScenes = self::trustedUploadScenes($appId, (int) ($payload['admin_id'] ?? 0), $attachments);
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) continue;
            $uploadId = max(0, (int) ($attachment['upload_id'] ?? 0));
            $scene = $uploadScenes[$uploadId] ?? '';
            if ($scene === 'chat_camera' && !$cameraEnabled) {
                throw new HttpException('管理员已关闭聊天拍摄功能', 403, 403);
            }
            if ($scene === 'chat_album' && !$albumEnabled) {
                throw new HttpException('管理员已关闭聊天相册功能', 403, 403);
            }
        }
    }

    /** @return array<int, string> */
    private static function trustedUploadScenes(int $appId, int $adminId, array $attachments): array
    {
        $uploadIds = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) continue;
            $uploadId = max(0, (int) ($attachment['upload_id'] ?? 0));
            if ($uploadId > 0) $uploadIds[$uploadId] = true;
        }
        if ($uploadIds === []) return [];
        $ids = array_keys($uploadIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $where = "id IN ({$placeholders}) AND app_id = ? AND status = 1";
        $query = array_merge($ids, [$appId]);
        if ($adminId > 0) {
            $where .= ' AND admin_id = ?';
            $query[] = $adminId;
        }
        $rows = Database::all("SELECT id, scene FROM uploads WHERE {$where}", $query);
        $scenes = [];
        foreach ($rows as $row) {
            $scene = strtolower(trim((string) ($row['scene'] ?? '')));
            if (in_array($scene, ['chat_camera', 'chat_album'], true)) {
                $scenes[(int) $row['id']] = $scene;
            }
        }
        return $scenes;
    }

    public static function adminPayload(array $admin, int $appId, array $data): array
    {
        return self::payload((int) $admin['id'], $appId, null, (int) $admin['id'], $data);
    }

    public static function save(string $targetType, int $targetId, array $payload): void
    {
        self::assertPublicAttachmentTrust($targetType, $payload);
        Database::transaction(static function () use ($targetType, $targetId, $payload): void {
            self::lockAttachmentReferences($payload, $targetType);
            self::insertAttachments($targetType, $targetId, $payload);
        });
    }

    public static function replace(string $targetType, int $targetId, array $payload): void
    {
        self::assertPublicAttachmentTrust($targetType, $payload);
        Database::transaction(static function () use ($targetType, $targetId, $payload): void {
            // Lock uploads before touching reference rows. UploadLibraryService::remove
            // uses the same upload -> reference order, preventing a delete/write race.
            self::lockAttachmentReferences($payload, $targetType);
            Database::execute(
                'DELETE FROM media_attachments WHERE admin_id = ? AND app_id = ? AND target_type = ? AND target_id = ?',
                [(int) $payload['admin_id'], (int) $payload['app_id'], $targetType, $targetId]
            );
            self::insertAttachments($targetType, $targetId, $payload);
        });
    }

    private static function insertAttachments(string $targetType, int $targetId, array $payload): void
    {
        $sort = 0;
        $publicMedia = self::isPublicMediaTarget($targetType);
        foreach ($payload['attachments'] as $attachment) {
            if ($publicMedia && empty($attachment['upload_id']) && empty($attachment['sticker_id'])) {
                throw new HttpException('公开内容附件必须引用已验证上传或表情', 0, 422);
            }
            $fileName = $publicMedia
                ? self::publicFileName((string) $attachment['media_type'], $sort + 1)
                : (string) $attachment['file_name'];
            $metadata = is_array($attachment['metadata'] ?? null) ? $attachment['metadata'] : [];
            if ($publicMedia) $metadata = self::sanitizePublicMetadata($metadata);
            Database::insert(
                'INSERT INTO media_attachments
                 (admin_id, app_id, owner_user_id, owner_admin_id, target_type, target_id, media_type,
                  upload_id, sticker_id, url, thumbnail_url, file_name, mime_type, size_bytes,
                  width, height, duration_ms, sort_order, metadata_json, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    (int) $payload['admin_id'], (int) $payload['app_id'], $payload['owner_user_id'],
                    $payload['owner_admin_id'], $targetType, $targetId, (string) $attachment['media_type'],
                    $attachment['upload_id'], $attachment['sticker_id'], (string) $attachment['url'],
                    (string) $attachment['thumbnail_url'], $fileName,
                    (string) $attachment['mime_type'], (int) $attachment['size_bytes'],
                    (int) $attachment['width'], (int) $attachment['height'], (int) $attachment['duration_ms'],
                    $sort++, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
        }
    }

    private static function lockAttachmentReferences(array $payload, string $targetType): void
    {
        $uploadIds = [];
        $stickerIds = [];
        foreach ((array) ($payload['attachments'] ?? []) as $attachment) {
            if (!is_array($attachment)) continue;
            $uploadId = max(0, (int) ($attachment['upload_id'] ?? 0));
            $stickerId = max(0, (int) ($attachment['sticker_id'] ?? 0));
            if ($uploadId > 0) $uploadIds[$uploadId] = true;
            if ($stickerId > 0) $stickerIds[$stickerId] = true;
        }

        $uploadIds = array_keys($uploadIds);
        sort($uploadIds, SORT_NUMERIC);
        if ($uploadIds !== []) {
            $placeholders = implode(',', array_fill(0, count($uploadIds), '?'));
            $where = "id IN ({$placeholders}) AND admin_id = ? AND app_id = ? AND status = 1";
            $params = array_merge($uploadIds, [(int) $payload['admin_id'], (int) $payload['app_id']]);
            $ownerUserId = $payload['owner_user_id'] ?? null;
            if ($ownerUserId !== null) {
                $where .= ' AND user_id = ?';
                $params[] = (int) $ownerUserId;
            } else {
                $where .= ' AND user_id IS NULL';
            }
            if ($targetType === 'forum_section') {
                $where .= " AND scene = 'forum_section' AND file_path LIKE 'private/uploads/%'";
            } elseif (self::isPublicMediaTarget($targetType)) {
                $where .= " AND file_path LIKE 'uploads/%' AND COALESCE(file_url, '') <> ''";
            }
            $lockedUploads = Database::all(
                "SELECT id FROM uploads WHERE {$where} ORDER BY id FOR UPDATE",
                $params
            );
            if (count($lockedUploads) !== count($uploadIds)) {
                throw new HttpException('附件上传已失效、被删除或不属于当前发布者，请重新上传', 0, 409);
            }
        }

        $stickerIds = array_keys($stickerIds);
        sort($stickerIds, SORT_NUMERIC);
        if ($stickerIds === []) return;
        $ownerUserId = $payload['owner_user_id'] ?? null;
        if ($ownerUserId === null) {
            throw new HttpException('管理员不能引用用户私有表情包', 0, 422);
        }
        $placeholders = implode(',', array_fill(0, count($stickerIds), '?'));
        $lockedStickers = Database::all(
            "SELECT s.id AS sticker_reference_id, su.* FROM stickers s INNER JOIN sticker_packs p
               ON p.id = s.pack_id AND p.admin_id = s.admin_id AND p.app_id = s.app_id
             INNER JOIN uploads su
               ON su.id = s.upload_id AND su.admin_id = s.admin_id AND su.app_id = s.app_id
              AND su.user_id = s.user_id AND su.status = 1 AND su.file_path LIKE 'uploads/%'
             WHERE s.id IN ({$placeholders}) AND s.admin_id = ? AND s.app_id = ? AND s.user_id = ?
               AND s.status = 1 AND p.status = 1 ORDER BY s.id FOR UPDATE",
            array_merge($stickerIds, [
                (int) $payload['admin_id'], (int) $payload['app_id'], (int) $ownerUserId,
            ])
        );
        if (count($lockedStickers) !== count($stickerIds)) {
            throw new HttpException('表情已失效、被删除或不属于当前发布者，请重新选择', 0, 409);
        }
        $expectedBySticker = [];
        foreach ((array) ($payload['attachments'] ?? []) as $attachment) {
            if (!is_array($attachment)) continue;
            $stickerId = max(0, (int) ($attachment['sticker_id'] ?? 0));
            if ($stickerId > 0 && is_array($attachment['_sticker_upload_validation'] ?? null)) {
                $expectedBySticker[$stickerId] = $attachment['_sticker_upload_validation'];
            }
        }
        foreach ($lockedStickers as $lockedSticker) {
            $stickerId = (int) ($lockedSticker['sticker_reference_id'] ?? 0);
            $expected = $expectedBySticker[$stickerId] ?? null;
            if (!is_array($expected)) {
                throw new HttpException('表情缺少事务外内容完整性校验，请重新选择', 0, 409);
            }
            UploadStorageService::assertLockedPublicImageUpload($lockedSticker, $expected);
        }
    }

    public static function assertPrivateForumUploads(array $payload): void
    {
        $ids = [];
        foreach ((array) ($payload['attachments'] ?? []) as $attachment) {
            $uploadId = is_array($attachment) ? max(0, (int) ($attachment['upload_id'] ?? 0)) : 0;
            $stickerId = is_array($attachment) ? max(0, (int) ($attachment['sticker_id'] ?? 0)) : 0;
            if ($uploadId <= 0 || $stickerId > 0) {
                throw new HttpException('受保护章节仅允许使用论坛章节私有上传，不支持贴纸或公开媒体地址', 0, 422);
            }
            $ids[$uploadId] = true;
        }
        if ($ids === []) return;
        $values = array_keys($ids);
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $row = Database::one(
            "SELECT COUNT(DISTINCT id) AS total FROM uploads
             WHERE id IN ({$placeholders}) AND admin_id = ? AND app_id = ? AND status = 1
               AND scene = 'forum_section' AND file_path LIKE 'private/%'",
            array_merge($values, [(int) $payload['admin_id'], (int) $payload['app_id']])
        );
        if ((int) ($row['total'] ?? 0) !== count($values)) {
            throw new HttpException('受保护章节的附件必须通过论坛私有上传通道重新上传', 0, 422);
        }
    }

    public static function assertStoredPrivateForumAttachments(int $sectionId, int $appId): void
    {
        $unsafe = Database::one(
            "SELECT ma.id FROM media_attachments ma
             LEFT JOIN uploads up
               ON up.id = ma.upload_id AND up.admin_id = ma.admin_id AND up.app_id = ma.app_id
             WHERE ma.app_id = ? AND ma.target_type = 'forum_section' AND ma.target_id = ?
               AND (ma.sticker_id IS NOT NULL OR ma.upload_id IS NULL OR up.id IS NULL OR up.status <> 1
                    OR COALESCE(up.scene, '') <> 'forum_section' OR up.file_path NOT LIKE 'private/%')
             LIMIT 1",
            [$appId, $sectionId]
        );
        if ($unsafe !== null) {
            throw new HttpException('已有附件不在私有存储中，请重新上传后再启用解锁保护', 0, 422);
        }
    }

    public static function hydrate(array $items, string $targetType, ?int $appId = null): array
    {
        $ids = [];
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id > 0) $ids[$id] = true;
        }
        if ($ids === []) return $items;
        $values = array_keys($ids);
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $where = "ma.target_type = ? AND ma.target_id IN ({$placeholders})";
        $query = array_merge([$targetType], $values);
        if ($appId !== null) {
            $where .= ' AND ma.app_id = ?';
            $query[] = $appId;
        }
        $rows = Database::all(
            "SELECT ma.id, ma.admin_id, ma.app_id, ma.owner_user_id, ma.target_id, ma.media_type, ma.upload_id, ma.sticker_id, ma.url,
                     ma.thumbnail_url AS stored_thumbnail_url,
                     COALESCE(NULLIF(up.thumbnail_url, ''), ma.thumbnail_url) AS thumbnail_url,
                    ma.file_name, ma.mime_type, ma.size_bytes, ma.width, ma.height, ma.duration_ms,
                    ma.sort_order, ma.metadata_json,
                    COALESCE(up.sha256, '') AS upload_sha256,
                    COALESCE(NULLIF(up.original_file_url, ''), NULLIF(up.file_url, ''), ma.url) AS original_file_url,
                    COALESCE(NULLIF(up.optimized_file_url, ''), ma.url) AS optimized_file_url,
                    COALESCE(NULLIF(up.original_size_bytes, 0), ma.size_bytes) AS original_size_bytes,
                    COALESCE(up.is_animated, 0) AS is_animated,
                    COALESCE(up.upload_mode, 'original') AS upload_mode,
                     COALESCE(up.optimization_status, 'not_required') AS optimization_status,
                     COALESCE(up.file_path, '') AS upload_file_path,
                     up.id AS verified_upload_id, up.status AS upload_status,
                     COALESCE(up.file_url, '') AS canonical_upload_url,
                     COALESCE(up.thumbnail_url, '') AS canonical_upload_thumbnail_url,
                     COALESCE(up.original_file_url, '') AS canonical_original_file_url,
                     COALESCE(up.optimized_file_url, '') AS canonical_optimized_file_url,
                     s.id AS verified_sticker_id, sp.id AS verified_sticker_pack_id,
                     s.upload_id AS sticker_upload_id,
                     COALESCE(s.image_url, '') AS canonical_sticker_url,
                     COALESCE(s.thumbnail_url, '') AS canonical_sticker_thumbnail_url
             FROM media_attachments ma
             LEFT JOIN uploads up
               ON up.id = ma.upload_id AND up.admin_id = ma.admin_id AND up.app_id = ma.app_id
             LEFT JOIN stickers s
               ON s.id = ma.sticker_id AND s.admin_id = ma.admin_id AND s.app_id = ma.app_id
              AND s.user_id = ma.owner_user_id AND s.status = 1
             LEFT JOIN sticker_packs sp
               ON sp.id = s.pack_id AND sp.admin_id = s.admin_id AND sp.app_id = s.app_id AND sp.status = 1
             WHERE {$where} ORDER BY ma.target_id, ma.sort_order, ma.id",
            $query
        );
        $publicMedia = self::isPublicMediaTarget($targetType);
        $directUploadIds = [];
        $stickerUploadIds = [];
        foreach ($rows as $row) {
            $directId = max(0, (int) ($row['upload_id'] ?? 0));
            if ($publicMedia && $directId > 0) $directUploadIds[$directId] = true;
            $id = max(0, (int) ($row['sticker_upload_id'] ?? 0));
            if ($id > 0) $stickerUploadIds[$id] = true;
        }
        $directUploadRows = [];
        $directUploadValidations = [];
        if ($directUploadIds !== []) {
            $idsForUploads = array_keys($directUploadIds);
            sort($idsForUploads, SORT_NUMERIC);
            $uploadPlaceholders = implode(',', array_fill(0, count($idsForUploads), '?'));
            foreach (Database::all(
                "SELECT * FROM uploads WHERE id IN ({$uploadPlaceholders}) AND status = 1 ORDER BY id",
                $idsForUploads
            ) as $uploadRow) {
                $uploadId = (int) $uploadRow['id'];
                try {
                    $directUploadValidations[$uploadId] = $targetType === 'forum_section'
                        ? UploadStorageService::trustedMediaMetadata($uploadRow)
                        : UploadStorageService::validatedPublicUpload($uploadRow);
                    $directUploadRows[$uploadId] = $uploadRow;
                } catch (HttpException) {
                    // Physically invalid historical media is hidden.
                }
            }
        }
        $stickerUploadRows = [];
        $stickerUploadValidations = [];
        if ($stickerUploadIds !== []) {
            $idsForUploads = array_keys($stickerUploadIds);
            sort($idsForUploads, SORT_NUMERIC);
            $uploadPlaceholders = implode(',', array_fill(0, count($idsForUploads), '?'));
            foreach (Database::all(
                "SELECT * FROM uploads WHERE id IN ({$uploadPlaceholders}) AND status = 1 ORDER BY id",
                $idsForUploads
            ) as $uploadRow) {
                $uploadId = (int) $uploadRow['id'];
                try {
                    $stickerUploadValidations[$uploadId] = UploadStorageService::validatedPublicImageUpload($uploadRow);
                    $stickerUploadRows[$uploadId] = $uploadRow;
                } catch (HttpException) {
                    // Public history fails closed: invalid physical media stays
                    // in storage for maintenance but is not rendered.
                }
            }
        }
        $grouped = [];
        foreach ($rows as $row) {
            if ($publicMedia && !self::canonicalizePublicHydrationRow(
                $targetType,
                $row,
                $directUploadRows,
                $directUploadValidations,
                $stickerUploadRows,
                $stickerUploadValidations
            )) continue;
            $targetId = (int) $row['target_id'];
            unset($row['target_id']);
            $reviewJson = json_encode([
                'id' => (int) $row['id'],
                'media_type' => (string) $row['media_type'],
                'upload_id' => $row['upload_id'] === null ? null : (int) $row['upload_id'],
                'sticker_id' => $row['sticker_id'] === null ? null : (int) $row['sticker_id'],
                'url' => (string) $row['url'],
                'thumbnail_url' => (string) ($row['stored_thumbnail_url'] ?? ''),
                'file_name' => (string) $row['file_name'],
                'mime_type' => (string) $row['mime_type'],
                'size_bytes' => (int) $row['size_bytes'],
                'width' => (int) $row['width'],
                'height' => (int) $row['height'],
                'duration_ms' => (int) $row['duration_ms'],
                'sort_order' => (int) $row['sort_order'],
                'metadata_json' => (string) ($row['metadata_json'] ?? ''),
                'upload_sha256' => strtolower((string) ($row['upload_sha256'] ?? '')),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($reviewJson)) throw new \RuntimeException('Unable to build attachment review identity');
            $row['review_identity'] = hash('sha256', $reviewJson);
            $row['id'] = (int) $row['id'];
            $row['app_id'] = (int) $row['app_id'];
            $privatePath = ltrim(str_replace('\\', '/', (string) $row['upload_file_path']), '/');
            if (str_starts_with($privatePath, 'private/')) {
                $signedUrl = PrivateForumMediaService::signedUrl($row['id'], $row['app_id']);
                $row['url'] = $signedUrl;
                $row['thumbnail_url'] = $signedUrl;
                $row['original_file_url'] = $signedUrl;
                $row['optimized_file_url'] = $signedUrl;
            }
            unset(
                $row['upload_file_path'], $row['admin_id'], $row['owner_user_id'], $row['app_id'],
                $row['stored_thumbnail_url'], $row['upload_sha256'],
                $row['verified_upload_id'], $row['upload_status'], $row['canonical_upload_url'],
                $row['canonical_upload_thumbnail_url'], $row['canonical_original_file_url'],
                $row['canonical_optimized_file_url'], $row['verified_sticker_id'],
                $row['verified_sticker_pack_id'], $row['canonical_sticker_url'],
                $row['canonical_sticker_thumbnail_url'], $row['sticker_upload_id']
            );
            $row['upload_id'] = $row['upload_id'] === null ? null : (int) $row['upload_id'];
            $row['sticker_id'] = $row['sticker_id'] === null ? null : (int) $row['sticker_id'];
            foreach (['size_bytes', 'original_size_bytes', 'width', 'height', 'duration_ms', 'sort_order'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row['is_animated'] = (int) $row['is_animated'] === 1;
            $row['metadata'] = self::decodeObject($row['metadata_json'] ?? null);
            unset($row['metadata_json']);
            if ($publicMedia) {
                $row['file_name'] = self::publicFileName((string) $row['media_type'], (int) $row['sort_order'] + 1);
            }
            $grouped[$targetId][] = $row;
        }
        $grouped = self::hydrateCommerceMetadata($grouped, $appId);
        if ($publicMedia) {
            foreach ($grouped as &$attachments) {
                foreach ($attachments as &$attachment) {
                    $metadata = is_array($attachment['metadata'] ?? null) ? $attachment['metadata'] : [];
                    $attachment['metadata'] = self::sanitizePublicMetadata($metadata);
                }
                unset($attachment);
            }
            unset($attachments);
        }
        foreach ($items as &$item) {
            $attachments = $grouped[(int) ($item['id'] ?? 0)] ?? [];
            $item['attachments'] = $attachments;
            $item['attachment_count'] = count($attachments);
            $item['has_media'] = $attachments !== [];
            $item['media_summary'] = self::summary($attachments);
            if (!isset($item['content_type']) || (string) $item['content_type'] === '') {
                $item['content_type'] = count($attachments) > 1
                    ? 'mixed'
                    : (string) ($attachments[0]['media_type'] ?? 'text');
            }
        }
        unset($item);
        if ($appId !== null && $targetType === 'private_message') {
            return MessageEditService::hydrate($items, 'private', $appId);
        }
        if ($appId !== null && $targetType === 'group_message') {
            return MessageEditService::hydrate($items, 'group', $appId);
        }
        return $items;
    }

    /**
     * Existing URL-only rows are historical untrusted data. Public reads expose
     * only a live tenant-bound upload or sticker and always replace the snapshot
     * URL with the current canonical record URL.
     *
     * @param array<string,mixed> $row
     */
    private static function canonicalizePublicHydrationRow(
        string $targetType,
        array &$row,
        array $directUploadRows = [],
        array $directUploadValidations = [],
        array $stickerUploadRows = [],
        array $stickerUploadValidations = []
    ): bool
    {
        $uploadId = max(0, (int) ($row['upload_id'] ?? 0));
        $stickerId = max(0, (int) ($row['sticker_id'] ?? 0));
        if (($uploadId > 0) === ($stickerId > 0)) return false;
        if ($uploadId > 0) {
            $verifiedId = max(0, (int) ($row['verified_upload_id'] ?? 0));
            $liveUpload = $directUploadRows[$uploadId] ?? null;
            $metadata = $directUploadValidations[$uploadId] ?? null;
            $path = ltrim(str_replace('\\', '/', (string) ($liveUpload['file_path'] ?? '')), '/');
            $ownerMatches = is_array($liveUpload)
                && (int) ($liveUpload['admin_id'] ?? 0) === (int) ($row['admin_id'] ?? 0)
                && (int) ($liveUpload['app_id'] ?? 0) === (int) ($row['app_id'] ?? 0)
                && (($row['owner_user_id'] ?? null) === null
                    ? ($liveUpload['user_id'] ?? null) === null
                    : (int) ($liveUpload['user_id'] ?? 0) === (int) $row['owner_user_id']);
            if ($verifiedId !== $uploadId || (int) ($row['upload_status'] ?? 0) !== 1
                || !$ownerMatches || !is_array($metadata)) return false;
            $private = str_starts_with($path, 'private/uploads/');
            if ($private && $targetType !== 'forum_section') return false;
            if (!$private && (!str_starts_with($path, 'uploads/') || self::unsafeRelativePath($path))) return false;
            $baseUrl = rtrim((string) config('app.url'), '/');
            $url = $private ? '' : ($baseUrl === '' ? '' : $baseUrl . '/' . $path);
            if (!$private && $url === '') return false;
            $row['url'] = $url;
            // Historical URL columns may point at a retired host or an external
            // address. Public reads derive every display URL from the verified
            // current relative path instead of replaying those snapshots.
            $row['thumbnail_url'] = $url;
            $row['original_file_url'] = $url;
            $row['optimized_file_url'] = $url;
            $row['mime_type'] = (string) ($liveUpload['mime_type'] ?? '');
            $row['size_bytes'] = (int) ($liveUpload['size_bytes'] ?? 0);
            $row['width'] = (int) ($metadata['width'] ?? 0);
            $row['height'] = (int) ($metadata['height'] ?? 0);
            $row['duration_ms'] = (int) ($metadata['duration_ms'] ?? 0);
            $row['is_animated'] = (bool) ($metadata['is_animated'] ?? false) ? 1 : 0;
            $row['upload_sha256'] = strtolower((string) ($liveUpload['sha256'] ?? ''));
            return true;
        }
        $verifiedStickerId = max(0, (int) ($row['verified_sticker_id'] ?? 0));
        $verifiedPackId = max(0, (int) ($row['verified_sticker_pack_id'] ?? 0));
        $stickerUploadId = max(0, (int) ($row['sticker_upload_id'] ?? 0));
        $uploadRow = $stickerUploadRows[$stickerUploadId] ?? null;
        $validation = $stickerUploadValidations[$stickerUploadId] ?? null;
        if ($verifiedStickerId !== $stickerId || $verifiedPackId <= 0 || $stickerUploadId <= 0
            || !is_array($uploadRow) || !is_array($validation)
            || (int) ($uploadRow['admin_id'] ?? 0) !== (int) ($row['admin_id'] ?? 0)
            || (int) ($uploadRow['app_id'] ?? 0) !== (int) ($row['app_id'] ?? 0)
            || (int) ($uploadRow['user_id'] ?? 0) !== (int) ($row['owner_user_id'] ?? 0)) return false;
        $url = (string) $validation['file_url'];
        $row['url'] = $url;
        $row['thumbnail_url'] = (string) $validation['thumbnail_url'];
        $row['original_file_url'] = $url;
        $row['optimized_file_url'] = $url;
        $row['mime_type'] = (string) $validation['mime_type'];
        $row['size_bytes'] = (int) $validation['size_bytes'];
        $row['width'] = (int) $validation['width'];
        $row['height'] = (int) $validation['height'];
        $row['duration_ms'] = 0;
        $row['is_animated'] = (bool) $validation['is_animated'] ? 1 : 0;
        $row['upload_sha256'] = (string) $validation['sha256'];
        return true;
    }

    private static function unsafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) return true;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') return true;
        }
        return false;
    }

    /** @return list<array{upload_id:?int,sticker_id:?int,image_url:string,sort_order:int}> */
    public static function publicImageList(array $attachments): array
    {
        $images = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)
                || !in_array((string) ($attachment['media_type'] ?? ''), ['image', 'sticker'], true)) continue;
            $uploadId = max(0, (int) ($attachment['upload_id'] ?? 0));
            $stickerId = max(0, (int) ($attachment['sticker_id'] ?? 0));
            $url = trim((string) ($attachment['url'] ?? ''));
            if ($url === '' || ($uploadId > 0) === ($stickerId > 0)) continue;
            $images[] = [
                'upload_id' => $uploadId > 0 ? $uploadId : null,
                'sticker_id' => $stickerId > 0 ? $stickerId : null,
                'image_url' => $url,
                'sort_order' => (int) ($attachment['sort_order'] ?? count($images)),
            ];
        }
        return $images;
    }

    public static function attachments(string $targetType, int $targetId, int $appId): array
    {
        $items = self::hydrate([['id' => $targetId]], $targetType, $appId);
        return $items[0]['attachments'] ?? [];
    }

    public static function recordRecall(
        array $message,
        string $channelType,
        int $channelId,
        string $targetType,
        string $recalledByType,
        int $recalledById,
        string $reason = ''
    ): void {
        $appId = (int) $message['app_id'];
        $attachments = self::attachments($targetType, (int) $message['id'], $appId);
        Database::execute(
            'INSERT INTO message_recall_audits
             (admin_id, app_id, channel_type, channel_id, message_id, sender_type, sender_id,
              original_content_type, original_content, original_attachments_json,
              recalled_by_type, recalled_by_id, reason, recalled_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE reason = VALUES(reason)',
            [
                (int) $message['admin_id'], $appId, $channelType, $channelId, (int) $message['id'],
                (string) ($message['sender_type'] ?? 'user'),
                (int) ($message['sender_id'] ?? $message['user_id'] ?? $message['sender_admin_id'] ?? 0),
                (string) ($message['content_type'] ?? 'text'), (string) ($message['content'] ?? ''),
                json_encode($attachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $recalledByType, $recalledById, mb_substr($reason, 0, 500),
            ]
        );
    }

    public static function summary(array $attachments): string
    {
        if ($attachments === []) return '';
        $counts = [];
        foreach ($attachments as $attachment) {
            $type = (string) ($attachment['media_type'] ?? 'file');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        $labels = [
            'image' => '图片', 'sticker' => '表情包', 'audio' => '语音', 'video' => '视频', 'file' => '文件',
            'favorite' => '收藏', 'moment_share' => '动态', 'red_packet' => '红包', 'transfer' => '转账',
            'contact_card' => '名片', 'gift' => '礼物', 'location' => '位置',
        ];
        $parts = [];
        foreach ($counts as $type => $count) $parts[] = ($labels[$type] ?? '附件') . ($count > 1 ? "×{$count}" : '');
        return '[' . implode('、', $parts) . ']';
    }

    private static function payload(
        int $adminId,
        int $appId,
        ?int $ownerUserId,
        ?int $ownerAdminId,
        array $data
    ): array {
        $content = trim((string) ($data['content'] ?? ''));
        if (mb_strlen($content) > 10000) throw new HttpException('消息正文不能超过 10000 个字符', 0, 422);
        $rawAttachments = $data['attachments'] ?? [];
        if (is_string($rawAttachments)) $rawAttachments = json_decode($rawAttachments, true);
        if (!is_array($rawAttachments) || count($rawAttachments) > self::MAX_ATTACHMENTS) {
            throw new HttpException('单条消息最多可包含 ' . self::MAX_ATTACHMENTS . ' 个媒体文件，更多内容请分批发送', 0, 422);
        }
        $context = self::attachmentContext($adminId, $appId, $ownerUserId, $rawAttachments);
        $attachments = [];
        foreach ($rawAttachments as $index => $raw) {
            if (is_string($raw)) $raw = ['media_type' => 'image', 'url' => $raw];
            if (!is_array($raw)) throw new HttpException('第 ' . ($index + 1) . ' 个附件格式错误', 0, 422);
            $attachments[] = self::normalizeAttachment($raw, $index, $context);
        }
        if ($content === '' && $attachments === []) throw new HttpException('消息正文和附件不能同时为空', 0, 422);
        $requestedType = strtolower(trim((string) ($data['content_type'] ?? 'text')));
        if ($attachments !== [] && $content !== '') $contentType = 'mixed';
        elseif (count($attachments) > 1) $contentType = 'mixed';
        elseif (count($attachments) === 1) $contentType = (string) $attachments[0]['media_type'];
        else $contentType = $requestedType === 'emoji' ? 'emoji' : 'text';
        return [
            'admin_id' => $adminId, 'app_id' => $appId, 'owner_user_id' => $ownerUserId,
            'owner_admin_id' => $ownerAdminId, 'content' => $content, 'content_type' => $contentType,
            'attachments' => $attachments,
        ];
    }

    /** @return array{uploads:array<int,array>,upload_metadata:array<int,array>,stickers:array<int,array>} */
    private static function attachmentContext(
        int $adminId,
        int $appId,
        ?int $ownerUserId,
        array $rawAttachments
    ): array {
        $uploadIds = [];
        $stickerIds = [];
        foreach ($rawAttachments as $raw) {
            if (!is_array($raw)) continue;
            $uploadId = max(0, (int) ($raw['upload_id'] ?? 0));
            $stickerId = max(0, (int) ($raw['sticker_id'] ?? 0));
            if ($uploadId > 0) $uploadIds[$uploadId] = true;
            if ($stickerId > 0) $stickerIds[$stickerId] = true;
        }

        $uploadRows = [];
        if ($uploadIds !== []) {
            $ids = array_keys($uploadIds);
            sort($ids, SORT_NUMERIC);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT * FROM uploads WHERE id IN ({$placeholders}) AND admin_id = ? AND app_id = ? AND status = 1";
            $query = array_merge($ids, [$adminId, $appId]);
            if ($ownerUserId === null) {
                $sql .= ' AND user_id IS NULL';
            } else {
                $sql .= ' AND user_id = ?';
                $query[] = $ownerUserId;
            }
            $uploadRows = Database::all($sql . ' ORDER BY id', $query);
        }
        $uploadCache = self::cacheTrustedUploadMetadata($uploadRows);

        $stickers = [];
        if ($stickerIds !== [] && $ownerUserId !== null) {
            $ids = array_keys($stickerIds);
            sort($ids, SORT_NUMERIC);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = Database::all(
                "SELECT s.* FROM stickers s INNER JOIN sticker_packs p ON p.id = s.pack_id
                 WHERE s.id IN ({$placeholders}) AND s.admin_id = ? AND s.app_id = ? AND s.user_id = ?
                   AND s.status = 1 AND p.status = 1 AND s.upload_id IS NOT NULL ORDER BY s.id",
                array_merge($ids, [$adminId, $appId, $ownerUserId])
            );
            $underlyingIds = array_values(array_unique(array_filter(array_map(
                static fn(array $row): int => (int) ($row['upload_id'] ?? 0),
                $rows
            ), static fn(int $id): bool => $id > 0)));
            $uploadRowsById = [];
            if ($underlyingIds !== []) {
                $uploadPlaceholders = implode(',', array_fill(0, count($underlyingIds), '?'));
                $underlyingRows = Database::all(
                    "SELECT * FROM uploads WHERE id IN ({$uploadPlaceholders}) AND admin_id = ? AND app_id = ?
                     AND user_id = ? AND status = 1 ORDER BY id",
                    array_merge($underlyingIds, [$adminId, $appId, $ownerUserId])
                );
                foreach ($underlyingRows as $uploadRow) $uploadRowsById[(int) $uploadRow['id']] = $uploadRow;
            }
            foreach ($rows as $row) {
                $underlyingId = (int) ($row['upload_id'] ?? 0);
                $underlying = $uploadRowsById[$underlyingId] ?? null;
                if (!is_array($underlying)) continue;
                try {
                    $validation = UploadStorageService::validatedPublicImageUpload($underlying);
                } catch (HttpException) {
                    continue;
                }
                $row['image_url'] = (string) $validation['file_url'];
                $row['thumbnail_url'] = (string) $validation['thumbnail_url'];
                $row['width'] = (int) $validation['width'];
                $row['height'] = (int) $validation['height'];
                $row['_upload_validation'] = $validation;
                $stickers[(int) $row['id']] = $row;
            }
        }
        return [
            'uploads' => $uploadCache['uploads'],
            'upload_metadata' => $uploadCache['metadata'],
            'stickers' => $stickers,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{uploads:array<int,array>,metadata:array<int,array>}
     */
    private static function cacheTrustedUploadMetadata(array $rows, ?callable $validator = null): array
    {
        $validator ??= static fn(array $upload): array => UploadStorageService::trustedMediaMetadata($upload);
        $uploads = [];
        $metadata = [];
        foreach ($rows as $row) {
            $id = max(0, (int) ($row['id'] ?? 0));
            if ($id <= 0 || isset($uploads[$id])) continue;
            $uploads[$id] = $row;
            $metadata[$id] = $validator($row);
        }
        return ['uploads' => $uploads, 'metadata' => $metadata];
    }

    private static function normalizeAttachment(array $raw, int $index, array $context): array
    {
        $uploadId = max(0, (int) ($raw['upload_id'] ?? 0));
        $stickerId = max(0, (int) ($raw['sticker_id'] ?? 0));
        if ($uploadId > 0 && $stickerId > 0) throw new HttpException('附件不能同时引用上传文件和表情', 0, 422);
        $upload = $uploadId > 0 ? ($context['uploads'][$uploadId] ?? null) : null;
        $sticker = $stickerId > 0 ? ($context['stickers'][$stickerId] ?? null) : null;
        if ($uploadId > 0 && !is_array($upload)) throw new HttpException('上传文件不存在或无权使用', 404, 404);
        if ($stickerId > 0 && !is_array($sticker)) throw new HttpException('表情不存在或不属于当前用户', 404, 404);
        $privateUpload = is_array($upload)
            && str_starts_with(ltrim(str_replace('\\', '/', (string) ($upload['file_path'] ?? '')), '/'), 'private/');
        $trustedUploadMetadata = $uploadId > 0
            ? ($context['upload_metadata'][$uploadId] ?? null)
            : (is_array($sticker['_upload_validation'] ?? null) ? $sticker['_upload_validation'] : null);
        $untrustedExternal = $upload === null && $sticker === null;
        $url = $privateUpload ? '' : trim((string) ($sticker['image_url'] ?? $upload['file_url'] ?? $raw['url'] ?? ''));
        if (!$privateUpload && ($url === '' || mb_strlen($url) > 1000 || preg_match('#^(https?://|/)#i', $url) !== 1)) {
            throw new HttpException('第 ' . ($index + 1) . ' 个附件地址无效', 0, 422);
        }
        $thumbnailUrl = $privateUpload ? '' : mb_substr((string) (
            $sticker['thumbnail_url'] ?? $upload['thumbnail_url'] ?? $raw['thumbnail_url'] ?? ''
        ), 0, 1000);
        if ($thumbnailUrl !== '' && preg_match('#^(https?://|/)#i', $thumbnailUrl) !== 1) {
            throw new HttpException('第 ' . ($index + 1) . ' 个附件缩略图地址无效', 0, 422);
        }
        $mime = mb_substr((string) ($upload['mime_type'] ?? $raw['mime_type'] ?? ''), 0, 150);
        $mediaType = $sticker !== null ? 'sticker' : strtolower(trim((string) ($raw['media_type'] ?? '')));
        if ($mediaType === '') $mediaType = self::inferMediaType($mime, $url);
        if (!in_array($mediaType, self::MEDIA_TYPES, true)) throw new HttpException('不支持的附件类型：' . $mediaType, 0, 422);
        $metadata = $raw['metadata'] ?? [];
        if (!is_array($metadata)) $metadata = [];
        // Never persist a client-claimed camera/album source as trusted provenance.
        unset($metadata['source']);
        if ($untrustedExternal) {
            unset($metadata['audio_kind']);
            $metadata['media_trust'] = 'untrusted_external';
        } else {
            $metadata['media_trust'] = 'server_verified';
        }
        if ($mediaType === 'location') {
            $name = trim((string) ($metadata['location_name'] ?? $raw['file_name'] ?? ''));
            if ($name === '') throw new HttpException('位置名称不能为空', 0, 422);
            $metadata['location_name'] = mb_substr($name, 0, 120);
            $metadata['address'] = mb_substr(trim((string) ($metadata['address'] ?? '')), 0, 300);
            $hasLatitude = array_key_exists('latitude', $metadata) && $metadata['latitude'] !== '' && $metadata['latitude'] !== null;
            $hasLongitude = array_key_exists('longitude', $metadata) && $metadata['longitude'] !== '' && $metadata['longitude'] !== null;
            if ($hasLatitude !== $hasLongitude) throw new HttpException('位置坐标必须同时包含纬度和经度', 0, 422);
            if ($hasLatitude) {
                if (!is_numeric($metadata['latitude']) || !is_numeric($metadata['longitude'])) {
                    throw new HttpException('位置坐标格式无效', 0, 422);
                }
                $latitude = (float) $metadata['latitude'];
                $longitude = (float) $metadata['longitude'];
                if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                    throw new HttpException('位置坐标超出有效范围', 0, 422);
                }
                $metadata['latitude'] = round($latitude, 7);
                $metadata['longitude'] = round($longitude, 7);
            }
        }
        return [
            'media_type' => $mediaType,
            'upload_id' => $uploadId > 0 ? $uploadId : null,
            'sticker_id' => $stickerId > 0 ? $stickerId : null,
            'url' => $url,
            'thumbnail_url' => $thumbnailUrl,
            'file_name' => mb_substr((string) ($upload['original_name'] ?? $raw['file_name'] ?? ''), 0, 255),
            'mime_type' => $mime,
            'size_bytes' => $untrustedExternal ? 0 : max(0, (int) ($upload['size_bytes'] ?? 0)),
            'width' => $untrustedExternal ? 0 : max(0, (int) ($trustedUploadMetadata['width'] ?? $sticker['width'] ?? 0)),
            'height' => $untrustedExternal ? 0 : max(0, (int) ($trustedUploadMetadata['height'] ?? $sticker['height'] ?? 0)),
            'duration_ms' => $untrustedExternal ? 0 : max(0, (int) ($trustedUploadMetadata['duration_ms'] ?? 0)),
            'metadata' => $metadata,
            '_sticker_upload_validation' => is_array($sticker['_upload_validation'] ?? null)
                ? $sticker['_upload_validation']
                : null,
        ];
    }

    private static function inferMediaType(string $mime, string $url): string
    {
        $mime = strtolower($mime);
        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'audio/')) return 'audio';
        if (str_starts_with($mime, 'video/')) return 'video';
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/', $path)) return 'image';
        if (preg_match('/\.(mp3|m4a|aac|wav|ogg|opus)$/', $path)) return 'audio';
        if (preg_match('/\.(mp4|webm|mov|mkv|avi)$/', $path)) return 'video';
        return 'file';
    }

    private static function isPublicMediaTarget(string $targetType): bool
    {
        return in_array($targetType, self::PUBLIC_MEDIA_TARGET_TYPES, true);
    }

    private static function assertPublicAttachmentTrust(string $targetType, array $payload): void
    {
        if (!self::isPublicMediaTarget($targetType)) return;
        foreach ((array) ($payload['attachments'] ?? []) as $attachment) {
            if (!is_array($attachment) || (int) ($attachment['upload_id'] ?? 0) <= 0
                && (int) ($attachment['sticker_id'] ?? 0) <= 0) {
                throw new HttpException('公开内容附件必须引用已验证上传或表情，不能直接发布外链', 0, 422);
            }
        }
    }

    /**
     * Full physical direct-upload and sticker validation is completed before the
     * catalog write lock. The token is rechecked against locked rows by
     * assertStoredPublicAttachmentTrust().
     *
     * @return array<string,mixed>
     */
    public static function prevalidateStoredPublicAttachmentTrust(
        string $targetType,
        int $targetId,
        int $adminId,
        int $appId
    ): array {
        if (!in_array($targetType, ['resource', 'store_app'], true)) {
            throw new \InvalidArgumentException('Stored public attachment gate only supports catalog targets');
        }
        $references = Database::all(
            'SELECT id, upload_id, sticker_id FROM media_attachments
             WHERE target_type = ? AND target_id = ? AND admin_id = ? AND app_id = ? ORDER BY id',
            [$targetType, $targetId, $adminId, $appId]
        );
        $identity = [];
        foreach ($references as $reference) {
            $identity[] = [
                (int) $reference['id'],
                $reference['upload_id'] === null ? null : (int) $reference['upload_id'],
                $reference['sticker_id'] === null ? null : (int) $reference['sticker_id'],
            ];
        }
        $directRows = Database::all(
            "SELECT ma.id AS attachment_reference_id, up.*
             FROM media_attachments ma
             INNER JOIN uploads up
               ON up.id = ma.upload_id AND up.admin_id = ma.admin_id AND up.app_id = ma.app_id
              AND up.user_id <=> ma.owner_user_id AND up.status = 1 AND up.file_path LIKE 'uploads/%'
             WHERE ma.target_type = ? AND ma.target_id = ? AND ma.admin_id = ? AND ma.app_id = ?
               AND ma.upload_id IS NOT NULL AND ma.sticker_id IS NULL ORDER BY ma.id",
            [$targetType, $targetId, $adminId, $appId]
        );
        $expectedDirectCount = count(array_filter(
            $references,
            static fn(array $reference): bool => $reference['upload_id'] !== null
                && $reference['sticker_id'] === null
        ));
        if (count($directRows) !== $expectedDirectCount) {
            throw new HttpException('公开内容包含失效或跨账号的上传附件，请重新上传', 0, 422);
        }
        $directValidations = [];
        foreach ($directRows as $row) {
            $attachmentId = (int) ($row['attachment_reference_id'] ?? 0);
            if ($attachmentId <= 0) {
                throw new HttpException('公开内容上传附件引用无效，请重新上传', 0, 422);
            }
            $directValidations[$attachmentId] = UploadStorageService::validatedPublicUpload($row);
        }
        $stickerRows = Database::all(
            "SELECT ma.id AS attachment_reference_id, s.id AS sticker_reference_id, su.*
             FROM media_attachments ma
             INNER JOIN stickers s
               ON s.id = ma.sticker_id AND s.admin_id = ma.admin_id AND s.app_id = ma.app_id
              AND s.user_id = ma.owner_user_id AND s.status = 1
             INNER JOIN sticker_packs sp
               ON sp.id = s.pack_id AND sp.admin_id = s.admin_id AND sp.app_id = s.app_id AND sp.status = 1
             INNER JOIN uploads su
               ON su.id = s.upload_id AND su.admin_id = s.admin_id AND su.app_id = s.app_id
              AND su.user_id = s.user_id AND su.status = 1 AND su.file_path LIKE 'uploads/%'
             WHERE ma.target_type = ? AND ma.target_id = ? AND ma.admin_id = ? AND ma.app_id = ?
               AND ma.sticker_id IS NOT NULL ORDER BY ma.id",
            [$targetType, $targetId, $adminId, $appId]
        );
        $stickerValidations = [];
        foreach ($stickerRows as $row) {
            $attachmentId = (int) ($row['attachment_reference_id'] ?? 0);
            $stickerId = (int) ($row['sticker_reference_id'] ?? 0);
            if ($attachmentId <= 0 || $stickerId <= 0) {
                throw new HttpException('公开内容表情引用无效，请重新选择表情', 0, 422);
            }
            $stickerValidations[$attachmentId] = [
                'sticker_id' => $stickerId,
                'upload' => UploadStorageService::validatedPublicImageUpload($row),
            ];
        }
        $expectedStickerCount = count(array_filter(
            $references,
            static fn(array $reference): bool => $reference['sticker_id'] !== null
                && $reference['upload_id'] === null
        ));
        if (count($stickerRows) !== $expectedStickerCount) {
            throw new HttpException('公开内容包含失效或跨账号的表情附件，请重新选择', 0, 422);
        }
        return [
            'attachment_identity' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)),
            'attachment_count' => count($references),
            'direct_validations' => $directValidations,
            'sticker_validations' => $stickerValidations,
        ];
    }

    public static function assertStoredPublicAttachmentTrust(
        string $targetType,
        int $targetId,
        int $adminId,
        int $appId,
        ?array $prevalidated = null
    ): void {
        if (!in_array($targetType, ['resource', 'store_app'], true)) {
            throw new \InvalidArgumentException('Stored public attachment gate only supports catalog targets');
        }
        $prevalidated ??= self::prevalidateStoredPublicAttachmentTrust(
            $targetType,
            $targetId,
            $adminId,
            $appId
        );
        $lockedReferences = Database::all(
            'SELECT id, upload_id, sticker_id FROM media_attachments
             WHERE target_type = ? AND target_id = ? AND admin_id = ? AND app_id = ? ORDER BY id FOR UPDATE',
            [$targetType, $targetId, $adminId, $appId]
        );
        $lockedIdentity = [];
        foreach ($lockedReferences as $reference) {
            $lockedIdentity[] = [
                (int) $reference['id'],
                $reference['upload_id'] === null ? null : (int) $reference['upload_id'],
                $reference['sticker_id'] === null ? null : (int) $reference['sticker_id'],
            ];
        }
        $lockedDigest = hash('sha256', json_encode($lockedIdentity, JSON_THROW_ON_ERROR));
        if (count($lockedReferences) !== (int) ($prevalidated['attachment_count'] ?? -1)
            || !hash_equals((string) ($prevalidated['attachment_identity'] ?? ''), $lockedDigest)) {
            throw new HttpException('公开附件已在审核期间变化，请刷新后重试', 0, 409);
        }
        $unsafe = Database::one(
            "SELECT ma.id FROM media_attachments ma
             LEFT JOIN uploads up
               ON up.id = ma.upload_id AND up.admin_id = ma.admin_id AND up.app_id = ma.app_id
              AND up.user_id <=> ma.owner_user_id
             LEFT JOIN stickers s
               ON s.id = ma.sticker_id AND s.admin_id = ma.admin_id AND s.app_id = ma.app_id
              AND s.user_id = ma.owner_user_id AND s.status = 1
             LEFT JOIN sticker_packs sp
               ON sp.id = s.pack_id AND sp.admin_id = s.admin_id AND sp.app_id = s.app_id AND sp.status = 1
             LEFT JOIN uploads sup
               ON sup.id = s.upload_id AND sup.admin_id = s.admin_id AND sup.app_id = s.app_id
              AND sup.user_id = s.user_id AND sup.status = 1
             WHERE ma.target_type = ? AND ma.target_id = ? AND ma.admin_id = ? AND ma.app_id = ?
               AND (
                    (ma.upload_id IS NULL AND ma.sticker_id IS NULL)
                 OR (ma.upload_id IS NOT NULL AND ma.sticker_id IS NOT NULL)
                 OR (ma.upload_id IS NOT NULL AND (up.id IS NULL OR up.status <> 1
                      OR up.file_path NOT LIKE 'uploads/%' OR COALESCE(up.sha256, '') = ''))
                 OR (ma.sticker_id IS NOT NULL AND (s.id IS NULL OR sp.id IS NULL OR sup.id IS NULL
                      OR sup.file_path NOT LIKE 'uploads/%' OR COALESCE(sup.sha256, '') = ''))
               )
             LIMIT 1 FOR UPDATE",
            [$targetType, $targetId, $adminId, $appId]
        );
        if ($unsafe !== null) {
            throw new HttpException('公开内容仍包含未验证外链或失效附件，请用 upload_id 替换后再审核', 0, 422);
        }
        $lockedDirectRows = Database::all(
            "SELECT ma.id AS attachment_reference_id, up.*
             FROM media_attachments ma
             INNER JOIN uploads up
               ON up.id = ma.upload_id AND up.admin_id = ma.admin_id AND up.app_id = ma.app_id
              AND up.user_id <=> ma.owner_user_id AND up.status = 1 AND up.file_path LIKE 'uploads/%'
             WHERE ma.target_type = ? AND ma.target_id = ? AND ma.admin_id = ? AND ma.app_id = ?
               AND ma.upload_id IS NOT NULL AND ma.sticker_id IS NULL ORDER BY ma.id FOR UPDATE",
            [$targetType, $targetId, $adminId, $appId]
        );
        $expectedDirect = is_array($prevalidated['direct_validations'] ?? null)
            ? $prevalidated['direct_validations']
            : [];
        if (count($lockedDirectRows) !== count($expectedDirect)) {
            throw new HttpException('公开上传附件已在审核期间变化，请刷新后重试', 0, 409);
        }
        foreach ($lockedDirectRows as $lockedDirect) {
            $attachmentId = (int) ($lockedDirect['attachment_reference_id'] ?? 0);
            $token = $expectedDirect[$attachmentId] ?? null;
            if (!is_array($token)) {
                throw new HttpException('公开上传附件缺少完整性校验，请刷新后重试', 0, 409);
            }
            UploadStorageService::assertLockedPublicUpload($lockedDirect, $token);
        }
        $lockedStickerRows = Database::all(
            "SELECT ma.id AS attachment_reference_id, s.id AS sticker_reference_id, su.*
             FROM media_attachments ma
             INNER JOIN stickers s
               ON s.id = ma.sticker_id AND s.admin_id = ma.admin_id AND s.app_id = ma.app_id
              AND s.user_id = ma.owner_user_id AND s.status = 1
             INNER JOIN sticker_packs sp
               ON sp.id = s.pack_id AND sp.admin_id = s.admin_id AND sp.app_id = s.app_id AND sp.status = 1
             INNER JOIN uploads su
               ON su.id = s.upload_id AND su.admin_id = s.admin_id AND su.app_id = s.app_id
              AND su.user_id = s.user_id AND su.status = 1 AND su.file_path LIKE 'uploads/%'
             WHERE ma.target_type = ? AND ma.target_id = ? AND ma.admin_id = ? AND ma.app_id = ?
               AND ma.sticker_id IS NOT NULL ORDER BY ma.id FOR UPDATE",
            [$targetType, $targetId, $adminId, $appId]
        );
        $expected = is_array($prevalidated['sticker_validations'] ?? null)
            ? $prevalidated['sticker_validations']
            : [];
        if (count($lockedStickerRows) !== count($expected)) {
            throw new HttpException('公开内容表情已在审核期间变化，请刷新后重试', 0, 409);
        }
        foreach ($lockedStickerRows as $lockedSticker) {
            $attachmentId = (int) ($lockedSticker['attachment_reference_id'] ?? 0);
            $stickerId = (int) ($lockedSticker['sticker_reference_id'] ?? 0);
            $token = $expected[$attachmentId] ?? null;
            if (!is_array($token) || $stickerId !== (int) ($token['sticker_id'] ?? 0)
                || !is_array($token['upload'] ?? null)) {
                throw new HttpException('公开内容表情缺少完整性校验，请刷新后重试', 0, 409);
            }
            UploadStorageService::assertLockedPublicImageUpload($lockedSticker, $token['upload']);
        }
    }

    /**
     * Public attachment metadata must never contain a client filename or a local
     * device path. The traversal also sanitizes nested picker/optimizer payloads.
     */
    private static function sanitizePublicMetadata(array $metadata): array
    {
        $sanitized = [];
        foreach ($metadata as $key => $value) {
            if (is_string($key) && self::isPrivateFileMetadataKey($key)) continue;
            $sanitized[$key] = is_array($value) ? self::sanitizePublicMetadata($value) : $value;
        }
        return $sanitized;
    }

    private static function isPrivateFileMetadataKey(string $key): bool
    {
        $withSnakeCase = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', trim($key));
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', (string) $withSnakeCase));
        $normalized = trim($normalized, '_');
        if (in_array($normalized, [
            'name', 'filename', 'file_name', 'original_name', 'original_filename', 'original_file_name',
            'display_name', 'client_name', 'client_filename', 'client_file_name', 'upload_filename',
            'upload_file_name', 'path', 'local_path', 'file_path', 'original_path', 'source_path',
            'client_path', 'temporary_path', 'temp_path', 'cache_path', 'uri', 'local_uri',
            'file_uri', 'content_uri',
        ], true)) return true;
        return str_ends_with($normalized, '_path')
            || str_ends_with($normalized, '_uri')
            || str_ends_with($normalized, '_filename')
            || str_ends_with($normalized, '_file_name');
    }

    private static function publicFileName(string $mediaType, int $position): string
    {
        $label = match ($mediaType) {
            'image' => '图片',
            'audio' => '音频',
            'video' => '视频',
            'sticker' => '表情',
            default => '附件',
        };
        return $label . ' ' . max(1, $position);
    }

    /**
     * Business attachments are long-lived message snapshots. Merge their current
     * state in one batch so claimed/refunded/expired cards update with the chat.
     */
    private static function hydrateCommerceMetadata(array $grouped, ?int $appId): array
    {
        if ($grouped === [] || $appId === null) return $grouped;
        $ids = ['red_packet' => [], 'transfer' => [], 'gift' => []];
        foreach ($grouped as $attachments) {
            foreach ($attachments as $attachment) {
                $type = (string) ($attachment['media_type'] ?? '');
                if (!isset($ids[$type])) continue;
                $metadata = is_array($attachment['metadata'] ?? null) ? $attachment['metadata'] : [];
                $id = match ($type) {
                    'red_packet' => (int) ($metadata['packet_id'] ?? 0),
                    'transfer' => self::businessRecordId($metadata, 'transfer_id'),
                    'gift' => self::businessRecordId($metadata, 'gift_record_id'),
                    default => 0,
                };
                if ($id > 0) $ids[$type][$id] = true;
            }
        }
        $records = [
            'red_packet' => self::commerceRows(
                'red_packets',
                'id, status, total_amount, remain_amount, total_count, remain_count, expired_at',
                array_keys($ids['red_packet']),
                $appId
            ),
            'transfer' => self::commerceRows(
                'user_transfers',
                'id, status, amount, expired_at, accepted_at, refunded_at',
                array_keys($ids['transfer']),
                $appId
            ),
            'gift' => self::commerceRows(
                'user_gift_records',
                'id, status, total_amount, quantity, expired_at, accepted_at, refunded_at',
                array_keys($ids['gift']),
                $appId
            ),
        ];
        foreach ($grouped as &$attachments) {
            foreach ($attachments as &$attachment) {
                $type = (string) ($attachment['media_type'] ?? '');
                if (!isset($records[$type])) continue;
                $metadata = is_array($attachment['metadata'] ?? null) ? $attachment['metadata'] : [];
                $id = match ($type) {
                    'red_packet' => (int) ($metadata['packet_id'] ?? 0),
                    'transfer' => self::businessRecordId($metadata, 'transfer_id'),
                    'gift' => self::businessRecordId($metadata, 'gift_record_id'),
                    default => 0,
                };
                $record = $records[$type][$id] ?? null;
                if (!is_array($record)) continue;
                $metadata = array_merge($metadata, $record);
                $metadata['commerce_state'] = self::commerceState($type, $record);
                $attachment['metadata'] = $metadata;
            }
            unset($attachment);
        }
        unset($attachments);
        return $grouped;
    }

    private static function businessRecordId(array $metadata, string $primaryKey): int
    {
        $id = (int) ($metadata[$primaryKey] ?? 0);
        if ($id > 0) return $id;
        $items = $metadata['items'] ?? [];
        if (is_array($items) && isset($items[0]) && is_array($items[0])) return (int) ($items[0]['id'] ?? 0);
        return 0;
    }

    private static function commerceRows(string $table, string $columns, array $ids, int $appId): array
    {
        if ($ids === []) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $rows = Database::all(
                "SELECT {$columns} FROM {$table} WHERE app_id = ? AND id IN ({$placeholders})",
                array_merge([$appId], $ids)
            );
        } catch (\Throwable $exception) {
            return [];
        }
        $indexed = [];
        foreach ($rows as $row) $indexed[(int) $row['id']] = $row;
        return $indexed;
    }

    private static function commerceState(string $type, array $record): string
    {
        $expiredAt = strtotime((string) ($record['expired_at'] ?? ''));
        $expired = $expiredAt !== false && $expiredAt <= time();
        if ($type === 'red_packet') {
            $status = (int) ($record['status'] ?? 1);
            if ($status === 2) return 'refunded';
            if ($status === 0 || (int) ($record['remain_count'] ?? 0) <= 0) return 'completed';
            return $expired ? 'expired' : 'pending';
        }
        $status = strtolower(trim((string) ($record['status'] ?? 'pending')));
        if ($status === 'pending' && $expired) return 'expired';
        return in_array($status, ['pending', 'accepted', 'refunded', 'expired', 'cancelled'], true)
            ? $status : 'pending';
    }

    private static function decodeObject($value): array
    {
        if (!is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
