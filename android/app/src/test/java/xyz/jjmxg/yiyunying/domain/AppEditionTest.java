package xyz.jjmxg.yiyunying.domain;

import org.junit.Test;

import xyz.jjmxg.yiyunying.BuildConfig;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public final class AppEditionTest {
    @Test public void buildVariantHasOneFixedRole() {
        assertEquals(Role.fromWireName(BuildConfig.FIXED_ROLE), AppEdition.role());
        assertTrue(BuildConfig.APP_EDITION.matches("platform_owner|authorized_platform|admin|user"));
    }

    @Test public void platformEditionsRejectTheOtherPlatformLevel() {
        if (AppEdition.role() != Role.PLATFORM) return;
        int required = AppEdition.requiredPlatformLevel();
        assertEquals("", AppEdition.platformLevelError(Role.PLATFORM, required));
        assertFalse(AppEdition.platformLevelError(Role.PLATFORM, required == 1 ? 2 : 1).isEmpty());
    }

    @Test public void levelTwoCannotOpenLevelOneOperatorManagement() {
        assertFalse(AppEdition.canOpenPlatformModule(2, "operators"));
        assertTrue(AppEdition.canOpenPlatformModule(2, "admins"));
        assertTrue(AppEdition.canOpenPlatformModule(1, "operators"));
    }
}
