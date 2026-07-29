<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;
use Yiyunying\Core\Pagination;
use Yiyunying\Core\Request;

final class WalletLedgerService
{
    private const CATEGORY_SCENES = [
        'red_packet' => [
            'red_packet_send', 'red_packet_claim', 'red_packet_recipient_return',
            'red_packet_expired_refund',
        ],
        'transfer' => [
            'transfer_escrow', 'transfer_accept', 'transfer_refund',
            'wallet_transfer_out', 'wallet_transfer_in', 'transfer_out', 'transfer_in',
        ],
        'gift' => [
            'gift_escrow', 'gift_refund', 'forum_reward_send', 'forum_reward_receive',
            'forum_reward', 'bounty_escrow', 'bounty_award', 'bounty_refund',
        ],
        'shopping' => [
            'shop_buy', 'shop_order_refund', 'forum_section_buy', 'forum_section_sale',
            'forum_paid_buy', 'forum_paid_sale', 'resource_buy', 'asset_purchase_pay',
            'asset_purchase_grant', 'payment_document_credit', 'purchase',
        ],
        'reward' => [
            'sign', 'invite_reward', 'card_redeem', 'login_card_bind',
        ],
        'recharge_withdrawal' => [
            'payment_recharge', 'withdrawal', 'withdrawal_hold', 'withdrawal_refund',
            'withdrawal_rejected_refund',
        ],
        'admin' => ['admin_adjust'],
    ];

    private const CATEGORY_NAMES = [
        'all' => '全部',
        'red_packet' => '红包',
        'transfer' => '转账',
        'gift' => '礼物与打赏',
        'shopping' => '购买与退款',
        'reward' => '奖励与签到',
        'recharge_withdrawal' => '充值与提现',
        'admin' => '管理调整',
        'other' => '其他',
    ];

    private const SCENE_NAMES = [
        'red_packet_send' => '发送红包',
        'red_packet_claim' => '领取红包',
        'red_packet_recipient_return' => '退回红包',
        'red_packet_expired_refund' => '红包到期退回',
        'transfer_escrow' => '发起转账',
        'transfer_accept' => '接收转账',
        'transfer_refund' => '转账退回',
        'wallet_transfer_out' => '转账支出',
        'wallet_transfer_in' => '转账收入',
        'transfer_out' => '转账支出',
        'transfer_in' => '转账收入',
        'gift_escrow' => '赠送礼物',
        'gift_refund' => '礼物退回',
        'forum_reward_send' => '论坛打赏支出',
        'forum_reward_receive' => '论坛打赏收入',
        'forum_reward' => '论坛打赏',
        'bounty_escrow' => '悬赏托管',
        'bounty_award' => '悬赏奖励',
        'bounty_refund' => '悬赏退回',
        'shop_buy' => '商城购买',
        'shop_order_refund' => '商城退款',
        'forum_section_buy' => '购买帖子付费节',
        'forum_section_sale' => '帖子付费节收入',
        'forum_paid_buy' => '购买付费帖子',
        'forum_paid_sale' => '付费帖子收入',
        'resource_buy' => '购买资源',
        'asset_purchase_pay' => '资产商品购买',
        'asset_purchase_grant' => '资产商品到账',
        'payment_document_credit' => '购买笔记额度',
        'purchase' => '购买消费',
        'sign' => '每日签到奖励',
        'invite_reward' => '邀请奖励',
        'card_redeem' => '卡密兑换',
        'login_card_bind' => '登录卡密绑定',
        'payment_recharge' => '余额充值',
        'withdrawal' => '余额提现',
        'withdrawal_hold' => '提现冻结',
        'withdrawal_refund' => '提现退回',
        'withdrawal_rejected_refund' => '提现驳回退回',
        'admin_adjust' => '管理调整',
        'cloud_sync_snapshot' => '云端记录同步',
    ];

    private const ASSET_NAMES = [
        'integral' => '余额',
        'balance' => '余额',
        'experience' => '经验',
        'document_credit' => '笔记额度',
        'vip_days' => '会员天数',
        'app_quota' => '应用额度',
    ];

    public static function paginate(Request $request, array $user): array
    {
        $page = $request->page();
        $limit = $request->limit();
        $offset = ($page - 1) * $limit;
        $where = ['admin_id = ?', 'app_id = ?', 'user_id = ?'];
        $query = [(int) $user['admin_id'], (int) $user['app_id'], (int) $user['id']];

        $assetType = trim((string) $request->input('asset_type', ''));
        if ($assetType !== '') {
            $where[] = 'asset_type = ?';
            $query[] = $assetType === 'activity_credit' ? 'integral' : $assetType;
        }
        foreach (['date_from' => '>=', 'date_to' => '<='] as $field => $operator) {
            $date = trim((string) $request->input($field, ''));
            if ($date === '') continue;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new HttpException($field . ' 必须是 YYYY-MM-DD 日期', 0, 422);
            }
            $where[] = 'created_at ' . $operator . ' ?';
            $query[] = $field === 'date_to' ? $date . ' 23:59:59' : $date . ' 00:00:00';
        }
        $scene = trim((string) $request->input('scene', ''));
        if ($scene !== '') {
            $where[] = 'scene = ?';
            $query[] = $scene;
        }

