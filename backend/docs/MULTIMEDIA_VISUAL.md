# 统一多媒体、撤回、隐私主页与监管资料

本章描述 Android 客户端与 PHP 后端共同使用的结构化协议。正常业务界面不要求用户填写 URL、附件 JSON、分类 ID 数组或投票选项 JSON；客户端通过系统相册/文件选择器取得本地内容，先上传，再组装接口请求。

## 1. 多媒体附件

支持的 `media_type`：

| 类型 | 用途 |
| --- | --- |
| `image` | 本地相册图片或普通图片 |
| `sticker` | 用户自己的表情包 |
| `audio` | 语音或音频文件 |
| `video` | 视频文件 |
| `file` | PDF、压缩包、文档等其他文件 |

用户上传调用 `POST /api/user/uploads`；admin 在所选应用中上传调用 `POST /api/admin/apps/{app_id}/uploads`。两者均使用 `multipart/form-data`，文件字段名为 `file`，可选文本字段为 `scene`。返回：

```json
{
  "upload_id": 12,
  "file_url": "/storage/uploads/2026/07/example.png",
  "mime_type": "image/png",
  "size_bytes": 2048
}
```

随后把 `upload_id` 放入业务接口。一条内容最多 20 个附件，正文与附件不能同时为空；正文和附件同时存在时，后端返回 `content_type=mixed`。

```json
{
  "content": "文字和附件一起发送",
  "attachments": [
    {"media_type":"image","upload_id":12},
    {"media_type":"audio","upload_id":13,"duration_ms":3200},
    {"media_type":"file","upload_id":14,"file_name":"说明书.pdf"}
  ]
}
```

统一返回字段为 `attachments`、`attachment_count`、`has_media` 和 `media_summary`。Android 对多图默认折叠，用户可展开和收起；图片可预览，语音、视频和文件可点击打开。

## 2. 可使用附件的业务

| 业务 | 发送/创建 | 读取 |
| --- | --- | --- |
| 私聊 | `POST /api/user/messages/private` | `GET /api/user/conversations/{conversation_id}/messages` |
| 群聊 | `POST /api/user/chat-rooms/{room_id}/messages` | `GET /api/user/chat-rooms/{room_id}/messages` |
| 用户客服 | `POST /api/user/service/messages` | `GET /api/user/service/messages` |
| admin 客服回复 | `POST /api/admin/apps/{app_id}/service-sessions/{session_id}/reply` | `GET /api/admin/apps/{app_id}/service-sessions/{session_id}/messages` |
| 论坛帖子 | `POST /api/user/forum-posts` | `GET /api/user/forum-posts/{post_id}` |
| 论坛评论 | `POST /api/user/forum-posts/{post_id}/comments` | 随帖子详情返回 |
| 资源投稿 | `POST /api/user/resources` | `GET /api/user/resources/{resource_id}` |
| 资源评论 | `POST /api/user/resources/{resource_id}/comments` | 随资源详情返回 |
| 商店应用 | `POST /api/admin/apps/{app_id}/store-apps` | `GET /api/user/store-apps/{store_app_id}` |

## 3. 个人表情包

4 级 user 可维护自己的表情包，不能引用其他用户的私有表情：

| 方法 | 路径 | 功能 |
| --- | --- | --- |
| GET | `/api/user/sticker-packs` | 返回分组及分组中的具体表情 |
| POST | `/api/user/sticker-packs` | 创建分组 |
| PUT | `/api/user/sticker-packs/{pack_id}` | 修改分组 |
| DELETE | `/api/user/sticker-packs/{pack_id}` | 删除分组和表情 |
| POST | `/api/user/sticker-packs/{pack_id}/stickers` | 用本人图片上传记录创建表情 |
| DELETE | `/api/user/sticker-packs/{pack_id}/stickers/{sticker_id}` | 删除表情 |

发送时使用：

```json
{"content":"","attachments":[{"sticker_id":36}]}
```

## 4. 撤回规则

