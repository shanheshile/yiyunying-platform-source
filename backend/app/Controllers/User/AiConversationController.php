<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\User;

use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AiConversationService;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;

final class AiConversationController
{
    public static function index(Request $request): \Yiyunying\Core\ApiResponse
    {
        return Response::success(AiConversationService::index(self::user($request), $request));
    }

    public static function messages(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        return Response::success(AiConversationService::messages(
            self::user($request),
            (int) $params['conversation_id'],
            $request
        ));
    }

    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $user = self::user($request);
        $conversation = AiConversationService::delete($user, (int) $params['conversation_id']);
        LogService::userOperation($request, $user, 'ai_conversation', 'delete', (int) $conversation['id']);
        return Response::success([], 'AI 会话已删除');
    }

    private static function user(Request $request): array
    {
        return AuthService::user($request, 'bot');
    }
}
