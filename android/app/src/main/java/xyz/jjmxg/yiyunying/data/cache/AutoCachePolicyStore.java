package xyz.jjmxg.yiyunying.data.cache;

import android.content.Context;
import android.content.SharedPreferences;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.NetworkCapabilities;
import android.text.TextUtils;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.LinkedHashSet;
import java.util.Locale;
import java.util.Map;
import java.util.Set;

/** User choices and administrator limits for automatic offline caching and media playback. */
public final class AutoCachePolicyStore {
    public static final String NETWORK_WIFI = "wifi";
    public static final String NETWORK_ANY = "wifi_mobile";
    public static final String NETWORK_NEVER = "never";

    public static final String CHAT_RECORD = "chat_record";
    public static final String PROFILE = "profile";
    public static final String IMAGE = "image";
    public static final String VIDEO = "video";
    public static final String VOICE = "voice";
    public static final String AUDIO = "audio";
    public static final String DOCUMENT = "document";
    public static final String FILE = "file";
    public static final String STICKER = "sticker";

    public static final long DEFAULT_MAX_BYTES = 512L * 1024L * 1024L;
    private static final long MIN_MAX_BYTES = 64L * 1024L * 1024L;
    private static final String PREFS = "auto_cache_policy_v1";
    private static final Map<String, String> LABELS;

    static {
        LinkedHashMap<String, String> labels = new LinkedHashMap<>();
        labels.put(CHAT_RECORD, "聊天记录");
        labels.put(PROFILE, "资料与联系人");
        labels.put(IMAGE, "图片与动图");
        labels.put(VIDEO, "视频");
        labels.put(VOICE, "聊天语音");
        labels.put(AUDIO, "音频");
        labels.put(DOCUMENT, "文档");
        labels.put(FILE, "其他文件");
        labels.put(STICKER, "表情包");
        LABELS = Collections.unmodifiableMap(labels);
    }

    private final Context context;
    private final SharedPreferences preferences;

