<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class UploadStorageService
{
    public static function store(
        array $file,
        int $adminId,
        int $appId,
        ?int $userId,
        string $scene,
        array $allowedExtensions,
        array $options = []
    ): array {
        $size = (int) ($file['size'] ?? 0);
        $original = basename((string) ($file['name'] ?? 'file'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            throw new HttpException('不允许上传该文件类型', 0, 422, ['extension' => $extension]);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) throw new HttpException('上传临时文件无效', 0, 422);
        $sha256 = hash_file('sha256', $tmp);
        if (!is_string($sha256) || strlen($sha256) !== 64) throw new HttpException('计算文件内容指纹失败', -1, 500);
        $mime = function_exists('mime_content_type') ? (string) mime_content_type($tmp) : (string) ($file['type'] ?? '');
        $scene = mb_substr(trim($scene) !== '' ? trim($scene) : 'general', 0, 40);
        $originalUpload = self::boolean($options['original_upload'] ?? false);
        $requestedMode = $originalUpload ? 'original' : 'optimized';
        $privateUpload = self::privateScene($scene);
        if ($extension === 'svg' || strtolower(trim($mime)) === 'image/svg+xml') {
            throw new HttpException('不支持 SVG 文件；请转换为 PNG、JPG、GIF 或 WebP', 0, 422);
        }
        if ($originalUpload && !(bool) AppService::setting($appId, 'media_original_upload_enabled', true)) {
            throw new HttpException('当前应用不允许上传原图或原视频，请关闭原媒体开关后重试', 0, 422);
        }

        $storageClause = $privateUpload
            ? " AND file_path LIKE 'private/%'"
            : " AND file_path NOT LIKE 'private/%'";
        $deduplicated = Database::transaction(static function () use (
            $adminId, $appId, $userId, $scene, $sha256, $size, $original, $requestedMode,
            $storageClause, $mime
        ): ?array {
            $existing = Database::one(
                'SELECT * FROM uploads WHERE admin_id = ? AND app_id = ? AND sha256 = ?
                 AND (original_size_bytes = ? OR (original_size_bytes = 0 AND size_bytes = ?))
                 AND original_name = ? AND upload_mode = ? AND status = 1' . $storageClause
                 . ' ORDER BY id LIMIT 1 FOR UPDATE',
                [$adminId, $appId, $sha256, $size, $size, mb_substr($original, 0, 255), $requestedMode]
            );
            if ($existing === null || !self::physicalExists((string) $existing['file_path'])) return null;
            $sameOwner = Database::one(
                'SELECT * FROM uploads WHERE admin_id = ? AND app_id = ? AND user_id <=> ? AND scene = ?
                 AND sha256 = ? AND (original_size_bytes = ? OR (original_size_bytes = 0 AND size_bytes = ?))
                 AND original_name = ? AND upload_mode = ? AND status = 1' . $storageClause
                 . ' ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [$adminId, $appId, $userId, $scene, $sha256, $size, $size, mb_substr($original, 0, 255), $requestedMode]
            );
            if ($sameOwner !== null) return self::result($sameOwner, true, false);
            $id = Database::insert(
                'INSERT INTO uploads
                 (admin_id, app_id, user_id, scene, original_name, stored_name, file_path, file_url,
                  mime_type, size_bytes, original_size_bytes, optimized_size_bytes, upload_mode,
                  optimization_status, original_file_url, optimized_file_url, thumbnail_url, is_animated,
                  sha256, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())',
                [$adminId, $appId, $userId, $scene, mb_substr($original, 0, 255),
                 (string) $existing['stored_name'], (string) $existing['file_path'], (string) $existing['file_url'],
                 mb_substr((string) ($existing['mime_type'] ?: $mime), 0, 150), (int) $existing['size_bytes'],
                 (int) ($existing['original_size_bytes'] ?: $size), (int) $existing['optimized_size_bytes'],
                 (string) $existing['upload_mode'], (string) $existing['optimization_status'],
                 (string) $existing['original_file_url'], (string) $existing['optimized_file_url'],
                 (string) $existing['thumbnail_url'], (int) $existing['is_animated'], $sha256]
            );
            $logical = $existing;
            $logical['id'] = $id;
            $logical['user_id'] = $userId;
            $logical['scene'] = $scene;
            return self::result($logical, true, true);
        });
        if ($deduplicated !== null) return $deduplicated;

        $relativeDir = ($privateUpload ? 'private/uploads/' : 'uploads/') . $appId . '/' . date('Y/m');
        $storageRoot = YIYUNYING_ROOT . ($privateUpload ? '/storage/' : '/public/');
        $storageDir = $storageRoot . $relativeDir;
        if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            throw new HttpException('创建上传目录失败', -1, 500);
        }
        $stored = bin2hex(random_bytes(16)) . '.' . $extension;
        $originalPath = $storageDir . '/' . $stored;
        if (!move_uploaded_file($tmp, $originalPath)) throw new HttpException('保存上传文件失败', -1, 500);
        $originalRelative = str_replace('\\', '/', $relativeDir) . '/' . $stored;
        $baseUrl = rtrim((string) config('app.url'), '/');
        $originalUrl = $privateUpload ? '' : $baseUrl . '/' . $originalRelative;
        $optimize = !$originalUpload && (bool) AppService::setting($appId, 'media_optimize_by_default', true);
        if (str_contains(mb_strtolower($scene), '表情') || str_contains(strtolower($scene), 'sticker')) {
            $optimize = !$originalUpload && (bool) AppService::setting($appId, 'sticker_optimize_enabled', true);
        }
        $optimization = $optimize
            ? MediaOptimizationService::optimize(
                $originalPath,
                $mime,
                $scene,
                max(65536, (int) AppService::setting($appId, 'sticker_target_max_bytes', 524288))
            )
            : [
                'path' => $originalPath, 'mime_type' => $mime, 'size_bytes' => $size,
                'status' => 'original', 'is_animated' => $extension === 'gif',
                'thumbnail_path' => '', 'width' => 0, 'height' => 0,
            ];
        $mainPath = (string) $optimization['path'];
        $relative = self::relativeStoredPath($mainPath, $privateUpload);
        $url = $privateUpload ? '' : $baseUrl . '/' . $relative;
        $thumbnailPath = (string) ($optimization['thumbnail_path'] ?? '');
        $thumbnailUrl = !$privateUpload && $thumbnailPath !== ''
            ? $baseUrl . '/' . self::relativeStoredPath($thumbnailPath, false)
            : '';
        $mainSize = max(0, (int) ($optimization['size_bytes'] ?? filesize($mainPath)));
        $mainMime = trim((string) ($optimization['mime_type'] ?? '')) ?: $mime;
        $uploadMode = $originalUpload ? 'original' : 'optimized';
        $optimizedUrl = $uploadMode === 'optimized' ? $url : '';
        $keptOriginalUrl = $originalUpload ? $originalUrl : '';
        if (!$originalUpload && $mainPath !== $originalPath) @unlink($originalPath);
        try {
            $id = Database::insert(
                'INSERT INTO uploads
                 (admin_id, app_id, user_id, scene, original_name, stored_name, file_path, file_url,
                  mime_type, size_bytes, original_size_bytes, optimized_size_bytes, upload_mode,
                  optimization_status, original_file_url, optimized_file_url, thumbnail_url, is_animated,
                  sha256, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())',
                [$adminId, $appId, $userId, $scene, mb_substr($original, 0, 255), basename($mainPath), $relative,
                 $url, mb_substr($mainMime, 0, 150), $mainSize, $size,
                 $uploadMode === 'optimized' ? $mainSize : 0, $uploadMode,
                 mb_substr((string) $optimization['status'], 0, 40), $keptOriginalUrl, $optimizedUrl,
                 $thumbnailUrl, (bool) ($optimization['is_animated'] ?? false) ? 1 : 0, $sha256]
            );
        } catch (\Throwable $exception) {
            @unlink($mainPath);
            if ($mainPath !== $originalPath) @unlink($originalPath);
            throw $exception;
        }
        return [
            'upload_id' => $id, 'file_url' => $url, 'mime_type' => $mainMime, 'size_bytes' => $mainSize,
            'original_size_bytes' => $size, 'optimized_size_bytes' => $uploadMode === 'optimized' ? $mainSize : 0,
            'upload_mode' => $uploadMode, 'optimization_status' => (string) $optimization['status'],
            'original_file_url' => $keptOriginalUrl, 'optimized_file_url' => $optimizedUrl,
            'thumbnail_url' => $thumbnailUrl, 'is_animated' => (bool) ($optimization['is_animated'] ?? false),
            'sha256' => $sha256, 'reused' => false, 'shared_physical_file' => false,
        ];
    }

    private static function result(array $upload, bool $reused, bool $shared): array
    {
        return [
            'upload_id' => (int) $upload['id'], 'file_url' => (string) $upload['file_url'],
            'mime_type' => (string) $upload['mime_type'], 'size_bytes' => (int) $upload['size_bytes'],
            'original_size_bytes' => (int) ($upload['original_size_bytes'] ?: $upload['size_bytes']),
            'optimized_size_bytes' => (int) ($upload['optimized_size_bytes'] ?? 0),
            'upload_mode' => (string) ($upload['upload_mode'] ?? 'original'),
            'optimization_status' => (string) ($upload['optimization_status'] ?? 'not_required'),
            'original_file_url' => (string) ($upload['original_file_url'] ?? ''),
            'optimized_file_url' => (string) ($upload['optimized_file_url'] ?? ''),
            'thumbnail_url' => (string) ($upload['thumbnail_url'] ?? ''),
            'is_animated' => (bool) ($upload['is_animated'] ?? false),
            'sha256' => (string) $upload['sha256'], 'reused' => $reused,
            'shared_physical_file' => $shared,
        ];
    }

    private static function physicalExists(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '') return false;
        $root = str_starts_with($relative, 'private/') ? '/storage/' : '/public/';
        return is_file(YIYUNYING_ROOT . $root . $relative);
    }

    public static function privatePhysicalPath(string $relative): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if (!str_starts_with($relative, 'private/')) return null;
        $root = realpath(YIYUNYING_ROOT . '/storage/private');
        $path = realpath(YIYUNYING_ROOT . '/storage/' . $relative);
        if ($root === false || $path === false || !is_file($path)) return null;
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $normalized = str_replace('\\', '/', $path);
        return str_starts_with($normalized, $root) ? $path : null;
    }

    public static function storedPhysicalPath(string $relative): ?string
    {
        return str_starts_with(ltrim(str_replace('\\', '/', $relative), '/'), 'private/')
            ? self::privatePhysicalPath($relative)
            : self::publicPhysicalPath($relative);
    }

    public static function storedPathState(string $relative): array
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if (str_starts_with($relative, 'private/')) {
            return self::boundedStoredPathState(YIYUNYING_ROOT . '/storage', $relative, true);
        }
        return self::publicStoredPathState($relative);
    }

    /** Read-only readiness check used by ordinary GET and submission requests. */
    public static function verifiedPrivateCatalogUpload(
        int $uploadId,
        int $adminId,
        int $appId,
        string $expectedScene,
        bool $fullIntegrity = false,
        bool $allowInactive = false,
        bool $allowPendingCleanup = false
    ): array {
        $expectedScene = strtolower(trim($expectedScene));
        if (!in_array($expectedScene, ['resource_source', 'store_app_package'], true)) {
            throw new \InvalidArgumentException('Unsupported private catalog scene');
        }
        $upload = Database::one(
            'SELECT * FROM uploads WHERE id = ? AND admin_id = ? AND app_id = ? AND scene = ?',
            [$uploadId, $adminId, $appId, $expectedScene]
        );
        if ($upload !== null && !$allowInactive && (int) ($upload['status'] ?? 0) !== 1) $upload = null;
        if ($upload === null || !str_starts_with((string) ($upload['file_path'] ?? ''), 'private/')) {
            throw new HttpException('该文件尚未完成私有化迁移，当前不可审核、购买或下载', 0, 409);
        }
        $pendingCleanup = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM catalog_file_migrations
             WHERE upload_id = ? AND cleanup_status <> 'cleaned'",
            [$uploadId]
        )['total'] ?? 0);
        if ($pendingCleanup > 0 && !$allowPendingCleanup) {
            throw new HttpException('旧公开副本尚未完成安全清理，当前不可审核、购买或下载', 0, 409);
        }
        $path = self::privatePhysicalPath((string) $upload['file_path']);
        $expectedHash = strtolower(trim((string) ($upload['sha256'] ?? '')));
        $expectedSize = max(0, (int) ($upload['size_bytes'] ?? 0));
        $actualSize = $path === null ? false : filesize($path);
        if ($path === null || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1 || $expectedSize <= 0
            || $actualSize === false || $actualSize !== $expectedSize) {
            throw new HttpException('私有文件缺失或完整性校验失败，请重新上传', 0, 409);
        }
        if ($fullIntegrity) {
            self::verifyPrivateFileIntegrity($path, $expectedSize, $expectedHash);
        }
        return $upload;
    }

    /**
     * Full read-only preflight for the maintenance-only catalog migration tool.
     * It intentionally performs the expensive hash and reference checks before
     * any database row or physical byte is changed.
     */
    public static function preflightCatalogMigration(
        int $uploadId,
        int $adminId,
        int $appId,
        string $expectedScene,
        bool $allowInactive = false
    ): array {
        $expectedScene = strtolower(trim($expectedScene));
        if (!in_array($expectedScene, ['resource_source', 'store_app_package'], true)) {
            throw new \InvalidArgumentException('Unsupported private catalog scene');
        }
        $upload = Database::one(
            'SELECT * FROM uploads WHERE id = ? AND admin_id = ? AND app_id = ? AND scene = ?',
            [$uploadId, $adminId, $appId, $expectedScene]
        );
        if ($upload === null || (!$allowInactive && (int) ($upload['status'] ?? 0) !== 1)) {
            throw new HttpException('资源文件不存在、已失效或类型不匹配，请重新上传', 0, 422);
        }
        $relative = ltrim(str_replace('\\', '/', (string) ($upload['file_path'] ?? '')), '/');
        if (str_starts_with($relative, 'private/')) {
            self::verifiedPrivateCatalogUpload(
                $uploadId, $adminId, $appId, $expectedScene, true, $allowInactive, true
            );
            return ['already_private' => true, 'copy_bytes' => 0, 'upload' => $upload];
        }
        $state = self::publicStoredPathState($relative);
        if (($state['status'] ?? '') !== 'file') {
            throw new HttpException(
                ($state['status'] ?? '') === 'unsafe'
                    ? '旧资源路径包含链接、越界或异常对象，必须人工隔离'
                    : '旧资源文件缺失，请重新上传或确认历史条目已安全停用',
                0,
                409
            );
        }
        $path = (string) $state['path'];
        $stat = @lstat($path);
        if (!is_array($stat) || (int) ($stat['nlink'] ?? 0) !== 1) {
            throw new HttpException('旧资源文件存在硬链接或文件状态异常，必须人工隔离', 0, 409);
        }
        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        if ($size === false || $size <= 0 || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new HttpException('旧资源文件无法完成只读完整性预检', 0, 409);
        }
        $logicalRows = Database::all(
            "SELECT id FROM uploads WHERE admin_id = ? AND app_id = ? AND file_path = ?
              AND scene IN ('resource_source', 'store_app_package')",
            [$adminId, $appId, $relative]
        );
        $ids = array_values(array_filter(array_map(
            static fn (array $row): int => max(0, (int) ($row['id'] ?? 0)),
            $logicalRows
        )));
        if ($ids === []) $ids = [$uploadId];
        $idList = implode(',', $ids);
        $otherReferences = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM uploads WHERE file_path = ? AND id NOT IN ({$idList})",
            [$relative]
        )['total'] ?? 0);
        if ($otherReferences > 0) {
            throw new HttpException('同一文件仍被非目录上传引用，必须重新上传独立文件', 0, 409);
        }
        if (self::unboundLegacyCatalogUrlReferences($relative, $ids) > 0) {
            throw new HttpException('旧公开地址仍被未绑定目录条目引用，必须先人工绑定或隔离', 0, 409);
        }
        return [
            'already_private' => false,
            'copy_bytes' => (int) $size,
            'sha256' => strtolower($hash),
            'upload' => $upload,
        ];
    }

    private static function verifyPrivateFileIntegrity(string $path, int $expectedSize, string $expectedHash): void
    {
        clearstatcache(true, $path);
        $mtime = filemtime($path);
        if ($mtime === false) throw new HttpException('私有文件状态读取失败，请重新上传', 0, 409);
        $cachePath = $path . '.integrity.json';
        if (is_link($cachePath)) throw new HttpException('私有文件校验缓存路径异常', 0, 409);
        if (is_file($cachePath)) {
            $cached = json_decode((string) file_get_contents($cachePath), true);
            if (is_array($cached)
                && (int) ($cached['size_bytes'] ?? -1) === $expectedSize
                && (int) ($cached['file_mtime'] ?? -1) === $mtime
                && (int) ($cached['verified_at'] ?? 0) >= time() - 300
                && hash_equals($expectedHash, strtolower((string) ($cached['sha256'] ?? '')))) {
                return;
            }
        }
        $actualHash = hash_file('sha256', $path);
        if ($actualHash === false || !hash_equals($expectedHash, strtolower($actualHash))) {
            throw new HttpException('私有文件完整性校验失败，请重新上传', 0, 409);
        }
        $payload = json_encode([
            'size_bytes' => $expectedSize,
            'file_mtime' => $mtime,
            'sha256' => $expectedHash,
            'verified_at' => time(),
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || file_put_contents($cachePath, $payload . PHP_EOL, LOCK_EX) === false) {
            throw new HttpException('私有文件校验缓存写入失败', -1, 500);
        }
        @chmod($cachePath, 0600);
    }

    public static function publicStoredPathState(string $relative): array
    {
        return self::boundedStoredPathState(YIYUNYING_ROOT . '/public', $relative, false);
    }

    private static function boundedStoredPathState(string $rootDirectory, string $relative, bool $private): array
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || str_contains($relative, '..')
            || ($private && !str_starts_with($relative, 'private/'))
            || (!$private && str_starts_with($relative, 'private/'))) {
            return ['status' => 'unsafe', 'path' => null];
        }
        $root = realpath($rootDirectory);
        if ($root === false) return ['status' => 'missing', 'path' => null];
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $current = $root;
        foreach (array_values(array_filter(explode('/', $relative), static fn(string $part): bool => $part !== '')) as $part) {
            if ($part === '.' || $part === '..') return ['status' => 'unsafe', 'path' => null];
            $current .= '/' . $part;
            if (!file_exists($current) && !is_link($current)) return ['status' => 'missing', 'path' => null];
            if (is_link($current)) return ['status' => 'unsafe', 'path' => $current];
        }
        $real = realpath($current);
        if ($real === false || !is_file($real)) return ['status' => 'unsafe', 'path' => $current];
        $normalized = str_replace('\\', '/', $real);
        if (!str_starts_with($normalized, $root . '/')) return ['status' => 'unsafe', 'path' => $current];
        return ['status' => 'file', 'path' => $real];
    }

    /**
     * Moves a catalog package/source out of the public web root before it can be
     * reviewed, sold or downloaded. Existing logical catalog uploads sharing the
     * same physical file are migrated together; a genuinely public non-catalog
     * reference keeps its original copy because those bytes are already public.
     */
    public static function ensurePrivateCatalogUpload(
        int $uploadId,
        int $adminId,
        int $appId,
        string $expectedScene,
        bool $allowInactive = false
    ): array {
        $expectedScene = strtolower(trim($expectedScene));
        if (!in_array($expectedScene, ['resource_source', 'store_app_package'], true)) {
            throw new \InvalidArgumentException('Unsupported private catalog scene');
        }
        $upload = Database::one(
            'SELECT * FROM uploads WHERE id = ? AND admin_id = ? AND app_id = ? AND scene = ?',
            [$uploadId, $adminId, $appId, $expectedScene]
        );
        if ($upload !== null && !$allowInactive && (int) ($upload['status'] ?? 0) !== 1) $upload = null;
        if ($upload === null) {
            throw new HttpException('资源文件不存在、已失效或类型不匹配，请重新上传', 0, 422);
        }
        if (str_starts_with((string) $upload['file_path'], 'private/')) {
            self::reconcileCatalogPublicCleanup($uploadId, $adminId, $appId);
            if (self::privatePhysicalPath((string) $upload['file_path']) === null) {
                throw new HttpException('资源文件缺失，请重新上传后再审核', 0, 409);
            }
            return $upload;
        }

        $oldRelative = ltrim(str_replace('\\', '/', (string) $upload['file_path']), '/');
        $oldPath = self::publicPhysicalPath($oldRelative);
        if ($oldPath === null) {
            throw new HttpException('旧资源文件缺失，请重新上传后再审核', 0, 409);
        }
        $oldStat = @lstat($oldPath);
        if (!is_array($oldStat) || (int) ($oldStat['nlink'] ?? 0) !== 1) {
            throw new HttpException('旧资源文件存在硬链接或文件状态异常，必须人工隔离', 0, 409);
        }
        $extension = strtolower(pathinfo((string) $upload['stored_name'], PATHINFO_EXTENSION));
        if ($extension === '' || preg_match('/^[a-z0-9]{1,12}$/', $extension) !== 1) $extension = 'bin';
        $relativeDir = 'private/uploads/' . $appId . '/' . date('Y/m');
        $storageDir = YIYUNYING_ROOT . '/storage/' . $relativeDir;
        if (!is_dir($storageDir) && !mkdir($storageDir, 0770, true) && !is_dir($storageDir)) {
            throw new HttpException('创建私有资源目录失败', -1, 500);
        }
        $stored = bin2hex(random_bytes(16)) . '.' . $extension;
        $newPath = $storageDir . '/' . $stored;
        $newRelative = $relativeDir . '/' . $stored;
        if (!copy($oldPath, $newPath)) throw new HttpException('迁移资源文件失败', -1, 500);
        @chmod($newPath, 0640);
        $oldSize = filesize($oldPath);
        $copiedSize = filesize($newPath);
        $oldHash = hash_file('sha256', $oldPath);
        $copiedHash = hash_file('sha256', $newPath);
        if ($oldSize === false || $copiedSize === false || $oldHash === false || $copiedHash === false
            || $copiedSize !== $oldSize || !hash_equals($oldHash, $copiedHash)) {
            @unlink($newPath);
            throw new HttpException('迁移资源文件校验失败', -1, 500);
        }

        $usedNewFile = false;
        try {
            $result = Database::transaction(static function () use (
                $uploadId, $adminId, $appId, $expectedScene, $oldRelative, $newRelative, $stored,
                $copiedHash, $copiedSize, $allowInactive
            ): array {
                $locked = Database::one(
                    'SELECT * FROM uploads WHERE id = ? AND admin_id = ? AND app_id = ? AND scene = ? FOR UPDATE',
                    [$uploadId, $adminId, $appId, $expectedScene]
                );
                if ($locked === null || (!$allowInactive && (int) ($locked['status'] ?? 0) !== 1)) {
                    throw new HttpException('资源文件已失效，请重新上传', 0, 409);
                }
                if (str_starts_with((string) $locked['file_path'], 'private/')) {
                    return ['upload' => $locked, 'used_new_file' => false, 'remaining_public_refs' => 1];
                }
                if ((string) $locked['file_path'] !== $oldRelative) {
                    throw new HttpException('资源文件已被其他操作更新，请刷新后重试', 0, 409);
                }
                $logicalRows = Database::all(
                    "SELECT id FROM uploads WHERE admin_id = ? AND app_id = ? AND file_path = ?
                      AND scene IN ('resource_source', 'store_app_package') FOR UPDATE",
                    [$adminId, $appId, $oldRelative]
                );
                $ids = array_values(array_filter(array_map(
                    static fn (array $row): int => max(0, (int) ($row['id'] ?? 0)),
                    $logicalRows
                )));
                if ($ids === []) $ids = [$uploadId];
                $idList = implode(',', $ids);
                $publicReferences = (int) (Database::one(
                    "SELECT COUNT(*) AS total FROM uploads WHERE file_path = ? AND id NOT IN ({$idList})",
                    [$oldRelative]
                )['total'] ?? 0);
                if ($publicReferences > 0) {
                    throw new HttpException(
                        '同一文件仍被公开内容引用，无法安全转为受控下载；请重新上传独立文件',
                        0,
                        409
                    );
                }
                Database::execute(
                    "UPDATE uploads SET stored_name = ?, file_path = ?, file_url = '', original_file_url = '',
                         optimized_file_url = '', thumbnail_url = '', sha256 = ?, size_bytes = ?
                     WHERE id IN ({$idList})",
                    [$stored, $newRelative, $copiedHash, $copiedSize]
                );
                Database::execute("UPDATE resources SET download_url = '' WHERE source_upload_id IN ({$idList})");
                Database::execute("UPDATE store_apps SET apk_url = '' WHERE source_upload_id IN ({$idList})");
                if (self::legacyCatalogUrlReferences($oldRelative) > 0) {
                    throw new HttpException(
                        '旧公开地址仍被未绑定的资源或应用引用，必须先完成全量迁移或人工隔离',
                        0,
                        409
                    );
                }
                foreach ($ids as $logicalUploadId) {
                    Database::execute(
                        "INSERT INTO catalog_file_migrations
                         (admin_id, app_id, upload_id, old_file_path, new_file_path, file_sha256,
                          file_size_bytes, cleanup_status, cleanup_error, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'cleanup_pending', '', NOW(), NOW())
                         ON DUPLICATE KEY UPDATE old_file_path = VALUES(old_file_path),
                          new_file_path = VALUES(new_file_path), file_sha256 = VALUES(file_sha256),
                          file_size_bytes = VALUES(file_size_bytes),
                          cleanup_status = 'cleanup_pending', cleanup_error = '', updated_at = NOW()",
                        [
                            $adminId,
                            $appId,
                            $logicalUploadId,
                            $oldRelative,
                            $newRelative,
                            $copiedHash,
                            $copiedSize,
                        ]
                    );
                }
                $remaining = (int) (Database::one(
                    'SELECT COUNT(*) AS total FROM uploads WHERE file_path = ?',
                    [$oldRelative]
                )['total'] ?? 0);
                $current = Database::one('SELECT * FROM uploads WHERE id = ?', [$uploadId]);
                if ($current === null) throw new HttpException('资源文件迁移状态异常', -1, 500);
                return ['upload' => $current, 'used_new_file' => true, 'remaining_public_refs' => $remaining];
            });
            $usedNewFile = (bool) $result['used_new_file'];
            if (!$usedNewFile) {
                @unlink($newPath);
                self::reconcileCatalogPublicCleanup($uploadId, $adminId, $appId);
                if (self::privatePhysicalPath((string) $result['upload']['file_path']) === null) {
                    throw new HttpException('资源文件缺失，请重新上传后再审核', 0, 409);
                }
                return $result['upload'];
            }
            if ((int) $result['remaining_public_refs'] !== 0) {
                Database::execute(
                    "UPDATE catalog_file_migrations SET cleanup_status = 'cleanup_failed',
                     cleanup_error = '数据库仍存在公开引用', updated_at = NOW()
                     WHERE admin_id = ? AND app_id = ? AND old_file_path = ?",
                    [$adminId, $appId, $oldRelative]
                );
                throw new HttpException('资源文件仍有公开引用，迁移未完成', 0, 409);
            }
            // Only the reconciler may delete the old public copy. It first proves
            // that the private copy still matches the durable size/hash journal.
            self::reconcileCatalogPublicCleanup($uploadId, $adminId, $appId);
            return $result['upload'];
        } catch (\Throwable $exception) {
            if (!$usedNewFile) @unlink($newPath);
            throw $exception;
        }
    }

    /**
     * A database row is not proof that a legacy public copy disappeared. Every
     * catalog access retries and verifies the durable cleanup journal first.
     */
    public static function reconcileCatalogPublicCleanup(int $uploadId, int $adminId, int $appId): void
    {
        $entries = Database::all(
            "SELECT * FROM catalog_file_migrations
             WHERE upload_id = ? AND admin_id = ? AND app_id = ? AND cleanup_status <> 'cleaned'
             ORDER BY id",
            [$uploadId, $adminId, $appId]
        );
        foreach ($entries as $entry) {
            $oldRelative = ltrim(str_replace('\\', '/', (string) $entry['old_file_path']), '/');
            $upload = Database::one(
                'SELECT id, file_path, sha256, size_bytes FROM uploads WHERE id = ? AND admin_id = ? AND app_id = ?',
                [$uploadId, $adminId, $appId]
            );
            $newRelative = (string) ($entry['new_file_path'] ?? '');
            $newPath = self::privatePhysicalPath($newRelative);
            $expectedHash = strtolower(trim((string) ($entry['file_sha256'] ?? '')));
            $expectedSize = max(0, (int) ($entry['file_size_bytes'] ?? 0));
            $actualSize = $newPath === null ? false : filesize($newPath);
            $actualHash = $newPath === null ? false : hash_file('sha256', $newPath);
            if ($upload === null || (string) ($upload['file_path'] ?? '') !== $newRelative || $newPath === null
                || $expectedHash === '' || $expectedSize <= 0 || $actualSize === false || $actualHash === false
                || $actualSize !== $expectedSize || !hash_equals($expectedHash, strtolower($actualHash))) {
                Database::execute(
                    "UPDATE catalog_file_migrations SET cleanup_status = 'cleanup_failed',
                     cleanup_error = '私有副本缺失或哈希不一致', updated_at = NOW() WHERE id = ?",
                    [(int) $entry['id']]
                );
                throw new HttpException('私有副本缺失或校验失败，已保留旧公开文件并停止访问', 0, 409);
            }
            $activeReferences = (int) (Database::one(
                'SELECT COUNT(*) AS total FROM uploads WHERE file_path = ?',
                [$oldRelative]
            )['total'] ?? 0);
            if ($activeReferences > 0) {
                Database::execute(
                    "UPDATE catalog_file_migrations SET cleanup_status = 'cleanup_failed',
                     cleanup_error = '数据库仍存在公开引用', updated_at = NOW() WHERE id = ?",
                    [(int) $entry['id']]
                );
                throw new HttpException('旧公开文件仍被引用，资源暂不可审核或下载', 0, 409);
            }
            if (self::legacyCatalogUrlReferences($oldRelative) > 0) {
                Database::execute(
                    "UPDATE catalog_file_migrations SET cleanup_status = 'cleanup_failed',
                     cleanup_error = '旧公开地址仍被目录条目引用', updated_at = NOW() WHERE id = ?",
                    [(int) $entry['id']]
                );
                throw new HttpException('旧公开地址仍被目录条目引用，必须先迁移或隔离后才能继续', 0, 409);
            }
            $oldState = self::publicStoredPathState($oldRelative);
            if ($oldState['status'] === 'unsafe') {
                Database::execute(
                    "UPDATE catalog_file_migrations SET cleanup_status = 'cleanup_failed',
                     cleanup_error = '旧公开路径包含链接或越界对象', updated_at = NOW() WHERE id = ?",
                    [(int) $entry['id']]
                );
                throw new HttpException('旧公开路径不安全，必须人工隔离后才能继续', 0, 409);
            }
            $oldPath = $oldState['status'] === 'file' ? (string) $oldState['path'] : null;
            if ($oldPath !== null) {
                $oldStat = @lstat($oldPath);
                if (!is_array($oldStat) || (int) ($oldStat['nlink'] ?? 0) !== 1) {
                    Database::execute(
                        "UPDATE catalog_file_migrations SET cleanup_status = 'cleanup_failed',
                         cleanup_error = '旧公开文件存在硬链接或文件状态异常', updated_at = NOW() WHERE id = ?",
                        [(int) $entry['id']]
                    );
                    throw new HttpException('旧公开文件存在硬链接，必须人工隔离后才能继续', 0, 409);
                }
            }
            if ($oldPath !== null && (!@unlink($oldPath) || is_file($oldPath))) {
                Database::execute(
                    "UPDATE catalog_file_migrations SET cleanup_status = 'cleanup_failed',
                     cleanup_error = '公开副本删除失败', updated_at = NOW() WHERE id = ?",
                    [(int) $entry['id']]
                );
                throw new HttpException('旧公开文件仍可访问，资源暂不可审核或下载', 0, 409);
            }
            Database::execute(
                "UPDATE catalog_file_migrations SET cleanup_status = 'cleaned', cleanup_error = '',
                 cleaned_at = NOW(), updated_at = NOW() WHERE id = ?",
                [(int) $entry['id']]
            );
        }
    }

    private static function publicPhysicalPath(string $relative): ?string
    {
        $state = self::publicStoredPathState($relative);
        return $state['status'] === 'file' ? (string) $state['path'] : null;
    }

    private static function legacyCatalogUrlReferences(string $oldRelative): int
    {
        $oldRelative = ltrim(str_replace('\\', '/', $oldRelative), '/');
        if ($oldRelative === '') return 0;
        $suffix = '%/' . $oldRelative;
        return (int) (Database::one(
            "SELECT
               (SELECT COUNT(*) FROM resources
                 WHERE TRIM(download_url) <> '' AND (download_url = ? OR download_url LIKE ?))
               + (SELECT COUNT(*) FROM store_apps
                 WHERE TRIM(apk_url) <> '' AND (apk_url = ? OR apk_url LIKE ?)) AS total",
            [$oldRelative, $suffix, $oldRelative, $suffix]
        )['total'] ?? 0);
    }

    private static function unboundLegacyCatalogUrlReferences(string $oldRelative, array $boundUploadIds): int
    {
        $oldRelative = ltrim(str_replace('\\', '/', $oldRelative), '/');
        if ($oldRelative === '') return 0;
        $ids = array_values(array_filter(array_map(static fn (mixed $id): int => max(0, (int) $id), $boundUploadIds)));
        $idList = $ids === [] ? '0' : implode(',', $ids);
        $suffix = '%/' . $oldRelative;
        return (int) (Database::one(
            "SELECT
               (SELECT COUNT(*) FROM resources
                 WHERE TRIM(download_url) <> '' AND (download_url = ? OR download_url LIKE ?)
                   AND (source_upload_id IS NULL OR source_upload_id NOT IN ({$idList})))
               + (SELECT COUNT(*) FROM store_apps
                 WHERE TRIM(apk_url) <> '' AND (apk_url = ? OR apk_url LIKE ?)
                   AND (source_upload_id IS NULL OR source_upload_id NOT IN ({$idList}))) AS total",
            [$oldRelative, $suffix, $oldRelative, $suffix]
        )['total'] ?? 0);
    }

    private static function relativeStoredPath(string $absolute, bool $private): string
    {
        $root = str_replace('\\', '/', YIYUNYING_ROOT . ($private ? '/storage/' : '/public/'));
        $path = str_replace('\\', '/', $absolute);
        if (!str_starts_with($path, $root)) throw new HttpException('媒体优化结果不在指定上传目录内', -1, 500);
        return ltrim(substr($path, strlen($root)), '/');
    }

    private static function privateScene(string $scene): bool
    {
        return in_array(strtolower(trim($scene)), [
            'forum_section', 'resource_source', 'store_app_package',
        ], true);
    }

    private static function boolean(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'original'], true);
    }
}
