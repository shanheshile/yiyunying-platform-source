<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;

final class ForumExperienceService
{
    private const TARGETS = ['post', 'comment'];
    private const SECTION_TYPES = ['free', 'paid', 'scheduled', 'paid_or_scheduled'];
    private const DEFAULT_UNLOCK_MAX_FUTURE_DAYS = 3650;
    private const ABSOLUTE_UNLOCK_MAX_FUTURE_DAYS = 36500;
    private const DEFAULT_UNLOCK_MAX_PRICE_BALANCE = 1000000000.0;
    private const ABSOLUTE_UNLOCK_MAX_PRICE_BALANCE = 1000000000.0;

    /**
     * The caller must hold the forum_posts row lock inside a transaction before
     * invoking this guard. Locking the purchase rows as well makes the decision
     * stable until the enclosing mutation commits.
     */
    public static function assertPostPurchaseSafeMutation(int $postId): void
    {
        $wholePostPurchase = Database::one(
            'SELECT id FROM forum_post_purchases WHERE post_id = ? LIMIT 1 FOR UPDATE',
            [$postId]
        );
        $sectionPurchase = Database::one(
            'SELECT id FROM forum_section_purchases WHERE post_id = ? LIMIT 1 FOR UPDATE',
            [$postId]
        );
        if ($wholePostPurchase !== null || $sectionPurchase !== null) {
            throw new HttpException(
                '该帖子已有整帖或内容节购买记录，为保护购买者权益禁止修改正文、媒体或删除帖子',
                0,
                409
            );
        }
    }

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
        $appId = (int) $post['app_id'];
        $items = Database::all(
            'SELECT * FROM forum_post_sections WHERE post_id = ? AND app_id = ? AND status = 1 ORDER BY sort_order, id',
            [(int) $post['id'], $appId]
        );
        if ($items === []) return [];
        $items = ContentTagService::hydrate($items);
        $owned = [];
        if ($user !== null) {
            foreach (Database::all(
                'SELECT section_id FROM forum_section_purchases WHERE app_id = ? AND buyer_user_id = ? AND post_id = ?',
                [$appId, (int) $user['id'], (int) $post['id']]
            ) as $purchase) $owned[(int) $purchase['section_id']] = true;
        }
        $paidRuntimeEnabled = $user !== null
            && AppService::featureEnabled($appId, 'forum_paid_unlock', true)
            && (bool) AppService::setting($appId, 'forum_paid_content_enabled', true);
        $scheduledRuntimeEnabled = AppService::featureEnabled($appId, 'forum_scheduled_unlock', true);
        $maximumPrice = self::maximumUnlockPrice($appId);
        $legacyPostUnlocked = $privileged
            || !((bool) ($post['paid_content'] ?? false))
            || (bool) ($post['purchased'] ?? false);
        foreach ($items as &$section) {
            $section['price_balance'] = (float) $section['price_balance'];
            $type = (string) $section['section_type'];
            $purchased = $user !== null && isset($owned[(int) $section['id']]);
            $scheduled = $scheduledRuntimeEnabled
                && self::scheduledUnlocked($section['unlock_at'] ?? null);
            $owner = $user !== null && (int) $post['user_id'] === (int) $user['id'];
            $sectionUnlocked = $type === 'free' || $privileged || $owner
                || ($type === 'paid' && $purchased)
                || ($type === 'scheduled' && $scheduled)
                || ($type === 'paid_or_scheduled' && ($purchased || $scheduled));
            $unlocked = $legacyPostUnlocked && $sectionUnlocked;
            $section['unlocked'] = $unlocked;
            $section['locked'] = !$unlocked;
            $section['purchased'] = $purchased;
            $section['scheduled_unlocked'] = $scheduled;
            $section['blocked_by_post_purchase'] = !$legacyPostUnlocked;
            $priceAllowed = is_finite($section['price_balance'])
                && $section['price_balance'] >= 0.01
                && $section['price_balance'] <= $maximumPrice;
            $section['can_buy'] = $legacyPostUnlocked
                && $paidRuntimeEnabled
                && $priceAllowed
                && !$unlocked
                && in_array($type, ['paid', 'paid_or_scheduled'], true);
            $section['unlock_at_iso'] = self::unlockAtIso($section['unlock_at'] ?? null);
            $section['unlock_label'] = self::unlockLabel($type, $section['unlock_at_iso'], $section['price_balance']);
            if (!$unlocked) {
                $section['content'] = '';
                $section['tags'] = [];
                $section['attachments'] = [];
                $section['attachment_count'] = 0;
                $section['has_media'] = false;
            }
        }
        unset($section);
        $unlockedKeys = [];
        $unlockedItems = [];
        foreach ($items as $key => $section) {
            if (!((bool) ($section['unlocked'] ?? false))) continue;
            $unlockedKeys[] = $key;
            $unlockedItems[] = $section;
        }
        if ($unlockedItems !== []) {
            $hydrated = MessageMediaService::hydrate($unlockedItems, 'forum_section', $appId);
            foreach ($unlockedKeys as $index => $key) $items[$key] = $hydrated[$index];
        }
        return $items;
    }

    public static function createSections(array $user, int $postId, $rawSections): array
    {
        if (is_string($rawSections)) $rawSections = json_decode($rawSections, true);
        if (!is_array($rawSections) || $rawSections === []) return [];
        AppService::requireFeature((int) $user['app_id'], 'forum_chapters');
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
        AppService::requireFeature((int) $user['app_id'], 'forum_chapters');
        return Database::transaction(static function () use ($user, $postId, $data): int {
            $post = Database::one(
                'SELECT id FROM forum_posts
                 WHERE id = ? AND app_id = ? AND user_id = ? AND status = 1 AND deleted_at IS NULL
                 FOR UPDATE',
                [$postId, (int) $user['app_id'], (int) $user['id']]
            );
            if ($post === null) throw new HttpException('帖子不存在或无权操作', 404, 404);
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
        });
    }

    public static function updateSection(array $user, int $postId, int $sectionId, array $data): void
    {
        self::ownedPost($user, $postId);
        AppService::requireFeature((int) $user['app_id'], 'forum_chapters');
        Database::transaction(static function () use ($user, $postId, $sectionId, $data): void {
            $section = Database::one(
                'SELECT * FROM forum_post_sections
                 WHERE id = ? AND post_id = ? AND author_user_id = ? AND status = 1 FOR UPDATE',
                [$sectionId, $postId, (int) $user['id']]
            );
            if ($section === null) throw new HttpException('内容节不存在或无权修改', 404, 404);
            if (Database::one(
                'SELECT id FROM forum_section_purchases WHERE section_id = ? LIMIT 1',
                [$sectionId]
            ) !== null) {
                throw new HttpException('该内容节已有购买记录，为保护购买者权益禁止修改', 0, 409);
            }
            $policy = self::sectionPolicy($user, $data, $section);
            $contentChanged = array_key_exists('content', $data);
            $attachmentsChanged = array_key_exists('attachments', $data);
            $content = $contentChanged ? trim((string) $data['content']) : (string) $section['content'];
            if (mb_strlen($content) > 10000) throw new HttpException('内容节正文不能超过 10000 个字符', 0, 422);
            $payload = null;
            if ($attachmentsChanged) {
                $payloadData = $data;
                $payloadData['content'] = $content;
                $payload = MessageMediaService::userPayload($user, $payloadData);
                $content = (string) $payload['content'];
            } elseif ($content === '' && self::sectionAttachmentCount($sectionId, (int) $user['app_id']) === 0) {
                throw new HttpException('内容节正文和附件不能同时为空', 0, 422);
            }
            if ($policy['type'] !== 'free') {
                if ($payload !== null) MessageMediaService::assertPrivateForumUploads($payload);
                else MessageMediaService::assertStoredPrivateForumAttachments($sectionId, (int) $user['app_id']);
            }
            Database::execute(
                'UPDATE forum_post_sections
                 SET section_type = ?, title = ?, content = ?, tags_json = ?, price_balance = ?, asset_type = ?,
                     unlock_at = ?, preview_content = ?, updated_at = NOW()
                 WHERE id = ?',
                [
                    $policy['type'], mb_substr(trim((string) ($data['title'] ?? $section['title'])), 0, 160),
                    $content,
                    array_key_exists('tags', $data) ? ContentTagService::encode($data['tags']) : (string) ($section['tags_json'] ?? '[]'),
                    $policy['price'], $policy['asset_type'], $policy['unlock_at'], $policy['preview'], $sectionId,
                ]
            );
            if ($payload !== null) MessageMediaService::replace('forum_section', $sectionId, $payload);
        });
    }

    public static function deleteSection(array $user, int $postId, int $sectionId): void
    {
        self::ownedPost($user, $postId);
        Database::transaction(static function () use ($user, $postId, $sectionId): void {
            $section = Database::one(
                'SELECT id FROM forum_post_sections
                 WHERE id = ? AND post_id = ? AND author_user_id = ? AND status = 1 FOR UPDATE',
                [$sectionId, $postId, (int) $user['id']]
            );
            if ($section === null) throw new HttpException('内容节不存在或无权删除', 404, 404);
            if (Database::one(
                'SELECT id FROM forum_section_purchases WHERE section_id = ? LIMIT 1',
                [$sectionId]
            ) !== null) {
                throw new HttpException('该付费节已有购买记录，为保护购买者权益不能删除或修改', 0, 409);
            }
            Database::execute(
                'UPDATE forum_post_sections SET status = -1, updated_at = NOW() WHERE id = ?',
                [$sectionId]
            );
            Database::execute(
                "DELETE FROM media_attachments WHERE app_id = ? AND target_type = 'forum_section' AND target_id = ?",
                [(int) $user['app_id'], $sectionId]
            );
        });
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
        AppService::requireFeature((int) $user['app_id'], 'forum_paid_unlock');
        if (!AppService::setting((int) $user['app_id'], 'forum_paid_content_enabled', true)) {
            throw new HttpException('管理员已关闭付费内容', 403, 403);
        }
        return Database::transaction(static function () use ($user, $postId, $sectionId): array {
            $section = Database::one(
                "SELECT s.*, p.user_id AS seller_user_id, p.title AS post_title
                 FROM forum_post_sections s INNER JOIN forum_posts p ON p.id = s.post_id
                 WHERE s.id = ? AND s.post_id = ? AND s.app_id = ?
                   AND s.section_type IN ('paid', 'paid_or_scheduled')
                   AND s.status = 1 AND p.status = 1 AND p.audit_status = 'approved'
                   AND p.deleted_at IS NULL FOR UPDATE",
                [$sectionId, $postId, (int) $user['app_id']]
            );
            if ($section === null) throw new HttpException('付费内容节不存在', 404, 404);
            if ((string) $section['section_type'] === 'paid_or_scheduled'
                && AppService::featureEnabled((int) $user['app_id'], 'forum_scheduled_unlock', true)
                && self::scheduledUnlocked($section['unlock_at'] ?? null)) {
                return ['already_owned' => true, 'released_by_schedule' => true, 'price_balance' => 0.0, 'asset_type' => 'balance'];
            }
            $assetType = strtolower(trim((string) ($section['asset_type'] ?? 'balance')));
            if ($assetType !== 'balance') throw new HttpException('付费内容节资产类型异常，请联系管理员', 0, 409);
            if ((int) $section['seller_user_id'] === (int) $user['id']) {
                return ['already_owned' => true, 'price_balance' => 0.0, 'asset_type' => $assetType];
            }
            $existingPurchase = Database::one(
                'SELECT id, asset_type FROM forum_section_purchases WHERE section_id = ? AND buyer_user_id = ?',
                [$sectionId, (int) $user['id']]
            );
            if ($existingPurchase !== null) {
                if (strtolower((string) ($existingPurchase['asset_type'] ?? 'balance')) !== $assetType) {
                    throw new HttpException('购买凭证资产类型不一致，请联系管理员', 0, 409);
                }
                return [
                    'already_owned' => true,
                    'price_balance' => WalletService::canonicalAmount('balance', $section['price_balance']),
                    'asset_type' => $assetType,
                ];
            }
            $seller = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], (int) $section['seller_user_id']);
            if ($seller === null) throw new HttpException('内容作者不存在', 404, 404);
            try {
                $price = WalletService::canonicalAmount('balance', $section['price_balance']);
                $priceUnits = WalletService::amountUnits('balance', $price);
            } catch (HttpException) {
                throw new HttpException('该内容节价格格式异常，暂不可购买', 0, 409);
            }
            $maximumPrice = self::maximumUnlockPrice((int) $user['app_id']);
            $maximumPriceUnits = WalletService::amountUnits('balance', (string) $maximumPrice);
            if ($priceUnits < 1 || $priceUnits > $maximumPriceUnits) {
                throw new HttpException('该内容节价格不符合当前管理员限制，暂不可购买', 0, 409);
            }
            WalletService::requireActivityEnabled((int) $user['app_id']);
            WalletService::adjust(
                $user,
                $assetType,
                WalletService::negativeAmount($assetType, $price),
                'forum_section_buy',
                'forum_section',
                $sectionId,
                '购买论坛付费内容节'
            );
            WalletService::adjust($seller, $assetType, $price, 'forum_section_sale', 'forum_section', $sectionId, '论坛付费内容节收入');
            Database::execute(
                'INSERT INTO forum_section_purchases
                 (admin_id, app_id, post_id, section_id, buyer_user_id, seller_user_id, price_balance, asset_type, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], $postId, $sectionId, (int) $user['id'], (int) $seller['id'], $price, $assetType]
            );
            NotificationService::send(
                $seller, 'forum_section_sale', '付费内容节售出', '《' . (string) $section['post_title'] . '》中的付费内容已售出',
                ['post_id' => $postId, 'section_id' => $sectionId, 'balance' => $price]
            );
            return ['already_owned' => false, 'price_balance' => $price, 'asset_type' => $assetType];
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
        $policy = self::sectionPolicy($user, $data);
        $payload = MessageMediaService::userPayload($user, $data);
        if ($policy['type'] !== 'free') MessageMediaService::assertPrivateForumUploads($payload);
        return Database::transaction(static function () use ($user, $postId, $data, $order, $policy, $payload): int {
            $id = Database::insert(
                'INSERT INTO forum_post_sections
                 (admin_id, app_id, post_id, author_user_id, section_type, title, content, tags_json,
                  price_balance, asset_type, unlock_at, preview_content, sort_order, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], $postId, (int) $user['id'], $policy['type'],
                    mb_substr(trim((string) ($data['title'] ?? '')), 0, 160), (string) $payload['content'],
                    ContentTagService::encode($data['tags'] ?? []), $policy['price'], $policy['asset_type'], $policy['unlock_at'],
                    $policy['preview'], $order,
                ]
            );
            MessageMediaService::save('forum_section', $id, $payload);
            return $id;
        });
    }

    private static function sectionPolicy(array $user, array $data, ?array $existing = null): array
    {
        $type = strtolower(trim((string) ($data['section_type'] ?? $existing['section_type'] ?? 'free')));
        if (!in_array($type, self::SECTION_TYPES, true)) {
            throw new HttpException('section_type 仅支持 free、paid、scheduled 或 paid_or_scheduled', 0, 422);
        }
        $appId = (int) $user['app_id'];
        $needsPayment = in_array($type, ['paid', 'paid_or_scheduled'], true);
        $needsSchedule = in_array($type, ['scheduled', 'paid_or_scheduled'], true);
        $storedAsset = strtolower(trim((string) ($existing['asset_type'] ?? 'balance')));
        $assetType = strtolower(trim((string) ($data['asset_type'] ?? $storedAsset)));
        if ($existing !== null && $assetType !== $storedAsset) {
            throw new HttpException('内容节资产类型创建后不可修改', 0, 422);
        }
        if ($assetType !== 'balance') throw new HttpException('内容节仅支持 balance 余额资产', 0, 422);
        if ($needsPayment) {
            AppService::requireFeature($appId, 'forum_paid_unlock');
            if (!AppService::setting($appId, 'forum_paid_content_enabled', true)) {
                throw new HttpException('管理员已关闭付费内容', 403, 403);
            }
        }
        if ($needsSchedule) AppService::requireFeature($appId, 'forum_scheduled_unlock');
        $hasProtectedAttachments = false;
        if (array_key_exists('attachments', $data)) {
            $attachments = $data['attachments'];
            if (is_string($attachments)) $attachments = json_decode($attachments, true);
            $hasProtectedAttachments = is_array($attachments) && $attachments !== [];
        } elseif ($existing !== null && (int) ($existing['id'] ?? 0) > 0) {
            $hasProtectedAttachments = self::sectionAttachmentCount((int) $existing['id'], $appId) > 0;
        }
        if ($type !== 'free' && $hasProtectedAttachments) {
            AppService::requireFeature($appId, 'forum_attachment_unlock');
        }
        $rawPrice = $data['price_balance'] ?? $existing['price_balance'] ?? 0;
        try {
            $price = $needsPayment
                ? WalletService::canonicalAmount('balance', $rawPrice)
                : '0.00';
            $priceUnits = WalletService::amountUnits('balance', $price, !$needsPayment);
        } catch (HttpException) {
            throw new HttpException('付费解锁价格最多保留两位小数，且必须是有效金额', 0, 422);
        }
        $maximumPrice = self::maximumUnlockPrice($appId);
        $maximumPriceUnits = WalletService::amountUnits('balance', (string) $maximumPrice);
        if ($needsPayment && ($priceUnits < 1 || $priceUnits > $maximumPriceUnits)) {
            throw new HttpException(
                '付费解锁价格必须在 0.01 到 ' . self::decimalLabel($maximumPrice) . ' 余额之间',
                0,
                422
            );
        }
        $unlockAt = null;
        if ($needsSchedule) {
            $unlockAt = array_key_exists('unlock_at', $data)
                ? self::normalizeNewUnlockAt($data['unlock_at'], $appId)
                : self::normalizeStoredUnlockAt($existing['unlock_at'] ?? null);
        }
        if ($needsSchedule && $unlockAt === null) throw new HttpException('定时解锁必须选择解锁日期和时间', 0, 422);
        $preview = mb_substr(trim((string) ($data['preview_content'] ?? $existing['preview_content'] ?? '')), 0, 1000);
        return [
            'type' => $type, 'price' => $price, 'asset_type' => $assetType,
            'unlock_at' => $unlockAt, 'preview' => $preview,
        ];
    }

    private static function sectionAttachmentCount(int $sectionId, int $appId): int
    {
        return (int) (Database::one(
            "SELECT COUNT(*) AS total FROM media_attachments
             WHERE app_id = ? AND target_type = 'forum_section' AND target_id = ?",
            [$appId, $sectionId]
        )['total'] ?? 0);
    }

    private static function normalizeNewUnlockAt($value, int $appId): ?string
    {
        $date = self::parseRfc3339UnlockAt($value);
        if ($date === null) return null;
        $now = time();
        if ($date->getTimestamp() <= $now) {
            throw new HttpException('unlock_at 必须晚于当前时间', 0, 422);
        }
        $maximumDays = self::maximumUnlockFutureDays($appId);
        if ($date->getTimestamp() > $now + ($maximumDays * 86400)) {
            throw new HttpException('unlock_at 不能超过未来 ' . $maximumDays . ' 天', 0, 422);
        }
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function parseRfc3339UnlockAt($value): ?\DateTimeImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') return null;
        if (preg_match(
            '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-]\d{2}:\d{2})$/D',
            $raw,
            $matches
        ) !== 1) {
            throw new HttpException('unlock_at 必须是带时区的 RFC3339 日期时间', 0, 422);
        }
        $zone = (string) $matches[3];
        if ($zone !== 'Z') {
            $zoneHour = (int) substr($zone, 1, 2);
            $zoneMinute = (int) substr($zone, 4, 2);
            if ($zoneHour > 23 || $zoneMinute > 59) {
                throw new HttpException('unlock_at 时区偏移无效', 0, 422);
            }
        }
        $fraction = str_pad((string) ($matches[2] ?? ''), 6, '0');
        $canonical = (string) $matches[1] . '.' . $fraction . ($zone === 'Z' ? '+00:00' : $zone);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $canonical);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))) {
            throw new HttpException('unlock_at 必须是严格合法的 RFC3339 日期时间', 0, 422);
        }
        return $date;
    }

    private static function normalizeStoredUnlockAt($value): ?string
    {
        $date = self::storedUnlockDate($value);
        return $date === null ? null : $date->format('Y-m-d H:i:s');
    }

    private static function storedUnlockDate($value): ?\DateTimeImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $raw) !== 1) return null;
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $raw,
            new \DateTimeZone('UTC')
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))) {
            return null;
        }
        return $date->format('Y-m-d H:i:s') === $raw ? $date : null;
    }

    private static function scheduledUnlocked($value): bool
    {
        $date = self::storedUnlockDate($value);
        return $date !== null && $date->getTimestamp() <= time();
    }

    private static function unlockAtIso($value): ?string
    {
        $date = self::storedUnlockDate($value);
        return $date === null ? null : $date->format('Y-m-d\TH:i:s\Z');
    }

    private static function maximumUnlockFutureDays(int $appId): int
    {
        $raw = AppService::setting($appId, 'forum_unlock_max_future_days', self::DEFAULT_UNLOCK_MAX_FUTURE_DAYS);
        $days = is_numeric($raw) ? (int) $raw : self::DEFAULT_UNLOCK_MAX_FUTURE_DAYS;
        return min(self::ABSOLUTE_UNLOCK_MAX_FUTURE_DAYS, max(1, $days));
    }

    private static function maximumUnlockPrice(int $appId): float
    {
        $raw = AppService::setting($appId, 'forum_unlock_max_price_balance', self::DEFAULT_UNLOCK_MAX_PRICE_BALANCE);
        $maximum = is_numeric($raw) ? (float) $raw : self::DEFAULT_UNLOCK_MAX_PRICE_BALANCE;
        if (!is_finite($maximum)) $maximum = self::DEFAULT_UNLOCK_MAX_PRICE_BALANCE;
        return min(self::ABSOLUTE_UNLOCK_MAX_PRICE_BALANCE, max(0.01, round($maximum, 2)));
    }

    private static function decimalLabel(mixed $value): string
    {
        $canonical = (string) WalletService::canonicalAmount('balance', $value, true);
        return rtrim(rtrim($canonical, '0'), '.');
    }

    private static function unlockLabel(string $type, ?string $unlockAt, mixed $price): string
    {
        return match ($type) {
            'paid' => '支付 ' . self::decimalLabel($price) . ' 余额解锁',
            'scheduled' => '到期自动解锁' . ($unlockAt === null ? '' : ' · ' . $unlockAt),
            'paid_or_scheduled' => '可提前购买，或到期自动解锁' . ($unlockAt === null ? '' : ' · ' . $unlockAt),
            default => '公开内容',
        };
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
