<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\User;

use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\ChatRecordService;
use Yiyunying\Services\CloudSyncService;
use Yiyunying\Services\LogService;

final class CloudSyncController
{
    public static function policy(Request $request): \Yiyunying\Core\ApiResponse
    {
        return Response::success(CloudSyncService::policy(self::user($request)));
    }

    public static function index(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        return Response::success(['items' => CloudSyncService::listing($user, (string) $request->input('data_type', ''))]);
    }

    public static function create(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $result = CloudSyncService::create($user, $request->all());
        LogService::userOperation($request, $user, 'cloud_sync', 'create', (int) $result['snapshot_id'], ['data_type' => $result['data_type']]);
        return Response::success($result, '云备份创建成功', 201);
    }

    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return Response::success(CloudSyncService::show(self::user($request), (int) $params['snapshot_id']));
    }

    public static function restore(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $result = CloudSyncService::restore($user, (int) $params['snapshot_id']);
        LogService::userOperation($request, $user, 'cloud_sync', 'restore', (int) $params['snapshot_id'], ['data_type' => $result['data_type']]);
        return Response::success($result, '云端内容已拉取并按规则合并');
    }

    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        CloudSyncService::delete($user, (int) $params['snapshot_id']);
        LogService::userOperation($request, $user, 'cloud_sync', 'delete', (int) $params['snapshot_id']);
        return Response::success([], '云备份已删除');
    }

    public static function cleanupChat(Request $request): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $data = $request->all();
        $result = ChatRecordService::cleanup(
            $user,
            (string) ($data['scope_type'] ?? ''),
            max(0, (int) ($data['target_id'] ?? 0)),
            is_array($data['filters'] ?? null) ? $data['filters'] : $data
        );
        LogService::userOperation($request, $user, 'chat_cache', 'cleanup', null, $result);
        return Response::success($result, '所选聊天记录已从当前账号显示中清理');
    }

    private static function user(Request $request): array
    {
        $user = AuthService::user($request);
        AuthService::ensureNotBanned($user, ['all', 'message', 'upload']);
        return $user;
    }
}
