# 易运盈后台通信监管与系统接管

## 1. 范围模型

- 1 级平台所有者可监管全树，不受通信策略限制。
- 2 级授权平台只能监管自己分支内的 3 级 admin、应用和 4 级 user。
- 3 级 admin 只能监管自己的应用和该应用下的 4 级 user。
- 2/3 级不能跨授权平台、跨 admin 或跨应用读取、搜索、发言和查看审计。

监管范围包含私聊、群聊、聊天室和客服会话。2/3 级进入会话仅表示取得管理视图，不会自动加入群成员或聊天室成员，成员数量、成员列表和在线成员均不增加监管者。

管理端先从目标 user 的详情进入“通信与社交”，再选择一条精确会话。私聊按“目标 user 与具体好友”定位；群聊和聊天室按“目标 user 所在的具体群/房间”定位；客服按该 user 的具体客服会话定位。接口会再次校验目标 user 是否属于该私聊、群聊或房间，不能只凭会话编号读取无关会话。

精确会话响应包含 `view_context`：

| 字段 | 含义 |
| --- | --- |
| `summary` | 中文视角摘要，例如“用户甲 与 用户乙”或“用户甲进入群聊 开发交流” |
| `subject_user` | 当前被管理、被选中的下级 user |
| `counterpart_user` | 私聊的另一方；群聊、聊天室和客服会话为空 |
| `channel_kind` | `private`、`group`、`chat_room` 或 `service` |
| `channel_id` | 当前精确会话编号 |
| `description` | 管理视角说明，不暴露给普通 user |

管理端消息页固定按 `subject_user` 的视角排列左右气泡，并在页面顶部显示“谁和谁”或“谁进入哪个群”。切换目标 user 时必须重新加载上下文，禁止沿用上一个用户的消息列表。

## 2. 接管发言

监管者接管发言时，普通 user 只能看到：

- 显示名称：`系统消息`
- 身份标识：`系统`
- 发送者类型：`system`

普通成员看不到真实平台或管理员账号。服务器同时向 `communication_takeover_audits` 写入真实 `actor_type`、`actor_id`、`actor_level`、IP、应用、目标用户、会话、消息编号、内容摘要与哈希，保证可追责且不泄露管理身份。

系统接管不是冒充用户，也不是修改原消息。每次发言都会新增不可混淆的系统消息，并进入正常聊天记录、未读状态和搜索索引。

## 3. 权限与强制关系

策略表 `communication_takeover_policies` 分别控制平台和 admin 的：

| 字段组 | 含义 |
| --- | --- |
| `*_view_enabled` | 是否允许查看下级通信 |
| `*_send_enabled` | 是否允许以系统身份接管发言 |
| `*_private_enabled` | 是否开放私聊监管 |
| `*_group_enabled` | 是否开放群聊与聊天室监管 |
| `*_service_enabled` | 是否开放客服会话监管 |

1 级设置 `force_descendants=true` 时锁定 2/3 级；2 级设置该值时锁定自己分支内的 3 级。未强制锁定时，下级可以调整自己被允许管理的通道；强制锁定后，下级仍能查看有效值，但修改返回中文 `403`。

## 4. 显式身份标识

聊天接口统一返回：

| 字段 | 示例 | 用途 |
| --- | --- | --- |
| `sender_display_name` | `系统消息` | 消息上方显示名称 |
| `sender_badge` | `系统`、`群主`、`版主`、`客服` | 独立身份标识 |
| `sender_role` | `system`、`owner`、`admin`、`service` | 客户端稳定判断值 |
| `sender_badge_tone` | `primary`、`secondary`、`warning`、`neutral` | 蓝白 UI 的标识色调 |

群主和版主身份按消息所属群以及发送当时可验证的成员角色计算；系统和客服身份优先于普通成员角色。昵称、正文、发送时间和身份标识在 UI 中分区显示，避免全部塞入同一个气泡。

## 5. 搜索与快照

监管通信接口的 `content_filter` 支持：

| 值 | 搜索范围 |
| --- | --- |
| `all` | 正文、文件、标签和聊天快照 |
| `file` | 当前消息及快照中的文件名、媒体类型、MIME 类型 |
| `tag` | 当前消息及快照中的标签 |
| `snapshot` | 转发的只读聊天快照，包括内部正文、发送人和附件 |

转发快照使用版本化 `snapshot_json` 固化发送者显示名称、身份标识、角色、原始时间、正文、标签和附件。接收者按转发者当时看到的顺序查看，支持搜索与复制；快照内部消息不可增加、删除或修改，原会话后续变化也不会篡改已转发快照。

## 6. 主要接口

平台接口：

- `GET/PUT /api/platform/apps/{app_id}/communication-takeover-policy`
- `GET /api/platform/apps/{app_id}/communication-takeover-audits`
- `GET /api/platform/apps/{app_id}/users/{user_id}/communications`
- `POST /api/platform/apps/{app_id}/users/{user_id}/communications/send`
- `PUT/DELETE /api/platform/apps/{app_id}/users/{user_id}/communications/{message_id}`
- `GET /api/platform/apps/{app_id}/message-forwards/{forward_id}`

管理员接口：

- `GET/PUT /api/admin/apps/{app_id}/communication-takeover-policy`
- `GET /api/admin/apps/{app_id}/communication-takeover-audits`
- `GET /api/admin/apps/{app_id}/users/{user_id}/communications`
- `POST /api/admin/apps/{app_id}/users/{user_id}/communications/send`
- `PUT/DELETE /api/admin/apps/{app_id}/users/{user_id}/communications/{message_id}`
- `GET /api/admin/apps/{app_id}/message-forwards/{forward_id}`

接管发言示例：

```json
{
  "channel_type": "group",
  "channel_id": 123,
  "content": "系统管理员已进入处理，本条消息不会暴露实际管理账号。"
}
```

平台强制策略示例：

```json
{
  "platform_view_enabled": true,
  "platform_send_enabled": true,
  "platform_private_enabled": true,
  "platform_group_enabled": true,
  "platform_service_enabled": true,
  "admin_view_enabled": true,
  "admin_send_enabled": true,
  "admin_private_enabled": true,
  "admin_group_enabled": true,
  "admin_service_enabled": true,
  "force_descendants": true
}
```

## 7. 部署与验收

全新安装直接导入 `database/install.sql`。已有数据库按 `database/MIGRATION_ORDER.md` 执行，最后运行 `database/migrations/upgrade_20260718_management_review_notes.sql`。验收运行：

```powershell
powershell -ExecutionPolicy Bypass -File tools/check.ps1
powershell -ExecutionPolicy Bypass -File tools/smoke-communication-takeover.ps1 -BaseUrl http://127.0.0.1:8787
```

专项测试必须验证跨分支拒绝、上级强制锁定、3 级自定义、接管者不进入成员列表、公开系统身份、真实审计身份、文件/标签/快照搜索、精确私聊双方、非参与者拒绝、群成员可进入和非成员拒绝。