- 私聊和群聊支持撤回；客服消息永远不可撤回，只允许长按复制。
- L1 提供默认时限；L2 可继承或自定义；L3 可在上级允许时为应用自定义。
- L1/L2 开启强制同步后，下级不能修改有效时限。
- 普通 user 只看到“消息已撤回”，接口不会返回原附件。
- 管理范围内的 L1/L2/L3 可在审计接口看到原正文、原附件、撤回人、撤回时间和原因。

用户撤回接口：

```text
POST   /api/user/messages/{message_id}/recall
DELETE /api/user/chat-rooms/{room_id}/messages/{message_id}
```

admin 群消息处置：

```text
DELETE /api/admin/apps/{app_id}/chat-rooms/{room_id}/messages/{message_id}
```

## 5. 隐私主页与关联导航

用户主页接口为 `GET /api/user/profiles/{user_id}`。

- 本人或公开资料返回 `profile_visibility=full`。
- 隐藏资料不再返回 403，而是返回 `profile_visibility=basic`、`details_hidden=true`。
- 基础主页仍展示用户 ID、账号、昵称、头像、称号、注册时间、关注数和粉丝数。
- 隐藏时不返回 QQ、背景、签名、性别、等级、经验和会员到期时间。
- L1/L2/L3 在合法管理范围内查看监管资料时不受用户公开开关影响。

Android 论坛固定导航为：

```text
论坛板块 -> 该板块帖子列表 -> 帖子正文/附件/评论 -> 点击作者头像 -> 用户主页
```

帖子列表必须带 `plate_id` 查询，帖子详情返回 `user_id`，评论也返回 `user_id`，因此所有关联跳转均使用真实主键，不依赖标题或昵称猜测。

## 6. 管理监管资料

admin 查看自己应用的用户：

```text
GET /api/admin/apps/{app_id}/users/{user_id}
GET /api/admin/apps/{app_id}/users/{user_id}/communications?channel_type=private&channel_id=1
```

L1/L2 查看管辖应用的用户：

```text
GET /api/platform/apps/{app_id}/users
GET /api/platform/apps/{app_id}/users/{user_id}/overview
GET /api/platform/apps/{app_id}/users/{user_id}/communications?channel_type=group&channel_id=2
```

`overview` 按中文分为“资料与资产、消息类、社交类、内容类、其他”，包含资产流水、订单、好友、申请、私聊、群聊、客服、撤回审计、笔记、帖子、评论、收藏、资源、应用投稿、上传、反馈和行为日志。平台接口仍执行四级归属校验，L2 不能读取其他 L2 分支。

## 7. 可视化投票

投票接口返回真实分类对象和真实选项对象：

```text
GET  /api/user/poll-categories
POST /api/user/poll-categories
GET  /api/user/polls
POST /api/user/polls
GET  /api/user/polls/{poll_id}
POST /api/user/polls/{poll_id}/vote
```

创建时 `options` 为 2-500 个选项，`category_ids` 最多 50 个。Android 使用分类复选框和可增删选项行生成这些字段；投票详情直接显示分类名、选项文字、单选/多选控件、已选项和允许查看时的票数。

## 8. 结构化位置消息

聊天、群聊、聊天室、论坛和悬赏统一使用 `media_type=location`。客户端显示地点名称和地址，不向普通用户展示经纬度 JSON：

```json
{
  "content": "",
  "attachments": [
    {
      "media_type": "location",
      "url": "/api/user/me",
      "file_name": "测试地点",
      "metadata": {
        "location_name": "测试地点",
        "address": "测试地址",
        "latitude": 35.550001,
        "longitude": 116.800001
      }
    }
  ]
}
```

仅发送位置时返回 `content_type=location`；聊天记录搜索使用 `content_filter=location`。Android 点击位置卡片进入软件内位置详情，可继续在内置浏览器查看地图。

## 9. 验证

专项回归：

```powershell
powershell -ExecutionPolicy Bypass -File tools/smoke-message-entitlements.ps1 -BaseUrl http://127.0.0.1:8788
powershell -ExecutionPolicy Bypass -File tools/smoke-multimedia-visual.ps1 -BaseUrl http://127.0.0.1:8788
```

前者包含 61 项消息权益、继承、强制和撤回检查；后者包含 71 项多媒体、隐私主页、论坛关联、资源附件、投票分类选项和管理审计检查。
