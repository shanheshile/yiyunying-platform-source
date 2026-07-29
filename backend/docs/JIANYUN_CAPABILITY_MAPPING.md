# 简云能力学习与易运盈原生映射

## 1. 结论

本次按简云 API 文档的 APP、用户、卡密、商城、论坛、笔记、通知、聊天与好友分组逐项研究。易运盈后台吸收的是业务能力和交互闭环，不复制旧路径、不增加 `/jianyun/*` 兼容层，也不沿用“所有操作都 POST”的设计。

易运盈继续使用统一 REST 规则：

- `GET` 查询；`POST` 创建或执行动作；`PUT` 修改；`DELETE` 删除。
- 公开接口使用 `X-App-Key` 或 `app_key` 确定应用。
- user 接口同时校验 Bearer Token 与令牌内的 `app_id`，不能跨应用搜索或读取数据。
- admin 只能操作自己拥有的 `{app_id}`；2 级只能管理自己分支；1 级拥有全树监管权。
- API 始终返回 `{code,msg,data,trace_id}`，面向客户端的提示与枚举名称使用中文。

完整的 599 条注册路由见 [ROUTES.md](ROUTES.md)，177 张表见 [SCHEMA.md](SCHEMA.md)。论坛付费分节、唯一浏览与热度排序见 [FORUM_EXPERIENCE.md](FORUM_EXPERIENCE.md)。

## 2. 分组映射

| 简云能力组 | 易运盈原生实现 | 本次补强 |
| --- | --- | --- |
| APP 信息、更新、访问、统计 | `/api/public/app`、`/bootstrap`、`/version`、`/app/visit`、`/app/statistics` | 新增访问事件、今日/累计访问量、独立访客、在线人数与公开统计开关 |
| 用户注册登录与资料 | `/api/user/register`、`/login`、`/logout`、`/me`、`/profile`、`/password` | 注册字段按应用动态显示；UID 与账号分离；邮箱/手机唯一绑定和独立解绑审核 |
| 验证、找回、签到、邀请、排行 | `/api/public/captcha`、`/verification-code/email`、用户找回密码、签到、邀请和排行接口 | 保留验证码用途和频率控制，不开放任意收件人的邮件转发器 |
| 用户搜索、关注与粉丝 | `/api/user/users/search`、`/profiles/{user_id}`、关注状态、关注/粉丝列表 | 新增应用内用户搜索，返回资料可见范围、好友、关注、黑名单和申请关系 |
| 账单与提现 | `/api/user/wallet/logs`、`/withdrawals` | 统一使用“余额”中文概念，所有变化进入资产流水，提现冻结与取消退款保持原子性 |
| 直充卡与登录卡 | `/api/user/cards/redeem`、`/api/public/card-login`、`/card-auto-login` | 新增首次设备绑定、一次性设备密钥、自动登录、绑定审计和重复绑定拒绝 |
| 商品、购买与订单 | 商品列表、商品详情、购买、我的订单、支付回调 | 用户不能手写订单；购买事务自动创建订单、扣款、交付和账单 |
| 论坛完整流程 | 板块、帖子、评论、点赞、收藏、打赏、付费内容、举报、历史和关注流 | 新增帖子/评论审核、点赞名单、我的/已购/关注/点赞视图、举报标签和举报进度 |
| 笔记 | `/api/user/notes` 及详情 CRUD | user 端文档统一称“笔记”；与 admin 的可编程远程文档严格分开 |
| 通知中心 | `/api/user/notifications`、未读数、单条/分类/全部已读 | 点赞、评论、社交、订单、余额、活动、内容、系统分组折叠；聊天不混入通知中心 |
| 私聊和会话 | 会话列表、消息记录、发送、撤回、本地删除、搜索、收藏和已读回执 | 客服与机器人可作为会话入口；本地删除不等同撤回；撤回受分级规则控制 |
| 群聊治理 | 建群、资料、成员、邀请、申请、群主、管理员、禁言、消息和群列表 | 新增限时恢复已解散群；群文件、相册、投票、接龙保持同一群上下文 |
| 好友体系 | 好友申请、处理、列表、备注、分组、黑名单、二维码 | 新增按 UID/账号/昵称搜索，并可从搜索结果直接查看资料或申请好友 |
| 收藏、媒体和云同步 | 统一收藏中心、媒体缓存、表情包、聊天快照与清理 | 收藏不再返回原始 JSON；支持分类、预览、缓存、范围清理和受权益控制的跨设备恢复 |

## 3. 本次新增的关键接口

### 3.1 APP 与在线状态

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| POST | `/api/public/app/visit` | 记录访问来源、页面和匿名访客标识；同日访客去重 |
| GET | `/api/public/app/statistics` | 返回访问量、独立访客、注册用户和当前在线人数 |
| POST | `/api/user/heartbeat` | 更新登录用户在线状态，在线窗口由应用设置控制 |
| GET | `/api/user/wallet/logs` | 查询中文资产流水和来源，不直接暴露数据库字段给用户 |

