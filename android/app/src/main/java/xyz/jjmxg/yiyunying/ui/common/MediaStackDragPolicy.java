package xyz.jjmxg.yiyunying.ui.common;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;

/**
 * Pure, frame-by-frame geometry for an interactive media-stack drag.
 *
 * <p>Every layer uses the same eased progress, so translation, scale, rotation,
 * opacity and depth reach the neighbouring layer boundary together. The card
 * that started in front follows the pointer one-to-one for most of the drag,
 * then converges continuously on its destination pose near the boundary. That
 * convergence removes the release-time jump while keeping forward and reverse
 * gestures governed by the same rules.</p>
 */
public final class MediaStackDragPolicy {
    private static final float FOLLOW_CONVERGENCE_START = 0.72f;

    private MediaStackDragPolicy() { }

    public static float progress(float dragTranslationX, float boundaryDistance) {
        float distance = Math.max(1f, Math.abs(boundaryDistance));
        if (!Float.isFinite(dragTranslationX)) return 0f;
        return clamp(Math.abs(dragTranslationX) / distance, 0f, 1f);
    }

    public static List<ItemFrame> frames(
        MediaStackTransitionPolicy.Transition transition,
        float rawProgress,
        float dragTranslationX
    ) {
        if (transition == null || transition.items.isEmpty()) return Collections.emptyList();
        float progress = clamp(rawProgress, 0f, 1f);
        float eased = smoothStep(progress);
        float followWeight = foregroundFollowWeight(progress);
        List<ItemFrame> frames = new ArrayList<>(transition.items.size());
        for (MediaStackTransitionPolicy.ItemTransition item : transition.items) {
            MediaStackTransitionPolicy.Pose start = item.start;
            MediaStackTransitionPolicy.Pose end = item.end;
            float x = lerp(start.x, end.x, eased);
            boolean foreground = start.layer == 0;
            if (foreground) {
                // Stay exactly under the pointer until the final part of the gesture. Near the
                // boundary, blend onto the destination x so release can continue without a jump.
                x = lerp(x, finiteOrZero(dragTranslationX), followWeight);
            }
            MediaStackTransitionPolicy.Pose pose = new MediaStackTransitionPolicy.Pose(
                Math.round(lerp(start.layer, end.layer, eased)),
                x,
                lerp(start.y, end.y, eased),
                lerp(start.scale, end.scale, eased),
                lerp(start.rotation, end.rotation, eased),
                lerp(start.alpha, end.alpha, eased),
                lerp(start.depth, end.depth, eased)
            );
            frames.add(new ItemFrame(item.itemIndex, pose, foreground));
        }
        return Collections.unmodifiableList(frames);
    }

    static float foregroundFollowWeight(float rawProgress) {
        float progress = clamp(rawProgress, 0f, 1f);
        if (progress <= FOLLOW_CONVERGENCE_START) return 1f;
        float convergence = (progress - FOLLOW_CONVERGENCE_START)
            / (1f - FOLLOW_CONVERGENCE_START);
        return 1f - smoothStep(convergence);
    }

    private static float smoothStep(float value) {
        float clamped = clamp(value, 0f, 1f);
        return clamped * clamped * (3f - 2f * clamped);
    }

    private static float lerp(float start, float end, float progress) {
        return start + (end - start) * progress;
    }

    private static float finiteOrZero(float value) {
        return Float.isFinite(value) ? value : 0f;
    }

    private static float clamp(float value, float minimum, float maximum) {
        if (!Float.isFinite(value)) return minimum;
        return Math.max(minimum, Math.min(maximum, value));
    }

    public static final class ItemFrame {
        public final int itemIndex;
        public final MediaStackTransitionPolicy.Pose pose;
        public final boolean foreground;

        ItemFrame(int itemIndex, MediaStackTransitionPolicy.Pose pose, boolean foreground) {
            this.itemIndex = itemIndex;
            this.pose = pose;
            this.foreground = foreground;
        }
    }
}
