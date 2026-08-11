<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\PublicApi;

use Yiyunying\Core\Database;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AppService;
use Yiyunying\Services\UploadLimitService;
use Yiyunying\Services\IdentityService;
use Yiyunying\Services\MessageMediaService;
use Yiyunying\Services\AdminBrandingService;

final class BootstrapController
{
    private const PUBLIC_SETTINGS = [
        'registration_enabled',
        'registration_nickname_enabled',
        'registration_nickname_required',
        'registration_email_enabled',
        'registration_email_required',
        'registration_phone_enabled',
        'registration_phone_required',
        'login_enabled',
        'document_enabled',
        'card_redeem_enabled',
        'card_login_enabled',
        'public_app_statistics_enabled',
        'heartbeat_online_seconds',
        'initial_document_credit',
        'document_create_cost',
        'document_max_count',
        'document_share_enabled',
        'password_reset_enabled',
        'profile_edit_enabled',
        'profile_public_default',
        'sign_enabled',
        'invite_enabled',
        'private_message_enabled',
        'upload_max_bytes',
        'upload_image_max_bytes',
        'upload_video_max_bytes',
        'upload_audio_max_bytes',
        'upload_file_max_bytes',
        'lottery_daily_limit',
        'wallet_transfer_enabled',
        'wallet_transfer_max',
        'economy_primary_asset',
        'user_free_vip_days',
        'user_login_vip_only',
        'document_credit_separate',
        'balance_document_purchase_enabled',
        'document_credit_balance_price',
        'balance_membership_purchase_enabled',
        'vip_day_balance_price',
        'balance_activity_enabled',
        'chat_poll_interval_ms',
        'message_recall_seconds',
        'group_restore_days',
    ];

    public static function app(Request $request): \Yiyunying\Core\ApiResponse
    {
        $app = self::resolveApp($request);
        return Response::success(['app' => self::publicApp($app)]);
    }

