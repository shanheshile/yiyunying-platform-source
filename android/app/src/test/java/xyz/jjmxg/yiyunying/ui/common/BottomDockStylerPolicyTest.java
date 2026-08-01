package xyz.jjmxg.yiyunying.ui.common;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public class BottomDockStylerPolicyTest {
    @Test
    public void xiaomiFamilyUsesCompatibilityMetrics() {
        assertTrue(BottomDockStyler.needsXiaomiTextMetrics("Xiaomi", "Xiaomi"));
        assertTrue(BottomDockStyler.needsXiaomiTextMetrics("Redmi", "Redmi"));
        assertTrue(BottomDockStyler.needsXiaomiTextMetrics("POCO", "Xiaomi"));
    }

    @Test
    public void vivoAndOtherBrandsKeepTheExistingMetrics() {
        assertFalse(BottomDockStyler.needsXiaomiTextMetrics("vivo", "vivo"));
        assertFalse(BottomDockStyler.needsXiaomiTextMetrics("OPPO", "OPPO"));
        assertFalse(BottomDockStyler.needsXiaomiTextMetrics("", ""));
    }

    @Test
    public void xiaomiAddsOnlyTheCompatibilityHeight() {
        assertEquals(68, BottomDockStyler.heightDp(1.0f, false));
        assertEquals(74, BottomDockStyler.heightDp(1.0f, true));
        assertEquals(76, BottomDockStyler.heightDp(1.5f, false));
        assertEquals(82, BottomDockStyler.heightDp(1.5f, true));
    }
}
