package xyz.jjmxg.yiyunying.ui.forum;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public final class ForumCommentPreviewPolicyTest {
    @Test public void collapsedThreadKeepsTwoFullRepliesInsideTheParent() {
        assertTrue(ForumCommentPreviewPolicy.isReplyVisible(false, 0));
        assertTrue(ForumCommentPreviewPolicy.isReplyVisible(false, 1));
        assertFalse(ForumCommentPreviewPolicy.isReplyVisible(false, 2));
        assertTrue(ForumCommentPreviewPolicy.isReplyVisible(true, 2));
        assertFalse(ForumCommentPreviewPolicy.showsToggle(2));
        assertTrue(ForumCommentPreviewPolicy.showsToggle(3));
    }

    @Test public void toggleAlwaysUsesTheRealReplyCount() {
        assertEquals("查看全部 7 条回复", ForumCommentPreviewPolicy.toggleLabel(false, 7));
        assertEquals("收起回复", ForumCommentPreviewPolicy.toggleLabel(true, 7));
        assertEquals("", ForumCommentPreviewPolicy.toggleLabel(false, 0));
    }
}
