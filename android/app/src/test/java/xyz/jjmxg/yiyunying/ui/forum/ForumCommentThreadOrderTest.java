package xyz.jjmxg.yiyunying.ui.forum;

import static org.junit.Assert.assertEquals;

import java.util.Arrays;
import java.util.List;
import org.junit.Test;

public final class ForumCommentThreadOrderTest {
    @Test
    public void replyStaysWithItsRootWhenAnotherTopLevelCommentArrives() {
        List<ForumCommentThreadOrder.CommentRef> comments = Arrays.asList(
            ref(0, 1, 0, 1),
            ref(1, 2, 0, 2),
            ref(2, 4, 1, 1),
            ref(3, 5, 0, 5)
        );

        assertEquals(Arrays.asList(0, 2, 1, 3), ForumCommentThreadOrder.orderedIndexes(comments));
    }

    @Test
    public void nestedRepliesStayInsideTheOriginalThread() {
        List<ForumCommentThreadOrder.CommentRef> comments = Arrays.asList(
            ref(0, 1, 0, 1),
            ref(1, 2, 0, 2),
            ref(2, 3, 1, 1),
            ref(3, 4, 3, 1)
        );

        assertEquals(Arrays.asList(0, 2, 3, 1), ForumCommentThreadOrder.orderedIndexes(comments));
    }

    @Test
    public void legacyRowsResolveTheirRootFromTheParentChain() {
        List<ForumCommentThreadOrder.CommentRef> comments = Arrays.asList(
            ref(0, 1, 0, 0),
            ref(1, 2, 0, 0),
            ref(2, 3, 1, 0),
            ref(3, 4, 3, 0)
        );

        assertEquals(Arrays.asList(0, 2, 3, 1), ForumCommentThreadOrder.orderedIndexes(comments));
    }

    @Test
    public void partialPagesKeepOrphansInsteadOfDroppingThem() {
        List<ForumCommentThreadOrder.CommentRef> comments = Arrays.asList(
            ref(0, 10, 9, 9),
            ref(1, 11, 0, 11)
        );

        assertEquals(Arrays.asList(1, 0), ForumCommentThreadOrder.orderedIndexes(comments));
    }

    private static ForumCommentThreadOrder.CommentRef ref(int index, long id, long parentId, long rootId) {
        return new ForumCommentThreadOrder.CommentRef(index, id, parentId, rootId);
    }
}
