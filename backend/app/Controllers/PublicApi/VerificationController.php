<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\PublicApi;

use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AppService;
use Yiyunying\Services\ContactVerificationService;

final class VerificationController
{
    public static function email(Request $request): \Yiyunying\Core\ApiResponse
    {
        $app = AppService::byKey(trim((string) $request->input('app_key', $request->header('x-app-key', ''))));
        $request->setAttribute('admin_id', (int) $app['admin_id']);
        $request->setAttribute('app_id', (int) $app['id']);
        $result = ContactVerificationService::issueEmail(
            $app,
            (string) $request->input('email', ''),
            trim((string) $request->input('scene', 'register')),
            $request
        );
        return Response::success($result, ContactVerificationService::deliveryResponseMessage($result), 202);
    }
}
