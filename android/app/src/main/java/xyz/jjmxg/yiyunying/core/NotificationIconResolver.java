package xyz.jjmxg.yiyunying.core;

import android.content.Context;

import androidx.annotation.DrawableRes;

import xyz.jjmxg.yiyunying.R;

public final class NotificationIconResolver {
    private NotificationIconResolver() { }

    @DrawableRes
    public static int smallIcon(Context context) {
        String style = AppearanceStyleStore.icon(context);
        if (AppearanceStyleStore.ICON_MINIMAL.equals(style)) return R.drawable.ic_notification_minimal;
        if (AppearanceStyleStore.ICON_DARK.equals(style)) return R.drawable.ic_notification_dark;
        return R.drawable.ic_notification_default;
    }
}
