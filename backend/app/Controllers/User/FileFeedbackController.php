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
use Yiyunying\Services\AiAssistantService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\NewsService;
use Yiyunying\Services\UploadLibraryService;
use Yiyunying\Services\UploadLimitService;
use Yiyunying\Services\UploadStorageService;
use Yiyunying\Services\WeatherService;

final class FileFeedbackController
{
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif',
        'pdf', 'txt', 'md', 'json', 'csv', 'rtf', 'odt', 'ods', 'odp',
        'zip', '7z', 'rar', 'tar', 'gz', 'bz2', 'xz',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'mp3', 'm4a', 'aac', 'wav', 'ogg', 'opus', 'flac',
        'mp4', 'webm', 'mov', 'mkv', 'avi', '3gp', 'm4v', 'apk',
    ];

    public static function files(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'remote_files');
        $where = ['admin_id = ?', 'app_id = ?', 'status = 1', 'deleted_at IS NULL', '(visibility = ? OR owner_user_id = ?)'];
        $query = [(int) $user['admin_id'], (int) $user['app_id'], 'public', (int) $user['id']];
        $parent = $request->input('parent_id');
        if ($parent === null || $parent === '' || (int) $parent === 0) {
            $where[] = 'parent_id IS NULL';
        } else {
            $where[] = 'parent_id = ?';
            $query[] = (int) $parent;
        }
        return Response::success(['items' => Database::all(
            'SELECT id, parent_id, file_type, name, file_url, mime_type, size_bytes, visibility, updated_at
             FROM remote_files WHERE ' . implode(' AND ', $where) . " ORDER BY (file_type = 'folder') DESC, name, id",
            $query
        )]);
    }

    public static function showFile(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'remote_files');
        $file = Database::one(
            'SELECT * FROM remote_files WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 1
             AND deleted_at IS NULL AND (visibility = ? OR owner_user_id = ?)',
            [(int) $params['file_id'], (int) $user['admin_id'], (int) $user['app_id'], 'public', (int) $user['id']]
        );
        if ($file === null) {
            throw new HttpException('远程文件不存在或无权访问', 404, 404);
        }
        return Response::success(['file' => $file]);
    }

    public static function upload(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = AuthService::user($request);
        $scene = self::normalizeUploadScene($request->input('scene', 'general'));
        foreach (self::uploadFeaturesForScene($scene) as $feature) {
            AuthService::requireUserFeature($user, $feature);
        }
        AuthService::ensureNotBanned($user, ['all', 'upload']);
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            throw new HttpException('缺少 multipart/form-data 文件字段 file', 0, 422);
        }
        $file = $_FILES['file'];
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException('文件上传失败', 0, 422, ['upload_error' => (int) ($file['error'] ?? -1)]);
        }
        $limit = UploadLimitService::validate((int) $user['app_id'], $file);
        if (!$limit['valid']) {
            throw new HttpException(
                UploadLimitService::label((string) $limit['category']) . '大小超出当前应用限制',
                0,
                422,
                $limit + ['unit' => '字节']
            );
        }
        $original = basename((string) ($file['name'] ?? 'file'));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new HttpException('不允许上传该文件类型', 0, 422, ['extension' => $extension]);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            throw new HttpException('上传临时文件无效', 0, 422);
        }
        $result = UploadStorageService::store(
            $file, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
            $scene, self::ALLOWED_EXTENSIONS,
            ['original_upload' => $request->input('original_upload', false)]
        );
        LogService::userOperation($request, $user, 'upload', 'create', (int) $result['upload_id'], [
            'scene' => $scene, 'reused' => (bool) $result['reused'],
        ]);
        return Response::success($result, (bool) $result['reused'] ? '已复用相同文件，无需重复上传' : '文件上传成功', 201);
    }

    public static function createFeedback(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'feedback');
        AuthService::ensureNotBanned($user, ['all', 'feedback']);
        $data = $request->all();
        Validator::required($data, ['title', 'content']);
        $images = $data['images'] ?? [];
        if (is_string($images)) {
            $images = json_decode($images, true);
        }
        if (!is_array($images) || count($images) > 20) {
            throw new HttpException('images 必须是最多 20 项的数组', 0, 422);
        }
        $id = Database::insert(
            'INSERT INTO feedbacks
             (admin_id, app_id, user_id, type, title, content, images_json, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
                mb_substr((string) ($data['type'] ?? 'feedback'), 0, 40),
                Validator::string($data['title'], 'title', 1, 200),
                Validator::string($data['content'], 'content', 1, 20000),
                json_encode(array_values($images), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'pending',
            ]
        );
        return Response::success(['feedback_id' => $id], '反馈提交成功', 201);
    }

    public static function feedbacks(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'feedback');
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one('SELECT COUNT(*) AS total FROM feedbacks WHERE app_id = ? AND user_id = ?', [
            (int) $user['app_id'], (int) $user['id'],
        ])['total'] ?? 0);
        $items = Database::all(
            "SELECT * FROM feedbacks WHERE app_id = ? AND user_id = ? ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            [(int) $user['app_id'], (int) $user['id']]
        );
        foreach ($items as &$item) {
            $item['images_json'] = json_decode((string) ($item['images_json'] ?? '[]'), true) ?: [];
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function botAsk(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'bot');
        $question = Validator::string($request->input('question', ''), 'question', 1, 500);
        if (WeatherService::isWeatherQuestion($question)) {
            $latitude = $request->input('latitude');
            $longitude = $request->input('longitude');
            $locationName = trim((string) $request->input('location_name', ''));
            $locationQuery = trim((string) $request->input('location_query', ''));
            if ($locationQuery === '') $locationQuery = WeatherService::extractLocationQuery($question);
            try {
                if (!is_numeric($latitude) || !is_numeric($longitude)) {
                    if ($locationQuery === '') {
                        return Response::success([
                            'matched' => true,
                            'type' => 'location_required',
                            'answer' => '需要获取你的当前位置后才能查询当地天气，请允许定位权限后重试。',
                        ]);
                    }
                    $resolved = WeatherService::resolveLocation($locationQuery);
                    $latitude = $resolved['latitude'];
                    $longitude = $resolved['longitude'];
                    if ($locationName === '') $locationName = (string) ($resolved['location_name'] ?? $locationQuery);
                }
                $weather = WeatherService::current(
                    (float) $latitude,
                    (float) $longitude,
                    $locationName,
                    $question
                );
                return Response::success([
                    'matched' => true,
                    'type' => 'weather',
                    'title' => $locationName === '' ? '天气查询结果' : $locationName . '天气',
                    'category' => '实时天气',
                    'answer' => (string) ($weather['summary'] ?? ''),
                    'requested_location' => $locationQuery,
                    'resolved_location' => $locationName,
                    'weather' => $weather,
                ]);
            } catch (HttpException $exception) {
                return Response::success([
                    'matched' => true,
                    'type' => 'weather_unavailable',
                    'answer' => $exception->getMessage(),
                ]);
            }
        }
        if (NewsService::isNewsQuestion($question)) {
            try {
                return Response::success(NewsService::latest($question));
            } catch (HttpException $exception) {
                return Response::success([
                    'matched' => true,
                    'type' => 'news_unavailable',
                    'answer' => $exception->getMessage(),
                ]);
            }
        }
        $customAnswers = Database::all(
            'SELECT id, question, answer, keywords, sort_order FROM bot_qa
             WHERE app_id = ? AND status = 1 ORDER BY sort_order DESC, id DESC LIMIT 500',
            [(int) $user['app_id']]
        );
        $conversationId = (int) $request->input('conversation_id', 0);
        return Response::success(AiAssistantService::answer(
            $user,
            $question,
            $customAnswers,
            $conversationId > 0 ? $conversationId : null
        ));
    }

    public static function uploads(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'remote_files');
        return Response::success(UploadLibraryService::list(
            (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], $request
        ));
    }

    public static function deleteUpload(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'remote_files');
        $result = UploadLibraryService::remove(
            (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], (int) $params['upload_id']
        );
        LogService::userOperation($request, $user, 'upload', 'delete', (int) $params['upload_id']);
        return Response::success($result, '上传文件已删除');
    }

    public static function favoriteUpload(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'remote_files');
        $uploadId = (int) ($params['upload_id'] ?? 0);
        if (!Database::one('SELECT id FROM uploads WHERE id = ? AND admin_id = ? AND app_id = ? AND user_id = ? AND status = 1', [
            $uploadId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'],
        ])) throw new HttpException('上传文件不存在或无权收藏', 404, 404);
        $existing = Database::one(
            "SELECT id FROM content_favorites WHERE user_id = ? AND content_type = 'upload' AND content_id = ?",
            [(int) $user['id'], $uploadId]
        );
        if ($existing !== null) {
            Database::execute('DELETE FROM content_favorites WHERE id = ?', [(int) $existing['id']]);
            return Response::success(['upload_id' => $uploadId, 'favorited' => false], '已取消文件收藏');
        }
        Database::execute(
            'INSERT INTO content_favorites (admin_id, app_id, user_id, content_type, content_id, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id'], 'upload', $uploadId]
        );
        return Response::success(['upload_id' => $uploadId, 'favorited' => true], '文件已收藏');
    }

    public static function favoriteUploads(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request, 'remote_files');
        $items = Database::all(
            "SELECT up.id, up.original_name, up.file_url, up.mime_type, up.size_bytes, up.sha256,
                    up.scene, up.created_at, favorite.created_at AS favorited_at
             FROM content_favorites favorite INNER JOIN uploads up ON up.id = favorite.content_id
             WHERE favorite.user_id = ? AND favorite.app_id = ? AND favorite.content_type = 'upload'
               AND up.status = 1 ORDER BY favorite.id DESC",
            [(int) $user['id'], (int) $user['app_id']]
        );
        foreach ($items as &$item) {
            $mime = strtolower((string) $item['mime_type']);
            $item['file_category'] = str_starts_with($mime, 'image/') ? 'image'
                : (str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'audio/') ? 'audio' : 'document'));
            $item['preview_url'] = $item['file_url'];
        }
        unset($item);
        return Response::success(['items' => $items]);
    }

    private static function normalizeUploadScene($value): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') return 'general';
        $normalized = strtolower($raw);
        return match ($normalized) {
            'chat_camera', 'chat_album', 'forum_post', 'forum_comment', 'forum_section',
            'resource_source', 'resource_cover', 'store_app_package', 'store_app_icon' => $normalized,
            '论坛帖子' => 'forum_post',
            '论坛评论' => 'forum_comment',
            '论坛章节' => 'forum_section',
            default => mb_substr($raw, 0, 40),
        };
    }

    /** @return list<string> */
    private static function uploadFeaturesForScene(string $scene): array
    {
        return match ($scene) {
            'chat_camera' => ['chat_camera'],
            'chat_album' => ['chat_album'],
            'forum_post', 'forum_comment' => ['forum'],
            'forum_section' => ['forum', 'forum_chapters', 'forum_attachment_unlock'],
            'resource_source', 'resource_cover' => ['resources'],
            'store_app_package', 'store_app_icon' => ['store'],
            default => ['remote_files'],
        };
    }

    private static function user(Request $request, string $feature): array
    {
        return AuthService::user($request, $feature);
    }
}