        $categoryWhere = $where;
        $categoryQuery = $query;
        $category = trim((string) $request->input('category', 'all'));
        if (!array_key_exists($category, self::CATEGORY_NAMES)) $category = 'all';
        if ($category !== 'all') {
            $scenes = $category === 'other' ? self::knownScenes() : (self::CATEGORY_SCENES[$category] ?? []);
            if ($scenes !== []) {
                $marks = implode(',', array_fill(0, count($scenes), '?'));
                $where[] = 'scene ' . ($category === 'other' ? 'NOT IN' : 'IN') . " ({$marks})";
                array_push($query, ...$scenes);
            }
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (Database::one(
            "SELECT COUNT(*) AS total FROM user_wallet_logs WHERE {$whereSql}",
            $query
        )['total'] ?? 0);
        $items = Database::all(
            "SELECT id, asset_type, change_value, before_value, after_value, scene,
                    ref_type, ref_id, remark, created_at
             FROM user_wallet_logs WHERE {$whereSql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            $query
        );
        foreach ($items as &$item) $item = self::decorate($item);
        unset($item);

        $summaryRows = Database::all(
            "SELECT asset_type,
                    SUM(CASE WHEN change_value > 0 THEN change_value ELSE 0 END) AS income,
                    ABS(SUM(CASE WHEN change_value < 0 THEN change_value ELSE 0 END)) AS expense,
                    SUM(change_value) AS net
             FROM user_wallet_logs WHERE {$whereSql} GROUP BY asset_type ORDER BY asset_type",
            $query
        );
        $summary = [];
        foreach ($summaryRows as $row) {
            $asset = (string) $row['asset_type'];
            $summary[] = [
                'asset_code' => $asset === 'integral' ? 'activity_credit' : $asset,
                'asset_name' => self::assetName($asset),
                'income' => (float) $row['income'],
                'expense' => (float) $row['expense'],
                'net' => (float) $row['net'],
            ];
        }

        $scopeSql = implode(' AND ', $categoryWhere);
        $sceneCounts = Database::all(
            "SELECT scene, COUNT(*) AS total FROM user_wallet_logs WHERE {$scopeSql} GROUP BY scene",
            $categoryQuery
        );
        $counts = array_fill_keys(array_keys(self::CATEGORY_NAMES), 0);
        foreach ($sceneCounts as $row) {
            $count = (int) $row['total'];
            $counts['all'] += $count;
            $counts[self::categoryForScene((string) $row['scene'])] += $count;
        }
        $categories = [];
        foreach (self::CATEGORY_NAMES as $code => $name) {
            $categories[] = ['code' => $code, 'name' => $name, 'count' => $counts[$code] ?? 0];
        }

        $data = Pagination::data($items, $total, $page, $limit);
        $data['summary'] = $summary;
        $data['categories'] = $categories;
        $data['active_category'] = $category;
        return $data;
    }

    public static function decorate(array $row): array
    {
        $scene = (string) ($row['scene'] ?? '');
        $asset = (string) ($row['asset_type'] ?? '');
        $change = (float) ($row['change_value'] ?? 0);
        $row['asset_code'] = $asset === 'integral' ? 'activity_credit' : $asset;
        $row['asset_name'] = self::assetName($asset);
        $row['category_code'] = self::categoryForScene($scene);
        $row['category_name'] = self::CATEGORY_NAMES[$row['category_code']];
        $row['scene_name'] = self::SCENE_NAMES[$scene] ?? ((string) ($row['remark'] ?? '') ?: '资产变动');
        $row['direction'] = $change > 0 ? 'income' : ($change < 0 ? 'expense' : 'neutral');
        $row['direction_name'] = $change > 0 ? '收入' : ($change < 0 ? '支出' : '无变动');
        $row['change_value'] = $change;
        $row['before_value'] = (float) ($row['before_value'] ?? 0);
        $row['after_value'] = (float) ($row['after_value'] ?? 0);
        $row['amount_text'] = ($change > 0 ? '+' : '') . self::amount($change) . ' ' . $row['asset_name'];
        $row['trace_no'] = 'ZJ-' . str_replace('-', '', substr((string) ($row['created_at'] ?? ''), 0, 10))
            . '-' . str_pad((string) ((int) ($row['id'] ?? 0)), 8, '0', STR_PAD_LEFT);
        $row['reference_type_name'] = self::referenceName((string) ($row['ref_type'] ?? ''));
        $row['reference_no'] = ((int) ($row['ref_id'] ?? 0)) > 0
            ? $row['reference_type_name'] . ' #' . (int) $row['ref_id'] : '';
        return $row;
    }

    public static function categoryForScene(string $scene): string
    {
        foreach (self::CATEGORY_SCENES as $category => $scenes) {
            if (in_array($scene, $scenes, true)) return $category;
        }
        return 'other';
    }

    private static function knownScenes(): array
    {
        $known = [];
        foreach (self::CATEGORY_SCENES as $scenes) array_push($known, ...$scenes);
        return array_values(array_unique($known));
    }

    private static function assetName(string $asset): string
    {
        return self::ASSET_NAMES[$asset] ?? '其他资产';
    }

    private static function referenceName(string $type): string
    {
        return [
            'red_packet' => '红包', 'transfer' => '转账', 'gift' => '礼物',
            'post' => '帖子', 'comment' => '评论', 'bounty' => '悬赏',
            'order' => '订单', 'resource' => '资源', 'withdrawal' => '提现',
            'payment' => '支付单', 'card' => '卡密', 'user' => '用户',
        ][$type] ?? ($type === '' ? '业务记录' : $type);
    }

    private static function amount(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
