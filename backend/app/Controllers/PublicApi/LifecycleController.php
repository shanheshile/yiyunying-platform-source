<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\PublicApi;

use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\LifecycleService;

final class LifecycleController
{
    public static function check(Request $request): \Yiyunying\Core\ApiResponse
    {
        $edition = trim((string) $request->input('edition_code', ''));
        $context = LifecycleService::context(
            $edition,
            trim((string) $request->input('platform_key', '')),
            trim((string) $request->input('app_key', '')),
            $request->input('admin_id') === null ? null : (int) $request->input('admin_id')
        );
        return Response::success(LifecycleService::check($context, max(0, (int) $request->input('version_code', 0)), $request->clientIp()));
    }
}
