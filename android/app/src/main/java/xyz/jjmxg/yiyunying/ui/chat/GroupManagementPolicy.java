package xyz.jjmxg.yiyunying.ui.chat;

/**
 * Pure role policy shared by the group profile UI. Server-side authorization remains authoritative;
 * this class only keeps unavailable controls out of the user interface.
 */
public final class GroupManagementPolicy {
    public static final String SYSTEM_ADMIN = "system_admin";

    private GroupManagementPolicy() { }

    public static boolean isManager(String role) {
        return SYSTEM_ADMIN.equals(role) || "owner".equals(role) || "admin".equals(role);
    }

    public static boolean canInvite(String role, boolean allowMemberInvite) {
        return isManager(role) || allowMemberInvite;
    }

    public static boolean canChangeRole(String role, String targetRole, boolean self) {
        if (self || "owner".equals(targetRole)) return false;
        return SYSTEM_ADMIN.equals(role) || "owner".equals(role);
    }

    public static boolean canModerate(String role, String targetRole, boolean self) {
        if (self || "owner".equals(targetRole)) return false;
        if (SYSTEM_ADMIN.equals(role) || "owner".equals(role)) return true;
        return "admin".equals(role) && "member".equals(targetRole);
    }
}
