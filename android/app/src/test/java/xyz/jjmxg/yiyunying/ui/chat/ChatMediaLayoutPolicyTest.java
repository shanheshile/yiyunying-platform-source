package xyz.jjmxg.yiyunying.ui.chat;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public final class ChatMediaLayoutPolicyTest {
    @Test public void narrowSelectionStackNeverExceedsMessageContent() {
        int contentWidth = ChatMediaLayoutPolicy.contentWidthDp(320, true);
        ChatMediaLayoutPolicy.StackMetrics metrics =
            ChatMediaLayoutPolicy.stackMetricsDp(contentWidth, 3);

        assertEquals(198, contentWidth);
        assertTrue(metrics.stageWidth >= 48);
        assertTrue(metrics.stageHeight >= 96);
        assertTrue(metrics.totalWidth(3) <= contentWidth);
    }

    @Test public void commonWidthsPreserveExistingMaximumWithoutOverflow() {
        for (int viewport : new int[]{320, 360, 411, 600}) {
            for (boolean selecting : new boolean[]{false, true}) {
                int contentWidth = ChatMediaLayoutPolicy.contentWidthDp(viewport, selecting);
                ChatMediaLayoutPolicy.StackMetrics metrics =
                    ChatMediaLayoutPolicy.stackMetricsDp(contentWidth, 3);
                assertTrue(metrics.totalWidth(3) <= contentWidth);
                assertTrue(ChatMediaLayoutPolicy.expandedColumnWidthDp(contentWidth)
                    + ChatMediaLayoutPolicy.expandedRailWidthDp(contentWidth)
                    + ChatMediaLayoutPolicy.expandedGapDp(contentWidth) <= contentWidth);
            }
        }

        assertEquals(300, ChatMediaLayoutPolicy.contentWidthDp(600, false));
        assertEquals(196,
            ChatMediaLayoutPolicy.stackMetricsDp(300, 3).stageWidth);
    }
}
