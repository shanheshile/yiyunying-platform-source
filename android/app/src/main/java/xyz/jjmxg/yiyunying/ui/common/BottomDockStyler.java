package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.content.res.Configuration;
import android.view.View;
import android.view.ViewGroup;
import android.widget.TextView;

import com.google.android.material.bottomnavigation.BottomNavigationView;
import com.google.android.material.navigation.NavigationBarView;

/** Keeps bottom navigation labels visible across OEM font and display scaling. */
public final class BottomDockStyler {
    private static final int BASE_HEIGHT_DP = 68;
    private static final int MAX_HEIGHT_DP = 78;

    private BottomDockStyler() { }

    public static void apply(BottomNavigationView navigation) {
        Context context = navigation.getContext();
        Configuration configuration = context.getResources().getConfiguration();
        float extraScale = Math.max(0f, configuration.fontScale - 1f);
        int heightDp = Math.min(MAX_HEIGHT_DP, BASE_HEIGHT_DP + Math.round(extraScale * 16f));

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
        navigation.setItemPaddingTop(dp(context, 4));
        navigation.setItemPaddingBottom(dp(context, 4));
        navigation.setActiveIndicatorLabelPadding(dp(context, 1));
        navigation.setPadding(navigation.getPaddingLeft(), 0, navigation.getPaddingRight(), 0);
        navigation.setClipToPadding(false);
        if (navigation.getParent() instanceof ViewGroup) {
            ((ViewGroup) navigation.getParent()).setClipChildren(false);
        }
        navigation.post(() -> normalizeLabels(navigation, navigation));
    }

    private static void normalizeLabels(BottomNavigationView navigation, View view) {
        if (view instanceof TextView && isMenuLabel(navigation, (TextView) view)) {
            TextView label = (TextView) view;
            label.setVisibility(View.VISIBLE);
            label.setMaxLines(1);
            label.setSingleLine(true);
            label.setIncludeFontPadding(false);
            label.setAlpha(1f);
        }
        if (!(view instanceof ViewGroup)) return;
        ViewGroup group = (ViewGroup) view;
        for (int index = 0; index < group.getChildCount(); index++) {
            normalizeLabels(navigation, group.getChildAt(index));
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
