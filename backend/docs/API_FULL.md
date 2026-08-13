# 易运盈后台完整模块与 API 文档

本文档对应“易运盈后台”全新重构后的完整后端。它不兼容旧易云后台，也不是把星光文档、水仙后台、APP 通用后台简单拼接，而是统一为一套多应用、强隔离、可审计的业务系统。

当前实现包含 221 张数据表和 811 条实际路由，并形成“1 级平台所有者 -> 2 级授权平台 -> 3 级 admin -> 4 级 user”的完整治理链。实际注册路由见 [ROUTES.md](ROUTES.md)，精确字段、索引与外键见 [SCHEMA.md](SCHEMA.md)，分级规则见 [PLATFORM_GOVERNANCE.md](PLATFORM_GOVERNANCE.md)，简云能力映射见 [JIANYUN_CAPABILITY_MAPPING.md](JIANYUN_CAPABILITY_MAPPING.md)，论坛增强规则见 [FORUM_EXPERIENCE.md](FORUM_EXPERIENCE.md)，通信监管规则见 [COMMUNICATION_TAKEOVER.md](COMMUNICATION_TAKEOVER.md)。完整 811 条接口的可检索网页以 `/api-docs.html` 为准。

## 1. 核心定义

### 1.1 命名统一

| 名称 | 含义 | 说明 |
| --- | --- | --- |
| `platform level=1` | 最高平台所有者 | 由部署者显式注入安全身份，管理自己的整棵 1/2/3/4 级数据树。 |
| `platform level=2` | 授权运营平台 | 由 1 级创建，只管理自己直属的 3 级及其 4 级数据。 |
| `admin` | 3 级后台管理员 | 由所属平台创建或由部署者显式注入，只管理自己的应用和数据。 |
| `app` | 应用 | 旧易云里的 `api` 实际是应用标识，新后台统一改为 `app`。 |
| `app_id` | 应用主键 ID | MySQL 自增或雪花 ID，用于表关联。 |
| `app_key` | 应用公开标识 | 客户端可以携带，用于识别应用，不等于密钥。 |
| `app_secret` | 应用密钥 | 只能后台或服务端保存，不建议写进 iApp 客户端。 |
| `user` | 应用用户 | 用户必须属于某一个 `app_id`，不能脱离应用存在。 |
| `admin_token` | 管理员令牌 | 管理后台接口使用。 |
| `user_token` | 用户令牌 | 用户端接口使用。 |
| `public` | 公开接口 | 无需登录，但必须明确属于某个应用。 |

### 1.2 数据隔离规则

1. 1 级可管理自己、直属 2 级以及整棵树中的 3/4 级。
2. 各 2 级相互独立，只管理自己的 3 级分支。
3. 每个 `admin` 必须直属某个 1/2 级平台，并可创建额度允许数量的 `app`。
4. 每个 `app` 只能属于一个 `admin`；每个 `user` 必须属于一个 `app`。
5. 不同 `admin`、不同 `app` 的用户、文档、论坛、卡密、商城、消息和文件不能互通。
6. 平台查询按 `platform_id` 范围隔离，业务查询按 `admin_id + app_id` 隔离。

### 1.3 四类接口模式

| 模式 | 使用者 | 认证方式 | 能力 |
| --- | --- | --- | --- |
| `platform` | 1/2 级平台 | `Authorization: Bearer platform_token` | 管理授权平台、admin、权益、应用、规则、购买、反馈和全局审计。 |
| `admin` | 后台管理员 | `Authorization: Bearer admin_token` | 管理应用、用户、内容、配置、卡密、订单、日志。 |
| `user` | 应用用户 | `Authorization: Bearer user_token` + `app_key` | 使用注册、登录、文档、论坛、消息、商城等功能。 |
| `public` | 未登录访客/启动页 | `app_key` | 获取应用公开配置、公告、版本、公开文档、公开资源。 |

### 1.4 统一返回格式

```json
{
  "code": 1,
  "msg": "操作成功",
  "data": {},
  "trace_id": "202607121200000001"
}
```

| code | 含义 |
| --- | --- |
| `1` | 成功 |
| `0` | 业务失败 |
| `-1` | 程序错误 |
| `401` | 未登录或令牌失效 |
| `403` | 无权限 |
| `404` | 数据不存在 |
| `429` | 请求过快或超过限额 |

### 1.5 请求链路

```text
1 级创建或授权 2 级
-> 1/2 级开放注册或直接创建 3 级 admin
-> admin 获得会员、应用、远程文档与余额权益
-> admin 创建并配置 app
-> 4 级 user 使用 app_key 注册或登录
-> user_token 固定绑定 platform_id + admin_id + app_id + user_id
-> 任一上级停用、到期或强制策略变化，整条下游实时按新规则执行
```

## 2. 完整模块表

| 模块 | 模块名 | admin 能做 | user 能做 | public 能做 |
| --- | --- | --- | --- | --- |
| M00 | 平台治理 | 申请购买、查看权益、向直属平台反馈 | 受整条上级状态约束 | 无 |
| M01 | 管理员账号 | 登录、退出、改密、查看自己资料、查看登录日志 | 无 | 无 |
| M02 | 应用管理 | 创建应用、修改应用、启用/停用应用、重置密钥、配置域名、配置功能开关 | 无 | 获取公开应用信息 |
| M03 | 应用配置 | 设置注册、登录、签到、找回密码、排行榜、外部接口、Key、Token、邮箱注册等开关 | 无 | 获取启动配置、功能开关 |
| M04 | 用户账号 | 创建用户、查询用户、修改资料、重置密码、停用/启用、封禁/解封、删除、导入 | 注册、登录、退出、刷新令牌、找回密码 | 检查账号是否存在、获取验证码 |
| M05 | 用户资料 | 修改昵称、QQ、邮箱、签名、头像、背景、标签、等级、VIP、余额、余额、经验、文档券 | 查看/修改自己的资料 | 查看允许公开的用户资料 |
| M06 | 用户规则 | 设置账号长度、密码规则、注册 IP 限制、每日注册量、是否允许登录/注册/找回密码/资料公开 | 按规则使用功能 | 无 |
| M07 | 签到邀请 | 设置签到奖励、VIP 倍数、邀请码奖励、连续签到规则 | 每日签到、生成邀请码、查看邀请记录 | 无 |
| M08 | 文档中心 | 管理自己的文档，查看和管理应用内全部用户文档，新增、查询、修改、删除、恢复，配置文档额度与规则 | 新建、编辑、读取、删除、搜索、分享自己的文档 | 读取公开分享文档 |
| M09 | 公告版本 | 发布公告、轮播图、启动图、更新包、强制更新、远程配置 | 查看公告、检测更新 | 获取公告、轮播、版本 |
| M10 | 资源大厅 | 分类管理、资源审核、上架/下架、删除、推荐、置顶、评论管理 | 查看、投稿、购买、下载、评论、评分 | 查看公开资源和分类 |
| M11 | 应用商店 | 管理应用分类、应用上传、安装包、截图、版本、下载统计 | 查看、下载、购买应用 | 查看公开应用 |
| M12 | 论坛社区 | 板块管理、删帖、删评、置顶、加精、锁定、审核、举报处理 | 发帖、评论、点赞、收藏、举报 | 看公开板块、帖子、评论 |
| M13 | 消息好友 | 查看举报、发送系统通知、封禁私信、清理消息 | 私信、好友申请、同意/拒绝、删除好友、未读消息 | 无 |
| M14 | 群聊客服 | 建群、改群资料、成员/角色/禁言/申请/消息管理、解散群聊、客服回复 | 建群、开放/审批/邀请入群、邀请成员、设管理员、转让群主、退群、发消息、联系客服 | 无 |
| M15 | 卡密系统 | 生成卡密、批次管理、停用、删除、查看兑换记录、设置兑换类型 | 使用卡密兑换余额、经验、VIP、余额、文档券 | 无 |
| M16 | 支付订单 | 配置支付、查看订单、退款标记、回调日志、充值套餐 | 创建订单、支付、查看订单 | 支付回调 |
| M17 | 远程文件 | 创建文件夹、上传、修改、删除、复制、移动、公开/私有设置 | 读取允许访问的文件、上传自己的文件 | 读取公开文件 |
| M18 | 商城互动 | 商品、订单、红包、抽奖、投票、活动配置 | 购买、抢红包、抽奖、投票、查看记录 | 查看公开商品/活动 |
| M19 | 反馈举报 | 查看反馈、处理反馈、回复、查看举报 | 提交反馈、举报内容、机器人问答 | 无 |
| M20 | 统计日志 | 看用户、启动、接口、订单、内容、异常、管理员操作日志 | 查看自己的行为记录 | 无 |

## 3. MySQL 表结构清单

### 3.1 通用字段约定

业务表尽量统一包含：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED PK | 主键 |
| `admin_id` | BIGINT UNSIGNED | 所属管理员 |
| `app_id` | BIGINT UNSIGNED | 所属应用 |
| `status` | TINYINT | 状态：1正常，0停用，-1删除/封禁 |
| `created_at` | DATETIME | 创建时间 |
| `updated_at` | DATETIME | 更新时间 |
| `deleted_at` | DATETIME NULL | 软删除时间 |

### 3.2 平台、管理员与应用

| 表名 | 作用 | 核心字段 |
| --- | --- | --- |
| `platform_accounts` | 1/2 级平台账号与父子关系 | `id,parent_id,level,platform_key,account,status,membership_expired_at,admin_quota,permissions_json` |
| `platform_tokens` | 平台登录令牌 | `platform_id,token_hash,expired_at,revoked_at` |
| `platform_settings` | 注册、赠送、下游和强制轮询规则 | `platform_id,setting_key,setting_value,value_type` |
| `platform_exchange_products` | 每个平台独立的余额商品目录 | `platform_id,product_code,product_type,grant_json,price_integral,stock,per_admin_limit,status` |
| `platform_login_logs` | 平台登录日志 | `platform_id,account,ip,result,reason,created_at` |
| `platform_operation_logs` | 1/2 级操作审计 | `platform_id,actor_level,module,action,target_type,target_id,before_json,after_json` |
| `platform_daily_statistics` | 平台每日统计 | `platform_id,stat_date,admin_registered,admin_login_success,purchase_created,purchase_fulfilled` |
| `admins` | 管理员账号 | `id, account, password_hash, nickname, avatar, email, phone, status, last_login_ip, last_login_at, created_at, updated_at` |
| `admin_tokens` | 管理员登录令牌 | `id, admin_id, token_hash, device, ip, user_agent, expired_at, created_at` |
| `admin_login_logs` | 管理员登录日志 | `id, admin_id, ip, user_agent, result, reason, created_at` |
| `admin_entitlements` | 会员、时段、应用/远程文档额度和平台余额 | `platform_id,admin_id,membership_status,membership_expired_at,app_quota,remote_document_quota,integral` |
| `admin_permissions` | 上级授予 admin 的模块权限 | `platform_id,admin_id,permission_code,allowed,config_json` |
| `admin_registration_logs` | 注册与 IP 限制审计 | `platform_id,admin_id,account,ip,result,reason,gift_json` |
| `admin_entitlement_logs` | 权益修改审计 | `platform_id,admin_id,actor_platform_id,before_json,change_json,after_json` |
| `admin_purchase_orders` | admin 向直属平台购买权益 | `platform_id,admin_id,order_no,purchase_type,quantity,status,grant_json` |
| `admin_platform_feedbacks` | admin 向直属平台反馈 | `platform_id,admin_id,type,title,content,status,reply_content` |
| `admin_exchange_orders` | 平台余额自动兑换与退款订单 | `platform_id,admin_id,product_id,order_no,idempotency_key,total_integral,grant_json,status` |
| `admin_integral_logs` | admin 平台余额完整流水 | `platform_id,admin_id,change_value,before_value,after_value,scene,ref_type,ref_id` |
| `admin_operation_logs` | 管理员操作日志 | `id, admin_id, app_id, module, action, target_id, before_json, after_json, ip, created_at` |
| `apps` | 应用表 | `id, admin_id, app_key, app_secret_hash, name, logo, description, status, version, created_at, updated_at` |
| `app_settings` | 应用配置 | `id, admin_id, app_id, setting_key, setting_value, value_type, created_at, updated_at` |
| `app_feature_flags` | 应用功能开关 | `id, admin_id, app_id, feature_code, enabled, config_json, created_at, updated_at` |
| `app_domains` | 应用绑定域名 | `id, admin_id, app_id, domain, status, created_at` |
| `app_api_keys` | 应用接口密钥 | `id, admin_id, app_id, key_name, key_hash, scopes_json, status, expired_at, created_at` |

关键唯一索引：

```text
admins(platform_id, account) UNIQUE
apps.app_key UNIQUE
apps(admin_id, name) INDEX
app_settings(app_id, setting_key) UNIQUE
app_feature_flags(app_id, feature_code) UNIQUE
```

