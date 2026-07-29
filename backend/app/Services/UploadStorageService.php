<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class UploadStorageService
{
    public static function store(
        array $file,
        int $adminId,
        int $appId,
        ?int $userId,
        string $scene,
        array $allowedExtensions,
        array $options = []
    ): array {
        $size = (int) ($file['size'] ?? 0);
        $original = basename((string) ($file['name'] ?? 'file'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            throw new HttpException('不允许上传该文件类型', 0, 422, ['extension' => $extension]);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) throw new HttpException('上传临时文件无效', 0, 422);
        $sha256 = hash_file('sha256', $tmp);
        if (!is_string($sha256) || strlen($sha256) !== 64) throw new HttpException('计算文件内容指纹失败', -1, 500);
        $mime = function_exists('mime_content_type') ? (string) mime_content_type($tmp) : (string) ($file['type'] ?? '');
        $scene = mb_substr(trim($scene) !== '' ? trim($scene) : 'general', 0, 40);
        $originalUpload = self::boolean($options['original_upload'] ?? false);
        $requestedMode = $originalUpload ? 'original' : 'optimized';
        if ($originalUpload && !(bool) AppService::setting($appId, 'media_original_upload_enabled', true)) {
            throw new HttpException('当前应用不允许上传原图或原视频，请关闭原媒体开关后重试', 0, 422);
        }

        $existing = Database::one(
            'SELECT * FROM uploads WHERE admin_id = ? AND app_id = ? AND sha256 = ?
             AND (original_size_bytes = ? OR (original_size_bytes = 0 AND size_bytes = ?))
             AND original_name = ? AND upload_mode = ? AND status = 1 ORDER BY id LIMIT 1',
            [$adminId, $appId, $sha256, $size, $size, mb_substr($original, 0, 255), $requestedMode]
        );
        if ($existing !== null && self::physicalExists((string) $existing['file_path'])) {
            $sameOwner = Database::one(
                 'SELECT * FROM uploads WHERE admin_id = ? AND app_id = ? AND user_id <=> ? AND scene = ?
                  AND sha256 = ? AND (original_size_bytes = ? OR (original_size_bytes = 0 AND size_bytes = ?))
                  AND original_name = ? AND upload_mode = ? AND status = 1 ORDER BY id DESC LIMIT 1',
                [$adminId, $appId, $userId, $scene, $sha256, $size, $size, mb_substr($original, 0, 255), $requestedMode]
            );
            if ($sameOwner !== null) return self::result($sameOwner, true, false);
            $id = Database::insert(
                'INSERT INTO uploads
                 (admin_id, app_id, user_id, scene, original_name, stored_name, file_path, file_url,
                  mime_type, size_bytes, original_size_bytes, optimized_size_bytes, upload_mode,
                  optimization_status, original_file_url, optimized_file_url, thumbnail_url, is_animated,
                  sha256, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())',
                [$adminId, $appId, $userId, $scene, mb_substr($original, 0, 255),
                 (string) $existing['stored_name'], (string) $existing['file_path'], (string) $existing['file_url'],
                 mb_substr((string) ($existing['mime_type'] ?: $mime), 0, 150), (int) $existing['size_bytes'],
                 (int) ($existing['original_size_bytes'] ?: $size), (int) $existing['optimized_size_bytes'],
                 (string) $existing['upload_mode'], (string) $existing['optimization_status'],
                 (string) $existing['original_file_url'], (string) $existing['optimized_file_url'],
                 (string) $existing['thumbnail_url'], (int) $existing['is_animated'], $sha256]
            );
            $logical = $existing;
            $logical['id'] = $id;
            $logical['user_id'] = $userId;
            $logical['scene'] = $scene;
            return self::result($logical, true, true);
        }

        $relativeDir = 'uploads/' . $appId . '/' . date('Y/m');
        $publicDir = YIYUNYING_ROOT . '/public/' . $relativeDir;
        if (!is_dir($publicDir) && !mkdir($publicDir, 0775, true) && !is_dir($publicDir)) {
            throw new HttpException('创建上传目录失败', -1, 500);
        }
        $stored = bin2hex(random_bytes(16)) . '.' . $extension;
        $originalPath = $publicDir . '/' . $stored;
        if (!move_uploaded_file($tmp, $originalPath)) throw new HttpException('保存上传文件失败', -1, 500);
        $originalRelative = str_replace('\\', '/', $relativeDir) . '/' . $stored;
        $baseUrl = rtrim((string) config('app.url'), '/');
        $originalUrl = $baseUrl . '/' . $originalRelative;
        $optimize = !$originalUpload && (bool) AppService::setting($appId, 'media_optimize_by_default', true);
        if (str_contains(mb_strtolower($scene), '表情') || str_contains(strtolower($scene), 'sticker')) {
            $optimize = !$originalUpload && (bool) AppService::setting($appId, 'sticker_optimize_enabled', true);
        }
        $optimization = $optimize
            ? MediaOptimizationService::optimize(
                $originalPath,
                $mime,
                $scene,
                max(65536, (int) AppService::setting($appId, 'sticker_target_max_bytes', 524288))
            )
            : [
                'path' => $originalPath, 'mime_type' => $mime, 'size_bytes' => $size,
                'status' => 'original', 'is_animated' => $extension === 'gif',
                'thumbnail_path' => '', 'width' => 0, 'height' => 0,
            ];
        $mainPath = (string) $optimization['path'];
        $relative = self::relativePublicPath($mainPath);
        $url = $baseUrl . '/' . $relative;
        $thumbnailPath = (string) ($optimization['thumbnail_path'] ?? '');
        $thumbnailUrl = $thumbnailPath !== '' ? $baseUrl . '/' . self::relativePublicPath($thumbnailPath) : '';
        $mainSize = max(0, (int) ($optimization['size_bytes'] ?? filesize($mainPath)));
        $mainMime = trim((string) ($optimization['mime_type'] ?? '')) ?: $mime;
        $uploadMode = $originalUpload ? 'original' : 'optimized';
        $optimizedUrl = $uploadMode === 'optimized' ? $url : '';
        $keptOriginalUrl = $originalUpload ? $originalUrl : '';
        if (!$originalUpload && $mainPath !== $originalPath) @unlink($originalPath);
        try {
            $id = Database::insert(
                'INSERT INTO uploads
                 (admin_id, app_id, user_id, scene, original_name, stored_name, file_path, file_url,
                  mime_type, size_bytes, original_size_bytes, optimized_size_bytes, upload_mode,
                  optimization_status, original_file_url, optimized_file_url, thumbnail_url, is_animated,
                  sha256, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())',
                [$adminId, $appId, $userId, $scene, mb_substr($original, 0, 255), basename($mainPath), $relative,
                 $url, mb_substr($mainMime, 0, 150), $mainSize, $size,
                 $uploadMode === 'optimized' ? $mainSize : 0, $uploadMode,
                 mb_substr((string) $optimization['status'], 0, 40), $keptOriginalUrl, $optimizedUrl,
                 $thumbnailUrl, (bool) ($optimization['is_animated'] ?? false) ? 1 : 0, $sha256]
            );
        } catch (\Throwable $exception) {
            @unlink($mainPath);
            if ($mainPath !== $originalPath) @unlink($originalPath);
            throw $exception;
        }
        return [
            'upload_id' => $id, 'file_url' => $url, 'mime_type' => $mainMime, 'size_bytes' => $mainSize,
            'original_size_bytes' => $size, 'optimized_size_bytes' => $uploadMode === 'optimized' ? $mainSize : 0,
            'upload_mode' => $uploadMode, 'optimization_status' => (string) $optimization['status'],
            'original_file_url' => $keptOriginalUrl, 'optimized_file_url' => $optimizedUrl,
            'thumbnail_url' => $thumbnailUrl, 'is_animated' => (bool) ($optimization['is_animated'] ?? false),
            'sha256' => $sha256, 'reused' => false, 'shared_physical_file' => false,
        ];
    }

    private static function result(array $upload, bool $reused, bool $shared): array
    {
        return [
            'upload_id' => (int) $upload['id'], 'file_url' => (string) $upload['file_url'],
            'mime_type' => (string) $upload['mime_type'], 'size_bytes' => (int) $upload['size_bytes'],
            'original_size_bytes' => (int) ($upload['original_size_bytes'] ?: $upload['size_bytes']),
            'optimized_size_bytes' => (int) ($upload['optimized_size_bytes'] ?? 0),
            'upload_mode' => (string) ($upload['upload_mode'] ?? 'original'),
            'optimization_status' => (string) ($upload['optimization_status'] ?? 'not_required'),
            'original_file_url' => (string) ($upload['original_file_url'] ?? ''),
            'optimized_file_url' => (string) ($upload['optimized_file_url'] ?? ''),
            'thumbnail_url' => (string) ($upload['thumbnail_url'] ?? ''),
            'is_animated' => (bool) ($upload['is_animated'] ?? false),
            'sha256' => (string) $upload['sha256'], 'reused' => $reused,
            'shared_physical_file' => $shared,
        ];
    }

    private static function physicalExists(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        return $relative !== '' && is_file(YIYUNYING_ROOT . '/public/' . $relative);
    }

    private static function relativePublicPath(string $absolute): string
    {
        $root = str_replace('\\', '/', YIYUNYING_ROOT . '/public/');
        $path = str_replace('\\', '/', $absolute);
        if (!str_starts_with($path, $root)) throw new HttpException('媒体优化结果不在公开上传目录内', -1, 500);
        return ltrim(substr($path, strlen($root)), '/');
    }

    private static function boolean(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'original'], true);
    }
}
