package xyz.jjmxg.yiyunying.ui.chat;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public final class ChatViewportPolicyTest {
    @Test public void onlyLatestViewportFollowsIncomingMessages() {
        assertTrue(ChatViewportPolicy.isAtLatest(9, 10));
        assertFalse(ChatViewportPolicy.isAtLatest(8, 10));
        assertFalse(ChatViewportPolicy.isAtLatest(6, 10));
    }

    @Test public void olderViewportAccumulatesNewMessageIndicator() {
        assertEquals(3, ChatViewportPolicy.nextPendingCount(0, 3, false));
        assertEquals(5, ChatViewportPolicy.nextPendingCount(3, 2, false));
        assertEquals(0, ChatViewportPolicy.nextPendingCount(5, 2, true));
    }

    @Test public void unchangedPollNeverRepositionsAnIdleRecyclerView() {
        assertTrue(ChatViewportPolicy.shouldFollowLatest(true, false, true));
        assertTrue(ChatViewportPolicy.shouldFollowLatest(false, true, true));
        assertFalse(ChatViewportPolicy.shouldFollowLatest(false, false, true));
        assertFalse(ChatViewportPolicy.shouldFollowLatest(false, true, false));
    }
}
