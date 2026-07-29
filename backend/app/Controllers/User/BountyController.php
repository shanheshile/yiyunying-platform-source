<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\User;

use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Core\Validator;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\BountyCategoryService;
use Yiyunying\Services\BountyService;
use Yiyunying\Services\LogService;

final class BountyController
{
    private static function user(Request $request): array { $user = AuthService::user($request); AppService::requireFeature((int) $user['app_id'], 'bounties'); return $user; }
    public static function index(Request $request): \Yiyunying\Core\ApiResponse { $user = self::user($request); return Response::success(BountyService::feed($request, $user)); }
    public static function show(Request $request, array $params): \Yiyunying\Core\ApiResponse { $user = self::user($request); return Response::success(['bounty' => BountyService::show($user, (int) $params['bounty_id'])]); }
    public static function create(Request $request): \Yiyunying\Core\ApiResponse { $user = self::user($request); AuthService::ensureNotBanned($user, ['all', 'bounty']); $id = BountyService::create($user, $request->all()); $bounty = BountyService::bounty((int) $user['admin_id'], (int) $user['app_id'], $id); LogService::userOperation($request, $user, 'bounty', 'create', $id, ['audit_status' => $bounty['audit_status']]); $pending = (string) $bounty['audit_status'] === 'pending'; return Response::success(['bounty_id' => $id, 'audit_status' => $bounty['audit_status']], $pending ? '悬赏已提交审核，余额已冻结' : '悬赏发布成功，余额已冻结', 201); }
    public static function submit(Request $request, array $params): \Yiyunying\Core\ApiResponse { $user = self::user($request); AuthService::ensureNotBanned($user, ['all', 'bounty']); $id = BountyService::submit($user, (int) $params['bounty_id'], $request->all()); LogService::userOperation($request, $user, 'bounty_submission', 'create', $id); return Response::success(['submission_id' => $id], '投稿成功', 201); }
    public static function award(Request $request, array $params): \Yiyunying\Core\ApiResponse { $user = self::user($request); $submissionId = Validator::integer($request->input('submission_id'), 'submission_id', 1, PHP_INT_MAX); $result = BountyService::award($user, (int) $params['bounty_id'], $submissionId); LogService::userOperation($request, $user, 'bounty', 'award', (int) $params['bounty_id'], $result); return Response::success($result, '悬赏已结算'); }
    public static function cancel(Request $request, array $params): \Yiyunying\Core\ApiResponse { $user = self::user($request); $result = BountyService::cancel($user, (int) $params['bounty_id']); LogService::userOperation($request, $user, 'bounty', 'cancel', (int) $params['bounty_id'], $result); return Response::success($result, '悬赏已取消，余额已退回'); }
    public static function reaction(Request $request, array $params): \Yiyunying\Core\ApiResponse { $user = self::user($request); $type = trim((string) $request->input('reaction_type', 'like')); $active = BountyService::reaction($user, (int) $params['bounty_id'], $type); return Response::success(['reaction_type' => $type, 'active' => $active], $active ? '操作成功' : '已取消'); }
    public static function categories(Request $request): \Yiyunying\Core\ApiResponse { $user = self::user($request); return Response::success(['items' => BountyCategoryService::categories((int) $user['admin_id'], (int) $user['app_id'])]); }
    public static function categoryRequests(Request $request): \Yiyunying\Core\ApiResponse { $user = self::user($request); return Response::success(BountyCategoryService::userRequests($request, $user)); }
    public static function createCategoryRequest(Request $request): \Yiyunying\Core\ApiResponse { $user = self::user($request); $id = BountyCategoryService::createRequest($user, $request->all()); LogService::userOperation($request, $user, 'bounty_category_request', 'create', $id); return Response::success(['request_id' => $id], '分类申请已提交审核', 201); }
}
