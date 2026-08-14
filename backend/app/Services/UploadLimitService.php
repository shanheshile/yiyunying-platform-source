<?php
declare(strict_types=1);

namespace Yiyunying\Services;

final class UploadLimitService
{
    private const MB = 1048576;

    private const DEFAULTS = [
        'json' => MediaOptimizationService::MAX_JSON_BYTES,
        'image' => MediaOptimizationService::MAX_IMAGE_BYTES,
        'video' => 1024 * self::MB,
        'audio' => 100 * self::MB,
        'file' => 512 * self::MB,
    ];

    /**
     * Classification never consumes the multipart Content-Type value.
     *
     * @return array{extension:string,declared_category:string,detected_category:string,categories:list<string>}
     */
    public static function classify(array $file): array
    {
        $name = basename((string) ($file['name'] ?? ''));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $declared = self::extensionCategory($extension);
        $tmp = (string) ($file['tmp_name'] ?? '');
        $probe = MediaOptimizationService::probeClientUpload($tmp);
        $detected = (string) ($probe['category'] ?? 'file');
        if (!array_key_exists($detected, self::DEFAULTS)) $detected = 'file';
        $categories = array_values(array_unique([$declared, $detected]));
        return [
            'extension' => $extension,
            'declared_category' => $declared,
            'detected_category' => $detected,
            'categories' => $categories,
        ];
    }

    public static function category(array $file): string
    {
        return self::classify($file)['declared_category'];
    }

    public static function bytes(int $appId, string $category): int
    {
        if (!array_key_exists($category, self::DEFAULTS)) $category = 'file';
        $key = 'upload_' . $category . '_max_bytes';
        $configured = max(self::MB, (int) AppService::setting($appId, $key, self::DEFAULTS[$category]));
        return match ($category) {
            'json' => min(MediaOptimizationService::MAX_JSON_BYTES, $configured),
            'image' => min(MediaOptimizationService::MAX_IMAGE_BYTES, $configured),
            default => $configured,
        };
    }

    public static function validate(int $appId, array $file): array
    {
        $classification = self::classify($file);
        $limits = [];
        foreach ($classification['categories'] as $category) {
            $limits[$category] = self::bytes($appId, $category);
        }
        return self::evaluateClassification($file, $classification, $limits);
    }

    /** @param array<string,int> $categoryLimits */
    public static function evaluate(array $file, array $categoryLimits): array
    {
        $classification = self::classify($file);
        $limits = [];
        foreach ($classification['categories'] as $category) {
            if (!array_key_exists($category, $categoryLimits)) {
                throw new \InvalidArgumentException('Missing upload limit for category: ' . $category);
            }
            $limits[$category] = max(1, (int) $categoryLimits[$category]);
        }
        return self::evaluateClassification($file, $classification, $limits);
    }

    /** @param array<string,mixed> $classification @param array<string,int> $limits */
    private static function evaluateClassification(array $file, array $classification, array $limits): array
    {
        asort($limits, SORT_NUMERIC);
        $category = (string) array_key_first($limits);
        $maximum = (int) $limits[$category];
        $reportedSize = max(0, (int) ($file['size'] ?? 0));
        $tmp = (string) ($file['tmp_name'] ?? '');
        $actualSize = is_file($tmp) && !is_link($tmp) ? @filesize($tmp) : false;
        $sizeMatches = $actualSize !== false && $actualSize > 0 && $reportedSize === (int) $actualSize;
        $valid = $sizeMatches && (int) $actualSize <= $maximum;
        return $classification + [
            'category' => $category,
            'size_bytes' => $actualSize === false ? 0 : (int) $actualSize,
            'reported_size_bytes' => $reportedSize,
            'max_bytes' => $maximum,
            'valid' => $valid,
            'reason' => !$sizeMatches ? 'size_mismatch_or_unreadable' : ($valid ? 'within_limit' : 'size_limit_exceeded'),
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
            'json' => 'JSON 文件',
            'image' => '图片',
            'video' => '视频',
            'audio' => '音频',
            default => '文件',
        };
    }

    private static function extensionCategory(string $extension): string
    {
        return match ($extension) {
            'json' => 'json',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' => 'image',
            'mp4', 'webm', 'mov', 'mkv', 'avi', '3gp', 'm4v' => 'video',
            'mp3', 'm4a', 'aac', 'wav', 'ogg', 'opus', 'flac' => 'audio',
            default => 'file',
        };
    }
}
