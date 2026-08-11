package xyz.jjmxg.yiyunying.ui.home;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

/** Keeps home shortcuts aligned with the app feature switches returned by public bootstrap. */
final class HomeFeaturePolicy {
    private HomeFeaturePolicy() { }

    static boolean enabled(JsonObject features, String code) {
        if (features == null || code == null || !features.has(code)) return true;
        JsonElement value = features.get(code);
        if (value == null || value.isJsonNull()) return true;
        try {
            if (value.isJsonObject()) {
                JsonObject envelope = value.getAsJsonObject();
                if (!envelope.has("enabled") || envelope.get("enabled").isJsonNull()) return true;
                return envelope.get("enabled").getAsBoolean();
            }
            return value.getAsBoolean();
        } catch (RuntimeException ignored) {
            // A malformed optional flag must not remove navigation on older deployments.
            return true;
        }
    }

    static boolean actionEnabled(JsonObject features, String module) {
        if (module == null) return true;
        switch (module) {
            case "add_friend":
            case "create_group":
                return enabled(features, "messages") && enabled(features, "social");
            case "create_chatroom":
                return enabled(features, "messages") && enabled(features, "chat_rooms");
            case "moments":
            case "moments_compose":
            case "favorites_center":
                return enabled(features, "social");
            case "short_videos":
                return enabled(features, "social") && explicitlyEnabled(features, "short_videos");
            case "short_video_publish":
                return enabled(features, "social")
                    && explicitlyEnabled(features, "short_videos")
                    && explicitlyEnabled(features, "short_video_publish");
            case "forum_posts": return enabled(features, "forum");
            case "bounties": return enabled(features, "bounties");
            case "resources":
            case "resource_hall": return enabled(features, "resources");
            case "polls": return enabled(features, "votes");
            case "shop_goods":
            case "orders": return enabled(features, "shop");
            case "red_packets": return enabled(features, "red_packets");
            case "lottery": return enabled(features, "lottery");
            case "card_redeem": return enabled(features, "cards");
            case "profile": return enabled(features, "user_profile");
            case "notifications": return enabled(features, "notifications");
            case "feedbacks": return enabled(features, "feedback");
            case "invites": return enabled(features, "sign_invite");
            case "upload": return enabled(features, "remote_files");
            case "wallet": return enabled(features, "payments");
            default: return true;
        }
    }

    static boolean anyActionEnabled(JsonObject features, String... modules) {
        if (modules == null || modules.length == 0) return false;
        for (String module : modules) {
            if (actionEnabled(features, module)) return true;
        }
        return false;
    }

    private static boolean explicitlyEnabled(JsonObject features, String code) {
        if (features == null || code == null || !features.has(code)) return false;
        JsonElement value = features.get(code);
        if (value == null || value.isJsonNull()) return false;
        try {
            if (!value.isJsonObject()) return value.getAsBoolean();
            JsonObject envelope = value.getAsJsonObject();
            if (envelope.has("effective_enabled") && !envelope.get("effective_enabled").isJsonNull()) {
                return envelope.get("effective_enabled").getAsBoolean();
            }
            return envelope.has("enabled") && !envelope.get("enabled").isJsonNull()
                && envelope.get("enabled").getAsBoolean();
        } catch (RuntimeException ignored) {
            return false;
        }
    }
}
