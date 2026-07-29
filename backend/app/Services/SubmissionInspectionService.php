<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class SubmissionInspectionService
{
    private const APP_CATEGORIES = [
        '暂无分类', '生活', '社交', '游戏', '工具', '影音',
        '学习', '办公', '购物', '出行', '健康', '金融',
    ];

    private const SOURCE_CATEGORIES = [
        'Android', 'HarmonyOS', 'iOS', 'Web', 'PHP', 'Java',
        'Python', 'JavaScript', 'C/C++', 'Rust', '数据库', 'iApp', '其他',
    ];

    private const APP_EXTENSIONS = ['apk', 'apks', 'xapk', 'hap', 'ipa'];

    private const SOURCE_EXTENSIONS = [
        'zip', '7z', 'rar', 'tar', 'gz', 'iapp', 'py', 'java', 'php',
        'html', 'htm', 'js', 'css', 'sql', 'c', 'cc', 'cpp', 'h', 'hpp',
        'rs', 'kt', 'kts', 'swift', 'xml', 'json', 'yaml', 'yml',
    ];

    public static function normalizeResourceType(string $type): string
    {
        $value = strtolower(trim($type));
        if (in_array($value, ['source', 'source_store', 'source_market', 'code', '源码', '源码商城'], true)) {
            return 'source_market';
        }
        return 'app_store';
    }

    public static function seedResourceCategories(int $adminId, int $appId, string $type): void
    {
        $type = self::normalizeResourceType($type);
        $names = $type === 'source_market' ? self::SOURCE_CATEGORIES : self::APP_CATEGORIES;
        foreach ($names as $index => $name) {
            Database::execute(
                'INSERT IGNORE INTO resource_categories
                 (admin_id, app_id, resource_type, name, icon, description, sort_order, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
                [
                    $adminId,
                    $appId,
                    $type,
                    $name,
                    '',
                    $type === 'source_market' ? '源码商城标准分类' : '应用商店标准分类',
                    count($names) - $index,
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

        $fallback = $type === 'source_market' ? '其他' : '暂无分类';
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
        return $row;
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
        $source = $sourceUploadId > 0
            ? self::verifiedUpload($sourceUploadId, $adminId, $appId, $userId)
            : null;
        $cover = $coverUploadId > 0
            ? self::verifiedUpload($coverUploadId, $adminId, $appId, $userId)
            : null;

        $urlField = $kind === 'app_store' ? 'apk_url' : 'download_url';
        $sourceUrl = $source !== null
            ? (string) $source['file_url']
            : trim((string) ($data[$urlField] ?? $data['download_url'] ?? $data['apk_url'] ?? ''));
        if ($sourceUrl === '') {
            throw new HttpException($kind === 'app_store' ? '请选择应用安装包' : '请选择源码文件', 0, 422);
        }

        $fileName = $source !== null
            ? (string) $source['original_name']
            : trim((string) ($data['file_name'] ?? $data['original_name'] ?? basename((string) parse_url($sourceUrl, PHP_URL_PATH))));
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
        $metadata['mime_type'] = $source !== null
            ? (string) ($source['mime_type'] ?? '')
            : mb_substr((string) ($data['mime_type'] ?? ''), 0, 120);
        $metadata['size_bytes'] = $source !== null
            ? (int) ($source['size_bytes'] ?? 0)
            : max(0, (int) ($data['size_bytes'] ?? $data['size'] ?? 0));
        foreach ([
            'package_name', 'version_name', 'version_code', 'platform', 'language',
            'min_sdk', 'target_sdk', 'permissions', 'framework', 'license',
        ] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== '') {
                $metadata[$field] = self::scalar($data[$field]);
            }
        }

        $hash = $source !== null
            ? strtolower((string) ($source['sha256'] ?? ''))
            : strtolower(trim((string) ($data['file_sha256'] ?? $data['sha256'] ?? '')));
        if ($hash !== '' && !preg_match('/^[a-f0-9]{64}$/', $hash)) {
            $hash = '';
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
                ? mb_substr((string) $cover['file_url'], 0, 500)
                : mb_substr((string) ($data['cover_url'] ?? $data['icon_url'] ?? ''), 0, 500),
            'size_bytes' => (int) $metadata['size_bytes'],
            'file_sha256' => $hash,
            'risk_level' => $risk['level'],
            'risk_reason' => mb_substr($risk['reason'], 0, 1000),
            'source_upload_id' => $sourceUploadId > 0 ? $sourceUploadId : null,
            'cover_upload_id' => $coverUploadId > 0 ? $coverUploadId : null,
            'metadata_json' => $metadataJson,
            'force_audit' => $risk['level'] !== 'low',
        ];
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

        $path = (string) ($upload['file_path'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
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
        if ($url === '') {
            return [
                'level' => 'review',
                'label' => '等待人工复核',
                'reason' => '文件地址无效',
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
        foreach ($keys as $key) {
            if (!empty($data[$key])) {
                return max(0, (int) $data[$key]);
            }
        }
        return 0;
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
                'Android' => ['android', 'apk', '安卓', 'kotlin'],
                'HarmonyOS' => ['harmony', '鸿蒙', 'hap', 'arkts'],
                'iOS' => ['ios', 'iphone', 'ipa', 'swift'],
                'PHP' => ['php'],
                'Java' => ['java'],
                'Python' => ['python', '.py', 'django', 'flask'],
                'JavaScript' => ['javascript', 'node', 'vue', 'react', 'typescript', '.js'],
                'C/C++' => ['c++', 'cpp', '.c', '.h'],
                'Rust' => ['rust', '.rs'],
                '数据库' => ['sql', 'mysql', 'database', '数据库'],
                'iApp' => ['iapp'],
                'Web' => ['web', 'html', 'css', '网页', '前端'],
            ];
            foreach ($rules as $category => $keywords) {
                if (self::containsAny($haystack, $keywords)) {
                    return $category;
                }
            }
            return in_array($requested, self::SOURCE_CATEGORIES, true) ? $requested : '其他';
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
}
