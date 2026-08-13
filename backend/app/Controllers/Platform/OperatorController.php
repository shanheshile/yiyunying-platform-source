<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Platform;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Password;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Validator;
use Yiyunying\Services\PlatformService;
use Yiyunying\Services\RolePermissionService;
use Yiyunying\Services\EntitlementDurationService;
use Yiyunying\Services\SettingDescriptorService;

final class OperatorController
{
    public static function index(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireLevelOne($actor);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['p.parent_id = ?', 'p.level = 2', 'p.deleted_at IS NULL'];
        $query = [(int) $actor['id']];
        if (trim((string) $request->input('keyword', '')) !== '') {
            $where[] = '(p.account LIKE ? OR p.nickname LIKE ? OR p.platform_key LIKE ?)';
            $keyword = '%' . trim((string) $request->input('keyword')) . '%';
            array_push($query, $keyword, $keyword, $keyword);
        }
        if ($request->input('status') !== null && $request->input('status') !== '') {
            $where[] = 'p.status = ?';
            $query[] = (int) $request->input('status');
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM platform_accounts p WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM admins a WHERE a.platform_id = p.id AND a.status <> -1) AS admin_count,
                    (SELECT COUNT(*) FROM apps ap INNER JOIN admins a2 ON a2.id = ap.admin_id
                     WHERE a2.platform_id = p.id AND ap.deleted_at IS NULL) AS app_count
             FROM platform_accounts p WHERE {$whereSql} ORDER BY p.id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        foreach ($items as &$item) {
            unset($item['password_hash']);
            $item['balance'] = (int) $item['integral'];
            unset($item['integral']);
        }
        unset($item);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function create(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireLevelOne($actor);
        $data = $request->all();
        Validator::required($data, ['account', 'password']);
        $account = Validator::string($data['account'], 'account', 3, 64);
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $account) !== 1) {
            throw new HttpException('account 格式错误', 0, 422);
        }
        if (Database::one('SELECT id FROM platform_accounts WHERE account = ?', [$account])) {
            throw new HttpException('平台账号已存在', 0, 409);
        }
        $password = Password::assertAcceptable((string) $data['password']);
        $days = max(1, (int) ($data['membership_days'] ?? PlatformService::setting(
            (int) $actor['id'], 'operator_free_trial_days', 3
        )));
        $permissions = $data['permissions'] ?? null;
        if ($permissions !== null && !is_array($permissions)) {
            throw new HttpException('permissions 必须是对象', 0, 422);
        }
        $platformKey = self::platformKey($data['platform_key'] ?? null);
        $operatorId = Database::transaction(static function () use (
            $request, $actor, $data, $account, $password, $days, $permissions, $platformKey
        ): int {
            $id = Database::insert(
                'INSERT INTO platform_accounts
                 (parent_id, created_by_platform_id, level, platform_key, account, password_hash,
                  nickname, avatar, email, phone, status, membership_level, membership_started_at,
                  membership_expired_at, admin_quota, integral, access_start_time, access_end_time,
                  allowed_weekdays, permissions_json, register_ip, created_at, updated_at)
                 VALUES (?, ?, 2, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    (int) $actor['id'], (int) $actor['id'], $platformKey, $account, Password::hash($password),
                    mb_substr((string) ($data['nickname'] ?? $account), 0, 100),
                    mb_substr((string) ($data['avatar'] ?? ''), 0, 500),
                    trim((string) ($data['email'] ?? '')) ?: null,
                    mb_substr(trim((string) ($data['phone'] ?? '')), 0, 40) ?: null,
                    mb_substr((string) ($data['membership_level'] ?? 'authorized'), 0, 40),
                    date('Y-m-d H:i:s', time() + $days * 86400),
                    max(0, (int) ($data['admin_quota'] ?? PlatformService::setting(
                        (int) $actor['id'], 'operator_free_admin_quota', 10
                    ))),
                    (int) ($data['balance'] ?? PlatformService::setting(
                        (int) $actor['id'], 'operator_free_balance', 15
                    )),
                    self::timeValue($data['access_start_time'] ?? null, 'access_start_time'),
                    self::timeValue($data['access_end_time'] ?? null, 'access_end_time'),
                    self::weekdays($data['allowed_weekdays'] ?? '1,2,3,4,5,6,7'),
                    $permissions === null ? null : json_encode($permissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $request->clientIp(),
                ]
            );
            PlatformService::seedDefaults($id);
            PlatformService::saveSettings($id, [
                'admin_free_trial_days' => max(0, (int) ($data['admin_free_trial_days']
                    ?? PlatformService::setting((int) $actor['id'], 'admin_free_trial_days', 3))),
                'admin_free_app_quota' => max(0, (int) ($data['admin_free_app_quota']
                    ?? PlatformService::setting((int) $actor['id'], 'admin_free_app_quota', 1))),
                'admin_free_remote_document_quota' => max(0, (int) ($data['admin_free_remote_document_quota']
                    ?? PlatformService::setting((int) $actor['id'], 'admin_free_remote_document_quota', 3))),
                'admin_free_balance' => max(0, (int) ($data['admin_free_balance']
                    ?? PlatformService::setting((int) $actor['id'], 'admin_free_balance', 15))),
            ]);
            return $id;
        });
        $operator = PlatformService::ownedOperator($actor, $operatorId);
        PlatformService::log($request, $actor, 'operator', 'create', 'platform', $operatorId, null, PlatformService::publicData($operator));
        return Response::success([
            'operator' => PlatformService::publicData($operator),
            'settings' => PlatformService::settings($operatorId),
        ], '2 级授权平台创建成功', 201);
    }

    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $operator = PlatformService::ownedOperator($actor, (int) $params['operator_id']);
        $data = PlatformService::publicData($operator);
        $data['permissions'] = json_decode((string) ($operator['permissions_json'] ?? ''), true);
        $data['settings'] = PlatformService::settings((int) $operator['id']);
        $data['chat_polling_policy'] = PlatformService::chatPollingPolicy((int) $operator['id']);
        $data['message_recall_policy'] = PlatformService::messageRecallPolicy((int) $operator['id']);
        $data['counts'] = self::counts((int) $operator['id']);
        return Response::success(['operator' => $data]);
    }

    public static function update(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $operator = PlatformService::ownedOperator($actor, (int) $params['operator_id']);
        $data = $request->all();
        $account = trim((string) ($data['account'] ?? $operator['account']));
        if ($account !== (string) $operator['account'] && Database::one('SELECT id FROM platform_accounts WHERE account = ?', [$account])) {
            throw new HttpException('平台账号已存在', 0, 409);
        }
        $expiredAt = array_key_exists('membership_expired_at', $data)
            ? Validator::nullableDateTime($data['membership_expired_at'], 'membership_expired_at')
            : $operator['membership_expired_at'];
        $permissions = array_key_exists('permissions', $data)
            ? json_encode((array) $data['permissions'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $operator['permissions_json'];
        Database::execute(
            'UPDATE platform_accounts SET account = ?, nickname = ?, avatar = ?, email = ?, phone = ?,
             membership_level = ?, membership_expired_at = ?, admin_quota = ?, integral = ?,
             access_start_time = ?, access_end_time = ?, allowed_weekdays = ?,
             permissions_json = ?, updated_at = NOW() WHERE id = ?',
            [
                $account, mb_substr((string) ($data['nickname'] ?? $operator['nickname']), 0, 100),
                mb_substr((string) ($data['avatar'] ?? $operator['avatar']), 0, 500),
                trim((string) ($data['email'] ?? $operator['email'])) ?: null,
                mb_substr(trim((string) ($data['phone'] ?? $operator['phone'])), 0, 40) ?: null,
                mb_substr((string) ($data['membership_level'] ?? $operator['membership_level']), 0, 40),
                $expiredAt, max(0, (int) ($data['admin_quota'] ?? $operator['admin_quota'])),
                (int) ($data['balance'] ?? $operator['integral']),
                self::timeValue($data['access_start_time'] ?? $operator['access_start_time'], 'access_start_time'),
                self::timeValue($data['access_end_time'] ?? $operator['access_end_time'], 'access_end_time'),
                self::weekdays($data['allowed_weekdays'] ?? $operator['allowed_weekdays']),
                $permissions, (int) $operator['id'],
            ]
        );
        $after = Database::one('SELECT * FROM platform_accounts WHERE id = ?', [(int) $operator['id']]) ?? $operator;
        PlatformService::log($request, $actor, 'operator', 'update', 'platform', (int) $operator['id'], PlatformService::publicData($operator), PlatformService::publicData($after));
        return Response::success(['operator' => PlatformService::publicData($after)], '授权平台已更新');
    }

    public static function resetPassword(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $operator = PlatformService::ownedOperator($actor, (int) $params['operator_id']);
        $password = Password::assertAcceptable((string) $request->input('new_password', ''), 'new_password');
        Database::transaction(static function () use ($operator, $password): void {
            Database::execute('UPDATE platform_accounts SET password_hash = ?, updated_at = NOW() WHERE id = ?', [
                Password::hash($password), (int) $operator['id'],
            ]);
            Database::execute('UPDATE platform_tokens SET revoked_at = NOW() WHERE platform_id = ?', [(int) $operator['id']]);
        });
        PlatformService::log($request, $actor, 'operator', 'password_reset', 'platform', (int) $operator['id']);
        return Response::success([], '授权平台密码已重置');
    }

    public static function entitlement(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireLevelOne($actor);
        $operator = PlatformService::ownedOperator($actor, (int) $params['operator_id']);
        $after = self::applyEntitlement($operator, $request->all());
        PlatformService::log($request, $actor, 'operator', 'entitlement_adjust', 'platform', (int) $operator['id'],
            PlatformService::publicData($operator), PlatformService::publicData($after));
        return Response::success([
            'operator' => PlatformService::publicData($after),
            'settings' => PlatformService::settings((int) $after['id']),
        ], '授权平台权益已调整');
    }

    public static function batchEntitlement(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireLevelOne($actor);
        $ids = self::targetIds($request->input('target_ids', []));
        $operators = [];
        foreach ($ids as $id) $operators[] = PlatformService::ownedOperator($actor, $id);
        $updated = [];
        foreach ($operators as $operator) {
            $after = self::applyEntitlement($operator, $request->all());
            $updated[] = ['id' => (int) $after['id'], 'account' => (string) $after['account']];
        }
        PlatformService::log($request, $actor, 'operator', 'batch_entitlement_adjust', 'platform', null, null, [
            'target_ids' => $ids, 'count' => count($updated),
        ]);
        return Response::success(['updated' => $updated, 'count' => count($updated)], '已批量调整授权平台权益');
    }

    public static function ban(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::status($request, $params, false);
    }

    public static function unban(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return self::status($request, $params, true);
    }

    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $operator = PlatformService::ownedOperator($actor, (int) $params['operator_id']);
        if ((string) $request->input('confirm', '') !== 'DELETE') {
            throw new HttpException('请传 confirm=DELETE 确认连带删除平台及全部下级数据', 0, 422);
        }
        $counts = self::counts((int) $operator['id']);
        PlatformService::log($request, $actor, 'operator', 'hard_delete', 'platform', (int) $operator['id'], [
            'operator' => PlatformService::publicData($operator), 'counts' => $counts,
        ]);
        Database::execute('DELETE FROM platform_accounts WHERE id = ?', [(int) $operator['id']]);
        return Response::success(['deleted_counts' => $counts], '授权平台及全部下级数据已连带删除');
    }

    public static function settings(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $operator = PlatformService::ownedOperator($actor, (int) $params['operator_id']);
        $settings = PlatformService::settings((int) $operator['id']);
        return Response::success([
            'settings' => $settings,
            'setting_descriptors' => SettingDescriptorService::describe($settings),
            'chat_polling_policy' => PlatformService::chatPollingPolicy((int) $operator['id']),
            'message_recall_policy' => PlatformService::messageRecallPolicy((int) $operator['id']),
        ]);
    }

    public static function saveSettings(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $operator = PlatformService::ownedOperator($actor, (int) $params['operator_id']);
        $settings = $request->input('settings', []);
        if (!is_array($settings) || $settings === []) {
            throw new HttpException('settings 必须是非空对象', 0, 422);
        }
        $before = PlatformService::settings((int) $operator['id']);
        $after = PlatformService::saveSettings((int) $operator['id'], $settings, true);
        PlatformService::log($request, $actor, 'operator', 'settings_update', 'platform', (int) $operator['id'], $before, $after);
        return Response::success([
            'settings' => $after,
            'setting_descriptors' => SettingDescriptorService::describe($after),
            'chat_polling_policy' => PlatformService::chatPollingPolicy((int) $operator['id']),
            'message_recall_policy' => PlatformService::messageRecallPolicy((int) $operator['id']),
        ], '授权平台配置已保存');
    }

    public static function permissionList(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireLevelOne($actor);
        $operator = PlatformService::ownedOperator($actor, (int) $params['operator_id']);
        return Response::success(RolePermissionService::platformPayload($operator, (int) $actor['level']));
    }

    public static function savePermissions(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireLevelOne($actor);
        $operator = PlatformService::ownedOperator($actor, (int) $params['operator_id']);
        $input = $request->input('permissions', []);
        if (!is_array($input)) {
            throw new HttpException('权限配置必须是对象', 0, 422);
        }
        $changes = RolePermissionService::normalizePlatformInput($input);
        $before = RolePermissionService::platformPayload($operator, (int) $actor['level']);
        $stored = json_decode((string) ($operator['permissions_json'] ?? ''), true);
        $stored = is_array($stored) ? $stored : [];
        foreach ($changes as $code => $value) {
            $stored[$code] = $value;
        }
        Database::execute(
            'UPDATE platform_accounts SET permissions_json = ?, updated_at = NOW() WHERE id = ?',
            [json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $operator['id']]
        );
        $updated = Database::one('SELECT * FROM platform_accounts WHERE id = ?', [(int) $operator['id']]) ?? $operator;
        $after = RolePermissionService::platformPayload($updated, (int) $actor['level']);
        PlatformService::log($request, $actor, 'operator', 'permissions_update', 'platform', (int) $operator['id'], $before, $after);
        return Response::success($after, '授权平台权限已保存');
    }
    private static function status(Request $request, array $params, bool $enable): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $operator = PlatformService::ownedOperator($actor, (int) $params['operator_id']);
        Database::transaction(static function () use ($request, $operator, $enable): void {
            Database::execute(
                'UPDATE platform_accounts SET status = ?, disabled_reason = ?, updated_at = NOW() WHERE id = ?',
                [$enable ? 1 : 0, $enable ? '' : mb_substr((string) $request->input('reason', ''), 0, 500), (int) $operator['id']]
            );
            if (!$enable) {
                Database::execute('UPDATE platform_tokens SET revoked_at = NOW() WHERE platform_id = ?', [(int) $operator['id']]);
                Database::execute(
                    'UPDATE admin_tokens SET revoked_at = NOW()
                     WHERE admin_id IN (SELECT id FROM admins WHERE platform_id = ?)',
                    [(int) $operator['id']]
                );
                Database::execute(
                    'UPDATE user_tokens SET revoked_at = NOW()
                     WHERE admin_id IN (SELECT id FROM admins WHERE platform_id = ?)',
                    [(int) $operator['id']]
                );
                Database::execute(
                    'UPDATE user_refresh_tokens SET revoked_at = NOW()
                     WHERE admin_id IN (SELECT id FROM admins WHERE platform_id = ?)',
                    [(int) $operator['id']]
                );
            }
        });
        PlatformService::log($request, $actor, 'operator', $enable ? 'unban' : 'ban', 'platform', (int) $operator['id']);
        return Response::success(['operator_id' => (int) $operator['id'], 'status' => $enable ? 1 : 0], $enable ? '授权平台已启用' : '授权平台已封禁');
    }

    private static function counts(int $platformId): array
    {
        return Database::one(
            'SELECT
                (SELECT COUNT(*) FROM admins WHERE platform_id = ?) AS admins,
                (SELECT COUNT(*) FROM apps ap INNER JOIN admins a ON a.id = ap.admin_id WHERE a.platform_id = ?) AS apps,
                (SELECT COUNT(*) FROM users u INNER JOIN admins a ON a.id = u.admin_id WHERE a.platform_id = ?) AS users,
                (SELECT COUNT(*) FROM documents d INNER JOIN admins a ON a.id = d.admin_id WHERE a.platform_id = ?) AS documents',
            [$platformId, $platformId, $platformId, $platformId]
        ) ?? [];
    }

    private static function applyEntitlement(array $operator, array $data): array
    {
        $type = trim((string) ($data['entitlement_type'] ?? ''));
        $operation = (string) ($data['operation'] ?? 'increase');
        $amount = (int) ($data['amount'] ?? 0);
        $settingsKey = match ($type) {
            'gift_membership' => 'admin_free_trial_days',
            'gift_app_quota' => 'admin_free_app_quota',
            'gift_document_quota' => 'admin_free_remote_document_quota',
            'gift_balance' => 'admin_free_balance',
            default => null,
        };
        if ($settingsKey !== null) {
            $current = (int) PlatformService::setting((int) $operator['id'], $settingsKey, 0);
            $next = (int) round($current + EntitlementDurationService::numericChange($operation, $amount, $current));
            if ($next < 0) throw new HttpException('赠送数量不能小于 0', 0, 422);
            PlatformService::saveSettings((int) $operator['id'], [$settingsKey => $next]);
            return Database::one('SELECT * FROM platform_accounts WHERE id = ?', [(int) $operator['id']]) ?? $operator;
        }
        return Database::transaction(static function () use ($operator, $data, $type, $operation, $amount): array {
            $row = Database::one('SELECT * FROM platform_accounts WHERE id = ? FOR UPDATE', [(int) $operator['id']]);
            if ($row === null) throw new HttpException('授权平台不存在', 404, 404);
            if ($type === 'vip') {
                $row['membership_expired_at'] = EntitlementDurationService::apply(
                    $row['membership_expired_at'] === null ? null : (string) $row['membership_expired_at'],
                    $operation, $amount, (string) ($data['duration_unit'] ?? 'day')
                );
                $row['membership_level'] = (string) ($data['membership_level'] ?? 'vip');
            } elseif ($type === 'balance' || $type === 'admin_quota') {
                $column = $type === 'balance' ? 'integral' : 'admin_quota';
                $next = (int) round((int) $row[$column]
                    + EntitlementDurationService::numericChange($operation, $amount, (int) $row[$column]));
                if ($next < 0) throw new HttpException(($type === 'balance' ? '余额' : '管理员额度') . '不能小于 0', 0, 422);
                $row[$column] = $next;
            } else {
                throw new HttpException('授权平台不支持该权益类型', 0, 422);
            }
            Database::execute(
                'UPDATE platform_accounts SET membership_level = ?, membership_expired_at = ?, admin_quota = ?, integral = ?, updated_at = NOW() WHERE id = ?',
                [$row['membership_level'], $row['membership_expired_at'], (int) $row['admin_quota'], (int) $row['integral'], (int) $row['id']]
            );
            return Database::one('SELECT * FROM platform_accounts WHERE id = ?', [(int) $row['id']]) ?? $row;
        });
    }

    private static function targetIds($value): array
    {
        if (!is_array($value)) throw new HttpException('请选择要调整的授权平台', 0, 422);
        $ids = array_values(array_unique(array_filter(array_map('intval', $value), static fn(int $id): bool => $id > 0)));
        if ($ids === [] || count($ids) > 500) throw new HttpException('每次请选择 1-500 个授权平台', 0, 422);
        return $ids;
    }

    private static function uniqueKey(): string
    {
        do {
            $key = 'platform_' . bin2hex(random_bytes(10));
        } while (Database::one('SELECT id FROM platform_accounts WHERE platform_key = ?', [$key]));
        return $key;
    }

    private static function platformKey($requested): string
    {
        $key = trim((string) $requested);
        if ($key === '') return self::uniqueKey();
        if (strlen($key) < 3 || strlen($key) > 80
            || preg_match('/^[A-Za-z0-9_.-]+$/', $key) !== 1) {
            throw new HttpException('platform_key 只能包含字母、数字、点、下划线和横线，长度 3-80', 0, 422);
        }
        if (Database::one('SELECT id FROM platform_accounts WHERE platform_key = ?', [$key])) {
            throw new HttpException('平台标识已存在', 0, 409);
        }
        return $key;
    }

    private static function timeValue($value, string $field): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value) !== 1) {
            throw new HttpException($field . ' 必须是 HH:mm 或 HH:mm:ss', 0, 422);
        }
        return strlen($value) === 5 ? $value . ':00' : $value;
    }

    private static function weekdays($value): string
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $days = array_values(array_unique(array_map('intval', $items)));
        sort($days);
        if ($days === [] || array_filter($days, static fn (int $day): bool => $day < 1 || $day > 7) !== []) {
            throw new HttpException('allowed_weekdays 只能包含 1-7', 0, 422);
        }
        return implode(',', $days);
    }
}
