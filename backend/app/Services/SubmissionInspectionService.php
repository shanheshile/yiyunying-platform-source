<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Validator;

final class SubmissionInspectionService
{
    public const MAX_CATALOG_PRICE_BALANCE = 1000000000;

    public static function catalogPrice(mixed $value): int
    {
        return Validator::integer($value, 'price_balance', 0, self::MAX_CATALOG_PRICE_BALANCE);
    }

    private const APP_CATEGORIES = [
        '暂无分类', '生活', '社交', '游戏', '工具', '影音',
        '学习', '办公', '购物', '出行', '健康', '金融',
    ];

    /**
     * Canonical source-market taxonomy. Application code must consume this list instead of
     * carrying controller-local copies; database migrations mirror it and are contract-tested.
     */
    private const SOURCE_CATEGORY_SEEDS = [
        ['name' => 'Android Java 源码', 'description' => 'Android 原生 Java 源码、示例与完整工程'],
        ['name' => 'iApp 源码', 'description' => 'iApp 源码、界面示例与可复用模块'],
        ['name' => 'Lua 源码', 'description' => 'Lua 脚本、源码模块与完整示例'],
        ['name' => 'Web 源码', 'description' => '网页、前端界面与 Web 完整工程源码'],
        ['name' => 'PHP 源码', 'description' => 'PHP 服务端源码、接口与完整工程'],
        ['name' => 'Python 源码', 'description' => 'Python 脚本、服务与完整工程源码'],
        ['name' => 'JavaScript 源码', 'description' => 'JavaScript、Node.js 与前端框架源码'],
        ['name' => 'HarmonyOS 源码', 'description' => 'HarmonyOS、ArkTS 与鸿蒙应用源码'],
        ['name' => 'iOS 源码', 'description' => 'iOS、Swift 与苹果应用完整源码'],
        ['name' => 'C/C++ 源码', 'description' => 'C、C++ 源码、库与完整工程'],
        ['name' => '数据库源码', 'description' => '数据库脚本、结构、查询与迁移源码'],
        ['name' => '通用模块', 'description' => '好友聊天、群聊、登录注册、论坛、文档和商城等独立模块'],
        ['name' => '其他源码', 'description' => '未归入标准技术分类的其他源码与示例'],
    ];

    private const APP_EXTENSIONS = ['apk', 'apks', 'xapk', 'hap', 'ipa'];

    private const SOURCE_EXTENSIONS = [
        'zip', '7z', 'rar', 'tar', 'gz', 'iapp', 'py', 'java', 'php',
        'html', 'htm', 'js', 'jsx', 'ts', 'tsx', 'css', 'sql', 'c', 'cc', 'cpp', 'h', 'hpp',
        'rs', 'kt', 'kts', 'lua', 'ets', 'swift', 'xml', 'json', 'yaml', 'yml',
    ];

    public static function normalizeResourceType(string $type): string
    {
        $value = strtolower(trim($type));
        if (in_array($value, ['source', 'source_store', 'source_market', 'code', '源码', '源码商城'], true)) {
            return 'source_market';
        }
        return 'app_store';
    }

    public static function catalogScene(string $kind): string
    {
        return self::normalizeResourceType($kind) === 'app_store'
            ? 'store_app_package'
            : 'resource_source';
    }

    public static function catalogCoverScene(string $kind): string
    {
        return self::normalizeResourceType($kind) === 'app_store'
            ? 'store_app_icon'
            : 'resource_cover';
    }

    /** @return array<int, array{name: string, description: string, sort_order: int}> */
    public static function sourceCategorySeeds(): array
    {
        $count = count(self::SOURCE_CATEGORY_SEEDS);
        $seeds = [];
        foreach (self::SOURCE_CATEGORY_SEEDS as $index => $seed) {
            $seeds[] = [
                'name' => $seed['name'],
                'description' => $seed['description'],
                'sort_order' => ($count - $index) * 10,
            ];
        }
        return $seeds;
    }

