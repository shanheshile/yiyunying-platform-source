package xyz.jjmxg.yiyunying.ui.chat;

import android.content.Context;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.ArrayList;
import java.util.Collection;
import java.util.Comparator;
import java.util.List;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import xyz.jjmxg.yiyunying.data.api.Jsons;

/** Durable, account-isolated snapshots used to render a conversation before network sync. */
final class ChatMessageSnapshotStore {
    interface LoadCallback {
        void onLoaded(List<JsonObject> messages);
    }

    private static final int MAX_MESSAGES = 300;
    private static final long MAX_BYTES = 6L * 1024L * 1024L;
    private static final ExecutorService IO = Executors.newSingleThreadExecutor(runnable -> {
        Thread thread = new Thread(runnable, "chat-snapshot-io");
        thread.setDaemon(true);
        return thread;
    });

    private ChatMessageSnapshotStore() { }

    static void loadAsync(
        Context context,
        String accountIdentity,
        Collection<String> scopeKeys,
        LoadCallback callback
    ) {
        Context application = context.getApplicationContext();
        String accountHash = digest(accountIdentity);
        List<String> keys = normalizedKeys(scopeKeys);
        IO.execute(() -> callback.onLoaded(loadLatest(application, accountHash, keys)));
    }

    static void saveAsync(
        Context context,
        String accountIdentity,
        Collection<String> scopeKeys,
        List<JsonObject> source
    ) {
        if (source == null || source.isEmpty()) return;
        Context application = context.getApplicationContext();
        String accountHash = digest(accountIdentity);
        List<String> keys = normalizedKeys(scopeKeys);
        if (keys.isEmpty()) return;
        List<JsonObject> snapshot = new ArrayList<>();
        int from = Math.max(0, source.size() - MAX_MESSAGES);
        for (int index = from; index < source.size(); index++) {
            JsonObject item = source.get(index);
            if (item != null) snapshot.add(item.deepCopy());
        }
        IO.execute(() -> save(application, accountHash, keys, snapshot));
    }

    private static List<JsonObject> loadLatest(Context context, String accountHash, List<String> keys) {
        File directory = directory(context);
        JsonObject newest = null;
        long newestSavedAt = -1L;
        for (String key : keys) {
            JsonObject candidate = read(fileFor(directory, accountHash, key));
            if (candidate == null || !accountHash.equals(string(candidate, "account_hash"))) continue;
            long savedAt = longValue(candidate, "saved_at");
            if (newest == null || savedAt > newestSavedAt) {
                newest = candidate;
                newestSavedAt = savedAt;
            }
        }
        List<JsonObject> result = new ArrayList<>();
        if (newest == null) return result;
        JsonArray items = newest.has("items") && newest.get("items").isJsonArray()
            ? newest.getAsJsonArray("items") : new JsonArray();
        for (JsonElement element : items) {
            if (element.isJsonObject()) result.add(element.getAsJsonObject().deepCopy());
        }
        result.sort(Comparator.comparingLong(ChatMessageSnapshotStore::messageId));
        return result;
    }

    private static void save(
        Context context,
        String accountHash,
        List<String> keys,
        List<JsonObject> messages
    ) {
        JsonObject value = new JsonObject();
        value.addProperty("schema", 1);
        value.addProperty("account_hash", accountHash);
        value.addProperty("saved_at", System.currentTimeMillis());
        JsonArray items = new JsonArray();
        for (JsonObject message : messages) items.add(message);
        value.add("items", items);
        byte[] bytes = Jsons.GSON.toJson(value).getBytes(StandardCharsets.UTF_8);
        if (bytes.length == 0 || bytes.length > MAX_BYTES) return;

        File directory = directory(context);
        for (String key : keys) {
            File target = fileFor(directory, accountHash, key);
            File temporary = new File(directory, target.getName() + ".tmp");
            try (FileOutputStream output = new FileOutputStream(temporary)) {
                output.write(bytes);
                output.flush();
                try {
                    output.getFD().sync();
                } catch (Exception ignored) {
                    // Some device and test file systems do not expose fsync; the atomic rename still protects parsing.
                }
            } catch (Exception ignored) {
                temporary.delete();
                continue;
            }
            if (target.exists() && !target.delete()) {
                temporary.delete();
                continue;
            }
            if (!temporary.renameTo(target)) temporary.delete();
        }
    }

    private static JsonObject read(File source) {
        if (!source.isFile() || source.length() <= 0L || source.length() > MAX_BYTES) return null;
        try (FileInputStream input = new FileInputStream(source)) {
            byte[] bytes = new byte[(int) source.length()];
            int offset = 0;
            while (offset < bytes.length) {
                int read = input.read(bytes, offset, bytes.length - offset);
                if (read < 0) break;
                offset += read;
            }
            if (offset != bytes.length) return null;
            JsonElement parsed = JsonParser.parseString(new String(bytes, StandardCharsets.UTF_8));
            return parsed.isJsonObject() ? parsed.getAsJsonObject() : null;
        } catch (Exception ignored) {
            return null;
        }
    }

    private static File directory(Context context) {
        File directory = new File(context.getFilesDir(), "chat_snapshots_v1");
        if (!directory.exists()) directory.mkdirs();
        return directory;
    }

    private static File fileFor(File directory, String accountHash, String scopeKey) {
        return new File(directory, digest(accountHash + "|" + scopeKey) + ".json");
    }

    private static List<String> normalizedKeys(Collection<String> source) {
        List<String> keys = new ArrayList<>();
        if (source == null) return keys;
        for (String value : source) {
            String key = value == null ? "" : value.trim();
            if (!key.isEmpty() && !keys.contains(key)) keys.add(key);
        }
        return keys;
    }

    private static long messageId(JsonObject message) {
        long id = Jsons.longValue(message, "id");
        return id > 0L ? id : message.toString().hashCode();
    }

    private static String string(JsonObject object, String key) {
        try {
            return object.has(key) && !object.get(key).isJsonNull() ? object.get(key).getAsString() : "";
        } catch (RuntimeException ignored) {
            return "";
        }
    }

    private static long longValue(JsonObject object, String key) {
        try {
            return object.has(key) && !object.get(key).isJsonNull() ? object.get(key).getAsLong() : 0L;
        } catch (RuntimeException ignored) {
            return 0L;
        }
    }

    private static String digest(String value) {
        try {
            byte[] hash = MessageDigest.getInstance("SHA-256")
                .digest((value == null ? "" : value).getBytes(StandardCharsets.UTF_8));
            StringBuilder text = new StringBuilder(hash.length * 2);
            for (byte item : hash) text.append(String.format("%02x", item & 0xff));
            return text.toString();
        } catch (Exception ignored) {
            return Integer.toHexString(value == null ? 0 : value.hashCode());
        }
    }
}
