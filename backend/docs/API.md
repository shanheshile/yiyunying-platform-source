# 易运盈后台 API 快速指南

基础地址：`http://appht.jjmxg.xyz`

本文件用于快速接入。完整角色与参数见 [API_FULL.md](API_FULL.md)，四级规则见 [PLATFORM_GOVERNANCE.md](PLATFORM_GOVERNANCE.md)，599 条实际路由见 [ROUTES.md](ROUTES.md)，177 张表的精确结构见 [SCHEMA.md](SCHEMA.md)，简云能力取长补短的原生映射见 [JIANYUN_CAPABILITY_MAPPING.md](JIANYUN_CAPABILITY_MAPPING.md)，论坛付费分节与热度规则见 [FORUM_EXPERIENCE.md](FORUM_EXPERIENCE.md)。浏览器可直接打开 `/api-docs.html` 查看并检索全部接口。

## 身份与归属

### 1/2 级平台

```http
Authorization: Bearer <platform_token>
Content-Type: application/json
```

1 级 `root/123456` 管理自己的完整数据树。2 级由 1 级授权，只能管理自己的 3 级 admin 及其应用和 user。

### 管理员

```http
Authorization: Bearer <admin_token>
Content-Type: application/json
```

管理员只能操作自己创建的应用。所有带 `{app_id}` 的路由都会再次检查应用归属。
管理员注册不传 `platform_key` 时直属 1 级；传入某个 2 级的 `platform_key` 时直属该 2 级。

### 用户

```http
Authorization: Bearer <user_token>
X-App-Key: <app_key>
Content-Type: application/json
```

用户令牌固定绑定 `admin_id + app_id + user_id`。`X-App-Key` 与令牌不一致时返回 `403`。

### 公开接口

公开接口不要求登录，但必须通过查询参数 `app_key` 或 `X-App-Key` 指明应用。文档分享通过全局唯一 `share_code` 读取。

### 固定分享码

每篇用户文档最多只有一个固定分享码。首次调用
`POST /api/user/notes/{document_id}/share` 时生成；后续重复调用、停用后重新启用，均返回原来的 `share_code`。

```json
{
  "code": 1,
  "msg": "固定分享码创建成功",
  "data": {
    "share": {
      "id": 18,
      "share_code": "D-SZAHiCwapVZ98x",
      "share_url": "http://appht.jjmxg.xyz/api/public/note-shares/D-SZAHiCwapVZ98x",
      "expired_at": null,
      "password_required": false,
      "status": 1,
      "reused": false
    }
  }
}
```

- `GET /api/user/notes/{document_id}/share`：查询固定码、访问量和启用状态。
- `DELETE /api/user/note-shares/{share_id}`：只停用访问，不销毁分享码。
- `GET /api/public/note-shares/{share_code}`：外部读取；设置密码时传 `password`。
- Android 用户端会识别“分享码：xxx”或完整分享链接，并在“我的笔记”页提示打开。

## 统一响应

```json
{
  "code": 1,
  "msg": "操作成功",
  "data": {},
  "trace_id": "20260712120000-1a2b3c4d5e6f"
}
```

HTTP 状态与业务语义一致。客户端应同时判断 HTTP 状态和 `code`，并记录 `trace_id` 便于后台定位请求。

## 首次调用

### 0. 平台登录

```http
POST /api/platform/login
```

```json
{"account":"root","password":"123456"}
```

### 1. 管理员登录

```http
POST /api/admin/login
```

```json
{
  "account": "admin",
  "password": "123456",
  "device": "admin-web"
}
```

### 2. 创建应用

```http
POST /api/admin/apps
Authorization: Bearer <admin_token>
```

```json
{
  "name": "我的应用",
  "description": "独立的用户和业务空间",
  "logo": "https://example.com/logo.png"
}
```

响应返回 `app.id`、公开 `app_key` 和只显示一次的 `app_secret`。

### 3. 配置应用

```http
PUT /api/admin/apps/{app_id}/settings
```

```json
{
  "settings": {
    "registration_enabled": true,
    "login_enabled": true,
    "initial_document_credit": 20,
    "document_create_cost": 1,
    "wallet_transfer_enabled": true,
    "wallet_transfer_max": 10000
  }
}
```

功能模块开关使用 `PUT /api/admin/apps/{app_id}/features`，配置值与模块是否启用相互独立。

聊天轮询使用 `chat_poll_interval_ms`。1/2 级未强制时，3 级可设为 `1000`；开启 `force_chat_poll_interval` 后，以 `1级强制 > 2级强制 > 3级配置` 计算实际值。客户端以公开启动接口返回的实际值为准。

### 4. 用户注册与登录

```http
POST /api/user/register
```

```json
{
  "app_key": "yy_xxxxxxxxxxxxxxxxxxxx",
  "account": "user001",
  "password": "123456",
  "nickname": "用户001"
}
```

注册和登录都会返回 `access_token` 与 `refresh_token`。访问令牌过期后调用：

```http
POST /api/user/token/refresh
X-App-Key: <app_key>
```

```json
{
  "refresh_token": "<refresh_token>"
}
```

旧访问令牌和旧刷新令牌会同时撤销。

## admin 平台余额自动兑换

平台余额与 App 内 user 余额相互独立。admin 先查询所属平台商品：

```http
GET /api/admin/exchange-products
Authorization: Bearer <admin_token>
```

报价不会扣款：

```http
POST /api/admin/exchanges/quote
```

```json
{"product_id":1,"quantity":2}
```

