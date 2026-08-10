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
        return expanded ? "收起回复" : "查看全部 " + replyCount + " 条回复";
    }

}
