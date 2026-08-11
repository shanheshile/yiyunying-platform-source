# 易运盈后台四级平台治理规范

本文档说明易运盈后台的最高平台、授权平台、admin、user 四级关系，以及会员、额度、强制配置、购买和反馈的实际执行规则。

## 1. 四级身份

| 等级 | 代码身份 | 含义 | 管理范围 |
| --- | --- | --- | --- |
| 1 | `platform level=1` | 易运盈最高平台所有者 | 自己、全部直属 2 级、整棵树中的 3/4 级和全部应用数据 |
| 2 | `platform level=2` | 1 级授权的独立运营平台 | 自己直属的 3 级、这些 3 级的应用和 4 级用户 |
| 3 | `admin` | 应用后台管理员 | 自己创建的应用及应用内全部业务 |
| 4 | `user` | 某个应用的最终用户 | 只能使用自己所属应用开放的功能 |

实际归属链：

```text
1 级平台
|- 直属 3 级 admin
|  `- app -> 4 级 user 与业务数据
`- 多个相互独立的 2 级平台
   `- 各自的 3 级 admin
      `- app -> 4 级 user 与业务数据
```

1 级可以查看和管理整棵树。2 级之间互不可见，2 级不能管理 1 级，也不能访问其他 2 级的数据。3 级之间按 `admin_id` 隔离；同一 3 级的不同应用继续按 `app_id` 隔离。

## 2. 首次安装身份与注册归属

全新安装不提供默认账号、默认密码、固定平台 KEY、固定 APP KEY 或公开 app_secret。
部署者可以在导入 `database/install.sql` 前，于同一个数据库会话中显式注入各层身份哈希；
未完整注入的层级只创建随机不可认证且 `status=0` 的占位数据，其下级也不会启用。

```text
1 级平台：显式 PLATFORM KEY + 账号 + PHP password_hash
2 级授权平台（可选）：显式 PLATFORM KEY + 账号 + PHP password_hash
3 级管理员（可选）：显式账号 + PHP password_hash
应用（可选）：显式 APP KEY + 随机 app_secret 的 SHA-256
4 级用户（可选）：显式 UID + 账号 + PHP password_hash
```

完整变量名、同会话 `SOURCE` 步骤和安全验收命令见 `deploy/DEPLOY.md`。测试身份只能注入一次性本地数据库，
不得复制到生产环境或提交到版本库。

3 级公开注册接口为 `POST /api/admin/register`：

- 不传 `platform_key`：默认注册为 1 级平台直属的 3 级 admin。
- 传 1 级的 `platform_key`：注册为该 1 级直属 admin。
- 传 2 级的 `platform_key`：注册为该 2 级直属 admin。
- 平台不存在、停用、过期或关闭注册时，注册失败。
- 2 级也可通过平台接口直接创建自己名下的 admin。

每个平台独立控制：

| 配置键 | 默认值 | 作用 |
| --- | --- | --- |
| `admin_registration_enabled` | `true` | 是否允许注册 3 级 admin |
| `admin_login_enabled` | `true` | 是否允许 3 级 admin 登录 |
| `downstream_user_enabled` | `true` | 是否允许所有下游 4 级继续使用 |
| `admin_daily_register_limit` | `100` | 平台每天成功注册上限，`0` 表示不限制 |
| `admin_ip_daily_register_limit` | `3` | 同一 IP 每日成功注册上限 |
| `admin_ip_total_register_limit` | `10` | 同一 IP 历史成功注册上限 |
| `admin_account_min_length` | `3` | admin 账号最短长度 |
| `admin_account_max_length` | `32` | admin 账号最长长度 |

注册成功默认赠送：

| 权益 | 默认值 |
| --- | --- |
| 会员 | 3 天试用 |
| 应用名额 | 1 个 |
| 远程文档名额 | 3 个 |
| admin 平台余额 | 15 |

赠送值由所属 1/2 级平台分别配置。注册日志保存账号、IP、User-Agent、成功与否、失败原因和赠送快照，可按 IP 统计每天与历史注册数量。

## 3. 会员、时段与连带停用

每个 3 级 admin 都有一条 `admin_entitlements` 权益记录：

