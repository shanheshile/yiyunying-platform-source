package xyz.jjmxg.yiyunying.domain;

import java.util.Arrays;
import java.util.Collections;
import java.util.HashSet;
import java.util.Set;

public final class AdminAccessPolicy {
    private static final Set<String> BILLING_MODULES = Collections.unmodifiableSet(new HashSet<>(Arrays.asList(
        "entitlement",
        "purchase_orders",
        "platform_feedbacks",
        "exchange_products",
        "exchanges",
        "integral_logs",
        "profile"
    )));

    private AdminAccessPolicy() { }

    public static boolean canOpen(String accessMode, String moduleId) {
        return !"billing_only".equals(accessMode) || BILLING_MODULES.contains(moduleId);
    }
}
