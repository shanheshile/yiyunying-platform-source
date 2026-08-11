package xyz.jjmxg.yiyunying.domain.forum;

import java.util.Locale;

public final class ForumUnlockPolicy {
    public static final String FREE = "free";
    public static final String PAID = "paid";
    public static final String SCHEDULED = "scheduled";
    public static final String PAID_OR_SCHEDULED = "paid_or_scheduled";

    private ForumUnlockPolicy() { }

    public static String from(boolean paid, boolean scheduled) {
        if (paid && scheduled) return PAID_OR_SCHEDULED;
        if (paid) return PAID;
        if (scheduled) return SCHEDULED;
        return FREE;
    }

    public static String normalize(String value) {
        String normalized = value == null ? "" : value.trim().toLowerCase(Locale.ROOT);
        if (PAID.equals(normalized) || SCHEDULED.equals(normalized) || PAID_OR_SCHEDULED.equals(normalized)) {
            return normalized;
        }
        return FREE;
    }

    public static boolean needsPayment(String value) {
        String normalized = normalize(value);
        return PAID.equals(normalized) || PAID_OR_SCHEDULED.equals(normalized);
    }

    public static boolean needsSchedule(String value) {
        String normalized = normalize(value);
        return SCHEDULED.equals(normalized) || PAID_OR_SCHEDULED.equals(normalized);
    }

    public static boolean protectedContent(String value) {
        return !FREE.equals(normalize(value));
    }

    public static String label(String value, double price, String localUnlockAt) {
        String normalized = normalize(value);
        if (PAID.equals(normalized)) return "付费 " + money(price) + " 余额";
        if (SCHEDULED.equals(normalized)) return "定时解锁" + suffix(localUnlockAt);
        if (PAID_OR_SCHEDULED.equals(normalized)) {
            return "付费 " + money(price) + " 余额或到期解锁" + suffix(localUnlockAt);
        }
        return "公开";
    }

    public static String explanation(String value) {
        String normalized = normalize(value);
        if (PAID.equals(normalized)) return "读者支付设定余额后可以查看";
        if (SCHEDULED.equals(normalized)) return "到达设定时间后自动向所有读者公开";
        if (PAID_OR_SCHEDULED.equals(normalized)) return "读者可以付费提前查看，未购买时会在到期后自动公开";
        return "所有读者均可直接查看，无需支付或等待";
    }

    public static boolean valid(String value, double price, String unlockAtIso) {
        return (!needsPayment(value) || price > 0)
            && (!needsSchedule(value) || (unlockAtIso != null && !unlockAtIso.trim().isEmpty()));
    }

    private static String suffix(String value) {
        return value == null || value.trim().isEmpty() ? "" : " · " + value.trim();
    }

    private static String money(double value) {
        if (Math.rint(value) == value) return String.valueOf((long) value);
        return String.format(Locale.ROOT, "%.2f", value).replaceAll("0+$", "").replaceAll("\\.$", "");
    }
}
