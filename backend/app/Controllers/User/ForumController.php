<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\User;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Validator;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\ContentTagService;
use Yiyunying\Services\ForumExperienceService;
use Yiyunying\Services\ForumCommentNotificationService;
use Yiyunying\Services\ForumModeratorService;
use Yiyunying\Services\ForumTaxonomyService;
use Yiyunying\Services\ForumVisibilityService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\MessageMediaService;
use Yiyunying\Services\MessageForwardService;
use Yiyunying\Services\NotificationService;
use Yiyunying\Services\RewardRuleService;
use Yiyunying\Services\WalletService;

final class ForumController
{
    public static function plates(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $where = ['app_id = ?', 'status = 1'];
        $query = [(int) $user['app_id']];
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(name LIKE ? OR description LIKE ? OR CAST(id AS CHAR) LIKE ?)';
            foreach (range(1, 3) as $_) $query[] = '%' . $keyword . '%';
        }
        array_unshift($query, (int) $user['id'], (int) $user['id'], (int) $user['id']);
        return Response::success(['items' => Database::all(
            "SELECT plate.id, plate.name, plate.icon, plate.description,
                    (SELECT COUNT(*) FROM forum_categories category
                     WHERE category.plate_id = plate.id AND category.status = 1) AS category_count,
                    (SELECT COUNT(*) FROM forum_posts post
                     WHERE post.plate_id = plate.id AND post.status = 1 AND post.deleted_at IS NULL
                       AND (post.audit_status = 'approved' OR post.user_id = ?)) AS post_count,
                    COALESCE(personal.position, 'normal') AS personal_position,
                    COALESCE(personal.sort_order, 0) AS personal_sort_order,
                    CASE WHEN moderator.id IS NULL THEN 0 ELSE 1 END AS is_moderator
             FROM forum_plates plate
             LEFT JOIN forum_personal_positions personal
               ON personal.user_id = ? AND personal.target_type = 'plate' AND personal.target_id = plate.id
             LEFT JOIN forum_moderators moderator
               ON moderator.user_id = ? AND moderator.plate_id = plate.id AND moderator.status = 1
             WHERE " . str_replace(['app_id', 'status', 'name', 'description', 'CAST(id AS CHAR)'],
                 ['plate.app_id', 'plate.status', 'plate.name', 'plate.description', 'CAST(plate.id AS CHAR)'], implode(' AND ', $where)) . "
             ORDER BY CASE COALESCE(personal.position, 'normal') WHEN 'bottom' THEN 1 ELSE 0 END,
                      CASE COALESCE(personal.position, 'normal') WHEN 'top' THEN 0 ELSE 1 END,
                      personal.sort_order DESC, plate.sort_order DESC, plate.id",
            $query
        )]);
    }

    public static function posts(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        return Response::success(self::postList($request, (int) $user['app_id'], (int) $user['id']));
    }

    public static function createPost(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        AuthService::ensureNotBanned($user, ['all', 'forum', 'post']);
        $data = $request->all();
        Validator::required($data, ['plate_id', 'title']);
        $clientDraftId = self::clientDraftId($data);
        if ($clientDraftId !== null) {
            $existingDraft = self::draftPostResult(
                (int) $user['app_id'], (int) $user['id'], $clientDraftId
            );
            if ($existingDraft !== null) {
                return Response::success(
                    $existingDraft + ['reward_result' => null],
                    '该草稿已经发布，已返回原帖子',
                    200
                );
            }
        }
        if (Database::one('SELECT id FROM forum_plates WHERE id = ? AND app_id = ? AND status = 1', [(int) $data['plate_id'], (int) $user['app_id']]) === null) {
            throw new HttpException('论坛板块不存在', 404, 404);
        }
        $audit = AppService::setting((int) $user['app_id'], 'forum_post_audit', false) ? 'pending' : 'approved';
        $mediaData = self::withLegacyImages($data);
        $sections = $data['sections'] ?? [];
        if (is_string($sections)) $sections = json_decode($sections, true);
        if (!is_array($sections)) throw new HttpException('sections 必须是数组', 0, 422);
        if ($sections !== [] && trim((string) ($mediaData['content'] ?? '')) === '' && empty($mediaData['attachments'])) {
            $mediaData['content'] = '本帖包含分节内容，请按顺序阅读。';
        }
        $payload = MessageMediaService::userPayload($user, $mediaData);
        $images = $data['images'] ?? [];
        $categoryId = ForumTaxonomyService::categoryId(
            (int) $user['admin_id'], (int) $user['app_id'], (int) $data['plate_id'], $data['category_id'] ?? null
        );
        $tagsJson = ContentTagService::encode(ForumTaxonomyService::normalizeTags(
            (int) $user['app_id'], (int) $data['plate_id'], $categoryId, $data['tags'] ?? []
        ));
        $result = Database::transaction(static function () use (
            $user, $data, $categoryId, $payload, $images, $tagsJson, $audit, $sections, $clientDraftId
        ): array {
            $insertSql = 'INSERT INTO forum_posts
                 (admin_id, app_id, plate_id, category_id, user_id, client_draft_id, title, content,
                  images_json, tags_json, audit_status, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())';
            $insertParams = [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $data['plate_id'], $categoryId,
                (int) $user['id'], $clientDraftId, Validator::string($data['title'], 'title', 1, 120),
                (string) $payload['content'],
                json_encode((array) $images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $tagsJson, $audit,
            ];
            if ($clientDraftId !== null) {
                Database::one(
                    'SELECT id FROM users WHERE id = ? AND app_id = ? FOR UPDATE',
                    [(int) $user['id'], (int) $user['app_id']]
                );
                $existing = self::draftPostResult(
                    (int) $user['app_id'], (int) $user['id'], $clientDraftId
                );
                if ($existing !== null) return $existing;
            }
            $id = Database::insert($insertSql, $insertParams);
            MessageMediaService::save('forum_post', $id, $payload);
            $sectionIds = ForumExperienceService::createSections($user, $id, $sections);
            return [
                'post_id' => $id,
                'section_ids' => $sectionIds,
                'audit_status' => $audit,
                'idempotent_replay' => false,
            ];
        });
        $id = (int) $result['post_id'];
        $idempotentReplay = (bool) ($result['idempotent_replay'] ?? false);
        $auditStatus = (string) ($result['audit_status'] ?? $audit);
        if (!$idempotentReplay) LogService::userOperation($request, $user, 'forum', 'post_create', $id);
        $rewardResult = null;
        if (!$idempotentReplay && $auditStatus === 'approved') {
            $rewardResult = RewardRuleService::trigger(
                $user,
                'forum_post_create',
                'forum_post',
                $id,
                [
                    'approved' => true,
                    'status' => 'approved',
                    'content' => trim((string) $data['title'] . "\n" . (string) $payload['content']),
                    'plate_id' => (int) $data['plate_id'],
                    'category_id' => $categoryId,
                ],
                'user',
                (int) $user['id']
            );
        }
        return Response::success(
            $result + ['audit_status' => $auditStatus, 'reward_result' => $rewardResult],
            $idempotentReplay ? '该草稿已经发布，已返回原帖子' : '帖子发布成功',
            $idempotentReplay ? 200 : 201
        );
    }

    public static function showPost(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $post = self::post((int) $user['app_id'], (int) $params['post_id'], (int) $user['id']);
        $view = ForumExperienceService::recordView($request, $post, $user);
        foreach (['view_count', 'unique_view_count', 'heat_score', 'hot_label'] as $field) {
            if (array_key_exists($field, $view)) $post[$field] = $view[$field];
        }
        $post = ForumVisibilityService::hydratePosts(
            [$post], (int) $user['app_id'], (int) $user['id']
        )[0];
        if ((bool) $post['purchased']) {
            $post = MessageForwardService::hydrate([$post], 'forum_post', (int) $user['app_id'])[0];
        }
        $post['sections'] = ForumExperienceService::sections($post, $user);
        $post['has_sections'] = $post['sections'] !== [];
        $post['comments'] = Database::all(
            "SELECT c.id, c.parent_id, c.root_comment_id, c.user_id, c.content, c.tags_json, c.audit_status, c.audit_reason,
                    c.is_pinned, c.pin_order, c.like_count, c.favorite_count, c.created_at, c.updated_at,
                    u.uid, p.nickname, p.avatar,
                    parent_comment.user_id AS reply_to_user_id,
                    parent_user.uid AS reply_to_uid,
                    COALESCE(NULLIF(parent_profile.nickname, ''), parent_user.account, '') AS reply_to_name,
                    CASE WHEN liked.id IS NULL THEN 0 ELSE 1 END AS liked,
                    CASE WHEN favorite.id IS NULL THEN 0 ELSE 1 END AS favorited
             FROM forum_comments c INNER JOIN users u ON u.id = c.user_id
             LEFT JOIN user_profiles p ON p.user_id = c.user_id
             LEFT JOIN forum_comments parent_comment ON parent_comment.id = c.parent_id AND parent_comment.post_id = c.post_id
               AND parent_comment.status = 1
               AND (parent_comment.audit_status = 'approved' OR parent_comment.user_id = ?)
             LEFT JOIN users parent_user ON parent_user.id = parent_comment.user_id
             LEFT JOIN user_profiles parent_profile ON parent_profile.user_id = parent_comment.user_id
             LEFT JOIN forum_likes liked ON liked.app_id = c.app_id AND liked.user_id = ?
               AND liked.target_type = 'comment' AND liked.target_id = c.id
             LEFT JOIN forum_content_favorites favorite ON favorite.app_id = c.app_id AND favorite.user_id = ?
               AND favorite.target_type = 'comment' AND favorite.target_id = c.id
             WHERE c.post_id = ? AND c.status = 1 AND (c.audit_status = ? OR c.user_id = ?)
             ORDER BY c.is_pinned DESC, c.pin_order DESC, c.id ASC LIMIT 500",
            [
                (int) $user['id'], (int) $user['id'], (int) $user['id'],
                (int) $post['id'], 'approved', (int) $user['id'],
            ]
        );
        $post['comments'] = self::hydrateCommentRoots($post['comments']);
        $post['comments'] = ContentTagService::hydrate($post['comments']);
        $post['comments'] = MessageMediaService::hydrate($post['comments'], 'forum_comment', (int) $user['app_id']);
        $post['comments'] = MessageForwardService::hydrate($post['comments'], 'forum_comment', (int) $user['app_id']);
        $post['liked'] = Database::one(
            'SELECT id FROM forum_likes WHERE app_id = ? AND user_id = ? AND target_type = ? AND target_id = ?',
            [(int) $user['app_id'], (int) $user['id'], 'post', (int) $post['id']]
        ) !== null;
        $post['favorited'] = Database::one('SELECT id FROM forum_favorites WHERE post_id = ? AND user_id = ?', [(int) $post['id'], (int) $user['id']]) !== null;
        return Response::success(['post' => $post]);
    }

    public static function updatePost(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $postId = (int) $params['post_id'];
        $post = Database::one(
            'SELECT * FROM forum_posts WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
            [$postId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        $isOwner = $post !== null && (int) $post['user_id'] === (int) $user['id'];
        if ($post === null || (!$isOwner && !ForumModeratorService::canManage($user, (int) $post['plate_id'], 'edit_posts'))) {
            throw new HttpException('帖子不存在或无权修改', 404, 404);
        }
        $images = $request->input('images', json_decode((string) $post['images_json'], true) ?: []);
        $all = $request->all();
        $categoryId = array_key_exists('category_id', $all)
            ? ForumTaxonomyService::categoryId((int) $user['admin_id'], (int) $user['app_id'], (int) $post['plate_id'], $request->input('category_id'))
            : ($post['category_id'] === null ? null : (int) $post['category_id']);
        $tagsJson = array_key_exists('tags', $all)
            ? ContentTagService::encode(ForumTaxonomyService::normalizeTags(
                (int) $user['app_id'], (int) $post['plate_id'], $categoryId, $request->input('tags')
            ))
            : (string) ($post['tags_json'] ?? '[]');
        $hasMediaChange = $request->input('attachments') !== null || $request->input('images') !== null;
        $rightsSensitiveUpdate = array_key_exists('content', $all)
            || array_key_exists('attachments', $all)
            || array_key_exists('images', $all);
        $payload = null;
        if ($hasMediaChange) {
            $mediaData = self::withLegacyImages($request->all());
            $mediaData['content'] = (string) $request->input('content', $post['content']);
            $payload = MessageMediaService::userPayload($user, $mediaData);
        }
        // Every user-side edit must re-enter moderation when the switch is enabled.
        // A plate moderator may edit content, but only the administrator review
        // endpoint is allowed to promote it back to approved.
        $audit = AppService::setting((int) $user['app_id'], 'forum_post_audit', false)
            ? 'pending' : 'approved';
        Database::transaction(static function () use (
            $request, $user, $post, $postId, $categoryId, $images, $tagsJson, $payload, $audit,
            $rightsSensitiveUpdate
        ): void {
            $lockedPost = Database::one(
                'SELECT id FROM forum_posts
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL FOR UPDATE',
                [$postId, (int) $user['admin_id'], (int) $user['app_id']]
            );
            if ($lockedPost === null) throw new HttpException('帖子不存在或无权修改', 404, 404);
            if ($rightsSensitiveUpdate) {
                ForumExperienceService::assertPostPurchaseSafeMutation($postId);
            }
            if ($payload !== null && Database::one(
                'SELECT id FROM forum_paid_contents WHERE post_id = ? AND status = 1', [$postId]
            ) !== null) {
                AppService::requireFeature((int) $user['app_id'], 'forum_attachment_unlock');
                self::assertPaidPostPayloadProtectable($payload);
            }
            Database::execute(
                'UPDATE forum_posts SET category_id = ?, title = ?, content = ?, images_json = ?, tags_json = ?,
                 audit_status = ?, audit_reason = ?, audited_by = NULL, audited_at = NULL, updated_at = NOW() WHERE id = ?',
                [
                    $categoryId,
                    Validator::string($request->input('title', $post['title']), 'title', 1, 120),
                    $payload === null ? (string) $request->input('content', $post['content']) : (string) $payload['content'],
                    json_encode((array) $images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $tagsJson, $audit, '', $postId,
                ]
            );
            if ($payload !== null) MessageMediaService::replace('forum_post', $postId, $payload);
        });
        LogService::userOperation($request, $user, 'forum', $isOwner ? 'post_update' : 'moderator_post_update', $postId, ['plate_id' => (int) $post['plate_id']]);
        return Response::success(['audit_status' => $audit], $audit === 'pending' ? '帖子修改成功，等待审核' : '帖子修改成功');
    }

    public static function deletePost(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $postId = (int) $params['post_id'];
        $post = Database::one(
            'SELECT id, user_id, plate_id FROM forum_posts WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
            [$postId, (int) $user['admin_id'], (int) $user['app_id']]
        );
        $isOwner = $post !== null && (int) $post['user_id'] === (int) $user['id'];
        if ($post === null || (!$isOwner && !ForumModeratorService::canManage($user, (int) $post['plate_id'], 'delete_posts'))) {
            throw new HttpException('帖子不存在或无权删除', 404, 404);
        }
        Database::transaction(static function () use ($user, $postId): void {
            $lockedPost = Database::one(
                'SELECT id FROM forum_posts
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL FOR UPDATE',
                [$postId, (int) $user['admin_id'], (int) $user['app_id']]
            );
            if ($lockedPost === null) throw new HttpException('帖子不存在或无权删除', 404, 404);
            ForumExperienceService::assertPostPurchaseSafeMutation($postId);
            Database::execute(
                'UPDATE forum_posts SET status = -1, deleted_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND deleted_at IS NULL',
                [$postId]
            );
        });
        LogService::userOperation($request, $user, 'forum', $isOwner ? 'post_delete' : 'moderator_post_delete', $postId, ['plate_id' => (int) $post['plate_id']]);
        return Response::success([], '帖子已删除');
    }

    public static function comment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        AuthService::ensureNotBanned($user, ['all', 'forum', 'comment']);
        $post = self::post((int) $user['app_id'], (int) $params['post_id'], (int) $user['id']);
        self::ensureApprovedForInteraction($post);
        if ((int) $post['is_locked'] === 1) {
            throw new HttpException('帖子已锁定，不能评论', 403, 403);
        }
        $payload = MessageMediaService::userPayload($user, $request->all());
        foreach ($payload['attachments'] as &$attachment) {
            $metadata = is_array($attachment['metadata'] ?? null) ? $attachment['metadata'] : [];
            if (($metadata['audio_kind'] ?? '') !== 'voice') continue;
            if (($attachment['media_type'] ?? '') !== 'audio') {
                throw new HttpException('语音评论必须上传音频文件', 0, 422);
            }
            $durationMs = (int) ($attachment['duration_ms'] ?? 0);
            if ($durationMs < 650 || $durationMs > 60000) {
                throw new HttpException('语音评论时长应在 1 到 60 秒之间', 0, 422);
            }
            $metadata['audio_kind'] = 'voice';
            $attachment['metadata'] = $metadata;
        }
        unset($attachment);
        if (mb_strlen((string) $payload['content']) > 5000) throw new HttpException('评论正文不能超过 5000 个字符', 0, 422);
        $tagsJson = ContentTagService::encode($request->input('tags', []));
        $parentId = (int) $request->input('parent_id', 0);
        $audit = AppService::setting((int) $user['app_id'], 'forum_comment_audit', false) ? 'pending' : 'approved';
        $mentionsJson = ForumCommentNotificationService::encodeMentions(
            $request->input('mentions', []), (int) $user['id']
        );
        [$id, $rootCommentId] = Database::transaction(static function () use (
            $user, $post, $payload, $parentId, $audit, $tagsJson, $mentionsJson
        ): array {
            $lockedPost = Database::one(
                'SELECT id, audit_status, status, deleted_at, is_locked FROM forum_posts
                 WHERE id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [(int) $post['id'], (int) $user['admin_id'], (int) $user['app_id']]
            );
            if ($lockedPost === null || (int) $lockedPost['status'] !== 1
                || $lockedPost['deleted_at'] !== null || (string) $lockedPost['audit_status'] !== 'approved') {
                throw new HttpException('帖子尚未审核通过，暂不能评论', 403, 403);
            }
            if ((int) $lockedPost['is_locked'] === 1) {
                throw new HttpException('帖子已锁定，不能评论', 403, 403);
            }
            $rootCommentId = null;
            if ($parentId > 0) {
                $parent = Database::one(
                    'SELECT id, parent_id, root_comment_id, user_id, content, audit_status, status
                     FROM forum_comments
                     WHERE id = ? AND post_id = ? AND admin_id = ? AND app_id = ? AND status = 1
                       AND (audit_status = ? OR user_id = ?) FOR UPDATE',
                    [
                        $parentId, (int) $post['id'], (int) $user['admin_id'],
                        (int) $user['app_id'], 'approved', (int) $user['id'],
                    ]
                );
                if ($parent === null) throw new HttpException('回复的评论不存在', 404, 404);
                if ($audit === 'approved') {
                    self::assertApprovedForumParentChain(
                        (int) $post['id'], $parent, (int) $user['admin_id'], (int) $user['app_id']
                    );
                }
                $rootCommentId = self::resolveStoredCommentRoot((int) $post['id'], $parent);
            }
            $id = Database::insert(
                'INSERT INTO forum_comments
                 (admin_id, app_id, post_id, parent_id, root_comment_id, user_id, content,
                  tags_json, mentions_json, audit_status, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], (int) $post['id'],
                    $parentId > 0 ? $parentId : null, $rootCommentId, (int) $user['id'],
                    (string) $payload['content'], $tagsJson, $mentionsJson, $audit,
                ]
            );
            MessageMediaService::save('forum_comment', $id, $payload);
            if ($audit === 'approved') {
                Database::execute(
                    'UPDATE forum_posts SET comment_count = comment_count + 1, last_activity_at = NOW(), updated_at = NOW() WHERE id = ?',
                    [(int) $post['id']]
                );
            }
            return [$id, $rootCommentId];
        });
        ForumExperienceService::refreshHeat((int) $post['id'], (int) $user['app_id']);
        if ($audit === 'approved') {
            ForumCommentNotificationService::notifyParticipants(
                (int) $user['admin_id'],
                (int) $user['app_id'],
                [
                    'id' => $id,
                    'post_id' => (int) $post['id'],
                    'parent_id' => $parentId > 0 ? $parentId : null,
                    'user_id' => (int) $user['id'],
                    'content' => (string) $payload['content'],
                    'mentions_json' => $mentionsJson,
                ]
            );
        }
        $rewardResult = null;
        if ($audit === 'approved') {
            $rewardResult = RewardRuleService::trigger(
                $user,
                $parentId > 0 ? 'reply_create' : 'comment_create',
                'forum_comment',
                $id,
                [
                    'approved' => true,
                    'status' => 'approved',
                    'content' => (string) $payload['content'],
                    'post_id' => (int) $post['id'],
                    'parent_id' => $parentId,
                ],
                'user',
                (int) $user['id']
            );
        }
        return Response::success(
            [
                'comment_id' => $id,
                'parent_id' => $parentId > 0 ? $parentId : null,
                'root_comment_id' => $rootCommentId ?? $id,
                'audit_status' => $audit,
                'reward_result' => $rewardResult,
            ],
            $audit === 'pending' ? '评论已提交，等待审核' : '评论成功',
            201
        );
    }

    public static function like(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $post = self::post((int) $user['app_id'], (int) $params['post_id'], (int) $user['id']);
        self::ensureApprovedForInteraction($post);
        $liked = ForumExperienceService::toggleLike($user, 'post', (int) $post['id']);
        if ($liked && (int) ($post['user_id'] ?? 0) > 0 && (int) $post['user_id'] !== (int) $user['id']) {
            $receiver = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], (int) $post['user_id']);
            if ($receiver !== null) NotificationService::send(
                $receiver, 'forum_post_like', '帖子收到点赞', '《' . (string) $post['title'] . '》收到一个新点赞',
                ['post_id' => (int) $post['id'], 'user_id' => (int) $user['id']]
            );
        }
        return Response::success(['liked' => $liked], $liked ? '点赞成功' : '已取消点赞');
    }

    public static function favorite(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $post = self::post((int) $user['app_id'], (int) $params['post_id'], (int) $user['id']);
        self::ensureApprovedForInteraction($post);
        $favorited = ForumExperienceService::toggleFavorite($user, 'post', (int) $post['id']);
        if ($favorited && (int) ($post['user_id'] ?? 0) > 0 && (int) $post['user_id'] !== (int) $user['id']) {
            $receiver = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], (int) $post['user_id']);
            if ($receiver !== null) NotificationService::send(
                $receiver, 'forum_post_favorite', '帖子被收藏', '《' . (string) $post['title'] . '》被用户收藏',
                ['post_id' => (int) $post['id'], 'user_id' => (int) $user['id']]
            );
        }
        return Response::success(['favorited' => $favorited], $favorited ? '收藏成功' : '已取消收藏');
    }

    public static function favorites(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $items = Database::all(
            'SELECT p.*, f.created_at AS favorited_at, up.nickname, up.avatar
             FROM forum_favorites f INNER JOIN forum_posts p ON p.id = f.post_id
             LEFT JOIN user_profiles up ON up.user_id = p.user_id
             WHERE f.app_id = ? AND f.user_id = ? AND p.status = 1 AND p.deleted_at IS NULL
               AND (p.audit_status = \'approved\' OR p.user_id = ?) ORDER BY f.id DESC',
            [(int) $user['app_id'], (int) $user['id'], (int) $user['id']]
        );
        foreach ($items as &$item) unset($item['client_draft_id']);
        unset($item);
        return Response::success(['items' => ForumVisibilityService::hydratePosts(
            $items, (int) $user['app_id'], (int) $user['id']
        )]);
    }

    public static function comments(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $post = self::post((int) $user['app_id'], (int) $params['post_id'], (int) $user['id']);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['c.post_id = ?', 'c.status = 1', '(c.audit_status = ? OR c.user_id = ?)'];
        $query = [(int) $post['id'], 'approved', (int) $user['id']];
        if ((int) $request->input('parent_id', 0) > 0) {
            $where[] = 'c.parent_id = ?';
            $query[] = (int) $request->input('parent_id');
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM forum_comments c WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT c.id, c.parent_id, c.root_comment_id, c.user_id, c.content, c.tags_json, c.audit_status, c.audit_reason,
                    c.is_pinned, c.pin_order, c.like_count, c.favorite_count,
                    c.created_at, c.updated_at, u.uid, p.nickname, p.avatar,
                    parent_comment.user_id AS reply_to_user_id,
                    parent_user.uid AS reply_to_uid,
                    COALESCE(NULLIF(parent_profile.nickname, ''), parent_user.account, '') AS reply_to_name,
                    CASE WHEN liked.id IS NULL THEN 0 ELSE 1 END AS liked,
                    CASE WHEN favorite.id IS NULL THEN 0 ELSE 1 END AS favorited
             FROM forum_comments c INNER JOIN users u ON u.id = c.user_id
             LEFT JOIN user_profiles p ON p.user_id = c.user_id
             LEFT JOIN forum_comments parent_comment ON parent_comment.id = c.parent_id AND parent_comment.post_id = c.post_id
               AND parent_comment.status = 1
               AND (parent_comment.audit_status = 'approved' OR parent_comment.user_id = ?)
             LEFT JOIN users parent_user ON parent_user.id = parent_comment.user_id
             LEFT JOIN user_profiles parent_profile ON parent_profile.user_id = parent_comment.user_id
             LEFT JOIN forum_likes liked ON liked.app_id = c.app_id AND liked.user_id = ?
               AND liked.target_type = 'comment' AND liked.target_id = c.id
             LEFT JOIN forum_content_favorites favorite ON favorite.app_id = c.app_id AND favorite.user_id = ?
               AND favorite.target_type = 'comment' AND favorite.target_id = c.id
             WHERE {$whereSql} ORDER BY c.is_pinned DESC, c.pin_order DESC, c.id ASC LIMIT {$limit} OFFSET {$offset}",
            array_merge([(int) $user['id'], (int) $user['id'], (int) $user['id']], $query)
        );
        $items = self::hydrateCommentRoots($items);
        $items = ContentTagService::hydrate($items);
        $items = MessageMediaService::hydrate($items, 'forum_comment', (int) $user['app_id']);
        $items = MessageForwardService::hydrate($items, 'forum_comment', (int) $user['app_id']);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function likes(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $post = self::post((int) $user['app_id'], (int) $params['post_id'], (int) $user['id']);
        self::ensureApprovedForInteraction($post);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $query = [(int) $user['app_id'], 'post', (int) $post['id']];
        $total = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM forum_likes WHERE app_id = ? AND target_type = ? AND target_id = ?',
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT l.id, l.user_id, l.created_at, u.uid, u.account, p.nickname, p.avatar, p.signature
             FROM forum_likes l INNER JOIN users u ON u.id = l.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE l.app_id = ? AND l.target_type = ? AND l.target_id = ?
             ORDER BY l.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function myPosts(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        return Response::success(self::postCollection(
            $request, $user, '', 'p.user_id = ?', [(int) $user['id']], false, true
        ));
    }

    public static function purchasedPosts(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        return Response::success(self::postCollection(
            $request,
            $user,
            'INNER JOIN forum_post_purchases collection ON collection.post_id = p.id',
            'collection.buyer_user_id = ?',
            [(int) $user['id']],
            true,
            true
        ));
    }

    public static function followingPosts(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        return Response::success(self::postCollection(
            $request,
            $user,
            'INNER JOIN user_follows collection ON collection.followed_user_id = p.user_id AND collection.app_id = p.app_id',
            'collection.follower_user_id = ?',
            [(int) $user['id']],
            true,
            false
        ));
    }

    public static function likedPosts(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        return Response::success(self::postCollection(
            $request,
            $user,
            "INNER JOIN forum_likes collection ON collection.target_id = p.id AND collection.target_type = 'post' AND collection.app_id = p.app_id",
            'collection.user_id = ?',
            [(int) $user['id']],
            true,
            false
        ));
    }

    public static function reportTags(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        return Response::success(['items' => Database::all(
            'SELECT id, name, description, sort_order FROM forum_report_tags
             WHERE admin_id = ? AND app_id = ? AND status = 1 ORDER BY sort_order DESC, id ASC',
            [(int) $user['admin_id'], (int) $user['app_id']]
        )]);
    }

    public static function myReports(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $query = [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']];
        $total = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM forum_reports WHERE admin_id = ? AND app_id = ? AND user_id = ?',
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT r.id, r.target_type, r.target_id, r.reason, r.status, r.handled_at, r.created_at,
                    t.name AS report_tag_name,
                    CASE WHEN r.target_type = 'post' THEN p.title
                         WHEN comment_post.id IS NOT NULL THEN LEFT(c.content, 120)
                         ELSE NULL END AS target_summary
             FROM forum_reports r
             LEFT JOIN forum_report_tags t ON t.id = r.report_tag_id AND t.app_id = r.app_id
             LEFT JOIN forum_posts p ON r.target_type = 'post' AND p.id = r.target_id
               AND p.status = 1 AND p.deleted_at IS NULL
               AND (p.audit_status = 'approved' OR p.user_id = r.user_id)
             LEFT JOIN forum_comments c ON r.target_type = 'comment' AND c.id = r.target_id
               AND c.status = 1 AND (c.audit_status = 'approved' OR c.user_id = r.user_id)
             LEFT JOIN forum_posts comment_post ON comment_post.id = c.post_id
               AND comment_post.status = 1 AND comment_post.deleted_at IS NULL
               AND (comment_post.audit_status = 'approved' OR comment_post.user_id = r.user_id)
             WHERE r.admin_id = ? AND r.app_id = ? AND r.user_id = ?
             ORDER BY r.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $statusNames = ['pending' => '待处理', 'approved' => '已确认', 'rejected' => '已驳回', 'handled' => '已处理'];
        foreach ($items as &$item) {
            $item['target_type_name'] = $item['target_type'] === 'post' ? '帖子' : '评论';
            $item['status_name'] = $statusNames[$item['status']] ?? '处理中';
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function history(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $items = Database::all(
            'SELECT p.*, h.view_count AS my_view_count, h.last_viewed_at, up.nickname, up.avatar
             FROM forum_view_history h INNER JOIN forum_posts p ON p.id = h.post_id
             LEFT JOIN user_profiles up ON up.user_id = p.user_id
             WHERE h.user_id = ? AND p.app_id = ? AND p.status = 1 AND p.deleted_at IS NULL
               AND (p.audit_status = \'approved\' OR p.user_id = ?)
             ORDER BY h.last_viewed_at DESC LIMIT 1000',
            [(int) $user['id'], (int) $user['app_id'], (int) $user['id']]
        );
        foreach ($items as &$item) unset($item['client_draft_id']);
        unset($item);
        return Response::success(['items' => ForumVisibilityService::hydratePosts(
            $items, (int) $user['app_id'], (int) $user['id']
        )]);
    }

    public static function reward(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        if (!AppService::setting((int) $user['app_id'], 'forum_reward_enabled', true)) throw new HttpException('管理员已关闭论坛打赏', 403, 403);
        $post = self::post((int) $user['app_id'], (int) $params['post_id'], (int) $user['id']);
        self::ensureApprovedForInteraction($post);
        $amount = Validator::integer($request->input('balance'), 'balance', 1, 1000000000);
        $targetType = trim((string) $request->input('target_type', 'post')); $targetId = (int) $request->input('target_id', $post['id']); $toUserId = (int) $post['user_id'];
        if ($targetType === 'comment') {
            $comment = Database::one(
                "SELECT user_id FROM forum_comments
                 WHERE id = ? AND post_id = ? AND status = 1 AND audit_status = 'approved'",
                [$targetId, (int) $post['id']]
            );
            if ($comment === null) throw new HttpException('评论不存在', 404, 404); $toUserId = (int) $comment['user_id'];
        } elseif ($targetType !== 'post') throw new HttpException('target_type 仅支持 post 或 comment', 0, 422);
        if ($toUserId === (int) $user['id']) throw new HttpException('不能打赏自己', 0, 422);
        $receiver = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], $toUserId); if ($receiver === null) throw new HttpException('收款用户不存在', 404, 404);
        $id = Database::transaction(static function () use ($user, $receiver, $amount, $targetType, $targetId): int {
            $asset = WalletService::requireActivityEnabled((int) $user['app_id']);
            WalletService::adjust($user, $asset, -$amount, 'forum_reward_send', $targetType, $targetId, '论坛打赏');
            WalletService::adjust($receiver, $asset, $amount, 'forum_reward_receive', $targetType, $targetId, '收到论坛打赏');
            return Database::insert('INSERT INTO forum_rewards (app_id, from_user_id, to_user_id, target_type, target_id, integral, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())', [(int) $user['app_id'], (int) $user['id'], (int) $receiver['id'], $targetType, $targetId, $amount]);
        });
        NotificationService::send($receiver, 'forum_reward', '收到论坛打赏', '你收到了 ' . $amount . ' 余额打赏', [
            'reward_id' => $id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'post_id' => (int) $post['id'],
            'comment_id' => $targetType === 'comment' ? $targetId : 0,
        ]);
        return Response::success(['reward_id' => $id, 'balance' => $amount], '打赏成功', 201);
    }

    public static function setPaidContent(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $appId = (int) $user['app_id'];
        AppService::requireFeature($appId, 'forum_paid_unlock');
        if (!AppService::setting($appId, 'forum_paid_content_enabled', true)) {
            throw new HttpException('管理员已关闭付费内容', 403, 403);
        }
        $assetType = strtolower(trim((string) $request->input('asset_type', 'balance')));
        if ($assetType !== 'balance') throw new HttpException('整帖付费仅支持 balance 余额资产', 0, 422);
        $postId = (int) $params['post_id'];
        $maxPrice = max(1, min(1000000000, (int) AppService::setting(
            $appId, 'forum_unlock_max_price_balance', 1000000000
        )));
        $price = Validator::integer($request->input('price_balance'), 'price_balance', 1, $maxPrice);
        $preview = Validator::string($request->input('preview_content', ''), 'preview_content', 1, 5000);
        Database::transaction(static function () use ($user, $appId, $postId, $price, $preview, $assetType): void {
            $post = Database::one(
                'SELECT id FROM forum_posts
                 WHERE id = ? AND app_id = ? AND user_id = ? AND status = 1 AND deleted_at IS NULL
                 FOR UPDATE',
                [$postId, $appId, (int) $user['id']]
            );
            if ($post === null) throw new HttpException('帖子不存在或无权设置', 404, 404);
            self::assertWholePostAttachmentsProtectable($appId, $postId);
            $existing = Database::one(
                'SELECT asset_type FROM forum_paid_contents WHERE post_id = ? FOR UPDATE',
                [$postId]
            );
            if ($existing !== null && (string) $existing['asset_type'] !== 'balance') {
                throw new HttpException('整帖付费资产类型不一致，请先执行数据迁移', 0, 409);
            }
            Database::execute(
                'INSERT INTO forum_paid_contents
                 (post_id, price_integral, asset_type, preview_content, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE price_integral = VALUES(price_integral),
                   preview_content = VALUES(preview_content), status = 1, updated_at = NOW()',
                [$postId, $price, $assetType, $preview]
            );
        });
        return Response::success([
            'post_id' => $postId, 'price_balance' => $price, 'asset_type' => $assetType,
        ], '付费内容规则已保存');
    }

    public static function buyPaidContent(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $postId = (int) $params['post_id'];
        $appId = (int) $user['app_id'];
        AppService::requireFeature($appId, 'forum_paid_unlock');
        if (!AppService::setting($appId, 'forum_paid_content_enabled', true)) {
            throw new HttpException('管理员已关闭付费内容', 403, 403);
        }
        $maxPrice = max(1, min(1000000000, (int) AppService::setting(
            $appId, 'forum_unlock_max_price_balance', 1000000000
        )));
        $result = Database::transaction(static function () use ($user, $postId, $appId, $maxPrice): array {
            $paid = Database::one(
                "SELECT pc.*, p.user_id AS seller_user_id
                 FROM forum_paid_contents pc INNER JOIN forum_posts p ON p.id = pc.post_id
                 WHERE pc.post_id = ? AND p.app_id = ? AND pc.status = 1 AND p.status = 1
                   AND p.audit_status = 'approved' AND p.deleted_at IS NULL FOR UPDATE",
                [$postId, $appId]
            );
            if ($paid === null) throw new HttpException('付费内容不存在', 404, 404);
            if ((string) ($paid['asset_type'] ?? '') !== 'balance') {
                throw new HttpException('整帖付费资产类型不一致，请先执行数据迁移', 0, 409);
            }
            $price = (int) $paid['price_integral'];
            if ($price < 1 || $price > $maxPrice) throw new HttpException('付费内容价格超出管理员允许范围', 0, 422);
            if ((int) $paid['seller_user_id'] === (int) $user['id']) {
                return ['already_owned' => true, 'price_balance' => 0, 'asset_type' => 'balance'];
            }
            $purchase = Database::one(
                'SELECT id, asset_type FROM forum_post_purchases WHERE post_id = ? AND buyer_user_id = ?',
                [$postId, (int) $user['id']]
            );
            if ($purchase !== null) {
                if ((string) ($purchase['asset_type'] ?? '') !== 'balance') {
                    throw new HttpException('购买记录资产类型不一致，请先执行数据迁移', 0, 409);
                }
                return ['already_owned' => true, 'price_balance' => $price, 'asset_type' => 'balance'];
            }
            $seller = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], (int) $paid['seller_user_id']); if ($seller === null) throw new HttpException('内容作者不存在', 404, 404);
            WalletService::requireActivityEnabled($appId);
            $assetType = 'balance';
            WalletService::adjust($user, $assetType, -$price, 'forum_paid_buy', 'post', $postId, '购买论坛付费内容');
            WalletService::adjust($seller, $assetType, $price, 'forum_paid_sale', 'post', $postId, '论坛付费内容收入');
            Database::execute(
                'INSERT INTO forum_post_purchases
                 (post_id, buyer_user_id, seller_user_id, price_integral, asset_type, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [$postId, (int) $user['id'], (int) $seller['id'], $price, $assetType]
            );
            NotificationService::send($seller, 'forum_paid_sale', '付费内容售出', '你的付费内容已售出', [
                'post_id' => $postId, 'balance' => $price, 'asset_type' => $assetType,
            ]);
            return ['already_owned' => false, 'price_balance' => $price, 'asset_type' => $assetType];
        });
        return Response::success($result, $result['already_owned'] ? '已拥有该内容' : '购买成功');
    }

    public static function createSection(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $sectionId = ForumExperienceService::createSection($user, (int) $params['post_id'], $request->all());
        return Response::success(['section_id' => $sectionId], '内容节创建成功', 201);
    }

    public static function updateSection(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        ForumExperienceService::updateSection($user, (int) $params['post_id'], (int) $params['section_id'], $request->all());
        return Response::success(['section_id' => (int) $params['section_id']], '内容节修改成功');
    }

    public static function deleteSection(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        ForumExperienceService::deleteSection($user, (int) $params['post_id'], (int) $params['section_id']);
        return Response::success([], '内容节已删除');
    }

    public static function reorderSections(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $ids = ForumExperienceService::reorderSections($user, (int) $params['post_id'], $request->input('section_ids', []));
        return Response::success(['section_ids' => $ids], '内容节顺序已同步');
    }

    public static function buySection(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $result = ForumExperienceService::buySection($user, (int) $params['post_id'], (int) $params['section_id']);
        return Response::success($result, $result['already_owned'] ? '已经拥有该内容节' : '购买成功');
    }

    public static function contentLike(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $targetType = (string) $params['target_type'];
        $targetId = (int) $params['target_id'];
        self::assertContentVisible($user, $targetType, $targetId);
        $liked = ForumExperienceService::toggleLike($user, $targetType, $targetId);
        return Response::success(['liked' => $liked], $liked ? '点赞成功' : '已取消点赞');
    }

    public static function contentFavorite(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $targetType = (string) $params['target_type'];
        $targetId = (int) $params['target_id'];
        self::assertContentVisible($user, $targetType, $targetId);
        $favorited = ForumExperienceService::toggleFavorite($user, $targetType, $targetId);
        return Response::success(['favorited' => $favorited], $favorited ? '收藏成功' : '已取消收藏');
    }

    public static function pinComment(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $enabled = Validator::boolean($request->input('enabled', true), 'enabled');
        $result = ForumExperienceService::setCommentPin(
            $user, (int) $params['post_id'], (int) $params['comment_id'], $enabled,
            (int) $request->input('sort_order', 0)
        );
        return Response::success($result, $enabled ? '评论已置顶' : '评论已取消置顶');
    }

    public static function personalPosition(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        if ((string) $params['target_type'] === 'post') {
            self::post((int) $user['app_id'], (int) $params['target_id'], (int) $user['id']);
        }
        $result = ForumExperienceService::setPersonalPosition(
            $user, (string) $params['target_type'], (int) $params['target_id'],
            trim((string) $request->input('position', 'normal')), (int) $request->input('sort_order', 0)
        );
        return Response::success($result, '个人排序已保存');
    }

    public static function forwardContent(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        self::assertContentVisible($user, (string) $params['target_type'], (int) $params['target_id']);
        $id = ForumExperienceService::recordForward(
            $user, (string) $params['target_type'], (int) $params['target_id'],
            trim((string) $request->input('destination_type', '')), (int) $request->input('destination_id', 0)
        );
        return Response::success(['forward_id' => $id], '转发记录已保存', 201);
    }

    public static function report(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $data = $request->all();
        Validator::required($data, ['target_type', 'target_id']);
        $targetType = trim((string) $data['target_type']);
        if (!in_array($targetType, ['post', 'comment'], true)) {
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') throw new HttpException('请填写举报原因', 0, 422);
            $id = Database::insert(
                'INSERT INTO content_reports
                 (admin_id, app_id, user_id, target_type, target_id, reason, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $targetType, (int) $data['target_id'], mb_substr($reason, 0, 1000), 'pending']
            );
        } else {
            $targetId = (int) $data['target_id'];
            $targetExists = $targetType === 'post'
                ? Database::one(
                    "SELECT id FROM forum_posts WHERE id = ? AND app_id = ? AND status = 1
                       AND deleted_at IS NULL AND (audit_status = 'approved' OR user_id = ?)",
                    [$targetId, (int) $user['app_id'], (int) $user['id']]
                )
                : Database::one(
                    "SELECT comment.id FROM forum_comments comment
                     INNER JOIN forum_posts post ON post.id = comment.post_id
                     WHERE comment.id = ? AND comment.app_id = ? AND comment.status = 1
                       AND post.status = 1 AND post.deleted_at IS NULL
                       AND (comment.audit_status = 'approved' OR comment.user_id = ?)
                       AND (post.audit_status = 'approved' OR post.user_id = ?)",
                    [$targetId, (int) $user['app_id'], (int) $user['id'], (int) $user['id']]
                );
            if ($targetExists === null) throw new HttpException('举报目标不存在', 404, 404);
            $tagId = max(0, (int) ($data['report_tag_id'] ?? 0));
            $tag = $tagId > 0 ? Database::one(
                'SELECT id, name FROM forum_report_tags WHERE id = ? AND app_id = ? AND status = 1',
                [$tagId, (int) $user['app_id']]
            ) : null;
            if ($tagId > 0 && $tag === null) throw new HttpException('举报标签不存在或已停用', 404, 404);
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') $reason = (string) ($tag['name'] ?? '');
            if ($reason === '') throw new HttpException('请选择举报标签或填写举报原因', 0, 422);
            if (Database::one(
                "SELECT id FROM forum_reports WHERE app_id = ? AND user_id = ? AND target_type = ?
                 AND target_id = ? AND status = 'pending' LIMIT 1",
                [(int) $user['app_id'], (int) $user['id'], $targetType, $targetId]
            )) {
                throw new HttpException('你已经举报过该内容，请等待管理员处理', 0, 409);
            }
            $id = Database::insert(
                'INSERT INTO forum_reports
                 (admin_id, app_id, target_type, target_id, user_id, report_tag_id, reason, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], $targetType, $targetId,
                    (int) $user['id'], $tagId > 0 ? $tagId : null, mb_substr($reason, 0, 1000), 'pending',
                ]
            );
        }
        return Response::success(['report_id' => $id], '举报已提交', 201);
    }

    private static function postCollection(
        Request $request,
        array $user,
        string $join,
        string $condition,
        array $conditionParams,
        bool $approvedOnly,
        bool $hydrateMedia
    ): array {
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['p.app_id = ?', 'p.status = 1', 'p.deleted_at IS NULL', $condition];
        $query = array_merge([(int) $user['app_id']], $conditionParams);
        if ($approvedOnly) {
            $where[] = "(p.audit_status = 'approved' OR p.user_id = ?)";
            $query[] = (int) $user['id'];
        }
        if ((int) $request->input('plate_id', 0) > 0) {
            $where[] = 'p.plate_id = ?';
            $query[] = (int) $request->input('plate_id');
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $search = ForumVisibilityService::keywordClause('p', $keyword, (int) $user['id'], false);
            $where[] = $search['sql'];
            array_push($query, ...$search['params']);
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $field => $operator) {
            $date = trim((string) $request->input($field, ''));
            if ($date === '') continue;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new HttpException($field . ' 必须是 YYYY-MM-DD 日期', 0, 422);
            }
            $where[] = 'p.created_at ' . $operator . ' ?';
            $query[] = $field === 'date_to' ? $date . ' 23:59:59' : $date . ' 00:00:00';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(DISTINCT p.id) AS total FROM forum_posts p {$join} WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT DISTINCT p.id, p.plate_id, p.user_id, p.title, p.content, p.images_json,
                    p.tags_json, p.is_top, p.is_essence, p.is_locked, p.audit_status,
                    p.audit_reason, p.view_count, p.unique_view_count, p.like_count, p.comment_count,
                    p.heat_score, p.hot_label, p.last_activity_at, p.created_at,
                    p.updated_at, fp.name AS plate_name, up.nickname, up.avatar
             FROM forum_posts p {$join}
             INNER JOIN forum_plates fp ON fp.id = p.plate_id
             LEFT JOIN user_profiles up ON up.user_id = p.user_id
             WHERE {$whereSql}
             ORDER BY p.is_top DESC, p.is_essence DESC, p.is_locked DESC,
                      CASE WHEN p.hot_label <> '' THEN 0 ELSE 1 END, p.heat_score DESC, p.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        $items = ContentTagService::hydrate($items);
        $items = ForumVisibilityService::hydratePosts(
            $items, (int) $user['app_id'], (int) $user['id'], $hydrateMedia
        );
        return Pagination::data($items, $total, $page, $limit);
    }

    public static function postList(Request $request, int $appId, ?int $userId = null): array
    {
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['p.app_id = ?', 'p.audit_status = ?', 'p.status = 1', 'p.deleted_at IS NULL'];
        $query = [$appId, 'approved'];
        if ($request->input('plate_id') !== null && $request->input('plate_id') !== '') {
            $where[] = 'p.plate_id = ?';
            $query[] = (int) $request->input('plate_id');
        }
        if ($request->input('category_id') !== null && $request->input('category_id') !== '') {
            $where[] = 'p.category_id = ?';
            $query[] = (int) $request->input('category_id');
        }
        if (trim((string) $request->input('tag', '')) !== '') {
            $entitlement = ForumVisibilityService::legacyUnlockedClause('p', $userId);
            $where[] = '(p.tags_json LIKE ? AND (' . $entitlement['sql'] . '))';
            $query[] = '%"' . trim((string) $request->input('tag')) . '"%';
            array_push($query, ...$entitlement['params']);
        }
        if (trim((string) $request->input('keyword', '')) !== '') {
            $search = ForumVisibilityService::keywordClause(
                'p', trim((string) $request->input('keyword')), $userId, true
            );
            $where[] = $search['sql'];
            array_push($query, ...$search['params']);
        }
        $dateFrom = trim((string) $request->input('date_from', ''));
        if ($dateFrom !== '') {
            $where[] = 'p.created_at >= ?';
            $query[] = Validator::nullableDateTime($dateFrom . ' 00:00:00', 'date_from');
        }
        $dateTo = trim((string) $request->input('date_to', ''));
        if ($dateTo !== '') {
            $where[] = 'p.created_at <= ?';
            $query[] = Validator::nullableDateTime($dateTo . ' 23:59:59', 'date_to');
        }
        $whereSql = implode(' AND ', $where);
        $personalJoin = '';
        $personalSelect = "'normal' AS personal_position, 0 AS personal_sort_order";
        $queryWithJoin = $query;
        if ($userId !== null && $userId > 0) {
            $personalJoin = "LEFT JOIN forum_personal_positions personal
               ON personal.user_id = ? AND personal.target_type = 'post' AND personal.target_id = p.id";
            $personalSelect = "COALESCE(personal.position, 'normal') AS personal_position,
                    COALESCE(personal.sort_order, 0) AS personal_sort_order";
            $queryWithJoin = array_merge([$userId], $query);
        }
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM forum_posts p {$personalJoin} WHERE {$whereSql}",
            $queryWithJoin
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT p.id, p.plate_id, p.category_id, p.user_id, p.title, p.content, p.images_json, p.tags_json, p.is_top,
                    p.is_essence, p.is_locked, p.view_count, p.unique_view_count, p.like_count, p.comment_count,
                    p.heat_score, p.hot_label, p.last_activity_at, p.created_at,
                    fp.name AS plate_name, fc.name AS category_name, up.nickname, up.avatar,
                    EXISTS(SELECT 1 FROM forum_paid_contents paid WHERE paid.post_id = p.id AND paid.status = 1) AS paid_content,
                    (SELECT COUNT(*) FROM forum_post_sections section WHERE section.post_id = p.id AND section.status = 1) AS section_count,
                    {$personalSelect}
             FROM forum_posts p {$personalJoin}
             INNER JOIN forum_plates fp ON fp.id = p.plate_id
             LEFT JOIN forum_categories fc ON fc.id = p.category_id
             LEFT JOIN user_profiles up ON up.user_id = p.user_id
             WHERE {$whereSql}
             ORDER BY CASE COALESCE(personal_position, 'normal') WHEN 'bottom' THEN 1 ELSE 0 END,
                      p.is_top DESC, p.is_essence DESC, p.is_locked DESC,
                      CASE COALESCE(personal_position, 'normal') WHEN 'top' THEN 0 ELSE 1 END,
                      personal_sort_order DESC,
                      CASE WHEN p.hot_label <> '' THEN 0 ELSE 1 END, p.heat_score DESC, p.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $queryWithJoin
        );
        $items = ContentTagService::hydrate($items);
        $items = ForumVisibilityService::hydratePosts($items, $appId, $userId);
        return Pagination::data($items, $total, $page, $limit);
    }

    public static function post(int $appId, int $postId, ?int $viewerUserId = null): array
    {
        $visibility = $viewerUserId !== null && $viewerUserId > 0
            ? "(p.audit_status = 'approved' OR p.user_id = ?)"
            : "p.audit_status = 'approved'";
        $query = [$postId, $appId];
        if ($viewerUserId !== null && $viewerUserId > 0) $query[] = $viewerUserId;
        $post = Database::one(
            'SELECT p.*, fp.name AS plate_name, fc.name AS category_name, up.nickname, up.avatar
             FROM forum_posts p INNER JOIN forum_plates fp ON fp.id = p.plate_id
             LEFT JOIN forum_categories fc ON fc.id = p.category_id
             LEFT JOIN user_profiles up ON up.user_id = p.user_id
             WHERE p.id = ? AND p.app_id = ? AND ' . $visibility . '
               AND p.status = 1 AND p.deleted_at IS NULL',
            $query
        );
        if ($post === null) {
            throw new HttpException('帖子不存在', 404, 404);
        }
        $post['images'] = json_decode((string) $post['images_json'], true) ?: [];
        unset($post['images_json']);
        $post['tags'] = ContentTagService::decode($post['tags_json'] ?? null);
        unset($post['tags_json']);
        unset($post['client_draft_id']);
        return $post;
    }

    private static function clientDraftId(array $data): ?string
    {
        if (!array_key_exists('client_draft_id', $data)) return null;
        $value = strtolower(trim((string) $data['client_draft_id']));
        if ($value === '') return null;
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) !== 1) {
            throw new HttpException('client_draft_id 必须是标准 UUID', 0, 422);
        }
        return $value;
    }

    private static function draftPostResult(int $appId, int $userId, string $clientDraftId): ?array
    {
        $post = Database::one(
            'SELECT id, audit_status FROM forum_posts
             WHERE app_id = ? AND user_id = ? AND client_draft_id = ? LIMIT 1',
            [$appId, $userId, $clientDraftId]
        );
        if ($post === null) return null;
        $sectionIds = array_map(
            static fn(array $row): int => (int) $row['id'],
            Database::all(
                'SELECT id FROM forum_post_sections WHERE post_id = ? AND status = 1 ORDER BY sort_order, id',
                [(int) $post['id']]
            )
        );
        return [
            'post_id' => (int) $post['id'],
            'section_ids' => $sectionIds,
            'audit_status' => (string) $post['audit_status'],
            'idempotent_replay' => true,
        ];
    }

    private static function assertWholePostAttachmentsProtectable(int $appId, int $postId): void
    {
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM media_attachments
             WHERE app_id = ? AND target_type = 'forum_post' AND target_id = ?",
            [$appId, $postId]
        )['total'] ?? 0);
        if ($total === 0) return;
        AppService::requireFeature($appId, 'forum_attachment_unlock');
        $unsafe = Database::one(
            "SELECT attachment.id FROM media_attachments attachment
             LEFT JOIN uploads upload
               ON upload.id = attachment.upload_id
              AND upload.admin_id = attachment.admin_id
              AND upload.app_id = attachment.app_id
             WHERE attachment.app_id = ? AND attachment.target_type = 'forum_post'
               AND attachment.target_id = ?
               AND (attachment.sticker_id IS NOT NULL OR attachment.upload_id IS NULL
                    OR upload.id IS NULL OR upload.status <> 1 OR upload.file_path NOT LIKE 'private/%')
             LIMIT 1",
            [$appId, $postId]
        );
        if ($unsafe !== null) {
            throw new HttpException('整帖付费附件必须全部迁入私有存储；普通帖子附件请改用受保护章节', 0, 422);
        }
    }

    private static function assertPaidPostPayloadProtectable(array $payload): void
    {
        foreach ((array) ($payload['attachments'] ?? []) as $attachment) {
            if (is_array($attachment) && (int) ($attachment['sticker_id'] ?? 0) > 0) {
                throw new HttpException('整帖付费不能直接使用公共贴纸，请改用私有上传的受保护章节', 0, 422);
            }
        }
        MessageMediaService::assertPrivateForumUploads($payload);
    }

    private static function assertContentVisible(array $user, string $targetType, int $targetId): void
    {
        if ($targetType === 'post') {
            $post = self::post((int) $user['app_id'], $targetId, (int) $user['id']);
            self::ensureApprovedForInteraction($post);
            return;
        }
        if ($targetType !== 'comment') throw new HttpException('target_type 仅支持 post 或 comment', 0, 422);
        $comment = Database::one(
            "SELECT comment.id FROM forum_comments comment
             INNER JOIN forum_posts post ON post.id = comment.post_id
             WHERE comment.id = ? AND comment.app_id = ? AND comment.status = 1
               AND post.status = 1 AND post.deleted_at IS NULL
               AND comment.audit_status = 'approved'
               AND post.audit_status = 'approved'",
            [$targetId, (int) $user['app_id']]
        );
        if ($comment === null) throw new HttpException('评论或回复不存在', 404, 404);
    }

    private static function ensureApprovedForInteraction(array $post): void
    {
        if ((string) ($post['audit_status'] ?? 'pending') !== 'approved') {
            throw new HttpException('帖子尚未审核通过，暂不能评论、点赞、收藏、转发或打赏', 403, 403, [
                'audit_status' => (string) ($post['audit_status'] ?? 'pending'),
                'audit_reason' => (string) ($post['audit_reason'] ?? ''),
            ]);
        }
    }

    private static function assertApprovedForumParentChain(
        int $postId, array $parent, int $adminId, int $appId
    ): void {
        $current = $parent;
        $visited = [];
        for ($depth = 0; $depth < 64; $depth++) {
            $currentId = (int) ($current['id'] ?? 0);
            if ($currentId <= 0 || isset($visited[$currentId])) {
                throw new HttpException('评论回复关系异常，暂不能公开回复', 0, 409);
            }
            $visited[$currentId] = true;
            if ((int) ($current['status'] ?? 0) !== 1
                || (string) ($current['audit_status'] ?? '') !== 'approved') {
                throw new HttpException('上级评论尚未审核通过，暂不能公开回复', 0, 409);
            }
            $nextId = (int) ($current['parent_id'] ?? 0);
            if ($nextId <= 0) return;
            $current = Database::one(
                'SELECT id, parent_id, root_comment_id, user_id, content, audit_status, status
                 FROM forum_comments
                 WHERE id = ? AND post_id = ? AND admin_id = ? AND app_id = ? FOR UPDATE',
                [$nextId, $postId, $adminId, $appId]
            );
            if ($current === null) {
                throw new HttpException('上级评论不存在，暂不能公开回复', 0, 409);
            }
        }
        throw new HttpException('评论回复层级过深，暂不能公开回复', 0, 409);
    }

    /**
     * Preserve the direct reply target while resolving the top-level comment
     * that owns the thread. Legacy rows are followed defensively so a reply can
     * never move under a different comment when the flat list order changes.
     */
    private static function resolveStoredCommentRoot(int $postId, array $parent): int
    {
        $fallbackId = (int) ($parent['id'] ?? 0);
        $current = $parent;
        $visited = [];
        for ($depth = 0; $depth < 64; $depth++) {
            $currentId = (int) ($current['id'] ?? 0);
            if ($currentId <= 0 || isset($visited[$currentId])) {
                return $fallbackId;
            }
            $visited[$currentId] = true;
            if ((int) ($current['parent_id'] ?? 0) <= 0) {
                return $currentId;
            }

            $storedRootId = (int) ($current['root_comment_id'] ?? 0);
            if ($storedRootId > 0) {
                $storedRoot = Database::one(
                    'SELECT id, parent_id FROM forum_comments WHERE id = ? AND post_id = ? AND status = 1',
                    [$storedRootId, $postId]
                );
                if ($storedRoot !== null && (int) ($storedRoot['parent_id'] ?? 0) <= 0) {
                    return (int) $storedRoot['id'];
                }
            }

            $current = Database::one(
                'SELECT id, parent_id, root_comment_id FROM forum_comments WHERE id = ? AND post_id = ? AND status = 1',
                [(int) $current['parent_id'], $postId]
            );
            if ($current === null) {
                return $fallbackId;
            }
        }
        return $fallbackId;
    }

    /**
     * Normalise root_comment_id for both migrated and legacy rows. Top-level
     * comments expose their own ID as the root so every client has one stable
     * grouping key.
     */
    private static function hydrateCommentRoots(array $comments): array
    {
        $byId = [];
        foreach ($comments as $comment) {
            $id = (int) ($comment['id'] ?? 0);
            if ($id > 0) $byId[$id] = $comment;
        }

        $resolved = [];
        $resolve = static function (int $id, array $trail = []) use (&$resolve, &$resolved, $byId): int {
            if ($id <= 0) return 0;
            if (isset($resolved[$id])) return $resolved[$id];
            if (isset($trail[$id])) return $resolved[$id] = $id;
            $row = $byId[$id] ?? null;
            if ($row === null) return $resolved[$id] = $id;

            $parentId = (int) ($row['parent_id'] ?? 0);
            if ($parentId <= 0) return $resolved[$id] = $id;

            $storedRootId = (int) ($row['root_comment_id'] ?? 0);
            if ($storedRootId > 0) {
                $storedRoot = $byId[$storedRootId] ?? null;
                if ($storedRoot === null || (int) ($storedRoot['parent_id'] ?? 0) <= 0) {
                    return $resolved[$id] = $storedRootId;
                }
            }

            $trail[$id] = true;
            return $resolved[$id] = $resolve($parentId, $trail);
        };

        foreach ($comments as &$comment) {
            $id = (int) ($comment['id'] ?? 0);
            $comment['root_comment_id'] = $resolve($id);
        }
        unset($comment);
        return $comments;
    }

    private static function user(Request $request): array
    {
        $user = AuthService::user($request);
        AppService::requireFeature((int) $user['app_id'], 'forum');
        return $user;
    }

    private static function withLegacyImages(array $data): array
    {
        if (isset($data['attachments'])) return $data;
        $images = $data['images'] ?? [];
        if (is_string($images)) $images = json_decode($images, true);
        if (!is_array($images)) $images = [];
        $data['attachments'] = array_map(static fn($url): array => [
            'media_type' => 'image', 'url' => (string) $url,
        ], $images);
        return $data;
    }
}
