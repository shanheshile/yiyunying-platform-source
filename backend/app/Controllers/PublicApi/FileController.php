<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\PublicApi;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AppService;

final class FileController
{
    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $appKey = trim((string) ($request->header('x-app-key') ?? $request->input('app_key', '')));
        $app = AppService::byKey($appKey);
        AppService::requireFeature((int) $app['id'], 'remote_files');
        $request->setAttribute('admin_id', (int) $app['admin_id']);
        $request->setAttribute('app_id', (int) $app['id']);
        $file = Database::one(
            "SELECT id, parent_id, file_type, name, content, file_url, mime_type, size_bytes, updated_at
             FROM remote_files WHERE id = ? AND admin_id = ? AND app_id = ? AND visibility = 'public'
               AND status = 1 AND deleted_at IS NULL",
            [(int) $params['file_id'], (int) $app['admin_id'], (int) $app['id']]
        );
        if ($file === null) {
            throw new HttpException('公开文件不存在', 404, 404);
        }
        return Response::success(['file' => $file]);
    }
}
