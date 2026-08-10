# 社区内容审核闭环

## 范围

- 论坛帖子：`forum_posts.audit_status`
- 论坛评论/回复：`forum_comments.audit_status`
- 动态：`user_moments.audit_status`
- 动态评论/回复：`moment_comments.audit_status`

四类内容统一使用 `pending / approved / rejected`、`audit_reason`、`audited_by`和
`audited_at`。拒绝必须提供原因；通过说明可选。结果会写入 `admin_operation_logs`并通知作者。

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
- 动态和评论的作者可看到自己的待审/未通过内容；其他用户只能看到 `approved`。
- 未通过的动态不能点赞、评论、收藏或转发。
- 批准评论前，其所属帖子/动态及上级评论必须已通过。
- 重复提交相同状态返回 `409`，提示审核员刷新，避免并发重复操作。

## 迁移和验收

执行 `database/migrations/upgrade_20260811_content_moderation_closure.sql`。迁移对已有内容使用
`approved` 默认值，不会将历史数据突然隐藏。

最小回归：

```powershell
php tools/test-content-moderation-contract.php
.\tools\check.ps1
```

Android 端需运行 `ContentModerationModuleTest`，并在真实审核数据上验证“列表 → 详情 → 通过/拒绝 → 列表刷新”。
