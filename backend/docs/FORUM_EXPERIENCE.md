# 易运盈论坛付费分节、热度与多级管理规范

本文对应 `upgrade_20260714_forum_experience.sql`。它说明 Android 2.4.0 使用的论坛数据、接口和权限规则。全部返回仍使用统一信封 `{code,msg,data,trace_id}`，用户接口必须同时携带用户 Token 与 `X-App-Key`。

## 1. 页面链路

```text
动态 -> 论坛 -> 板块列表 -> 帖子列表 -> 帖子详情
帖子详情 -> 内容节、附件、评论与回复 -> 作者公开主页
1/2 级平台 -> 应用 -> 论坛板块 -> 帖子 -> 完整治理详情
3 级管理员 -> 当前应用 -> 论坛板块 -> 帖子 -> 完整治理详情
```

- 点击板块进入帖子列表，点击帖子进入同一详情页；评论和回复不拆成无上下文的独立页面。
- 用户仅能看到公开资料；被隐藏的联系方式、资产和管理字段不会随作者主页返回。
- 1 级可查看完整数据树，2 级只能查看自己的分支，3 级只能查看自己的应用。所有管理穿透接口都会重新校验应用归属。

## 2. 帖子内容节

帖子可以同时包含多个按顺序排列的内容节：

| 字段 | 含义 |
| --- | --- |
| `section_type` | `free` 免费节；`paid` 付费节 |
| `title` | 本节标题，最多 160 字 |
| `content` | 本节正文 |
| `tags` | 本节标签数组 |
| `attachments` | 图片、GIF、视频、音频或文件附件 |
| `price_balance` | 付费节价格；免费节固定为 0 |
| `sort_order` | 展示顺序，从小到大 |

发布者可混排免费节和付费节，并在发布后调整顺序。未购买的付费节只返回标题、价格和锁定状态，正文、标签和附件均在服务端清空，不能靠修改客户端绕过。作者、已购买者和治理角色可看完整内容。

付费节使用应用配置的用户余额资产。购买在同一数据库事务内完成扣款、作者入账、购买凭证和通知；重复购买幂等，不重复扣费。已有购买记录的内容节不能删除，但作者可以继续修改，以保护购买者权益。

### 内容节接口

| 方法 | 路径 | 用途 |
| --- | --- | --- |
| POST | `/api/user/forum-posts/{post_id}/sections` | 给自己的帖子新增内容节 |
| PUT | `/api/user/forum-posts/{post_id}/sections/{section_id}` | 修改自己的内容节 |
| DELETE | `/api/user/forum-posts/{post_id}/sections/{section_id}` | 删除尚无人购买的内容节 |
| PUT | `/api/user/forum-posts/{post_id}/sections/reorder` | 一次提交全部 `section_ids` 调整顺序 |
| POST | `/api/user/forum-posts/{post_id}/sections/{section_id}/buy` | 购买并永久解锁一个付费节 |

新增内容节示例：

```json
{
  "section_type": "paid",
  "title": "完整教程和附件",
  "content": "购买后显示的正文",
  "tags": ["教程", "源码"],
  "price_balance": 8.5,
  "attachments": [101, 102]
}
```

调整顺序示例：

```json
{"section_ids":[23,21,22]}
```

`section_ids` 必须完整包含该帖当前所有有效内容节，避免并发编辑时误丢内容。

## 3. 评论与回复

- 评论和对评论的回复统一保存在帖子详情中，`parent_id` 形成树形关系。
- 帖子发布者可以置顶评论或回复，数量受 `forum_self_comment_pin_limit` 控制。
- 帖子和评论均支持点赞、取消点赞、收藏、取消收藏、转发和举报。
- 管理员仍可按原有审核、锁定、删除、置顶和加精接口治理内容。

| 方法 | 路径 | 用途 |
| --- | --- | --- |
| POST | `/api/user/forum-posts/{post_id}/comments` | 评论或回复；回复时传 `parent_id` |
| POST | `/api/user/forum-content/{target_type}/{target_id}/like` | 点赞或取消；`target_type=post/comment` |
| POST | `/api/user/forum-content/{target_type}/{target_id}/favorite` | 收藏或取消 |
| PUT | `/api/user/forum-posts/{post_id}/comments/{comment_id}/pin` | 作者置顶或取消置顶评论 |
| POST | `/api/user/forum-content/{target_type}/{target_id}/forward` | 记录转发目标与次数 |
| POST | `/api/user/reports` | 举报论坛、帖子、评论或回复 |

置顶示例：

```json
{"enabled":true,"pin_order":0}
```