    public AutoCachePolicyStore(Context context) {
        this.context = context.getApplicationContext();
        preferences = this.context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    public boolean userEnabled() {
        return preferences.getBoolean("user_enabled", true);
    }

    public boolean effectiveEnabled() {
        return userEnabled() && administratorAllowsCaching();
    }

    public boolean administratorAllowsCaching() {
        return preferences.getBoolean("admin_enabled", true);
    }

    public void setUserEnabled(boolean enabled) {
        preferences.edit().putBoolean("user_enabled", enabled).apply();
    }

    public boolean wifiOnly() {
        return NETWORK_WIFI.equals(cacheNetworkPolicy());
    }

    public boolean administratorForcesWifiOnly() {
        return preferences.getBoolean("admin_force_wifi_only", false);
    }

    /** Backward-compatible setter used by old settings screens. */
    public void setWifiOnly(boolean wifiOnly) {
        setCacheNetworkPolicy(wifiOnly ? NETWORK_WIFI : NETWORK_ANY);
    }

    public String cacheNetworkPolicy() {
        if (!administratorAllowsCaching()) return NETWORK_NEVER;
        if (administratorForcesWifiOnly()) return NETWORK_WIFI;
        String legacy = preferences.getBoolean("user_wifi_only", true) ? NETWORK_WIFI : NETWORK_ANY;
        String requested = normalizeNetwork(preferences.getString("user_cache_network", legacy));
        String administrator = normalizeNetwork(preferences.getString("admin_cache_network", NETWORK_ANY));
        return constrainedNetwork(requested, administrator);
    }

    public void setCacheNetworkPolicy(String policy) {
        String value = normalizeNetwork(policy);
        preferences.edit()
            .putString("user_cache_network", value)
            .putBoolean("user_wifi_only", NETWORK_WIFI.equals(value))
            .apply();
    }

    public boolean videoAutoplayEnabled() {
        return preferences.getBoolean("user_video_autoplay_enabled", true)
            && preferences.getBoolean("admin_video_autoplay_enabled", true);
    }

    public void setVideoAutoplayEnabled(boolean enabled) {
        preferences.edit().putBoolean("user_video_autoplay_enabled", enabled).apply();
    }

    public String videoAutoplayNetworkPolicy() {
        if (!videoAutoplayEnabled()) return NETWORK_NEVER;
        String requested = normalizeNetwork(preferences.getString(
            "user_video_autoplay_network",
            preferences.getString("admin_video_autoplay_default_network", NETWORK_WIFI)
        ));
        String administrator = normalizeNetwork(preferences.getString(
            "admin_video_autoplay_network",
            NETWORK_ANY
        ));
        return constrainedNetwork(requested, administrator);
    }

    public void setVideoAutoplayNetworkPolicy(String policy) {
        preferences.edit()
            .putString("user_video_autoplay_network", normalizeNetwork(policy))
            .apply();
    }

    public boolean videoAutoplayAllowed() {
        return videoAutoplayEnabled() && networkAllows(videoAutoplayNetworkPolicy());
    }

    public long maxBytes() {
        long administratorLimit = preferences.getLong("admin_max_bytes", 2L * 1024L * 1024L * 1024L);
        long configured = preferences.getLong(
            "user_max_bytes",
            preferences.getLong("admin_default_max_bytes", DEFAULT_MAX_BYTES)
        );
        return Math.max(MIN_MAX_BYTES, Math.min(configured, Math.max(MIN_MAX_BYTES, administratorLimit)));
    }

    public long administratorMaxBytes() {
        return Math.max(MIN_MAX_BYTES, preferences.getLong("admin_max_bytes", 2L * 1024L * 1024L * 1024L));
    }

    public void setUserMaxBytes(long bytes) {
        preferences.edit()
            .putLong("user_max_bytes", Math.max(MIN_MAX_BYTES, Math.min(bytes, administratorMaxBytes())))
            .apply();
    }

    public int retentionDays() {
        int user = preferences.getInt("user_retention_days", preferences.getInt("admin_retention_days", 90));
        int administrator = preferences.getInt("admin_retention_days", 90);
        if (administrator <= 0) return Math.max(0, user);
        if (user <= 0) return administrator;
        return Math.min(user, administrator);
    }

    public void setRetentionDays(int days) {
        preferences.edit().putInt("user_retention_days", Math.max(0, days)).apply();
    }

    public Set<String> selectedCategories() {
        Set<String> selected = decode(preferences.getString("user_categories", ""));
        if (selected.isEmpty()) selected.addAll(LABELS.keySet());
        selected.retainAll(administratorCategories());
        return selected;
    }

    public Set<String> administratorCategories() {
        Set<String> allowed = decode(preferences.getString("admin_categories", ""));
        if (allowed.isEmpty()) allowed.addAll(LABELS.keySet());
        return allowed;
    }

    public void setSelectedCategories(Set<String> categories) {
        LinkedHashSet<String> safe = new LinkedHashSet<>();
        if (categories != null) {
            for (String category : LABELS.keySet()) {
                if (categories.contains(category)) safe.add(category);
            }
        }
        preferences.edit().putString("user_categories", TextUtils.join(",", safe)).apply();
    }

    /** Applies only to automatic caching. User-initiated downloads remain user-managed. */
    public boolean accepts(String category) {
        if (!effectiveEnabled() || !selectedCategories().contains(normalizeCategory(category))) return false;
        return networkAllows(cacheNetworkPolicy());
    }

    public String policyVersion() {
        return preferences.getString("admin_policy_version", "local-default");
    }

    public void applyRemote(JsonObject response) {
        if (response == null) return;
        JsonObject policy = response.has("auto_cache_policy") && response.get("auto_cache_policy").isJsonObject()
            ? response.getAsJsonObject("auto_cache_policy") : response;
        SharedPreferences.Editor editor = preferences.edit();
        if (policy.has("enabled")) editor.putBoolean("admin_enabled", bool(policy, "enabled", true));
        if (policy.has("wifi_only")) editor.putBoolean("admin_wifi_only", bool(policy, "wifi_only", false));
        if (policy.has("force_wifi_only")) {
            editor.putBoolean("admin_force_wifi_only", bool(policy, "force_wifi_only", false));
        }
        if (policy.has("network")) {
            editor.putString("admin_cache_network", normalizeNetwork(text(policy, "network", NETWORK_ANY)));
        }
        long defaultBytes = number(policy, "default_max_bytes", DEFAULT_MAX_BYTES);
        long maximumBytes = number(
            policy,
            "max_bytes_limit",
            Math.max(defaultBytes, 2L * 1024L * 1024L * 1024L)
        );
        editor.putLong("admin_default_max_bytes", Math.max(MIN_MAX_BYTES, defaultBytes));
        editor.putLong("admin_max_bytes", Math.max(MIN_MAX_BYTES, maximumBytes));
        editor.putInt("admin_retention_days", (int) Math.max(0, number(policy, "retention_days", 90)));
        editor.putString("admin_policy_version", text(policy, "policy_version", "remote"));
        Set<String> allowed = categories(policy.get("allowed_categories"));
        if (!allowed.isEmpty()) editor.putString("admin_categories", TextUtils.join(",", allowed));

        JsonObject playback = response.has("video_autoplay_policy")
            && response.get("video_autoplay_policy").isJsonObject()
            ? response.getAsJsonObject("video_autoplay_policy") : new JsonObject();
        if (playback.has("enabled")) {
            editor.putBoolean("admin_video_autoplay_enabled", bool(playback, "enabled", true));
        }
        if (playback.has("network")) {
            editor.putString(
                "admin_video_autoplay_network",
                normalizeNetwork(text(playback, "network", NETWORK_ANY))
            );
        }
        if (playback.has("default_network")) {
            editor.putString(
                "admin_video_autoplay_default_network",
                normalizeNetwork(text(playback, "default_network", NETWORK_WIFI))
            );
        }
        editor.apply();
    }

    public static Map<String, String> labels() {
        return LABELS;
    }

    public static String label(String category) {
        String value = LABELS.get(normalizeCategory(category));
        return value == null ? "其他缓存" : value;
    }

    public static String networkLabel(String policy) {
        String value = normalizeNetwork(policy);
        if (NETWORK_WIFI.equals(value)) return "仅 Wi-Fi";
        if (NETWORK_NEVER.equals(value)) return "从不自动";
        return "Wi-Fi 与移动网络";
    }

    public static String categoryForContentKind(String contentKind) {
        String kind = contentKind == null ? "" : contentKind;
        if (kind.contains("聊天") || kind.contains("客服")) return CHAT_RECORD;
        if (kind.contains("资料") || kind.contains("联系人") || kind.contains("互动")) return PROFILE;
        if (kind.contains("表情")) return STICKER;
        if (kind.contains("图片") || kind.contains("动图")) return IMAGE;
        if (kind.contains("视频")) return VIDEO;
        if (kind.contains("语音")) return VOICE;
        if (kind.contains("音频")) return AUDIO;
        if (kind.contains("文档")) return DOCUMENT;
        return FILE;
    }

    public static String categoryForFile(String mimeType, String name, String fallback) {
        String mime = mimeType == null ? "" : mimeType.toLowerCase(Locale.ROOT);
        String fileName = name == null ? "" : name.toLowerCase(Locale.ROOT);
        if (mime.startsWith("image/gif") || fileName.endsWith(".gif")) return IMAGE;
        if (mime.startsWith("image/")) return IMAGE;
        if (mime.startsWith("video/")) return VIDEO;
        if (mime.startsWith("audio/")) return AUDIO;
        if (mime.contains("pdf") || mime.contains("word") || mime.contains("excel")
            || mime.contains("powerpoint")
            || fileName.matches(".*\\.(pdf|docx?|xlsx?|pptx?|txt|md)$")) {
            return DOCUMENT;
        }
        String normalized = normalizeCategory(fallback);
        return LABELS.containsKey(normalized) ? normalized : FILE;
    }

    private boolean networkAllows(String policy) {
        String value = normalizeNetwork(policy);
        if (NETWORK_NEVER.equals(value)) return false;
        ConnectivityManager manager =
            (ConnectivityManager) context.getSystemService(Context.CONNECTIVITY_SERVICE);
        if (manager == null) return false;
        try {
            Network network = manager.getActiveNetwork();
            if (network == null) return false;
            NetworkCapabilities capabilities = manager.getNetworkCapabilities(network);
            if (capabilities == null
                || !capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)) return false;
            if (NETWORK_WIFI.equals(value)) {
                return capabilities.hasTransport(NetworkCapabilities.TRANSPORT_WIFI);
            }
            return capabilities.hasTransport(NetworkCapabilities.TRANSPORT_WIFI)
                || capabilities.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR)
                || capabilities.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET);
        } catch (RuntimeException ignored) {
            return false;
        }
    }

    private static String constrainedNetwork(String requested, String administrator) {
        if (NETWORK_NEVER.equals(administrator)) return NETWORK_NEVER;
        if (NETWORK_WIFI.equals(administrator) && NETWORK_ANY.equals(requested)) return NETWORK_WIFI;
        return requested;
    }

    private static Set<String> categories(JsonElement value) {
        LinkedHashSet<String> result = new LinkedHashSet<>();
        if (value == null || value.isJsonNull()) return result;
        if (value.isJsonArray()) {
            JsonArray array = value.getAsJsonArray();
            for (JsonElement item : array) {
                if (item.isJsonPrimitive()) addCategory(result, item.getAsString());
            }
        } else if (value.isJsonPrimitive()) {
            result.addAll(decode(value.getAsString()));
        }
        return result;
    }

    private static Set<String> decode(String encoded) {
        LinkedHashSet<String> result = new LinkedHashSet<>();
        if (encoded == null || encoded.trim().isEmpty()) return result;
        for (String item : encoded.split(",")) addCategory(result, item);
        return result;
    }

    private static void addCategory(Set<String> result, String value) {
        String category = normalizeCategory(value);
        if (LABELS.containsKey(category)) result.add(category);
    }

    private static String normalizeCategory(String value) {
        return value == null ? "" : value.trim().toLowerCase(Locale.ROOT);
    }

    private static String normalizeNetwork(String value) {
        String normalized = value == null ? "" : value.trim().toLowerCase(Locale.ROOT);
        if ("any".equals(normalized) || "mobile".equals(normalized) || "all".equals(normalized)) {
            return NETWORK_ANY;
        }
        if (NETWORK_NEVER.equals(normalized) || "off".equals(normalized) || "disabled".equals(normalized)) {
            return NETWORK_NEVER;
        }
        return NETWORK_WIFI.equals(normalized) ? NETWORK_WIFI : NETWORK_ANY;
    }

    private static boolean bool(JsonObject object, String key, boolean fallback) {
        try {
            return object.get(key).getAsBoolean();
        } catch (RuntimeException ignored) {
            return fallback;
        }
    }

    private static long number(JsonObject object, String key, long fallback) {
        try {
            return object.get(key).getAsLong();
        } catch (RuntimeException ignored) {
            return fallback;
        }
    }

    private static String text(JsonObject object, String key, String fallback) {
        try {
            String value = object.get(key).getAsString().trim();
            return value.isEmpty() ? fallback : value;
        } catch (RuntimeException ignored) {
            return fallback;
        }
    }
}