package xyz.jjmxg.yiyunying;

import android.app.Application;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.ShortcutInfo;
import android.content.pm.ShortcutManager;
import android.graphics.drawable.Icon;
import android.os.Build;

import androidx.annotation.RequiresApi;
import androidx.appcompat.app.AppCompatDelegate;
import androidx.core.os.LocaleListCompat;

import java.util.ArrayList;
import java.util.List;

import xyz.jjmxg.yiyunying.core.AppContainer;
import xyz.jjmxg.yiyunying.core.AppearanceStyleStore;
import xyz.jjmxg.yiyunying.core.CrashReporter;
import xyz.jjmxg.yiyunying.core.RuntimeLanguage;
import xyz.jjmxg.yiyunying.core.ThemeModeStore;
import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.data.update.UpdatePackageStore;
import xyz.jjmxg.yiyunying.service.MessageNotificationService;
import xyz.jjmxg.yiyunying.ui.main.MainActivity;
import xyz.jjmxg.yiyunying.ui.settings.UserSettingsActivity;
import xyz.jjmxg.yiyunying.ui.social.FriendQrActivity;
import xyz.jjmxg.yiyunying.ui.upload.CacheManagementActivity;

public final class YiyunyingApplication extends Application {
    private AppContainer container;
    private final SharedPreferences.OnSharedPreferenceChangeListener appearanceListener =
        (preferences, key) -> {
            if (AppearanceStyleStore.KEY_LANGUAGE.equals(key)) {
                safely("应用界面语言", () -> AppCompatDelegate.setApplicationLocales(
                    LocaleListCompat.forLanguageTags(AppearanceStyleStore.language(this))));
            }
            if (AppearanceStyleStore.KEY_LANGUAGE.equals(key)
                || AppearanceStyleStore.KEY_ACCENT.equals(key)
                || AppearanceStyleStore.KEY_FONT.equals(key)
                || AppearanceStyleStore.KEY_ICON.equals(key)) {
                safely("更新桌面快捷方式", this::publishShortcuts);
            }
        };

    @Override
    public void onCreate() {
        super.onCreate();
        CrashReporter.install(this);
        // Package replacement may kill the old process before it receives a callback. Reconcile
        // the persisted install request on every cold start so automatic cleanup is reliable on
        // vendor Android builds that delay or omit MY_PACKAGE_REPLACED delivery.
        safely("核对软件更新安装包", () -> UpdatePackageStore.reconcileInstalled(this));
        safely("修复桌面入口", () -> AppearanceStyleStore.repairLauncherState(this));
        safely("应用主题", () -> ThemeModeStore.apply(this));
        safely("应用界面语言", () -> AppCompatDelegate.setApplicationLocales(
            LocaleListCompat.forLanguageTags(AppearanceStyleStore.language(this))));
        container = new AppContainer(this);
        getSharedPreferences(AppearanceStyleStore.PREFERENCES, MODE_PRIVATE)
            .registerOnSharedPreferenceChangeListener(appearanceListener);
        if (container.session().isAuthenticated() && container.session().role() == Role.USER) {
            try {
                MessageNotificationService.start(this);
            } catch (RuntimeException ignored) {
                // MainActivity starts it again when background start is temporarily restricted.
            }
        }
        safely("发布桌面快捷方式", this::publishShortcuts);
    }

    public AppContainer container() {
        return container;
    }

    public void refreshShortcuts() {
        safely("刷新桌面快捷方式", this::publishShortcuts);
    }

    private void safely(String area, Runnable action) {
        try {
            action.run();
        } catch (RuntimeException exception) {
            CrashReporter.record(area, exception);
        }
    }

    private void publishShortcuts() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.N_MR1) return;
        ShortcutManager manager = getSystemService(ShortcutManager.class);
        if (manager == null) return;
        List<ShortcutInfo> shortcuts = new ArrayList<>();
        if (container.session().isAuthenticated() && container.session().role() == Role.USER) {
            shortcuts.add(shortcut(
                "open_messages", "消息", "打开消息列表", R.drawable.ic_chat,
                MainActivity.moduleIntent(this, "home").setAction("shortcut.messages")
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)));
            shortcuts.add(shortcut(
                "open_notes", "我的笔记", "查看和编辑笔记", R.drawable.ic_document,
                MainActivity.moduleIntent(this, "documents").setAction("shortcut.notes")
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)));
            shortcuts.add(shortcut(
                "scan_friend", "扫一扫", "扫描好友二维码", R.drawable.ic_qr,
                FriendQrActivity.intent(this, true).setAction("shortcut.scan_friend")));
            shortcuts.add(shortcut(
                "open_settings", "设置", "账号、通知、权限与存储设置", R.drawable.ic_settings,
                new Intent(this, UserSettingsActivity.class)
                    .setAction("shortcut.settings")
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP)));
        } else if (container.session().isAuthenticated()) {
            shortcuts.add(shortcut(
                "open_dashboard", "工作台", "打开管理工作台", R.drawable.ic_dashboard,
                MainActivity.moduleIntent(this, "home").setAction("shortcut.dashboard")
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)));
            shortcuts.add(shortcut(
                "open_apps", "应用管理", "查看和管理应用", R.drawable.ic_apps,
                MainActivity.moduleIntent(this, "apps").setAction("shortcut.apps")
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)));
            String peopleModule = container.session().role() == Role.PLATFORM ? "admins" : "users";
            String peopleLabel = container.session().role() == Role.PLATFORM ? "管理员" : "用户管理";
            shortcuts.add(shortcut(
                "open_people", peopleLabel, "查看下级账号与资料", R.drawable.ic_users,
                MainActivity.moduleIntent(this, peopleModule).setAction("shortcut.people")
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)));
            String contentModule = container.session().role() == Role.PLATFORM ? "operators" : "documents";
            String contentLabel = container.session().role() == Role.PLATFORM ? "授权平台" : "文档管理";
            shortcuts.add(shortcut(
                "open_content", contentLabel, "打开常用管理功能", R.drawable.ic_document,
                MainActivity.moduleIntent(this, contentModule).setAction("shortcut.content")
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)));
        }
        Intent cacheIntent = new Intent(this, CacheManagementActivity.class)
            .setAction(Intent.ACTION_VIEW)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        shortcuts.add(shortcut(
            "storage_cache", "缓存管理", "存储与缓存管理", R.drawable.ic_refresh, cacheIntent));
        int maximum = Math.max(1, manager.getMaxShortcutCountPerActivity());
        if (shortcuts.size() > maximum) shortcuts = new ArrayList<>(shortcuts.subList(0, maximum));
        manager.setDynamicShortcuts(shortcuts);
    }

    @RequiresApi(Build.VERSION_CODES.N_MR1)
    private ShortcutInfo shortcut(String id, String label, String longLabel, int icon, Intent intent) {
        return new ShortcutInfo.Builder(this, id)
            .setShortLabel(RuntimeLanguage.translate(this, label))
            .setLongLabel(RuntimeLanguage.translate(this, longLabel))
            .setIcon(Icon.createWithResource(this, icon))
            .setIntent(intent)
            .build();
    }
}
