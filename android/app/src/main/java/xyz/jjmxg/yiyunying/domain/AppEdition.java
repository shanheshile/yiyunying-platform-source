package xyz.jjmxg.yiyunying.domain;

import xyz.jjmxg.yiyunying.BuildConfig;

public final class AppEdition {
    private AppEdition() {
    }

    public static String code() {
        return BuildConfig.APP_EDITION;
    }

    public static Role role() {
        return Role.fromWireName(BuildConfig.FIXED_ROLE);
    }

    public static int requiredPlatformLevel() {
        return BuildConfig.REQUIRED_PLATFORM_LEVEL;
    }

    public static boolean allowsSelfRegistration() {
        return BuildConfig.ALLOW_SELF_REGISTER;
    }

    public static String defaultAccount() {
        return BuildConfig.DEFAULT_ACCOUNT;
    }

    public static String platformLevelError(Role role, int actualLevel) {
        int required = requiredPlatformLevel();
        if (role != Role.PLATFORM || required == 0 || actualLevel == required) return "";
        return required == 1
            ? "该账号不是一级平台所有者，请使用授权平台版登录"
            : "该账号不是二级授权平台，请使用平台总控版登录";
    }

    public static boolean acceptsSession(Role role, int actorLevel) {
        if (role != role()) return false;
        return role != Role.PLATFORM || requiredPlatformLevel() == actorLevel;
    }

    public static int actorLevel(Role role, int platformLevel) {
        if (role == Role.PLATFORM) return platformLevel;
        if (role == Role.ADMIN) return 3;
        return 4;
    }

    public static boolean canOpenPlatformModule(int platformLevel, String moduleId) {
        if (platformLevel != 2) return true;
        return !"operators".equals(moduleId) && !"data_console".equals(moduleId);
    }
}
