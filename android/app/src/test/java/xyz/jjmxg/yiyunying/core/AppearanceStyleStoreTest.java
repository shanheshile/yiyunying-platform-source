package xyz.jjmxg.yiyunying.core;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertTrue;

import android.content.ComponentName;
import android.content.Context;
import android.content.pm.PackageManager;

import androidx.test.core.app.ApplicationProvider;

import org.junit.Before;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;

import xyz.jjmxg.yiyunying.YiyunyingApplication;

@RunWith(RobolectricTestRunner.class)
@Config(sdk = 32, application = YiyunyingApplication.class)
public final class AppearanceStyleStoreTest {
    private Context context;

    @Before
    public void setUp() {
        context = ApplicationProvider.getApplicationContext();
        context.getSharedPreferences("appearance", Context.MODE_PRIVATE).edit().clear().commit();
    }

    @Test
    public void everyDeclaredLauncherAliasCanBeSelectedForSuffixedBuilds() {
        assertEquals(
            context.getPackageName() + ".launcher.DefaultLauncher",
            AppearanceStyleStore.launcherClassName(context, "DefaultLauncher")
        );
        assertTrue(
            "The test package must include a flavor/debug suffix",
            !"xyz.jjmxg.yiyunying".equals(context.getPackageName())
        );
        assertIcon(AppearanceStyleStore.ICON_MINIMAL, "MinimalLauncher");
        assertIcon(AppearanceStyleStore.ICON_DARK, "DarkLauncher");
        assertIcon(AppearanceStyleStore.ICON_DEFAULT, "DefaultLauncher");
    }

    @Test
    public void appearanceValuesAreNormalizedAndPersistedGlobally() {
        AppearanceStyleStore.setAccent(context, AppearanceStyleStore.TEAL);
        AppearanceStyleStore.setFont(context, AppearanceStyleStore.FONT_SERIF);
        AppearanceStyleStore.setLanguage(context, "ja-JP");

        assertEquals(AppearanceStyleStore.TEAL, AppearanceStyleStore.accent(context));
        assertEquals(AppearanceStyleStore.FONT_SERIF, AppearanceStyleStore.font(context));
        assertEquals("ja", AppearanceStyleStore.language(context));

        AppearanceStyleStore.setAccent(context, "unsupported-accent");
        AppearanceStyleStore.setFont(context, "unsupported-font");
        AppearanceStyleStore.setLanguage(context, "unsupported-language");

        assertEquals(AppearanceStyleStore.BLUE, AppearanceStyleStore.accent(context));
        assertEquals(AppearanceStyleStore.FONT_SYSTEM, AppearanceStyleStore.font(context));
        assertEquals("zh-CN", AppearanceStyleStore.language(context));
    }

    @Test
    public void startupRepairRestoresExactlyOneLauncherAfterUpgrade() {
        PackageManager manager = context.getPackageManager();
        context.getSharedPreferences(AppearanceStyleStore.PREFERENCES, Context.MODE_PRIVATE)
            .edit()
            .putString(AppearanceStyleStore.KEY_ICON, AppearanceStyleStore.ICON_DARK)
            .commit();
        for (String alias : new String[]{"DefaultLauncher", "MinimalLauncher", "DarkLauncher"}) {
            manager.setComponentEnabledSetting(
                component(alias),
                PackageManager.COMPONENT_ENABLED_STATE_DISABLED,
                PackageManager.DONT_KILL_APP
            );
        }

        AppearanceStyleStore.repairLauncherState(context);

        assertEquals(
            PackageManager.COMPONENT_ENABLED_STATE_ENABLED,
            manager.getComponentEnabledSetting(component("DarkLauncher"))
        );
        assertEquals(
            PackageManager.COMPONENT_ENABLED_STATE_DISABLED,
            manager.getComponentEnabledSetting(component("DefaultLauncher"))
        );
        assertEquals(
            PackageManager.COMPONENT_ENABLED_STATE_DISABLED,
            manager.getComponentEnabledSetting(component("MinimalLauncher"))
        );
    }

    private void assertIcon(String icon, String selectedAlias) {
        assertTrue(
            "Launcher alias must exist and be selectable: " + selectedAlias,
            AppearanceStyleStore.setIcon(context, icon)
        );
        assertEquals(icon, AppearanceStyleStore.icon(context));
        assertTrue("Selected launcher must be the only enabled alias", AppearanceStyleStore.isIconApplied(context, icon));

        PackageManager manager = context.getPackageManager();
        for (String alias : new String[]{"DefaultLauncher", "MinimalLauncher", "DarkLauncher"}) {
            ComponentName component = component(alias);
            int expected = alias.equals(selectedAlias)
                ? PackageManager.COMPONENT_ENABLED_STATE_ENABLED
                : PackageManager.COMPONENT_ENABLED_STATE_DISABLED;
            assertEquals(
                "Unexpected launcher component state: " + alias,
                expected,
                manager.getComponentEnabledSetting(component)
            );
        }
    }

    private ComponentName component(String alias) {
        return new ComponentName(
            context.getPackageName(),
            context.getPackageName() + ".launcher." + alias
        );
    }
}
