<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Platform;

use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AiGatewayService;
use Yiyunying\Services\AiKnowledgeManagementService;
use Yiyunying\Services\PlatformService;

final class AiKnowledgeController
{
    public static function index(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        return Response::success(AiKnowledgeManagementService::platformIndex($actor, $request));
    }

    public static function status(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        PlatformService::requireCapability($actor, 'ai.manage');
        return Response::success(['ai' => AiGatewayService::diagnostics()]);
    }

    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        return Response::success(['document' => AiKnowledgeManagementService::platformShow($actor, (int) $params['document_id'])]);
    }

    public static function create(Request $request): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $document = AiKnowledgeManagementService::platformCreate($actor, $request->all());
        PlatformService::log($request, $actor, 'ai_knowledge', 'create', 'document', (int) $document['id'], null, $document);
        return Response::success(['document' => $document], 'AI 知识已创建', 201);
    }

    public static function update(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $before = AiKnowledgeManagementService::platformShow($actor, (int) $params['document_id']);
        $document = AiKnowledgeManagementService::platformUpdate($actor, (int) $params['document_id'], $request->all());
        PlatformService::log($request, $actor, 'ai_knowledge', 'update', 'document', (int) $document['id'], $before, $document);
        return Response::success(['document' => $document], 'AI 知识已更新');
    }

    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $actor = PlatformService::auth($request);
        $document = AiKnowledgeManagementService::platformDelete($actor, (int) $params['document_id']);
        PlatformService::log($request, $actor, 'ai_knowledge', 'delete', 'document', (int) $document['id'], $document, null);
        return Response::success([], 'AI 知识已删除');
    }
}
