<?php
declare(strict_types=1);

namespace Yiyunying\Controllers\Admin;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Response;
use Yiyunying\Services\AdminAccessService;
use Yiyunying\Services\AdminBrandingService;
use Yiyunying\Services\AuthService;
use Yiyunying\Services\LogService;
use Yiyunying\Services\WalletService;

final class ManagementWorkbenchController
{
    public static function overview(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $adminId = (int) $admin['id'];
        $counts = Database::one(
            'SELECT
               (SELECT COUNT(*) FROM apps WHERE admin_id = ? AND deleted_at IS NULL) AS app_count,
               (SELECT COUNT(*) FROM users WHERE admin_id = ? AND deleted_at IS NULL) AS user_count,
               (SELECT COUNT(*) FROM documents WHERE admin_id = ? AND deleted_at IS NULL) AS document_count,
               (SELECT COUNT(*) FROM remote_files WHERE admin_id = ? AND deleted_at IS NULL) AS remote_file_count,
               (SELECT COUNT(*) FROM admin_tokens WHERE admin_id = ? AND revoked_at IS NULL AND expired_at > NOW()) AS active_device_count',
            [$adminId, $adminId, $adminId, $adminId, $adminId]
        ) ?? [];
        $appCount = (int) ($counts['app_count'] ?? 0);
        $appQuota = (int) ($admin['app_quota'] ?? 0);
        $remoteDocumentCount = (int) ($counts['remote_file_count'] ?? 0);
        $remoteDocumentQuota = (int) ($admin['remote_document_quota'] ?? 0);
        return Response::success([
            'profile' => [
                'id' => $adminId,
                'account' => (string) $admin['account'],
                'nickname' => (string) ($admin['nickname'] ?? ''),
                'avatar' => (string) ($admin['avatar'] ?? ''),
                'email' => (string) ($admin['email'] ?? ''),
                'phone' => (string) ($admin['phone'] ?? ''),
            ],
            'membership' => [
                'level' => (string) ($admin['membership_level'] ?? 'trial'),
                'status' => (string) ($admin['membership_status'] ?? ''),
                'expired_at' => $admin['membership_expired_at'] ?? null,
                'access' => AdminAccessService::accessState($admin),
            ],
            'quotas' => [
                'apps' => ['used' => $appCount, 'limit' => $appQuota, 'remaining' => max(0, $appQuota - $appCount)],
                'api_apps_remaining' => max(0, $appQuota - $appCount),
                'remote_documents' => [
                    'used' => $remoteDocumentCount,
                    'limit' => $remoteDocumentQuota,
                    'remaining' => max(0, $remoteDocumentQuota - $remoteDocumentCount),
                ],
            ],
            'counts' => [
                'apps' => $appCount,
                'users' => (int) ($counts['user_count'] ?? 0),
                'documents' => (int) ($counts['document_count'] ?? 0),
                'remote_files' => (int) ($counts['remote_file_count'] ?? 0),
                'active_devices' => (int) ($counts['active_device_count'] ?? 0),
            ],
            'public_profile' => AdminBrandingService::get($adminId),
            'sponsors' => self::topSponsors($adminId, 20),
        ]);
    }

    public static function savePublicProfile(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $before = AdminBrandingService::get((int) $admin['id']);
        $after = AdminBrandingService::save((int) $admin['id'], $request->all());
        LogService::adminOperation($request, (int) $admin['id'], null, 'management_profile', 'save', (int) $admin['id'], $before, $after);
        return Response::success(['public_profile' => $after], '公开信息已保存');
    }