- `membership_level`：试用、VIP、SVIP 或平台自定义等级。
- `membership_status`：`active`、`frozen`、`expired`。
- `membership_expired_at`：会员到期时间。
- `app_quota`：可创建的应用总数。
- `remote_document_quota`：可创建的远程文件总数，不含文件夹。
- `integral`：admin 在平台侧的余额。
- `access_start_time/access_end_time`：每天允许使用的时段，可跨午夜。
- `allowed_weekdays`：允许使用的星期，取值为 `1-7`。

访问状态有三种：

| 状态 | 触发条件 | 结果 |
| --- | --- | --- |
| `full` | 平台、admin、会员、时段全部正常 | 可使用全部已授权功能，4 级正常使用 |
| `billing_only` | admin 会员到期、冻结或不在允许时段 | admin 只可看权益、提交购买、提交反馈和退出；4 级全部阻断 |
| `blocked` | 1/2 级平台停用/删除/到期，或 admin 被封禁 | admin 和全部下游立即阻断 |

这条限制在统一鉴权服务中执行，公开启动配置、用户注册登录、已登录 user、公开文档分享等入口都会检查，不依赖前端自觉隐藏按钮。

## 4. 应用与远程文档额度

- 3 级创建应用前，系统统计未删除应用数并检查 `app_quota`。
- 创建达到上限后返回业务错误，购买或上级赠送名额后可继续创建。
- 创建远程文件前检查 `remote_document_quota`；文件夹不占远程文档名额。
- 1/2 级可直接设定绝对额度，也可按增量加减。
- 每次权益修改都写入变更前、变更内容、变更后和操作平台。

## 5. 聊天轮询强制规则

应用配置键为 `chat_poll_interval_ms`，单位毫秒。默认 `5000`，`1000` 表示每秒请求一次。

每个 1/2 级平台都有：

| 配置键 | 默认值 | 作用 |
| --- | --- | --- |
| `default_chat_poll_interval_ms` | `5000` | 平台默认轮询间隔 |
| `force_chat_poll_interval` | `false` | 是否强制所有下属 3 级使用平台默认值 |
| `min_chat_poll_interval_ms` | `1000` | 3 级可选的最小间隔 |
| `max_chat_poll_interval_ms` | `60000` | 3 级可选的最大间隔 |

实际优先级固定为：

```text
1 级强制值 > 2 级强制值 > 3 级应用配置值
```

- 1/2 级都未开启强制：3 级可在有效范围内改为 `1000`、`5000` 等值。
- 2 级开启强制：该 2 级下所有 3 级不能修改，实际使用 2 级默认值。
- 1 级开启强制：直属 3 级和全部 2 级分支都使用 1 级默认值；1 级强制覆盖 2 级强制。
- 1 级关闭强制后，原有 2 级强制自动恢复生效。
- 2 级允许范围不能突破 1 级设置的最小/最大范围。

管理员配置接口同时返回：

```json
{
  "configured_settings": {"chat_poll_interval_ms": 1000},
  "settings": {"chat_poll_interval_ms": 2000},
  "chat_polling_policy": {
    "configured_interval_ms": 1000,
    "effective_interval_ms": 2000,
    "locked": true,
    "can_admin_modify": false,
    "forced_by_level": 2,
    "forced_by_platform_id": 10
  }
}
```

`configured_settings` 是 3 级原先保存的值，`settings` 是当前真正生效的值。公开 `/api/public/bootstrap` 返回实际值和锁定来源，客户端只需按 `settings.chat_poll_interval_ms` 运行。

## 6. 平台余额自动兑换

`admin_entitlements.integral` 是 1/2 级平台赠送给 3 级 admin 的平台余额，与应用内 `user_wallets.integral` 完全隔离。user 余额不能购买后台权益，admin 平台余额也不能进入某个 App 的用户钱包。

每个 1/2 级都有独立余额商品目录，2 级创建时自动生成默认商品：

| 商品 | 默认价格 | 自动发放 |
| --- | --- | --- |
| 1 个远程文档名额 | 5 平台余额 | `remote_document_quota + 1` |
| 1 天 VIP | 10 平台余额 | 会员到期时间延长 1 天 |
| 1 个 App 名额 | 50 平台余额 | `app_quota + 1` |
| 成长组合包 | 100 平台余额 | 30 天 VIP、1 个 App、10 个远程文档 |

平台可自行新增、编辑、上架、下架和删除商品，并设置：

