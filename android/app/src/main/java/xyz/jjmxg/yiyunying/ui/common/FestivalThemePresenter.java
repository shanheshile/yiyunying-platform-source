package xyz.jjmxg.yiyunying.ui.common;

import android.app.Activity;
import android.content.Context;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.graphics.Typeface;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.appcompat.app.AlertDialog;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.gson.JsonObject;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.HashSet;
import java.util.Locale;
import java.util.Set;
import java.util.concurrent.atomic.AtomicBoolean;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.ui.browser.LinkNavigator;

public final class FestivalThemePresenter {
    private static final String PREFS = "yiyunying.festival.display.v1";
    private static final Set<Long> PROCESS_SHOWN = new HashSet<>();

    private FestivalThemePresenter() { }

    public static void showIfNeeded(Activity activity, JsonObject theme, Runnable onDone) {
        if (!booleanValue(theme, "active")) {
            onDone.run();
            return;
        }
        long policyId = Jsons.longValue(theme, "policy_id");
        JsonObject config = Jsons.object(theme, "config");
        if (config.has("show_on_launch") && !booleanValue(config, "show_on_launch")) {
            onDone.run();
            return;
        }
        String displayMode = Jsons.string(config, "display_mode").trim().toLowerCase(Locale.ROOT);
        if (displayMode.isEmpty()) displayMode = "once";
        if (!shouldShow(activity, policyId, displayMode)) {
            onDone.run();
            return;
        }
        markShown(activity, policyId, displayMode);

        int padding = dp(activity, 20);
        LinearLayout content = new LinearLayout(activity);
        content.setOrientation(LinearLayout.VERTICAL);
        content.setPadding(padding, dp(activity, 8), padding, dp(activity, 4));

        String background = ImageLoader.get().absoluteUrl(activity, Jsons.string(theme, "background_url"));
        if (!background.isEmpty()) {
            ImageView image = new ImageView(activity);
            image.setScaleType(ImageView.ScaleType.CENTER_CROP);
            image.setAdjustViewBounds(false);
            content.addView(image, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(activity, 210)));
            ImageLoader.get().load(background, image, R.drawable.ic_content);
        }

        TextView title = text(activity, Jsons.string(theme, "title"), 21, Typeface.BOLD);
        title.setGravity(Gravity.CENTER_HORIZONTAL);
        title.setPadding(0, dp(activity, 16), 0, 0);
        content.addView(title);

        String greetingValue = Jsons.string(theme, "greeting").trim();
        if (!greetingValue.isEmpty()) {
            TextView greeting = text(activity, greetingValue, 15, Typeface.NORMAL);
            greeting.setGravity(Gravity.CENTER_HORIZONTAL);
            greeting.setAlpha(0.78f);
            greeting.setPadding(0, dp(activity, 10), 0, 0);
            content.addView(greeting);
        }

        String actionText = Jsons.string(theme, "action_text").trim();
        String actionUrl = Jsons.string(theme, "action_url").trim();
        com.google.android.material.dialog.MaterialAlertDialogBuilder builder = new YiyunyingDialogBuilder(activity)
            .setView(content)
            .setNegativeButton("关闭", null);
        if (!actionText.isEmpty() && !actionUrl.isEmpty()) builder.setPositiveButton(actionText, null);
        AlertDialog dialog = builder.create();
        AtomicBoolean completed = new AtomicBoolean(false);
        dialog.setOnDismissListener(ignored -> {
            if (completed.compareAndSet(false, true)) onDone.run();
        });
        dialog.setOnShowListener(ignored -> {
            if (!actionText.isEmpty() && !actionUrl.isEmpty()) {
                dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(button -> {
                    LinkNavigator.open(activity, actionUrl);
                    dialog.dismiss();
                });
            }
            String accent = Jsons.string(theme, "accent_color");
            try {
                int color = Color.parseColor(accent);
                dialog.getButton(AlertDialog.BUTTON_NEGATIVE).setTextColor(color);
                if (dialog.getButton(AlertDialog.BUTTON_POSITIVE) != null) {
                    dialog.getButton(AlertDialog.BUTTON_POSITIVE).setTextColor(color);
                }
            } catch (RuntimeException ignoredColor) { }
            content.setAlpha(0f);
            content.setScaleX(0.97f);
            content.setScaleY(0.97f);
            content.animate().alpha(1f).scaleX(1f).scaleY(1f).setDuration(220L).start();
        });
        dialog.show();
    }

    private static boolean shouldShow(Context context, long policyId, String mode) {
        synchronized (PROCESS_SHOWN) {
            if (PROCESS_SHOWN.contains(policyId)) return false;
        }
        SharedPreferences preferences = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        if ("every_login".equals(mode)) return true;
        if ("daily".equals(mode)) {
            return !today().equals(preferences.getString("daily." + policyId, ""));
        }
        return !preferences.getBoolean("once." + policyId, false);
    }

    private static void markShown(Context context, long policyId, String mode) {
        synchronized (PROCESS_SHOWN) { PROCESS_SHOWN.add(policyId); }
        SharedPreferences.Editor editor = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit();
        if ("daily".equals(mode)) editor.putString("daily." + policyId, today());
        else if (!"every_login".equals(mode)) editor.putBoolean("once." + policyId, true);
        editor.apply();
    }

    private static TextView text(Context context, String value, int sp, int style) {
        TextView view = new TextView(context);
        view.setText(value);
        view.setTextSize(sp);
        view.setTypeface(view.getTypeface(), style);
        view.setLineSpacing(0f, 1.15f);
        return view;
    }

    private static String today() {
        return new SimpleDateFormat("yyyy-MM-dd", Locale.CHINA).format(new Date());
    }

    private static int dp(Context context, int value) {
        return Math.round(value * context.getResources().getDisplayMetrics().density);
    }

    private static boolean booleanValue(JsonObject object, String key) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) return false;
        try { return object.get(key).getAsBoolean(); } catch (RuntimeException ignored) { return false; }
    }
}
