package xyz.jjmxg.yiyunying.ui.main;

import java.util.Arrays;
import java.util.Collections;
import java.util.List;
import java.util.Locale;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import xyz.jjmxg.yiyunying.domain.Role;

/** Stable Chinese information architecture for the administrator shell. */
public final class ManagementNavigationPolicy {
    private static final String[] ADMIN_TAB_TITLES = {"主页", "源码示例", "交流", "我的"};
    private static final String[] LEGACY_TAB_TITLES = {"应用", "源码", "交流", "我的"};
    private static final String[] APP_TYPE_CODES = {"general", "community", "business", "tool"};
    private static final String[] APP_TYPE_LABELS = {"综合应用", "社区应用", "商业应用", "工具应用"};
    private static final List<String> SOURCE_CATEGORIES = Collections.unmodifiableList(Arrays.asList(
        "Android Java 源码", "iApp 源码", "Lua 源码", "Web 源码", "PHP 源码",
        "Python 源码", "JavaScript 源码", "HarmonyOS 源码", "iOS 源码",
        "C/C++ 源码", "数据库源码", "通用模块", "其他源码"
    ));

    public static final class SystemEntry {
        public final String title;
        public final String description;
        public final String primaryModule;
        public final List<String> embeddedModules;

        SystemEntry(String title, String description, String primaryModule, String... embeddedModules) {
            this.title = title;
            this.description = description;
            this.primaryModule = primaryModule;
            this.embeddedModules = Collections.unmodifiableList(Arrays.asList(embeddedModules));
        }
    }

    private static final List<SystemEntry> SYSTEMS = Collections.unmodifiableList(Arrays.asList(
        new SystemEntry("用户系统", "账号、资料、标签、会员与权限", "users", "user_tags", "entitlement"),
        new SystemEntry("邮箱系统", "注册邮箱、邮箱验证与账号联系方式", "app_settings", "users"),
        new SystemEntry("论坛系统", "板块、分类、帖子、评论、审核与举报", "forum_posts", "forum_plates", "forum_categories", "forum_comments", "reports"),
        new SystemEntry("文档系统", "文档、分享、远程文档与回收", "documents", "document_shares", "remote_files"),
        new SystemEntry("好友系统", "好友账号、关系消息与通信审计", "users", "messages", "reports"),
        new SystemEntry("群聊系统", "群资料、群头像、成员、消息与入群审核", "chat_rooms", "messages", "reports"),
        new SystemEntry("聊天系统", "私聊、客服、消息与机器人能力", "messages", "service_sessions", "bot_qa", "ai_knowledge"),
        new SystemEntry("安全系统", "权限、域名、远程配置、接口与操作审计", "app_settings", "domains", "api_logs", "operation_logs"),
        new SystemEntry("卡密系统", "卡密批次、卡密、兑换与绑定记录", "card_batches", "cards", "card_redeem_logs", "card_login_bindings"),
        new SystemEntry("云仓库", "上传文件、远程文件与容量管理", "remote_files", "uploads", "documents"),
        new SystemEntry("商城系统", "商品、应用商店、订单、支付与评论", "shop_goods", "store_apps", "orders", "payments", "shop_goods_comments"),
        new SystemEntry("公告·更新·维护", "公告、轮播图、版本更新、维护与远程配置", "notices", "banners", "versions", "maintenances", "remote_configs"),
        new SystemEntry("反馈系统", "用户反馈、回复、举报与处理进度", "feedbacks", "reports", "platform_feedbacks"),
        new SystemEntry("在线与数据统计", "在线会话、用户规模、接口与运营数据", "statistics", "service_sessions", "api_logs", "operation_logs"),
        new SystemEntry("审核系统", "论坛、动态、短视频、资源、商店与评论审核", "forum_posts", "forum_comments", "moments", "moment_comments", "short_videos", "resources", "store_apps"),
        new SystemEntry("举报系统", "论坛、资源、商品与其他内容治理", "reports", "forum_report_tags", "resource_comments", "shop_goods_comments")
    ));

    private ManagementNavigationPolicy() { }

    public static boolean useAdminWorkbench(Role role) {
        return role == Role.ADMIN;
    }

