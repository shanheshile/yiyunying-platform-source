<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Platform;

use PDO;
use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Password;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\PlatformService;

final class DataConsoleController
{
    /**
     * The generic console is deliberately read-only and fail-closed. New tables
     * never become visible merely because a migration created them; mutations
     * must go through their typed controller/service invariants instead.
     */
    private const READABLE_TABLES = [
        'platform_daily_statistics', 'statistics_daily',
        'notices', 'banners',
        'resource_categories', 'store_categories',
        'forum_plates', 'forum_categories', 'forum_tags',
        'software_update_policies', 'maintenance_policies', 'festival_theme_policies',
    ];
    private const WRITABLE_TABLES = [];

    private const HIDDEN_TABLES = [
        'platform_accounts', 'platform_tokens', 'platform_login_logs', 'platform_operation_logs',
        'platform_mail_settings',
        'admins', 'admin_tokens', 'admin_login_logs', 'admin_registration_logs', 'admin_operation_logs',
        'apps', 'app_api_keys',
        'users', 'user_tokens', 'user_refresh_tokens', 'user_login_logs', 'user_operation_logs',
        'captcha_challenges', 'password_reset_tokens', 'verification_codes',
        'identity_bindings', 'identity_unbind_requests',
        'card_batches', 'cards', 'card_redeem_logs', 'card_login_bindings',
        'document_shares', 'payment_channels', 'api_request_logs', 'system_error_logs',
    ];
    private const SENSITIVE_COLUMNS = [
        'password_hash', 'token_hash', 'app_secret_hash', 'secret', 'private_key',
        'smtp_password_ciphertext', 'key_hash', 'code_hash', 'identity_value',
        'identity_hash', 'device_hash', 'device_secret_hash',
    ];

    public static function tables(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = self::actor($request);
        $schema = (string) config('database.name');
        $rows = Database::all(
            'SELECT t.table_name, t.table_rows, t.create_time, t.update_time,
                    (SELECT COUNT(*) FROM information_schema.columns c WHERE c.table_schema = t.table_schema AND c.table_name = t.table_name) AS column_count
             FROM information_schema.tables t WHERE t.table_schema = ? AND t.table_type = ? ORDER BY t.table_name',
            [$schema, 'BASE TABLE']
        );
        $items = [];
        foreach ($rows as $row) {
            $table = (string) $row['table_name'];
            if (!in_array($table, self::READABLE_TABLES, true)
                || in_array($table, self::HIDDEN_TABLES, true)) {
                continue;
            }
            $items[] = [
                'id' => $table,
                'table' => $table,
                'table_name' => $table,
                'record_estimate' => (int) ($row['table_rows'] ?? 0),
                'column_count' => (int) $row['column_count'],
                'writable' => in_array($table, self::WRITABLE_TABLES, true),
                'sensitive' => false,
                'updated_at' => $row['update_time'],
            ];
        }
        PlatformService::log($request, $actor, 'data_console', 'tables', 'database', null);
        return Response::success(['items' => $items, 'table_count' => count($items)]);
    }

    public static function rows(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = self::actor($request); $table = self::table((string) $params['table']);
        if (!in_array($table, self::READABLE_TABLES, true)
            || in_array($table, self::HIDDEN_TABLES, true)) {
            throw new HttpException('该表未列入数据总控只读白名单', 403, 403);
        }
        $columns = self::columns($table); $safeColumns = self::publicColumns($columns);
        if ($safeColumns === []) throw new HttpException('该表没有允许通过数据总控读取的字段', 403, 403);
        $page = $request->page(); $limit = $request->limit(); $offset = ($page - 1) * $limit;
        [$where, $query] = self::filters($request, $safeColumns);
        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM `{$table}` WHERE {$whereSql}", $query)['total'] ?? 0);
        $order = isset($safeColumns['id']) ? '`id` DESC' : '`' . array_key_first($safeColumns) . '` ASC';
        $select = implode(', ', array_map(static fn(string $column): string => "`{$column}`", array_keys($safeColumns)));
        $items = Database::all("SELECT {$select} FROM `{$table}` WHERE {$whereSql} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}", $query);
        foreach ($items as &$item) self::redact($item); unset($item);
        PlatformService::log($request, $actor, 'data_console', 'rows', $table, null);
        return Response::success(array_merge(Pagination::data($items, $total, $page, $limit), ['columns' => array_values($safeColumns)]));
    }