### 3.3 用户系统

| 表名 | 作用 | 核心字段 |
| --- | --- | --- |
| `users` | 用户账号 | `id, admin_id, app_id, account, password_hash, email, phone, status, register_ip, last_login_ip, last_login_at, created_at, updated_at` |
| `user_profiles` | 用户资料 | `id, admin_id, app_id, user_id, nickname, qq, avatar, background, signature, gender, birthday, title, public_profile, updated_at` |
| `user_tokens` | 用户令牌 | `id, admin_id, app_id, user_id, token_hash, device, ip, expired_at, created_at` |
| `user_login_logs` | 用户登录日志 | `id, admin_id, app_id, user_id, ip, user_agent, result, reason, created_at` |
| `user_bans` | 封禁记录 | `id, admin_id, app_id, user_id, ban_type, reason, start_at, end_at, operator_admin_id, created_at` |
| `user_tags` | 用户标签 | `id, admin_id, app_id, name, color, sort_order, created_at` |
| `user_tag_relations` | 用户标签关系 | `id, admin_id, app_id, user_id, tag_id, created_at` |
| `user_wallets` | 用户资产 | `id, admin_id, app_id, user_id, integral, experience, balance, document_credit, vip_expired_at, level_code, updated_at` |
| `user_wallet_logs` | 资产流水 | `id, admin_id, app_id, user_id, asset_type, change_value, before_value, after_value, scene, ref_type, ref_id, remark, created_at` |
| `user_sign_logs` | 签到记录 | `id, admin_id, app_id, user_id, sign_date, reward_integral, reward_experience, reward_credit, continuous_days, created_at` |
| `invite_codes` | 邀请码 | `id, admin_id, app_id, user_id, invite_code, max_use, used_count, reward_json, status, expired_at, created_at` |
| `invite_relations` | 邀请关系 | `id, admin_id, app_id, invite_code, inviter_user_id, invited_user_id, reward_status, created_at` |

关键唯一索引：

```text
users(app_id, account) UNIQUE
users(app_id, email) INDEX
user_profiles(user_id) UNIQUE
user_wallets(user_id) UNIQUE
user_sign_logs(app_id, user_id, sign_date) UNIQUE
invite_codes(app_id, invite_code) UNIQUE
```

### 3.4 文档中心

| 表名 | 作用 | 核心字段 |
| --- | --- | --- |
| `document_folders` | 文档文件夹 | `id, admin_id, app_id, user_id, parent_id, name, sort_order, created_at` |
| `documents` | 文档主表 | `id, admin_id, app_id, user_id, folder_id, title, content_type, content, word_count, is_public, status, created_at, updated_at, deleted_at` |
| `document_versions` | 文档版本 | `id, admin_id, app_id, document_id, user_id, title, content, version_no, created_at` |
| `document_shares` | 文档分享 | `id, admin_id, app_id, document_id, share_code, password_hash, expired_at, view_count, status, created_at` |
| `document_quota_logs` | 文档额度流水 | `id, admin_id, app_id, user_id, change_value, before_value, after_value, scene, ref_id, created_at` |

关键索引：

```text
documents(app_id, user_id, created_at)
documents(app_id, is_public, status)
document_shares(share_code) UNIQUE
```

### 3.5 公告、版本、远程配置

| 表名 | 作用 | 核心字段 |
| --- | --- | --- |
| `notices` | 公告 | `id, admin_id, app_id, title, content, type, is_popup, status, start_at, end_at, created_at` |
| `banners` | 轮播图/启动图 | `id, admin_id, app_id, title, image_url, link_url, position, sort_order, status, created_at` |
| `app_versions` | 版本更新 | `id, admin_id, app_id, version_name, version_code, apk_url, update_content, force_update, status, created_at` |
| `remote_configs` | 远程配置 | `id, admin_id, app_id, config_key, config_value, value_type, description, status, updated_at` |

### 3.6 资源大厅与应用商店

| 表名 | 作用 | 核心字段 |
| --- | --- | --- |
| `resource_categories` | 资源分类 | `id, admin_id, app_id, name, icon, sort_order, status, created_at` |
| `resources` | 资源内容 | `id, admin_id, app_id, category_id, user_id, title, description, cover_url, price_integral, download_url, audit_status, status, view_count, download_count, created_at` |
| `resource_files` | 资源附件 | `id, admin_id, app_id, resource_id, file_name, file_url, file_size, file_type, created_at` |
| `resource_comments` | 资源评论 | `id, admin_id, app_id, resource_id, user_id, content, status, created_at` |
| `resource_ratings` | 资源评分 | `id, admin_id, app_id, resource_id, user_id, score, created_at` |
| `resource_purchases` | 资源购买 | `id, admin_id, app_id, resource_id, buyer_user_id, seller_user_id, price_integral, created_at` |
| `store_apps` | 应用商店应用 | `id, admin_id, app_id, category_id, user_id, name, package_name, version_name, version_code, icon_url, apk_url, size_bytes, description, status, created_at` |
| `store_app_images` | 应用截图 | `id, admin_id, app_id, store_app_id, image_url, sort_order, created_at` |

### 3.7 论坛社区

| 表名 | 作用 | 核心字段 |
| --- | --- | --- |
| `forum_plates` | 论坛板块 | `id, admin_id, app_id, name, icon, description, sort_order, status, created_at` |
| `forum_posts` | 帖子 | `id, admin_id, app_id, plate_id, user_id, title, content, images_json, is_top, is_essence, is_locked, audit_status, view_count, like_count, comment_count, status, created_at` |
| `forum_comments` | 评论/回复 | `id, admin_id, app_id, post_id, parent_id, user_id, content, status, created_at` |
| `forum_likes` | 点赞 | `id, admin_id, app_id, post_id, comment_id, user_id, created_at` |
| `forum_favorites` | 收藏 | `id, admin_id, app_id, post_id, user_id, created_at` |
| `forum_reports` | 举报 | `id, admin_id, app_id, target_type, target_id, user_id, reason, status, created_at` |

关键唯一索引：

```text
forum_likes(app_id, post_id, comment_id, user_id) UNIQUE
forum_favorites(app_id, post_id, user_id) UNIQUE
```

### 3.8 消息、好友、客服、聊天室

| 表名 | 作用 | 核心字段 |
| --- | --- | --- |
| `conversations` | 会话 | `id, admin_id, app_id, type, user_id, target_user_id, room_id, last_message_id, unread_count, updated_at` |
| `messages` | 私信/系统消息 | `id, admin_id, app_id, conversation_id, sender_type, sender_id, receiver_user_id, content_type, content, is_read, created_at` |
| `friend_requests` | 好友申请 | `id, admin_id, app_id, from_user_id, to_user_id, message, status, created_at` |
| `friends` | 好友关系 | `id, admin_id, app_id, user_id, friend_user_id, remark, status, created_at` |
| `friend_groups` | 用户自定义好友分组 | `id, admin_id, app_id, user_id, name, sort_order, created_at, updated_at` |
| `friend_group_members` | 好友与分组关系 | `id, admin_id, app_id, user_id, friend_user_id, group_id, created_at` |
| `chat_rooms` | 聊天室 | `id, admin_id, app_id, name, icon, description, status, created_at` |
| `chat_room_members` | 聊天室成员 | `id, admin_id, app_id, room_id, user_id, role, mute_until, joined_at` |
| `chat_room_messages` | 群聊消息 | `id, admin_id, app_id, room_id, user_id, sender_type, sender_admin_id, content_type, content, reply_to_message_id, status, created_at` |
| `chat_room_policies` | 群聊规则 | `room_id, owner_user_id, join_mode, max_members, allow_member_invite, mute_all, announcement` |
| `chat_room_invitations` | 群邀请 | `room_id, inviter_user_id, invitee_user_id, message, status, expired_at, responded_at` |
| `chat_room_join_requests` | 入群申请 | `room_id, user_id, message, status, handled_by_user_id, handled_by_admin_id, handled_at` |
| `chat_room_reads` | 群消息已读位置 | `room_id, user_id, last_read_message_id, updated_at` |
| `chat_room_user_groups` | 用户自定义群聊分组 | `id, admin_id, app_id, user_id, name, sort_order, created_at, updated_at` |
| `chat_room_user_settings` | 用户群备注与所在分组 | `id, admin_id, app_id, user_id, room_id, group_id, remark, created_at, updated_at` |
| `service_sessions` | 客服会话 | `id, admin_id, app_id, user_id, admin_reply_id, status, last_message_at, created_at` |
| `service_messages` | 客服消息 | `id, admin_id, app_id, session_id, sender_type, sender_id, content, is_read, created_at` |

### 3.9 卡密、支付、商城互动

| 表名 | 作用 | 核心字段 |
| --- | --- | --- |
| `card_batches` | 卡密批次 | `id, admin_id, app_id, name, card_type, value_json, total_count, used_count, status, expired_at, created_at` |
| `cards` | 卡密 | `id, admin_id, app_id, batch_id, card_code, card_type, value_json, max_use, used_count, status, expired_at, created_at` |
| `card_redeem_logs` | 卡密兑换记录 | `id, admin_id, app_id, card_id, user_id, reward_json, created_at` |
| `payment_channels` | 支付方式 | `id, admin_id, app_id, channel_code, config_json, enabled, created_at` |
| `orders` | 订单 | `id, admin_id, app_id, user_id, order_no, order_type, title, amount, pay_amount, status, paid_at, created_at` |
| `payments` | 支付流水 | `id, admin_id, app_id, order_id, channel_code, trade_no, amount, callback_json, status, created_at` |
| `shop_goods` | 商品 | `id, admin_id, app_id, name, cover_url, description, price_integral, price_money, stock, status, created_at` |
| `shop_orders` | 商城订单 | `id, admin_id, app_id, user_id, goods_id, quantity, amount_integral, amount_money, status, created_at` |
| `red_packets` | 红包 | `id, admin_id, app_id, user_id, packet_type, packet_label, distribution_mode, eligibility_mode, delivery_scope, context_id, total_amount DECIMAL(18,2), total_count, remain_amount DECIMAL(18,2), remain_count, status, expired_at, created_at` |
| `red_packet_recipients` | 红包指定接收人 | `id, admin_id, app_id, packet_id, user_id, created_at` |
| `red_packet_claims` | 抢红包记录 | `id, admin_id, app_id, packet_id, user_id, amount, created_at` |
| `red_packet_returns` | 接收人退回红包记录 | `id, admin_id, app_id, packet_id, user_id, amount, created_at` |
| `lottery_prizes` | 抽奖奖品 | `id, admin_id, app_id, name, prize_type, value_json, weight, stock, status, created_at` |
| `lottery_draws` | 抽奖记录 | `id, admin_id, app_id, user_id, prize_id, cost_json, result_json, created_at` |
| `votes` | 投票 | `id, admin_id, app_id, title, description, multi_select, status, start_at, end_at, created_at` |
| `vote_options` | 投票选项 | `id, admin_id, app_id, vote_id, option_text, vote_count, sort_order, created_at` |
| `vote_records` | 投票记录 | `id, admin_id, app_id, vote_id, option_id, user_id, created_at` |

### 3.10 远程文件、反馈、统计日志

| 表名 | 作用 | 核心字段 |
| --- | --- | --- |
| `remote_files` | 远程文件 | `id, admin_id, app_id, parent_id, owner_user_id, name, file_type, file_url, content, size_bytes, is_public, status, created_at` |
| `remote_file_versions` | 文件版本 | `id, admin_id, app_id, file_id, content, file_url, version_no, created_at` |
| `uploads` | 上传记录 | `id, admin_id, app_id, user_id, file_name, file_url, mime_type, size_bytes, scene, created_at` |
| `feedbacks` | 反馈 | `id, admin_id, app_id, user_id, type, title, content, images_json, reply_content, status, created_at, replied_at` |
| `bot_qa` | 机器人问答 | `id, admin_id, app_id, question, answer, keyword_json, status, created_at` |
| `api_request_logs` | 接口请求日志 | `id, admin_id, app_id, actor_type, actor_id, method, path, params_json, response_code, response_msg, ip, cost_ms, created_at` |
| `user_operation_logs` | 用户行为日志 | `id, admin_id, app_id, user_id, module, action, target_type, target_id, detail_json, created_at` |
| `statistics_daily` | 每日统计 | `id, admin_id, app_id, stat_date, user_new, user_active, app_start, api_count, order_amount, document_count, post_count, created_at` |
| `system_error_logs` | 程序异常日志 | `id, admin_id, app_id, level, message, file, line, trace, created_at` |

## 4. 接口清单

统一前缀：

```text
/api
```

认证约定：

