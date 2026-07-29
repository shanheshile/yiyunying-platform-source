<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\PublicApi;

use Yiyunying\Core\Database;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;

final class HealthController
{
    public static function show(Request $request): \Yiyunying\Core\ApiResponse
    {
        Database::one('SELECT 1 AS ok');
        return Response::success([
            'service' => '易运盈后台',
            'status' => 'ok',
            'database' => 'connected',
            'time' => date('Y-m-d H:i:s'),
        ]);
    }
}
