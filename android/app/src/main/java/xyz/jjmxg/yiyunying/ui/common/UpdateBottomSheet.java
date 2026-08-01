package xyz.jjmxg.yiyunying.ui.common;

import android.app.Activity;
import android.content.res.ColorStateList;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.view.Gravity;
import android.view.View;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;

import com.google.android.material.bottomsheet.BottomSheetDialog;
import com.google.android.material.button.MaterialButton;
import com.google.gson.JsonObject;

import java.util.concurrent.atomic.AtomicBoolean;

import xyz.jjmxg.yiyunying.R;
import xyz.jjmxg.yiyunying.core.CrashReporter;
import xyz.jjmxg.yiyunying.data.api.Jsons;

/** A content-sized update card with complete rounded action buttons on every device. */
final class UpdateBottomSheet {
    private UpdateBottomSheet() { }

    static void show(Activity activity, JsonObject update, boolean forced, Runnable onAllowed) {
        if (!usable(activity)) return;
        JsonObject snapshot = update == null ? new JsonObject() : update.deepCopy();
        AtomicBoolean handled = new AtomicBoolean(false);
        AtomicBoolean continued = new AtomicBoolean(false);
        Runnable continueOnce = () -> {
            if (!continued.compareAndSet(false, true) || !usable(activity) || onAllowed == null) return;
            try {
                onAllowed.run();
            } catch (RuntimeException | LinkageError exception) {
                CrashReporter.record("更新弹层继续使用", exception);
            }
        };
        BottomSheetDialog dialog = new BottomSheetDialog(activity);
        dialog.setCancelable(false);
        dialog.setCanceledOnTouchOutside(false);

        LinearLayout root = new LinearLayout(activity);
        root.setOrientation(LinearLayout.VERTICAL);
        GradientDrawable surface = new GradientDrawable();
        surface.setColor(activity.getColor(R.color.glass_surface_strong));
        surface.setCornerRadius(dp(activity, 22));
        surface.setStroke(dp(activity, 1), activity.getColor(R.color.glass_outline));
        root.setBackground(surface);
        root.setPadding(dp(activity, 20), dp(activity, 18), dp(activity, 20), dp(activity, 22));
        root.setClipChildren(false);
        root.setClipToPadding(false);

        TextView title = text(activity, forced ? "必须更新" : "发现新版本", 24, true);
        root.addView(title, new LinearLayout.LayoutParams(-1, -2));

        String version = value(snapshot, "version_name", "version", "new_version");
        TextView versionView = text(activity, version.isEmpty() ? "新版本可用" : "版本 " + version, 14, true);
        versionView.setTextColor(ThemeColors.primary(activity));
        LinearLayout.LayoutParams versionParams = new LinearLayout.LayoutParams(-1, -2);
        versionParams.topMargin = dp(activity, 10);
        root.addView(versionView, versionParams);

        ScrollView scroll = new ScrollView(activity);
        scroll.setFillViewport(false);
        LinearLayout details = new LinearLayout(activity);
        details.setOrientation(LinearLayout.VERTICAL);
        details.setPadding(0, dp(activity, 8), 0, dp(activity, 8));
        String notes = value(snapshot, "release_notes", "content", "update_content", "description");
        addSection(activity, details, "更新内容", notes.isEmpty() ? "本次更新包含体验优化和稳定性修复。" : notes);
        String optional = value(snapshot, "extra_content", "optional_content", "other_content");
        if (!optional.isEmpty()) addSection(activity, details, "其他说明", optional);
        String packageName = value(snapshot, "file_name", "package_name", "apk_name");
        long packageSize = firstLong(snapshot, "file_size", "size_bytes", "package_size");
        if (!packageName.isEmpty() || packageSize > 0) {
            String packageText = packageName;
            if (packageSize > 0) packageText += (packageText.isEmpty() ? "" : " · ") + sizeText(packageSize);
            addSection(activity, details, "安装包", packageText);
        }
        scroll.addView(details, new ScrollView.LayoutParams(-1, -2));
        root.addView(scroll, new LinearLayout.LayoutParams(-1, -2));

        LinearLayout actions = new LinearLayout(activity);
        actions.setOrientation(LinearLayout.HORIZONTAL);
        actions.setGravity(Gravity.CENTER_VERTICAL);
        actions.setPadding(0, dp(activity, 12), 0, dp(activity, 4));
        actions.setClipChildren(false);
        actions.setClipToPadding(false);
        MaterialButton later = outlinedButton(activity, forced ? "退出" : "稍后");
        MaterialButton updateNow = primaryButton(activity, "立即更新");
        later.setOnClickListener(view -> {
            if (!handled.compareAndSet(false, true)) return;
            dialog.dismiss();
            if (forced) {
                if (usable(activity)) activity.finishAffinity();
            } else {
                continueOnce.run();
            }
        });
        updateNow.setOnClickListener(view -> {
            if (!handled.compareAndSet(false, true) || !usable(activity)) return;
            dialog.dismiss();
            try {
                AppUpdateInstaller.install(activity, snapshot, forced, continueOnce);
            } catch (RuntimeException | LinkageError exception) {
                CrashReporter.record("启动软件更新", exception);
                if (forced) activity.finishAffinity();
                else continueOnce.run();
            }
        });
        LinearLayout.LayoutParams actionParams = new LinearLayout.LayoutParams(0, dp(activity, 54), 1f);
        actionParams.topMargin = dp(activity, 2);
        actionParams.bottomMargin = 0;
        actions.addView(later, actionParams);
        LinearLayout.LayoutParams primaryParams = new LinearLayout.LayoutParams(0, dp(activity, 54), 1.35f);
        primaryParams.leftMargin = dp(activity, 10);
        primaryParams.topMargin = dp(activity, 2);
        primaryParams.bottomMargin = 0;
        actions.addView(updateNow, primaryParams);
        root.addView(actions, new LinearLayout.LayoutParams(-1, -2));

        dialog.setContentView(root);
        GlassBottomSheet.prepareFloating(dialog, activity, 0.86f, false);
        try {
            dialog.show();
        } catch (RuntimeException | LinkageError exception) {
            CrashReporter.record("显示更新弹层", exception);
            if (forced) activity.finishAffinity();
            else continueOnce.run();
        }
    }

