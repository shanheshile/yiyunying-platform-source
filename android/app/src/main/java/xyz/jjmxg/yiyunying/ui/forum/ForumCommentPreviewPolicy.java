package xyz.jjmxg.yiyunying.ui.forum;

import java.util.Locale;

/** Presentation rules shared by collapsed forum reply threads. */
final class ForumCommentPreviewPolicy {
    static final int PREVIEW_LIMIT = 2;

    private ForumCommentPreviewPolicy() {}

    static boolean includesPreview(int replyIndex) {
        return replyIndex >= 0 && replyIndex < PREVIEW_LIMIT;
    }

    static String toggleLabel(boolean expanded, int replyCount) {
        if (replyCount <= 0) return "";
        return expanded ? "收起回复" : "查看全部 " + replyCount + " 条回复";
    }

    static String attachmentLabel(String mediaType, String mimeType) {
        String type = mediaType == null ? "" : mediaType.trim().toLowerCase(Locale.ROOT);
        String mime = mimeType == null ? "" : mimeType.trim().toLowerCase(Locale.ROOT);
        if ("image".equals(type) || mime.startsWith("image/")) return "图片";
        if ("video".equals(type) || mime.startsWith("video/")) return "视频";
        if ("voice".equals(type)) return "语音";
        if ("audio".equals(type) || mime.startsWith("audio/")) return "音频";
        if ("document".equals(type) || mime.contains("pdf") || mime.contains("word")
            || mime.contains("sheet") || mime.startsWith("text/")) return "文档";
        return "文件";
    }
}
