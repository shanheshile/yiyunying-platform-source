<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;
use Yiyunying\Core\Validator;

final class BountyService
{
    public static function feed(Request $request, array $user): array
    {
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['b.admin_id = ?', 'b.app_id = ?', 'b.deleted_at IS NULL'];
        $query = [(int) $user['admin_id'], (int) $user['app_id']];
        $where[] = "(b.audit_status = 'approved' OR b.creator_user_id = ?)";
        $query[] = (int) $user['id'];
        $status = trim((string) $request->input('status', ''));
        if ($status !== '') { $where[] = 'b.status = ?'; $query[] = $status; }
        $auditStatus = trim((string) $request->input('audit_status', ''));
        if ($auditStatus !== '') { $where[] = 'b.audit_status = ?'; $query[] = $auditStatus; }
        $categoryId = (int) $request->input('category_id', 0);
        if ($categoryId > 0) { $where[] = 'b.category_id = ?'; $query[] = $categoryId; }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(b.title LIKE ? OR b.description LIKE ? OR u.account LIKE ? OR CAST(b.id AS CHAR) LIKE ?)';
            foreach (range(1, 4) as $_) $query[] = '%' . $keyword . '%';
        }
        if (self::boolValue($request->input('mine', false))) { $where[] = 'b.creator_user_id = ?'; $query[] = (int) $user['id']; }
        if (self::boolValue($request->input('submitted', false))) {
            $where[] = 'EXISTS(SELECT 1 FROM bounty_submissions bs WHERE bs.bounty_id = b.id AND bs.user_id = ?)';
            $query[] = (int) $user['id'];
        }
        if (self::boolValue($request->input('favorited', false))) {
            $where[] = "EXISTS(SELECT 1 FROM bounty_reactions br WHERE br.bounty_id = b.id AND br.user_id = ? AND br.reaction_type = 'favorite')";
            $query[] = (int) $user['id'];
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM bounties b INNER JOIN users u ON u.id = b.creator_user_id
             LEFT JOIN bounty_categories bc ON bc.id = b.category_id WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT b.*, bc.name AS category_name, bc.icon AS category_icon,
                    u.account AS creator_account, p.nickname AS creator_nickname, p.avatar AS creator_avatar,
                    EXISTS(SELECT 1 FROM bounty_reactions br WHERE br.bounty_id = b.id AND br.user_id = ? AND br.reaction_type = 'like') AS liked,
                    EXISTS(SELECT 1 FROM bounty_reactions br WHERE br.bounty_id = b.id AND br.user_id = ? AND br.reaction_type = 'favorite') AS favorited,
                    EXISTS(SELECT 1 FROM bounty_submissions bs WHERE bs.bounty_id = b.id AND bs.user_id = ?) AS submitted
             FROM bounties b INNER JOIN users u ON u.id = b.creator_user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN bounty_categories bc ON bc.id = b.category_id
             WHERE {$whereSql} ORDER BY (b.status = 'open') DESC, b.id DESC LIMIT {$limit} OFFSET {$offset}",
            array_merge([(int) $user['id'], (int) $user['id'], (int) $user['id']], $query)
        );
        foreach ($items as &$item) {
            self::hydrateAttachments($item);
            self::renameBalance($item);
        }
        unset($item);
        return Pagination::data($items, $total, $page, $limit);
    }

    public static function adminFeed(Request $request, int $adminId, int $appId): array
    {
        $page = $request->page(); $limit = $request->limit(); $offset = ($page - 1) * $limit;
        $where = ['b.admin_id = ?', 'b.app_id = ?', 'b.deleted_at IS NULL'];
        $query = [$adminId, $appId];
        $status = trim((string) $request->input('status', ''));
        if ($status !== '') { $where[] = 'b.status = ?'; $query[] = $status; }
        $auditStatus = trim((string) $request->input('audit_status', ''));
        if ($auditStatus !== '') { $where[] = 'b.audit_status = ?'; $query[] = $auditStatus; }
        $categoryId = (int) $request->input('category_id', 0);
        if ($categoryId > 0) { $where[] = 'b.category_id = ?'; $query[] = $categoryId; }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $where[] = '(b.title LIKE ? OR b.description LIKE ? OR u.account LIKE ? OR CAST(b.id AS CHAR) LIKE ?)';
            foreach (range(1, 4) as $_) $query[] = '%' . $keyword . '%';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM bounties b INNER JOIN users u ON u.id = b.creator_user_id
             LEFT JOIN bounty_categories bc ON bc.id = b.category_id WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT b.*, bc.name AS category_name, bc.icon AS category_icon, u.uid AS creator_uid, u.account AS creator_account,
                    p.nickname AS creator_nickname, p.avatar AS creator_avatar
             FROM bounties b INNER JOIN users u ON u.id = b.creator_user_id LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN bounty_categories bc ON bc.id = b.category_id
            WHERE {$whereSql} ORDER BY b.id DESC LIMIT {$limit} OFFSET {$offset}", $query
        );
        foreach ($items as &$item) {
            self::hydrateAttachments($item);
            self::renameBalance($item);
        }
        unset($item);
        return Pagination::data($items, $total, $page, $limit);
    }

    public static function show(array $user, int $bountyId): array
    {
        $bounty = self::bounty((int) $user['admin_id'], (int) $user['app_id'], $bountyId);
        if ((string) ($bounty['audit_status'] ?? 'pending') !== 'approved'
            && (int) $bounty['creator_user_id'] !== (int) $user['id']) {
            throw new HttpException('悬赏尚未审核通过或已被驳回', 403, 403);
        }
        $bounty['requirements'] = json_decode((string) ($bounty['requirements_json'] ?? ''), true) ?: [];
        $bounty['attachments'] = json_decode((string) ($bounty['attachments_json'] ?? ''), true) ?: [];
        $category = (int) ($bounty['category_id'] ?? 0) > 0
            ? Database::one('SELECT name, icon FROM bounty_categories WHERE id = ? AND app_id = ?', [(int) $bounty['category_id'], (int) $user['app_id']])
            : null;
        $bounty['category_name'] = (string) ($category['name'] ?? '未分类');
        $bounty['category_icon'] = (string) ($category['icon'] ?? '');
        unset($bounty['requirements_json']);
        unset($bounty['attachments_json']);
        $bounty['liked'] = self::hasReaction($bountyId, (int) $user['id'], 'like');
        $bounty['favorited'] = self::hasReaction($bountyId, (int) $user['id'], 'favorite');
        $bounty['submissions'] = Database::all(
            'SELECT s.*, u.account, p.nickname, p.avatar FROM bounty_submissions s
             INNER JOIN users u ON u.id = s.user_id LEFT JOIN user_profiles p ON p.user_id = u.id
            WHERE s.bounty_id = ? ORDER BY s.id DESC', [$bountyId]
        );
        foreach ($bounty['submissions'] as &$submission) {
            $submission['attachments'] = json_decode((string) ($submission['attachments_json'] ?? ''), true) ?: [];
            unset($submission['attachments_json']);
        }
        unset($submission);
        self::renameBalance($bounty);
        return $bounty;
    }

    public static function create(array $user, array $data): int
    {
        $reward = Validator::integer($data['reward_balance'] ?? null, 'reward_balance', 1, PHP_INT_MAX);
        $min = max(1, (int) AppService::setting((int) $user['app_id'], 'bounty_min_reward_balance', 1));
        $max = max($min, (int) AppService::setting((int) $user['app_id'], 'bounty_max_reward_balance', 1000000));
        if ($reward < $min || $reward > $max) throw new HttpException('悬赏余额超出允许范围', 0, 422, ['min' => $min, 'max' => $max]);
        $title = Validator::string($data['title'] ?? '', 'title', 1, 200);
        $description = Validator::string($data['description'] ?? '', 'description', 1, 50000);
        $categoryId = BountyCategoryService::categoryId((int) $user['admin_id'], (int) $user['app_id'], $data['category_id'] ?? null);
        $attachments = self::attachments($data['attachments'] ?? []);
        $deadline = self::dateValue($data['deadline_at'] ?? null);
        if ($deadline !== null && strtotime($deadline) <= time()) throw new HttpException('截止时间必须晚于当前时间', 0, 422);
        if ($deadline !== null && strtotime($deadline) > strtotime('+1 month')) throw new HttpException('悬赏截止时间最长只能选择一个月', 0, 422);
        $auditStatus = (bool) AppService::setting((int) $user['app_id'], 'bounty_review_enabled', true)
            ? 'pending' : 'approved';
        return Database::transaction(static function () use ($user, $data, $reward, $title, $description, $categoryId, $attachments, $deadline, $auditStatus): int {
            $id = Database::insert(
                'INSERT INTO bounties
                 (admin_id, app_id, category_id, creator_user_id, title, description, requirements_json, attachments_json, reward_integral,
                  status, audit_status, deadline_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    (int) $user['admin_id'], (int) $user['app_id'], $categoryId, (int) $user['id'], $title, $description,
                    json_encode((array) ($data['requirements'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($attachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $reward, 'open', $auditStatus, $deadline,
                ]
            );
            $asset = WalletService::requireActivityEnabled((int) $user['app_id']);
            WalletService::adjust($user, $asset, -$reward, 'bounty_escrow', 'bounty', $id, '发布悬赏冻结余额');
            return $id;
        });
    }

    public static function submit(array $user, int $bountyId, array $data): int
    {
        $content = Validator::string($data['content'] ?? '', 'content', 1, 50000);
        $attachments = self::attachments($data['attachments'] ?? []);
        return Database::transaction(static function () use ($user, $bountyId, $content, $attachments): int {
            $bounty = Database::one(
                "SELECT * FROM bounties WHERE id = ? AND admin_id = ? AND app_id = ?
                 AND status = 'open' AND audit_status = 'approved' AND deleted_at IS NULL FOR UPDATE",
                [$bountyId, (int) $user['admin_id'], (int) $user['app_id']]
            );
            if ($bounty === null) throw new HttpException('悬赏不存在或已结束', 404, 404);
            if ((int) $bounty['creator_user_id'] === (int) $user['id']) throw new HttpException('不能投稿自己发布的悬赏', 0, 422);
            if ($bounty['deadline_at'] !== null && strtotime((string) $bounty['deadline_at']) <= time()) throw new HttpException('悬赏已超过截止时间', 0, 410);
            try {
                $id = Database::insert(
                    'INSERT INTO bounty_submissions
                     (admin_id, app_id, bounty_id, user_id, content, attachments_json, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    [
                        (int) $user['admin_id'], (int) $user['app_id'], $bountyId, (int) $user['id'], $content,
                        json_encode($attachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'submitted',
                    ]
                );
            } catch (\PDOException $e) {
                if ((string) $e->getCode() === '23000') throw new HttpException('你已经投稿过该悬赏', 0, 409);
                throw $e;
            }
            Database::execute('UPDATE bounties SET submission_count = submission_count + 1, updated_at = NOW() WHERE id = ?', [$bountyId]);
            $creator = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], (int) $bounty['creator_user_id']);
            if ($creator !== null) NotificationService::send($creator, 'bounty_submission', '悬赏收到新投稿', '你的悬赏收到了一份新投稿', ['bounty_id' => $bountyId, 'submission_id' => $id]);
            return $id;
        });
    }

    public static function award(array $user, int $bountyId, int $submissionId): array
    {
        return Database::transaction(static function () use ($user, $bountyId, $submissionId): array {
            $bounty = Database::one(
                "SELECT * FROM bounties WHERE id = ? AND admin_id = ? AND app_id = ? AND creator_user_id = ?
                 AND status = 'open' AND audit_status = 'approved' FOR UPDATE",
                [$bountyId, (int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']]
            );
            if ($bounty === null) throw new HttpException('悬赏不存在、已结束或你不是发布者', 404, 404);
            $submission = Database::one('SELECT * FROM bounty_submissions WHERE id = ? AND bounty_id = ? AND status = ?', [$submissionId, $bountyId, 'submitted']);
            if ($submission === null) throw new HttpException('投稿不存在或不可选中', 404, 404);
            $winner = NotificationService::user((int) $user['admin_id'], (int) $user['app_id'], (int) $submission['user_id']);
            if ($winner === null) throw new HttpException('获奖用户不存在', 404, 404);
            $asset = WalletService::requireActivityEnabled((int) $user['app_id']);
            WalletService::adjust($winner, $asset, (int) $bounty['reward_integral'], 'bounty_award', 'bounty', $bountyId, '悬赏结算奖励');
            Database::execute("UPDATE bounty_submissions SET status = CASE WHEN id = ? THEN 'accepted' ELSE 'rejected' END, updated_at = NOW() WHERE bounty_id = ?", [$submissionId, $bountyId]);
            Database::execute(
                "UPDATE bounties SET winner_user_id = ?, winner_submission_id = ?, status = 'awarded', awarded_at = NOW(), updated_at = NOW() WHERE id = ?",
                [(int) $submission['user_id'], $submissionId, $bountyId]
            );
            NotificationService::send($winner, 'bounty_awarded', '悬赏投稿已选中', '你的投稿被选中，奖励余额已经到账', ['bounty_id' => $bountyId, 'reward_balance' => (int) $bounty['reward_integral']]);
            return ['winner_user_id' => (int) $submission['user_id'], 'reward_balance' => (int) $bounty['reward_integral']];
        });
    }

    public static function cancel(array $actorUser, int $bountyId, bool $adminOverride = false): array
    {
        return Database::transaction(static function () use ($actorUser, $bountyId, $adminOverride): array {
            $where = $adminOverride ? '' : ' AND creator_user_id = ?';
            $params = [$bountyId, (int) $actorUser['admin_id'], (int) $actorUser['app_id']];
            if (!$adminOverride) $params[] = (int) $actorUser['id'];
            $bounty = Database::one(
                "SELECT * FROM bounties WHERE id = ? AND admin_id = ? AND app_id = ? AND status = 'open' {$where} FOR UPDATE",
                $params
            );
            if ($bounty === null) throw new HttpException('悬赏不存在、已结算或无权取消', 404, 404);
            $creator = NotificationService::user((int) $bounty['admin_id'], (int) $bounty['app_id'], (int) $bounty['creator_user_id']);
            if ($creator === null) throw new HttpException('悬赏发布者不存在，无法退款', 0, 409);
            $asset = WalletService::requireActivityEnabled((int) $actorUser['app_id']);
            WalletService::adjust($creator, $asset, (int) $bounty['reward_integral'], 'bounty_refund', 'bounty', $bountyId, '悬赏取消退回余额');
            Database::execute("UPDATE bounties SET status = 'cancelled', updated_at = NOW() WHERE id = ?", [$bountyId]);
            Database::execute("UPDATE bounty_submissions SET status = 'cancelled', updated_at = NOW() WHERE bounty_id = ? AND status = 'submitted'", [$bountyId]);
            NotificationService::send($creator, 'bounty_cancelled', '悬赏已取消', '冻结的悬赏余额已经退回', ['bounty_id' => $bountyId]);
            return ['refunded_balance' => (int) $bounty['reward_integral'], 'creator_user_id' => (int) $bounty['creator_user_id']];
        });
    }

    public static function reaction(array $user, int $bountyId, string $type): bool
    {
        $bounty = self::bounty((int) $user['admin_id'], (int) $user['app_id'], $bountyId);
        if ((string) ($bounty['audit_status'] ?? 'pending') !== 'approved') {
            throw new HttpException('悬赏尚未审核通过，暂不能互动', 403, 403);
        }
        if (!in_array($type, ['like', 'favorite'], true)) throw new HttpException('reaction_type 不支持', 0, 422);
        return Database::transaction(static function () use ($user, $bountyId, $type): bool {
            $existing = Database::one('SELECT id FROM bounty_reactions WHERE bounty_id = ? AND user_id = ? AND reaction_type = ?', [$bountyId, (int) $user['id'], $type]);
            $column = $type === 'like' ? 'like_count' : 'favorite_count';
            if ($existing !== null) {
                Database::execute('DELETE FROM bounty_reactions WHERE id = ?', [(int) $existing['id']]);
                Database::execute("UPDATE bounties SET {$column} = GREATEST(0, {$column} - 1) WHERE id = ?", [$bountyId]);
                return false;
            }
            Database::execute('INSERT INTO bounty_reactions (bounty_id, user_id, reaction_type, created_at) VALUES (?, ?, ?, NOW())', [$bountyId, (int) $user['id'], $type]);
            Database::execute("UPDATE bounties SET {$column} = {$column} + 1 WHERE id = ?", [$bountyId]);
            return true;
        });
    }

    public static function review(
        int $adminId,
        int $appId,
        int $bountyId,
        string $auditStatus,
        string $reason,
        int $auditorId
    ): array {
        if (!in_array($auditStatus, ['approved', 'rejected'], true)) {
            throw new HttpException('审核结果仅支持 approved 或 rejected', 0, 422);
        }
        $reason = mb_substr(trim($reason), 0, 500);
        return Database::transaction(static function () use (
            $adminId, $appId, $bountyId, $auditStatus, $reason, $auditorId
        ): array {
            $bounty = Database::one(
                'SELECT * FROM bounties WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL FOR UPDATE',
                [$bountyId, $adminId, $appId]
            );
            if ($bounty === null) throw new HttpException('悬赏不存在', 404, 404);
            $oldAudit = (string) ($bounty['audit_status'] ?? 'pending');
            if ($oldAudit === 'rejected' && $auditStatus === 'approved') {
                throw new HttpException('已驳回并退款的悬赏不能直接恢复，请由用户重新发布', 0, 409);
            }
            if ((string) $bounty['status'] === 'awarded' && $auditStatus === 'rejected') {
                throw new HttpException('已经结算的悬赏不能驳回', 0, 409);
            }

            $refunded = 0;
            if ($auditStatus === 'rejected' && $oldAudit !== 'rejected' && (string) $bounty['status'] === 'open') {
                $creator = NotificationService::user($adminId, $appId, (int) $bounty['creator_user_id']);
                if ($creator === null) throw new HttpException('悬赏发布者不存在，无法完成退款', 0, 409);
                $asset = WalletService::requireActivityEnabled($appId);
                WalletService::adjust(
                    $creator,
                    $asset,
                    (int) $bounty['reward_integral'],
                    'bounty_review_refund',
                    'bounty',
                    $bountyId,
                    '悬赏审核未通过退回余额'
                );
                $refunded = (int) $bounty['reward_integral'];
                Database::execute(
                    "UPDATE bounty_submissions SET status = 'cancelled', updated_at = NOW()
                     WHERE bounty_id = ? AND status = 'submitted'",
                    [$bountyId]
                );
            }
            $businessStatus = $auditStatus === 'rejected' && (string) $bounty['status'] === 'open'
                ? 'cancelled' : (string) $bounty['status'];
            Database::execute(
                'UPDATE bounties SET audit_status = ?, audit_reason = ?, audited_by = ?, audited_at = NOW(),
                 status = ?, updated_at = NOW() WHERE id = ?',
                [$auditStatus, $reason, $auditorId, $businessStatus, $bountyId]
            );
            $creator = NotificationService::user($adminId, $appId, (int) $bounty['creator_user_id']);
            if ($creator !== null) {
                NotificationService::send(
                    $creator,
                    'bounty_audit',
                    $auditStatus === 'approved' ? '悬赏审核通过' : '悬赏审核未通过',
                    '《' . (string) $bounty['title'] . '》' . ($reason === '' ? '' : '：' . $reason),
                    ['bounty_id' => $bountyId, 'audit_status' => $auditStatus, 'reason' => $reason]
                );
            }
            return [
                'bounty_id' => $bountyId,
                'audit_status' => $auditStatus,
                'audit_reason' => $reason,
                'status' => $businessStatus,
                'refunded_balance' => $refunded,
            ];
        });
    }

    public static function updateByAdmin(int $adminId, int $appId, int $bountyId, array $data): array
    {
        $bounty = self::bounty($adminId, $appId, $bountyId);
        $updates = [];
        $params = [];
        if (array_key_exists('title', $data)) {
            $updates[] = 'title = ?';
            $params[] = Validator::string($data['title'], 'title', 1, 200);
        }
        if (array_key_exists('description', $data)) {
            $updates[] = 'description = ?';
            $params[] = Validator::string($data['description'], 'description', 1, 50000);
        }
        if (array_key_exists('requirements', $data)) {
            $updates[] = 'requirements_json = ?';
            $params[] = json_encode((array) $data['requirements'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (array_key_exists('category_id', $data)) {
            $updates[] = 'category_id = ?';
            $params[] = BountyCategoryService::categoryId($adminId, $appId, $data['category_id']);
        }
        if (array_key_exists('attachments', $data)) {
            $updates[] = 'attachments_json = ?';
            $params[] = json_encode(self::attachments($data['attachments']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (array_key_exists('deadline_at', $data)) {
            $deadline = self::dateValue($data['deadline_at']);
            if ($deadline !== null && strtotime($deadline) <= time()) throw new HttpException('截止时间必须晚于当前时间', 0, 422);
            if ($deadline !== null && strtotime($deadline) > strtotime('+1 month')) throw new HttpException('悬赏截止时间最长只能选择一个月', 0, 422);
            $updates[] = 'deadline_at = ?';
            $params[] = $deadline;
        }
        if ($updates === []) throw new HttpException('没有可修改的悬赏字段', 0, 422);
        $params[] = $bountyId;
        $params[] = $adminId;
        $params[] = $appId;
        Database::execute(
            'UPDATE bounties SET ' . implode(', ', $updates) . ', updated_at = NOW()
             WHERE id = ? AND admin_id = ? AND app_id = ?',
            $params
        );
        return ['before' => $bounty, 'bounty' => self::bounty($adminId, $appId, $bountyId)];
    }

    public static function deleteByAdmin(array $actor, int $bountyId): array
    {
        $bounty = self::bounty((int) $actor['admin_id'], (int) $actor['app_id'], $bountyId);
        $refund = ['refunded_balance' => 0];
        if ((string) $bounty['status'] === 'open') {
            $refund = self::cancel($actor, $bountyId, true);
        }
        Database::execute(
            'UPDATE bounties SET deleted_at = NOW(), updated_at = NOW() WHERE id = ? AND admin_id = ? AND app_id = ?',
            [$bountyId, (int) $actor['admin_id'], (int) $actor['app_id']]
        );
        return ['bounty_id' => $bountyId, 'deleted' => true] + $refund;
    }

    public static function bounty(int $adminId, int $appId, int $bountyId): array
    {
        $row = Database::one('SELECT * FROM bounties WHERE id = ? AND admin_id = ? AND app_id = ? AND deleted_at IS NULL', [$bountyId, $adminId, $appId]);
        if ($row === null) throw new HttpException('悬赏不存在', 404, 404);
        return $row;
    }

    private static function hasReaction(int $bountyId, int $userId, string $type): bool
    {
        return Database::one('SELECT id FROM bounty_reactions WHERE bounty_id = ? AND user_id = ? AND reaction_type = ?', [$bountyId, $userId, $type]) !== null;
    }

    private static function boolValue($value): bool
    {
        if (is_bool($value)) return $value;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function dateValue($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $time = strtotime($value);
        if ($time === false) throw new HttpException('deadline_at 时间格式错误', 0, 422);
        return date('Y-m-d H:i:s', $time);
    }

    private static function attachments($value): array
    {
        if (!is_array($value)) throw new HttpException('attachments 必须为附件列表', 0, 422);
        if (count($value) > 20) throw new HttpException('单条悬赏最多上传 20 个附件', 0, 422);
        $result = [];
        foreach ($value as $index => $item) {
            if (is_string($item)) $item = ['url' => $item];
            if (!is_array($item)) throw new HttpException('第 ' . ($index + 1) . ' 个附件格式错误', 0, 422);
            $url = trim((string) ($item['url'] ?? $item['path'] ?? ''));
            if ($url === '') throw new HttpException('第 ' . ($index + 1) . ' 个附件缺少地址', 0, 422);
            if (mb_strlen($url) > 2000) throw new HttpException('附件地址过长', 0, 422);
            $mediaType = strtolower(trim((string) ($item['media_type'] ?? $item['type'] ?? 'file')));
            if (!in_array($mediaType, ['image', 'video', 'audio', 'file', 'document', 'archive'], true)) $mediaType = 'file';
            $result[] = [
                'url' => $url,
                'name' => mb_substr(trim((string) ($item['name'] ?? basename(parse_url($url, PHP_URL_PATH) ?: '附件'))), 0, 255),
                'media_type' => $mediaType,
                'mime_type' => mb_substr(trim((string) ($item['mime_type'] ?? '')), 0, 120),
                'size' => max(0, (int) ($item['size'] ?? 0)),
                'thumbnail' => mb_substr(trim((string) ($item['thumbnail'] ?? '')), 0, 2000),
                'duration' => max(0, (int) ($item['duration'] ?? 0)),
            ];
        }
        return $result;
    }

    private static function hydrateAttachments(array &$item): void
    {
        $attachments = json_decode((string) ($item['attachments_json'] ?? '[]'), true);
        $requirements = json_decode((string) ($item['requirements_json'] ?? '[]'), true);
        $item['attachments'] = is_array($attachments) ? $attachments : [];
        $item['attachment_count'] = count($item['attachments']);
        $item['requirements'] = is_array($requirements) ? $requirements : [];
        unset($item['attachments_json'], $item['requirements_json']);
    }

    private static function renameBalance(array &$item): void
    {
        if (array_key_exists('reward_integral', $item)) {
            $item['reward_balance'] = (int) $item['reward_integral'];
            unset($item['reward_integral']);
        }
        $audit = (string) ($item['audit_status'] ?? 'pending');
        $item['audit_status_name'] = [
            'pending' => '待审核',
            'approved' => '已通过',
            'rejected' => '未通过',
        ][$audit] ?? $audit;
    }
}
