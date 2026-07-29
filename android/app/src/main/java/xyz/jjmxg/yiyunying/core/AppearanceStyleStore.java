package xyz.jjmxg.yiyunying.core;

import android.app.Activity;
import android.content.ComponentName;
import android.content.Context;
import android.content.pm.PackageManager;
import android.graphics.Typeface;
import android.os.Build;
import android.view.View;
import android.view.ViewGroup;
import android.widget.TextView;

import androidx.appcompat.app.AppCompatDelegate;
import androidx.core.os.LocaleListCompat;

import java.util.ArrayList;
import java.util.List;

import xyz.jjmxg.yiyunying.R;

public final class AppearanceStyleStore {
    public static final String BLUE = "blue";
    public static final String TEAL = "teal";
    public static final String ROSE = "rose";
    public static final String ICON_DEFAULT = "default";
    public static final String ICON_MINIMAL = "minimal";
    public static final String ICON_DARK = "dark";
    public static final String FONT_SYSTEM = "system";
    public static final String FONT_SANS = "sans";
    public static final String FONT_SERIF = "serif";
    public static final String FONT_MONOSPACE = "monospace";

    public static final String PREFERENCES = "appearance";
    public static final String KEY_ACCENT = "accent";
    public static final String KEY_ICON = "app_icon";
    public static final String KEY_FONT = "font_family";
    public static final String KEY_LANGUAGE = "language";

    private AppearanceStyleStore() { }

    public static String accent(Context context) {
        return preferences(context).getString(KEY_ACCENT, BLUE);
    }

    public static void setAccent(Context context, String value) {
        String safe = TEAL.equals(value) || ROSE.equals(value) ? value : BLUE;
        preferences(context).edit().putString(KEY_ACCENT, safe).apply();
    }

    public static String language(Context context) {
        String value = preferences(context).getString(KEY_LANGUAGE, "zh-CN");
        if (value != null && value.startsWith("en")) return "en";
        if (value != null && value.startsWith("ja")) return "ja";
        return "zh-CN";
    }

    /** Persists and applies the locale through one path so every activity receives it. */
    public static void setLanguage(Context context, String value) {
        String safe = value != null && value.startsWith("en") ? "en"
            : (value != null && value.startsWith("ja") ? "ja" : "zh-CN");
        preferences(context).edit().putString(KEY_LANGUAGE, safe).apply();
        AppCompatDelegate.setApplicationLocales(LocaleListCompat.forLanguageTags(safe));
    }

    public static void applyAccent(Activity activity) {
        int overlay = R.style.ThemeOverlay_Yiyunying_Accent_Blue;
        if (TEAL.equals(accent(activity))) overlay = R.style.ThemeOverlay_Yiyunying_Accent_Teal;
        else if (ROSE.equals(accent(activity))) overlay = R.style.ThemeOverlay_Yiyunying_Accent_Rose;
        activity.getTheme().applyStyle(overlay, true);
    }

    /** Applies the selected font to the activity theme so dialogs and inflated views inherit it. */
    public static void applyFontTheme(Activity activity) {
        int overlay = R.style.ThemeOverlay_Yiyunying_Font_System;
        String value = font(activity);
        if (FONT_SANS.equals(value)) overlay = R.style.ThemeOverlay_Yiyunying_Font_Sans;
        else if (FONT_SERIF.equals(value)) overlay = R.style.ThemeOverlay_Yiyunying_Font_Serif;
        else if (FONT_MONOSPACE.equals(value)) overlay = R.style.ThemeOverlay_Yiyunying_Font_Monospace;
        activity.getTheme().applyStyle(overlay, true);
    }

    public static String icon(Context context) {
        return normalizeIcon(preferences(context).getString(KEY_ICON, ICON_DEFAULT));
    }

