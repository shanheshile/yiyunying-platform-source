package xyz.jjmxg.yiyunying.ui.common;

import android.app.Activity;
import android.view.View;

import xyz.jjmxg.yiyunying.ui.common.YiyunyingDialogBuilder;
import com.google.android.material.snackbar.Snackbar;
import com.google.gson.JsonObject;

import java.util.LinkedHashMap;
import java.util.Map;
import java.util.concurrent.atomic.AtomicBoolean;

import xyz.jjmxg.yiyunying.BuildConfig;
import xyz.jjmxg.yiyunying.core.AppAccess;
import xyz.jjmxg.yiyunying.core.CrashReporter;
import xyz.jjmxg.yiyunying.data.api.Jsons;
import xyz.jjmxg.yiyunying.data.api.RequestHandle;
import xyz.jjmxg.yiyunying.data.session.SessionManager;
import xyz.jjmxg.yiyunying.domain.AppEdition;

public final class LifecycleChecker {
    private LifecycleChecker() {
    }

    public static RequestHandle check(Activity activity, View anchor, Runnable onAllowed) {
        AtomicBoolean allowed = new AtomicBoolean(false);
        Runnable allowOnce = () -> {
            if (!allowed.compareAndSet(false, true) || !usable(activity)) return;
            runSafely(activity, onAllowed, "继续打开应用");
        };
        SessionManager session = AppAccess.from(activity).session();
        Map<String, String> query = new LinkedHashMap<>();
        query.put("edition_code", AppEdition.code());
        query.put("version_code", String.valueOf(BuildConfig.VERSION_CODE));
        if ("user".equals(AppEdition.code())) {
            query.put("app_key", session.appKey());
        } else {
            query.put("platform_key", session.platformKey());
            if ("admin".equals(AppEdition.code()) && session.actorId() > 0) {
                query.put("admin_id", String.valueOf(session.actorId()));
            }
        }
        return AppAccess.from(activity).repository().getPublic("/api/public/lifecycle", query, result -> {
            if (!usable(activity)) return;
            if (!result.isSuccessful()) {
                if (anchor != null && anchor.isAttachedToWindow()) {
                    Snackbar.make(anchor, result.message().isEmpty()
                        ? "更新维护检查失败，继续使用" : result.message(), Snackbar.LENGTH_LONG).show();
                }
                allowOnce.run();
                return;
            }
            JsonObject data = result.dataObject() == null ? new JsonObject() : result.dataObject().deepCopy();
            JsonObject maintenance = Jsons.object(data, "maintenance").deepCopy();
            if (booleanValue(maintenance, "active")) {
                showMaintenance(activity, anchor, maintenance, allowOnce);
                return;
            }
            JsonObject update = Jsons.object(data, "update").deepCopy();
            JsonObject festival = Jsons.object(data, "festival_theme").deepCopy();
            Runnable afterUpdate = () -> {
                if (!usable(activity)) return;
                try {
                    FestivalThemePresenter.showIfNeeded(activity, festival, allowOnce);
                } catch (RuntimeException | LinkageError exception) {
                    CrashReporter.record("显示节日主题", exception);
                    allowOnce.run();
                }
            };
            if (booleanValue(update, "available")) {
                showUpdate(activity, update, afterUpdate);
                return;
            }
            afterUpdate.run();
        });
    }

    private static void showMaintenance(Activity activity, View anchor, JsonObject maintenance, Runnable onAllowed) {
        if (!usable(activity)) return;
        boolean forced = booleanValue(maintenance, "forced");
        String configuredTitle = Jsons.string(maintenance, "title");
        String configuredMessage = Jsons.string(maintenance, "message");
        YiyunyingDialogBuilder builder = new YiyunyingDialogBuilder(activity);
        if (configuredTitle.isEmpty()) builder.setTitle("系统维护");
        else builder.setBusinessTitle(configuredTitle);
        if (configuredMessage.isEmpty()) builder.setMessage("系统维护中，请稍后再试。");
        else builder.setBusinessMessage(configuredMessage);
        AtomicBoolean handled = new AtomicBoolean(false);
        builder.setCancelable(false)
            .setPositiveButton("重新检查", (dialog, which) -> {
                if (!handled.compareAndSet(false, true) || !usable(activity)) return;
                check(activity, anchor, onAllowed);
            });
        if (!forced) {
            builder.setNegativeButton("临时进入", (dialog, which) -> {
                if (handled.compareAndSet(false, true)) runSafely(activity, onAllowed, "临时进入应用");
            });
        } else {
            builder.setNegativeButton("退出", (dialog, which) -> {
                if (handled.compareAndSet(false, true) && usable(activity)) activity.finishAffinity();
            });
        }
        try {
            builder.show();
        } catch (RuntimeException | LinkageError exception) {
            CrashReporter.record("显示维护提示", exception);
            if (forced) activity.finishAffinity();
            else runSafely(activity, onAllowed, "维护提示降级");
        }
    }

    private static void showUpdate(Activity activity, JsonObject update, Runnable onAllowed) {
        boolean forced = booleanValue(update, "force_update");
        UpdateBottomSheet.show(activity, update, forced, onAllowed);
    }

    private static boolean booleanValue(JsonObject object, String key) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) return false;
        try { return object.get(key).getAsBoolean(); } catch (RuntimeException ignored) { return false; }
    }

    private static boolean usable(Activity activity) {
        return activity != null && !activity.isFinishing() && !activity.isDestroyed();
    }

    private static void runSafely(Activity activity, Runnable action, String area) {
        if (!usable(activity) || action == null) return;
        try {
            action.run();
        } catch (RuntimeException | LinkageError exception) {
            CrashReporter.record(area, exception);
        }
    }
}
