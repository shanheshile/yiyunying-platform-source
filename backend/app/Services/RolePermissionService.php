<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\Database;
use Yiyunying\Core\HttpException;

final class RolePermissionService
{
    private const PLATFORM_DEFINITIONS = [
        ['code' => 'admins.manage', 'title' => '管理员管理', 'group' => '组织与账号', 'description' => '创建、编辑、封禁和删除本分支管理员。'],
        ['code' => 'admins.permissions', 'title' => '管理员授权', 'group' => '组织与账号', 'description' => '调整本分支管理员的功能权限。'],
        ['code' => 'admins.impersonate', 'title' => '管理员代管', 'group' => '组织与账号', 'description' => '签发受审计的临时代管令牌进入管理员后台。'],
        ['code' => 'data.view', 'title' => '数据查看', 'group' => '数据与审计', 'description' => '查看本分支统计、日志和业务概览。'],
        ['code' => 'data.manage', 'title' => '数据管理', 'group' => '数据与审计', 'description' => '管理本分支应用、用户和业务数据。'],
        ['code' => 'settings.manage', 'title' => '平台设置', 'group' => '平台配置', 'description' => '修改本分支注册、额度和默认规则。'],
        ['code' => 'governance.manage', 'title' => '强制规则', 'group' => '平台配置', 'description' => '向本分支下级下发允许、禁止或强制同步规则。'],
        ['code' => 'billing.manage', 'title' => '余额与计费', 'group' => '资产与运营', 'description' => '管理充值、兑换、余额和计费记录。'],
        ['code' => 'reward_management', 'title' => '奖励规则', 'group' => '资产与运营', 'description' => '配置注册、签到、邀请和内容行为奖励。'],
        ['code' => 'activities.manage', 'title' => '活动管理', 'group' => '内容与运营', 'description' => '管理红包、抽奖、投票和悬赏活动。'],
        ['code' => 'feedback.manage', 'title' => '反馈处理', 'group' => '内容与运营', 'description' => '查看和处理下级反馈与申诉。'],
        ['code' => 'ai.manage', 'title' => '智能知识库', 'group' => '内容与运营', 'description' => '管理智能机器人知识、状态和回答来源。'],
        ['code' => 'software.manage', 'title' => '更新与维护', 'group' => '平台配置', 'description' => '发布软件更新、维护通知和节日主题。'],
    ];

    private const ADMIN_DEFINITIONS = [
        ['code' => 'apps.manage', 'title' => '应用管理', 'group' => '应用与用户', 'description' => '创建、启停和维护自己的应用。'],
        ['code' => 'users.manage', 'title' => '用户管理', 'group' => '应用与用户', 'description' => '管理应用用户、资料、状态和个人权限。'],
        ['code' => 'downstream_users.access', 'title' => '下级用户审计', 'group' => '应用与用户', 'description' => '查看下级用户概览、关系和行为记录。'],
        ['code' => 'documents.manage', 'title' => '文档管理', 'group' => '内容管理', 'description' => '新增、查询、编辑和删除远程文档。'],
        ['code' => 'content.manage', 'title' => '公告与版本', 'group' => '内容管理', 'description' => '管理公告、版本、维护和远程配置。'],
        ['code' => 'resources.manage', 'title' => '资源与商店', 'group' => '内容管理', 'description' => '管理应用、源码、商品、分类和订单。'],
        ['code' => 'forum.manage', 'title' => '论坛与审核', 'group' => '社区管理', 'description' => '管理板块、帖子、评论、标签和审核。'],
        ['code' => 'communication.manage', 'title' => '聊天与群组', 'group' => '社区管理', 'description' => '查看并管理私聊、群聊、聊天室和客服会话。'],
        ['code' => 'activities.manage', 'title' => '活动管理', 'group' => '运营管理', 'description' => '管理红包、抽奖、投票、悬赏和奖励。'],
        ['code' => 'commerce.manage', 'title' => '交易与订单', 'group' => '运营管理', 'description' => '管理余额、支付、商品订单和交易追踪。'],
        ['code' => 'cards.manage', 'title' => '卡密管理', 'group' => '运营管理', 'description' => '创建、查询、停用和核销卡密。'],
        ['code' => 'files.manage', 'title' => '文件管理', 'group' => '内容管理', 'description' => '管理上传文件、媒体、下载和存储记录。'],
        ['code' => 'statistics.view', 'title' => '统计与日志', 'group' => '数据与审计', 'description' => '查看应用统计、登录记录和操作日志。'],
    ];