    public static boolean setIcon(Context context, String value) {
        String safe = normalizeIcon(value);
        try {
            PackageManager manager = context.getPackageManager();
            ComponentName selected = component(context, aliasFor(safe));
            if (!componentExists(manager, selected)) return false;

            String[] aliases = {"DefaultLauncher", "MinimalLauncher", "DarkLauncher"};
            boolean changed = setComponentsAtomically(context, manager, selected, aliases);
            if (!changed) {
                // Older Android versions have no batch API. Enable the new launcher first so
                // there is never a moment without an enabled home-screen entry.
                if (!setComponent(manager, selected, true)) return false;
                for (String alias : aliases) {
                    ComponentName component = component(context, alias);
                    if (!component.equals(selected) && componentExists(manager, component)) {
                        setComponent(manager, component, false);
                    }
                }
            }
            // Some vendor launchers accept the component request without actually changing
            // the package state. Verify all three aliases before telling the user it worked.
            if (!isIconApplied(context, safe)) {
                if (!setComponent(manager, selected, true)) return false;
                for (String alias : aliases) {
                    ComponentName component = component(context, alias);
                    if (!component.equals(selected) && componentExists(manager, component)) {
                        if (!setComponent(manager, component, false)) return false;
                    }
                }
            }
            if (!isIconApplied(context, safe)) return false;
            return preferences(context).edit().putString(KEY_ICON, safe).commit();
        } catch (RuntimeException exception) {
            CrashReporter.record("修复桌面入口", exception);
            return false;
        }
    }

    /** Returns true only when the selected alias is the sole enabled launcher entry. */
    static boolean isIconApplied(Context context, String value) {
        String safe = normalizeIcon(value);
        String selectedAlias = aliasFor(safe);
        PackageManager manager = context.getPackageManager();
        try {
            for (String alias : new String[]{"DefaultLauncher", "MinimalLauncher", "DarkLauncher"}) {
                ComponentName candidate = component(context, alias);
                if (!componentExists(manager, candidate)) return false;
                boolean enabled = isLauncherEnabled(manager, candidate, alias);
                if (alias.equals(selectedAlias) != enabled) return false;
            }
            return true;
        } catch (RuntimeException exception) {
            return false;
        }
    }

    /** Repairs launcher state left by older flavor/debug package names without crashing startup. */
    public static void repairLauncherState(Context context) {
        String selectedIcon = icon(context);
        String selectedAlias = aliasFor(selectedIcon);
        try {
            PackageManager manager = context.getPackageManager();
            ComponentName selected = component(context, selectedAlias);
            if (!componentExists(manager, selected)) {
                preferences(context).edit().putString(KEY_ICON, ICON_DEFAULT).apply();
                return;
            }
            boolean needsRepair = !isLauncherEnabled(manager, selected, selectedAlias);
            for (String alias : new String[]{"DefaultLauncher", "MinimalLauncher", "DarkLauncher"}) {
                if (alias.equals(selectedAlias)) continue;
                ComponentName other = component(context, alias);
                if (componentExists(manager, other) && isLauncherEnabled(manager, other, alias)) {
                    needsRepair = true;
                    break;
                }
            }
            if (needsRepair) setIcon(context, selectedIcon);
        } catch (RuntimeException exception) {
            // A launcher/ROM bug must never prevent LoginActivity from opening.
            CrashReporter.record("检查桌面入口", exception);
        }
    }

    private static boolean isLauncherEnabled(
        PackageManager manager,
        ComponentName component,
        String alias
    ) {
        int state = manager.getComponentEnabledSetting(component);
        return state == PackageManager.COMPONENT_ENABLED_STATE_ENABLED
            || (state == PackageManager.COMPONENT_ENABLED_STATE_DEFAULT
                && "DefaultLauncher".equals(alias));
    }

