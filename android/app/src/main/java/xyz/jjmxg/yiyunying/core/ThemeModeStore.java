package xyz.jjmxg.yiyunying.core;

import android.content.Context;
import android.content.SharedPreferences;

import androidx.appcompat.app.AppCompatDelegate;

public final class ThemeModeStore {
    public static final String SYSTEM = "system";
    public static final String LIGHT = "light";
    public static final String DARK = "dark";
    private static final String PREFERENCES = "appearance";
    private static final String KEY_MODE = "theme_mode";

    private ThemeModeStore() { }

    public static String get(Context context) {
        return preferences(context).getString(KEY_MODE, SYSTEM);
    }

    public static void set(Context context, String mode) {
        String safe = DARK.equals(mode) || LIGHT.equals(mode) ? mode : SYSTEM;
        preferences(context).edit().putString(KEY_MODE, safe).apply();
        AppCompatDelegate.setDefaultNightMode(delegateMode(safe));
    }

    public static void apply(Context context) {
        AppCompatDelegate.setDefaultNightMode(delegateMode(get(context)));
    }

    public static String label(Context context) {
        String mode = get(context);
        if (DARK.equals(mode)) return "深色模式";
        if (LIGHT.equals(mode)) return "浅色模式";
        return "跟随系统";
    }

    private static int delegateMode(String mode) {
        if (DARK.equals(mode)) return AppCompatDelegate.MODE_NIGHT_YES;
        if (LIGHT.equals(mode)) return AppCompatDelegate.MODE_NIGHT_NO;
        return AppCompatDelegate.MODE_NIGHT_FOLLOW_SYSTEM;
    }

    private static SharedPreferences preferences(Context context) {
        return context.getApplicationContext().getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE);
    }
}
