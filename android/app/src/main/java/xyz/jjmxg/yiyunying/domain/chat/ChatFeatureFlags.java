package xyz.jjmxg.yiyunying.domain.chat;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

/** Parses public bootstrap feature values in both legacy boolean and envelope formats. */
public final class ChatFeatureFlags {
    private ChatFeatureFlags() { }

    public static boolean enabled(JsonObject features, String code, boolean fallback) {
        if (features == null || code == null || !features.has(code)) return fallback;
        return enabled(features.get(code), fallback);
    }

    public static boolean enabled(JsonElement value, boolean fallback) {
        try {
            if (value == null || value.isJsonNull()) return fallback;
            if (value.isJsonObject()) {
                JsonObject object = value.getAsJsonObject();
                return object.has("enabled") ? enabled(object.get("enabled"), fallback) : fallback;
            }
            return value.isJsonPrimitive() && value.getAsJsonPrimitive().isBoolean()
                ? value.getAsBoolean() : fallback;
        } catch (RuntimeException ignored) {
            return fallback;
        }
    }
}
