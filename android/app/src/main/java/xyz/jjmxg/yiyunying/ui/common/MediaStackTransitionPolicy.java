package xyz.jjmxg.yiyunying.ui.common;

import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Set;

/**
 * Pure geometry/order rules for the image and video card stacks.
 *
 * <p>The visible order is circular. Advancing one item therefore moves every
 * visible card to the exact pose previously occupied by its neighbour (the
 * front card reaches the back pose for a three-item stack). Reversing performs
 * the same transition in the opposite direction. When more items exist than
 * visible layers, the entering/leaving card uses one transparent layer just
 * beyond the visible stack instead of making the whole stack fade.</p>
 */
public final class MediaStackTransitionPolicy {
    private static final float SCALE_STEP = 0.035f;
    private static final float ROTATION_DEGREES = 2.2f;

    private MediaStackTransitionPolicy() { }

    public static List<Integer> order(int itemCount, int current, int maxLayers) {
        if (itemCount <= 0 || maxLayers <= 0) return Collections.emptyList();
        int safeCurrent = clamp(current, 0, itemCount - 1);
        int layerCount = Math.min(itemCount, maxLayers);
        List<Integer> result = new ArrayList<>(layerCount);
        for (int layer = 0; layer < layerCount; layer++) {
            result.add((safeCurrent + layer) % itemCount);
        }
        return result;
    }

    public static Pose pose(int layer, int maxLayers, float offsetX, float offsetY) {
        int safeLayer = Math.max(0, layer);
        boolean visible = safeLayer < Math.max(1, maxLayers);
        float scale = Math.max(0.82f, 1f - SCALE_STEP * safeLayer);
        float rotation = safeLayer == 0 ? 0f
            : (safeLayer % 2 == 0 ? -ROTATION_DEGREES : ROTATION_DEGREES);
        return new Pose(safeLayer, safeLayer * offsetX, safeLayer * offsetY,
            scale, rotation, visible ? 1f : 0f,
            Math.max(0f, Math.max(1, maxLayers) - safeLayer));
    }

    public static Transition transition(
        int itemCount,
        int from,
        int to,
        int maxLayers,
        float offsetX,
        float offsetY
    ) {
        if (itemCount <= 0 || maxLayers <= 0) {
            return new Transition(0, 0, 0, Collections.emptyList());
        }
        int safeFrom = clamp(from, 0, itemCount - 1);
        int safeTo = clamp(to, 0, itemCount - 1);
        int direction = Integer.compare(safeTo, safeFrom);
        List<Integer> fromOrder = order(itemCount, safeFrom, maxLayers);
        List<Integer> toOrder = order(itemCount, safeTo, maxLayers);
        Set<Integer> union = new LinkedHashSet<>(fromOrder);
        union.addAll(toOrder);
        List<ItemTransition> items = new ArrayList<>(union.size());
        int hiddenLayer = Math.min(itemCount, maxLayers);
        for (int itemIndex : union) {
            int fromLayer = fromOrder.indexOf(itemIndex);
            int toLayer = toOrder.indexOf(itemIndex);
            Pose start = pose(fromLayer >= 0 ? fromLayer : hiddenLayer,
                maxLayers, offsetX, offsetY);
            Pose end = pose(toLayer >= 0 ? toLayer : hiddenLayer,
                maxLayers, offsetX, offsetY);
            items.add(new ItemTransition(itemIndex, start, end));
        }
        return new Transition(safeFrom, safeTo, direction,
            Collections.unmodifiableList(items));
    }

    private static int clamp(int value, int minimum, int maximum) {
        return Math.max(minimum, Math.min(maximum, value));
    }

    public static final class Pose {
        public final int layer;
        public final float x;
        public final float y;
        public final float scale;
        public final float rotation;
        public final float alpha;
        public final float depth;

        Pose(int layer, float x, float y, float scale, float rotation, float alpha, float depth) {
            this.layer = layer;
            this.x = x;
            this.y = y;
            this.scale = scale;
            this.rotation = rotation;
            this.alpha = alpha;
            this.depth = depth;
        }
    }

    public static final class ItemTransition {
        public final int itemIndex;
        public final Pose start;
        public final Pose end;

        ItemTransition(int itemIndex, Pose start, Pose end) {
            this.itemIndex = itemIndex;
            this.start = start;
            this.end = end;
        }
    }

    public static final class Transition {
        public final int from;
        public final int to;
        public final int direction;
        public final List<ItemTransition> items;

        Transition(int from, int to, int direction, List<ItemTransition> items) {
            this.from = from;
            this.to = to;
            this.direction = direction;
            this.items = items;
        }
    }
}
