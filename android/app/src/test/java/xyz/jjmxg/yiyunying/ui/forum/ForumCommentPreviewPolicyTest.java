package xyz.jjmxg.yiyunying.ui.forum;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public final class ForumCommentPreviewPolicyTest {
    @Test public void collapsedThreadOnlyPreviewsTwoReplies() {
        assertTrue(ForumCommentPreviewPolicy.includesPreview(0));
        assertTrue(ForumCommentPreviewPolicy.includesPreview(1));
        assertFalse(ForumCommentPreviewPolicy.includesPreview(2));
    }

    @Test public void toggleAlwaysUsesTheRealReplyCount() {
        assertEquals("查看全部 7 条回复", ForumCommentPreviewPolicy.toggleLabel(false, 7));
        assertEquals("收起回复", ForumCommentPreviewPolicy.toggleLabel(true, 7));
        assertEquals("", ForumCommentPreviewPolicy.toggleLabel(false, 0));
    }

    @Test public void attachmentSummaryPreservesMediaMeaning() {
        assertEquals("图片", ForumCommentPreviewPolicy.attachmentLabel("image", "image/gif"));
        assertEquals("语音", ForumCommentPreviewPolicy.attachmentLabel("voice", "audio/aac"));
        assertEquals("文档", ForumCommentPreviewPolicy.attachmentLabel("", "application/pdf"));
        assertEquals("文件", ForumCommentPreviewPolicy.attachmentLabel("file", "application/zip"));
    }
}
