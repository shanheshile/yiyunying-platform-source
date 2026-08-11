<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'install' => $root . '/database/install.sql',
    'migration' => $root . '/database/migrations/upgrade_20260811_content_moderation_closure.sql',
    'routes' => $root . '/routes/api.php',
    'admin' => $root . '/app/Controllers/Admin/ContentModerationController.php',
    'forum' => $root . '/app/Controllers/Admin/ForumController.php',
    'user' => $root . '/app/Controllers/User/MomentController.php',
    'user_forum' => $root . '/app/Controllers/User/ForumController.php',
    'favorite' => $root . '/app/Controllers/User/FavoriteController.php',
    'forum_notifications' => $root . '/app/Services/ForumCommentNotificationService.php',
    'access' => $root . '/app/Services/AdminAccessService.php',
    'roles' => $root . '/app/Services/RolePermissionService.php',
    'settings' => $root . '/app/Services/SettingDescriptorService.php',
    'app_service' => $root . '/app/Services/AppService.php',
    'modules' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/domain/module/ModuleRegistry.java',
    'generic' => dirname($root) . '/android/app/src/main/java/xyz/jjmxg/yiyunying/ui/module/GenericModuleFragment.java',
];

$source = [];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
    $source[$name] = (string) file_get_contents($path);
}

