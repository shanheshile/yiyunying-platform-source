package xyz.jjmxg.yiyunying.ui.chat;

/** Keeps the function pager's release behavior independent from platform fling differences. */
public final class PageSnapPolicy {
    private PageSnapPolicy() { }

    public static int targetPage(
        int startPage,
        int currentScrollX,
        int pageWidth,
        float velocityX,
        float velocityThreshold,
        int pageCount
    ) {
        int safeCount = Math.max(1, pageCount);
        int safeWidth = Math.max(1, pageWidth);
        int boundedStart = clamp(startPage, 0, safeCount - 1);
        if (Math.abs(velocityX) >= Math.max(1f, velocityThreshold)) {
            return clamp(boundedStart + (velocityX > 0f ? 1 : -1), 0, safeCount - 1);
        }
        double currentPage = Math.max(0, currentScrollX) / (double) safeWidth;
        double movedPages = currentPage - boundedStart;
        if (movedPages >= 1d / 3d) return clamp(boundedStart + 1, 0, safeCount - 1);
        if (movedPages <= -1d / 3d) return clamp(boundedStart - 1, 0, safeCount - 1);
        return boundedStart;
    }

    private static int clamp(int value, int minimum, int maximum) {
        return Math.max(minimum, Math.min(maximum, value));
    }
}
