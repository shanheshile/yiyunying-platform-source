package xyz.jjmxg.yiyunying.ui.chat;

/**
 * Width policy for dynamic chat media. Values are expressed in dp so the
 * adapter can keep image stacks inside the message column on every density.
 */
final class ChatMediaLayoutPolicy {
    private static final int RECYCLER_HORIZONTAL_PADDING_DP = 24;
    private static final int AVATAR_SLOT_DP = 50;
    private static final int SELECTION_SLOT_DP = 48;

    private ChatMediaLayoutPolicy() { }

    static int contentWidthDp(int viewportWidthDp, boolean selectionMode) {
        int reserved = RECYCLER_HORIZONTAL_PADDING_DP + AVATAR_SLOT_DP
            + (selectionMode ? SELECTION_SLOT_DP : 0);
        return clamp(viewportWidthDp - reserved, 96, 300);
    }

    static StackMetrics stackMetricsDp(int contentWidthDp, int requestedDepth) {
        int depth = clamp(requestedDepth, 1, 3);
        int railWidth = clamp(Math.round(contentWidthDp * 0.20f), 44, 58);
        int railGap = contentWidthDp < 240 ? 6 : 8;
        int layerOffsetX = clamp(Math.round(contentWidthDp / 18f), 8, 17);
        int layerOffsetY = clamp(Math.round(layerOffsetX * 0.88f), 7, 15);
        int availableStage = contentWidthDp - railWidth - railGap
            - (depth - 1) * layerOffsetX;
        int stageWidth = clamp(availableStage, 1, 196);
        int stageHeight = clamp(Math.round(stageWidth * (212f / 196f)), 96, 212);
        return new StackMetrics(
            railWidth, railGap, layerOffsetX, layerOffsetY, stageWidth, stageHeight);
    }

    static int expandedColumnWidthDp(int contentWidthDp) {
        int railWidth = clamp(Math.round(contentWidthDp * 0.20f), 44, 58);
        int railGap = contentWidthDp < 240 ? 6 : 8;
        return Math.max(1, contentWidthDp - railWidth - railGap);
    }

    static int expandedRailWidthDp(int contentWidthDp) {
        return clamp(Math.round(contentWidthDp * 0.20f), 44, 58);
    }

    static int expandedGapDp(int contentWidthDp) {
        return contentWidthDp < 240 ? 6 : 8;
    }

    private static int clamp(int value, int minimum, int maximum) {
        return Math.max(minimum, Math.min(maximum, value));
    }

    static final class StackMetrics {
        final int railWidth;
        final int railGap;
        final int layerOffsetX;
        final int layerOffsetY;
        final int stageWidth;
        final int stageHeight;

        StackMetrics(int railWidth, int railGap, int layerOffsetX, int layerOffsetY,
                     int stageWidth, int stageHeight) {
            this.railWidth = railWidth;
            this.railGap = railGap;
            this.layerOffsetX = layerOffsetX;
            this.layerOffsetY = layerOffsetY;
            this.stageWidth = stageWidth;
            this.stageHeight = stageHeight;
        }

        int totalWidth(int depth) {
            return railWidth + railGap + stageWidth
                + (Math.max(1, Math.min(3, depth)) - 1) * layerOffsetX;
        }
    }
}
