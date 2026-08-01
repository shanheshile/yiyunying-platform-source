package xyz.jjmxg.yiyunying.ui.chat;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public final class ChatTimelineFormatterTest {
    @Test public void fiveMinuteBoundaryCreatesNode() {
        assertFalse(ChatTimelineFormatter.shouldShow("2026-08-01 07:30:00", "2026-08-01 07:34:59"));
        assertTrue(ChatTimelineFormatter.shouldShow("2026-08-01 07:30:00", "2026-08-01 07:35:00"));
    }

    @Test public void dayBoundaryAlwaysCreatesNode() {
        assertTrue(ChatTimelineFormatter.shouldShow("2026-08-01 23:59:00", "2026-08-02 00:01:00"));
    }

    @Test public void firstValidMessageCreatesNode() {
        assertTrue(ChatTimelineFormatter.shouldShow("", "2026-08-01 07:30:00"));
    }

    @Test public void detailedLabelContainsYearDateWeekdayAndTime() {
        long value = ChatTimelineFormatter.parseMillis("2026-08-01 07:36:00");
        String label = ChatTimelineFormatter.label("2026-08-01 07:36:00", value, true);
        assertTrue(label.contains("2026年"));
        assertTrue(label.contains("8月1日"));
        assertTrue(label.contains("07:36"));
    }
}