```text
platform 接口：Authorization: Bearer <platform_token>
admin 接口：Authorization: Bearer <admin_token>
user 接口：Authorization: Bearer <user_token>
public 接口：app_key 必填
```

### 4.1 platform 平台治理接口

| 方法 | 路径 | 功能 | 主要参数 |
| --- | --- | --- | --- |
| POST | `/api/platform/login` | 1/2 级平台登录并校验当前安装版本的平台唯一 KEY | `platform_key,account,password,device` |
| POST | `/api/platform/logout` | 平台退出 | 无 |
| GET | /api/platform/me | 当前平台、设置与轮询策略；客户端恢复会同时校验实时平台等级、安装版要求等级和本地会话等级 | 无 |
| GET | /api/platform/permissions | 当前1/2级账号的最终生效权限、来源和锁定状态 | 无 |
| PUT | `/api/platform/profile` | 修改平台资料 | `nickname,avatar,email,phone` |
| PUT | `/api/platform/password` | 修改平台密码 | `old_password,new_password` |
| GET | `/api/platform/login-logs` | 当前平台登录日志 | `page,limit` |
| GET | `/api/platform/dashboard` | 范围内数据面板 | `platform_id`，仅 1 级可选分支 |
| GET | `/api/platform/settings` | 当前平台规则 | 无 |
| PUT | `/api/platform/settings` | 保存注册、赠送、下游和强制规则 | `settings` |
| GET | `/api/platform/mail-settings` | 1级总控读取邮件非敏感状态 | 无 |
| PUT | `/api/platform/mail-settings` | 1级总控保存邮件配置；密码只写不回显 | `transport,from_address,from_name,smtp_*,expected_revision,current_password` |
| POST | `/api/platform/mail-settings/test` | 审计且频控的明确地址测试邮件 | `recipient_email,current_password` |
| POST | `/api/platform/mail-settings/reencrypt` | 使用当前活动密钥重加密 SMTP 密码 | `expected_revision,current_password` |
| GET | `/api/platform/ip-statistics` | admin 注册 IP 汇总 | `platform_id,ip` |
| GET | `/api/platform/admin-registration-logs` | admin 注册日志 | `platform_id,ip,page,limit` |
| GET | `/api/platform/admin-login-logs` | admin 登录日志 | `platform_id,page,limit` |
| GET | `/api/platform/operation-logs` | 平台操作审计 | `page,limit` |
| GET | `/api/platform/operators` | 2 级平台列表，仅 1 级 | `keyword,status,page,limit` |
| POST | `/api/platform/operators` | 创建 2 级平台，仅 1 级 | `account,password,membership_days,admin_quota,permissions` |
| GET | `/api/platform/operators/{operator_id}` | 2 级详情 | 无 |
| PUT | `/api/platform/operators/{operator_id}` | 修改 2 级账号、会员、额度和权限 | 资料与权益字段 |
| PUT | `/api/platform/operators/{operator_id}/password` | 重置 2 级密码 | `new_password` |
| POST | `/api/platform/operators/{operator_id}/ban` | 封禁 2 级并撤销下游令牌 | `reason` |
| POST | `/api/platform/operators/{operator_id}/unban` | 解封 2 级 | 无 |
| DELETE | `/api/platform/operators/{operator_id}` | 连带删除 2/3/4 级全部数据 | `confirm=DELETE` |
| GET | `/api/platform/operators/{operator_id}/settings` | 查看指定 2 级规则 | 无 |
| PUT | /api/platform/operators/{operator_id}/settings | 1 级修改指定 2 级规则 | settings |
| GET | /api/platform/operators/{operator_id}/permissions | 1级查看指定2级的本级配置与最终权限 | 无 |
| PUT | /api/platform/operators/{operator_id}/permissions | 1级保存指定2级的分支管理权限 | permissions |
| GET | `/api/platform/admins` | 管理范围内的 3 级列表 | `platform_id,keyword,status,membership_status,ip,page,limit` |
| POST | `/api/platform/admins` | 直接创建 3 级及其首个可登录应用 | `platform_id,account,password,app_key,app_name,vip_days,app_quota,remote_document_quota,balance`；成功响应含 `initial_app`、`registration_gift`、仅本次返回的 `app_secret` |
| GET | `/api/platform/admins/{admin_id}` | 3 级详情、权限和数量 | 无 |
| PUT | `/api/platform/admins/{admin_id}` | 修改 3 级账号资料 | `account,nickname,avatar,email,phone` |
| PUT | `/api/platform/admins/{admin_id}/password` | 重置 3 级密码 | `new_password` |
| POST | `/api/platform/admins/{admin_id}/ban` | 封禁 3 级并阻断 4 级 | `reason` |
| POST | `/api/platform/admins/{admin_id}/unban` | 解封 3 级 | 无 |
| DELETE | `/api/platform/admins/{admin_id}` | 连带删除 admin、app、user 与业务数据 | `confirm=DELETE` |
| PUT | `/api/platform/admins/{admin_id}/entitlement` | 修改会员、时段、名额、余额 | 权益字段或 `*_change` |
| GET | `/api/platform/admins/{admin_id}/permissions` | 3 级模块权限 | 无 |
| PUT | `/api/platform/admins/{admin_id}/permissions` | 保存 3 级模块权限 | `permissions` |
| POST | `/api/platform/admins/{admin_id}/impersonate` | 签发受审计代管令牌 | `ttl` |
| GET | `/api/platform/admins/{admin_id}/apps` | 指定 3 级的应用 | 无 |
| GET | `/api/platform/apps` | 范围内全部应用 | `platform_id,keyword,page,limit` |
| GET | `/api/platform/apps/{app_id}` | 应用、实际配置和业务数量 | 无 |
| PUT | `/api/platform/apps/{app_id}` | 修改/启停应用 | `name,logo,description,status,reason` |
| PUT | `/api/platform/apps/{app_id}/settings` | 平台修改应用配置 | `settings` |
| DELETE | /api/platform/apps/{app_id} | 连带删除应用全部数据 | confirm=DELETE |
| GET | /api/platform/apps/{app_id}/users/{user_id}/permissions | 1/2级查看所属分支用户的最终权限 | 无 |
| PUT | /api/platform/apps/{app_id}/users/{user_id}/permissions | 1/2级保存所属分支用户权限 | permissions |
| GET | `/api/platform/purchase-orders` | admin 购买申请 | `platform_id,status,purchase_type,keyword,page,limit` |
| POST | `/api/platform/purchase-orders/{order_id}/fulfill` | 发放购买权益 | `grant,platform_note` |
| POST | `/api/platform/purchase-orders/{order_id}/reject` | 拒绝购买申请 | `platform_note` |
| GET | `/api/platform/admin-feedbacks` | admin 向平台的反馈 | `platform_id,status,type,page,limit` |
| POST | `/api/platform/admin-feedbacks/{feedback_id}/reply` | 回复或关闭反馈 | `reply_content,status` |
| GET | `/api/platform/exchange-products` | 余额商品列表 | `platform_id,status,keyword,page,limit` |
| POST | `/api/platform/exchange-products` | 创建余额商品 | `platform_id,product_code,name,product_type,grant,price_balance,stock,limits` |
| GET | `/api/platform/exchange-products/{product_id}` | 商品详情和兑换统计 | 无 |
| PUT | `/api/platform/exchange-products/{product_id}` | 修改商品、库存、价格和限购 | 商品字段 |
| POST | `/api/platform/exchange-products/{product_id}/enable` | 上架商品 | 无 |
| POST | `/api/platform/exchange-products/{product_id}/disable` | 下架商品 | 无 |
| DELETE | `/api/platform/exchange-products/{product_id}` | 软删除商品并保留历史订单 | `confirm=DELETE` |
| GET | `/api/platform/exchanges` | 自动兑换订单 | `platform_id,status,product_type,keyword,page,limit` |
| GET | `/api/platform/exchanges/{exchange_id}` | 自动兑换订单详情 | 无 |
| POST | `/api/platform/exchanges/{exchange_id}/refund` | 原子退款并回收未使用权益 | `refund_reason` |
| GET | `/api/platform/balance-logs` | admin 平台余额流水 | `platform_id,admin_id,scene,page,limit` |

> 首个应用密钥一次性语义：平台直接创建管理员和管理员自助注册均会在同一事务内创建首个 `app_key` 应用。`app_secret` 仅在该次成功响应中明文返回，不写入后续查询、列表、日志或再次读取接口；调用方必须立即交付并保存到服务端安全配置。创建失败、应用唯一 ID 冲突、额度不足或默认数据初始化失败会整体回滚，不会留下可登录但没有首个应用的管理员。

### 4.2 admin 管理员接口

#### 管理员账号

| 方法 | 路径 | 功能 | 主要参数 |
| --- | --- | --- | --- |
| POST | `/api/admin/register` | 注册 3 级并原子创建首个可登录应用 | `platform_key,app_key,app_name,account,password,password_confirmation,nickname,email,phone`；成功响应含 `initial_app`、`registration_gift`、仅本次返回的 `app_secret` |
| POST | `/api/admin/login` | 管理员登录并同时校验所属平台 KEY、账号密码及其名下应用 API 唯一 ID | `platform_key,app_key,account,password` |
| POST | `/api/admin/logout` | 退出登录 | 无 |
| GET | /api/admin/me | 当前管理员资料 | 无 |
| GET | /api/admin/permissions | 当前3级管理员的最终生效权限、来源和锁定状态 | 无 |
| PUT | `/api/admin/profile` | 修改资料 | `nickname,avatar,email,phone` |
| PUT | `/api/admin/password` | 修改密码 | `old_password,new_password` |
| GET | `/api/admin/login-logs` | 登录日志 | `page,limit` |
| GET | `/api/admin/entitlement` | 会员状态、使用时段、额度和已用量 | 无 |
| GET | `/api/admin/purchase-orders` | 购买申请列表 | `status,page,limit` |
| POST | `/api/admin/purchase-orders` | 向直属平台购买权益 | `purchase_type,quantity,amount,request,note` |
| GET | `/api/admin/platform-feedbacks` | 给直属平台的反馈记录 | `page,limit` |
| POST | `/api/admin/platform-feedbacks` | 向直属 1/2 级反馈 | `type,title,content,images` |
| GET | `/api/admin/exchange-products` | 可兑换商品、余额和报价 | `product_type,keyword` |
| GET | `/api/admin/exchange-products/{product_id}` | 商品详情与实时报价 | 无 |
| POST | `/api/admin/exchanges/quote` | 兑换报价和失败原因 | `product_id,quantity` |
| GET | `/api/admin/exchanges` | 我的自动兑换订单 | `status,product_type,page,limit` |
| POST | `/api/admin/exchanges` | 原子扣余额并自动发放权益 | `product_id,quantity,idempotency_key` |
| GET | `/api/admin/exchanges/{exchange_id}` | 我的兑换订单详情 | 无 |
| GET | `/api/admin/balance-logs` | 我的平台余额流水 | `scene,page,limit` |

#### 管理工作台、安全与管理员交流

| 方法 | 路径 | 功能 | 主要参数 |
| --- | --- | --- | --- |
| GET | `/api/admin/workbench` | 聚合当前资料、会员、应用/API/文档额度、设备数、公开信息与赞助排行 | 无 |
| PUT | `/api/admin/workbench/public-profile` | 保存官网、下载地址、官方群、软件介绍、关于我们和收款码图片链接 | `official_url,download_url,official_qq_group,official_qq_group_link,alipay_qr_url,wechat_qr_url,software_intro,about_us` |
| GET | `/api/admin/sponsors` | 分页查看手工确认到账的赞助记录和自动金额排行 | `page,limit` |
| POST | `/api/admin/sponsors` | 登记一笔已人工确认到账的赞助 | `sponsor_name,amount,channel,note,paid_at,status` |
| PUT | `/api/admin/sponsors/{sponsor_id}` | 修改赞助记录并重新排序 | 同创建参数 |
| DELETE | `/api/admin/sponsors/{sponsor_id}` | 软删除赞助记录 | 无 |
| GET | `/api/admin/security/sessions` | 查看当前账号设备会话、当前设备标记和实时有效状态；不返回 Token 哈希 | 无 |
| DELETE | `/api/admin/security/sessions/{session_id}` | 撤销指定设备会话；可识别是否撤销当前会话 | 无 |
| DELETE | `/api/admin/account` | 密码和中文确认词双重校验后停用账号、应用与全部 Token，保留审计历史 | `password,confirm=注销账号` |
| GET | `/api/admin/community/posts` | 按综合、技术、求助、分享、交流分类查看管理员交流帖子 | `category_code,page,limit` |
| POST | `/api/admin/community/posts` | 发布管理员交流帖子 | `category_code,title,content,attachments` |
| POST | `/api/admin/community/posts/{post_id}/pin` | 在同一平台范围内置顶或取消置顶 | `pinned` |
| POST | `/api/admin/community/posts/{post_id}/reports` | 举报交流帖子；待处理举报幂等复用 | `reason` |

