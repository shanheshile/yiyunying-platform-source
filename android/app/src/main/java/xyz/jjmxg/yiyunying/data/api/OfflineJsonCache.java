package xyz.jjmxg.yiyunying.data.api;

import android.content.Context;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.Arrays;
import java.util.Comparator;

import xyz.jjmxg.yiyunying.data.cache.AutoCachePolicyStore;
import xyz.jjmxg.yiyunying.data.cache.LocalCacheManager;

/** Durable, account-isolated cache for successful read-only API responses. */
final class OfflineJsonCache {
    private static final long MAX_ENTRY_BYTES = 8L * 1024L * 1024L;

    private final File directory;
    private final LocalCacheManager manager;

    OfflineJsonCache(Context context) {
        Context application = context.getApplicationContext();
        directory = new File(application.getFilesDir(), "offline_json_v1");
        if (!directory.exists()) directory.mkdirs();
        manager = LocalCacheManager.get(application);
    }

    synchronized void put(
        String key,
        String resourceKey,
        String contentKind,
        ApiResult result
    ) {
        if (key == null || key.isEmpty() || result == null || !result.isSuccessful()) return;
        String category = AutoCachePolicyStore.categoryForContentKind(contentKind);
        if (!manager.policy().accepts(category)) return;

        JsonObject stored = new JsonObject();
        stored.addProperty("saved_at", System.currentTimeMillis());
        stored.addProperty("account_key", manager.accountKey());
        stored.addProperty("origin_key", key);
        stored.addProperty("resource_key", resourceKey == null ? "" : resourceKey);
        stored.addProperty("content_kind", contentKind == null ? "只读内容" : contentKind);
        stored.addProperty("http_code", result.httpCode());
        stored.addProperty("code", result.code());
        stored.addProperty("message", result.message());
        stored.addProperty("trace_id", result.traceId());
        stored.add("data", result.data().deepCopy());
        byte[] bytes = Jsons.GSON.toJson(stored).getBytes(StandardCharsets.UTF_8);
        if (bytes.length > MAX_ENTRY_BYTES) return;

        File target = fileFor(key);
        File temporary = new File(directory, target.getName() + ".tmp");
        try (FileOutputStream output = new FileOutputStream(temporary)) {
            output.write(bytes);
            output.getFD().sync();
            if (target.exists() && !target.delete()) return;
            if (!temporary.renameTo(target)) return;
            target.setLastModified(System.currentTimeMillis());
            manager.registerApiCache(target, key, resourceKey, contentKind);
        } catch (Exception ignored) {
            temporary.delete();
        }
    }

    synchronized ApiResult get(String key, String resourceKey) {
        if (key == null || key.isEmpty()) return null;
        String accountKey = manager.accountKey();
        File source = fileFor(key);
        JsonObject exact = read(source);
        if (belongsToAccount(exact, accountKey)) return toResult(source, exact);
        if (resourceKey == null || resourceKey.isEmpty()) return null;

        File[] files = directory.listFiles((dir, name) -> name.endsWith(".json"));
        if (files == null || files.length == 0) return null;
        Arrays.sort(files, Comparator.comparingLong(File::lastModified).reversed());
        for (File candidate : files) {
            if (candidate.equals(source)) continue;
            JsonObject value = read(candidate);
            if (belongsToAccount(value, accountKey)
                && resourceKey.equals(string(value, "resource_key"))) {
                return toResult(candidate, value);
            }
        }
        return null;
    }

    private JsonObject read(File source) {
        if (source == null || !source.isFile() || source.length() <= 0L
            || source.length() > MAX_ENTRY_BYTES) {
            return null;
        }
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
            source.delete();
            return null;
        }
    }

    private ApiResult toResult(File source, JsonObject value) {
        JsonElement data = value.has("data") ? value.get("data") : new JsonObject();
        String kind = string(value, "content_kind");
        String originKey = string(value, "origin_key");
        source.setLastModified(System.currentTimeMillis());
        if (!originKey.isEmpty()) manager.touch(originKey);
        return ApiResult.response(
            200,
            1,
            "当前网络不可用，已展示此账号最近缓存的" + (kind.isEmpty() ? "只读内容" : kind),
            data,
            "offline-cache"
        );
    }

    private static boolean belongsToAccount(JsonObject object, String accountKey) {
        if (object == null || accountKey == null || accountKey.isEmpty()) return false;
        return accountKey.equals(string(object, "account_key"));
    }

    private static String string(JsonObject object, String key) {
        if (object == null) return "";
        try {
            JsonElement value = object.get(key);
            return value == null || value.isJsonNull() ? "" : value.getAsString();
        } catch (RuntimeException ignored) {
            return "";
        }
    }

    private File fileFor(String key) {
        return new File(directory, digest(key) + ".json");
    }

    private static String digest(String value) {
        try {
            byte[] hash = MessageDigest.getInstance("SHA-256")
                .digest(value.getBytes(StandardCharsets.UTF_8));
            StringBuilder text = new StringBuilder(hash.length * 2);
            for (byte item : hash) text.append(String.format("%02x", item & 0xff));
            return text.toString();
        } catch (Exception ignored) {
            return Integer.toHexString(value.hashCode());
        }
    }
}