$checks = [
    'migration remains portable and idempotent' =>
        !preg_match('/\bDELIMITER\b|CREATE\s+PROCEDURE/i', $source['migration'])
        && substr_count($source['migration'], 'information_schema.COLUMNS') >= 8
        && str_contains($source['migration'], 'ON DUPLICATE KEY UPDATE'),
    'fresh schema contains moment audit fields' =>
        substr_count($source['install'], '`audit_status` VARCHAR(20)') >= 4
        && str_contains($source['install'], 'idx_user_moments_moderation')
        && str_contains($source['install'], 'idx_moment_comments_moderation'),
    'forum pending-comment mentions are persisted by an idempotent schema change' =>
        str_contains($source['install'], '`mentions_json` LONGTEXT')
        && str_contains($source['migration'], "TABLE_NAME = 'forum_comments' AND COLUMN_NAME = 'mentions_json'")
        && str_contains($source['migration'], 'ALTER TABLE forum_comments ADD COLUMN mentions_json'),
    'existing and new applications receive both review switches' =>
        str_contains($source['migration'], "'moment_post_audit'")
        && str_contains($source['migration'], "'moment_comment_audit'")
        && str_contains($source['install'], "'moment_post_audit'")
        && str_contains($source['app_service'], "'moment_comment_audit' => false")
        && str_contains($source['settings'], "'moment_post_audit' =>"),
    'administrator routes expose real list detail and decision endpoints' =>
        str_contains($source['routes'], "/api/admin/apps/{app_id}/moments/{moment_id}/audit")
        && str_contains($source['routes'], "/api/admin/apps/{app_id}/moment-comments/{comment_id}/audit")
        && str_contains($source['routes'], "/api/admin/apps/{app_id}/forum-comments/{comment_id}"),
    'moment review is tenant scoped and transaction locked' =>
        substr_count($source['admin'], 'FOR UPDATE') >= 2
        && substr_count($source['admin'], 'admin_id = ? AND app_id = ?') >= 5,
    'comment approval locks every approved ancestor and non-public decisions cascade through descendants' =>
        str_contains($source['admin'], 'assertApprovedMomentParentChain(')
        && str_contains($source['admin'], 'transitionMomentCommentDescendants(')
        && str_contains($source['forum'], 'assertApprovedForumParentChain(')
        && str_contains($source['forum'], 'transitionForumCommentDescendants(')
        && substr_count($source['admin'], 'ORDER BY id FOR UPDATE') >= 1
        && substr_count($source['forum'], 'ORDER BY id FOR UPDATE') >= 1,
    'parent-content joins never expose an invisible parent' =>
        str_contains($source['user'], "pc.status = 1 AND (pc.audit_status = 'approved' OR pc.user_id = ?)")
        && str_contains($source['user_forum'], "parent_comment.audit_status = 'approved' OR parent_comment.user_id = ?"),
    'rejections require a human-readable reason' =>
        str_contains($source['admin'], '拒绝审核时必须填写原因')
        && str_contains($source['forum'], '拒绝审核时必须填写原因'),
    'three-state decisions accept optional hold notes and clear stale rejection reasons on approval' =>
        substr_count($source['admin'], "['approved', 'rejected', 'on_hold']") >= 1
        && substr_count($source['forum'], "['approved', 'rejected', 'on_hold']") >= 1
        && str_contains($source['admin'], "if (\$status === 'approved') \$reason = ''")
        && str_contains($source['forum'], "if (\$status === 'approved') \$reason = ''")
        && str_contains($source['admin'], "'on_hold' => '暂定'")
        && str_contains($source['forum'], "'on_hold' => '暂定'"),
    'top-level hold and rejection lock and transition every active child' =>
        str_contains($source['admin'], 'transitionAllMomentComments(')
        && str_contains($source['forum'], 'transitionAllForumComments(')
        && str_contains($source['admin'], "if (\$status !== 'approved')")
        && str_contains($source['forum'], "if (\$status !== 'approved')"),
    'all content decisions write administrator audit logs' =>
        str_contains($source['admin'], "'moment_moderation'")
        && str_contains($source['admin'], "'moment_comment_moderation'")
        && str_contains($source['forum'], "'forum_post_moderation'")
        && str_contains($source['forum'], "'forum_comment_moderation'"),
    'audit state persistent notifications and administrator logs share transactions' =>
        preg_match('/auditMoment\(.*?Database::transaction\(.*?LogService::adminOperation\(.*?notifyAuditResult\(/s', $source['admin']) === 1
        && preg_match('/auditComment\(.*?Database::transaction\(.*?ForumCommentNotificationService::notifyParticipants\(.*?LogService::adminOperation\(/s', $source['forum']) === 1,
    'user feed hides unapproved moments from other users' =>
        substr_count($source['user'], "m.audit_status = 'approved' OR m.user_id = ?") >= 2,
    'new and edited moments follow the administrator review switch' =>
        substr_count($source['user'], "'moment_post_audit'") >= 2
        && str_contains($source['user'], "audited_by = NULL, audited_at = NULL"),
    'pending comments stay private and do not notify participants early' =>
        str_contains($source['user'], "c.audit_status = 'approved' OR c.user_id = ?")
        && str_contains($source['user'], "if (\$auditStatus === 'approved') self::notifyOwner"),
    'automatic child approval locks and validates the full parent chain' =>
        str_contains($source['user'], 'assertApprovedMomentParentChain(')
        && str_contains($source['user_forum'], 'assertApprovedForumParentChain(')
        && substr_count($source['user'], 'FOR UPDATE') >= 3
        && substr_count($source['user_forum'], 'FOR UPDATE') >= 3,
    'moment favorites only expose approved content or the viewer own content' =>
        str_contains($source['favorite'], "moment.audit_status = 'approved' OR moment.user_id = ?"),
    'forum author preview is separate from approved-only interaction' =>
        substr_count($source['user_forum'], 'ensureApprovedForInteraction($post);') >= 5
        && str_contains($source['user_forum'], "comment.audit_status = 'approved'")
        && str_contains($source['user_forum'], "post.audit_status = 'approved'"),
    'held forum content cannot be reported or otherwise interacted with through owner visibility' =>
        str_contains($source['user_forum'], "deleted_at IS NULL AND audit_status = 'approved'")
        && substr_count($source['user_forum'], "AND comment.audit_status = 'approved'") >= 2
        && substr_count($source['user_forum'], "AND post.audit_status = 'approved'") >= 2,
    'all user-side forum edits re-enter review when post moderation is enabled' =>
        str_contains($source['user_forum'], "\$audit = AppService::setting((int) \$user['app_id'], 'forum_post_audit', false)")
        && !str_contains($source['user_forum'], "\$audit = \$isOwner && AppService::setting"),
    'disabled or deleted forum posts cannot receive approved comments or counts' =>
        str_contains($source['forum'], "(int) \$post['status'] !== 1 || \$post['deleted_at'] !== null")
        && str_contains($source['forum'], 'AND status = 1 AND deleted_at IS NULL'),
    'forum approval replays persisted author reply and mention notifications' =>
        str_contains($source['user_forum'], 'mentions_json')
        && str_contains($source['forum'], 'ForumCommentNotificationService::notifyParticipants')
        && str_contains($source['forum_notifications'], "'forum_mention'")
        && str_contains($source['forum_notifications'], 'mentionIds('),
    'community moderation permission guards dynamic endpoints' =>
        str_contains($source['access'], 'forum-comments|moments|moment-comments|short-videos|short-video-comments|reports')
        && str_contains($source['roles'], '社区内容与审核'),
    'Android uses explicit approve reject and optional-note hold actions' =>
        substr_count($source['modules'], '.fixed("audit_status", "approved")') >= 4
        && substr_count($source['modules'], '.fixed("audit_status", "rejected")') >= 4
        && substr_count($source['modules'], '.fixed("audit_status", "on_hold")') >= 4
        && substr_count($source['modules'], 'multilineRequired("reason", "拒绝原因（必填）")') >= 4
        && substr_count($source['modules'], 'multiline("reason", "暂定说明（可选）")') >= 4,
    'Android loads server detail before review and refreshes after actions' =>
        str_contains($source['generic'], 'loadModerationDetail(snapshot)')
        && str_contains($source['generic'], '"moment-comments"')
        && str_contains($source['generic'], 'if (action.refreshAfter()) load(false)'),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Content moderation contract failed: {$name}\n");
        exit(1);
    }
}

echo "Content moderation contract: passed\n";
