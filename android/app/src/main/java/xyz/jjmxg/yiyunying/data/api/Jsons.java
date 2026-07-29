package xyz.jjmxg.yiyunying.data.api;

import com.google.gson.Gson;
import com.google.gson.GsonBuilder;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonNull;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Map;

public final class Jsons {
    public static final Gson GSON = new GsonBuilder().serializeNulls().disableHtmlEscaping().create();
    public static final Gson PRETTY = new GsonBuilder().serializeNulls().disableHtmlEscaping().setPrettyPrinting().create();

    private Jsons() {
    }

    public static JsonObject object() {
        return new JsonObject();
    }

    public static JsonElement parse(String value) {
        if (value == null || value.trim().isEmpty()) {
            return JsonNull.INSTANCE;
        }
        return JsonParser.parseString(value);
    }

    public static String string(JsonObject object, String key) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) {
            return "";
        }
        try {
            return object.get(key).getAsString();
        } catch (RuntimeException ignored) {
            return object.get(key).toString();
        }
    }

    public static long longValue(JsonObject object, String key) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) {
            return 0L;
        }
        try {
            return object.get(key).getAsLong();
        } catch (RuntimeException ignored) {
            return 0L;
        }
    }

    public static int intValue(JsonObject object, String key, int fallback) {
        if (object == null || !object.has(key) || object.get(key).isJsonNull()) {
            return fallback;
        }
        try {
            return object.get(key).getAsInt();
        } catch (RuntimeException ignored) {
            return fallback;
        }
    }

    public static JsonObject object(JsonObject parent, String key) {
        if (parent != null && parent.has(key) && parent.get(key).isJsonObject()) {
            return parent.getAsJsonObject(key);
        }
        return new JsonObject();
    }

    public static JsonArray array(JsonObject parent, String key) {
        if (parent != null && parent.has(key) && parent.get(key).isJsonArray()) {
            return parent.getAsJsonArray(key);
        }
        return new JsonArray();
    }

    public static List<Map.Entry<String, JsonElement>> sortedEntries(JsonObject object) {
        if (object == null) {
            return Collections.emptyList();
        }
        List<Map.Entry<String, JsonElement>> entries = new ArrayList<>(object.entrySet());
        entries.sort(Map.Entry.comparingByKey());
        return entries;
    }
}