    private static void addSection(Activity activity, LinearLayout parent, String label, String value) {
        TextView heading = text(activity, label, 13, true);
        heading.setTextColor(activity.getColor(R.color.on_surface_variant));
        parent.addView(heading, new LinearLayout.LayoutParams(-1, -2));
        TextView body = text(activity, value, 15, false);
        body.setLineSpacing(0f, 1.12f);
        LinearLayout.LayoutParams bodyParams = new LinearLayout.LayoutParams(-1, -2);
        bodyParams.topMargin = dp(activity, 4);
        bodyParams.bottomMargin = dp(activity, 12);
        parent.addView(body, bodyParams);
    }

    private static TextView text(Activity activity, String value, int size, boolean bold) {
        TextView view = new TextView(activity);
        view.setText(value);
        view.setTextSize(size);
        view.setTextColor(activity.getColor(R.color.on_surface));
        if (bold) view.setTypeface(Typeface.DEFAULT, Typeface.BOLD);
        return view;
    }

    private static MaterialButton primaryButton(Activity activity, String label) {
        MaterialButton button = new MaterialButton(activity);
        configureButton(activity, button, label, true);
        return button;
    }

    private static MaterialButton outlinedButton(Activity activity, String label) {
        MaterialButton button = new MaterialButton(activity, null,
            com.google.android.material.R.attr.materialButtonOutlinedStyle);
        configureButton(activity, button, label, false);
        return button;
    }

    private static void configureButton(Activity activity, MaterialButton button, String label,
                                        boolean primary) {
        button.setText(label);
        button.setAllCaps(false);
        button.setTextSize(15);
        button.setMaxLines(1);
        button.setGravity(Gravity.CENTER);
        button.setPadding(dp(activity, 14), 0, dp(activity, 14), 0);
        GlassBottomSheet.styleActionButton(button, activity, primary, 17);
        button.setMinHeight(dp(activity, 52));
        button.setMinimumHeight(dp(activity, 52));
    }

    private static String value(JsonObject object, String... keys) {
        if (object == null) return "";
        for (String key : keys) {
            String value = Jsons.string(object, key);
            if (!value.trim().isEmpty()) return value.trim();
        }
        return "";
    }

    private static long firstLong(JsonObject object, String... keys) {
        if (object == null) return 0;
        for (String key : keys) {
            long value = Jsons.longValue(object, key);
            if (value > 0) return value;
        }
        return 0;
    }

    private static String sizeText(long bytes) {
        if (bytes < 1024) return bytes + " B";
        if (bytes < 1024L * 1024L) return String.format(java.util.Locale.CHINA, "%.1f KB", bytes / 1024f);
        return String.format(java.util.Locale.CHINA, "%.1f MB", bytes / 1024f / 1024f);
    }

    private static int dp(Activity activity, int value) {
        return Math.round(value * activity.getResources().getDisplayMetrics().density);
    }

    private static boolean usable(Activity activity) {
        return activity != null && !activity.isFinishing() && !activity.isDestroyed();
    }
}
