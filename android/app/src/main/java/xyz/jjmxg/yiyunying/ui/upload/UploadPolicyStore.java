package xyz.jjmxg.yiyunying.ui.upload;

import android.content.Context;
import android.content.SharedPreferences;

import com.google.gson.JsonObject;

import java.util.Locale;

import xyz.jjmxg.yiyunying.data.api.Jsons;

public final class UploadPolicyStore {
    private static final String PREFS = "upload_policy";
    private static final long MB = 1024L * 1024L;
    private static final long IMAGE_DEFAULT = 100L * MB;
    private static final long VIDEO_DEFAULT = 1024L * MB;
    private static final long AUDIO_DEFAULT = 100L * MB;
    private static final long FILE_DEFAULT = 512L * MB;

    private UploadPolicyStore() { }

    public static void update(Context context, JsonObject limits) {
        if (context == null || limits == null) return;
        SharedPreferences.Editor editor = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit();
        put(editor, limits, "image", IMAGE_DEFAULT);
        put(editor, limits, "video", VIDEO_DEFAULT);
        put(editor, limits, "audio", AUDIO_DEFAULT);
        put(editor, limits, "file", FILE_DEFAULT);
        editor.apply();
    }

    public static long maxBytes(Context context, String mediaType) {
        String type = normalize(mediaType);
        long fallback = fallback(type);
        return Math.max(MB, context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getLong(type + "_max_bytes", fallback));
    }

    public static boolean accepts(Context context, String mediaType, long sizeBytes) {
        return sizeBytes <= 0 || sizeBytes <= maxBytes(context, mediaType);
    }

    public static String rejectionMessage(Context context, String mediaType, long sizeBytes) {
        return label(mediaType) + "大小为 " + format(sizeBytes) + "，超过当前上限 "
            + format(maxBytes(context, mediaType)) + "，请压缩或选择较小文件";
    }

    public static String label(String mediaType) {
        switch (normalize(mediaType)) {
            case "image": return "图片";
            case "video": return "视频";
            case "audio": return "音频";
            default: return "文件";
        }
    }

    public static String format(long bytes) {
        if (bytes <= 0) return "未知大小";
        if (bytes >= MB) return String.format(Locale.CHINA, "%.1f MB", bytes / (double) MB);
        if (bytes >= 1024L) return String.format(Locale.CHINA, "%.1f KB", bytes / 1024d);
        return bytes + " 字节";
    }

    private static void put(SharedPreferences.Editor editor, JsonObject limits, String type, long fallback) {
        long value = Jsons.longValue(limits, type + "_max_bytes");
        editor.putLong(type + "_max_bytes", value > 0 ? value : fallback);
    }

    private static String normalize(String mediaType) {
        String value = mediaType == null ? "" : mediaType.trim().toLowerCase(Locale.ROOT);
        return "image".equals(value) || "video".equals(value) || "audio".equals(value) ? value : "file";
    }

    private static long fallback(String type) {
        switch (type) {
            case "image": return IMAGE_DEFAULT;
            case "video": return VIDEO_DEFAULT;
            case "audio": return AUDIO_DEFAULT;
            default: return FILE_DEFAULT;
        }
    }
}