    private const USER_DEFINITIONS = [
        ['code' => 'user_account', 'title' => '账号服务', 'group' => '账号与资料', 'description' => '登录、退出、密码和账号基础服务。'],
        ['code' => 'user_profile', 'title' => '个人资料', 'group' => '账号与资料', 'description' => '头像、昵称、资料卡和动态隐私。'],
        ['code' => 'sign_invite', 'title' => '签到与邀请', 'group' => '账号与资料', 'description' => '签到、邀请码和邀请奖励。'],
        ['code' => 'documents', 'title' => '笔记与文档', 'group' => '内容功能', 'description' => '使用笔记、附件和授权文档能力。'],
        ['code' => 'notices', 'title' => '公告通知', 'group' => '内容功能', 'description' => '查看公告、维护和更新通知。'],
        ['code' => 'resources', 'title' => '资源中心', 'group' => '内容功能', 'description' => '浏览应用商店和源码商城。'],
        ['code' => 'store', 'title' => '商品商店', 'group' => '交易与活动', 'description' => '浏览商品、下单和查看订单。'],
        ['code' => 'shop', 'title' => '余额商店', 'group' => '交易与活动', 'description' => '使用余额购买虚拟商品。'],
        ['code' => 'forum', 'title' => '论坛社区', 'group' => '社区与社交', 'description' => '浏览板块、发帖、评论和互动。'],
        ['code' => 'messages', 'title' => '私聊消息', 'group' => '社区与社交', 'description' => '发送和接收好友私聊消息。'],
        ['code' => 'chat_rooms', 'title' => '群聊与聊天室', 'group' => '社区与社交', 'description' => '加入群聊、聊天室并参与交流。'],
        ['code' => 'social', 'title' => '好友与动态', 'group' => '社区与社交', 'description' => '好友、关注、粉丝和生活动态。'],
        ['code' => 'service', 'title' => '在线客服', 'group' => '消息与服务', 'description' => '与本应用客服进行会话。'],
        ['code' => 'bot', 'title' => '智能机器人', 'group' => '消息与服务', 'description' => '使用智能问答、天气和知识服务。'],
        ['code' => 'notifications', 'title' => '通知中心', 'group' => '消息与服务', 'description' => '接收系统、动态和业务通知。'],
        ['code' => 'cards', 'title' => '卡密兑换', 'group' => '交易与活动', 'description' => '兑换卡密和查看兑换结果。'],
        ['code' => 'payments', 'title' => '余额与支付', 'group' => '交易与活动', 'description' => '余额账单、转账和支付。'],
        ['code' => 'withdrawals', 'title' => '提现服务', 'group' => '交易与活动', 'description' => '提交提现并查看处理记录。'],
        ['code' => 'red_packets', 'title' => '红包', 'group' => '交易与活动', 'description' => '发送、领取和查看红包详情。'],
        ['code' => 'lottery', 'title' => '抽奖', 'group' => '交易与活动', 'description' => '参与抽奖并查看中奖记录。'],
        ['code' => 'votes', 'title' => '投票', 'group' => '交易与活动', 'description' => '发起或参与单选、多选投票。'],
        ['code' => 'bounties', 'title' => '悬赏', 'group' => '内容功能', 'description' => '发布、参与和管理悬赏。'],
        ['code' => 'feedback', 'title' => '反馈与举报', 'group' => '消息与服务', 'description' => '提交反馈、举报和申诉。'],
        ['code' => 'remote_files', 'title' => '远程文件', 'group' => '文件与扩展', 'description' => '上传、预览、搜索和下载文件。'],
        ['code' => 'chat_extensions', 'title' => '聊天扩展', 'group' => '文件与扩展', 'description' => '图片、视频、语音、文件、名片和定位。'],
        ['code' => 'level_forum', 'title' => '等级论坛', 'group' => '社区与社交', 'description' => '按用户等级开放对应社区内容。'],
        ['code' => 'balance_document_purchase', 'title' => '余额购买文档', 'group' => '交易与活动', 'description' => '允许使用余额购买授权文档。'],
        ['code' => 'balance_membership_purchase', 'title' => '余额购买会员', 'group' => '交易与活动', 'description' => '允许使用余额购买会员服务。'],
        ['code' => 'hierarchical_activities', 'title' => '分级活动', 'group' => '交易与活动', 'description' => '查看和领取上级定向发布的活动。'],
    ];

    public static function platformDefinitions(): array
    {
        return self::PLATFORM_DEFINITIONS;
    }

    public static function adminDefinitions(): array
    {
        return self::ADMIN_DEFINITIONS;
    }

    public static function userDefinitions(): array
    {
        return self::USER_DEFINITIONS;
    }

