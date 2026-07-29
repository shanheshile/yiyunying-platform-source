package xyz.jjmxg.yiyunying.core;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNotNull;
import static org.junit.Assert.assertTrue;

import android.content.ComponentName;
import android.content.Context;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;

import androidx.test.core.app.ApplicationProvider;

import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.android.controller.ActivityController;
import org.robolectric.annotation.Config;

import xyz.jjmxg.yiyunying.YiyunyingApplication;
import xyz.jjmxg.yiyunying.ui.auth.LoginActivity;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 34, application = YiyunyingApplication.class)
public final class ApplicationStartupTest {
    @Test
    public void corruptUpgradePreferencesCannotCrashApplicationStartup() {
        YiyunyingApplication application = ApplicationProvider.getApplicationContext();
        SharedPreferences appearance = application.getSharedPreferences(
            AppearanceStyleStore.PREFERENCES,
            Context.MODE_PRIVATE
        );
        appearance.edit()
            .putString(AppearanceStyleStore.KEY_ICON, "removed-launcher")
            .putString(AppearanceStyleStore.KEY_LANGUAGE, "broken-locale")
            .putString(AppearanceStyleStore.KEY_ACCENT, "broken-accent")
            .putString(AppearanceStyleStore.KEY_FONT, "broken-font")
            .commit();

        // Re-run the idempotent startup path to model a process restart after an upgrade.
        application.onCreate();

        assertNotNull(application.container());
        assertTrue(AppearanceStyleStore.ICON_DEFAULT.equals(
            AppearanceStyleStore.icon(application)));
        assertTrue("zh-CN".equals(AppearanceStyleStore.language(application)));
    }

    @Test
    public void freshInstallCanOpenLoginScreenThroughDeclaredLauncher() {
        YiyunyingApplication application = ApplicationProvider.getApplicationContext();
        application.container().session().clearAuthentication();

        ComponentName launcher = new ComponentName(
            application.getPackageName(),
            application.getPackageName() + ".launcher.DefaultLauncher"
        );
        try {
            assertNotNull(application.getPackageManager().getActivityInfo(
                launcher,
                PackageManager.MATCH_DISABLED_COMPONENTS
            ));
        } catch (PackageManager.NameNotFoundException exception) {
            throw new AssertionError(exception);
        }

        try (ActivityController<LoginActivity> activityController =
                 Robolectric.buildActivity(LoginActivity.class).setup()) {
            LoginActivity activity = activityController.get();
            assertFalse(activity.isFinishing());
            assertFalse(activity.isDestroyed());
            assertNotNull(activity.findViewById(xyz.jjmxg.yiyunying.R.id.loginButton));
        }
    }
}
