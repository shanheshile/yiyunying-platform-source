package xyz.jjmxg.yiyunying.domain;

public enum Role {
    PLATFORM("platform", "平台"),
    ADMIN("admin", "管理员"),
    USER("user", "用户");

    private final String wireName;
    private final String displayName;

    Role(String wireName, String displayName) {
        this.wireName = wireName;
        this.displayName = displayName;
    }

    public String wireName() {
        return wireName;
    }

    public String displayName() {
        return displayName;
    }

    public String loginPath() {
        return "/api/" + wireName + "/login";
    }

    public String logoutPath() {
        return "/api/" + wireName + "/logout";
    }

    public String mePath() {
        return "/api/" + wireName + "/me";
    }

    public static Role fromWireName(String value) {
        if (value != null) {
            for (Role role : values()) {
                if (role.wireName.equalsIgnoreCase(value)) {
                    return role;
                }
            }
        }
        return USER;
    }
}
