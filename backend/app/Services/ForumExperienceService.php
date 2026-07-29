<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;

final class ForumExperienceService
{
    private const TARGETS = ['post', 'comment'];

    public static function recordView(Request $request, array $post, ?array $user = null): array
    {
        $postId = (int) $post['id'];
        $appId = (int) $post['app_id'];
        $viewerKey = hash('sha256', $user === null
            ? 'public|' . $appId . '|' . $request->clientIp() . '|' . $request->userAgent()
            : 'user|' . $appId . '|' . (int) $user['id']);
        $created = Database::transaction(static function () use ($post, $postId, $appId, $viewerKey, $user): bool {
            $inserted = Database::execute(
                'INSERT IGNORE INTO forum_unique_views
                 (admin_id, app_id, post_id, viewer_key, user_id, view_count, first_viewed_at, last_viewed_at)
                 VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())',
                [(int) $post['admin_id'], $appId, $postId, $viewerKey, $user === null ? null : (int) $user['id']]
            );
            if ($inserted > 0) {
                Database::execute(
                    'UPDATE forum_posts SET view_count = view_count + 1, unique_view_count = unique_view_count + 1 WHERE id = ?',
                    [$postId]
                );
            } else {
                Database::execute(
                    'UPDATE forum_unique_views SET view_count = view_count + 1, last_viewed_at = NOW()
                     WHERE post_id = ? AND viewer_key = ?',
                    [$postId, $viewerKey]
                );
            }
            if ($user !== null) {
                Database::execute(
                    'INSERT INTO forum_view_history (post_id, user_id, view_count, last_viewed_at)
                     VALUES (?, ?, 1, NOW())
                     ON DUPLICATE KEY UPDATE view_count = view_count + 1, last_viewed_at = NOW()',
                    [$postId, (int) $user['id']]
                );
            }
            return $inserted > 0;
        });
        self::refreshHeat($postId, $appId);
        $counts = Database::one(
            'SELECT view_count, unique_view_count, heat_score, hot_label FROM forum_posts WHERE id = ?',
            [$postId]
        ) ?? [];
        return ['unique_view' => $created] + $counts;
    }

    public static function refreshHeat(int $postId, int $appId): array
    {
        $post = Database::one(
            'SELECT unique_view_count, view_count, like_count, comment_count, created_at, updated_at
             FROM forum_posts WHERE id = ? AND app_id = ?',
            [$postId, $appId]
        );
        if ($post === null) return ['heat_score' => 0, 'hot_label' => ''];
        $approvedComments = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM forum_comments
             WHERE post_id = ? AND app_id = ? AND status = 1 AND audit_status = 'approved'",
            [$postId, $appId]
        )['total'] ?? 0);
        $ageHours = max(0, (time() - (strtotime((string) $post['created_at']) ?: time())) / 3600);
        $recency = max(0, 48 - (int) floor($ageHours));
        $score = max((int) $post['unique_view_count'], (int) $post['view_count'])
            + ((int) $post['like_count'] * 4)
            + ($approvedComments * 6)
            + $recency;
        $enabled = (bool) AppService::setting($appId, 'forum_hot_enabled', true);
        $threshold = max(1, (int) AppService::setting($appId, 'forum_hot_score_threshold', 40));
        $windowDays = max(1, (int) AppService::setting($appId, 'forum_hot_window_days', 14));
        $hotLabel = '';
        if ($enabled && $ageHours <= $windowDays * 24 && $score >= $threshold) {
            if ($approvedComments >= 8) {
                $hotLabel = '近期高讨论';
            } elseif ($approvedComments >= 3 && $ageHours <= 72) {
                $hotLabel = '近期热议';
            } elseif ((int) $post['like_count'] >= 8
                || max((int) $post['unique_view_count'], (int) $post['view_count']) >= 80) {
                $hotLabel = '最近火热';
            }
        }
        Database::execute(
            'UPDATE forum_posts SET comment_count = ?, heat_score = ?, hot_label = ?,
             last_activity_at = COALESCE(last_activity_at, updated_at, created_at)
             WHERE id = ? AND app_id = ?',
            [$approvedComments, $score, $hotLabel, $postId, $appId]
        );
        return ['comment_count' => $approvedComments, 'heat_score' => $score, 'hot_label' => $hotLabel];
    }

    public static function sections(array $post, ?array $user, bool $privileged = false): array
    {
        $items = Database::all(
            'SELECT * FROM forum_post_sections WHERE post_id = ? AND app_id = ? AND status = 1 ORDER BY sort_order, id',
            [(int) $post['id'], (int) $post['app_id']]
        );
        if ($items === []) return [];
        $items = ContentTagService::hydrate($items);
        $items = MessageMediaService::hydrate($items, 'forum_section', (int) $post['app_id']);
        $owned = [];
        if ($user !== null) {
            foreach (Database::all(
                'SELECT section_id FROM forum_section_purchases WHERE app_id = ? AND buyer_user_id = ? AND post_id = ?',
                [(int) $post['app_id'], (int) $user['id'], (int) $post['id']]
            ) as $purchase) $owned[(int) $purchase['section_id']] = true;
        }
        foreach ($items as &$section) {
            $section['price_balance'] = (float) $section['price_balance'];
            $unlocked = (string) $section['section_type'] === 'free' || $privileged
                || ($user !== null && ((int) $post['user_id'] === (int) $user['id'] || isset($owned[(int) $section['id']])));
            $section['unlocked'] = $unlocked;
            $section['locked'] = !$unlocked;
            if (!$unlocked) {
                $section['content'] = '';
                $section['tags'] = [];
                $section['attachments'] = [];
                $section['attachment_count'] = 0;
                $section['has_media'] = false;
            }
        }
        unset($section);
        return $items;
    }

    public static function createSections(array $user, int $postId, $rawSections): array
    {
        if (is_string($rawSections)) $rawSections = json_decode($rawSections, true);
        if (!is_array($rawSections) || $rawSections === []) return [];
        $maximum = max(1, (int) AppService::setting((int) $user['app_id'], 'forum_paid_section_max_count', 30));
        if (count($rawSections) > $maximum) throw new HttpException('单个帖子最多可创建 ' . $maximum . ' 个内容节', 0, 422);
        $ids = [];
        foreach (array_values($rawSections) as $index => $raw) {
            if (!is_array($raw)) throw new HttpException('第 ' . ($index + 1) . ' 个内容节格式错误', 0, 422);
            $ids[] = self::insertSection($user, $postId, $raw, $index);
        }
        return $ids;
    }

    public static function createSection(array $user, int $postId, array $data): int
    {
        self::ownedPost($user, $postId);
        $count = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM forum_post_sections WHERE post_id = ? AND status = 1', [$postId]
        )['total'] ?? 0);
        $maximum = max(1, (int) AppService::setting((int) $user['app_id'], 'forum_paid_section_max_count', 30));
        if ($count >= $maximum) throw new HttpException('内容节数量已达到管理员设置的上限', 0, 422);
        $nextOrder = (int) (Database::one(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_order FROM forum_post_sections WHERE post_id = ?',
            [$postId]
        )['next_order'] ?? 0);
        return self::insertSection($user, $postId, $data, $nextOrder);
    }

    public static function updateSection(array $user, int $postId, int $sectionId, array $data): void
    {
        self::ownedPost($user, $postId);
        $section = Database::one(
            'SELECT * FROM forum_post_sections WHERE id = ? AND post_id = ? AND author_user_id = ? AND status = 1',
            [$sectionId, $postId, (int) $user['id']]
        );
        if ($section === null) throw new HttpException('内容节不存在或无权修改', 404, 404);
        $type = strtolower(trim((string) ($data['section_type'] ?? $section['section_type'])));
        if (!in_array($type, ['free', 'paid'], true)) throw new HttpException('section_type 仅支持 free 或 paid', 0, 422);
        $price = $type === 'paid' ? round((float) ($data['price_balance'] ?? $section['price_balance']), 2) : 0.0;
        if ($type === 'paid' && $price <= 0) throw new HttpException('付费节价格必须大于 0', 0, 422);
        $hasPayload = array_key_exists('content', $data) || array_key_exists('attachments', $data);
        $payload = null;
        if ($hasPayload) {
            $payloadData = $data;
            $payloadData['content'] = (string) ($data['content'] ?? $section['content']);
            $payload = MessageMediaService::userPayload($user, $payloadData);
        }
        Database::transaction(static function () use ($sectionId, $section, $data, $type, $price, $payload, $user): void {
            Database::execute(
                'UPDATE forum_post_sections SET section_type = ?, title = ?, content = ?, tags_json = ?, price_balance = ?, updated_at = NOW() WHERE id = ?',
                [
                    $type, mb_substr(trim((string) ($data['title'] ?? $section['title'])), 0, 160),
                    $payload === null ? (string) ($data['content'] ?? $section['content']) : (string) $payload['content'],
                    array_key_exists('tags', $data) ? ContentTagService::encode($data['tags']) : (string) ($section['tags_json'] ?? '[]'),
                    $price, $sectionId,
                ]
            );
            if ($payload !== null) MessageMediaService::replace('forum_section', $sectionId, $payload);
        });
    }

    public static function deleteSection(array $user, int $postId, int $sectionId): void
    {
        self::ownedPost($user, $postId);
        if (Database::one('SELECT id FROM forum_section_purchases WHERE section_id = ? LIMIT 1', [$sectionId])) {
            throw new HttpException('该付费节已有购买记录，为保护购买者权益不能删除，可以继续修改内容', 0, 409);
        }
        $changed = Database::execute(
            'UPDATE forum_post_sections SET status = -1, updated_at = NOW()
             WHERE id = ? AND post_id = ? AND author_user_id = ? AND status = 1',
            [$sectionId, $postId, (int) $user['id']]
        );
        if ($changed === 0) throw new HttpException('内容节不存在或无权删除', 404, 404);
        Database::execute(
            "DELETE FROM media_attachments WHERE app_id = ? AND target_type = 'forum_section' AND target_id = ?",
            [(int) $user['app_id'], $sectionId]
        );
    }

    public static function reorderSections(array $user, int $postId, $rawIds): array
    {
        self::ownedPost($user, $postId);
        if (is_string($rawIds)) $rawIds = json_decode($rawIds, true);
        if (!is_array($rawIds) || $rawIds === []) throw new HttpException('section_ids 必须是非空数组', 0, 422);
        $ids = array_values(array_unique(array_map('intval', $rawIds)));
        $existing = Database::all(
            'SELECT id FROM forum_post_sections WHERE post_id = ? AND author_user_id = ? AND status = 1 ORDER BY sort_order, id',
            [$postId, (int) $user['id']]
        );
        $expected = array_map(static fn(array $item): int => (int) $item['id'], $existing);
        $sortedA = $ids; $sortedB = $expected; sort($sortedA); sort($sortedB);
        if ($sortedA !== $sortedB) throw new HttpException('section_ids 必须完整包含当前帖子的全部内容节', 0, 422);
        Database::transaction(static function () use ($ids, $postId): void {
            Database::execute('UPDATE forum_post_sections SET sort_order = sort_order + 10000 WHERE post_id = ? AND status = 1', [$postId]);
            foreach ($ids as $order => $id) Database::execute(
                'UPDATE forum_post_sections SET sort_order = ?, updated_at = NOW() WHERE id = ? AND post_id = ?',
                [$order, $id, $postId]
            );
        });
        return $ids;
    }

    public static function buySection(array $user, int $postId, int $sectionId): array
    {
        return Database::transaction(static function () use ($user, $postId, $sectionId): array {
            $section = Database::one(
                "SELECT s.*, p.user_id AS seller_user_id, p.title AS post_title
                 FROM forum_post_sections s INNER JOIN forum_posts p ON p.id = s.post_id
                 WHERE s.id = ? AND s.post_id = ? AND s.app_id = ? AND s.section_type = 'paid'
                   AND s.status = 1 AND p.status = 1 AND p.deleted_at IS NULL FOR UPDATE",
                [$sectionId, $postId, (int) $user['app_id']]
            );
            if ($section === null) throw new HttpException('付费内容节不存在', 404, 404);
            if ((int) $section['seller_user_id'] === (int) $user['id']) return ['already_owned' => true, 'price_balance' => 0.0];
            if (Database::one(
                'SELECT id FROM forum_section_purchases WHERE section_id = ? AND buyer_user_id = ?',
                [$sectionId, (int) $user['id']]
            )) return ['already_owned' => true, 'price_balance' => (float) $section['price_balance']];
            $seller = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], (int) $section['seller_user_id']);
            if ($seller === null) throw new HttpException('内容作者不存在', 404, 404);
            $price = (float) $section['price_balance'];
            $asset = WalletService::requireActivityEnabled((int) $user['app_id']);
            WalletService::adjust($user, $asset, -$price, 'forum_section_buy', 'forum_section', $sectionId, '购买论坛付费内容节');
            WalletService::adjust($seller, $asset, $price, 'forum_section_sale', 'forum_section', $sectionId, '论坛付费内容节收入');
            Database::execute(
                'INSERT INTO forum_section_purchases
                 (admin_id, app_id, post_id, section_id, buyer_user_id, seller_user_id, price_balance, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], $postId, $sectionId, (int) $user['id'], (int) $seller['id'], $price]
            );
            NotificationService::send(
                $seller, 'forum_section_sale', '付费内容节售出', '《' . (string) $section['post_title'] . '》中的付费内容已售出',
                ['post_id' => $postId, 'section_id' => $sectionId, 'balance' => $price]
            );
            return ['already_owned' => false, 'price_balance' => $price];
        });
    }

    public static function toggleLike(array $user, string $targetType, int $targetId): bool
    {
        $target = self::target($user, $targetType, $targetId);
        $existing = Database::one(
            'SELECT id FROM forum_likes WHERE app_id = ? AND user_id = ? AND target_type = ? AND target_id = ?',
            [(int) $user['app_id'], (int) $user['id'], $targetType, $targetId]
        );
        $liked = Database::transaction(static function () use ($user, $targetType, $targetId, $target, $existing): bool {
            $table = $targetType === 'post' ? 'forum_posts' : 'forum_comments';
            if ($existing !== null) {
                Database::execute('DELETE FROM forum_likes WHERE id = ?', [(int) $existing['id']]);
                Database::execute("UPDATE {$table} SET like_count = GREATEST(0, like_count - 1) WHERE id = ?", [$targetId]);
                return false;
            }
            Database::execute(
                'INSERT INTO forum_likes (admin_id, app_id, user_id, target_type, target_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $targetType, $targetId]
            );
            Database::execute("UPDATE {$table} SET like_count = like_count + 1 WHERE id = ?", [$targetId]);
            return true;
        });
        self::refreshHeat((int) ($target['post_id'] ?? $targetId), (int) $user['app_id']);
        return $liked;
    }

    public static function toggleFavorite(array $user, string $targetType, int $targetId): bool
    {
        self::target($user, $targetType, $targetId);
        $existing = Database::one(
            'SELECT id FROM forum_content_favorites WHERE app_id = ? AND user_id = ? AND target_type = ? AND target_id = ?',
            [(int) $user['app_id'], (int) $user['id'], $targetType, $targetId]
        );
        if ($existing === null && $targetType === 'post') {
            $existing = Database::one('SELECT id FROM forum_favorites WHERE app_id = ? AND user_id = ? AND post_id = ?',
                [(int) $user['app_id'], (int) $user['id'], $targetId]);
        }
        return Database::transaction(static function () use ($user, $targetType, $targetId, $existing): bool {
            if ($existing !== null) {
                Database::execute(
                    'DELETE FROM forum_content_favorites WHERE app_id = ? AND user_id = ? AND target_type = ? AND target_id = ?',
                    [(int) $user['app_id'], (int) $user['id'], $targetType, $targetId]
                );
                if ($targetType === 'post') Database::execute(
                    'DELETE FROM forum_favorites WHERE app_id = ? AND user_id = ? AND post_id = ?',
                    [(int) $user['app_id'], (int) $user['id'], $targetId]
                );
                else Database::execute('UPDATE forum_comments SET favorite_count = GREATEST(0, favorite_count - 1) WHERE id = ?', [$targetId]);
                return false;
            }
            Database::execute(
                'INSERT INTO forum_content_favorites (admin_id, app_id, user_id, target_type, target_id, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $targetType, $targetId]
            );
            if ($targetType === 'post') Database::execute(
                'INSERT IGNORE INTO forum_favorites (admin_id, app_id, post_id, user_id, created_at) VALUES (?, ?, ?, ?, NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], $targetId, (int) $user['id']]
            );
            else Database::execute('UPDATE forum_comments SET favorite_count = favorite_count + 1 WHERE id = ?', [$targetId]);
            return true;
        });
    }

    public static function setCommentPin(array $user, int $postId, int $commentId, bool $enabled, int $order): array
    {
        $post = self::ownedPost($user, $postId);
        $comment = Database::one(
            'SELECT id FROM forum_comments WHERE id = ? AND post_id = ? AND app_id = ? AND status = 1',
            [$commentId, $postId, (int) $user['app_id']]
        );
        if ($comment === null) throw new HttpException('评论或回复不存在', 404, 404);
        if ($enabled) {
            $limit = max(0, (int) AppService::setting((int) $user['app_id'], 'forum_self_comment_pin_limit', 3));
            $count = (int) (Database::one(
                'SELECT COUNT(*) AS total FROM forum_comments WHERE post_id = ? AND is_pinned = 1 AND status = 1 AND id <> ?',
                [$postId, $commentId]
            )['total'] ?? 0);
            if ($limit === 0 || $count >= $limit) throw new HttpException('置顶评论数量已达到管理员设置的上限', 0, 422);
        }
        Database::execute(
            'UPDATE forum_comments SET is_pinned = ?, pin_order = ?, updated_at = NOW() WHERE id = ?',
            [$enabled ? 1 : 0, $enabled ? $order : 0, $commentId]
        );
        return ['enabled' => $enabled, 'pin_order' => $enabled ? $order : 0, 'post_id' => (int) $post['id']];
    }

    public static function setPersonalPosition(array $user, string $targetType, int $targetId, string $position, int $order): array
    {
        if (!in_array($targetType, ['plate', 'post'], true)) throw new HttpException('target_type 仅支持 plate 或 post', 0, 422);
        $position = strtolower(trim($position));
        if (!in_array($position, ['normal', 'top', 'bottom'], true)) throw new HttpException('position 仅支持 normal、top 或 bottom', 0, 422);
        $exists = $targetType === 'plate'
            ? Database::one('SELECT id FROM forum_plates WHERE id = ? AND app_id = ? AND status = 1', [$targetId, (int) $user['app_id']])
            : Database::one('SELECT id FROM forum_posts WHERE id = ? AND app_id = ? AND status = 1 AND deleted_at IS NULL', [$targetId, (int) $user['app_id']]);
        if ($exists === null) throw new HttpException($targetType === 'plate' ? '论坛板块不存在' : '帖子不存在', 404, 404);
        if ($position === 'normal') {
            Database::execute(
                'DELETE FROM forum_personal_positions WHERE user_id = ? AND target_type = ? AND target_id = ?',
                [(int) $user['id'], $targetType, $targetId]
            );
        } else {
            $setting = $targetType === 'plate' ? 'forum_personal_plate_pin_limit' : 'forum_personal_post_pin_limit';
            $limit = max(0, (int) AppService::setting((int) $user['app_id'], $setting, 20));
            $count = (int) (Database::one(
                'SELECT COUNT(*) AS total FROM forum_personal_positions WHERE user_id = ? AND target_type = ? AND position = ? AND target_id <> ?',
                [(int) $user['id'], $targetType, $position, $targetId]
            )['total'] ?? 0);
            if ($limit === 0 || $count >= $limit) throw new HttpException('个人排序数量已达到管理员设置的上限', 0, 422);
            Database::execute(
                'INSERT INTO forum_personal_positions
                 (admin_id, app_id, user_id, target_type, target_id, position, sort_order, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE position = VALUES(position), sort_order = VALUES(sort_order), updated_at = NOW()',
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $targetType, $targetId, $position, $order]
            );
        }
        return ['target_type' => $targetType, 'target_id' => $targetId, 'position' => $position, 'sort_order' => $order];
    }

    public static function recordForward(array $user, string $targetType, int $targetId, string $destinationType, int $destinationId): int
    {
        self::target($user, $targetType, $targetId);
        if (!in_array($destinationType, ['private', 'group', 'room', 'forum', 'service'], true)) {
            throw new HttpException('不支持的转发目标', 0, 422);
        }
        return Database::insert(
            'INSERT INTO forum_content_forwards
             (admin_id, app_id, user_id, target_type, target_id, destination_type, destination_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $targetType, $targetId, $destinationType, $destinationId]
        );
    }

    private static function insertSection(array $user, int $postId, array $data, int $order): int
    {
        $type = strtolower(trim((string) ($data['section_type'] ?? 'free')));
        if (!in_array($type, ['free', 'paid'], true)) throw new HttpException('section_type 仅支持 free 或 paid', 0, 422);
        $price = $type === 'paid' ? round((float) ($data['price_balance'] ?? 0), 2) : 0.0;
        if ($type === 'paid' && $price <= 0) throw new HttpException('付费节价格必须大于 0', 0, 422);
        $payload = MessageMediaService::userPayload($user, $data);
        return Database::transaction(static function () use ($user, $postId, $data, $order, $type, $price, $payload): int {
            $id = Database::insert(
                'INSERT INTO forum_post_sections
                 (admin_id, app_id, post_id, author_user_id, section_type, title, content, tags_json,
                  price_balance, sort_order, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], $postId, (int) $user['id'], $type,
                    mb_substr(trim((string) ($data['title'] ?? '')), 0, 160), (string) $payload['content'],
                    ContentTagService::encode($data['tags'] ?? []), $price, $order,
                ]
            );
            MessageMediaService::save('forum_section', $id, $payload);
            return $id;
        });
    }

    private static function target(array $user, string $targetType, int $targetId): array
    {
        if (!in_array($targetType, self::TARGETS, true)) throw new HttpException('target_type 仅支持 post 或 comment', 0, 422);
        $target = $targetType === 'post'
            ? Database::one('SELECT id, id AS post_id, user_id FROM forum_posts WHERE id = ? AND app_id = ? AND status = 1 AND deleted_at IS NULL', [$targetId, (int) $user['app_id']])
            : Database::one('SELECT id, post_id, user_id FROM forum_comments WHERE id = ? AND app_id = ? AND status = 1', [$targetId, (int) $user['app_id']]);
        if ($target === null) throw new HttpException($targetType === 'post' ? '帖子不存在' : '评论或回复不存在', 404, 404);
        return $target;
    }

    private static function ownedPost(array $user, int $postId): array
    {
        $post = Database::one(
            'SELECT * FROM forum_posts WHERE id = ? AND app_id = ? AND user_id = ? AND status = 1 AND deleted_at IS NULL',
            [$postId, (int) $user['app_id'], (int) $user['id']]
        );
        if ($post === null) throw new HttpException('帖子不存在或无权操作', 404, 404);
        return $post;
    }
}
