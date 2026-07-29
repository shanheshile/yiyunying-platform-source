package xyz.jjmxg.yiyunying.domain;

import org.junit.Test;

import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

public class AdminAccessPolicyTest {
    @Test
    public void billingOnlyMatchesBackendWhitelist() {
        assertTrue(AdminAccessPolicy.canOpen("billing_only", "entitlement"));
        assertTrue(AdminAccessPolicy.canOpen("billing_only", "purchase_orders"));
        assertTrue(AdminAccessPolicy.canOpen("billing_only", "platform_feedbacks"));
        assertTrue(AdminAccessPolicy.canOpen("billing_only", "exchange_products"));
        assertTrue(AdminAccessPolicy.canOpen("billing_only", "exchanges"));
        assertTrue(AdminAccessPolicy.canOpen("billing_only", "integral_logs"));
        assertTrue(AdminAccessPolicy.canOpen("billing_only", "profile"));
        assertFalse(AdminAccessPolicy.canOpen("billing_only", "apps"));
        assertFalse(AdminAccessPolicy.canOpen("billing_only", "dashboard"));
        assertFalse(AdminAccessPolicy.canOpen("billing_only", "api_console"));
    }

    @Test
    public void fullModeAllowsEveryModule() {
        assertTrue(AdminAccessPolicy.canOpen("full", "apps"));
        assertTrue(AdminAccessPolicy.canOpen("full", "dashboard"));
    }
}