支付宝和微信收款码只保存经过 URL 协议校验的 `http/https` 图片地址，不复用头像上传接口，也不伪造支付回调。赞助榜只根据管理员手工确认的到账记录排序。

#### 应用管理

| 方法 | 路径 | 功能 | 主要参数 |
| --- | --- | --- | --- |
| GET | `/api/admin/apps` | 应用列表 | `keyword,status,page,limit` |
| POST | `/api/admin/apps` | 创建应用 | `name,description,logo` |
| GET | `/api/admin/apps/{app_id}` | 应用详情 | 无 |
| PUT | `/api/admin/apps/{app_id}` | 修改应用 | `name,description,logo,status` |
| POST | `/api/admin/apps/{app_id}/enable` | 启用应用 | 无 |
| POST | `/api/admin/apps/{app_id}/disable` | 停用应用 | `reason` |
| DELETE | `/api/admin/apps/{app_id}` | 删除应用 | `confirm` |
| POST | `/api/admin/apps/{app_id}/secret/reset` | 重置密钥 | 无 |
| POST | `/api/admin/apps/{app_id}/key/verify` | 实时校验当前管理员 Token、应用归属和应用唯一 KEY | `app_key` |
| GET | `/api/admin/apps/{app_id}/settings` | 配置列表 | 无 |
| PUT | `/api/admin/apps/{app_id}/settings` | 保存配置 | `settings_json` |
| GET | `/api/admin/apps/{app_id}/features` | 功能开关列表 | 无 |
| PUT | `/api/admin/apps/{app_id}/features` | 保存功能开关 | `feature_code,enabled,config_json` |

#### 用户管理

| 方法 | 路径 | 功能 | 主要参数 |
| --- | --- | --- | --- |
| GET | `/api/admin/apps/{app_id}/users` | 用户列表 | `keyword,status,tag_id,page,limit` |
| POST | `/api/admin/apps/{app_id}/users` | 创建用户 | `account,password,nickname,email,phone` |
| GET | `/api/admin/apps/{app_id}/users/{user_id}` | 用户详情 | 无 |
| PUT | `/api/admin/apps/{app_id}/users/{user_id}` | 修改用户资料 | `nickname,qq,email,phone,signature,avatar,status` |
| PUT | `/api/admin/apps/{app_id}/users/{user_id}/password` | 重置用户密码 | `new_password` |
| POST | `/api/admin/apps/{app_id}/users/{user_id}/ban` | 封禁用户 | `ban_type,reason,end_at` |
| POST | `/api/admin/apps/{app_id}/users/{user_id}/unban` | 解封用户 | 无 |
| DELETE | /api/admin/apps/{app_id}/users/{user_id} | 删除用户 | confirm |
| GET | /api/admin/apps/{app_id}/users/{user_id}/permissions | 3级查看自己应用内用户的最终权限 | 无 |
| PUT | /api/admin/apps/{app_id}/users/{user_id}/permissions | 3级保存自己应用内用户权限 | permissions |
| POST | `/api/admin/apps/{app_id}/users/import` | 批量导入 | `users_json` |
| PUT | `/api/admin/apps/{app_id}/users/{user_id}/wallet` | 调整资产 | `asset_type,change_value,remark` |
| PUT | `/api/admin/apps/{app_id}/users/{user_id}/vip` | 设置会员 | `vip_expired_at` |
| GET | `/api/admin/apps/{app_id}/users/{user_id}/logs` | 用户行为日志 | `module,page,limit` |
| GET | `/api/admin/apps/{app_id}/user-tags` | 标签列表 | 无 |
| POST | `/api/admin/apps/{app_id}/user-tags` | 新增标签 | `name,color` |
| PUT | `/api/admin/apps/{app_id}/user-tags/{tag_id}` | 修改标签 | `name,color,sort_order` |
| DELETE | `/api/admin/apps/{app_id}/user-tags/{tag_id}` | 删除标签 | 无 |
| POST | `/api/admin/apps/{app_id}/users/{user_id}/tags` | 设置用户标签 | `tag_ids` |

#### 文档中心

| 方法 | 路径 | 功能 | 主要参数 |
| --- | --- | --- | --- |
| GET | `/api/admin/apps/{app_id}/documents` | 文档列表 | `keyword,user_id,status,page,limit` |
| POST | `/api/admin/apps/{app_id}/documents` | 新建管理员自有文档 | `title,content,content_type,is_public` |
| GET | `/api/admin/apps/{app_id}/documents/{document_id}` | 文档详情 | 无 |
| PUT | `/api/admin/apps/{app_id}/documents/{document_id}` | 修改管理员或应用用户文档 | `title,content,content_type,is_public` |
| DELETE | `/api/admin/apps/{app_id}/documents/{document_id}` | 删除文档 | `reason` |
| POST | `/api/admin/apps/{app_id}/documents/{document_id}/restore` | 恢复文档 | 无 |
| GET | `/api/admin/apps/{app_id}/document-shares` | 分享列表 | `page,limit` |
| PUT | `/api/admin/apps/{app_id}/document-rules` | 文档规则 | `default_credit,create_cost,share_enabled,max_count` |

#### 公告版本远程配置

| 方法 | 路径 | 功能 | 主要参数 |
| --- | --- | --- | --- |
| GET | `/api/admin/apps/{app_id}/notices` | 公告列表 | `type,status,page,limit` |
| POST | `/api/admin/apps/{app_id}/notices` | 新增公告 | `title,content,type,is_popup,start_at,end_at` |
| PUT | `/api/admin/apps/{app_id}/notices/{notice_id}` | 修改公告 | `title,content,status` |
| DELETE | `/api/admin/apps/{app_id}/notices/{notice_id}` | 删除公告 | 无 |
| GET | `/api/admin/apps/{app_id}/banners` | 轮播/启动图列表 | `position` |
| POST | `/api/admin/apps/{app_id}/banners` | 新增轮播/启动图 | `title,image_url,link_url,position,sort_order` |
| PUT | `/api/admin/apps/{app_id}/versions` | 发布版本 | `version_name,version_code,apk_url,update_content,force_update` |
| GET | `/api/admin/apps/{app_id}/remote-configs` | 远程配置列表 | 无 |
| PUT | `/api/admin/apps/{app_id}/remote-configs` | 保存远程配置 | `config_key,config_value,value_type` |

#### 资源、应用商店、论坛

| 方法 | 路径 | 功能 | 主要参数 |
| --- | --- | --- | --- |
| GET | `/api/admin/apps/{app_id}/resource-categories` | 源码分类列表 | `resource_type`，管理端默认源码商城 |
| POST | `/api/admin/apps/{app_id}/resource-categories` | 新增源码分类 | `name,description,sort_order,resource_type` |
| PUT/DELETE | `/api/admin/apps/{app_id}/resource-categories/{category_id}` | 修改/删除源码分类 | 分类字段；有资源时禁止删除 |
| GET | `/api/admin/apps/{app_id}/resources` | 资源审核列表与四态汇总 | `category_id,audit_status,status,resource_type,risk_level,keyword,page,limit` |
| GET | `/api/admin/apps/{app_id}/resources/{resource_id}` | 资源审核详情 | 无 |
| PUT | `/api/admin/apps/{app_id}/resources/{resource_id}/audit` | 资源审核：通过/不通过/暂定 | `audit_status=approved/rejected/on_hold,reason,expected_audit_status,expected_review_revision,override_risk` |
| GET | `/api/admin/apps/{app_id}/resources/{resource_id}/download` | 管理员鉴权下载受控源码文件 | 无 |
| PUT | `/api/admin/apps/{app_id}/resources/{resource_id}` | 修改资源 | `title,description,price_balance,status` |
| DELETE | `/api/admin/apps/{app_id}/resources/{resource_id}` | 删除资源 | 无 |
| GET/POST | `/api/admin/apps/{app_id}/store-categories` | 应用商店分类列表/新增分类 | `name,icon,sort_order` |
| PUT/DELETE | `/api/admin/apps/{app_id}/store-categories/{category_id}` | 修改/删除应用分类 | 分类字段；有应用时禁止删除 |
| GET | `/api/admin/apps/{app_id}/store-apps` | 应用审核列表与四态汇总 | `keyword,audit_status,risk_level,status,page,limit` |
| POST | `/api/admin/apps/{app_id}/store-apps` | 新增应用 | `name,package_name,version_name,version_code,icon_url,apk_url,images` |
| GET | `/api/admin/apps/{app_id}/store-apps/{store_app_id}` | 应用审核详情 | 无 |
| PUT | `/api/admin/apps/{app_id}/store-apps/{store_app_id}/audit` | 应用审核：通过/不通过/暂定 | `audit_status=approved/rejected/on_hold,reason,expected_audit_status,expected_review_revision,override_risk` |
| GET | `/api/admin/apps/{app_id}/store-apps/{store_app_id}/download` | 管理员鉴权下载安装包 | 无 |
| PUT/DELETE | `/api/admin/apps/{app_id}/store-apps/{store_app_id}` | 修改/删除应用 | 应用字段；安装包变更后自动回到待审核 |
| GET | `/api/admin/apps/{app_id}/forum-plates` | 板块列表 | 无 |
| POST | `/api/admin/apps/{app_id}/forum-plates` | 新增板块 | `name,icon,description,sort_order` |
| POST | `/api/admin/apps/{app_id}/forum-plates/{plate_id}/avatar` | 上传并替换板块头像（受 `forum_plate_avatar_upload` 控制） | `multipart file` |
| GET/POST | `/api/admin/apps/{app_id}/forum-categories` | 二级分类列表/新增分类 | `plate_id,name,slug,aliases,sort_order,status` |
| PUT/DELETE | `/api/admin/apps/{app_id}/forum-categories/{category_id}` | 修改/删除二级分类 | 分类字段 |
| GET/POST | `/api/admin/apps/{app_id}/forum-tags` | 规范标签列表/新增标签 | `plate_id,category_id,name,slug,aliases,color,sort_order,status` |
| PUT/DELETE | `/api/admin/apps/{app_id}/forum-tags/{tag_id}` | 修改/删除规范标签 | 标签字段 |
| GET | `/api/admin/apps/{app_id}/forum-structure-requests` | 板块和分类申请列表 | `request_type,status,keyword,page,limit` |
| POST | `/api/admin/apps/{app_id}/forum-structure-requests/{request_id}/review` | 审核结构申请并在通过时自动创建对应板块、二级分类或标签 | `decision=approve/reject,review_comment` |
| GET | `/api/admin/apps/{app_id}/forum-posts` | 帖子列表 | `plate_id,audit_status,status,page,limit` |
| PUT | `/api/admin/apps/{app_id}/forum-posts/{post_id}/top` | 置顶/取消置顶 | `enabled` |
| PUT | `/api/admin/apps/{app_id}/forum-posts/{post_id}/essence` | 加精/取消加精 | `enabled` |
| PUT | `/api/admin/apps/{app_id}/forum-posts/{post_id}/lock` | 锁定/解锁 | `enabled` |
| DELETE | `/api/admin/apps/{app_id}/forum-posts/{post_id}` | 删除帖子 | `reason` |
| DELETE | `/api/admin/apps/{app_id}/forum-comments/{comment_id}` | 删除评论 | `reason` |

#### 消息、客服、卡密、支付、商城互动

