package xyz.jjmxg.yiyunying.ui.chat;

import android.net.Uri;

import com.google.gson.JsonObject;

final class PendingAttachment {
    final Uri uri;
    final String mediaType;
    final String name;
    final String mimeType;
    final long sizeBytes;
    final long stickerId;
    final String previewUrl;
    final int width;
    final int height;
    final long durationMs;
    final JsonObject metadata;

    private PendingAttachment(
        Uri uri,
        String mediaType,
        String name,
        String mimeType,
        long sizeBytes,
        long stickerId,
        String previewUrl,
        int width,
        int height,
        long durationMs,
        JsonObject metadata
    ) {
        this.uri = uri;
        this.mediaType = mediaType;
        this.name = name;
        this.mimeType = mimeType;
        this.sizeBytes = sizeBytes;
        this.stickerId = stickerId;
        this.previewUrl = previewUrl;
        this.width = Math.max(0, width);
        this.height = Math.max(0, height);
        this.durationMs = Math.max(0, durationMs);
        this.metadata = metadata == null ? new JsonObject() : metadata.deepCopy();
    }

    static PendingAttachment local(Uri uri, String mediaType, String name, String mimeType, long sizeBytes) {
        return local(uri, mediaType, name, mimeType, sizeBytes, 0, 0, 0);
    }

    static PendingAttachment local(
        Uri uri, String mediaType, String name, String mimeType, long sizeBytes,
        int width, int height, long durationMs
    ) {
        return new PendingAttachment(uri, mediaType, name, mimeType, sizeBytes, 0, "", width, height, durationMs, null);
    }

    static PendingAttachment local(
        Uri uri, String mediaType, String name, String mimeType, long sizeBytes,
        int width, int height, long durationMs, JsonObject metadata
    ) {
        return new PendingAttachment(uri, mediaType, name, mimeType, sizeBytes, 0, "", width, height, durationMs, metadata);
    }

    static PendingAttachment sticker(long stickerId, String name, String previewUrl) {
        return new PendingAttachment(null, "sticker", name, "image/*", 0, stickerId, previewUrl, 0, 0, 0, null);
    }

    static PendingAttachment structured(String mediaType, String name, String previewUrl, JsonObject metadata) {
        return new PendingAttachment(null, mediaType, name, "application/json", 0, 0,
            previewUrl == null ? "" : previewUrl, 0, 0, 0, metadata);
    }

    boolean needsUpload() {
        return uri != null;
    }
}
