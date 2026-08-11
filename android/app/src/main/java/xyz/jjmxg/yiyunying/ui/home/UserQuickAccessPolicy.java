package xyz.jjmxg.yiyunying.ui.home;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

/** Resolves the authenticated user's effective permission for shell-level quick entries. */
final class UserQuickAccessPolicy {
    static final String PRIVATE_CHAT = "private_chat";
    static final String GROUP_CHAT = "group_chat";
    static final String RED_PACKETS = "red_packets";
    static final String FORUM = "forum";
    static final String SHORT_VIDEOS = "short_videos";
    static final String SHOP = "shop";

    private UserQuickAccessPolicy() { }

    static boolean visible(JsonObject features, String entry) {
        if (entry == null) return false;
        switch (entry) {
            case PRIVATE_CHAT:
                // Selecting a friend uses the social directory; opening the conversation uses messages.
                return effectiveOrLegacy(features, "social")
                    && effectiveOrLegacy(features, "messages");
            case GROUP_CHAT:
                // Group lists and group messages are governed by the independent chat_rooms
                // capability. Disabling private messages must not hide an otherwise valid group.
                return effectiveOrLegacy(features, "chat_rooms");
            case RED_PACKETS:
                return effectiveOrLegacy(features, "red_packets");
            case FORUM:
                return effectiveOrLegacy(features, "forum");
            case SHORT_VIDEOS:
                // Short video is a newly introduced capability and deliberately fails closed.
                return effectiveOrLegacy(features, "social")
                    && HomeFeaturePolicy.actionEnabled(features, "short_videos");
            case SHOP:
                return effectiveOrLegacy(features, "shop");
            default:
                return false;
        }
    }

    private static boolean effectiveOrLegacy(JsonObject features, String code) {
        if (features != null && code != null && features.has(code)) {
            JsonElement value = features.get(code);
            try {
                if (value != null && value.isJsonObject()) {
                    JsonObject envelope = value.getAsJsonObject();
                    if (envelope.has("effective_enabled")
                        && !envelope.get("effective_enabled").isJsonNull()) {
                        return envelope.get("effective_enabled").getAsBoolean();
                    }
                }
            } catch (RuntimeException ignored) {
                // Legacy capabilities keep the existing compatibility behavior for malformed flags.
            }
        }
        return HomeFeaturePolicy.enabled(features, code);
    }
}
