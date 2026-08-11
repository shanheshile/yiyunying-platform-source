package xyz.jjmxg.yiyunying.ui.forum;

/** Presentation rules shared by collapsed forum reply threads. */
final class ForumCommentPreviewPolicy {
    static final int PREVIEW_LIMIT = 2;

    private ForumCommentPreviewPolicy() {}

    static boolean isReplyVisible(boolean expanded, int replyIndex) {
        return expanded || (replyIndex >= 0 && replyIndex < PREVIEW_LIMIT);
    }

    static boolean showsToggle(int replyCount) {
        return replyCount > PREVIEW_LIMIT;
    }

    static String toggleLabel(boolean expanded, int replyCount) {
        if (replyCount <= 0) return "";
        if (expanded) return "返回全部评论";
        int hidden = Math.max(1, replyCount - PREVIEW_LIMIT);
        return "更多 " + hidden + " 条回复";
    }

}