    public static function sponsors(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $total = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM admin_sponsor_records WHERE admin_id = ? AND deleted_at IS NULL',
            [(int) $admin['id']]
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT id, sponsor_name, amount, channel, note, paid_at, status, created_at, updated_at
             FROM admin_sponsor_records WHERE admin_id = ? AND deleted_at IS NULL
             ORDER BY status DESC, amount DESC, paid_at ASC, id ASC LIMIT {$limit} OFFSET {$offset}",
            [(int) $admin['id']]
        );
        self::rankSponsors($items, $offset);
        return Response::success(Pagination::data($items, $total, $page, $limit));
    }

    public static function createSponsor(Request $request): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $values = self::sponsorValues($request->all(), null);
        $id = Database::insert(
            'INSERT INTO admin_sponsor_records
             (admin_id, sponsor_name, amount, channel, note, paid_at, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [(int) $admin['id'], $values['sponsor_name'], $values['amount'], $values['channel'], $values['note'], $values['paid_at'], $values['status']]
        );
        LogService::adminOperation($request, (int) $admin['id'], null, 'sponsor', 'create', $id, null, $values);
        return Response::success(['sponsor_id' => $id, 'ranking' => self::topSponsors((int) $admin['id'], 20)], '赞助记录已登记并自动排序', 201);
    }

    public static function updateSponsor(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $id = (int) $params['sponsor_id'];
        $before = Database::one('SELECT * FROM admin_sponsor_records WHERE id = ? AND admin_id = ? AND deleted_at IS NULL', [$id, (int) $admin['id']]);
        if ($before === null) throw new HttpException('赞助记录不存在', 404, 404);
        $values = self::sponsorValues($request->all(), $before);
        Database::execute(
            'UPDATE admin_sponsor_records SET sponsor_name = ?, amount = ?, channel = ?, note = ?, paid_at = ?, status = ?, updated_at = NOW()
             WHERE id = ? AND admin_id = ? AND deleted_at IS NULL',
            [$values['sponsor_name'], $values['amount'], $values['channel'], $values['note'], $values['paid_at'], $values['status'], $id, (int) $admin['id']]
        );
        LogService::adminOperation($request, (int) $admin['id'], null, 'sponsor', 'update', $id, $before, $values);
        return Response::success(['ranking' => self::topSponsors((int) $admin['id'], 20)], '赞助记录已更新并重新排序');
    }

    public static function deleteSponsor(Request $request, array $params): \Yiyunying\Core\ApiResponse
    {
        $admin = AuthService::admin($request);
        $id = (int) $params['sponsor_id'];
        $before = Database::one('SELECT * FROM admin_sponsor_records WHERE id = ? AND admin_id = ? AND deleted_at IS NULL', [$id, (int) $admin['id']]);
        if ($before === null) throw new HttpException('赞助记录不存在', 404, 404);
        Database::execute('UPDATE admin_sponsor_records SET status = -1, deleted_at = NOW(), updated_at = NOW() WHERE id = ? AND admin_id = ?', [$id, (int) $admin['id']]);
        LogService::adminOperation($request, (int) $admin['id'], null, 'sponsor', 'delete', $id, $before);
        return Response::success(['ranking' => self::topSponsors((int) $admin['id'], 20)], '赞助记录已删除');
    }

    private static function sponsorValues(array $data, ?array $fallback): array
    {
        $name = trim((string) ($data['sponsor_name'] ?? ($fallback['sponsor_name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 100) throw new HttpException('赞助人名称必须为 1-100 个字符', 0, 422);
        $amount = WalletService::canonicalAmount('balance', $data['amount'] ?? ($fallback['amount'] ?? null));
        if (WalletService::amountUnits('balance', $amount) <= 0) throw new HttpException('赞助金额必须大于 0', 0, 422);
        $channel = strtolower(trim((string) ($data['channel'] ?? ($fallback['channel'] ?? 'manual'))));
        if (!in_array($channel, ['manual', 'alipay', 'wechat', 'other'], true)) throw new HttpException('赞助渠道不支持', 0, 422);
        $paidAt = trim((string) ($data['paid_at'] ?? ($fallback['paid_at'] ?? '')));
        if ($paidAt === '') $paidAt = date('Y-m-d H:i:s');
        if (strtotime($paidAt) === false) throw new HttpException('到账时间格式错误', 0, 422);
        return [
            'sponsor_name' => mb_substr($name, 0, 100),
            'amount' => $amount,
            'channel' => $channel,
            'note' => mb_substr(trim((string) ($data['note'] ?? ($fallback['note'] ?? ''))), 0, 500),
            'paid_at' => date('Y-m-d H:i:s', (int) strtotime($paidAt)),
            'status' => isset($data['status']) ? ((int) $data['status'] === 1 ? 1 : 0) : (int) ($fallback['status'] ?? 1),
        ];
    }

    private static function topSponsors(int $adminId, int $limit): array
    {
        $items = Database::all(
            "SELECT id, sponsor_name, amount, channel, note, paid_at
             FROM admin_sponsor_records WHERE admin_id = ? AND status = 1 AND deleted_at IS NULL
             ORDER BY amount DESC, paid_at ASC, id ASC LIMIT {$limit}",
            [$adminId]
        );
        self::rankSponsors($items, 0);
        return $items;
    }

    private static function rankSponsors(array &$items, int $offset): void
    {
        foreach ($items as $index => &$item) {
            $item['id'] = (int) $item['id'];
            $item['rank'] = $offset + $index + 1;
            if (isset($item['status'])) $item['status'] = (int) $item['status'];
            $item['amount'] = WalletService::canonicalAmount('balance', $item['amount']);
        }
        unset($item);
    }
}
