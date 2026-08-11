package xyz.jjmxg.yiyunying.ui.moment;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

/** Pure policy helpers shared by the short-video feed and its focused tests. */
final class ShortVideoFeaturePolicy {
    static final String CONTENT_KIND = "short_video";

    private ShortVideoFeaturePolicy() { }

    static boolean enabled(JsonObject features, String code) {
        if (features == null || code == null || !features.has(code)) return false;
        JsonElement value = features.get(code);
        if (value == null || value.isJsonNull()) return false;
        try {
            if (value.isJsonObject()) {
                JsonObject envelope = value.getAsJsonObject();
                if (envelope.has("effective_enabled") && !envelope.get("effective_enabled").isJsonNull()) {
                    return envelope.get("effective_enabled").getAsBoolean();
                }
                if (envelope.has("enabled") && !envelope.get("enabled").isJsonNull()) {
                    return envelope.get("enabled").getAsBoolean();
                }
                return false;
            }
            return value.getAsBoolean();
        } catch (RuntimeException ignored) {
            return false;
        }
    }

    static int maxSelection(boolean shortVideoMode) {
        return shortVideoMode ? 1 : 9;
    }

    static String contentKind(boolean shortVideoMode) {
        return shortVideoMode ? CONTENT_KIND : "moment";
    }

    static boolean acceptsMime(boolean shortVideoMode, String mimeType) {
        String value = mimeType == null ? "" : mimeType.toLowerCase(java.util.Locale.ROOT);
        return shortVideoMode ? value.startsWith("video/")
            : value.startsWith("image/") || value.startsWith("video/");
    }
}
