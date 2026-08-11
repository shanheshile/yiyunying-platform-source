package xyz.jjmxg.yiyunying.ui.main;

import org.junit.Test;

import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class ManagementShellUiContractTest {
    @Test public void roleGateSeparatesAdminWorkbenchFromPlatformDirectories() throws Exception {
        String shell = read("src/main/java/xyz/jjmxg/yiyunying/ui/main/ManagementShellFragment.java");
        assertTrue(shell.contains("ManagementNavigationPolicy.useAdminWorkbench(app().session().role())"));
        assertTrue(shell.contains("findItem(MENU_IDS[index]).setTitle(titles[index])"));
        assertTrue(shell.contains("if (adminWorkbench)"));
        assertTrue(shell.contains("ManagementHomeFragment.newInstance()"));
        assertTrue(shell.contains("SourceExamplesFragment.newInstance()"));
        assertTrue(shell.contains("AdminCommunityFragment.newInstance()"));
        assertTrue(shell.contains("AdminMineFragment.newInstance()"));
        assertTrue(shell.contains("FeatureDirectoryFragment.newEmbeddedInstance(\"apps\", true)"));
        assertTrue(shell.contains("FeatureDirectoryFragment.newEmbeddedInstance(\"source\")"));
        assertFalse(shell.contains("/api/admin/"));
        for (String page : new String[]{"ManagementHomeFragment.java", "SourceExamplesFragment.java",
            "AdminCommunityFragment.java", "AdminMineFragment.java"}) {
            String source = read("src/main/java/xyz/jjmxg/yiyunying/ui/main/" + page);
            assertTrue(page, source.contains(
                "!ManagementNavigationPolicy.useAdminWorkbench(app().session().role())"));
        }
    }

    @Test public void formsKeepChineseTypeSelectionAndExactSponsorAmountText() throws Exception {
        String home = read("src/main/java/xyz/jjmxg/yiyunying/ui/main/ManagementHomeFragment.java");
        String mine = read("src/main/java/xyz/jjmxg/yiyunying/ui/main/AdminMineFragment.java");
        assertTrue(home.contains("setTitle(\"选择应用类型\")"));
        assertTrue(home.contains("ManagementNavigationPolicy.appTypeLabels()"));
        assertTrue(home.contains("body.addProperty(\"app_type\", appTypeCode)"));
        assertFalse(home.contains("应用类型 general/community/business/tool"));
        assertTrue(mine.contains("FieldSpec.typed(\"amount\", \"确认到账金额（元）\", FieldType.TEXT, true)"));
        assertFalse(mine.contains("FieldSpec.typed(\"amount\", \"确认到账金额\", FieldType.DECIMAL"));
    }

    @Test public void sourceUiUsesExplicitCategoriesAndBoundaryMatcher() throws Exception {
        String sourcePage = read("src/main/java/xyz/jjmxg/yiyunying/ui/main/SourceExamplesFragment.java");
        String directory = read("src/main/java/xyz/jjmxg/yiyunying/ui/main/FeatureDirectoryFragment.java");
        String policy = read("src/main/java/xyz/jjmxg/yiyunying/ui/main/ManagementNavigationPolicy.java");
        assertTrue(sourcePage.contains("ManagementNavigationPolicy.sourceCategories()"));
        assertTrue(directory.contains("ManagementNavigationPolicy.sourceDirectoryModule(id, group)"));
        assertFalse(directory.contains("containsAny(id, \"resource\", \"source\", \"store_app\", \"upload\", \"file\""));
        for (String category : new String[]{
            "Android Java 源码", "iApp 源码", "Lua 源码", "Web 源码", "PHP 源码",
            "Python 源码", "JavaScript 源码", "HarmonyOS 源码", "iOS 源码",
            "C/C++ 源码", "数据库源码", "通用模块", "其他源码"
        }) {
            assertTrue(category, policy.contains("\"" + category + "\""));
        }
    }

    @Test public void sourceAndCommunityResolvePermissionBeforeLoadingData() throws Exception {
        String source = read("src/main/java/xyz/jjmxg/yiyunying/ui/main/SourceExamplesFragment.java");
        String community = read("src/main/java/xyz/jjmxg/yiyunying/ui/main/AdminCommunityFragment.java");

        assertTrue(source.contains("ManagementNavigationPolicy.permissionAllowed(result.dataObject(), \"resources.manage\")"));
        assertTrue(source.contains("if (!permissionAllowed) return;"));
        assertTrue(source.indexOf("\"/api/admin/permissions\"")
            < source.indexOf("\"/api/admin/apps/\" + appId + \"/resource-categories\""));
        String sourceCreate = section(source, "public View onCreateView", "@Override public void onResume");
        assertTrue(sourceCreate.contains("loadPermission();"));
        assertFalse(sourceCreate.contains("loadCategories();"));

        assertTrue(community.contains("ManagementNavigationPolicy.permissionAllowed(result.dataObject(), \"forum.manage\")"));
        assertTrue(community.contains("if (!permissionAllowed) return;"));
        assertTrue(community.indexOf("\"/api/admin/permissions\"")
            < community.indexOf("\"/api/admin/community/posts\""));
        String communityCreate = section(community, "public View onCreateView", "private void loadPermission");
        assertTrue(communityCreate.contains("loadPermission();"));
        assertFalse(communityCreate.contains("loadPosts();"));
    }

    @Test public void groupShortcutUsesOnlyIndependentChatRoomPermission() throws Exception {
        String quickPolicy = read("src/main/java/xyz/jjmxg/yiyunying/ui/home/UserQuickAccessPolicy.java");
        String groupCase = section(quickPolicy, "case GROUP_CHAT:", "case RED_PACKETS:");
        assertTrue(groupCase.contains("effectiveOrLegacy(features, \"chat_rooms\")"));
        assertFalse(groupCase.contains("\"messages\""));
    }

    @Test public void adminHomeRejectsDashboardSelfNavigation() throws Exception {
        String home = read("src/main/java/xyz/jjmxg/yiyunying/ui/main/ManagementHomeFragment.java");
        String directory = read("src/main/java/xyz/jjmxg/yiyunying/ui/main/FeatureDirectoryFragment.java");
        assertTrue(home.contains("ManagementNavigationPolicy.safeChildModule(entry.primaryModule)"));
        assertTrue(home.contains("ManagementNavigationPolicy.safeChildModule(module)"));
        assertTrue(home.contains("host().onAppSelectionChanged()"));
        String main = read("src/main/java/xyz/jjmxg/yiyunying/ui/main/MainActivity.java");
        assertTrue(main.contains("!\"dashboard\".equals(currentModule.id())"));
        assertTrue(directory.contains("if (\"dashboard\".equals(module.id()) && excludeDashboard()) continue;"));
        assertTrue(directory.contains("args.putBoolean(ARG_EXCLUDE_DASHBOARD, excludeDashboard)"));
    }

    private static String read(String relative) throws IOException {
        Path path = Path.of(relative);
        if (!Files.exists(path) && relative.startsWith("src/")) {
            path = Path.of("app/" + relative);
        }
        return new String(Files.readAllBytes(path), StandardCharsets.UTF_8);
    }

    private static String section(String source, String start, String end) {
        int from = source.indexOf(start);
        int to = source.indexOf(end, from < 0 ? 0 : from + start.length());
        assertTrue("missing section start: " + start, from >= 0);
        assertTrue("missing section end: " + end, to > from);
        return source.substring(from, to);
    }
}
