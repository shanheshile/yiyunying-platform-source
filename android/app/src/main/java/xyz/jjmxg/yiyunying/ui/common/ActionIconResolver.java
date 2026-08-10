package xyz.jjmxg.yiyunying.ui.common;

import android.content.res.ColorStateList;
import android.text.TextUtils;
import android.widget.TextView;

import androidx.annotation.DrawableRes;
import androidx.core.content.ContextCompat;
import androidx.core.widget.TextViewCompat;

import com.google.android.material.button.MaterialButton;

import java.util.Locale;

import xyz.jjmxg.yiyunying.R;

/** Assigns one semantic icon to common content actions across user and management UIs. */
public final class ActionIconResolver {
    private ActionIconResolver() { }

    @DrawableRes
    public static int resolve(String label, @DrawableRes int fallback) {
        String value = label == null ? "" : label.trim().toLowerCase(Locale.ROOT);
        if (value.isEmpty()) return fallback;
        if (containsAny(value, "删除", "移除", "清空", "清理", "清除", "回收站")) {
            return R.drawable.ic_delete;
        }
        if (value.contains("收藏")) return R.drawable.ic_favorite;
        if (containsAny(value, "转发", "分享")) return R.drawable.ic_forward;
        if (containsAny(value, "点赞", "已赞", "取消赞") || value.equals("赞") || value.startsWith("赞 ")) {
            return R.drawable.ic_like;
        }
        if (value.contains("评论")) return R.drawable.ic_comment;
        if (value.contains("回复")) return R.drawable.ic_reply;
        return fallback;
    }

    public static boolean destructive(String label) {
        String value = label == null ? "" : label.trim();
        return containsAny(value, "删除", "移除", "清空", "清理", "清除", "回收站");
    }

    /**
     * Applies a semantic icon without replacing the visible label or count. Outlined/text
     * destructive actions use the error color; callers with a filled destructive action can use
     * {@link #apply(MaterialButton, String, int, boolean)}.
     */
    public static void apply(MaterialButton button, String semanticLabel,
                             @DrawableRes int fallback) {
        apply(button, semanticLabel, fallback, false);
    }

    public static void apply(MaterialButton button, String semanticLabel,
                             @DrawableRes int fallback, boolean filled) {
        if (button == null) return;
        int icon = resolve(semanticLabel, fallback);
        boolean danger = destructive(semanticLabel);
        if (icon != 0) {
            button.setIconResource(icon);
            button.setIconPadding(dp(button, 6));
            int tint = danger && filled
                ? ThemeColors.resolve(button.getContext(),
                    com.google.android.material.R.attr.colorOnError, R.color.white)
                : (danger ? ContextCompat.getColor(button.getContext(), R.color.error)
                    : (filled ? ThemeColors.onPrimary(button.getContext())
                        : ThemeColors.primary(button.getContext())));
            button.setIconTint(ColorStateList.valueOf(tint));
        }
        button.setContentDescription(description(semanticLabel, button.getText()));
        if (danger) {
            if (filled) {
                button.setBackgroundTintList(ColorStateList.valueOf(
                    ContextCompat.getColor(button.getContext(), R.color.error)));
                button.setTextColor(ThemeColors.resolve(button.getContext(),
                    com.google.android.material.R.attr.colorOnError, R.color.white));
            } else {
                button.setTextColor(ContextCompat.getColor(button.getContext(), R.color.error));
            }
        }
    }

    /** Styles clickable dialog rows and platform dialog buttons that are plain TextViews. */
    public static void apply(TextView view, String semanticLabel, @DrawableRes int fallback) {
        if (view == null) return;
        if (view instanceof MaterialButton) {
            apply((MaterialButton) view, semanticLabel, fallback);
            return;
        }
        int icon = resolve(semanticLabel, fallback);
        if (icon != 0 && view.getCompoundDrawablesRelative()[0] == null) {
            view.setCompoundDrawablesRelativeWithIntrinsicBounds(icon, 0, 0, 0);
            view.setCompoundDrawablePadding(dp(view, 10));
        }
        if (icon != 0) {
            TextViewCompat.setCompoundDrawableTintList(view, ColorStateList.valueOf(
                destructive(semanticLabel)
                    ? ContextCompat.getColor(view.getContext(), R.color.error)
                    : ThemeColors.primary(view.getContext())));
        }
        view.setContentDescription(description(semanticLabel, view.getText()));
        if (destructive(semanticLabel)) {
            view.setTextColor(ContextCompat.getColor(view.getContext(), R.color.error));
        }
    }

    static String description(String semanticLabel, CharSequence visibleText) {
        String semantic = semanticLabel == null ? "" : semanticLabel.trim();
        if (!semantic.isEmpty()) return semantic;
        return TextUtils.isEmpty(visibleText) ? "操作" : visibleText.toString().trim();
    }

    private static boolean containsAny(String value, String... candidates) {
        for (String candidate : candidates) if (value.contains(candidate)) return true;
        return false;
    }

    private static int dp(TextView view, int value) {
        return Math.round(value * view.getResources().getDisplayMetrics().density);
    }
}
