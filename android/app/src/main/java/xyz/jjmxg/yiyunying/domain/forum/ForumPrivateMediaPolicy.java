package xyz.jjmxg.yiyunying.domain.forum;

import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;

import java.util.Collections;
import java.util.LinkedHashSet;
import java.util.Set;

/**
 * Client-side lifetime policy for the short capability URLs returned for an
 * unlocked forum attachment. The server remains the source of truth: this
 * class only decides when the current post must be fetched again.
 */
public final class ForumPrivateMediaPolicy {
    public static final long REFRESH_AHEAD_MS = 60_000L;
    private static final String PRIVATE_PATH = "/api/public/forum-media/";

    private ForumPrivateMediaPolicy() { }

    public static Snapshot inspect(JsonElement root, long nowMs) {
        MutableSnapshot result = new MutableSnapshot();
        inspectElement(root, Math.max(0L, nowMs), result);
        return result.freeze();
    }

    public static boolean shouldRefresh(JsonObject attachment, long nowMs) {
        Snapshot snapshot = inspectAttachment(attachment, nowMs);
        return snapshot.hasPrivateMedia() && snapshot.refreshRequired();
    }

    public static long privateAttachmentId(JsonObject attachment) {
        return parseAttachmentId(privateUrl(attachment));
    }

    private static Snapshot inspectAttachment(JsonObject attachment, long nowMs) {
        MutableSnapshot result = new MutableSnapshot();
        inspectObject(attachment, Math.max(0L, nowMs), result, false);
        return result.freeze();
    }

    private static void inspectElement(JsonElement element, long nowMs, MutableSnapshot result) {
        if (element == null || element.isJsonNull()) return;
        if (element.isJsonArray()) {
            JsonArray values = element.getAsJsonArray();
            for (JsonElement value : values) inspectElement(value, nowMs, result);
            return;
        }
        if (element.isJsonObject()) inspectObject(element.getAsJsonObject(), nowMs, result, true);
    }

    private static void inspectObject(
        JsonObject object,
        long nowMs,
        MutableSnapshot result,
        boolean recurse
    ) {
        if (object == null) return;
        String url = privateUrl(object);
        if (!url.isEmpty()) {
            result.hasPrivateMedia = true;
            long attachmentId = parseAttachmentId(url);
            if (attachmentId > 0L) result.attachmentIds.add(attachmentId);
            long expiryMs = parseExpiryMillis(url);
            if (expiryMs <= 0L) {
                result.refreshRequired = true;
            } else {
                result.earliestExpiryMs = Math.min(result.earliestExpiryMs, expiryMs);
                if (expiryMs <= nowMs + REFRESH_AHEAD_MS) result.refreshRequired = true;
            }
        }
        if (!recurse) return;
        for (String key : object.keySet()) {
            if ("metadata".equals(key)) continue;
            inspectElement(object.get(key), nowMs, result);
        }
    }

    private static String privateUrl(JsonObject object) {
        if (object == null) return "";
        String[] keys = {
            "url", "file_url", "media_url", "download_url", "thumbnail_url",
            "preview_url", "optimized_file_url", "original_file_url", "source_url",
            "poster_url", "cover_url", "image_url"
        };
        for (String key : keys) {
            String value = text(object, key);
            if (value.contains(PRIVATE_PATH)) return value;
        }
        JsonElement metadata = object.get("metadata");
        if (metadata != null && metadata.isJsonObject()) {
            JsonObject values = metadata.getAsJsonObject();
            for (String key : keys) {
                String value = text(values, key);
                if (value.contains(PRIVATE_PATH)) return value;
            }
        }
        return "";
    }

    private static String text(JsonObject object, String key) {
        try {
            JsonElement value = object.get(key);
            return value == null || value.isJsonNull() ? "" : value.getAsString().trim();
        } catch (RuntimeException ignored) {
            return "";
        }
    }

    private static long parseAttachmentId(String url) {
        int marker = url == null ? -1 : url.indexOf(PRIVATE_PATH);
        if (marker < 0) return 0L;
        int start = marker + PRIVATE_PATH.length();
        int end = start;
        while (end < url.length() && Character.isDigit(url.charAt(end))) end++;
        if (end == start) return 0L;
        try {
            return Math.max(0L, Long.parseLong(url.substring(start, end)));
        } catch (RuntimeException ignored) {
            return 0L;
        }
    }

    private static long parseExpiryMillis(String url) {
        if (url == null || url.isEmpty()) return 0L;
        int queryStart = url.indexOf('?');
        if (queryStart < 0 || queryStart == url.length() - 1) return 0L;
        String query = url.substring(queryStart + 1);
        int fragment = query.indexOf('#');
        if (fragment >= 0) query = query.substring(0, fragment);
        for (String part : query.split("&")) {
            int separator = part.indexOf('=');
            if (separator <= 0 || !"expires".equals(part.substring(0, separator))) continue;
            try {
                long seconds = Long.parseLong(part.substring(separator + 1));
                if (seconds <= 0L || seconds > Long.MAX_VALUE / 1000L) return 0L;
                return seconds * 1000L;
            } catch (RuntimeException ignored) {
                return 0L;
            }
        }
        return 0L;
    }

    public static final class Snapshot {
        private final boolean hasPrivateMedia;
        private final boolean refreshRequired;
        private final long earliestExpiryMs;
        private final Set<Long> attachmentIds;

        private Snapshot(
            boolean hasPrivateMedia,
            boolean refreshRequired,
            long earliestExpiryMs,
            Set<Long> attachmentIds
        ) {
            this.hasPrivateMedia = hasPrivateMedia;
            this.refreshRequired = refreshRequired;
            this.earliestExpiryMs = earliestExpiryMs;
            this.attachmentIds = attachmentIds;
        }

        public boolean hasPrivateMedia() { return hasPrivateMedia; }
        public boolean refreshRequired() { return refreshRequired; }
        public long earliestExpiryMs() { return earliestExpiryMs; }
        public Set<Long> attachmentIds() { return attachmentIds; }
        public boolean contains(long attachmentId) { return attachmentIds.contains(attachmentId); }
    }

    private static final class MutableSnapshot {
        boolean hasPrivateMedia;
        boolean refreshRequired;
        long earliestExpiryMs = Long.MAX_VALUE;
        final Set<Long> attachmentIds = new LinkedHashSet<>();

        Snapshot freeze() {
            return new Snapshot(
                hasPrivateMedia,
                refreshRequired,
                earliestExpiryMs,
                Collections.unmodifiableSet(new LinkedHashSet<>(attachmentIds))
            );
        }
    }
}
