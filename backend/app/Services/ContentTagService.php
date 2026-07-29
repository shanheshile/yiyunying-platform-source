<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\HttpException;

final class ContentTagService
{
    private const MAX_TAGS = 10;
    private const MAX_LENGTH = 24;

    public static function normalize($value): array
    {
        if ($value === null || $value === '') return [];
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[\s,，;；#]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($value)) throw new HttpException('标签必须是数组或用逗号分隔的文字', 0, 422);
        $result = [];
        $seen = [];
        foreach ($value as $item) {
            if (is_array($item)) $item = $item['name'] ?? $item['tag'] ?? '';
            $tag = trim((string) $item);
            $tag = preg_replace('/^#+/u', '', $tag) ?? $tag;
            if ($tag === '') continue;
            if (mb_strlen($tag) > self::MAX_LENGTH) {
                throw new HttpException('单个标签不能超过 ' . self::MAX_LENGTH . ' 个字符', 0, 422);
            }
            $key = mb_strtolower($tag);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $result[] = $tag;
            if (count($result) > self::MAX_TAGS) {
                throw new HttpException('标签最多 ' . self::MAX_TAGS . ' 个', 0, 422);
            }
        }
        return $result;
    }

    public static function encode($value): string
    {
        return json_encode(self::normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    public static function decode($value): array
    {
        if (!is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) return [];
        try { return self::normalize($decoded); }
        catch (HttpException $ignored) { return []; }
    }

    public static function hydrate(array $items): array
    {
        foreach ($items as &$item) {
            $item['tags'] = self::decode($item['tags_json'] ?? null);
            unset($item['tags_json']);
        }
        unset($item);
        return $items;
    }
}