| 方法 | 路径 | 功能 | 主要参数 |
| --- | --- | --- | --- |
| POST | `/api/admin/apps/{app_id}/system-messages` | 发送系统通知 | `target_type,target_user_ids,title,content` |
| GET | `/api/admin/apps/{app_id}/messages` | 消息记录 | `user_id,type,page,limit` |
| GET | `/api/admin/apps/{app_id}/service-sessions` | 客服会话 | `status,page,limit` |
| POST | `/api/admin/apps/{app_id}/service-sessions/{session_id}/reply` | 客服回复 | `content` |
| GET/POST | `/api/admin/apps/{app_id}/chat-rooms` | 群列表/管理员建群 | `keyword,status` / `name,icon,description,join_mode,max_members,allow_member_invite,mute_all,announcement` |
| GET/PUT/DELETE | `/api/admin/apps/{app_id}/chat-rooms/{room_id}` | 群详情/修改/解散 | 群资料和规则字段 |
| GET/POST | `/api/admin/apps/{app_id}/chat-rooms/{room_id}/members` | 成员列表/添加成员 | `user_id,role` |
| PUT/DELETE | `/api/admin/apps/{app_id}/chat-rooms/{room_id}/members/{user_id}` | 修改角色/移出成员 | `role` |
| PUT | `/api/admin/apps/{app_id}/chat-rooms/{room_id}/members/{user_id}/mute` | 禁言或解除 | `mute_until` |
| GET/POST | `/api/admin/apps/{app_id}/chat-rooms/{room_id}/messages` | 群消息/管理员发消息 | `since_id,limit` / `content,content_type,reply_to_message_id` |
| DELETE | `/api/admin/apps/{app_id}/chat-rooms/{room_id}/messages/{message_id}` | 删除群消息 | 无 |
| GET/POST | `/api/admin/apps/{app_id}/chat-rooms/{room_id}/join-requests[/\{request_id\}/decision]` | 查看/处理入群申请 | `approve` |
| GET | `/api/admin/apps/{app_id}/card-batches` | 卡密批次 | `page,limit` |
| POST | `/api/admin/apps/{app_id}/card-batches` | 生成卡密 | `name,card_type,value_json,total_count,max_use,expired_at` |
| GET | `/api/admin/apps/{app_id}/cards` | 卡密列表 | `batch_id,status,page,limit` |
| PUT | `/api/admin/apps/{app_id}/cards/{card_id}` | 修改卡密状态 | `status` |
| GET | `/api/admin/apps/{app_id}/orders` | 订单列表 | `order_type,status,page,limit` |
| GET | `/api/admin/apps/{app_id}/payments` | 支付流水 | `channel_code,status,page,limit` |
| PUT | `/api/admin/apps/{app_id}/payment-channels` | 配置支付 | `channel_code,enabled,config_json` |
| GET | `/api/admin/apps/{app_id}/shop-goods` | 商品列表 | `status,page,limit` |
| POST | `/api/admin/apps/{app_id}/shop-goods` | 新增商品 | `name,cover_url,description,price,stock,status` |
| GET | `/api/admin/apps/{app_id}/lottery-prizes` | 抽奖奖品 | 无 |
| POST | `/api/admin/apps/{app_id}/lottery-prizes` | 保存奖品 | `name,prize_type,value_json,weight,stock,status` |
| GET | `/api/admin/apps/{app_id}/votes` | 投票列表 | 无 |
| POST | `/api/admin/apps/{app_id}/votes` | 创建投票 | `title,options,multi_select,start_at,end_at` |

#### 文件、反馈、统计、日志

| 方法 | 路径 | 功能 | 主要参数 |
| --- | --- | --- | --- |
| GET | `/api/admin/apps/{app_id}/remote-files` | 文件列表 | `parent_id` |
| POST | `/api/admin/apps/{app_id}/remote-files/folders` | 创建文件夹 | `parent_id,name` |
| POST | `/api/admin/apps/{app_id}/remote-files` | 创建/上传文件 | `parent_id,name,content,file_url,is_public` |
| PUT | `/api/admin/apps/{app_id}/remote-files/{file_id}` | 修改文件 | `name,content,file_url,is_public,status` |
| DELETE | `/api/admin/apps/{app_id}/remote-files/{file_id}` | 删除文件 | 无 |
| GET | `/api/admin/apps/{app_id}/feedbacks` | 反馈列表 | `status,page,limit` |
| POST | `/api/admin/apps/{app_id}/feedbacks/{feedback_id}/reply` | 回复反馈 | `reply_content,status` |
| GET | `/api/admin/apps/{app_id}/statistics` | 统计总览 | `date_start,date_end` |
| GET | `/api/admin/apps/{app_id}/api-logs` | 接口日志 | `path,actor_type,page,limit` |
| GET | `/api/admin/apps/{app_id}/operation-logs` | 操作日志 | `module,action,page,limit` |

### 4.3 user 用户接口

#### 用户账号

| 方法 | 路径 | 功能 | 主要参数 |
| --- | --- | --- | --- |
| POST | `/api/user/register` | 注册 | `app_key,account,password,nickname,email,phone,code,invite_code` |
| POST | `/api/user/login` | 登录 | `app_key,account,password` |
| POST | `/api/user/logout` | 退出 | 无 |
| POST | `/api/user/token/refresh` | 刷新令牌 | `refresh_token` |
| GET | /api/user/me | 我的资料 | 无 |
| GET | /api/user/permissions | 当前4级用户的最终生效权限、来源和锁定状态 | 无 |
| GET | /api/user/features | 当前登录用户的有效功能开关；短视频六项已合并应用开关、上级强制规则、用户定向规则与个人角色权限，仅返回安全的最终布尔值 | 无 |
| PUT | `/api/user/profile` | 修改资料 | `nickname,qq,email,signature,avatar,background,gender` |
| PUT | `/api/user/password` | 修改密码 | `old_password,new_password` |
| POST | `/api/user/password/reset` | 找回密码 | `app_key,account,email_or_phone,code,new_password` |
| POST | `/api/user/sign` | 每日签到 | 无 |
| GET | `/api/user/wallet` | 我的资产 | 无 |
| GET | `/api/user/logs` | 我的行为日志 | `page,limit` |

#### 文档

| 方法 | 路径 | 功能 | 主要参数 |
| --- | --- | --- | --- |
| GET | `/api/user/notes` | 我的笔记列表 | `folder_id,keyword,page,limit` |
| POST | `/api/user/notes` | 新建笔记 | `folder_id,title,content,content_type` |
| GET | `/api/user/notes/{document_id}` | 读取笔记 | 无 |
| PUT | `/api/user/notes/{document_id}` | 修改笔记 | `title,content` |
| DELETE | `/api/user/notes/{document_id}` | 删除笔记 | 无 |
| GET | `/api/user/notes/{document_id}/share` | 查询该笔记固定分享码与启用状态 | 无 |
| POST | `/api/user/notes/{document_id}/share` | 创建或重新启用固定分享码；重复调用始终返回同一码 | `password,expired_at` |
| DELETE | `/api/user/note-shares/{share_id}` | 停用分享但保留固定分享码 | 无 |
| POST | `/api/user/note-folders` | 新建文件夹 | `parent_id,name` |
| PUT | `/api/user/note-folders/{folder_id}` | 修改文件夹 | `name,parent_id` |
| DELETE | `/api/user/note-folders/{folder_id}` | 删除文件夹 | 无 |

#### 资源、商店、论坛

| 方法 | 路径 | 功能 | 主要参数 |
| --- | --- | --- | --- |
| GET | `/api/user/resource-categories` | 资源分类 | `resource_type`，源码商城使用 `source_market` |
| GET | `/api/user/resource-submission-policy` | 资源投稿开关与审核策略 | 无 |
| GET | `/api/user/resources` | 资源列表；公开、我的投稿或历史已购三种范围 | `category_id,resource_type,keyword,mine,purchased,audit_status,page,limit` |
| GET | `/api/user/resources/{resource_id}` | 资源详情 | 无 |
| POST | `/api/user/resources` | 投稿源码资源 | `resource_type=source_market,category_id,title,description,cover_url,source_upload_id,price_balance` |
| POST | `/api/user/resources/{resource_id}/buy` | 按确认快照购买资源 | `expected_price_balance,expected_source_upload_id` |
| GET | `/api/user/resources/{resource_id}/download` | 作者或已购用户鉴权下载；支持 ETag/If-Range 续传 | 无 |
| POST | `/api/user/resources/{resource_id}/comments` | 评论资源 | `content` |
| POST | `/api/user/resources/{resource_id}/rating` | 资源评分 | `score` |
| GET | `/api/user/store-categories` | 应用商店分类 | 无 |
| GET | `/api/user/store-submission-policy` | 应用投稿开关与审核策略 | 无 |
| GET | `/api/user/store-apps` | 应用商店列表；公开、我的投稿或历史已购三种范围 | `category_id,keyword,mine,purchased,audit_status,page,limit` |
| POST | `/api/user/store-apps` | 投稿应用安装包 | `category_id,name,package_name,version_name,version_code,source_upload_id,icon_url,price_balance` |
| GET | `/api/user/store-apps/{store_app_id}` | 应用详情 | 无 |
| POST | `/api/user/store-apps/{store_app_id}/buy` | 按确认快照购买应用 | `expected_price_balance,expected_source_upload_id,expected_version_code` |
| GET | `/api/user/store-apps/{store_app_id}/download` | 作者或已购用户鉴权下载；支持 ETag/If-Range 续传 | 无 |
| POST | `/api/user/store-apps/{store_app_id}/reactions` | 点赞/收藏或取消 | `reaction_type=like/favorite` |
| GET | `/api/user/forum-plates` | 论坛板块 | 无 |
| GET | `/api/user/forum-categories` | 二级分类 | `plate_id,keyword` |
| GET | `/api/user/forum-tags` | 可选规范标签与别名 | `plate_id,category_id,keyword` |
| GET/POST | `/api/user/forum-structure-requests` | 我的结构申请/申请新板块或分类 | `request_type,plate_id,name,description,reason` |
| GET | `/api/user/forum-posts` | 帖子列表 | `plate_id,category_id,tag,keyword,date_from,date_to,sort=comprehensive/hot/latest/earliest,page,limit` |
| POST | `/api/user/forum-posts` | 发帖 | `plate_id,category_id,title,content,attachments,tags,price_balance,sections` |
| GET | `/api/user/forum-posts/{post_id}` | 帖子详情 | 无 |
| PUT | `/api/user/forum-posts/{post_id}` | 编辑自己的帖子 | `title,content,images` |
| DELETE | `/api/user/forum-posts/{post_id}` | 删除自己的帖子 | 无 |
| POST | `/api/user/forum-posts/{post_id}/comments` | 评论 | `content,parent_id` |
| POST | `/api/user/forum-posts/{post_id}/like` | 点赞/取消点赞 | 无 |
| POST | `/api/user/forum-posts/{post_id}/favorite` | 收藏/取消收藏 | 无 |
| POST | `/api/user/reports` | 举报 | `target_type,target_id,reason` |

#### 消息、好友、客服、聊天室

| 方法 | 路径 | 功能 | 主要参数 |
| --- | --- | --- | --- |
| GET | `/api/user/messages/unread` | 未读统计 | 无 |
| GET | `/api/user/conversations` | 会话列表 | `page,limit` |
| GET | `/api/user/conversations/{conversation_id}/messages` | 消息列表 | `since_id,page,limit` |
| POST | `/api/user/messages/private` | 发送私信 | `to_user_id,content` |
| POST | `/api/user/friends/requests` | 申请好友 | `to_user_id,message` |
| GET | `/api/user/friends/requests` | 好友申请列表 | 无 |
| POST | `/api/user/friends/requests/{request_id}/accept` | 同意好友 | 无 |
| POST | `/api/user/friends/requests/{request_id}/reject` | 拒绝好友 | 无 |
| GET | `/api/user/friends` | 好友列表与筛选 | `keyword,group_id` |
| PUT | `/api/user/friends/{friend_user_id}` | 修改好友备注与分组 | `remark,group_id` |
| DELETE | `/api/user/friends/{friend_user_id}` | 删除好友 | 无 |
| GET/POST | `/api/user/friend-groups` | 好友分组列表/新建分组 | 无 / `name,sort_order` |
| PUT/DELETE | `/api/user/friend-groups/{group_id}` | 修改/删除好友分组 | `name,sort_order` / 无 |
| GET/POST | `/api/user/chat-room-groups` | 群聊分组列表/新建分组 | 无 / `name,sort_order` |
| PUT/DELETE | `/api/user/chat-room-groups/{group_id}` | 修改/删除群聊分组 | `name,sort_order` / 无 |
| GET/POST | `/api/user/chat-rooms` | 可见群列表/用户建群 | `keyword,page,limit` / `name,icon,description,join_mode,max_members,allow_member_invite,announcement` |
| GET/PUT/DELETE | `/api/user/chat-rooms/{room_id}` | 群详情/群管理员修改/群主解散 | 群资料和规则字段 |
| POST | `/api/user/chat-rooms/{room_id}/avatar` | 群主或管理员上传群聊/聊天室头像（分别受独立功能开关控制） | `multipart file` |
| POST | `/api/user/chat-rooms/{room_id}/join` | 开放加入、提交审批或接受邀请 | `message` |
| POST | `/api/user/chat-rooms/{room_id}/leave` | 退出群聊 | 无 |
| PUT | `/api/user/chat-rooms/{room_id}/user-settings` | 修改当前用户的群备注与分组 | `remark,group_id` |
| GET | `/api/user/chat-rooms/{room_id}/members` | 群成员 | `page,limit` |
| PUT/DELETE | `/api/user/chat-rooms/{room_id}/members/{user_id}` | 群主设管理员/管理者移出成员 | `role` |
| PUT | `/api/user/chat-rooms/{room_id}/members/{user_id}/mute` | 管理者禁言成员 | `mute_until` |
| POST | `/api/user/chat-rooms/{room_id}/transfer` | 转让群主 | `new_owner_user_id` |
| POST | `/api/user/chat-rooms/{room_id}/invitations` | 邀请用户 | `user_id,message,expired_at` |
| GET | `/api/user/chat-room-invitations` | 我的群邀请 | 无 |
| POST | `/api/user/chat-room-invitations/{invitation_id}/accept` | 接受群邀请 | 无 |
| POST | `/api/user/chat-room-invitations/{invitation_id}/reject` | 拒绝群邀请 | 无 |
| GET | `/api/user/chat-rooms/{room_id}/join-requests` | 群管理者查看申请 | 无 |
| POST | `/api/user/chat-rooms/{room_id}/join-requests/{request_id}/approve` | 同意入群 | 无 |
| POST | `/api/user/chat-rooms/{room_id}/join-requests/{request_id}/reject` | 拒绝入群 | 无 |
| GET/POST | `/api/user/chat-rooms/{room_id}/messages` | 群消息/发送消息 | `since_id,limit` / `content,content_type,reply_to_message_id` |
| DELETE | `/api/user/chat-rooms/{room_id}/messages/{message_id}` | 删除自己的消息或管理者撤回 | 无 |
| POST | `/api/user/chat-rooms/{room_id}/read` | 更新已读位置 | `message_id` |
| GET | `/api/user/service/session` | 我的客服会话 | 无 |
| POST | `/api/user/service/messages` | 发送客服消息 | `content` |
| GET | `/api/user/service/messages` | 客服消息列表 | `since_id,page,limit` |

