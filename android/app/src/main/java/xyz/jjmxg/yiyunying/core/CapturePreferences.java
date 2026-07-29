package xyz.jjmxg.yiyunying.core;

import android.content.Context;
import android.content.SharedPreferences;

public final class CapturePreferences {
    private static final String PREFERENCES = "capture_options";
    private static final String SAVE_TO_GALLERY = "save_to_gallery";
    private static final String OPTIMIZE_BEFORE_SEND = "optimize_before_send";

    private CapturePreferences() { }

    public static boolean saveToGallery(Context context) {
        return preferences(context).getBoolean(SAVE_TO_GALLERY, false);
    }

    public static void setSaveToGallery(Context context, boolean enabled) {
        preferences(context).edit().putBoolean(SAVE_TO_GALLERY, enabled).apply();
    }

    public static boolean optimizeBeforeSend(Context context) {
        return preferences(context).getBoolean(OPTIMIZE_BEFORE_SEND, true);
    }

    public static void setOptimizeBeforeSend(Context context, boolean enabled) {
        preferences(context).edit().putBoolean(OPTIMIZE_BEFORE_SEND, enabled).apply();
    }

    private static SharedPreferences preferences(Context context) {
        return context.getApplicationContext().getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE);
    }
}
