<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\Database;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\BountyCategoryService;
use Yiyunying\Services\BountyService;
use Yiyunying\Services\LogService;

final class BountyController
{
    public static function index(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        return Response::success(BountyService::adminFeed($request, (int) $admin['id'], $appId));
    }

    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $bounty = BountyService::bounty((int) $admin['id'], $appId, (int) $params['bounty_id']);
        $bounty['requirements'] = json_decode((string) ($bounty['requirements_json'] ?? ''), true) ?: [];
        $bounty['attachments'] = json_decode((string) ($bounty['attachments_json'] ?? ''), true) ?: [];
        unset($bounty['requirements_json']);
        unset($bounty['attachments_json']);
        $bounty['reward_balance'] = (int) $bounty['reward_integral'];
        unset($bounty['reward_integral']);
        $bounty['submissions'] = Database::all(
            'SELECT submission.*, user.uid, user.account, profile.nickname, profile.avatar
             FROM bounty_submissions submission
             INNER JOIN users user ON user.id = submission.user_id
             LEFT JOIN user_profiles profile ON profile.user_id = user.id
             WHERE submission.bounty_id = ? ORDER BY submission.id DESC',
            [(int) $params['bounty_id']]
        );
        foreach ($bounty['submissions'] as &$submission) {
            $submission['attachments'] = json_decode((string) ($submission['attachments_json'] ?? '[]'), true) ?: [];
            unset($submission['attachments_json']);
        }
        unset($submission);
        if ((int) ($bounty['category_id'] ?? 0) > 0) {
            $category = Database::one('SELECT name, icon FROM bounty_categories WHERE id = ? AND app_id = ?', [(int) $bounty['category_id'], $appId]);
            $bounty['category_name'] = (string) ($category['name'] ?? '未分类');
            $bounty['category_icon'] = (string) ($category['icon'] ?? '');
        } else {
            $bounty['category_name'] = '未分类';
            $bounty['category_icon'] = '';
        }
        return Response::success(['bounty' => $bounty]);
    }

    public static function categories(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        return Response::success(['items' => BountyCategoryService::categories((int) $admin['id'], $appId, false)]);
    }

    public static function createCategory(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = BountyCategoryService::create((int) $admin['id'], $appId, $request->all());
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'bounty_category', 'create', $id);
        return Response::success(['category_id' => $id], '悬赏分类创建成功', 201);
    }

    public static function updateCategory(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['category_id'];
        $item = BountyCategoryService::update((int) $admin['id'], $appId, $id, $request->all());
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'bounty_category', 'update', $id, null, $item);
        return Response::success(['category' => $item], '悬赏分类已修改');
    }

    public static function deleteCategory(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['category_id'];
        BountyCategoryService::delete((int) $admin['id'], $appId, $id);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'bounty_category', 'delete', $id);
        return Response::success([], '悬赏分类已删除，原悬赏保留为未分类');
    }

    public static function categoryRequests(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        return Response::success(BountyCategoryService::adminRequests($request, (int) $admin['id'], $appId));
    }

    public static function reviewCategoryRequest(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['request_id'];
        $result = BountyCategoryService::reviewRequest((int) $admin['id'], $appId, $id, trim((string) $request->input('decision', '')), (string) $request->input('review_comment', ''));
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'bounty_category_request', 'review', $id, null, $result);
        return Response::success($result, '悬赏分类申请已处理');
    }

    public static function update(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['bounty_id'];
        $result = BountyService::updateByAdmin((int) $admin['id'], $appId, $id, $request->all());
        LogService::adminOperation(
            $request,
            (int) $admin['id'],
            $appId,
            'bounty',
            'update',
            $id,
            $result['before'] ?? null,
            $result['bounty'] ?? null
        );
        unset($result['before']);
        return Response::success($result, '悬赏已修改');
    }

    public static function audit(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['bounty_id'];
        $result = BountyService::review(
            (int) $admin['id'],
            $appId,
            $id,
            trim((string) $request->input('audit_status', '')),
            (string) $request->input('reason', ''),
            (int) $admin['id']
        );
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'bounty', 'audit', $id, null, $result);
        return Response::success($result, $result['audit_status'] === 'approved' ? '悬赏审核通过' : '悬赏审核未通过');
    }

    public static function cancel(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['bounty_id'];
        $actor = ['id' => 0, 'admin_id' => (int) $admin['id'], 'app_id' => $appId];
        $result = BountyService::cancel($actor, $id, true);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'bounty', 'cancel', $id, null, $result);
        return Response::success($result, '悬赏已下架并退款');
    }

    public static function delete(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        [$admin, $appId] = self::context($request, $params);
        $id = (int) $params['bounty_id'];
        $actor = ['id' => 0, 'admin_id' => (int) $admin['id'], 'app_id' => $appId];
        $result = BountyService::deleteByAdmin($actor, $id);
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'bounty', 'delete', $id, null, $result);
        return Response::success($result, '悬赏已删除');
    }

    private static function context(Request $request, array $params): array
    {
        $admin = AuthService::admin($request);
        $appId = (int) $params['app_id'];
        AppService::owned((int) $admin['id'], $appId);
        return [$admin, $appId];
    }
}
