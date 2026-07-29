# 易运盈后台 1/2/3/4 权限与需求核验表

> 生成日期：2026-07-22 04:37:17（Asia/Shanghai）
>
> 本表由后端权限定义自动生成。结论分为自动断言、静态实现核验和后续真机复验；不以“代码存在”冒充真机体验通过。

## 核验口径

- 1级：平台总控，永久全权，可管理全部2/3/4级、应用及附属数据。
- 2级：授权平台，只管理自己的3级、应用和4级分支。
- 3级：管理员，只管理自己创建或获授权的应用及其4级用户。
- 4级：用户，只能使用并查看自己在所属应用内的最终权限。
- 上级强制规则优先；页面同时展示本级配置、最终结果、授权来源和锁定原因。

## 逐项核验

| 编号 | 用户要求 / 权限项 | 适用层级 | 应有行为 | 实现证据 | 核验结论 |
| ---: | --- | --- | --- | --- | --- |
| 1 | 管理员管理（`admins.manage`） | 2级授权平台 / 组织与账号 | 创建、编辑、封禁和删除本分支管理员。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 2 | 管理员授权（`admins.permissions`） | 2级授权平台 / 组织与账号 | 调整本分支管理员的功能权限。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 3 | 管理员代管（`admins.impersonate`） | 2级授权平台 / 组织与账号 | 签发受审计的临时代管令牌进入管理员后台。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 4 | 数据查看（`data.view`） | 2级授权平台 / 数据与审计 | 查看本分支统计、日志和业务概览。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 5 | 数据管理（`data.manage`） | 2级授权平台 / 数据与审计 | 管理本分支应用、用户和业务数据。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 6 | 平台设置（`settings.manage`） | 2级授权平台 / 平台配置 | 修改本分支注册、额度和默认规则。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 7 | 强制规则（`governance.manage`） | 2级授权平台 / 平台配置 | 向本分支下级下发允许、禁止或强制同步规则。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 8 | 余额与计费（`billing.manage`） | 2级授权平台 / 资产与运营 | 管理充值、兑换、余额和计费记录。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 9 | 奖励规则（`reward_management`） | 2级授权平台 / 资产与运营 | 配置注册、签到、邀请和内容行为奖励。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 10 | 活动管理（`activities.manage`） | 2级授权平台 / 内容与运营 | 管理红包、抽奖、投票和悬赏活动。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 11 | 反馈处理（`feedback.manage`） | 2级授权平台 / 内容与运营 | 查看和处理下级反馈与申诉。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 12 | 智能知识库（`ai.manage`） | 2级授权平台 / 内容与运营 | 管理智能机器人知识、状态和回答来源。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 13 | 更新与维护（`software.manage`） | 2级授权平台 / 平台配置 | 发布软件更新、维护通知和节日主题。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 14 | 应用管理（`apps.manage`） | 3级管理员 / 应用与用户 | 创建、启停和维护自己的应用。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 15 | 用户管理（`users.manage`） | 3级管理员 / 应用与用户 | 管理应用用户、资料、状态和个人权限。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 16 | 下级用户审计（`downstream_users.access`） | 3级管理员 / 应用与用户 | 查看下级用户概览、关系和行为记录。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 17 | 文档管理（`documents.manage`） | 3级管理员 / 内容管理 | 新增、查询、编辑和删除远程文档。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 18 | 公告与版本（`content.manage`） | 3级管理员 / 内容管理 | 管理公告、版本、维护和远程配置。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 19 | 资源与商店（`resources.manage`） | 3级管理员 / 内容管理 | 管理应用、源码、商品、分类和订单。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 20 | 论坛与审核（`forum.manage`） | 3级管理员 / 社区管理 | 管理板块、帖子、评论、标签和审核。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 21 | 聊天与群组（`communication.manage`） | 3级管理员 / 社区管理 | 查看并管理私聊、群聊、聊天室和客服会话。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 22 | 活动管理（`activities.manage`） | 3级管理员 / 运营管理 | 管理红包、抽奖、投票、悬赏和奖励。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 23 | 交易与订单（`commerce.manage`） | 3级管理员 / 运营管理 | 管理余额、支付、商品订单和交易追踪。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 24 | 卡密管理（`cards.manage`） | 3级管理员 / 运营管理 | 创建、查询、停用和核销卡密。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 25 | 文件管理（`files.manage`） | 3级管理员 / 内容管理 | 管理上传文件、媒体、下载和存储记录。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 26 | 统计与日志（`statistics.view`） | 3级管理员 / 数据与审计 | 查看应用统计、登录记录和操作日志。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 27 | 账号服务（`user_account`） | 4级用户 / 账号与资料 | 登录、退出、密码和账号基础服务。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 28 | 个人资料（`user_profile`） | 4级用户 / 账号与资料 | 头像、昵称、资料卡和动态隐私。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 29 | 签到与邀请（`sign_invite`） | 4级用户 / 账号与资料 | 签到、邀请码和邀请奖励。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 30 | 笔记与文档（`documents`） | 4级用户 / 内容功能 | 使用笔记、附件和授权文档能力。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 31 | 公告通知（`notices`） | 4级用户 / 内容功能 | 查看公告、维护和更新通知。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 32 | 资源中心（`resources`） | 4级用户 / 内容功能 | 浏览应用商店和源码商城。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 33 | 商品商店（`store`） | 4级用户 / 交易与活动 | 浏览商品、下单和查看订单。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 34 | 余额商店（`shop`） | 4级用户 / 交易与活动 | 使用余额购买虚拟商品。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 35 | 论坛社区（`forum`） | 4级用户 / 社区与社交 | 浏览板块、发帖、评论和互动。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 36 | 私聊消息（`messages`） | 4级用户 / 社区与社交 | 发送和接收好友私聊消息。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 37 | 群聊与聊天室（`chat_rooms`） | 4级用户 / 社区与社交 | 加入群聊、聊天室并参与交流。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 38 | 好友与动态（`social`） | 4级用户 / 社区与社交 | 好友、关注、粉丝和生活动态。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 39 | 在线客服（`service`） | 4级用户 / 消息与服务 | 与本应用客服进行会话。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 40 | 智能机器人（`bot`） | 4级用户 / 消息与服务 | 使用智能问答、天气和知识服务。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 41 | 通知中心（`notifications`） | 4级用户 / 消息与服务 | 接收系统、动态和业务通知。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 42 | 卡密兑换（`cards`） | 4级用户 / 交易与活动 | 兑换卡密和查看兑换结果。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 43 | 余额与支付（`payments`） | 4级用户 / 交易与活动 | 余额账单、转账和支付。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 44 | 提现服务（`withdrawals`） | 4级用户 / 交易与活动 | 提交提现并查看处理记录。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 45 | 红包（`red_packets`） | 4级用户 / 交易与活动 | 发送、领取和查看红包详情。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 46 | 抽奖（`lottery`） | 4级用户 / 交易与活动 | 参与抽奖并查看中奖记录。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 47 | 投票（`votes`） | 4级用户 / 交易与活动 | 发起或参与单选、多选投票。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 48 | 悬赏（`bounties`） | 4级用户 / 内容功能 | 发布、参与和管理悬赏。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 49 | 反馈与举报（`feedback`） | 4级用户 / 消息与服务 | 提交反馈、举报和申诉。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 50 | 远程文件（`remote_files`） | 4级用户 / 文件与扩展 | 上传、预览、搜索和下载文件。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 51 | 聊天扩展（`chat_extensions`） | 4级用户 / 文件与扩展 | 图片、视频、语音、文件、名片和定位。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 52 | 等级论坛（`level_forum`） | 4级用户 / 社区与社交 | 按用户等级开放对应社区内容。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 53 | 余额购买文档（`balance_document_purchase`） | 4级用户 / 交易与活动 | 允许使用余额购买授权文档。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 54 | 余额购买会员（`balance_membership_purchase`） | 4级用户 / 交易与活动 | 允许使用余额购买会员服务。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 55 | 分级活动（`hierarchical_activities`） | 4级用户 / 交易与活动 | 查看和领取上级定向发布的活动。 | `app/Services/RolePermissionService.php`、`tools/check-role-permissions.php` | 通过：权限定义、字段完整性和代码唯一性已自动断言 |
| 56 | 1级总控永久全权 | 1级 | 所有顶级权限固定生效，不受会员、余额、额度或下级规则限制。 | `RolePermissionService::ownerPayload()` | 通过：自动断言 unlimited=true 且全部生效 |
| 57 | 1级总控不可被反向限制 | 1级 | 总控权限只读展示，下级不能关闭或覆盖。 | `RolePermissionService::ownerPayload()` | 通过：自动断言 editable=false |
| 58 | 2级分支隔离 | 2级 | 只管理本授权平台下的3级、应用和4级用户。 | `RolePermissionActivity::managementScopeText()` | 通过：页面明确展示管理边界 |
| 59 | 2级禁止跨分支管理 | 2级 | 不能查看或修改其他2级授权平台分支。 | `RolePermissionActivity::managementScopeText()` | 通过：页面明确展示禁止跨分支 |
| 60 | 3级应用隔离 | 3级 | 只管理本人创建或被授权的应用及其用户。 | `RolePermissionActivity::managementScopeText()` | 通过：页面明确展示应用边界 |
| 61 | 3级禁止管理1/2级 | 3级 | 管理员不能反向管理平台总控或授权平台。 | `RolePermissionActivity::managementScopeText()` | 通过：页面明确展示层级边界 |
| 62 | 4级只读本人权限 | 4级 | 用户只能查看本人最终生效权限。 | `RolePermissionActivity::configureEndpoint()` | 通过：用户自身页面强制只读 |
| 63 | 上级强制优先 | 2/3/4级 | 上级锁定规则优先于下级自定义。 | `RolePermissionService.php`、`RolePermissionActivity.java` | 通过：锁定项目禁用开关 |
| 64 | 本级配置与最终结果分离 | 1/2/3/4级 | 分别展示 configured 和 effective，避免把配置误当结果。 | `RolePermissionActivity::addPermission()` | 通过：两行独立展示 |
| 65 | 授权来源可视化 | 1/2/3/4级 | 每项显示系统总控、1级、2级或所属上级来源。 | `RolePermissionService::item()` | 通过：自动断言来源字段 |
| 66 | 锁定原因可视化 | 2/3/4级 | 强制锁定时显示具体原因。 | `RolePermissionActivity::addPermission()` | 通过：locked 或 reason 非空即展示 |
| 67 | 可编辑状态可视化 | 1/2/3/4级 | 明确显示“可修改 / 上级强制 / 只读查看”。 | `RolePermissionActivity::addPermission()` | 通过：三态徽标已实现 |
| 68 | 1级自身权限接口 | 1级 | 读取总控永久权限。 | `GET /api/platform/permissions` | 通过：路由与控制器已自动检查 |
| 69 | 2级自身权限接口 | 2级 | 读取所属总控授予的最终权限。 | `GET /api/platform/permissions` | 通过：按 actor_level 返回2级权限 |
| 70 | 3级自身权限接口 | 3级 | 读取管理员最终权限。 | `GET /api/admin/permissions` | 通过：路由与控制器已自动检查 |
| 71 | 4级自身权限接口 | 4级 | 读取当前应用内用户最终权限。 | `GET /api/user/permissions` | 通过：路由已自动检查 |
| 72 | 1级查看2级权限 | 1→2级 | 总控查看授权平台权限。 | `GET /api/platform/operators/{operator_id}/permissions` | 通过：路由已自动检查 |
| 73 | 1级修改2级权限 | 1→2级 | 总控保存授权平台权限。 | `PUT /api/platform/operators/{operator_id}/permissions` | 通过：路由已自动检查 |
| 74 | 1/2级查看3级权限 | 1/2→3级 | 按分支查看管理员权限。 | `GET /api/platform/admins/{admin_id}/permissions` | 通过：路由已自动检查 |
| 75 | 1/2级修改3级权限 | 1/2→3级 | 按分支修改管理员权限。 | `PUT /api/platform/admins/{admin_id}/permissions` | 通过：路由已自动检查 |
| 76 | 1/2级查看4级权限 | 1/2→4级 | 按应用查看用户权限。 | `GET /api/platform/apps/{app_id}/users/{user_id}/permissions` | 通过：路由已自动检查 |
| 77 | 1/2级修改4级权限 | 1/2→4级 | 按应用修改用户权限。 | `PUT /api/platform/apps/{app_id}/users/{user_id}/permissions` | 通过：路由已自动检查 |
| 78 | 3级查看4级权限 | 3→4级 | 管理员查看自己应用下的用户权限。 | `GET /api/admin/apps/{app_id}/users/{user_id}/permissions` | 通过：路由已自动检查 |
| 79 | 3级修改4级权限 | 3→4级 | 管理员修改自己应用下的用户权限。 | `PUT /api/admin/apps/{app_id}/users/{user_id}/permissions` | 通过：路由已自动检查 |
| 80 | 权限链可视化 | 1/2/3/4级 | 页面固定展示1→2→3→4层级链和当前对象。 | `activity_role_permission.xml`、`roleChainText()` | 通过：布局和文案已实现 |
| 81 | 管理范围可视化 | 1/2/3/4级 | 页面按角色解释能管谁、不能管谁。 | `managementScopeText()` | 通过：四种角色均有专用文案 |
| 82 | 权限统计可视化 | 1/2/3/4级 | 显示启用、关闭和上级锁定数量。 | `RolePermissionActivity::render()` | 通过：summary 数据已渲染 |
| 83 | 权限分组展示 | 1/2/3/4级 | 权限按组织、数据、平台、运营、社区等分组。 | `RolePermissionService::payload()` | 通过：groups 数据驱动渲染 |
| 84 | 权限名称与说明搜索 | 1/2/3/4级 | 按权限标题、说明、代码、来源和锁定原因即时过滤。 | `RolePermissionActivity::matchesPermission()` | 通过：搜索框与过滤逻辑已接入自动断言 |
| 85 | 权限状态筛选 | 1/2/3/4级 | 支持全部、已开启、已关闭、上级锁定四种状态筛选。 | `activity_role_permission.xml`、`renderPermissionGroups()` | 通过：四个筛选项均由自动断言覆盖 |
| 86 | 筛选不丢编辑状态 | 2/3级管理下级 | 切换搜索或筛选后，未显示权限的待保存值仍被保留。 | `pendingPermissionValues` | 通过：保存独立遍历完整暂存映射 |
| 87 | 空筛选结果中文提示 | 1/2/3/4级 | 无匹配项时显示可理解的中文空状态。 | `RolePermissionActivity::addEmptyState()` | 通过：不显示原始数据或空白页面 |
| 88 | 自身权限快捷入口 | 1/2/3级 | 平台和管理员工作台可直接进入“我的权限”。 | `DashboardFragment.java` | 通过：快捷入口已接线 |
| 89 | 被管理对象权限入口 | 1/2/3级 | 从授权平台、管理员和用户详情进入对应权限页。 | `GenericModuleFragment.java`、`ManagedUserDetailActivity.java` | 通过：目标模式入口已接线 |
| 90 | 账号名与备注不翻译 | 1/2/3/4级 | 动态名称和账号使用动态文本接口，不经过界面词典。 | `RuntimeLanguage.setDynamicText()` | 通过：权限对象名称与账号均按原文显示 |
| 91 | 权限页中文可视化 | 1/2/3/4级 | 用户看到标题、解释、状态和错误信息，不显示原始JSON。 | `RolePermissionActivity.java` | 通过：页面仅消费结构化字段并渲染中文控件 |
| 92 | 未知权限代码拒绝 | 2/3/4级 | 后端拒绝未定义权限，避免注入任意代码。 | `normalizePlatformInput()`、`normalizeAdminInput()`、`normalizeUserInput()` | 通过：异常路径已自动断言 |
| 93 | 空权限提交拒绝 | 2/3/4级 | 后端拒绝空权限对象，避免误清空。 | `RolePermissionService::normalizeInput()` | 通过：异常路径已自动断言 |
| 94 | 请求生命周期保护 | 1/2/3/4级 | 页面销毁时取消请求，回调先检查Activity状态。 | `RolePermissionActivity::onDestroy()` | 通过：静态实现已核验 |
| 95 | API文档覆盖权限接口 | 1/2/3/4级 | 权限接口方法、路径和层级写入完整API文档。 | `docs/API_FULL.md` | 通过：11条权限接口均已登记 |
| 96 | 权限测试接入总检查 | 后端 | 总检查脚本必须执行权限断言，失败即中止。 | `tools/check.ps1` | 通过：已纳入必跑清单 |

