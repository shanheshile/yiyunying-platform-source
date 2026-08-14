<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class UploadStorageService
{
    private const MAX_DEDUPE_CANDIDATES = 4096;
    private const DEDUPE_VALIDATION_SECONDS = 8.0;

    public static function store(
        array $file,
        int $adminId,
        int $appId,
        ?int $userId,
        string $scene,
        array $allowedExtensions,
        array $options = []
    ): array {
        $reportedSize = max(0, (int) ($file['size'] ?? 0));
        $original = basename((string) ($file['name'] ?? 'file'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            throw new HttpException('不允许上传该文件类型', 0, 422, ['extension' => $extension]);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) throw new HttpException('上传临时文件无效', 0, 422);
        if ($extension === 'svg') {
            throw new HttpException('不支持 SVG 文件；请转换为 PNG、JPG、GIF 或 WebP', 0, 422);
        }
        $actualTmpSize = @filesize($tmp);
        if ($actualTmpSize === false || $actualTmpSize <= 0 || (int) $actualTmpSize !== $reportedSize) {
            throw new HttpException('上传文件大小校验失败', 0, 422, [
                'reported_size_bytes' => $reportedSize,
                'actual_size_bytes' => $actualTmpSize === false ? 0 : (int) $actualTmpSize,
            ]);
        }
        $limit = UploadLimitService::validate($appId, $file);
        if (($limit['valid'] ?? false) !== true) {
            throw new HttpException(UploadLimitService::label((string) ($limit['category'] ?? 'file'))
                . '大小超出当前应用限制', 0, 422, $limit + ['unit' => '字节']);
        }
        $size = (int) $actualTmpSize;
        $sourceSha256 = @hash_file('sha256', $tmp);
        if (!is_string($sourceSha256) || preg_match('/^[a-f0-9]{64}$/D', strtolower($sourceSha256)) !== 1) {
            throw new HttpException('计算文件内容指纹失败', -1, 500);
        }
        $sourceSha256 = strtolower($sourceSha256);
        $inspection = MediaOptimizationService::inspectClientUpload($tmp, $extension);
        if (($inspection['accepted'] ?? false) !== true) {
            throw new HttpException('上传文件内容与扩展名不一致或无法安全解析', 0, 422, [
                'extension' => $extension,
                'reason' => (string) ($inspection['reason'] ?? 'untrusted_content'),
                'detected_kind' => (string) ($inspection['kind'] ?? 'unknown'),
            ]);
        }
        $postInspectionSize = @filesize($tmp);
        $postInspectionHash = @hash_file('sha256', $tmp);
        if ($postInspectionSize === false || (int) $postInspectionSize !== $size
            || !is_string($postInspectionHash)
            || !hash_equals($sourceSha256, strtolower($postInspectionHash))) {
            throw new HttpException('上传临时文件在内容校验期间发生变化', 0, 422);
        }
        $mime = (string) $inspection['mime_type'];
        $scene = mb_substr(trim($scene) !== '' ? trim($scene) : 'general', 0, 40);
        $originalUpload = self::boolean($options['original_upload'] ?? false);
        $privateUpload = self::privateScene($scene);
        if ($originalUpload && !(bool) AppService::setting($appId, 'media_original_upload_enabled', true)) {
            throw new HttpException('当前应用不允许上传原图或原视频，请关闭原媒体开关后重试', 0, 422);
        }

        $relativeDir = ($privateUpload ? 'private/uploads/' : 'uploads/') . $appId . '/' . date('Y/m');
        $storageRoot = YIYUNYING_ROOT . ($privateUpload ? '/storage/' : '/public/');
        $storageDir = $storageRoot . $relativeDir;
        if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            throw new HttpException('创建上传目录失败', -1, 500);
        }
        $storageDir = self::canonicalUploadDirectory($storageRoot, $relativeDir);
        $stored = bin2hex(random_bytes(16)) . '.' . $extension;
        $originalPath = $storageDir . '/' . $stored;
        $createdPaths = [];
        $committed = false;
        $primaryFailure = null;
        try {
            $preMoveSize = @filesize($tmp);
            $preMoveHash = @hash_file('sha256', $tmp);
            if ($preMoveSize === false || (int) $preMoveSize !== $size || !is_string($preMoveHash)
                || !hash_equals($sourceSha256, strtolower($preMoveHash))) {
                throw new HttpException('上传临时文件在保存前发生变化', 0, 422);
            }
            if (!move_uploaded_file($tmp, $originalPath)) throw new HttpException('保存上传文件失败', -1, 500);
            $createdPaths[] = $originalPath;
            $movedSize = @filesize($originalPath);
            $movedHash = @hash_file('sha256', $originalPath);
            $movedStat = @lstat($originalPath);
            if ($movedSize === false || (int) $movedSize !== $size || !is_string($movedHash)
                || !hash_equals($sourceSha256, strtolower($movedHash)) || !is_array($movedStat)
                || is_link($originalPath) || (int) ($movedStat['nlink'] ?? 0) !== 1) {
                throw new HttpException('上传文件保存后的完整性校验失败', -1, 500);
            }
        $originalRelative = str_replace('\\', '/', $relativeDir) . '/' . $stored;
        $baseUrl = rtrim((string) config('app.url'), '/');
        $originalUrl = $privateUpload ? '' : $baseUrl . '/' . $originalRelative;
        $optimize = !$originalUpload && (bool) AppService::setting($appId, 'media_optimize_by_default', true);
        if (str_contains(mb_strtolower($scene), '表情') || str_contains(strtolower($scene), 'sticker')) {
            $optimize = !$originalUpload && (bool) AppService::setting($appId, 'sticker_optimize_enabled', true);
        }
        $mainPath = $originalPath;
            $optimization = $optimize
                ? MediaOptimizationService::optimize(
                    $originalPath,
                    $mime,
                    $scene,
                    max(65536, (int) AppService::setting($appId, 'sticker_target_max_bytes', 524288)),
                    $inspection
                )
                : [
                    'path' => $originalPath, 'mime_type' => $mime, 'size_bytes' => $size,
                    'status' => 'original', 'is_animated' => (bool) ($inspection['is_animated'] ?? false),
                    'thumbnail_path' => '', 'width' => (int) ($inspection['width'] ?? 0),
                    'height' => (int) ($inspection['height'] ?? 0),
                    'duration_ms' => (int) ($inspection['duration_ms'] ?? 0),
                    'inspection' => $inspection,
                ];
            $mainPath = (string) ($optimization['path'] ?? '');
            if ($mainPath !== '' && $mainPath !== $originalPath) $createdPaths[] = $mainPath;
            $disposition = MediaOptimizationService::optimizationDisposition($originalPath, $optimization);
            if (($disposition['accepted'] ?? false) !== true) {
                $status = (string) ($optimization['status'] ?? 'invalid');
                $httpStatus = MediaOptimizationService::isFatalOptimizationStatus($status) ? 422 : 500;
                throw new HttpException('媒体文件无法安全解码或优化', 0, $httpStatus, [
                    'reason' => (string) ($disposition['reason'] ?? $status),
                ]);
            }
            $mainExtension = strtolower(pathinfo($mainPath, PATHINFO_EXTENSION));
            $mainInspection = is_array($optimization['inspection'] ?? null)
                ? $optimization['inspection']
                : [];
            if (($mainInspection['accepted'] ?? false) !== true) {
                throw new HttpException('媒体优化结果未通过内容校验', -1, 500);
            }
            $mainMime = (string) $mainInspection['mime_type'];
            $optimizerMime = strtolower(trim((string) ($optimization['mime_type'] ?? '')));
            if ($optimizerMime !== '' && $optimizerMime !== $mainMime) {
                throw new HttpException('媒体优化结果的 MIME 与内容不一致', -1, 500);
            }
            $mainSizeValue = @filesize($mainPath);
            if ($mainSizeValue === false || (int) ($optimization['size_bytes'] ?? -1) !== (int) $mainSizeValue) {
                throw new HttpException('媒体优化结果的大小校验失败', -1, 500);
            }
            $uploadMode = (string) $disposition['upload_mode'];
            $isOptimized = $uploadMode === 'optimized';
            if ($isOptimized
                && ((int) ($optimization['width'] ?? 0) !== (int) ($mainInspection['width'] ?? 0)
                    || (int) ($optimization['height'] ?? 0) !== (int) ($mainInspection['height'] ?? 0)
                    || (int) ($optimization['duration_ms'] ?? 0) !== (int) ($mainInspection['duration_ms'] ?? 0))) {
                throw new HttpException('媒体优化结果的尺寸校验失败', -1, 500);
            }
            $mainSize = (int) $mainSizeValue;
            $mainSha256 = @hash_file('sha256', $mainPath);
            if (!is_string($mainSha256) || preg_match('/^[a-f0-9]{64}$/D', strtolower($mainSha256)) !== 1) {
                throw new HttpException('媒体优化结果的指纹校验失败', -1, 500);
            }
            $mainSha256 = strtolower($mainSha256);
        $relative = self::relativeStoredPath($mainPath, $privateUpload);
        $mainState = self::storedPathState($relative);
        $mainStat = @lstat($mainPath);
        if (($mainState['status'] ?? '') !== 'file'
            || !is_array($mainStat) || (int) ($mainStat['nlink'] ?? 0) !== 1
            || realpath($mainPath) === false
            || str_replace('\\', '/', (string) $mainState['path']) !== str_replace('\\', '/', (string) realpath($mainPath))) {
            throw new HttpException('媒体优化结果路径包含链接、越界或异常对象', -1, 500);
        }
        $url = $privateUpload ? '' : $baseUrl . '/' . $relative;
        $thumbnailPath = (string) ($optimization['thumbnail_path'] ?? '');
        $thumbnailUrl = $isOptimized && !$privateUpload && $thumbnailPath !== ''
            ? $baseUrl . '/' . self::relativeStoredPath($thumbnailPath, false)
            : '';
        $optimizedUrl = $isOptimized ? $url : '';
        $keptOriginalUrl = $isOptimized ? '' : $originalUrl;
        if ($isOptimized) self::cleanupCreatedFiles([$originalPath]);
        $candidate = [
            'admin_id' => $adminId, 'app_id' => $appId, 'user_id' => $userId, 'scene' => $scene,
            'original_name' => mb_substr($original, 0, 255), 'stored_name' => basename($mainPath),
            'file_path' => $relative, 'file_url' => $url, 'mime_type' => mb_substr($mainMime, 0, 150),
            'size_bytes' => $mainSize, 'original_size_bytes' => $size,
            'optimized_size_bytes' => $isOptimized ? $mainSize : 0, 'upload_mode' => $uploadMode,
            'optimization_status' => mb_substr((string) $optimization['status'], 0, 40),
            'original_file_url' => $keptOriginalUrl, 'optimized_file_url' => $optimizedUrl,
            'thumbnail_url' => $thumbnailUrl,
            'is_animated' => (bool) ($mainInspection['is_animated'] ?? false) ? 1 : 0,
            'sha256' => $mainSha256,
            '_inspection' => $mainInspection,
        ];
        $result = self::persistProcessedUpload($candidate, $createdPaths);
        $result['width'] = (int) ($mainInspection['width'] ?? 0);
        $result['height'] = (int) ($mainInspection['height'] ?? 0);
        $result['duration_ms'] = (int) ($mainInspection['duration_ms'] ?? 0);
        $committed = true;
        return $result;
        } catch (\Throwable $failure) {
            $primaryFailure = $failure;
            throw $failure;
        } finally {
            if (!$committed) {
                try {
                    self::cleanupCreatedFiles($createdPaths);
                } catch (\Throwable $cleanupFailure) {
                    // A cleanup summary must never replace the actionable upload
                    // failure that caused the rollback. Keep the aggregate count
                    // in the server log while preserving the original exception.
                    if ($primaryFailure !== null) {
                        error_log('upload_cleanup_after_failure: ' . $cleanupFailure->getMessage());
                    } else {
                        throw $cleanupFailure;
                    }
                }
            }
        }
    }

    /** @param array<string,mixed> $candidate @param list<string> $createdPaths */
    private static function persistProcessedUpload(array $candidate, array $createdPaths): array
    {
        if (Database::connection()->inTransaction()) {
            throw new HttpException('上传保存不能嵌套在外层数据库事务中', -1, 500);
        }
        $private = str_starts_with((string) $candidate['file_path'], 'private/');
        $storageClause = $private
            ? " AND file_path LIKE 'private/%'"
            : " AND file_path NOT LIKE 'private/%'";
        $lockName = self::dedupeLockName($candidate, $private);
        $lock = Database::one('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
        if ((int) ($lock['acquired'] ?? 0) !== 1) {
            throw new HttpException('上传去重锁繁忙，请稍后重试', 0, 503);
        }
        try {
            $rows = Database::all(
                'SELECT * FROM uploads WHERE admin_id = ? AND app_id = ? AND sha256 = ? AND size_bytes = ?
                 AND upload_mode = ? AND mime_type = ? AND status = 1' . $storageClause
                 . ' ORDER BY id LIMIT ' . (self::MAX_DEDUPE_CANDIDATES + 1),
                [
                    (int) $candidate['admin_id'], (int) $candidate['app_id'], (string) $candidate['sha256'],
                    (int) $candidate['size_bytes'], (string) $candidate['upload_mode'],
                    (string) $candidate['mime_type'],
                ]
            );
            if (count($rows) > self::MAX_DEDUPE_CANDIDATES) {
                throw new HttpException('可复用上传候选过多，请先维护历史记录后重试', 0, 503);
            }
            $selection = self::selectReusableUploadCandidates(
                $rows,
                $candidate,
                microtime(true) + self::DEDUPE_VALIDATION_SECONDS
            );
            $existing = $selection['existing'];
            $existingValidation = $selection['existing_validation'];
            $sameOwner = $selection['same_owner'];
            $sameOwnerValidation = $selection['same_owner_validation'];
            if ($existing !== null) {
                self::cleanupCreatedFiles($createdPaths);
            }
            $candidateInspection = is_array($candidate['_inspection'] ?? null) ? $candidate['_inspection'] : null;
            unset($candidate['_inspection']);
            return Database::transaction(static function () use (
                $candidate,
                $candidateInspection,
                $existing,
                $existingValidation,
                $sameOwner,
                $sameOwnerValidation
            ): array {
                if ($existing === null) {
                    $id = Database::insert(
                        'INSERT INTO uploads
                         (admin_id, app_id, user_id, scene, original_name, stored_name, file_path, file_url,
                          mime_type, size_bytes, original_size_bytes, optimized_size_bytes, upload_mode,
                          optimization_status, original_file_url, optimized_file_url, thumbnail_url, is_animated,
                          sha256, status, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())',
                        [
                            (int) $candidate['admin_id'], (int) $candidate['app_id'], $candidate['user_id'],
                            (string) $candidate['scene'], (string) $candidate['original_name'],
                            (string) $candidate['stored_name'], (string) $candidate['file_path'],
                            (string) $candidate['file_url'], (string) $candidate['mime_type'],
                            (int) $candidate['size_bytes'], (int) $candidate['original_size_bytes'],
                            (int) $candidate['optimized_size_bytes'], (string) $candidate['upload_mode'],
                            (string) $candidate['optimization_status'], (string) $candidate['original_file_url'],
                            (string) $candidate['optimized_file_url'], (string) $candidate['thumbnail_url'],
                            (int) $candidate['is_animated'], (string) $candidate['sha256'],
                        ]
                    );
                    $candidate['id'] = $id;
                    $candidate['status'] = 1;
                    return self::result($candidate, false, false, $candidateInspection);
                }

                $selected = $sameOwner ?? $existing;
                $selectedValidation = $sameOwnerValidation ?? $existingValidation;
                $locked = Database::one('SELECT * FROM uploads WHERE id = ? AND status = 1 FOR UPDATE', [
                    (int) $selected['id'],
                ]);
                if ($locked === null || !is_array($selectedValidation)
                    || !hash_equals(
                        (string) $selectedValidation['row_fingerprint'],
                        self::uploadRowFingerprint($locked)
                    )
                    || !hash_equals(
                        (string) $selectedValidation['physical_fingerprint'],
                        (string) (self::storedPhysicalFingerprint(
                            (string) ($locked['file_path'] ?? ''),
                            (int) ($locked['size_bytes'] ?? -1)
                        ) ?? '')
                    )) {
                    throw new HttpException('可复用上传记录在写入前发生变化，请重试', 0, 409);
                }
                if ($sameOwner !== null) {
                    return self::result($locked, true, false, $selectedValidation['inspection']);
                }
                $id = Database::insert(
                    'INSERT INTO uploads
                     (admin_id, app_id, user_id, scene, original_name, stored_name, file_path, file_url,
                      mime_type, size_bytes, original_size_bytes, optimized_size_bytes, upload_mode,
                      optimization_status, original_file_url, optimized_file_url, thumbnail_url, is_animated,
                      sha256, status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())',
                    [
                        (int) $candidate['admin_id'], (int) $candidate['app_id'], $candidate['user_id'],
                        (string) $candidate['scene'], (string) $candidate['original_name'],
                        (string) $locked['stored_name'], (string) $locked['file_path'],
                        (string) $locked['file_url'], (string) $locked['mime_type'],
                        (int) $locked['size_bytes'], (int) $candidate['original_size_bytes'],
                        (int) $locked['optimized_size_bytes'], (string) $locked['upload_mode'],
                        (string) $locked['optimization_status'], (string) $locked['original_file_url'],
                        (string) $locked['optimized_file_url'], (string) $locked['thumbnail_url'],
                        (int) $locked['is_animated'], (string) $locked['sha256'],
                    ]
                );
                $logical = $locked;
                $logical['id'] = $id;
                $logical['user_id'] = $candidate['user_id'];
                $logical['scene'] = $candidate['scene'];
                $logical['original_name'] = $candidate['original_name'];
                $logical['original_size_bytes'] = $candidate['original_size_bytes'];
                $logical['status'] = 1;
                return self::result($logical, true, true, $selectedValidation['inspection']);
            });
        } finally {
            try { Database::one('SELECT RELEASE_LOCK(?) AS released', [$lockName]); } catch (\Throwable) {}
        }
    }

    /**
     * Fast row/URL filtering is completed before any hash or decoder work. A
     * physical file identity is then inspected once even when many logical rows
     * share it. The hard deadline fails closed instead of silently missing a
     * later valid candidate.
     *
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $candidate
     * @return array{existing:?array,existing_validation:?array,same_owner:?array,same_owner_validation:?array,physical_validation_count:int}
     */
    private static function selectReusableUploadCandidates(
        array $rows,
        array $candidate,
        float $deadline
    ): array {
        if (count($rows) > self::MAX_DEDUPE_CANDIDATES) {
            throw new HttpException('可复用上传候选超过安全预算', 0, 503);
        }
        $fastCandidates = [];
        foreach ($rows as $row) {
            if (microtime(true) > $deadline) {
                throw new HttpException('可复用上传快速校验超时，请重试', 0, 503);
            }
            $fast = self::reusableUploadFastState($row);
            if ($fast !== null) $fastCandidates[] = ['row' => $row, 'fast' => $fast];
        }

        $cache = [];
        $physicalValidationCount = 0;
        $existing = null;
        $existingValidation = null;
        $sameOwner = null;
        $sameOwnerValidation = null;
        foreach ($fastCandidates as $entry) {
            if (microtime(true) > $deadline) {
                throw new HttpException('可复用上传内容校验超时，请重试', 0, 503);
            }
            $row = $entry['row'];
            $fast = $entry['fast'];
            $physical = self::reusableUploadPhysicalState($row, $fast);
            if ($physical === null) continue;
            $cacheKey = hash('sha256', implode('|', [
                (string) $physical['physical_fingerprint'],
                strtolower(trim((string) ($row['sha256'] ?? ''))),
                strtolower(trim((string) ($row['mime_type'] ?? ''))),
                (string) ((int) ($row['is_animated'] ?? 0)),
            ]));
            if (!array_key_exists($cacheKey, $cache)) {
                $cache[$cacheKey] = self::validateReusablePhysicalUpload($row, $fast, $physical);
                $physicalValidationCount++;
                if (microtime(true) > $deadline) {
                    throw new HttpException('可复用上传内容校验超时，请重试', 0, 503);
                }
            }
            $physicalValidation = $cache[$cacheKey];
            if (!is_array($physicalValidation)) continue;
            $validation = $physicalValidation + [
                'row_fingerprint' => self::uploadRowFingerprint($row),
            ];
            if ($existing === null) {
                $existing = $row;
                $existingValidation = $validation;
            }
            $sameUser = (($row['user_id'] ?? null) === null && $candidate['user_id'] === null)
                || (($row['user_id'] ?? null) !== null && $candidate['user_id'] !== null
                    && (int) $row['user_id'] === (int) $candidate['user_id']);
            if ($sameUser && (string) $row['scene'] === (string) $candidate['scene']
                && (string) $row['original_name'] === (string) $candidate['original_name']) {
                $sameOwner = $row;
                $sameOwnerValidation = $validation;
                break;
            }
        }
        return [
            'existing' => $existing,
            'existing_validation' => $existingValidation,
            'same_owner' => $sameOwner,
            'same_owner_validation' => $sameOwnerValidation,
            'physical_validation_count' => $physicalValidationCount,
        ];
    }

    /** @param array<string,mixed> $candidate */
    private static function dedupeLockName(array $candidate, bool $private): string
    {
        $identity = implode('|', [
            (string) ($candidate['admin_id'] ?? ''), (string) ($candidate['app_id'] ?? ''),
            (string) ($candidate['sha256'] ?? ''), (string) ($candidate['size_bytes'] ?? ''),
            (string) ($candidate['upload_mode'] ?? ''), (string) ($candidate['mime_type'] ?? ''),
            $private ? 'private' : 'public',
        ]);
        return 'yiyun_upload_' . substr(hash('sha256', $identity), 0, 48);
    }

    private static function result(array $upload, bool $reused, bool $shared, ?array $inspection = null): array
    {
        $metadata = is_array($inspection) && ($inspection['accepted'] ?? false) === true
            ? self::mediaMetadata($inspection)
            : self::trustedMediaMetadata($upload);
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
            'width' => (int) $metadata['width'], 'height' => (int) $metadata['height'],
            'duration_ms' => (int) $metadata['duration_ms'],
            'sha256' => (string) $upload['sha256'], 'reused' => $reused,
            'shared_physical_file' => $shared,
        ];
    }

    /** @param array<string,mixed> $upload @return array{width:int,height:int,duration_ms:int,is_animated:bool} */
    public static function trustedMediaMetadata(array $upload): array
    {
        $validated = self::prevalidatedReusableUpload($upload);
        if ($validated === null) {
            throw new HttpException('上传文件完整性或媒体元数据校验失败，请重新上传', 0, 409);
        }
        return self::mediaMetadata($validated['inspection']);
    }

    /**
     * Fully validates a live public upload while treating every stored URL as a
     * historical presentation snapshot. The physical file, hash, MIME, decoder
     * result and row invariants remain mandatory; only URL columns are replaced
     * with their current canonical equivalents before structural validation.
     *
     * @param array<string,mixed> $upload
     * @return array<string,mixed>
     */
    public static function validatedPublicUpload(array $upload): array
    {
        $relative = ltrim(str_replace('\\', '/', (string) ($upload['file_path'] ?? '')), '/');
        $mime = strtolower(trim((string) ($upload['mime_type'] ?? '')));
        $baseUrl = rtrim((string) config('app.url'), '/');
        if ($baseUrl === '' || !str_starts_with($relative, 'uploads/')
            || str_contains('/' . $relative . '/', '/../') || $mime === '') {
            throw new HttpException('公开上传已失效或未通过内容完整性校验，请重新上传', 0, 409);
        }
        // URL columns are historical presentation snapshots and may contain a
        // retired host. They are normalized only for the existing structural
        // verifier; the original row fingerprint is retained for the locked
        // TOCTOU comparison below.
        $canonicalUrl = $baseUrl . '/' . $relative;
        $normalized = $upload;
        $normalized['file_url'] = $canonicalUrl;
        $mode = strtolower(trim((string) ($upload['upload_mode'] ?? '')));
        if ($mode === 'optimized') {
            $normalized['original_file_url'] = '';
            $normalized['optimized_file_url'] = $canonicalUrl;
            $normalized['thumbnail_url'] = str_starts_with($mime, 'image/') ? $canonicalUrl : '';
        } else {
            $normalized['original_file_url'] = $canonicalUrl;
            $normalized['optimized_file_url'] = '';
            $normalized['thumbnail_url'] = '';
        }
        $validated = self::prevalidatedReusableUpload($normalized);
        if ($validated === null) {
            throw new HttpException('公开上传已失效或未通过内容完整性校验，请重新上传', 0, 409);
        }
        $metadata = self::mediaMetadata($validated['inspection']);
        $url = $canonicalUrl;
        return [
            'upload_id' => (int) ($upload['id'] ?? 0),
            'file_url' => $url,
            'original_file_url' => $mode === 'original' ? $url : '',
            'optimized_file_url' => $mode === 'optimized' ? $url : '',
            'thumbnail_url' => str_starts_with($mime, 'image/') ? $url : '',
            'mime_type' => $mime,
            'size_bytes' => (int) ($upload['size_bytes'] ?? 0),
            'sha256' => strtolower((string) ($upload['sha256'] ?? '')),
            'width' => (int) $metadata['width'],
            'height' => (int) $metadata['height'],
            'duration_ms' => (int) $metadata['duration_ms'],
            'is_animated' => (bool) $metadata['is_animated'],
            'row_fingerprint' => self::uploadRowFingerprint($upload),
            'physical_fingerprint' => (string) $validated['physical_fingerprint'],
        ];
    }

    /** @param array<string,mixed> $upload @return array<string,mixed> */
    public static function validatedPublicImageUpload(array $upload): array
    {
        $validated = self::validatedPublicUpload($upload);
        $mime = (string) ($validated['mime_type'] ?? '');
        if (!str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            throw new HttpException('公开图片上传已失效或未通过内容完整性校验，请重新上传', 0, 409);
        }
        return $validated;
    }

    /**
     * Short transaction-side TOCTOU recheck for a public image that was fully
     * inspected before the row lock was acquired.
     *
     * @param array<string,mixed> $lockedUpload
     * @param array<string,mixed> $prevalidated
     */
    public static function assertLockedPublicUpload(array $lockedUpload, array $prevalidated): void
    {
        $uploadId = (int) ($lockedUpload['id'] ?? 0);
        if ($uploadId <= 0 || $uploadId !== (int) ($prevalidated['upload_id'] ?? 0)
            || !hash_equals(
                (string) ($prevalidated['row_fingerprint'] ?? ''),
                self::uploadRowFingerprint($lockedUpload)
            )
            || !hash_equals(
                (string) ($prevalidated['physical_fingerprint'] ?? ''),
                (string) (self::storedPhysicalFingerprint(
                    (string) ($lockedUpload['file_path'] ?? ''),
                    (int) ($lockedUpload['size_bytes'] ?? -1)
                ) ?? '')
            )) {
            throw new HttpException('公开上传在保存前发生变化，请重新上传', 0, 409);
        }
    }

    /** @param array<string,mixed> $lockedUpload @param array<string,mixed> $prevalidated */
    public static function assertLockedPublicImageUpload(array $lockedUpload, array $prevalidated): void
    {
        $mime = strtolower(trim((string) ($lockedUpload['mime_type'] ?? '')));
        if (!str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            throw new HttpException('公开图片上传类型已变化，请重新上传', 0, 409);
        }
        self::assertLockedPublicUpload($lockedUpload, $prevalidated);
    }

    /** @param array<string,mixed> $inspection @return array{width:int,height:int,duration_ms:int,is_animated:bool} */
    private static function mediaMetadata(array $inspection): array
    {
        return [
            'width' => (int) ($inspection['width'] ?? 0),
            'height' => (int) ($inspection['height'] ?? 0),
            'duration_ms' => (int) ($inspection['duration_ms'] ?? 0),
            'is_animated' => (bool) ($inspection['is_animated'] ?? false),
        ];
    }

    /** @param list<string> $paths */
    private static function cleanupCreatedFiles(array $paths): void
    {
        $publicRootPath = YIYUNYING_ROOT . '/public';
        $privateRootPath = YIYUNYING_ROOT . '/storage';
        $publicRootReal = realpath($publicRootPath);
        $privateRootReal = realpath($privateRootPath);
        $publicRoot = $publicRootReal === false ? '' : rtrim(str_replace('\\', '/', $publicRootReal), '/') . '/';
        $privateRoot = $privateRootReal === false ? '' : rtrim(str_replace('\\', '/', $privateRootReal), '/') . '/';
        $failures = 0;
        foreach (array_reverse(array_values(array_unique($paths))) as $path) {
            if (!is_string($path) || $path === '') continue;
            if (!file_exists($path) && !is_link($path)) continue;
            if (is_link($path)) {
                $failures++;
                continue;
            }
            $resolved = realpath($path);
            if ($resolved === false) {
                $failures++;
                continue;
            }
            $normalized = str_replace('\\', '/', $resolved);
            $inPublic = $publicRoot !== '' && str_starts_with($normalized, $publicRoot);
            $inPrivate = $privateRoot !== '' && str_starts_with($normalized, $privateRoot);
            if (!$inPublic && !$inPrivate) {
                $failures++;
                continue;
            }
            $private = $inPrivate;
            $root = $private ? $privateRoot : $publicRoot;
            $relative = ltrim(substr($normalized, strlen($root)), '/');
            $state = self::boundedStoredPathState(
                $private ? $privateRootPath : $publicRootPath,
                $relative,
                $private
            );
            $safePath = ($state['status'] ?? '') === 'file' ? (string) ($state['path'] ?? '') : '';
            $stat = $safePath !== '' ? @lstat($safePath) : false;
            if ($safePath === '' || !is_array($stat) || (int) ($stat['nlink'] ?? 0) !== 1
                || is_dir($safePath) || !@unlink($safePath)) {
                $failures++;
            }
        }
        if ($failures > 0) {
            throw new HttpException('上传失败后的临时文件清理不完整', -1, 500, [
                'failed_items' => $failures,
            ]);
        }
    }

    /** @param array<string,mixed> $upload */
    private static function verifiedReusableUpload(array $upload): bool
    {
        return self::prevalidatedReusableUpload($upload) !== null;
    }

    /**
     * Performs every filesystem/hash/decoder check before a row lock is held.
     * The returned row fingerprint is compared again inside the short write transaction.
     *
     * @param array<string,mixed> $upload
     * @return array{inspection:array<string,mixed>,row_fingerprint:string,physical_fingerprint:string}|null
     */
    private static function prevalidatedReusableUpload(array $upload): ?array
    {
        $fast = self::reusableUploadFastState($upload);
        if ($fast === null) return null;
        $physical = self::reusableUploadPhysicalState($upload, $fast);
        if ($physical === null) return null;
        $validated = self::validateReusablePhysicalUpload($upload, $fast, $physical);
        if ($validated === null) return null;
        return $validated + ['row_fingerprint' => self::uploadRowFingerprint($upload)];
    }

    /** @param array<string,mixed> $upload @return array<string,mixed>|null */
    private static function reusableUploadFastState(array $upload): ?array
    {
        $mode = strtolower(trim((string) ($upload['upload_mode'] ?? '')));
        $status = strtolower(trim((string) ($upload['optimization_status'] ?? '')));
        $mime = strtolower(trim((string) ($upload['mime_type'] ?? '')));
        $fileUrl = trim((string) ($upload['file_url'] ?? ''));
        $originalUrl = trim((string) ($upload['original_file_url'] ?? ''));
        $optimizedUrl = trim((string) ($upload['optimized_file_url'] ?? ''));
        $thumbnailUrl = trim((string) ($upload['thumbnail_url'] ?? ''));
        if ((int) ($upload['status'] ?? 0) !== 1 || $mime === ''
            || MediaOptimizationService::isFatalOptimizationStatus($status)) return null;
        $metadataDisposition = MediaOptimizationService::optimizationDisposition('__stored_original__', [
            'path' => $mode === 'optimized' ? '__stored_optimized__' : '__stored_original__',
            'status' => $status,
        ]);
        if (($metadataDisposition['accepted'] ?? false) !== true
            || ($metadataDisposition['upload_mode'] ?? '') !== $mode) {
            return null;
        }
        $relative = ltrim(str_replace('\\', '/', (string) ($upload['file_path'] ?? '')), '/');
        if ($relative === '' || str_contains('/' . $relative . '/', '/../')
            || (string) ($upload['stored_name'] ?? '') !== basename($relative)) return null;
        $private = str_starts_with($relative, 'private/');
        $size = (int) ($upload['size_bytes'] ?? -1);
        $expectedHash = strtolower(trim((string) ($upload['sha256'] ?? '')));
        if ($size <= 0 || preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1) return null;
        $originalSize = (int) ($upload['original_size_bytes'] ?? 0);
        $optimizedSize = (int) ($upload['optimized_size_bytes'] ?? 0);
        if ($mode === 'optimized') {
            if ($status !== 'optimized' || $originalSize <= (int) $size || $optimizedSize !== (int) $size
                || (bool) ($upload['is_animated'] ?? false) === true) return null;
        } elseif ($mode !== 'original' || $status === 'optimized' || $originalSize !== (int) $size
            || $optimizedSize !== 0) {
            return null;
        }
        $urlsValid = false;
        if ($private) {
            $urlsValid = $fileUrl === '' && $originalUrl === '' && $optimizedUrl === '' && $thumbnailUrl === '';
        } else {
            $expectedUrl = rtrim((string) config('app.url'), '/') . '/' . $relative;
            if ($fileUrl !== '' && $fileUrl === $expectedUrl) {
                if ($mode === 'original') {
                    $urlsValid = $originalUrl === $expectedUrl && $optimizedUrl === '' && $thumbnailUrl === '';
                } else {
                    $expectedThumbnail = str_starts_with($mime, 'image/') ? $expectedUrl : '';
                    $urlsValid = $originalUrl === '' && $optimizedUrl === $expectedUrl
                        && $thumbnailUrl === $expectedThumbnail;
                }
            }
        }
        if (!$urlsValid) return null;
        return [
            'mode' => $mode,
            'status' => $status,
            'mime' => $mime,
            'relative' => $relative,
            'private' => $private,
            'size' => $size,
            'expected_hash' => $expectedHash,
        ];
    }

    /**
     * Resolve and stat only; hash and decoder work is intentionally separate so
     * callers can cache it by the unique path/inode identity.
     *
     * @param array<string,mixed> $upload
     * @param array<string,mixed> $fast
     * @return array{path:string,physical_fingerprint:string}|null
     */
    private static function reusableUploadPhysicalState(array $upload, array $fast): ?array
    {
        $relative = (string) $fast['relative'];
        $state = self::storedPathState($relative);
        $path = ($state['status'] ?? '') === 'file' ? (string) ($state['path'] ?? '') : '';
        $expectedPhysical = YIYUNYING_ROOT . ((bool) $fast['private'] ? '/storage/' : '/public/') . $relative;
        $expectedReal = realpath($expectedPhysical);
        if ($path === '' || is_link($path) || $expectedReal === false
            || str_replace('\\', '/', $path) !== str_replace('\\', '/', $expectedReal)) return null;
        $fingerprint = self::storedPhysicalFingerprint($relative, (int) $fast['size']);
        return $fingerprint === null ? null : ['path' => $path, 'physical_fingerprint' => $fingerprint];
    }

    /**
     * @param array<string,mixed> $upload
     * @param array<string,mixed> $fast
     * @param array{path:string,physical_fingerprint:string} $physical
     * @return array{inspection:array<string,mixed>,physical_fingerprint:string}|null
     */
    private static function validateReusablePhysicalUpload(array $upload, array $fast, array $physical): ?array
    {
        $path = $physical['path'];
        $sha256 = @hash_file('sha256', $path);
        if (!is_string($sha256) || !hash_equals((string) $fast['expected_hash'], strtolower($sha256))) return null;
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $inspection = MediaOptimizationService::inspectClientUpload($path, $extension);
        if (($inspection['accepted'] ?? false) !== true
            || (string) ($inspection['mime_type'] ?? '') !== (string) $fast['mime']
            || (bool) ($inspection['is_animated'] ?? false) !== (bool) ($upload['is_animated'] ?? false)) {
            return null;
        }
        return [
            'inspection' => $inspection,
            'physical_fingerprint' => $physical['physical_fingerprint'],
        ];
    }

    private static function storedPhysicalFingerprint(string $relative, int $expectedSize): ?string
    {
        if ($expectedSize <= 0) return null;
        $state = self::storedPathState($relative);
        $path = ($state['status'] ?? '') === 'file' ? (string) ($state['path'] ?? '') : '';
        if ($path === '' || is_link($path)) return null;
        $stat = @lstat($path);
        if (!is_array($stat) || (int) ($stat['nlink'] ?? 0) !== 1
            || (int) ($stat['size'] ?? -1) !== $expectedSize) return null;
        $identity = [
            'path' => str_replace('\\', '/', $path),
            'dev' => (int) ($stat['dev'] ?? -1),
            'ino' => (int) ($stat['ino'] ?? -1),
            'nlink' => (int) ($stat['nlink'] ?? 0),
            'size' => (int) ($stat['size'] ?? -1),
            'mtime' => (int) ($stat['mtime'] ?? -1),
            'ctime' => (int) ($stat['ctime'] ?? -1),
        ];
        return hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $upload */
    private static function uploadRowFingerprint(array $upload): string
    {
        $fields = [];
        foreach ([
            'id', 'admin_id', 'app_id', 'user_id', 'scene', 'original_name', 'stored_name', 'file_path',
            'file_url', 'mime_type', 'size_bytes', 'original_size_bytes', 'optimized_size_bytes',
            'upload_mode', 'optimization_status', 'original_file_url', 'optimized_file_url',
            'thumbnail_url', 'is_animated', 'sha256', 'status',
        ] as $field) {
            $fields[$field] = $upload[$field] ?? null;
        }
        return hash('sha256', json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
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
        $rootPath = YIYUNYING_ROOT . ($private ? '/storage' : '/public');
        $rootReal = realpath($rootPath);
        $pathReal = realpath($absolute);
        if ($rootReal === false || $pathReal === false || is_link($absolute)) {
            throw new HttpException('媒体优化结果不在指定上传目录内', -1, 500);
        }
        $root = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
        $path = str_replace('\\', '/', $pathReal);
        if (!str_starts_with($path, $root)) throw new HttpException('媒体优化结果不在指定上传目录内', -1, 500);
        return ltrim(substr($path, strlen($root)), '/');
    }

    private static function canonicalUploadDirectory(string $storageRoot, string $relativeDirectory): string
    {
        if (is_link($storageRoot)) throw new HttpException('上传根目录不能是链接', -1, 500);
        $root = realpath($storageRoot);
        $relativeDirectory = trim(str_replace('\\', '/', $relativeDirectory), '/');
        if ($root === false || $relativeDirectory === '' || str_contains('/' . $relativeDirectory . '/', '/../')) {
            throw new HttpException('上传目录不在指定存储根目录内', -1, 500);
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $current = $root;
        foreach (explode('/', $relativeDirectory) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new HttpException('上传目录包含无效路径片段', -1, 500);
            }
            $current .= '/' . $part;
            if (is_link($current) || !is_dir($current)) {
                throw new HttpException('上传目录包含链接或异常对象', -1, 500);
            }
        }
        $resolved = realpath($current);
        $resolved = $resolved === false ? '' : str_replace('\\', '/', $resolved);
        if ($resolved === '' || !str_starts_with($resolved . '/', $root . '/')) {
            throw new HttpException('上传目录越出指定存储根目录', -1, 500);
        }
        return $resolved;
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
