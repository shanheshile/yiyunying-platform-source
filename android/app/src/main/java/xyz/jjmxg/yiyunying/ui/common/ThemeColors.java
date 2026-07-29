package xyz.jjmxg.yiyunying.ui.common;

import android.content.Context;
import android.util.TypedValue;

import androidx.annotation.AttrRes;
import androidx.annotation.ColorInt;
import androidx.annotation.ColorRes;
import androidx.core.content.ContextCompat;

import xyz.jjmxg.yiyunying.R;

/** Resolves runtime theme colors instead of bypassing the active accent overlay. */
public final class ThemeColors {
    private ThemeColors() { }

    @ColorInt public static int primary(Context context) {
        return resolve(context, androidx.appcompat.R.attr.colorPrimary, R.color.primary);
    }

    @ColorInt public static int primaryContainer(Context context) {
        return resolve(context, com.google.android.material.R.attr.colorPrimaryContainer, R.color.primary_container);
    }

    @ColorInt public static int onPrimary(Context context) {
        return resolve(context, com.google.android.material.R.attr.colorOnPrimary, R.color.on_primary);
    }

    @ColorInt public static int secondary(Context context) {
        return resolve(context, com.google.android.material.R.attr.colorSecondary, R.color.secondary);
    }

    @ColorInt public static int resolve(Context context, @AttrRes int attribute, @ColorRes int fallback) {
        TypedValue value = new TypedValue();
        if (context.getTheme().resolveAttribute(attribute, value, true)) {
            if (value.resourceId != 0) return ContextCompat.getColor(context, value.resourceId);
            return value.data;
        }
        return ContextCompat.getColor(context, fallback);
    }
}
