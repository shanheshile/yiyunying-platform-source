package xyz.jjmxg.yiyunying.ui.common;

/** Pure direction policy for handing a top-edge scroll gesture back to BottomSheetBehavior. */
final class BottomSheetDragHandoffPolicy {
    private BottomSheetDragHandoffPolicy() { }

    static boolean shouldReleaseParent(boolean gestureStart, float previousY, float currentY,
                                       boolean contentCanScrollUp) {
        if (contentCanScrollUp) return false;
        return gestureStart || currentY > previousY;
    }
}