- 单价、剩余库存；`stock=null` 表示不限库存，`stock=0` 表示售罄。
- 每个 admin 终身限购数量。
- 每个 admin 每日限购数量。
- 上架开始和结束时间。
- 单笔最大兑换数量。
- 每个 admin 每日最多消耗的平台余额。
- `balance_exchange_enabled` 余额兑换总开关。

兑换链路：

```text
admin 请求报价
-> 校验所属平台和商品可用性
-> 校验平台总开关、会员冻结、数量、库存、限购和余额
-> 锁定 admin_entitlements 与商品
-> 再次校验实时余额和库存
-> 扣除平台余额
-> 增加会员/App/远程文档权益
-> 扣减库存
-> 写兑换订单、余额流水、权益日志和平台统计
-> 同一事务提交
```

客户端应通过 `Idempotency-Key` 请求头或 `idempotency_key` 参数提供 8-100 位幂等键。同一 admin 使用同一幂等键和相同商品、数量重试时只返回原订单，不再次扣分；用同一键提交不同商品或数量会被拒绝。

会员正常到期后可在 `billing_only` 模式兑换 VIP，成功后立即恢复后台和下游 user。`membership_status=frozen` 属于上级冻结，不能通过自助兑换解除；平台或 admin 被封禁时也不能兑换。

平台可以退款，但退款必须能完整回收权益：

- App 名额已被实际 App 占用时拒绝退款，先删除超额 App 才能退款。
- 远程文档名额已被文件占用时拒绝退款，先删除超额文件才能退款。
- 会员权益在兑换后被人为缩短，无法准确回滚时拒绝自动退款。
- 成功退款会原子恢复余额和有限库存，订单改为 `refunded`，已退款订单不能重复退款。
- 退款订单不再占用终身/每日限购，也不计入净兑换收入。

余额流水覆盖注册赠送、平台增减、自动兑换扣款和退款返还。每条流水保存变动前、变动值、变动后、场景、关联订单和操作平台。

## 7. 购买与人工发放

3 级可向直属 1/2 级提交购买申请：

- `vip_days`：会员天数。
- `app_quota`：应用名额。
- `remote_document_quota`：远程文档名额。
- `integral`：平台余额。
- `custom`：由平台人工指定组合权益。

订单状态：

```text
pending -> fulfilled
pending -> rejected
```

发放时会锁定订单，只有 `pending` 可处理。权益修改、权益日志、订单完成和每日统计在同一事务中执行，避免重复发放。

## 8. admin 向平台反馈

3 级可通过 `/api/admin/platform-feedbacks` 向自己的直属平台反馈：

- 直属 1 级的 admin，反馈进入 1 级。
- 直属 2 级的 admin，反馈进入对应 2 级。
- 1 级仍可在全局范围查看和处理全部分支反馈。
- 其他 2 级无法看到该反馈。
- 反馈类型支持 `feedback`、`bug`、`feature`、`billing`、`policy`。
- 会员到期或不在使用时段时仍可提交反馈；封禁或平台停用后不能提交。

状态支持 `pending`、`replied`、`closed`，保存回复平台、回复内容和回复时间。

## 9. 平台接口

平台接口统一使用：

```http
Authorization: Bearer <platform_token>
Content-Type: application/json
```

