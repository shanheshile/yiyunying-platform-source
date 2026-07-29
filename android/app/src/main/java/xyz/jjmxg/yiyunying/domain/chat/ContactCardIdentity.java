package xyz.jjmxg.yiyunying.domain.chat;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

/** Builds contact-card metadata from the profile that actually owns the card. */
public final class ContactCardIdentity {
    private ContactCardIdentity() {
    }

    public static JsonObject metadata(
        JsonObject profile,
        long fallbackUserId,
        String fallbackAccount,
        boolean self
    ) {
        JsonObject outer = profile == null ? new JsonObject() : profile;
        JsonObject nested = object(outer, "user");
        JsonObject source = nested.size() > 0 ? nested : outer;

        long userId = firstLong(source, outer, "id", "user_id");
        if (userId <= 0L) userId = fallbackUserId;

        String account = first(source, outer, "account", "account_name", "username");
        if (account.isEmpty()) account = clean(fallbackAccount);

        String uid = first(source, outer, "uid", "public_no", "user_uid");
        if (uid.isEmpty() && userId > 0L) uid = String.valueOf(userId);

        String nickname = first(source, outer, "nickname", "display_name");
        String avatar = first(source, outer, "avatar", "avatar_url", "profile_avatar");
        String displayName = !account.isEmpty() ? account : !nickname.isEmpty() ? nickname : uid;

        JsonObject metadata = new JsonObject();
        metadata.addProperty("user_id", userId);
        metadata.addProperty("uid", uid);
        metadata.addProperty("account", account);
        metadata.addProperty("nickname", nickname);
        metadata.addProperty("display_name", displayName);
        metadata.addProperty("avatar", avatar);
        metadata.addProperty("avatar_url", avatar);
        metadata.addProperty("is_self", self);
        return metadata;
    }

    public static String displayName(JsonObject metadata) {
        return first(metadata, metadata, "display_name", "account", "nickname", "uid");
    }

    private static JsonObject object(JsonObject value, String key) {
        if (value == null || !value.has(key)) return new JsonObject();
        JsonElement element = value.get(key);
        return element != null && element.isJsonObject() ? element.getAsJsonObject() : new JsonObject();
    }

    private static String first(JsonObject primary, JsonObject fallback, String... keys) {
        for (String key : keys) {
            String value = string(primary, key);
            if (!value.isEmpty()) return value;
            if (fallback != primary) {
                value = string(fallback, key);
                if (!value.isEmpty()) return value;
            }
        }
        return "";
    }

    private static long firstLong(JsonObject primary, JsonObject fallback, String... keys) {
        for (String key : keys) {
            long value = longValue(primary, key);
            if (value > 0L) return value;
            if (fallback != primary) {
                value = longValue(fallback, key);
                if (value > 0L) return value;
            }
        }
        return 0L;
    }

    private static String string(JsonObject value, String key) {
        if (value == null || !value.has(key)) return "";
        try {
            JsonElement element = value.get(key);
            return element == null || element.isJsonNull() ? "" : clean(element.getAsString());
        } catch (RuntimeException ignored) {
            return "";
        }
    }

    private static long longValue(JsonObject value, String key) {
        if (value == null || !value.has(key)) return 0L;
        try {
            JsonElement element = value.get(key);
            return element == null || element.isJsonNull() ? 0L : element.getAsLong();
        } catch (RuntimeException ignored) {
            return 0L;
        }
    }

    private static String clean(String value) {
        return value == null ? "" : value.trim();
    }
}
