<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\PublicApi;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AdminBrandingService;

final class BrandingController
{
    public static function show(Request $request): \Yiyunying\Core\ApiResponse
    {
        $appKey = trim((string) ($request->attribute('app_key') ?? $request->header('x-app-key', '')));
        $app = Database::one('SELECT id, admin_id FROM apps WHERE app_key = ? AND status = 1 AND deleted_at IS NULL', [$appKey]);
        if ($app === null) throw new HttpException('应用不存在或不可用', 404, 404);
        return Response::success([
            'app_id' => (int) $app['id'],
            'branding' => AdminBrandingService::get((int) $app['admin_id']),
        ]);
    }
}