    public static String[] tabTitles(Role role) {
        return (useAdminWorkbench(role) ? ADMIN_TAB_TITLES : LEGACY_TAB_TITLES).clone();
    }

    public static List<SystemEntry> systems() { return SYSTEMS; }

    public static String appTypeName(String value) {
        for (int index = 0; index < APP_TYPE_CODES.length; index++) {
            if (APP_TYPE_CODES[index].equals(value) || APP_TYPE_LABELS[index].equals(value)) {
                return APP_TYPE_LABELS[index];
            }
        }
        return "综合应用";
    }

    public static String[] appTypeLabels() { return APP_TYPE_LABELS.clone(); }

    public static String appTypeCode(int index) {
        return APP_TYPE_CODES[Math.max(0, Math.min(index, APP_TYPE_CODES.length - 1))];
    }

    public static List<String> sourceCategories() { return SOURCE_CATEGORIES; }

    /** Fail-closed renderer gate for administrator workbench pages. */
    public static boolean permissionAllowed(JsonObject payload, String code) {
        if (payload == null || code == null || code.trim().isEmpty()) return false;
        try {
            JsonObject permissions = payload.has("permissions") && payload.get("permissions").isJsonObject()
                ? payload.getAsJsonObject("permissions") : new JsonObject();
            if (!permissions.has(code) || permissions.get(code).isJsonNull()) return false;
            JsonElement value = permissions.get(code);
            if (value.isJsonObject()) {
                JsonObject item = value.getAsJsonObject();
                return item.has("allowed") && !item.get("allowed").isJsonNull()
                    && item.get("allowed").getAsBoolean();
            }
            return value.getAsBoolean();
        } catch (RuntimeException ignored) {
            return false;
        }
    }

    public static boolean validSourceCategory(String value) {
        return SOURCE_CATEGORIES.contains(value);
    }

    /** Prevents a dashboard button inside the dashboard shell from nesting the shell again. */
    public static boolean safeChildModule(String moduleId) {
        if (moduleId == null) return false;
        String normalized = moduleId.trim();
        return !normalized.isEmpty() && !"dashboard".equalsIgnoreCase(normalized);
    }

    /** Explicit source-directory matching; notably, "profile" is not a file module. */
    public static boolean sourceDirectoryModule(String moduleId, String group) {
        String id = moduleId == null ? "" : moduleId.trim().toLowerCase(Locale.ROOT);
        if ("文件".equals(group) || "开发".equals(group)) return true;
        if (id.startsWith("api_") || id.startsWith("store_app")) return true;
        return containsIdToken(id, "resource", "resources", "source", "sources", "upload", "uploads",
            "file", "files", "document", "documents", "sdk", "template", "templates", "code");
    }

    public static String permissionForModule(String id) {
        if (id == null) return "";
        if (id.equals("apps") || id.equals("app_settings") || id.equals("domains")) return "apps.manage";
        if (id.equals("users") || id.equals("user_tags")) return "users.manage";
        if (id.startsWith("document")) return "documents.manage";
        if (contains(id, "notice", "version", "maintenance", "banner", "remote_config")) return "content.manage";
        if (contains(id, "resource", "store_app", "store_categories")) return "resources.manage";
        if (contains(id, "forum", "moment", "short_video", "report")) return "forum.manage";
        if (contains(id, "message", "service_session", "chat_room")) return "communication.manage";
        if (contains(id, "bount", "poll", "red_packet", "lottery", "hierarchy_activit", "vote")) return "activities.manage";
        if (contains(id, "card")) return "cards.manage";
        if (contains(id, "order", "payment", "withdraw", "shop_good")) return "commerce.manage";
        if (contains(id, "remote_file", "upload", "feedback", "bot_qa", "ai_knowledge")) return "files.manage";
        if (contains(id, "statistic", "api_log", "operation_log")) return "statistics.view";
        return "";
    }

    private static boolean contains(String value, String... needles) {
        for (String needle : needles) if (value.contains(needle)) return true;
        return false;
    }

    private static boolean containsIdToken(String value, String... tokens) {
        for (String token : tokens) {
            if (value.equals(token) || value.startsWith(token + "_") || value.endsWith("_" + token)
                || value.contains("_" + token + "_")) return true;
        }
        return false;
    }
}