| 方法 | 路径 | 功能 |
| --- | --- | --- |
| POST | `/api/platform/login` | 1/2 级平台登录 |
| GET | `/api/platform/me` | 当前平台、设置和轮询策略 |
| GET | `/api/platform/dashboard` | 分级数据面板和 30 日统计 |
| GET/PUT | `/api/platform/settings` | 查看/修改自己的注册、赠送和强制规则 |
| GET | `/api/platform/ip-statistics` | 按 IP 汇总 admin 注册 |
| GET | `/api/platform/admin-registration-logs` | admin 注册日志 |
| GET | `/api/platform/admin-login-logs` | admin 登录日志 |
| GET | `/api/platform/operation-logs` | 平台操作审计 |
| GET/POST | `/api/platform/operators` | 1 级查询/创建 2 级 |
| GET/PUT/DELETE | `/api/platform/operators/{operator_id}` | 1 级查看、修改、连带删除 2 级 |
| POST | `/api/platform/operators/{operator_id}/ban` | 封禁 2 级并撤销全部下游令牌 |
| POST | `/api/platform/operators/{operator_id}/unban` | 解封 2 级 |
| GET/PUT | `/api/platform/operators/{operator_id}/settings` | 1 级设置指定 2 级规则 |
| GET/POST | `/api/platform/admins` | 按管理范围查询/创建 3 级 |
| GET/PUT/DELETE | `/api/platform/admins/{admin_id}` | 查看、修改、连带删除 3 级 |
| PUT | `/api/platform/admins/{admin_id}/entitlement` | 调整会员、时段、名额和余额 |
| GET/PUT | `/api/platform/admins/{admin_id}/permissions` | 查看/调整 3 级模块权限 |
| POST | `/api/platform/admins/{admin_id}/impersonate` | 签发有审计记录的代管令牌 |
| GET | `/api/platform/apps` | 范围内全部应用 |
| GET/PUT/DELETE | `/api/platform/apps/{app_id}` | 查看、修改、连带删除应用 |
| PUT | `/api/platform/apps/{app_id}/settings` | 平台直接修改应用配置 |
| GET | `/api/platform/purchase-orders` | 购买申请列表 |
| POST | `/api/platform/purchase-orders/{order_id}/fulfill` | 发放购买权益 |
| POST | `/api/platform/purchase-orders/{order_id}/reject` | 拒绝购买申请 |
| GET | `/api/platform/admin-feedbacks` | admin 反馈列表 |
| POST | `/api/platform/admin-feedbacks/{feedback_id}/reply` | 回复或关闭反馈 |
| GET/POST | `/api/platform/exchange-products` | 查询/创建平台余额商品 |
| GET/PUT/DELETE | `/api/platform/exchange-products/{product_id}` | 查看、修改或删除余额商品 |
| POST | `/api/platform/exchange-products/{product_id}/enable` | 上架余额商品 |
| POST | `/api/platform/exchange-products/{product_id}/disable` | 下架余额商品 |
| GET | `/api/platform/exchanges` | 管理范围内的自动兑换订单 |
| GET | `/api/platform/exchanges/{exchange_id}` | 自动兑换订单与权益快照 |
| POST | `/api/platform/exchanges/{exchange_id}/refund` | 受资源占用约束的原子退款 |
| GET | `/api/platform/balance-logs` | 管理范围内的 admin 平台余额流水 |

## 10. admin 新增接口

| 方法 | 路径 | 功能 |
| --- | --- | --- |
| POST | `/api/admin/register` | 注册 3 级；不传 `platform_key` 时直属 1 级 |
| GET | `/api/admin/entitlement` | 查看会员、使用状态、额度和已使用量 |
| GET/POST | `/api/admin/purchase-orders` | 查询/提交购买申请 |
| GET/POST | `/api/admin/platform-feedbacks` | 查询/提交给直属平台的反馈 |
| GET | `/api/admin/exchange-products` | 可兑换商品、余额和单件报价 |
| GET | `/api/admin/exchange-products/{product_id}` | 商品详情和实时报价 |
| POST | `/api/admin/exchanges/quote` | 按商品和数量计算报价及失败原因 |
| GET/POST | `/api/admin/exchanges` | 查询订单/自动兑换权益 |
| GET | `/api/admin/exchanges/{exchange_id}` | 自己的兑换订单详情 |
| GET | `/api/admin/balance-logs` | 自己的平台余额流水 |

完整 479 条注册路由见 [ROUTES.md](ROUTES.md)，146 张表的字段、索引与外键见 [SCHEMA.md](SCHEMA.md)。

## 11. 验证

```powershell
powershell -ExecutionPolicy Bypass -File tools/smoke-platform.ps1 -BaseUrl http://127.0.0.1:8788
```

专项烟测会真实验证：1/2/3/4 级归属、注册赠送、应用额度、每秒一次、1/2 级强制优先级、反馈回复、购买发放、封禁连带阻断和级联删除。

```powershell
powershell -ExecutionPolicy Bypass -File tools/smoke-exchange.ps1 -BaseUrl http://127.0.0.1:8788
```

自动兑换最大闭环烟测覆盖报价、余额不足、原子扣款与发货、幂等重放、App/文档/VIP 实际使用、到期恢复、冻结不可自解、已占用权益退款失败、释放资源后退款成功、库存、限购、总开关、每日余额限制、余额流水和 1/2 级范围审计。

`tools/smoke-exchange-concurrency.ps1` 会启动两个独立 PHP 进程并发访问同一数据库：单库存商品必须恰好一个成功；同一幂等键必须两个调用都得到同一订单且只执行一次扣款。
