package xyz.jjmxg.yiyunying.ui.upload;

import android.content.Context;
import android.content.SharedPreferences;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.util.ArrayList;
import java.util.List;
import java.util.Map;

import xyz.jjmxg.yiyunying.core.AppAccess;

final class DownloadHistoryStore {
    private DownloadHistoryStore() { }

    static synchronized void record(Context context, long id, String name, String url, String category) {
        List<JsonObject> items = list(context);
        JsonObject item = new JsonObject();
        item.addProperty("download_id", id);
        item.addProperty("name", name);
        item.addProperty("url", url);
        item.addProperty("category", category);
        item.addProperty("created_at_ms", System.currentTimeMillis());
        items.add(0, item);
        while (items.size() > 500) items.remove(items.size() - 1);
        save(context, items);
    }

    static synchronized List<JsonObject> list(Context context) {
        List<JsonObject> items = new ArrayList<>();
        String raw = preferences(context).getString(key(context), "[]");
        try {
            JsonArray array = JsonParser.parseString(raw).getAsJsonArray();
            for (JsonElement element : array) if (element.isJsonObject()) items.add(element.getAsJsonObject());
        } catch (RuntimeException ignored) { }
        return items;
    }

    static synchronized void remove(Context context, long downloadId) {
        List<JsonObject> items = list(context);
        items.removeIf(item -> item.has("download_id") && item.get("download_id").getAsLong() == downloadId);
        save(context, items);
    }

    static synchronized List<Long> allDownloadIds(Context context) {
        List<Long> ids = new ArrayList<>();
        for (Map.Entry<String, ?> entry : preferences(context).getAll().entrySet()) {
            try {
                JsonArray array = JsonParser.parseString(String.valueOf(entry.getValue())).getAsJsonArray();
                for (JsonElement element : array) {
                    if (element.isJsonObject() && element.getAsJsonObject().has("download_id")) {
                        ids.add(element.getAsJsonObject().get("download_id").getAsLong());
                    }
                }
            } catch (RuntimeException ignored) { }
        }
        return ids;
    }

    static synchronized void clearAll(Context context) {
        preferences(context).edit().clear().apply();
    }

    private static void save(Context context, List<JsonObject> items) {
        JsonArray array = new JsonArray();
        for (JsonObject item : items) array.add(item);
        preferences(context).edit().putString(key(context), array.toString()).apply();
    }

    private static SharedPreferences preferences(Context context) {
        return context.getSharedPreferences("download_history", Context.MODE_PRIVATE);
    }

    private static String key(Context context) {
        return AppAccess.from(context).session().role().wireName() + ":" + AppAccess.from(context).session().actorId();
    }
}