| 97 | 拼手气红包与资金退回闭环 | 1/2/3/4级 / 资产与互动 | 红包金额最多两位小数、每份最低0.01、随机拆分总和准确、唯一运气王；发送人可抢自己发的红包；转账和礼物只能由接收方退回给原发送方。 | `app/Services/RedPacketAmountService.php`、`app/Controllers/User/CommerceController.php`、`tools/test-red-packet-amount.php`、`tools/check-commerce-refund-policy.php` | 通过：定点金额、随机拆分与退款权限均已自动断言 |

## 自动测试

- 权限定义：55项。
- 本表核验项：97项。
- 权限自动断言：运行 `php tools/check-role-permissions.php`。
- 全量后端检查：运行 `powershell -ExecutionPolicy Bypass -File tools/check.ps1`。
- 四端构建：运行 `gradlew.bat assemblePlatformOwnerDebug assembleAuthorizedPlatformDebug assembleAdminDebug assembleUserDebug`。

## 仍需真机复验

- 在1/2/3/4四种真实登录账号下确认颜色、字体、长文本换行、开关禁用态和返回路径。
- 使用跨分支、跨应用账号发起越权请求，确认服务端返回中文拒绝信息且不会泄露目标数据。
- 确认上级强制关闭后，下级页面立即显示“上级强制”和锁定原因，且保存按钮不包含锁定项。