#### 卡密、订单、商城互动、文件、反馈

| 方法 | 路径 | 功能 | 主要参数 |
| --- | --- | --- | --- |
| POST | `/api/user/cards/redeem` | 使用卡密 | `card_code` |
| GET | `/api/user/cards/redeem-logs` | 兑换记录 | `page,limit` |
| GET | `/api/user/orders` | 我的订单 | `status,page,limit` |
| GET | `/api/user/shop-goods` | 商品列表 | `page,limit` |
| POST | `/api/user/shop-goods/{goods_id}/buy` | 购买商品 | `quantity` |
| GET | `/api/user/red-packets` | 查看与自己有关的红包列表 | `page,limit` |
| POST | `/api/user/red-packets` | 创建“按份数发”或“金额池随机抢”红包；领取范围可独立设置 | `packet_type=random/equal,distribution_mode=count_split/random_grab,eligibility_mode=context_all/selected,include_sender,context_user_id,total_amount,total_count,to_user_ids,delivery_scope,context_id,message,expire_seconds` |
| GET | `/api/user/red-packets/{packet_id}` | 查看红包类型、价格标签、领取/退回明细和唯一“运气王” | 无 |
| POST | `/api/user/red-packets/{packet_id}/claim` | 合资格用户领取；金额池随机抢每次从剩余金额随机领取，余额抢完即止 | 无 |
| POST | `/api/user/red-packets/{packet_id}/refund` | 仅私发红包的指定接收人可在领取前退回给发送人；群聊、聊天室、客服和活动红包普通用户不能退回 | 无 |
| GET | `/api/user/transfers` | 查看与自己有关的转账 | 无 |
| POST | `/api/user/transfers` | 向一个或多个接收人转账 | `to_user_ids,amount,remark,expire_seconds` |
| GET | `/api/user/transfers/{transfer_id}` | 查看转账详情及当前用户可执行操作 | 无 |
| POST | `/api/user/transfers/{transfer_id}/accept` | 收款人确认收款 | 无 |
| POST | `/api/user/transfers/{transfer_id}/refund` | 仅收款人可把待收转账退回原付款人；付款人不能自行收回 | 无 |
| GET | `/api/user/gift-catalog` | 获取可赠送礼物目录 | 无 |
| GET | `/api/user/gifts` | 查看与自己有关的礼物 | 无 |
| POST | `/api/user/gifts` | 向一个或多个接收人赠送礼物 | `gift_catalog_id,to_user_ids,message,expire_seconds` |
| GET | `/api/user/gifts/{gift_id}` | 查看礼物详情及当前用户可执行操作 | 无 |
| POST | `/api/user/gifts/{gift_id}/accept` | 收礼人查收礼物 | 无 |
| POST | `/api/user/gifts/{gift_id}/refund` | 仅收礼人可把待查收礼物退回原赠送人；赠送人不能自行收回 | 无 |

红包金额和参与资格是两个独立维度：

- `distribution_mode=count_split`（按份数发）：`total_count` 表示实际红包份数。所有合资格用户参与抢领，份数抢完即止，不保证每个人都有；`packet_type=random` 为随机金额，`packet_type=equal` 为等额金额。
- `distribution_mode=random_grab`（金额池随机抢）：所有合资格用户均可参与，每人最多领取一次，每次从剩余金额池随机领取，余额抢完即止；允许部分参与人没有抢到。旧客户端传入的 `single_race` 会自动兼容为此模式。
- `eligibility_mode=context_all`（当前会话所有人）：私聊、群聊、聊天室、客服或活动上下文内的有效参与人均可抢。
- `eligibility_mode=selected`（仅指定人员）：只有 `to_user_ids` 指定的有效用户可抢；发送人通过 `include_sender=true`（默认值）加入领取范围。

红包和转账金额统一按两位小数的十进制定点数结算。按份数发的单份最低为 `0.01` 余额，因此 `1.00` 余额可创建 5 份拼手气红包；随机拆分后各份之和严格等于总金额。金额池随机抢每次至少领取 `0.01`，可能提前抢完。并发领取使用数据库行锁，同一用户不能重复领取，领取总额不会超过红包总额。领取详情只标记一名金额最高且最早达到该金额的领取人为“运气王”。未领取余额在 24 小时到期后原路退回。
| GET | `/api/user/lottery-prizes` | 抽奖列表 | 无 |
| POST | `/api/user/lottery/draw` | 抽奖 | `lottery_id` |
| GET | `/api/user/votes` | 投票列表 | 无 |
| POST | `/api/user/votes/{vote_id}/submit` | 投票 | `option_ids` |
| GET | `/api/user/remote-files` | 可访问文件 | `parent_id` |
| GET | `/api/user/remote-files/{file_id}` | 读取文件 | 无 |
| POST | `/api/user/uploads` | 上传文件 | `file,scene` |
| POST | `/api/user/feedbacks` | 提交反馈 | `type,title,content,images` |
| GET | `/api/user/feedbacks` | 我的反馈 | `page,limit` |

### 4.4 public 公开接口

| 方法 | 路径 | 功能 | 主要参数 |
| --- | --- | --- | --- |
| GET | `/api/public/app` | 应用公开信息 | `app_key` |
| GET | `/api/public/bootstrap` | 启动配置 | `app_key,version_code,device` |
| GET | `/api/public/branding` | 获取应用所属管理员已发布且带版本哈希的官网、官方群、介绍与收款码链接 | `app_key` 或 `X-App-Key` |
| GET | `/api/public/features` | 功能开关 | `app_key` |
| GET | `/api/public/notices` | 公告列表 | `app_key,type` |
| GET | `/api/public/banners` | 轮播/启动图 | `app_key,position` |
| GET | `/api/public/version` | 检查更新 | `app_key,version_code` |
| GET | `/api/public/note-shares/{share_code}` | 读取公开笔记 | `password` |
| GET | `/api/public/resource-categories` | 公开资源分类 | `app_key` |
| GET | `/api/public/resources` | 公开资源列表 | `app_key,category_id,page,limit` |
| GET | `/api/public/resources/{resource_id}` | 公开资源详情 | `app_key` |
| GET | `/api/public/forum-plates` | 公开板块 | `app_key` |
| GET | `/api/public/forum-posts` | 公开帖子列表 | `app_key,plate_id,page,limit` |
| GET | `/api/public/forum-posts/{post_id}` | 公开帖子详情 | `app_key` |
| GET | `/api/public/forum-media/{attachment_id}` | 短期签名论坛媒体流（支持 Range） | `app_id,expires,signature` |
| GET | `/api/public/remote-files/{file_id}` | 读取公开文件 | `app_key` |
| POST | `/api/public/captcha` | 获取验证码 | `app_key,scene,account` |
| POST | `/api/public/payment/callback/{channel}` | 支付回调 | 支付平台参数 |

### 4.5 四级层级活动与受众规则

平台端使用 `/api/platform/activities*`，admin 端使用 `/api/admin/activities*`，user 端使用 `/api/user/activities*`。1/2 级平台和 3 级 admin 可以发布；4 级 user 只能查看与参与。活动类型为 `red_packet`、`lottery`、`bounty`。

| 字段 | 含义 |
| --- | --- |
| `audience_sync=true` | 默认模式，`targets` 同时作为可见范围和参与范围 |
| `audience_sync=false` | 分离模式，分别读取 `visibility_targets` 和 `participation_targets` |
| `visibility_targets` | 额外仅可见范围；命中者可看详情但不能领取、抽奖或投稿 |
| `participation_targets` | 可参与范围；系统自动赋予这些目标可见权，不允许出现“能参与却看不见” |
| 目标对象 | 支持 `level`、`platform`、`admin`、`app`、`actor`，且只能选择发布者同级或下级的合法分支 |

例如 2 级只向 3 级发红包时，目标为 `[{"type":"level","level":3}]`，4 级 user 的列表和详情均不可见，直接访问返回 `404`。若关闭同步，把 4 级放入 `visibility_targets`、3 级放入 `participation_targets`，4 级可以查看，但领取返回 `403`。只有把 4 级加入 `participation_targets` 才能领取。

| 方法 | 路径 | 功能 |
| --- | --- | --- |
| GET | `/api/platform/activities` | 1/2 级可见活动列表 |
| GET | `/api/admin/activities` | 3 级可见活动列表 |
| GET | `/api/user/activities` | 4 级可见活动列表 |
| GET | `/api/platform/activities/{activity_id}` | 平台活动详情与受众权限 |
| GET | `/api/admin/activities/{activity_id}` | admin 活动详情与受众权限 |
| GET | `/api/user/activities/{activity_id}` | user 活动详情与受众权限 |
| POST | `/api/platform/activities` | 1/2 级发布活动并冻结预算 |
| POST | `/api/admin/activities` | 3 级发布活动并冻结预算 |
| POST | `/api/platform/activities/{activity_id}/claim` | 平台领取被授权红包 |
| POST | `/api/admin/activities/{activity_id}/claim` | admin 领取被授权红包 |
| POST | `/api/user/activities/{activity_id}/claim` | user 领取被授权红包 |
| POST | `/api/platform/activities/{activity_id}/draw` | 平台参与被授权抽奖 |
| POST | `/api/admin/activities/{activity_id}/draw` | admin 参与被授权抽奖 |
| POST | `/api/user/activities/{activity_id}/draw` | user 参与被授权抽奖 |
| POST | `/api/platform/activities/{activity_id}/submit` | 平台提交被授权悬赏 |
| POST | `/api/admin/activities/{activity_id}/submit` | admin 提交被授权悬赏 |
| POST | `/api/user/activities/{activity_id}/submit` | user 提交被授权悬赏 |
| POST | `/api/platform/activities/{activity_id}/award` | 平台结算悬赏 |
| POST | `/api/admin/activities/{activity_id}/award` | admin 结算自己发布的悬赏 |
| POST | `/api/platform/activities/{activity_id}/close` | 平台结束活动并退回余额 |
| POST | `/api/admin/activities/{activity_id}/close` | admin 结束活动并退回余额 |
| POST | `/api/platform/activities/{activity_id}/cancel` | 平台取消活动并原子退款 |
| POST | `/api/admin/activities/{activity_id}/cancel` | admin 取消活动并原子退款 |
| GET | `/api/platform/activities/balance` | 平台活动余额与流水 |
| GET | `/api/admin/activities/balance` | admin 活动余额与流水 |
| GET | `/api/user/activities/balance` | user 活动余额与流水 |

### 4.6 统一多媒体、撤回、隐私主页与监管资料

私聊、群聊、客服、论坛帖子/评论、资源/评论和商店应用统一使用 `attachments` 数组，支持 `image`、`sticker`、`audio`、`video`、`file`，正文与附件可混合发送，单条聊天消息最多 200 个媒体文件，更多内容应分批发送。用户先调用 `POST /api/user/uploads` 上传本地相册或文件；admin 使用 `POST /api/admin/apps/{app_id}/uploads`。

私聊和群聊按 L1/L2/L3 继承与强制规则限时撤回，客服消息不可撤回。普通 user 不会收到撤回原附件；合法管理范围内的 L1/L2/L3 可通过用户 `communications` 审计接口查看原文和原附件。

`GET /api/user/profiles/{user_id}` 对隐藏资料返回基础主页而非 403，使用 `profile_visibility` 和 `details_hidden` 标识可见范围。平台和 admin 的用户监管接口按“资料与资产、消息类、社交类、内容类、其他”返回结构化数据。完整请求示例、字段和 Android 可视化流程见 [MULTIMEDIA_VISUAL.md](MULTIMEDIA_VISUAL.md)。

