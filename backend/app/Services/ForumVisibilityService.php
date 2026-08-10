<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;

/**
 * Applies legacy whole-post purchase entitlements before forum media is loaded.
 *
 * New chapter unlocks are redacted by ForumExperienceService. Older posts use
 * forum_paid_contents/forum_post_purchases, so every non-governance collection
 * must pass through this service instead of hydrating forum_post attachments
 * directly.
 */
final class ForumVisibilityService
{
    public static function hydratePosts(
        array $items,
        int $appId,
        ?int $viewerUserId,
        bool $hydrateMedia = true
    ): array {
        if ($items === []) return [];

        $postIds = [];
        foreach ($items as $item) {
            $postId = (int) ($item['id'] ?? 0);
            if ($postId > 0) $postIds[$postId] = true;
        }
        if ($postIds === []) return $items;

        $ids = array_keys($postIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $paidByPost = [];
        foreach (Database::all(
            "SELECT paid.post_id, paid.price_integral, paid.preview_content
             FROM forum_paid_contents paid
             INNER JOIN forum_posts post ON post.id = paid.post_id
             WHERE post.app_id = ? AND paid.status = 1 AND paid.post_id IN ({$placeholders})",
            array_merge([$appId], $ids)
        ) as $paid) {
            $paidByPost[(int) $paid['post_id']] = $paid;
        }

        $purchased = [];
        if ($viewerUserId !== null && $viewerUserId > 0 && $paidByPost !== []) {
            $paidIds = array_keys($paidByPost);
            $paidPlaceholders = implode(',', array_fill(0, count($paidIds), '?'));
            foreach (Database::all(
                "SELECT purchase.post_id
                 FROM forum_post_purchases purchase
                 INNER JOIN forum_posts post ON post.id = purchase.post_id
                 WHERE post.app_id = ? AND purchase.buyer_user_id = ?
                   AND purchase.post_id IN ({$paidPlaceholders})",
                array_merge([$appId, $viewerUserId], $paidIds)
            ) as $purchase) {
                $purchased[(int) $purchase['post_id']] = true;
            }
        }

        $mediaItems = [];
        $mediaKeys = [];
        foreach ($items as $key => &$item) {
            $postId = (int) ($item['id'] ?? 0);
            $paid = $paidByPost[$postId] ?? null;
            $owner = $viewerUserId !== null
                && $viewerUserId > 0
                && (int) ($item['user_id'] ?? $item['author_user_id'] ?? 0) === $viewerUserId;
            $unlocked = $paid === null || $owner || isset($purchased[$postId]);

            $item['paid_content'] = $paid !== null;
            $item['purchased'] = $unlocked;
            $item['attachments_locked'] = $paid !== null && !$unlocked;
            if ($paid !== null) {
                $item['paid_price_balance'] = (int) $paid['price_integral'];
            }

            if (!$unlocked) {
                self::redactLockedPost($item, (string) ($paid['preview_content'] ?? ''));
                continue;
            }
            if ($hydrateMedia) {
                $mediaKeys[] = $key;
                $mediaItems[] = $item;
            }
        }
        unset($item);

        if ($hydrateMedia && $mediaItems !== []) {
            $hydrated = MessageMediaService::hydrate($mediaItems, 'forum_post', $appId);
            foreach ($mediaKeys as $index => $key) $items[$key] = $hydrated[$index];
        }
        return $items;
    }

    /**
     * Keyword matching may use the public preview, but never a locked original
     * body. The returned parameter order exactly matches the generated SQL.
     *
     * @return array{sql:string,params:array}
     */
    public static function keywordClause(
        string $postAlias,
        string $keyword,
        ?int $viewerUserId,
        bool $includeId = true
    ): array {
        $needle = '%' . $keyword . '%';
        $clauses = ["{$postAlias}.title LIKE ?"];
        $params = [$needle];
        if ($includeId) {
            $clauses[] = "CAST({$postAlias}.id AS CHAR) LIKE ?";
            $params[] = $needle;
        }
        $clauses[] = "EXISTS(
            SELECT 1 FROM forum_paid_contents visibility_preview
            WHERE visibility_preview.post_id = {$postAlias}.id
              AND visibility_preview.status = 1
              AND visibility_preview.preview_content LIKE ?
        )";
        $params[] = $needle;

        $entitlement = self::legacyUnlockedClause($postAlias, $viewerUserId);
        $clauses[] = "(({$postAlias}.tags_json LIKE ? OR {$postAlias}.content LIKE ?)
            AND ({$entitlement['sql']}))";
        array_push($params, $needle, $needle, ...$entitlement['params']);
        return ['sql' => '(' . implode(' OR ', $clauses) . ')', 'params' => $params];
    }

    /** @return array{sql:string,params:array} */
    public static function legacyUnlockedClause(string $postAlias, ?int $viewerUserId): array
    {
        $sql = "NOT EXISTS(
            SELECT 1 FROM forum_paid_contents visibility_paid
            WHERE visibility_paid.post_id = {$postAlias}.id AND visibility_paid.status = 1
        )";
        $params = [];
        if ($viewerUserId !== null && $viewerUserId > 0) {
            $sql .= " OR {$postAlias}.user_id = ? OR EXISTS(
                SELECT 1 FROM forum_post_purchases visibility_purchase
                WHERE visibility_purchase.post_id = {$postAlias}.id
                  AND visibility_purchase.buyer_user_id = ?
            )";
            $params = [$viewerUserId, $viewerUserId];
        }
        return ['sql' => $sql, 'params' => $params];
    }

    private static function redactLockedPost(array &$item, string $preview): void
    {
        $item['content'] = $preview;
        $item['tags'] = [];
        if (array_key_exists('tags_json', $item)) $item['tags_json'] = '[]';
        $item['images'] = [];
        if (array_key_exists('images_json', $item)) $item['images_json'] = '[]';
        $item['attachments'] = [];
        $item['attachment_count'] = 0;
        $item['has_media'] = false;
        $item['media_summary'] = '';
    }
}
