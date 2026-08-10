<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\PublicApi;

use Yiyunying\Core\Request;
use Yiyunying\Services\PrivateForumMediaService;

final class ForumMediaController
{
    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return PrivateForumMediaService::show($request, $params);
    }
}
