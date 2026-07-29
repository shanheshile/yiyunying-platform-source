package xyz.jjmxg.yiyunying.ui.chat;

import static org.junit.Assert.assertEquals;

import org.junit.Test;

public final class PageSnapPolicyTest {
    @Test public void slowDragChangesPageAfterOneThirdWidth() {
        assertEquals(0, PageSnapPolicy.targetPage(0, 332, 1000, 0f, 1000f, 2));
        assertEquals(1, PageSnapPolicy.targetPage(0, 334, 1000, 0f, 1000f, 2));
        assertEquals(1, PageSnapPolicy.targetPage(1, 668, 1000, 0f, 1000f, 2));
        assertEquals(0, PageSnapPolicy.targetPage(1, 666, 1000, 0f, 1000f, 2));
    }

    @Test public void fastShortSwipeChangesOnePage() {
        assertEquals(1, PageSnapPolicy.targetPage(0, 120, 1000, 2400f, 1000f, 2));
        assertEquals(0, PageSnapPolicy.targetPage(1, 880, 1000, -2400f, 1000f, 2));
    }

    @Test public void targetNeverEscapesAvailablePages() {
        assertEquals(0, PageSnapPolicy.targetPage(0, 0, 1000, -2400f, 1000f, 2));
        assertEquals(1, PageSnapPolicy.targetPage(1, 1000, 1000, 2400f, 1000f, 2));
    }
}
