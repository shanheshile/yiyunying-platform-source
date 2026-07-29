<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class ForumTaxonomyService
{
    public static function categoryId(int $adminId, int $appId, int $plateId, $rawCategoryId): ?int
    {
        $categoryId = (int) ($rawCategoryId ?? 0);
        if ($categoryId <= 0) return null;
        $category = Database::one(
            'SELECT id FROM forum_categories
             WHERE id = ? AND admin_id = ? AND app_id = ? AND plate_id = ? AND status = 1',
            [$categoryId, $adminId, $appId, $plateId]
        );
        if ($category === null) {
            throw new HttpException('所选二级分类不存在或不属于当前板块', 0, 422);
        }
        return $categoryId;
    }

    public static function normalizeTags(int $appId, int $plateId, ?int $categoryId, $rawTags): array
    {
        $input = self::parseList($rawTags);
        if ($input === []) return [];

        $rows = Database::all(
            'SELECT name, aliases_json FROM forum_tags
             WHERE app_id = ? AND plate_id = ? AND status = 1
               AND (category_id IS NULL OR category_id = ?)
             ORDER BY sort_order DESC, id ASC',
            [$appId, $plateId, $categoryId]
        );
        $canonical = [];
        foreach ($rows as $row) {
            $name = trim((string) $row['name']);
            if ($name === '') continue;
            $canonical[self::key($name)] = $name;
            $aliases = json_decode((string) ($row['aliases_json'] ?? '[]'), true);
            if (!is_array($aliases)) $aliases = [];
            foreach ($aliases as $alias) {
                $alias = trim((string) $alias);
                if ($alias !== '') $canonical[self::key($alias)] = $name;
            }
        }

        $result = [];
        $seen = [];
        foreach ($input as $tag) {
            $tag = mb_substr(ltrim(trim((string) $tag), '#'), 0, 30);
            if ($tag === '') continue;
            $resolved = $canonical[self::key($tag)] ?? $tag;
            $key = self::key($resolved);
            if (isset($seen[$key])) continue;
            if (count($result) >= 10) {
                throw new HttpException('帖子标签最多 10 个', 0, 422);
            }
            $seen[$key] = true;
            $result[] = $resolved;
        }
        return $result;
    }

    public static function parseList($value): array
    {
        if (is_array($value)) return array_values($value);
        $text = trim((string) $value);
        if ($text === '') return [];
        if (str_starts_with($text, '[')) {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) return array_values($decoded);
        }
        return preg_split('/[\s,，、#]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public static function encodeAliases($value): string
    {
        $aliases = [];
        $seen = [];
        foreach (self::parseList($value) as $alias) {
            $alias = mb_substr(trim((string) $alias), 0, 80);
            if ($alias === '') continue;
            $key = self::key($alias);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $aliases[] = $alias;
            if (count($aliases) >= 30) break;
        }
        return json_encode($aliases, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
