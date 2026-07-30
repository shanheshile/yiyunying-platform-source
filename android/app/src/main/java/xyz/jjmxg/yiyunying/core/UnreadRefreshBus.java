package xyz.jjmxg.yiyunying.core;

import android.content.Context;

/**
 * Minimal in-process bus used to ask the home shell to re-query unread badges
 * after notifications are marked read elsewhere (e.g. inside NotificationCenterFragment).
 * Kept dependency-free so it compiles on the project's current toolchain.
 */
public final class UnreadRefreshBus {
    public interface Listener {
        void onRefreshRequested(Context context);
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
        if (listener != null) listener.onRefreshRequested(context);
    }
}
