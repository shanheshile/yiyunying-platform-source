<?php
declare(strict_types=1);

namespace Yiyunying\Services;

final class SettingDescriptorService
{
    public static function describe(array $settings): array
    {
        $result = [];
        foreach ($settings as $key => $value) {
            $result[$key] = self::one((string) $key, $value);
        }
        return $result;
    }

    private static function one(string $key, $value): array
    {
        $known = [
            'admin_registration_enabled' => ['管理员注册', '', '关闭后，下级管理员不能再注册；已有账号不受影响。'],
            'admin_registration_daily_limit' => ['管理员每日注册上限', '次/天', '当前平台全部管理员账号每天最多成功注册的数量。0 表示禁止注册。'],
            'admin_registration_ip_daily_limit' => ['同一 IP 每日注册上限', '次/IP/天', '同一公网 IP 每个自然日允许成功注册管理员账号的数量。'],
            'admin_registration_ip_total_limit' => ['同一 IP 累计注册上限', '次/IP', '同一公网 IP 在整个生命周期内允许注册的管理员账号总数。'],
            'admin_account_min_length' => ['管理员账号最短长度', '个字符', 'L3 管理员注册或创建账号时允许的最少字符数。'],
            'admin_account_max_length' => ['管理员账号最长长度', '个字符', 'L3 管理员注册或创建账号时允许的最多字符数。'],
            'admin_daily_register_limit' => ['管理员每日注册上限', '个/天', '当前平台每个自然日最多允许成功注册的 L3 管理员数量。0 表示禁止注册。'],
            'admin_ip_daily_register_limit' => ['同一网络地址每日管理员注册上限', '个/地址/天', '同一公网地址每个自然日最多允许成功注册的 L3 管理员数量。'],
            'admin_ip_total_register_limit' => ['同一网络地址管理员注册总上限', '个/地址', '同一公网地址在当前平台累计允许注册的 L3 管理员数量。'],
            'admin_login_enabled' => ['允许管理员登录', '开关', '关闭后当前平台下的 L3 管理员不能登录，已有数据仍由上级管理。'],
            'admin_membership_required' => ['管理员必须有有效会员', '开关', '开启后 L3 管理员会员到期会阻断其后台及所属应用用户操作。'],
            'admin_vip_only' => ['仅允许有效会员管理员使用', '开关', '开启后只有达到当前平台要求会员等级的 L3 管理员可以使用后台。'],
            'admin_balance_purchase_enabled' => ['允许管理员购买余额', '开关', '控制 L3 管理员是否可以通过平台提供的渠道购买或充值余额。'],
            'admin_document_purchase_enabled' => ['允许管理员购买远程文档额度', '开关', '控制 L3 管理员是否可以购买额外的远程文档额度。'],
            'admin_membership_purchase_enabled' => ['允许管理员购买会员', '开关', '控制 L3 管理员是否可以购买或续费后台会员。'],
            'admin_free_trial_days' => ['新管理员赠送会员', '天', '管理员注册成功后自动增加的会员有效期。'],
            'admin_free_app_quota' => ['新管理员赠送应用额度', '个', '管理员注册成功后可免费创建的应用数量。'],
            'admin_free_remote_document_quota' => ['新管理员赠送远程文档额度', '份', '管理员注册成功后获得的远程文档可用数量。'],
            'admin_free_balance' => ['新管理员赠送余额', '余额', '管理员注册成功后写入平台钱包的初始余额。'],
            'admin_free_integral' => ['新管理员赠送活动额度', '额度', 'L3 管理员注册成功后获得的初始活动兑换额度。'],
            'operator_free_trial_days' => ['新授权平台赠送会员', '天', 'L2 授权平台创建成功后自动增加的有效期。'],
            'operator_free_admin_quota' => ['新授权平台赠送管理员额度', '个', 'L2 授权平台可以创建的 L3 管理员初始数量。'],
            'operator_free_balance' => ['新授权平台赠送余额', '余额', 'L2 授权平台创建成功后获得的初始余额。'],
            'authorized_platform_membership_required' => ['授权平台必须有有效会员', '开关', '开启后 L2 会员到期会阻断其自身、所属 L3 及其应用用户的操作。'],
            'authorized_platform_vip_only' => ['仅允许有效会员授权平台使用', '开关', '开启后只有达到要求会员等级的 L2 授权平台可以使用后台。'],
            'balance_display_name' => ['余额显示名称', '文本', '设置 1/2/3 级管理端统一展示的钱包资产名称，默认显示为“余额”。'],
            'balance_exchange_enabled' => ['允许余额兑换权益', '开关', '控制 L2/L3 是否可以使用平台余额兑换会员、应用或文档等权益。'],
            'balance_exchange_admin_daily_limit' => ['管理员每日余额兑换上限', '余额/天', '单个 L3 管理员每个自然日最多可用于权益兑换的余额。0 表示不单独限额。'],
            'balance_exchange_max_quantity_per_order' => ['余额兑换单次最大数量', '份/单', '一次余额兑换订单最多允许购买的权益数量。'],
            'point_exchange_enabled' => ['允许活动额度兑换权益', '开关', '控制 L2/L3 是否可以使用活动额度兑换会员、应用或文档等权益。'],
            'point_exchange_admin_daily_integral_limit' => ['管理员每日活动额度兑换上限', '额度/天', '单个 L3 管理员每个自然日最多可用于兑换的活动额度。0 表示不单独限额。'],
            'point_exchange_max_quantity_per_order' => ['活动额度兑换单次最大数量', '份/单', '一次活动额度兑换订单最多允许购买的权益数量。'],
            'data_console_enabled' => ['启用数据总控台', '开关', '控制平台是否开放可视化数据表查看与增删查改入口；仅具备对应权限的上级可使用。'],
            'downstream_user_enabled' => ['允许下游应用用户使用', '开关', '关闭后当前平台整棵分支下的 L4 用户不能登录或调用业务接口。'],
            'hierarchical_activities_enabled' => ['允许分级发布活动', '开关', '控制上级是否可以向指定下级范围发布红包、抽奖、悬赏和投票等活动。'],
            'hierarchical_activity_max_budget' => ['分级活动最高预算', '余额/活动', '单个分级红包、抽奖或悬赏活动允许设置的最高总预算。'],
            'default_chat_poll_interval_ms' => ['默认聊天刷新间隔', '毫秒', '未单独配置时，客户端获取新消息的默认间隔。1000 毫秒等于 1 秒。'],
            'min_chat_poll_interval_ms' => ['最短聊天刷新间隔', '毫秒', '下级允许设置的最小值，用于限制过于频繁的接口请求。'],
            'max_chat_poll_interval_ms' => ['最长聊天刷新间隔', '毫秒', '下级允许设置的最大值，避免消息刷新过慢。'],
            'force_chat_poll_interval' => ['强制聊天刷新间隔', '', '开启后，下级只能使用当前平台规定的默认刷新间隔，不能自行修改。'],
            'default_message_recall_seconds' => ['默认消息撤回时限', '秒', '私聊和群聊默认允许撤回的时间。120 秒等于 2 分钟；0 表示关闭普通用户撤回。'],
            'force_message_recall_seconds' => ['强制消息撤回时限', '', '开启后，下级应用必须使用当前平台规定的撤回时限。'],
            'allow_child_message_recall_override' => ['允许下级修改撤回时限', '', '关闭后，L2/L3 不能自定义消息撤回秒数。'],
            'message_recall_inherit' => ['继承上级撤回规则', '', '开启时使用上级规则；关闭时使用当前层级自定义规则。'],
            'default_relationship_request_valid_days' => ['默认申请与邀请有效期', '天', '好友申请、群聊邀请和入群申请默认保留的有效天数，默认 30 天。'],
            'force_relationship_request_valid_days' => ['强制申请与邀请有效期', '开关', '开启后下级平台和应用必须使用当前层级规定的有效期。'],
            'allow_child_relationship_request_valid_days_override' => ['允许下级修改申请有效期', '开关', '关闭后下级不能自定义好友申请、群聊邀请和入群申请的有效天数。'],
            'relationship_request_valid_days_inherit' => ['继承申请与邀请有效期', '开关', '开启后使用上级默认值；关闭后使用当前平台或应用自定义值。'],
            'registration_enabled' => ['用户注册', '', '关闭后，当前应用的新用户注册入口不可用。'],
            'registration_nickname_enabled' => ['注册昵称', '', '开启后注册页显示昵称；账号是登录用户名，昵称是对外展示名称。'],
            'registration_nickname_required' => ['注册昵称必填', '', '只有启用注册昵称后才能开启。'],
            'registration_email_enabled' => ['注册邮箱', '', '开启后显示邮箱输入和邮箱验证码；每个邮箱只能绑定一个 UID。'],
            'registration_email_required' => ['注册邮箱必填', '', '开启后必须完成邮箱验证才能注册。'],
            'registration_phone_enabled' => ['注册手机号', '', '开启后注册页显示手机号；每个手机号只能绑定一个 UID。'],
            'registration_phone_required' => ['注册手机号必填', '', '只有启用注册手机号后才能开启。'],
            'identity_unbind_enabled' => ['允许申请解绑', '', '用户可提交邮箱或手机号解绑申请，必须由所属管理员审核。'],
            'accept_stranger_messages_default' => ['陌生人消息默认开关', '开关', '新注册用户是否默认接收非好友发来的私聊消息，用户可在允许范围内自行修改。'],
            'account_min_length' => ['用户账号最短长度', '个字符', 'L4 用户注册时自定义登录账号允许的最少字符数。'],
            'account_max_length' => ['用户账号最长长度', '个字符', 'L4 用户注册时自定义登录账号允许的最多字符数。'],
            'daily_register_limit' => ['应用每日注册上限', '个/天', '当前应用每个自然日允许成功注册的 L4 用户数量。0 表示禁止注册。'],
            'register_ip_daily_limit' => ['同一网络地址每日用户注册上限', '个/地址/天', '同一公网地址每个自然日在当前应用允许注册的 L4 用户数量。'],
            'login_enabled' => ['用户登录', '', '关闭后，当前应用普通用户不能登录；管理端仍可处理数据。'],
            'user_login_vip_only' => ['仅允许有效会员用户登录', '开关', '开启后只有会员仍在有效期内的 L4 用户可以登录当前应用。'],
            'user_free_vip_days' => ['新用户赠送会员', '天', 'L4 用户注册成功后自动获得的会员有效天数。0 表示不赠送。'],
            'initial_document_credit' => ['新用户赠送笔记额度', '份', 'L4 用户注册成功后获得的初始笔记可创建额度。'],
            'user_initial_balance' => ['新用户赠送余额', '余额', 'L4 用户注册成功后写入其应用钱包的初始余额。'],
            'user_initial_activity_credit' => ['新用户赠送活动额度', '额度', 'L4 用户注册成功后获得的初始活动兑换额度。'],
            'economy_primary_asset' => ['应用主要资产类型', '选项', '决定商城、活动和奖励默认使用余额还是其他应用资产；不改变笔记额度的独立性。'],
            'balance_activity_enabled' => ['活动允许使用余额', '开关', '控制抽奖、红包、悬赏等互动活动是否可以使用用户余额。'],
            'balance_document_purchase_enabled' => ['允许余额购买笔记额度', '开关', '开启后用户可以按管理员设置的价格使用余额购买额外笔记额度。'],
            'document_credit_balance_price' => ['单份笔记额度价格', '余额/份', '使用余额购买一份笔记额度时需要扣除的余额。'],
            'document_credit_separate' => ['笔记额度独立计算', '开关', '开启后笔记额度与用户余额分开记录，不会因余额变化自动增减。'],
            'balance_membership_purchase_enabled' => ['允许余额购买会员', '开关', '开启后用户可以使用余额购买或续费应用会员。'],
            'vip_day_balance_price' => ['会员每日价格', '余额/天', '使用余额购买一天应用会员需要扣除的余额。'],
            'document_enabled' => ['启用用户笔记', '开关', '控制 L4 用户是否可以使用笔记的创建、查看、修改、删除和分享功能。'],
            'document_share_enabled' => ['允许笔记公开分享', '开关', '控制用户是否可以生成分享码并向未登录访客公开指定笔记。'],
            'chat_poll_interval_ms' => ['当前应用聊天刷新间隔', '毫秒', '用户端获取私聊和群聊新消息的间隔，必须位于上级允许范围内。'],
            'message_recall_seconds' => ['当前应用消息撤回时限', '秒', '用户在私聊和群聊中可撤回消息的最长时间。'],
            'relationship_request_valid_days' => ['当前应用申请与邀请有效期', '天', '好友申请、群聊邀请和入群申请的有效天数；过期记录仍可查看但不能处理。'],
            'document_create_cost' => ['创建笔记消耗额度', '份/次', '用户每创建一份笔记需要扣除的文档额度。0 表示免费。'],
            'document_max_count' => ['用户笔记数量上限', '份/用户', '每个用户最多可以保留的未删除笔记数量。'],
            'user_group_max_owned' => ['用户建群上限', '个/用户', '每个用户最多可以作为群主创建的群聊数量。'],
            'user_group_create_enabled' => ['允许用户创建群聊', '开关', '关闭后普通用户只能加入已有群聊，不能自行创建新群。'],
            'user_chatroom_create_enabled' => ['允许用户创建聊天室', '开关', '关闭后普通用户只能加入已有聊天室，不能自行创建新聊天室。'],
            'user_chatroom_max_owned' => ['用户创建聊天室上限', '个/用户', '每个用户最多可以作为创建者拥有的聊天室数量。'],
            'chatroom_default_max_members' => ['聊天室默认人数上限', '人/聊天室', '新建聊天室默认允许加入的最大成员数量。'],
            'private_message_enabled' => ['启用好友私聊', '开关', '控制用户之间是否可以发送私聊消息；不影响客服和机器人入口。'],
            'group_default_max_members' => ['群聊默认人数上限', '人/群', '新建群聊时默认允许加入的最大成员数量。'],
            'invite_enabled' => ['启用邀请码', '开关', '控制用户是否可以生成邀请码并邀请新用户注册。'],
            'invite_reward_balance' => ['邀请奖励余额', '余额/人', '每成功邀请一个符合条件的新用户时赠送给邀请人的余额。'],
            'invite_reward_integral' => ['邀请奖励活动额度', '额度/人', '每成功邀请一个符合条件的新用户时赠送给邀请人的活动额度。'],
            'sign_enabled' => ['启用每日签到', '开关', '控制用户是否可以进行每日签到并领取管理员配置的奖励。'],
            'sign_reward_balance' => ['签到奖励余额', '余额/天', '用户每日首次签到成功后获得的余额。'],
            'sign_reward_integral' => ['签到奖励活动额度', '额度/天', '用户每日首次签到成功后获得的活动额度。'],
            'sign_reward_credit' => ['签到奖励笔记额度', '份/天', '用户每日首次签到成功后获得的笔记额度。'],
            'sign_reward_experience' => ['签到奖励经验', '经验/天', '用户每日首次签到成功后获得的经验值。'],
            'card_redeem_enabled' => ['启用卡密兑换', '开关', '控制用户是否可以使用管理员生成的卡密兑换余额、会员或其他权益。'],
            'card_login_enabled' => ['启用登录卡密', '开关', '控制用户是否可以用登录卡密首次绑定设备，并使用设备密钥安全自动登录。'],
            'public_app_statistics_enabled' => ['公开应用统计', '开关', '控制未登录客户端是否可以查看当前应用的访问量、用户数和在线人数汇总。'],
            'heartbeat_online_seconds' => ['用户在线有效时长', '秒', '用户最后一次心跳后保持在线状态的时间；客户端会按该值的一半建议间隔继续上报。'],
            'password_reset_enabled' => ['允许找回密码', '开关', '控制用户是否可以通过已启用的身份验证方式重置登录密码。'],
            'profile_edit_enabled' => ['允许修改个人资料', '开关', '控制用户是否可以修改昵称、头像、签名及其他公开资料。'],
            'profile_public_default' => ['个人资料默认公开', '开关', '新注册用户的资料默认是否允许其他用户查看；隐私字段仍按单项规则处理。'],
            'moment_like_non_friend_visible' => ['动态点赞者对非好友可见', '开关', '关闭时仅展示当前用户与动态作者的共同好友点赞身份；开启后展示全部点赞者。点赞总数始终保留。'],
            'resource_user_submit_enabled' => ['允许用户投稿资源', '开关', '控制当前应用的普通用户能否向源码商城投稿；关闭后客户端入口会禁用，后端也会强制拒绝提交。'],
            'resource_submit_audit' => ['资源投稿需要审核', '开关', '开启后用户投稿的资源必须由管理员审核通过后才在资源大厅公开。'],
            'forum_post_audit' => ['论坛发帖需要审核', '开关', '开启后新帖子必须通过管理员或版主审核后才对其他用户显示。'],
            'forum_comment_audit' => ['论坛评论需要审核', '开关', '开启后新评论先进入待审核列表；审核通过后才公开显示并计入帖子评论数。'],
            'group_restore_days' => ['已解散群聊恢复期限', '天', '群主解散群聊后允许恢复的天数。0 表示解散后不可恢复。'],
            'forum_paid_content_enabled' => ['允许帖子设置付费内容', '开关', '控制发帖人是否可以设置余额价格，购买后系统自动生成订单记录。'],
            'forum_reward_enabled' => ['允许帖子打赏', '开关', '控制用户是否可以使用应用余额给帖子作者打赏。'],
            'bounty_min_reward_balance' => ['悬赏最低余额', '余额/单', '创建使用余额结算的悬赏时允许设置的最低奖励。'],
            'bounty_max_reward_balance' => ['悬赏最高余额', '余额/单', '创建使用余额结算的悬赏时允许设置的最高奖励。'],
            'bounty_min_reward_integral' => ['悬赏最低活动额度', '额度/单', '创建使用活动额度结算的悬赏时允许设置的最低奖励。'],
            'bounty_max_reward_integral' => ['悬赏最高活动额度', '额度/单', '创建使用活动额度结算的悬赏时允许设置的最高奖励。'],
            'lottery_daily_limit' => ['用户每日抽奖上限', '次/天', '单个用户每个自然日允许参与抽奖的最大次数。0 表示禁止参与。'],
            'user_poll_create_enabled' => ['允许用户创建投票', '开关', '控制 L4 用户是否可以自定义投票活动、分类和多个选项。'],
            'wallet_transfer_enabled' => ['启用用户余额转账', '开关', '控制用户是否可以向其他符合规则的用户转账；系统始终禁止给自己转账。'],
            'wallet_transfer_max' => ['用户单笔转账上限', '余额/笔', '未设置更细规则时，用户一次转账允许输入的最大余额。'],
            'withdrawal_enabled' => ['启用余额提现申请', '开关', '控制用户是否可以把应用余额提交为提现审核申请。'],
            'withdrawal_min_amount' => ['单笔提现最低金额', '余额/笔', '用户提交一次提现申请时允许输入的最低余额。'],
            'withdrawal_max_amount' => ['单笔提现最高金额', '余额/笔', '用户提交一次提现申请时允许输入的最高余额。'],
            'upload_max_bytes' => ['兼容上传上限', '字节/文件', '旧客户端使用的通用上传上限；新版客户端优先读取各媒体分类上限。'],
            'upload_image_max_bytes' => ['图片上传上限', '字节/张', '单张图片允许上传的最大体积，默认 100 MB，可容纳 50 MB 以上原图。'],
            'upload_video_max_bytes' => ['视频上传上限', '字节/个', '单个视频允许上传的最大体积，默认 1 GB。该值不能高于 Nginx 与 PHP 的请求体上限。'],
            'upload_audio_max_bytes' => ['音频上传上限', '字节/个', '单个语音或音频允许上传的最大体积，默认 100 MB。'],
            'upload_file_max_bytes' => ['普通文件上传上限', '字节/个', '文档、压缩包等普通文件允许上传的最大体积，默认 512 MB。'],
            'media_optimize_by_default' => ['默认优化媒体', '开关', '开启后，未选择“原图/原视频”的媒体会优先生成适合聊天快速展示的优化版本。'],
            'media_original_upload_enabled' => ['允许上传原媒体', '开关', '是否允许用户选择原图或原视频上传；关闭后客户端只能发送优化版本。'],
            'sticker_optimize_enabled' => ['表情包自动优化', '开关', '开启后静态表情会生成较小的展示版本，GIF 保留动效并生成缩略图。'],
            'sticker_target_max_bytes' => ['表情包目标体积', '字节/个', '表情包优化后的建议目标体积，默认 512 KB；无法无损达到时保留可用版本。'],
            'cloud_chat_backup_enabled' => ['聊天记录云备份', '开关', '是否允许用户把选定会话和筛选后的聊天记录保存为跨设备只读快照。'],
            'cloud_chat_backup_vip_required' => ['聊天云备份会员限制', '开关', '开启后只有当前仍在会员有效期内的用户可以创建聊天云备份。'],
            'cloud_chat_backup_price' => ['聊天云备份单价', '余额/次', '每次创建聊天记录云备份需要扣除的余额，0 表示免费。'],
            'cloud_sticker_sync_enabled' => ['表情包云同步', '开关', '是否允许用户备份并在其他设备拉取自己的表情包分组。'],
            'cloud_sticker_sync_vip_required' => ['表情云同步会员限制', '开关', '开启后只有有效会员可以创建表情包云快照。'],
            'cloud_sticker_sync_price' => ['表情云同步单价', '余额/次', '每次创建表情包云快照需要扣除的余额，0 表示免费。'],
            'cloud_favorite_sync_enabled' => ['收藏云同步', '开关', '是否允许用户备份帖子、资源、文件和消息等收藏索引。'],
            'cloud_favorite_sync_vip_required' => ['收藏云同步会员限制', '开关', '开启后只有有效会员可以创建收藏云快照。'],
            'cloud_favorite_sync_price' => ['收藏云同步单价', '余额/次', '每次创建收藏云快照需要扣除的余额，0 表示免费。'],
            'cloud_backup_max_items' => ['单次云备份上限', '条/次', '单次快照允许保存的最大记录数，避免一个账号占用过多数据库空间。'],
            'cloud_backup_retention_days' => ['云备份保留期', '天', '云快照允许保留的天数；0 表示不按时间自动过期。'],
            'chat_local_cache_days' => ['聊天本地缓存天数', '天', '客户端默认保留聊天缓存的天数，用户仍可按会话、发言人、时间段或多选记录清理。'],
            'media_cache_max_bytes' => ['媒体本地缓存上限', '字节/设备', '客户端用于表情、动图、小图片、音频和聊天媒体缓存的建议总空间。'],
            'transfer_daily_limit' => ['每日转账上限', '余额/天', '单个用户每天累计允许转出的余额。'],
            'transfer_single_limit' => ['单笔转账上限', '余额/笔', '用户一次转账允许输入的最大余额。'],
            'profile_like_per_action_limit' => ['主页单次连赞上限', '次/操作', '用户点按主页点赞时，一次操作最多增加的点赞次数。'],
            'profile_like_daily_limit' => ['主页每日连赞上限', '次/用户/天', '同一用户每天最多可以给同一个用户主页点赞的总次数。'],
        ];
        [$label, $unit, $description] = $known[$key] ?? self::fallback($key);
        $type = is_bool($value) ? 'boolean' : (is_numeric($value) ? 'number' : (is_array($value) ? 'object' : 'text'));
        return ['label' => $label, 'unit' => $unit, 'description' => $description, 'value_type' => $type];
    }

    private static function fallback(string $key): array
    {
        $unit = '';
        if (str_ends_with($key, '_days')) $unit = '天';
        elseif (str_ends_with($key, '_seconds')) $unit = '秒';
        elseif (str_ends_with($key, '_ms')) $unit = '毫秒';
        elseif (str_contains($key, 'quota') || str_contains($key, 'count')) $unit = '个';
        elseif (str_contains($key, 'balance') || str_contains($key, 'amount')) $unit = '余额';
        return [str_replace('_', ' ', $key), $unit, '当前规则的具体作用范围以所在平台或应用为准；保存后立即对其下级生效。'];
    }
}
