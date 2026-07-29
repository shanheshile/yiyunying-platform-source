<?php
declare(strict_types=1);

namespace Yiyunying\Services;

final class UploadLimitService
{
    private const MB = 1048576;

    private const DEFAULTS = [
        'image' => 100 * self::MB,
        'video' => 1024 * self::MB,
        'audio' => 100 * self::MB,
        'file' => 512 * self::MB,
    ];

    public static function category(array $file): string
    {
        $mime = strtolower(trim((string) ($file['type'] ?? '')));
        $name = strtolower((string) ($file['name'] ?? ''));
        if (str_starts_with($mime, 'image/') || preg_match('/\.(jpg|jpeg|png|gif|webp|bmp|heic|heif|svg)$/', $name)) return 'image';
        if (str_starts_with($mime, 'video/') || preg_match('/\.(mp4|webm|mov|mkv|avi|3gp|m4v)$/', $name)) return 'video';
        if (str_starts_with($mime, 'audio/') || preg_match('/\.(mp3|m4a|aac|wav|ogg|opus|flac)$/', $name)) return 'audio';
        return 'file';
    }

    public static function bytes(int $appId, string $category): int
    {
        if (!array_key_exists($category, self::DEFAULTS)) $category = 'file';
        $key = 'upload_' . $category . '_max_bytes';
        return max(self::MB, (int) AppService::setting($appId, $key, self::DEFAULTS[$category]));
    }

    public static function validate(int $appId, array $file): array
    {
        $category = self::category($file);
        $maximum = self::bytes($appId, $category);
        $size = max(0, (int) ($file['size'] ?? 0));
        return [
            'category' => $category,
            'size_bytes' => $size,
            'max_bytes' => $maximum,
            'valid' => $size > 0 && $size <= $maximum,
        ];
    }

    public static function publicLimits(int $appId): array
    {
        $items = [];
        foreach (array_keys(self::DEFAULTS) as $category) {
            $items[$category . '_max_bytes'] = self::bytes($appId, $category);
        }
        return $items + ['unit' => '字节'];
    }

    public static function label(string $category): string
    {
        return match ($category) {
            'image' => '图片',
            'video' => '视频',
            'audio' => '音频',
            default => '文件',
        };
    }
}
