<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Validator;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;

final class ContentController
{
    public static function notices(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['admin_id = ?', 'app_id = ?', 'deleted_at IS NULL'];
        $queryParams = [(int) $admin['id'], $appId];
        foreach (['type', 'status'] as $field) {
            if ($request->input($field) !== null && $request->input($field) !== '') {
                $where[] = "{$field} = ?";
                $queryParams[] = $field === 'status' ? (int) $request->input($field) : (string) $request->input($field);
            }
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM notices WHERE {$whereSql}", $queryParams)['total'] ?? 0);
        $items = Database::all(
            "SELECT * FROM notices WHERE {$whereSql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            $queryParams
        );
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function createNotice(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $data = $request->all();
        Validator::required($data, ['title', 'content']);
        $title = Validator::string($data['title'], 'title', 1, 200);
        $content = (string) $data['content'];
        if (mb_strlen($content) > 100000) {
            throw new HttpException('公告内容不能超过 100000 个字符', 0, 422);
        }
        $type = mb_substr(trim((string) ($data['type'] ?? 'notice')), 0, 30);
        $isPopup = Validator::boolean($data['is_popup'] ?? false, 'is_popup') ? 1 : 0;
        $displayEnabled = Validator::boolean($data['display_enabled'] ?? true, 'display_enabled') ? 1 : 0;
        $popupFrequency = trim((string) ($data['popup_frequency'] ?? 'once'));
        if (!in_array($popupFrequency, ['once', 'daily', 'login', 'always', 'none'], true)) {
            throw new HttpException('popup_frequency 仅支持 once、daily、login、always、none', 0, 422);
        }
        $audienceType = trim((string) ($data['audience_type'] ?? 'all'));
        if (!in_array($audienceType, ['all', 'vip', 'normal', 'user_ids', 'levels', 'tags'], true)) {
            throw new HttpException('audience_type 不支持', 0, 422);
        }
        $audience = $data['audience'] ?? [];
        if (!is_array($audience)) throw new HttpException('audience 必须是数组', 0, 422);
        $startAt = Validator::nullableDateTime($data['start_at'] ?? null, 'start_at');
        $endAt = Validator::nullableDateTime($data['end_at'] ?? null, 'end_at');
        $noticeId = Database::insert(
            'INSERT INTO notices
             (admin_id, app_id, title, content, type, is_popup, display_enabled, popup_frequency,
              audience_type, audience_json, status, start_at, end_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW())',
            [
                (int) $admin['id'], $appId, $title, $content, $type, $isPopup, $displayEnabled,
                $popupFrequency, $audienceType,
                json_encode(array_values($audience), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $startAt, $endAt,
            ]
        );
        $notice = self::notice((int) $admin['id'], $appId, $noticeId);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'notice', 'create', $noticeId, null, $notice);
        return Response::success(['notice' => $notice], '公告发布成功', 201);
    }

    public static function updateNotice(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $noticeId = (int) $params['notice_id'];
        $before = self::notice((int) $admin['id'], $appId, $noticeId);
        $data = $request->all();
        $fields = [];
        $values = [];
        foreach (['title' => 200, 'content' => 100000, 'type' => 30] as $field => $max) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = mb_substr((string) $data[$field], 0, $max);
            }
        }
        if (array_key_exists('is_popup', $data)) {
            $fields[] = 'is_popup = ?';
            $values[] = Validator::boolean($data['is_popup'], 'is_popup') ? 1 : 0;
        }
        if (array_key_exists('display_enabled', $data)) {
            $fields[] = 'display_enabled = ?';
            $values[] = Validator::boolean($data['display_enabled'], 'display_enabled') ? 1 : 0;
        }
        if (array_key_exists('popup_frequency', $data)) {
            $frequency = trim((string) $data['popup_frequency']);
            if (!in_array($frequency, ['once', 'daily', 'login', 'always', 'none'], true)) throw new HttpException('popup_frequency 不支持', 0, 422);
            $fields[] = 'popup_frequency = ?'; $values[] = $frequency;
        }
        if (array_key_exists('audience_type', $data)) {
            $audienceType = trim((string) $data['audience_type']);
            if (!in_array($audienceType, ['all', 'vip', 'normal', 'user_ids', 'levels', 'tags'], true)) throw new HttpException('audience_type 不支持', 0, 422);
            $fields[] = 'audience_type = ?'; $values[] = $audienceType;
        }
        if (array_key_exists('audience', $data)) {
            if (!is_array($data['audience'])) throw new HttpException('audience 必须是数组', 0, 422);
            $fields[] = 'audience_json = ?';
            $values[] = json_encode(array_values($data['audience']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (array_key_exists('status', $data)) {
            $fields[] = 'status = ?';
            $values[] = Validator::integer($data['status'], 'status', 0, 1);
        }
        foreach (['start_at', 'end_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = Validator::nullableDateTime($data[$field], $field);
            }
        }
        if ($fields === []) {
            throw new HttpException('没有可修改的字段', 0, 422);
        }
        $values[] = $noticeId;
        $values[] = (int) $admin['id'];
        $values[] = $appId;
        Database::execute(
            'UPDATE notices SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ? AND admin_id = ? AND app_id = ?',
            $values
        );
        $after = self::notice((int) $admin['id'], $appId, $noticeId);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'notice', 'update', $noticeId, $before, $after);
        return Response::success(['notice' => $after], '公告修改成功');
    }

    public static function deleteNotice(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $noticeId = (int) $params['notice_id'];
        $before = self::notice((int) $admin['id'], $appId, $noticeId);
        Database::execute(
            'UPDATE notices SET status = -1, deleted_at = NOW(), updated_at = NOW() WHERE id = ? AND admin_id = ? AND app_id = ?',
            [$noticeId, (int) $admin['id'], $appId]
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'notice', 'delete', $noticeId, $before);
        return Response::success([], '公告已删除');
    }

    public static function versions(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $items = Database::all(
            'SELECT * FROM app_versions WHERE admin_id = ? AND app_id = ? AND deleted_at IS NULL
             ORDER BY version_code DESC, id DESC',
            [(int) $admin['id'], $appId]
        );
        return Response::success(['items' => $items]);
    }

    public static function publishVersion(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $data = $request->all();
        Validator::required($data, ['version_name', 'version_code', 'apk_url', 'update_content']);
        $versionName = Validator::string($data['version_name'], 'version_name', 1, 40);
        $versionCode = Validator::integer($data['version_code'], 'version_code', 1, 2147483647);
        $minSupportedVersionCode = Validator::integer(
            $data['min_supported_version_code'] ?? 0,
            'min_supported_version_code',
            0,
            $versionCode
        );
        $apkUrl = Validator::string($data['apk_url'], 'apk_url', 1, 500);
        $updateContent = (string) $data['update_content'];
        $forceUpdate = Validator::boolean($data['force_update'] ?? false, 'force_update') ? 1 : 0;
        if (Database::one('SELECT id FROM app_versions WHERE app_id = ? AND version_code = ? AND deleted_at IS NULL', [$appId, $versionCode])) {
            throw new HttpException('该 version_code 已存在', 0, 409);
        }
        $versionId = Database::insert(
            'INSERT INTO app_versions
             (admin_id, app_id, version_name, version_code, min_supported_version_code, apk_url, update_content, force_update, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [(int) $admin['id'], $appId, $versionName, $versionCode, $minSupportedVersionCode, $apkUrl, $updateContent, $forceUpdate]
        );
        Database::execute('UPDATE apps SET version = ?, updated_at = NOW() WHERE id = ? AND admin_id = ?', [
            $versionName,
            $appId,
            (int) $admin['id'],
        ]);
        $version = Database::one('SELECT * FROM app_versions WHERE id = ?', [$versionId]);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'version', 'publish', $versionId, null, $version);
        return Response::success(['version' => $version], '版本发布成功', 201);
    }

    public static function banners(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $where = ['admin_id = ?', 'app_id = ?'];
        $query = [(int) $admin['id'], $appId];
        if (trim((string) $request->input('position', '')) !== '') {
            $where[] = 'position = ?';
            $query[] = trim((string) $request->input('position'));
        }
        return Response::success(['items' => Database::all(
            'SELECT * FROM banners WHERE ' . implode(' AND ', $where) . ' ORDER BY sort_order DESC, id DESC',
            $query
        )]);
    }

    public static function createBanner(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $data = $request->all();
        Validator::required($data, ['image_url']);
        $id = Database::insert(
            'INSERT INTO banners
             (admin_id, app_id, title, image_url, link_url, position, sort_order, status, start_at, end_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (int) $admin['id'], $appId, mb_substr((string) ($data['title'] ?? ''), 0, 200),
                mb_substr((string) $data['image_url'], 0, 500), mb_substr((string) ($data['link_url'] ?? ''), 0, 500),
                mb_substr((string) ($data['position'] ?? 'home'), 0, 40), (int) ($data['sort_order'] ?? 0),
                array_key_exists('status', $data) ? Validator::integer($data['status'], 'status', 0, 1) : 1,
                Validator::nullableDateTime($data['start_at'] ?? null, 'start_at'),
                Validator::nullableDateTime($data['end_at'] ?? null, 'end_at'),
            ]
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'banner', 'create', $id);
        return Response::success(['banner_id' => $id], '轮播图创建成功', 201);
    }

    public static function updateBanner(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $id = (int) $params['banner_id'];
        $banner = Database::one('SELECT * FROM banners WHERE id = ? AND admin_id = ? AND app_id = ?', [$id, (int) $admin['id'], $appId]);
        if ($banner === null) {
            throw new HttpException('轮播图不存在', 404, 404);
        }
        $data = $request->all();
        Database::execute(
            'UPDATE banners SET title = ?, image_url = ?, link_url = ?, position = ?, sort_order = ?, status = ?,
             start_at = ?, end_at = ?, updated_at = NOW() WHERE id = ?',
            [
                mb_substr((string) ($data['title'] ?? $banner['title']), 0, 200),
                mb_substr((string) ($data['image_url'] ?? $banner['image_url']), 0, 500),
                mb_substr((string) ($data['link_url'] ?? $banner['link_url']), 0, 500),
                mb_substr((string) ($data['position'] ?? $banner['position']), 0, 40),
                (int) ($data['sort_order'] ?? $banner['sort_order']),
                array_key_exists('status', $data) ? Validator::integer($data['status'], 'status', 0, 1) : (int) $banner['status'],
                array_key_exists('start_at', $data) ? Validator::nullableDateTime($data['start_at'], 'start_at') : $banner['start_at'],
                array_key_exists('end_at', $data) ? Validator::nullableDateTime($data['end_at'], 'end_at') : $banner['end_at'],
                $id,
            ]
        );
        return Response::success([], '轮播图修改成功');
    }

    public static function deleteBanner(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        Database::execute('DELETE FROM banners WHERE id = ? AND admin_id = ? AND app_id = ?', [
            (int) $params['banner_id'], (int) $admin['id'], $appId,
        ]);
        return Response::success([], '轮播图已删除');
    }

    public static function remoteConfigs(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        return Response::success(['items' => Database::all(
            'SELECT id, config_key, config_value, value_type, description, status, updated_at
             FROM remote_configs WHERE admin_id = ? AND app_id = ? ORDER BY config_key',
            [(int) $admin['id'], $appId]
        )]);
    }

    public static function saveRemoteConfigs(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $items = $request->input('configs');
        if ($items === null) {
            $items = [[
                'config_key' => $request->input('config_key'),
                'config_value' => $request->input('config_value'),
                'value_type' => $request->input('value_type', 'string'),
                'description' => $request->input('description', ''),
                'status' => $request->input('status', 1),
            ]];
        }
        if (!is_array($items) || $items === []) {
            throw new HttpException('configs 必须是非空数组', 0, 422);
        }
        foreach ($items as $item) {
            $key = trim((string) ($item['config_key'] ?? ''));
            if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,99}$/', $key) !== 1) {
                throw new HttpException('config_key 格式错误', 0, 422);
            }
            $value = $item['config_value'] ?? '';
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            Database::execute(
                'INSERT INTO remote_configs
                 (admin_id, app_id, config_key, config_value, value_type, description, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), value_type = VALUES(value_type),
                 description = VALUES(description), status = VALUES(status), updated_at = NOW()',
                [
                    (int) $admin['id'], $appId, $key, (string) $value,
                    mb_substr((string) ($item['value_type'] ?? 'string'), 0, 20),
                    mb_substr((string) ($item['description'] ?? ''), 0, 255),
                    (int) ($item['status'] ?? 1),
                ]
            );
        }
        return Response::success([], '远程配置保存成功');
    }

    private static function notice(int $adminId, int $appId, int $noticeId): array
    {
        $notice = Database::one(
            'SELECT * FROM notices WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL',
            [$noticeId, $adminId, $appId]
        );
        if ($notice === null) {
            throw new HttpException('公告不存在', 404, 404);
        }
        return $notice;
    }
}