## 4. 唯一浏览与热度

浏览量不再按每次刷新累加：

- 登录用户按 `app_id + user_id` 去重。
- 公开访客按应用、IP 与 User-Agent 的哈希去重，不存储原始指纹。
- 重复访问只更新该访客的访问次数和最后访问时间，不增加 `unique_view_count`。

热度分数由唯一浏览、点赞、评论和发布时间衰减共同计算：

```text
heat_score = max(unique_view_count, view_count)
             + like_count * 4
             + comment_count * 6
             + 48 小时内的新鲜度分
```

达到应用阈值时返回 `hot_label=最近火热/近期高讨论`。热帖排在官方置顶、加精和锁定规则之后，不覆盖人工治理顺序。

管理员可配置：

| 配置键 | 默认值 | 单位/说明 |
| --- | ---: | --- |
| `forum_hot_enabled` | `true` | 是否启用自动热度标签 |
| `forum_hot_score_threshold` | `40` | 触发标签的最低热度分 |
| `forum_hot_window_days` | `14` | 仅统计最近多少天的热帖 |
| `forum_self_comment_pin_limit` | `3` | 每个帖子作者可置顶的评论数 |
| `forum_personal_plate_pin_limit` | `20` | 每个用户私有置顶板块数 |
| `forum_personal_post_pin_limit` | `30` | 每个用户私有置顶帖子数 |
| `forum_paid_section_max_count` | `30` | 每个帖子最多内容节数 |

## 5. 个人置顶与置底

个人置顶、置底只影响操作者自己的列表，不改变其他人的排序，也不等同于管理员的全局置顶。

```http
PUT /api/user/forum-personal/{target_type}/{target_id}/position
```

```json
{"position":"top","sort_order":0}
```

- `target_type`：`plate` 或 `post`。
- `position`：`top`、`normal` 或 `bottom`。
- `normal` 会删除个人排序记录，恢复默认顺序。

## 6. 四级查看接口

### 用户

| 方法 | 路径 |
| --- | --- |
| GET | `/api/user/forum-plates` |
| GET | `/api/user/forum-posts` |
| GET | `/api/user/forum-posts/{post_id}` |

用户帖子列表支持板块、关键词、标签、日期和热度排序；详情自动返回当前用户的购买、点赞、收藏和个人排序状态。

### 3 级管理员

| 方法 | 路径 |
| --- | --- |
| GET | `/api/admin/apps/{app_id}/forum-plates` |
| GET | `/api/admin/apps/{app_id}/forum-posts` |
| GET | `/api/admin/apps/{app_id}/forum-posts/{post_id}` |

### 1/2 级平台

| 方法 | 路径 |
| --- | --- |
| GET | `/api/platform/apps/{app_id}/forum-plates` |
| GET | `/api/platform/apps/{app_id}/forum-posts` |
| GET | `/api/platform/apps/{app_id}/forum-posts/{post_id}` |

管理详情返回未遮蔽内容、附件、购买记录所需的治理字段和完整评论树，但平台范围校验仍然生效：2 级不能通过猜测 `app_id` 查看其他 2 级分支。

## 7. Android 2.4.0 对应交互

- 四端统一白色主背景、蓝色强调色和状态栏安全区。
- 用户端“动态”一级入口包含论坛、悬赏、资源、商店和投票；“活动”包含余额商店、红包、抽奖和兑换。
- 帖子发布器以可视化列表新增、编辑、删除和上下移动内容节，不要求用户手写 JSON。
- 付费节在详情页显示锁定卡片、余额价格和确认购买按钮，成功后原地刷新并解锁。
- 1/2/3 端可从应用进入板块、帖子和完整详情，返回键逐层返回，不会从详情直接退出软件。
- 帖子图片、GIF 与视频使用统一媒体预览；视频支持小窗预览、全屏、倍速、亮度、音量、双击暂停和长按临时加速。

## 8. 安全与一致性

1. 所有写操作校验作者身份、应用归属和层级范围。
2. 付费解锁以数据库购买凭证为准，不信任客户端传入的“已购买”状态。
3. 余额扣款和作者入账在一个事务内完成，失败时整体回滚。
4. 同一用户重复购买、重复点赞和重复收藏不会产生重复记录。
5. 内容节顺序整体提交并校验全集，避免部分排序导致内容丢失。
6. 管理查看不产生用户购买记录，也不从用户余额扣款。

完整的 599 条接口、字段和在线调试界面见 `public/api-docs.html`；精确表结构见 [SCHEMA.md](SCHEMA.md)。