### 4.7 UID、动态注册、二维码好友与独立解绑审核

用户输入的 `account` 是登录账号，`nickname` 是展示昵称，`uid` 是服务器生成的统一搜索码。UID 当前生成 10 至 16 位数字，但客户端不得假设固定长度；加好友、群邀请和用户搜索均可传 `uid`。邮箱和手机号是否显示、是否必填由所属应用配置，密码始终要求二次确认。启用邮箱注册后必须先取得邮箱验证码，一个邮箱或手机号只能绑定一个账号。

解绑不是同一申请逐级流转。每一级只处理自己的直属解绑：4 级 user 的申请由所属 3 级 admin 审核；3 级 admin 由直属 2 级审核，若直属 1 级则由 1 级审核；2 级由 1 级审核。1 级查看全树或强制处理必须显式使用 `scope=all` 或 `force=true`，并在日志中标记为总控操作。

| 方法 | 路径 | 功能与主要参数 |
| --- | --- | --- |
| GET | `/api/public/bootstrap` | 返回 `registration_policy`、上传限制、功能开关；参数 `app_key` |
| POST | `/api/public/verification-code/email` | 发送注册/绑定邮箱验证码；`app_key,email,scene` |
| POST | `/api/user/register` | `app_key,account,nickname,password,password_confirmation,email?,email_code?,phone?` |
| GET | `/api/user/friends/qr-code` | 返回当前用户 UID 和带签名、绑定应用、有限期的二维码内容 |
| POST | `/api/user/friends/scan-qr` | 扫描并校验二维码后创建好友申请；`qr_payload,message` |
| GET | `/api/user/identity-unbind-requests` | 查看当前用户自己的解绑申请 |
| POST | `/api/user/identity-unbind-requests` | 提交邮箱或手机号解绑；`identity_type,reason` |
| GET | `/api/admin/identity-unbind-requests` | 3 级只查看直属 4 级待审核申请 |
| POST | `/api/admin/identity-unbind-requests/{request_id}/review` | 直属 3 级审核；`action=approve/reject,remark` |
| GET | `/api/admin/my-identity-unbind-requests` | 3 级查看自己的解绑申请 |
| POST | `/api/admin/my-identity-unbind-requests` | 3 级向自己的直属平台提交解绑 |
| GET | `/api/platform/identity-unbind-requests` | 默认直属列表；1 级显式传 `scope=all` 才查看全树 |
| POST | `/api/platform/identity-unbind-requests/{request_id}/review` | 直属审核；1 级跨层处理必须显式传 `force=true` |
| GET | `/api/platform/my-identity-unbind-requests` | 2 级查看自己的解绑申请 |
| POST | `/api/platform/my-identity-unbind-requests` | 2 级向 1 级提交自己的解绑申请 |

### 4.8 统一收藏、媒体缓存与跨设备备份

收藏中心不再把聊天消息、论坛帖子、生活动态、笔记、悬赏、资源、应用、商城商品和上传文件拆散在多个入口。`GET /api/user/favorites` 返回统一中文标题、摘要、来源、来源动作、目标编号、预览媒体和快照，支持 `all/messages/images/links/files/posts/moments/notes/bounties/resources/apps/goods/uploads`。收藏中心点击进入只读快照，点击“查看来源”则按 `source_type + target_id` 精确回到原消息、帖子、动态、笔记、悬赏、资源、应用、商品或文件详情；聊天内收藏选择器与收藏中心共用同一账号收藏库，但保留单选/多选发送交互。聊天媒体默认上传优化版本，用户选择“原图/原视频”才传 `original_upload=1`；GIF 保留动效，超出应用分类上限的媒体不可选择但仍可本地预览。

| 方法 | 路径 | 功能与主要参数 |
| --- | --- | --- |
| GET | `/api/user/favorites` | 统一收藏中心；`category,keyword,page,limit` |
| GET | `/api/user/sticker-packs` | 表情包分组、表情列表和缓存元数据 |
| POST | `/api/user/sticker-packs/{pack_id}/stickers/batch` | 批量添加表情包 |
| DELETE | `/api/user/sticker-packs/{pack_id}/stickers/batch` | 批量删除表情包；`sticker_ids` |
| GET | `/api/user/cloud-sync/policy` | 返回聊天、表情、收藏云同步的会员和余额条件 |
| GET | `/api/user/cloud-sync/snapshots` | 按类型查看云端快照 |
| POST | `/api/user/cloud-sync/snapshots` | 创建选定范围的聊天/表情/收藏快照 |
| POST | `/api/user/cloud-sync/snapshots/{snapshot_id}/restore` | 在另一设备拉取只读快照数据 |
| DELETE | `/api/user/cloud-sync/snapshots/{snapshot_id}` | 删除自己的云端快照 |
| POST | `/api/user/chat-records/cleanup` | 按会话、发言人、时间段或消息编号清理本地显示状态 |

### 4.9 简云能力原生增强

本节列出本次学习简云接口体系后补入的闭环能力。它们使用易运盈统一鉴权、租户隔离、中文枚举和 REST 语义，不提供旧路径兼容层。完整对照与取舍见 [JIANYUN_CAPABILITY_MAPPING.md](JIANYUN_CAPABILITY_MAPPING.md)。

| 方法 | 路径 | 功能与主要参数 |
| --- | --- | --- |
| POST | `/api/public/app/visit` | 记录 APP 访问；`visitor_id,source,path` |
| GET | `/api/public/app/statistics` | 获取受开关控制的访问、访客、注册和在线统计 |
| POST | `/api/public/card-login` | 登录卡首次绑定；`app_key,card_code,device_id,device_label` |
| POST | `/api/public/card-auto-login` | 登录卡设备密钥自动登录；`app_key,device_id,device_secret` |
| POST | `/api/user/heartbeat` | 用户心跳并返回中文在线状态 |
| GET | `/api/user/wallet/logs` | 用户统一余额、权益和奖励流水 |
| GET | `/api/user/users/search` | 在当前应用按 UID、账号或昵称搜索；`keyword,page,limit` |
| GET | `/api/user/profiles/{user_id}/follow-status` | 关注、互关、黑名单和可交互状态 |
| GET | `/api/user/forum-posts/mine` | 我的帖子与审核状态 |
| GET | `/api/user/forum-posts/purchased` | 已购买的付费帖子 |
| GET | `/api/user/forum-posts/following` | 我关注用户发布的帖子 |
| GET | `/api/user/forum-posts/liked` | 我点赞过的帖子 |
| GET | `/api/user/forum-report-tags` | 当前应用的举报标签 |
| GET | `/api/user/forum-reports` | 我的举报和处理进度 |
| GET | `/api/user/forum-posts/{post_id}/comments` | 帖内评论分页；`scope=roots/thread`，`sort=comprehensive/hot/latest/earliest`；评论串传 `root_comment_id` 或通知深链的 `comment_id`，返回 `resolved_root_comment_id`，且 `items[0]` 固定为主评论；`pagination`/`reply_total` 只统计回复，`thread_total` 含主评论；未指定页码的深链会自动返回目标所在页并回显 `focused_reply_page` |
| GET | `/api/user/forum-posts/{post_id}/likes` | 帖子点赞用户列表 |
| PUT | `/api/admin/apps/{app_id}/forum-posts/{post_id}/audit` | 管理员审核帖子：通过/不通过/暂定；`audit_status=approved/rejected/on_hold,reason`，不通过原因必填，暂定说明可选 |
| GET | `/api/admin/apps/{app_id}/forum-comments` | 管理员查看评论和待审核队列 |
| PUT | `/api/admin/apps/{app_id}/forum-comments/{comment_id}/audit` | 管理员审核评论：`audit_status=approved/rejected/on_hold,reason`，并校验帖子和上级回复状态 |
| DELETE | `/api/admin/apps/{app_id}/forum-comments/{comment_id}` | 管理员删除违规评论 |
| PUT | `/api/admin/apps/{app_id}/moments/{moment_id}/audit` | 管理员审核动态：`audit_status=approved/rejected/on_hold,reason` |
| GET | `/api/admin/apps/{app_id}/moment-comments` | 管理员查看动态评论审核队列；`audit_status,keyword,page,limit` |
| PUT | `/api/admin/apps/{app_id}/moment-comments/{comment_id}/audit` | 管理员审核动态评论；`audit_status=approved/rejected/on_hold,reason`，并校验动态和上级回复状态 |
| GET | `/api/admin/apps/{app_id}/forum-report-tags` | 查询举报标签 |
| POST | `/api/admin/apps/{app_id}/forum-report-tags` | 新增举报标签 |
| PUT | `/api/admin/apps/{app_id}/forum-report-tags/{tag_id}` | 修改举报标签 |
| DELETE | `/api/admin/apps/{app_id}/forum-report-tags/{tag_id}` | 删除未被引用的举报标签 |
| GET | `/api/admin/apps/{app_id}/card-login-bindings` | 查询登录卡设备绑定审计 |
| GET | `/api/user/chat-rooms/dissolved` | 群主查看可恢复的已解散群 |
| POST | `/api/user/chat-rooms/{room_id}/restore` | 在规则期限内恢复群聊 |

### 4.10 二级/三级通信监管与系统接管

2 级授权平台只能监管自己分支内 3 级 admin 的应用及其 4 级 user；3 级 admin 只能监管自己的应用和 user。监管者查看私聊、群聊、聊天室和客服会话时不会成为群成员或聊天室成员。接管发言对普通成员统一显示“系统消息”和“系统”标识，真实操作人的层级、编号、IP、会话和消息编号只写入通信接管审计。

策略采用“上级默认值 + 可编辑下放 + 强制锁定”。1 级可强制锁定 2/3 级，2 级可强制锁定自己的 3 级；没有强制锁定时，3 级可独立设置查看、发言及私聊/群聊与聊天室/客服三个通道。消息列表同时返回 `sender_display_name`、`sender_badge`、`sender_role` 和 `sender_badge_tone`，用于显示“群主”“版主”“客服”“系统”等显式身份。

| 方法 | 路径 | 功能与主要参数 |
| --- | --- | --- |
| GET | `/api/platform/apps/{app_id}/users/{user_id}/communications` | 1/2 级查看下级通信；`type,channel_id,keyword,content_filter,page,limit` |
| POST | `/api/platform/apps/{app_id}/users/{user_id}/communications/send` | 1/2 级以系统身份接管发言；`channel_type,channel_id,content` |
| GET | `/api/platform/apps/{app_id}/communication-takeover-policy` | 查看平台有效策略、锁定状态和公开身份 |
| PUT | `/api/platform/apps/{app_id}/communication-takeover-policy` | 设置平台/管理员通道权限；可传 `force_descendants` |
| GET | `/api/platform/apps/{app_id}/communication-takeover-audits` | 查询真实接管审计；可按操作人、动作和通道过滤 |
| GET | `/api/platform/apps/{app_id}/message-forwards/{forward_id}` | 1/2 级在合法分支内读取完整只读聊天快照 |
| GET | `/api/admin/apps/{app_id}/users/{user_id}/communications` | 3 级查看自己应用 user 的通信 |
| POST | `/api/admin/apps/{app_id}/users/{user_id}/communications/send` | 3 级以系统身份接管发言 |
| GET | `/api/admin/apps/{app_id}/communication-takeover-policy` | 查看管理员有效策略和上级锁定状态 |
| PUT | `/api/admin/apps/{app_id}/communication-takeover-policy` | 未被强制锁定时设置自己的查看与发言权限 |
| GET | `/api/admin/apps/{app_id}/communication-takeover-audits` | 查看自己应用范围内的接管审计 |
| GET | `/api/admin/apps/{app_id}/message-forwards/{forward_id}` | 3 级读取自己应用内的完整只读聊天快照 |

`content_filter` 支持 `all/file/tag/snapshot`。`file` 会搜索当前消息和转发快照中的文件名、媒体类型与 MIME 类型；`tag` 会搜索当前消息和快照中的标签；`snapshot` 专门搜索只读聊天快照。快照保留原发送者昵称、身份标识、时间、正文、标签和附件，接收者可以查看、搜索与复制，但不能修改快照内部记录。

### 4.11 群空间、语音转写与媒体类型

群文件、群相册、群投票和群接龙都属于同一个群上下文，不再跳入通用“数据记录”页。群文件支持真实文件夹层级、目录导航、上传人、文件类型、大小、下载次数和权限删除；删除文件夹会递归软删除其下内容，但不会影响同级文件。相册同时接受图片和视频，并返回上传人、媒体类型、MIME、文件大小、缩略图和下载次数。投票支持单选或多选，接龙按服务端提交顺序生成连续编号，避免多人同时填写时出现重复序号。创建、上传、参与等操作会写入群系统消息。

