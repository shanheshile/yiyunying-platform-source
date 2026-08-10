package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertTrue;

import java.util.Arrays;
import org.junit.Test;

public final class MediaStackTransitionPolicyTest {
    @Test public void forwardMoveSendsFrontCardToExactBackBoundary() {
        MediaStackTransitionPolicy.Transition move = MediaStackTransitionPolicy.transition(
            3, 0, 1, 3, 17f, 15f);
        assertEquals(Arrays.asList(0, 1, 2), MediaStackTransitionPolicy.order(3, 0, 3));
        assertEquals(Arrays.asList(1, 2, 0), MediaStackTransitionPolicy.order(3, 1, 3));

        MediaStackTransitionPolicy.ItemTransition front = item(move, 0);
        assertEquals(0, front.start.layer);
        assertEquals(2, front.end.layer);
        assertEquals(34f, front.end.x, 0.001f);
        assertEquals(30f, front.end.y, 0.001f);
        assertEquals(1f, front.end.alpha, 0.001f);
    }

    @Test public void backwardMoveIsTheGeometricInverse() {
        MediaStackTransitionPolicy.Transition forward = MediaStackTransitionPolicy.transition(
            3, 0, 1, 3, 17f, 15f);
        MediaStackTransitionPolicy.Transition backward = MediaStackTransitionPolicy.transition(
            3, 1, 0, 3, 17f, 15f);
        assertEquals(1, forward.direction);
        assertEquals(-1, backward.direction);
        for (int index = 0; index < 3; index++) {
            MediaStackTransitionPolicy.ItemTransition first = item(forward, index);
            MediaStackTransitionPolicy.ItemTransition reverse = item(backward, index);
            assertEquals(first.start.x, reverse.end.x, 0.001f);
            assertEquals(first.start.y, reverse.end.y, 0.001f);
            assertEquals(first.end.x, reverse.start.x, 0.001f);
            assertEquals(first.end.y, reverse.start.y, 0.001f);
        }
    }

    @Test public void overflowUsesTransparentBoundaryInsteadOfFadingWholeStack() {
        MediaStackTransitionPolicy.Transition move = MediaStackTransitionPolicy.transition(
            5, 0, 1, 3, 10f, 12f);
        MediaStackTransitionPolicy.ItemTransition leavingFront = item(move, 0);
        MediaStackTransitionPolicy.ItemTransition enteringBack = item(move, 3);
        assertEquals(3, leavingFront.end.layer);
        assertEquals(0f, leavingFront.end.alpha, 0.001f);
        assertEquals(3, enteringBack.start.layer);
        assertEquals(0f, enteringBack.start.alpha, 0.001f);
        assertEquals(2, enteringBack.end.layer);
        assertTrue(enteringBack.end.alpha > enteringBack.start.alpha);
    }

    private static MediaStackTransitionPolicy.ItemTransition item(
        MediaStackTransitionPolicy.Transition transition,
        int itemIndex
    ) {
        for (MediaStackTransitionPolicy.ItemTransition item : transition.items) {
            if (item.itemIndex == itemIndex) return item;
        }
        throw new AssertionError("missing item " + itemIndex);
    }
}
