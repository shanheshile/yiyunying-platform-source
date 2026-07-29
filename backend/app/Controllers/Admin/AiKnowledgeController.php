<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AiGatewayService;
use Yiyunying\Services\AiKnowledgeManagementService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;

final class AiKnowledgeController
{
    public static function status(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        \Yiyunying\Services\AppService::owned((int) $admin['id'], (int) $params['app_id']);
        return Response::success(['ai' => AiGatewayService::diagnostics()]);
    }

    public static function index(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        return Response::success(AiKnowledgeManagementService::adminIndex($admin, (int) $params['app_id'], $request));
    }

    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $document = AiKnowledgeManagementService::adminShow($admin, (int) $params['app_id'], (int) $params['document_id']);
        return Response::success(['document' => $document]);
    }

    public static function create(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $document = AiKnowledgeManagementService::adminCreate($admin, $appId, $request->all());
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'ai_knowledge', 'create', (int) $document['id'], null, $document);
        return Response::success(['document' => $document], '应用 AI 知识已创建', 201);
    }

    public static function update(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $documentId = (int) $params['document_id'];
        $before = AiKnowledgeManagementService::adminShow($admin, $appId, $documentId);
        $document = AiKnowledgeManagementService::adminUpdate($admin, $appId, $documentId, $request->all());
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'ai_knowledge', 'update', $documentId, $before, $document);
        return Response::success(['document' => $document], '应用 AI 知识已更新');
    }

    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        $document = AiKnowledgeManagementService::adminDelete($admin, $appId, (int) $params['document_id']);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'ai_knowledge', 'delete', (int) $document['id'], $document, null);
        return Response::success([], '应用 AI 知识已删除');
    }
}