    public static function ownerPayload(array $owner): array
    {
        $items = [];
        $map = [];
        foreach (self::PLATFORM_DEFINITIONS as $definition) {
            $code = $definition['code'];
            $map[$code] = ['allowed' => true, 'config' => null];
            $items[] = self::item($definition, true, true, false, false, '系统总控内置权限', null, null);
        }
        return self::payload('platform', (int) $owner['id'], 1, (string) (($owner['nickname'] ?? '') ?: ($owner['account'] ?? '平台总控')), (string) ($owner['account'] ?? ''), 1, $items, $map, [
            'status' => (int) ($owner['status'] ?? 0),
            'unlimited' => true,
            'editable' => false,
        ]);
    }

    public static function platformPayload(array $operator, int $actorLevel, bool $editable = true): array
    {
        $stored = json_decode((string) ($operator['permissions_json'] ?? ''), true);
        $stored = is_array($stored) ? $stored : [];
        $items = [];
        $map = [];
        foreach (self::PLATFORM_DEFINITIONS as $definition) {
            $code = $definition['code'];
            $value = $stored[$code] ?? true;
            $allowed = is_array($value) ? (bool) ($value['allowed'] ?? true) : (bool) $value;
            $config = is_array($value) && is_array($value['config'] ?? null) ? $value['config'] : null;
            $map[$code] = ['allowed' => $allowed, 'config' => $config];
            $items[] = self::item($definition, $allowed, $allowed, $editable, false, '1 级总控授权', null, $config);
        }
        return self::payload('platform', (int) $operator['id'], 2, (string) ($operator['nickname'] ?: $operator['account']), (string) $operator['account'], $actorLevel, $items, $map, [
            'status' => (int) ($operator['status'] ?? 0),
            'unlimited' => false,
            'editable' => $editable,
        ]);
    }

    public static function adminPayload(array $admin, int $actorLevel, bool $editable = true): array
    {
        $rows = Database::all('SELECT permission_code, allowed, config_json FROM admin_permissions WHERE admin_id = ?', [(int) $admin['id']]);
        $stored = [];
        foreach ($rows as $row) {
            $stored[(string) $row['permission_code']] = [
                'allowed' => (bool) $row['allowed'],
                'config' => self::decodeObject($row['config_json'] ?? null),
            ];
        }
        $items = [];
        $map = [];
        foreach (self::ADMIN_DEFINITIONS as $definition) {
            $code = $definition['code'];
            $value = $stored[$code] ?? ['allowed' => true, 'config' => null];
            $allowed = (bool) $value['allowed'];
            $map[$code] = $value;
            $source = $actorLevel === 1
                ? '1 级总控授权'
                : ($actorLevel === 2 ? '2 级授权平台授权' : '所属上级平台授权');
            $items[] = self::item($definition, $allowed, $allowed, $editable, false, $source, null, $value['config']);
        }
        return self::payload('admin', (int) $admin['id'], 3, (string) ($admin['nickname'] ?: $admin['account']), (string) $admin['account'], $actorLevel, $items, $map, [
            'status' => (int) ($admin['status'] ?? 0),
            'membership_status' => (string) ($admin['membership_status'] ?? ''),
            'platform_id' => (int) ($admin['platform_id'] ?? 0),
            'editable' => $editable,
        ]);
    }

    public static function userPayload(array $user, int $actorLevel): array
    {
        $appId = (int) $user['app_id'];
        $userId = (int) $user['id'];
        $flags = AppService::features($appId);
        $rows = Database::all(
            'SELECT feature_code, enabled, config_json FROM user_feature_permissions WHERE app_id = ? AND user_id = ?',
            [$appId, $userId]
        );
        $stored = [];
        foreach ($rows as $row) {
            $stored[(string) $row['feature_code']] = [
                'allowed' => (bool) $row['enabled'],
                'config' => self::decodeObject($row['config_json'] ?? null),
            ];
        }
        $items = [];
        $map = [];
        foreach (self::USER_DEFINITIONS as $definition) {
            $code = $definition['code'];
            $override = $stored[$code] ?? ['allowed' => true, 'config' => null];
            $appAllowed = !isset($flags[$code]) || (bool) $flags[$code]['enabled'];
            $configured = (bool) $override['allowed'];
            $policy = GovernanceService::effectiveFeatureForApp($appId, $code, $appAllowed && $configured, $userId);
            $locked = (bool) $policy['locked'] || !$appAllowed;
            $effective = (bool) $policy['effective_enabled'];
            $source = (bool) $policy['locked']
                ? '上级平台强制规则'
                : (!$appAllowed ? '应用功能已关闭' : (isset($stored[$code]) ? '用户单独设置' : '应用默认设置'));
            $reason = (bool) $policy['locked']
                ? '该权限已由上级平台强制锁定，当前层级不能修改。'
                : (!$appAllowed ? '应用级功能已关闭，请先在应用功能设置中启用。' : null);
            $map[$code] = ['allowed' => $configured, 'effective' => $effective, 'config' => $override['config']];
            $editable = $actorLevel === 1 || !$locked;
            if ($actorLevel === 1 && $locked) {
                $reason = '总控可以修改本层配置；当前最终状态仍受“' . $source . '”约束，如需立即生效请同时调整对应上级规则。';
            }
            $items[] = self::item($definition, $configured, $effective, $editable, $locked, $source, $reason, $override['config'], $policy);
        }
        return self::payload('user', $userId, 4, (string) (($user['nickname'] ?? '') ?: ($user['account'] ?? '用户')), (string) ($user['account'] ?? ''), $actorLevel, $items, $map, [
            'status' => (int) ($user['status'] ?? 0),
            'app_id' => $appId,
            'admin_id' => (int) $user['admin_id'],
            'uid' => (string) ($user['uid'] ?? ''),
        ]);
    }

