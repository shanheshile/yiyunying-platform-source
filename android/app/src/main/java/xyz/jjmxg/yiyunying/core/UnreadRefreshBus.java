package xyz.jjmxg.yiyunying.core;

import android.content.Context;

/**
 * Small in-process unread state bus. A negative count requests a server refresh;
 * a non-negative count is already authoritative and should be rendered immediately.
 */
public final class UnreadRefreshBus {
    public interface Listener {
        void onUnreadChanged(Context context, int notificationUnread);
    }

    private static Listener listener;

    private UnreadRefreshBus() { }

    public static void setListener(Listener l) {
        listener = l;
    }

    public static void clearListener(Listener l) {
        if (listener == l) listener = null;
    }

    public static void requestRefresh(Context context) {
        if (listener != null) listener.onUnreadChanged(context, -1);
    }

    public static void publishNotificationUnread(Context context, int notificationUnread) {
        if (listener != null) {
            listener.onUnreadChanged(context, Math.max(0, notificationUnread));
        }
    }
}