    private static boolean setComponentsAtomically(
        Context context,
        PackageManager manager,
        ComponentName selected,
        String[] aliases
    ) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return false;
        try {
            List<PackageManager.ComponentEnabledSetting> settings = new ArrayList<>();
            for (String alias : aliases) {
                ComponentName component = component(context, alias);
                if (!componentExists(manager, component)) continue;
                settings.add(new PackageManager.ComponentEnabledSetting(
                    component,
                    component.equals(selected)
                        ? PackageManager.COMPONENT_ENABLED_STATE_ENABLED
                        : PackageManager.COMPONENT_ENABLED_STATE_DISABLED,
                    componentChangeFlags()
                ));
            }
            if (settings.isEmpty()) return false;
            manager.setComponentEnabledSettings(settings);
            return true;
        } catch (RuntimeException exception) {
            return false;
        }
    }

    public static String font(Context context) {
        return preferences(context).getString(KEY_FONT, FONT_SYSTEM);
    }

    public static void setFont(Context context, String value) {
        String safe = FONT_SANS.equals(value) || FONT_SERIF.equals(value)
            || FONT_MONOSPACE.equals(value) ? value : FONT_SYSTEM;
        preferences(context).edit().putString(KEY_FONT, safe).apply();
    }

    public static String fontLabel(Context context) {
        String value = font(context);
        if (FONT_SANS.equals(value)) return "现代无衬线";
        if (FONT_SERIF.equals(value)) return "阅读衬线";
        if (FONT_MONOSPACE.equals(value)) return "等宽字体";
        return "系统默认";
    }

    public static void applyFontTree(Context context, View root) {
        if (root == null) return;
        // The system option must leave each widget's theme/XML typeface intact.  A font
        // change recreates the activity, so returning here also restores Material's
        // original weights instead of flattening every TextView to sans-serif.
        if (FONT_SYSTEM.equals(font(context))) return;
        if (root instanceof TextView) {
            TextView textView = (TextView) root;
            Typeface current = textView.getTypeface();
            int style = current == null ? Typeface.NORMAL : current.getStyle();
            Typeface desired = Typeface.create(fontFamily(context), style);
            if (!desired.equals(current)) textView.setTypeface(desired);
        }
        if (root instanceof ViewGroup) {
            ViewGroup group = (ViewGroup) root;
            for (int index = 0; index < group.getChildCount(); index++) {
                applyFontTree(context, group.getChildAt(index));
            }
        }
    }

    private static String fontFamily(Context context) {
        String value = font(context);
        if (FONT_SANS.equals(value)) return "sans-serif";
        if (FONT_SERIF.equals(value)) return "serif";
        if (FONT_MONOSPACE.equals(value)) return "monospace";
        return "sans-serif";
    }

    private static String aliasFor(String icon) {
        if (ICON_MINIMAL.equals(icon)) return "MinimalLauncher";
        if (ICON_DARK.equals(icon)) return "DarkLauncher";
        return "DefaultLauncher";
    }

    private static ComponentName component(Context context, String alias) {
        return new ComponentName(context.getPackageName(), launcherClassName(context, alias));
    }

    static String launcherClassName(Context context, String alias) {
        return context.getPackageName() + ".launcher." + alias;
    }

    private static String normalizeIcon(String value) {
        return ICON_MINIMAL.equals(value) || ICON_DARK.equals(value) ? value : ICON_DEFAULT;
    }

    @SuppressWarnings("deprecation")
    private static boolean componentExists(PackageManager manager, ComponentName component) {
        try {
            // The int overload remains available on Android 16 and is supported by
            // Robolectric, unlike the API 33 flags overload in current test tooling.
            manager.getActivityInfo(component, PackageManager.MATCH_DISABLED_COMPONENTS);
            return true;
        } catch (PackageManager.NameNotFoundException | RuntimeException exception) {
            return false;
        }
    }

    private static boolean setComponent(
        PackageManager manager,
        ComponentName component,
        boolean enabled
    ) {
        try {
            manager.setComponentEnabledSetting(
                component,
                enabled ? PackageManager.COMPONENT_ENABLED_STATE_ENABLED
                    : PackageManager.COMPONENT_ENABLED_STATE_DISABLED,
                componentChangeFlags()
            );
            return true;
        } catch (RuntimeException exception) {
            return false;
        }
    }

    private static int componentChangeFlags() {
        int flags = PackageManager.DONT_KILL_APP;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            flags |= PackageManager.SYNCHRONOUS;
        }
        return flags;
    }

    private static android.content.SharedPreferences preferences(Context context) {
        return context.getApplicationContext().getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE);
    }
}