### 3.2 搜索与关系

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/user/users/search` | 按 UID、账号或昵称搜索；必传 `keyword`，支持 `page,limit` |
| GET | `/api/user/profiles/{user_id}/follow-status` | 查询关注、被关注、互相关注和黑名单状态 |
| GET | `/api/user/profiles/{user_id}` | 根据隐私设置返回完整资料或基础资料 |

搜索结果仅来自令牌所属的 `app_id`，同时返回 `relation_name`、`profile_visibility_name`、`can_interact`、`to_uid`。客户端因此可以用中文展示关系，并直接联动好友申请，不需要用户填写数据库内部 ID。

### 3.3 登录卡

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| POST | `/api/public/card-login` | 登录卡首次绑定设备并创建/登录对应用户 |
| POST | `/api/public/card-auto-login` | 使用设备标识和设备密钥自动登录 |
| GET | `/api/admin/apps/{app_id}/card-login-bindings` | admin 查询登录卡绑定、用户、设备和最后使用时间 |

登录卡密钥只在首次绑定时明文返回一次，数据库只保存哈希；换设备重复绑定和错误密钥都会拒绝。

### 3.4 论坛审核与举报

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| PUT | `/api/admin/apps/{app_id}/forum-posts/{post_id}/audit` | 审核帖子并记录审核人、原因和时间 |
| GET | `/api/admin/apps/{app_id}/forum-comments` | 按状态查询评论，包括待审核队列 |
| PUT | `/api/admin/apps/{app_id}/forum-comments/{comment_id}/audit` | 通过或拒绝评论 |
| DELETE | `/api/admin/apps/{app_id}/forum-comments/{comment_id}` | 删除违规评论 |
| GET | `/api/admin/apps/{app_id}/forum-report-tags` | 查询举报标签 |
| POST | `/api/admin/apps/{app_id}/forum-report-tags` | 新增举报标签 |
| PUT | `/api/admin/apps/{app_id}/forum-report-tags/{tag_id}` | 修改举报标签 |
| DELETE | `/api/admin/apps/{app_id}/forum-report-tags/{tag_id}` | 删除未被引用的举报标签 |
| GET | `/api/user/forum-report-tags` | user 获取可选举报原因 |
| GET | `/api/user/forum-reports` | user 查看自己的举报和处理进度 |
| GET | `/api/user/forum-posts/{post_id}/likes` | 查看帖子点赞用户列表 |

每个新应用自动建立“垃圾广告、违法违规、人身攻击、其他问题”四个中文举报标签。

### 3.5 可恢复群聊

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/user/chat-rooms/dissolved` | 群主查看仍在恢复期限内的已解散群 |
| POST | `/api/user/chat-rooms/{room_id}/restore` | 在规则允许的期限内恢复群和成员状态 |

## 4. 数据库落点

| 表或字段 | 用途 |
| --- | --- |
| `app_visit_events` | 访问事件、来源、页面和匿名访客哈希 |
| `user_presence` | 心跳、设备、最后在线时间和在线判定 |
| `card_login_bindings` | 登录卡、用户、设备密钥哈希与审计时间 |
| `forum_report_tags` | 每应用独立的举报标签 |
| `statistics_daily.app_views/unique_visitors/heartbeat_count` | 每日聚合统计 |
| `forum_posts.audit_*`、`forum_comments.audit_*` | 帖子和评论审核链路 |
| `forum_reports.report_tag_id` | 举报与标签关联 |
| `chat_rooms.dissolved_at/restore_until` | 群解散和可恢复期限 |

## 5. 没有照搬的设计

1. **不提供任意“发送邮件”接口。** 这会形成垃圾邮件和开放中继风险；易运盈只允许验证码、找回密码和经过模板/权限控制的系统邮件。
2. **不把取消点赞、取消收藏拆成重复业务。** 易运盈的切换接口返回明确状态；需要严格删除语义的场景使用 `DELETE`。
3. **不让 user 创建订单。** 订单只能由购买、充值或受控支付流程生成。
4. **不把聊天消息塞进通知中心。** 消息列表只放私聊、群聊、客服和机器人；点赞等业务提醒进入可折叠通知分组。
5. **不混淆笔记与后台文档。** user 笔记用于个人内容；admin/授权平台文档用于远程配置和自主业务能力。
6. **不开放跨应用搜索。** UID 可以全局唯一，但可见性和所有业务关系始终按 `app_id` 隔离。

## 6. 验证

`tools/smoke-jianyun-capabilities.ps1` 会创建临时应用并自动清理，覆盖：

- 访问量与独立访客去重；
- 当前应用用户搜索及关系联动；
- 关注状态；
- 帖子与评论审核；
- 点赞名单、用户论坛视图、举报标签和处理进度；
- 群解散与恢复；
- 登录卡首次绑定、另一设备拒绝、错误密钥拒绝和正确自动登录；
- 心跳、在线统计和统一资产账单。

专项脚本、全量检查和四端构建结果以交付包内测试报告为准。