    public static function bootstrap(Request $request): \Yiyunying\Core\ApiResponse
    {
        $app = self::resolveApp($request);
        return Response::success([
            'app' => self::publicApp($app),
            'settings' => self::publicSettings((int) $app['id']),
            'registration_policy' => IdentityService::registrationPolicy((int) $app['id']),
            'upload_limits' => UploadLimitService::publicLimits((int) $app['id']),
            'chat_polling_policy' => AppService::chatPollingPolicy((int) $app['id']),
            'message_recall_policy' => AppService::messageRecallPolicy((int) $app['id']),
            'features' => AppService::features((int) $app['id']),
            'notices' => self::activeNotices((int) $app['admin_id'], (int) $app['id'], null, 10),
            'banners' => self::activeBanners((int) $app['admin_id'], (int) $app['id'], null),
            'remote_configs' => self::remoteConfigs((int) $app['admin_id'], (int) $app['id']),
            'latest_version' => self::latestVersion((int) $app['admin_id'], (int) $app['id']),
            'branding' => AdminBrandingService::get((int) $app['admin_id']),
            'server_time' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function notices(Request $request): \Yiyunying\Core\ApiResponse
    {
        $app = self::resolveApp($request);
        $type = trim((string) $request->input('type', ''));
        $limit = min(50, max(1, (int) $request->input('limit', 20)));
        return Response::success([
            'items' => self::activeNotices(
                (int) $app['admin_id'],
                (int) $app['id'],
                $type === '' ? null : $type,
                $limit
            ),
        ]);
    }

    public static function version(Request $request): \Yiyunying\Core\ApiResponse
    {
        $app = self::resolveApp($request);
        $latest = self::latestVersion((int) $app['admin_id'], (int) $app['id']);
        $currentCode = max(0, (int) $request->input('version_code', 0));
        return Response::success([
            'current_version_code' => $currentCode,
            'update_available' => $latest !== null && (int) $latest['version_code'] > $currentCode,
            'latest_version' => $latest,
        ]);
    }

    public static function features(Request $request): \Yiyunying\Core\ApiResponse
    {
        $app = self::resolveApp($request);
        return Response::success(['features' => AppService::features((int) $app['id'])]);
    }

    public static function recordVisit(Request $request): \Yiyunying\Core\ApiResponse
    {
        $app = self::resolveApp($request);
        $visitorId = trim((string) $request->input('visitor_id', ''));
        $visitorSource = $visitorId === ''
            ? $request->clientIp() . "\0" . $request->userAgent()
            : 'client:' . mb_substr($visitorId, 0, 200);
        $visitorHash = hash('sha256', (int) $app['id'] . "\0" . $visitorSource);
        $source = mb_substr(trim((string) $request->input('source', 'app')), 0, 50);
        $path = mb_substr(trim((string) $request->input('path', '')), 0, 255);
        $ipHash = hash('sha256', (int) $app['id'] . "\0" . $request->clientIp());
        $affected = Database::execute(
            'INSERT INTO app_visit_events
             (admin_id, app_id, visitor_hash, visit_date, visit_count, source, last_path,
              last_ip_hash, last_user_agent, first_visited_at, last_visited_at)
             VALUES (?, ?, ?, CURDATE(), 1, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE visit_count = visit_count + 1, source = VALUES(source),
               last_path = VALUES(last_path), last_ip_hash = VALUES(last_ip_hash),
               last_user_agent = VALUES(last_user_agent), last_visited_at = NOW()',
            [
                (int) $app['admin_id'], (int) $app['id'], $visitorHash,
                $source === '' ? 'app' : $source, $path, $ipHash, mb_substr($request->userAgent(), 0, 500),
            ]
        );
        $isUniqueToday = $affected === 1;
        Database::execute(
            'INSERT INTO statistics_daily
             (admin_id, app_id, stat_date, app_views, unique_visitors, created_at, updated_at)
             VALUES (?, ?, CURDATE(), 1, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE app_views = app_views + 1,
               unique_visitors = unique_visitors + VALUES(unique_visitors), updated_at = NOW()',
            [(int) $app['admin_id'], (int) $app['id'], $isUniqueToday ? 1 : 0]
        );
        return Response::success([
            'recorded' => true,
            'unique_today' => $isUniqueToday,
            'server_time' => date('Y-m-d H:i:s'),
        ], '访问已记录');
    }

    public static function statistics(Request $request): \Yiyunying\Core\ApiResponse
    {
        $app = self::resolveApp($request);
        if (!AppService::setting((int) $app['id'], 'public_app_statistics_enabled', true)) {
            throw new \Yiyunying\Core\HttpException('当前应用未公开统计数据', 403, 403);
        }
        $periods = [
            'today' => 'visit_date = CURDATE()',
            'week' => 'visit_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)',
            'month' => 'visit_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)',
            'year' => 'visit_date >= DATE_SUB(CURDATE(), INTERVAL 364 DAY)',
            'total' => '1 = 1',
        ];
        $statistics = [];
        foreach ($periods as $key => $where) {
            $row = Database::one(
                "SELECT COALESCE(SUM(visit_count), 0) AS views,
                        COUNT(DISTINCT visitor_hash) AS visitors
                 FROM app_visit_events WHERE app_id = ? AND {$where}",
                [(int) $app['id']]
            );
            $statistics[$key] = [
                'views' => (int) ($row['views'] ?? 0),
                'visitors' => (int) ($row['visitors'] ?? 0),
            ];
        }
        $userCounts = Database::one(
            'SELECT COUNT(*) AS total_users,
                    SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END) AS today_new_users
             FROM users WHERE app_id = ? AND deleted_at IS NULL',
            [(int) $app['id']]
        );
        $onlineUsers = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM user_presence
             WHERE app_id = ? AND status = 'online' AND online_until > NOW()",
            [(int) $app['id']]
        )['total'] ?? 0);
        return Response::success([
            'app' => self::publicApp($app),
            'visits' => $statistics,
            'users' => [
                'total' => (int) ($userCounts['total_users'] ?? 0),
                'today_new' => (int) ($userCounts['today_new_users'] ?? 0),
                'online' => $onlineUsers,
            ],
            'server_time' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function banners(Request $request): \Yiyunying\Core\ApiResponse
    {
        $app = self::resolveApp($request);
        $position = trim((string) $request->input('position', ''));
        $where = [
            'admin_id = ?', 'app_id = ?', 'status = 1', 'display_enabled = 1', "audience_type = 'all'",
            '(start_at IS NULL OR start_at <= NOW())', '(end_at IS NULL OR end_at >= NOW())',
        ];
        $query = [(int) $app['admin_id'], (int) $app['id']];
        if ($position !== '') {
            $where[] = 'position = ?';
            $query[] = $position;
        }
        return Response::success(['items' => Database::all(
            'SELECT id, title, image_url, link_url, position, sort_order
             FROM banners WHERE ' . implode(' AND ', $where) . ' ORDER BY sort_order DESC, id DESC',
            $query
        )]);
    }

    public static function documentShare(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $share = Database::one(
            'SELECT s.*, d.title, d.content, d.content_type, d.word_count, d.updated_at,
                    a.app_key, a.name AS app_name, p.nickname AS author_name
             FROM document_shares s INNER JOIN documents d ON d.id = s.document_id
             INNER JOIN apps a ON a.id = s.app_id AND a.admin_id = s.admin_id
             LEFT JOIN user_profiles p ON p.user_id = s.user_id
             WHERE s.share_code = ? AND s.status = 1 AND d.status = 1 AND d.deleted_at IS NULL
               AND a.status = 1 AND a.deleted_at IS NULL
             LIMIT 1',
            [(string) $params['share_code']]
        );
        if ($share === null || ($share['expired_at'] !== null && strtotime((string) $share['expired_at']) < time())) {
            throw new \Yiyunying\Core\HttpException('分享不存在或已过期', 404, 404);
        }
        AppService::byKey((string) $share['app_key']);
        if ($share['password_hash'] !== null
            && !password_verify((string) $request->input('password', ''), (string) $share['password_hash'])) {
            throw new \Yiyunying\Core\HttpException('分享密码错误', 403, 403, ['password_required' => true]);
        }
        Database::execute('UPDATE document_shares SET view_count = view_count + 1 WHERE id = ?', [(int) $share['id']]);
        $request->setAttribute('admin_id', (int) $share['admin_id']);
        $request->setAttribute('app_id', (int) $share['app_id']);
        $documents = MessageMediaService::hydrate([[
            'id' => (int) $share['document_id'],
            'title' => $share['title'],
            'content' => $share['content'],
            'content_type' => $share['content_type'],
            'word_count' => (int) $share['word_count'],
            'author_name' => $share['author_name'],
            'app_name' => $share['app_name'],
            'updated_at' => $share['updated_at'],
        ]], 'note', (int) $share['app_id']);
        return Response::success(['document' => $documents[0]]);
    }

    private static function resolveApp(Request $request): array
    {
        $appKey = trim((string) ($request->header('x-app-key') ?? $request->input('app_key', '')));
        $app = AppService::byKey($appKey);
        $request->setAttribute('admin_id', (int) $app['admin_id']);
        $request->setAttribute('app_id', (int) $app['id']);
        return $app;
    }

    private static function publicApp(array $app): array
    {
        return [
            'id' => (int) $app['id'],
            'app_key' => $app['app_key'],
            'name' => $app['name'],
            'logo' => $app['logo'],
            'description' => $app['description'],
            'version' => $app['version'],
            'status' => (int) $app['status'],
        ];
    }

    private static function publicSettings(int $appId): array
    {
        return array_intersect_key(AppService::effectiveSettings($appId), array_flip(self::PUBLIC_SETTINGS));
    }

    private static function activeNotices(int $adminId, int $appId, ?string $type, int $limit): array
    {
        $where = [
            'admin_id = ?',
            'app_id = ?',
            'status = 1',
            'deleted_at IS NULL',
            '(start_at IS NULL OR start_at <= NOW())',
            '(end_at IS NULL OR end_at >= NOW())',
        ];
        $params = [$adminId, $appId];
        if ($type !== null) {
            $where[] = 'type = ?';
            $params[] = $type;
        }
        return Database::all(
            'SELECT id, title, content, type, is_popup, display_enabled, popup_frequency,
                    audience_type, start_at, end_at, created_at, updated_at
             FROM notices WHERE ' . implode(' AND ', $where) . " ORDER BY id DESC LIMIT {$limit}",
            $params
        );
    }

    private static function latestVersion(int $adminId, int $appId): ?array
    {
        return Database::one(
            'SELECT id, version_name, version_code, apk_url, update_content, force_update, created_at
             FROM app_versions
             WHERE admin_id = ? AND app_id = ? AND status = 1 AND deleted_at IS NULL
             ORDER BY version_code DESC, id DESC LIMIT 1',
            [$adminId, $appId]
        );
    }

    private static function activeBanners(int $adminId, int $appId, ?string $position): array
    {
        $where = [
            'admin_id = ?', 'app_id = ?', 'status = 1',
            '(start_at IS NULL OR start_at <= NOW())', '(end_at IS NULL OR end_at >= NOW())',
        ];
        $query = [$adminId, $appId];
        if ($position !== null && $position !== '') {
            $where[] = 'position = ?';
            $query[] = $position;
        }
        return Database::all(
            'SELECT id, title, image_url, link_url, position, sort_order FROM banners WHERE '
            . implode(' AND ', $where) . ' ORDER BY sort_order DESC, id DESC',
            $query
        );
    }

    private static function remoteConfigs(int $adminId, int $appId): array
    {
        $rows = Database::all(
            'SELECT config_key, config_value, value_type FROM remote_configs
             WHERE admin_id = ? AND app_id = ? AND status = 1 ORDER BY config_key',
            [$adminId, $appId]
        );
        $result = [];
        foreach ($rows as $row) {
            $value = (string) ($row['config_value'] ?? '');
            if ($row['value_type'] === 'bool') {
                $value = in_array(strtolower($value), ['1', 'true'], true);
            } elseif ($row['value_type'] === 'int') {
                $value = (int) $value;
            } elseif ($row['value_type'] === 'float') {
                $value = (float) $value;
            } elseif ($row['value_type'] === 'json') {
                $value = json_decode($value, true);
            }
            $result[$row['config_key']] = $value;
        }
        return $result;
    }
}
