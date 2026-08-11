package xyz.jjmxg.yiyunying.domain.forum;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public final class ForumThreadPaginationPolicyTest {
    @Test public void pageIsAlwaysClampedToAnExistingChinesePage() {
        assertEquals(1, ForumThreadPaginationPolicy.page(0, 0));
        assertEquals(3, ForumThreadPaginationPolicy.page(8, 3));
        assertEquals("第 2/3 页", ForumThreadPaginationPolicy.label(2, 3));
    }

    @Test public void navigationOnlyOffersExistingNeighbors() {
        assertFalse(ForumThreadPaginationPolicy.canPrevious(1));
        assertTrue(ForumThreadPaginationPolicy.canNext(1, 2));
        assertTrue(ForumThreadPaginationPolicy.canPrevious(2));
        assertFalse(ForumThreadPaginationPolicy.canNext(2, 2));
    }
}
