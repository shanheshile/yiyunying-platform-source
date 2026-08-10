<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;

final class UploadLibraryService
{
    public static function list(int $adminId, int $appId, ?int $userId, Request $request): array
    {
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['up.admin_id = ?', 'up.app_id = ?', 'up.status = 1'];
        $query = [$adminId, $appId];
        if ($userId !== null) {
            $where[] = 'up.user_id = ?';
            $query[] = $userId;
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(up.original_name LIKE ? OR up.scene LIKE ? OR up.mime_type LIKE ?)';
            foreach (range(1, 3) as $_) $query[] = '%' . $keyword . '%';
        }
        $scene = trim((string) $request->input('scene', ''));
        if ($scene !== '') {
            $where[] = 'up.scene = ?';
            $query[] = $scene;
        }
        $category = trim((string) $request->input('category', ''));
        if ($category !== '') {
            $where[] = self::categorySql($category);
        }
        $dateFrom = trim((string) $request->input('date_from', ''));
        if ($dateFrom !== '') {
            $where[] = 'up.created_at >= ?';
            $query[] = $dateFrom . (strlen($dateFrom) <= 10 ? ' 00:00:00' : '');
        }
        $dateTo = trim((string) $request->input('date_to', ''));
        if ($dateTo !== '') {
            $where[] = 'up.created_at <= ?';
            $query[] = $dateTo . (strlen($dateTo) <= 10 ? ' 23:59:59' : '');
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM uploads up WHERE {$whereSql}", $query
        )['total'] ?? 0);
        $favoriteSelect = $userId === null
            ? '0 AS favorited'
            : "EXISTS(SELECT 1 FROM content_favorites favorite
                       WHERE favorite.user_id = ? AND favorite.app_id = ?
                         AND favorite.content_type = 'upload' AND favorite.content_id = up.id) AS favorited";
        $listQuery = $userId === null ? $query : array_merge([$userId, $appId], $query);
        $items = Database::all(
            "SELECT up.id, up.user_id, up.scene, up.original_name, up.file_url, up.mime_type,
                    up.size_bytes, up.sha256, up.created_at, u.account AS user_account,
                    profile.nickname AS user_nickname, profile.avatar AS user_avatar,
                    {$favoriteSelect}
             FROM uploads up LEFT JOIN users u ON u.id = up.user_id
             LEFT JOIN user_profiles profile ON profile.user_id = up.user_id
             WHERE {$whereSql} ORDER BY up.id DESC LIMIT {$limit} OFFSET {$offset}",
            $listQuery
        );
        foreach ($items as &$item) self::decorate($item);
        unset($item);
        return Pagination::data($items, $total, $page, $limit) + [
            'filter_options' => [
                'categories' => [
                    ['value' => 'image', 'label' => '图片'], ['value' => 'video', 'label' => '视频'],
                    ['value' => 'audio', 'label' => '音频'], ['value' => 'document', 'label' => '文档'],
                    ['value' => 'archive', 'label' => '压缩包'], ['value' => 'other', 'label' => '其他文件'],
                ],
                'date_fields' => ['date_from' => '开始日期', 'date_to' => '结束日期'],
            ],
        ];
    }

    public static function remove(int $adminId, int $appId, ?int $userId, int $uploadId): array
    {
        $where = ['id = ?', 'admin_id = ?', 'app_id = ?', 'status = 1'];
        $query = [$uploadId, $adminId, $appId];
        if ($userId !== null) {
            $where[] = 'user_id = ?';
            $query[] = $userId;
        }
        $result = Database::transaction(static function () use (
            $where, $query, $adminId, $appId, $uploadId
        ): array {
            $upload = Database::one(
                'SELECT * FROM uploads WHERE ' . implode(' AND ', $where) . ' FOR UPDATE',
                $query
            );
            if ($upload === null) throw new HttpException('上传文件不存在或无权删除', 404, 404);

            $attachmentReference = Database::one(
                'SELECT id, target_type, target_id FROM media_attachments
                 WHERE admin_id = ? AND app_id = ? AND upload_id = ? LIMIT 1 FOR UPDATE',
                [$adminId, $appId, $uploadId]
            );
            if ($attachmentReference !== null) {
                $targetName = (string) $attachmentReference['target_type'] === 'forum_section'
                    ? '论坛内容节'
                    : '已发布内容';
                throw new HttpException(
                    '该上传文件仍被' . $targetName . '引用，不能删除；请先在原内容中解除引用',
                    0,
                    409
                );
            }

            Database::execute('UPDATE uploads SET status = 0 WHERE id = ?', [$uploadId]);
            $filePath = (string) ($upload['file_path'] ?? '');
            $remainingReferences = $filePath === '' ? 0 : (int) (Database::one(
                'SELECT COUNT(*) AS total FROM uploads WHERE file_path = ? AND status = 1',
                [$filePath]
            )['total'] ?? 0);
            return [
                'upload_id' => $uploadId,
                'original_name' => (string) $upload['original_name'],
                'file_path' => $filePath,
                'physical_file_removed' => $remainingReferences === 0,
                'remaining_references' => $remainingReferences,
            ];
        });
        if ((bool) $result['physical_file_removed']) {
            self::removePhysicalFile((string) $result['file_path']);
        }
        unset($result['file_path']);
        return $result;
    }

    private static function decorate(array &$item): void
    {
        $category = self::category((string) ($item['mime_type'] ?? ''), (string) ($item['original_name'] ?? ''));
        $item['file_category'] = $category;
        $item['file_category_name'] = [
            'image' => '图片', 'video' => '视频', 'audio' => '音频', 'document' => '文档',
            'archive' => '压缩包', 'other' => '其他文件',
        ][$category];
        $item['can_preview'] = in_array($category, ['image', 'video', 'audio', 'document'], true);
        $item['preview_url'] = $item['file_url'];
        $item['size_bytes'] = (int) $item['size_bytes'];
        $item['favorited'] = (bool) ($item['favorited'] ?? false);
    }

    private static function category(string $mime, string $name): string
    {
        $mime = strtolower($mime);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'video/')) return 'video';
        if (str_starts_with($mime, 'audio/')) return 'audio';
        if (in_array($extension, ['zip', 'rar', '7z', 'gz', 'tar'], true)) return 'archive';
        if (str_starts_with($mime, 'text/') || str_contains($mime, 'pdf') || str_contains($mime, 'document')
            || str_contains($mime, 'sheet') || str_contains($mime, 'presentation')
            || in_array($extension, ['txt', 'md', 'json', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], true)) return 'document';
        return 'other';
    }

    private static function categorySql(string $category): string
    {
        return match ($category) {
            'image' => "up.mime_type LIKE 'image/%'",
            'video' => "up.mime_type LIKE 'video/%'",
            'audio' => "up.mime_type LIKE 'audio/%'",
            'archive' => "LOWER(SUBSTRING_INDEX(up.original_name, '.', -1)) IN ('zip','rar','7z','gz','tar')",
            'document' => "(up.mime_type LIKE 'text/%' OR up.mime_type LIKE '%pdf%' OR up.mime_type LIKE '%document%' OR up.mime_type LIKE '%sheet%' OR up.mime_type LIKE '%presentation%')",
            'other' => "(up.mime_type NOT LIKE 'image/%' AND up.mime_type NOT LIKE 'video/%' AND up.mime_type NOT LIKE 'audio/%' AND up.mime_type NOT LIKE 'text/%')",
            default => throw new HttpException('category 仅支持 image、video、audio、document、archive 或 other', 0, 422),
        };
    }

    private static function removePhysicalFile(string $relative): void
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || !str_starts_with($relative, 'uploads/')) return;
        $public = realpath(YIYUNYING_ROOT . '/public');
        $path = realpath(YIYUNYING_ROOT . '/public/' . $relative);
        if ($public !== false && $path !== false && str_starts_with($path, $public . DIRECTORY_SEPARATOR) && is_file($path)) {
            @unlink($path);
        }
    }
}
