package xyz.jjmxg.yiyunying.domain.forum;

import static org.junit.Assert.assertEquals;

import org.junit.Test;

public final class ForumSortPolicyTest {
    @Test public void unknownSortFailsClosedToComprehensive() {
        assertEquals(ForumSortPolicy.COMPREHENSIVE, ForumSortPolicy.normalize(null));
        assertEquals(ForumSortPolicy.COMPREHENSIVE, ForumSortPolicy.normalize("sql desc"));
        assertEquals("综合排序", ForumSortPolicy.label("unknown"));
    }

    @Test public void everyApiValueHasChineseLabelAndStableIndex() {
        String[] expected = {"综合排序", "热度优先", "最新优先", "最早优先"};
        assertEquals(expected.length, ForumSortPolicy.labels().length);
        for (int index = 0; index < expected.length; index++) {
            String value = ForumSortPolicy.valueAt(index);
            assertEquals(index, ForumSortPolicy.selectedIndex(value));
            assertEquals(expected[index], ForumSortPolicy.label(value));
        }
    }
}
