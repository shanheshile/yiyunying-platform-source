<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Validator;
use Yiyunying\Services\AdminAccessService;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\NotificationService;
use Yiyunying\Services\RewardRuleService;
use Yiyunying\Services\UploadLibraryService;
use Yiyunying\Services\UploadLimitService;
use Yiyunying\Services\UploadStorageService;

final class FileFeedbackController
{
    private const ALLOWED_UPLOAD_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
        'pdf', 'txt', 'md', 'json', 'csv', 'rtf', 'odt', 'ods', 'odp',
        'zip', 'tar', 'gz',
        'docx', 'xlsx', 'pptx',
        'mp3', 'm4a', 'aac', 'wav', 'ogg', 'opus', 'flac',
        'mp4', 'webm', 'mov', 'mkv', 'avi', '3gp', 'm4v', 'apk',
    ];
    public static function files(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $where = ['f.admin_id = ?', 'f.app_id = ?', 'f.deleted_at IS NULL'];
        $query = [(int) $admin['id'], $appId];
        $parent = $request->input('parent_id');
        if ($parent === null || $parent === '' || (int) $parent === 0) {
            $where[] = 'f.parent_id IS NULL';
        } else {
            $where[] = 'f.parent_id = ?';
            $query[] = (int) $parent;
        }
        return Response::success(['items' => Database::all(
            'SELECT f.*, u.account AS owner_account, p.nickname AS owner_name,
                    (SELECT COUNT(*) FROM remote_file_versions v WHERE v.file_id = f.id) AS version_count
             FROM remote_files f LEFT JOIN users u ON u.id = f.owner_user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE ' . implode(' AND ', $where) . " ORDER BY (f.file_type = 'folder') DESC, f.name, f.id",
            $query
        )]);
    }

    public static function createFolder(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::createFile($request, $params, true);
    }

    public static function createFile(Request $request, array $params, bool $forceFolder = false): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $data = $request->all();
        Validator::required($data, ['name']);
        $parentId = (int) ($data['parent_id'] ?? 0) ?: null;
        self::parent($parentId, (int) $admin['id'], $appId);
        $type = $forceFolder ? 'folder' : trim((string) ($data['file_type'] ?? 'file'));
        if (!in_array($type, ['folder', 'file'], true)) {
            throw new HttpException('file_type 仅支持 folder 或 file', 0, 422);
        }
        $visibility = self::visibility($data);
        $content = $type === 'folder' ? null : (string) ($data['content'] ?? '');
        $fileUrl = $type === 'folder' ? '' : mb_substr((string) ($data['file_url'] ?? ''), 0, 1000);
        $id = Database::transaction(static function () use (
            $admin, $appId, $parentId, $type, $data, $content, $fileUrl, $visibility
        ): int {
            if ($type === 'file') {
                AdminAccessService::requireRemoteDocumentQuota($admin, true);
            }
            $size = max(0, (int) ($data['size_bytes'] ?? strlen((string) $content)));
            $id = Database::insert(
                'INSERT INTO remote_files
                 (admin_id, app_id, owner_user_id, parent_id, file_type, name, content, file_url,
                  mime_type, size_bytes, visibility, status, created_at, updated_at)
                 VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    (int) $admin['id'], $appId, $parentId, $type,
                    Validator::string($data['name'], 'name', 1, 255), $content, $fileUrl,
                    mb_substr((string) ($data['mime_type'] ?? ''), 0, 150), $size, $visibility,
                    Validator::boolean($data['status'] ?? true, 'status') ? 1 : 0,
                ]
            );
            if ($type === 'file') {
                self::saveVersion((int) $admin['id'], $appId, $id, (string) $content, $fileUrl, $size);
            }
            return $id;
        });
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'remote_file', 'create', $id, null, ['file_type' => $type]);
        return Response::success(['file_id' => $id, 'file_type' => $type], $type === 'folder' ? '文件夹创建成功' : '文件创建成功', 201);
    }

    public static function updateFile(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $file = self::ownedFile((int) $params['file_id'], (int) $admin['id'], $appId);
        $parentId = $request->input('parent_id', $file['parent_id']);
        $parentId = (int) $parentId ?: null;
        if ($parentId === (int) $file['id']) {
            throw new HttpException('文件不能作为自己的父级', 0, 422);
        }
        self::parent($parentId, (int) $admin['id'], $appId);
        if ($parentId !== null && (string) $file['file_type'] === 'folder') {
            self::ensureNoFileCycle((int) $file['id'], $parentId, (int) $admin['id'], $appId);
        }
        $content = (string) $request->input('content', $file['content'] ?? '');
        $fileUrl = mb_substr((string) $request->input('file_url', $file['file_url']), 0, 1000);
        $visibility = self::visibility($request->all(), (string) $file['visibility']);
        $changed = $content !== (string) ($file['content'] ?? '') || $fileUrl !== (string) $file['file_url'];
        Database::transaction(static function () use ($request, $admin, $appId, $file, $parentId, $content, $fileUrl, $visibility, $changed): void {
            Database::execute(
                'UPDATE remote_files SET parent_id = ?, name = ?, content = ?, file_url = ?, mime_type = ?,
                 size_bytes = ?, visibility = ?, status = ?, updated_at = NOW() WHERE id = ?',
                [
                    $parentId, mb_substr((string) $request->input('name', $file['name']), 0, 255),
                    (string) $file['file_type'] === 'folder' ? null : $content,
                    (string) $file['file_type'] === 'folder' ? '' : $fileUrl,
                    mb_substr((string) $request->input('mime_type', $file['mime_type']), 0, 150),
                    max(0, (int) $request->input('size_bytes', strlen($content))), $visibility,
                    Validator::boolean($request->input('status', (bool) $file['status']), 'status') ? 1 : 0,
                    (int) $file['id'],
                ]
            );
            if ($changed && (string) $file['file_type'] === 'file') {
                self::saveVersion((int) $admin['id'], $appId, (int) $file['id'], $content, $fileUrl, strlen($content));
            }
        });
        return Response::success(['file_id' => (int) $file['id']], '远程文件已更新');
    }

    public static function deleteFile(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $file = self::ownedFile((int) $params['file_id'], (int) $admin['id'], $appId);
        self::softDeleteTree((int) $file['id'], (int) $admin['id'], $appId);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'remote_file', 'delete', (int) $file['id']);
        return Response::success(['file_id' => (int) $file['id']], '远程文件已删除');
    }

    public static function versions(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $file = self::ownedFile((int) $params['file_id'], (int) $admin['id'], $appId);
        return Response::success(['file_id' => (int) $file['id'], 'items' => Database::all(
            'SELECT * FROM remote_file_versions WHERE file_id = ? ORDER BY version_no DESC',
            [(int) $file['id']]
        )]);
    }

    public static function feedbacks(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['f.admin_id = ?', 'f.app_id = ?'];
        $query = [(int) $admin['id'], $appId];
        if (trim((string) $request->input('status', '')) !== '') {
            $where[] = 'f.status = ?';
            $query[] = trim((string) $request->input('status'));
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM feedbacks f WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT f.*, u.account, p.nickname, p.avatar FROM feedbacks f
             INNER JOIN users u ON u.id = f.user_id LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE {$whereSql} ORDER BY f.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function replyFeedback(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $feedback = Database::one('SELECT * FROM feedbacks WHERE id = ? AND admin_id = ? AND app_id = ?', [
            (int) $params['feedback_id'], (int) $admin['id'], $appId,
        ]);
        if ($feedback === null) {
            throw new HttpException('反馈不存在', 404, 404);
        }
        $reply = Validator::string($request->input('reply_content', ''), 'reply_content', 1, 20000);
        $status = trim((string) $request->input('status', 'resolved'));
        if (!in_array($status, ['pending', 'processing', 'resolved', 'closed'], true)) {
            throw new HttpException('反馈状态不正确', 0, 422);
        }
        Database::execute(
            'UPDATE feedbacks SET reply_content = ?, status = ?, replied_by = ?, replied_at = NOW(), updated_at = NOW() WHERE id = ?',
            [$reply, $status, (int) $admin['id'], (int) $feedback['id']]
        );
        $rewardResult = null;
        if ($status === 'resolved' && (string) $feedback['status'] !== 'resolved') {
            $author = NotificationService::user((int) $admin['id'], $appId, (int) $feedback['user_id']);
            if ($author !== null) {
                $rewardResult = RewardRuleService::trigger(
                    $author,
                    'valid_feedback',
                    'feedback',
                    (int) $feedback['id'],
                    [
                        'approved' => true,
                        'status' => 'resolved',
                        'content' => trim((string) $feedback['title'] . "\n" . (string) $feedback['content']),
                        'feedback_type' => (string) $feedback['type'],
                    ],
                    'admin',
                    (int) $admin['id']
                );
            }
        }
        return Response::success([
            'feedback_id' => (int) $feedback['id'],
            'status' => $status,
            'reward_result' => $rewardResult,
        ], '反馈回复成功');
    }

    public static function uploads(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        return Response::success(UploadLibraryService::list((int) $admin['id'], $appId, null, $request));
    }

    public static function deleteUpload(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $result = UploadLibraryService::remove((int) $admin['id'], $appId, null, (int) $params['upload_id']);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'upload', 'delete', (int) $params['upload_id']);
        return Response::success($result, '上传文件已删除');
    }

    public static function upload(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            throw new HttpException('缺少 multipart/form-data 文件字段 file', 0, 422);
        }
        $file = $_FILES['file'];
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException('文件上传失败', 0, 422, ['upload_error' => (int) ($file['error'] ?? -1)]);
        }
        $limit = UploadLimitService::validate($appId, $file);
        if (!$limit['valid']) throw new HttpException(
            UploadLimitService::label((string) $limit['category']) . '大小超出当前应用限制',
            0,
            422,
            $limit + ['unit' => '字节']
        );
        $original = basename((string) ($file['name'] ?? 'file'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, self::ALLOWED_UPLOAD_EXTENSIONS, true)) {
            throw new HttpException('不允许上传该文件类型', 0, 422, ['extension' => $extension]);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) throw new HttpException('上传临时文件无效', 0, 422);
        $result = UploadStorageService::store(
            $file, (int) $admin['id'], $appId, null,
            (string) $request->input('scene', 'message'), self::ALLOWED_UPLOAD_EXTENSIONS,
            ['original_upload' => $request->input('original_upload', false)]
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'upload', 'create', (int) $result['upload_id'], null, [
            'reused' => (bool) $result['reused'],
        ]);
        return Response::success($result, (bool) $result['reused'] ? '已复用相同文件，无需重复上传' : '文件上传成功', 201);
    }

    public static function botQa(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        return Response::success(['items' => Database::all(
            'SELECT * FROM bot_qa WHERE admin_id = ? AND app_id = ? ORDER BY sort_order DESC, id DESC',
            [(int) $admin['id'], $appId]
        )]);
    }

    public static function saveBotQa(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $question = Validator::string($request->input('question', ''), 'question', 1, 500);
        $answer = Validator::string($request->input('answer', ''), 'answer', 1, 20000);
        $id = (int) $request->input('id', 0);
        if ($id > 0) {
            $existing = Database::one('SELECT id FROM bot_qa WHERE id = ? AND admin_id = ? AND app_id = ?', [$id, (int) $admin['id'], $appId]);
            if ($existing === null) {
                throw new HttpException('问答规则不存在', 404, 404);
            }
            Database::execute(
                'UPDATE bot_qa SET question = ?, answer = ?, keywords = ?, sort_order = ?, status = ?, updated_at = NOW() WHERE id = ?',
                [$question, $answer, mb_substr((string) $request->input('keywords', ''), 0, 1000), (int) $request->input('sort_order', 0), Validator::boolean($request->input('status', true), 'status') ? 1 : 0, $id]
            );
        } else {
            $id = Database::insert(
                'INSERT INTO bot_qa
                 (admin_id, app_id, question, answer, keywords, sort_order, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [(int) $admin['id'], $appId, $question, $answer, mb_substr((string) $request->input('keywords', ''), 0, 1000), (int) $request->input('sort_order', 0), Validator::boolean($request->input('status', true), 'status') ? 1 : 0]
            );
        }
        return Response::success(['qa_id' => $id], '问答规则已保存', 201);
    }

    public static function deleteBotQa(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $affected = Database::execute('DELETE FROM bot_qa WHERE id = ? AND admin_id = ? AND app_id = ?', [
            (int) $params['qa_id'], (int) $admin['id'], $appId,
        ]);
        if ($affected === 0) {
            throw new HttpException('问答规则不存在', 404, 404);
        }
        return Response::success(['qa_id' => (int) $params['qa_id']], '问答规则已删除');
    }

    private static function visibility(array $data, string $default = 'public'): string
    {
        if (array_key_exists('is_public', $data)) {
            return Validator::boolean($data['is_public'], 'is_public') ? 'public' : 'private';
        }
        $value = trim((string) ($data['visibility'] ?? $default));
        if (!in_array($value, ['public', 'private', 'user'], true)) {
            throw new HttpException('visibility 不正确', 0, 422);
        }
        return $value;
    }

    private static function parent(?int $parentId, int $adminId, int $appId): void
    {
        if ($parentId === null) {
            return;
        }
        if (!Database::one(
            "SELECT id FROM remote_files WHERE id = ? AND admin_id = ? AND app_id = ? AND file_type = 'folder' AND deleted_at IS NULL",
            [$parentId, $adminId, $appId]
        )) {
            throw new HttpException('父文件夹不存在', 404, 404);
        }
    }

    private static function ensureNoFileCycle(int $fileId, int $parentId, int $adminId, int $appId): void
    {
        $current = $parentId;
        for ($depth = 0; $depth < 1000 && $current > 0; $depth++) {
            if ($current === $fileId) {
                throw new HttpException('不能把文件夹移动到自己的子级中', 0, 422);
            }
            $row = Database::one(
                'SELECT parent_id FROM remote_files WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
                [$current, $adminId, $appId]
            );
            $current = $row === null ? 0 : (int) ($row['parent_id'] ?? 0);
        }
        if ($current > 0) {
            throw new HttpException('文件夹层级过深或存在循环', 0, 422);
        }
    }

    private static function ownedFile(int $id, int $adminId, int $appId): array
    {
        $file = Database::one(
            'SELECT * FROM remote_files WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
            [$id, $adminId, $appId]
        );
        if ($file === null) {
            throw new HttpException('远程文件不存在', 404, 404);
        }
        return $file;
    }

    private static function saveVersion(int $adminId, int $appId, int $fileId, string $content, string $fileUrl, int $size): void
    {
        $last = Database::one('SELECT MAX(version_no) AS version_no FROM remote_file_versions WHERE file_id = ?', [$fileId]);
        Database::execute(
            'INSERT INTO remote_file_versions
             (admin_id, app_id, file_id, version_no, content, file_url, size_bytes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [$adminId, $appId, $fileId, (int) ($last['version_no'] ?? 0) + 1, $content, $fileUrl, max(0, $size)]
        );
    }

    private static function softDeleteTree(int $fileId, int $adminId, int $appId): void
    {
        $children = Database::all(
            'SELECT id FROM remote_files WHERE parent_id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
            [$fileId, $adminId, $appId]
        );
        foreach ($children as $child) {
            self::softDeleteTree((int) $child['id'], $adminId, $appId);
        }
        Database::execute('UPDATE remote_files SET status = 0, deleted_at = NOW(), updated_at = NOW() WHERE id = ?', [$fileId]);
    }

    private static function context(Request $request, array $params): array
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        return [$admin, $appId];
    }
}