GIF 与手机拍摄的动态照片是两个独立类型：GIF 依据 MIME、扩展名和文件头识别；动态照片依据 JPEG/HEIC 中的 Motion Photo、MicroVideo 等元数据识别。两者都可发送，但客户端显示不同中文标识。聊天语音转写会先验证用户对私聊、群聊或客服会话的读取权限；结果显示在语音条下方，可展开或收起，不通过文件地址绕过会话权限。完整环境变量见 [SPEECH_TRANSCRIPTION.md](SPEECH_TRANSCRIPTION.md)。

| 方法 | 路径 | 功能与主要参数 |
| --- | --- | --- |
| POST | `/api/user/audio/transcriptions` | 转写有权读取的语音附件；`message_id,attachment_id,scope_type,scope_id` |
| GET | `/api/user/chat-rooms/{room_id}/files` | 查看当前目录；根目录省略 `parent_id`，子目录传 `parent_id` |
| POST | `/api/user/chat-rooms/{room_id}/files` | 上传文件：`name,file_url,mime_type,size_bytes,parent_id?`；新建文件夹：`name,is_folder=true,parent_id?` |
| POST | `/api/user/chat-rooms/{room_id}/files/{file_id}/download` | 下载前登记并取得 `file_url`，原子增加 `download_count` |
| DELETE | `/api/user/chat-rooms/{room_id}/files/{file_id}` | 上传者、群管理或上级监管删除群文件 |
| GET | `/api/user/chat-rooms/{room_id}/albums` | 查看群相册及其中图片/视频 |
| POST | `/api/user/chat-rooms/{room_id}/albums` | 创建群相册；`name,description?` |
| POST | `/api/user/chat-rooms/{room_id}/albums/{album_id}/photos` | 添加图片或视频；`url,file_name,media_type,mime_type,size_bytes` |
| DELETE | `/api/user/chat-rooms/{room_id}/albums/{album_id}/photos/{photo_id}` | 上传者、群管理或上级监管删除相册媒体 |
| GET | `/api/user/chat-rooms/{room_id}/votes` | 查看群投票及参与状态 |
| POST | `/api/user/chat-rooms/{room_id}/votes` | 创建单选/多选群投票；`title,options,multiple` |
| POST | `/api/user/chat-rooms/{room_id}/votes/{vote_id}/submit` | 提交一个或多个选项；`option_ids` |
| GET | `/api/user/chat-rooms/{room_id}/solitaires` | 查看群接龙列表和人数 |
| GET | `/api/user/chat-rooms/{room_id}/solitaires/{solitaire_id}` | 查看有序接龙明细 |
| POST | `/api/user/chat-rooms/{room_id}/solitaires/{solitaire_id}/join` | 参与接龙；`content,fields?` |

### 4.12 应用内语音与视频通话

语音和视频通话只使用应用内网络通信，不调用运营商电话，也不会自动保存录音或录像。呼叫双方先通过后端交换 WebRTC 信令，再建立点对点媒体通道；Android 端支持麦克风、听筒/扬声器、前后摄像头切换、系统原生画中画、桌面边缘停靠和持续通话计时。音频与视频使用同一状态机，服务端按应用、用户和参与方校验每一次读取与写入。

| 方法 | 路径 | 功能与主要参数 |
| --- | --- | --- |
| POST | `/api/user/voice-calls` | 发起语音或视频呼叫；`target_user_id,call_type=audio|video,offer` |
| GET | `/api/user/voice-calls/incoming` | 查询当前来电和通话状态 |
| GET | `/api/user/voice-calls/{call_id}` | 查看参与的通话、ICE 配置和最新信令 |
| POST | `/api/user/voice-calls/{call_id}/answer` | 接听并提交 WebRTC answer；`answer` |
| POST | `/api/user/voice-calls/{call_id}/decline` | 拒绝来电；`reason?` |
| POST | `/api/user/voice-calls/{call_id}/hangup` | 主叫取消或任一参与方挂断；`reason?` |
| POST | `/api/user/voice-calls/{call_id}/signals` | 发送 offer、answer 或 ICE candidate；`signal_type,payload` |
| GET | `/api/user/voice-calls/{call_id}/signals` | 按游标拉取信令；`after_id` |

完整状态机、画中画行为和生产 TURN 配置见 [NETWORK_CALLS.md](NETWORK_CALLS.md)。仅配置公共 STUN 时，大多数普通网络可以连接，但严格 NAT、企业网和部分移动网络需要部署 TURN 才能保证通话成功率。

### 4.13 服务器本地 AI、综合知识库与连续会话

用户端不直连任何模型。问题先进入 PHP：天气、账号、余额、权限等确定性问题由真实业务工具回答；其余问题检索当前租户知识库，并把命中的资料和当前会话上下文交给服务器本机 Ollama。默认模型 `qwen2.5:3b` 可处理文学、语言、历史、人文、文化、地理、农学、自然科学、工程技术、数学、哲学、教育、旅行与一般生活问题。模型不可用时依次退回租户知识库和本地规则，接口仍返回统一中文 JSON。

知识库遵守四级隔离：1 级可管理自己根平台下的全局、平台、管理员和应用知识；2 级可只读继承 1 级全局知识，只能修改自己分支；3 级只能维护自己应用的知识；4 级只能在问答中使用生效知识，不能越权浏览原始知识库。来源地址、命中文档、模型、耗时和会话都留在服务器端，Android 看不到 Ollama 地址或密钥。

| 方法 | 路径 | 功能与主要参数 |
| --- | --- | --- |
| GET | `/api/platform/ai/status` | 1/2 级检查本地模型配置、连通性与熔断状态 |
| GET | `/api/platform/ai-knowledge` | 分页查询可见知识；`q,scope_type,status,page,limit` |
| POST | `/api/platform/ai-knowledge` | 创建知识；`scope_type,title,content,keywords,source_url,priority,status`，按范围补 `platform_id/admin_id/app_id` |
| GET | `/api/platform/ai-knowledge/{document_id}` | 查看知识详情和是否继承、是否可管理 |
| PUT | `/api/platform/ai-knowledge/{document_id}` | 更新可管理知识，2 级不能修改继承的全局知识 |
| DELETE | `/api/platform/ai-knowledge/{document_id}` | 删除可管理知识 |
| GET | `/api/admin/apps/{app_id}/ai/status` | 3 级检查当前应用使用的本地 AI 状态 |
| GET | `/api/admin/apps/{app_id}/ai-knowledge` | 查询本应用知识；`q,status,page,limit` |
| POST | `/api/admin/apps/{app_id}/ai-knowledge` | 新增本应用知识；`title,content,keywords,source_url,priority,status` |
| GET | `/api/admin/apps/{app_id}/ai-knowledge/{document_id}` | 查看本应用知识详情 |
| PUT | `/api/admin/apps/{app_id}/ai-knowledge/{document_id}` | 修改本应用知识 |
| DELETE | `/api/admin/apps/{app_id}/ai-knowledge/{document_id}` | 删除本应用知识 |
| POST | `/api/user/bot/ask` | 综合问答；`question,conversation_id?,latitude?,longitude?,location_name?`，显式城市名优先于定位 |
| GET | `/api/user/ai/conversations` | 查询自己的 AI 会话列表；`page,limit` |
| GET | `/api/user/ai/conversations/{conversation_id}/messages` | 查看自己的连续会话消息 |
| DELETE | `/api/user/ai/conversations/{conversation_id}` | 删除自己的 AI 会话及消息 |

部署、模型内存选择、PHP-FPM 环境变量和真实问答自检见 [../deploy/local-ai/README.md](../deploy/local-ai/README.md) 与 [../deploy/DEPLOY.md](../deploy/DEPLOY.md)。

## 5. 已实现的最大业务闭环

当前后端已经完成并通过端到端测试的主链路：

1. 管理员登录、资料与密码管理、创建/启停/删除应用、重置应用密钥、绑定域名。
2. 每个应用独立配置功能开关、注册登录规则、文档规则、转账限额和业务参数。
3. 用户注册、登录、刷新令牌、资料修改、找回密码、签到、邀请、排行、资产与标签管理。
4. 用户与管理员自有文档 CRUD、文档文件夹、版本留痕、额度扣减、加密分享、公开读取、回收和恢复。
5. 公告、版本、轮播图、远程配置和一次聚合返回的公开启动配置。
6. 资源分类、用户投稿、管理员审核、余额购买、卖家入账、评论评分和应用商店。
7. 论坛板块、发帖、评论、点赞、收藏、置顶、加精、锁定、审核、举报与处置。
8. 系统通知、私信会话、好友申请、管理员/用户建群、开放/审批/邀请入群、成员角色、禁言、群主转让、已读位置、user 群消息、admin 群管理和客服双向回复。
9. 卡密批次、卡密状态、原子兑换、余额/活动额度/经验/笔记额度/VIP 入账及兑换审计。
10. 支付渠道、订单、HMAC 回调验签、金额校验、重复回调幂等、支付流水和商品交付。
11. 余额商城、现金商品订单、红包、抽奖、投票与资产转账。
12. 远程文件树、文件版本、公开读取、真实文件上传、反馈回复和机器人问答库。
13. 管理操作日志、用户行为日志、登录日志、API 日志、错误日志、每日统计和财务统计。
14. 所有用户业务强制绑定唯一 `app_id`，令牌与 `X-App-Key` 双重校验，跨应用访问返回 `403`。
15. `tools/smoke-maximum.ps1` 以两个用户完成 138 项端到端检查，并覆盖管理员文档 CRUD、user 群消息与 admin 群管理生命周期。
16. 1/2 级平台、3 级会员与额度、注册/IP 限制、购买发放、反馈回复和强制轮询均已进入真实接口链路。
17. `tools/smoke-platform.ps1` 验证 `1级强制 > 2级强制 > 3级配置`、连带阻断和级联删除。
18. admin 平台余额商品、报价、原子兑换、幂等、防超卖、限购、会员到期恢复、资源占用退款保护、余额流水和分级审计形成独立最大闭环。
19. `tools/smoke-exchange.ps1` 以 48 项专项断言验证自动兑换的成功与失败路径，失败事务不会改变余额、库存或权益。
20. `tools/smoke-exchange-concurrency.ps1` 通过两个独立 PHP 进程完成 8 项并发断言，验证单库存竞争和同幂等键竞争。
21. `tools/smoke-hierarchy.ps1` 完成 47 项层级断言，验证 2→3 时 4 不可见、仅可见不可领、参与自动可见、跨分支隔离、1 级审计和余额退款。
22. `tools/smoke-identity-qr.ps1` 完成 28 项断言，验证动态注册字段、非固定长度 UID、签名二维码、扫码好友和直属层级独立解绑审核。
23. 消息权益、多媒体可视化、聊天转发搜索、媒体缓存云同步专项脚本合计完成 250 项断言，通知中心另行验证聊天列表与通知分组彻底分离。
24. `tools/smoke-jianyun-capabilities.ps1` 验证访问统计、用户搜索、关注、论坛审核举报、群恢复、登录卡设备绑定、心跳和资产账单；错误密钥与跨设备重复绑卡均被拒绝。
25. 2/3 级通信监管支持私聊、群聊、聊天室和客服查看/接管，成员列表隐身，公开系统身份与真实审计身份分离，并支持正文、文件、标签和只读聊天快照搜索。
26. `tools/smoke-chat-commerce.ps1` 完成 99 项红包、转账、名片、礼物和订单断言；`tools/smoke-group-space.ps1` 完成 52 项群文件夹、目录导航、下载计数、递归删除、50 MB 图片、100 MB 视频、相册、投票、接龙和系统通知断言。
27. 当前仓库的闭环脚本覆盖四级租户、身份、通信、支付、群空间和媒体链路；全新数据库安装包含 221 张表，API 工作台由 811 条真实路由生成。
28. 本地 AI 完成实时工具、四级租户知识库、连续会话、本机大模型和离线兜底闭环，并提供 Ollama、模型、PHP 扩展和真实问答联合自检。

## 6. 从旧源码吸收的方向

| 来源 | 吸收 | 放弃 |
| --- | --- | --- |
| 易云后台 | 多应用、用户管理、卡密、论坛、聊天室、商城、云盘、公告更新 | 文件夹数据库、散装 PHP、明文密码、旧 `api` 命名 |
| 星光文档 | 文档中心、文档券、iApp 调用方式、卡密兑换、消息提醒 | 文件存储用户数据、缺失接口、硬编码域名 |
| 水仙后台 | Key/Token 开关、邮箱注册、外部接口、模块化接口文档、论坛/消息/抽奖/应用商店模块 | 只按截图实现，不照搬旧接口 |
| APP 通用后台 | Webman 架构、路由、中间件、Token、MySQL、日志 | 功能过少、字段不够 |
| KL-PHP | 小工具库思想 | SG 加密依赖、不可控框架底座 |
