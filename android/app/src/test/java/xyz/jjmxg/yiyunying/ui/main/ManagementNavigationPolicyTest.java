package xyz.jjmxg.yiyunying.ui.main;

import com.google.gson.JsonObject;
import com.google.gson.JsonPrimitive;

import org.junit.Test;

import java.util.Arrays;

import xyz.jjmxg.yiyunying.domain.Role;
import xyz.jjmxg.yiyunying.ui.common.DisplayText;

import static org.junit.Assert.assertArrayEquals;
import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class ManagementNavigationPolicyTest {
    @Test public void onlyAdministratorUsesTheNewFourPageWorkbench() {
        assertTrue(ManagementNavigationPolicy.useAdminWorkbench(Role.ADMIN));
        assertFalse(ManagementNavigationPolicy.useAdminWorkbench(Role.PLATFORM));
        assertFalse(ManagementNavigationPolicy.useAdminWorkbench(Role.USER));
        assertArrayEquals(new String[]{"主页", "源码示例", "交流", "我的"},
            ManagementNavigationPolicy.tabTitles(Role.ADMIN));
        assertArrayEquals(new String[]{"应用", "源码", "交流", "我的"},
            ManagementNavigationPolicy.tabTitles(Role.PLATFORM));
    }

    @Test public void dashboardCannotBeOpenedAsAChildOfItsOwnShell() {
        assertFalse(ManagementNavigationPolicy.safeChildModule("dashboard"));
        assertFalse(ManagementNavigationPolicy.safeChildModule(" Dashboard "));
        assertFalse(ManagementNavigationPolicy.safeChildModule(null));
        assertTrue(ManagementNavigationPolicy.safeChildModule("statistics"));
        assertTrue(ManagementNavigationPolicy.safeChildModule("app_settings"));
        for (ManagementNavigationPolicy.SystemEntry system : ManagementNavigationPolicy.systems()) {
            assertTrue(system.title, ManagementNavigationPolicy.safeChildModule(system.primaryModule));
            for (String embedded : system.embeddedModules) {
                assertTrue(system.title + "/" + embedded,
                    ManagementNavigationPolicy.safeChildModule(embedded));
            }
        }
    }

    @Test public void sourceDirectoryUsesExplicitIdBoundaries() {
        assertTrue(ManagementNavigationPolicy.sourceDirectoryModule("resource_categories", "内容"));
        assertTrue(ManagementNavigationPolicy.sourceDirectoryModule("store_apps", "内容"));
        assertTrue(ManagementNavigationPolicy.sourceDirectoryModule("uploads", "其他"));
        assertTrue(ManagementNavigationPolicy.sourceDirectoryModule("remote_files", "其他"));
        assertTrue(ManagementNavigationPolicy.sourceDirectoryModule("documents", "其他"));
        assertTrue(ManagementNavigationPolicy.sourceDirectoryModule("api_logs", "其他"));
        assertFalse(ManagementNavigationPolicy.sourceDirectoryModule("profile", "账户"));
        assertFalse(ManagementNavigationPolicy.sourceDirectoryModule("user_profile", "账户"));
        assertFalse(ManagementNavigationPolicy.sourceDirectoryModule("profile_settings", "账户"));
    }

    @Test public void sourceAndApplicationTypesAreExplicitChineseChoices() {
        assertEquals(Arrays.asList(
                "Android Java 源码", "iApp 源码", "Lua 源码", "Web 源码", "PHP 源码",
                "Python 源码", "JavaScript 源码", "HarmonyOS 源码", "iOS 源码",
                "C/C++ 源码", "数据库源码", "通用模块", "其他源码"),
            ManagementNavigationPolicy.sourceCategories());
        assertEquals(13, ManagementNavigationPolicy.sourceCategories().size());
        assertArrayEquals(new String[]{"综合应用", "社区应用", "商业应用", "工具应用"},
            ManagementNavigationPolicy.appTypeLabels());
        assertEquals("general", ManagementNavigationPolicy.appTypeCode(0));
        assertEquals("community", ManagementNavigationPolicy.appTypeCode(1));
        assertEquals("business", ManagementNavigationPolicy.appTypeCode(2));
        assertEquals("tool", ManagementNavigationPolicy.appTypeCode(3));
        assertEquals("社区应用", ManagementNavigationPolicy.appTypeName("community"));
        assertEquals("社区应用", ManagementNavigationPolicy.appTypeName("社区应用"));
    }

    @Test public void administratorPermissionPayloadFailsClosed() {
        JsonObject payload = new JsonObject();
        JsonObject permissions = new JsonObject();
        JsonObject allowed = new JsonObject();
        allowed.addProperty("allowed", true);
        JsonObject denied = new JsonObject();
        denied.addProperty("allowed", false);
        JsonObject malformed = new JsonObject();
        permissions.add("resources.manage", allowed);
        permissions.add("forum.manage", denied);
        permissions.add("apps.manage", malformed);
        payload.add("permissions", permissions);

        assertTrue(ManagementNavigationPolicy.permissionAllowed(payload, "resources.manage"));
        assertFalse(ManagementNavigationPolicy.permissionAllowed(payload, "forum.manage"));
        assertFalse(ManagementNavigationPolicy.permissionAllowed(payload, "apps.manage"));
        assertFalse(ManagementNavigationPolicy.permissionAllowed(payload, "missing.manage"));
        assertFalse(ManagementNavigationPolicy.permissionAllowed(new JsonObject(), "resources.manage"));
        assertFalse(ManagementNavigationPolicy.permissionAllowed(null, "resources.manage"));
        assertFalse(ManagementNavigationPolicy.permissionAllowed(payload, null));
    }

    @Test public void recordRendererLocalizesApplicationTypeKeyAndValues() {
        assertEquals("应用类型", DisplayText.label("app_type"));
        assertEquals("综合应用", DisplayText.fieldValue("app_type", new JsonPrimitive("general")));
        assertEquals("社区应用", DisplayText.fieldValue("app_type", new JsonPrimitive("community")));
        assertEquals("商业应用", DisplayText.fieldValue("app_type", new JsonPrimitive("business")));
        assertEquals("工具应用", DisplayText.fieldValue("app_type", new JsonPrimitive("tool")));
    }
}
