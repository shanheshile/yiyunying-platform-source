package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public final class MediaStackDragPolicyTest {
    @Test public void foregroundTracksPointerThenEndsOnExactBackBoundary() {
        MediaStackTransitionPolicy.Transition transition =
            MediaStackTransitionPolicy.transition(3, 0, 1, 3, 17f, 15f);

        MediaStackDragPolicy.ItemFrame halfway = frame(
            MediaStackDragPolicy.frames(transition, 0.5f, -40f), 0);
        assertTrue(halfway.foreground);
        assertEquals(-40f, halfway.pose.x, 0.001f);
        assertTrue(halfway.pose.y > 0f && halfway.pose.y < 30f);
        assertTrue(halfway.pose.scale < 1f && halfway.pose.scale > 0.9f);

        java.util.List<MediaStackDragPolicy.ItemFrame> boundary =
            MediaStackDragPolicy.frames(transition, 1f, -80f);
        for (MediaStackTransitionPolicy.ItemTransition item : transition.items) {
            assertPose(item.end, frame(boundary, item.itemIndex).pose);
        }
    }

    @Test public void enteringAndLeavingLayersShareContinuousOpacityAndDepth() {
        MediaStackTransitionPolicy.Transition transition =
            MediaStackTransitionPolicy.transition(5, 0, 1, 3, 10f, 12f);
        MediaStackDragPolicy.ItemFrame leaving = frame(
            MediaStackDragPolicy.frames(transition, 0.5f, -36f), 0);
        MediaStackDragPolicy.ItemFrame entering = frame(
            MediaStackDragPolicy.frames(transition, 0.5f, -36f), 3);

        assertEquals(0.5f, leaving.pose.alpha, 0.001f);
        assertEquals(0.5f, entering.pose.alpha, 0.001f);
        MediaStackTransitionPolicy.ItemTransition leavingPath = item(transition, 0);
        MediaStackTransitionPolicy.ItemTransition enteringPath = item(transition, 3);
        assertEquals((leavingPath.start.depth + leavingPath.end.depth) / 2f,
            leaving.pose.depth, 0.001f);
        assertEquals((enteringPath.start.depth + enteringPath.end.depth) / 2f,
            entering.pose.depth, 0.001f);
        assertEquals((enteringPath.start.y + enteringPath.end.y) / 2f,
            entering.pose.y, 0.001f);
    }

    @Test public void reverseGestureUsesTheExactInverseForSharedLayers() {
        MediaStackTransitionPolicy.Transition forward =
            MediaStackTransitionPolicy.transition(3, 0, 1, 3, 17f, 15f);
        MediaStackTransitionPolicy.Transition backward =
            MediaStackTransitionPolicy.transition(3, 1, 0, 3, 17f, 15f);
        MediaStackDragPolicy.ItemFrame forwardFrame = frame(
            MediaStackDragPolicy.frames(forward, 0.3f, -24f), 2);
        MediaStackDragPolicy.ItemFrame backwardFrame = frame(
            MediaStackDragPolicy.frames(backward, 0.7f, 24f), 2);

        assertPose(forwardFrame.pose, backwardFrame.pose);
        MediaStackDragPolicy.ItemFrame forwardFront = frame(
            MediaStackDragPolicy.frames(forward, 0.5f, -38f), 0);
        MediaStackDragPolicy.ItemFrame backwardFront = frame(
            MediaStackDragPolicy.frames(backward, 0.5f, 38f), 1);
        assertEquals(-forwardFront.pose.x, backwardFront.pose.x, 0.001f);
        assertTrue(forwardFront.foreground);
        assertTrue(backwardFront.foreground);
    }

    @Test public void edgeDragStaysElasticAndProgressIsAlwaysBounded() {
        assertEquals(0f, MediaStackDragPolicy.progress(Float.NaN, 80f), 0f);
        assertEquals(0.5f, MediaStackDragPolicy.progress(-40f, 80f), 0.001f);
        assertEquals(1f, MediaStackDragPolicy.progress(400f, 80f), 0f);

        MediaStackTransitionPolicy.Transition edge =
            MediaStackTransitionPolicy.transition(3, 0, 0, 3, 17f, 15f);
        assertEquals(35f, frame(MediaStackDragPolicy.frames(edge, 0.5f, 35f), 0).pose.x,
            0.001f);
        assertPose(item(edge, 0).end,
            frame(MediaStackDragPolicy.frames(edge, 1f, 80f), 0).pose);
    }

    private static MediaStackDragPolicy.ItemFrame frame(
        java.util.List<MediaStackDragPolicy.ItemFrame> frames,
        int itemIndex
    ) {
        for (MediaStackDragPolicy.ItemFrame frame : frames) {
            if (frame.itemIndex == itemIndex) return frame;
        }
        throw new AssertionError("missing frame " + itemIndex);
    }

    private static MediaStackTransitionPolicy.ItemTransition item(
        MediaStackTransitionPolicy.Transition transition,
        int itemIndex
    ) {
        for (MediaStackTransitionPolicy.ItemTransition item : transition.items) {
            if (item.itemIndex == itemIndex) return item;
        }
        throw new AssertionError("missing transition " + itemIndex);
    }

    private static void assertPose(
        MediaStackTransitionPolicy.Pose expected,
        MediaStackTransitionPolicy.Pose actual
    ) {
        assertEquals(expected.x, actual.x, 0.001f);
        assertEquals(expected.y, actual.y, 0.001f);
        assertEquals(expected.scale, actual.scale, 0.001f);
        assertEquals(expected.rotation, actual.rotation, 0.001f);
        assertEquals(expected.alpha, actual.alpha, 0.001f);
        assertEquals(expected.depth, actual.depth, 0.001f);
    }
}
