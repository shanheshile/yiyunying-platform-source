<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\ApiResponse;
use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;

final class PrivateForumMediaService
{
    private const URL_TTL_SECONDS = 300;

    public static function signedUrl(int $attachmentId, int $appId): string
    {
        if ($attachmentId <= 0 || $appId <= 0) return '';
        $expires = time() + self::URL_TTL_SECONDS;
        $signature = self::signature($attachmentId, $appId, $expires);
        return rtrim((string) config('app.url'), '/')
            . '/api/public/forum-media/' . $attachmentId
            . '?app_id=' . $appId . '&expires=' . $expires . '&signature=' . $signature;
    }

    public static function show(Request $request, array $params): ApiResponse
    {
        $attachmentId = max(0, (int) ($params['attachment_id'] ?? 0));
        $appId = max(0, (int) $request->input('app_id', 0));
        $expires = max(0, (int) $request->input('expires', 0));
        $provided = strtolower(trim((string) $request->input('signature', '')));
        if ($attachmentId <= 0 || $appId <= 0 || $expires < time()
            || $expires > time() + self::URL_TTL_SECONDS + 60
            || !hash_equals(self::signature($attachmentId, $appId, $expires), $provided)) {
            throw new HttpException('媒体访问地址无效或已过期', 403, 403);
        }
        $media = Database::one(
            "SELECT ma.id, ma.mime_type, ma.target_type, up.file_path
             FROM media_attachments ma
             INNER JOIN uploads up
               ON up.id = ma.upload_id AND up.admin_id = ma.admin_id AND up.app_id = ma.app_id
             WHERE ma.id = ? AND ma.app_id = ? AND ma.target_type IN ('forum_post', 'forum_section')
               AND up.status = 1 AND up.file_path LIKE 'private/%'",
            [$attachmentId, $appId]
        );
        if ($media === null) throw new HttpException('媒体文件不存在', 404, 404);
        $path = UploadStorageService::privatePhysicalPath((string) $media['file_path']);
        if ($path === null) throw new HttpException('媒体文件不存在', 404, 404);
        return Response::file($path, (string) ($media['mime_type'] ?: 'application/octet-stream'));
    }

    private static function signature(int $attachmentId, int $appId, int $expires): string
    {
        $key = (string) config('security.media_signing_key', '');
        $qrKey = (string) config('security.qr_signing_key', '');
        $knownPlaceholders = [
            'local-development-only-change-me',
            'replace-with-a-different-random-secret',
        ];
        if (strlen($key) < 32 || ($qrKey !== '' && hash_equals($key, $qrKey))
            || in_array($key, $knownPlaceholders, true)) {
            throw new HttpException('媒体签名密钥必须独立配置为至少 32 字节的高熵值', -1, 500);
        }
        return hash_hmac('sha256', $attachmentId . '|' . $appId . '|' . $expires, $key);
    }
}
