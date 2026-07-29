package xyz.jjmxg.yiyunying.core;

import android.content.Context;
import android.content.SharedPreferences;

public final class ChatBackgroundStore {
    private static final String PREFERENCES = "appearance";
    private static final String GLOBAL_KEY = "chat_background_uri";
    private static final String CONVERSATION_PREFIX = "chat_background_conversation_";
    private static final String SYSTEM_DEFAULT_VALUE = "__system_default__";

    private ChatBackgroundStore() { }

    public static String global(Context context) {
        return preferences(context).getString(GLOBAL_KEY, "");
    }

    public static void setGlobal(Context context, String uri) {
        putOrRemove(preferences(context), GLOBAL_KEY, uri);
    }

    public static String conversation(Context context, String identity) {
        if (identity == null || identity.trim().isEmpty()) return "";
        String value = preferences(context).getString(CONVERSATION_PREFIX + safe(identity), "");
        return SYSTEM_DEFAULT_VALUE.equals(value) ? "" : value;
    }

    public static boolean hasConversation(Context context, String identity) {
        return identity != null && !identity.trim().isEmpty()
            && preferences(context).contains(CONVERSATION_PREFIX + safe(identity));
    }

    public static void setConversation(Context context, String identity, String uri) {
        if (identity == null || identity.trim().isEmpty()) return;
        putOrRemove(preferences(context), CONVERSATION_PREFIX + safe(identity), uri);
    }

    /** Forces this conversation to use the built-in background, even when a global image is set. */
    public static void setConversationSystemDefault(Context context, String identity) {
        if (identity == null || identity.trim().isEmpty()) return;
        preferences(context).edit()
            .putString(CONVERSATION_PREFIX + safe(identity), SYSTEM_DEFAULT_VALUE)
            .apply();
    }

    public static boolean usesSystemDefault(Context context, String identity) {
        if (identity == null || identity.trim().isEmpty()) return false;
        return SYSTEM_DEFAULT_VALUE.equals(preferences(context).getString(
            CONVERSATION_PREFIX + safe(identity), ""));
    }

    public static void clearConversation(Context context, String identity) {
        if (identity == null || identity.trim().isEmpty()) return;
        preferences(context).edit().remove(CONVERSATION_PREFIX + safe(identity)).apply();
    }

    /** Removes every per-conversation override so the selected global background is visible everywhere. */
    public static void clearAllConversationOverrides(Context context) {
        SharedPreferences preferences = preferences(context);
        SharedPreferences.Editor editor = preferences.edit();
        for (String key : preferences.getAll().keySet()) {
            if (key != null && key.startsWith(CONVERSATION_PREFIX)) editor.remove(key);
        }
        editor.apply();
    }

    public static String resolved(Context context, String identity) {
        if (usesSystemDefault(context, identity)) return "";
        if (hasConversation(context, identity)) return conversation(context, identity);
        return global(context);
    }

    /** Returns whether an appearance preference can change the current chat background. */
    public static boolean isBackgroundPreference(String key) {
        return GLOBAL_KEY.equals(key)
            || (key != null && key.startsWith(CONVERSATION_PREFIX));
    }

    private static void putOrRemove(SharedPreferences preferences, String key, String value) {
        SharedPreferences.Editor editor = preferences.edit();
        if (value == null || value.trim().isEmpty()) editor.remove(key);
        else editor.putString(key, value.trim());
        editor.apply();
    }

    private static String safe(String value) {
        return value.trim().replaceAll("[^a-zA-Z0-9:_-]", "_");
    }

    private static SharedPreferences preferences(Context context) {
        return context.getApplicationContext().getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE);
    }
}
