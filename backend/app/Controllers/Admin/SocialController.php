<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AppService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\NotificationService;
use Yiyunying\Services\WalletService;

final class SocialController
{
    public static function withdrawals(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request); $appId = (int) $params['app_id']; AppService::owned((int) $admin['id'], $appId);
        $page = $request->page(); $limit = $request->limit(); $offset = ($page - 1) * $limit; $where = ['w.admin_id = ?', 'w.app_id = ?']; $query = [(int) $admin['id'], $appId];
        if (trim((string) $request->input('status', '')) !== '') { $where[] = 'w.status = ?'; $query[] = trim((string) $request->input('status')); }
        $whereSql = implode(' AND ', $where); $total = (int) (Database::one("SELECT COUNT(*) AS total FROM user_withdrawals w WHERE {$whereSql}", $query)['total'] ?? 0);
        $items = Database::all("SELECT w.*, u.account, p.nickname FROM user_withdrawals w INNER JOIN users u ON u.id = w.user_id LEFT JOIN user_profiles p ON p.user_id = u.id WHERE {$whereSql} ORDER BY w.id DESC LIMIT {$limit} OFFSET {$offset}", $query);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function reviewWithdrawal(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request); $appId = (int) $params['app_id']; AppService::owned((int) $admin['id'], $appId); $id = (int) $params['withdrawal_id']; $decision = trim((string) $request->input('decision', ''));
        if (!in_array($decision, ['approve', 'reject'], true)) throw new HttpException('decision 仅支持 approve 或 reject', 0, 422);
        $row = Database::transaction(static function () use ($admin, $appId, $id, $decision, $request): array {
            $row = Database::one("SELECT * FROM user_withdrawals WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 'pending' FOR UPDATE", [$id, (int) $admin['id'], $appId]);
            if ($row === null) throw new HttpException('待处理提现不存在', 404, 404);
            $status = $decision === 'approve' ? 'approved' : 'rejected';
            Database::execute('UPDATE user_withdrawals SET status = ?, review_remark = ?, reviewed_by_admin_id = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?', [$status, mb_substr((string) $request->input('remark', ''), 0, 500), (int) $admin['id'], $id]);
            $user = NotificationService::user((int) $admin['id'], $appId, (int) $row['user_id']);
            if ($user !== null && $decision === 'reject') WalletService::adjust($user, 'balance', (float) $row['amount'], 'withdrawal_rejected_refund', 'withdrawal', $id, '提现驳回退回余额');
            if ($user !== null) NotificationService::send($user, 'withdrawal_review', $decision === 'approve' ? '提现已通过' : '提现被驳回', $decision === 'approve' ? '提现申请已审核通过' : '提现金额已退回余额', ['withdrawal_id' => $id, 'status' => $status]);
            $row['status'] = $status; return $row;
        });
        LogService::adminOperation($request, (int) $admin['id'], $appId, 'withdrawal', $decision, $id, null, $row); return Response::success(['withdrawal' => $row], '提现审核完成');
    }
}