    public static function normalizePlatformInput(array $permissions): array
    {
        return self::normalizeInput($permissions, self::PLATFORM_DEFINITIONS);
    }

    public static function normalizeAdminInput(array $permissions): array
    {
        return self::normalizeInput($permissions, self::ADMIN_DEFINITIONS);
    }

    public static function normalizeUserInput(array $permissions): array
    {
        return self::normalizeInput($permissions, self::USER_DEFINITIONS);
    }

    public static function assertUserPermissionMutable(int $appId, int $userId, string $code, int $actorLevel = 3): void
    {
        if ($actorLevel === 1) {
            return;
        }
        $flags = AppService::features($appId);
        if (isset($flags[$code]) && !(bool) $flags[$code]['enabled']) {
            throw new HttpException('“' . self::titleFor(self::USER_DEFINITIONS, $code) . '”已在应用级关闭，请先启用应用功能', 403, 403);
        }
        $policy = GovernanceService::effectiveFeatureForApp($appId, $code, true, $userId);
        if ((bool) $policy['locked']) {
            throw new HttpException('“' . self::titleFor(self::USER_DEFINITIONS, $code) . '”已被上级平台强制锁定', 403, 403, $policy);
        }
    }

    private static function normalizeInput(array $permissions, array $definitions): array
    {
        if ($permissions === []) {
            throw new HttpException('请至少选择一项权限', 0, 422);
        }
        $known = array_column($definitions, 'code');
        $result = [];
        foreach ($permissions as $code => $value) {
            $code = (string) $code;
            if (!in_array($code, $known, true)) {
                throw new HttpException('不支持的权限：' . $code, 0, 422);
            }
            $result[$code] = [
                'allowed' => is_array($value) ? (bool) ($value['allowed'] ?? true) : (bool) $value,
                'config' => is_array($value) && is_array($value['config'] ?? null) ? $value['config'] : null,
            ];
        }
        return $result;
    }

    private static function payload(string $type, int $id, int $level, string $name, string $account, int $actorLevel, array $items, array $map, array $state): array
    {
        $groups = [];
        foreach ($items as $item) {
            $group = $item['group'];
            if (!isset($groups[$group])) {
                $groups[$group] = ['code' => 'group_' . (count($groups) + 1), 'title' => $group, 'items' => []];
            }
            $groups[$group]['items'][] = $item;
        }
        $enabled = count(array_filter($items, static fn(array $item): bool => (bool) $item['effective_enabled']));
        $locked = count(array_filter($items, static fn(array $item): bool => (bool) $item['locked']));
        return [
            'target' => ['type' => $type, 'id' => $id, 'level' => $level, 'name' => $name, 'account' => $account],
            'actor_level' => $actorLevel,
            'summary' => ['total' => count($items), 'enabled' => $enabled, 'disabled' => count($items) - $enabled, 'locked' => $locked],
            'groups' => array_values($groups),
            'permissions' => $map,
            'access_state' => $state,
        ];
    }

    private static function item(array $definition, bool $configured, bool $effective, bool $editable, bool $locked, string $source, ?string $reason, ?array $config, array $policy = []): array
    {
        return [
            'code' => $definition['code'],
            'title' => $definition['title'],
            'description' => $definition['description'],
            'group' => $definition['group'],
            'configured_enabled' => $configured,
            'effective_enabled' => $effective,
            'editable' => $editable,
            'locked' => $locked,
            'state_text' => $effective ? '允许使用' : '已关闭',
            'source' => $source,
            'lock_reason' => $reason,
            'config' => $config,
            'source_rule_id' => $policy['source_rule_id'] ?? null,
            'forced_by_level' => $policy['forced_by_level'] ?? null,
        ];
    }

    private static function decodeObject(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function titleFor(array $definitions, string $code): string
    {
        foreach ($definitions as $definition) {
            if ($definition['code'] === $code) {
                return $definition['title'];
            }
        }
        return $code;
    }
}