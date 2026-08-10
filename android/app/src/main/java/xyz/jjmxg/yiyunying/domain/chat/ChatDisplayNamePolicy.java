package xyz.jjmxg.yiyunying.domain.chat;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.Locale;

/** Keeps chat identity and recall notices stable across live, cached and legacy payloads. */
public final class ChatDisplayNamePolicy {
    private ChatDisplayNamePolicy() { }

    public static boolean isRecalled(JsonObject item) {
        if (item == null) return false;
        return "recall".equalsIgnoreCase(string(item, "content_type"))
            || bool(item, "recalled") || bool(item, "is_recalled");
    }

    public static String senderName(JsonObject item, boolean mine) {
        String preferred = first(item,
            "sender_remark", "remark",
            "sender_nickname", "nickname",
            "sender_account", "account", "uid",
            "sender_display_name", "sender_name");
        if (!preferred.isEmpty()) return preferred;
        if (mine) return "我";
        String type = string(item, "sender_type").toLowerCase(Locale.ROOT);
        if ("admin".equals(type)) return "管理员";
        if ("platform".equals(type)) return "平台";
        if ("system".equals(type)) return "系统";
        return "用户";
    }

    public static String recallNotice(JsonObject item, long viewerUserId) {
        long actorUserId = longValue(item, "sender_id");
        if (actorUserId <= 0L) actorUserId = longValue(item, "user_id");
        if (viewerUserId > 0L && actorUserId == viewerUserId) return "你撤回了一条消息";

        String actor = first(item,
            "recall_actor_name", "sender_remark", "remark",
            "sender_nickname", "nickname",
            "sender_account", "account", "uid",
            "sender_display_name", "sender_name");
        if (actor.isEmpty() || "系统".equals(actor) || "系统消息".equals(actor)) actor = "对方";
        return actor + "撤回了一条消息";
    }

    private static String first(JsonObject item, String... keys) {
        if (item == null) return "";
        for (String key : keys) {
            String value = string(item, key);
            if (value.isEmpty()) continue;
            if (isPlaceholderName(key, value)) continue;
            return value;
        }
        return "";
    }

    private static boolean isPlaceholderName(String key, String value) {
        if (!("sender_name".equals(key) || "sender_display_name".equals(key)
            || "sender_nickname".equals(key) || "nickname".equals(key))) return false;
        return "user".equalsIgnoreCase(value);
    }

    private static String string(JsonObject item, String key) {
        if (item == null || !item.has(key)) return "";
        try {
            JsonElement value = item.get(key);
            return value == null || value.isJsonNull() ? "" : value.getAsString().trim();
        } catch (RuntimeException ignored) {
            return "";
        }
    }

    private static boolean bool(JsonObject item, String key) {
        if (item == null || !item.has(key)) return false;
        try { return !item.get(key).isJsonNull() && item.get(key).getAsBoolean(); }
        catch (RuntimeException ignored) { return false; }
    }

    private static long longValue(JsonObject item, String key) {
        if (item == null || !item.has(key)) return 0L;
        try { return item.get(key).isJsonNull() ? 0L : item.get(key).getAsLong(); }
        catch (RuntimeException ignored) { return 0L; }
    }
}
