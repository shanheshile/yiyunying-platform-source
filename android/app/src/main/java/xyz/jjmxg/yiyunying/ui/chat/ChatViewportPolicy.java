package xyz.jjmxg.yiyunying.ui.chat;

final class ChatViewportPolicy {
    private ChatViewportPolicy() { }

    static boolean isAtLatest(int lastVisiblePosition, int itemCount) {
        return itemCount <= 0 || lastVisiblePosition >= itemCount - 1;
    }

    static int nextPendingCount(int current, int added, boolean wasAtLatest) {
        if (wasAtLatest) return 0;
        long total = (long) Math.max(0, current) + Math.max(0, added);
        return total > Integer.MAX_VALUE ? Integer.MAX_VALUE : (int) total;
    }

    /** Avoids a RecyclerView layout pass after a poll that returned no visual changes. */
    static boolean shouldFollowLatest(boolean firstLoad, boolean messagesChanged, boolean wasAtLatest) {
        return firstLoad || (messagesChanged && wasAtLatest);
    }
}