    public static function create(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = self::actor($request); self::confirmWrite($actor, $request); $table = self::writableTable((string) $params['table']);
        $data = $request->input('data', []); if (!is_array($data) || $data === []) throw new HttpException('data 必须是非空对象', 0, 422);
        $clean = self::cleanData($table, $data, false); $columns = array_keys($clean);
        $sqlColumns = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $id = Database::insert("INSERT INTO `{$table}` ({$sqlColumns}) VALUES ({$placeholders})", array_values($clean));
        PlatformService::log($request, $actor, 'data_console', 'create', $table, $id, null, ['fields' => $columns]);
        return Response::success(['table' => $table, 'insert_id' => $id], '数据总控新增成功', 201);
    }

    public static function update(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = self::actor($request); self::confirmWrite($actor, $request); $table = self::writableTable((string) $params['table']); $id = (int) $params['row_id'];
        if ($id <= 0 || !isset(self::columns($table)['id'])) throw new HttpException('该表不支持按 id 修改', 0, 422);
        $data = $request->input('data', []); if (!is_array($data) || $data === []) throw new HttpException('data 必须是非空对象', 0, 422);
        $clean = self::cleanData($table, $data, true); unset($clean['id']);
        if ($table === 'platform_accounts' && $id === (int) $actor['id']) unset($clean['level'], $clean['parent_id'], $clean['deleted_at']);
        if ($clean === []) throw new HttpException('没有允许修改的字段', 0, 422);
        $before = Database::one("SELECT id FROM `{$table}` WHERE id = ?", [$id]); if ($before === null) throw new HttpException('目标记录不存在', 404, 404);
        $sets = implode(', ', array_map(static fn(string $column): string => "`{$column}` = ?", array_keys($clean)));
        $changed = Database::execute("UPDATE `{$table}` SET {$sets} WHERE id = ?", array_merge(array_values($clean), [$id]));
        PlatformService::log($request, $actor, 'data_console', 'update', $table, $id, ['fields' => array_keys($before)], ['fields' => array_keys($clean)]);
        return Response::success(['table' => $table, 'row_id' => $id, 'changed' => $changed], '数据总控修改成功');
    }

    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = self::actor($request); self::confirmWrite($actor, $request); $table = self::writableTable((string) $params['table']); $id = (int) $params['row_id'];
        $columns = self::columns($table); if ($id <= 0 || !isset($columns['id'])) throw new HttpException('该表不支持按 id 删除', 0, 422);
        if ($table === 'platform_accounts' && $id === (int) $actor['id']) throw new HttpException('不能删除当前 1 级平台所有者', 403, 403);
        $before = Database::one("SELECT id FROM `{$table}` WHERE id = ?", [$id]); if ($before === null) throw new HttpException('目标记录不存在', 404, 404);
        $hard = self::boolValue($request->input('hard_delete', false));
        if (!$hard && isset($columns['deleted_at'])) $changed = Database::execute("UPDATE `{$table}` SET deleted_at = NOW()" . (isset($columns['status']) ? ', status = -1' : '') . ' WHERE id = ?', [$id]);
        elseif (!$hard && isset($columns['status'])) $changed = Database::execute("UPDATE `{$table}` SET status = -1 WHERE id = ?", [$id]);
        else $changed = Database::execute("DELETE FROM `{$table}` WHERE id = ?", [$id]);
        PlatformService::log($request, $actor, 'data_console', $hard ? 'hard_delete' : 'delete', $table, $id, ['fields' => array_keys($before)], null);
        return Response::success(['table' => $table, 'row_id' => $id, 'changed' => $changed, 'hard_delete' => $hard], $hard ? '记录已永久删除' : '记录已删除');
    }

    private static function actor(Request $request): array
    {
        $actor = PlatformService::auth($request); PlatformService::requireLevelOne($actor);
        if (!(bool) config('security.data_console_enabled', false)
            || !PlatformService::setting((int) $actor['id'], 'data_console_enabled', false)) {
            throw new HttpException('数据总控已关闭', 403, 403);
        }
        return $actor;
    }

    private static function confirmWrite(array $actor, Request $request): void
    {
        if (trim((string) $request->input('confirmation', '')) !== '确认执行数据总控') throw new HttpException('请输入中文确认短语：确认执行数据总控', 0, 422);
        if (!Password::verify((string) $request->input('current_password', ''), (string) $actor['password_hash'])) throw new HttpException('当前 root 密码不正确', 403, 403);
    }

    private static function table(string $table): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $table) !== 1) throw new HttpException('数据表名称格式错误', 0, 422);
        $exists = Database::one('SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name = ? AND table_type = ?', [(string) config('database.name'), $table, 'BASE TABLE']);
        if ($exists === null) throw new HttpException('数据表不存在', 404, 404); return $table;
    }

    private static function writableTable(string $table): string
    {
        $table = self::table($table);
        if (!in_array($table, self::WRITABLE_TABLES, true)) {
            throw new HttpException('数据总控只读；请使用类型化业务接口修改数据', 403, 403);
        }
        return $table;
    }

    private static function columns(string $table): array
    {
        $rows = Database::all('SELECT column_name, data_type, is_nullable, column_default, extra FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position', [(string) config('database.name'), $table]);
        $result = []; foreach ($rows as $row) $result[(string) $row['column_name']] = $row; return $result;
    }

    private static function cleanData(string $table, array $data, bool $updating): array
    {
        $columns = self::columns($table); $clean = [];
        foreach ($data as $key => $value) {
            $key = (string) $key; if (!isset($columns[$key]) || self::isSensitiveColumn($key)) continue;
            if (!$updating && str_contains((string) $columns[$key]['extra'], 'auto_increment')) continue;
            $clean[$key] = is_array($value) || is_object($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $value;
        }
        if ($clean === []) throw new HttpException('没有可写入的安全字段', 0, 422); return $clean;
    }

    private static function filters(Request $request, array $columns): array
    {
        $filters = $request->input('filters', []); if (is_string($filters) && trim($filters) !== '') $filters = json_decode($filters, true);
        if (!is_array($filters)) $filters = []; $where = []; $query = [];
        foreach (array_slice($filters, 0, 20, true) as $column => $value) {
            if (!isset($columns[$column]) || self::isSensitiveColumn((string) $column)) continue;
            $where[] = "`{$column}` = ?"; $query[] = $value;
        }
        return [$where, $query];
    }

    private static function redact(array &$item): void
    {
        foreach (array_keys($item) as $column) {
            if (self::isSensitiveColumn((string) $column)) unset($item[$column]);
        }
    }

    private static function publicColumns(array $columns): array
    {
        return array_filter(
            $columns,
            static fn(array $metadata, string $column): bool => !self::isSensitiveColumn($column),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private static function isSensitiveColumn(string $column): bool
    {
        if (in_array(strtolower($column), self::SENSITIVE_COLUMNS, true)) return true;
        return preg_match('/(?:^|_)(?:password|token|secret|credential|api_key|private_key|ciphertext|encrypted)(?:_|$)|_hash$/i', $column) === 1;
    }

    private static function boolValue($value): bool { if (is_bool($value)) return $value; return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true); }
}
