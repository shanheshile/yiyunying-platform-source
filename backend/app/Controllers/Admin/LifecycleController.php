<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\Database;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LifecycleService;
use Yiyunying\Services\LogService;

final class LifecycleController
{
    public static function maintenances(Request $request, array $params): \Yiyunying\Core\ApiResponse { $admin = AuthService::admin($request); $appId = (int) $params['app_id']; AppService::owned((int) $admin['id'], $appId); return Response::success(['items' => Database::all("SELECT * FROM maintenance_policies WHERE issuer_type = 'admin' AND issuer_id = ? AND target_type = 'app' AND target_id = ? ORDER BY id DESC", [(int) $admin['id'], $appId])]); }
    public static function festivalThemes(Request $request, array $params): \Yiyunying\Core\ApiResponse { $admin = AuthService::admin($request); $appId = (int) $params['app_id']; AppService::owned((int) $admin['id'], $appId); return Response::success(['items' => Database::all("SELECT * FROM festival_theme_policies WHERE issuer_type = 'admin' AND issuer_id = ? AND target_type = 'app' AND target_id = ? ORDER BY id DESC", [(int) $admin['id'], $appId])]); }
    public static function createMaintenance(Request $request, array $params): \Yiyunying\Core\ApiResponse { $admin = AuthService::admin($request); $appId = (int) $params['app_id']; $id = LifecycleService::createAdminMaintenance($admin, $appId, $request->all()); LogService::adminOperation($request, (int) $admin['id'], $appId, 'maintenance', 'create', $id); return Response::success(['policy_id' => $id], '应用维护策略已发布', 201); }
    public static function createFestivalTheme(Request $request, array $params): \Yiyunying\Core\ApiResponse { $admin = AuthService::admin($request); $appId = (int) $params['app_id']; $id = LifecycleService::createAdminFestival($admin, $appId, $request->all()); LogService::adminOperation($request, (int) $admin['id'], $appId, 'festival_theme', 'create', $id); return Response::success(['policy_id' => $id], '应用节日界面策略已发布', 201); }
    public static function updateMaintenance(Request $request, array $params): \Yiyunying\Core\ApiResponse { $admin = AuthService::admin($request); $appId = (int) $params['app_id']; $id = (int) $params['policy_id']; $old = LifecycleService::adminPolicy($admin, $appId, $id); Database::transaction(static function () use ($admin, $appId, $id, $request): void { Database::execute('DELETE FROM maintenance_policies WHERE id = ?', [$id]); LifecycleService::createAdminMaintenance($admin, $appId, $request->all()); }); LogService::adminOperation($request, (int) $admin['id'], $appId, 'maintenance', 'replace', $id, $old, $request->all()); return Response::success([], '应用维护策略已更新'); }
    public static function updateFestivalTheme(Request $request, array $params): \Yiyunying\Core\ApiResponse { $admin = AuthService::admin($request); $appId = (int) $params['app_id']; $id = (int) $params['policy_id']; $old = LifecycleService::adminFestivalPolicy($admin, $appId, $id); Database::transaction(static function () use ($admin, $appId, $id, $request): void { Database::execute('DELETE FROM festival_theme_policies WHERE id = ?', [$id]); LifecycleService::createAdminFestival($admin, $appId, $request->all()); }); LogService::adminOperation($request, (int) $admin['id'], $appId, 'festival_theme', 'replace', $id, $old, $request->all()); return Response::success([], '应用节日界面策略已更新'); }
    public static function deleteMaintenance(Request $request, array $params): \Yiyunying\Core\ApiResponse { $admin = AuthService::admin($request); $appId = (int) $params['app_id']; $id = (int) $params['policy_id']; $old = LifecycleService::adminPolicy($admin, $appId, $id); Database::execute('DELETE FROM maintenance_policies WHERE id = ?', [$id]); LogService::adminOperation($request, (int) $admin['id'], $appId, 'maintenance', 'delete', $id, $old); return Response::success([], '应用维护策略已删除'); }
    public static function deleteFestivalTheme(Request $request, array $params): \Yiyunying\Core\ApiResponse { $admin = AuthService::admin($request); $appId = (int) $params['app_id']; $id = (int) $params['policy_id']; $old = LifecycleService::adminFestivalPolicy($admin, $appId, $id); Database::execute('DELETE FROM festival_theme_policies WHERE id = ?', [$id]); LogService::adminOperation($request, (int) $admin['id'], $appId, 'festival_theme', 'delete', $id, $old); return Response::success([], '应用节日界面策略已删除'); }
}