    public static function seedResourceCategories(int $adminId, int $appId, string $type): void
    {
        $type = self::normalizeResourceType($type);
        $categories = $type === 'source_market'
            ? self::sourceCategorySeeds()
            : array_map(
                static fn (string $name, int $index): array => [
                    'name' => $name,
                    'description' => '应用商店标准分类',
                    'sort_order' => count(self::APP_CATEGORIES) - $index,
                ],
                self::APP_CATEGORIES,
                array_keys(self::APP_CATEGORIES)
            );
        foreach ($categories as $category) {
            Database::execute(
                'INSERT INTO resource_categories
                 (admin_id, app_id, resource_type, name, icon, description, sort_order, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE description = VALUES(description), sort_order = VALUES(sort_order),
                   status = 1, updated_at = NOW()',
                [
                    $adminId,
                    $appId,
                    $type,
                    $category['name'],
                    '',
                    $category['description'],
                    $category['sort_order'],
                ]
            );
        }
    }

    public static function seedStoreCategories(int $adminId, int $appId): void
    {
        foreach (self::APP_CATEGORIES as $index => $name) {
            Database::execute(
                'INSERT IGNORE INTO store_categories
                 (admin_id, app_id, name, icon, sort_order, status, created_at)
                 VALUES (?, ?, ?, ?, ?, 1, NOW())',
                [$adminId, $appId, $name, '', count(self::APP_CATEGORIES) - $index]
            );
        }
    }

    public static function resolveResourceCategory(
        int $adminId,
        int $appId,
        string $type,
        array $data
    ): array {
        $type = self::normalizeResourceType($type);
        self::seedResourceCategories($adminId, $appId, $type);
        $categoryId = (int) ($data['category_id'] ?? 0);
        if ($categoryId > 0) {
            $category = Database::one(
                'SELECT * FROM resource_categories
                 WHERE id = ? AND admin_id = ? AND app_id = ? AND resource_type = ? AND status = 1',
                [$categoryId, $adminId, $appId, $type]
            );
            if ($category !== null) {
                return $category;
            }
        }

        $fallback = $type === 'source_market' ? '其他源码' : '暂无分类';
        $name = self::canonicalCategory(
            trim((string) ($data['category_name'] ?? $data['category'] ?? '')),
            $type,
            (string) ($data['name'] ?? $data['title'] ?? ''),
            (string) ($data['package_name'] ?? '')
        );
        if ($name === '') {
            $name = $fallback;
        }
        $category = Database::one(
            'SELECT * FROM resource_categories
             WHERE admin_id = ? AND app_id = ? AND resource_type = ? AND name = ? AND status = 1',
            [$adminId, $appId, $type, $name]
        );
        if ($category === null) {
            $category = Database::one(
                'SELECT * FROM resource_categories
                 WHERE admin_id = ? AND app_id = ? AND resource_type = ? AND name = ? AND status = 1',
                [$adminId, $appId, $type, $fallback]
            );
        }
        if ($category === null) {
            throw new HttpException('资源分类初始化失败，请稍后重试', 0, 500);
        }
        return $category;
    }

    public static function resolveStoreCategory(int $adminId, int $appId, array $data): array
    {
        self::seedStoreCategories($adminId, $appId);
        $categoryId = (int) ($data['category_id'] ?? 0);
        if ($categoryId > 0) {
            $category = Database::one(
                'SELECT * FROM store_categories WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1',
                [$categoryId, $adminId, $appId]
            );
            if ($category !== null) {
                return $category;
            }
        }

        $name = self::canonicalCategory(
            trim((string) ($data['category_name'] ?? $data['category'] ?? '')),
            'app_store',
            (string) ($data['name'] ?? $data['title'] ?? ''),
            (string) ($data['package_name'] ?? '')
        );
        if ($name === '') {
            $name = '暂无分类';
        }
        $category = Database::one(
            'SELECT * FROM store_categories WHERE admin_id = ? AND app_id = ? AND name = ? AND status = 1',
            [$adminId, $appId, $name]
        );
        if ($category === null) {
            $category = Database::one(
                'SELECT * FROM store_categories WHERE admin_id = ? AND app_id = ? AND name = ? AND status = 1',
                [$adminId, $appId, '暂无分类']
            );
        }
        if ($category === null) {
            throw new HttpException('应用分类初始化失败，请稍后重试', 0, 500);
        }
        return $category;
    }

    public static function inspectUserUpload(array $user, array $data, string $kind): array
    {
        return self::inspect(
            (int) $user['admin_id'],
            (int) $user['app_id'],
            (int) $user['id'],
            $data,
            $kind
        );
    }

    public static function inspectAdminUpload(array $admin, int $appId, array $data, string $kind): array
    {
        return self::inspect((int) $admin['id'], $appId, null, $data, $kind);
    }

    public static function present(array $row): array
    {
        $row = self::canonicalizeCatalogPresentation($row, false);
        $row['review_revision'] = self::reviewRevision($row);
        if (is_array($row['attachments'] ?? null)) {
            foreach ($row['attachments'] as &$attachment) {
                if (is_array($attachment)) unset($attachment['review_identity']);
            }
            unset($attachment);
        }
        // Never serialize a stored raw catalog URL. Authorized controllers add
        // an authenticated download endpoint after entitlement checks.
        unset($row['download_url'], $row['apk_url'], $row['source_url']);
        $metadata = $row['metadata_json'] ?? null;
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $row['metadata'] = $metadata;
        unset($row['metadata_json'], $row['file_path'], $row['stored_name']);

        $risk = (string) ($row['risk_level'] ?? 'review');
        $row['risk_level_label'] = [
            'low' => '检查通过',
            'review' => '等待人工复核',
            'high' => '高风险，禁止公开',
        ][$risk] ?? '等待人工复核';

        $audit = (string) ($row['audit_status'] ?? '');
        if ($audit !== '') {
            $row['audit_status_label'] = [
                'pending' => '待审核',
                'approved' => '已通过',
                'rejected' => '未通过',
                'on_hold' => '暂定',
            ][$audit] ?? '待审核';
        }

        if (isset($row['resource_type'])) {
            $row['resource_type_label'] = self::normalizeResourceType((string) $row['resource_type']) === 'source_market'
                ? '源码商城'
                : '应用商店';
        }
        if (isset($row['size_bytes'])) {
            $row['file_size_label'] = self::formatBytes((int) $row['size_bytes']);
        }
        unset($row['_catalog_media_sha256']);
        return $row;
    }

    /** Stable review token over content that an auditor actually approves. */
    public static function reviewRevision(array $row): string
    {
        $fields = isset($row['package_name'])
            ? [
                'user_id', 'category_id', 'name', 'package_name', 'version_name', 'version_code',
                'icon_url', 'size_bytes', 'description', 'metadata_json', 'file_sha256',
                'risk_level', 'risk_reason', 'source_upload_id', 'icon_upload_id', 'price_integral',
            ]
            : [
                'user_id', 'category_id', 'resource_type', 'title', 'description', 'cover_url',
                'size_bytes', 'file_sha256', 'risk_level', 'risk_reason', 'source_upload_id',
                'cover_upload_id', 'metadata_json', 'price_integral',
                'attachments_json', 'images_json', 'tags_json',
            ];
        $snapshot = [];
        foreach ($fields as $field) {
            $value = $row[$field] ?? null;
            $snapshot[$field] = is_scalar($value) || $value === null ? $value : null;
        }
        $attachments = is_array($row['attachments'] ?? null) ? $row['attachments'] : [];
        $attachmentSnapshot = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) continue;
            $stable = [
                'id' => (int) ($attachment['id'] ?? 0),
                'sort_order' => (int) ($attachment['sort_order'] ?? 0),
                'review_identity' => strtolower((string) ($attachment['review_identity'] ?? '')),
            ];
            if (preg_match('/^[a-f0-9]{64}$/', $stable['review_identity']) !== 1) {
                throw new \RuntimeException('Attachment is missing a stable review identity');
            }
            $attachmentSnapshot[] = $stable;
        }
        usort($attachmentSnapshot, static function (array $left, array $right): int {
            return [(int) ($left['sort_order'] ?? 0), (int) ($left['id'] ?? 0)]
                <=> [(int) ($right['sort_order'] ?? 0), (int) ($right['id'] ?? 0)];
        });
        $snapshot['attachments'] = $attachmentSnapshot;
        $snapshot['catalog_media_sha256'] = strtolower((string) ($row['_catalog_media_sha256'] ?? ''));
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) throw new \RuntimeException('Unable to build review revision');
        return hash('sha256', $json);
    }

    private static function inspect(
        int $adminId,
        int $appId,
        ?int $userId,
        array $data,
        string $kind
    ): array {
        $kind = self::normalizeResourceType($kind);
        $sourceUploadId = self::firstInt($data, [
            'source_upload_id', 'upload_id', 'file_upload_id', 'apk_upload_id',
        ]);
        $coverUploadId = self::firstInt($data, ['cover_upload_id', 'icon_upload_id']);
        if ($sourceUploadId <= 0) {
            throw new HttpException(
                $kind === 'app_store'
                    ? '应用安装包必须通过软件内上传，不能使用外部公开地址'
                    : '源码文件必须通过软件内上传，不能使用外部公开地址',
                0,
                422
            );
        }
        if ($coverUploadId <= 0
            && trim((string) ($data['cover_url'] ?? $data['icon_url'] ?? '')) !== '') {
            throw new HttpException('封面或图标不能直接提交外链，请先上传并提交 cover_upload_id', 0, 422);
        }
        $source = self::verifiedUpload($sourceUploadId, $adminId, $appId, $userId);
        $expectedScene = self::catalogScene($kind);
        if (strtolower(trim((string) ($source['scene'] ?? ''))) !== $expectedScene) {
            throw new HttpException('所选上传文件类型与投稿类型不一致，请重新选择', 0, 422);
        }
        $source = UploadStorageService::verifiedPrivateCatalogUpload(
            $sourceUploadId,
            $adminId,
            $appId,
            $expectedScene,
            true
        );
        $cover = $coverUploadId > 0
            ? self::verifiedUpload($coverUploadId, $adminId, $appId, $userId)
            : null;
        $coverValidation = null;
        if ($cover !== null) {
            $coverScene = self::catalogCoverScene($kind);
            if (strtolower(trim((string) ($cover['scene'] ?? ''))) !== $coverScene) {
                throw new HttpException('所选封面或图标的上传场景不正确，请重新上传', 0, 422);
            }
            if (!str_starts_with(strtolower(trim((string) ($cover['mime_type'] ?? ''))), 'image/')) {
                throw new HttpException('封面或图标必须使用图片文件', 0, 422);
            }
            if (strtolower(trim((string) ($cover['mime_type'] ?? ''))) === 'image/svg+xml') {
                throw new HttpException('封面或图标不支持 SVG，请改用 PNG、JPG、GIF 或 WebP', 0, 422);
            }
            $coverValidation = UploadStorageService::validatedPublicImageUpload($cover);
        }

        // Private catalog payloads deliberately have no directly reachable URL.
        $sourceUrl = '';

        $fileName = (string) $source['original_name'];
        $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        $allowed = $kind === 'app_store' ? self::APP_EXTENSIONS : self::SOURCE_EXTENSIONS;
        if ($extension !== '' && !in_array($extension, $allowed, true)) {
            throw new HttpException(
                $kind === 'app_store'
                    ? '应用商店仅支持 APK、APKS、XAPK、HAP、IPA 安装包'
                    : '源码商城暂不支持该文件类型',
                0,
                422
            );
        }

        $metadata = self::metadata($data['metadata'] ?? $data['metadata_json'] ?? []);
        $metadata['file_name'] = mb_substr($fileName, 0, 255);
        $metadata['extension'] = $extension;
        $metadata['mime_type'] = (string) ($source['mime_type'] ?? '');
        $metadata['size_bytes'] = (int) ($source['size_bytes'] ?? 0);
        foreach ([
            'package_name', 'version_name', 'version_code', 'platform', 'language',
            'min_sdk', 'target_sdk', 'permissions', 'framework', 'license',
        ] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== '') {
                $metadata[$field] = self::scalar($data[$field]);
            }
        }

        $hash = strtolower((string) ($source['sha256'] ?? ''));
        if ($hash !== '' && !preg_match('/^[a-f0-9]{64}$/', $hash)) {
            $hash = '';
        }
        $coverHash = strtolower((string) ($coverValidation['sha256'] ?? ''));
        if ($coverHash !== '' && !preg_match('/^[a-f0-9]{64}$/', $coverHash)) {
            $coverHash = '';
        }

        $risk = self::risk($source, $kind, $extension, $sourceUrl);
        $metadata['inspection'] = [
            'level' => $risk['level'],
            'label' => $risk['label'],
            'reason' => $risk['reason'],
            'verified_upload' => $source !== null,
            'checked_at' => date('Y-m-d H:i:s'),
        ];
        $metadataJson = json_encode(
            self::limitMetadata($metadata),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($metadataJson)) {
            $metadataJson = '{}';
        }

        return [
            'source_url' => mb_substr($sourceUrl, 0, 1000),
            'cover_url' => $cover !== null
                ? mb_substr((string) $coverValidation['file_url'], 0, 500)
                : '',
            'size_bytes' => (int) $metadata['size_bytes'],
            'file_sha256' => $hash,
            'risk_level' => $risk['level'],
            'risk_reason' => mb_substr($risk['reason'], 0, 1000),
            'source_upload_id' => $sourceUploadId > 0 ? $sourceUploadId : null,
            'cover_upload_id' => $coverUploadId > 0 ? $coverUploadId : null,
            'cover_sha256' => $coverHash,
            'metadata_json' => $metadataJson,
            'force_audit' => $risk['level'] !== 'low',
        ];
    }

    /**
     * Final in-transaction reference gate. Inspection happens before content is
     * written, so the upload row must be locked and revalidated to prevent a
     * concurrent library deletion from creating a dangling catalog entry.
     */
    public static function lockCatalogUploadReference(
        int $uploadId,
        int $adminId,
        int $appId,
        ?int $userId,
        string $expectedScene,
        string $expectedSha256 = ''
    ): array {
        $upload = self::lockUploadReferenceRow(
            $uploadId, $adminId, $appId, $userId, $expectedScene
        );
        if (!str_starts_with((string) ($upload['file_path'] ?? ''), 'private/')
            || UploadStorageService::privatePhysicalPath((string) $upload['file_path']) === null) {
            throw new HttpException('所选文件尚未完成私有化存储，不能保存条目', 0, 409);
        }
        $pendingCleanup = Database::one(
            "SELECT id FROM catalog_file_migrations
             WHERE upload_id = ? AND cleanup_status <> 'cleaned' LIMIT 1",
            [$uploadId]
        );
        if ($pendingCleanup !== null) {
            throw new HttpException('旧公开副本尚未完成清理，不能保存或审核条目', 0, 409);
        }
        self::assertLockedUploadHash($upload, $expectedSha256);
        return $upload;
    }

    public static function lockCatalogCoverReference(
        int $uploadId,
        int $adminId,
        int $appId,
        ?int $userId,
        string $expectedScene,
        string $expectedSha256 = ''
    ): array {
        $upload = self::lockUploadReferenceRow(
            $uploadId, $adminId, $appId, $userId, $expectedScene
        );
        $validation = UploadStorageService::validatedPublicImageUpload($upload);
        $expectedSha256 = strtolower(trim($expectedSha256));
        if ($expectedSha256 !== '' && !hash_equals($expectedSha256, (string) $validation['sha256'])) {
            throw new HttpException('封面或图标内容已变化，请重新检查后提交', 0, 409);
        }
        $upload['file_url'] = (string) $validation['file_url'];
        $upload['thumbnail_url'] = (string) $validation['thumbnail_url'];
        $upload['_public_image_validation'] = $validation;
        return $upload;
    }

    private static function lockUploadReferenceRow(
        int $uploadId,
        int $adminId,
        int $appId,
        ?int $userId,
        string $expectedScene
    ): array {
        $where = 'id = ? AND admin_id = ? AND app_id = ? AND scene = ? AND status = 1';
        $params = [$uploadId, $adminId, $appId, strtolower(trim($expectedScene))];
        if ($userId !== null) {
            $where .= ' AND user_id = ?';
            $params[] = $userId;
        } else {
            $where .= ' AND user_id IS NULL';
        }
        $upload = Database::one("SELECT * FROM uploads WHERE {$where} FOR UPDATE", $params);
        if ($upload === null) {
            throw new HttpException('所选文件已失效、被删除或不属于当前账号，请重新上传', 0, 409);
        }
        return $upload;
    }

    /**
     * Canonicalize catalog cover/icon exclusively from a live tenant-bound
     * public upload. Read paths hide historical mismatches; strict review/write
     * paths lock and reject them.
     */
    public static function canonicalizeCatalogPresentation(array $row, bool $strict = false): array
    {
        if (array_key_exists('cover_url', $row) && (int) ($row['cover_upload_id'] ?? 0) <= 0) {
            $row['cover_url'] = '';
        }
        if (array_key_exists('icon_url', $row) && (int) ($row['icon_upload_id'] ?? 0) <= 0) {
            $row['icon_url'] = '';
        }
        $isStore = array_key_exists('icon_url', $row) || isset($row['package_name']);
        $urlField = $isStore ? 'icon_url' : 'cover_url';
        $uploadField = $isStore ? 'icon_upload_id' : 'cover_upload_id';
        if (!array_key_exists($urlField, $row) && !array_key_exists($uploadField, $row)) return $row;
        $row[$urlField] = '';
        $row['_catalog_media_sha256'] = '';
        $uploadId = max(0, (int) ($row[$uploadField] ?? 0));
        $adminId = max(0, (int) ($row['admin_id'] ?? 0));
        $appId = max(0, (int) ($row['app_id'] ?? 0));
        if ($uploadId <= 0 || $adminId <= 0 || $appId <= 0) {
            if ($strict && $uploadId > 0) {
                throw new HttpException('封面或图标缺少租户绑定信息，不能审核', 0, 409);
            }
            return $row;
        }
        $ownerId = array_key_exists('user_id', $row) && $row['user_id'] !== null
            ? (int) $row['user_id']
            : null;
        $kind = $isStore
            ? 'app_store'
            : self::normalizeResourceType((string) ($row['resource_type'] ?? 'source_market'));
        $scene = self::catalogCoverScene($kind);
        try {
            if ($strict) {
                $upload = self::lockCatalogCoverReference(
                    $uploadId,
                    $adminId,
                    $appId,
                    $ownerId,
                    $scene
                );
                $validation = $upload['_public_image_validation'];
            } else {
                $upload = self::verifiedUploadForOwnerAndScene(
                    $uploadId,
                    $adminId,
                    $appId,
                    $ownerId,
                    $scene
                );
                $validation = UploadStorageService::validatedPublicImageUpload($upload);
            }
            $row[$urlField] = (string) $validation['file_url'];
            $row['_catalog_media_sha256'] = (string) $validation['sha256'];
        } catch (HttpException $failure) {
            if ($strict) throw $failure;
        }
        return $row;
    }

    private static function verifiedUploadForOwnerAndScene(
        int $uploadId,
        int $adminId,
        int $appId,
        ?int $userId,
        string $scene
    ): array {
        $where = 'id = ? AND admin_id = ? AND app_id = ? AND scene = ? AND status = 1';
        $params = [$uploadId, $adminId, $appId, strtolower(trim($scene))];
        if ($userId === null) {
            $where .= ' AND user_id IS NULL';
        } else {
            $where .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $upload = Database::one("SELECT * FROM uploads WHERE {$where}", $params);
        if ($upload === null) throw new HttpException('封面或图标上传已失效或不属于当前条目', 0, 409);
        return $upload;
    }

    private static function assertLockedUploadHash(array $upload, string $expectedSha256): void
    {
        $expectedSha256 = strtolower(trim($expectedSha256));
        if ($expectedSha256 !== '' && !hash_equals($expectedSha256, strtolower((string) ($upload['sha256'] ?? '')))) {
            throw new HttpException('文件内容指纹已变化，请重新检查后提交', 0, 409);
        }
    }

    private static function verifiedUpload(
        int $uploadId,
        int $adminId,
        int $appId,
        ?int $userId
    ): array {
        $where = 'id = ? AND admin_id = ? AND app_id = ? AND status = 1';
        $params = [$uploadId, $adminId, $appId];
        if ($userId !== null) {
            $where .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $upload = Database::one("SELECT * FROM uploads WHERE {$where}", $params);
        if ($upload === null) {
            throw new HttpException('所选文件不存在、已失效或不属于当前账号', 0, 422);
        }
        return $upload;
    }

    private static function risk(?array $upload, string $kind, string $extension, string $url): array
    {
        if ($upload === null) {
            return [
                'level' => 'review',
                'label' => '等待人工复核',
                'reason' => '文件未通过软件内上传记录关联，需人工确认来源与内容',
            ];
        }

        $path = UploadStorageService::storedPhysicalPath((string) ($upload['file_path'] ?? ''));
        if ($path === null || !is_readable($path)) {
            return [
                'level' => 'review',
                'label' => '等待人工复核',
                'reason' => '服务器无法读取文件内容，需人工复核',
            ];
        }

        $handle = @fopen($path, 'rb');
        $sample = $handle === false ? '' : (string) fread($handle, 2 * 1024 * 1024);
        if (is_resource($handle)) {
            fclose($handle);
        }
        $lower = strtolower($sample);
        $highPatterns = [
            'eicar-standard-antivirus-test-file',
            'eval(base64_decode',
            'assert($_post',
            'shell_exec($_',
            'passthru($_',
            'system($_',
            'runtime.getruntime().exec',
        ];
        foreach ($highPatterns as $pattern) {
            if ($lower !== '' && strpos($lower, $pattern) !== false) {
                return [
                    'level' => 'high',
                    'label' => '高风险，禁止公开',
                    'reason' => '检测到疑似恶意执行、WebShell 或病毒测试特征',
                ];
            }
        }

        if ($kind === 'source_market' && in_array($extension, ['php', 'py', 'js', 'java', 'kt', 'rs', 'c', 'cpp'], true)) {
            return [
                'level' => 'review',
                'label' => '等待人工复核',
                'reason' => '源码包含可执行逻辑，系统已收录并等待人工安全审核',
            ];
        }
        return ['level' => 'low', 'label' => '检查通过', 'reason' => '文件类型、来源和基础特征检查通过'];
    }

    private static function metadata($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }

    private static function limitMetadata(array $value): array
    {
        $result = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if (++$count > 100) {
                break;
            }
            $safeKey = mb_substr((string) $key, 0, 100);
            if (is_array($item)) {
                $result[$safeKey] = self::limitMetadata($item);
            } elseif (is_scalar($item) || $item === null) {
                $result[$safeKey] = self::scalar($item);
            }
        }
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded) && strlen($encoded) > 65536) {
            return ['notice' => '元数据过长，系统仅保留基础文件信息'];
        }
        return $result;
    }

    private static function scalar($value)
    {
        if (is_string($value)) {
            return mb_substr($value, 0, 2000);
        }
        return $value;
    }

    private static function firstInt(array $data, array $keys): int
    {
        $values = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === '' || $data[$key] === null) continue;
            $value = max(0, (int) $data[$key]);
            if ($value > 0) $values[$value] = true;
        }
        if (count($values) > 1) {
            throw new HttpException('同一上传引用的多个 ID 字段互相冲突，请只提交一个一致的 upload_id', 0, 422);
        }
        return $values === [] ? 0 : (int) array_key_first($values);
    }

    private static function canonicalCategory(
        string $requested,
        string $type,
        string $name,
        string $packageName
    ): string {
        $haystack = strtolower($requested . ' ' . $name . ' ' . $packageName);
        if ($type === 'source_market') {
            $rules = [
                'JavaScript 源码' => ['javascript', 'node', 'vue', 'react', 'typescript', '.js'],
                'Android Java 源码' => ['android', 'apk', '安卓', 'kotlin', 'java'],
                'iApp 源码' => ['iapp'],
                'Lua 源码' => ['lua', '.lua'],
                'Web 源码' => ['web', 'html', 'css', '网页', '前端'],
                'PHP 源码' => ['php'],
                'Python 源码' => ['python', '.py', 'django', 'flask'],
                'HarmonyOS 源码' => ['harmony', '鸿蒙', 'hap', 'arkts'],
                'iOS 源码' => ['ios', 'iphone', 'ipa', 'swift'],
                'C/C++ 源码' => ['c++', 'cpp', '.c', '.h'],
                '数据库源码' => ['sql', 'mysql', 'database', '数据库'],
                '通用模块' => ['通用模块', '聊天模块', '群聊模块', '登录注册模块', '论坛模块'],
            ];
            foreach ($rules as $category => $keywords) {
                if (self::containsAny($haystack, $keywords)) {
                    return $category;
                }
            }
            $categoryNames = array_column(self::SOURCE_CATEGORY_SEEDS, 'name');
            return in_array($requested, $categoryNames, true) ? $requested : '其他源码';
        }

        $rules = [
            '社交' => ['社交', '聊天', '通讯', '即时通信', 'im', 'qq', '微信'],
            '游戏' => ['游戏', '王者', 'minecraft', 'mc', '麦块', '我的世界'],
            '生活' => ['生活', '天气', '外卖', '日历', '社区'],
            '工具' => ['工具', '文件', '浏览器', '清理', '扫码', '计算器'],
            '影音' => ['影音', '视频', '音乐', '播放器', '直播'],
            '学习' => ['学习', '教育', '课程', '题库', '词典'],
            '办公' => ['办公', '文档', '表格', '会议', '协作'],
            '购物' => ['购物', '商城', '电商', '买卖'],
            '出行' => ['出行', '地图', '导航', '打车', '旅行'],
            '健康' => ['健康', '医疗', '运动', '健身'],
            '金融' => ['金融', '银行', '证券', '支付', '记账'],
        ];
        foreach ($rules as $category => $keywords) {
            if (self::containsAny($haystack, $keywords)) {
                return $category;
            }
        }
        return in_array($requested, self::APP_CATEGORIES, true) ? $requested : '暂无分类';
    }

    private static function containsAny(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && strpos($haystack, strtolower($keyword)) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = min((int) floor(log($bytes, 1024)), count($units) - 1);
        return round($bytes / (1024 ** $index), $index === 0 ? 0 : 2) . ' ' . $units[$index];
    }

    public static function requireCatalogMigrationReady(int $appId): void
    {
        if (AppService::setting($appId, 'catalog_private_migration_ready', false) !== true) {
            throw new HttpException('资源与应用目录正在进行文件安全维护，请稍后再试', 0, 503);
        }
    }

    /**
     * Serialize every catalog mutation against the private-file migration and
     * re-check the internal gate while the setting row is locked.
     */
    public static function catalogWriteTransaction(int $appId, callable $callback): mixed
    {
        return Database::transaction(static function () use ($appId, $callback): mixed {
                $gate = Database::one(
                    "SELECT setting_value, value_type FROM app_settings
                     WHERE app_id = ? AND setting_key = 'catalog_private_migration_ready' LOCK IN SHARE MODE",
                    [$appId]
                );
                if ($gate === null || (string) ($gate['value_type'] ?? '') !== 'bool'
                    || !in_array(strtolower(trim((string) ($gate['setting_value'] ?? ''))), ['1', 'true'], true)) {
                    throw new HttpException('资源与应用目录正在进行文件安全维护，请稍后再试', 0, 503);
                }
                return $callback();
        });
    }

    /** Serialize app creation and catalog writes against migration activation. */
    public static function catalogSchemaTransaction(callable $callback): mixed
    {
        $lock = Database::one("SELECT GET_LOCK('yiyunying_catalog_private_migration', 0) AS acquired");
        if ((int) ($lock['acquired'] ?? 0) !== 1) {
            throw new HttpException('资源与应用目录正在进行文件安全维护，请稍后再试', 0, 503);
        }
        try {
            return Database::transaction(static fn (): mixed => $callback());
        } finally {
            try {
                Database::one("SELECT RELEASE_LOCK('yiyunying_catalog_private_migration') AS released");
            } catch (\Throwable) {
            }
        }
    }
}
