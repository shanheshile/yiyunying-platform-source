<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Platform;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\GovernanceService;
use Yiyunying\Services\LifecycleService;
use Yiyunying\Services\PlatformService;

final class LifecycleController
{
    public static function updates(Request $request): \Yiyunying\Core\ApiResponse { return self::list($request, 'software_update_policies'); }
    public static function maintenances(Request $request): \Yiyunying\Core\ApiResponse { return self::list($request, 'maintenance_policies'); }
    public static function festivalThemes(Request $request): \Yiyunying\Core\ApiResponse { return self::list($request, 'festival_theme_policies'); }
    public static function createUpdate(Request $request): \Yiyunying\Core\ApiResponse { $actor = PlatformService::auth($request); $id = LifecycleService::createPlatformUpdate($actor, $request->all()); PlatformService::log($request, $actor, 'lifecycle', 'update_create', 'software_update', $id); return Response::success(['policy_id' => $id], '更新策略已发布', 201); }
    public static function createMaintenance(Request $request): \Yiyunying\Core\ApiResponse { $actor = PlatformService::auth($request); $id = LifecycleService::createPlatformMaintenance($actor, $request->all()); PlatformService::log($request, $actor, 'lifecycle', 'maintenance_create', 'maintenance', $id); return Response::success(['policy_id' => $id], '维护策略已发布', 201); }
    public static function createFestivalTheme(Request $request): \Yiyunying\Core\ApiResponse { $actor = PlatformService::auth($request); $id = LifecycleService::createPlatformFestival($actor, $request->all()); PlatformService::log($request, $actor, 'lifecycle', 'festival_create', 'festival_theme', $id); return Response::success(['policy_id' => $id], '节日界面策略已发布', 201); }
    public static function updateUpdate(Request $request, array $params): \Yiyunying\Core\ApiResponse { return self::replace($request, 'software_update_policies', (int) $params['policy_id'], 'update'); }
    public static function updateMaintenance(Request $request, array $params): \Yiyunying\Core\ApiResponse { return self::replace($request, 'maintenance_policies', (int) $params['policy_id'], 'maintenance'); }
    public static function updateFestivalTheme(Request $request, array $params): \Yiyunying\Core\ApiResponse { return self::replace($request, 'festival_theme_policies', (int) $params['policy_id'], 'festival'); }
    public static function deleteUpdate(Request $request, array $params): \Yiyunying\Core\ApiResponse { return self::delete($request, 'software_update_policies', (int) $params['policy_id']); }
    public static function deleteMaintenance(Request $request, array $params): \Yiyunying\Core\ApiResponse { return self::delete($request, 'maintenance_policies', (int) $params['policy_id']); }
    public static function deleteFestivalTheme(Request $request, array $params): \Yiyunying\Core\ApiResponse { return self::delete($request, 'festival_theme_policies', (int) $params['policy_id']); }

    private static function list(Request $request, string $table): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request); [$visible, $params] = GovernanceService::visibleWhere($actor, 'x');
        $visible = str_replace('x.issuer_platform_id', 'x.issuer_id', $visible);
        $page = $request->page(); $limit = $request->limit(); $offset = ($page - 1) * $limit;
        $where = "x.issuer_type = 'platform' AND {$visible}";
        $total = (int) (Database::one("SELECT COUNT(*) AS total FROM {$table} x WHERE {$where}", $params)['total'] ?? 0);
        $items = Database::all("SELECT x.* FROM {$table} x WHERE {$where} ORDER BY x.id DESC LIMIT {$limit} OFFSET {$offset}", $params);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    private static function replace(Request $request, string $table, int $id, string $kind): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request); $old = LifecycleService::manageablePolicy($actor, $table, $id);
        Database::transaction(static function () use ($request, $actor, $table, $id, $kind): void {
            Database::execute("DELETE FROM {$table} WHERE id = ?", [$id]);
            if ($kind === 'update') LifecycleService::createPlatformUpdate($actor, $request->all());
            elseif ($kind === 'festival') LifecycleService::createPlatformFestival($actor, $request->all());
            else LifecycleService::createPlatformMaintenance($actor, $request->all());
        });
        PlatformService::log($request, $actor, 'lifecycle', 'replace', $table, $id, $old, $request->all());
        return Response::success([], '生命周期策略已更新');
    }

    private static function delete(Request $request, string $table, int $id): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request); $old = LifecycleService::manageablePolicy($actor, $table, $id);
        Database::execute("DELETE FROM {$table} WHERE id = ?", [$id]);
        PlatformService::log($request, $actor, 'lifecycle', 'delete', $table, $id, $old, null);
        return Response::success([], '生命周期策略已删除');
    }
}
