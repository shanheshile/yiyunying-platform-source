# 社区内容审核闭环

## 范围

- 论坛帖子：`forum_posts.audit_status`
- 论坛评论/回复：`forum_comments.audit_status`
- 动态：`user_moments.audit_status`
- 动态评论/回复：`moment_comments.audit_status`

四类内容统一使用 `pending / approved / rejected / on_hold`、`audit_reason`、
`audited_by` 和 `audited_at`。管理端提供三个明确按钮：

- `approved`（通过）：不要求说明，并清空之前遗留的拒绝/暂定原因。
- `rejected`（不通过）：必须提供不超过 500 字的原因。
- `on_hold`（暂定）：说明可选，内容保持非公开，等待后续再次决策。

结果会在同一事务中写入 `admin_operation_logs` 并通知作者；重复提交当前状态返回 `409`。

## 管理员控制

- `forum_post_audit`：新帖子是否进入待审。
- `forum_comment_audit`：新论坛评论是否进入待审。
- `moment_post_audit`：新动态是否进入待审。
- `moment_comment_audit`：新动态评论是否进入待审。

开关关闭时保留历史审核结果，不批量改写既有内容。用户修改开启审核的动态时，内容会重新变为
`pending`，避免审核通过后替换内容绕过审核。

## 权限与状态边界

- 所有接口必须通过 `admin_id + app_id` 租户校验。
- 三级管理员必须具有 `forum.manage`（社区内容与审核）权限。
- 列表默认将待审内容排在最前；接口支持 `audit_status` 筛选。
- 动态、帖子和评论的作者可看到自己的待审、暂定或未通过内容；治理端通过管理接口可查看
  全部状态，其他用户和公开接口只能看到 `approved`。
- `pending / on_hold / rejected` 内容不能评论、点赞、收藏、转发、打赏或购买。
- 批准评论前，其所属帖子/动态及上级评论必须已通过。
- 帖子或动态被暂定/不通过时，活动评论会在行锁保护下同步进入相同的非公开状态；评论被
  暂定/不通过时，其全部下级回复同样同步，避免通过下级内容泄漏父内容。
- 重复提交相同状态返回 `409`，提示审核员刷新，避免并发重复操作。

## 迁移和验收

现有四个 `audit_status` 字段均为 `VARCHAR(20)`，可直接承载 `on_hold`，因此本次无需新增或
破坏性修改数据库结构。新环境仍应执行
`database/migrations/upgrade_20260811_content_moderation_closure.sql`；该迁移对已有内容使用
`approved` 默认值，不会将历史数据突然隐藏。

最小回归：

```powershell
php tools/test-content-moderation-contract.php
.\tools\check.ps1
```

Android 端需运行 `ContentModerationModuleTest`，并在真实审核数据上分别验证：

1. “列表 → 详情 → 暂定 → 作者可见但不可互动 → 列表刷新”；
2. “暂定 → 通过 → 原因清空并公开”；
3. “通过 → 不通过（原因必填）→ 父级及下级内容同步隐藏”。
