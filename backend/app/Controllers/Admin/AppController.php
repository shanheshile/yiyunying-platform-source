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
use Yiyunying\Services\GovernanceService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\SettingDescriptorService;

final class AppController
{
    public static function index(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['a.admin_id = ?', 'a.deleted_at IS NULL'];
        $params = [(int) $admin['id']];

        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(a.name LIKE ? OR a.app_key LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }
        if ($request->input('status') !== null && $request->input('status') !== '') {
            $where[] = 'a.status = ?';
            $params[] = (int) $request->input('status');
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM apps a WHERE {$whereSql}", $params)['total'] ?? 0);
        $items = Database::all(
            "SELECT a.*,
                    (SELECT COUNT(*) FROM users u WHERE u.app_id = a.id AND u.deleted_at IS NULL) AS user_count,
                    (SELECT COUNT(*) FROM documents d WHERE d.app_id = a.id AND d.deleted_at IS NULL) AS document_count
             FROM apps a WHERE {$whereSql}
             ORDER BY a.id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        foreach ($items as &$item) {
            unset($item['app_secret_hash']);
            $item['id'] = (int) $item['id'];
            $item['status'] = (int) $item['status'];
            $item['user_count'] = (int) $item['user_count'];
            $item['document_count'] = (int) $item['document_count'];
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function create(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $data = $request->all();
        Validator::required($data, ['name']);
        $name = Validator::string($data['name'], 'name', 2, 100);
        $description = mb_substr(trim((string) ($data['description'] ?? '')), 0, 1000);
        $logo = mb_substr(trim((string) ($data['logo'] ?? '')), 0, 500);
        $appKey = self::uniqueAppKey();
        $appSecret = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');

        $appId = Database::transaction(static function () use ($admin, $name, $description, $logo, $appKey, $appSecret): int {
            AdminAccessService::requireAppQuota($admin, true);
            $id = Database::insert(
                'INSERT INTO apps
                 (admin_id, app_key, app_secret_hash, name, logo, description, status, version, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())',
                [(int) $admin['id'], $appKey, hash('sha256', $appSecret), $name, $logo, $description, '1.0.0']
            );
            AppService::seedDefaults((int) $admin['id'], $id);
            return $id;
        });

        $app = AppService::owned((int) $admin['id'], $appId);
        unset($app['app_secret_hash']);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'app', 'create', $appId, null, $app);
        return Response::success([
            'app' => $app,
            'app_secret' => $appSecret,
            'secret_notice' => 'app_secret 只在创建时返回一次，请保存在服务端。',
        ], '应用创建成功', 201);
    }

    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $app = AppService::owned((int) $admin['id'], (int) $params['app_id']);
        unset($app['app_secret_hash']);
        $app['configured_settings'] = AppService::settings((int) $app['id']);
        $app['settings'] = AppService::effectiveSettings((int) $app['id']);
        $app['chat_polling_policy'] = AppService::chatPollingPolicy((int) $app['id'], $app['configured_settings']);
        $app['message_recall_policy'] = AppService::messageRecallPolicy((int) $app['id'], $app['configured_settings']);
        return Response::success(['app' => $app]);
    }

    public static function update(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $before = AppService::owned((int) $admin['id'], $appId);
        $data = $request->all();
        $fields = [];
        $values = [];

        foreach (['name' => 100, 'logo' => 500, 'description' => 1000, 'version' => 40] as $field => $max) {
            if (array_key_exists($field, $data)) {
                $value = trim((string) $data[$field]);
                if ($field === 'name' && mb_strlen($value) < 2) {
                    throw new HttpException('name 至少 2 个字符', 0, 422);
                }
                $fields[] = "{$field} = ?";
                $values[] = mb_substr($value, 0, $max);
            }
        }
        if ($fields === []) {
            throw new HttpException('没有可修改的字段', 0, 422);
        }
        $values[] = $appId;
        $values[] = (int) $admin['id'];
        Database::execute(
            'UPDATE apps SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ? AND admin_id = ?',
            $values
        );
        $after = AppService::owned((int) $admin['id'], $appId);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'app', 'update', $appId, $before, $after);
        unset($after['app_secret_hash']);
        return Response::success(['app' => $after], '应用修改成功');
    }

    public static function enable(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::setStatus($request, $params, 1, 'enable');
    }

    public static function disable(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::setStatus($request, $params, 0, 'disable');
    }

    public static function settings(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $app = AppService::owned((int) $admin['id'], (int) $params['app_id']);
        $configured = AppService::settings((int) $app['id']);
        $settings = AppService::effectiveSettings((int) $app['id']);
        return Response::success([
            'app_id' => (int) $app['id'],
            'settings' => $settings,
            'configured_settings' => $configured,
            'setting_descriptors' => SettingDescriptorService::describe($settings),
            'chat_polling_policy' => AppService::chatPollingPolicy((int) $app['id'], $configured),
            'message_recall_policy' => AppService::messageRecallPolicy((int) $app['id'], $configured),
        ]);
    }

    public static function saveSettings(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $settings = $request->input('settings', $request->input('settings_json'));
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }
        if (!is_array($settings) || $settings === []) {
            throw new HttpException('settings 必须是非空对象', 0, 422);
        }
        $before = AppService::settings($appId);
        if (array_key_exists('chat_poll_interval_ms', $settings)
            && (int) $settings['chat_poll_interval_ms'] !== (int) ($before['chat_poll_interval_ms'] ?? 5000)) {
            $settings['chat_poll_interval_ms'] = AdminAccessService::validateChatPollInterval(
                (int) $admin['platform_id'],
                (int) $settings['chat_poll_interval_ms']
            );
        }
        if (array_key_exists('message_recall_seconds', $settings)
            && !array_key_exists('message_recall_inherit', $settings)) {
            $settings['message_recall_inherit'] = false;
        }
        if (array_key_exists('message_recall_seconds', $settings)
            || array_key_exists('message_recall_inherit', $settings)) {
            $seconds = (int) ($settings['message_recall_seconds'] ?? ($before['message_recall_seconds'] ?? 120));
            $inherit = (bool) ($settings['message_recall_inherit'] ?? ($before['message_recall_inherit'] ?? true));
            $changed = $seconds !== (int) ($before['message_recall_seconds'] ?? 120)
                || $inherit !== (bool) ($before['message_recall_inherit'] ?? true);
            if ($changed) AdminAccessService::validateMessageRecallPolicy((int) $admin['platform_id'], $seconds, $inherit);
        }
        $configured = AppService::saveSettings((int) $admin['id'], $appId, $settings);
        $after = AppService::effectiveSettings($appId);
        $policy = AppService::chatPollingPolicy($appId, $configured);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'app_setting', 'save', $appId, $before, $configured);
        return Response::success([
            'settings' => $after,
            'configured_settings' => $configured,
            'setting_descriptors' => SettingDescriptorService::describe($after),
            'chat_polling_policy' => $policy,
            'message_recall_policy' => AppService::messageRecallPolicy($appId, $configured),
        ], '配置保存成功');
    }

    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $app = AppService::owned((int) $admin['id'], $appId);
        $confirm = trim((string) $request->input('confirm', ''));
        if ($confirm !== 'DELETE' && $confirm !== (string) $app['name']) {
            throw new HttpException('请传 confirm=DELETE 或应用名称确认删除', 0, 422);
        }
        Database::execute(
            'UPDATE apps SET status = -1, deleted_at = NOW(), disabled_reason = ?, updated_at = NOW()
             WHERE id = ? AND admin_id = ?',
            ['管理员删除应用', $appId, (int) $admin['id']]
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'app', 'delete', $appId, $app);
        return Response::success([], '应用已删除');
    }

    public static function resetSecret(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $secret = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
        Database::execute(
            'UPDATE apps SET app_secret_hash = ?, updated_at = NOW() WHERE id = ? AND admin_id = ?',
            [hash('sha256', $secret), $appId, (int) $admin['id']]
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'app', 'secret_reset', $appId);
        return Response::success([
            'app_secret' => $secret,
            'secret_notice' => '新 app_secret 只返回一次，请保存在服务端。',
        ], '应用密钥已重置');
    }

    public static function features(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        return Response::success([
            'features' => AppService::features($appId),
            'effective_features' => GovernanceService::effectiveFeatures($appId),
        ]);
    }

    public static function saveFeatures(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $payload = $request->all();
        $items = $payload['features'] ?? null;
        if ($items === null) {
            $item = [
                'feature_code' => $request->input('feature_code'),
                'enabled' => $request->input('enabled'),
            ];
            if (array_key_exists('config', $payload)) {
                $item['config'] = $payload['config'];
            } elseif (array_key_exists('config_json', $payload)) {
                $item['config'] = $payload['config_json'];
            }
            $items = [$item];
        }
        if (!is_array($items) || $items === []) {
            throw new HttpException('features 必须是非空数组', 0, 422);
        }
        if (!array_is_list($items)) {
            $normalized = [];
            foreach ($items as $featureCode => $value) {
                if (is_array($value)) {
                    $normalized[] = array_merge($value, ['feature_code' => (string) $featureCode]);
                } else {
                    $normalized[] = [
                        'feature_code' => (string) $featureCode,
                        'enabled' => $value,
                    ];
                }
            }
            $items = $normalized;
        }
        foreach ($items as $item) {
            if (!is_array($item) || trim((string) ($item['feature_code'] ?? '')) === '') {
                throw new HttpException('每个功能项必须包含 feature_code', 0, 422);
            }
            $enabled = Validator::boolean($item['enabled'] ?? true, 'enabled');
            $configProvided = array_key_exists('config', $item) || array_key_exists('config_json', $item);
            $config = array_key_exists('config', $item) ? $item['config'] : ($item['config_json'] ?? null);
            if (is_string($config) && $config !== '') {
                $config = json_decode($config, true);
            }
            GovernanceService::assertFeatureMutable($appId, trim((string) $item['feature_code']));
            AppService::saveFeature(
                (int) $admin['id'],
                $appId,
                trim((string) $item['feature_code']),
                $enabled,
                is_array($config) ? $config : null,
                $configProvided
            );
        }
        $after = AppService::features($appId);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'app_feature', 'save', $appId, null, $after);
        return Response::success([
            'features' => $after,
            'effective_features' => GovernanceService::effectiveFeatures($appId),
        ], '功能开关保存成功');
    }

    public static function domains(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        return Response::success(['items' => Database::all(
            'SELECT id, domain, status, created_at FROM app_domains WHERE admin_id = ? AND app_id = ? ORDER BY id DESC',
            [(int) $admin['id'], $appId]
        )]);
    }

    public static function createDomain(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        $domain = strtolower(trim((string) $request->input('domain', '')));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');
        if ($domain === '' || mb_strlen($domain) > 255) {
            throw new HttpException('domain 格式错误', 0, 422);
        }
        Database::execute(
            'INSERT INTO app_domains (admin_id, app_id, domain, status, created_at)
             VALUES (?, ?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE status = 1',
            [(int) $admin['id'], $appId, $domain]
        );
        return Response::success(['domain' => $domain], '域名绑定成功', 201);
    }

    public static function deleteDomain(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        Database::execute(
            'DELETE FROM app_domains WHERE id = ? AND admin_id = ? AND app_id = ?',
            [(int) $params['domain_id'], (int) $admin['id'], $appId]
        );
        return Response::success([], '域名已删除');
    }

    private static function setStatus(Request $request, array $params, int $status, string $action): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $before = AppService::owned((int) $admin['id'], $appId);
        $reason = $status === 0 ? mb_substr(trim((string) $request->input('reason', '')), 0, 255) : null;
        Database::execute(
            'UPDATE apps SET status = ?, disabled_reason = ?, updated_at = NOW() WHERE id = ? AND admin_id = ?',
            [$status, $reason, $appId, (int) $admin['id']]
        );
        $after = AppService::owned((int) $admin['id'], $appId);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'app', $action, $appId, $before, $after);
        unset($after['app_secret_hash']);
        return Response::success(['app' => $after], $status === 1 ? '应用已启用' : '应用已停用');
    }

    private static function uniqueAppKey(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $key = 'yy_' . bin2hex(random_bytes(10));
            if (Database::one('SELECT id FROM apps WHERE app_key = ?', [$key]) === null) {
                return $key;
            }
        }
        throw new \RuntimeException('无法生成唯一 app_key');
    }
}
