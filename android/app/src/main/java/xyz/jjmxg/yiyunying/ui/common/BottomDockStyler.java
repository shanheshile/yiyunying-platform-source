package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.content.res.Configuration;
import android.os.Build;
import android.view.View;
import android.view.ViewGroup;
import android.widget.TextView;

import com.google.android.material.bottomnavigation.BottomNavigationView;
import com.google.android.material.navigation.NavigationBarView;

import java.util.Locale;

/** Keeps bottom navigation labels visible across OEM font and display scaling. */
public final class BottomDockStyler {
    private static final int BASE_HEIGHT_DP = 68;
    private static final int MAX_HEIGHT_DP = 78;
    private static final int XIAOMI_EXTRA_HEIGHT_DP = 6;

    private BottomDockStyler() { }

    public static void apply(BottomNavigationView navigation) {
        Context context = navigation.getContext();
        Configuration configuration = context.getResources().getConfiguration();
        boolean xiaomiTextMetrics = needsXiaomiTextMetrics(Build.MANUFACTURER, Build.BRAND);
        int heightDp = heightDp(configuration.fontScale, xiaomiTextMetrics);

        ViewGroup.LayoutParams params = navigation.getLayoutParams();
        if (params != null) {
            params.height = dp(context, heightDp);
            navigation.setLayoutParams(params);
        }
        navigation.setMinimumHeight(dp(context, heightDp));
        navigation.setLabelVisibilityMode(NavigationBarView.LABEL_VISIBILITY_LABELED);
        navigation.setItemHorizontalTranslationEnabled(false);
        navigation.setItemIconSize(dp(context, 22));
        navigation.setLabelMaxLines(1);
        navigation.setLabelFontScalingEnabled(true);
        navigation.setItemPaddingTop(dp(context, xiaomiTextMetrics ? 3 : 4));
        navigation.setItemPaddingBottom(dp(context, xiaomiTextMetrics ? 7 : 4));
        navigation.setActiveIndicatorLabelPadding(dp(context, 1));
        navigation.setPadding(navigation.getPaddingLeft(), 0, navigation.getPaddingRight(), 0);
        navigation.setClipToPadding(false);
        if (navigation.getParent() instanceof ViewGroup) {
            ((ViewGroup) navigation.getParent()).setClipChildren(false);
        }
        navigation.post(() -> normalizeLabels(navigation, navigation, xiaomiTextMetrics));
        if (xiaomiTextMetrics) {
            navigation.postDelayed(() -> normalizeLabels(navigation, navigation, true), 120L);
        }
    }

    private static void normalizeLabels(BottomNavigationView navigation, View view,
                                        boolean xiaomiTextMetrics) {
        if (view instanceof TextView && isMenuLabel(navigation, (TextView) view)) {
            TextView label = (TextView) view;
            label.setVisibility(View.VISIBLE);
            label.setMaxLines(1);
            label.setSingleLine(true);
            label.setIncludeFontPadding(xiaomiTextMetrics);
            if (xiaomiTextMetrics) {
                label.setMinHeight(Math.max(label.getMinHeight(), labelMinHeightPx(label)));
                unclampAncestors(label, navigation);
            }
            label.setAlpha(1f);
        }
        if (!(view instanceof ViewGroup)) return;
        ViewGroup group = (ViewGroup) view;
        for (int index = 0; index < group.getChildCount(); index++) {
            normalizeLabels(navigation, group.getChildAt(index), xiaomiTextMetrics);
        }
    }

    static boolean needsXiaomiTextMetrics(String manufacturer, String brand) {
        String identity = ((manufacturer == null ? "" : manufacturer) + " "
            + (brand == null ? "" : brand)).toLowerCase(Locale.ROOT);
        return identity.contains("xiaomi") || identity.contains("redmi") || identity.contains("poco");
    }

    static int heightDp(float fontScale, boolean xiaomiTextMetrics) {
        float safeScale = Math.max(1f, fontScale);
        int scaled = BASE_HEIGHT_DP + Math.round((safeScale - 1f) * 16f);
        int normal = Math.min(MAX_HEIGHT_DP, scaled);
        return normal + (xiaomiTextMetrics ? XIAOMI_EXTRA_HEIGHT_DP : 0);
    }

    private static int labelMinHeightPx(TextView label) {
        int fontHeight = label.getPaint().getFontMetricsInt(null);
        return fontHeight + dp(label.getContext(), 4);
    }

    private static void unclampAncestors(View child, View stopAt) {
        android.view.ViewParent parent = child.getParent();
        while (parent instanceof ViewGroup) {
            ViewGroup group = (ViewGroup) parent;
            group.setClipChildren(false);
            group.setClipToPadding(false);
            if (group == stopAt) break;
            parent = group.getParent();
        }
    }

    private static boolean isMenuLabel(BottomNavigationView navigation, TextView candidate) {
        CharSequence value = candidate.getText();
        if (value == null || value.length() == 0) return false;
        for (int index = 0; index < navigation.getMenu().size(); index++) {
            CharSequence title = navigation.getMenu().getItem(index).getTitle();
            if (title != null && title.toString().contentEquals(value)) return true;
        }
        return false;
    }

    private static int dp(Context context, int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }
}