执行兑换时必须尽量复用同一个幂等键：

```http
POST /api/admin/exchanges
Idempotency-Key: order-from-client-0001
```

```json
{"product_id":1,"quantity":2}
```

系统在同一事务中完成余额扣减、会员/App/远程文档权益发放、库存扣减、订单和双流水。相同幂等键重试只返回原结果。平台通过 `POST /api/platform/exchanges/{exchange_id}/refund` 退款；已被实际资源占用的名额不能直接退款。

## 关键事务

### 卡密兑换

管理员创建批次：

```json
{
  "name": "会员卡",
  "total_count": 10,
  "max_use": 1,
  "value_json": {
    "integral": 100,
    "experience": 50,
    "balance": 5.5,
    "document_credit": 20,
    "vip_days": 30
  }
}
```

用户调用 `POST /api/user/cards/redeem`。卡密次数、兑换日志、资产余额和资产流水在同一事务中提交。

### 支付回调

支付渠道的 `config_json.secret` 用于 HMAC-SHA256。回调参数移除 `sign` 后按键名字典序排列，以 `key=value&key=value` 拼接，再计算：

```text
sign = HMAC_SHA256(canonical_string, secret)
```

回调必须包含：

```json
{
  "app_key": "yy_xxx",
  "order_no": "YY20260712000000ABCDEF",
  "trade_no": "PAY-123456",
  "amount": "5.25",
  "status": "paid",
  "timestamp": 1783792000,
  "sign": "..."
}
```

`timestamp` 有效期为 15 分钟。回调会校验应用、渠道、签名、订单状态和金额；同一已支付订单重复回调返回成功并标记 `idempotent=true`，不会重复发货或入账。

## 消息列表与通知中心

`GET /api/user/message-center` 只返回聊天会话，`items[].type` 仅可能是：

- `private`：好友、陌生人或自己的私聊
- `group`：群聊
- `service`：在线客服，未创建会话时也保留固定入口
- `bot`：机器人问答固定入口

点赞、评论、关注、好友申请、订单、余额、活动、内容审核、公告和更新不会进入聊天列表。聊天未读数位于 `unread_count`；非聊天通知未读数独立位于 `notification_unread_count`。

`GET /api/user/notifications` 是统一通知中心，同时合并业务通知与无会话的系统公告。返回的 `groups` 按以下中文分类汇总：点赞与喜欢、评论与回复、关注与好友、订单与购买、余额与权益、活动与悬赏、内容与文件、系统与公告、其他通知。

```http
GET /api/user/notifications?limit=200
POST /api/user/notifications/{notification_id}/read
POST /api/user/notifications/groups/{group_key}/read
POST /api/user/notifications/read-all
```

读取单条系统公告时，请在请求体中传入 `{"source_type":"system"}`；业务通知传 `business` 或省略。分类已读只处理指定分类，全部已读会同时处理业务通知和系统公告。

## 应用内语音与视频通话

好友之间可发起应用内网络语音或视频通话，不使用运营商话费。Android 客户端支持听筒/扬声器、麦克风、前后摄像头、系统原生画中画、桌面边缘停靠和持续通话计时；通话不会自动录音或录像。

```http
POST /api/user/voice-calls
GET  /api/user/voice-calls/incoming
GET  /api/user/voice-calls/{call_id}
POST /api/user/voice-calls/{call_id}/answer
POST /api/user/voice-calls/{call_id}/decline
POST /api/user/voice-calls/{call_id}/hangup
POST /api/user/voice-calls/{call_id}/signals
GET  /api/user/voice-calls/{call_id}/signals?after_id=0
```

发起请求传 `target_user_id`、`call_type=audio|video` 和 WebRTC `offer`。生产环境必须在 `VOICE_CALL_ICE_SERVERS` 中配置自有 TURN；公共 STUN 只能覆盖部分网络环境。完整说明见 [NETWORK_CALLS.md](NETWORK_CALLS.md)。

## 分页

列表接口统一接受：

```text
page=1
limit=20
```

最大 `limit` 为 100，返回结构：

```json
{
  "items": [],
  "pagination": {
    "page": 1,
    "limit": 20,
    "total": 0,
    "total_pages": 0
  }
}
```

## 文件上传

`POST /api/user/uploads` 使用 `multipart/form-data`：

- `file`：文件字段
- `scene`：业务场景，可选

默认分类上限为图片 100 MiB、视频 1 GiB、音频 100 MiB、其他文件 512 MiB，可分别通过 `upload_image_max_bytes`、`upload_video_max_bytes`、`upload_audio_max_bytes`、`upload_file_max_bytes` 调整。扩展名、文件大小和上传来源均会校验；文件元数据、SHA-256 和所属应用会写入数据库。Nginx 还必须配置 `client_max_body_size 1100m`，PHP 必须配置 `upload_max_filesize=1024M` 与 `post_max_size=1100M`。若 Nginx 返回 HTTP 413，请求尚未进入 PHP，必须修改当前站点的 Nginx 配置并重载服务。

## 测试入口

```text
GET /api/health
```

部署后运行 `tools/smoke-maximum.ps1` 验证全部主业务域，运行 `tools/smoke-platform.ps1` 验证四级治理，运行 `tools/smoke-exchange.ps1` 验证余额自动兑换与退款，运行 `tools/smoke-exchange-concurrency.ps1` 验证真实并发下的库存和幂等，运行 `tools/smoke-notification-center.ps1` 验证聊天与通知分流、分类折叠和已读闭环。
