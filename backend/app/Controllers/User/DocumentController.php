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
use Yiyunying\Services\LogService;
use Yiyunying\Services\MessageMediaService;

final class DocumentController
{
    public static function index(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        self::ensureEnabled($user);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['admin_id = ?', 'app_id = ?', 'user_id = ?', 'deleted_at IS NULL', 'status = 1'];
        $params = [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']];
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(title LIKE ? OR content LIKE ? OR tags_json LIKE ? OR CAST(id AS CHAR) LIKE ?)';
            foreach (range(1, 4) as $_) $params[] = '%' . $keyword . '%';
        }
        if ($request->input('folder_id') !== null && $request->input('folder_id') !== '') {
            $folderId = (int) $request->input('folder_id');
            $where[] = 'EXISTS (SELECT 1 FROM document_folder_items dfi WHERE dfi.document_id = documents.id AND dfi.folder_id = ?)';
            $params[] = $folderId;
        }
        [$dateStart, $dateEnd] = self::noteDateRange($request);
        if ($dateStart !== null) {
            $where[] = 'created_at >= ?';
            $params[] = $dateStart;
        }
        if ($dateEnd !== null) {
            $where[] = 'created_at < ?';
            $params[] = $dateEnd;
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM documents WHERE {$whereSql}", $params)['total'] ?? 0);
        $items = Database::all(
            "SELECT id, title, content, content_type, tags_json, word_count, is_public, version_no, created_at, updated_at,
                    EXISTS (
                        SELECT 1 FROM content_favorites cf
                        WHERE cf.admin_id = documents.admin_id
                          AND cf.app_id = documents.app_id
                          AND cf.user_id = documents.user_id
                          AND cf.content_type = 'document'
                          AND cf.content_id = documents.id
                    ) AS favorited
             FROM documents WHERE {$whereSql} ORDER BY updated_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        $items = MessageMediaService::hydrate($items, 'note', (int) $user['app_id']);
        foreach ($items as &$item) {
            $item['tags'] = ContentTagService::decode($item['tags_json'] ?? null);
            unset($item['tags_json']);
            $item['excerpt'] = mb_substr(trim(strip_tags((string) $item['content'])), 0, 120);
            unset($item['content']);
            $item['id'] = (int) $item['id'];
            $item['word_count'] = (int) $item['word_count'];
            $item['is_public'] = (bool) $item['is_public'];
            $item['version_no'] = (int) $item['version_no'];
            $item['favorited'] = (bool) ($item['favorited'] ?? false);
            $createdAt = new \DateTimeImmutable((string) $item['created_at']);
            $item['note_date'] = $createdAt->format('Y-m-d');
            $item['year'] = (int) $createdAt->format('Y');
            $item['month'] = (int) $createdAt->format('m');
            $item['day'] = (int) $createdAt->format('d');
            $item['date_label'] = $createdAt->format('Y年m月d日');
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function create(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        self::ensureEnabled($user);
        $data = $request->all();
        Validator::required($data, ['title']);
        $title = Validator::string($data['title'], 'title', 1, 200);
        $content = (string) ($data['content'] ?? '');
        self::validateContent($content);
        $contentType = self::contentType($data['content_type'] ?? 'text');
        $isPublic = Validator::boolean($data['is_public'] ?? false, 'is_public') ? 1 : 0;
        $wordCount = self::wordCount($content);
        $tagsJson = ContentTagService::encode($data['tags'] ?? []);
        $folderId = (int) ($data['folder_id'] ?? 0);
        $mediaPayload = MessageMediaService::userPayload($user, [
            'content' => $title,
            'attachments' => $data['attachments'] ?? [],
        ]);
        $appId = (int) $user['app_id'];
        $adminId = (int) $user['admin_id'];
        $userId = (int) $user['id'];
        $cost = max(0, (int) AppService::setting($appId, 'document_create_cost', 1));
        $maxCount = max(1, (int) AppService::setting($appId, 'document_max_count', 1000));

        $documentId = Database::transaction(static function () use (
            $adminId,
            $appId,
            $userId,
            $cost,
            $maxCount,
            $title,
            $content,
            $contentType,
            $isPublic,
            $wordCount,
            $tagsJson,
            $folderId,
            $mediaPayload
        ): int {
            $count = (int) (Database::one(
                'SELECT COUNT(*) AS total FROM documents
                 WHERE admin_id = ? AND app_id = ? AND user_id = ? AND deleted_at IS NULL AND status = 1',
                [$adminId, $appId, $userId]
            )['total'] ?? 0);
            if ($count >= $maxCount) {
                throw new HttpException('文档数量已达到应用限制', 429, 429);
            }
            $wallet = Database::one(
                'SELECT * FROM user_wallets WHERE admin_id = ? AND app_id = ? AND user_id = ? FOR UPDATE',
                [$adminId, $appId, $userId]
            );
            if ($wallet === null) {
                throw new HttpException('用户资产账户不存在', -1, 500);
            }
            $beforeCredit = (int) $wallet['document_credit'];
            if ($beforeCredit < $cost) {
                throw new HttpException('文档额度不足，请先兑换卡密或联系管理员', 0, 422, [
                    'required' => $cost,
                    'current' => $beforeCredit,
                ]);
            }

            $documentId = Database::insert(
                'INSERT INTO documents
                 (admin_id, app_id, user_id, title, content_type, content, tags_json, word_count, is_public, status, version_no, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW(), NOW())',
                [$adminId, $appId, $userId, $title, $contentType, $content, $tagsJson, $wordCount, $isPublic]
            );
            MessageMediaService::save('note', $documentId, $mediaPayload);
            Database::execute(
                'INSERT INTO document_versions
                 (admin_id, app_id, document_id, user_id, title, content_type, content, word_count, version_no, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())',
                [$adminId, $appId, $documentId, $userId, $title, $contentType, $content, $wordCount]
            );
            if ($folderId > 0) {
                $folder = Database::one(
                    'SELECT id FROM document_folders WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ?',
                    [$folderId, $adminId, $appId, $userId]
                );
                if ($folder === null) {
                    throw new HttpException('文档文件夹不存在', 404, 404);
                }
                Database::execute(
                    'INSERT INTO document_folder_items
                     (admin_id, app_id, user_id, folder_id, document_id, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW())',
                    [$adminId, $appId, $userId, $folderId, $documentId]
                );
            }
            if ($cost > 0) {
                $afterCredit = $beforeCredit - $cost;
                Database::execute(
                    'UPDATE user_wallets SET document_credit = ?, updated_at = NOW()
                     WHERE admin_id = ? AND app_id = ? AND user_id = ?',
                    [$afterCredit, $adminId, $appId, $userId]
                );
                Database::execute(
                    'INSERT INTO user_wallet_logs
                     (admin_id, app_id, user_id, asset_type, change_value, before_value, after_value, scene, ref_type, ref_id, remark, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [$adminId, $appId, $userId, 'document_credit', -$cost, $beforeCredit, $afterCredit, 'document_create', 'document', $documentId, '创建文档消耗额度']
                );
            }
            return $documentId;
        });

        $document = self::find($user, $documentId, false);
        LogService::userOperation($request, $user, 'document', 'create', $documentId, ['title' => $title]);
        LogService::increment($adminId, $appId, 'document_created');
        return Response::success(['document' => $document], '文档创建成功', 201);
    }

    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        self::ensureEnabled($user);
        return Response::success(['document' => self::find($user, (int) $params['document_id'], false)]);
    }

    public static function update(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        self::ensureEnabled($user);
        $documentId = (int) $params['document_id'];
        $data = $request->all();
        if (!array_key_exists('title', $data) && !array_key_exists('content', $data)
            && !array_key_exists('content_type', $data) && !array_key_exists('is_public', $data)
            && !array_key_exists('folder_id', $data) && !array_key_exists('tags', $data)
            && !array_key_exists('attachments', $data)) {
            throw new HttpException('没有可修改的字段', 0, 422);
        }
        $before = self::find($user, $documentId, false);
        $title = array_key_exists('title', $data)
            ? Validator::string($data['title'], 'title', 1, 200)
            : (string) $before['title'];
        $content = array_key_exists('content', $data) ? (string) $data['content'] : (string) $before['content'];
        self::validateContent($content);
        $contentType = array_key_exists('content_type', $data)
            ? self::contentType($data['content_type'])
            : (string) $before['content_type'];
        $isPublic = array_key_exists('is_public', $data)
            ? (Validator::boolean($data['is_public'], 'is_public') ? 1 : 0)
            : (int) $before['is_public'];
        $wordCount = self::wordCount($content);
        $tagsJson = array_key_exists('tags', $data)
            ? ContentTagService::encode($data['tags'])
            : ContentTagService::encode($before['tags'] ?? []);
        $nextVersion = (int) $before['version_no'] + 1;
        $folderId = array_key_exists('folder_id', $data) ? (int) $data['folder_id'] : null;
        $mediaPayload = array_key_exists('attachments', $data)
            ? MessageMediaService::userPayload($user, [
                'content' => $title,
                'attachments' => $data['attachments'],
            ])
            : null;

        Database::transaction(static function () use (
            $user,
            $documentId,
            $title,
            $content,
            $contentType,
            $isPublic,
            $wordCount,
            $tagsJson,
            $nextVersion,
            $folderId,
            $mediaPayload
        ): void {
            $locked = Database::one(
                'SELECT id FROM documents WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ?
                 AND deleted_at IS NULL AND status = 1 FOR UPDATE',
                [$documentId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
            );
            if ($locked === null) {
                throw new HttpException('文档不存在', 404, 404);
            }
            Database::execute(
                'UPDATE documents SET title = ?, content = ?, content_type = ?, tags_json = ?, word_count = ?,
                 is_public = ?, version_no = ?, updated_at = NOW()
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ?',
                [
                    $title, $content, $contentType, $tagsJson, $wordCount, $isPublic, $nextVersion,
                    $documentId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
                ]
            );
            Database::execute(
                'INSERT INTO document_versions
                 (admin_id, app_id, document_id, user_id, title, content_type, content, word_count, version_no, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], $documentId, (int) $user['id'],
                    $title, $contentType, $content, $wordCount, $nextVersion,
                ]
            );
            if ($mediaPayload !== null) {
                MessageMediaService::replace('note', $documentId, $mediaPayload);
            }
            if ($folderId !== null) {
                Database::execute('DELETE FROM document_folder_items WHERE document_id = ? AND user_id = ?', [
                    $documentId, (int) $user['id'],
                ]);
                if ($folderId > 0) {
                    $folder = Database::one(
                        'SELECT id FROM document_folders WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ?',
                        [$folderId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
                    );
                    if ($folder === null) {
                        throw new HttpException('文档文件夹不存在', 404, 404);
                    }
                    Database::execute(
                        'INSERT INTO document_folder_items
                         (admin_id, app_id, user_id, folder_id, document_id, created_at)
                         VALUES (?, ?, ?, ?, ?, NOW())',
                        [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $folderId, $documentId]
                    );
                }
            }
        });

        $after = self::find($user, $documentId, false);
        LogService::userOperation($request, $user, 'document', 'update', $documentId, ['version_no' => $nextVersion]);
        LogService::increment((int) $user['admin_id'], (int) $user['app_id'], 'document_updated');
        return Response::success(['document' => $after], '文档修改成功');
    }

    public static function toggleFavorite(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        self::ensureEnabled($user);
        $documentId = (int) $params['document_id'];
        self::find($user, $documentId, false);

        $existing = Database::one(
            "SELECT id FROM content_favorites
             WHERE admin_id = ? AND app_id = ? AND user_id = ?
               AND content_type = 'document' AND content_id = ?",
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $documentId]
        );
        if ($existing !== null) {
            Database::execute('DELETE FROM content_favorites WHERE id = ?', [(int) $existing['id']]);
            $favorited = false;
            $action = 'unfavorite';
            $message = '已取消收藏笔记';
        } else {
            Database::execute(
                "INSERT IGNORE INTO content_favorites
                 (admin_id, app_id, user_id, content_type, content_id, created_at)
                 VALUES (?, ?, ?, 'document', ?, NOW())",
                [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $documentId]
            );
            $favorited = true;
            $action = 'favorite';
            $message = '笔记已收藏';
        }
        LogService::userOperation($request, $user, 'document', $action, $documentId);
        return Response::success([
            'document_id' => $documentId,
            'favorited' => $favorited,
        ], $message);
    }
    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        self::ensureEnabled($user);
        $documentId = (int) $params['document_id'];
        self::find($user, $documentId, false);
        Database::execute(
            'UPDATE documents SET status = -1, deleted_at = NOW(), updated_at = NOW()
             WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ?',
            [$documentId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        LogService::userOperation($request, $user, 'document', 'delete', $documentId);
        LogService::increment((int) $user['admin_id'], (int) $user['app_id'], 'document_deleted');
        return Response::success([], '文档已删除');
    }

    public static function share(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        self::ensureEnabled($user);
        if (!AppService::setting((int) $user['app_id'], 'document_share_enabled', true)) {
            throw new HttpException('当前应用已关闭文档分享', 403, 403);
        }
        $documentId = (int) $params['document_id'];
        self::find($user, $documentId, false);
        $data = $request->all();
        $passwordProvided = array_key_exists('password', $data);
        $password = $passwordProvided ? (string) $data['password'] : '';
        $expiryProvided = array_key_exists('expired_at', $data);
        $expiredAt = $expiryProvided
            ? Validator::nullableDateTime($data['expired_at'], 'expired_at')
            : null;

        $result = Database::transaction(static function () use (
            $user,
            $documentId,
            $passwordProvided,
            $password,
            $expiryProvided,
            $expiredAt
        ): array {
            $lockedDocument = Database::one(
                'SELECT id FROM documents
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ?
                   AND deleted_at IS NULL AND status = 1
                 FOR UPDATE',
                [$documentId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
            );
            if ($lockedDocument === null) {
                throw new HttpException('文档不存在', 404, 404);
            }
            $existing = Database::one(
                'SELECT * FROM document_shares
                 WHERE document_id = ? AND admin_id = ? AND app_id = ? AND user_id = ?
                 ORDER BY id ASC LIMIT 1 FOR UPDATE',
                [$documentId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
            );
            if ($existing !== null) {
                $passwordHash = $passwordProvided
                    ? ($password === '' ? null : password_hash($password, PASSWORD_DEFAULT))
                    : $existing['password_hash'];
                $nextExpiredAt = $expiryProvided ? $expiredAt : $existing['expired_at'];
                if (!$expiryProvided && $nextExpiredAt !== null
                    && strtotime((string) $nextExpiredAt) < time()) {
                    // Re-enabling an already expired share must make it usable again.
                    $nextExpiredAt = null;
                }
                Database::execute(
                    'UPDATE document_shares
                     SET password_hash = ?, expired_at = ?, status = 1
                     WHERE id = ?',
                    [$passwordHash, $nextExpiredAt, (int) $existing['id']]
                );
                // Older versions could create several codes. Keep the first code fixed and retire the rest.
                Database::execute(
                    'UPDATE document_shares SET status = 0 WHERE document_id = ? AND id <> ?',
                    [$documentId, (int) $existing['id']]
                );
                $shareId = (int) $existing['id'];
                $code = (string) $existing['share_code'];
                $reused = true;
            } else {
                $code = self::newShareCode();
                $shareId = Database::insert(
                    'INSERT INTO document_shares
                     (admin_id, app_id, document_id, user_id, share_code, password_hash, expired_at, view_count, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1, NOW())',
                    [
                        (int) $user['admin_id'], (int) $user['app_id'], $documentId, (int) $user['id'],
                        $code, $password === '' ? null : password_hash($password, PASSWORD_DEFAULT), $expiredAt,
                    ]
                );
                $nextExpiredAt = $expiredAt;
                $passwordHash = $password === '' ? null : 'protected';
                $reused = false;
            }
            Database::execute(
                'UPDATE documents SET is_public = 1, updated_at = NOW()
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ?',
                [$documentId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
            );
            return [
                'id' => $shareId,
                'share_code' => $code,
                'expired_at' => $nextExpiredAt,
                'password_required' => $passwordHash !== null,
                'status' => 1,
                'reused' => $reused,
            ];
        });
        $result['share_url'] = self::shareUrl((string) $result['share_code']);
        LogService::userOperation($request, $user, 'document', 'share', $documentId, [
            'share_id' => $result['id'],
            'reused' => $result['reused'],
        ]);
        return Response::success(
            ['share' => $result],
            $result['reused'] ? '固定分享码已重新启用' : '固定分享码创建成功',
            $result['reused'] ? 200 : 201
        );
    }

    public static function currentShare(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        self::ensureEnabled($user);
        $documentId = (int) $params['document_id'];
        self::find($user, $documentId, false);
        $share = Database::one(
            'SELECT id, share_code, expired_at, view_count, status,
                    CASE WHEN password_hash IS NULL THEN 0 ELSE 1 END AS password_required,
                    created_at
             FROM document_shares
             WHERE document_id = ? AND admin_id = ? AND app_id = ? AND user_id = ?
             ORDER BY id ASC LIMIT 1',
            [$documentId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        if ($share === null) {
            return Response::success(['share' => null]);
        }
        $share['id'] = (int) $share['id'];
        $share['view_count'] = (int) $share['view_count'];
        $share['status'] = (int) $share['status'];
        $share['password_required'] = (bool) $share['password_required'];
        $share['share_url'] = self::shareUrl((string) $share['share_code']);
        return Response::success(['share' => $share]);
    }

    public static function cancelShare(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $shareId = (int) $params['share_id'];
        $share = Database::one(
            'SELECT * FROM document_shares WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ? AND status = 1',
            [$shareId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        if ($share === null) {
            throw new HttpException('分享不存在', 404, 404);
        }
        Database::execute('UPDATE document_shares SET status = 0 WHERE id = ?', [$shareId]);
        $active = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM document_shares WHERE document_id = ? AND status = 1',
            [(int) $share['document_id']]
        )['total'] ?? 0);
        if ($active === 0) {
            Database::execute('UPDATE documents SET is_public = 0, updated_at = NOW() WHERE id = ?', [(int) $share['document_id']]);
        }
        return Response::success([], '文档分享已取消');
    }

    public static function createFolder(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        self::ensureEnabled($user);
        $name = Validator::string($request->input('name', ''), 'name', 1, 100);
        $parentId = (int) $request->input('parent_id', 0);
        if ($parentId > 0 && Database::one(
            'SELECT id FROM document_folders WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ?',
            [$parentId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        ) === null) {
            throw new HttpException('父文件夹不存在', 404, 404);
        }
        $id = Database::insert(
            'INSERT INTO document_folders
             (admin_id, app_id, user_id, parent_id, name, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
                $parentId > 0 ? $parentId : null, $name, (int) $request->input('sort_order', 0),
            ]
        );
        return Response::success(['folder_id' => $id], '文件夹创建成功', 201);
    }

    public static function updateFolder(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $folderId = (int) $params['folder_id'];
        $folder = self::folder($user, $folderId);
        $name = array_key_exists('name', $request->all())
            ? Validator::string($request->input('name'), 'name', 1, 100)
            : (string) $folder['name'];
        $parentId = array_key_exists('parent_id', $request->all()) ? (int) $request->input('parent_id') : (int) ($folder['parent_id'] ?? 0);
        if ($parentId === $folderId) {
            throw new HttpException('文件夹不能以自己为父级', 0, 422);
        }
        if ($parentId > 0) {
            self::folder($user, $parentId);
            self::ensureNoFolderCycle($user, $folderId, $parentId);
        }
        Database::execute(
            'UPDATE document_folders SET name = ?, parent_id = ?, updated_at = NOW()
             WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ?',
            [$name, $parentId > 0 ? $parentId : null, $folderId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        return Response::success([], '文件夹修改成功');
    }

    public static function deleteFolder(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $folderId = (int) $params['folder_id'];
        self::folder($user, $folderId);
        if (Database::one('SELECT id FROM document_folders WHERE parent_id = ? LIMIT 1', [$folderId])) {
            throw new HttpException('请先删除或移动子文件夹', 0, 422);
        }
        Database::transaction(static function () use ($folderId): void {
            Database::execute('DELETE FROM document_folder_items WHERE folder_id = ?', [$folderId]);
            Database::execute('DELETE FROM document_folders WHERE id = ?', [$folderId]);
        });
        return Response::success([], '文件夹已删除，文档保留在根目录');
    }

    private static function ensureNoFolderCycle(array $user, int $folderId, int $parentId): void
    {
        $current = $parentId;
        for ($depth = 0; $depth < 1000 && $current > 0; $depth++) {
            if ($current === $folderId) {
                throw new HttpException('不能把文件夹移动到自己的子级中', 0, 422);
            }
            $row = self::folder($user, $current);
            $current = (int) ($row['parent_id'] ?? 0);
        }
        if ($current > 0) {
            throw new HttpException('文件夹层级过深或存在循环', 0, 422);
        }
    }

    private static function find(array $user, int $documentId, bool $includeDeleted): array
    {
        $sql = "SELECT documents.*,
                       EXISTS (
                           SELECT 1 FROM content_favorites cf
                           WHERE cf.admin_id = documents.admin_id
                             AND cf.app_id = documents.app_id
                             AND cf.user_id = documents.user_id
                             AND cf.content_type = 'document'
                             AND cf.content_id = documents.id
                       ) AS favorited
                FROM documents WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ?";
        if (!$includeDeleted) {
            $sql .= ' AND deleted_at IS NULL AND status = 1';
        }
        $document = Database::one($sql, [
            $documentId,
            (int) $user['admin_id'],
            (int) $user['app_id'],
            (int) $user['id'],
        ]);
        if ($document === null) {
            throw new HttpException('文档不存在', 404, 404);
        }
        $document['id'] = (int) $document['id'];
        $document['word_count'] = (int) $document['word_count'];
        $document['is_public'] = (bool) $document['is_public'];
        $document['version_no'] = (int) $document['version_no'];
        $document['favorited'] = (bool) ($document['favorited'] ?? false);
        $document['tags'] = ContentTagService::decode($document['tags_json'] ?? null);
        unset($document['tags_json']);
        return MessageMediaService::hydrate([$document], 'note', (int) $user['app_id'])[0];
    }

    private static function ensureEnabled(array $user): void
    {
        if (!AppService::setting((int) $user['app_id'], 'document_enabled', true)) {
            throw new HttpException('当前应用已关闭文档功能', 403, 403);
        }
    }

    private static function folder(array $user, int $folderId): array
    {
        $folder = Database::one(
            'SELECT * FROM document_folders WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ?',
            [$folderId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
        );
        if ($folder === null) {
            throw new HttpException('文件夹不存在', 404, 404);
        }
        return $folder;
    }

    private static function contentType($value): string
    {
        $type = strtolower(trim((string) $value));
        if (!in_array($type, ['text', 'markdown', 'html'], true)) {
            throw new HttpException('content_type 仅支持 text、markdown、html', 0, 422);
        }
        return $type;
    }

    private static function noteDateRange(Request $request): array
    {
        $exactDate = trim((string) $request->input('date', ''));
        if ($exactDate !== '') {
            $start = self::parseDate($exactDate, 'date');
            return [$start->format('Y-m-d 00:00:00'), $start->modify('+1 day')->format('Y-m-d 00:00:00')];
        }

        $year = (int) $request->input('year', 0);
        $month = (int) $request->input('month', 0);
        $day = (int) $request->input('day', 0);
        if ($year > 0 || $month > 0 || $day > 0) {
            if ($year < 2000 || $year > 2100) {
                throw new HttpException('year 必须在 2000-2100 之间', 0, 422);
            }
            if ($month < 0 || $month > 12 || $day < 0 || $day > 31 || ($day > 0 && $month === 0)) {
                throw new HttpException('month 或 day 格式错误', 0, 422);
            }
            $month = $month === 0 ? 1 : $month;
            $day = $day === 0 ? 1 : $day;
            if (!checkdate($month, $day, $year)) {
                throw new HttpException('指定的年月日不存在', 0, 422);
            }
            $start = new \DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day));
            if ((int) $request->input('day', 0) > 0) $end = $start->modify('+1 day');
            elseif ((int) $request->input('month', 0) > 0) $end = $start->modify('+1 month');
            else $end = $start->modify('+1 year');
            return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
        }

        $from = trim((string) $request->input('date_from', ''));
        $to = trim((string) $request->input('date_to', ''));
        $start = $from === '' ? null : self::parseDate($from, 'date_from');
        $end = $to === '' ? null : self::parseDate($to, 'date_to')->modify('+1 day');
        if ($start !== null && $end !== null && $start >= $end) {
            throw new HttpException('date_from 不能晚于 date_to', 0, 422);
        }
        return [
            $start?->format('Y-m-d 00:00:00'),
            $end?->format('Y-m-d 00:00:00'),
        ];
    }

    private static function parseDate(string $value, string $field): \DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)
            || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw new HttpException($field . ' 必须是有效的 YYYY-MM-DD 日期', 0, 422);
        }
        return new \DateTimeImmutable($value . ' 00:00:00');
    }

    private static function validateContent(string $content): void
    {
        if (strlen($content) > 2 * 1024 * 1024) {
            throw new HttpException('单篇文档不能超过 2 MB', 0, 422);
        }
    }

    private static function wordCount(string $content): int
    {
        return mb_strlen(trim(strip_tags($content)));
    }

    private static function newShareCode(): string
    {
        do {
            $code = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
        } while (Database::one('SELECT id FROM document_shares WHERE share_code = ? LIMIT 1', [$code]) !== null);
        return $code;
    }

    private static function shareUrl(string $code): string
    {
        return rtrim((string) config('app.url', ''), '/') . '/api/public/note-shares/' . rawurlencode($code);
    }
}
